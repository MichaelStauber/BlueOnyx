#!/usr/bin/perl
#
# $Id: check_bridgedhcp.pl
#
# Note: This script relies heavily on subroutines from 
# /usr/sausalito/handlers/base/network/Network.pm
#
# This handler checks if someone tries to enable DHCP on the primary 
# network interface while we have bridged networking enabled. If so,
# it fails the attempt and throws a warning message.
#
# Likewise: If the primary network interface is set to DHCP and someone
# tries to enable bridged networking, it also raises a warning and fails
# the attempt.
#
# Bottom line: Bridgend network AND DHCP on primary network interface?
# That's a no go!
#

use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network);
use CCE;
use Sauce::Util;
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

# Debugging switch:
$DEBUG = '0'; # 0 = off, 1 = syslog
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd(); # handler
#$cce->connectuds();

my $network_new = $cce->event_new();

#
### Information gathering phase (lining up the ducks):
#

# Get 'System' object:
my ($sysoid) = $cce->find('System');
my ($ok, $System) = $cce->get($sysoid);
if (!$ok) {
    debug_msg("Failed to get 'System' object!\n");
    $cce->bye('FAIL');
    exit 1;
}

# Determine primary interface:
my $primary_network_interface = get_primary_interface();
my $bridged_network = $System->{'bridged_network'};
my $bootproto = blueonyx_dhcp($primary_network_interface);

debug_msg("NIC           : $primary_network_interface \n");
debug_msg("bootproto NIC : $bootproto \n");
debug_msg("briged_network: $briged_network \n");

if (defined $network_new->{bootproto}) {
    debug_msg("This is a modification of a Network Object. New value for 'bootproto': " . $network_new->{'bootproto'} . "\n");
    if (($bridged_network eq '1') && ($network_new->{'bootproto'} eq 'none')) {
        $cce->bye('SUCCESS');
        exit 0;
    }
    if (($bridged_network eq '1') && ($network_new->{'bootproto'} eq 'dhcp')) {
        debug_msg("Fail: We cannot use DHCP on a Bridge!\n");
        $cce->bye('FAIL', "[[base-network.CantHaveDHCPonBridge]]");
        exit(1);
    }
}

if (defined $network_new->{'bridged_network'}) {
    debug_msg("This is a modification of a System Object. New value for 'bridged_network': " . $network_new->{'bridged_network'} . "\n");
    if (($bootproto eq 'none') && ($network_new->{'bridged_network'} eq '0')) {
        $cce->bye('SUCCESS');
        exit 0;
    }
    if (($bootproto eq 'dhcp') && ($network_new->{'bridged_network'} eq '1')) {
        debug_msg("Fail: We cannot use DHCP on a Bridge!\n");
        $cce->bye('FAIL', "[[base-network.CantHaveDHCPonBridge]]");
    }
}

if (($bootproto eq 'dhcp') && ($bridged_network eq '1')) {
    debug_msg("Fail: We cannot use DHCP on a Bridge!\n");
    $cce->bye('FAIL', "[[base-network.CantHaveDHCPonBridge]]");
    exit(1);
}

$cce->bye('SUCCESS');
exit 0;

#
### Subs:
#

sub debug_msg {
    if ($DEBUG gt '0') {
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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