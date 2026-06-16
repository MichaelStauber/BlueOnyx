#!/usr/bin/perl -I/usr/sausalito/perl

# System name of the service:
$service = 'sshd';

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use AM::Util;
use Sauce::Service;
use CCE;

my $cce = new CCE;
$cce->connectuds();

my %am_states = am_get_statecodes();

# Get the System Object:
@sysoids = $cce->find('System');
($ok, $SSHsettings) = $cce->get($sysoids[0], 'SSH');
if (!$ok) {
    &debug_msg("Service $service: Unspecified fail. \n");
    $cce->bye('FAIL');
    exit $am_states{AM_STATE_NOINFO};
}

# Check if the service is enabled:
if ($SSHsettings->{'enabled'} eq "1") {
    # Service is enabled:
    &debug_msg("Service $service is enabled in the GUI. Checking status. \n");

    # Check if the System service is enabled:
    $enabled = Sauce::Service::service_get_init($service);
    &debug_msg("Service $service is enabled on the System level. \n");

    if ($enabled eq "0") {
        Sauce::Service::service_set_init($service, 'on');
        Sauce::Service::service_run_init($service, 'restart');
        &debug_msg("Service $service was not enabled. But it is enabled now and has been started.\n");
    }

    # Check Status:
    if (-f '/usr/bin/systemctl') {
        $status = `systemctl status $service.service|grep "Active: active (running)"|wc -l`;
    }
    else {
        $status = `netstat -tupan|grep LISTEN|grep sshd|wc -l`;
    }
    chomp($status);
    if ($status gt "0") {
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
        if (-f '/usr/bin/systemctl') {
            $status = `systemctl status $service.service|grep "Active: active (running)"|wc -l`;
        }
        else {
            $status = `netstat -tupan|grep LISTEN|grep sshd|wc -l`;
        }
        chomp($status);
        if ($status eq "0") {
            &debug_msg("Service $service has failed. \n");
            print $ENV{redMsg};
            $cce->bye('SUCCESS');
            exit $am_states{AM_STATE_RED};
        }
    }
}
else {
    #
    # Service is disabled in the GUI:
    # => Make sure it is also disabled and stopped on the system.
    #
    &debug_msg("Service $service is disabled in the GUI. Ensuring it is stopped and disabled on the system level.\n");

    # 1) Disable service at boot (if currently enabled)
    my $enabled = Sauce::Service::service_get_init($service);
    if ($enabled ne "0") {
        &debug_msg("Service $service is enabled at boot. Disabling it.\n");
        Sauce::Service::service_set_init($service, 'off');
    }

    # 2) Check if the service is currently running
    my $running = 0;

    if (-x '/usr/bin/systemctl') {
        # systemd-based check
        my $rc = system("systemctl -q is-active $service.service >/dev/null 2>&1");
        # system() returns 0 if command succeeded (i.e. service is active)
        $running = ($rc == 0) ? 1 : 0;
    }
    else {
        # Legacy fallback
        my $status = `netstat -tupan 2>/dev/null | grep LISTEN | grep $service | wc -l`;
        chomp($status);
        $running = ($status =~ /^\d+$/ && $status > 0) ? 1 : 0;
    }

    # 3) Stop service if it is still running
    if ($running) {
        &debug_msg("Service $service is currently running. Stopping it.\n");
        Sauce::Service::service_run_init($service, 'stop');
    }
    else {
        &debug_msg("Service $service is already stopped.\n");
    }

    # From the AM perspective: service is intentionally disabled
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_NOINFO};
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
# Copyright (c) 2016-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2016-2025 Team BlueOnyx, BLUEONYX.IT
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