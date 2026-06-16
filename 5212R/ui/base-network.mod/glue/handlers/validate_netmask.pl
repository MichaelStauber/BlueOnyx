#!/usr/bin/perl -I /usr/sausalito/perl
#
# $Id: validate_netmask.pl
#
# make sure a netmask is valid (ie leading bits are all 1's and once a 0
# is found all the trailing bits are 0's)
#
# handles both Network.netmask and Route.netmask
#

use CCE;

my $cce = new CCE('Domain' => 'base-network');
$cce->connectfd();

my $network = $cce->event_object();

# if netmask is blank, we have nothing to say
if ($network->{netmask} eq '') {
	$cce->bye('SUCCESS');
	exit(0);
}

# setup the error message different for real and alias interfaces
my @pre_error = ();
if (($network->{CLASS} eq 'Network') && $network->{real}) {
	@pre_error = ('invalidNetmaskReal',
		{
			'device' => '[[base-network.interface' .
					$network->{device} . ']]',
			'netmask' => $network->{netmask}
		});
} else {
	# aliases get a generic error message
	@pre_error = ('invalidNetmaskAlias',
		{ 'netmask' => $network->{netmask} });
}

		
my @netmask = split(/\./, $network->{netmask});

# must be at least a subnet of a class A network
if ($netmask[0] != 255) {
	$cce->bye('FAIL', @pre_error);
	exit(1);
}

my $in_zero_part = 0;
for (my $i = 1; $i < scalar(@netmask); $i++) {
	if ($in_zero_part && ($netmask[$i] != 0)) {
		$cce->bye('FAIL', @pre_error);
		exit(1);
	} elsif ($netmask[$i] != 255) {
		if (&is_octet_valid($netmask[$i])) {
			$in_zero_part = 1;
		} else {
			$cce->bye('FAIL', @pre_error);
			exit(1);
		}
	}
}

$cce->bye('SUCCESS');
exit(0);

sub is_octet_valid
{                                                                               
        my $octet = shift;

        # check for edge case
	if ($octet == 0) {
		return 1;
	}

	my $sum = 0;                                                            
        for (my $j = 7; $j >= 0; $j--) {                                        
                $sum += 2 ** $j;                                                 
                if ($octet == $sum) {                                           
                        return 1;                                               
                }                                                               
        }                                                                       
        return 0;                                                               
}

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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
# You acknowledge that this software is not designed or intended for 
# use in the design, construction, operation or maintenance of any 
# nuclear facility.
# 