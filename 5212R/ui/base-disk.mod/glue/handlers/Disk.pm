#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/disk
# $Id: Disk.pm

package Disk;
use Exporter ();
@ISA = qw(Exporter);
@EXPORT = qw(setquota getquota getquotausage get_dir_size);

# We deprecated the usage of perl-Quota and instead use 'repquota' and 'setquota' from
# the RPM "quota". Please note: We need quota v4.0.4 or better, because we use the "-O csv"
# parameter, which isn't present in earlier versions. Special note: CentOS 7 uses an older 
# quota RPM, so we supply our own that is new enough. On EL 8 and newer we use the OS 
# supplied ones.
#
# Additionally this module now checks via disk_has_quota_main() if the file-system has real
# disk-quota support available. If so, it uses the quota shell commands to set or get quota
# information. If no user- or group-quota are available on the underlying file-system, then
# setquota() will return w/o failure and getquota() as well as getquotausage() will use the
# alternate method via 'du -s --block-size=512' to report quota usage.
#

$repquota_cmd = '/usr/sbin/repquota';
$setquota_cmd = '/usr/sbin/setquota';

#use vars qw($DEBUG);

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
}

use CCE;
use Base::HomeDir;
use DiskInfo;
use Sauce::Util;
use Unix::PasswdFile;
use Unix::GroupFile;

my $pw = Unix::PasswdFile->new("/etc/passwd");
my $gr = Unix::GroupFile->new("/etc/group");

my $cce = CCE->new();
$cce->connectuds();

my (%siteAdmin_hash, %siteAdmin_hash_reverse, %siteDirs);
my $siteAdmins;

sub setquota
# Set the quota for a user or group.
# Arguments: cce connection, user or group object, and oid
# Side effects: modifies the quota tables
# Returns: 1 for success, a string explaining failure otherwise.
{
    my ($cce, $obj, $oid) = @_;

    my $type = $obj->{CLASS};
    my $name = $obj->{name};

    my ($ok, $disk, $disk_old) = $cce->get($oid, 'Disk');
    my $limit = $disk->{quota};

    # be explicit about this on destroy to avoid confusion in the future
    if ($cce->event_is_destroy()) {
        # always set to unlimited on destroy
        $limit = -1;
    }
    
    my @dirs = ();
    my $home = $Base::HomeDir::HOME_ROOT;

    my $realquotas = disk_has_quota_main($home); # Determines if we have real quota support or need to use 'du -s --block-size=512'

    my $BlocksPerMB = $DiskInfo::BYTES_PER_BLOCK;
    # user or group
    &debug_msg("Creation type is: $type \n");
    &debug_msg("Creation limit is: $limit \n");
    &debug_msg("Creation disk is: $disk \n");
    if ($type eq 'User') {
        $type = 0;
        if (($name eq "admin") || ($name eq "alter-admin")) {
            # If we're creating the 'admin' User, we don't really need quota for him.
            # We need the admin account so much that we simply ignore any quota issues
            # that could potentially arise.
            &debug_msg("Creation name is: $name - returning 1 (success)\n");
            return 1;
        }
        # volume for users lives in main namespace
        push @dirs, ($obj->{volume} ? $obj->{volume} : $home);
    }
    else {
        $type = 1;
        # volume for Workgroups and Vsites will be in main namespace
        push @dirs, ($obj->{volume} ? $obj->{volume} : $home);
    }

    for my $dir (@dirs) {
        my $softquota = 0;
        my $hardquota = 0;
        my $softinode = 0;
        my $hardinode = 0;

        if ($limit eq 0) {
            # no quota is really quota for one file
            $softquota = 1;
            $hardquota = 1;
            $softinode = 1;
            $hardinode = 1;
        }
        elsif ($limit gt 0) {
            $softquota = $limit * $BlocksPerMB;
            $hardquota = $softquota + $BlocksPerMB;
        }

        if ($realquotas) {
            # Use real quotas
            &debug_msg("Using real quotas for $name \n");

            # Get existing quota:
            my @args = (Disk::getquota($cce, $obj, $oid));

            # harris used extra arg $type here, which is not documented
            # to let it set quotas for groups too --pbaltz
            if ($type) {
                # use 0 (defaults to this anyway), "" still gives error
                push @args, 0; 
                push @args, $type;
            }

            # Set Quota using 'setquota':
            # setquota [ -rm ] [ -u | -g | -P ] [ -F quotaformat ] name block-softlimit block-hardlimit inode-softlimit inode-hardlimit -a | filesystem...

            # volume for users lives in main namespace
            $volume = '/home';
            $haveHome = `LC_ALL=C mount | grep " on /home " | wc -l`;
            chomp($haveHome);
            if ($haveHome eq 0) {
                $volume = '/';
            }
            if ($dir) {
                $haveDirQuota = `mount|grep "$dir"|wc -l`;
                chomp($haveDirQuota);
                if ($haveDirQuota eq 1) {
                    $volume = $dir;
                }
            }
            &debug_msg("Using Volume: $volume \n");

            if ($type eq 0) {
                # Set Quota for User:
                $quota_command = "LC_ALL=C $setquota_cmd -u $name --always-resolve $softquota $hardquota $softinode $hardinode $volume";
                &debug_msg("Set Quota: \"$quota_command\"\n");
                $exit_code = system($quota_command);
            }
            else {
                # Set Quota for Group:
                $quota_command = "LC_ALL=C $setquota_cmd -g $name --always-resolve $softquota $hardquota $softinode $hardinode $volume";
                &debug_msg("Set Quota: \"$quota_command\"\n");
                $exit_code = system($quota_command);
            }
            &debug_msg("Set Quota ret: $exit_code\n");

            #ROLLBACK QUOTA
            &debug_msg("Quota operation args: " . join(' ', @args) . " \n");
            if ($exit_code != 0) {
                &debug_msg("Quota Error: Exit code $exit_code\n");
                &debug_msg("Quota Error args: $quota_command \n");
                $cce->warn('couldNotSetQuota', {'name' => $name });
                return 0;
            }

            # add rollback for quota
            my $old_softquota = 0;
            my $old_hardquota = 0;
            my $old_softinode = 0;
            my $old_hardinode = 0;
            if ($disk_old->{quota} eq 0) {
                # no quota is really quota for one file
                $old_softquota = 1;
                $old_hardquota = 1;
                $old_softinode = 1;
                $old_hardinode = 1;
            }
            elsif ($disk_old->{quota} > 0) {
                $old_softquota = $disk_old->{quota} * $BlocksPerMB;
                $old_hardquota = $old_softquota + $BlocksPerMB;
            }

            if ($type eq 0) {
                # Define rollback_cmd for User:
                $rollback_cmd = "LC_ALL=C $setquota_cmd -u $name --always-resolve $old_softquota $old_hardquota $old_softinode $old_hardinode $volume";
                $exit_code = system($quota_command);
            }
            else {
                # Define rollback_cmd Group:
                $rollback_cmd = "LC_ALL=C $setquota_cmd -g $name --always-resolve $old_softquota $old_hardquota $old_softinode $old_hardinode $volume";
                $exit_code = system($quota_command);
            }
            &debug_msg("Rollback: $rollback_cmd \n");
            Sauce::Util::addrollbackcommand($rollback_cmd);
        }
        else {
            &debug_msg("This server has no disk quota enabled for the file system. Skipping. \n");
        }
    }
    return 1;
}

sub getquota
# Get the quota for a user or group.
# Arguments: cce connection, user or group object, and oid
# Returns: 1 for success, a string explaining failure otherwise.
{

    my ($cce, $obj, $oid) = @_;

    my $type = $obj->{CLASS};
    my $name = $obj->{name};

    my ($ok, $disk, $disk_old) = $cce->get($oid, 'Disk');
    my $limit = $disk->{quota};

    # be explicit about this on destroy to avoid confusion in the future
    if ($cce->event_is_destroy()) {
        # always set to unlimited on destroy
        $limit = -1;
    }
    
    my @dirs = ();
    my @quotaresult =();
    my @gqres =();
    my $home = $Base::HomeDir::HOME_ROOT;
    my $BlocksPerMB = $DiskInfo::BYTES_PER_BLOCK;
    # user or group
    &debug_msg("Quota type is: $type \n");
    &debug_msg("Quota limit is: $limit \n");
    &debug_msg("Quota disk is: $disk \n");
    &debug_msg("Quota oid is: $oid \n");

    # volume for users lives in main namespace
    $volume = '/home';
    $haveHome = `mount|grep "/home"|wc -l`;
    chomp($haveHome);
    if ($haveHome eq 0) {
        $volume = '/';
    }
    if ($dir) {
        $haveDirQuota = `mount|grep "$dir"|wc -l`;
        chomp($haveDirQuota);
        if ($haveDirQuota eq 1) {
            $volume = $dir;
        }
    }

    my $realquotas = disk_has_quota_main($volume); # Determines if we have real quota support or need to use 'du -s --block-size=512'

    if ($type eq 'User') {
        $type = 0;
        if (($name eq "admin") || ($name eq "alter-admin")) {
            # If we're creating the 'admin' User, we don't really need quota for him.
            # We need the admin account so much that we simply ignore any quota issues
            # that could potentially arise.
            &debug_msg("Quota name is: $name - returning 1 (success)\n");
            return 1;
        }
    } 
    else {
        $type = 1;
    }

    if ($realquotas) {
        # Use real quotas
        &debug_msg("Using real quotas for $name \n");

        # LC_ALL=C /usr/sbin/repquota -u -O csv /
        # User,BlockStatus,FileStatus,BlockUsed,BlockSoftLimit,BlockHardLimit,BlockGrace,FileUsed,FileSoftLimit,FileHardLimit,FileGrace

        if ($type eq 0) {
            # Get Quota for User:
            &debug_msg("Getting User Quota: '$repquota_cmd -u -O csv $volume|grep \"^$name,\"'\n");
            $currQuota = `LC_ALL=C $repquota_cmd -u -O csv $volume|grep "^$name,"`;
        }
        elsif ($type eq 1) {
            # Get Quota for Group:
            &debug_msg("Getting Group Quota: '$repquota_cmd -g -O csv $volume|grep "^$name,"'\n");
            $currQuota = `LC_ALL=C $repquota_cmd -g -O csv $volume|grep "^$name,"`;
        }
        else {
            &debug_msg("Not specified if we wanted User or Group Quota! Getting both then: '$repquota_cmd -ug -O csv $volume|grep "^$name,"'\n");
            $currQuota = `LC_ALL=C $repquota_cmd -ug -O csv $volume|grep "^$name,"`;
        }
        &debug_msg("getquota result for $name: $currQuota \n");
        @gqres = split /,/, $currQuota;
        @quotaresult = ($volume, $gqres[0], $gqres[4], $gqres[5], $gqres[8], $gqres[9]);
    }
    else {
        # Use 'du -s --block-size=512' for getting quotas
        &debug_msg("Using 'du -s --block-size=512' quotas for $name \n");

        # Use 'du -s --block-size=512' for getting quotas
        if ($type eq 0) {
            # Get Quota for User:
            &debug_msg("Getting User Quota via 'du -s --block-size=512' for User $name\n");
            %quotas = fetch_user_disk_usage($name);
            $currQuota = "$name,ok,ok," . $quotas{$name}{used} . ",0,0,," . $quotas{$name}{quota} . ",0,0";
        }
        elsif ($type eq 1) {
            # Get Quota for Group:
            &debug_msg("Getting Group Quota via 'du -s --block-size=512' for Vsite $name\n");
            %quotas = fetch_group_disk_usage($name);
            $currQuota = "$name,ok,ok," . $quotas{$name}{used} . ",0,0,," . $quotas{$name}{quota} . ",0,0";
        }
        else {
            &debug_msg("Not specified if we wanted User or Group Quota! Getting both then via 'du -s --block-size=512' for Vsite $name\n");
            %quotas = fetch_group_disk_usage($name);
            $currQuota = "$name,ok,ok," . $quotas{$name}{used} . ",0,0,," . $quotas{$name}{quota} . ",0,0";
        }

        &debug_msg("getquota result for $name: $currQuota \n");
        @gqres = split /,/, $currQuota;
        @quotaresult = ($volume, $gqres[0], $gqres[4], $gqres[5], $gqres[8], $gqres[9]);
    }

    return @quotaresult;
}

sub getquotausage
# Get the quota *and* quota usage for a user or group.
# Arguments: cce connection, user or group object, and oid
# Returns: 1 for success, a string explaining failure otherwise.
{

    my ($cce, $obj, $oid) = @_;

    my $type = $obj->{CLASS};
    my $name = $obj->{name};

    my ($ok, $disk, $disk_old) = $cce->get($oid, 'Disk');
    my $limit = $disk->{quota};

    # be explicit about this on destroy to avoid confusion in the future
    if ($cce->event_is_destroy()) {
        # always set to unlimited on destroy
        $limit = -1;
    }
    
    my @dirs = ();
    my $quotaresult = {};
    my @gqres =();
    my $home = $Base::HomeDir::HOME_ROOT;
    my $BlocksPerMB = $DiskInfo::BYTES_PER_BLOCK;
    # user or group
    &debug_msg("Quota type is: $type \n");
    &debug_msg("Quota limit is: $limit \n");
    &debug_msg("Quota disk is: $disk \n");
    &debug_msg("Quota oid is: $oid \n");

    # volume for users lives in main namespace
    $volume = '/home';
    $haveHome = `mount|grep "/home"|wc -l`;
    chomp($haveHome);
    if ($haveHome eq 0) {
        $volume = '/';
    }
    if ($dir) {
        $haveDirQuota = `mount|grep "$dir"|wc -l`;
        chomp($haveDirQuota);
        if ($haveDirQuota eq 1) {
            $volume = $dir;
        }
    }

    my $realquotas = disk_has_quota_main($volume); # Determines if we have real quota support or need to use 'du -s --block-size=512'

    if ($type eq 'User') {
        $type = 0;
        if (($name eq "admin") || ($name eq "alter-admin")) {
            # If we're creating the 'admin' User, we don't really need quota for him.
            # We need the admin account so much that we simply ignore any quota issues
            # that could potentially arise.
            &debug_msg("Quota name is: $name - returning 1 (success)\n");
            return 1;
        }
    } 
    else {
        $type = 1;
    }

    if ($realquotas) {
        # Use real quotas
        &debug_msg("Using real quotas for $name \n");

        # LC_ALL=C /usr/sbin/repquota -u -O csv /
        # User,BlockStatus,FileStatus,BlockUsed,BlockSoftLimit,BlockHardLimit,BlockGrace,FileUsed,FileSoftLimit,FileHardLimit,FileGrace

        if ($type eq 0) {
            # Get Quota for User:
            &debug_msg("Getting User Quota: '$repquota_cmd -u -O csv $volume|grep \"^$name,\"'\n");
            $currQuota = `LC_ALL=C $repquota_cmd -u -O csv $volume|grep "^$name,"`;
        }
        elsif ($type eq 1) {
            # Get Quota for Group:
            &debug_msg("Getting Group Quota: '$repquota_cmd -g -O csv $volume|grep "^$name,"'\n");
            $currQuota = `LC_ALL=C $repquota_cmd -g -O csv $volume|grep "^$name,"`;
        }
        else {
            &debug_msg("Not specified if we wanted User or Group Quota! Getting both then: '$repquota_cmd -ug -O csv $volume|grep "^$name,"'\n");
            $currQuota = `LC_ALL=C $repquota_cmd -ug -O csv $volume|grep "^$name,"`;
        }
        &debug_msg("getquota result for $name: $currQuota \n");
        @gqres = split /,/, $currQuota;
        $quotaresult = { 'BlockUsed' => $gqres[3], 'BlockSoftLimit' => $gqres[4], 'BlockHardLimit' => $gqres[5], 'FileSoftLimit' => $gqres[8], 'FileHardLimit' => $gqres[9]};
    }
    else {
        # Use 'du -s --block-size=512' for getting quotas
        if ($type eq 0) {
            # Get Quota for User:
            &debug_msg("Getting User Quota via 'du -s --block-size=512' for User $name\n");
            %quotas = fetch_user_disk_usage($name);
            $currQuota = "$name,ok,ok," . $quotas{$name}{used} . ",0,0,," . $quotas{$name}{quota} . ",0,0";
        }
        elsif ($type eq 1) {
            # Get Quota for Group:
            &debug_msg("Getting Group Quota via 'du -s --block-size=512' for Vsite $name\n");
            %quotas = fetch_group_disk_usage($name);
            $currQuota = "$name,ok,ok," . $quotas{$name}{used} . ",0,0,," . $quotas{$name}{quota} . ",0,0";
        }
        else {
            &debug_msg("Not specified if we wanted User or Group Quota! Getting both then via 'du -s --block-size=512' for Vsite $name\n");
            %quotas = fetch_group_disk_usage($name);
            $currQuota = "$name,ok,ok," . $quotas{$name}{used} . ",0,0,," . $quotas{$name}{quota} . ",0,0";
            &debug_msg("getquota result for $name: $currQuota \n");
        }

        &debug_msg("getquota result for $name: $currQuota \n");
        @gqres = split /,/, $currQuota;
        $quotaresult = { 'BlockUsed' => $gqres[3], 'BlockSoftLimit' => $gqres[4], 'BlockHardLimit' => $gqres[5], 'FileSoftLimit' => $gqres[8], 'FileHardLimit' => $gqres[9]};
    }

    return $quotaresult;
}

#
### Fetch simulated quota stats:
#

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

sub fetch_group_disk_usage {
    my ($name) = @_;
    my $base_dir = '/home/.sites';
    my %group_disk_usage;
    my $dir = $base_dir . '/' . $name;

    if (-d $dir) {
        my $group = $name;

        my $group_disk_allowance = get_group_quota_allowance($group);

        $group_disk_usage{$group}{used} = get_dir_size($dir);
        $group_disk_usage{$group}{quota} = $group_disk_allowance;
    }
    return %group_disk_usage;
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

sub fetch_user_disk_usage {
    my ($name) = @_;

    &debug_msg("fetch_user_disk_usage(): Using 'du -s --block-size=512' quotas for $name \n");

    # For now, let's assume the quota allowance is fetched or predefined somewhere
    my $user_disk_allowance = get_user_quota_allowance($name);

    my $dir = $pw->home($name);

    &debug_msg("fetch_user_disk_usage(): Directory for $name is $dir\n");

    $user_disk_usage{$name}{used} = get_dir_size($dir);
    $user_disk_usage{$name}{quota} = $user_disk_allowance;

    # Check of user is siteAdmin:
    get_siteAdmins();

    &debug_msg("Got siteAdmin info\n");

    if (($siteAdmin_hash_reverse{$name}) && ($siteDirs{$siteAdmin_hash_reverse{$name}})) {
        my $webdirQuota = get_dir_size($siteDirs{$siteAdmin_hash_reverse{$name}});
        $user_disk_usage{$name}{used} += $webdirQuota;
    }
    return %user_disk_usage;
}

sub get_user_quota_allowance {
    my ($username) = @_;
    my $cce_user_DiskQuota = 0;
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

# get_dir_size: Uses 'du --block-size=512' to match kernel quota accounting
# Includes all metadata, directories, and allocated blocks — unlike find+stat %b
# This ensures accurate quota emulation when native quotas are unavailable:

sub get_dir_size {
    my ($dir) = @_;
    return 0 unless $dir && -d $dir;

    # Shell-escape the directory path
    $dir =~ s/'/'"'"'/g;
    $dir = "'$dir'";

    # Use du with 512-byte blocks to match quota allocation
    my $output = `LC_ALL=C du -s --block-size=512 $dir 2>/dev/null | cut -f1`;

    # Fix: Avoid chomp on // expression
    $output //= '';
    chomp $output;

    # Sanitize: remove non-digits
    $output =~ s/\D//g;
    $output ||= 0;

    # Convert 512-byte blocks → KB
    return int(($output * 512) / 1024);
}

# Simple shell escaping
sub shell_escape {
    my ($str) = @_;
    $str =~ s/'/'"'"'/g;
    return "'$str'";
}

#
### Disk quota check routines:
#

# Function to execute a shell command and capture the output
sub run_command {
    my ($command) = @_;
    my @output = `$command 2>&1`;
    chomp @output;
    return @output;
}

# Function to detect the filesystem type
sub get_filesystem_type {
    my ($mount_point) = @_;
    my @output = run_command("LC_ALL=C df -T $mount_point | tail -1 | awk '{print \$2}'");
    return $output[0];
}

# Function to check if quota files exist and are non-empty (for EXT3/EXT4)
sub check_quota_files {
    my ($mount_point) = @_;
    my $quota_user_file = "$mount_point/aquota.user";
    my $quota_group_file = "$mount_point/aquota.group";
    
    return (-e $quota_user_file && -s $quota_user_file && -e $quota_group_file && -s $quota_group_file);
}

# Function to check quotas on EXT3/EXT4
sub check_ext_quotas {
    my ($mount_point) = @_;
    return check_quota_files($mount_point);
}

# Function to check quotas on XFS
sub check_xfs_quotas {
    my ($mount_point) = @_;
    my @output = run_command("LC_ALL=C xfs_quota -x -c 'report -h' $mount_point");
    
    foreach my $line (@output) {
        if ($line =~ /User quota on/i || $line =~ /Group quota on/i) {
            return 1;
        }
    }
    
    return 0;
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
        &debug_msg("Unsupported filesystem type: $fs_type on $mount_point\n");
        $quotas_enabled = 0;
    }
    
    return $quotas_enabled;
}

# Main routine for checking availability of disk quotas for BlueOnyx
sub disk_quota_availability_check {
    my ($mount_point) = @_;  # Set to the mount point you want to check
    
    my $quotas_enabled = check_quotas($mount_point);
    
    if ($quotas_enabled) {
        # Quotas are enabled on the filesystem.
        &debug_msg("Quotas are enabled on filesystem type: $fs_type on $mount_point\n");
        return 1;
    }
    else {
        # Quotas are not enabled on the filesystem.
        &debug_msg("Quotas are NOT enabled on filesystem type: $fs_type on $mount_point\n");
        return 0;
    }
}

#
### Check if server has file-system quotas enabled:
#

sub disk_has_quota_main {
    my $mount_point = "@_";

    # For Debugging: To enable fake quota uncommont the next line:
    #return 0;
    
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
    my @output = run_command("LC_ALL=C xfs_quota -x -c 'report -h' $mount_point");
    
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

#
### Debug printer:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "Disk.pm: $msg");
        closelog;
    }
}

1;

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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
