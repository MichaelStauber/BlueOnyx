#!/usr/bin/perl -I/usr/sausalito/perl -I.
#
# $Id: change_route.pl
#
# This is a legacy script that was supposed to manually create routes for all
# bound IPs under the weirdest imaginable scenarios possible. It covered OpenVZ
# nodes and CTs w/o NetworkManager, real physical or properly virtualized servers,
# DHCP and what not.
#
# In modern times this is no longer necessary. OpenVZ is dead and of no further
# interest as far as 5211R (or newer) goes. And we now use NetworkManager, which
# is nice enough to handle routing automatically for us. Therefore this script
# has been MASSIVELY tuned down to just restart NetworkManager.
#
# Note: the "-c" option can be used to run change_route.pl as a standalone
# command-line tool, rather than a handler.
#
# Additional note: The extra_ips for the primary network interface *must* be 
# stored in the 'System' Object. Because if this runs as a handler, it cannot 
# use $cce->get() in Handler context to fetch the 'Network' object of the primary
# etwork interface that would contain the info in a perfect world. 
#

use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network);

# Debugging switch:
$DEBUG = "1";
if ($DEBUG) {
    use Data::Dumper;
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

#
### Use File::NFSLock to make sure only one instance of this script runs:
#

use Fcntl qw(LOCK_EX LOCK_NB);
use File::NFSLock;

use FileHandle;
use Sauce::Config;
use Sauce::Util;
use Sauce::Service;
use CCE;
use Getopt::Long;
use Net::IP qw(:PROC);
use Network qw(find_eth_ifaces get_primary_interface get_device_mac check_if_slave 
                    reinitialize_network get_nmcli_uuid get_uuid_by_device 
                    get_secondary_ipv4_addresses get_secondary_ipv6_addresses calcnetwork 
                    find_config_file netmask_to_prefix get_primary_ipv4_ip 
                    get_primary_ipv4_netmask get_primary_ipv6_ip nm_debug_msg 
                    get_primary_ipv4_gateway get_primary_ipv6_gateway get_primary_dns_servers
                    remove_prefixes array_to_string string_to_array network_device_change_state
                    network_device_check_state remove_connections get_dns_servers compare_hashes 
                    in_array compare_network_configs check_dhcp blueonyx_dhcp
                    );

my $CMDLINE = 0;
GetOptions('cmdline', \$CMDLINE, 'debug', \$DEBUG);

&debug_msg("Running: starting\n");
$DEBUG && print STDERR "$0: starting.\n";

my $cce = new CCE(Domain => 'base-network');

if ($CMDLINE) {
    $cce->connectuds();
} 
else {
    $cce->connectfd();
}

# Try to get an exclusive lock on myself.
my $lock;
eval {
    $lock = File::NFSLock->new($0, LOCK_EX | LOCK_NB);
};
if ($@ || !$lock) {
    # Prevent this script from running multiple instances of itself.
    # This is done after CCE init so that we can send $cce->bye('SUCCESS').
    &debug_msg("$0 is already running. Bailing.\n");
    $cce->bye('SUCCESS');
    exit(0);
}
else {
    # Just to make sure this no longer runs, because handler base/network/rewrite-ifcfg.pl does it all for us:
    &debug_msg("$0 is no longer needed. Bailing.\n");
    $cce->bye('SUCCESS');
    exit(0);
}

my $eo = $cce->event_object();
my $eo_new = $cce->event_new();
my $eo_old = $cce->event_old();

#&debug_msg("eo: " . Dumper($eo) . "\n");
#&debug_msg("eo_new: " . Dumper($eo_new) . "\n");
#&debug_msg("eo_old: " . Dumper($eo_old) . "\n");

# Handle bootproto=dhcp on AWS, where we do NOT change anything:
if (-f "/etc/is_aws") {
    # Cleanup and release the lock before exiting
    if ($lock) {
        $lock->unlock();
    }
    $cce->bye('SUCCESS');
    exit(0);
}

my ($sysoid) = $cce->find('System');
my ($ok, $System) = $cce->get($sysoid);
if (!$ok) {
    &debug_msg("Running: No 'System' Object found, bailing.\n");
    # Cleanup and release the lock before exiting
    if ($lock) {
        $lock->unlock();
    }
    $cce->bye('FAIL');
    exit 1;
}
else {
    $gateway = $System->{gateway};
    $gateway_IPv6 = $System->{gateway_IPv6};
    $IPType = $System->{IPType};
    $bridged_network = $System->{bridged_network};
}

my $device = get_primary_interface();

#
### Do we need to restart the network? Yes, if there was a Gateway change:
#

if (($eo_old->{gateway} ne $eo_new->{gateway})) {
    &debug_msg("INFO: ********** NETWORK RESTART **********\n");
    # Restart Network:
    Sauce::Service::service_run_init('NetworkManager', 'restart');
    # Forced reinitialization of network device:
    reinitialize_network($device);
}
elsif (($eo_old->{gateway_IPv6} ne $eo_new->{gateway_IPv6})) {
    &debug_msg("INFO: ********** NETWORK RESTART **********\n");
    # Restart Network:
    Sauce::Service::service_run_init('NetworkManager', 'restart');
    # Forced reinitialization of network device:
    reinitialize_network($device);
}

# Reset 'nw_update';
my ($ok) = $cce->update($sysoid, '', { 'nw_update' => '0' });

# Cleanup and release the lock before exiting
if ($lock) {
    $lock->unlock();
}

##################################################################
my $final_ipv4 = get_primary_ipv4_ip();
my $final_ipv6 = get_primary_ipv6_ip();
&debug_msg("FINAL IPv4 State: $final_ipv4\n");
&debug_msg("FINAL IPv6 State: $final_ipv6\n");
##################################################################

$cce->bye('SUCCESS');
exit(0);

#
### Subroutines:
#

sub uniq {
    my %seen;
    grep !$seen{$_}++, @_;
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        print STDERR "$msg";
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