#!/usr/bin/perl -I/usr/sausalito/perl

use strict;
use warnings;
use POSIX qw(getpwnam);
use File::Path qw(make_path remove_tree);
use Cwd;

use CCE;
use Sauce::Service;

# Debugging switch:
my $DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
}

my $cce = new CCE;
$cce->connectfd();

my $user = $cce->event_object();

my @oids = $cce->find('System');
if (!defined($oids[0])) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $Radicale) = $cce->get($oids[0], "Radicale");

# Get the username from the current user
my $username = $user->{'name'};

&debug_msg("Restore of all collections for User (" . $username . ") requested.\n");

# Get the user's home directory
my $home_directory = (getpwnam($username))[7];

# Construct the path to the backup directory
my $backup_dir = "$home_directory/.radicale";

&debug_msg("Backup directory for user $username is: " . $backup_dir . "\n");

# Check if the backup directory exists
unless (-d $backup_dir) {
    &debug_msg("Backup directory for user $username does not exist.\n");
    $cce->bye('FAIL', '[[base-organizer.ErrorRestoringBackup]]');
    exit 1;
}

# Get the list of backup tarballs
opendir(my $dir, $backup_dir) or die "Failed to open directory: $!";
my @backup_files = grep { /\.tar\.gz$/ && -f "$backup_dir/$_" } readdir($dir);
closedir($dir);

# Restore each backup tarball
foreach my $backup_file (@backup_files) {
    my ($collection_name) = $backup_file =~ /^(.+)\.tar\.gz$/;
    my $tarball_file = "$backup_dir/$backup_file";
    my $collection_path = "/var/lib/radicale/collections/collection-root/$username/$collection_name";
    my $user_collection_path = "/var/lib/radicale/collections/collection-root/$username";

    if (! -d $user_collection_path) {
        system("mkdir -p $user_collection_path");
        system("chown -R radicale:radicale $user_collection_path");
        system("chmod 0750 $user_collection_path");
    }

    # Check if the collection directory already exists
    if (-d $collection_path) {
        &debug_msg("Collection directory $collection_path already exists. Deleting it first.\n");
        remove_tree($collection_path);
    }

    # Change to the user's collection directory
    chdir($user_collection_path) or &debug_msg("Failed to change directory: $!\n");

    # Extract the tarball
    system("tar xzf $tarball_file");
    my $exit_status = $? >> 8;
    if ($exit_status != 0) {
        &debug_msg("Failed to extract $tarball_file\n");
        next;
    }

    # Fix UID/GID of restored collection
    system("chown -R radicale:radicale $collection_path");

    &debug_msg("Restore of collection $collection_name completed.\n");
}

# Restart Radicale (if enabled):
service_toggle_init('radicale', $Radicale->{enabled});
service_run_init('radicale', $Radicale->{enabled});

&debug_msg("Restore of all collections completed.\n");

$cce->bye('SUCCESS');
exit(0);

#
### Subroutines:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0, '', 'user');
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