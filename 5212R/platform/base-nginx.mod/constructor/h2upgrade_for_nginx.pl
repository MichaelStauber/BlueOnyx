#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: h2upgrade_for_nginx.pl
#
# On package install / constructor pass: emit or remove
# /etc/httpd/conf.d/zz-h2upgrade-off.conf according to System.Nginx.enabled.
# Live toggles are handled by glue/handlers/nginx_enabler.pl.

use CCE;

my $cce = new CCE;
$cce->connectuds();

my $file = '/etc/httpd/conf.d/zz-h2upgrade-off.conf';
my ($oid) = $cce->find('System');
my ($ok, $Nginx) = $cce->get($oid, 'Nginx');

if ($ok && $Nginx->{enabled} eq '1') {
    if (open(my $fh, '>', $file)) {
        print $fh <<'END';
#
# Written only while Nginx is the SSL proxy.
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
}
elsif (-f $file) {
    unlink $file;
}

$cce->bye('SUCCESS');
exit(0);


# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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