#!/usr/bin/perl -w -I/usr/sausalito/perl

use Sauce::Service;
use Sauce::Util;
use CCE;

my $cce = new CCE( Namespace => 'Email', Domain => 'base-email' );

$cce->connectfd();

# for smtps
system("echo \"\" > /etc/admserv/certs/blank.txt"); 
system("cat /etc/admserv/certs/key /etc/admserv/certs/blank.txt /etc/admserv/certs/certificate > /usr/share/ssl/certs/sendmail.pem");
if (-f '/etc/admserv/certs/ca-certs') {
    # If AdmServ has an intermediate, then copy it to the ca-bundle as well:
    system("cat /etc/admserv/certs/ca-certs >> /usr/share/ssl/certs/ca-bundle.crt")
}
system("cp /usr/share/ssl/certs/sendmail.pem /etc/pki/tls/certs/postfix.pem");
chmod 0600, "/usr/share/ssl/certs/sendmail.pem";
chmod 0600, "/usr/share/ssl/certs/ca-bundle.crt";
chmod 0644, "/etc/pki/tls/certs/postfix.pem";
Sauce::Service::service_run_init('sendmail', 'restart');

# Handle Dovecot key and cert:
system("/bin/cp /etc/admserv/certs/key /etc/pki/dovecot/private/dovecot.pem");
system("/bin/cp /etc/admserv/certs/certificate /etc/pki/dovecot/certs/dovecot.pem");

# Handle Dovecot intermediate cert:
if (-f "/etc/admserv/certs/ca-certs") {
    system("/bin/cp /etc/admserv/certs/ca-certs /etc/pki/dovecot/certs/ca.pem");
    system("cat /etc/pki/dovecot/certs/dovecot.pem /etc/admserv/certs/blank.txt /etc/pki/dovecot/certs/ca.pem > /etc/pki/dovecot/certs/combined.pem");
    system("mv /etc/pki/dovecot/certs/combined.pem /etc/pki/dovecot/certs/dovecot.pem");
    chmod 0600, "/etc/pki/dovecot/certs/dovecot.pem";
}
else {
    system("touch /etc/pki/dovecot/certs/ca.pem");
}
chmod 0600, "/etc/pki/dovecot/certs/ca.pem";

# Edit /etc/dovecot/conf.d/10-ssl.conf:
&edit_dovecot_intermediate;

Sauce::Service::service_run_init('dovecot', 'restart');

# Restart xinetd as well, so that ProFTPd gets to know the new cert:
Sauce::Service::service_run_init('xinetd', 'restart');

$cce->bye("SUCCESS");
exit(0);

sub edit_dovecot_intermediate {

    # Build output hash:
    $server_dovecot_settings_writeoff = { 
        'ssl_ca' => "</etc/pki/dovecot/certs/ca.pem"
    };

    # Write changes using Sauce::Util::hash_edit_function:

    $ok = Sauce::Util::editfile(
        "/etc/dovecot/conf.d/10-ssl.conf",
        *Sauce::Util::hash_edit_function,
        '#',
        { 're' => '=', 'val' => ' = ' },
        $server_dovecot_settings_writeoff);

    system('/bin/rm -f /etc/dovecot/conf.d/10-ssl.conf.backup.*');

    # Error handling:
    unless ($ok) {
        $cce->bye('FAIL', "Error while editing /etc/dovecot/conf.d/10-ssl.conf!");
        exit(1);
    }
}

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. All  Rights Reserved.
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