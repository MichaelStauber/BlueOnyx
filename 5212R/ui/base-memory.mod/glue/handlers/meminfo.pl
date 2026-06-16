#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: meminfo.pl
#
# Author: Kevin K.M. Chiu
#

use CCE;
use strict;

my $cce = new CCE;
$cce->connectfd();

# /proc/meminfo
if(!open(MEMINFO, "</proc/meminfo")) {
    $cce->bye("FAIL", "[[base-memory.cantOpenMeminfo]]");
    exit 1;
}

# read memory information
my $memTotal = 0;
my $memFree = 0;
my $memShared = 0;
my $buffers = 0;
my $cached = 0;
my $swapTotal = 0;
my $swapFree = 0;
while(<MEMINFO>) {
    if(/^MemTotal/o) {
    my @info = split(/\s+/o);
    $memTotal = $info[1];
    }

    if(/^MemFree/o) {
    my @info = split(/\s+/o);
    $memFree = $info[1];
    }

    if(/^MemShared/o) {
    my @info = split(/\s+/o);
    $memShared = $info[1];
    }

    if(/^Buffers/o) {
    my @info = split(/\s+/o);
    $buffers = $info[1];
    }

    if(/^Cached/o) {
    my @info = split(/\s+/o);
    $cached = $info[1];
    }

    if(/^SwapTotal/o) {
    my @info = split(/\s+/o);
    $swapTotal = $info[1];
    }

    if(/^SwapFree/o) {
    my @info = split(/\s+/o);
    $swapFree = $info[1];
    }
}
close MEMINFO;

# calculate total physical memory
# the total is always less the real amount because kernel use up some memory
# this algorithm is only good if the kernel don't take up >16MB and
# memory is blocks of 16MB
my $physicalMemTotal = int (($memTotal+16384)/16384)*16;

# 4GB systems use the top 64MB as PCI & ROM address space
# pad it out by 64 to match the real DIMM total
#if($physicalMemTotal >= 4032) {
#    $physicalMemTotal = 4096;
#}

# write result to CCE
my @oids = $cce->find("System");
if($#oids < 0) {
    $cce->bye("FAIL", "[[base-memory.systemObjectNotFound]]");
    exit 1;
}
my ($ok, $badKeys, @info) = $cce->set($oids[0], "Memory", {
    "physicalMemTotal" => $physicalMemTotal,
    "memTotal" => $memTotal,
    "memFree" => $memFree,
    "memShared" => $memShared,
    "buffers" => $buffers,
    "cached" => $cached,
    "swapTotal" => $swapTotal,
    "swapFree" => $swapFree
});

if(!$ok) {
    $cce->bye("FAIL", "[[base-memory.cantSetSystemObject]]");
    exit(1);
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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