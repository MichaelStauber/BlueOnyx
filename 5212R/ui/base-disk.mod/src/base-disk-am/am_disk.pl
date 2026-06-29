#!/usr/bin/perl
# $Id: am_disk.pl
#
# Email is sent to admin for everyone who is over their quota 
# at 4am. 
# 
# Email is sent to users the instant they are over their quota,
# at most once a day.
#

my $DEBUG = 0;

use lib qw( /usr/sausalito/perl /usr/sausalito/perl/Base);
use AM::Util;
use I18n;
use Disk;
use CCE;
use SendEmail;
use MIME::Lite;
use Sys::Hostname;
use Base::CustomPasswdFile;
use Base::CustomGroupFile;
use Base::HomeDir;
use Sys::Syslog qw(:DEFAULT setlogsock);
use Data::Dumper;

# Get hostname:
my $host = hostname();

my $object;
my $i18n = new I18n();

my $cce = new CCE;
$cce->connectuds();

my @sysoid = $cce->find ('System');
my ($ok, $sysobj) = $cce->get($sysoid[0]);
my $system_lang = $sysobj->{productLanguage};
my $platform = $sysobj->{productBuild};
my $locale = "";
my $l1_oid = "";
my $l2_oid = "";

if (!$system_lang) {
    $i18n->setLocale("en_US");
}
else {
    $i18n->setLocale($system_lang);
} 

my $homeDir = $Base::HomeDir::HOME_ROOT; # Get the location of user and group directories
my $realquotas = disk_has_quota_main($homeDir); # Determines if we have real quota support or need to use find/stat
debug_msg("HomeDir: $homeDir \n");
debug_msg("Real Quota-Support: $realquotas \n");
my ($pw, $gr);

if (!defined($ENV{red_free})) {
    $ENV{red_free} = 100;
}

if (!defined($ENV{red_pcnt})) {
    $ENV{red_pcnt} = 95;
}

if (!defined($ENV{yellow_free})) {
    $ENV{yellow_free} = 125;
}

if (!defined($ENV{yellow_pcnt})) {
    $ENV{yellow_pcnt} = 90;
}

if (!defined($ENV{root_thresh})) {
    $ENV{root_thresh} = 500000;
}

# only send email to admin around 4am
my ( $null, $minutes, $hour) = localtime;
my $time_to_send_admin_mail = 0;
if ($hour == 4 && $minutes < 15) {
    $time_to_send_admin_mail = 1;
}

# Always send email when debugging:
if ($DEBUG) { 
    $time_to_send_admin_mail = 1; 
}

my %am_states = am_get_statecodes();

my $ret;

# check if root is getting filled. if so, suspend CCE. if not, restore it.
open(DF, "/bin/df -lP / |");
while (<DF>) {
    if (/^\/dev\//) {
        my ($device, $size, $used, $avail, $percent, $mount) = split(/ +/);
        set_disks_refresh();
        if ($ENV{suspend_cce} && $avail < $ENV{root_thresh}) {
            $ret = system(( '/usr/sausalito/sbin/cce_lock.pl', '--lock', '--reason=[[base-disk.suspended_cce]]'));
            print "[[base-disk.suspended_cce]]";
            exit $am_states{AM_STATE_RED};
        }
        else {
            $ret = system(( '/usr/sausalito/sbin/cce_lock.pl', '--sync', '--reason=[[base-backupcontrol.locked]]'));
        }
    }
}
close(DF);

my @dev_warnings = ();

# get mounts from df | grep "^/dev/"
# check if device is readwrite in /proc/mounts
# if more than $red_percent used or less than $red_free available, then red
# if more than $yellow_percent used or less than $yellow_free available, then yellow
my ($rw, $dev_status, $worst_dev_status, $server_status);
$worst_dev_status = $am_states{AM_STATE_GREEN};

open (DF, "/bin/df -lP |");
while (<DF>) {
    chomp;
    if (/^\/dev\//) {
        my ($device, $size, $used, $avail, $percent, $mount) = split(/ +/);
        $dev_status = $am_states{AM_STATE_GREEN};

        my $rw = `grep "^$device" /etc/mtab | grep '\\brw\\b'`;
        if (!$rw) {
            next;
        }

        $percent =~ s/%//;
        $avail = $avail / 1024; # AM thresholds are in megs, df is in kbytes
        $size = $size / 1024;

        # RED: either very full in %, or low free on a sufficiently large FS
        if (($percent > $ENV{red_pcnt}) || (($avail < $ENV{red_free}) && ($size > $ENV{red_free} * 2))) {
            $dev_status = $am_states{AM_STATE_RED};
            push @dev_warnings, "[[base-disk.amDiskWarning,fs=\"$mount\",pcnt=\"$percent\",free=\"$avail\"]]"; 
        }
        elsif (($percent > $ENV{yellow_pcnt}) || (($avail < $ENV{yellow_free}) && ($size > $ENV{yellow_free} * 2))) {
            push @dev_warnings, "[[base-disk.amDiskWarning,fs=\"$mount\",pcnt=\"$percent\",free=\"$avail\"]]"; 
            $dev_status = $am_states{AM_STATE_YELLOW};
        }

        $worst_dev_status = $worst_dev_status > $dev_status ? $worst_dev_status : $dev_status;
    }
}
close(DF);

$server_status = $worst_dev_status;

my ($am_oid) = $cce->findx('ActiveMonitor');
my ($okx, $am) = $cce->get($am_oid, 'Disk');

my $now = time;

# ALGORITHM
# use Quota.pm to find users over quota
# if they are newly over or it's been a day since we last emailed, then email them.
# record over_quota status every time
# record lastmailed time if we've mailed them

# use Quota.pm to find sites over quota
# if they are over quota, then send mail to admin if necessary
# record user_over_quota status on vsite if one of it's users is over quota
# record lastmailed time if we've mailed the admin about the site

#
### Get Quota for all Users and Groups:
#

if ($realquotas) {
    debug_msg("Getting all quotas via 'repquota'\n");

    # Get all quotas via 'repquota' from the 'quota' RPM. Needs to be v4.0.4 
    # or better as the '-O csv' has only recently been added.
    $raw_userquota = `LC_ALL=C /usr/sbin/repquota -au -O csv|grep -v ^#|grep -v ^User`;
    chomp $raw_userquota;
    @uq = split /\n/, $raw_userquota;
    foreach my $x (@uq) {
        chomp($x);
        next if $x =~ /^\s*$/;               # skip blank lines
        my (@row) = split (/,/, $x);
        $USERQUOTA{$row[0]} = { 'BlockUsed' => $row[3], 'BlockSoftLimit' => $row[4], 'BlockHardLimit' => $row[5], 'FileSoftLimit' => $row[8], 'FileHardLimit' => $row[9]};
    }

    $raw_groupquota = `LC_ALL=C /usr/sbin/repquota -ag -O csv|grep -v ^#|grep -v ^Group`;
    chomp $raw_groupquota;
    @gq = split /\n/, $raw_groupquota;
    @found_groups = ();
    foreach my $x (@gq) {
        chomp($x);
        next if $x =~ /^\s*$/;               # skip blank lines
        my (@row) = split (/,/, $x);
        push @found_groups, $row[0];
        $GROUPQUOTA{$row[0]} = { 'BlockUsed' => $row[3], 'BlockSoftLimit' => $row[4], 'BlockHardLimit' => $row[5], 'FileSoftLimit' => $row[8], 'FileHardLimit' => $row[9]};
    }
}
else {
    #
    ### Get quotas via find/stat:
    #

    debug_msg("Getting all quotas via 'find/stat'\n");

    $pw = Base::CustomPasswdFile->new();
    $gr = Base::CustomGroupFile->new() or die "Cannot open /etc/group: $!";

    #
    ### User Quotas:
    #
    $siteAdmins = get_siteAdmins();
    @over_quota_web_owner = ();
    @items = all_users();
    %quotas = fetch_user_disk_usage();
    my (@results) = ();
    my (@gqres) = ();
    foreach my $item (@items) {
        my $used = $quotas{$item}{used} || 0;
        my $quota = $quotas{$item}{quota} || 0;
        $USERQUOTA{$item} = { 
            'BlockUsed' => $used, 
            'BlockSoftLimit' => $quota, 
            'BlockHardLimit' => 0, 
            'FileSoftLimit' => 0, 
            'FileHardLimit' => 0
        };
    }

    #
    ### Vsite Quota:
    #

    @items = sites();
    %site_quotas = fetch_group_disk_usage();

    foreach my $item (@items) {
        my $used = $site_quotas{$item}{used} || 0;
        my $quota = $site_quotas{$item}{quota} || 0;
        push @found_groups, $item;
        $GROUPQUOTA{$item} = { 
            'BlockUsed' => $used, 
            'BlockSoftLimit' => $quota, 
            'BlockHardLimit' => 0, 
            'FileSoftLimit' => 0, 
            'FileHardLimit' => 0
        };
    }
}

###

my @site_warnings = ();
my @user_warnings = ();

my ($user, $disk, $user_ok);
my ($user_status);
my (@oids) = ();
my ($newly_over, $send_mail);
my (%site_users) = (); # sites with users over quota. using a hash to remove dups
my (@cce_users) = (); # users that are over quota
my (@cce_user_oids) = (); # OIds of users that are over quota
my (%lastmailed_users) = (); # users that are over quota who we need to mail. using a hash to remove dups.
my (@lastmailed_sites) = (); # vsites that are over quota who we need to mail
my (%users_to_warn) = (); # users that are over quota who we need to mail, and their associated vsites

my @users_over_quota = users_over_quota();

# Loop through users over quota
foreach my $username (@users_over_quota) {
    # check if system user is a CCE user
    my ($oid) = $cce->find('User', {'name' => $username});
    if (!$oid) {
        $DEBUG && print "user $username doesn't exist in CCE, skipping\n";
        next;
    }

    # Skip system administrators from any enforcement or mail logic
    my $is_sysadmin = is_system_admin($username);
    if ($is_sysadmin) {
        $DEBUG && print "Skipping sysadmin $username from quota processing\n";
        next;
    }

    # record which users we need to update in CCE
    push @cce_users, $username;
    push @cce_user_oids, $oid;

    # flag that this site has a user over quota
    ($user_ok, $user) = $cce->get($oid);
    $site_users{$user->{site}} = 1;

    # this flag hasn't been updated since last AM run
    # so it shows if we were over quota LAST TIME
    ($user_ok, $disk) = $cce->get($oid, 'Disk');
    my $newly_over = !$disk->{over_quota};

    # has it been over a day since we last mailed them?
    my $send_mail = $disk->{lastmailed} < time - 3600*24;

    # Current usage for this user:
    my $used  = $USERQUOTA{$username}{BlockUsed}      || 0;
    my $quota = $USERQUOTA{$username}{BlockSoftLimit} || 0;

    # Treat as mail-blocked if already flagged OR at/over 100% now
    my $mail_blocked = ($disk->{over_quota} || ($quota && $used >= $quota)) ? 1 : 0;

    # Email users if necessary (but skip if mail would be blocked)
    if ($am->{mail_user} && !$mail_blocked && ($newly_over || $send_mail)) {
        next if $is_sysadmin; # Don't include sysadmins in admin’s over-quota user list!
        $DEBUG && print "Notifying the user $username about quota\n";
        $lastmailed_users{$username} = 1;
        $users_to_warn{$username} = $user->{site};
    }
    elsif ($mail_blocked) {
        $DEBUG && print "Skip user mail for $username: Quota enforcement active ($used/$quota)\n";
    }

    # Email admin if it's the right time (4 AM or debug mode)
    if ($am->{mail_admin_on_user} && $time_to_send_admin_mail) {
        $DEBUG && print "notifying the admin about $username about quota\n";
        $lastmailed_users{$username} = 1;

        # Get FQDN of the site that this overquota-user belongs to:
        my ($oidvsite) = $cce->find('Vsite', {'name' => "$user->{site}"});
        my $uservsite_ok = "";
        my $user_vsite = "";
        ($uservsite_ok, $user_vsite) = $cce->get($oidvsite);

        push @user_warnings, $username . '@' . $user_vsite->{fqdn};
    }
    
    $DEBUG && print "done processing $username\n";
}

#######################################################################
## EMAIL FIRST
#######################################################################

# Compute sites over quota now so admin email has the info,
# but DO NOT set any flags yet.
my @sites_over_quota = sites_over_quota();

# If admin wants vsite alerts and it's the right time, stage site warnings
# and remember which sites we will mark as lastmailed (after sending).
if ($am->{mail_admin_on_vsite} && $time_to_send_admin_mail) {
    foreach my $site (@sites_over_quota) {
        my ($oid) = $cce->find('Vsite', { 'name' => $site });
        next unless $oid;
        my (undef, $site_obj) = $cce->get($oid);
        push @site_warnings, $site_obj->{fqdn};
        push @lastmailed_sites, $site;   # lastmailed will be set after we send
    }
}

# -----------------------
# Build admin email body
# -----------------------
my @mail_output = ();

if (@site_warnings) {
    my $adm_msg_site_bdy = $i18n->get("[[base-disk.sites_over_quota,sites=\"" . join(',', @site_warnings) . "\"]]");
    push @mail_output, $adm_msg_site_bdy;
}

if (@user_warnings) {
    # Header line (can't drop users="" param without touching locales)
    my $adm_msg_bdy = "\n" . $i18n->get('[[base-disk.users_over_quota,users=""]]'); 
    push @mail_output, $adm_msg_bdy;

    # One address per line (already built as user@fqdn earlier)
    foreach my $user_single (@user_warnings) {
        push @mail_output, $user_single;
    }
}

# ---------------
# Mail to admin
# ---------------
if (@mail_output) {
    $DEBUG && print "mailing to admin: " . join(',', @mail_output) . "\n";

    my (undef, $am_alert) = $cce->get($am_oid);
    my @am_recips = $cce->scalar_to_array($am_alert->{alertEmailList});
    my $recips = join(',', @am_recips);

    # Locale for admin
    my ($l1_oid) = $cce->find('User', {'name' => 'admin'});
    my (undef, $user_locale) = $cce->get($l1_oid);
    my $locale = $user_locale->{localePreference};
    if (not -d "/usr/share/locale/$locale" && not -d "/usr/local/share/locale/$locale") {
        $locale = I18n::i18n_getSystemLocale($cce);
    }

    $i18n->setLocale($locale);
    my $a_subject = $host . ": " . $i18n->get('[[base-disk.userOverQuota]]');
    my $a_body    = join("\n", @mail_output);

    my $senderAddr = 'root <root@' . $host . '>';

    my $send_msg = MIME::Lite->new(
        From    => $senderAddr,
        To      => $recips,
        Subject => $a_subject,
        Data    => $a_body
    );

    $send_msg->attr("content-type" => "text/plain");
    if (($locale eq "ja_JP") || ($locale eq "ja")) { 
        $send_msg->attr("content-type.charset" => "EUC-JP"); 
    }
    else { 
        $send_msg->attr("content-type.charset" => "UTF-8"); 
    }

    eval { $send_msg->send; };
    if ($@) {
        $DEBUG && print STDERR "Failed to send admin email to $recips: $@\n";
    }
    else {
        $DEBUG && print STDERR "Sent admin email to $recips\n";
    }
}

# --------------
# Mail to users
# --------------
while (my ($user, $site) = each(%users_to_warn)) {

    # Check if user is already at or past the quota limit:
    my $used  = $USERQUOTA{$user}{BlockUsed}      || 0;
    my $quota = $USERQUOTA{$user}{BlockSoftLimit} || 0;
    if ($quota && $used >= $quota) {
        $DEBUG && print "skipping send to $user (now at/over 100%)\n";
        next;
    }

    # Build recipient address (user or user@site_fqdn)
    my ($oid) = $cce->find('Vsite', { 'name' => $site });
    my $email;
    if ($oid) {
        my (undef, $site_obj) = $cce->get($oid);
        $email = $user . '@' . $site_obj->{fqdn};
    }
    else {
        # Admin user not on a Vsite
        $email = $user;
    }

    # Locale for the user
    my ($l2_oid) = $cce->find('User', {'name' => $user});
    my (undef, $user_locale) = $cce->get($l2_oid);
    my $locale = $user_locale->{localePreference};
    if (not -d "/usr/share/locale/$locale" && not -d "/usr/local/share/locale/$locale") {
        $locale = I18n::i18n_getSystemLocale($cce);
    }

    $i18n->setLocale($locale);
    my $u_subject = $i18n->get('[[base-disk.userOverQuota]]');
    my $u_body    = $i18n->get('[[base-disk.overQuotaMsg]]') . "\n\n";

    my $senderAddr = 'root <root@' . $host . '>';

    my $send_msg = MIME::Lite->new(
        From    => $senderAddr, 
        To      => $email,
        Subject => $u_subject,
        Data    => $u_body
    );

    $send_msg->attr("content-type" => "text/plain");
    if (($locale eq "ja_JP") || ($locale eq "ja")) { 
        $send_msg->attr("content-type.charset" => "EUC-JP"); 
    }
    else { 
        $send_msg->attr("content-type.charset" => "UTF-8"); 
    }

    eval { $send_msg->send; };
    if ($@) {
        $DEBUG && print STDERR "Failed to send email to $email: $@\n";
        # keep going
    }
    else {
        $DEBUG && print STDERR "Sent email to $email\n";
    }
}

# -----------------------------
# Update 'lastmailed' timestamps
# -----------------------------
foreach my $username (keys(%lastmailed_users)) {
    $DEBUG && print "Updating $username 'lastmailed' status\n";
    my ($oid) = $cce->find('User', { 'name' => $username });
    my ($user_ok) = $cce->set($oid, 'Disk', { 'lastmailed' => $now });
}

foreach my $sitename (@lastmailed_sites) {
    $DEBUG && print "Updating $sitename 'lastmailed' status\n";
    my ($oid) = $cce->find('Vsite', { 'name' => $sitename });
    my ($site_ok) = $cce->set($oid, 'Disk', { 'lastmailed' => $now });
}

#######################################################################
## EMAIL FIRST
#######################################################################

# flag the user as being over quota
# reset old flags
@oids = $cce->find('User', { 'Disk.over_quota' => 1 });
foreach my $user (@oids) {
    ($user_ok, my $user_obj) = $cce->get($user);
    my $username = $user_obj->{name};
    my $groupname = get_primary_group($username);
    my $quota = $USERQUOTA{$username}{BlockSoftLimit} || 0;

    if (in_array(\@cce_users, $username)) {
        # User is still over-quota. Not resetting their 'over_quota' flag
        $DEBUG && print "User $username remains over quota, no change needed\n";
    }
    elsif ($groupname !~ /^site/ || $quota == 0) {
        # User is either not in a 'site*' group or has no quota; reset 'over_quota' flag
        ($user_ok, $disk) = $cce->get($user, 'Disk');
        if ($disk->{over_quota} != 0) {
            ($user_ok) = $cce->set($user, 'Disk', { 'over_quota' => 0, 'quota_toggle' => $now });
            $DEBUG && print "Cleared over_quota for $username, set quota_toggle\n";
            if (!$user_ok) {
                $DEBUG && print STDERR "couldn't clear over_quota flag on oid $user for user $username\n";
            }
        }
    }
    else {
        # User is no longer over-quota but is in a 'site*' group with a quota; reset 'over_quota' flag
        ($user_ok, $disk) = $cce->get($user, 'Disk');
        if ($disk->{over_quota} != 0) {
            ($user_ok) = $cce->set($user, 'Disk', { 'over_quota' => 0, 'quota_toggle' => $now });
            $DEBUG && print "Cleared over_quota for $username, set quota_toggle\n";
            if (!$user_ok) {
                $DEBUG && print STDERR "couldn't clear over_quota flag on oid $user for user $username\n";
            }
        }
    }
}

# update new flag:
foreach my $username (@cce_users) {
    my $used  = $USERQUOTA{$username}{BlockUsed}      || 0;
    my $quota = $USERQUOTA{$username}{BlockSoftLimit} || 0;
    #my $should_block = ($quota && $used >= $quota);  # block only at 100%, although notification still happens at 95%

    # Only ENFORCE blocking for Vsite users with a quota, never for server-admins and block only at 100%, although notification still happens at 95%
    my ($oid_u) = $cce->find('User', { name => $username });
    my (undef, $uobj) = $cce->get($oid_u);
    my $is_sysadmin = is_system_admin($username);
    my $groupname   = get_primary_group($username);
    my $is_vsite_user = ($groupname =~ /^site/ && ($uobj->{site} || '') ne '');
    my $should_block = (!$is_sysadmin && $is_vsite_user && $quota && $used >= $quota);

    my ($ok, $disk) = $cce->get($oid_u, 'Disk');

    my $new_state = $should_block ? 1 : 0;
    if (($disk->{over_quota} // 0) != $new_state) {
        ($ok) = $cce->set($oid_u, 'Disk', { over_quota => $new_state, quota_toggle => $now });
        $DEBUG && print (($new_state ? "Set" : "Cleared") . " over_quota for $username (used=$used, quota=$quota)\n");
    }
}

# Clean up /etc/mail/access and /etc/postfix/suspended_users for users who are not over quota
my $access_file = "/etc/mail/access";
my $suspended_users_file = "/etc/postfix/suspended_users";
my %blocked_users = ();

# Parse /etc/mail/access to find users marked as over quota
if (-e $access_file) {
    open(my $fh, '<', $access_file) or $DEBUG && print STDERR "Cannot open $access_file: $!\n";
    while (my $line = <$fh>) {
        chomp($line);
        if ($line =~ /^### Start Block Email for User: (\S+) on Virtual Site:/) {
            $blocked_users{$1} = 1;
        }
    }
    close($fh);
}

# Parse /etc/postfix/suspended_users to find users marked as over quota
if (-e $suspended_users_file) {
    open(my $fh, '<', $suspended_users_file) or $DEBUG && print STDERR "Cannot open $suspended_users_file: $!\n";
    while (my $line = <$fh>) {
        chomp($line);
        if ($line =~ /^### Start Block Email for User: (\S+) on Virtual Site:/) {
            $blocked_users{$1} = 1;
        }
    }
    close($fh);
}

# Check each blocked user against current quota status
foreach my $username (keys %blocked_users) {
    my ($oid) = $cce->find('User', { 'name' => $username });
    if (!$oid) {
        $DEBUG && print "User $username not found in CCE, skipping\n";
        next;
    }
    my $groupname = get_primary_group($username);
    my $is_sysadmin = is_system_admin($username);
    my $used  = $USERQUOTA{$username}{BlockUsed}      || 0;
    my $quota = $USERQUOTA{$username}{BlockSoftLimit} || 0;

    # align cleanup with "block only at 100%" policy, and NEVER block sysadmins or non-Vsite users
    my $is_vsite_user = ($groupname =~ /^site/);
    my $should_be_blocked = (!$is_sysadmin && $is_vsite_user && $quota && $used >= $quota);

    if (!$should_be_blocked || !$is_vsite_user || $quota == 0) {
        # We clear over-quota flags once we're below 100%:
        $DEBUG && print "Updating CODB for $username to clear over_quota and trigger user_disable.pl\n";
        my ($user_ok, $disk) = $cce->get($oid, 'Disk');
        if (($disk->{over_quota} || 0) != 0) {
            ($user_ok) = $cce->set($oid, 'Disk', { 'over_quota' => 0, 'quota_toggle' => $now });
            if (!$user_ok) {
                $DEBUG && print STDERR "Failed to update CODB for $username to clear over_quota and set quota_toggle\n";
            }
        }
        # Also proactively scrub any stale blocks from both transport files
        unblock_user_in_files($username);
    }
    else {
        $DEBUG && print "User $username remains blocked (used=$used, quota=$quota)\n";
    }
}


# flag the site that has a user over quota
# reset old flags
@oids = $cce->find('Vsite', { 'Disk.user_over_quota' => 1 });
foreach my $site (@oids) {
    my ($site_ok) = $cce->set($site, 'Disk', { 'user_over_quota' => 0 });
    if (!$site_ok) {
        $DEBUG && print STDERR "couldn't clear user_over_quota for oid $site\n";
    }
}

# update the new flags
foreach my $site (keys(%site_users)) {
    my ($oid) = $cce->find('Vsite', { 'name' => $site });
    if (!$oid) {
        # an admin user, not on a vsite
        next;
    }

    my ($ok) = $cce->set($oid, 'Disk', { 'user_over_quota' => 1 });
    if (!$ok) {
        $DEBUG && print STDERR "couldn't set user_over_quota for site $site\n.";
        next;
    }
}
           
# Find all Vsites that currently have 'vsite_over_quota' set to '1';
my ($sitedirs, $fullDir);  # avoid implicit globals later
my @vhosts = ();
my (@vhosts) = $cce->findx('Vsite');
my @complete_vsite_list = ();
# Walk through all Vsites:
for my $vsite (@vhosts) {
    ($ok, my $my_vsite) = $cce->get($vsite);
    ($ok, my $my_vsite_disk) = $cce->get($vsite, 'Disk');
    if ($my_vsite_disk->{vsite_over_quota} eq "1") {
        debug_msg("Vsite " . $my_vsite->{name} . " has the flag 'vsite_over_quota' set to 1\n");
        push(@complete_vsite_list, $my_vsite->{name});
    }
}

my $site_over_quota = 0;
foreach my $site (@sites_over_quota) {
    # check if system group is a CCE vsite
    my ($oid) = $cce->find('Vsite', {'name' => $site});
    if (!$oid) {
        $DEBUG && print "site $site doesn't exist in CCE, skipping\n";
        # site doesn't exist in CCE, skipping
        next;
    }

    # Set 'vsite_over_quota' for respective Vsite:
    my ($ok) = $cce->set($oid, 'Disk', { 'vsite_over_quota' => 1 });
    if (!$ok) {
        $DEBUG && print STDERR "couldn't set vsite_over_quota for site $site\n.";
        next;
    }
    $site_over_quota = 1;

    # Remove the Vsite from the list @complete_vsite_list of Vsites that had 'vsite_over_quota' set to one before we started:
    @complete_vsite_list = grep { $_ ne $site } @complete_vsite_list;

    if ( $server_status < $am_states{AM_STATE_YELLOW} ) {
        # site over quota means state yellow
        $server_status = $am_states{AM_STATE_YELLOW};
    }
}

# Reset the 'vsite_over_quota' flag for all Vsites that have it set to '1' but aren't actually over-quota anymore:
for my $xvsite (@complete_vsite_list) {
    my ($oid) = $cce->find('Vsite', {'name' => $xvsite});
    if ($oid) {
        debug_msg("Reset 'vsite_over_quota' for $xvsite in CCE\n");
        my ($ok) = $cce->set($oid, 'Disk', { 'vsite_over_quota' => 0 });
        if (!$ok) {
            $DEBUG && print STDERR "couldn't set vsite_over_quota for site $xvsite\n.";
            next;
        }
    }
}

### FINALLY, BATCH UPDATES:

# AM output
my @am_output = ();

# AM warnings include server usage
# and a simple note that a site is over quota and to look for a 2nd email about this
if (@dev_warnings) {
    push @am_output, @dev_warnings;
} 
if ($site_over_quota) {
    push @am_output, '[[base-disk.site_over_quota]]';
}

if (!@am_output) {
    push @am_output, '[[base-disk.amDiskOk]]';
}

print join("\n", @am_output);
$cce->bye();
exit $server_status;

# helper functions
sub users_over_quota {
    my ($name, $null, $uid, $user_gid, $all_gid, $dir);
    my (@users_over_quota) = ();
    my @cceusers;
    my ($used, $quota);

    # fetch all CCE users
    my @alluseroids = $cce->find('User', '');
    foreach my $entry (@alluseroids) {
        (my $ok, my $user) = $cce->get($entry);
        push(@cceusers, $user->{name});
    }

    # Loop through %USERQUOTA to see if someone is over-quota:
    foreach $name (keys %USERQUOTA) {

        # Skip system administrators outright
        next if is_system_admin($name);

        my $userfound = 0;
        if (grep {$_ eq $name} @cceusers) {
            $userfound = 1;
        }
        if ($userfound == 0) {
            next;
        }
        my $groupname = get_primary_group($name);

        # Also require actual Vsite membership, not just a site-like group name
        my ($oid_u) = $cce->find('User', { name => $name });
        my (undef, $uobj) = $cce->get($oid_u);
        my $site = $uobj->{site} || '';

        $used = $USERQUOTA{$name}{BlockUsed};
        $quota = $USERQUOTA{$name}{BlockSoftLimit};
        if (!defined $quota || $quota == 0 || $groupname !~ /^site/ || $site eq '') {
            # no quota set or user not in a 'site*' group, skip quota check
            $DEBUG && print "Skipping quota check for $name: quota=$quota, group=$groupname\n";
            next;
        }
        $DEBUG && print "Username: $name - $used / $quota\n";
        if ($used >= ($quota * $ENV{red_pcnt} / 100)) { # threshold in red_pcnt
            $DEBUG && print "$name is over quota. used $used of $quota\n";
            push @users_over_quota, $name;
        }        
    }
    return @users_over_quota;
}

sub sites_over_quota {
    my ($sitedirs, $fullDir);
    my @sites_over_quota = ();
    my @hashdirs = ();
    my @sd = ();
    my ($quota, $used);
    # This dirty one here gets us all directories from /home/.sites/:
    $sitedirs = `LC_ALL=C /usr/bin/tree -L 1 -d /home/.sites/|/usr/bin/grep "\s"|/usr/bin/awk '{print \$2}'|/usr/bin/grep -v ^server|grep -v directories`;
    chomp($sitedirs);
    @sd = split /\n/, $sitedirs;

    foreach my $x (@sd) {
        chomp($x);
        $fullDir = '/home/.sites/' . $x;
        if (-d $fullDir) {
            if (in_array(\@found_groups, $x)) {
                push @hashdirs, $x;
            }            
        }
    }

    # find all dirs in all hashes
    foreach my $name (@hashdirs) {
        if (in_array(\@found_groups, $name)) {
            $used = $GROUPQUOTA{$name}{BlockUsed};
            $quota = $GROUPQUOTA{$name}{BlockSoftLimit};
            if (!defined $quota || $quota == 0) {
                # no quota set
                $DEBUG && print "no quota set on $name, skipping\n";
                next;
            }
            if ($used >= $quota) { 
                $DEBUG && print "$name is over quota. $used / $quota\n";
                push @sites_over_quota, $name;
            }
            else {
                $DEBUG && print "$name is NOT over quota. $used / $quota\n";
            }
        }
    }

    # Using foreach loop to add Vsites whose /web owners are over quota to the array @sites_over_quota:
    foreach my $site (@over_quota_web_owner) {
        push @sites_over_quota, $site;
    }

    return @sites_over_quota;
}

# Set Disk.refresh on all disks if our root fs is getting full
sub set_disks_refresh {
    my $diskcce = new CCE;
    $diskcce->connectuds();
    my (@disks) = $diskcce->find('Disk');
    my $diskoid;

    foreach $diskoid (@disks) {
        # We don't care if it fails
        $diskcce->set($diskoid, '', { 'refresh' => time });
    }
    $diskcce->bye();
}

sub get_siteAdmins {
    my $site_ok;
    my $site;
    my $site_PHP;
    my $oid;

    my @oids = $cce->findx('Vsite');
    if (!$oids[0]) {
        return;
    }

    foreach my $xoid (@oids) {
        ($site_ok, $site) = $cce->get($xoid);
        ($site_ok, $site_PHP) = $cce->get($xoid, 'PHP');
        $siteAdmin_hash{$site->{name}} = $site_PHP->{prefered_siteAdmin};
        $siteDirs{$site->{name}} = $site->{basedir} . '/wwwroot/web';
        if ($site_PHP->{prefered_siteAdmin}) {
            $siteAdmin_hash_reverse{$site_PHP->{prefered_siteAdmin}} = $site->{name};
        }
    }
}

sub get_dir_size {
    my ($dir) = @_;
    my $size_in_blocks = `LC_ALL=C find $dir -type f -print0 | xargs -0 stat --format=%b | awk '{s+=\$1} END {print s}'`;
    chomp $size_in_blocks;
    my $size = int(($size_in_blocks * 512) / 1024); # Ensuring integer result
    return $size;
}

sub get_primary_group {
    my ($username) = @_;
    my $group_name = `id -gn $username`;
    chomp $group_name;
    return $group_name;
}

sub fetch_user_disk_usage {
    my %user_disk_usage;
    foreach my $name ($pw->users) {
        my $uid = $pw->uid($name);
        my $user_gid = $pw->gid($name);
        my $dir = $pw->home($name);
        my @groupworkaround = split(/\//, $dir);
        my $groupname = get_primary_group($name);

        # For now, let's assume the quota allowance is fetched or predefined somewhere
        my $user_disk_allowance = get_user_quota_allowance($name);

        if (($uid >= 500) && ($uid != 65534) && (defined $groupworkaround[5] && $groupworkaround[5] ne "logs")) {
            if (-d $dir) {
                $user_disk_usage{$name}{used} = get_dir_size($dir);
                $user_disk_usage{$name}{quota} = $user_disk_allowance;

                if (($siteAdmin_hash_reverse{$name}) && ($siteDirs{$siteAdmin_hash_reverse{$name}})) {
                    my $webdirQuota = get_dir_size($siteDirs{$siteAdmin_hash_reverse{$name}});
                    $user_disk_usage{$name}{used} += $webdirQuota;

                    if ($user_disk_usage{$name}{used} >= $user_disk_allowance) {
                        # Note that the owner of /web of this Vsite is over quota:
                        push @over_quota_web_owner, $groupname;
                    }
                }
            }
        }
    }
    return %user_disk_usage;
}

sub fetch_group_disk_usage {
    my $base_dir = '/home/.sites';
    my %group_disk_usage;
    my @dirs = glob("$base_dir/*");
    foreach my $dir (@dirs) {
        if (-d $dir) {
            my $group = (split('/', $dir))[-1];

            # Get quota allowance from CODB:
            my $group_disk_allowance = get_group_quota_allowance($group);

            $group_disk_usage{$group}{used} = get_dir_size($dir);
            $group_disk_usage{$group}{quota} = $group_disk_allowance;
        }
    }
    return %group_disk_usage;
}

sub get_user_quota_allowance {
    my ($username) = @_;
    my $cce_user_DiskQuota = 0;
    my $uid = $pw->uid($username);
    my $user_gid = $pw->gid($username);
    my $dir = $pw->home($username);
    my @groupworkaround = split(/\//, $dir);
    my $groupname = get_primary_group($username);

    if (($uid >= 500) && ($uid != 65534) && (defined $groupworkaround[5] && $groupworkaround[5] ne "logs")) {
        my ($oid) = $cce->find('User', { 'name' => $username });
        if (!$oid) {
            return $cce_user_DiskQuota;
        }
        else {
            my ($user_ok, $cce_user) = $cce->get($oid, 'Disk');
            $cce_user_DiskQuota = int($cce_user->{quota} * 1024);
            return $cce_user_DiskQuota;
        }
    }
    else {
        return $cce_user_DiskQuota;
    }
}

sub get_group_quota_allowance {
    my ($groupname) = @_;
    my $cce_vsite_DiskQuota = 0;

    my ($oid) = $cce->find('Vsite', { 'name' => $groupname });
    if (!$oid) {
        return $cce_vsite_DiskQuota;
    }
    else {
        my ($vsite_ok, $cce_vsite) = $cce->get($oid, 'Disk');
        $cce_vsite_DiskQuota = int($cce_vsite->{quota} * 1024);
        return $cce_vsite_DiskQuota;
    }
}

sub site_users {
    my $site = shift;
    my $site_ok;
    my ($oid) = $cce->find('Vsite', { 'name' => $site });
    if (!$oid) {
        debug_msg("Couldn't find site $site in CCE\n");
        print STDERR "Couldn't find site $site in CCE\n";
        return;
    }
    ($site_ok, $site) = $cce->get($oid);
    my $sitedir = $site->{basedir};

    opendir(my $dh, "$sitedir/home/users") or return;
    my @users = grep { !/^\./ } readdir($dh);
    closedir($dh);

    return @users;
}

sub all_users {
    my @all_users;
    foreach my $name ($pw->users) {
        my $uid = $pw->uid($name);
        my $dir = $pw->home($name);
        my @groupworkaround = split(/\//, $dir);

        if (defined $groupworkaround[5]) {
            if (($uid >= 500) && ($uid != 65534) && ($groupworkaround[5] ne "logs")) {
                push @all_users, $name;
            }
        }
        else {
            if (($uid >= 500) && ($uid != 65534)) {
                push @all_users, $name;
            }
        }
    }
    return @all_users;
}

sub in_array {
    my ($arr, $search_for) = @_;
    my %items = map { $_ => 1 } @$arr;
    return exists($items{$search_for});
}

sub sites {
    my @sites;
    my $sitedirs = `LC_ALL=C /usr/bin/tree -L 1 -d /home/.sites/ | /usr/bin/grep "\\s" | /usr/bin/awk '{print \$2}' | /usr/bin/grep -v ^server | grep -v directories`;
    chomp($sitedirs);
    my @sd = split /\n/, $sitedirs;
    foreach my $x (@sd) {
        my $fullDir = '/home/.sites/' . $x;
        if (-d $fullDir) {
            push @sites, $x;
        }
    }
    return @sites;
}

sub siteusage_repquota {
    my %hash;
    my $sitedirs = `LC_ALL=C /usr/bin/tree -L 1 -d /home/.sites/ | /usr/bin/grep "\\s" | /usr/bin/awk '{print \$2}' | /usr/bin/grep -v ^server | grep -v directories`;
    chomp($sitedirs);
    my @sd = split /\n/, $sitedirs;
    foreach my $x (@sd) {
        my $fullDir = '/home/.sites/' . $x;
        if (-d $fullDir) {
            if (in_array(\@found_groups, $x)) {
                $hash{$x}{used} = $GROUPQUOTA{$x}{BlockUsed} || 0;
                $hash{$x}{quota} = $GROUPQUOTA{$x}{BlockSoftLimit} || 0;
            }
        }
    }
    return %hash;
}

sub userusage_repquota {
    my %hash;
    foreach my $name ($pw->users) {
        my $uid = $pw->uid($name);
        my $user_gid = $pw->gid($name);
        my $dir = $pw->home($name);
        my @groupworkaround = split(/\//, $dir);

        if (!defined $groupworkaround[5]) {
            $groupworkaround[5] = '';
        }

        if (($uid < 500) || ($uid == 65534)) {
            next;
        }
        elsif ($groupworkaround[5] eq "logs") {
            next;
        }
        elsif ($name eq "nfsnobody") {
            next;
        }
        else {
            $hash{$name}{used} = $USERQUOTA{$name}{BlockUsed} || 0;
            $hash{$name}{quota} = $USERQUOTA{$name}{BlockSoftLimit} || 0;
        }
    }
    return %hash;
}

#
### Check if server has file-system quotas enabled:
#

sub disk_has_quota_main {
    my $mount_point = "@_";
    
    my $quotas_enabled = check_quotas($mount_point);
    
    if ($quotas_enabled) {
        # Disk quotas are enabled on the filesystem:
        return 1;
    }
    else {
        # Disk quotas are not enabled on the filesystem:
        return 0;
    }
}

# Main function to check quotas based on filesystem type
sub check_quotas {
    my ($mount_point) = @_;
    
    my $fs_type = get_filesystem_type($mount_point);
    my $quotas_enabled;
    
    if ($fs_type eq 'ext3' || $fs_type eq 'ext4') {
        $quotas_enabled = check_ext_quotas($mount_point);
    }
    elsif ($fs_type eq 'xfs') {
        $quotas_enabled = check_xfs_quotas($mount_point);
    }
    else {
        die "Unsupported filesystem type: $fs_type\n";
    }
    
    return $quotas_enabled;
}

# Function to detect the filesystem type
sub get_filesystem_type {
    my ($mount_point) = @_;
    my @output = run_command("LC_ALL=C df -T $mount_point | tail -1 | awk '{print \$2}'");
    return $output[0];
}

# Function to check quotas on EXT3/EXT4
sub check_ext_quotas {
    my ($mount_point) = @_;
    
    # Check if quota files exist and are non-empty
    return check_quota_files($mount_point);
}

# Function to check quotas on XFS
sub check_xfs_quotas {
    my ($mount_point) = @_;
    
    # Check general quota status
    my @output = run_command("LC_ALL=C /usr/sbin/xfs_quota -x -c 'report -h' $mount_point");
    
    # Look for lines indicating quotas are active
    foreach my $line (@output) {
        if ($line =~ /User quota on/i || $line =~ /Group quota on/i) {
            return 1;
        }
    }
    return 0;
}

# Function to check if quota files exist and are non-empty
sub check_quota_files {
    my ($mount_point) = @_;
    my $quota_user_file = "$mount_point/aquota.user";
    my $quota_group_file = "$mount_point/aquota.group";
    
    if (-e $quota_user_file && -s $quota_user_file && -e $quota_group_file && -s $quota_group_file) {
        return 1;
    }
    return 0;
}

# Function to execute a shell command and capture the output
sub run_command {
    my ($command) = @_;
    my @output = `$command 2>&1`;
    chomp @output;
    return @output;
}

# Consider a user a system admin if any common CCE flags indicate so
sub is_system_admin {
    my ($username) = @_;
    my ($oid) = $cce->find('User', { name => $username });
    return 0 unless $oid;
    my (undef, $u) = $cce->get($oid);
    return 1 if defined $u->{systemAdministrator} && $u->{systemAdministrator};
    return 1 if defined $u->{capLevels}           && $u->{capLevels}           =~ /\bsystemAdministrator\b/;
    return 1 if defined $u->{uiRights}             && $u->{uiRights}             =~ /\badminUser\b/;
    return 0;
}

# Remove any stale block sections for a username from postfix/sendmail files and reload postfix map
sub unblock_user_in_files {
    my ($username) = @_;
    my @files = ('/etc/mail/access', '/etc/postfix/suspended_users');
    my $changed = 0;
    foreach my $file (@files) {
        next unless -e $file;
        local $/ = undef;
        if (open my $fh, '<', $file) {
            my $content = <$fh>;
            close $fh;
            my $before = $content;
            my $re = qr/### Start Block Email for User: \Q$username\E .*? ###.*?### END Block Email for User: \Q$username\E .*? ###\s*/s;
            $content =~ s/$re//g;   # remove ALL matching blocks
            if ($content ne $before) {
                if (open my $oh, '>', $file) {
                    print $oh $content;
                    close $oh;
                    $changed = 1;
                    $DEBUG && print "Removed stale block entries for $username in $file\n";
                }
            }
        }
    }
}

#
### Debug printer:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0, '', 'user');
        syslog('info', "$msg");
        closelog();
    }
}

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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
