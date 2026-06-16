#!/bin/bash
# network_settings.sh - BlueOnyx Network Settings configurator - nmtui version (simplified, less dialog hell)

export LANGUAGE=en_US.UTF-8 LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8

# Get BlueOnyx version
BX_VERSION="5212R"
if [ -f /etc/build ]; then
    BX_VERSION=$(awk '{ print $5 }' /etc/build | head -1)
    [ -z "$BX_VERSION" ] && BX_VERSION="5212R"
fi

CDTITLE="BlueOnyx ${BX_VERSION} First Boot"

# Simple colored echo
cecho() { echo -e "\e[1;36m$1\e[0m"; }

# Exit containers
if [ -f /proc/user_beancounters ]; then
  echo "This is an OpenVZ Container. Network settings may not be changed from inside the VPS."
  exit 0
fi
if [ -f /dev/incus/sock ]; then
  echo "This is an Incus Container. Network settings may not be changed from inside the VPS."
  exit 0
fi

# Wait for constructors
waitForCceConstruct() {
  local waitFor="cce_construct"
  while pgrep -f "$waitFor" >/dev/null; do
    echo "75" | dialog --nocancel --backtitle "${CDTITLE}" --title "Waiting" --gauge "Please wait for CCEd Constructors to finish ..." 10 70 0
    sleep 2
  done
  echo "100" | dialog --nocancel --backtitle "${CDTITLE}" --title "Waiting" --gauge "CCEd Constructors finished." 10 70 0
  sleep 2

  local wait_count=0
  while systemctl list-jobs --quiet 2>/dev/null | grep -q . && [ $wait_count -lt 40 ]; do
    sleep 1
    ((wait_count++))
  done
  sleep 2
  clear
  tput reset 2>/dev/null || true
}

waitForCceConstruct

# Detect primary interface
PRIMARY=$(ip -o link show | awk -F': ' '{print $2}' | grep -E -v '^(lo|vir|incus|br|veth|docker|wg)' | head -1 | cut -d@ -f1)
[ -z "$PRIMARY" ] && PRIMARY="eth0"

cecho "\n\e[1;37m=== BlueOnyx ${BX_VERSION} Network Setup ===\e[0m\n"
cecho "Primary interface detected: $PRIMARY"

# Quick connectivity check
if ping -c1 -W3 8.8.8.8 >/dev/null 2>&1 && nslookup blueonyx.it >/dev/null 2>&1; then
  cecho "✓ Network is already working!"
  IP=$(ip -4 addr show "$PRIMARY" | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -1)
  [ -z "$IP" ] && IP=$(hostname -I | awk '{print $1}' | head -1)
  SERVERNAME=$(uname -n | head -1)
  cecho "Server Name : $SERVERNAME"
  cecho "Current IP  : $IP"
  cecho "GUI         : https://$IP:81/login"
  read -n1 -s -r -p "Keep this configuration? [Y/n] " keep
  echo
  if [[ $keep =~ ^[Yy]$ ]] || [ -z "$keep" ]; then
    cecho "Keeping current configuration and saving it to CODB ..."
    /usr/sausalito/constructor/base/network/30_addNetwork.pl >/dev/null 2>&1 || true
    cecho "Enjoy BlueOnyx ${BX_VERSION}!"
    exit 0
  fi
fi

cecho "Network needs configuration. Launching nmtui (NetworkManager TUI)..."
clear
nmtui

# After nmtui exits
clear
cecho "Applying configuration and saving it to CODB ..."
nmcli connection reload
sleep 4
/usr/sausalito/constructor/base/network/30_addNetwork.pl >/dev/null 2>&1 || true

cecho "Running Active Monitor component Swatch to make sure all services are up ..."
# Enable swatch:
systemctl enable swatch.service --now &>/dev/null || :
sleep 2
# Run Swatch
/usr/sausalito/sbin/swatch.sh &>/dev/null || :

cecho "\n=== Setup Complete ==="
IP=$(ip -4 addr show "$PRIMARY" | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -1)
[ -z "$IP" ] && IP=$(hostname -I | awk '{print $1}' | head -1)
[ -z "$IP" ] && IP="your-server-ip"
SERVERNAME=$(uname -n | head -1)
cecho "Server Name : $SERVERNAME"
cecho "Current IP  : $IP"
cecho "GUI         : https://$IP:81/"
cecho "Enjoy BlueOnyx ${BX_VERSION}!"

# Final cleanup
grep -v "network_settings.sh" /root/.bashrc > /tmp/.bashrc && mv /tmp/.bashrc /root/.bashrc || true
exit 0

#
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
#
# 1. Redistributions of source code must retain the above copyright
# notice, this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright
# notice, this list of conditions and the following disclaimer in
# the documentation and/or other materials provided with the
# distribution.
#
# 3. Neither the name of the copyright holder nor the names of its
# contributors may be used to endorse or promote products derived
# from this software without specific prior written permission.
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