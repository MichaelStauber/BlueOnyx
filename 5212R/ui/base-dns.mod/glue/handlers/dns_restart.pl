#!/usr/bin/perl
# $Id: dns_restart.pl
#
# Enable/disable and start/stop BIND to match System.DNS.enabled.
# Under systemd the unit is named-chroot; legacy init uses named.
#
# Do not use service_toggle_init here: it double-handles enable/disable and
# only stops on disable (no start on enable). Drive the unit explicitly.
# Do not trust pid files named after the unit (named-chroot.pid vs named.pid).

my $DEBUG = 0;

my $SERVICE = "named";    # init script / pid name (pre-systemd)
my $CMDLINE = "named";    # /proc/nnn/cmdline match (legacy path only)

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
        openlog($0, '', 'user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# CCE "enabled" is usually 0/1; treat only real on-values as enabled.
sub dns_is_enabled {
    my ($v) = @_;
    return 0 if !defined($v) || $v eq '' || $v eq '0' || $v eq 'off' || $v eq 'false';
    return 1 if $v eq '1' || $v eq 'on' || $v eq 'true' || $v == 1;
    return $v ? 1 : 0;
}

sub unit_is_active {
    my ($svc) = @_;
    if (-f "/usr/bin/systemctl") {
        my $st = `/usr/bin/systemctl is-active $svc.service 2>/dev/null`;
        chomp($st);
        return ($st eq 'active') ? 1 : 0;
    }
    # Legacy: named.pid under chroot
    my $fh = new FileHandle("</var/named/chroot/var/run/named/named.pid");
    return 0 unless $fh;
    my $pid = <$fh>;
    chomp($pid);
    $fh->close();
    return 0 unless $pid && $pid =~ /^\d+$/;
    $fh = new FileHandle("</proc/$pid/cmdline");
    return 0 unless $fh;
    my $cmdline = <$fh>;
    chomp($cmdline);
    $fh->close();
    return ($cmdline =~ m/$CMDLINE/) ? 1 : 0;
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

if (-f "/usr/bin/systemctl") {
    $SERVICE = "named-chroot";
    &debug_msg("Systemd detected, using $SERVICE\n");
}

my $enabled = dns_is_enabled($obj->{enabled});
my $options = 'nobg';    # finish start/stop before UI returns

if ($enabled) {
    Sauce::Service::service_set_init($SERVICE, 'on', '345');
    &debug_msg("Service $SERVICE set to on\n");

    # CONFIGURE (dns_generate) runs before this CLEANUP handler; conf should
    # exist when DNS is on. Still start/restart even if missing so AM can RED.
    if (unit_is_active($SERVICE)) {
        service_run_init($SERVICE, 'restart', $options);
        &debug_msg("Restarted active $SERVICE\n");
    }
    else {
        # inactive/failed: restart often starts; prefer explicit start then
        # restart if still needed after generate-only reload cases.
        service_run_init($SERVICE, 'start', $options);
        &debug_msg("Started $SERVICE\n");
        if (!unit_is_active($SERVICE)) {
            service_run_init($SERVICE, 'restart', $options);
            &debug_msg("Retry restart $SERVICE\n");
        }
    }
    # Clear stale failed state if we recovered
    if (-f "/usr/bin/systemctl") {
        system("/usr/bin/systemctl reset-failed $SERVICE.service >/dev/null 2>&1");
    }
}
else {
    Sauce::Service::service_set_init($SERVICE, 'off', '345');
    &debug_msg("Service $SERVICE set to off\n");
    service_run_init($SERVICE, 'stop', $options);
    &debug_msg("Stopped $SERVICE\n");
    if (-f "/usr/bin/systemctl") {
        system("/usr/bin/systemctl reset-failed $SERVICE.service >/dev/null 2>&1");
    }
}

my $running = unit_is_active($SERVICE);
&debug_msg("Running? $running, Enabled in CCE? " . $obj->{enabled} . "\n");

# Soft signal only: hard FAIL races the UI; AM reports sustained failure.
if ($enabled && !$running) {
    &debug_msg("Service $SERVICE not active after enable path\n");
    $cce->warn("[[base-dns.named-did-not-start]]");
}

&debug_msg("Operation completed successfully\n");
$cce->bye("SUCCESS");
exit 0;

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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
