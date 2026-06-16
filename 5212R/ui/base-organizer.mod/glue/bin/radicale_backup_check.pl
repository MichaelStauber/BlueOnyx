#!/usr/bin/perl

use strict;
use warnings;
use POSIX qw(getpwnam);
use JSON;

# Get the username from the command line argument
my $username = shift || die "Username not provided.\n";

# Get the user's home directory
my $home_directory = (getpwnam($username))[7];

# Get the path to the user's .radicale directory
my $radicale_dir = "$home_directory/.radicale";

# Check if the .radicale directory exists
unless (-d $radicale_dir) {
    # Return an empty array as JSON
    print "[]\n";
    exit 0;
}

# Perform a directory listing of the .radicale directory
opendir(my $dh, $radicale_dir) or die "Failed to open directory: $!";
my @tar_files = grep { /\.tar\.gz$/ && -f "$radicale_dir/$_" } readdir($dh);
closedir($dh);

# Return the array of tar files as JSON
my $json = JSON->new;
my $json_array = $json->encode(\@tar_files);
print $json_array."\n";

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