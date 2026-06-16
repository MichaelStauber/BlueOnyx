import os
import subprocess
import json
import time

def get_uptime():
    """Get the system uptime."""
    result = subprocess.run(["uptime"], capture_output=True, text=True)
    return result.stdout.strip()

def parse_uptime_proc():
    """Parse uptime from /proc/uptime."""
    try:
        with open("/proc/uptime", "r") as f:
            uptime_seconds = float(f.readline().split()[0])
            days = int(uptime_seconds // 86400)
            hours = int((uptime_seconds % 86400) // 3600)
            minutes = int((uptime_seconds % 3600) // 60)
            return days, hours, minutes
    except Exception as e:
        print(f"Error reading /proc/uptime: {e}")
        return 0, 0, 0

def format_time(days, hours, minutes):
    """Format uptime with leading zeros."""
    return f"{days}d:{hours:02}h:{minutes:02}min"

def map_processes_to_containers(max_container_width):
    """Map processes to containers and display their information with dynamic width."""
    print(f"{'PID':<10}{'CPU%':<6}{'MEM%':<6}{'COMMAND':<23}{'CONTAINER':<{max_container_width}}")

    ps_output = subprocess.run(
        ["ps", "-e", "h", "-o", "pid,pcpu,pmem,comm", "--sort=-pcpu"],
        capture_output=True, text=True
    ).stdout.strip()

    if not os.path.exists("/usr/bin/incus"):
        print("Incus is not installed. Not reporting Instance load averages.")
        return

    incus_list = subprocess.run(
        ["/usr/bin/incus", "list", "--format=json"],
        capture_output=True, text=True
    )

    try:
        containers = json.loads(incus_list.stdout)
    except json.JSONDecodeError:
        print("Error: Failed to parse JSON from 'incus list'. Is Incus running and accessible?")
        return

    container_pid_map = {
        str(container.get("state", {}).get("pid")): container["name"]
        for container in containers if container.get("status") == "Running"
    }

    for line in ps_output.split("\n"):
        pid, cpu, mem, command = line.split(maxsplit=3)
        if float(cpu) <= 0.3:
            continue
        container = "host"

        process_ns = f"/proc/{pid}/ns/pid"
        if os.path.exists(process_ns):
            process_ns_link = os.readlink(process_ns)
            for host_pid, container_name in container_pid_map.items():
                host_ns = f"/proc/{host_pid}/ns/pid"
                if os.path.exists(host_ns) and os.readlink(host_ns) == process_ns_link:
                    container = container_name
                    break

        print(f"{pid:<10}{cpu:<6}{mem:<6}{command:<23}{container:<{max_container_width}}")

def main():
    THRESHOLD = 3
    uname = os.uname().nodename

    if not os.isatty(1):
        uptime_output = get_uptime()
        load_avg = float(uptime_output.split("load average:")[1].split(",")[0].strip())
        if load_avg < THRESHOLD:
            return
        print(subprocess.run(["date"], capture_output=True, text=True).stdout.strip())

    print("NODE:")
    print(uname, get_uptime())
    print()

    if not os.path.exists("/usr/bin/incus"):
        print("Incus is not installed. Not reporting Instance load averages.")
        return

    incus_list = subprocess.run(["/usr/bin/incus", "list", "--format=json"], capture_output=True, text=True)
    try:
        containers = json.loads(incus_list.stdout)
    except json.JSONDecodeError:
        print("Error: Failed to parse JSON from 'incus list'. Is Incus running and accessible?")
        return

    # Calculate the maximum container name length
    running_containers = [c["name"] for c in containers if c.get("status") == "Running"]
    max_container_width = max(len(name) for name in running_containers) + 1 if running_containers else 15
    max_container_width = max(max_container_width, len("CONTAINER"))  # Ensure header fits

    # Print container info with dynamic width
    print(f"{'CONTAINER':<{max_container_width}}{'UPTIME':<18}{'TIME':<12}{'USERS':<11}{'LOAD AVERAGE':<16}")
    for c in containers:
        if c.get("status") == "Running":
            container_name = c["name"]

            uptime_output = subprocess.run(
                ["/usr/bin/incus", "exec", container_name, "--", "cat", "/proc/uptime"],
                capture_output=True, text=True
            ).stdout.strip()
            try:
                container_uptime_seconds = float(uptime_output.split()[0])
                days = int(container_uptime_seconds // 86400)
                hours = int((container_uptime_seconds % 86400) // 3600)
                minutes = int((container_uptime_seconds % 3600) // 60)
                uptime_formatted = format_time(days, hours, minutes)
            except Exception:
                uptime_formatted = "N/A"

            uptime_cmd_output = subprocess.run(
                ["/usr/bin/incus", "exec", container_name, "--", "uptime"],
                capture_output=True, text=True
            ).stdout.strip()
            try:
                users = next((s.split()[0] for s in uptime_cmd_output.split(",") if "user" in s), "0")
                load_avg = uptime_cmd_output.split("load average:")[-1].strip()
            except Exception:
                users = "N/A"
                load_avg = "N/A"

            user_text = f"{users} user" if users == "1" else f"{users} users"
            print(f"{container_name:<{max_container_width}}{uptime_formatted:<18}{time.strftime('%H:%M:%S'):<12}{user_text:<11}{load_avg:<16}")

    print()
    map_processes_to_containers(max_container_width)

if __name__ == "__main__":
    main()

#
# Copyright (c) 2008-2025 Michael Stauber,  SOLARSPEED.NET
# Copyright (c) 2008-2025 Greg Kuhnert,     COMPASSNETWORKS.COM.AU
# Copyright (c) 2008-2025 Team BlueOnyx,    BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#    notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#    notice, this list of conditions and the following disclaimer in 
#    the documentation and/or other materials provided with the 
#    distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#    contributors may be used to endorse or promote products derived 
#    from this software without specific prior written permission.
# 
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
# FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
# COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
# INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
# LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
# CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
# ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
# POSSIBILITY OF SUCH DAMAGE.
# 
# You acknowledge that this software is not designed or intended for 
# use in the design, construction, operation or maintenance of any 
# nuclear facility.
#
