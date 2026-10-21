#!/usr/bin/perl -I/usr/sausalito/perl

# System name of the service:
$service = 'goaccess';

# Debugging switch:
$DEBUG = "0";
if ($DEBUG)
{
        use Sys::Syslog qw( :DEFAULT setlogsock);
}

use AM::Util;
use Sauce::Service;
use CCE;

my $cce = new CCE;
$cce->connectuds();

my %am_states = am_get_statecodes();

# goaccess has nothing to parse until httpd has created its access log, and httpd creates that file
# lazily on its first request rather than at start. goaccess.service carries the same test as a
# ConditionPathExists=, so while the log is absent the unit is deliberately skipped instead of
# failing. Report that as "not applicable" and leave the service alone: this script must not enable
# or restart a unit that is skipped on purpose, and on a later swatch pass - once the log exists -
# the check below finds the unit inactive and starts it. Deliberately no message is printed: the
# green message states that goaccess is running, which would not be true here.
my $access_log = '/var/log/httpd/access_log';
if (!-e $access_log) {
    &debug_msg("$access_log does not exist yet - nothing for $service to parse. Reporting NOINFO.\n");
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_NOINFO};
}

# Check if the service is enabled:
$enabled = Sauce::Service::service_get_init($service);
&debug_msg("Service $service is enabled. \n");

if ($enabled eq "0") {
    Sauce::Service::service_set_init($service, 'on');
    Sauce::Service::service_run_init($service, 'restart');
    &debug_msg("Service $service was not enabled. But it is enabled now and has been started.\n");
}

# Coming back from "not applicable": the log exists now, so there is work to do, but the unit was
# skipped at boot and nothing has started it. Swatch exports the object's current state into this
# script's environment, so the transition out of NOINFO is visible here. Start it directly instead
# of queueing a restart: the queue holds restarts for QUEUE_WAIT_TIME (10s) while the check below
# rechecks after 3s, so a queued start would always be reported RED first - an N -> R -> G flicker
# for a condition that deserves no alarm at all. Starting an already-running unit is a no-op.
if ((defined($ENV{currentState})) && ($ENV{currentState} eq "N")) {
    $status = `systemctl status $service.service|grep "Active: active (running)"|wc -l`;
    chomp($status);
    if ($status eq "0") {
        &debug_msg("Previous state was NOINFO and the access log exists now - starting $service directly.\n");
        system("/usr/bin/systemctl start $service.service");
        $status = `systemctl status $service.service|grep "Active: active (running)"|wc -l`;
        chomp($status);
    }
    if ($status eq "1") {
        print $ENV{greenMsg};
        $cce->bye('SUCCESS');
        exit $am_states{AM_STATE_GREEN};
    }
    &debug_msg("Direct start did not bring $service up - falling through to the normal check.\n");
}

# Check Status:
$status = `systemctl status $service.service|grep "Active: active (running)"|wc -l`;
chomp($status);
if ($status eq "1") {
    # Service is working:
    &debug_msg("Service $service is working. \n");
    print $ENV{greenMsg};
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_GREEN};
}
else {
    # Service failed. Attempting a restart:
    &debug_msg("Service $service has failed and we are restarting it. \n");
    Sauce::Service::service_run_init($service, 'restart');
    sleep 3;
    # Retest if service is working now:
    $status = `systemctl status $service.service|grep "Active: active (running)"|wc -l`;
    chomp($status);
    if ($status eq "0") {
        &debug_msg("Service $service has failed. \n");
        print $ENV{redMsg};
        $cce->bye('SUCCESS');
        exit $am_states{AM_STATE_RED};
    }
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

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