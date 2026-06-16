#!/usr/bin/perl
# id: blueonyx-postfix-generate-sender-login-maps.pl
use strict;
use warnings;
use lib "/usr/sausalito/perl";
use CCE;

# To do list / notes:
#
# - Possibly make email protection configurable per Vsite/User
#

my $DEBUG = '0'; # 0 = off, 1 = syslog, 2 = syslog and screen
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock );
}

my $cce = new CCE;
$cce->connectuds();

my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $System_Email) = $cce->get($oids[0], 'Email');
unless ($ok and $System_Email) {
    $cce->bye('FAIL');
    exit 1;
}

# Keep track of map entries by LHS:
#   LHS => { terms => { term => 1 }, order => [ term1, term2, ... ] }
my %map_entries;

#
# ---------------------------------------------------------------------------
# 0) Determine host FQDN for system users (admin, etc.)
# ---------------------------------------------------------------------------
#

my $host_fqdn = `hostname -f 2>/dev/null`;
chomp $host_fqdn;
if (!$host_fqdn) {
    $host_fqdn = `hostname 2>/dev/null`;
    chomp $host_fqdn;
}
$host_fqdn ||= 'localhost';

#
# ---------------------------------------------------------------------------
# 1) Collect Vsite data and add wildcard mappings
# ---------------------------------------------------------------------------
# We build a hash keyed by Vsite->{name} with:
#  - fqdn
#  - domains => [ fqdn + mailAliases ]
#  - admin_rhs => "siteAdmin siteAdmin@fqdn"
#  - active => (not suspended, not emailDisabled)
#

my %vsite_info;
my @vsites = $cce->findx("Vsite");

for my $oid (@vsites) {
    my ($ok, $vsite) = $cce->get($oid);
    if (!$ok) {
        debug_msg("Failed to get Vsite OID $oid\n");
        next;
    }

    my $site_name = $vsite->{name};
    next unless $site_name;

    my $vsite_suspended = defined $vsite->{suspend}       ? $vsite->{suspend}       : '0';
    my $vsite_mail_off  = defined $vsite->{emailDisabled} ? $vsite->{emailDisabled} : '0';
    my $active          = ($vsite_suspended eq '0' && $vsite_mail_off eq '0') ? 1 : 0;

    my ($vok, $vsite_PHP) = $cce->get($oid, 'PHP');
    if (!$vok) {
        debug_msg("Failed to get Vsite PHP namespace for OID $oid\n");
        $vsite_PHP = {};
    }

    my $fqdn = $vsite->{fqdn} || "";
    my @domains = ($fqdn);

    debug_msg("Processing Vsite: $fqdn (site=$site_name)\n");

    # ----------------------------------------------------------
    # Collect Vsite mail aliases (Email Server Aliases)
    # ----------------------------------------------------------
    if (defined $vsite->{mailAliases} && $vsite->{mailAliases} ne '') {
        my @mail_aliases = $cce->scalar_to_array($vsite->{mailAliases});
        if (@mail_aliases) {
            debug_msg("Vsite $site_name mailAliases (raw): $vsite->{mailAliases}\n");
            debug_msg("Vsite $site_name mailAliases (parsed): @mail_aliases\n");
            push @domains, @mail_aliases;
        }
    }

    # De-duplicate domains, drop empties
    my %seen;
    @domains = grep { $_ && !$seen{$_}++ } @domains;

    debug_msg("Vsite $site_name final domains: @domains\n");

    my $site_admin = $vsite_PHP->{prefered_siteAdmin} || "";

    my @sa_identities_admin;
    my $admin_rhs = '';
    if ($site_admin) {
        # Allowed SASL identities for the siteAdmin:
        #  - bare username
        #  - username@fqdn
        @sa_identities_admin = ($site_admin);
        push @sa_identities_admin, "$site_admin\@$fqdn" if $fqdn;
        $admin_rhs = join(' ', @sa_identities_admin);
    }

    # Store everything we need for Vsite users later:
    $vsite_info{$site_name} = {
        fqdn      => $fqdn,
        domains   => [ @domains ],   # <<< ensure arrayref
        admin_rhs => $admin_rhs,
        active    => $active,
    };

    # If Vsite is active and we have a siteAdmin, add wildcard
    #   @domain → siteAdmin identities
    if ($active && $admin_rhs && @domains) {
        for my $domain (@domains) {
            next unless $domain;
            add_map_line("\@$domain", $admin_rhs);
        }
    }
}

#
# ---------------------------------------------------------------------------
# 2) Single pass over ALL users
#    - If $user->{site} eq ''   => system user
#    - Else                     => Vsite member
# ---------------------------------------------------------------------------
#

my @user_oids = $cce->findx("User");

for my $user_oid (@user_oids) {
    my ($uok, $user) = $cce->get($user_oid);
    if (!$uok) {
        debug_msg("Failed to get User OID $user_oid\n");
        next;
    }

    my $username = $user->{name};
    next unless $username;

    my $site = defined $user->{site} ? $user->{site} : '';

    my ($ok_email, $email) = $cce->get($user_oid, 'Email');
    next unless $ok_email;

    # Per-user flags:
    # emailDisabled       = "0"
    # enabled             = "1"
    # ui_enabled          = "1"
    # allow_sender_spoof  = "0" or "1"
    my $u_enabled         = defined $user->{enabled}    ? $user->{enabled}    : '0';
    my $u_ui_enabled      = defined $user->{ui_enabled} ? $user->{ui_enabled} : '0';
    my $u_mail_off        = defined $user->{emailDisabled} ? $user->{emailDisabled} : '0';
    my $u_spoof_allowed   = defined $email->{allow_sender_spoof} ? $email->{allow_sender_spoof} : '0';

    if ($System_Email->{authsend_protect} eq '0') {
        $u_spoof_allowed = '0';
    }

    # Skip users that are disabled or have mail disabled
    next unless ($u_enabled eq '1' && $u_ui_enabled eq '1' && $u_mail_off eq '0');

    my @email_aliases;
    if ($email->{aliases}) {
        @email_aliases = $cce->scalar_to_array($email->{aliases});
    }

    if ($site eq '') {
        #
        # -------------------- System User --------------------
        #
        debug_msg("Processing System-User: $username (spoof=$u_spoof_allowed)\n");

        # Allowed SASL identities:
        #   - bare username
        #   - username@host_fqdn
        my @sa_identities_sys = ($username);
        push @sa_identities_sys, "$username\@$host_fqdn" if $host_fqdn;
        my $sys_rhs = join(' ', @sa_identities_sys);

        my @localparts = ($username);
        push @localparts, @email_aliases if @email_aliases;

        # localpart@host_fqdn → allowed login IDs
        for my $localpart (@localparts) {
            next unless $localpart;
            add_map_line("$localpart\@$host_fqdn", $sys_rhs);
        }

        # bare username → allowed login IDs
        add_map_line($username, $sys_rhs);

        # If spoofing is allowed, let this system user spoof any sender at host_fqdn
        if ($u_spoof_allowed eq '1' && $host_fqdn) {
            debug_msg("System-User $username is allowed to spoof @${host_fqdn}\n");
            add_map_line("\@$host_fqdn", $sys_rhs);
        }
    }
    else {
        #
        # -------------------- Vsite Member --------------------
        #
        my $vinfo = $vsite_info{$site};
        unless ($vinfo) {
            debug_msg("User $username belongs to unknown Vsite '$site' – skipping\n");
            next;
        }

        # If Vsite is suspended / emailDisabled, skip all its users
        unless ($vinfo->{active}) {
            debug_msg("User $username belongs to inactive Vsite '$site' – skipping\n");
            next;
        }

        my $fqdn = $vinfo->{fqdn} || "";

        my @domains;
        if (exists $vinfo->{domains}) {
            if (ref($vinfo->{domains}) eq 'ARRAY') {
                @domains = @{ $vinfo->{domains} };
            }
            else {
                # Fallback in case something weird happened
                @domains = ($vinfo->{domains});
                debug_msg("WARNING: Vsite '$site' has non-ARRAY domains='$vinfo->{domains}' – coerced to list\n");
            }
        }
        else {
            @domains = ();
        }

        debug_msg("Processing Vsite-User: $username (site=$site, fqdn=$fqdn, spoof=$u_spoof_allowed, domains=@domains)\n");

        # Allowed identities for this user:
        #   - bare username
        #   - username@fqdn (main Vsite fqdn)
        my @sa_identities_user = ($username);
        push @sa_identities_user, "$username\@$fqdn" if $fqdn;
        my $user_rhs = join(' ', @sa_identities_user);

        my @localparts = ($username);
        push @localparts, @email_aliases if @email_aliases;

        # 1) full addresses:  localpart@domain → allowed login IDs
        for my $localpart (@localparts) {
            next unless $localpart;
            for my $domain (@domains) {
                next unless $domain;
                add_map_line("$localpart\@$domain", $user_rhs);
            }
        }

        # 2) bare username (for local mail / weird MUAs that omit @domain)
        add_map_line($username, $user_rhs);

        # 3) If this user is allowed to spoof, add wildcard @domain mappings
        if ($u_spoof_allowed eq '1' && @domains) {
            for my $domain (@domains) {
                next unless $domain;
                debug_msg("User $username is allowed to spoof $domain\n");
                add_map_line("\@$domain", $user_rhs);
            }
        }
    }
}

#
# ---------------------------------------------------------------------------
# 3) Write out map and build DB
# ---------------------------------------------------------------------------
#

open my $fh, '>', '/etc/postfix/sender_canonical' or die "Cannot write /etc/postfix/sender_canonical: $!";

for my $lhs (sort keys %map_entries) {
    my $rhs = join(' ', @{ $map_entries{$lhs}->{order} });
    next unless $rhs;
    print $fh "$lhs\t$rhs\n";
}
close $fh;

system("postmap /etc/postfix/sender_canonical");

exit 0;

#
# ---------------------------------------------------------------------------
# Subroutines:
# ---------------------------------------------------------------------------
#

sub add_map_line {
    my ($lhs, $rhs) = @_;

    # Sanity cleanup
    return unless defined $lhs && defined $rhs;
    $lhs =~ s/\s+$//;
    $rhs =~ s/\s+$//;
    return if ($lhs eq '' || $rhs eq '');

    # Split RHS into individual SASL identities
    my @rhs_terms = split(/\s+/, $rhs);
    return unless @rhs_terms;

    # Create or fetch entry for this LHS
    my $entry = $map_entries{$lhs} ||= { terms => {}, order => [] };

    for my $term (@rhs_terms) {
        next if $term eq '';
        next if $entry->{terms}{$term};    # already have this identity
        $entry->{terms}{$term} = 1;
        push @{ $entry->{order} }, $term;  # preserve first-seen order
    }
}

sub debug_msg {
    my $msg = shift;
    if ($DEBUG gt '1') {
        print "$msg";
    }
    if ($DEBUG gt '0') {
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$msg");
        closelog;
    }
}

# 
# Copyright (c) 2020-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2020-2025 Team BlueOnyx, BLUEONYX.IT
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
