#!/bin/bash
set -e

mkdir -p /etc/cced-api/certs

cp -f /etc/admserv/certs/certificate /etc/cced-api/certs/ || true
cp -f /etc/admserv/certs/key /etc/cced-api/certs/ || true
cp -f /etc/admserv/certs/ca-certs /etc/cced-api/certs/ || true
chown admserv:admserv /etc/cced-api/certs/* || true
if [ -f /etc/cced-api/certs/ca-certs ];then
	chmod 0644 /etc/cced-api/certs/ca-certs || true
fi
chmod 0644 /etc/cced-api/certs/certificate || true
chmod 0600 /etc/cced-api/certs/key || true

# Create and set permissions on the log file
touch /var/log/cced-api.log
chown admserv:admserv /var/log/cced-api.log
chmod 664 /var/log/cced-api.log

# Prepare empty /etc/cced-api/config/access and/or set permissions and ownerships:
touch /etc/cced-api/config/access
chown admserv:admserv /etc/cced-api/config/access
chmod 600 /etc/cced-api/config/access

# Run Constructor to create 'api-admin' account. Rotates its password on every subsequent use.
if [ -e /usr/sausalito/sbin/gen_api_admin.pl ];then
	/usr/sausalito/sbin/gen_api_admin.pl
fi

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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
