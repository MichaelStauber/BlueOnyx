#!/usr/bin/perl -I/usr/sausalito/perl
#
# $Id: fix_vsite_php_dir_perms.pl
#
# One-shot / idempotent repair of per-Vsite PHP runtime directories after
# base-apache-glue security fix (parent php/ must be root:root 0755; leaves
# php/tmp 1777 and php/sessions 0700 owned by prefered_siteAdmin:siteX).
#
# Why a dedicated script (not only force_php_pool_update.pl):
#   force_php_pool_update.pl only fires when pool configs lack sys_temp_dir.
#   Boxes already on 2.4.9-7+ have those directives, so a DNF update would
#   skip the CCE walk and leave siteAdmin-owned php/ parents in place - the
#   LPE window would stay open. This script always walks the filesystem
#   (and CCE for the correct leaf owner) and heals ownership/mode without
#   rewriting pool files.
#
# Safety:
#   - Never follows symlinks for chown/chmod: lstat, move aside, mkdir.
#   - Parent hardened to root BEFORE leaves are touched.
#   - Does not touch wwwroot or other Vsite trees.
#   - Scratch renames keep any hostile symlink for forensics
#     (*.symlink-removed.<epoch>.<pid>).
#
# Output goes to stdout/stderr; RPM %post pipes to logger -t base-apache.
# Exit 0 on success (including nothing-to-do); 1 only on hard failures.
#

use strict;
use warnings;
use CCE;
use File::Basename;

my $cce = new CCE;
$cce->connectuds();

my $id = `id -u`;
chomp($id);
if ($id ne "0") {
    print "$0 must be run by user 'root'!\n";
    $cce->bye('FAIL');
    exit(1);
}

sub user_exists {
    my ($u) = @_;
    return 0 unless defined($u) && $u ne '';
    return getpwnam($u) ? 1 : 0;
}

sub ensure_real_dir {
    my ($path, $owner, $group, $mode_oct) = @_;
    return 0 unless defined($path) && $path ne '';

    my @lst = lstat($path);
    if (@lst) {
        if (-l _) {
            my $aside = $path . '.symlink-removed.' . time() . '.' . $$;
            if (rename($path, $aside)) {
                print "Moved symlink $path aside to $aside\n";
            } else {
                print "FAILED to move symlink $path aside: $!\n";
                return 0;
            }
        }
        elsif (! -d _) {
            my $aside = $path . '.not-a-dir.' . time() . '.' . $$;
            if (rename($path, $aside)) {
                print "Moved non-directory $path aside to $aside\n";
            } else {
                print "FAILED to move non-directory $path aside: $!\n";
                return 0;
            }
        }
    }

    if (! -e $path) {
        system('/bin/mkdir', '-p', '--', $path) == 0
            or do { print "mkdir failed for $path\n"; return 0; };
    }

    @lst = lstat($path);
    if (!@lst || -l _ || ! -d _) {
        print "Refusing chown/chmod on $path - not a real directory\n";
        return 0;
    }

    system('/bin/chown', '--', "$owner:$group", $path);
    system('/bin/chmod', sprintf('%04o', $mode_oct), $path);
    return 1;
}

sub heal_vsite_php {
    my ($basedir, $leaf_uid, $leaf_gid) = @_;
    return 0 unless defined($basedir) && $basedir ne '' && -d $basedir;

    $leaf_uid = 'apache' if (!user_exists($leaf_uid));
    $leaf_gid = 'apache' if (!defined($leaf_gid) || $leaf_gid eq '');

    my $php  = "$basedir/php";
    my $tmp  = "$php/tmp";
    my $sess = "$php/sessions";

    # Parent first.
    ensure_real_dir($php, 'root', 'root', 0755) or return 0;
    ensure_real_dir($tmp, $leaf_uid, $leaf_gid, 01777) or return 0;
    ensure_real_dir($sess, $leaf_uid, $leaf_gid, 0700) or return 0;

    # Heal ownership of existing files inside leaves (session/temp debris)
    # without touching the root-owned parent.
    for my $leaf ($tmp, $sess) {
        my @lst = lstat($leaf);
        next unless (@lst && -d _ && ! -l _);
        system('/bin/chown', '-R', '--', "$leaf_uid:$leaf_gid", $leaf);
    }
    system('/bin/chmod', '1777', $tmp)  if (-d $tmp  && ! -l $tmp);
    system('/bin/chmod', '0700', $sess) if (-d $sess && ! -l $sess);

    return 1;
}

# Map basedir -> {uid,gid} from CCE; also collect basedirs.
my %meta;
my (@oids) = $cce->findx('Vsite');
for my $oid (@oids) {
    my ($ok, $vs) = $cce->get($oid);
    next unless $ok && $vs->{basedir};
    my ($ok2, $php) = $cce->get($oid, 'PHP');
    my $uid = ($ok2 && $php->{prefered_siteAdmin}) ? $php->{prefered_siteAdmin} : '';
    if ($uid eq '' || !user_exists($uid)) {
        # Fall back to Vsite siteAdmin if present
        $uid = $vs->{siteAdmin} if ($vs->{siteAdmin} && user_exists($vs->{siteAdmin}));
    }
    $uid = 'apache' if ($uid eq '' || !user_exists($uid));
    my $gid = $vs->{name} || 'apache';
    $meta{$vs->{basedir}} = { uid => $uid, gid => $gid, fqdn => $vs->{fqdn} || '' };
}

# Also pick up any /home/.sites/*/php trees that CCE might have missed
# (orphaned dirs still need hardening).
for my $php_dir (glob('/home/.sites/*/php'), glob('/home/.sites/*/*/php')) {
    next unless -e $php_dir || -l $php_dir;
    my $basedir = dirname($php_dir);
    next if exists $meta{$basedir};
    # Infer site group from path leaf (siteN) when possible.
    my $base = basename($basedir);
    my $gid = ($base =~ /^site\d+$/) ? $base : 'apache';
    $meta{$basedir} = { uid => 'apache', gid => $gid, fqdn => '(filesystem)' };
}

my $healed = 0;
my $failed = 0;
my $skipped = 0;

for my $basedir (sort keys %meta) {
    my $m = $meta{$basedir};
    my $php = "$basedir/php";
    # Skip if nothing exists yet (brand-new vsite without PHP dirs) -
    # the handler will create correctly on first run.
    unless (-e $php || -l $php || -e "$php/tmp" || -l "$php/tmp") {
        $skipped++;
        next;
    }
    print "Healing PHP runtime dirs for $m->{fqdn} ($basedir) as $m->{uid}:$m->{gid}\n";
    if (heal_vsite_php($basedir, $m->{uid}, $m->{gid})) {
        $healed++;
    } else {
        print "FAILED healing $basedir\n";
        $failed++;
    }
}

print "fix_vsite_php_dir_perms: healed=$healed failed=$failed skipped_absent=$skipped\n";

if ($failed) {
    $cce->bye('FAIL');
    exit(1);
}
$cce->bye('SUCCESS');
exit(0);

#
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
#
