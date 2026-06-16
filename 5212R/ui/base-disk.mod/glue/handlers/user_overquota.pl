#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/disk
# $Id: user_overquota.pl
#

use CCE;
use Disk;
use lib qw(/usr/sausalito/perl);
use Base::HomeDir;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG)
{
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

$cce = new CCE('Domain' => 'base-disk');
$cce->connectfd();

# get user
$user = $cce->event_object();
$oid = $cce->event_oid();

&debug_msg("User: " . $user->{name} . " - Vsite: " . $user->{site} . "\n");
$user_home_dir = Base::HomeDir::homedir_get_user_dir($user->{name}, $user->{site});
&debug_msg("User's home directory: $user_home_dir\n");

$quota_lockfile = $user_home_dir . '/.user_overquota';
($ok, $User_Disk) = $cce->get($oid, "Disk");

if (($User_Disk->{over_quota} eq "1") && (!-f $quota_lockfile)) {
    &debug_msg("User's $user->{'name'} is over-quota. Creating lockfile $quota_lockfile.\n");
    system("touch $quota_lockfile");
}
if (($User_Disk->{over_quota} eq "0") && (-f $quota_lockfile)) {
    &debug_msg("User's $user->{'name'} no longer is over-quota. Removing lockfile $quota_lockfile.\n");
    system("rm -f $quota_lockfile");
}

$cce->bye('SUCCESS');
exit(0);

sub debug_msg {
    if ($DEBUG) {
        $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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