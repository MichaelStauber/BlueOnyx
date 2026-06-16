#!/usr/bin/perl

use strict;
use warnings;
use JSON;
use POSIX qw(getpwnam);
use File::stat;
use Data::Dumper;

# Get the username from the command line argument
my $username = shift || die "Username not provided.\n";

# Get the user's home directory
my $home_directory = '';
my @user = getpwnam($username);
if (@user) {
    $home_directory = $user[7];
}

# Construct the path to the user's collection directory
my $collection_dir = "/var/lib/radicale/collections/collection-root/$username";

# Check if the collection directory exists
unless (-d $collection_dir) {
    print "Collection directory for user $username does not exist.\n";
    exit 1;
}

# Retrieve the subdirectories in the collection directory
opendir(my $dir, $collection_dir) or die "Failed to open directory: $!";
my @subdirectories = grep { !/^\./ && -d "$collection_dir/$_" } readdir($dir);
closedir($dir);

# Create a hash to store the data
my %data;
$data{username} = $username;
$data{subdirectories} = \@subdirectories;

# Process .Radicale.props files in each subdirectory
foreach my $subdirectory (@subdirectories) {
    my $props_file = "$collection_dir/$subdirectory/.Radicale.props";
    
    if (-e $props_file) {
        open(my $fh, '<', $props_file) or next;
        my $props_content = do { local $/; <$fh> };
        close($fh);
        
        $data{props}{$subdirectory} = $props_content;
    }
}

# Check if we have backups:
if ($home_directory ne '') {
    my $backup_dir = $home_directory . '/.radicale';

    foreach my $props_key (keys %{$data{props}}) {
        my $tag_file = "$backup_dir/$props_key.tar.gz";
        if (-e $tag_file) {
            my $last_modified = (stat($tag_file))->mtime;
            $data{backup}{$props_key} = get_last_modified_date($last_modified);
        }
        else {
            $data{backup}{$props_key} = 'n/a';
        }
    }
}

#print Dumper(\%data);

# Encode the data as JSON
my $json_data = encode_json(\%data);

# Print the JSON-encoded data
print $json_data;
exit 0;

#
### Subs:
#

# Subroutine to retrieve the last modified date of a file
sub get_last_modified_date {
    my ($last_modified) = @_;
    my ($sec, $min, $hour, $day, $month, $year) = (localtime($last_modified))[0..5];
    $year += 1900;
    $month += 1;
    return sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $month, $day, $hour, $min, $sec);
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