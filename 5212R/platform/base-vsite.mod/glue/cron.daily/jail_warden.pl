#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: jail_warden.pl
#
# Checks the integrity of jails and updates the binaries within
# the jails if they no longer match the checksum of the OS 
# binaries that they're based on.
#

use CCE;

my $cce = new CCE('Domain' => 'base-vsite');
$cce->connectuds();

# Find all Vsites:
my @Vsites = ();
my (@Vsites) = $cce->findx('Vsite');
foreach my $oid (@Vsites) {
    my ($vok, $Vsite) = $cce->get($oid, '');
    my ($aok, $Shell) = $cce->get($oid, 'Shell');
    if (($aok) && ($vok)) {
        if ($Shell->{enabled} gt '0') {
            $vsite_jail = $Vsite->{basedir};
            $vsite_home_jail = $Vsite->{basedir} . '/home';
            if (-d $vsite_jail) {
                system("/usr/sbin/jk_update -k -j $vsite_jail -s /opt -s /lib >/dev/null 2>&1 ||:");
                system("/usr/sbin/jk_init -j $vsite_jail -k basicshell editors extendedshell netutils ssh sftp scp pico id logbasics jk_lsh >/dev/null 2>&1 ||:");
            }
            if (-d $vsite_home_jail) {
                system("/usr/sbin/jk_update -k -j $vsite_home_jail -s /opt -s /lib >/dev/null 2>&1 ||:");
                system("/usr/sbin/jk_init -j $vsite_home_jail -k basicshell editors extendedshell netutils ssh sftp scp pico id logbasics jk_lsh >/dev/null 2>&1 ||:");
            }
        }
    }
}

$cce->bye('SUCCESS');
exit(0);

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
