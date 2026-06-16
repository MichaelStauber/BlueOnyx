#!/usr/bin/perl -I /usr/sausalito/perl
# Author: Michael Waychison <michael.waychison@sun.com>
# $Id: SecurityLevels.pm
#
# Script the simplify the constructor creation of CapabilityGroup objects used 
# be the arbitrary Security Level framework.

package SecurityLevels;
my $DEBUG = 0;

use strict;
use CCE;
use Data::Dumper;

sub new {
    my $proto = shift;
    my $cce = shift;
    my $self = {};
    if (!$cce) {
        $cce = new CCE();
        $cce->connectuds();
    }  
    $self->{cce} = $cce;
    bless($self, $proto);
    return $self;
}


sub updateSecurityLevels {
    my ($self, $securityLevelName, $nameTag, $nameTagHelp, @capabilities) = @_;
 
    # check if we have enough args...
    if ((scalar @_) < 4) {
        warn("Usage:  \n"
            . "updateSecurityLevels(CapabilityGroupName, I18nTag, "
            . "I18nTagHelp, [item, [...]])\n"
            . "\tCapabilityGroupName  -  The name of the grouping\n"
            . "\tI18nTag  -  A localisation tag to be displayed in the UI\n"
            . "\tI18nTagHelp  -  A localised description of this group\n"
            . "\titem, [...]  -  A list of children to this grouping\n");

        return(0);
    }

    my $cce = $self->{cce};

    my @finds = $cce->find("CapabilityGroup", {name=>$securityLevelName});
    if (0 == (scalar (@finds))) {
        # create new object...
        $DEBUG && print STDERR "Creating CapabilityGroup '$securityLevelName'... ";
        my ($ok) = $cce->create("CapabilityGroup", {
            name=> $securityLevelName, 
            nameTag =>$nameTag,
            nameTagHelp =>$nameTagHelp,
            capabilities => ($cce->array_to_scalar(@capabilities)) } );
        
        $DEBUG && print STDERR ($ok ? "done\n": "failed\n");
        $self->{oid} = $cce->oid();
    
    }
    else {
        # check if we need to update the object..
        my ($ok, $obj, @info) = $cce->get($finds[0]);
        $self->{oid} = $finds[0];
        my %changes; # a list of the changes that need to be made..
    
        # check for changes in each individual element.
        if ($obj->{name} ne $securityLevelName) {
            $changes{name} = $securityLevelName;
        }
        if ($obj->{nameTag} ne $nameTag) {
            $changes{nameTag} = $nameTag;
        }
        if ($obj->{nameTagHelp} ne $nameTagHelp) {
            $changes{nameTagHelp} = $nameTagHelp;
        }
        if (!&matchCaps(\@capabilities, [($cce->scalar_to_array($obj->{capabilities}))])) {
            $changes{capabilities} = $cce->array_to_scalar(@capabilities);
        }
        
        if (scalar keys %changes) {
            # we need to update,  something has changed..
            $DEBUG && print STDERR "Updating CapabilityGroup '$securityLevelName'... ";
            my ($ok) = $cce->set($finds[0], "", \%changes);
            $DEBUG && print STDERR ($ok ? "done\n" : "failed\n");
        }
    }

    # that's the end
    return 1;
}

sub setSortOrder {
    my $self = shift;
    my $order = shift;
    my $cce = $self->{cce};
    $cce->set($self->{oid}, "", {sort => $order});
}

# This routine checks to see if the contents of two lists are the same.  
# The two lists can be in any arbitrary order.
# Takes two array references and returns a bool.
sub matchCaps {
    my ($l1,$l2) = @_; # the two lists given
    my $count = 0;  # the count of matched elements
    $DEBUG && print STDERR Dumper ($l1) . "\n"  . Dumper( $l2);
    for my $e (@$l1) {
        my $flag = 0;
        $DEBUG && print STDERR "\$e: $e\n";
        for (my $i = 0; $i < scalar(@$l2); $i++) {
            if ($DEBUG) {
                print STDERR "\t\$l2->[$i]: ";
                print STDERR (defined($l2->[$i]) ? $l2->[$i] : 'undefined'), "\n";
            }
            if (defined($l2->[$i]) && $e eq $l2->[$i]) {
                # We found a non-matching element,  stop here.
                undef ($l2->[$i]);
                $count++;
                $flag = 1;
                last;
            }
        }
        $flag || return 0;
    }
    # Check to see if all the elements matched, if so, return true.
    ($count == scalar @$l2) && return 1;
}
1;

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