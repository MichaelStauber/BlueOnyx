#!/bin/bash

if [ -f /etc/admserv/certs/nginx_cert_ca_combined ];then
    /usr/bin/goaccess /var/log/httpd/access_log --addr=127.0.0.1 --log-format='%v %h %l %u %^[%d:%t %^] \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"' --date-format=%d/%b/%Y --time-format=%T -o /usr/sausalito/ui/web/base/sitestats/index.html --real-time-html --ssl-cert=/etc/admserv/certs/nginx_cert_ca_combined --ssl-key=/etc/admserv/certs/key
else
    /usr/bin/goaccess /var/log/httpd/access_log --addr=127.0.0.1 --log-format='%v %h %l %u %^[%d:%t %^] \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"' --date-format=%d/%b/%Y --time-format=%T -o /usr/sausalito/ui/web/base/sitestats/index.html --real-time-html --ssl-cert=/etc/admserv/certs/certificate --ssl-key=/etc/admserv/certs/key
fi

#
# Copyright (c) 2022-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2022-2024 Team BlueOnyx, BLUEONYX.IT
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

