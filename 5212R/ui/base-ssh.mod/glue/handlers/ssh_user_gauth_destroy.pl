#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: ssh_keycert.pl
#
# This handler is run when a user with Shell access and configure Google Authenticator
# is deleted. Prime purpose: Remove the user from the group 'google-authenticator'
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debug enabled.\n");
}

#
#### No configureable options below!
#

use CCE;
use Sauce::Util;
use Unix::PasswdFile;
use Unix::GroupFile;

my $cce = new CCE;

$cce->connectfd();

# retreive user object data:
my $oid = $cce->event_oid();

my $old = $cce->event_old();

# verify that we're really deleting a user
if (!$oid ) {
    $cce->warn('[[base-user.Invalid-object]]');
    $cce->bye("FAIL");
}

# verify that the user exists
my @user_info = getpwnam($old->{name});
if (!@user_info) {
    $cce->warn("[[base-user.Already-Destroyed,name=".$old->{name}."]]");
    # already destroyed?
    $cce->bye("SUCCESS");
    exit(0);
}

# Parse /etc/passwd:
$pw = new Unix::PasswdFile "/etc/passwd";

$user_home_dir = $pw->home($old->{name});

# Set work directory for this user get UID/GID:
$uid = $pw->uid($old->{name});
$gid = $pw->gid($old->{name});
$home = $pw->home($old->{name});

# Need to undef $pw or /etc/passwd remains locked:
undef $pw;

&debug_msg("User (" . $old->{name} . ") UID/GID: $uid/$gid and homedir: $home\n");

$gauth_file = $home . '/.google_authenticator';
$gauth_image = $home . '/.google_authenticator.png';

if (! -f $gauth_file) {
    &debug_msg("Removing user $old->{name} from group 'google-authenticator\n");
    system("gpasswd -d $old->{name} google-authenticator");
}

$cce->bye('SUCCESS');
exit(0);

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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