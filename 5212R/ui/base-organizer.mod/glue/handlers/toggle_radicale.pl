#!/usr/bin/perl -w -I /usr/sausalito/perl
# $Id: toggle_radicale.pl

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use Sauce::Service;

my $cce = new CCE('Namespace' => 'Radicale');
$cce->connectfd();

my $RadicaleNS = $cce->event_object();

$radicale_config = '/etc/radicale/config';
$radicale_apache = '/etc/httpd/conf.d/authnz_radicale.conf';
$radicale_admserv = '/etc/admserv/conf.d/authnz_radicale.conf';
$internal_auth = '/etc/httpd/conf.d/internalauth.conf';
$internal_path = '/var/www/internalauth';
$internal_success = '/var/www/internalauth/index.html';

# Find System Object:
my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

# Get System object:
my ($ok, $System) = $cce->get($oids[0]);
if (!$ok) {
    $cce->bye('FAIL');
    exit(1);
}

# Version Check for correct Python path:
$have_htpasswd = '0';

if ($System->{'productBuild'} eq "5212R") {
    $have_htpasswd = '1';
    $python_switcher = `cat /usr/lib/python3.12/site-packages/radicale/auth/htpasswd.py|grep "Iterate through htpasswd credential file"|wc -l`;
    chomp($python_switcher);
    $python_switch_file = '/usr/lib/python3.12/site-packages/radicale/auth/htpasswd.py';
}
elsif ($System->{'productBuild'} eq "5211R") {
    $have_htpasswd = '1';
    $python_switcher = `cat /usr/lib/python3.9/site-packages/radicale/auth/htpasswd.py|grep "Iterate through htpasswd credential file"|wc -l`;
    chomp($python_switcher);
    $python_switch_file = '/usr/lib/python3.9/site-packages/radicale/auth/htpasswd.py';
}
else {
    $have_htpasswd = '1';
    $python_switcher = `cat /usr/lib/python3.8/site-packages/radicale/auth/htpasswd.py|grep "Iterate through htpasswd credential file"|wc -l`;
    chomp($python_switcher);
    $python_switch_file = '/usr/lib/python3.8/site-packages/radicale/auth/htpasswd.py';
}

# Update /etc/radicale/config:
$out = '# -*- mode: conf -*-' . "\n";
$out .= '# vim:ft=cfg' . "\n";
$out .= '' . "\n";
$out .= '# Config file for Radicale - A simple calendar server' . "\n";
$out .= '#' . "\n";
$out .= '# Place it into /etc/radicale/config (global)' . "\n";
$out .= '# or ~/.config/radicale/config (user)' . "\n";
$out .= '#' . "\n";
$out .= '# The current values are the default ones' . "\n";
$out .= '' . "\n";
$out .= '' . "\n";
$out .= '[server]' . "\n";
$out .= '' . "\n";
$out .= '# CalDAV server hostnames separated by a comma' . "\n";
$out .= '# IPv4 syntax: address:port' . "\n";
$out .= '# IPv6 syntax: [address]:port' . "\n";
$out .= '# For example: 0.0.0.0:9999, [::]:9999' . "\n";
$out .= 'hosts = 127.0.0.1:5232' . "\n";
$out .= '' . "\n";
$out .= '# Max parallel connections' . "\n";
$out .= 'max_connections = ' . $RadicaleNS->{max_connections} . "\n";
$out .= '' . "\n";
$out .= '# Max size of request body (bytes)' . "\n";
$out .= 'max_content_length = ' . $RadicaleNS->{max_content_length} . "\n";
$out .= '' . "\n";
$out .= '# Socket timeout (seconds)' . "\n";
$out .= 'timeout = ' . $RadicaleNS->{timeout} . "\n";
$out .= '' . "\n";
$out .= '# SSL flag, enable HTTPS protocol' . "\n";
$out .= 'ssl = False' . "\n";
$out .= '' . "\n";
$out .= '# SSL certificate path' . "\n";
$out .= '#certificate = /etc/ssl/radicale.cert.pem' . "\n";
$out .= '' . "\n";
$out .= '# SSL private key' . "\n";
$out .= '#key = /etc/ssl/radicale.key.pem' . "\n";
$out .= '' . "\n";
$out .= '# CA certificate for validating clients. This can be used to secure' . "\n";
$out .= '# TCP traffic between Radicale and a reverse proxy' . "\n";
$out .= '#certificate_authority =' . "\n";
$out .= '' . "\n";
$out .= '' . "\n";
$out .= '[encoding]' . "\n";
$out .= '' . "\n";
$out .= '# Encoding for responding requests' . "\n";
$out .= 'request = utf-8' . "\n";
$out .= '' . "\n";
$out .= '# Encoding for storing local collections' . "\n";
$out .= 'stock = utf-8' . "\n";
$out .= '' . "\n";
$out .= '' . "\n";
$out .= '[auth]' . "\n";
$out .= '' . "\n";
$out .= '# Authentication method' . "\n";
$out .= '# Value: none | htpasswd | remote_user | http_x_remote_user' . "\n";
$out .= 'type = htpasswd' . "\n";
$out .= '' . "\n";
$out .= '# Htpasswd filename' . "\n";
$out .= '#htpasswd_filename = /etc/radicale/users' . "\n";
$out .= '' . "\n";
$out .= '# Htpasswd encryption method' . "\n";
$out .= '# Value: plain | bcrypt | md5' . "\n";
$out .= '# bcrypt requires the installation of radicale[bcrypt].' . "\n";
$out .= '#htpasswd_encryption = md5' . "\n";
$out .= '' . "\n";
$out .= '# Incorrect authentication delay (seconds)' . "\n";
$out .= 'delay = ' . $RadicaleNS->{delay} . "\n";
$out .= '' . "\n";
$out .= '# Message displayed in the client when a password is needed' . "\n";
$out .= 'realm = Radicale - Password Required' . "\n";
$out .= '' . "\n";
$out .= '' . "\n";
$out .= '[rights]' . "\n";
$out .= '' . "\n";
$out .= '# Rights backend' . "\n";
$out .= '# Value: none | authenticated | owner_only | owner_write | from_file' . "\n";
$out .= 'type = owner_only' . "\n";
$out .= '' . "\n";
$out .= '# File for rights management from_file' . "\n";
$out .= '#file = /etc/radicale/rights' . "\n";
$out .= '' . "\n";
$out .= '[storage]' . "\n";
$out .= '' . "\n";
$out .= '# Storage backend' . "\n";
$out .= '# Value: multifilesystem | multifilesystem_nolock' . "\n";
$out .= 'type = multifilesystem' . "\n";
$out .= '' . "\n";
$out .= '# Folder for storing local collections, created if not present' . "\n";
$out .= 'filesystem_folder = /var/lib/radicale/collections' . "\n";
$out .= '' . "\n";
$out .= '# Delete sync token that are older (seconds)' . "\n";
$out .= 'max_sync_token_age = 2592000' . "\n";
$out .= '' . "\n";
$out .= '# Command that is run after changes to storage' . "\n";
$out .= '# Example: ([ -d .git ] || git init) && git add -A && (git diff --cached --quiet || git commit -m "Changes by "%(user)s)' . "\n";
$out .= '# Note: storage hooks configuration is currently not supported by packaged SELinux policy and requires a local custom policy extension (RHBZ#1928899)' . "\n";
$out .= '#hook =' . "\n";
$out .= '' . "\n";
$out .= '' . "\n";
$out .= '[web]' . "\n";
$out .= '' . "\n";
$out .= '# Web interface backend' . "\n";
$out .= '# Value: none | internal' . "\n";
$out .= 'type = internal' . "\n";
$out .= '' . "\n";
$out .= '' . "\n";
$out .= '[logging]' . "\n";
$out .= '' . "\n";
$out .= '# Threshold for the logger' . "\n";
$out .= '# Value: debug | info | warning | error | critical' . "\n";
$out .= 'level =  info' . "\n";
$out .= '' . "\n";
$out .= '# Don\'t include passwords in logs' . "\n";
$out .= 'mask_passwords = True' . "\n";
$out .= '' . "\n";
$out .= '' . "\n";
$out .= '[headers]' . "\n";
$out .= '' . "\n";
$out .= '# Additional HTTP headers' . "\n";
$out .= 'Access-Control-Allow-Origin = *' . "\n";
$out .= '' . "\n";

# Write the file:
umask(0077);
open(STAGE, ">$radicale_config");
print STAGE $out;
close(STAGE);
&debug_msg("Updated $radicale_config\n");
system("chown root:radicale $radicale_config");
system("chmod 0640 $radicale_config");

#
### Python Switch:
#

if (($have_htpasswd eq '1') && ($python_switcher eq '1')) {
    $out_python = 'import requests' . "\n";
    $out_python .= 'import syslog' . "\n";
    $out_python .= 'from requests.exceptions import RequestException' . "\n";
    $out_python .= 'from typing import Optional' . "\n";
    $out_python .= 'import base64' . "\n";
    $out_python .= '' . "\n";
    $out_python .= 'from radicale import auth, config' . "\n";
    $out_python .= '' . "\n";
    $out_python .= 'class Auth(auth.BaseAuth):' . "\n";
    $out_python .= '    _url: str' . "\n";
    $out_python .= '    _configuration: config.Configuration' . "\n";
    $out_python .= '' . "\n";
    $out_python .= '    def __init__(self, configuration: config.Configuration) -> None:' . "\n";
    $out_python .= '        super().__init__(configuration)' . "\n";
    $out_python .= '        self._url = "http://localhost/internalauth/"' . "\n";
    $out_python .= '        self._configuration = configuration' . "\n";
    $out_python .= '' . "\n";
    $out_python .= '    def _log_to_syslog(self, message: str) -> None:' . "\n";
    $out_python .= '        syslog.syslog(syslog.LOG_INFO | syslog.LOG_AUTH, message)' . "\n";
    $out_python .= '' . "\n";
    $out_python .= '    def login(self, login: str, password: str) -> str:' . "\n";
    $out_python .= '        """Validate credentials by making a POST request to the internalauth endpoint."""' . "\n";
    $out_python .= '        self._log_to_syslog(f"Attempting login for username: {login} to radicale via internalauth")' . "\n";
    $out_python .= '        try:' . "\n";
    $out_python .= '            session = requests.Session()' . "\n";
    $out_python .= '            headers = {' . "\n";
    $out_python .= '                "Authorization": f"Basic {base64.b64encode(f\'{login}:{password}\'.encode()).decode()}"' . "\n";
    $out_python .= '            }' . "\n";
    $out_python .= '            response = session.post(' . "\n";
    $out_python .= '                self._url, data={"username": login, "password": password}, headers=headers, allow_redirects=True' . "\n";
    $out_python .= '            )' . "\n";
    $out_python .= '            response.raise_for_status()  # Raise an exception for non-2xx status codes' . "\n";
    $out_python .= '            if response.history:' . "\n";
    $out_python .= '                last_response = response.history[-1]' . "\n";
    $out_python .= '            else:' . "\n";
    $out_python .= '                last_response = response' . "\n";
    $out_python .= '            if last_response.text.strip() == "Auth success":' . "\n";
    $out_python .= '                self._log_to_syslog(f"Successful login for username: {login} to radicale via internalauth")' . "\n";
    if ($System->{'productBuild'} eq "5212R") {
        $out_python .= '                return login, self._configuration.get("auth", "realm")' . "\n";
    }
    else {
        $out_python .= '                return login' . "\n";
    }
    $out_python .= '            else:' . "\n";
    $out_python .= '                self._log_to_syslog(f"Failed login attempt for username: {login} to radicale via internalauth")' . "\n";
    $out_python .= '        except RequestException as e:' . "\n";
    $out_python .= '            self._log_to_syslog(f"Failed to authenticate: {e} to radicale via internalauth")' . "\n";
    $out_python .= '            self._log_to_syslog("Authentication error occurred, but continuing without raising an exception.")' . "\n";
    $out_python .= '            return ""  # Return an empty string in case of an exception' . "\n";
    $out_python .= '' . "\n";
    $out_python .= '# ' . "\n";
    $out_python .= '# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET' . "\n";
    $out_python .= '# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT' . "\n";
    $out_python .= '# All Rights Reserved.' . "\n";
    $out_python .= '# ' . "\n";
    $out_python .= '# 1. Redistributions of source code must retain the above copyright ' . "\n";
    $out_python .= '#    notice, this list of conditions and the following disclaimer.' . "\n";
    $out_python .= '# ' . "\n";
    $out_python .= '# 2. Redistributions in binary form must reproduce the above copyright ' . "\n";
    $out_python .= '#    notice, this list of conditions and the following disclaimer in ' . "\n";
    $out_python .= '#    the documentation and/or other materials provided with the ' . "\n";
    $out_python .= '#    distribution.' . "\n";
    $out_python .= '# ' . "\n";
    $out_python .= '# 3. Neither the name of the copyright holder nor the names of its ' . "\n";
    $out_python .= '#    contributors may be used to endorse or promote products derived ' . "\n";
    $out_python .= '#    from this software without specific prior written permission.' . "\n";
    $out_python .= '# ' . "\n";
    $out_python .= '# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS ' . "\n";
    $out_python .= '# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT ' . "\n";
    $out_python .= '# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS ' . "\n";
    $out_python .= '# FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE ' . "\n";
    $out_python .= '# COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, ' . "\n";
    $out_python .= '# INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, ' . "\n";
    $out_python .= '# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; ' . "\n";
    $out_python .= '# LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER ' . "\n";
    $out_python .= '# CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT ' . "\n";
    $out_python .= '# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ' . "\n";
    $out_python .= '# ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE ' . "\n";
    $out_python .= '# POSSIBILITY OF SUCH DAMAGE.' . "\n";
    $out_python .= '# ' . "\n";
    $out_python .= '# You acknowledge that this software is not designed or intended for ' . "\n";
    $out_python .= '# use in the design, construction, operation or maintenance of any ' . "\n";
    $out_python .= '# nuclear facility.' . "\n";
    $out_python .= '# ' . "\n";
    $out_python .= '' . "\n";

    # Write the file:
    umask(0077);
    open(STAGE, ">$python_switch_file");
    print STAGE $out_python;
    close(STAGE);
    &debug_msg("Updated $python_switch_file\n");
    system("chown root:root $python_switch_file");
    system("chmod 0644 $python_switch_file");
}

# Enable and Restart Radicale if it should be running:
&debug_msg("Radicale should be of runstate: " . $RadicaleNS->{enabled} . "\n");
service_toggle_init('radicale', $RadicaleNS->{enabled});
service_run_init('radicale', $RadicaleNS->{enabled});

#
### Handle Apache includes:
#

$apache_out = '';

if ($RadicaleNS->{enabled} eq '1') {
    $apache_out = # Configuration for Radicale CalDAV + CardDAV: Enabled' . "\n";
    $apache_out .= '' . "\n";
    $apache_out .= 'RewriteEngine On' . "\n";
    $apache_out .= 'RewriteRule ^/radicale$ /radicale/ [R,L]' . "\n";
    $apache_out .= '' . "\n";
    $apache_out .= '<Location "/radicale/">' . "\n";
    $apache_out .= '    ProxyPass        http://localhost:5232/ retry=0' . "\n";
    $apache_out .= '    ProxyPassReverse http://localhost:5232/' . "\n";
    $apache_out .= '    RequestHeader    set X-Script-Name /radicale' . "\n";
    $apache_out .= '</Location>' . "\n";
    $apache_out .= '' . "\n";
}
else {
    $apache_out = '# Configuration for Radicale CalDAV + CardDAV: Disabled' . "\n";
    $apache_out .= '' . "\n";
    $apache_out .= '# RewriteEngine On' . "\n";
    $apache_out .= '# RewriteRule ^/radicale$ /radicale/ [R,L]' . "\n";
    $apache_out .= '# ' . "\n";
    $apache_out .= '# <Location "/radicale/">' . "\n";
    $apache_out .= '#     ProxyPass        http://localhost:5232/ retry=0' . "\n";
    $apache_out .= '#     ProxyPassReverse http://localhost:5232/' . "\n";
    $apache_out .= '#     RequestHeader    set X-Script-Name /radicale' . "\n";
    $apache_out .= '# </Location>' . "\n";
    $apache_out .= '' . "\n";
}

# Write the file:
umask(0077);
open(STAGE, ">$radicale_apache");
print STAGE $apache_out;
close(STAGE);
&debug_msg("Updated $radicale_apache\n");
system("chown root:root $radicale_apache");
system("chmod 0644 $radicale_apache");

# Handle internal_auth config file as well:
if (! -f $internal_auth) {
    $internal_out = # Configuration for Radicale CalDAV + CardDAV: Enabled' . "\n";
    $internal_out .= '#' . "\n";
    $internal_out .= '# Internal authentication process for Radicale for BlueOnyx' . "\n";
    $internal_out .= '# ' . "\n";
    $internal_out .= '' . "\n";
    $internal_out .= 'Alias /internalauth /var/www/internalauth' . "\n";
    $internal_out .= '' . "\n";
    $internal_out .= 'DefineExternalAuth pwauth pipe /usr/bin/pwauth' . "\n";
    $internal_out .= '' . "\n";
    $internal_out .= '<Location /internalauth>' . "\n";
    $internal_out .= '    # External Authentication Configuration' . "\n";
    $internal_out .= '    AuthType Basic' . "\n";
    $internal_out .= '    AuthName "Internal Authentication"' . "\n";
    $internal_out .= '    AuthBasicProvider external' . "\n";
    $internal_out .= '    AuthExternal pwauth' . "\n";
    $internal_out .= '    ' . "\n";
    $internal_out .= '    # Allow access from localhost only and ask for authentication' . "\n";
    $internal_out .= '    <RequireAll>' . "\n";
    $internal_out .= '        Require local' . "\n";
    $internal_out .= '        Require valid-user' . "\n";
    $internal_out .= '    </RequireAll>' . "\n";
    $internal_out .= '</Location>' . "\n";
    $internal_out .= '' . "\n";

    # Write the file:
    umask(0077);
    open(STAGE, ">$internal_auth");
    print STAGE $internal_out;
    close(STAGE);
    &debug_msg("Created $internal_auth\n");
    system("chown root:root $internal_auth");
    system("chmod 0644 $internal_auth");
}

if (! -d $internal_path) {
    system("mkdir -p $internal_path");
    system("chown root:root $internal_path");
    system("chmod 0755 $internal_path");    
}

if (! -f $internal_success) {
    $internam_msg = 'Auth success';
    umask(0077);
    open(STAGE, ">$internal_success");
    print STAGE $internam_msg;
    close(STAGE);
    &debug_msg("Created $internal_success\n");
    system("chown root:root $internal_success");
    system("chmod 0644 $internal_success");
}

&debug_msg("Restarting Apache\n");
Sauce::Service::service_run_init('httpd', 'reload', 'nobg');

#
### Handle AdmServ include:
#

$admserv_out = '';

if ($RadicaleNS->{enabled} eq '1') {
    $admserv_out = # Configuration for Radicale CalDAV + CardDAV: Enabled' . "\n";
    $admserv_out .= '' . "\n";
    $admserv_out .= 'RewriteEngine On' . "\n";
    $admserv_out .= 'RewriteRule ^/radicale$ /radicale/ [R,L]' . "\n";
    $admserv_out .= '' . "\n";
    $admserv_out .= '<Location "/radicale/">' . "\n";
    $admserv_out .= '    ProxyPass        http://localhost:5232/ retry=0' . "\n";
    $admserv_out .= '    ProxyPassReverse http://localhost:5232/' . "\n";
    $admserv_out .= '    RequestHeader    set X-Script-Name /radicale' . "\n";
    $admserv_out .= '</Location>' . "\n";
    $admserv_out .= '' . "\n";
}
else {
    $admserv_out = '# Configuration for Radicale CalDAV + CardDAV: Disabled' . "\n";
    $admserv_out .= '' . "\n";
    $admserv_out .= '# RewriteEngine On' . "\n";
    $admserv_out .= '# RewriteRule ^/radicale$ /radicale/ [R,L]' . "\n";
    $admserv_out .= '# ' . "\n";
    $admserv_out .= '# <Location "/radicale/">' . "\n";
    $admserv_out .= '#     ProxyPass        http://localhost:5232/ retry=0' . "\n";
    $admserv_out .= '#     ProxyPassReverse http://localhost:5232/' . "\n";
    $admserv_out .= '#     RequestHeader    set X-Script-Name /radicale' . "\n";
    $admserv_out .= '# </Location>' . "\n";
    $admserv_out .= '' . "\n";
}

# Write the file:
umask(0077);
open(STAGE, ">$radicale_admserv");
print STAGE $admserv_out;
close(STAGE);
&debug_msg("Updated $radicale_admserv\n");
system("chown root:root $radicale_admserv");
system("chmod 0644 $radicale_admserv");

&debug_msg("Reloading AdmServ\n");
Sauce::Service::service_run_init('admserv', 'reload', 'nobg');

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

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