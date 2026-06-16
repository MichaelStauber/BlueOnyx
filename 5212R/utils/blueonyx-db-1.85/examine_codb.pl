#!/usr/bin/perl
use strict;
use warnings;
use feature qw(say);

my $codb_root = '/usr/sausalito/codb';
my $db_file   = "$codb_root/db.classes";
my $oids_file = "$codb_root/codb.oids";
my $dump_file  = '/tmp/db.classes.dump';

sub die_usage {
    die "Usage: $0\n";
}

sub hex_to_bytes {
    my ($hex) = @_;
    $hex =~ s/\s+//g;
    return pack('H*', $hex);
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
    my @b = unpack('C*', $bytes);
    my $oid = 0;
    for my $i (0 .. $#b) {
        $oid += $b[$i] << (8 * $i);
    }
    return $oid;
}

sub run_dump {
    my ($src, $dst) = @_;

    my @dump_cmds = (
        ['db_dump185', $src],
        ['db_dump',    $src],
    );

    my $dumped = 0;
    my $err = '';

    for my $cmd (@dump_cmds) {
        my ($bin, $path) = @$cmd;
        open my $in, '-|', $bin, $path or do {
            $err = $!;
            next;
        };
        open my $out, '>', $dst or die "open $dst: $!";
        while (my $line = <$in>) {
            print {$out} $line;
        }
        close $out or die "close $dst: $!";
        if (close $in) {
            $dumped = 1;
            last;
        }
        $err = $! || "$bin failed";
        unlink $dst;
    }

    die "Could not dump $src using db_dump185 or db_dump: $err\n" unless $dumped;
}

sub load_used_oids {
    my ($file) = @_;
    my %used;
    my $raw = '';
    my $has_trailing_newline = 0;
    my $has_hard_return = 0;

    return (\%used, $has_trailing_newline, $has_hard_return) unless -f $file;

    open my $fh, '<', $file or die "open $file: $!";
    local $/;
    $raw = <$fh>;
    close $fh;

    $has_trailing_newline = ($raw =~ /\n\z/) ? 1 : 0;
    $has_hard_return = ($raw =~ /\r/ && $raw !~ /\r\n/) ? 1 : 0;

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

    return (\%used, $has_trailing_newline, $has_hard_return);
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

die_usage() unless -f $db_file;

run_dump($db_file, $dump_file);

my ($used_oids, $has_newline, $has_hard_return) = load_used_oids($oids_file);

open my $dump, '<', $dump_file or die "open $dump_file: $!";

my $in_body = 0;
my @lines;
while (my $line = <$dump>) {
    chomp $line;
    if (!$in_body) {
        $in_body = 1 if $line eq 'HEADER=END';
        next;
    }
    next if $line =~ /^\s*$/;
    push @lines, $line;
}
close $dump;

die "dump has an odd number of body lines\n" if @lines % 2;

my %class_counts;
my %seen_pair;
my $entries = 0;
my $dupes = 0;
my $missing_object_dirs = 0;
my $class_mismatches = 0;
my $not_in_codb_oids = 0;
my %live_oids;

for (my $i = 0; $i < @lines; $i += 2) {
    my $class_hex = $lines[$i];
    my $oid_hex   = $lines[$i + 1];

    my $class = decode_key($class_hex);
    my $oid   = decode_oid($oid_hex);

    $entries++;
    $class_counts{$class}++;

    my $pair = join(':', $class, $oid);
    if ($seen_pair{$pair}++) {
        $dupes++;
        say "DUPLICATE\t$class\t$oid";
    }

    my $objdir    = "$codb_root/objects/$oid";
    my $classfile = "$objdir/.CLASS";
    $live_oids{$oid} = 1;

    if (!-d $objdir) {
        $missing_object_dirs++;
        say "MISSING_OBJDIR\t$class\t$oid\t$objdir";
    } else {
        if (-f $classfile) {
            open my $cfh, '<', $classfile or die "open $classfile: $!";
            my $live_class = do { local $/; <$cfh> };
            close $cfh;
            chomp $live_class;
            if ($live_class ne $class) {
                $class_mismatches++;
                say "CLASS_MISMATCH\t$oid\tindex=$class\tfile=$live_class";
            }
        } else {
            $class_mismatches++;
            say "MISSING_CLASSFILE\t$oid\tindex=$class\t$classfile";
        }
    }

    if (!exists $used_oids->{$oid}) {
        $not_in_codb_oids++;
        say "NOT_IN_CODB_OIDS\t$class\t$oid";
    }
}

my $live_oid_list = build_oid_list(\%live_oids);
my $oids_missing = 0;
my $oids_extra = 0;

for my $oid (keys %live_oids) {
    $oids_missing++ if !exists $used_oids->{$oid};
}
for my $oid (keys %{$used_oids}) {
    $oids_extra++ if !exists $live_oids{$oid};
}

say "";
say "SUMMARY";
say "dump_file=$dump_file";
say "entries=$entries";
say "duplicates=$dupes";
say "missing_object_dirs=$missing_object_dirs";
say "class_mismatches=$class_mismatches";
say "oids_not_marked_in_use=$not_in_codb_oids";
say "codb_oids_missing_live=$oids_missing";
say "codb_oids_extra_stale=$oids_extra";
say "codb_oids_trailing_newline=" . ($has_newline ? 1 : 0);
say "codb_oids_hard_return=" . ($has_hard_return ? 1 : 0);

say "";
say "CLASS_COUNTS";
for my $class (sort { $class_counts{$b} <=> $class_counts{$a} || $a cmp $b } keys %class_counts) {
    say "$class\t$class_counts{$class}";
}

say "";
say "OIDS_CANONICAL";
say $live_oid_list;

exit(($missing_object_dirs || $class_mismatches || $not_in_codb_oids || $oids_missing || $oids_extra || $has_newline || $has_hard_return) ? 2 : 0);
