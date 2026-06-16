#!/usr/bin/perl -I/usr/sausalito/perl -I.
# $Id: mbox.pl
# updates /etc/sysconfig/bxmbox
#

use Sauce::Util;
use CCE;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd();

# get system and network object ids:
my ($system_oid) = $cce->find("System");

# get system object:
my ($ok, $System) = $cce->get($system_oid);
if (!$ok) { 
    $cce->bye('FAIL');
    exit(1);
}

my ($ok, $Email) = $cce->get($system_oid, 'Email');
if (!$ok) { 
    $cce->bye('FAIL');
    exit(1);
}

# Default Mailbox format:
my $MAILBOX = 'MBOX';
my $service_name = 'dovecot';

if ($System->{'Mailbox'} ne '') {
    $MAILBOX = $System->{'Mailbox'};
}

# Target files
my $SysCFGfileName = '/etc/sysconfig/bxmbox';
my $procmailCFG = '/etc/procmailrc';
my $dovecotCFG = '/etc/dovecot/conf.d/10-mail.conf';

# File contends:
my $my_cfg = 'MAILBOX=' . $MAILBOX . "\n";

#
### Write config file /etc/sysconfig/bxmbox:
#
open(my $fh, '>', $SysCFGfileName);
print $fh $my_cfg;
close $fh;
&debug_msg("Created $SysCFGfileName with MAILBOX set to: $MAILBOX");
Sauce::Util::chmodfile(0644, $SysCFGfileName);
Sauce::Util::chownfile('root', 'root', $SysCFGfileName);

#
### Write config file /etc/procmailrc:
#

if ($MAILBOX eq 'MAILDIR') {
    $my_cfgProcmail = 'ORGMAIL=$HOME/Maildir/' . "\n";
    $my_cfgProcmail .= 'DEFAULT=$ORGMAIL' . "\n";
    $my_cfgProcmail .= 'MAILDIR=$ORGMAIL' . "\n";
}
else {
    $my_cfgProcmail = 'ORGMAIL=$HOME/mbox' . "\n";
    $my_cfgProcmail .= 'DEFAULT=$ORGMAIL' . "\n";
}

open(my $fh, '>', $procmailCFG);
print $fh $my_cfgProcmail;
close $fh;
&debug_msg("Updated $procmailCFG with provisions for $MAILBOX");
Sauce::Util::chmodfile(0644, $procmailCFG);
Sauce::Util::chownfile('root', 'root', $procmailCFG);

#
### Edit config file /etc/dovecot/conf.d/10-mail.conf:
#

# mail_location = mbox:~/mail/:INBOX=mbox
# mail_location = maildir:~/Maildir:LAYOUT=fs

if (-f $dovecotCFG) {
    &debug_msg("Updating $dovecotCFG with provisions for $MAILBOX");
    if ($MAILBOX eq 'MAILDIR') {
        &debug_msg("sed -i -E 's#^mail_location(.*)\$#mail_location = maildir:~/Maildir:LAYOUT=fs#g' $dovecotCFG\n");
        system("sed -i -E 's#^mail_location(.*)\$#mail_location = maildir:~/Maildir:LAYOUT=fs#g' $dovecotCFG");
    }
    else {
        &debug_msg("sed -i -E 's#^mail_location(.*)\$#mail_location = mbox:~/mail/:INBOX=mbox#g' $dovecotCFG\n");
        system("sed -i -E 's#^mail_location(.*)\$#mail_location = mbox:~/mail/:INBOX=mbox#g' $dovecotCFG");
    }
}

# Conditionally restart Dovecot, provided it's enabled in the GUI:
if (($Email->{'enableImap'} eq '1') || ($Email->{'enableImaps'} eq '1') || ($Email->{'enablePop'} eq '1') || ($Email->{'enablePops'} eq '1')) {
    &debug_msg("Restarting $service_name\n");
    system("systemctl restart $service_name");
}

$cce->bye('SUCCESS');
exit(0);

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