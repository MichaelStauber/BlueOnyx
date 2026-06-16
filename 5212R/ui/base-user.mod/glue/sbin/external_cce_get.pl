#!/usr/bin/perl
# external_cce_get.pl

my $login = (getpwuid $>);
die "must run as root" if $login ne 'root';

use lib qw(/usr/sausalito/perl);
use utf8;
use CCE;
use Getopt::Long;
use JSON;
use Data::Dumper;

GetOptions( 'oid=s' => \$OID, 
            'help' => \$help, 
            );

if ($OID) {
    $oids = decode_json($OID);
    @OID_LIST = @{ $oids };
}
else {
    print "Usage: external_cce_get.pl [ --oid ] [ --help ]\n";
    print " --oid\       Get OIDs from CODB\n";
    print " -h|--help    This help text\n\n";
    exit;
}

%OBJECT_DATA = ();

my $cce = new CCE;
$cce->connectuds();
foreach my $x (@OID_LIST) {
    ($cce_ok, $codb_data) = $cce->get($x);
    $OBJECT_DATA{$x}{'OBJECT'} = $codb_data;

    # Get List of all NameSpaces:
    ($ok, @SystemNameSpaces) = $cce->names($x);

    # Get all NameSpaces:
    foreach my $NS (@SystemNameSpaces) {
        ($ok, $SNS) = $cce->get($x, $NS);
        $OBJECT_DATA{$x}{$NS} = $SNS;
    }
}
$cce->bye('SUCCESS');

$coder = JSON::XS->new->utf8->allow_nonref;
$out = $coder->encode (\%OBJECT_DATA);

print $out . "\n";
exit(0);

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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