#!/usr/bin/perl -I/usr/sausalito/perl
# Initial Author: Brian N. Smith
# $Id: subdomain-delete.pl

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debugging enabled for subdomain-delete.pl\n");
}

use CCE;
use Sauce::Service;

$cce = new CCE;
$cce->connectfd();

$old = $cce->event_old();

$subdomain_config_dir = "/etc/httpd/conf.d/subdomains";
if ($old->{'hostname'} ne '') {
    $subdomain_config_file = $subdomain_config_dir . "/" . $old->{'group'} . "-" . $old->{'hostname'} . '.' . $old->{'domainname'} . ".conf";
    $nginx_vhosts_file = '/etc/nginx/vsites/' . $old->{'group'} . "-" . $old->{'hostname'} . '.' . $old->{'domainname'};
}
else {
    $subdomain_config_file = $subdomain_config_dir . "/" . $old->{'group'} . "-" . $old->{'domainname'} . ".conf";
    $nginx_vhosts_file = '/etc/nginx/vsites/' . $old->{'group'} . "-" . $old->{'domainname'};
}

&debug_msg("Running: /bin/rm -f $subdomain_config_file\n");
system("/bin/rm -f $subdomain_config_file");

&debug_msg("Running: /bin/rm -f $nginx_vhosts_file\n");
system("/bin/rm -f $nginx_vhosts_file");

# Trigger a run of /usr/sausalito/base/apache/virtual_host.pl
@oids = $cce->find('Vsite', { 'name' => $old->{'group'} });
($ok) = $cce->set($oids[0], '', { 'force_update' => time() });

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
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2008 NuOnce Networks, Inc.
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