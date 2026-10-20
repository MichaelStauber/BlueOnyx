#!/usr/bin/perl -I/usr/sausalito/perl

use AM::Util;
use Sauce::Service;
use CCE;

my $cce = new CCE;
$cce->connectuds();

my ($oid) = $cce->find('System');
my ($AMoid) = $cce->find('ActiveMonitor');
my ($ok, $System) = $cce->get($oid);
my ($ok, $Jailkit) = $cce->get($oid, 'Jailkit');
my ($ok, $JailkitAM) = $cce->get($AMoid, 'Jailkit');

my %am_states = am_get_statecodes();

$jailkit_enabled_status = Sauce::Service::service_get_init("jailkit");

if ($Jailkit->{enabled} eq "1") {
    # Make sure jailkit is enabled:
    if ($jailkit_enabled_status == "0") {        
        Sauce::Service::service_set_init('jailkit', 'on');
    }
}
else {
    Sauce::Service::service_set_init('jailkit', 'off');
    ($ok) = $cce->update($AMoid, 'Jailkit',{
        'enabled' => '0',
        'monitor' => '0'
    });
}

$jailkit_daemon_status = `ps axf|grep /usr/sbin/jk_socketd|grep -v grep|wc -l`;
chomp($jailkit_daemon_status);

# An empty /etc/jailkit/jk_socketd.ini means "nothing to broker" - the same state systemd
# skips this unit on, via ConditionFileNotEmpty in the drop-in shipped by this package.
# Report it the same way here: no restart attempt, and no failure. "-s" is deliberately the
# identical test systemd applies, so the unit and the monitor cannot disagree about what
# "empty" means. A config with content is a genuine expectation that the daemon is running,
# and every check below keeps its full teeth: restart attempt, then RED if it stays down.
#
# The state is AM_STATE_NOINFO, not GREEN: a service that is deliberately not in play is
# "not applicable", which is what the other monitors report in the same situation
# (am_OpenDKIM and am_phpfpm* exit NOINFO when their service is not enabled). greenMsg is
# printed as well so the row is not left with an empty message, as am_OpenDKIM does.
my $jk_cfg = "/etc/jailkit/jk_socketd.ini";
if (!-s $jk_cfg) {
    print $ENV{greenMsg};
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_NOINFO};
}

if ($jailkit_daemon_status eq "0") {
    # Service not running:

    # Perform action:
    if (-f "/usr/bin/systemctl") { 
        # Got Systemd: 
        # Please note: For jailkit we do not use systemctl with the --no-block option to
        # enqueue the call. We issue it directly and wait for the result.
        `/usr/bin/systemctl --job-mode=flush restart jailkit.service`; 
    } 
    else { 
        # Thank God, no Systemd: 
        `/sbin/service jailkit restart`;
    }
    # Now check again, on the same evidence we used to decide it was down. The previous
    # version used service_get_init(), which is `systemctl is-enabled` - that reports
    # whether the unit starts at boot, not whether the daemon is running, so the verdict
    # was inverted: RED for a running daemon on a disabled unit, GREEN for a dead daemon
    # on an enabled one. Ask the process, the same way the top of this script asks.
    $jailkit_daemon_status = `ps axf|grep /usr/sbin/jk_socketd|grep -v grep|wc -l`;
    chomp($jailkit_daemon_status);
    if ($jailkit_daemon_status == "0") {        
        print $ENV{redMsg};
        $cce->bye('SUCCESS'); 
        exit $am_states{AM_STATE_RED};
    }
    else {
        print $ENV{greenMsg};
        $cce->bye('SUCCESS');
        exit $am_states{AM_STATE_GREEN};
    }
}
else {
    print $ENV{greenMsg};
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_GREEN};
}

$cce->bye('SUCCESS');
exit $am_states{AM_STATE_NOINFO};

# 
# Copyright (c) 2019-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2019-2022 Team BlueOnyx, BLUEONYX.IT
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