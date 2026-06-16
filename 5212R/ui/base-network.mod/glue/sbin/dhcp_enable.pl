#!/usr/bin/perl -I/usr/sausalito/perl
use strict;
use warnings;
use Getopt::Long;
use CCE;

my $cce = new CCE;

$cce->connectuds();

# Debugging switch:
my $DEBUG = '3'; # 0 = off, 1 = syslog, 2 = syslog and screen # 3 = Extra info
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# Check if the script is run as root
if ($> != 0) {
    die "This program must be run as 'root'!\n";
}

# Variables to store command-line options
my $confirm;
my $enable;
my $disable;
my $help;

# Get command-line options
GetOptions(
    'confirm=s' => \$confirm,
    'enable'    => \$enable,
    'disable'   => \$disable,
    'help'      => \$help,
) or print_help();

# Check if help is requested
if ($help) {
    print_help();
}

# Check if the script is called with --confirm=yes
if (defined $confirm && $confirm eq 'yes') {

    if ($enable) {
        print "Switching all network interfaces to DHCP ...\n\n";

        my (@Network_OIDs) = $cce->findx('Network', '', { 'real' => '1' });
        foreach my $oid (@Network_OIDs) {
            my ($ok, $net_info) = $cce->get($oid);
            my $device = $net_info->{'device'};

            print "Processing Network Device $device \n";

            my ($update_ok) = $cce->set($Network_OIDs[0], '', { 'enabled' => 1, 'ipaddr' => '', 'netmask' => '', 'ipaddr_IPv6' => '', 'bootproto' => 'dhcp' });
            if ($update_ok) {
                debug_msg("Updated CODB settings for $device to turn on DHCP\n");
            }
        }
        debug_msg("All done. Exiting.\n");
    }
    elsif ($disable) {
        print "Disabling DHCP on all network interfaces ...\n\n";

        my (@Network_OIDs) = $cce->findx('Network', '', { 'real' => '1' });
        foreach my $oid (@Network_OIDs) {
            my ($ok, $net_info) = $cce->get($oid);
            my $device = $net_info->{'device'};

            print "Processing Network Device $device \n";

            my ($update_ok) = $cce->set($Network_OIDs[0], '', { 'bootproto' => 'none' });
            if ($update_ok) {
                debug_msg("Updated CODB settings for $device to turn off DHCP\n");
            }
        }
        debug_msg("All done. Exiting.\n");
    }
    else {
        print_help();
    }
}
else {
    print_help();
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

# Subroutine to print help text and exit
sub print_help {
    print <<'END_HELP';
Usage: /usr/sausalito/sbin/dhcp_enable.pl --confirm=yes --enable|--disable

This script switches all network interfaces to DHCP or disables DHCP.

Options:
  --confirm=yes   Confirm to switch all network interfaces to DHCP or disable DHCP
  --enable        Enable DHCP on all network interfaces
  --disable       Disable DHCP on all network interfaces
  --help          Show this help message

END_HELP
}

sub debug_msg {
    my $msg = shift;
    if ($DEBUG gt '1') {
        print "$msg";
    }
    if ($DEBUG gt '0') {
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$msg");
        closelog;
    }
}

# 
# Copyright (c) 2015-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2015-2024 Team BlueOnyx, BLUEONYX.IT
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

