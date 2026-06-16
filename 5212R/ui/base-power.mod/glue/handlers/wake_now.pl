#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/power
# $Id: wake_now.pl

use CCE;
use Sauce::Util;
use I18n;

my $ok;

my $wakeutil = '/usr/sbin/etherwake';
my $cce = new CCE('Namespace' => 'Power');
$cce->connectfd();

my $old = $cce->event_old();
my $object = $cce->event_object();
my $new = $cce->event_new();
my $fail = 0;

my @addresses;

open LOG, ">/tmp/wake_macs.log";

if ($object->{wake_macaddresses} && $object->{wake_now}) {
    @addresses = $cce->scalar_to_array($object->{wake_macaddresses});
    foreach my $address (@addresses) {
    $ok = system(($wakeutil, $address));
    if ($ok != 0) {
        $cce->warn("[[base-power.couldNotWakeMachine,address=\"$address\"]]");
        $fail = 1;
    }
    }
    # if something on error list
    # return failure
    # and mention which addresses failed
    if ($fail) {
    $cce->bye('FAIL');
    exit 1;
    }
}
close LOG;

$cce->bye('SUCCESS');
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