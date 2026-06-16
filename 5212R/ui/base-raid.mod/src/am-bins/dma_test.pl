#!/usr/bin/perl
# $Id: dma_test.pl
#
# AM test
# check if DMA is enabled on all drives
# if it's not, that's yellow
# if it is, that's green

use warnings;
use strict;
use lib qw(/usr/sausalito/perl);
use AM::Util;
use Cobalt::RAID qw(raid_get_raidtab);
use CCE;

my $DEBUG = 0;
my %am_states = am_get_statecodes();

# find which disks to use. We want physical disks that 
# make up all Disk objects in CCE. 
# so if the Disk object is a raid device,
# we look in raidtab for the physical disks.
my %disks = ();
my %raidtab = raid_get_raidtab();
my $cce = new CCE;
$cce->connectuds();
my ($DMA_ON, $DMA_OFF, $DMA_NON_EXISTING) = (1, 0, -1);

my (@oids) = $cce->find('Disk');

foreach my $oid (@oids) {
    my ($ok, $obj) = $cce->get($oid);
    my $device = $obj->{device};
    if ($device !~ /^\/dev\// ) {
        next;
    }
    if ($raidtab{$device}) {
        foreach my $dev (@{$raidtab{$device}}) {
            $dev =~ s/\d+$//;
            $disks{$dev} = 1;
        }
    }
    else {
        my $dev = $device;
        $dev =~ s/\d+$//;
        $disks{$dev} = 1;
    }
}

my @physicaldrives = keys %disks; # using a hash to remove duplicates

my $dma_ok = 1;
my @output = ();
my @fixed_drives = ();
my @problem_drives = ();
my ($display_names, $drives);
my @names = ();
my $status;
my $worst_state;
my $systype = `cat /proc/cobalt/systype 2> /dev/null`;
chomp($systype);

foreach my $drive (@physicaldrives) {
    $DEBUG && print STDERR "Checking drive $drive\n";
    
    $status = is_dma_on($drive);
    # if non existent, then ok
    if ($status == $DMA_NON_EXISTING) {
        $DEBUG && print STDERR "Drive does not exist, silently succeed\n";
        next;
    }   
    # if on, then ok
    if ($status == $DMA_ON) {
        $DEBUG && print STDERR "DMA is on\n";
        next;
    }

    # if off, turn back on
    $DEBUG && print STDERR "DMA is off, attempting to turn back on\n";
    turn_dma_on($drive);

    # if can turn back on, then yellow
    $status = is_dma_on($drive);
    if ($status == $DMA_ON) {
        $DEBUG && print STDERR "DMA is back on\n";
        push @fixed_drives, $drive;
        next;
    }

    # if can't turn back on, then red
    $DEBUG && print STDERR "DMA is still off\n";
    push @problem_drives, $drive;
}

# gather all output strings
if (@problem_drives) {
    $drives = join(', ', @problem_drives);

    @names = ();
    foreach my $drive (@problem_drives) {
        $drive =~ s/\//_/g;
        push @names, "[[base-raid.$systype$drive]]";
    }

    $display_names = join(', ', @names);


    # the "drives" parameter is not used in display, but is parsed by the UI for data transfer purposes
    push @output, "[[base-dma.dma_off_drives,drives=\"$drives\",display_names=\"$display_names\"]]";
}
if (@fixed_drives) {
    $drives = join(', ', @fixed_drives);

    @names = ();
    foreach my $drive (@fixed_drives) {
        $drive =~ s/\//_/g;
        push @names, "[[base-raid.$systype$drive]]";
    }

    $display_names = join(', ', @names);

    # the "drives" parameter is not used in display, but is parsed by the UI for data transfer purposes
    push @output, "[[base-dma.dma_fixed_drives,drives=\"$drives\",display_names=\"$display_names\"]]";
}

# no strings means we have no errors, so say we're okay
if (!@output) {
    push @output, "[[base-dma.dma_ok]]";
}

# get worst state
if (@problem_drives) {
    $worst_state = $am_states{AM_STATE_RED};
}
elsif (@fixed_drives) {
    $worst_state = $am_states{AM_STATE_YELLOW};
}
else {
    $worst_state = $am_states{AM_STATE_GREEN};
}

# print output
print join("\n", @output);

# return worst state
exit $worst_state;


######################## helper functions
sub is_dma_on {
    my $drive = shift;
    my $command = "/sbin/hdparm -d $drive 2> /dev/null";
    my $ret = `$command`;
    if ($ret =~ /\(on\)/) {
        return 1;
    }
    elsif ($ret =~ /\(off\)/){
        return 0;
    }
    else {
        # got no output
        # means the drive doesn't exist
        return -1;
    }
}

sub turn_dma_on {
    my $drive = shift;
    my $command = "/sbin/hdparm -d1 $drive 2> /dev/null";
    my $ret = `$command`;
}

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