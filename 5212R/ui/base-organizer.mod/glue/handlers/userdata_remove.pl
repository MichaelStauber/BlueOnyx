#!/usr/bin/perl -I/usr/sausalito/perl -I.
# $Id: userdata_remove.pl
# 
# User._DESTROY handler

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use Sauce::Service;

$radicale_collection_root = '/var/lib/radicale/collections/collection-root/';

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

# Verify that the user exists
my @user_info = getpwnam($old->{name});
if (!@user_info) {
    $cce->warn("[[base-user.Already-Destroyed,name=".$old->{name}."]]");
    # Already destroyed?
    $cce->bye("SUCCESS");
    exit(0);
}

# Find System Object:
my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

# Get Radicale NameSpace from System object:
my ($ok, $RadicaleNS) = $cce->get($oids[0], 'Radicale');
if (!$ok) {
    $cce->bye('FAIL');
    exit(1);
}

# Directory where the CalDAV/CardDAV data of this user resides:
$user_collection = $radicale_collection_root . $old->{name};

if (! -d $user_collection) {
    # User has no Radicale data:
    &debug_msg("User '" . $old->{name} . "' doesn not have CalDAV/CardDAV data under $user_collection. Nothing to do.\n");
}
else {
    # User has Radicale data. Delete it:
    &debug_msg("Removing CalDAV/CardDAV data directory $user_collection of User '" . $old->{name} . "'\n");
    system("rm -Rf $user_collection");

    # Conditionally restart Radicale:
    if ($RadicaleNS->{enabled} eq '1') {
        &debug_msg("Restarting Radicale\n");
        Sauce::Service::service_run_init('radicale', 'restart', 'nobg');
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
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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