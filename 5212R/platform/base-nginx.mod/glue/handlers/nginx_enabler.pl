#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: nginx_enabler.pl

# Debugging switch:
$DEBUG = "0";
if ($DEBUG)
{
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use Sauce::Util;

my $cce = new CCE;
$cce->connectfd();

@SysOID = $cce->find("System");
($AMoid) = $cce->find('ActiveMonitor');

($ok, $System) = $cce->get($SysOID[0]);
($ok, $Nginx) = $cce->get($SysOID[0], 'Nginx');

if ($Nginx->{enabled} eq "1") {
    ($ok) = $cce->set($AMoid, 'Nginx',{
        'enabled' => '1',
        'monitor' => '1'
    });
}
else {
        ($ok) = $cce->set($AMoid, 'Nginx',{
            'enabled' => '0',
            'monitor' => '0'
        });
}

# When Nginx terminates TLS it talks HTTP/1.1 to Apache on port 80.
# Apache would otherwise answer with Upgrade: h2, which Nginx forwards
# into the HTTP/2 response (illegal; Safari/curl fail). Only emit this
# drop-in while the SSL proxy is enabled so native Apache HTTPS HTTP/2
# is unchanged.
&sync_h2upgrade_off($Nginx->{enabled});

# Find all Vsites:
@vhosts = ();
(@vhosts) = $cce->findx('Vsite');

$combined_cert = '/etc/admserv/certs/nginx_cert_ca_combined';
$the_ca_cert = '/etc/admserv/certs/ca-certs';
$the_cert = '/etc/admserv/certs/certificate';
$the_blank = '/etc/admserv/certs/blank.txt';

if (! -f $the_blank) {
    system("echo \"\" > $the_blank");
    system("chmod 640 $the_blank");
}

if ((-f $the_ca_cert) && (-f $the_cert)) {
    system("cat $the_cert $the_blank $the_ca_cert > $combined_cert");
    system("chmod 640 $combined_cert");
}
elsif ((! -f $the_ca_cert) && (-f $the_cert)) {
    # We have no intermediate.
    system("cat $the_cert > $combined_cert");
    system("chmod 640 $combined_cert");
}
if (! -f $combined_cert) {
    # If we still have noting, we go bare:
    system("touch $combined_cert");
    system("chmod 640 $combined_cert");
}

# Walk through all Vsites:
for $vsite (@vhosts) {
    ($ok, $my_vsite) = $cce->get($vsite);
    ($ok, $xvsite_SSL) = $cce->get($vsite, 'SSL');
    if ($xvsite_SSL->{'enabled'} == "1") {
        &debug_msg("Vsite $my_vsite->{fqdn} has SSL enabled. Forcing it to update.\n");
        ($ok) = $cce->set($vsite, '',{ 'force_update' => time()});
    }

    @oids = $cce->find('Subdomains', { 'group' => $my_vsite->{'name'} });
    &debug_msg("Processing SSL for Vsite Subdomains \n");
    foreach $sub_oid (@oids) {
        # Update all 'Subdomains' Objects related to this Vsite:
        ($ok) = $cce->set($sub_oid, '', { 'force_update' => time() });
    }
}

$cce->bye('SUCCESS');
exit(0);

#
### Subroutines:
#

sub sync_h2upgrade_off {
    my $enabled = shift;
    my $file = '/etc/httpd/conf.d/zz-h2upgrade-off.conf';

    if ($enabled eq '1') {
        if (open(my $fh, '>', $file)) {
            print $fh <<'END';
#
# Written by nginx_enabler.pl only while Nginx is the SSL proxy.
# Removed again when the proxy is disabled.
#
# Nginx talks HTTP/1.1 to Apache on port 80. Without this, Apache
# answers with Upgrade: h2, which Nginx forwards into HTTP/2
# (RFC 9113 8.2.2 — Safari/curl treat that as malformed).
# Native HTTPS HTTP/2 via ALPN is unaffected.
#
<IfModule http2_module>
    H2Upgrade Off
</IfModule>
END
            close($fh);
            chmod 0644, $file;
        }
        else {
            &debug_msg("Could not write $file\n");
        }
    }
    elsif (-f $file) {
        unlink $file;
    }
}

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
# Copyright (c) 2018-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2018-2022 Team BlueOnyx, BLUEONYX.IT
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