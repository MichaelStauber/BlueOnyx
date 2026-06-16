#!/usr/bin/perl -I/usr/sausalito/perl
#

# disables AM on non-RAID system

use CCE;
my $cce = new CCE;

if (-f "/proc/mdstat")  {
    my $ret = `grep -q raid /proc/mdstat`;
    my $on = $? >> 8;
}
else {
    my $on = "0";
}

$cce->connectuds();
my @oids = $cce->find ('ActiveMonitor');
if ($#oids > -1) {
    if ($on == "1") {
        $cce->update($oids[0], 'RAID', {"monitor" => "1"});
        $cce->update($oids[0], 'SMART', {"monitor" => "1"});
        $cce->update($oids[0], 'DMA', {"monitor" => "1"});
        $cce->update($oids[0], 'DiskIntegrity', {"monitor" => "1"});
    }
    else {
        $cce->update($oids[0], 'RAID', {"monitor" => "0"});
        $cce->update($oids[0], 'SMART', {"monitor" => "0"});
        $cce->update($oids[0], 'DMA', {"monitor" => "0"});
        $cce->update($oids[0], 'DiskIntegrity', {"monitor" => "0"});
    }
}
$cce->bye();
exit 0;

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2000 Cobalt Networks
# Copyright (c) 2004 Turbolinux, inc.
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