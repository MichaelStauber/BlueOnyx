#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/disk
# get_quotas.pl - Determines quotas either using repquota or 'du -s --block-size=512' and CODB.

use strict;
use warnings;

my $login = (getpwuid $>);
die "must run as root" if $login ne 'root';

use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/disk);
use Base::HomeDir qw(homedir_get_group_dir);
use Disk qw(get_dir_size);
use Getopt::Long;
use CCE;
use Base::CustomPasswdFile;
use Base::CustomGroupFile;
use Sys::Syslog qw(:DEFAULT setlogsock);
use Data::Dumper;
use Base::HomeDir;

# Debugging switch:
my $DEBUG = 0;

my $homeDir = $Base::HomeDir::HOME_ROOT; # Get the location of user and group directories
my $realquotas = disk_has_quota_main($homeDir); # Determines if we have real quota support or need to use 'du -s --block-size=512'
debug_msg("HomeDir: $homeDir \n");
debug_msg("Real Quota-Support: $realquotas \n");

my $site = '';
my $sort = '';
my $descending = '';
my $help = '';
my $users_only = 1;
my $sites_only = '';

my $pw = Base::CustomPasswdFile->new();
my $gr = Base::CustomGroupFile->new() or die "Cannot open /etc/group: $!";

GetOptions(
    'site=s'       => \$site,
    'sort=s'       => \$sort,
    'descending'   => \$descending,
    'ascending'    => sub { $descending = 0 },
    'help'         => \$help,
    'users'        => sub { $users_only = 1; $sites_only = 0 },
    'sites'        => sub { $sites_only = 1; $users_only = 0 }
);

if ($help) {
    print_usage();
    exit;
}

my (@items, %quotas, %USERQUOTA, %GROUPQUOTA, @found_groups, %siteAdmin_hash, %siteAdmin_hash_reverse, %siteDirs);
my $siteAdmins;

# Connect to CCEd:
my $cce = CCE->new();
$cce->connectuds();

if ($realquotas) {
    get_quotas_repquota();
}
else {
    get_quotas_find_stat();
}

my (@results) = ();

foreach my $item (@items) {
    push @results, [ $item, $quotas{$item}{used} || 0, $quotas{$item}{quota} || 0 ];
}

if ($sort eq "usage") {
    @results = sort { $a->[1] <=> $b->[1] } @results;
}
elsif ($sort eq "quota") {
    @results = sort { $a->[2] <=> $b->[2] } @results;
}
else {
    @results = sort { $a->[0] cmp $b->[0] } @results;
}

if ($descending) {
    @results = reverse(@results);
}

foreach my $user (@results) {
    print join("\t", @$user), "\n";
}

$cce->bye("SUCCESS");
exit(0);

#
### Subs:
#

sub print_usage {
    print "Usage: get_quotas.pl [ --users ] [ --sites ] \n\t\t\t[ --sort=type ] [ --site=name ] [ --descending ] [ --ascending] [ --help ]\n";
    print " --users\t Get quotas for users. Default.\n";
    print " --sites\t Get quotas for sites.\n";
    print " --sort=type\t Type is one of 'quota', 'usage', 'name'. \n\t\t\tIf none are specified, defaults to 'name'.\n";
    print " --site=name\t Return the quotas for users that are on site called 'name'.\n\t\t\tName is the 'name' property in CCE of the site you wish to find. \n\t\t\tUsually, it's 'siteN' where N is some number. \n\t\t\tIf this option is not present, \n\t\t\tthe script will return all users on all sites\n";
    print " --ascending\t Sort in ascending order. Default.\n";
    print " --descending\t Sort in descending order.\n";
}

sub get_quotas_repquota {
    # Get all quotas via 'repquota' from the 'quota' RPM. Needs to be v4.0.4 
    # or better as the '-O csv' has only recently been added.
    my $raw_userquota = `LC_ALL=C /usr/sbin/repquota -au -O csv|grep -v ^#|grep -v ^User`;
    chomp $raw_userquota;
    my @uq = split /\n/, $raw_userquota;
    foreach my $x (@uq) {
        chomp($x);
        next if $x =~ /^\s*$/;               # skip blank lines
        my @row = split /,/, $x;
        $USERQUOTA{$row[0]} = { 
            'BlockUsed' => $row[3], 
            'BlockSoftLimit' => $row[4], 
            'BlockHardLimit' => $row[5], 
            'FileSoftLimit' => $row[8], 
            'FileHardLimit' => $row[9]
        };
    }

    my $raw_groupquota = `LC_ALL=C /usr/sbin/repquota -ag -O csv|grep -v ^#|grep -v ^Group`;
    chomp $raw_groupquota;
    my @gq = split /\n/, $raw_groupquota;
    foreach my $x (@gq) {
        chomp($x);
        next if $x =~ /^\s*$/;               # skip blank lines
        my @row = split /,/, $x;
        push @found_groups, $row[0];
        $GROUPQUOTA{$row[0]} = { 
            'BlockUsed' => $row[3], 
            'BlockSoftLimit' => $row[4], 
            'BlockHardLimit' => $row[5], 
            'FileSoftLimit' => $row[8], 
            'FileHardLimit' => $row[9]
        };
    }

    if ($sites_only) {
        @items = sites();
        %quotas = siteusage_repquota();
    }
    elsif ($users_only && $site) {
        @items = site_users($site);
        %quotas = userusage_repquota();
    }
    elsif ($users_only && !$site) {
        @items = all_users();
        %quotas = userusage_repquota();
    }
}

sub get_quotas_find_stat {
    if ($sites_only) {
        @items = sites();
        %quotas = fetch_group_disk_usage();
    }
    elsif ($users_only && $site) {
        $siteAdmins = get_siteAdmins();
        @items = site_users($site);
        %quotas = fetch_user_disk_usage();
    }
    elsif ($users_only && !$site) {
        $siteAdmins = get_siteAdmins();
        @items = all_users();
        %quotas = fetch_user_disk_usage();
    }
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
