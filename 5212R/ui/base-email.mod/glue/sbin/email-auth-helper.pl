#!/usr/bin/perl -I/usr/sausalito/perl
#
# Id: /usr/sausalito/sbin/email-auth-helper.pl
#
# Generate a fast allowlist map for email-login alias resolution:
#   email-address -> system username
#
# - Only generates explicit addresses (no catch-all behavior).
# - Skips Vsites that are suspended OR have Email service disabled:
#     Vsite.suspend == 1 OR Vsite.emailDisabled == 1
# - Skips Users that are disabled OR have Email service disabled OR whose Vsite is disabled:
#     User.enabled != 1 OR User.emailDisabled == 1 OR (parent Vsite disabled as above)
#
# Output (default):
#   /etc/dovecot/blueonyx-login-aliases.map   (TAB-separated: email \t username \n)
#   /etc/dovecot/blueonyx-login-disabled.map (TAB-separated: username \t reason \n)
#
# Optional:
#   --postmap     also run `postmap hash:<mapfile>` to create <mapfile>.db
#
use strict;
use warnings;

use Getopt::Long qw(GetOptions);
use File::Temp qw(tempfile);
use File::Basename qw(dirname);
use File::Path qw(make_path);
use POSIX qw(strftime);

use CCE;

# --------------------------
# CLI options
# --------------------------
my $OUT_MAP      = '/etc/dovecot/blueonyx-login-aliases.map';
my $OUT_DISABLED = '/etc/dovecot/blueonyx-login-disabled.map';
my $DO_POSTMAP   = 0;
my $DEBUG        = 0;

# Ensure dovecot can read the maps (auth runs unprivileged)
my $DOVECOT_GROUP = 'dovecot';

GetOptions(
    'out-map=s'      => \$OUT_MAP,
    'out-disabled=s' => \$OUT_DISABLED,
    'postmap!'       => \$DO_POSTMAP,
    'debug!'         => \$DEBUG,
) or die "Usage: $0 [--out-map FILE] [--out-disabled FILE] [--postmap] [--debug]\n";

sub dbg { return unless $DEBUG; print STDERR "[DBG] ", @_, "\n"; }

# --------------------------
# Connect to CCE
# --------------------------
my $cce = CCE->new();
$cce->connectuds();

# --------------------------
# Fetch all Vsites (OBJECT namespace only)
# --------------------------
dbg("Fetching all Vsites…");
my @vsites = $cce->getAll('Vsite', {});  # OBJECT only

# Build vsite index by vsite name
my %vsite_by_name;
for my $v (@vsites) {
    my $obj = $v->{OBJECT} || {};
    my $siteName = $obj->{name} // '';
    next if $siteName eq '';
    $vsite_by_name{$siteName} = $v;
}

# --------------------------
# Fetch all Users (OBJECT + Email namespace preferred)
# --------------------------
dbg("Fetching all Users…");
my @users;
my $have_namespaced_getall = 0;

# If your new CCE.pm getAll() supports namespaces, prefer it:
eval {
    # try the 3-arg form: (class, criteria, namespaces)
    @users = $cce->getAll('User', {}, ['Email']);
    $have_namespaced_getall = 1;
    1;
} or do {
    # fallback to OBJECT-only getAll()
    @users = $cce->getAll('User', {});
    $have_namespaced_getall = 0;
};

# Build user list by site
my %users_by_site;        # siteName => [ userhashref ... ]
my %user_disabled_reason; # username => reason (for disabled.map)
for my $u (@users) {
    my $obj = $u->{OBJECT} || {};
    my $uname = $obj->{name} // '';
    next if $uname eq '';
    my $site = $obj->{site} // '';
    next if $site eq '';

    push @{ $users_by_site{$site} }, $u;
}

# --------------------------
# Determine whether a Vsite is allowed for mail auth
# --------------------------
sub vsite_mail_disabled_reason {
    my ($siteName) = @_;
    my $v = $vsite_by_name{$siteName};
    return "vsite_missing" unless $v;

    my $o = $v->{OBJECT} || {};
    if (defined $o->{suspend} && "$o->{suspend}" eq '1') {
        return "vsite.suspend=1";
    }
    if (defined $o->{emailDisabled} && "$o->{emailDisabled}" eq '1') {
        return "vsite.emailDisabled=1";
    }
    return ''; # ok
}

# --------------------------
# Determine whether a User is allowed for mail auth
# --------------------------
sub user_mail_disabled_reason {
    my ($u) = @_;
    my $o = $u->{OBJECT} || {};
    my $uname = $o->{name} // '';

    # If parent vsite is disabled, user auth is disabled too
    my $site = $o->{site} // '';
    my $v_reason = $site ? vsite_mail_disabled_reason($site) : "vsite_missing";
    return $v_reason if $v_reason ne '';

    # User enabled?
    my $enabled = $o->{enabled};
    if (!defined $enabled || "$enabled" ne '1') {
        return "user.enabled=$enabled";
    }

    # User mail disabled?
    if (defined $o->{emailDisabled} && "$o->{emailDisabled}" eq '1') {
        return "user.emailDisabled=1";
    }

    return ''; # ok
}

# --------------------------
# Build mapping
# --------------------------
my %map;        # email => username
my %conflict;   # email => 1 (ambiguous; do not emit)
my $now = strftime('%Y-%m-%d %H:%M:%S', localtime);

sub add_map_entry {
    my ($email, $uname) = @_;
    $email = norm_email($email);
    return if $email eq '';
    return unless is_emailish($email);

    # If already known ambiguous, ignore
    return if exists $conflict{$email};

    # First wins if empty
    if (!exists $map{$email}) {
        $map{$email} = $uname;
        return;
    }

    # Same mapping is fine
    return if $map{$email} eq $uname;

    # Ambiguous mapping: DROP it (safer than "keep first")
    dbg("AMBIGUOUS: $email maps to both $map{$email} and $uname (dropping entry)");
    delete $map{$email};
    $conflict{$email} = 1;
}

# Iterate deterministically
for my $siteName (sort keys %vsite_by_name) {
    my $v = $vsite_by_name{$siteName};
    my $vobj = $v->{OBJECT} || {};

    my $v_reason = vsite_mail_disabled_reason($siteName);
    if ($v_reason ne '') {
        dbg("Skipping vsite $siteName ($v_reason)");
        next;
    }

    # Determine valid domains for this vsite
    # NOTE: You *want* mailAliases considered, because users may login via those.
    my %valid_domains;
    for my $k (qw(domain fqdn)) {
        my $d = lc($vobj->{$k} // '');
        $d =~ s/^\s+|\s+$//g;
        $valid_domains{$d} = 1 if $d ne '';
    }
    for my $d (scalar_to_array($vobj->{mailAliases} // '')) {
        $d = lc($d);
        $d =~ s/^\s+|\s+$//g;
        $valid_domains{$d} = 1 if $d ne '';
    }

    my $userlist = $users_by_site{$siteName} || [];
    for my $u (@$userlist) {
        my $uobj  = $u->{OBJECT} || {};
        my $uname = $uobj->{name} // '';
        next if $uname eq '';

        my $u_reason = user_mail_disabled_reason($u);
        if ($u_reason ne '') {
            $user_disabled_reason{$uname} = $u_reason; # for disabled.map
            dbg("Skipping user $uname in $siteName ($u_reason)");
            next;
        }

        # 1) Direct username@domain for each valid domain
        for my $d (sort keys %valid_domains) {
            add_map_entry("$uname\@$d", $uname);
        }

        # 2) Email aliases (from Email namespace)
        my $aliases_scalar = '';

        if ($have_namespaced_getall && exists $u->{Email} && ref($u->{Email}) eq 'HASH') {
            $aliases_scalar = $u->{Email}->{aliases} // '';
        }
        else {
            # fallback: fetch Email namespace for this user OID (slower but correct)
            my $oid = $uobj->{OID} || $u->{OID};
            if ($oid) {
                my ($ok, $ns) = $cce->get($oid, 'Email');
                $aliases_scalar = $ok ? ($ns->{aliases} // '') : '';
            }
        }

        for my $a (scalar_to_array($aliases_scalar // '')) {
            $a = lc($a);
            $a =~ s/^\s+|\s+$//g;
            next if $a eq '';

            # alias stored as full email
            if ($a =~ /\@/) {
                my ($al, $ad) = split(/\@/, $a, 2);
                $al //= ''; $ad //= '';
                $al =~ s/^\s+|\s+$//g;
                $ad =~ s/^\s+|\s+$//g;

                # Only accept if alias domain belongs to this vsite
                next unless $al ne '' && $ad ne '' && $valid_domains{$ad};
                add_map_entry("$al\@$ad", $uname);
                next;
            }

            # alias stored as local-part only => expand for each valid domain
            for my $d (sort keys %valid_domains) {
                add_map_entry("$a\@$d", $uname);
            }
        }
    }
}

# --------------------------
# Output content
# --------------------------
# Sort for deterministic diffs
my @emails = sort keys %map;

my $map_out = "# Generated by email-auth-helper.pl at $now\n";
$map_out   .= "# Format: email<TAB>username\n";
for my $e (@emails) {
    $map_out .= "$e\t$map{$e}\n";
}

my @disabled_users = sort keys %user_disabled_reason;
my $dis_out = "# Generated by email-auth-helper.pl at $now\n";
$dis_out   .= "# Format: username<TAB>reason\n";
for my $u (@disabled_users) {
    $dis_out .= "$u\t$user_disabled_reason{$u}\n";
}

# Write atomically
write_atomic($OUT_MAP,      \$map_out);
write_atomic($OUT_DISABLED, \$dis_out);

dbg("Wrote $OUT_MAP with ".scalar(@emails)." entries");
dbg("Wrote $OUT_DISABLED with ".scalar(@disabled_users)." entries");
dbg("Suppressed ".scalar(keys %conflict)." ambiguous address keys") if $DEBUG;

# Optional: build a hashed map for ultra-fast lookup (Postfix-style)
if ($DO_POSTMAP) {
    my $postmap = -x '/usr/sbin/postmap' ? '/usr/sbin/postmap'
               : -x '/sbin/postmap'     ? '/sbin/postmap'
               : -x '/usr/bin/postmap'  ? '/usr/bin/postmap'
               : '';
    if ($postmap) {
        my $cmd = "$postmap hash:$OUT_MAP";
        dbg("Running: $cmd");
        system($cmd) == 0 or die "postmap failed for $OUT_MAP\n";
    }
    else {
        die "Requested --postmap but postmap binary not found\n";
    }
}

# Fix ownerships and permissions:
chown(0, (getgrnam($DOVECOT_GROUP))[2], $OUT_MAP)      or warn "chown $OUT_MAP failed: $!";
chown(0, (getgrnam($DOVECOT_GROUP))[2], $OUT_DISABLED) or warn "chown $OUT_DISABLED failed: $!";
chmod(0640, $OUT_MAP)      or warn "chmod $OUT_MAP failed: $!";
chmod(0640, $OUT_DISABLED) or warn "chmod $OUT_DISABLED failed: $!";

$cce->bye();
exit 0;

# --------------------------
# Subs:
# --------------------------
sub scalar_to_array {
    my ($s) = @_;
    return () unless defined $s;
    $s =~ s/^\s+|\s+$//g;
    return () if $s eq '';
    $s =~ s/^&+//;
    $s =~ s/&+$//;
    return () if $s eq '';
    my @p = grep { defined($_) && $_ ne '' }
            map  { my $x = $_; $x =~ s/^\s+|\s+$//g; $x }
            split(/&/, $s);
    return @p;
}

sub norm_email {
    my ($e) = @_;
    return '' unless defined $e;
    $e =~ s/^\s+|\s+$//g;
    $e = lc($e);
    return $e;
}

sub is_emailish {
    my ($e) = @_;
    return 0 unless defined $e;
    return ($e =~ /^[^@\s]+@[^@\s]+\.[^@\s]+$/) ? 1 : 0;
}

sub write_atomic {
    my ($path, $content_ref) = @_;
    my $dir = dirname($path);
    if (!-d $dir) {
        make_path($dir, { mode => 0755 }) or die "Failed to create $dir: $!\n";
    }

    my ($fh, $tmp) = tempfile(".$$.tmpXXXX", DIR => $dir, UNLINK => 0);
    binmode($fh, ':utf8');
    print $fh $$content_ref;
    close($fh) or die "Failed writing $tmp: $!\n";

    rename($tmp, $path) or die "Failed to rename $tmp -> $path: $!\n";
}

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