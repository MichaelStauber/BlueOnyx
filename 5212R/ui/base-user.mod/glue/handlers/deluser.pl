#!/usr/bin/perl -w -I/usr/sausalito/perl -I.
# $Id: deluser.pl
# 
# User._DESTROY handler
# author: Jonathan Mayer <jmayer@cobalt.com>

use Sauce::Config;
use Sauce::Util;
use CCE;
use Base::User qw(user_kill_processes userdel);
use Base::HomeDir qw(homedir_create_user_link);

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd();

# retreive user object data:
my $oid = $cce->event_oid();

my $old = $cce->event_old();

# verify that we're really deleting a user
if (!$oid ) {
    &debug_msg("Attempting to delete invalid User Object.\n");
    $cce->warn('[[base-user.Invalid-object]]');
    $cce->bye("FAIL");
}

# verify that the user exists
my @user_info = getpwnam($old->{name});
if (!@user_info) {
    $cce->warn("[[base-user.Already-Destroyed,name=".$old->{name}."]]");
    &debug_msg("User '" . $old->{name} . "' has already been deleted.\n");
    # already destroyed?
    $cce->bye("SUCCESS");
    exit(0);
}

# kill all of this user's currently running processes:
user_kill_processes($old->{name});

# get rid of friendly symlink
#my ($user_link, $link_target) = homedir_create_user_link($old->{name}, $old->{site}, $old->{volume});
#unlink($user_link);
#Sauce::Util::addrollbackcommand("umask 000; /bin/ln -sf \"$link_target\" \"$user_link\"");

# clean the password file
if (!(userdel(1, $old->{name}))[0]) {
    $cce->bye('FAIL', '[[base-user.cantDeleteUser]]');
    exit(1);
}

# update workgroups
update_workgroups();

sub update_workgroups {
    my @oids = $cce->find("Workgroup", { 'members' => $old->{name} });
    foreach my $oid (@oids) {
        my ($ok, $obj) = $cce->get($oid);
        my (@members) = $cce->scalar_to_array($obj->{members});
        @members = grep {$_ ne $old->{name}} @members;
        $cce->set($oid, "", { 'members' => $cce->array_to_scalar(@members) } );
    }
}

$cce->bye("SUCCESS");
exit 0;

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