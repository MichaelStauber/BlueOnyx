#!/usr/bin/perl
# $Id: dns_restart.pl
#
# starts, stops, and restarts a service on demand, with some extra
# safety checks.

my $DEBUG = 0;

# configure here: (mostly)
my $SERVICE = "named";  # name of initd script for this daemon
my $CMDLINE = "named";  # contents of /proc/nnn/cmdline for this daemon
my $RESTART = "reload"; # restart action

# Debugging switch and initialization
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
    &debug_msg("Debugging enabled for dns_restart.pl\n");
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

use lib qw( /usr/sausalito/perl );
use FileHandle;
use Sauce::Util;
use Sauce::Service;
use CCE;
$cce = new CCE;
$cce->connectfd();

my ($sysoid) = $cce->find("System");
my ($ok, $obj) = $cce->get($sysoid, "DNS");

# Check if we have Systemd:
if (-f "/usr/bin/systemctl") {
    # Got Systemd:
    $SERVICE = "named-chroot";
    &debug_msg("Systemd detected, using $SERVICE\n");
}

# fix chkconfig information:
if ($obj->{enabled}) {
    Sauce::Service::service_set_init($SERVICE, 'on', '345');
    &debug_msg("Service $SERVICE set to 'on' for runlevels 345\n");
} else {
    Sauce::Service::service_set_init($SERVICE, 'off', '345');
    &debug_msg("Service $SERVICE set to 'off' for runlevels 345\n");
}

# check to see if the service is presently running;
my $running = 0;
{
    my $fh = new FileHandle("</var/named/chroot/var/run/named/$SERVICE.pid");
    if ($fh) {
        my $pid = <$fh>; chomp($pid);
        &debug_msg("Old $SERVICE pid = $pid\n");
        $fh->close();
        
        $fh = new FileHandle("</proc/$pid/cmdline");
        if ($fh) {
            my $cmdline = <$fh>; chomp($cmdline);
            &debug_msg("Old $SERVICE cmdline = $cmdline\n");
            $fh->close();
            
            if ($cmdline =~ m/$CMDLINE/) { 
                $running = 1;
                &debug_msg("Service $SERVICE is running\n");
            }
        }
    }
}

&debug_msg("Running? $running, Enabled in CCE? " . $obj->{enabled} . "\n");

# do the right thing
service_toggle_init($SERVICE, $obj->{enabled});
&debug_msg("Toggled service $SERVICE to " . ($obj->{enabled} ? "enabled" : "disabled") . "\n");

my $options = {};
if ($obj->{enabled}) {
    service_run_init($SERVICE, 'restart', $options);
    &debug_msg("Restarted service $SERVICE\n");
}
else {
    service_run_init($SERVICE, 'stop', $options);
    &debug_msg("Stopped service $SERVICE\n");
}

# is it running now?
$running = 0;
{
    $SERVICE = "named";
    my $fh = new FileHandle("</var/named/chroot/var/run/named/$SERVICE.pid");
    if ($fh) {
        my $pid = <$fh>; chomp($pid);
        &debug_msg("New $SERVICE pid = $pid\n");
        $fh->close();
        
        $fh = new FileHandle("</proc/$pid/cmdline");
        if ($fh) {
            my $cmdline = <$fh>; chomp($cmdline);
            &debug_msg("New $SERVICE cmdline = $cmdline\n");
            $fh->close();
            
            if ($cmdline =~ m/$CMDLINE/) { 
                $running = 1;
                &debug_msg("Service $SERVICE is now running\n");
            }
        }
    }
}

&debug_msg("Running? $running, Enabled in CCE? " . $obj->{enabled} . "\n");

# proc test has delays that incur a race failure unless we wait at the
# direct expense of the UI.  If there is a failure, AM will catch 
# correct or report it accordingly
# 
# report the did-not-start error, if necessary:
# if ($obj->{enabled} && !$running) {
#     &debug_msg("Service $SERVICE failed to start\n");
#     $cce->warn("[[base-dns.${SERVICE}-did-not-start]]");
#     $cce->bye("FAIL");
#     exit 1;
# }

&debug_msg("Operation completed successfully\n");
$cce->bye("SUCCESS");
exit 0;

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#   notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#   notice, this list of conditions and the following disclaimer in 
#   the documentation and/or other materials provided with the 
#   distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#   contributors may be used to endorse or promote products derived 
#   from this software without specific prior written permission.
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