#!/usr/bin/perl -I/usr/sausalito/perl

use strict;
use warnings;
use POSIX qw(getpwnam);
use File::Path qw(make_path remove_tree);
use File::Copy qw(copy);
use Cwd;

use CCE;
use Sauce::Service;
use Data::Dumper;

my $cce = new CCE;
$cce->connectuds();

my @oids = $cce->find('System');
if (!defined($oids[0])) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $Radicale) = $cce->get($oids[0], "Radicale");

# Get the username from the command line argument
my $username = shift || die "Username not provided.\n";

# Get the operation (backup or restore) from the command line argument
my $operation = shift || die "Operation not provided.\n";

# Get the collection directory name from the command line argument
my $collection_name = shift || die "Username not provided.\n";

# Get the user's home directory
my $home_directory = '';
my @user = getpwnam($username);
if (@user) {
    $home_directory = $user[7];
}

# Backup directory:
my $backup_dir = "$home_directory/.radicale";

# Construct the path to the user's collection directory
my $collection_dir = "/var/lib/radicale/collections/collection-root/$username";

# Check if this user's collection directory doesn't exists yet:
if ((! -d $collection_dir) && (-d $home_directory)) {
    # It's a valid user, but has no collection_dir yet:
    system("mkdir -p $collection_dir");
    system("chown -R radicale:radicale $collection_dir");
    system("chmod 0750 $collection_dir");
}

# Check if the collection name is provided
unless ($collection_name) {
    print "Please provide a valid collection name.\n";
    $cce->bye('FAIL');
    exit 1;
}

# Construct the path to the collection directory
my $collection_path = "$collection_dir/$collection_name";

# Check if the collection directory exists
if ((! -d $collection_path) && ($operation eq "backup")) {
    print "Collection directory $collection_path does not exist.\n";
    $cce->bye('FAIL');
    exit 1;
}

if ($operation eq 'backup') {
    # Create the backup directory if it doesn't exist
    unless (-d $backup_dir) {
        make_path($backup_dir) or die "Failed to create backup directory: $!";
        chown $user[2], $user[3], $backup_dir;
    }

    # Get the current working directory
    my $current_dir = getcwd();
    
    # Change to the collection directory
    chdir($collection_path) or die "Failed to change directory: $!";

    # Create the tarball:
    my $tarball_file = "$backup_dir/$collection_name.tar.gz";
    system("cd $collection_dir ; tar czf $tarball_file $collection_name");
    chown $user[2], $user[3], $tarball_file;

    # Change back to the original directory
    chdir($current_dir) or die "Failed to change directory: $!";

    print "Backup of collection $collection_name completed.\n";
    $cce->bye('SUCCESS');
    exit 0;
}
elsif ($operation eq 'restore') {
    # Check if the backup directory exists
    unless (-d $backup_dir) {
        print "Backup directory $backup_dir does not exist.\n";
        $cce->bye('FAIL');
        exit 1;
    }
    
    # Get the current working directory
    my $current_dir = getcwd();
    
    # Change to the backup directory
    chdir($backup_dir) or die "Failed to change directory: $!";
    
    # Check if the backup tarball exists
    my $tarball_file = "$backup_dir/$collection_name.tar.gz";
    unless (-e $tarball_file) {
        print "Backup tarball $tarball_file does not exist.\n";
        $cce->bye('FAIL');
        exit 1;
    }

    # Change to the collection directory
    chdir($collection_dir) or die "Failed to change directory: $!";

    # Extract the tarball
    system("cd $collection_dir ; tar zxf $tarball_file -C .");

    # Change back to the original directory
    chdir($current_dir) or die "Failed to change directory: $!";

    # Fix UID/GID of restored collection:
    system("chown -R radicale:radicale $collection_path");

    # Change back to the original directory
    chdir($current_dir) or die "Failed to change directory: $!";

    # Restart Radicale (if enabled):
    service_toggle_init('radicale', $Radicale->{enabled});
    service_run_init('radicale', $Radicale->{enabled});

    print "Restore of collection $collection_name completed.\n";
    $cce->bye('SUCCESS');
    exit 0;
}
else {
    print "Invalid operation. Please provide 'backup' or 'restore'.\n";
    $cce->bye('FAIL');
    exit 1;
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