#!/usr/bin/perl -I/usr/sausalito/perl -I.
# $Id: mbox_converter.pl
#

use Sauce::Util;
use CCE;

# Debugging switch:
$DEBUG = "1";
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

if ($MAILBOX eq "MBOX") {
    &debug_msg("Running: /usr/sausalito/sbin/mbox_maildir_convert.pl --mbox\n");
    system("/usr/sausalito/sbin/mbox_maildir_convert.pl --mbox");
}
else {
    &debug_msg("Running: /usr/sausalito/sbin/mbox_maildir_convert.pl --maildir\n");
    system("/usr/sausalito/sbin/mbox_maildir_convert.pl --maildir");
}

&debug_msg("Finished Mailbox conversion!\n");

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
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#  notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#  notice, this list of conditions and the following disclaimer in 
#  the documentation and/or other materials provided with the 
#  distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#  contributors may be used to endorse or promote products derived 
#  from this software without specific prior written permission.
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