#!/usr/bin/perl
# $Id: pkg_update_all.pl
#
# Install all NewLinQ PKG updates for packages already installed on this
# system, one after another.
#
# Matching rule: Available Package with the same "name" as an Installed
# Package and a newer version (SWUpdate::compareVersion / Sort::Versions).
# Only the newest Available NVR per name is installed.
#
# Each install is always invoked as: pkg_install.pl <oid> -R
# so a single package cannot reboot mid-batch. After the full run:
#   - without -R on THIS script: if any candidate had options~/\breboot\b/,
#     reboot once via System.Power
#   - with -R: never reboot; print a clear advisory if reboot was wanted
#
# Usage:
#   pkg_update_all.pl [-n] [-r] [-R] [-v]
#     -n   dry-run (list only)
#     -r   refresh catalogue first (grab_updates.pl)
#     -R   never reboot; only advise if a package wanted reboot
#     -v   verbose
#

use strict;
use warnings;
use lib '/usr/sausalito/perl';
use CCE;
use SWUpdate;
use Sort::Versions;
use Getopt::Std;
use POSIX qw(strftime);

# Getopt::Std intercepts --help/--version before getopts and prints its own
# paranoid blurb unless HELP_MESSAGE / VERSION_MESSAGE are defined.
$Getopt::Std::STANDARD_HELP_VERSION = 1;

sub _print_help {
    print <<'EOF';
Usage: pkg_update_all.pl [-n] [-r] [-R] [-v] [-h]
       pkg_update_all.pl --help

  Install newer Available NewLinQ packages for names already Installed
  (same Package.name, newer version). Only the newest Available NVR per
  name is installed. Packages never installed before are skipped.

  -n   Dry-run: list candidates only, do not install
  -r   Run /usr/sausalito/sbin/grab_updates.pl first (refresh catalogue)
  -R   Never reboot. If a package wanted reboot, print advice only.
       Without -R: after all installs succeed, reboot once if any
       package in the plan had options containing "reboot".
  -v   Verbose
  -h   This help
  --help
       Same as -h (handled before Getopt::Std default blurb)

  Each package is installed with:
      /usr/sausalito/sbin/pkg_install.pl <oid> -R
  so a single package cannot reboot mid-batch. System.SWUpdate.installLock
  is respected (waits for a live lock PID; clears stale locks).

Examples:
  pkg_update_all.pl -n -v       # show what would be updated
  pkg_update_all.pl -r -R       # refresh, install all updates, never reboot
  pkg_update_all.pl             # install; reboot at end only if a pkg wanted it
EOF
}

sub HELP_MESSAGE {
    _print_help();
    exit 0;
}

sub VERSION_MESSAGE {
    # Suppress Getopt::Std version noise for --version; we have no separate version string.
}

# Also catch long options before getopts (some callers pass only --help).
if (grep { $_ eq '--help' || $_ eq '-help' } @ARGV) {
    _print_help();
    exit 0;
}

use vars qw($opt_n $opt_r $opt_R $opt_v $opt_h);
getopts('nrRvh');

if ($opt_h) {
    _print_help();
    exit 0;
}

my $PKG_INSTALL = '/usr/sausalito/sbin/pkg_install.pl';
my $GRAB       = '/usr/sausalito/sbin/grab_updates.pl';
my $LOCK_WAIT_SEC = 600;
my $LOCK_POLL     = 5;

sub vlog {
    my ($msg) = @_;
    print $msg, "\n" if $opt_v || $opt_n;
}

sub info {
    my ($msg) = @_;
    print $msg, "\n";
}

sub compare_pkg_version {
    my ($a, $b) = @_;
    # Prefer SWUpdate helper when present (same as dependency checks).
    if (defined &SWUpdate::compareVersion) {
        return SWUpdate::compareVersion($a, $b);
    }
    my $fa = swupdate_fromccevers($a);
    my $fb = swupdate_fromccevers($b);
    return versioncmp($fa, $fb);
}

my $cce = new CCE;
$cce->connectuds();

my ($sysOID) = $cce->find('System');
die "No System object in CCE\n" unless $sysOID;

# Clear leftover UI command noise from a prior single install.
$cce->set($sysOID, 'SWUpdate', {
    uiCMD    => '',
    message  => '[[base-swupdate.initializing]]',
    progress => '0',
});

if ($opt_r) {
    info("Refreshing package catalogue (grab_updates.pl)...");
    my $rc = system($GRAB);
    if ($rc != 0) {
        warn "grab_updates.pl exited " . ($rc >> 8) . " (continuing with current CODB)\n";
    }
}

# Installed: name => { version, vendor, oid } (first wins if duplicates)
my %installed;
for my $oid ($cce->find('Package', { installState => 'Installed' })) {
    my ($ok, $p) = $cce->get($oid);
    next unless $ok && $p && defined $p->{name} && $p->{name} ne '';
    next if exists $installed{ $p->{name} };
    $installed{ $p->{name} } = {
        oid     => $oid,
        version => $p->{version} // '',
        vendor  => $p->{vendor}  // '',
    };
}

# Available: pick newest per name that is an update of something installed
my %best_avail;    # name => package hash + oid
for my $oid ($cce->find('Package', { installState => 'Available' })) {
    my ($ok, $p) = $cce->get($oid);
    next unless $ok && $p && defined $p->{name} && $p->{name} ne '';
    next unless exists $installed{ $p->{name} };
    next unless defined $p->{location} && $p->{location} ne '';

    my $iname = $p->{name};
    my $cmp = compare_pkg_version($p->{version}, $installed{$iname}{version});
    next unless defined $cmp && $cmp > 0;    # available must be newer

    if (!exists $best_avail{$iname}
        || compare_pkg_version($p->{version}, $best_avail{$iname}{version}) > 0)
    {
        $best_avail{$iname} = {
            oid      => $oid,
            name     => $p->{name},
            vendor   => $p->{vendor}  // '',
            version  => $p->{version} // '',
            options  => $p->{options} // '',
            location => $p->{location},
            wants_reboot => ($p->{options} // '') =~ /\breboot\b/ ? 1 : 0,
        };
    }
}

my @plan = sort { $a->{name} cmp $b->{name} } values %best_avail;

if (!@plan) {
    info("No updates available for installed packages.");
    $cce->bye('SUCCESS');
    exit 0;
}

info("Update plan (" . scalar(@plan) . " package(s)):");
my $any_wants_reboot = 0;
for my $c (@plan) {
    my $old = $installed{ $c->{name} }{version};
    my $rb  = $c->{wants_reboot} ? ' [wants reboot]' : '';
    info(sprintf("  %s/%s  %s -> %s  (oid %s)%s",
        $c->{vendor}, $c->{name}, $old, $c->{version}, $c->{oid}, $rb));
    $any_wants_reboot = 1 if $c->{wants_reboot};
}

if ($opt_n) {
    info("Dry-run only; nothing installed.");
    $cce->bye('SUCCESS');
    exit 0;
}

sub wait_for_lock {
    my ($cce, $sysOID) = @_;
    my $deadline = time() + $LOCK_WAIT_SEC;
    while (1) {
        my ($ok, $sw) = $cce->get($sysOID, 'SWUpdate');
        my $lock = ($ok && $sw) ? ($sw->{installLock} // 0) : 0;
        if (!$lock || $lock !~ /^\d+$/ || $lock == 0) {
            return 1;
        }
        # Live PID?
        my $alive = 0;
        if (open(my $ps, '-|', 'ps', '--no-headers', '-p', $lock)) {
            my $line = <$ps>;
            close($ps);
            $alive = 1 if defined $line && $line =~ /\S/;
        }
        unless ($alive) {
            vlog("Clearing stale installLock=$lock");
            $cce->set($sysOID, 'SWUpdate', { installLock => 0 });
            return 1;
        }
        if (time() >= $deadline) {
            warn "Timeout waiting for installLock (PID $lock)\n";
            return 0;
        }
        vlog("Waiting for installLock PID $lock ...");
        sleep $LOCK_POLL;
    }
}

my $failed = 0;
my $done   = 0;
for my $c (@plan) {
    info("--- Installing $c->{vendor}/$c->{name} $c->{version} (oid $c->{oid}) ---");
    unless (wait_for_lock($cce, $sysOID)) {
        warn "Skipping $c->{name}: install lock busy\n";
        $failed++;
        next;
    }

    # Always -R on the child so mid-batch reboot cannot fire.
    my $cmd = "$PKG_INSTALL $c->{oid} -R";
    vlog("Running: $cmd");
    my $rc = system($cmd);
    my $exit = $rc == -1 ? -1 : ($rc >> 8);
    if ($exit == 0) {
        info("OK: $c->{name} $c->{version}");
        $done++;
    }
    else {
        warn "FAILED: $c->{name} $c->{version} (exit $exit)\n";
        $failed++;
    }
}

info("Finished: $done installed, $failed failed, " . scalar(@plan) . " planned.");

# Reboot policy (this script's -R, not pkg_install's).
if ($any_wants_reboot) {
    if ($opt_R) {
        info("NOTE: One or more packages requested reboot, but -R was set.");
        info("      A reboot is advisable when convenient. No reboot performed.");
    }
    else {
        if ($failed) {
            info("NOTE: Packages requested reboot, but some installs failed.");
            info("      Not rebooting automatically. Reboot manually when ready.");
        }
        else {
            info("One or more packages requested reboot. Rebooting system now...");
            my $systime = time();
            $cce->set($sysOID, 'SWUpdate', {
                uiCMD    => 'reboot',
                message  => '[[base-swupdate.packageInstallSuccess]]',
                progress => '100',
            });
            sleep 2;
            $cce->set($sysOID, 'Power', { reboot => $systime });
            $cce->bye('SUCCESS');
            exit 0;
        }
    }
}

$cce->set($sysOID, 'SWUpdate', {
    progress => '100',
    message  => '[[base-swupdate.packageInstallSuccess]]',
    uiCMD    => '',
}) if $sysOID;

$cce->bye('SUCCESS');
exit($failed ? 1 : 0);

#
# Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2026 Team BlueOnyx, BLUEONYX.IT
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
