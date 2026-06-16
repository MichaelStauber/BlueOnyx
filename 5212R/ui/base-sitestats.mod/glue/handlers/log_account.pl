#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: log_account.pl
#
# manages the per-site log user account and ServiceQuota object
# for site-level split logs and usage stats

use CCE;
use Sauce::Config;
use Sauce::Util;
use Base::User qw(useradd userdel);

# debugging flag, set to 1 to turn on logging to STDERR
my $DEBUG = 0;  
if ($DEBUG) 
{ 
    use Data::Dumper; 
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# make sure umask is sane, we create and chmod dirs here
umask(002);

my $cce = new CCE;
$cce->connectfd();

my $err; # Global error message/return state

# We're triggered on Vsite create/mod/edit
my $oid = $cce->event_oid(); 
my $obj = $cce->event_object(); # Vsite
my $obj_new = $cce->event_new();
my $obj_old = $cce->event_old();

# Find matching ServiceQuota objects
my $sitegroup = $obj_old->{name};
$sitegroup ||= $obj->{name};
my @oids = $cce->find('ServiceQuota', {
    'site' => $sitegroup,
    'label' => '[[base-sitestats.statsQuota]]',
    }); 

if($cce->event_is_destroy())
{
    # destroy the associated ServiceQuota object
    &debug_msg("Deleting ServiceQuota objects:\n");

    foreach my $i (@oids)
    {
        my ($ret, @info) = $cce->destroy($i);
        $err .= '[[base-sitestats.couldNotClearStatsQuotaMon]]' unless ($ret);
        &debug_msg("destroy $i $ret\n");
    }
    
    # Delete the site logs user
    my $user = &group_to_user($sitegroup);
    
    if(getpwnam($user))
    {
        # delete user, no need to tell userdel to remove dir
        # vsite destroy will take care of that
        &debug_msg("Running 'userdel' on $user:\n");
        userdel(0, $user);
    }
} 
elsif ($cce->event_is_create())
{
    # make sure vsite_create.pl has created the system group already
    #if (!$obj->{name})
    if (!$obj->{name} || (!getgrnam($obj->{name})))
    {
        $cce->bye('DEFER');
        exit(0);
    }

    if (getgrnam($obj->{name})) {
        &debug_msg("Group " . $obj->{name} . " exists!\n");
    }
    else {
        &debug_msg("Group " . $obj->{name} . " does NOT exist!\n");
    }

    # create a ServiceQuota object
    &debug_msg("Creating ServiceQuota object\n");

    $owner = &group_to_user($sitegroup);

    my($ret) = $cce->create('ServiceQuota', { 
        'label' => '[[base-sitestats.statsQuota]]',
        'site' => $obj->{name},
        'account' => $owner,
        'isgroup' => 0,
        'quota' => 20,
        'used' => 0,
        });
        
    $err .= '[[base-sitestats.couldNotSetStatsQuotaMon]]' unless ($ret);

    ($ret) = $cce->set($oid, 'SiteStats', { 
        'owner' => $owner,
        });
        
    $err .= '[[base-sitestats.couldNotSetStatsQuotaMon]]' unless ($ret);

    # Create the site logs user
    my ($group_name, undef, $group_gid) = getgrnam($obj->{name});
    my @group_by_gid = getgrgid($group_gid);
    my @group_info = getgrnam($obj->{name});
    my $group_file_line = `grep '^$obj->{name}:' /etc/group 2>/dev/null`;
    chomp($group_file_line);
    my $group_members = defined($group_info[3]) ? $group_info[3] : '';
    &debug_msg("pre-useradd group lookup: getgrnam=" . ($group_name || '<missing>') .
               " gid=" . ($group_gid || '<missing>') .
               " getgrgid=" . (@group_by_gid ? $group_by_gid[0] : '<missing>') .
               " members=" . ($group_members || '<none>') .
               " etc_group=" . ($group_file_line || '<missing>') . "\n");

    my $user = {
                    'comment' => $obj->{fqdn},
                    'homedir' => $obj->{basedir}.'/var/logs',
                    # useradd() is picky about the group lookup path, so prefer
                    # the numeric GID when we already have a valid group entry.
                    'group' => $group_gid || $obj->{name},
                    'shell' => Sauce::Config::bad_shell(),
                    'name' => $owner,
                    };

    &debug_msg(Dumper($user));

    # this also creates the logs directory
    my $useradd_ok = 0;
    for my $attempt (1 .. 3) {
        my ($ret) = useradd($user);
        if ($ret) {
            $useradd_ok = 1;
            last;
        }

        my $exit = $? >> 8;
        my $group_probe = `getent group $obj->{name} 2>/dev/null`;
        chomp($group_probe);
        &debug_msg("useradd attempt $attempt failed: raw_status=$? exit=$exit getent_group=" . ($group_probe || '<missing>') . "\n");
        if (($exit == 16) && ($attempt < 3)) {
            &debug_msg("useradd reported missing group '$obj->{name}' on attempt $attempt, retrying\n");
            sleep 1;
            next;
        }

        $err .= '[[base-sitestats.couldNotCreateStatsUser]]';
        &debug_msg("Error: $err\n");
        last;
    }

    if ($useradd_ok && -d $user->{homedir}) {
        # make sure the dir permissions are correct
        Sauce::Util::chmodfile(02751, $user->{homedir});
        $group = $obj->{name};
        $homedir = $user->{homedir};
        $vardir = $obj->{basedir}.'/var';
        system("chown root:root $vardir");
        system("chown $owner:$group $homedir");
        &debug_msg("Running: chown $owner:$group $homedir\n");
    }
}

if($err)
{
    $cce->bye('FAIL', $err);
    exit 1;
}
else
{
    $cce->bye('SUCCESS');
    exit 0;
}


sub group_to_user
{
    my $x = $_[0];
    $x =~ tr/[a-z]/[A-Z]/;
    return $x.'-logs'; 
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
