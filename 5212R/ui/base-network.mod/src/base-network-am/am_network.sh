#!/usr/bin/sh
# test the network state, try to recover if something happens
#
. /usr/sausalito/swatch/statecodes

# we may change this throughout the script
FINAL_RET=$AM_STATE_GREEN

# get network config
. /etc/sysconfig/network

PING="ping -q -c3 -l3"
PING6="ping6 -q -c3 -l3"

# cop out if we're shut-out of network connectivity via ppp window
HOUR=`date +%H`
if [ -f /etc/ppp/nodial/$HOUR ]; then
    exit $FINAL_RET
fi

# make sure any interfaces that should be up are up
nmcli -t -f NAME,DEVICE connection show | while IFS=: read -r NAME DEVICE; do
    if [ -z "$DEVICE" ]; then
        # Check if the connection profile is set to autoconnect
        AUTOCONNECT=$(nmcli -t -f connection.autoconnect connection show "$NAME" | cut -d: -f2)

        if [ "$AUTOCONNECT" = "yes" ]; then
            # Bring up the connection if it is set to autoconnect
            nmcli connection up "$NAME" >/dev/null 2>&1
            DEVICE=$(nmcli -t -f GENERAL.DEVICES connection show "$NAME" | cut -d: -f2)
        fi
    fi

    if [ -z "$DEVICE" ] || [ "$DEVICE" = "--" ]; then
        continue
    fi

    # Check if the connection profile is set to autoconnect
    AUTOCONNECT=$(nmcli -t -f connection.autoconnect connection show "$NAME" | cut -d: -f2)

    if [ "$AUTOCONNECT" = "yes" ]; then
        # Check if the device is up
        DEVICE_STATE=$(nmcli -t -f DEVICE,STATE device status | grep "^$DEVICE:" | cut -d: -f2)
        if [ "$DEVICE_STATE" != "connected" ]; then

            # Try to bring the device up
            nmcli device connect $DEVICE >/dev/null 2>&1
            DEVICE_STATE=$(nmcli -t -f DEVICE,STATE device status | grep "^$DEVICE:" | cut -d: -f2)

            if [ "$DEVICE_STATE" != "unmanaged" ]; then
                continue
            fi

            if [ "$DEVICE_STATE" != "connected" ]; then
                # Try to recover the interface
                nmcli device disconnect $DEVICE >/dev/null 2>&1
                nmcli device connect $DEVICE >/dev/null 2>&1
                # Try again
                $PING $IPADDR > /dev/null 2>&1

                RET=$?
                if [ "$RET" != "0" ]; then
                    echo -ne "[[base-network.amIfaceIsDown,iface=$DEVICE]]"
                    FINAL_RET=$AM_STATE_RED
                    continue
                fi
            fi
        fi
    fi
done

# IPv4 Gateway:
IPv4GW=$(/sbin/ip route | awk '/default/ { print $3 }' | head -1)

# IPv6 Gateway:
IPv6GW=$(/sbin/ip -6 route | awk '/default/ { print $3 }' | head -1)

# Test the gateway
if [[ $IPv4GW =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    # IPv4
    $PING $IPv4GW > /dev/null 2>&1
else
    # IPv6:
    $PING6 $IPv6GW > /dev/null 2>&1
fi

RET=$?
if [ "$RET" != "0" ]; then
    # Try again
    $PING $GATEWAY > /dev/null 2>&1
    RET=$?
    if [ "$RET" != "0" ]; then
        echo -ne "[[base-network.amGatewayIsUnreachable]]"
        if [ "$FINAL_RET" != "$AM_STATE_RED" ]; then
            FINAL_RET=$AM_STATE_YELLOW  
        fi
    fi
fi

if [ "$FINAL_RET" = "$AM_STATE_GREEN" ]; then
    echo -ne "[[base-network.amNetworkOK]]"
fi

exit $FINAL_RET

# 
# Copyright (c) 2014-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2014-2024 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#     notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#     notice, this list of conditions and the following disclaimer in 
#     the documentation and/or other materials provided with the 
#     distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#     contributors may be used to endorse or promote products derived 
#     from this software without specific prior written permission.
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
