#!/usr/bin/perl
#
# $Id: /usr/sausalito/sbin/sync_hostname.pl
#
# Checks 'System' Object to see what server name should be.
# If real server names is different? Change server name to
# CODB defined values.
#

use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network);
use CCE;
use Data::Dumper;
use Sys::Hostname;
use Sauce::Config;
use Sauce::Util;
use File::Find;
use File::Slurp;
use Data::Dumper;
use List::Util qw(first);

my $cce = new CCE;
$cce->connectuds();

# Get 'System' object:
my ($sysoid) = $cce->find('System');
my ($ok, $System) = $cce->get($sysoid);
if (!$ok) {
    debug_msg("Failed to get 'System' object!\n");
    $cce->bye('FAIL');
    exit 1;
}

# Get Hostname from OS:
my $hostname = hostname();
my $domainname = '';
my $System_FQDN = $System->{'hostname'} . '.' . $System->{'domainname'};

if ($System_FQDN ne $hostname) {
    # update /etc/hostname
    {
      my $fn = sub {
            my ($fin, $fout) = (shift, shift);
            my $System_FQDN = shift;
            print $fout $System_FQDN,"\n";
            return 1;
        };
        Sauce::Util::editfile('/etc/hostname', $fn, $System_FQDN)
    }
  
    # run the hostname command 
    Sauce::Util::addrollbackcommand("/bin/hostname " . `/bin/hostname`);
    system('/bin/hostname', $System_FQDN);
    system("/usr/bin/nmcli g hostname $System_FQDN");
    system("/usr/bin/hostnamectl set-hostname $System_FQDN");
    system("/usr/bin/hostnamectl set-hostname $System_FQDN --static");
    system("/usr/bin/hostnamectl set-hostname $System_FQDN --transient");
    system("/usr/bin/systemctl restart systemd-hostnamed");
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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
