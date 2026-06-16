#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: root_access.pl
#
# add or remove a user from the wheel group if they are allowed root access
# via the su program.  also create a "root" account for them

use CCE;
use Base::Group qw(group_add_members group_rem_members);
use Base::User qw(system_useradd usermod userdel);

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
    &debug_msg("Debugging enabled for root_access.pl\n");
}

my $cce = new CCE('Domain' => 'base-user');
$cce->connectfd();

my $root_prefix = 'root-';
my $user;
if ($cce->event_is_destroy()) {
    $user = $cce->event_old();
}
else {
    $user = $cce->event_object();
}

my $UserName = $user->{name};
&debug_msg("INFO: Detected Username: $UserName \n");

if (!$user->{name}) {
    &debug_msg("No user-name yet. Defering.\n");
    $cce->bye('DEFER');
    exit(0);
}

my ($ok, $root_access) = $cce->get($cce->event_oid(), 'RootAccess');

if (!$ok) {
    &debug_msg("FAIL: cantGetRootAccess for $UserName \n");
    $cce->bye('FAIL', 'cantGetRootAccess', { 'name' => $UserName });
    exit(1);
}

if (!$root_access->{enabled} || $cce->event_is_destroy()) {
    my $ret = group_rem_members('wheel', $UserName);

    &debug_msg("INFO: User $UserName has root access.\n");

    if (!$ret) {
        &debug_msg("FAIL: cantDisableRootAccess for $UserName \n");
        $cce->bye('FAIL', 'cantDisableRootAccess', { 'name' => $UserName });
        exit(1);
    }

    # destroy their root account
    # ($ret) = userdel(0, $root_prefix . $UserName); # Commented out for now. 
    # We need to --force the userdel transactions for AlterAdmins on EL6, because the userdel command
    # claims that the user in question is logged in - even if it is not. So we use userdel directly
    # instead:
    $goaway_user = $root_prefix . $UserName;
    &debug_msg("INFO: Destroying account for User $UserName via: /usr/sbin/userdel --force $goaway_user\n");
    $ret = system("/usr/sbin/userdel --force $goaway_user");
    if (!$ret) {
        # Yes, we run it again if it fails!
        $ret = system("/usr/sbin/userdel --force $goaway_user");
    }

    # if this fails it is really bad, don't let the user be destroyed
    # or there will be a back door account
    if (!$ret) {
        &debug_msg("FAIL: cantDeleteAlterRoot for $UserName \n");
        $cce->bye('FAIL', 'cantDeleteAlterRoot', { 'root' => ($root_prefix . $UserName) });
        exit(1);
    }

    # clean up root email alias
    my ($alias) = $cce->find('ProtectedEmailAlias', {
                                'action' => $UserName,
                                'alias' => ($root_prefix . $UserName),
                                'fqdn' => ''
                                });
    if ($alias) {
        ($ok) = $cce->destroy($alias);
        if (!$ok) {
            &debug_msg("FAIL during destroy of ProtectedEmailAlias for $UserName \n");
            $cce->bye('FAIL');
            exit(1);
        }
    }
}
elsif ($root_access->{enabled}) {
    my $ret = group_add_members('wheel', $UserName);

    &debug_msg("INFO: Adding User $UserName to Wheel. Return state: $ret\n");

    if (!$ret) {
        &debug_msg("FAIL cantEnableRootAccess for $UserName \n");
        $cce->bye('FAIL', 'cantEnableRootAccess', { 'name' => $UserName });
        exit(1);
    }

    # create the alterroot account if necessary
    &debug_msg("INFO: Creating alterroot if necessary for User $UserName\n");
    my (@pwent) = getpwnam($root_prefix . $UserName);
    if ($pwent[0] ne $root_prefix . $UserName) {
        my $alterroot = {
                            'name' => ($root_prefix . $UserName),
                            'uid' => 0,
                            'group' => 'root',
                            'homedir' => '/root',
                            'shell' => '/bin/bash',
                            'password' => $user->{md5_password},
                            'dont_create_home' => 1
                        };

        my @ret = system_useradd($alterroot);

        if (!$ret[0]) {
            &debug_msg("FAIL cantCreateAlterRoot for $UserName \n");
            $cce->bye('FAIL', 'cantCreateAlterRoot', { 'root' => ($root_prefix . $UserName) });
            exit(1);
        }

        if ($user->{md5_password} eq "") {
            &debug_msg("WARN: No md5_password set for User $UserName. Setting hash of 'root' account instead.\n");
            $RootPWhash = `getent shadow|grep root:|cut -d : -f2`;
            chomp($RootPWhash);
            &debug_msg("INFO: md5_password for 'root': $RootPWhash\n");
            $assembly = "'" . $root_prefix . $UserName . ':' . $RootPWhash . "'";
            &debug_msg("INFO: Sending: echo $assembly|chpasswd -e\n");
            system("echo $assembly|chpasswd -e");
        }
    }
    else {   # update user password
        my ($ret) = usermod({
                            'name' => ($root_prefix . $UserName),
                            'password' => $user->{md5_password}
                        });

        &debug_msg("INFO: Using 'usermod' to update password for $root_prefix $UserName - Return state: $ret\n");

        if ($ret != '0') {
            &debug_msg("FAIL cantUpdateAlterRoot for $UserName \n");
            $cce->bye('FAIL', 'cantUpdateAlterRoot',
                    { 
                        'root' => ($root_prefix . $UserName),
                        'name' => $UserName
                    });
            exit(1);
        }
    }

    # make sure the root-account gets email sent to the user's account
    my ($alias) = $cce->find('ProtectedEmailAlias', {
                                'action' => $UserName,
                                'alias' => ($root_prefix . $UserName),
                                'fqdn' => ''
                                });
    if (!$alias) {
        ($ok) = $cce->create('ProtectedEmailAlias',
                        { 
                            'action' => $UserName, 
                            'alias' => ($root_prefix . $UserName),
                            'local_alias' => 1
                            });
        if (!$ok) {
            &debug_msg("FAIL during CREATE ProtectedEmailAlias for $UserName \n");
            $cce->bye('FAIL');
            exit(1);
        }
    }
} # end elsif($root_access->{enabled})

&debug_msg("SUCCESS on transactions for $UserName \n");
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

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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