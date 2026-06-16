#!/usr/bin/perl

package SMART;

use strict;
use lib qw(/usr/sausalito/perl);
use vars qw(@ISA @EXPORT);

require Exporter;
@ISA = qw(Exporter);
@EXPORT = qw(get_smart_info smart_to_array array_to_smart);


# Runs ide-smart with and returns 2 has references.
# The first hash reference contains the Id and Value data and has the following structure:
# hash->{drive}->{id} = value
#             |->{id} = value
#
# The second hash reference contains the failure state of the Ids and has the following
# structure:
#
# hash->{drive}->{id} = boolean FAILED
#             |->{id} = boolean
# The boolean equals 1 if the id has failed

1;

sub get_smart_info {
    my @drives = @_;
    my $data = undef;
    my $failures = undef;
    my ($id, $val);
    
    foreach my $drive (@drives) {
        open(SMART, "/usr/local/sbin/ide-smart $drive 2>&1 |") or die "can't run ide-smart from get_smart_info";
        while (<SMART>) {
            if (/Id=\s*(\d+).*Value=\s*(\d+)/) {
                $id = $1;
                $val = $2;
                $data->{$drive}->{$id} = $val;
                if (/Failed/) {
                    $failures->{$drive}->{$id} = 1;
                }
            }
        }
        close(SMART);
    }

    if (wantarray) {
        return ($failures, $data);
    }
    else {
        return $data;
    }
}


# takes hash reference of smart info and returns an array of elements 
# of form drive/id/value
sub smart_to_array {
    my $hash = shift;
    my @drives = keys(%$hash);
    my @result = ();
    foreach my $drive (@drives) {
        foreach my $id (keys %{$hash->{$drive}}) {
            push @result, "$drive/$id/" . $hash->{$drive}->{$id};
        }
    }
    return @result;
}

# takes array of elements of form drive/id/value and returns hash ref
# hash->{drive}->{id}->{value}
sub array_to_smart {
    my $hash = undef;
    my @fields;
    foreach my $entry (@_) {
        @fields = split('/', $entry);
        $hash->{$fields[0]}->{$fields[1]} = $fields[2];
    }
    return $hash;
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