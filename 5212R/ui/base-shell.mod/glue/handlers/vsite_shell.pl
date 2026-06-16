#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/shell
# $Id: vsite_shell.pl
#
# toggle shell access on and off for all site users when the vsite
#

use CCE;
use Base::User qw(usermod);
use MyShell;
use Base::Vsite qw(vsite_update_site_admin_caps);
use Base::HomeDir qw(homedir_get_group_dir);
use File::Temp;
use Sauce::Service;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
}

my $cce = new CCE;
$cce->connectfd();

my $vsite = $cce->event_object();
my ($ok, $shell) = $cce->get($cce->event_oid(), 'Shell');
if (!$ok) {
    $cce->bye('FAIL', '[[base-shell.cantReadVsiteShell]]');
    exit(1);
}

# if the vsite is suspended or shell is turned off, disable shell for
# all users
my @usermods = ();
my $fail_msg = '';

if ($vsite->{suspend}) {
    $shell->{enabled} = "0";
}

#
### The Shawshank Redemption - Handles Jail creation:
#

&debug_msg("Warden Samuel Norton: The roof of the license-plate factory needs resurfacing. I need a dozen volunteers for a week's work. As you know, special detail carries with it special privileges.\n");

# Note to self: If /dev/log fails to create, then Systemd is fucked and needs a restart:
#system("systemctl restart systemd-journald.socket");
#system("systemctl restart rsyslog");

my $jailpresent = '0';
my $ret = '';

my $site_dir = homedir_get_group_dir($vsite->{name}, $vsite->{volume});  # /home/.sites/siteX
my $home_dir = $site_dir . '/home';                                      # /home/.sites/siteX/home

# Create/Remove both jail trees (they serve different user groups)
&andy_dufresne($site_dir, $shell, $cce, '0');   # SiteAdmin jail
&andy_dufresne($home_dir, $shell, $cce, '1');   # Regular-user jail

&debug_msg("Warden Samuel Norton : If you wanna indulge in this fantasy, that's your business. Don't make it mine. This meeting is over.\n");

# Ensure jk_lsh.ini has DEFAULT + [group <site>] entries for all jailed sites:
update_jk_lsh_ini($cce);

# Ensure /etc/jailkit/jk_socketd.ini has all the proper entries for jailed sites:
update_jk_socketd_ini($cce);

my $jailroot = '/home/.sites/' . $vsite->{name} . '/home';

#
### Fin
#

# enable shell for all users, who have been given shell access
my @users = $cce->find('User', { 'site' => $vsite->{name} });
for my $oid (@users) {
    ($ok, my $user) = $cce->get($oid);
    ($ok, my $user_shell) = $cce->get($oid, 'Shell');
    if (!$ok) {
        &debug_msg("Fail: [[base-shell.cantReadVsiteUser]]\n");
        $cce->bye('FAIL', '[[base-shell.cantReadVsiteUser]]');
        exit(1);
    }

    &debug_msg("Vsite Shell: $shell->{enabled} - User '$user->{name}' Shell: $user_shell->{enabled}\n");

    if ($user_shell->{enabled} gt $shell->{enabled}) {
        &debug_msg("User '$user->{name}' needs Shell privilege reduction from $user_shell->{enabled} to $shell->{enabled}\n");
        ($ok) = $cce->set($oid, 'Shell', { 'enabled' => $shell->{enabled} } );
        if (!$ok) {
            &debug_msg("Fail: [[base-shell.cantmodifyVsiteUserShell]]\n");
            $cce->bye('FAIL', '[[base-shell.cantmodifyVsiteUserShell]]');
            exit(1);
        }

        # IMPORTANT: refresh user_shell after we changed it
        ($ok, $user_shell) = $cce->get($oid, 'Shell');
    }

    # ---- START: jk_jailuser BLOCK ----

    # Only jail for vsite modes that actually imply jails:
    my $want_jail = (($shell->{enabled} eq "1") || ($shell->{enabled} eq "2")) ? 1 : 0;

    if ($want_jail && ($user_shell->{enabled} ne "0")) {

        # Choose jail root based on siteAdmin cap
        my $target_jailroot = ($user->{capLevels} =~ /&siteAdmin&/) ? $site_dir : $home_dir;

        # Map shell mode -> jail shell
        my $jail_shell;
        if ($user_shell->{enabled} eq "1") {
            $jail_shell = $MyShell::LIMITED_SHELL;
        }
        elsif ($user_shell->{enabled} eq "2") {
            $jail_shell = $MyShell::GOOD_SHELL;
        }
        else {
            $jail_shell = undef;   # mode 3 (or anything else) = no jail
        }

        if (defined $jail_shell) {

            # Optional: only run if user entry missing
            my $needs_entry = 1;
            my $jail_passwd = "$target_jailroot/etc/passwd";
            if (-f $jail_passwd) {
                if (open(my $fh, '<', $jail_passwd)) {
                    while (my $l = <$fh>) {
                        if ($l =~ /^\Q$user->{name}\E:/) { $needs_entry = 0; last; }
                    }
                    close($fh);
                }
            }

            if ($needs_entry) {
                &debug_msg("Jailing user '$user->{name}' into '$target_jailroot' with shell '$jail_shell'\n");
                system('/usr/sbin/jk_jailuser', '-n', '-j', $target_jailroot, '-s', $jail_shell, $user->{name});
                my $rc = $? >> 8;
                &debug_msg("jk_jailuser rc=$rc for user '$user->{name}'\n");
            }
            else {
                &debug_msg("User '$user->{name}' already present in $jail_passwd - skipping jk_jailuser\n");
            }
        }
    }

    # ---- END: jk_jailuser BLOCK ----
} # got all site users

# update site admin caps
if (!$cce->event_is_destroy()) {
    vsite_update_site_admin_caps($cce, $vsite, 'siteShell', $shell->{enabled});
}

&debug_msg("Exit: SUCCESS\n");

$cce->bye('SUCCESS');
exit(0);

#
### Subroutines:
#

sub jailtrasher {
    my ($janitor_site_dir, $isHomeJail) = @_;
    &debug_msg("Removing Jail for $janitor_site_dir!\n");
    if (($janitor_site_dir ne "") && ($janitor_site_dir ne "/")) {
        my $binGone = $janitor_site_dir . '/bin';
        my $devGone = $janitor_site_dir . '/dev';
        my $etcGone = $janitor_site_dir . '/etc';
        my $libGone = $janitor_site_dir . '/lib64';
        my $tmpGone = $janitor_site_dir . '/tmp';
        my $usrGone = $janitor_site_dir . '/usr';
        my $runGone = $janitor_site_dir . '/run';
        my $varGone = $janitor_site_dir . '/var/tmp';
        system("rm -Rf $binGone");
        system("rm -Rf $devGone");
        system('rm','-Rf',$etcGone);
        system("rm -Rf $libGone");
        system("rm -Rf $tmpGone");
        system("rm -Rf $usrGone");
        system("rm -Rf $runGone");
        system("rm -Rf $varGone");

        if ($isHomeJail eq '1') {
            my $HomevarGone = $janitor_site_dir . '/var';
            &debug_msg("Removing $HomevarGone\n");
            system("rm -Rf $HomevarGone");
        }
    }
    else {
        &debug_msg("ERROR: $janitor_site_dir was empty or set to '/' - can't allow that!\n");
    }
}

sub get_temp_filename {
    my $fh = File::Temp->new(
        TEMPLATE => 'jailXXXXX',
        DIR      => '/usr/sausalito/jailer',
        SUFFIX   => '.sh',
    );

    return $fh->filename;
}

sub janitor {
    my ($jailroot) = @_;

    # Jailkit expects sections as separate args after -k (space-separated),
    # NOT one comma-separated token.
    my @sections = qw(
        basicshell
        editors
        extendedshell
        netutils
        ssh
        sftp
        scp
        pico
        id
        logbasics
        jk_lsh
    );

    my @cmd = ('/usr/sbin/jk_init', '-j', $jailroot, '-k', @sections);

    my $cmd_str = join(' ', @cmd);
    my $out = `$cmd_str 2>&1`;
    my $rc  = $? >> 8;

    &debug_msg("Running: $cmd_str\n");
    &debug_msg("jk_init rc=$rc output:\n$out\n");

    return ($rc == 0) ? 1 : 0;
}

sub andy_dufresne {
    my ($jailroot, $shell, $cce, $isHomeJail) = @_;

    if (! -d $jailroot) {
        &debug_msg("ERROR: $jailroot is not a directory!\n");
        $cce->bye('FAIL', '[[base-shell.cantReadDir]]');
        exit(1);
    }

    # Determine whether we want a jail
    my $want_jail = (($shell->{enabled} eq "1") || ($shell->{enabled} eq "2")) ? 1 : 0;

    # In YOUR environment, etc/passwd+etc/group are the reliable markers
    my $has_passwd = (-f "$jailroot/etc/passwd") ? 1 : 0;
    my $has_group  = (-f "$jailroot/etc/group")  ? 1 : 0;
    my $jailpresent = ($has_passwd && $has_group) ? 1 : 0;

    &debug_msg("jailroot: $jailroot - Shell: $shell->{enabled} - want_jail=$want_jail - present=$jailpresent (passwd=$has_passwd group=$has_group)\n");

    if ($want_jail) {
        if (!$jailpresent) {
            &debug_msg("Jail should be turned on for $jailroot\n");

            # Ensure ownership/permissions that jk_init expects
            ensure_jailkit_safe_dirs($jailroot);
            #system('chown', 'root:root', $jailroot);
            #system('chmod', '0755', $jailroot);
            #system('chmod', 'g-s',  $jailroot);

            my $ok = janitor($jailroot);

            # Re-check presence using the markers that matter for you
            $has_passwd = (-f "$jailroot/etc/passwd") ? 1 : 0;
            $has_group  = (-f "$jailroot/etc/group")  ? 1 : 0;
            $jailpresent = ($has_passwd && $has_group) ? 1 : 0;

            if ($ok && $jailpresent) {
                &debug_msg("Jail created successfully for $jailroot\n");
            }
            else {
                &debug_msg("ERROR: Jail creation incomplete for $jailroot (ok=$ok passwd=$has_passwd group=$has_group)\n");
                # Optional hard-fail:
                # $cce->bye('FAIL', '[[base-shell.jailCreateFailed]]'); exit(1);
            }
        }
        else {
            &debug_msg("Jail already present for $jailroot\n");
        }
    }
    else {
        &debug_msg("Jail should be turned off for $jailroot\n");
        jailtrasher($jailroot, $isHomeJail);
    }
}

sub ensure_jailkit_safe_dirs {
    my ($jailroot) = @_;

    # jk_init wants the jailroot itself safe:
    system('chown', 'root:root', $jailroot);
    system('chmod', '0755',     $jailroot);
    system('chmod', 'g-s',      $jailroot);

    # If a "var" exists inside this jailroot, jk_init may check it too:
    my $v = "$jailroot/var";
    if (-e $v) {
        if (-d $v) {
            system('chown', 'root:root', $v);
            system('chmod', '0755',     $v);
            system('chmod', 'g-s',      $v);
        }
        else {
            &debug_msg("WARNING: $v exists but is not a directory - leaving it alone\n");
        }
    }
}

# ----------------------------------------------------------------------
# Jailkit jk_lsh.ini maintenance
# We manage only our own block in /etc/jailkit/jk_lsh.ini.
# jk_lsh looks for:
#   - a [<username>] section OR
#   - a [group <primarygroup>] section OR
#   - [DEFAULT]
# Your log shows it found none -> SFTP dies.
# ----------------------------------------------------------------------

sub update_jk_lsh_ini {
    my ($cce) = @_;

    my $cfg = "/etc/jailkit/jk_lsh.ini";

    # Determine which Vsites currently have jailed shell enabled (1 or 2)
    # and are not suspended:
    my @oids = $cce->find('Vsite');
    my @jailed_sites = ();

    foreach my $oid (@oids) {
        my ($ok, $v) = $cce->get($oid);
        next if (!$ok);
        next if ($v->{suspend});

        my ($ok2, $sh) = $cce->get($oid, 'Shell');
        next if (!$ok2);

        # Vsite-level jailed modes:
        if (($sh->{enabled} eq "1") || ($sh->{enabled} eq "2")) {
            push @jailed_sites, $v->{name};   # e.g. "site16"
        }
    }

    # Build our managed block:
    my $managed = build_jk_lsh_managed_block(\@jailed_sites);

    # Read existing file (if any) and strip old managed block:
    my $existing = "";
    if (-f $cfg) {
        if (open(my $fh, '<', $cfg)) {
            local $/;
            $existing = <$fh>;
            close($fh);
        }
    }

    $existing = strip_jk_lsh_managed_block($existing);

    # Ensure file ends with newline
    $existing .= "\n" if ($existing ne "" && $existing !~ /\n\z/);

    my $new = $existing . $managed;

    # Write atomically:
    my $tmp = "$cfg.$$\.tmp";
    if (open(my $out, '>', $tmp)) {
        print $out $new;
        close($out);
        chmod(0644, $tmp);
        rename($tmp, $cfg);
        &debug_msg("Updated $cfg (managed block) for jailed sites: " . join(',', @jailed_sites) . "\n");
    }
    else {
        &debug_msg("ERROR: Could not write temp jk_lsh.ini $tmp: $!\n");
        unlink($tmp);
    }
}

sub build_jk_lsh_managed_block {
    my ($sites_ref) = @_;
    my @sites = @{$sites_ref || []};

    my $out = "";
    $out .= "# --- BlueOnyx managed: BEGIN (do not edit inside this block) ---\n";
    $out .= "# This block is auto-generated by vsite_shell.pl\n";
    $out .= "# It provides [DEFAULT] and [group <site>] sections for jk_lsh\n";
    $out .= "\n";

    # DEFAULT must exist or jk_lsh may refuse to run sftp/scp, etc.
    $out .= "[DEFAULT]\n";
    $out .= "loglevel = 0\n";
    $out .= "allow_word_expansion = 0\n";
    $out .= "umask = 022\n";
    $out .= "paths = /bin, /usr/bin, /usr/libexec/openssh\n";
    $out .= "executables = /usr/libexec/openssh/sftp-server, /usr/bin/scp, /usr/bin/sftp, /usr/bin/ssh, /usr/bin/id, /bin/ls, /bin/pwd\n";
    $out .= "\n";

    # One group section per jailed vsite.
    # NOTE: group name must match the user's primary group inside BlueOnyx (usually the site name, e.g. "site16").
    foreach my $s (@sites) {
        next if (!$s);
        $out .= "[group $s]\n";
        $out .= "include = DEFAULT\n";
        $out .= "\n";
    }

    $out .= "# --- BlueOnyx managed: END ---\n";
    return $out;
}

sub strip_jk_lsh_managed_block {
    my ($txt) = @_;
    $txt ||= "";

    $txt =~ s/# --- BlueOnyx managed: BEGIN.*?# --- BlueOnyx managed: END ---\n?//s;
    return $txt;
}

# ----------------------------------------------------------------------
# Jailkit jk_socketd.ini maintenance
# jk_socketd listens on jail dev/log sockets. If missing, logging inside
# jailed shells can break and you’ll see rate-limit spam.
# ----------------------------------------------------------------------

sub update_jk_socketd_ini {
    my ($cce) = @_;

    my $cfg = "/etc/jailkit/jk_socketd.ini";

    # Build list of socket paths from all active jailed Vsites.
    my @oids = $cce->find('Vsite');
    my @paths = ();

    foreach my $oid (@oids) {
        my ($ok, $v) = $cce->get($oid);
        next if (!$ok);
        next if ($v->{suspend});

        my ($ok2, $sh) = $cce->get($oid, 'Shell');
        next if (!$ok2);

        # Only Vsites with jailed modes:
        next unless (($sh->{enabled} eq "1") || ($sh->{enabled} eq "2"));

        my $site = $v->{name};  # "site16"
        next if (!$site);

        my $p1 = "/home/.sites/$site/dev/log";
        my $p2 = "/home/.sites/$site/home/dev/log";

        # Only include if the directory exists (avoids jk_socketd warnings):
        push @paths, $p1 if (-d "/home/.sites/$site/dev");
        push @paths, $p2 if (-d "/home/.sites/$site/home/dev");
    }

    # De-dup + stable sort:
    my %seen;
    @paths = sort grep { !$seen{$_}++ } @paths;

    # Build COMPLETE file content from scratch
    my $content = "";
    foreach my $p (@paths) {
        $content .= "[$p]\n";
        $content .= "base=512\n";
        $content .= "peak=2048\n";
        $content .= "interval=10\n\n";
    }

    # Check if existing file is broken (for logging)
    if (-f $cfg) {
        my $is_broken = 0;
        eval {
            my $hash = Config::INI::Reader->read_file($cfg);
        };
        if ($@) {
            $is_broken = 1;
            &debug_msg("jk_socketd.ini was broken: $@ - regenerating\n");
        }
        
        # Also check for comments or duplicate sections in existing file
        if (open(my $fh, '<', $cfg)) {
            my %sections = ();
            while (my $line = <$fh>) {
                if ($line =~ /^\s*#/) {
                    $is_broken = 1;
                    &debug_msg("jk_socketd.ini has comments - regenerating\n");
                    last;
                }
                if ($line =~ /^\[(.+)\]$/) {
                    if (exists $sections{$1}) {
                        $is_broken = 1;
                        &debug_msg("jk_socketd.ini has duplicate sections - regenerating\n");
                        last;
                    }
                    $sections{$1} = 1;
                }
            }
            close($fh);
        }
        
        if (!$is_broken) {
            &debug_msg("jk_socketd.ini is valid, regenerating to ensure correctness\n");
        }
    }

    # Write atomically:
    my $tmp = "$cfg.$$\.tmp";
    if (open(my $out, '>', $tmp)) {
        print $out $content;
        close($out);
        chmod(0644, $tmp);
        rename($tmp, $cfg);
        &debug_msg("Updated $cfg with " . scalar(@paths) . " socket entries (complete rebuild)\n");
    }
    else {
        &debug_msg("ERROR: Could not write temp jk_socketd.ini $tmp: $!\n");
        unlink($tmp);
    }

    # Restart jailkit to re-read config
    system('/usr/bin/systemctl', 'restart', 'jailkit.service');
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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
