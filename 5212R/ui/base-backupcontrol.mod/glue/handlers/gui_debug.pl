#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: gui_debug.pl
#
# Toggle CI_ENVIRONMENT in the Chorizo .env between production and development.

use CCE;
use Sauce::Util;

my $env = '/usr/sausalito/ui/chorizo/ci4/.env';

my $cce = new CCE;
$cce->connectfd();

my $oid = $cce->event_oid();
my ($ok, $obj) = $cce->get($oid, 'DesktopControl');
if (!$ok || !defined($obj)) {
    $obj = $cce->event_object() || {};
}
my $delta = $cce->event_new();
if (ref($delta) eq 'HASH') {
    foreach my $k (keys %{$delta}) {
        next unless (defined($delta->{$k}));
        $obj->{$k} = $delta->{$k};
    }
}

my $debug = 0;
if (defined($obj->{'gui_debug'}) && ($obj->{'gui_debug'} eq '1' || $obj->{'gui_debug'} eq 'true' || $obj->{'gui_debug'} eq 'On')) {
    $debug = 1;
}

if (! -f $env) {
    $cce->bye('FAIL', '[[base-backupcontrol.gui_debug_env_missing]]');
    exit(1);
}

if (!Sauce::Util::editfile($env, *edit_env, $debug)) {
    $cce->bye('FAIL', '[[base-backupcontrol.gui_debug_env_edit_fail]]');
    exit(1);
}

$cce->bye('SUCCESS');
exit(0);

sub edit_env {
    my ($fin, $fout, $debug) = @_;
    my $saw_dev = 0;
    my $saw_prod = 0;
    while (my $line = <$fin>) {
        if ($line =~ /^\s*#?\s*CI_ENVIRONMENT\s*=\s*development\b/) {
            print $fout ($debug ? "CI_ENVIRONMENT = development\n" : "#CI_ENVIRONMENT = development\n");
            $saw_dev = 1;
            next;
        }
        if ($line =~ /^\s*#?\s*CI_ENVIRONMENT\s*=\s*production\b/) {
            print $fout ($debug ? "#CI_ENVIRONMENT = production\n" : "CI_ENVIRONMENT = production\n");
            $saw_prod = 1;
            next;
        }
        print $fout $line;
    }
    if (!$saw_dev) {
        print $fout ($debug ? "CI_ENVIRONMENT = development\n" : "#CI_ENVIRONMENT = development\n");
    }
    if (!$saw_prod) {
        print $fout ($debug ? "#CI_ENVIRONMENT = production\n" : "CI_ENVIRONMENT = production\n");
    }
    return 1;
}

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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