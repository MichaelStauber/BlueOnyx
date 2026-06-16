package main

import (
    "bufio"
    "context"
    "crypto/rand"
    "crypto/tls"
    "crypto/x509"
    "encoding/json"
    "fmt"
    "io"
    "log"
    "net"
    "net/http"
    "os"
    "os/exec"
    "os/signal"
    "regexp"
    "strconv"
    "strings"
    "sync"
    "syscall"
    "time"
)

type Config struct {
    UnixSocket          string                   `json:"unix_socket"`
    SocketReadTimeout   time.Duration            `json:"socket_read_timeout"`
    ListenAddr          string                   `json:"listen_address"`
    CertFile            string                   `json:"cert_file"`
    KeyFile             string                   `json:"key_file"`
    CACerts             string                   `json:"ca_certs"`
    EnableHTTP          bool                     `json:"enable_http"`
    Logging             bool                     `json:"logging"`
    DebugLog            bool                     `json:"debuglog"`
    APIAllowed          []string                 `json:"api_access"`
    TokenLifetime       time.Duration            `json:"token_lifetime"`
    AuthFailLimit       int                      `json:"api_auth_fails"`
    AuthBanTime         time.Duration            `json:"api_ban_time"`
}

type CCERequest struct {
    Cmd               string                   `json:"cmd"`
    Class             string                   `json:"class,omitempty"`
    OID               string                   `json:"oid,omitempty"`
    Namespace         string                   `json:"namespace,omitempty"`
    Args              map[string]string        `json:"args,omitempty"`
    RegexArgs         map[string]string        `json:"regex_args,omitempty"`
    SortType          string                   `json:"sorttype,omitempty"`
    SortProp          string                   `json:"sortprop,omitempty"`
    User              string                   `json:"user,omitempty"`
    Password          string                   `json:"password,omitempty"`
    SessionId         string                   `json:"sessionid,omitempty"`
    Data              map[string]interface{}   `json:"data,omitempty"`
    OIDs              []string                 `json:"oids,omitempty"`
    IsTransaction     bool                     `json:"is_transaction,omitempty"`
    Token             string                   `json:"token,omitempty"`
}

type CCEResponse struct {
    Status              int                      `json:"status"`
    Message             string                   `json:"message,omitempty"`
    Data                map[string]interface{}   `json:"data,omitempty"`
}

type authAttempt struct {
    times []time.Time
}

type ccedConnection struct {
    conn   net.Conn
    writer *bufio.Writer
    reader *bufio.Scanner
}

type transactionBuffer struct {
    commands []string
}

type TokenEntry struct {
    Username  string
    SessionID string
    Expires   time.Time
}

var apiAdminPassword string

var (
    tokenSessionMap   = make(map[string]TokenEntry)
    tokenSessionMutex sync.Mutex
)

var (
    transactionLock   sync.Mutex
    transactionQueues = make(map[string]*transactionBuffer) // keyed by sessionId
)

var rateLimitMap = make(map[string]*authAttempt)
var rateLimitMutex sync.Mutex

var cfg Config
var logger *log.Logger

func respondJSON(w http.ResponseWriter, statusCode int, payload interface{}) {
    w.Header().Set("Content-Type", "application/json")
    w.WriteHeader(statusCode)
    _ = json.NewEncoder(w).Encode(payload)
}

func loadConfig(path string) (*Config, error) {
    file, err := os.Open(path)
    if err != nil {
        return nil, err
    }
    defer file.Close()

    config := &Config{}
    scanner := bufio.NewScanner(file)
    for scanner.Scan() {
        line := strings.TrimSpace(scanner.Text())
        if line == "" || strings.HasPrefix(line, "#") {
            continue
        }
        parts := strings.SplitN(line, "=", 2)
        if len(parts) != 2 {
            continue
        }
        key := strings.TrimSpace(parts[0])
        val := strings.TrimSpace(parts[1])
        switch key {
        case "unix_socket_path":
            config.UnixSocket = val
        case "listen_address":
            config.ListenAddr = val
        case "cert_file":
            config.CertFile = val
        case "key_file":
            config.KeyFile = val
        case "ca_cert_file":
            config.CACerts = val
        case "enable_http":
            config.EnableHTTP = strings.ToLower(val) == "true"
        case "logging":
            config.Logging = strings.ToLower(val) == "true"
        case "debuglog":
            config.DebugLog = strings.ToLower(val) == "true"
        case "api_access":
            raw := strings.Split(val, ",")
            for _, entry := range raw {
                config.APIAllowed = append(config.APIAllowed, strings.TrimSpace(entry))
            }
        case "token_lifetime":
            if n, err := strconv.Atoi(val); err == nil {
                config.TokenLifetime = time.Duration(n) * time.Second
            }
        case "api_auth_fails":
            if n, err := strconv.Atoi(val); err == nil {
                config.AuthFailLimit = n
            }
        case "api_ban_time":
            if n, err := strconv.Atoi(val); err == nil {
                config.AuthBanTime = time.Duration(n) * time.Second
            }
        case "socket_read_timeout":
            if n, err := strconv.Atoi(val); err == nil {
                config.SocketReadTimeout = time.Duration(n) * time.Second
            }
        }
    }
    if err := scanner.Err(); err != nil {
        return nil, err
    }

    if config.TokenLifetime == 0 {
        config.TokenLifetime = 5 * time.Minute
    }
    if config.AuthFailLimit == 0 {
        config.AuthFailLimit = 5
    }
    if config.AuthBanTime == 0 {
        config.AuthBanTime = 60 * time.Second
    }
    if config.SocketReadTimeout == 0 {
        config.SocketReadTimeout = 5 * time.Second
    }

    return config, nil
}

// maskSessionId masks the sessionId for logging, showing only the first 5 characters followed by "..."
func maskSessionId(sessionId string) string {
    if len(sessionId) > 5 {
        return sessionId[:5] + "..."
    }
    return "..."
}

func sanitizeCommand(cmd string) string {
    // Handle AUTH command
    if strings.HasPrefix(cmd, "AUTH ") {
        parts := strings.SplitN(cmd, " ", 3)
        if len(parts) == 3 {
            user := strings.Trim(parts[1], `"`)
            return fmt.Sprintf("AUTH \"%s\" \"xxx\"", user)
        }
    }
    // Handle AUTHKEY command
    if strings.HasPrefix(cmd, "AUTHKEY ") {
        parts := strings.SplitN(cmd, " ", 3)
        if len(parts) == 3 {
            user := strings.Trim(parts[1], `"`)
            sessionId := strings.Trim(parts[2], `"`)
            return fmt.Sprintf("AUTHKEY \"%s\" \"%s\"", user, maskSessionId(sessionId))
        }
    }
    // Handle SET command with password
    if strings.HasPrefix(cmd, "SET ") {
        re := regexp.MustCompile(`"password"\s*=\s*"[^"]*"`)
        return re.ReplaceAllString(cmd, `"password" = "xxx"`)
    }
    return cmd
}

func openAuthedSocket(user, sessionId string) (*ccedConnection, error) {
    conn, err := net.DialTimeout("unix", cfg.UnixSocket, 5*time.Second)
    if err != nil {
        return nil, err
    }

    writer := bufio.NewWriter(conn)
    reader := bufio.NewScanner(conn)

    authLine := fmt.Sprintf("AUTHKEY \"%s\" \"%s\"\n", user, sessionId)
    if cfg.Logging && logger != nil {
        logWithContext("127.0.0.1", user, ">> Sending command: %s", sanitizeCommand(strings.TrimSpace(authLine)))
    }
    fmt.Fprint(writer, authLine)
    writer.Flush()

    for reader.Scan() {
        line := reader.Text()
        if cfg.DebugLog && logger != nil {
            logger.Printf("<< %s", line)
        }

        // Check for success
        if strings.HasPrefix(line, "202 ") || strings.HasPrefix(line, "201 ") {
            break
        }

        // Handle known failure
        if strings.HasPrefix(line, "401 ") {
            return nil, fmt.Errorf("AUTHKEY failed: %s", line)
        }
    }

    return &ccedConnection{
        conn:   conn,
        writer: writer,
        reader: reader,
    }, nil
}

func sendCommandOverSocket(cc *ccedConnection, command string, reuse bool) (CCEResponse, error) {
    if command == "" {
        return CCEResponse{Status: 402, Message: "Empty command"}, nil
    }

    fmt.Fprintln(cc.writer, command)
    if !reuse {
        fmt.Fprintln(cc.writer, "BYE")
    }
    cc.writer.Flush()

    resp := CCEResponse{Data: make(map[string]interface{})}
    dataFields := make(map[string]string)
    var namespaces []string
    var classes []string
    var errorsList []map[string]interface{}
    var infosList []map[string]interface{}
    statusCode := 0
    finalCheck := 0

ReadLoop:
    for cc.reader.Scan() {
        line := cc.reader.Text()
        if cfg.DebugLog && logger != nil {
            logger.Printf("<< %s", line)
        }

        if len(line) < 3 {
            continue
        }

        codeStr := line[:3]
        code, err := strconv.Atoi(codeStr)
        if err != nil {
            continue
        }
        finalCheck = code

        switch code {
        case 102, 103:
            parts := strings.SplitN(line[4:], "=", 2)
            if len(parts) == 2 {
                key := strings.TrimPrefix(strings.TrimSpace(parts[0]), "DATA ")
                val := strings.Trim(strings.TrimSpace(parts[1]), "\"")
                dataFields[key] = val
            }
        case 104:
            parts := strings.Fields(line)
            if len(parts) >= 3 && strings.ToUpper(parts[1]) == "OBJECT" {
                oid := parts[2]
                if list, ok := resp.Data["oidlist"].([]string); ok {
                    resp.Data["oidlist"] = append(list, oid)
                } else {
                    resp.Data["oidlist"] = []string{oid}
                }
            }
        case 105:
            ns := strings.TrimSpace(strings.TrimPrefix(line, "105 NAMESPACE"))
            namespaces = append(namespaces, ns)
        case 106:
            infosList = append(infosList, map[string]interface{}{
                "code":    code,
                "message": strings.TrimSpace(line[4:]),
            })
        case 110:
            cls := strings.TrimSpace(strings.TrimPrefix(line, "110 CLASS"))
            classes = append(classes, cls)
        case 109:
            resp.Data["sessionid"] = strings.TrimSpace(line[4:])
        case 201:
            statusCode = 201
            if reuse {
                break ReadLoop
            }
        case 302:
            if cfg.Logging && logger != nil {
                logger.Printf("Command failed with 302: %s", command)
            }
            return CCEResponse{Status: 302, Message: "BAD DATA", Data: map[string]interface{}{}}, fmt.Errorf("Error: %s", line)
        case 401:
            if cfg.Logging && logger != nil {
                logger.Printf("Command failed with 401: %s", command)
            }
            return CCEResponse{Status: 401, Message: "FAIL", Data: map[string]interface{}{}}, fmt.Errorf("Error: %s", line)
        case 202:
            break ReadLoop
        default:
            if code >= 300 && code <= 999 {
                errorsList = append(errorsList, map[string]interface{}{
                    "code":    code,
                    "message": strings.TrimSpace(line[4:]),
                })
            }
        }
    }

    if cfg.DebugLog && logger != nil {
        logger.Printf("Checking response status for command [%d]: %d - %s", finalCheck, statusCode, cscpCodeToText(finalCheck))
    }

    if strings.HasPrefix(command, "GET") && len(dataFields) == 0 {
        resp.Status = 404
        resp.Message = "Object or namespace not found"
        if cfg.DebugLog && logger != nil {
            logger.Printf("GET returned no DATA for command: %s", command)
        }
        return resp, fmt.Errorf("GET failed: object or namespace not found")
    }

    resp.Data["DATA"] = dataFields

    if len(namespaces) > 0 {
        resp.Data["namespaces"] = namespaces
    }
    if len(classes) > 0 {
        resp.Data["classes"] = classes
    }
    if len(errorsList) > 0 {
        resp.Data["errors"] = errorsList
    }
    if len(infosList) > 0 {
        resp.Data["infos"] = infosList
    }

    if statusCode == 0 && finalCheck != 0 {
        statusCode = finalCheck
    }
    if statusCode == 0 {
        statusCode = 500
    }
    resp.Status = statusCode
    resp.Message = cscpCodeToText(finalCheck)

    if statusCode >= 300 {
        return resp, fmt.Errorf("command failed with status %d", statusCode)
    }

    return resp, nil
}

func handleMetrics(w http.ResponseWriter, r *http.Request) {
    clientIP := extractClientIP(r)
    isLocal := clientIP == "127.0.0.1" || clientIP == "::1"

    if !isLocal && !validateRequestAuth(r) {
        http.Error(w, "Unauthorized", http.StatusUnauthorized)
        return
    }

    metrics := make(map[string][]map[string]interface{})

    // Load averages
    load1, _ := getLoadAvg(0)
    load5, _ := getLoadAvg(1)
    load15, _ := getLoadAvg(2)
    metrics["shortload"] = []map[string]interface{}{
        {"name": "node_load1", "value": load1, "labels": []string{}},
        {"name": "node_load5", "value": load5, "labels": []string{}},
        {"name": "node_load15", "value": load15, "labels": []string{}},
    }

    // Memory stats
    memTotal, memFree, memAvailable := getMemoryStats()
    swapTotal, swapFree := getSwapStats()
    metrics["shortmem"] = []map[string]interface{}{
        {"name": "node_memory_MemTotal_bytes", "value": memTotal, "labels": []string{}},
        {"name": "node_memory_MemAvailable_bytes", "value": memAvailable, "labels": []string{}},
        {"name": "node_memory_MemFree_bytes", "value": memFree, "labels": []string{}},
        {"name": "node_memory_SwapTotal_bytes", "value": swapTotal, "labels": []string{}},
        {"name": "node_memory_SwapFree_bytes", "value": swapFree, "labels": []string{}},
    }

    // Network stats
    netStats := getNetworkStats()
    var aggnet []map[string]interface{}
    for iface, stats := range netStats {
        aggnet = append(aggnet, map[string]interface{}{
            "name":   "receive_bytes_total_" + iface,
            "value":  stats.Received,
            "labels": []string{},
        })
        aggnet = append(aggnet, map[string]interface{}{
            "name":   "transmit_bytes_total_" + iface,
            "value":  stats.Sent,
            "labels": []string{},
        })
    }
    metrics["aggnet"] = aggnet

    // Disk I/O
    diskStats := getDiskStats()
    var shortdisk []map[string]interface{}
    for dev, val := range diskStats {
        shortdisk = append(shortdisk, map[string]interface{}{
            "name":  "disk_io_now_" + dev,
            "value": val,
        })
    }
    metrics["shortdisk"] = shortdisk

    w.Header().Set("Content-Type", "application/json")
    w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")
    json.NewEncoder(w).Encode(metrics)
}

func validateRequestAuth(r *http.Request) bool {
    token := r.Header.Get("X-Token")
    user := r.Header.Get("X-User")
    session := r.Header.Get("X-Session")

    if token != "" {
        tokenSessionMutex.Lock()
        entry, ok := tokenSessionMap[token]
        tokenSessionMutex.Unlock()

        if !ok || time.Now().After(entry.Expires) {
            return false
        }
        user = entry.Username
        session = entry.SessionID
    }

    if user == "" || session == "" {
        return false
    }

    cc, err := openAuthedSocket(user, session)
    if err != nil {
        return false
    }
    defer cc.conn.Close()

    return true
}

func getLoadAvg(index int) (float64, error) {
    data, err := os.ReadFile("/proc/loadavg")
    if err != nil {
        return 0, err
    }
    parts := strings.Fields(string(data))
    if len(parts) < 3 {
        return 0, fmt.Errorf("unexpected loadavg format")
    }
    return strconv.ParseFloat(parts[index], 64)
}

func getMemoryStats() (total, free, available uint64) {
    data, _ := os.ReadFile("/proc/meminfo")
    lines := strings.Split(string(data), "\n")
    for _, line := range lines {
        fields := strings.Fields(line)
        if len(fields) < 2 {
            continue
        }
        val, _ := strconv.ParseUint(fields[1], 10, 64)
        switch fields[0] {
        case "MemTotal:":
            total = val * 1024
        case "MemFree:":
            free = val * 1024
        case "MemAvailable:":
            available = val * 1024
        }
    }
    return
}

func getSwapStats() (total, free uint64) {
    data, _ := os.ReadFile("/proc/meminfo")
    lines := strings.Split(string(data), "\n")
    for _, line := range lines {
        fields := strings.Fields(line)
        if len(fields) < 2 {
            continue
        }
        val, _ := strconv.ParseUint(fields[1], 10, 64)
        switch fields[0] {
        case "SwapTotal:":
            total = val * 1024
        case "SwapFree:":
            free = val * 1024
        }
    }
    return
}

type NetStat struct {
    Received uint64
    Sent     uint64
}

func getNetworkStats() map[string]NetStat {
    result := make(map[string]NetStat)

    data, _ := os.ReadFile("/proc/net/dev")
    lines := strings.Split(string(data), "\n")[2:]
    for _, line := range lines {
        fields := strings.Fields(line)
        if len(fields) < 17 {
            continue
        }
        iface := strings.TrimSuffix(fields[0], ":")
        recv, _ := strconv.ParseUint(fields[1], 10, 64)
        transmit, _ := strconv.ParseUint(fields[9], 10, 64)
        result[iface] = NetStat{Received: recv, Sent: transmit}
    }
    return result
}

func getDiskStats() map[string]uint64 {
    stats := make(map[string]uint64)
    data, _ := os.ReadFile("/proc/diskstats")
    lines := strings.Split(string(data), "\n")
    for _, line := range lines {
        fields := strings.Fields(line)
        if len(fields) < 14 {
            continue
        }
        name := fields[2]
        ioNow, err := strconv.ParseUint(fields[11], 10, 64)
        if err == nil {
            stats[name] = ioNow
        }
    }
    return stats
}

func parseFloat(s string) float64 {
    val, _ := strconv.ParseFloat(s, 64)
    return val
}

func handleCCE(w http.ResponseWriter, r *http.Request) {
    // Read body first so we can log and decode it multiple times
    bodyBytes, err := io.ReadAll(r.Body)
    if err != nil {
        http.Error(w, "Failed to read request", http.StatusBadRequest)
        return
    }
    r.Body.Close()

    // Restore the body so later decoding works
    r.Body = io.NopCloser(strings.NewReader(string(bodyBytes)))

    clientIP := extractClientIP(r)
    username := "none"

    var tempReq CCERequest
    if err := json.Unmarshal(bodyBytes, &tempReq); err == nil && tempReq.User != "" {
        username = tempReq.User
    }

    if cfg.DebugLog && logger != nil {
        logWithContext(clientIP, username, "handleCCE(): received request")
    }

    // Redirect GET to login page
    if r.Method == http.MethodGet {
        host := r.Host
        if strings.Contains(host, ":") {
            host, _, _ = strings.Cut(host, ":")
        }
        target := fmt.Sprintf("https://%s:81/login", host)
        http.Redirect(w, r, target, http.StatusFound)
        return
    }

    var req CCERequest
    startTime := time.Now()

    decoder := json.NewDecoder(r.Body)
    if err := decoder.Decode(&req); err != nil {
        http.Error(w, "Invalid request body", http.StatusBadRequest)
        return
    }

    // Debug: Log the raw command
    if cfg.DebugLog && logger != nil {
        logger.Printf("Decoded request command: %s", req.Cmd)
    }

    // Populate req.Args from req.Data or req.Vars for CREATE/SET
    if strings.EqualFold(req.Cmd, "CREATE") || strings.EqualFold(req.Cmd, "SET") {
        if req.Args == nil {
            req.Args = make(map[string]string)
        }
        if req.Data != nil {
            for k, v := range req.Data {
                req.Args[k] = fmt.Sprintf("%v", v)
            }
        }
    }

    req.Cmd = strings.TrimSpace(req.Cmd)
    if req.Cmd == "" {
        http.Error(w, "Missing command", http.StatusBadRequest)
        return
    }
    cmdUpper := strings.ToUpper(req.Cmd)

    // Reject invalid ERRORS command early
    if cmdUpper == "ERRORS" {
        resp := CCEResponse{Status: 402, Message: "Invalid command: ERRORS is not supported"}
        logRequest(r, req, resp.Status, resp.Message, nil, time.Since(startTime))
        http.Error(w, resp.Message, http.StatusBadRequest)
        return
    }

    // Handle PING natively (without talking to CCEd)
    if cmdUpper == "PING" {
        respondJSON(w, http.StatusAccepted, map[string]interface{}{
            "status":  202,
            "message": "PONG",
        })
        return
    }

    clientSecret := r.Header.Get("X-Client-Secret")
    isLocal := clientIP == "127.0.0.1" || clientIP == "::1"

    // Enforce secret only for LOGIN (from remote IPs)
    if strings.ToUpper(req.Cmd) == "LOGIN" {
        if isLocal {
            http.Error(w, "LOGIN not allowed from localhost", http.StatusForbidden)
            return
        }
        if !validateClientSecret(clientIP, clientSecret) {
            http.Error(w, "Unauthorized: invalid client secret", http.StatusUnauthorized)
            return
        }
    }

    // Enforce AUTH and AUTHKEY to localhost only
    if (strings.ToUpper(req.Cmd) == "AUTH" || strings.ToUpper(req.Cmd) == "AUTHKEY") && !isLocal {
        http.Error(w, "AUTH and AUTHKEY only allowed from localhost", http.StatusForbidden)
        return
    }

    if ip := r.Header.Get("X-Real-IP"); ip != "" {
        clientIP = ip
    }
    if strings.Contains(clientIP, ":") {
        clientIP, _, _ = net.SplitHostPort(clientIP)
    }

    if cmdUpper == "LOGIN" {
        // Explicitly reject all IPs not listed in api_access
        if !isTokenAllowed(clientIP) {
            if cfg.Logging && logger != nil {
                logger.Printf("[DENY] [IP: %s] LOGIN blocked before AUTH", clientIP)
            }
            http.Error(w, "Forbidden", http.StatusForbidden)
            return
        }

        // Now safe to continue with AUTH
        sessionResp, err := talkToCCED(CCERequest{
            Cmd:      "AUTH",
            User:     "api-admin",
            Password: apiAdminPassword,
        }, clientIP)

        if err != nil || sessionResp.Status != 201 {
            http.Error(w, "Login failed", http.StatusUnauthorized)
            return
        }

        sessionID, ok := sessionResp.Data["sessionid"].(string)
        if !ok || sessionID == "" {
            http.Error(w, "No session ID received", http.StatusBadGateway)
            return
        }

        apiToken := generateRandomToken(64)
        expiry := time.Now().Add(cfg.TokenLifetime)

        tokenSessionMutex.Lock()
        tokenSessionMap[apiToken] = TokenEntry{
            Username:  "api-admin",
            SessionID: sessionID,
            Expires:   expiry,
        }
        tokenSessionMutex.Unlock()

        resp := CCEResponse{
            Status:  201,
            Message: "TOKEN ISSUED",
            Data: map[string]interface{}{
                "token":   apiToken,
                "expires": expiry.Format(time.RFC3339),
            },
        }

        json.NewEncoder(w).Encode(resp)
        return
    }

    // Resolve token if present
    if req.Token != "" && req.User == "" && req.SessionId == "" {
        tokenSessionMutex.Lock()
        entry, ok := tokenSessionMap[req.Token]
        tokenSessionMutex.Unlock()

        if !ok || time.Now().After(entry.Expires) {
            http.Error(w, "Invalid or expired token", http.StatusUnauthorized)
            return
        }

        req.User = entry.Username
        req.SessionId = entry.SessionID
    }

    if !isIPAllowed(clientIP) {
        http.Error(w, "Forbidden", http.StatusForbidden)
        if cfg.Logging && logger != nil {
            logger.Printf("[DENY] [IP: %s] Access denied by api_access restriction", clientIP)
        }
        return
    }

    if cmdUpper == "AUTH" || cmdUpper == "AUTHKEY" {
        if clientIP != "127.0.0.1" && clientIP != "::1" {
            if isRateLimited(clientIP) {
                http.Error(w, "Too many authentication attempts", http.StatusTooManyRequests)
                if cfg.Logging && logger != nil {
                    logger.Printf("[RATE LIMIT] [IP: %s] Too many AUTH attempts", clientIP)
                }
                return
            }
        }
    }

    // BEGIN starts a transaction
    if cmdUpper == "BEGIN" {
        transactionLock.Lock()
        transactionQueues[req.SessionId] = &transactionBuffer{}
        transactionLock.Unlock()

        resp := CCEResponse{Status: 201, Message: "BEGIN OK"}
        logRequest(r, req, resp.Status, resp.Message, nil, time.Since(startTime))
        w.Header().Set("Content-Type", "application/json")
        json.NewEncoder(w).Encode(resp)
        return
    }

    // COMMIT finalizes the transaction
    if cmdUpper == "COMMIT" {
        transactionLock.Lock()
        tq, ok := transactionQueues[req.SessionId]
        transactionLock.Unlock()

        if !ok {
            http.Error(w, "No active transaction", http.StatusBadRequest)
            return
        }

        cc, err := openAuthedSocket(req.User, req.SessionId)
        if err != nil {
            transactionLock.Lock()
            delete(transactionQueues, req.SessionId)
            transactionLock.Unlock()

            logger.Printf("COMMIT failed: %v", err)
            resp := CCEResponse{Status: 401, Message: "AUTHKEY failed: session invalid or expired"}
            logRequest(r, req, resp.Status, resp.Message, err, time.Since(startTime))
            w.Header().Set("Content-Type", "application/json")
            json.NewEncoder(w).Encode(resp)
            return
        }

        cc.conn.SetDeadline(time.Now().Add(cfg.SocketReadTimeout))
        defer cc.conn.Close()

        logger.Printf("Transaction queue for session %s: %#v", req.SessionId, tq.commands)

        // Send queued commands
        var results []map[string]interface{}
        var topLevelErrors []map[string]interface{}
        var transactionFailed bool
        var commands []string

        // Process each command in the transaction
        for i, queuedCmd := range tq.commands {
            // Sanitize command for logging
            sanitizedCmd := sanitizeCommand(queuedCmd)
            if cfg.Logging && logger != nil {
                logger.Printf(">> COMMIT sending [%d]: %s", i, sanitizedCmd)
            }
            resp, err := sendCommandOverSocket(cc, queuedCmd, true)
            commands = append(commands, queuedCmd)
            logger.Printf("<< Response [%d]: %#v", i, resp)

            entry := map[string]interface{}{
                "index":   i,
                "command": sanitizedCmd,
                "status":  resp.Status,
                "message": resp.Message,
            }
            if err != nil {
                entry["error"] = err.Error()
            }

            // Log the response and check for errors
            logger.Printf("Checking response status for command [%d]: %d - %s", i, resp.Status, resp.Message)

            // Check for known error conditions
            if resp.Status == 302 {
                logger.Printf("Command failed with 302 BADDATA: %s", sanitizedCmd)
                transactionFailed = true
                topLevelErrors = append(topLevelErrors, entry)
            } else if resp.Status == 401 {
                logger.Printf("Command failed with 401 FAIL: %s", sanitizedCmd)
                transactionFailed = true
                topLevelErrors = append(topLevelErrors, entry)
            } else if resp.Status >= 300 || err != nil {
                logger.Printf("Command failed with status %d: %s", resp.Status, sanitizedCmd)
                transactionFailed = true
                topLevelErrors = append(topLevelErrors, entry)
            }

            results = append(results, entry)
        }

        // Always send COMMIT, even if earlier commands failed
        finalResp := CCEResponse{
            Status:  201,
            Message: "COMMIT successful",
            Data: map[string]interface{}{
                "results": results,
            },
        }

        // Handle the transaction failure or success
        if transactionFailed {
            finalResp.Status = 409
            finalResp.Message = "One or more commands in the transaction failed."
            finalResp.Data["errors"] = topLevelErrors
            logger.Printf("COMMIT failed due to errors: %v", topLevelErrors)
        }

        // Clean up the transaction queue
        transactionLock.Lock()
        delete(transactionQueues, req.SessionId)
        transactionLock.Unlock()

        logRequest(r, req, finalResp.Status, finalResp.Message, err, time.Since(startTime))
        w.Header().Set("Content-Type", "application/json")
        json.NewEncoder(w).Encode(finalResp)
        return
    }

    // Queue command if within transaction (but not BYE)
    if isWithinTransaction(req.SessionId) && cmdUpper != "BYE" {
        cmdStr, _ := buildCommand(req)
        if cmdStr != "" {
            transactionLock.Lock()
            transactionQueues[req.SessionId].commands = append(transactionQueues[req.SessionId].commands, cmdStr)
            transactionLock.Unlock()

            resp := CCEResponse{Status: 100, Message: "QUEUED"}
            logRequest(r, req, resp.Status, resp.Message, nil, time.Since(startTime))
            w.Header().Set("Content-Type", "application/json")
            json.NewEncoder(w).Encode(resp)
            return
        }
    }

    // Log Token usage:
    if req.Token != "" && logger != nil {
        logWithContext(clientIP, req.User, "[TOKEN AUTH] [Token: %.12s...]", req.Token)
    }

    // Resolve token if present
    if req.Token != "" {
        tokenSessionMutex.Lock()
        entry, ok := tokenSessionMap[req.Token]
        tokenSessionMutex.Unlock()

        if !ok || time.Now().After(entry.Expires) {
            http.Error(w, "Invalid or expired token", http.StatusUnauthorized)
            return
        }

        req.User = entry.Username
        req.SessionId = entry.SessionID
    }

    // Flush transaction if BYE - and also destroy used single-use Token
    if cmdUpper == "BYE" {
        // Invalidate token
        if req.Token != "" {
            tokenSessionMutex.Lock()
            delete(tokenSessionMap, req.Token)
            tokenSessionMutex.Unlock()

            if cfg.Logging && logger != nil {
                logger.Printf("[TOKEN INVALIDATED] [%s] on BYE", req.Token)
            }
        }

        transactionLock.Lock()
        delete(transactionQueues, req.SessionId)
        transactionLock.Unlock()
    }

    // Special handlers
    if cmdUpper == "FINDX" {
        resp, err := handleFindx(&req, clientIP)
        logRequest(r, req, resp.Status, resp.Message, err, time.Since(startTime))
        if err != nil {
            http.Error(w, fmt.Sprintf("CCE backend error: %s", err), http.StatusBadGateway)
            return
        }
        w.Header().Set("Content-Type", "application/json")
        json.NewEncoder(w).Encode(resp)
        return
    }

    if cmdUpper == "GETOBJECT" {
        resp, err := handleGetObject(&req, clientIP)
        logRequest(r, req, resp.Status, resp.Message, err, time.Since(startTime))
        if err != nil {
            http.Error(w, fmt.Sprintf("CCE backend error: %s", err), http.StatusBadGateway)
            return
        }
        w.Header().Set("Content-Type", "application/json")
        json.NewEncoder(w).Encode(resp)
        return
    }

    if cmdUpper == "GETALL" {
        resp, err := handleGetAll(&req, clientIP)
        logRequest(r, req, resp.Status, resp.Message, err, time.Since(startTime))
        if err != nil {
            http.Error(w, fmt.Sprintf("CCE backend error: %s", err), http.StatusBadGateway)
            return
        }
        w.Header().Set("Content-Type", "application/json")
        json.NewEncoder(w).Encode(resp)
        return
    }

    // Default: send command
    resp, err := talkToCCED(req, clientIP)
    duration := time.Since(startTime)

    logRequest(r, req, resp.Status, resp.Message, err, duration)

    if err != nil {
        http.Error(w, fmt.Sprintf("CCE backend error: %s", err), http.StatusBadGateway)
        return
    }

    httpStatus := http.StatusOK
    switch resp.Status {
    case 304:
        httpStatus = http.StatusForbidden
    case 401:
        httpStatus = http.StatusUnauthorized
    case 402, 403:
        httpStatus = http.StatusBadRequest
    case 400:
        httpStatus = http.StatusServiceUnavailable
    case 500:
        httpStatus = http.StatusInternalServerError
    default:
        if resp.Status >= 300 {
            httpStatus = http.StatusBadGateway
        }
    }

    w.Header().Set("Content-Type", "application/json")
    w.WriteHeader(httpStatus)
    json.NewEncoder(w).Encode(resp)
}

func multiOidTransaction(req CCERequest, clientIP string) (CCEResponse, error) {
    conn, err := net.DialTimeout("unix", cfg.UnixSocket, 5*time.Second)
    if err != nil {
        return CCEResponse{Status: 500, Message: "Failed to connect to CCEd socket"}, err
    }
    defer conn.Close()

    socketWriter := bufio.NewWriter(conn)
    if req.SessionId != "" {
        authLine := fmt.Sprintf("AUTHKEY %s %s\n", req.User, req.SessionId)
        fmt.Fprint(socketWriter, authLine)
        if cfg.Logging && logger != nil {
            logWithContext(clientIP, req.User, ">> Sending command: %s", sanitizeCommand(authLine))
        }
    }

    for _, oid := range req.OIDs {
        switch strings.ToUpper(req.Cmd) {
        case "GET":
            if req.Namespace != "" {
                fmt.Fprintf(socketWriter, "GET %s . %s\n", oid, req.Namespace)
            } else {
                fmt.Fprintf(socketWriter, "GET %s\n", oid)
            }
        case "NAMES":
            fmt.Fprintf(socketWriter, "NAMES %s\n", oid)
        default:
            return CCEResponse{Status: 400, Message: "Unsupported batch command"}, nil
        }
    }

    fmt.Fprintln(socketWriter, "BYE")
    socketWriter.Flush()

    scanner := bufio.NewScanner(conn)
    response := CCEResponse{Status: 201, Message: "OK", Data: map[string]interface{}{"results": []map[string]interface{}{}}}
    currentData := map[string]string{}
    var allResults []map[string]interface{}

    for scanner.Scan() {
        line := scanner.Text()
        if cfg.DebugLog && logger != nil {
            logger.Printf("<< %s", line)
        }

        if len(line) < 3 {
            continue
        }
        codeStr := line[:3]
        code, err := strconv.Atoi(codeStr)
        if err != nil {
            continue
        }

        switch code {
        case 102, 103:
            parts := strings.SplitN(line[4:], "=", 2)
            if len(parts) == 2 {
                key := strings.ToLower(strings.TrimSpace(strings.TrimPrefix(parts[0], "DATA ")))
                val := strings.Trim(strings.TrimSpace(parts[1]), "\"")
                currentData[key] = val
            }
        case 201:
            if len(currentData) > 0 {
                entry := map[string]interface{}{}
                for k, v := range currentData {
                    entry[k] = v
                }
                allResults = append(allResults, entry)
                currentData = map[string]string{}
            }
        }
    }

    response.Data["results"] = allResults
    return response, nil
}

func talkToCCED(req CCERequest, clientIP string) (CCEResponse, error) {
    if len(req.OIDs) > 0 {
        return multiOidTransaction(req, clientIP)
    }

    conn, err := net.DialTimeout("unix", cfg.UnixSocket, 5*time.Second)
    if err != nil {
        return CCEResponse{Status: 500, Message: "Failed to connect to CCEd socket"}, err
    }
    defer conn.Close()

    socketWriter := bufio.NewWriter(conn)
    cmdUpper := strings.ToUpper(req.Cmd)
    authStart := time.Now()
    var commands []string
    var sanitizedCommands []string

    var authLine string
    if req.SessionId != "" && cmdUpper != "AUTH" && cmdUpper != "AUTHKEY" {
        authLine = fmt.Sprintf("AUTHKEY \"%s\" \"%s\"", req.User, req.SessionId)
        fmt.Fprintf(socketWriter, "%s\n", authLine)
        commands = append(commands, authLine)
        sanitizedCommands = append(sanitizedCommands, sanitizeCommand(authLine))
    } else if cmdUpper == "AUTH" && req.User != "" && req.Password != "" {
        authLine = fmt.Sprintf("AUTH \"%s\" \"%s\"", req.User, req.Password)
        fmt.Fprintf(socketWriter, "%s\n", authLine)
        commands = append(commands, authLine)
        sanitizedCommands = append(sanitizedCommands, sanitizeCommand(authLine))
    } else if cmdUpper == "AUTHKEY" && req.User != "" && req.SessionId != "" {
        authLine = fmt.Sprintf("AUTHKEY \"%s\" \"%s\"", req.User, req.SessionId)
        fmt.Fprintf(socketWriter, "%s\n", authLine)
        commands = append(commands, authLine)
        sanitizedCommands = append(sanitizedCommands, sanitizeCommand(authLine))
    }

    command, sanitizedCommand := buildCommand(req)
    if command != "" {
        fmt.Fprintln(socketWriter, command)
        commands = append(commands, command)
        // Apply additional sanitization to catch = syntax
        sanitizedCommand = sanitizeCommand(sanitizedCommand)
        sanitizedCommands = append(sanitizedCommands, sanitizedCommand)
        // Debug logging
        if cfg.DebugLog && logger != nil {
            logger.Printf("talkToCCED: command=%s, sanitizedCommand=%s", command, sanitizedCommand)
        }
    }

    // Always append BYE
    commands = append(commands, "BYE")
    sanitizedCommands = append(sanitizedCommands, "BYE")
    fmt.Fprintln(socketWriter, "BYE")
    socketWriter.Flush()

    // Log the commands with sanitized output
    for i, _ := range commands {
        logWithContext(clientIP, req.User, ">> Sending command: %s", sanitizedCommands[i])
    }

    scanner := bufio.NewScanner(conn)
    response := CCEResponse{Data: make(map[string]interface{})}
    dataFields := make(map[string]string)
    var oidlist []string
    var namespaces []string
    var classes []string
    var errorsList []map[string]interface{}
    var infosList []map[string]interface{}
    statusCode := 0
    finalCheck := 0

    for scanner.Scan() {
        line := scanner.Text()

        if cfg.DebugLog && logger != nil {
            logger.Printf("<< %s", line)
        }

        if len(line) < 3 {
            continue
        }
        codeStr := line[:3]
        code, err := strconv.Atoi(codeStr)
        if err != nil {
            continue
        }
        finalCheck = code

        switch code {
        case 102, 103:
            parts := strings.SplitN(line[4:], "=", 2)
            if len(parts) == 2 {
                key := strings.TrimPrefix(strings.TrimSpace(parts[0]), "DATA ")
                val := strings.Trim(strings.TrimSpace(parts[1]), "\"")
                dataFields[key] = val
            }
        case 104:
            parts := strings.Fields(line)
            if len(parts) >= 3 && strings.ToUpper(parts[1]) == "OBJECT" {
                oid := parts[2]
                if strings.ToUpper(req.Cmd) == "WHOAMI" {
                    response.Data["oid"] = oid
                } else {
                    oidlist = append(oidlist, oid)
                }
            }
        case 105:
            ns := strings.TrimSpace(strings.TrimPrefix(line, "105 NAMESPACE"))
            namespaces = append(namespaces, ns)
        case 106:
            infosList = append(infosList, map[string]interface{}{
                "code":    code,
                "message": strings.TrimSpace(line[4:]),
            })
        case 109:
            parts := strings.Fields(line)
            if len(parts) >= 3 {
                response.Data["sessionid"] = parts[2]
            }
        case 110:
            cls := strings.TrimSpace(strings.TrimPrefix(line, "110 CLASS"))
            classes = append(classes, cls)
        case 201:
            statusCode = 201
        default:
            if code >= 300 && code <= 999 {
                if code > statusCode {
                    statusCode = code
                }
                errorsList = append(errorsList, map[string]interface{}{
                    "code":    code,
                    "message": strings.TrimSpace(line[4:]),
                })
            }
        }
    }

    // Special handling: If command was GET and no DATA was returned, treat it as "not found"
    if strings.ToUpper(req.Cmd) == "GET" && len(dataFields) == 0 {
        response.Status = 404
        response.Message = "Object or namespace not found"
        if cfg.DebugLog && logger != nil {
            logger.Printf("GET returned no DATA for OID=%s Namespace=%s", req.OID, req.Namespace)
        }
        return response, fmt.Errorf("GET failed: object or namespace not found")
    }

    response.Data["DATA"] = dataFields

    if len(oidlist) > 0 {
        response.Data["oidlist"] = oidlist
    }
    if len(namespaces) > 0 {
        response.Data["namespaces"] = namespaces
    }
    if len(classes) > 0 {
        response.Data["classes"] = classes
    }
    if len(errorsList) > 0 {
        response.Data["errors"] = errorsList
    }
    if len(infosList) > 0 {
        response.Data["infos"] = infosList
    }

    if statusCode == 0 && finalCheck != 0 {
        statusCode = finalCheck
    }
    if statusCode == 0 {
        statusCode = 500
    }
    response.Status = statusCode
    response.Message = cscpCodeToText(statusCode)

    elapsed := time.Since(authStart)
    if cmdUpper == "AUTH" && cfg.Logging && logger != nil {
        statusLabel := "OK"
        if statusCode != 201 {
            statusLabel = "FAIL"
        }
        logWithContext(clientIP, req.User, "[AUTH %s] => %d %s (took %dms)", statusLabel, statusCode, cscpCodeToText(statusCode), elapsed.Milliseconds())
    } else if cmdUpper == "AUTHKEY" && statusCode != 201 && cfg.Logging && logger != nil {
        logWithContext(clientIP, req.User, "[AUTHKEY FAIL] => %d %s (took %dms)", statusCode, cscpCodeToText(statusCode), elapsed.Milliseconds())
    }

    return response, scanner.Err()
}

func cscpCodeToText(code int) string {
    switch code {
    case 100:
        return "HEADER"
    case 101:
        return "EVENT"
    case 102:
        return "DATA"
    case 103:
        return "NEWDATA"
    case 104:
        return "OBJECT"
    case 105:
        return "NAMESPACE"
    case 106:
        return "INFO"
    case 107:
        return "CREATE"
    case 108:
        return "DESTROY"
    case 109:
        return "SESSIONID"
    case 110:
        return "CLASS"
    case 200:
        return "READY"
    case 201:
        return "OK"
    case 202:
        return "GOODBYE"
    case 300:
        return "UNKNOWN OBJECT"
    case 301:
        return "UNKNOWN CLASS"
    case 302:
        return "BAD DATA"
    case 303:
        return "UNKNOWN NAMESPACE"
    case 304:
        return "PERMISSION DENIED"
    case 305:
        return "WARNING"
    case 306:
        return "COMMAND ERROR"
    case 307:
        return "OUT OF MEMORY"
    case 400:
        return "NOT READY"
    case 401:
        return "FAIL"
    case 402:
        return "BAD COMMAND"
    case 403:
        return "BAD PARAMETERS"
    case 998:
        return "SHUTDOWN"
    case 999:
        return "ON FIRE"
    default:
        return fmt.Sprintf("CSCP %d", code)
    }
}

func buildCommand(req CCERequest) (string, string) {
    cmd := strings.ToUpper(req.Cmd)

    // These commands are handled separately — don't emit them here
    if cmd == "AUTH" || cmd == "AUTHKEY" {
        return "", ""
    }

    var command, sanitizedCommand string
    switch cmd {
    case "GET":
        if req.Namespace != "" {
            command = fmt.Sprintf("GET %s . %s", req.OID, req.Namespace)
        } else {
            command = fmt.Sprintf("GET %s", req.OID)
        }
        sanitizedCommand = command
    case "FIND":
        args := ""
        sanitizedArgs := ""
        for k, v := range req.Args {
            value := escapeCCEValue(v)
            args += fmt.Sprintf("%s=\"%s\" ", k, value)
            if k == "password" {
                sanitizedArgs += fmt.Sprintf("%s=\"xxx\" ", k)
            } else {
                sanitizedArgs += fmt.Sprintf("%s=\"%s\" ", k, value)
            }
        }
        command = fmt.Sprintf("FIND %s %s", req.Class, strings.TrimSpace(args))
        sanitizedCommand = fmt.Sprintf("FIND %s %s", req.Class, strings.TrimSpace(sanitizedArgs))
    case "FINDX":
        command = "__FINDX__"
        sanitizedCommand = command
    case "CREATE":
        args := ""
        sanitizedArgs := ""
        for k, v := range req.Args {
            value := escapeCCEValue(v)
            args += fmt.Sprintf("%s=\"%s\" ", k, value)
            if k == "password" {
                sanitizedArgs += fmt.Sprintf("%s=\"xxx\" ", k)
            } else {
                sanitizedArgs += fmt.Sprintf("%s=\"%s\" ", k, value)
            }
        }
        command = fmt.Sprintf("CREATE %s %s", req.Class, strings.TrimSpace(args))
        sanitizedCommand = fmt.Sprintf("CREATE %s %s", req.Class, strings.TrimSpace(sanitizedArgs))
    case "SET":
        args := ""
        sanitizedArgs := ""
        for k, v := range req.Args {
            value := escapeCCEValue(v)
            args += fmt.Sprintf("%s=\"%s\" ", k, value)
            if k == "password" {
                sanitizedArgs += fmt.Sprintf("%s=\"xxx\" ", k)
            } else {
                sanitizedArgs += fmt.Sprintf("%s=\"%s\" ", k, value)
            }
        }
        if req.Namespace != "" {
            command = fmt.Sprintf("SET %s . %s %s", req.OID, req.Namespace, strings.TrimSpace(args))
            sanitizedCommand = fmt.Sprintf("SET %s . %s %s", req.OID, req.Namespace, strings.TrimSpace(sanitizedArgs))
        } else {
            command = fmt.Sprintf("SET %s %s", req.OID, strings.TrimSpace(args))
            sanitizedCommand = fmt.Sprintf("SET %s %s", req.OID, strings.TrimSpace(sanitizedArgs))
        }
        // Additional sanitization for = syntax
        sanitizedCommand = sanitizeCommand(sanitizedCommand)
        // Debug logging
        if cfg.DebugLog && logger != nil {
            logger.Printf("buildCommand: command=%s, sanitizedCommand=%s", command, sanitizedCommand)
        }
    case "DESTROY":
        command = fmt.Sprintf("DESTROY %s", req.OID)
        sanitizedCommand = command
    case "NAMES":
        if req.OID != "" {
            command = fmt.Sprintf("NAMES %s", req.OID)
        } else if req.Class != "" {
            command = fmt.Sprintf("NAMES %s", req.Class)
        }
        sanitizedCommand = command
    case "BEGIN":
        command = "BEGIN"
        sanitizedCommand = command
    case "BYE":
        if req.Args != nil {
            if flag, ok := req.Args["flag"]; ok && flag != "" {
                command = fmt.Sprintf("BYE %s", flag)
                sanitizedCommand = command
            }
        }
        command = "BYE"
        sanitizedCommand = command
    case "ENDKEY":
        command = "ENDKEY"
        sanitizedCommand = command
    case "CLASSES":
        command = "CLASSES"
        sanitizedCommand = command
    case "HELP":
        command = "HELP"
        sanitizedCommand = command
    case "SUSPEND":
        command = "SUSPEND"
        sanitizedCommand = command
    case "RESUME":
        command = "RESUME"
        sanitizedCommand = command
    case "WHOAMI":
        command = "WHOAMI"
        sanitizedCommand = command
    default:
        command = req.Cmd
        sanitizedCommand = command
    }

    return command, sanitizedCommand
}

func logRequest(r *http.Request, req CCERequest, status int, message string, err error, duration time.Duration) {
    if !cfg.Logging || logger == nil {
        return
    }

    clientIP := extractClientIP(r)
    if ip := r.Header.Get("X-Real-IP"); ip != "" {
        clientIP = ip
    }

    username := "-"
    if req.User != "" {
        username = req.User
    }

    // Construct the high-level command directly
    cmdUpper := strings.ToUpper(req.Cmd)
    command := ""
    switch cmdUpper {
    case "GETALL":
        if req.Class != "" {
            command = fmt.Sprintf("GETALL %s", req.Class)
        } else if len(req.OIDs) > 0 {
            command = fmt.Sprintf("GETALL oids=[%s]", strings.Join(req.OIDs, ","))
        } else {
            command = "GETALL"
        }
    case "FINDX":
        command = fmt.Sprintf("FINDX %s", req.Class)
    case "GETOBJECT":
        command = fmt.Sprintf("GETOBJECT %s", req.Class)
    case "BEGIN":
        command = "BEGIN"
    case "COMMIT":
        command = "COMMIT"
    case "AUTHKEY":
        command = "AUTHKEY"
    default:
        // Fallback to buildCommand for other commands
        _, sanitizedCommand := buildCommand(req)
        command = sanitizedCommand
        if command == "" && cfg.DebugLog && logger != nil {
            logger.Printf("logRequest: empty command after buildCommand, raw cmd: %s", req.Cmd)
        }
        // Additional sanitization for SET commands
        if strings.HasPrefix(command, "SET ") {
            command = sanitizeCommand(command)
        }
    }

    // Skip CMD: logging for BYE commands
    if strings.ToUpper(req.Cmd) == "BYE" {
        return
    }

    // Log CMD: line
    logLine := fmt.Sprintf("[IP: %s] [User: %s] CMD: \"%s\"", clientIP, username, command)
    if err != nil {
        logLine += fmt.Sprintf(" => ERROR: %v", err)
    } else {
        logLine += fmt.Sprintf(" => %d %s", status, message)
    }
    logLine += fmt.Sprintf(" (took %dms)", duration.Milliseconds())
    logger.Println(logLine)
}

func handleFindx(req *CCERequest, clientIP string) (CCEResponse, error) {
    // Step 1: Build a standard FIND request
    findReq := CCERequest{
        Cmd:       "FIND",
        Class:     req.Class,
        Args:      req.Args,
        SortType:  req.SortType,
        SortProp:  req.SortProp,
        User:      req.User,
        SessionId: req.SessionId,
    }

    // Step 2: Run the FIND
    findResp, err := talkToCCED(findReq, clientIP)
    if err != nil {
        return findResp, err
    }

    // Step 3: Iterate over the result OIDs and GET each object
    filteredOIDs := []string{}
    if oids, ok := findResp.Data["oidlist"].([]string); ok {
        for _, oid := range oids {
            getReq := CCERequest{
                Cmd:       "GET",
                OID:       oid,
                User:      req.User,
                SessionId: req.SessionId,
            }
            getResp, err := talkToCCED(getReq, clientIP)
            if err != nil || getResp.Status != 201 {
                continue
            }

            obj := map[string]string{}
            if rawData, ok := getResp.Data["DATA"].(map[string]string); ok {
                for k, v := range rawData {
                    obj[strings.TrimSpace(k)] = v
                }
            }

            matches := true
            for rk, rv := range req.RegexArgs {
                rkClean := strings.TrimSpace(rk)
                val, ok := obj[rkClean]
                if !ok {
                    matches = false
                    break
                }
                val = strings.TrimSpace(val)
                re, err := regexp.Compile(rv)
                if err != nil || !re.MatchString(val) {
                    matches = false
                    break
                }
            }

            if matches {
                filteredOIDs = append(filteredOIDs, oid)
            }
        }
    }

    return CCEResponse{
        Status:  201,
        Message: "OK",
        Data: map[string]interface{}{
            "oidlist": filteredOIDs,
        },
    }, nil
}

func handleGetObject(req *CCERequest, clientIP string) (CCEResponse, error) {
    // Step 1: Build a FIND request to get the matching OID(s)
    findReq := CCERequest{
        Cmd:       "FIND",
        Class:     req.Class,
        Args:      req.Args,
        User:      req.User,
        SessionId: req.SessionId,
    }

    findResp, err := talkToCCED(findReq, clientIP)
    if err != nil {
        return CCEResponse{Status: 500, Message: "FIND failed"}, err
    }

    oids, ok := findResp.Data["oidlist"].([]string)
    if !ok || len(oids) == 0 {
        return CCEResponse{
            Status:  404,
            Message: "Object not found",
            Data:    map[string]interface{}{},
        }, nil
    }

    // Step 2: GET the first OID
    getReq := CCERequest{
        Cmd:       "GET",
        OID:       oids[0],
        Namespace: req.Namespace,
        User:      req.User,
        SessionId: req.SessionId,
    }

    getResp, err := talkToCCED(getReq, clientIP)
    if err != nil {
        return CCEResponse{Status: 500, Message: "GET failed"}, err
    }
    if getResp.Status != 201 {
        return getResp, nil
    }

    // Step 3: Return the full DATA
    return CCEResponse{
        Status:  201,
        Message: "OK",
        Data:    getResp.Data,
    }, nil
}

func handleGetAll(req *CCERequest, clientIP string) (CCEResponse, error) {
    var oidList []string

    // Step 1: Determine OID list
    if req.Class != "" {
        findReq := CCERequest{
            Cmd:       "FIND",
            Class:     req.Class,
            Args:      req.Args,
            SortType:  req.SortType,
            SortProp:  req.SortProp,
            User:      req.User,
            SessionId: req.SessionId,
        }
        findResp, err := talkToCCED(findReq, clientIP)
        if err != nil {
            return findResp, err
        }
        if oids, ok := findResp.Data["oidlist"].([]string); ok {
            oidList = oids
        }

        if cfg.Logging && logger != nil {
            logger.Printf("Resolved %d OIDs for GETALL %s: %v", len(oidList), req.Class, oidList)
        }
    } else if len(req.OIDs) > 0 {
        oidList = req.OIDs
    } else {
        return CCEResponse{Status: 403, Message: "Missing or invalid 'oids' or 'args'"}, nil
    }

    // Step 2: Open multiplexed socket
    cc, err := openAuthedSocket(req.User, req.SessionId)
    if err != nil {
        return CCEResponse{Status: 500, Message: "AUTHKEY failed"}, err
    }
    cc.conn.SetDeadline(time.Now().Add(cfg.SocketReadTimeout))
    defer cc.conn.Close()

    fullData := make(map[string]map[string]interface{})
    var commands []string

    // Step 3: Loop over OIDs
    for _, oid := range oidList {
        entry := make(map[string]interface{})

        // Get main object data
        mainCmd, _ := buildCommand(CCERequest{Cmd: "GET", OID: oid})
        getResp, err := sendCommandOverSocket(cc, mainCmd, true)
        commands = append(commands, mainCmd)
        if err != nil || getResp.Status != 201 {
            continue
        }
        if data, ok := getResp.Data["DATA"].(map[string]string); ok {
            entry["OBJECT"] = data
        } else if data, ok := getResp.Data["DATA"].(map[string]interface{}); ok {
            entry["OBJECT"] = data
        }

        // Get namespace list
        namesCmd, _ := buildCommand(CCERequest{Cmd: "NAMES", OID: oid})
        namesResp, err := sendCommandOverSocket(cc, namesCmd, true)
        commands = append(commands, namesCmd)
        if err != nil || namesResp.Status != 201 {
            fullData[oid] = entry
            continue
        }

        // Fetch each namespace
        if nsList, ok := namesResp.Data["namespaces"].([]string); ok {
            for _, ns := range nsList {
                nsCmd, _ := buildCommand(CCERequest{Cmd: "GET", OID: oid, Namespace: ns})
                nsResp, err := sendCommandOverSocket(cc, nsCmd, true)
                commands = append(commands, nsCmd)
                if err == nil && nsResp.Status == 201 {
                    if nsData, ok := nsResp.Data["DATA"].(map[string]string); ok {
                        entry[ns] = nsData
                    } else if nsData, ok := nsResp.Data["DATA"].(map[string]interface{}); ok {
                        entry[ns] = nsData
                    }
                }
            }
        }

        fullData[oid] = entry
    }

    // Step 4: Finalize with BYE
    fmt.Fprintln(cc.writer, "BYE")
    cc.writer.Flush()

    // Step 5: Drain GOODBYE response with timeout protection
    for {
        cc.conn.SetReadDeadline(time.Now().Add(cfg.SocketReadTimeout))
        if !cc.reader.Scan() {
            break
        }
        line := cc.reader.Text()
        if cfg.DebugLog && logger != nil {
            logger.Printf("<< %s", line)
        }
        if strings.HasPrefix(line, "202 ") {
            break
        }
    }

    return CCEResponse{
        Status:  201,
        Message: "OK",
        Data: map[string]interface{}{
            "objects": fullData,
        },
    }, nil
}

func isIPAllowed(ip string) bool {
    // Always allow loopback
    if ip == "127.0.0.1" || ip == "::1" {
        return true
    }

    parsedIP := net.ParseIP(ip)
    if parsedIP == nil {
        return false
    }

    for _, cidr := range cfg.APIAllowed {
        _, ipnet, err := net.ParseCIDR(cidr)
        if err != nil {
            // Try direct IP match instead of CIDR
            if cidr == ip {
                return true
            }
            continue
        }
        if ipnet.Contains(parsedIP) {
            return true
        }
    }
    return false
}

func isTokenAllowed(ip string) bool {
    parsedIP := net.ParseIP(ip)
    if parsedIP == nil {
        return false
    }

    for _, cidr := range cfg.APIAllowed {
        _, ipnet, err := net.ParseCIDR(cidr)
        if err != nil {
            // Try direct IP match
            if cidr == ip {
                return true
            }
            continue
        }
        if ipnet.Contains(parsedIP) {
            return true
        }
    }
    return false
}

func isRateLimited(ip string) bool {
    now := time.Now()
    limit := cfg.AuthFailLimit
    window := cfg.AuthBanTime

    rateLimitMutex.Lock()
    defer rateLimitMutex.Unlock()

    entry, exists := rateLimitMap[ip]
    if !exists {
        rateLimitMap[ip] = &authAttempt{times: []time.Time{now}}
        return false
    }

    // Remove old attempts
    filtered := []time.Time{}
    for _, t := range entry.times {
        if now.Sub(t) <= window {
            filtered = append(filtered, t)
        }
    }
    entry.times = filtered

    if len(filtered) >= limit {
        return true
    }

    entry.times = append(entry.times, now)
    return false
}

func isWithinTransaction(sessionId string) bool {
    transactionLock.Lock()
    defer transactionLock.Unlock()
    _, ok := transactionQueues[sessionId]
    return ok
}

func extractClientIP(r *http.Request) string {
    ip := r.Header.Get("X-Real-IP")
    if ip == "" {
        host, _, err := net.SplitHostPort(r.RemoteAddr)
        if err != nil {
            return r.RemoteAddr // fallback
        }
        ip = host
    }
    return ip
}

func generateRandomToken(n int) string {
    const letters = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
    b := make([]byte, n)
    _, err := rand.Read(b)
    if err != nil {
        log.Fatalf("Failed to generate random token: %v", err)
    }
    for i := range b {
        b[i] = letters[int(b[i])%len(letters)]
    }
    return string(b)
}

func loadApiAdminPassword() (string, error) {
    // Read the encrypted password
    encPass, err := os.ReadFile("/etc/cced-api/api-admin.passwd")
    if err != nil {
        return "", err
    }

    // Read the master key
    key, err := os.ReadFile("/etc/cced-api/master.key")
    if err != nil {
        return "", err
    }
    keyStr := strings.TrimSpace(string(key))

    // Create a temp file to write the encrypted data
    tmpEncFile, err := os.CreateTemp("", "enc-pass-*")
    if err != nil {
        return "", err
    }
    defer os.Remove(tmpEncFile.Name())

    if _, err := tmpEncFile.Write(encPass); err != nil {
        tmpEncFile.Close()
        return "", err
    }
    tmpEncFile.Close()

    // Run openssl to decrypt
    cmd := fmt.Sprintf("openssl enc -aes-256-cbc -pbkdf2 -d -salt -pass pass:%s -in %s", keyStr, tmpEncFile.Name())
    out, err := execCommand("/bin/sh", "-c", cmd)
    if err != nil {
        return "", fmt.Errorf("decryption failed: %v", err)
    }

    return strings.TrimSpace(out), nil
}

func execCommand(name string, args ...string) (string, error) {
    cmd := exec.Command(name, args...)
    output, err := cmd.CombinedOutput()
    return string(output), err
}

func ensureAccessFile(path string, allowed []string) error {
    // Load existing access file if present
    lines := []string{}
    existing := make(map[string]string)

    // Load existing access file if present
    if content, err := os.ReadFile(path); err == nil {
        for _, line := range strings.Split(string(content), "\n") {
            if line == "" || strings.HasPrefix(line, "#") {
                continue
            }
            parts := strings.SplitN(line, ":", 2)
            if len(parts) == 2 {
                existing[strings.TrimSpace(parts[0])] = strings.TrimSpace(parts[1])
            }
        }
    }

    // Only preserve entries in current allowed list
    for _, entry := range allowed {
        entry = strings.TrimSpace(entry)
        if entry == "" {
            continue
        }
        if strings.Contains(entry, "/") {
            // Check if valid CIDR
            if _, _, err := net.ParseCIDR(entry); err == nil {
                ip := entry
                secret, ok := existing[ip]
                if !ok {
                    secret = generateRandomToken(32)
                }
                lines = append(lines, fmt.Sprintf("%s:%s", ip, secret))
            }
        } else {
            // Plain IP (no CIDR)
            if net.ParseIP(entry) != nil {
                secret, ok := existing[entry]
                if !ok {
                    secret = generateRandomToken(32)
                }
                lines = append(lines, fmt.Sprintf("%s:%s", entry, secret))
            }
        }
    }

    // Write updated file
    return os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0600)
}

func validateClientSecret(ip, secret string) bool {
    content, err := os.ReadFile("/etc/cced-api/config/access")
    if err != nil {
        return false
    }
    clientIP := net.ParseIP(ip)
    if clientIP == nil {
        return false
    }

    for _, line := range strings.Split(string(content), "\n") {
        parts := strings.SplitN(line, ":", 2)
        if len(parts) != 2 {
            continue
        }
        entry := strings.TrimSpace(parts[0])
        storedSecret := strings.TrimSpace(parts[1])

        // Exact match
        if entry == ip {
            if secret == storedSecret {
                if cfg.Logging && logger != nil {
                    logger.Printf("[AUTH OK] Exact IP match for %s", ip)
                }
                return true
            } else {
                if cfg.Logging && logger != nil {
                    logger.Printf("[AUTH FAIL] Secret mismatch for exact IP %s", ip)
                }
                continue
            }
        }

        // CIDR match
        if _, cidr, err := net.ParseCIDR(entry); err == nil {
            if cidr.Contains(clientIP) {
                if secret == storedSecret {
                    if cfg.Logging && logger != nil {
                        logger.Printf("[AUTH OK] CIDR match for %s in %s", ip, entry)
                    }
                    return true
                } else {
                    if cfg.Logging && logger != nil {
                        logger.Printf("[AUTH FAIL] Secret mismatch for IP %s in %s", ip, entry)
                    }
                    continue
                }
            }
        }
    }

    if cfg.Logging && logger != nil {
        logger.Printf("[DENY] No valid access entry for IP %s", ip)
    }
    return false
}

func logWithContext(ip, user, msg string, args ...interface{}) {
    if user == "" {
        user = "none"
    }
    prefix := fmt.Sprintf("[IP: %s] [User: %s]", ip, user)
    if len(args) > 0 {
        logger.Printf(prefix+" "+msg, args...)
    } else {
        logger.Println(prefix + " " + msg)
    }
}

func handleServices(w http.ResponseWriter, r *http.Request) {
    clientIP := extractClientIP(r)

    // Localhost access requires no auth
    isLocal := clientIP == "127.0.0.1" || clientIP == "::1"

    var user, session string

    if !isLocal {
        // Enforce valid token or session for remote access
        token := r.Header.Get("X-Token")
        user = r.Header.Get("X-User")
        session = r.Header.Get("X-Session")

        if token != "" {
            tokenSessionMutex.Lock()
            entry, ok := tokenSessionMap[token]
            tokenSessionMutex.Unlock()

            if !ok || time.Now().After(entry.Expires) {
                http.Error(w, "Invalid or expired token", http.StatusUnauthorized)
                return
            }

            user = entry.Username
            session = entry.SessionID
        }

        if user == "" || session == "" {
            http.Error(w, "Unauthorized", http.StatusUnauthorized)
            return
        }

        // Validate AUTHKEY against CCEd socket
        cc, err := openAuthedSocket(user, session)
        if err != nil {
            http.Error(w, "AUTHKEY failed", http.StatusUnauthorized)
            return
        }
        defer cc.conn.Close()
    }

    // Read the daemon state file
    filePath := "/usr/sausalito/sessions/.sauce_serviced_daemon.state"
    data := map[string]interface{}{
        "file_count":  0,
        "event_files": []string{},
    }

    if contents, err := os.ReadFile(filePath); err == nil {
        var parsed map[string]interface{}
        if err := json.Unmarshal(contents, &parsed); err == nil {
            data = parsed
        }
    }

    w.Header().Set("Content-Type", "application/json")
    w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")
    json.NewEncoder(w).Encode(data)
}

func escapeCCEValue(text string) string {
    replacements := map[string]string{
        "\\": "\\\\",
        "\a": "\\a",
        "\b": "\\b",
        "\f": "\\f",
        "\n": "\\n",
        "\t": "\\t",
        "\"": "\\\"",
        "$":  "\\$",
        "&quot;": "\\\"",
        "&amp;":  "\\&",
        "&#39;":  "'",
        "&lt;":   "<",
        "&gt;":   ">",
    }

    for old, newVal := range replacements {
        text = strings.ReplaceAll(text, old, newVal)
    }

    // Escape non-ASCII characters to \uXXXX
    escaped := ""
    for _, r := range text {
        if r < 32 || r > 126 {
            escaped += fmt.Sprintf("\\u%04x", r)
        } else {
            escaped += string(r)
        }
    }

    return escaped
}

func getPrimaryInterface() string {
    out, err := exec.Command("ip", "route").Output()
    if err != nil {
        return "eth0" // fallback
    }

    lines := strings.Split(string(out), "\n")
    for _, line := range lines {
        if strings.HasPrefix(line, "default") && !strings.Contains(line, "linkdown") && !strings.Contains(line, "veth") {
            fields := strings.Fields(line)
            for i, field := range fields {
                if field == "dev" && i+1 < len(fields) {
                    return fields[i+1]
                }
            }
        }
    }

    // Fallback: get first interface from `ip -o link`
    linkOut, err := exec.Command("ip", "-o", "link", "show", "up").Output()
    if err == nil {
        lines := strings.Split(string(linkOut), "\n")
        for _, line := range lines {
            fields := strings.Fields(line)
            if len(fields) >= 2 {
                iface := strings.TrimSuffix(fields[1], ":")
                if !strings.HasPrefix(iface, "lo") && !strings.HasPrefix(iface, "veth") {
                    return iface
                }
            }
        }
    }

    return "eth0"
}

func main() {
    // Load config
    var err error
    cfgPtr, err := loadConfig("/etc/cced-api/config/cced-api.conf")
    if err != nil {
        log.Fatalf("Error loading config: %v", err)
    }
    cfg = *cfgPtr

    if err := ensureAccessFile("/etc/cced-api/config/access", cfg.APIAllowed); err != nil {
        log.Fatalf("Failed to initialize access file: %v", err)
    }

    // Fetch password of api-admin:
    apiAdminPassword, err = loadApiAdminPassword()
    if err != nil {
        log.Fatalf("Failed to load api-admin password: %v", err)
    }

    // Logging
    if cfg.Logging {
        logFile, err := os.OpenFile("/var/log/cced-api.log", os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
        if err != nil {
            log.Fatalf("Failed to open log file: %v", err)
        }
        // Combine stdout and log file writers
        multi := io.MultiWriter(os.Stdout, logFile)
        // Logger with no timestamp prefix
        logger = log.New(multi, "", 0)
    } else {
        logger = log.New(os.Stdout, "", log.LstdFlags)
    }

    // Token cleanup goroutine
    go func() {
        for {
            time.Sleep(5 * time.Minute)
            now := time.Now()
            tokenSessionMutex.Lock()
            for token, entry := range tokenSessionMap {
                if entry.Expires.Before(now) {
                    delete(tokenSessionMap, token)
                    if cfg.DebugLog && logger != nil {
                        if cfg.DebugLog {
                            logger.Printf("[EXPIRED] Token removed: %s", token)
                        } else {
                            logger.Printf("[EXPIRED] Token removed: %.12s...", token)
                        }
                    }
                }
            }
            tokenSessionMutex.Unlock()
        }
    }()

    // HTTP handler
    mux := http.NewServeMux()

    // Handle CCEd access:
    mux.HandleFunc("/v2/cce", handleCCE)

    // Handle Service restart visualization:
    mux.HandleFunc("/v2/services", handleServices)

    // Handle consolidated metrics (CPU, Memory, Network, Disk)
    mux.HandleFunc("/v2/metrics", handleMetrics)

    // HTTP support
    if cfg.EnableHTTP {
        go func() {
            log.Printf("cced-api (HTTP) listening on http://%s", cfg.ListenAddr)
            if err := http.ListenAndServe(cfg.ListenAddr, mux); err != nil && err != http.ErrServerClosed {
                log.Fatalf("HTTP server error: %v", err)
            }
        }()
    }

    // TLS setup
    cert, err := tls.LoadX509KeyPair(cfg.CertFile, cfg.KeyFile)
    if err != nil {
        log.Fatalf("Failed to load TLS key pair: %v", err)
    }
    tlsConfig := &tls.Config{Certificates: []tls.Certificate{cert}}
    if cfg.CACerts != "" {
        if caCerts, err := os.ReadFile(cfg.CACerts); err == nil && len(caCerts) > 0 {
            caPool := x509.NewCertPool()
            if caPool.AppendCertsFromPEM(caCerts) {
                tlsConfig.ClientCAs = caPool
                tlsConfig.ClientAuth = tls.VerifyClientCertIfGiven
            }
        }
    }

    srv := &http.Server{
        Addr:      cfg.ListenAddr,
        Handler:   mux,
        TLSConfig: tlsConfig,
    }

    // Graceful shutdown
    sigChan := make(chan os.Signal, 1)
    signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)
    go func() {
        <-sigChan
        log.Println("Signal received, shutting down server...")
        ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
        defer cancel()
        if err := srv.Shutdown(ctx); err != nil {
            log.Fatalf("Server shutdown failed: %v", err)
        }
        log.Println("Server gracefully stopped.")
    }()

    // Start HTTPS server
    log.Printf("cced-api (HTTPS) listening on https://%s", cfg.ListenAddr)
    if err := srv.ListenAndServeTLS("", ""); err != nil && err != http.ErrServerClosed {
        log.Fatalf("HTTPS server error: %v", err)
    }
}

// 
// Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
// Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
// All Rights Reserved.
// 
// 1. Redistributions of source code must retain the above copyright 
//    notice, this list of conditions and the following disclaimer.
// 
// 2. Redistributions in binary form must reproduce the above copyright 
//    notice, this list of conditions and the following disclaimer in 
//    the documentation and/or other materials provided with the 
//    distribution.
// 
// 3. Neither the name of the copyright holder nor the names of its 
//    contributors may be used to endorse or promote products derived 
//    from this software without specific prior written permission.
// 
// THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
// "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
// LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
// FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
// COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
// INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
// BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
// LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
// CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
// LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
// ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
// POSSIBILITY OF SUCH DAMAGE.
// 
// You acknowledge that this software is not designed or intended for 
// use in the design, construction, operation or maintenance of any 
// nuclear facility.
//