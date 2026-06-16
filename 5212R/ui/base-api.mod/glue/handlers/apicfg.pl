#!/usr/bin/perl -I. -I/usr/sausalito/perl
# $Id: apicfg.pl

use CCE;
use JSON;
use Sauce::Service;

my $DEBUG = 0;
if ($DEBUG) { 
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# Config file location for APIv1:
$cfg_file = '/usr/sausalito/configs/api/ips.json';
$cfg_file_dir = '/usr/sausalito/configs/api';

# Config file location for APIv2:
$apiv2_config_file = '/etc/cced-api/config/cced-api.conf';

my $cce = new CCE;

$cce->connectfd();

&debug_msg("Startup!");

my @oids = $cce->find('System');
if (not @oids) {
    &debug_msg("Unable to find System object");
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $API) = $cce->get($oids[0], 'API');
unless ($ok and $API) {
    &debug_msg("Unable to find System object's API namespace");
    $cce->bye('FAIL');
    exit 1;
}

#
### Handle V1 API config:
#

my @apiHosts = ();
if (($API->{'enabled'} eq '1') || ($API->{'api_enabled'} eq '1')) {
    if ($API->{'apiHosts'}) {
        @apiHosts = $cce->scalar_to_array($API->{'apiHosts'});
    }
}

# Encode and write config file:
my $json = encode_json(\@apiHosts);
if (! -d $cfg_file_dir) {
    system("mkdir $cfg_file_dir");
    system("chown root:root $cfg_file_dir");
    system("chmod 0755 $cfg_file_dir");    
}
&debug_msg("Writing updated $cfg_file");
open(my $fh, '>', $cfg_file);
print $fh $json . "\n";
close $fh;
system("chown admserv:admserv $cfg_file");
system("chmod 0644 $cfg_file");

#
### Handle V2 API config:
#

$api_access_list = '';
if ($API->{'api_access'}) {
    @raw_api_access = $cce->scalar_to_array($API->{'api_access'});
    $api_access_list = join(",", @raw_api_access);
}

# Configuration values from variables
$unix_socket_path   = '/usr/sausalito/cced.socket';
$socket_timeout     = $API->{'socket_read_timeout'};
$listen_address     = $API->{'listen_address'} . ':' . $API->{'listen_port'};
$cert_file          = '/etc/cced-api/certs/certificate';
$key_file           = '/etc/cced-api/certs/key';
$ca_cert_file       = '/etc/cced-api/certs/ca-certs';
$enable_http        = 'false';
$logging            = 'true';
if ($API->{'logging'} eq "0") {
    $logging            = 'false';
}
$debuglog           = 'false';
if ($API->{'debuglog'} eq "1") {
    $debuglog           = 'true';
}
$api_access         = $api_access_list;
$token_lifetime     = $API->{'token_lifetime'};
$api_auth_fails     = $API->{'api_auth_fails'};
$api_ban_time       = $API->{'api_ban_time'};

# Open file and write contents
open($fh, '>', $apiv2_config_file);

print $fh <<"EOF";
unix_socket_path = $unix_socket_path
socket_read_timeout = $socket_timeout
listen_address = $listen_address
cert_file = $cert_file
key_file = $key_file
ca_cert_file = $ca_cert_file
enable_http = $enable_http
logging = $logging
debuglog = $debuglog
api_access = $api_access
token_lifetime = $token_lifetime
api_auth_fails = $api_auth_fails
api_ban_time = $api_ban_time
EOF
close($fh);

system("chown root:root $apiv2_config_file");
system("chmod 644 $apiv2_config_file");

&debug_msg("api_access = $api_access_list");
&debug_msg("listen_address = $listen_address");

Sauce::Service::service_toggle_init('cced-api', '1');
Sauce::Service::service_run_init('cced-api', 'restart');

# Done:
$cce->bye('SUCCESS');
exit 0;

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