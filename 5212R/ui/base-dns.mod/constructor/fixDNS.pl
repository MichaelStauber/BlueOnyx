#!/usr/bin/perl -I/usr/sausalito/perl
#
# RHEL is normally running SE-linux, this is turned off by default by us.
# This makes it impossible to save AXFR zone-transfers if we don't add "ENABLE_ZONE_WRITE=yes"
# to /etc/sysconfig/named.
#
# Also scrub broken ExecStartPre lines on named-chroot.service and align the
# unit enable state with System.DNS.enabled. Do not start BIND here when there
# is no /etc/named.conf (stock first boot / DNS never enabled) - that is what
# left named-chroot failed and the system "degraded". Start only when DNS is
# enabled and config exists; stop when DNS is disabled.

use lib qw( /usr/sausalito/perl );
use CCE;
use Sauce::Util;
use Sauce::Service;
use FileHandle;

my $cce = new CCE;
$cce->connectuds();

my $CMDLINE = "named";

sub dns_is_enabled {
    my ($v) = @_;
    return 0 if !defined($v) || $v eq '' || $v eq '0' || $v eq 'off' || $v eq 'false';
    return 1 if $v eq '1' || $v eq 'on' || $v eq 'true' || $v == 1;
    return $v ? 1 : 0;
}

$md5_orig = `cat /etc/sysconfig/named /usr/lib/systemd/system/named-chroot.service 2>/dev/null | md5sum`;

my $sysconfig = "/etc/sysconfig/named";
$ret = Sauce::Util::editfile($sysconfig, *fix_sysconfig_named);
if (! $ret ) {
  $cce->bye('FAIL', 'cantEditFile', {'file' => $sysconfig});
  exit(0);
} 
system('rm -f /etc/sysconfig/named.backup.*');

my $unitfile = "/usr/lib/systemd/system/named-chroot.service";
if (-f $unitfile) {
  $ret = Sauce::Util::editfile($unitfile, *fix_unitfile_named);
  if (! $ret ) {
    $cce->bye('FAIL', 'cantEditFile', {'file' => $unitfile});
    exit(0);
  }
  system("rm -f /usr/lib/systemd/system/named-chroot.service.backup.*");
}

my ($sysoid) = $cce->find("System");
my ($ok, $obj) = $cce->get($sysoid, "DNS");
my $enabled = dns_is_enabled($obj->{enabled});

my $SERVICE;
if (-f "/usr/bin/systemctl") {
  system("/usr/bin/systemctl daemon-reload");
  $SERVICE = "named-chroot";
}
else {
  $SERVICE = "named";
}

# Align enablement with CCE only (no service_toggle_init).
if ($enabled) {
  Sauce::Service::service_set_init($SERVICE, 'on', '345');
} else {
  Sauce::Service::service_set_init($SERVICE, 'off', '345');
}

my $options = 'nobg';
my $has_conf = (-f "/etc/named.conf") ? 1 : 0;

if ($enabled && $has_conf) {
  # Config present: ensure unit is up after constructor unit-file fixes.
  my $active = 0;
  if (-f "/usr/bin/systemctl") {
    my $st = `/usr/bin/systemctl is-active $SERVICE.service 2>/dev/null`;
    chomp($st);
    $active = ($st eq 'active') ? 1 : 0;
  }
  if ($active) {
    service_run_init($SERVICE, 'restart', $options);
  }
  else {
    service_run_init($SERVICE, 'start', $options);
  }
  system("/usr/bin/systemctl reset-failed $SERVICE.service >/dev/null 2>&1")
    if (-f "/usr/bin/systemctl");
}
elsif (!$enabled) {
  service_run_init($SERVICE, 'stop', $options);
  system("/usr/bin/systemctl reset-failed $SERVICE.service >/dev/null 2>&1")
    if (-f "/usr/bin/systemctl");
}
# enabled but no conf: leave unit alone (NOINFO path for AM); dns_generate +
# dns_restart own the first real start.

$md5_new = `cat /etc/sysconfig/named /usr/lib/systemd/system/named-chroot.service 2>/dev/null | md5sum`;

$cce->bye('SUCCESS');
exit(0);

sub fix_sysconfig_named {
    my $in  = shift;
    my $out = shift;

    my $done = 0;
    my $ZONEWRITE = "ENABLE_ZONE_WRITE=yes";
    
    select $out;
    while (<$in>) {
      if (/^ENABLE_ZONE_WRITE/o ) {
        print "$ZONEWRITE\n";
        $done = 1;
      }
      else {
        print $_;
      }
    }

    if (!$done) {
      print "$ZONEWRITE\n";
    }
    return 1;
}

sub fix_unitfile_named {
    my $in  = shift;
    my $out = shift;

    my $done = 0;
    
    select $out;
    while (<$in>) {
      if (/^ExecStartPre=\/bin\/bash/o ) {
        $done = 1;
      }
      else {
        print $_;
      }
    }
    return 1;
}

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2010 Bluapp AB, Rickard Osser <rickard.osser@bluapp.com>
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
