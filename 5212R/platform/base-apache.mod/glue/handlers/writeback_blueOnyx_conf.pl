#!/usr/bin/perl -I/usr/sausalito/perl -I.
# $Id: writeback_blueOnyx_conf.pl
#
# This handler is responsible for updating /etc/httpd/conf.d/blueonyx.conf,
# as well as writing /var/www/html/robots.txt and /var/www/html/robots_deny_all.txt
#

my $confdir = '/etc/httpd/conf.d';
my $blueonyx_conf = "$confdir/blueonyx.conf";

my $robots_txt = '/var/www/html/robots.txt';
my $robots_deny_all_txt = '/var/www/html/robots_deny_all.txt';

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
    &debug_msg("Debugging enabled for writeback_blueOnyx_conf.pl\n");
}

use Sauce::Util;
use CCE;

my $cce = new CCE;
$cce->connectfd();

my ($oid) = $cce->find("System");
my ($ok, $obj) = $cce->get($oid);
my ($status, $web) = $cce->get($oid, "Web");

if (!Sauce::Util::editfile(($blueonyx_conf),*edit_blueonyx, $web)) {
    $cce->bye('FAIL', '[[base-apache.cantEdit_BlueOnyx_conf]]');
    exit(1);
}

# Update robots.txt based on good and bad user-agents
if (!update_robots_txt($web->{'good_useragents'}, $web->{'bad_useragents'}, $cce)) {
    $cce->bye('FAIL', '[[base-apache.cantEdit_robots_txt]]');
    exit(1);
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

sub update_robots_txt {
    my ($good_useragents, $bad_useragents) = @_;

    my @good_agents = $cce->scalar_to_array($good_useragents);
    my @bad_agents = $cce->scalar_to_array($bad_useragents);

    open(my $fh, '>', $robots_txt) or return 0;

    print $fh "# Generated robots.txt by BlueOnyx\n\n";

    # Allow well-known search engines and reputable crawlers
    print $fh "# Allow well-known search engines and reputable crawlers\n";
    foreach my $ua (@good_agents) {
        print $fh "User-agent: $ua\n";
        print $fh "Allow: /\n\n";
    }

    # Block known unwanted bots and scrapers
    print $fh "# Block known unwanted bots and scrapers\n";
    foreach my $ua (@bad_agents) {
        print $fh "User-agent: $ua\n";
        print $fh "Disallow: /\n\n";
    }

    # General restrictions for all other bots
    print $fh "# General restrictions for all other bots\n";
    print $fh "User-agent: *\n";
    print $fh "Disallow: /cgi-bin/\n";
    print $fh "Disallow: /wp-admin/\n";
    print $fh "Disallow: /wp-includes/\n";
    print $fh "Disallow: /tmp/\n";
    print $fh "Disallow: /private/\n";
    print $fh "Disallow: /config/\n";

    close($fh);

    open(my $fh, '>', $robots_deny_all_txt) or return 0;
    print $fh "# Generated robots_deny_all.txt by BlueOnyx\n\n";
    print $fh "User-agent: *\n";
    print $fh "Disallow: /\n";
    print $fh "\n";
    close($fh);

    return 1;
}

sub edit_blueonyx {
    my ($in, $out, $webdata) = @_;
    my $web = $webdata;                      # use the arg you were passed
    my $begin = '<Directory /home/.sites/>';
    my $script_conf = '';

    # --- Build Options line (keep +/- semantics and optional All) ---
    my @Options = ();

    if ($web->{Options_Indexes} && $web->{Options_Indexes} eq "1") {
        push(@Options, "+Indexes");
        &debug_msg("Options: Indexes: +Indexes");
    }
    else {
        push(@Options, "-Indexes");
        &debug_msg("Options: Indexes: -Indexes");
    }
    push(@Options, "+SymLinksIfOwnerMatch") if ($web->{Options_SymLinksIfOwnerMatch} && $web->{Options_SymLinksIfOwnerMatch} eq "1");
    push(@Options, "+FollowSymLinks")       if ($web->{Options_FollowSymLinks}       && $web->{Options_FollowSymLinks}       eq "1");
    push(@Options, "+Includes")             if ($web->{Options_Includes}             && $web->{Options_Includes}             eq "1");
    push(@Options, "+MultiViews")           if ($web->{Options_MultiViews}           && $web->{Options_MultiViews}           eq "1");

    my $o_all = '';
    if ($web->{Options_All} && $web->{Options_All} eq "1") {
        $o_all = 'All ';
    }

    # --- Build AllowOverride classes ---
    my @AllowOverride = ();
    push(@AllowOverride, "AuthConfig") if ($web->{AllowOverride_AuthConfig} && $web->{AllowOverride_AuthConfig} eq "1");
    push(@AllowOverride, "Indexes")    if ($web->{AllowOverride_Indexes}    && $web->{AllowOverride_Indexes}    eq "1");
    push(@AllowOverride, "Limit")      if ($web->{AllowOverride_Limit}      && $web->{AllowOverride_Limit}      eq "1");
    push(@AllowOverride, "FileInfo")   if ($web->{AllowOverride_FileInfo}   && $web->{AllowOverride_FileInfo}   eq "1");

    # --- Derive Options=... (comma-separated) for AllowOverride from @Options ---
    my %seen;
    for my $opt (@Options) {
        next if $opt =~ /^\-/;          # skip disabled options
        my $name = $opt;                # IMPORTANT: copy, don't mutate @Options
        $name =~ s/^[+\-]//;            # strip +/- only in the copy
        next if $name eq '' || lc($name) eq 'all';
        $seen{$name} = 1;
    }
    my $options_eq = join(',', sort keys %seen);

    if ($web->{AllowOverride_Options} && $web->{AllowOverride_Options} eq "1") {
        if ($options_eq) {
            push @AllowOverride, "Options=$options_eq";
        }
        else {
            # nothing granular selected but Options allowed
            push @AllowOverride, "Options";
        }
    }

    # --- Finalize AllowOverride: 'All ' (redundancy allowed) or 'None' if empty ---
    my $all_present = '';
    if ($web->{AllowOverride_All} && $web->{AllowOverride_All} eq "1") {
        $all_present = 'All ';
        # (Intentional: keep the specific classes after 'All' if you like the redundancy)
    }
    elsif (scalar(@AllowOverride) == 0) {
        push @AllowOverride, "None";
    }

    my $out_options       = join(" ", @Options);
    my $out_AllowOverride = join(" ", @AllowOverride);

    &debug_msg("RAW \@Options = " . Dumper(\@Options));
    &debug_msg("RAW \@AllowOverride = " . Dumper(\@AllowOverride));

    # --- Compose exactly like your original ---
    $script_conf .= "<Directory /home/.sites/>\n";
    $script_conf .= "Options " . $o_all . $out_options . "\n";
    $script_conf .= "AllowOverride " . $all_present . $out_AllowOverride . "\n";
    $script_conf .= "\n";
    $script_conf .= "# ignore .ht*\n";

    &debug_msg("Setting 'Options' to: Options " . $o_all . $out_options . "\n");
    &debug_msg("Setting 'AllowOverride' to: AllowOverride " . $all_present . $out_AllowOverride . "\n");

    # --- Your original replacement window & rest passthrough ---
    my $last;
    while (<$in>) {
        if (/^Alias \/libImage\/(.*)$/) { $last = $_; last; }

        if (/^$begin$/) {
            while (<$in>) {
                if (/^# ignore \.ht(.*)$/) { last; }
            }
            print $out $script_conf;
        }
        else {
            print $out $_;
        }
    }
    print $out $last if defined $last;

    # preserve the remainder of the config file
    while (<$in>) {
        print $out $_;
    }

    return 1;
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#     notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#     notice, this list of conditions and the following disclaimer in 
#     the documentation and/or other materials provided with the 
#     distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#     contributors may be used to endorse or promote products derived 
#     from this software without specific prior written permission.
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