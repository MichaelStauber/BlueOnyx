#!/usr/bin/perl
#
# Script that uses Net::DBus to figure out the UUID of network devices.
# Want to know what UUID eth0 has? This script will report it.
# 
# Usage: /usr/sausalito/sbin/get_nmcli_uuid.pl --device=eth0
#

use strict;
use warnings;
use Net::DBus;
use Getopt::Long;

# Debugging switch:
my $DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# Get the device from command-line arguments
my $device;
GetOptions("device=s" => \$device) or die("Error in command line arguments\n");

# Check if device parameter is provided
if (!$device) {
    debug_msg("Incorrect usage of /usr/sausalito/sbin/get_nmcli_uuid.pl\n");
    die("Usage: $0 --device=device_name\n");
}

# Connect to the system bus
my $bus = Net::DBus->system();

# Get the NetworkManager service
my $nm_service = $bus->get_service("org.freedesktop.NetworkManager");

# Get the NetworkManager object
my $nm = $nm_service->get_object("/org/freedesktop/NetworkManager", "org.freedesktop.NetworkManager");

# Get all connections
my $settings = $nm_service->get_object("/org/freedesktop/NetworkManager/Settings", "org.freedesktop.NetworkManager.Settings");
my $connections = $settings->ListConnections();

foreach my $connection_path (@$connections) {
    my $connection = $nm_service->get_object($connection_path, "org.freedesktop.NetworkManager.Settings.Connection");
    my $settings = $connection->GetSettings();

    if ($settings->{'connection'}->{'interface-name'} eq $device) {
        print "UUID:" . $settings->{'connection'}->{'uuid'} . "\n";
        debug_msg("$device: " . $settings->{'connection'}->{'uuid'} . "\n");
        exit 0;
    }
}

print "ERROR: Device $device not found.\n";
debug_msg("ERROR: Device $device not found.\n");
exit 1;

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "/usr/sausalito/sbin/get_nmcli_uuid.pl: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2024 Team BlueOnyx, BLUEONYX.IT
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