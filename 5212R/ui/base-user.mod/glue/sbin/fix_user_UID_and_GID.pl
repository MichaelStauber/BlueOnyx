#!/usr/bin/perl
#
# $Id: fix_user_UID_and_GID.pl
#
# This script fixes the UID and GID of all Vsite users. Also fixed ownership of Vsite logs.
#
# Please note: This script does NOT change UID or GID of files in the webspace of a Vsite!
#
# Usage:
#
# Just run this script without any parameters. You can run it as often as you like
# without causing any problems.

use Unix::PasswdFile;
use Unix::GroupFile;

# Root check
my $id = `id -u`;
chomp($id);
if ($id ne "0") {
    print "$0 must be run by user 'root'!.\n";

    $cce->bye('FAIL');
    exit(1);
}

# Make backup copy of /etc/passwd - just in case:
system("/bin/cp /etc/passwd /etc/.passwd.pre-uid-fixing");

# Give brief startup info:
print "Processing UID/GID fix for all users.\n";

$pw = new Unix::PasswdFile "/etc/passwd";
$found_one = "0";

foreach $user ($pw->users) {
    undef(@groupworkaround);
    undef $home;
    $uid = $pw->uid($user);
    $gid = $pw->gid($user);
    $home = $pw->home($user);
    @groupworkaround = split(/\//, $home);

    if (($uid >= "500") && ($groupworkaround[2] eq ".sites")) {

    # Determine GID of user by parsing /etc/group:
    $grp = new Unix::GroupFile "/etc/group";
    $real_gid_of_user = $grp->gid($groupworkaround[3]);
    undef $grp;

    # Fixing GID of everything in the users home directory:
    if (-d "$home") {
        system("/bin/chown -R $uid:$real_gid_of_user $home");
    }

    $found_one++;
    }

    # Fix UID / GID of logfiles:
    if (($uid >= "500") && ($groupworkaround[5] eq "logs")) {
        # This is a SITExx-logs user
        if (-d "$home") {

            # Get GID:
            $grp = new Unix::GroupFile "/etc/group";
            $real_gid_of_user = $grp->gid($groupworkaround[3]);
            undef $grp;

            # Do the chown:
            print "Setting UID and GID of $home to $user ($uid) and GID $groupworkaround[3] ($real_gid_of_user) \n";
            system("/bin/chown -R $user:$groupworkaround[3] $home");
            $found_one++;
        }
    }
}

undef $pw;

if ($found_one gt "0") {
    print "Everything done. All users are fixed.\n";
}
else {
    print "Nothing to do.\n";
}

exit(0);

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2006-2009 NuOnce Networks, Inc. 
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