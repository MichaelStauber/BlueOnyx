<?php
/**
 * CceClient.php
 *
 * BlueOnyx CceClient for CodeIgniter - Version 4.0
 *
 * @package CceClient
 * @author Michael Stauber
 * @copyright Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
 * @copyright Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
 * @copyright Copyright (c) 2003 Sun Microsystems, Inc. All rights reserved.
 * @link http://www.blueonyx.it
 * @license http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version 4.0
 *
 * This version has three options to connect to CCEd:
 *
 * 1.) cce.so:   admserv-php CCE Zend API module (default, if available)
 * 2.) CCEd-API: If reachable and running
 * 3.) CCE.php:  Unix-Socket, always reachable if CCEd is running.
 *
 */

global $isCceClientDefined;
if ($isCceClientDefined) return;
$isCceClientDefined = true;

include_once("CceError.php");
include_once("System.php");
include_once("CCE.php");
include_once("CceApiClient.php");

class CceClient {
    // ==================== Backend Constants ====================
    public const BACKEND_CCE_SO = 'cce_so';   // Priority 1: native PHP extension cce.so
    public const BACKEND_API    = 'api';      // Priority 2: CCEd HTTP/JSON API
    public const BACKEND_SOCKET = 'socket';   // Priority 3: CCE.php Unix socket

    // ==================== Public properties (legacy compatibility) ====================
    public $handle;
    public $isConnected = false;
    public $Username;
    public $SessionId;
    public $Password;
    public $ERRORS = [];
    public $cce_replay = [];
    public $cce_replay_file;
    public $replay_errors = [];
    public $DEBUG;
    public $NOSO;

    // ==================== Internal ====================
    private string $backend = '';
    private $CCE;                    // CCE.php instance
    private $apiClient;              // CceApiClient instance
    private $CI;                     // Cached CI instance
    private $cced_api_ip = '127.0.0.1';
    private $cced_api_port = '9092';

    // ==================== Constructor ====================
    public function __construct() {
        $this->CI = &get_instance();
        $this->DEBUG = is_file("/etc/DEBUG");
        $this->NOSO = is_file("/etc/NOSO");
        $this->loadCceApiDefaultsFromConfig();

        if ((function_exists('ccephp_new')) && (!$this->NOSO)) {
            $this->backend = self::BACKEND_CCE_SO;
            $this->handle = ccephp_new();
            $this->connect();
            if ($this->backend === self::BACKEND_CCE_SO && $this->isConnected) {
                bx_error_log("CceClient: Using cce.so native (handle=" . $this->handle . ")");
            }
        }
        elseif (!is_file('/etc/NOAPI') && $this->isApiAvailable()) {
            $this->backend = self::BACKEND_API;
            if ($this->DEBUG) bx_error_log("CceClient: Using CCEd-API");
        }
        else {
            $this->backend = self::BACKEND_SOCKET;
            $this->CCE = new CCE();
            $this->handle = $this->CCE->ccephp_new();
            if ($this->DEBUG) bx_error_log("CceClient: Falling back to socket");
        }
    }

    public function getBackend(): string {
        return $this->backend;
    }

    // ==================== API Helpers ====================
    private function isApiAvailable(): bool {
        try {
            $client = $this->getApiClient();
            $response = $client->ping();
            return isset($response['status']) && in_array($response['status'], [200, 202]);
        }
        catch (Exception $e) {
            return false;
        }
    }

    public function setCceApiEndpoint($ip, $port) {
        $this->cced_api_ip = $ip;
        $this->cced_api_port = $port;
        $this->apiClient = new CceApiClient($ip, $port);
    }

    public function getCceApiEndpoint(): array {
        return ['ip' => $this->cced_api_ip, 'port' => $this->cced_api_port];
    }

    private function loadCceApiDefaultsFromConfig() {
        $conf_file = '/etc/cced-api/config/cced-api.conf';
        if (is_readable($conf_file)) {
            $lines = file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (preg_match('/^listen_address\s*=\s*(.*)$/', $line, $matches)) {
                    $parts = explode(':', $matches[1]);
                    if (count($parts) === 2) {
                        $this->cced_api_ip = $parts[0];
                        $this->cced_api_port = $parts[1];
                    }
                    break;
                }
            }
        }
        $this->apiClient = new CceApiClient($this->cced_api_ip, $this->cced_api_port);
    }

    private function getApiClient() {
        if (!$this->apiClient) {
            $ep = $this->getCceApiEndpoint();
            $this->apiClient = new CceApiClient($ep['ip'], $ep['port']);
        }
        return $this->apiClient;
    }

    private function switchToApiBackend(): bool {
        if (is_file('/etc/NOAPI')) {
            return false;
        }

        try {
            if ($this->isApiAvailable()) {
                $this->backend = self::BACKEND_API;
                $this->isConnected = true;
                bx_error_log("CceClient: cce.so unavailable, falling back to CCEd-API");
                if ($this->DEBUG) bx_error_log("CceClient: Falling back to CCEd-API");
                return true;
            }
        }
        catch (Exception $e) {
            if ($this->DEBUG) bx_error_log("CceClient: CCEd-API fallback failed: " . $e->getMessage());
        }

        return false;
    }

    private function switchToSocketBackend($socketPath): bool {
        $this->backend = self::BACKEND_SOCKET;
        $this->CCE = new CCE();
        $this->handle = $this->CCE->ccephp_new();
        $this->isConnected = $this->CCE->ccephp_connect($socketPath);
        bx_error_log("CceClient: cce.so unavailable, falling back to socket backend");
        if ($this->DEBUG) bx_error_log("CceClient: Falling back to socket (connected=" . ($this->isConnected ? 'yes' : 'no') . ")");
        return $this->isConnected;
    }

    private function normalizeFindxSortType($sorttype): string {
        if (is_int($sorttype)) {
            return $sorttype ? 'old_numeric' : 'ascii';
        }

        $sorttype = strtolower((string)$sorttype);
        if ($sorttype === 'numeric') {
            return 'old_numeric';
        }
        return $sorttype;
    }

    private function sanitizeFindVars($vars, string $command = 'FIND') {
        if (!is_array($vars)) {
            return [];
        }

        if (array_key_exists('cce_nocache', $vars)) {
            unset($vars['cce_nocache']);
            if ($this->DEBUG) {
                bx_error_log("CceClient: stripped cce_nocache from $command vars");
            }
        }

        return $vars;
    }

    // ==================== Legacy Compatibility ====================
    function setNative($CM) {} // deprecated
    function getNative() { return $this->backend; }

    function setUsername($Username = "") {
        $this->Username = $Username;
        $this->CI->setBX_SESSION_loginName($this->Username);
    }
    function getUsername() {
        if (!isset($this->Username)) {
            $BX_SESSION = $this->CI->getBX_SESSION();
            $this->Username = $BX_SESSION['loginName'] ?? '';
        }
        return $this->Username;
    }

    function setSessionId($SessionId = "") {
        $this->SessionId = $SessionId;
        $this->CI->setBX_SESSION_sessionId($this->SessionId);
    }
    function getSessionId() {
        if (!isset($this->SessionId)) {
            $BX_SESSION = $this->CI->getBX_SESSION();
            $this->SessionId = $BX_SESSION['sessionId'] ?? '';
        }
        return $this->SessionId;
    }

    function setPassword($Password = "") { $this->Password = $Password; }
    function getPassword() { return $this->Password; }

    function setDebug($DEBUG = false) { $this->DEBUG = $DEBUG; }
    function getDebug() { return $this->DEBUG; }

    // ==================== API Error Recording ====================
    private function recordApiErrors($res, $oid = '', $key = '') {
        if (!isset($res['status'])) {
            $this->ERRORS[] = ['code' => 500, 'oid' => $oid, 'key' => $key, 'message' => 'Invalid API response'];
            return;
        }

        $recorded = [];
        if (isset($res['data']['errors']) && is_array($res['data']['errors'])) {
            foreach ($res['data']['errors'] as $err) {
                if (isset($err['code']) && $err['code'] >= 300 && !in_array($err['code'], $recorded)) {
                    $message = preg_replace('/^(WARN|FAIL)\s*/', '', $err['message'] ?? '');
                    $this->ERRORS[] = [
                        'code' => $err['code'],
                        'oid' => $oid,
                        'key' => $key,
                        'message' => $message
                    ];
                    $recorded[] = $err['code'];
                }
            }
        }

        if ($res['status'] >= 300 && !in_array($res['status'], $recorded)) {
            $this->ERRORS[] = [
                'code' => $res['status'],
                'oid' => $oid,
                'key' => $key,
                'message' => $res['message'] ?? 'Unknown error'
            ];
        }
    }

    // ==================== Core Methods (3-way Backend) ====================
    function auth($userName, $password) {
        if ($this->DEBUG) bx_error_log("Command: AUTH \"$userName\" \"XXXX\"");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_auth($this->handle, $userName, $password);
        if ($this->backend === self::BACKEND_API) {
            $result = $this->getApiClient()->auth($userName, $password);
            $this->recordApiErrors(['status' => empty($result) ? 401 : 200]);
            if ($result) {
                $this->setUsername($userName); $this->setSessionId($result);
            }
            return $result ?: '';
        }
        return $this->CCE->ccephp_auth($userName, $password);
    }

    function authkey($userName, $sessionId) {
        if ($this->DEBUG) bx_error_log("Command: AUTHKEY \"$userName\" \"$sessionId\"");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_authkey($this->handle, $userName, $sessionId);
        if ($this->backend === self::BACKEND_API) {
            $result = $this->getApiClient()->authkey($userName, $sessionId) ?? '';
            $this->recordApiErrors(['status' => empty($result) ? 401 : 200]);
            return $result;
        }
        $this->CI->setBX_SESSION_sessionId($sessionId);
        return $this->CCE->ccephp_authkey($userName, $sessionId);
    }

    function whoami() {
        if ($this->DEBUG) bx_error_log("Command: WHOAMI");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_whoami($this->handle);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->whoami($this->getUsername(), $this->getSessionId());
            $this->recordApiErrors($res);
            return $res['data']['oid'] ?? '';
        }
        return $this->CCE->ccephp_whoami();
    }

    function bye($whodidit = '') {
        if ($whodidit && $this->DEBUG) bx_error_log("CceClient: BYE by $whodidit");
        $this->isConnected = false;
        if ($this->DEBUG) bx_error_log("Command: BYE");

        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_bye($this->handle);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->bye($this->getUsername(), $this->getSessionId());
            $this->recordApiErrors($res);
            return in_array($res['status'] ?? 0, [200, 201]);
        }
        return $this->CCE->ccephp_bye();
    }

    function begin() {
        if ($this->DEBUG) bx_error_log("Command: BEGIN");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_begin($this->handle);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->begin($this->getUsername(), $this->getSessionId());
            $this->recordApiErrors($res);
            return $res['data']['oid'] ?? 0;
        }
        return $this->CCE->ccephp_begin();
    }

    function commit() {
        if ($this->DEBUG) bx_error_log("Command: COMMIT");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_commit($this->handle);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->commit($this->getUsername(), $this->getSessionId());
            $this->recordApiErrors($res);
            return $res['data']['oid'] ?? 0;
        }
        return $this->CCE->ccephp_commit();
    }

    function endkey() {
        if ($this->DEBUG) bx_error_log("Command: ENDKEY");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_endkey($this->handle);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->endkey($this->getUsername(), $this->getSessionId());
            $this->recordApiErrors($res);
            return in_array($res['status'] ?? 0, [200, 201]);
        }
        return $this->CCE->ccephp_endkey();
    }

    function create($class, $vars = []) {
        if ($this->DEBUG) bx_error_log("Command: CREATE $class " . json_encode($vars));
        if (empty($vars)) { bx_error_log("CceClient::create(): ABORTING! Empty vars"); return 0; }

        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_create($this->handle, $class, $vars);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->create($this->getUsername(), $this->getSessionId(), $class, $vars);
            $this->recordApiErrors($res);
            if (in_array($res['status'] ?? 0, [200, 201])) {
                return $res['data']['oid'] ?? $res['data']['oidlist'][0] ?? 0;
            }
            return 0;
        }
        return $this->CCE->ccephp_create($class, $vars);
    }

    function destroy($oid) {
        if ($this->DEBUG) bx_error_log("Command: DESTROY $oid");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_destroy($this->handle, $oid);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->destroy($this->getUsername(), $this->getSessionId(), $oid);
            $this->recordApiErrors($res, $oid);
            return in_array($res['status'] ?? 0, [200, 201]);
        }
        return $this->CCE->ccephp_destroy($oid);
    }

    function find($class, $vars = []) {
        if ($this->DEBUG) bx_error_log("Command: FIND $class " . json_encode($vars));
        $vars = $this->sanitizeFindVars($vars, 'FIND');

        if ($this->backend === self::BACKEND_CCE_SO) {
            // Preserve legacy optional args for callers that pass them.
            return ccephp_find($this->handle, $class, $vars, 0, "");
        }

        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->find($this->getUsername(), $this->getSessionId(), $class, $vars);
            $this->recordApiErrors($res);
            return $res['data']['oidlist'] ?? [];
        }
        return $this->CCE->ccephp_find($class, $vars, "", 0);
    }

    function findx($class, $vars = [], $revars = [], $sorttype = "", $sortprop = "") {
        if ($this->DEBUG) bx_error_log("Command: FINDX $class");
        $vars = $this->sanitizeFindVars($vars, 'FINDX');

        // FINDX with only exact-match vars should behave like FIND.
        // Some backends are stricter about FINDX argument handling, so we
        // avoid that path unless regex or sorting is actually requested.
        if (empty($revars) && $sorttype === "" && $sortprop === "") {
            return $this->find($class, $vars);
        }

        if ($this->backend === self::BACKEND_CCE_SO) {
                return ccephp_findx(
                    $this->handle,
                    $class,
                    $vars,
                    $revars,
                    $this->normalizeFindxSortType($sorttype),
                    (string)$sortprop
                );
        }

        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->findx($this->getUsername(), $this->getSessionId(), $class, $vars, $revars, $sorttype, $sortprop);
            $this->recordApiErrors($res);
            return $res['data']['oidlist'] ?? [];
        }
        return $this->CCE->ccephp_findx($class, $vars, $revars, $sorttype, $sortprop);
    }

    function get($oid, $namespace = "") {
        if ($this->DEBUG) bx_error_log("Command: GET " . (is_string($oid) ? $oid : json_encode($oid)) . ($namespace ? " .$namespace" : ""));
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_get($this->handle, $oid, $namespace);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->get($this->getUsername(), $this->getSessionId(), $oid, $namespace);
            if (($res['status'] ?? 0) !== 201 || !isset($res['data']['DATA'])) {
                $this->recordApiErrors($res, $oid, $namespace);
                return -1;
            }
            $data = $res['data']['DATA'];
            if (isset($data['oid'])) { $data['OID'] = $data['oid']; unset($data['oid']); }
            $data['NAMESPACE'] = $namespace;
            return $data;
        }
        return $this->CCE->ccephp_get($oid, $namespace);
    }

    function set($oid, $namespace = "", $vars = []) {
        if ($this->DEBUG) bx_error_log("Command: SET $oid" . ($namespace ? " $namespace" : "") . " " . json_encode($vars));
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_set($this->handle, $oid, $namespace, $vars);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->set($this->getUsername(), $this->getSessionId(), $oid, $namespace, $vars);
            $this->recordApiErrors($res, $oid, $namespace);
            return in_array($res['status'] ?? 0, [200, 201]) ? 1 : 0;
        }
        return $this->CCE->ccephp_set($oid, $namespace, $vars);
    }

    function names($arg) {
        if ($this->DEBUG) bx_error_log("Command: NAMES $arg");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_names($this->handle, $arg);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->names($this->getUsername(), $this->getSessionId(), $arg);
            $this->recordApiErrors($res);
            return $res['data']['namespaces'] ?? [];
        }
        return $this->CCE->ccephp_names($arg);
    }

    function suspended() {
        if ($this->DEBUG) bx_error_log("Command: SUSPENDED");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_suspended($this->handle);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->suspended($this->getUsername(), $this->getSessionId());
            $this->recordApiErrors($res);
            return $res['data'] ?? false;
        }
        return $this->CCE->ccephp_suspended();
    }

    function suspend($reason) {
        if ($this->DEBUG) bx_error_log("Command: SUSPEND \"$reason\"");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_suspend($this->handle, $reason);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->suspend($this->getUsername(), $this->getSessionId(), $reason);
            $this->recordApiErrors($res);
            return in_array($res['status'] ?? 0, [200, 201]);
        }
        return $this->CCE->ccephp_suspend($reason);
    }

    function resume() {
        if ($this->DEBUG) bx_error_log("Command: RESUME");
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_resume($this->handle);
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->resume($this->getUsername(), $this->getSessionId());
            $this->recordApiErrors($res);
            return in_array($res['status'] ?? 0, [200, 201]);
        }
        return $this->CCE->ccephp_resume();
    }

    function isRollback() {
        if ($this->backend === self::BACKEND_CCE_SO) return ccephp_is_rollback($this->handle);
        return false; // API + Socket don't support this the same way
    }

    function connect($socketPath = "") {
        if ($socketPath == "") {
            $system = new System();
            $socketPath = $system->getConfig("ccedSocketPath");
        }
        if ($this->backend === self::BACKEND_CCE_SO) {
            $this->isConnected = ccephp_connect($this->handle, $socketPath);
            if (!$this->isConnected) {
                $this->ERRORS[] = [
                    'code' => 500,
                    'oid' => 0,
                    'key' => 'connect',
                    'message' => "cce.so connect failed for socket $socketPath"
                ];
                if ($this->DEBUG) {
                    bx_error_log("CceClient: cce.so connect failed for $socketPath");
                    return false;
                }
                if ($this->switchToApiBackend()) {
                    return $this->isConnected;
                }
                return $this->switchToSocketBackend($socketPath);
            }
        }
        elseif ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->ping();
            $this->isConnected = (isset($res['message']) && $res['message'] === 'PONG');
        }
        else {
            $this->isConnected = $this->CCE->ccephp_connect($socketPath);
        }
        return $this->isConnected;
    }

    function errors() {
        $errors = ($this->backend === self::BACKEND_CCE_SO) ? ccephp_errors($this->handle) : $this->ERRORS;
        $errorObjs = [];
        foreach ($errors as $e) {
            $ekey = is_array($e['key'] ?? null) ? reset($e['key']) : ($e['key'] ?? '');
            $errorObjs[] = new CceError(
                $e['code'] ?? 'UNKNOWN',
                $e['oid'] ?? '-1',
                $ekey,
                $e['message'] ?? 'Unknown error'
            );
        }
        return $errorObjs;
    }

    // ==================== getAll() - always prefers API ====================
    function getAll($class_or_oids, $args = []) {
        if ($this->backend !== self::BACKEND_SOCKET) {
            // Check if the API is actually reachable before routing through
            // it. If cced-api is down (e.g. during a CCEd restart), fall
            // through to the socket path instead of silently returning an
            // empty array.
            if (!is_file('/etc/NOAPI') && $this->isApiAvailable()) {
                $result = $this->getApiClient()->getAll($this->getUsername(), $this->getSessionId(), $class_or_oids, $args);
                // If the API returned a non-null result, use it. A null
                // result means the call failed even after retries.
                if ($result !== null) {
                    return $result;
                }
                if ($this->DEBUG) bx_error_log("CceClient::getAll(): API returned null, falling back to socket");
            }
            else {
                if ($this->DEBUG) bx_error_log("CceClient::getAll(): API unavailable, using socket fallback");
            }
        }
        // socket fallback
        $oids = $this->find($class_or_oids, $args);
        $output = '';
        $ret = $this->CI->serverScriptHelper->shell("/usr/sausalito/sbin/external_cce_get.pl --oid " . json_encode($oids), $output, 'root', $this->getSessionId());
        $data = json_decode($output, true);
        return ($ret == 0 && is_array($data)) ? $data : '-1';
    }

    // ==================== Convenience Methods (unchanged logic) ====================
    function destroyObjects($class, $vars = []) {
        if ($this->DEBUG) bx_error_log("Command: DestroyObjects $class " . json_encode($vars));
        $oids = $this->find($class, $vars);
        foreach ($oids as $oid) $this->destroy($oid);
    }

    function findSorted($class, $key, $vars = []) {
        if ($this->DEBUG) bx_error_log("Command: FINDSORTED $class $key");
        $vars = $this->sanitizeFindVars($vars, 'FINDSORTED');

        if ($this->backend === self::BACKEND_CCE_SO) {
            // Sorting is best-effort only in the legacy cce.so path.
            // Return the matching OIDs even if the native sorted find path is flaky.
            return $this->find($class, $vars);
        }

        return $this->findx($class, $vars, [], "ascii", $key);
    }

    function findNSorted($class, $key, $vars = []) {
        if ($this->DEBUG) bx_error_log("Command: FINDNSORTED $class $key");
        $vars = $this->sanitizeFindVars($vars, 'FINDNSORTED');

        if ($this->backend === self::BACKEND_CCE_SO) {
            // Same fallback as findSorted(): retrieve hits first, sort later if needed.
            return $this->find($class, $vars);
        }

        return $this->findx($class, $vars, [], "old_numeric", $key);
    }

    function getObject($class, $vars = [], $namespace = "") {
        if ($this->DEBUG) bx_error_log("Command: GetObject $class " . json_encode($vars));
        if ($this->backend === self::BACKEND_API) {
            $res = $this->getApiClient()->getObject($this->getUsername(), $this->getSessionId(), $class, $vars, $namespace);
            if (is_array($res)) {
                if (isset($res['oid'])) {
                    $res['OID'] = $res['oid'];
                    unset($res['oid']);
                }
                if (!isset($res['NAMESPACE'])) {
                    $res['NAMESPACE'] = $namespace;
                }
                return $res;
            }
            return null;
        }
        $oids = $this->find($class, $vars);
        return (isset($oids[0])) ? $this->get($oids[0], $namespace) : null;
    }

    function getObjects($class, $vars = [], $namespace = "") {
        if ($this->DEBUG) bx_error_log("Command: GetObjects $class");
        $oids = $this->find($class, $vars);
        return array_map(fn($oid) => $this->get($oid, $namespace), $oids);
    }

    function setObject($class, $setVars = [], $namespace = "", $findVars = []) {
        if ($this->DEBUG) bx_error_log("Command: setObject $class");
        $oids = $this->find($class, $findVars);
        return count($oids) ? $this->set($oids[0], $namespace, $setVars) : 0;
    }

    function setObjectForce($class, $setVars = [], $namespace = "", $findVars = []) {
        if ($this->DEBUG) bx_error_log("Command: setObjectForce $class");
        $oids = $this->find($class, $findVars);
        if (count($oids) == 0) {
            if (!$this->create($class, $setVars)) return 0;
        }
        return $this->setObject($class, $setVars, $namespace, $findVars);
    }

    // ==================== CCE Replay System ====================
    function record($oid, $namespace = "", $vars = []) {
        if ($this->DEBUG) bx_error_log("Command: RECORD REPLAY");
        $uname = $this->getUsername();
        $this->cce_replay_file = '/usr/sausalito/license/json_cce_replay.' . $uname;

        if (is_file($this->cce_replay_file)) {
            $this->cce_replay = json_decode(file_get_contents($this->cce_replay_file), true) ?? [];
        }
        if (!is_array($this->cce_replay)) $this->cce_replay = [];

        $this->cce_replay[][$oid] = ['namespace' => $namespace, 'transaction' => $vars];

        $json = json_encode($this->cce_replay);
        $this->CI->serverScriptHelper->shell("/bin/rm -f " . $this->cce_replay_file, $nfk, 'root', $this->getSessionId());

        if (!write_file($this->cce_replay_file, $json)) {
            $this->setReplayError('2', $oid, $this->cce_replay_file, 'Could not write replay file');
            return false;
        }
        return true;
    }

    function replayReset() {
        $uname = $this->getUsername();
        $this->cce_replay_file = '/usr/sausalito/license/json_cce_replay.' . $uname;
        if (is_file($this->cce_replay_file)) {
            $this->CI->serverScriptHelper->shell("/bin/rm -f " . $this->cce_replay_file, $nfk, 'root', $this->getSessionId());
        }
        return true;
    }

    function replayStatus() {
        $uname = $this->getUsername();
        $this->cce_replay_file = '/usr/sausalito/license/json_cce_replay.' . $uname;
        if (!is_file($this->cce_replay_file)) return -1;
        $data = json_decode(file_get_contents($this->cce_replay_file), true);
        return is_array($data) ? count($data) : 0;
    }

    function replay($replayType = "auto") {
        $uname = $this->getUsername();
        $this->cce_replay_file = '/usr/sausalito/license/json_cce_replay.' . $uname;
        if (!is_file($this->cce_replay_file)) return 0;

        $this->cce_replay = json_decode(file_get_contents($this->cce_replay_file), true) ?? [];
        if (!is_array($this->cce_replay)) return false;

        $num = count($this->cce_replay);

        if ($replayType === "auto") {
            foreach ($this->cce_replay as $trans) {
                foreach ($trans as $OID => $SetVal) {
                    $this->set($OID, $SetVal['namespace'], $SetVal['transaction']);
                    if (count($this->errors()) > 0) {
                        $this->replayReset();
                        return $this->errors();
                    }
                }
            }
        }
        else {
            $one = array_shift($this->cce_replay);
            foreach ($one as $OID => $SetVal) {
                $this->set($OID, $SetVal['namespace'], $SetVal['transaction']);
                if (count($this->errors()) > 0) {
                    $this->replayReset();
                    return $this->errors();
                }
            }
        }

        if (count($this->cce_replay) > 0) {
            write_file($this->cce_replay_file, json_encode($this->cce_replay));
            return count($this->cce_replay);
        }

        $this->replayReset();
        return 0;
    }

    function setReplayError($code, $oid = "", $key = "", $msg = "") {
        $this->ERRORS[] = ['code' => $code, 'oid' => $oid, 'key' => $key, 'message' => $msg];
    }

    function get_replay_errors() {
        return $this->ERRORS;
    }

    // ==================== Scalar Helpers (unchanged) ====================
    function array_to_scalar($array) {
        $result = "&";
        if (is_array($array)) {
            foreach ($array as $value) {
                $value = preg_replace_callback("/([^A-Za-z0-9_\. -])/", fn($m) => sprintf('%%%02X', ord($m[1])), $value);
                $value = str_replace(" ", "+", $value);
                $result .= $value . "&";
            }
        }
        return $result === "&" ? "" : $result;
    }

    function scalar_to_array($scalar) {
        $scalar = trim($scalar);
        $scalar = preg_replace(["/^&/", "/&$/", "/;/"], "", $scalar);
        $array = explode("&", $scalar);
        foreach ($array as &$v) {
            $v = str_replace("+", " ", $v);
            $v = preg_replace_callback("/%([0-9a-fA-F]{2})/", fn($m) => chr(hexdec($m[1])), $v);
        }
        return array_filter($array);
    }

    function string_to_scalar($string) {
        $string = trim($string);
        $string = preg_replace(["/^&/", "/&$/", "/\s\s+/", "/,[\s]*/i", "/\n/i"], ["", "", " ", "&", "&"], $string);
        return $string ? "&" . $string . "&" : "";
    }

    function scalar_to_string($scalar) {
        if (preg_match("/^\&(.*)\&$/", $scalar, $m)) {
            return implode("\n", $this->scalar_to_array($scalar));
        }
        return $scalar;
    }

    function _escape($text) {
        if (is_array($text)) $text = $this->array_to_scalar($text);
        if (preg_match('/^[a-zA-Z0-9_]+$/', $text)) return $text;
        return str_replace(["\\","\a","\b","\f","\n","\t",'"','$','&quot;','&amp;','&#39;','&lt;','&gt;'],
                           ["\\\\","\\a","\\b","\\f","\\n","\\t",'\\"',"\\$",'\"','\&',"'",'<','>'], $text);
    }

    function isConnected() {
        return $this->isConnected;
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
Copyright (c) 2003 Sun Microsystems, Inc. 
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
?>
