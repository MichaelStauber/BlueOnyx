#!/usr/bin/perl -I/usr/sausalito/perl
#
# Verify and repair the files in Jailkit chroots after OS package updates.
# Manual invocation is verbose. Automated callers use --quiet --if-due.
#

use strict;
use warnings;
use CCE;
use Base::HomeDir qw(homedir_get_group_dir);
use Fcntl qw(:DEFAULT);
use File::Path qw(make_path);
use POSIX qw(strftime);

my $quiet = 0;
my $if_due = 0;
my $force = 0;
my $dry_run = 0;

for my $arg (@ARGV) {
    if ($arg eq '--help' || $arg eq '-h') {
        print <<'HELP';
Usage: jailkit_integrity.pl [options]

Checks and repairs active BlueOnyx Jailkit Vsite and user jails.

  --help, -h   Show this help
  --quiet      Suppress normal output
  --if-due     Run only if the last run is older than 24 hours
  --force      Ignore the daily execution guard
  --dry-run    Do not write files or repair jails
HELP
        exit 0;
    }
    $quiet = 1 if $arg eq '--quiet';
    $if_due = 1 if $arg eq '--if-due';
    $force = 1 if $arg eq '--force';
    $dry_run = 1 if $arg eq '--dry-run';
}

sub say_msg {
    return if $quiet;
    print @_;
}

sub fail_msg {
    print STDERR @_ unless $quiet;
}

my $state_dir = '/var/lib/sausalito/jailkit';
my $stamp = "$state_dir/last-integrity-check";
my $lockfile = "$state_dir/integrity.lock";
my $lock_fh;

if ($if_due && !$force && -e $stamp && (time() - (stat($stamp))[9]) < 86400) {
    exit 0;
}

if (!$dry_run) {
    eval { make_path($state_dir, { mode => 0755 }); };
    if ($@) {
        fail_msg("Unable to create $state_dir: $@\n");
        exit 1;
    }

    if (!sysopen($lock_fh, $lockfile, O_WRONLY | O_CREAT | O_EXCL, 0644)) {
        exit 0 if $!{EEXIST};
        fail_msg("Unable to acquire Jailkit integrity lock: $!\n");
        exit 1;
    }
}

END {
    close($lock_fh) if $lock_fh;
    unlink($lockfile) if $lock_fh && -e $lockfile;
}

sub run_command {
    my (@cmd) = @_;
    return 1 if $dry_run;

    my $pid = fork();
    return 0 unless defined $pid;
    if ($pid == 0) {
        if ($quiet) {
            open(STDOUT, '>', '/dev/null');
            open(STDERR, '>', '/dev/null');
        }
        exec @cmd;
        exit 127;
    }
    waitpid($pid, 0);
    return ($? == 0);
}

sub read_jk_policy {
    my ($file) = @_;
    my @lines;
    my %default;
    my %groups;
    my $section = '';

    if (open(my $fh, '<', $file)) {
        while (my $line = <$fh>) {
            push @lines, $line;
            if ($line =~ /^\s*\[([^\]]+)\]\s*$/) {
                $section = $1;
                next;
            }
            next unless $section eq 'DEFAULT';
            if ($line =~ /^\s*(executables|paths|allow_word_expansion)\s*=\s*(.*?)\s*$/) {
                $default{$1} = $2;
            }
        }
        close($fh);
    }

    for my $line (@lines) {
        if ($line =~ /^\s*\[group\s+([^\]]+)\]\s*$/) {
            $groups{$1} = 1;
        }
    }

    my @execs = split(/\s*,\s*/, $default{executables} || '');
    @execs = grep { $_ ne '' } @execs;
    if (!@execs) {
        @execs = (
            '/usr/libexec/openssh/sftp-server',
            '/usr/bin/scp',
            '/usr/bin/sftp',
            '/usr/bin/ssh',
            '/usr/bin/id',
            '/bin/ls',
            '/bin/pwd'
        );
    }

    my @paths = split(/\s*,\s*/, $default{paths} || '');
    @paths = grep { $_ ne '' } @paths;
    @paths = ('/bin', '/usr/bin', '/usr/libexec/openssh') unless @paths;

    return (\@lines, \%default, \%groups, \@execs, \@paths);
}

sub write_jk_policy {
    my ($file, $lines, $default, $groups, $sites) = @_;
    my $managed_start = '# BEGIN BLUEONYX MANAGED JAILKIT POLICY';
    my $managed_end = '# END BLUEONYX MANAGED JAILKIT POLICY';
    my @kept;
    my $managed = 0;

    for my $line (@$lines) {
        if ($line =~ /^\Q$managed_start\E/ ||
            $line =~ /^# --- BlueOnyx managed: BEGIN/) {
            $managed = 1;
            next;
        }
        if ($line =~ /^\Q$managed_end\E/ ||
            $line =~ /^# --- BlueOnyx managed: END/) {
            $managed = 0;
            next;
        }
        push @kept, $line unless $managed;
    }

    my $executables = $default->{executables} || join(', ', (
        '/usr/libexec/openssh/sftp-server',
        '/usr/bin/scp',
        '/usr/bin/sftp',
        '/usr/bin/ssh',
        '/usr/bin/id',
        '/bin/ls',
        '/bin/pwd'
    ));
    my $paths = $default->{paths} || '/bin, /usr/bin, /usr/libexec/openssh';
    my $allow = exists $default->{allow_word_expansion}
        ? $default->{allow_word_expansion} : '0';

    push @kept, "$managed_start\n";
    push @kept, "[DEFAULT]\n";
    push @kept, "loglevel = 0\n";
    push @kept, "allow_word_expansion = $allow\n";
    push @kept, "umask = 022\n";
    push @kept, "paths = $paths\n";
    push @kept, "executables = $executables\n";
    for my $site (@$sites) {
        push @kept, "[group $site]\n";
        push @kept, "include = DEFAULT\n";
    }
    push @kept, "$managed_end\n";

    return 1 if $dry_run;
    my $tmp = "$file.$$";
    open(my $out, '>', $tmp) or return 0;
    print $out @kept;
    close($out) or return 0;
    chmod(0644, $tmp);
    rename($tmp, $file) or return 0;
    return 1;
}

sub jail_libraries {
    my ($binary) = @_;
    my @libraries;
    return @libraries unless -x $binary;

    open(my $fh, '-|', '/usr/bin/ldd', $binary) or return @libraries;
    while (my $line = <$fh>) {
        if ($line =~ /not found/) {
            push @libraries, '__JAILKIT_LDD_MISSING__';
        } elsif ($line =~ /=>\s+(\/\S+)/) {
            push @libraries, $1;
        } elsif ($line =~ /^\s*(\/\S+)\s+\(0x/) {
            push @libraries, $1;
        }
    }
    close($fh);
    my %seen;
    return grep { !$seen{$_}++ } @libraries;
}

sub repair_jail {
    my ($root, $label, $missing) = @_;
    my $ok = 1;

    if (!-d $root) {
        say_msg("  Creating $label with jk_init...\n");
        $ok = run_command(
            '/usr/sbin/jk_init', '-j', $root, '-k',
            'basicshell', 'editors', 'extendedshell', 'netutils',
            'ssh', 'sftp', 'scp', 'pico', 'id', 'logbasics', 'jk_lsh'
        );
        fail_msg("  ERROR: jk_init failed for $label\n") unless $ok;
    }

    for my $path (@{$missing || []}) {
        say_msg("  Copying $path into $label with jk_cp...\n");
        my $copied = run_command('/usr/sbin/jk_cp', '-v', '-f', $root, $path);
        if (!$copied) {
            fail_msg("  ERROR: jk_cp failed for $path in $label\n");
            $ok = 0;
        }
    }
    return $ok;
}

my $cce = new CCE;
$cce->connectuds();

my @sites;
my %site_data;
for my $oid ($cce->find('Vsite')) {
    my ($ok, $vsite) = $cce->get($oid);
    next unless $ok && $vsite->{name};
    next if $vsite->{suspend};
    my ($sok, $shell) = $cce->get($oid, 'Shell');
    next unless $sok && ($shell->{enabled} eq '1' || $shell->{enabled} eq '2');

    push @sites, $vsite->{name};
    $site_data{$vsite->{name}} = {
        oid => $oid,
        shell => $shell->{enabled},
        root => homedir_get_group_dir($vsite->{name}, $vsite->{volume})
    };
}

if (!@sites) {
    say_msg("No active jailed Vsites found.\n");
    $cce->bye('SUCCESS');
    exit 0;
}

my $policy_file = '/etc/jailkit/jk_lsh.ini';
my ($lines, $default, $groups, $policy_execs, $policy_paths) =
    read_jk_policy($policy_file);
my %required = map { $_ => 1 } @$policy_execs;
for my $site (@sites) {
    $required{'/usr/sbin/jk_lsh'} = 1 if $site_data{$site}->{shell} eq '1';
    $required{'/bin/bash'} = 1 if $site_data{$site}->{shell} eq '2';
}

my $policy_bad = !-f $policy_file || !exists $default->{executables}
    || !exists $default->{paths};
for my $site (@sites) {
    $policy_bad = 1 unless $groups->{$site};
}
if ($policy_bad) {
    say_msg("Repairing $policy_file\n");
    unless (write_jk_policy($policy_file, $lines, $default, $groups, \@sites)) {
        fail_msg("ERROR: Unable to repair $policy_file\n");
    }
}

my $errors = 0;
my %ldd_cache;
for my $site (@sites) {
    my $data = $site_data{$site};
    say_msg("Checking Vsite $site\n");

    my @users = $cce->find('User', { site => $site });
    my %jails = (
        siteAdmin => { root => $data->{root}, users => [] },
        users => { root => $data->{root} . '/home', users => [] }
    );

    for my $oid (@users) {
        my ($ok, $user) = $cce->get($oid);
        my ($sok, $shell) = $cce->get($oid, 'Shell');
        next unless $ok && $sok && ($shell->{enabled} eq '1' || $shell->{enabled} eq '2');
        my $is_admin = ($user->{capLevels} || '') =~ /&siteAdmin&/;
        my $bucket = $is_admin ? 'siteAdmin' : 'users';
        push @{$jails{$bucket}->{users}}, [$user->{name}, $shell->{enabled}];
    }

    for my $kind (qw(siteAdmin users)) {
        my $jail = $jails{$kind};
        unless (-d $jail->{root}) {
            say_msg(" Missing Jail $kind ($jail->{root})\n");
            $errors++ unless repair_jail($jail->{root}, "$site/$kind", []);
            next;
        }
        say_msg(" Checking Jail $kind ($jail->{root})\n");
        for my $user (@{$jail->{users}}) {
            say_msg("  Checking User $user->[0] (shell mode $user->[1])\n");
        }

        my $broken = 0;
        my @missing;
        for my $binary (keys %required) {
            unless (-x $binary) {
                fail_msg("  ERROR: host executable missing: $binary\n");
                $errors++;
                next;
            }
            $ldd_cache{$binary} ||= [jail_libraries($binary)];
            for my $library (@{$ldd_cache{$binary}}) {
                if ($library eq '__JAILKIT_LDD_MISSING__') {
                    say_msg("  Unresolved library reported by ldd for $binary\n");
                    $broken = 1;
                    next;
                }
                next if -e $jail->{root} . $library;
                say_msg("  Missing $library in $kind jail\n");
                $broken = 1;
                push @missing, $library;
            }
            unless (-e $jail->{root} . $binary) {
                say_msg("  Missing $binary in $kind jail\n");
                $broken = 1;
                push @missing, $binary;
            }
        }
        if ($broken && !repair_jail($jail->{root}, "$site/$kind", \@missing)) {
            $errors++;
        }
    }
}

if (!$dry_run) {
    open(my $fh, '>', $stamp);
    close($fh) if $fh;
}

$cce->bye($errors ? 'FAIL' : 'SUCCESS');
exit($errors ? 1 : 0);

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
