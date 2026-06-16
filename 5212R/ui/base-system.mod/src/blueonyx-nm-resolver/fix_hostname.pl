#!/usr/bin/perl
#
# This script sets the name of the server to the FQDN configured
# via BlueOnyx cloud-config (if present) or to the host- and domain
# name set in CODB's 'System' object.
#

use lib qw(/usr/sausalito/perl);
use CCE;
use Sauce::Util;
use Sys::Hostname;

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

#
### Special Incus privions (Cloud-Init that works!)
#

my %BX_CLOUD = {};
$bx_cloud_cfg = '/tmp/bx_cloud_cfg';
if ((-e '/dev/incus/sock') && (-f $bx_cloud_cfg)) {
    open my $fh, '<', $bx_cloud_cfg or die "Could not open '$bx_cloud_cfg': $!";

    while (my $line = <$fh>) {
        chomp $line;
        my ($key, $value) = split(/\|/, $line, 2);

        # Check if the key is not empty and the value is defined
        if (defined $key && $key ne '' && defined $value && $value ne '') {
            if ($key eq 'FQDN') {
                my ($hostname, $domainname) = split(/\./, $value, 2);
                $BX_CLOUD->{'hostname'} = $hostname;
                $BX_CLOUD->{'domainname'} = $domainname;
            }
            else {
                $BX_CLOUD->{$key} = $value;
            }
        }
    }
    close $fh;
}

#
### Hostname check
#

# Get Hostname from OS:
my $hostname = hostname();
my $domainname = '';
my $System_FQDN = $System->{'hostname'} . '.' . $System->{'domainname'};

if (defined($BX_CLOUD->{'hostname'}) && $BX_CLOUD->{'hostname'} ne '' && 
    defined($BX_CLOUD->{'domainname'}) && $BX_CLOUD->{'domainname'} ne '') {
    $hostname = $BX_CLOUD->{'hostname'};
    $domainname = $BX_CLOUD->{'domainname'};
    $System_FQDN = $hostname . '.' . $domainname;
} 

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
    system("/usr/bin/hostnamectl set-hostname $System_FQDN --static >/dev/null 2>&1");
    system("/usr/bin/hostnamectl set-hostname $System_FQDN --transient >/dev/null 2>&1");
    system("/usr/bin/systemctl restart systemd-hostnamed");
}

$cce->bye('SUCCESS');
exit(0);

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

