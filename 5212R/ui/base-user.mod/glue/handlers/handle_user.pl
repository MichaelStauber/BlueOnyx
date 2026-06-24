#!/usr/bin/perl -I/usr/sausalito/perl
#
# $Id: handle_user.pl
#
# unified create/modify script for users.
#

use Sauce::Util;
use Sauce::Config;
use CCE;
use FileHandle;
use File::Path;
use I18n;
use Base::HomeDir qw(
        homedir_get_user_dir homedir_create_user_link
        homedir_setup_admin_home homedir_setup_user_home);
use Base::User qw(system_useradd useradd usermod);
use Digest::SHA qw(sha512_hex);

# debugging flag, set to 1 to turn on logging to STDERR
my $DEBUG = 0;
if ($DEBUG) { 
    use Data::Dumper; 
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

#my %illegal_usernames;

my %illegal_usernames = map { $_ => 1 } qw /
    root bin daemon adm lp sync shutdown halt mail news uucp operator
    games gopher ftp nobody dbus rpm htt nscd vcsa ntp wnn ident canna
    haldaemon rpc named amanda sshd postgres pvm netdump pcap radvd
    quagga mailnull smmsp pegasus apache mailman webalizer xfs cyrus
    radiusd ldap exim mysql fax squid dovecot postfixgdm
    pop alterroot httpd chiliasp qmail share-guest majordomo
    anonymous guest Root Admin ROOT ADMIN
/;

# reserved prefixes for admin access to sites and alternate root accounts
my $illegal_nameprefixs = '(admin-|root-)';

# connect:
my $cce = new CCE(Domain => 'base-user');
$cce->connectfd();

my $i18n = new I18n;

# retrieve info
my $oid = $cce->event_oid();
my $old = $cce->event_old();
my $new = $cce->event_new();
my $obj = $cce->event_object();
my $ftpaccessuser = $cce->event_object();

my ($sysoid) = $cce->find("System");
my ($sysobj);
{
    my $ok;
    ($ok, $sysobj) = $cce->get($sysoid);
}

# step one: validate the data
my $errors = 0;

if (defined($new->{name})) {
    if ($illegal_usernames{$new->{name}}) {
        $cce->baddata($oid, 'name', '[[base-user.userNameAlreadyTaken]]');
        $errors++;
    }
    elsif ($new->{name} =~ /^$illegal_nameprefixs/) {
        $cce->warn('[[base-user.userNameAlreadyTaken]]');
        $errors++;
    }

    my @others = $cce->find("User", { 'name' => $new->{name} });

    #
    # do a check to see if cross checking between system and vsite is
    # necessary for the email aliases fqdn
    #
    my @other_aliases = ();
    if ($new->{site}) {
        # see if this site has the same fqdn as the system
        my ($vs_oid) = $cce->find('Vsite',
                      { 
                        'name' => $new->{site},
                        'hostname' => $sysobj->{hostname},
                        'domain' => $sysobj->{domainname}
                      });
        if ($vs_oid) {
            my ($ok, $vsite) = $cce->get($vs_oid);
            @other_aliases = &find_aliases($new->{name}, $new->{site}, 1, '');
        }
        else {
            @other_aliases = &find_aliases($new->{name}, $new->{site}, 0, '');
        }
    }
    else {
        # no site so check for a Vsite with the same fqdn as the system
        my ($vs_oid) = $cce->find('Vsite',
                      {
                        'hostname' => $sysobj->{hostname},
                        'domain' => $sysobj->{domainname}
                      });
        if ($vs_oid) {
            # site with matching hostname do a cross check
            my ($ok, $vsite) = $cce->get($vs_oid);
            @other_aliases = &find_aliases($new->{name}, '', 1, $vsite->{name});
        }
        else {
            # no match.  just do a normal search
            @other_aliases = &find_aliases($new->{name}, '', 0, '');
        }
    }

    my @mlist = $cce->find("MailList", 
                   {
                        'site' => $new->{site},
                        'name' => $new->{name}
                   });

    if (($#others > 0) || ($#mlist >= 0) || ($#other_aliases > -1)) {
        if ($i18n->getProperty('genUsername', 'base-user') ne 'no') {
            my @suggestions = &gen_usernames($new->{name},  $new->{fullName}, $new->{site});
            my $namelist = join(', ',@suggestions);

            #$cce->warn('userNameSuggest', {'list' => ${namelist}});
            $cce->warn('userNameAlreadyTaken');
        }
        else {
            # generating user names not possible for this locale
            $cce->warn('userNameAlreadyTaken');
        }
        $errors++;
    }

    unless($errors) {
        if ($#other_aliases > -1) {
            $cce->baddata($oid, 'name', '[[base-user.aliasAlreadyTaken]]');
            $errors++;
        }
    }
}

if (defined($new->{sortName})) {
    my $re = $i18n->getProperty("sortNameRegex", "base-user");
    if (($new->{sortName} !~ /$re/) && ($re ne "ERROR")) {
        $cce->baddata($oid, 'sortName', '[[base-user.sortNameField_rule]]');
        $errors++;
    }
}

if (defined($new->{quota})) {
    if ($new->{quota} > 10240000) {
        $cce->baddata($oid, 'quota', '[[base-user.excessivelyLargeQuota]]');
        $errors++;
    }
}

if ($errors) {
    $cce->bye('FAIL');
    exit(1);
}

&debug_msg('data valid');

#
# create or modify the user
# paraphrase: Cobalt::User::user_add
#

#
# set a more permissive umask to make sure directories have the
# intended permissions
#
umask 002;

# build comment:
my $comment = $obj->{fullName} || $obj->{name};

#
# make sure comment gets encoded properly.  trust I18n::encodeString
# to do the right thing.  If it doesn't, fix that function rather than add
# another hack here.  It should encode the string properly based on the
# system-wide locale, so if the locale isn't ja the 'euc' encoding gets ignored.
#
#my $comment = $i18n->encodeString($comment1, 'euc');

# hacky workaround.  some handler is leaving /etc/group.lock around.
if (-e "/etc/group.lock") {
    &debug_msg("Breaking /etc/group.lock\n");
    unlink("/etc/group.lock");
}
if (-e "/etc/passwd.lock") {
    &debug_msg("Breaking /etc/passwd.lock\n");
    unlink("/etc/passwd.lock");
}

my $hu_user = { 'comment' => $comment }; 

# do we create or modify the user?
my $username = $old->{name} || $obj->{name};
$hu_user->{name} = $username;

# does user already exist?
my @pwent = getpwnam($username);

# select home directory if creating or site is changing
my $alt_root = $obj->{volume};
my $homedir = '';
if (!scalar(@pwent) || exists($new->{site})) {

    $homedir = homedir_get_user_dir($obj->{name}, $obj->{site}, $alt_root);

    # set homedir since it has changed
    $hu_user->{homedir} = $homedir;
}
else {
    # fill in $homedir for use later just in case
    $homedir = $pwent[7];
}

#
# notice that the shell doesn't get set here, this gets handled by
# base-shell.mod and PWDB will default the shell to /bin/false anyways
#

#
# the user's group affiliation only needed on create since we never
# change a user's inital group
#
if (!scalar(@pwent)) {
    $hu_user->{group} = 'users';
}

# set up password if necessary
my ($crypt_pw, $md5_pw) = ('', '');
if (defined($new->{password})) {
    ($crypt_pw, $md5_pw) = cryptpw($new->{password});
    $hu_user->{password} = $md5_pw;
}
elsif (defined($new->{md5_password})) { 
    $hu_user->{password} = $new->{md5_password}; 
} 
        
if (!@pwent) {

    # create
    $hu_user->{skel} = &find_user_skel(I18n::i18n_getSystemLocale());

    #
    # for addition, system administrators get special cased
    # so they end up in /etc/passwd and not the db, just in case
    #

    &debug_msg(Dumper(\$hu_user));

    my $ret = 0;
    if ($obj->{systemAdministrator}) {
        ($ret) = system_useradd($hu_user);
    }
    else {
        ($ret) = useradd($hu_user);
    }

    if (not $ret) {
        &debug_msg("useradd failed!");
        if (-d $hu_user->{homedir}) {
            &debug_msg("homedir already exists. Deleting $hu_user->{homedir}");
            system("rm -Rf $hu_user->{homedir}");
        }
        $cce->bye('FAIL');
        exit(1);
    }
    else {
        &debug_msg("useradd worked!");
    }
}
else {
    # modify
    if ($old->{name}) {
        $hu_user->{oldname} = $old->{name};
        $hu_user->{name} = $obj->{name};
    }

    # usermod works for users in flat files or db
    #if ((usermod($hu_user))[0] != 1) {
    #    &debug_msg("usermod failed!");
    #    $cce->bye('FAIL');
    #    exit(1);
    #}

    ####
    my ($success, $bad_users, $err, $errmsg) = usermod($hu_user);
    &debug_msg("Result: $success - $err - $errmsg");

    if ($success != 0) {
        &debug_msg("Failed usermod reported in handle_user.pl\n");
        $cce->bye('FAIL', $errmsg);
        exit(1);
    }
    ####

}

&debug_msg("obj->{name} $obj->{name} created or modified");

#my ($dir_link, $link_target) = homedir_create_user_link($obj->{name}, $obj->{site}, $alt_root);
#&debug_msg("linking $link_target, $dir_link\n");
#Sauce::Util::linkfile($link_target, $dir_link);

&check_for_stupid_file('A');

# verify that nothing went wrong, read uid and gid
setpwent;
my ($uid, $gid);
{
    my ($username, $cryptpw, $quota, $comment, $gcos, $dir);
    my ($shell, $expire);
    ($username, $cryptpw, $uid, $gid, $quota, $comment, $gcos, $dir, $shell, $expire) = getpwnam($obj->{name});
    if (!$username) {
        # could not create user, fail
        &debug_msg("could not create user, fail");
        if (-d $hu_user->{homedir}) {
            &debug_msg("homedir already exists. Deleting $hu_user->{homedir}");
            system("rm -Rf $hu_user->{homedir}");
        }
        $cce->warn('[[base-user.failed-to-add-user,name=${username}]]');
        $cce->bye('FAIL');
        exit(1);
    }
}

# figure out which group all the files and dirs should be owned by
my $owner_gid = ($obj->{site} ? (getgrnam($obj->{site}))[2] : $gid);

#
# make sure the user owns there symlink or apache complains
# can't use perl chown, because it dereferences the symlink
#
&debug_msg("dir link is $dir_link");
system('/bin/chown', '-h', "$uid:$gid", $dir_link);
system("rm -Rf $hu_user->{homedir}/web");
Sauce::Util::addrollbackcommand("/bin/chown -h $pwent[2]:$pwent[3] $dir_link");

&debug_msg("$obj->{name} exists");

&check_for_stupid_file('C');

if (scalar(@pwent) == 0) {
    # if this is a create, setup the user's home directory
    if ($obj->{systemAdministrator} || 
        ($obj->{capLevels} =~ /&adminUser&/)) {
        &debug_msg("Creating home directory for User $obj->{name}");
        homedir_setup_admin_home({
                        'name' => $obj->{name},
                        'gid' => $owner_gid
                     });
    }
    else {
        &debug_msg("Creating home directory for User $obj->{name}");
        homedir_setup_user_home({
                        'name' => $obj->{name},
                        'gid' => $owner_gid
                    });
    }
}

# handle user un/suspend
if (defined($new->{enabled})) {
    &debug_msg("Handling Suspend state for User $obj->{name}");
    if ($new->{enabled}) {
        # FIXME: this assumes raq style dir permissions - no longer. We now use proper ones for 5210R:
        #Sauce::Util::chmodfile(($obj->{site} ? 02771 : 0700), $homedir);
        Sauce::Util::chmodfile(($obj->{site} ? 0700 : 0700), $homedir);
    }
    else {
        Sauce::Util::chmodfile(0000, $homedir);
    }
}

# If this is the admin user, update the locale file that is used by the lcd
if (($obj->{name} eq "admin") && $new->{localePreference} && ($new->{localePreference} ne "browser")) {
    Sauce::Util::modifyfile("/usr/sausalito/locale");
    open(LOCALEFILE, ">/usr/sausalito/locale");
    print LOCALEFILE "$new->{localePreference}";
    close(LOCALEFILE);

    # Set system "productLanguage" to match
    $cce->set($sysoid, "", { productLanguage => $new->{localePreference} });
}

# update password
if (defined($new->{password})) {
    if (!($new->{password})) {
        $crypt_pw = '*';
        $md5_pw = '*';
    }
    &debug_msg("Set password for User $obj->{name}");
    $cce->set($oid, "", 
          { 
            crypt_password => $crypt_pw,
            md5_password => $md5_pw 
          });
    &debug_msg("Password for User $obj->{name} set.");
}
else {
    if ($cce->event_is_create()) {
        # create dummy passwords
        &debug_msg("Set dummy password during create.");
        $cce->set($oid, "", 
              {
                crypt_password => '*',
                md5_password => '*',
              });
        &debug_msg("Password for User $obj->{name} set.");
    }
}

# update workgroups
if (defined($old->{name}) && defined($new->{name})) {
    &debug_msg("Updating Workgroups");
    &update_workgroups();
}

# Handle User FTP access:
&debug_msg("Handle FTP-Access for User $obj->{name}");
&ftpaccess;

# all done
$cce->bye("SUCCESS");
exit(0);

# helper functions below here

# that darn cat!
sub check_for_stupid_file {
    my $checkpoint = shift;
    
    if (-e '/etc/group.lock') {
        unlink ('/etc/group.lock');
        &debug_msg("At checkpoint $checkpoint, ");
        &debug_msg("/etc/group.lock was found.\n");
    }
    if (-e "/etc/passwd.lock") {
        &debug_msg("Breaking /etc/passwd.lock\n");
        unlink("/etc/passwd.lock");
    }
}

sub update_workgroups {
    my @oids = $cce->find("Workgroup", { 'members' => $old->{name} });
    foreach my $oid (@oids) {
        my ($ok, $obj) = $cce->get($oid);
        my (@members) = $cce->scalar_to_array($obj->{members});
        @members = grep {$_ ne $old->{name}} @members;
        if (defined($new->{name})) {
            push(@members, $new->{name});
        }
        $cce->set($oid, "", { 'members' => $cce->array_to_scalar(@members) });
    }
}

sub cryptpw {
    my $pw = shift;
    my @saltchars = ('a'..'z', 'A'..'Z', 0..9);
    
    # Generate a salt for DES-based hash
    my $salt1 = sel(@saltchars) . sel(@saltchars);
    my $crypt_pw = crypt($pw, $salt1);
    
    # Generate a salt for SHA-512 hash
    my $salt2 = '$6$';
    for (my $i = 0; $i < 16; $i++) { $salt2 .= sel(@saltchars); }
    $salt2 .= '$';
    
    # Hash the password using SHA-512
    my $sha512_pw = crypt($pw, $salt2);
    
    return ($crypt_pw, $sha512_pw);
}

sub sel {
    return $_[int(rand(@_))];
}

sub fail() {
    $cce->bye("FAIL");
    exit(1);
}

sub mysystem {
    #if ($DEBUG) {
    #    print STDERR "running: ", join(" ", map { "\"$_\"" } @_), "\n";
    #}
    system(@_);
    return $?;
}

#
# From the fullname, render a few possible usernames, testing to
# verify none are in use.
#
sub gen_usernames {
    my $name = shift;
    my $fullname = shift;
    my $site = shift;
    my @fn = split(/\s+/, $fullname);

    #
    # test to see if a cross-check of shared hostnames between the system 
    # and a site is necessary for email aliases.  Do this external to
    # the find_aliases function, so that it only happens once.
    #
    my $cross_check = 0;
    my $cross_check_site = '';
    if ($site ne '') {
        # get the site and see if the fqdn matches the system
        my ($vs_oid) = $cce->find('Vsite', { 'name' => $site });
        my ($ok, $vsite) = $cce->get($vs_oid);
        my ($soid) = $cce->find('System',
                    {
                        'hostname' => $vsite->{hostname},
                        'domainname' => $vsite->{domain}
                    });
        if ($soid) {
            $cross_check = 1;
        }
    }
    else {
        #
        # no site.  see if there is a vsite with the same fqdn as
        # the system.
        #
        my ($soid) = $cce->find('System');
        my ($ok, $sys) = $cce->get($soid);
        my ($vs_oid) = $cce->find('Vsite',
                      {
                        'hostname' => $sys->{hostname},
                        'domain' => $sys->{domainname}
                      });
        ($ok, my $vsite) = $cce->get($vs_oid);
        if ($vs_oid) {
            $cross_check = 1;
            $cross_check_site = $vsite->{name};
        }
    }

    my @usernames;

    # firstname
    if ($fn[0]) {
        push(@usernames, $fn[0]);
    }

    # lastname
    if ($fn[$#fn]) {
        push(@usernames, $fn[$#fn]);
    }

    # first-hyphen-last
    my ($joint, $un, @keepers, %unique);

    foreach $joint ('.', '-') {
        if (join($joint, @fn) =~ /.+/) {
            unless ($unique{join($joint, @fn)}) {
                push(@usernames, join($joint, @fn));
            }
        }
    }

    foreach $un (@usernames) {
        $un = substr($un, 0, 12);
        $un =~ tr/[A-Z]/[a-z]/;
        my @uoids = $cce->find('User', { 'name' => $un });
        my @alias_oids = &find_aliases($un, $site, $cross_check, $cross_check_site);
        if (!$uoids[0] && !$unique{$un} && !scalar(@alias_oids)) {
            push(@keepers, $un);
        }
        $unique{$un} = 1;
    }

    # usernameN (appended natural number)
    my $index = 1;
    while ($#keepers < 1) {
        $un = substr($name, 0, 12 - length($index)) . $index;
        my @uoids = $cce->find('User', { 'name' => $un });

        unless ($uoids[0] || $unique{$un}) {
            push(@keepers, $un);
            $unique{$un} = 1; 
        }

        $un = substr($fn[0], 0, 12 - length($index)) . $index;
        @uoids = $cce->find('User', { 'name' => $un });
        my @alias_oids = &find_aliases($un, $site, $cross_check, $cross_check_site);
        if (!$uoids[0] && !$unique{$un} && !scalar(@alias_oids)) {
            push(@keepers, $un);
            $unique{$un} = 1; 
        }

        $index++;
    }

    return (@keepers);
}

#
# search for email aliases that conflict with the given username
# returns the list of all oids of conflicting aliases
#
sub find_aliases {
    my ($username, $site, $cross_check, $cross_check_site) = @_;

    # start with normal aliases
    my @aliases = $cce->find('EmailAlias', { 'alias' => $username, 'site' => $site });
    # search for protected aliases
    push @aliases, $cce->find('ProtectedEmailAlias', { 'alias' => $username, 'site' => $site });

    #
    # check for cross conflict between a Vsite and the System if they
    # share hostnames
    #
    if ($cross_check) {
        push @aliases, $cce->find('EmailAlias',
                      {
                        'alias' => $username,
                        'site' => $cross_check_site
                      });
        push @aliases, $cce->find('ProtectedEmailAlias',
                      { 
                                                'alias' => $username,
                                                'site' => $cross_check_site
                      });
    }

    return @aliases;
}

#
# find the skeleton directory for new users
# takes care of problems where system locale is xx_XX and xx_XX doesn't
# exist, but xx does
#
sub find_user_skel {
    my $locale = shift;
    
    my $skel_base = '/etc/skel/user';

    if (-d "$skel_base/$locale") {
        # if there is an explicit match, let them have it
        return "$skel_base/$locale";
    }
    
    # check if a broader version of the locale exists
    my $broad_locale = $locale;
    if (($broad_locale =~ s/_.+$//) && (-d "$skel_base/$broad_locale")) {
        return "$skel_base/$broad_locale";
    }

    #locale not found
    return '';
}

sub ftpaccess {

    # Find the home directories of the respective users:
    $homedir = '';
    $homedir = homedir_get_user_dir($ftpaccessuser->{name}, $ftpaccessuser->{site}, $ftpaccessuser->{volume});

    $ftpDisabled = $ftpaccessuser->{ftpDisabled};
    $ftpaccessusername = $ftpaccessuser->{name};
    $site = $ftpaccessuser->{site};

    if ($ftpDisabled eq '1') {
        # Do the file actions. Add the .ftpaccess files to the user directory:
        if ($homedir =~ /^\/.+/) {
            if (-d "$homedir") {
                system("/bin/echo '<Limit RAW LOGIN READ WRITE DIRS ALL>\nDenyAll\n</Limit>\n' > $homedir/.ftpaccess");
            }
        }
    }
    else {
        # Remove the .ftpaccess file from this user's home directory:
        system("/bin/rm -f $homedir/.ftpaccess");
    }
}

sub debug_msg {
    if ($DEBUG) {
    my $msg = shift;
    $DEBUG && print STDERR "$ARGV[0]: ", $msg, "\n";

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