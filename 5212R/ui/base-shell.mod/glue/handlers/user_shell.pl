#!/usr/bin/perl -w -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/shell
# $Id: user_shell.pl
#
# toggle shell access on or off for user
#

use CCE;
use Base::User qw(usermod);
use MyShell;
use Base::HomeDir qw(homedir_get_user_dir);
use Unix::PasswdFile;

# Debugging switch:
our $DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
}

my $cce = new CCE;
$cce->connectfd();

&debug_msg("Available: $MyShell::BAD_SHELL $MyShell::GOOD_SHELL $MyShell::SUSPEND_SHELL $MyShell::LIMITED_SHELL $MyShell::JAIL_SHELL\n");

my $user = $cce->event_object();
my $site_len = length($user->{site} // '');

# run on create, so make sure we have the info needed
if ($cce->event_is_create() && !scalar(getpwnam($user->{name}))) {
    $cce->bye('DEFER');
    exit(0);
}

# Get the user's standard homedir:
my $alt_root = $user->{volume};
my $homedir  = homedir_get_user_dir($user->{name}, $user->{site}, $alt_root);

my ($ok, $shell, $shell_old, $shell_new) = $cce->get($cce->event_oid(), 'Shell');
if (!$ok) {
    &debug_msg("FAIL: [[base-shell.cantReadUserShell]]\n");
    $cce->bye('FAIL', '[[base-shell.cantReadUserShell]]');
    exit(1);
}

&debug_msg("User $user->{name} homedir: $homedir\n");

# ------------------------------------------------------------
# Jail detection (ONLY if user is in a Vsite)
# ------------------------------------------------------------

my $has_site = ($user->{site} && $site_len ne "0") ? 1 : 0;

my $sitebase        = $has_site ? ('/home/.sites/' . $user->{site}) : '';
my $shortjaildir    = '';
my $jailpasswdfile  = '';
my $jailgroupfile   = '';
my $jail_home_in_jail = '';

if (!$has_site) {
    &debug_msg("User $user->{name} is NOT member of a Vsite (site_len=$site_len) - NO JAIL.\n");

    # If someone tries to enable jailed modes for a non-site user, clamp to non-jailed:
    if (($shell->{enabled} eq "1") || ($shell->{enabled} eq "2")) {
        &debug_msg("Requested jailed shell mode ($shell->{enabled}) for non-Vsite user; clamping to non-jailed mode 3.\n");
        $shell->{enabled} = "3";
    }
}
else {
    # We have TWO jails:
    #   1) siteAdmin jailroot:      /home/.sites/<site>
    #      -> home inside jail:     /home/users/<name>
    #   2) regular-user jailroot:   /home/.sites/<site>/home
    #      -> home inside jail:     /users/<name>

    my $is_siteadmin = ($user->{capLevels} =~ /&siteAdmin&/) ? 1 : 0;

    my $site_jailroot_exists = (-d "$sitebase/etc")      ? 1 : 0;  # /home/.sites/<site>/etc
    my $user_jailroot_exists = (-d "$sitebase/home/etc") ? 1 : 0;  # /home/.sites/<site>/home/etc

    if ($is_siteadmin) {
        if ($site_jailroot_exists) {
            $shortjaildir = $sitebase;
            &debug_msg("User $user->{name} is a siteAdmin -> using siteAdmin jailroot: $shortjaildir\n");
        }
        elsif ($user_jailroot_exists) {
            $shortjaildir = "$sitebase/home";
            &debug_msg("WARN: siteAdmin jailroot missing ($sitebase/etc). Falling back to user jailroot: $shortjaildir\n");
        }
        else {
            $shortjaildir = "$sitebase/home";
            &debug_msg("WARN: No jail roots detected under $sitebase. Defaulting to: $shortjaildir\n");
        }
    }
    else {
        if ($user_jailroot_exists) {
            $shortjaildir = "$sitebase/home";
            &debug_msg("User $user->{name} is NOT a siteAdmin -> using user jailroot: $shortjaildir\n");
        }
        elsif ($site_jailroot_exists) {
            $shortjaildir = $sitebase;
            &debug_msg("WARN: user jailroot missing ($sitebase/home/etc). Falling back to siteAdmin jailroot: $shortjaildir\n");
        }
        else {
            $shortjaildir = "$sitebase/home";
            &debug_msg("WARN: No jail roots detected under $sitebase. Defaulting to: $shortjaildir\n");
        }
    }

    # Now ONLY compute jail paths if we have a jailroot:
    if ($shortjaildir && -d "$shortjaildir/etc") {
        $jailpasswdfile = "$shortjaildir/etc/passwd";
        $jailgroupfile  = "$shortjaildir/etc/group";

        # Home INSIDE the chroot depends on which jailroot we chose:
        $jail_home_in_jail = ($shortjaildir eq $sitebase)
            ? "/home/users/$user->{name}"
            : "/users/$user->{name}";
    }

    &debug_msg("User $user->{name} jailroot: $shortjaildir - jail_home_in_jail: $jail_home_in_jail - passwd: $jailpasswdfile - group: $jailgroupfile\n");
}

# ------------------------------------------------------------
# Verify vsite permissions (ONLY if has_site)
# ------------------------------------------------------------
my $vsite_shell = { enabled => "3" };  # default: no restriction
my $vsite_PHP   = {};
my $ok_php      = 1;

if ($has_site) {
    my ($site_oid) = $cce->find('Vsite', { 'name' => $user->{site} });
    ($ok, $vsite_shell) = $cce->get($site_oid, 'Shell');
    ($ok_php, $vsite_PHP) = $cce->get($site_oid, 'PHP');

    if ((!$ok) || (!$ok_php)) {
        $cce->bye('FAIL', '[[base-shell.cceError]]');
        exit(1);
    }

    # only worry if they are trying to toggle from off to on for user shell
    if (($shell_new->{enabled}) && ($vsite_shell->{enabled} eq "0")) {
        $cce->bye('FAIL', '[[base-shell.cantEnableNoVsiteShell]]');
        exit(1);
    }
}

# ------------------------------------------------------------
# Handle PHP-FPM stop/start (as in your original)
# ------------------------------------------------------------
my %known_php_services = (
    'PHPOS' => 'php-fpm',
    'PHP53' => 'php-fpm-5.3',
    'PHP54' => 'php-fpm-5.4',
    'PHP55' => 'php-fpm-5.5',
    'PHP56' => 'php-fpm-5.6',
    'PHP70' => 'php-fpm-7.0',
    'PHP71' => 'php-fpm-7.1',
    'PHP72' => 'php-fpm-7.2',
    'PHP73' => 'php-fpm-7.3',
    'PHP74' => 'php-fpm-7.4',
    'PHP80' => 'php-fpm-8.0',
    'PHP81' => 'php-fpm-8.1',
    'PHP82' => 'php-fpm-8.2',
    'PHP83' => 'php-fpm-8.3',
    'PHP84' => 'php-fpm-8.4',
    'PHP85' => 'php-fpm-8.5',
    'PHP86' => 'php-fpm-8.6',
    'PHP90' => 'php-fpm-9.0',
    'PHP91' => 'php-fpm-9.1',
    'PHP92' => 'php-fpm-9.2',
    'PHP93' => 'php-fpm-9.3',
    'PHP94' => 'php-fpm-9.4',
);

my $need_to_handle_FPM = "0";
my $php_fpm_service    = '';

if ($has_site && exists $vsite_PHP->{'prefered_siteAdmin'} && $vsite_PHP->{'prefered_siteAdmin'}) {
    if (exists $vsite_PHP->{'fpm_enabled'} && $vsite_PHP->{'fpm_enabled'}) {
        if (($vsite_PHP->{'fpm_enabled'} eq "1") && ($vsite_PHP->{'prefered_siteAdmin'} eq $user->{name})) {
            &debug_msg("Vsite uses PHP-FPM (" . $known_php_services{$vsite_PHP->{'version'}} . ") and User '" . $user->{name} .  "' is siteAdmin who owns /web!\n");
            $need_to_handle_FPM = "1";
            $php_fpm_service = $known_php_services{$vsite_PHP->{'version'}};
            &debug_msg("Stopping $php_fpm_service now ...\n");
            system("systemctl stop $php_fpm_service");
        }
        else {
            &debug_msg("Vsite uses PHP-FPM (" . $known_php_services{$vsite_PHP->{'version'}} . ") and User '" . $user->{name} .  "' is NOT siteAdmin who owns /web\n");
        }
    }
}

# ------------------------------------------------------------
# Decide shells/homedir
# ------------------------------------------------------------
my $fail_msg  = '';
my $new_shell = $MyShell::BAD_SHELL;
my $jail_shell = $MyShell::GOOD_SHELL;

if (($user->{enabled} eq "0") || ($shell->{enabled} eq "0") || ($vsite_shell->{enabled} eq "0")) {
    $fail_msg = '[[base-shell.cantDisableUserShell]]';

    if (!$user->{enabled}) {
        $new_shell = $MyShell::SUSPEND_SHELL;
        $jail_shell = $MyShell::GOOD_SHELL;
        $user->{jailhomedir} = $homedir;
        &debug_msg("Case 1A\n");
    }
    else {
        $new_shell = $MyShell::BAD_SHELL;
        $jail_shell = $MyShell::GOOD_SHELL;
        $user->{jailhomedir} = $homedir;
        &debug_msg("Case 1B\n");
    }
}
elsif ($shell->{enabled} eq "1") {
    $fail_msg  = '[[base-shell.cantEnableUserShell]]';
    $new_shell = $MyShell::JAIL_SHELL;
    $jail_shell = $MyShell::LIMITED_SHELL;
    $user->{jailhomedir} = ($has_site ? $jail_home_in_jail : $homedir);
    &debug_msg("Case 2\n");
}
elsif ($shell->{enabled} eq "2") {
    $fail_msg  = '[[base-shell.cantEnableUserShell]]';
    $new_shell = $MyShell::JAIL_SHELL;
    $jail_shell = $MyShell::GOOD_SHELL;
    $user->{jailhomedir} = ($has_site ? $jail_home_in_jail : $homedir);
    &debug_msg("Case 3\n");
}
elsif ($shell->{enabled} eq "3") {
    $fail_msg  = '[[base-shell.cantEnableUserShell]]';
    $new_shell = $MyShell::GOOD_SHELL;
    $jail_shell = $MyShell::GOOD_SHELL;
    $user->{jailhomedir} = $homedir;
    &debug_msg("Case 4\n");
}

# Special case for 'admin' and other 'systemAdministrator' users:
&debug_msg("user->{systemAdministrator}: $user->{systemAdministrator} - user->{site}: $user->{site} - user->{enabled}: $user->{enabled} - shell->{user}: $shell->{user} - shell->{enabled}: $shell->{enabled} - user->{name}: $user->{name}\n");
if (($site_len eq "0") && ($shell->{enabled} eq "1")) {
    $fail_msg  = '[[base-shell.cantEnableServerAdminShell]]';
    $new_shell = $MyShell::GOOD_SHELL;
    $jail_shell = $MyShell::GOOD_SHELL;
    $user->{jailhomedir} = $homedir;
    &debug_msg("Case 5\n");
}

&debug_msg("new shell is $new_shell\n");

my $changeUser = {
    'name'       => $user->{name},
    'jailhomedir'=> $user->{jailhomedir},
    'shell'      => $new_shell
};

my ($success, $bad_users, $err, $errmsg) = usermod($changeUser);
&debug_msg("Result: $success - $err - $errmsg\n");

# Restart PHP-FPM if required:
if ($need_to_handle_FPM) {
    &debug_msg("Starting $php_fpm_service again ...\n");
    system("systemctl start $php_fpm_service");
}

if ($success != 0) {
    &debug_msg("Failed usermod reported in user_shell.pl\n");
    $cce->bye('FAIL', $errmsg);
    exit(1);
}

# ------------------------------------------------------------
# Jail user (ONLY if has_site and jailed mode)
# ------------------------------------------------------------
if (($user->{systemAdministrator} eq "1") || (!$has_site)) {
    &debug_msg("Skipping jail modification for user $user->{name} (systemAdministrator=$user->{systemAdministrator}, has_site=$has_site)\n");
}
else {
    if (($shell->{enabled} eq "1") || ($shell->{enabled} eq "2")) {

        &debug_msg("Successful usermod, now fixing jails ...\n");
        &debug_msg("Running: /usr/sbin/jk_jailuser -n -j $shortjaildir -s $jail_shell $user->{name}\n");

        system('/usr/sbin/jk_jailuser', '-n', '-j', $shortjaildir, '-s', $jail_shell, $user->{name});
        my $jk_rc = $? >> 8;
        &debug_msg("jk_jailuser rc=$jk_rc for user $user->{name}\n");

        # Wait briefly for passwd file if needed
        if (! -f $jailpasswdfile) {
            &debug_msg("Jailpasswdfile $jailpasswdfile NOT yet present - waiting...\n");
            sleep(5);
        }

        if (-f $jailpasswdfile) {
            &debug_msg("Unix::PasswdFile: modifying jailpasswdfile: $jailpasswdfile\n");
            eval {
                my $pw = Unix::PasswdFile->new($jailpasswdfile);
                $pw->shell($user->{name}, $jail_shell);
                $pw->home($user->{name}, $jail_home_in_jail);
                $pw->commit();
                undef $pw;
            };
            if ($@) {
                &debug_msg("Error in Unix::PasswdFile: $@\n");
            }
            else {
                &debug_msg("Updated jail passwd entry for $user->{name}: home=$jail_home_in_jail shell=$jail_shell\n");
            }
        }
        else {
            &debug_msg("ERROR: jail passwd file still missing: $jailpasswdfile (cannot adjust home/shell inside jail)\n");
        }

        # Re-apply to ensure NSS/user state consistent with changeUser struct
        my ($success2, $bad_users2, $err2, $errmsg2) = usermod($changeUser);
        &debug_msg("Result from final usermod: $success2 - $err2 - $errmsg2\n");
    }
}

$cce->bye('SUCCESS');
exit(0);

# ------------------------------------------------------------
# Subroutines
# ------------------------------------------------------------
sub debug_msg {
    return unless $DEBUG;
    my $msg = shift;
    setlogsock('unix');
    openlog($0,'','user');
    syslog('info', "$ARGV[0]: $msg");
    closelog;
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