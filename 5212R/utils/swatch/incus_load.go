// incus_load.go
package main

import (
    "encoding/json"
    "fmt"
    "log"
    "os"
    "os/exec"
    "path/filepath"
    "strconv"
    "strings"
    "time"
)

type Container struct {
    Name   string `json:"name"`
    Status string `json:"status"`
    State  struct {
        PID int `json:"pid"`
    } `json:"state"`
}

func getUptime() string {
    out, err := exec.Command("uptime").Output()
    if err != nil {
        return "N/A"
    }
    return strings.TrimSpace(string(out))
}

func parseProcUptime() (int, int, int) {
    data, err := os.ReadFile("/proc/uptime")
    if err != nil {
        return 0, 0, 0
    }
    parts := strings.Fields(string(data))
    sec, _ := strconv.ParseFloat(parts[0], 64)
    days := int(sec) / 86400
    hours := int(sec)%86400 / 3600
    minutes := int(sec)%3600 / 60
    return days, hours, minutes
}

func formatTime(days, hours, minutes int) string {
    return fmt.Sprintf("%dd:%02dh:%02dmin", days, hours, minutes)
}

func loadContainers() ([]Container, error) {
    out, err := exec.Command("/usr/bin/incus", "list", "--format=json").Output()
    if err != nil {
        return nil, err
    }
    var containers []Container
    err = json.Unmarshal(out, &containers)
    return containers, err
}

func printRunningContainers(containers []Container, maxWidth int) {
    // Adjust column widths for better alignment
    fmt.Printf("%-*s%-18s%-12s%-16s%-16s\n", maxWidth, "CONTAINER", "UPTIME", "TIME", "USERS", "LOAD AVERAGE")

    for _, c := range containers {
        if c.Status != "Running" {
            continue
        }

        // Get uptime in seconds from /proc/uptime inside the container
        upCmd := exec.Command("/usr/bin/incus", "exec", c.Name, "--", "cat", "/proc/uptime")
        upRaw, _ := upCmd.Output()
        days, hours, minutes := 0, 0, 0
        if fields := strings.Fields(string(upRaw)); len(fields) > 0 {
            if sec, err := strconv.ParseFloat(fields[0], 64); err == nil {
                days = int(sec) / 86400
                hours = int(sec)%86400 / 3600
                minutes = int(sec)%3600 / 60
            }
        }
        uptimeFmt := formatTime(days, hours, minutes)

        // Get uptime and load average with users from the 'uptime' command inside the container
        uptimeCmd := exec.Command("/usr/bin/incus", "exec", c.Name, "--", "uptime")
        uptimeOut, _ := uptimeCmd.Output()
        uptimeStr := string(uptimeOut)

        // Initialize user and load values
        users := "0" // Default to 0 users
        load := "N/A" // Default load average

        // Parse uptimeStr to extract the correct users count and load averages
        if strings.Contains(uptimeStr, "load average") {
            // Extract load average part
            parts := strings.Split(uptimeStr, "load average:")
            load = strings.TrimSpace(parts[1])

            // Extract user count from uptime string (e.g., "0 user" or "1 user")
            userParts := strings.Split(uptimeStr, ", ")
            if len(userParts) > 2 {
                users = strings.TrimSpace(userParts[2]) // Correctly extract the "X user" part
                users = strings.Split(users, " ")[0]    // Remove "user" or "users" from the string
            } else {
                users = "0"  // Default users to 0 if not found
            }
        }

        // Format userStr correctly (i.e., "1 user" or "X users")
        userStr := fmt.Sprintf("%s user", users)
        if users != "1" {
            userStr = fmt.Sprintf("%s users", users)
        }

        // Adjust column widths for better alignment in the print statement
        fmt.Printf("%-*s%-18s%-12s%-16s%-16s\n", maxWidth, c.Name, uptimeFmt, time.Now().Format("15:04:05"), userStr, load)
    }
}

func listProcesses(containerMap map[string]string, maxWidth int) {
    fmt.Printf("%-10s%-6s%-6s%-23s%-*s\n", "PID", "CPU%", "MEM%", "COMMAND", maxWidth, "CONTAINER")

    out, err := exec.Command("ps", "-e", "h", "-o", "pid,pcpu,pmem,comm", "--sort=-pcpu").Output()
    if err != nil {
        return
    }
    lines := strings.Split(string(out), "\n")
    for _, line := range lines {
        fields := strings.Fields(line)
        if len(fields) < 4 {
            continue
        }
        pid := fields[0]
        cpu, _ := strconv.ParseFloat(fields[1], 64)
        if cpu <= 0.3 {
            continue
        }
        mem := fields[2]
        command := fields[3]
        container := "host"
        procNs := filepath.Join("/proc", pid, "ns/pid")
        if procLink, err := os.Readlink(procNs); err == nil {
            for hpid, cname := range containerMap {
                hostNs := filepath.Join("/proc", hpid, "ns/pid")
                if hostLink, err := os.Readlink(hostNs); err == nil && procLink == hostLink {
                    container = cname
                    break
                }
            }
        }
        fmt.Printf("%-10s%-6s%-6s%-23s%-*s\n", pid, fields[1], mem, command, maxWidth, container)
    }
}

func main() {
    if _, err := os.Stat("/usr/bin/incus"); err != nil {
        fmt.Println("Incus is not installed. Not reporting Instance load averages.")
        return
    }

    uptime := getUptime()
    node, _ := os.Hostname()
    fmt.Println("NODE:")
    fmt.Println(node, uptime)
    fmt.Println()

    containers, err := loadContainers()
    if err != nil {
        log.Fatal("Failed to parse Incus container list")
    }

    nameWidths := 15
    names := []string{}
    containerPidMap := map[string]string{}
    for _, c := range containers {
        if c.Status == "Running" {
            names = append(names, c.Name)
            containerPidMap[strconv.Itoa(c.State.PID)] = c.Name
        }
    }
    for _, name := range names {
        if len(name) > nameWidths {
            nameWidths = len(name) + 1
        }
    }

    printRunningContainers(containers, nameWidths)
    fmt.Println()
    listProcesses(containerPidMap, nameWidths)
}

//
// Copyright (c) 2008-2025 Michael Stauber,  SOLARSPEED.NET
// Copyright (c) 2008-2025 Greg Kuhnert,     COMPASSNETWORKS.COM.AU
// Copyright (c) 2008-2025 Team BlueOnyx,    BLUEONYX.IT
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
