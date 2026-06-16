#!/usr/bin/perl -I/usr/sausalito/perl

use strict;
use warnings;
use POSIX qw(getpwnam);
use File::Path qw(make_path);
use Cwd;

use CCE;

# Debugging switch:
my $DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
}

my $cce = new CCE;
$cce->connectfd();

my $user = $cce->event_object();

# Get the username from the current user
my $username = $user->{'name'};

&debug_msg("Backup of all collections for User (" . $username . ") requested.\n");

# Get the user's home directory
my $home_directory = (getpwnam($username))[7];

# Construct the path to the user's collection directory
my $collection_dir = "/var/lib/radicale/collections/collection-root/$username";

&debug_msg("Source collection directory is: " . $collection_dir . "\n");

# Check if the collection directory exists
unless (-d $collection_dir) {
    &debug_msg("Collection directory for user $username does not exist.\n");
    $cce->bye('FAIL', '[[base-organizer.ErrorCreatingBackup]]');
    exit 1;
}

# Get the list of collection directories
opendir(my $dir, $collection_dir) or die "Failed to open directory: $!";
my @collection_dirs = grep { !/^\./ && -d "$collection_dir/$_" } readdir($dir);
closedir($dir);

# Create the backup directory if it doesn't exist
my $backup_dir = "$home_directory/.radicale";
&debug_msg("Backup directory for user $username is: " . $backup_dir . "\n");
unless (-d $backup_dir) {
    make_path($backup_dir) or die "Failed to create backup directory: $!";
}

# Backup each collection directory
foreach my $collection_name (@collection_dirs) {
    my $collection_path = "$collection_dir/$collection_name";
    my $tarball_file = "$backup_dir/$collection_name.tar.gz";
    
    # Create the tarball
    my $current_dir = getcwd();
    chdir($collection_dir) or die "Failed to change directory: $!";
    system("tar czf $tarball_file $collection_name");
    chdir($current_dir) or die "Failed to change directory: $!";

    &debug_msg("Backup of collection $collection_name completed.\n");
}

&debug_msg("Backup of all collections completed.\n");

$cce->bye('SUCCESS');
exit(0);

#
### Subroutines:
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