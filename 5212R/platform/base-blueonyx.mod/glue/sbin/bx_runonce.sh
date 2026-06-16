#!/usr/bin/sh
# $Id: bx_runonce.sh
# Initializes BlueOnyx system at first boot.
#
# Description: Initialize BlueOnyx settings.
#

# Default: assume setup not done
SETUPDONE=0

# Look for .isLicenseAccepted file and read its content if present
LICENSE_FILE=$(find /usr/sausalito/codb/objects/ -name .isLicenseAccepted -print -quit 2>/dev/null)
if [ -n "$LICENSE_FILE" ] && [ -f "$LICENSE_FILE" ]; then
    CONTENT=$(cat "$LICENSE_FILE" 2>/dev/null | tr -cd '0-9' | head -c 1)  # Extract first digit, ignore junk
    if [ "$CONTENT" = "1" ]; then
        SETUPDONE=1
    fi
fi

if [ "$SETUPDONE" -eq "0" ];then
    echo "Initial setup had not been finished yet! Running setup scripts!"

    for file in /usr/sausalito/runonce/*.sh
    do
        echo "Run Once: $file"
        $file >/dev/null 2>&1 || :
        rm -f $file
    done

    if [ ! -f /var/lib/dovecot/ssl-parameters.dat ]; then
        /usr/libexec/dovecot/ssl-params >/dev/null 2>&1 || :
    fi

else
    echo "Initial setup has already been done. Nothing to do but cleaning up!"

    for file in /usr/sausalito/runonce/*.sh
    do
        rm -f $file
    done

fi

exit 0

# 
# Copyright (c) 2015-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2015-2025 Team BlueOnyx, BLUEONYX.IT
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
