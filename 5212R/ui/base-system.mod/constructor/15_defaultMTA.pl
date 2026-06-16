#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: 15_defaultMTA.pl

use Sauce::Util;
use CCE;

# Debugging switch:
$DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectuds();

# get system and network object ids:
my ($system_oid) = $cce->find("System");

# get system object:
my ($ok, $System) = $cce->get($system_oid);
if (!$ok) { 
    $cce->bye('FAIL');
    exit(1);
}

# Default MTA:
my $MTA = 'POSTFIX';

if ($System->{'MTA'} ne '') {
    $MTA = $System->{'MTA'};
}

# Target file
my $fileName = '/etc/sysconfig/bxmta';

# File contends:
my $my_cfg = 'MTA=' . $MTA . "\n";

# Write config file:
open(my $fh, '>', $fileName);
print $fh $my_cfg;
close $fh;
&debug_msg("Created $fileName with MTA set to: $MTA\n");
Sauce::Util::chmodfile(0644, $fileName);
Sauce::Util::chownfile('root', 'root', $fileName);

if ($MTA eq 'POSTFIX') {
    &debug_msg("Turning off and disabling Sendmail.\n");
    # And we can't use Sauce::Service() for this, as it would rewrite that to the current active MTA!
    system("systemctl stop sendmail");
    system("systemctl disable sendmail");
    #system("/usr/sbin/alternatives --set mta /usr/sbin/sendmail.postfix");
    system("/usr/sbin/alternatives --set mta /usr/sbin/sendmail.sendmail");
}
if ($MTA eq 'SENDMAIL') {
    &debug_msg("Turning off and disabling Postfix.\n");
    # And we can't use Sauce::Service() for this, as it would rewrite that to the current active MTA!
    system("systemctl stop postfix");
    system("systemctl disable postfix");
    system("/usr/sbin/alternatives --set mta /usr/sbin/sendmail.sendmail");
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
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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