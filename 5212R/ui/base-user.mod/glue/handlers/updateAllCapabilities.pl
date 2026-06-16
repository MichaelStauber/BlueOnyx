#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: updateAllCapabilities.pl

use CCE;
use Base::User::Capabilities;

my $cce = new CCE();

$cce->connectfd();

# create the Capability object that takes care of expansions.
my $Capability = new Base::User::Capabilities($cce);

# This may be the unwanted behaviour, but for now, if one user fails, then 
# they all fail..    oh well.. (notice that this will need to be changed for
# products that use vsites where one user may be able to admin some user's caps,
# but not all of them..
my $ok = 1;

# loop and mod re-expand each user..
my @Useroids = $cce->find("User");
for my $useroid (@Useroids) {
    my $obj;
    ($ok, $obj) = $cce->get($useroid); 
    last if (!$ok);

    # get an array of capabilityGroups that are being set..
    my %capLevels; @capLevels{$cce->scalar_to_array($obj->{capLevels}),$cce->scalar_to_array($obj->{uiRights})} = ();

    # expand the capabilityGroups into cce-level capabilities 
    my $caps = $Capability->expandCaps(\%capLevels); 
       
    my $capsScalar = $cce->array_to_scalar(keys %$caps);
    ($ok) = $cce->set($useroid, "", { capabilities=>$capsScalar });
    last if (!$ok);
}

if (!$ok) {
    $cce->bye("FAIL");
}
else {
    $cce->bye("SUCCESS");
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