#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/vsite
#
# $Id: fix_vsite_ips
#
# Walks through all Vsites, removes IPs, saves, puts the IPs back in and saves again.
#
# Usage:
#
# Simply run this script once. Running it multiple times will do no harm, though.

use CCE;
use Net::IP qw(:PROC);
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network /usr/sausalito/handlers/base/vsite);
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

my $cce = new CCE;
$cce->connectuds();

# Root check:
my $id = `id -u`;
chomp($id);
if ($id ne "0") {
    print "$0 must be run by user 'root'!\n";
    $cce->bye('FAIL');
    exit(1);
}

# Find all Vsites:
my @vhosts = ();
my (@vhosts) = $cce->findx('Vsite');
my @oids = $cce->find('System');
my $sys_oid = $oids[0];
my ($ok, $System) = $cce->get($sys_oid);
my ($aok, $sys_obj) = $cce->get($sys_oid, 'Network');
my $pooling = $sys_obj->{'pooling'};

print "Going through all Vsites to Re-Apply the IPv4 and IPv6 addresses. \n";

$cce->update($sys_oid, 'Network', { 'pooling' => '0' });

# Get primary network interface:
my $device = get_primary_interface();

# Get IPv4 IP:
$ipv4_ip = get_primary_ipv4_ip();

# Get IPv4 Netmask:
$ipv4_nm = get_primary_ipv4_netmask();

# Get IPv6 IP:
$ipv6_ip = get_primary_ipv6_ip();

# Get primary IPs of '$device' from Network Config file:
if (($System->{IPType} eq 'VZv6') || ($System->{IPType} eq 'IPv6')) {
    $ipv4_ip = '';
}

print "Primary IPv4: $ipv4_ip\n";
print "Primary IPv6: $ipv6_ip\n";

my @ipv4 = ();
my @ipv6 = ();

# Walk through all Vsites:
for my $vsite (@vhosts) {
    ($ok, my $my_vsite) = $cce->get($vsite);

    print "Processing Site: $my_vsite->{fqdn} \n";

    if ($my_vsite->{ipaddr} ne '') {
        push @ipv4, $my_vsite->{ipaddr} unless grep { $_ eq $my_vsite->{ipaddr} } @ipv4;
    }
    if ($my_vsite->{ipaddrIPv6} ne '') {
        push @ipv6, $my_vsite->{ipaddrIPv6} unless grep { $_ eq $my_vsite->{ipaddrIPv6} } @ipv6;
    }

    ($ok) = $cce->set($vsite, '',{ 'ipaddr' => '127.0.0.1', 'ipaddrIPv6' => '::10' });

    if (($System->{IPType} eq "VZv4") || ($System->{IPType} eq "IPv4")) {
        ($ok) = $cce->set($vsite, '',{ 'ipaddr' => $my_vsite->{ipaddr} });
    }
    elsif (($System->{IPType} eq "VZv6") || ($System->{IPType} eq "IPv6")) {
        ($ok) = $cce->set($vsite, '',{ 'ipaddr' => '', 'ipaddrIPv6' => $my_vsite->{ipaddrIPv6} });
    }
    elsif (($System->{IPType} eq "VZBOTH") || ($System->{IPType} eq "BOTH")) {
        ($ok) = $cce->set($vsite, '',{ 'ipaddr' => $my_vsite->{ipaddr}, 'ipaddrIPv6' => $my_vsite->{ipaddrIPv6} });
    }
    else {
        ($ok) = $cce->set($vsite, '',{ 'ipaddr' => $my_vsite->{ipaddr}, 'ipaddrIPv6' => $my_vsite->{ipaddrIPv6} });
    }
}

# Sort the @ipv4 array
@ipv4 = sort @ipv4;

# Sort the @ipv6 array
@ipv6 = sort @ipv6;

$uuid = get_nmcli_uuid($device);
$ipv4_out = $cce->array_to_scalar(@ipv4);
$ipv6_out = $cce->array_to_scalar(@ipv6);

$cce->update($sys_oid, 'Network', { 'interfaceConfigure' => $sys_obj->{pooling} });
$cce->update($sys_oid, '', { 'extra_ipaddr' => $ipv4_out, 'extra_ipaddr_IPv6' => $ipv6_out, 'nw_update' => time() });

print "All done!\n";

# Tell cce everything is okay
$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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