#!/bin/bash

THRESHOLD=3
UNAME=$(LC_ALL=C uname -n)

if [ ! -t 1 ] ; then 
    # Running in cron job - Bail if load is low
    UPTIME=$(LC_ALL=C uptime | awk -F'load average: ' '{print $2}' | cut -d . -f 1)
    if [ "$UPTIME" -lt "${THRESHOLD}" ]; then
        exit 0
    fi
    date
fi

# Function to map processes to containers
function map_processes_to_containers {
    printf "%-10s %-6s %-6s %-15s %-15s\n" "PID" "CPU%" "MEM%" "COMMAND" "CONTAINER"
    LC_ALL=C ps -e h -o pid,pcpu,pmem,comm --sort=-pcpu | grep -v " 0.0" | while read -r PID CPU MEM COMMAND; do
        CONTAINER="$UNAME"
        for CONTAINER_NAME in $(LC_ALL=C /usr/bin/incus list --format=json | jq -r '.[] | select(.status == "Running") | .name'); do
            HOST_PID=$(LC_ALL=C /usr/bin/incus info "$CONTAINER_NAME" | grep PID | awk '{print $2}')
            if [ -d "/proc/$PID/ns" ] && [ -d "/proc/$HOST_PID/ns" ] && [ "$(readlink /proc/$PID/ns/pid)" = "$(readlink /proc/$HOST_PID/ns/pid)" ]; then
                CONTAINER=$CONTAINER_NAME
                break
            fi
        done
        printf "%-10s %-6s %-6s %-15s %-15s\n" "$PID" "$CPU" "$MEM" "$COMMAND" "$CONTAINER"
    done
}

# Print hostname and node uptime
echo "NODE:"
echo -n "$UNAME"
LC_ALL=C uptime
echo ""

# Loop through running containers and gather uptime/load averages
printf "%-15s %-13s %-10s %-11s %-16s\n" "CONTAINER" "UPTIME" "HOURS:MIN" "USERS" "LOAD AVERAGE"
for CONTAINER in $(LC_ALL=C /usr/bin/incus list --format=json | jq -r '.[] | select(.status == "Running") | .name'); do
    UPTIME_OUTPUT=$(LC_ALL=C /usr/bin/incus exec "$CONTAINER" -- uptime)

    # Extract components from uptime output
    TIME=$(echo "$UPTIME_OUTPUT" | LC_ALL=C awk '{print $1}')
    UP=$(echo "$UPTIME_OUTPUT" | LC_ALL=C awk -F', ' '{print $2}' | sed 's/^\s*up //g')
    HOURS_MIN=$(echo "$UP" | grep -oP '\d+:\d+')
    USERS=$(echo "$UPTIME_OUTPUT" | grep -oP '\d+\s+user[s]?')
    LOAD=$(echo "$UPTIME_OUTPUT" | LC_ALL=C awk -F'load average:' '{print $2}' | sed 's/^ //')

    # Remove hours:mins from the `UP` variable if it exists
    UP=$(echo "$UP" | sed "s/$HOURS_MIN//g")

    # Default values for components if not found
    HOURS_MIN=${HOURS_MIN:-"N/A"}
    USERS=${USERS:-"N/A"}
    LOAD=${LOAD:-"N/A"}

    # Assemble the formatted output
    printf "%-15s %-13s %-10s %-11s %-16s\n" "$CONTAINER" "$TIME up $UP" "$HOURS_MIN" "$USERS" "$LOAD"
done
echo ""

# List processes with detailed information and container mapping
map_processes_to_containers

#
# Copyright (c) 2008-2025 Greg Kuhnert,     COMPASSNETWORKS.COM.AU
# Copyright (c) 2008-2025 Michael Stauber,  SOLARSPEED.NET
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