#!/usr/bin/perl
use strict;
use warnings;
use feature qw(say);
use File::Copy qw(copy move);
use POSIX qw(strftime);

my $codb_root = '/usr/sausalito/codb';
my $db_file    = "$codb_root/db.classes";
my $dump_file  = '/tmp/db.classes.dump';
my $clean_dump = '/tmp/db.classes.clean.dump';
my $new_db     = '/tmp/db.classes.repaired';
my $oids_new   = '/tmp/codb.oids.repaired';
my $fix        = grep { $_ eq '--fix' } @ARGV;
my $codb_backup = '';
my $db_backup = '';
my $oids_backup = '';
my $replaced = 0;
my $oids_replaced = 0;
my $db_load185 = '/home/solarspeed/db/bin/db_load185';

sub usage {
    die "Usage: $0 [--fix]\n";
}

sub hex_to_bytes {
    my ($hex) = @_;
    $hex =~ s/\s+//g;
    return pack('H*', $hex);
}

sub bytes_to_hex {
    my ($bytes) = @_;
    return unpack('H*', $bytes);
}

sub encode_key {
    my ($class) = @_;
    return bytes_to_hex($class . "\0");
}

sub encode_oid {
    my ($oid) = @_;
    return bytes_to_hex(pack('Q<', $oid));
}

sub decode_key {
    my ($hex) = @_;
    my $bytes = hex_to_bytes($hex);
    $bytes =~ s/\0+$//;
    return $bytes;
}

sub decode_oid {
    my ($hex) = @_;
    my $bytes = hex_to_bytes($hex);
    my $oid = unpack('Q<', $bytes);
    return $oid;
}

sub run_dump {
    my ($src, $dst) = @_;
    my $in;
    if (!open($in, '-|', 'db_dump185', $src)) {
        open($in, '-|', 'db_dump', $src) or die "db_dump185/db_dump failed for $src: $!";
    }
    open my $out, '>', $dst or die "open $dst: $!";
    while (my $line = <$in>) {
        print {$out} $line;
    }
    close $out or die "close $dst: $!";
    close $in or die "db_dump failed for $src: $!";
}

sub load_dump {
    my ($src, $dst) = @_;
    die "missing bundled db_load185 helper at $db_load185\n" unless -x $db_load185;
    system($db_load185, $src, $dst) == 0 or die "db_load185 failed for $src -> $dst\n";
}

sub load_used_oids {
    my ($file) = @_;
    my %used;
    return \%used unless -f $file;

    open my $fh, '<', $file or die "open $file: $!";
    local $/;
    my $raw = <$fh>;
    close $fh;

    $raw =~ s/\r//g;
    $raw =~ s/\n\z//;

    for my $chunk (split /,/, $raw) {
        next if $chunk =~ /^\s*$/;
        if ($chunk =~ /^(\d+)-(\d+)$/) {
            my ($a, $b) = ($1, $2);
            ($a, $b) = ($b, $a) if $a > $b;
            $used{$_} = 1 for $a .. $b;
        } elsif ($chunk =~ /^(\d+)$/) {
            $used{$1} = 1;
        }
    }
    return \%used;
}

sub build_oid_list {
    my ($oids) = @_;
    my @sorted = sort { $a <=> $b } keys %{$oids};
    return '' unless @sorted;
    return $sorted[0] if @sorted == 1;

    my @parts;
    my $start = $sorted[0];
    my $prev = $sorted[0];

    for my $oid (@sorted[1 .. $#sorted]) {
        if ($oid == $prev + 1) {
            $prev = $oid;
            next;
        }

        push @parts, ($start == $prev) ? $start : "$start-$prev";
        $start = $prev = $oid;
    }

    push @parts, ($start == $prev) ? $start : "$start-$prev";
    return join(',', @parts);
}

sub backup_original {
    my ($src) = @_;
    my $stamp = strftime('%Y%m%d-%H%M%S', localtime);
    my $bak = "$src.bak.$stamp";
    copy($src, $bak) or die "backup $src -> $bak failed: $!";
    return $bak;
}

sub write_text_file {
    my ($path, $content) = @_;
    open my $fh, '>', $path or die "open $path: $!";
    print {$fh} $content or die "write $path failed: $!";
    close $fh or die "close $path: $!";
}

sub run_systemctl {
    my (@cmd) = @_;
    system(@cmd);
    if ($? == -1) {
        die "failed to execute @cmd: $!\n";
    }
    if ($? & 127) {
        die sprintf("@cmd died with signal %d\n", $? & 127);
    }
    my $exit = $? >> 8;
    if ($exit != 0) {
        die sprintf("@cmd exited with status %d\n", $exit);
    }
}

sub stop_unit {
    my ($unit) = @_;
    eval { run_systemctl('systemctl', 'stop', $unit); 1 } or do {
        my $err = $@ || "unknown error";
        warn "failed to stop $unit: $err";
    };
}

sub start_unit {
    my ($unit) = @_;
    eval { run_systemctl('systemctl', 'start', $unit); 1 } or do {
        my $err = $@ || "unknown error";
        warn "failed to start $unit: $err";
    };
}

sub stop_services {
    stop_unit('cced.init.service');
    stop_unit('cced-api');
    stop_unit('crond');
    stop_unit('admserv');
}

sub start_services {
    start_unit('crond');
    start_unit('admserv');
    start_unit('cced-api');
    start_unit('cced.init.service');
}

sub backup_codb_tree {
    my $stamp = strftime('%Y%m%d-%H%M%S', localtime);
    my $tarball = "/usr/sausalito/codb-backup-$stamp.tar.gz";
    system('tar', '-czf', $tarball, '-C', '/usr/sausalito', 'codb');
    if ($? == -1) {
        die "failed to execute tar: $!\n";
    }
    if ($? & 127) {
        die sprintf("tar died with signal %d\n", $? & 127);
    }
    my $exit = $? >> 8;
    if ($exit != 0) {
        die sprintf("tar exited with status %d\n", $exit);
    }
    return $tarball;
}

usage() unless -f $db_file;

run_dump($db_file, $dump_file);

my $current_used = load_used_oids("$codb_root/codb.oids");

my %live;
my %live_classes;
my %live_oids;
my $live_total = 0;
my $mismatch_total = 0;

opendir(my $dirh, "$codb_root/objects") or die "opendir $codb_root/objects: $!";
while (my $entry = readdir($dirh)) {
    next if $entry =~ /^\./;
    my $objdir = "$codb_root/objects/$entry";
    next unless -d $objdir;
    my $class_file = "$objdir/.CLASS";
    my $oid_file   = "$objdir/.OID";
    next unless -f $class_file && -f $oid_file;

    open my $cfh, '<', $class_file or die "open $class_file: $!";
    my $class = do { local $/; <$cfh> };
    close $cfh;
    chomp $class;

    open my $ofh, '<', $oid_file or die "open $oid_file: $!";
    my $oid = do { local $/; <$ofh> };
    close $ofh;
    chomp $oid;

    if ($oid !~ /^\d+$/) {
        warn "Skipping non-numeric OID in $objdir/.OID: $oid\n";
        next;
    }

    if ($oid != $entry) {
        $mismatch_total++;
        warn "OID mismatch: dir=$entry file=$oid class=$class\n";
    }

    $live{$class}{$entry} = 1;
    $live_classes{$class}++;
    $live_oids{$entry} = 1;
    $live_total++;
}
closedir($dirh);

open my $clean, '>', $clean_dump or die "open $clean_dump: $!";
print {$clean} "format=bytevalue\n";
print {$clean} "type=btree\n";
print {$clean} "db_lorder=1234\n";
print {$clean} "db_pagesize=4096\n";
print {$clean} "duplicates=1\n";
print {$clean} "HEADER=END\n";

for my $class (sort keys %live) {
    for my $oid (sort { $a <=> $b } keys %{ $live{$class} }) {
        print {$clean} encode_key($class), "\n";
        print {$clean} encode_oid($oid), "\n";
    }
}

close $clean or die "close $clean_dump: $!";

my $expected_entries = 0;
$expected_entries += scalar(keys %{ $live{$_} }) for keys %live;
my $clean_oids = build_oid_list(\%live_oids);

say "live_objects=$live_total";
say "live_class_entries=$expected_entries";
say "class_oids_mismatched=$mismatch_total";
say "current_codb_oids=" . scalar(keys %{$current_used});
say "clean_dump=$clean_dump";
say "clean_codb_oids=$clean_oids";

unless ($fix) {
    say "dry_run=1";
    exit 0;
}

stop_services();
$codb_backup = backup_codb_tree();
say "codb_backup=$codb_backup";

my $repaired = $new_db . "." . $$;
eval {
    load_dump($clean_dump, $repaired);

    $db_backup = backup_original($db_file);
    move($repaired, $db_file) or die "replace $db_file failed: $!";
    $replaced = 1;

    $oids_backup = backup_original("$codb_root/codb.oids");
    write_text_file($oids_new, $clean_oids);
    move($oids_new, "$codb_root/codb.oids") or die "replace $codb_root/codb.oids failed: $!";
    $oids_replaced = 1;
    1;
} or do {
    my $err = $@ || 'unknown error';
    warn $err;
    start_services();
    die $err;
};

start_services();
say "RESULT codb_backup=$codb_backup db_backup=$db_backup oids_backup=$oids_backup replaced=" . ($replaced ? 1 : 0) . " oids_replaced=" . ($oids_replaced ? 1 : 0) . " db_file=$db_file oids_file=$codb_root/codb.oids";
exit 0;

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR 
# A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT 
# HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, 
# SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT 
# LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, 
# DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY 
# THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT 
# (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE 
# OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
# 
# You acknowledge that this software is not designed or intended for 
# use in the design, construction, operation or maintenance of any 
# nuclear facility.
# 
