#!/usr/bin/perl

use strict;
use warnings;
use File::Find;

# Dry-run by default
my $fix = 0;
$fix = 1 if @ARGV && $ARGV[0] eq '--fix';

find(\&fix_po_header, ".");

sub fix_po_header {
    return unless /\.po$/;
    return unless $File::Find::name =~ m!/en_US/!;

    my $file = $_;
    open my $fh, '<', $file or return;
    my @lines = <$fh>;
    close $fh;

    # Skip if already has Content-Type header
    my $has_header = grep { /Content-Type:/ } @lines;
    return if $has_header;

    print ($fix ? "Fixing: " : "Would fix: ");
    print "$File::Find::name\n";

    return unless $fix;

    # Define gettext header
    my @header = (
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: PACKAGE VERSION\n"',
        '"POT-Creation-Date: 2025-01-01 00:00+0000\n"',
        '"PO-Revision-Date: 2025-01-01 00:00+0000\n"',
        '"Last-Translator: Michael Stauber <mstauber@blueonyx.it>\n"',
        '"Language-Team: Team blueonyx\n"',
        '"MIME-Version: 1.0\n"',
        '"Content-Type: text/plain; charset=UTF-8\n"',
        '"Content-Transfer-Encoding: 8bit\n"',
        ''
    );

    # Write fixed file
    open my $out, '>', $file or die "Cannot write $file: $!";
    print $out join("\n", @header), "\n", @lines;
    close $out;
}

