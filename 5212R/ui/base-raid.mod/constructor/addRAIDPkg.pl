#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: addRAIDPkg.pl
#

use CCE;
use Cobalt::RAID;
my $cce = new CCE;

$cce->connectuds();

my $packageName = 'RAID'; 
my $isConfigurable = 0;
my ($system, $ok, $prod, $level);

my @oids = $cce->find('System', {});
if (@oids == 1) {
    ($ok, $system) = $cce->get($oids[0]);
    if ($ok) {
        $prod = ${$system}{'productBuild'};
        
        # raid level is not configurable on Bluapp
        my $home_partition = `/bin/df -l -P /home | grep "/home"`;
        $home_partition = (split / +/, $home_partition)[0];
        if ($home_partition =~ /VolGroup00/) {
            my $lvm = 1;
            my $volgroup_name = "VolGroup00";
            my $home_device = `/usr/sbin/vgdisplay $volgroup_name --verbose | /bin/grep 'PV Name'`;
            ($home_device) = (split / +/, $home_device)[3];
            if ($home_device =~ /\/dev\/md/) {
                $raid = 1;
                $level = `/sbin/mdadm --misc -D $home_device |grep 'Raid Level'`;
                ($level) = (split /raid/, $level)[1];
            }
            else {
                $raid = 0;
                $level = 0;
            }
        }
        elsif ($home_partition =~ /\/dev\/md/) {
            $home_device = $home_partition;
            $lvm = 0;
            $raid = 1;
            $level = `/sbin/mdadm --misc -D $home_device |grep 'Raid Level'`;
            ($level) = (split /raid/, $level)[1];
        }
        else {
            $lvm = 0;
            $raid = 0;
            $level = 0;
        }
    }
}

my $add_raid_pkg = 1 if ($raid);

my $disks = Cobalt::RAID::raid_get_numdisk();

if ($disks !~ /^\d+$/) {
    $disks=0;
}

if (@oids == 1) {
    # The level can only be set once (reconfiguration is not allowed)
    # therefore we don't set a level now for configurable systems
    if ($level) {
        $ok = $cce->update($oids[0], 'RAID', { level => $level, configurable => $isConfigurable, disks => $disks }); 
    }
    else {
        $ok = $cce->update($oids[0], 'RAID', { configurable => $isConfigurable, disks => $disks }); 
    }
}

$cce->bye();
exit 0;

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