# $Id: Network.pm
#
# This module contains commonly used Network related functions of BlueOnyx:
#

package Network;

use strict;
use warnings;

# Debugging switch:
my $DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use Exporter 'import';
use Net::IP qw(:PROC);
use Net::DBus;
use File::Find;
use File::Slurp;
use Data::Dumper;

our @EXPORT_OK = qw(find_eth_ifaces get_primary_interface get_device_mac check_if_slave 
                    reinitialize_network get_nmcli_uuid get_uuid_by_device 
                    get_secondary_ipv4_addresses get_secondary_ipv6_addresses calcnetwork 
                    find_config_file netmask_to_prefix get_primary_ipv4_ip 
                    get_primary_ipv4_netmask get_primary_ipv6_ip nm_debug_msg 
                    get_primary_ipv4_gateway get_primary_ipv6_gateway get_primary_dns_servers
                    remove_prefixes array_to_string string_to_array network_device_change_state
                    network_device_check_state remove_connections get_dns_servers compare_hashes 
                    in_array compare_network_configs check_dhcp blueonyx_dhcp
                    );

# Programs
our $IFCONFIG = '/sbin/ifconfig';
our $IP = '/sbin/ip';

# Find the interface names for all real and alias interfaces,
# excluding those that are unmanaged by NetworkManager (nmcli)
sub find_eth_ifaces {
    my @eth_ifaces = ();

    # Build hash of unmanaged interfaces (fast lookup)
    my %unmanaged = ();
    if (open(my $nm_fh, '-|', "/usr/bin/nmcli -t device 2>/dev/null")) {
        while (<$nm_fh>) {
            chomp;
            my @fields = split /:/;
            # Field 0 = device name, Field 2 = state (unmanaged, connected, etc.)
            if (@fields >= 3 && $fields[2] eq 'unmanaged') {
                $unmanaged{$fields[0]} = 1;
            }
        }
        close($nm_fh);
    }

    # Get interfaces from ip link show
    if (open(my $ip_fh, '-|', "LC_ALL=C ip link show 2>/dev/null")) {
        while (<$ip_fh>) {
            if (/^\d+:\s+([^\s:]+):/) {
                my $iface = $1;
                $iface =~ s/@.*$//;   # remove virtual suffix (e.g. veth0@if12)

                # Skip unmanaged interfaces
                next if exists $unmanaged{$iface};

                # Keep only desired interface types (real + aliases)
                if ($iface =~ /^(eth|br|venet|enp|ens|eno|wlan|wwan|bond|veth)[0-9a-z]+(:[0-9]+)?$/) {
                    push @eth_ifaces, $iface;
                }
            }
        }
        close($ip_fh);
    }

    return @eth_ifaces;
}

# Previous version of find_eth_ifaces():
sub old_find_eth_ifaces {
    my @eth_ifaces = ();

    if (open(my $ip_fh, '-|', "LC_ALL=C ip link show 2>/dev/null")) {
        while (<$ip_fh>) {
            if (/^\d+:\s+([^\s:]+):/) {
                my $iface = $1;
                $iface =~ s/@.*$//;
                if ($iface =~ /^(eth|br|venet|enp|ens|eno|wlan|wwan|bond|veth)[0-9a-z]+(:[0-9]+)?$/) {
                    push @eth_ifaces, $iface;
                }
            }
        }
        close($ip_fh);
    }

    return @eth_ifaces;
}

# Get device name of primary network interface:
sub get_primary_interface {
    my $primary_interface = '';
    my @routes = `ip route | grep default`;

    foreach my $route (@routes) {
        if ($route !~ /linkdown/ && $route !~ /veth/) {
            ($primary_interface) = $route =~ /\bdev\s+(\S+)/;
            last if $primary_interface;
        }
    }
    chomp($primary_interface);

    if ($primary_interface eq '') {
        $primary_interface = `ifconfig | grep -E '^[a-zA-Z0-9]+' | awk '{print \$1}' | sed 's/://g' | head -1`;
        chomp($primary_interface);
    }

    return $primary_interface;
}

# Get MAC address of specified network device:
sub get_device_mac {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    # Get MAC:
    my $mac = `/usr/bin/nmcli device show $actual_device|grep GENERAL.HWADDR:|awk {'print \$2'}`;
    chomp($mac);

    return $mac;
}

# Function to check if specified network device is a slave
sub check_if_slave {
    my $interface = shift;

    nm_debug_msg("check_if_slave() for device: $interface\n");

    # Fetch UUID for the device
    my $uuid = get_nmcli_uuid($interface);

    my $output = `/usr/bin/nmcli con show uuid $uuid | grep 'connection.master'`;
    chomp($output);

    if ($output =~ /connection.master:\s+--/) {
        return 0; # Not a slave
    }
    elsif ($output =~ /connection.master:\s+/) {
        return 1; # Is a slave
    }
    else {
        nm_debug_msg("Error checking $interface for connection.master: $output\n");
        return -1;
    }
}

sub reinitialize_network {
    # Fetch UUID for the device
    my ($device, $bridged_network) = @_;

    # Fetch UUID for the device
    my $uuid = get_nmcli_uuid($device);

    if ($uuid) {
        nm_debug_msg("reinitialize_network(): Re-initializing network for device $device with UUID $uuid\n");

        # Take down and bring up the connection using its UUID
        system("/usr/bin/nmcli con down uuid $uuid && /usr/bin/nmcli con up uuid $uuid");
    }
    else {
        nm_debug_msg("reinitialize_network(): No UUID found for device $device; cannot reinitialize network.\n");
    }

    if ($bridged_network) {
        # Get real primary network interface that isn't a bridge:
        my $eth0 = `ifconfig | grep -E '^[a-zA-Z0-9]+' | grep -v '^br' | awk '{print \$1}' | sed 's/://g' | head -1`;
        chomp($eth0);
        my $uuid_eth0 = get_nmcli_uuid($eth0);
        if ($uuid_eth0) {
            nm_debug_msg("reinitialize_network(): Re-initializing network for device $eth0 with UUID $uuid_eth0\n");

            # Take down and bring up the connection using its UUID
            system("/usr/bin/nmcli con down uuid $uuid_eth0 && /usr/bin/nmcli con up uuid $uuid_eth0");
        }
        else {
            nm_debug_msg("reinitialize_network(): No UUID found for device $eth0; cannot reinitialize network.\n");
        }
    }
    sleep 3;
}

sub get_nmcli_uuid {
    my ($iface) = @_;
    my $max_retries = 5;
    my $retry_delay = 2;  # seconds

    if ($iface eq 'br0') {
        $max_retries = 3;
    }

    for (my $attempt = 1; $attempt <= $max_retries; $attempt++) {
        my @uuids = get_uuid_by_device($iface);

        my %active_devices = get_active_devices();

        # Check if there is at least one active UUID and return the first one
        my @active_uuids = grep { $active_devices{$_} } @uuids;
        if (@active_uuids) {

            # Remove inactive UUIDs
            foreach my $uuid (@uuids) {
                unless ($active_devices{$uuid}) {
                    system("nmcli con delete $uuid");
                }
            }

            return $active_uuids[0];  # Return the first active UUID found
        }

        # If no active UUIDs found, consider inactive ones
        my @inactive_uuids = grep { !$active_devices{$_} } @uuids;

        if (scalar @inactive_uuids > 0) {
            return $inactive_uuids[0];  # Return the first inactive UUID found
        }
        else {
            sleep($retry_delay);
        }
    }

    return '';  # Return empty if no UUIDs found after retries
}

# Helper function to get UUIDs by device name
sub get_uuid_by_device {
    my $device_name = shift;
    my @uuids;

    # Connect to the system bus
    my $bus = Net::DBus->system();

    # Get the NetworkManager service
    my $nm_service = $bus->get_service("org.freedesktop.NetworkManager");

    # Get the NetworkManager Settings object
    my $settings = $nm_service->get_object("/org/freedesktop/NetworkManager/Settings", "org.freedesktop.NetworkManager.Settings");

    # List all connections
    my $connections = $settings->ListConnections();

    # Loop through all connections to find matching device name
    foreach my $connection_path (@$connections) {
        my $connection = $nm_service->get_object($connection_path, "org.freedesktop.NetworkManager.Settings.Connection");
        my $settings = $connection->GetSettings();

        if ($settings->{'connection'}{'interface-name'} && $settings->{'connection'}{'interface-name'} eq $device_name) {
            push @uuids, $settings->{'connection'}{'uuid'};
        }
    }
    return @uuids;
}

# Helper function to get active devices
sub get_active_devices {
    my %active_devices;

    open my $nmcli_fh, '-|', "nmcli -g UUID,DEVICE con show --active" or die "Can't run nmcli: $!";
    while (<$nmcli_fh>) {
        chomp;
        my ($uuid, $dev) = split /:/;
        $active_devices{$uuid} = 1;
    }
    close $nmcli_fh;

    return %active_devices;
}

# Function to get secondary IPv4 addresses by UUID
sub get_secondary_ipv4_addresses {
    my ($uuid, $primary_ipv4) = @_;

    # Use nmcli to get all IPv4 addresses for the connection by UUID
    my $all_ips_line = `nmcli -g IP4.ADDRESS connection show uuid $uuid`;
    chomp($all_ips_line);

    # Split the line into individual IP addresses
    my @all_ips = split(/\s*\|\s*/, $all_ips_line);

    # Process the output to filter out the primary IP address
    my @secondary_ips;
    foreach my $entry (@all_ips) {
        my ($ip, $prefix) = split('/', $entry);
        # Exclude the primary IP address
        if (defined $ip && $ip ne $primary_ipv4) {
            push @secondary_ips, "$ip/$prefix";
        }
    }

    return @secondary_ips;
}

# Function to get secondary IPv4 addresses by UUID
sub get_secondary_ipv6_addresses {
    my ($uuid, $primary_ipv6) = @_;

    # Use nmcli to get all IPv6 addresses for the connection by UUID
    my $all_ips_line = `nmcli -g IP6.ADDRESS connection show uuid $uuid`;
    chomp($all_ips_line);

    # Split the line into individual IP addresses
    my @all_ips = split(/\s*\|\s*/, $all_ips_line);

    # Process the output to filter out the primary and link-local IP addresses
    my @secondary_ips;
    foreach my $entry (@all_ips) {
        # Remove escaping from colons
        $entry =~ s/\\:/:/g;
        my ($ip, $prefix) = split('/', $entry);
        # Exclude the primary IP and link-local addresses (fe80::)
        if (defined $ip && $ip ne $primary_ipv6 && $ip !~ /^fe80::/) {
            push @secondary_ips, "$ip/$prefix";
        }
    }

    return @secondary_ips;
}

# Calculate the network to which the specified IP address belongs
sub calcnetwork {
    my ($ipaddr, $netmask) = (shift, shift);

    # convert the ip address and netmask to binary representations
    my $binip = pack('CCCC', split(/\./, $ipaddr));
    my $binmask = pack('CCCC', $netmask);

    # calculate the network
    my $binnet = $binip & $binmask;

    # calculate the broadcast address
    my $binbcast = $binnet | ~$binmask;
    
    # convert network and broadcast into dotted-quad format
    my $network = join('.', unpack('CCCC', $binnet));
    my $bcast   = join('.', unpack('CCCC', $binbcast));
    
    return ($network, $bcast);
}

# Subroutine to remove prefixes from an array of IP addresses
sub remove_prefixes {
    my @ips_with_prefixes = @_;
    my @ips_without_prefixes;

    foreach my $ip (@ips_with_prefixes) {
        my ($ip_without_prefix) = split('/', $ip);
        push @ips_without_prefixes, $ip_without_prefix;
    }

    return @ips_without_prefixes;
}

# Subroutine to find the configuration file for a given interface name
sub find_config_file {
    my ($interface_name) = @_;
    my $config_dir = '/etc/NetworkManager/system-connections';
    my $config_file;

    find(sub {
        return unless -f;
        my $content = read_file($File::Find::name);
        if ($content =~ /interface-name=$interface_name/) {
            $config_file = $File::Find::name;
            # Stop searching once the file is found
            $File::Find::prune = 1 if defined $config_file;
        }
    }, $config_dir);

    return $config_file;
}

# Convert netmask to prefix length
sub netmask_to_prefix {
    my ($netmask) = @_;

    # Split the netmask into its octets
    my @octets = split(/\./, $netmask);

    # Convert each octet to binary and join them into a single string
    my $binary_mask = join('', map { sprintf("%08b", $_) } @octets);

    # Count the number of '1's in the binary string
    my $prefix_length = ($binary_mask =~ tr/1//);

    return $prefix_length;
}

sub get_primary_ipv4_ip {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    # Get IPv4 IP:
    my $ipv4_ip = `LC_ALL=C ip -o address show $actual_device|grep -v inet6|grep '$actual_device\\\\'|awk '{print \$4}'|cut -d / -f1|head -1`;
    chomp($ipv4_ip);

    return $ipv4_ip;
}

sub get_primary_ipv4_netmask {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    # Get primary IPv4:
    my $ipv4_ip = get_primary_ipv4_ip();

    # Get IPv4 Netmask:
    my $ipv4_nm = `LC_ALL=C ip -o address show $actual_device|grep -v inet6|grep '$actual_device\\\\'|awk '{print \$4}'|cut -d / -f2|head -1`;
    chomp($ipv4_nm);
    if ($ipv4_nm ne '') {
        my $calc_ip = '127.0.0.0' . '/' . $ipv4_nm;
        my $ip_nm = new Net::IP ($calc_ip) or die (Net::IP::Error());
        $ipv4_nm = $ip_nm->mask();
    }

    # Fallback if we have no NETMASK yet:
    if (($ipv4_nm eq '') && ($ipv4_ip ne '')) {
        $ipv4_nm = `LC_ALL=C /sbin/ifconfig |grep $ipv4_ip|awk {'print \$4'}`;
    }
    chomp($ipv4_nm);

    return $ipv4_nm;
}

sub get_primary_ipv4_gateway {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    # Get IPv4 Gateway::
    my $ipv4_gw = `LC_ALL=C ip route show dev $actual_device | awk '/default/ {print \$3}'`;
    chomp($ipv4_gw);

    return $ipv4_gw;
}

sub get_primary_ipv6_ip {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    # Get IPv6 IP:
    my $ipv6_ip = `LC_ALL=C ip -6 addr show dev $actual_device|grep inet6|awk '{ print \$2 }'|cut -d / -f1|grep -v '^fe80::'|head -1`;
    chomp($ipv6_ip);

    return $ipv6_ip;
}

sub get_primary_ipv6_gateway {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    # Get IPv4 Gateway::
    my $ipv6_gw = `LC_ALL=C ip -6 route show dev "$actual_device" | awk '/default/ {print \$3}'|head -1`;
    chomp($ipv6_gw);

    return $ipv6_gw;
}

sub get_primary_dns_servers {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    my @dns_servers;

    # Run the nmcli command to get DNS information
    my @output = `nmcli device show $actual_device`;

    # Parse the output to extract DNS servers
    foreach my $line (@output) {
        if ($line =~ /IP[46]\.DNS\[\d+\]:\s*(\S+)/) {
            push @dns_servers, $1;
        }
    }

    # Parse /etc/resolv.conf to get nameservers
    if (open my $resolv_fh, '<', '/etc/resolv.conf') {
        while (my $line = <$resolv_fh>) {
            if ($line =~ /^\s*nameserver\s+(\S+)/) {
                push @dns_servers, $1;
            }
        }
        close $resolv_fh;
    }

    # Remove duplicate entries
    my %seen;
    @dns_servers = grep { !$seen{$_}++ } @dns_servers;

    return @dns_servers;
}

# Subroutine to transform an array into the "['val1', 'val2', 'val3']" format
sub array_to_string {
    my @array = @_;
    @array = sort(@array);
    my $formatted_string = "['" . join("', '", @array) . "']";
    return $formatted_string;
}

# Subroutine to transform a formatted string into an array
sub string_to_array {
    my ($string) = @_;
    
    # Check if the string is defined
    if (!defined $string) {
        return ();
    }

    $string =~ s/^\s*\[\s*'//;  # Remove the leading "['"
    $string =~ s/\s*'\s*\]\s*$//;  # Remove the trailing "']"
    
    my @array = split(/\s*'\s*,\s*'\s*/, $string);  # Split on "', '"
    @array = sort(@array);
    
    return @array;
}

# Subroutine to check the state of a network device
sub network_device_check_state {
    my ($device_name) = @_;

    if ($device_name eq '') {
        nm_debug_msg("network_device_check_state(): No device name given!\n");
        return 0;
    }
    else {
        my $output = `/usr/bin/nmcli -t -f GENERAL.STATE device show $device_name`;

        if ($? != 0) {
            nm_debug_msg("network_device_check_state(): Failed to get device state for device $device_name\n");
            return 0;
        }

        my ($state) = $output =~ /GENERAL.STATE:\s*(\d+)/;

        nm_debug_msg("network_device_check_state(): Device $device_name, State $state\n");

        if ($state == 100) {
            return 1; # Device is up
        }
        else {
            return 0; # Device is down
        }
    }
}

# Subroutine to bring connections up or down:
sub network_device_change_state {
    my ($device_uuid, $state) = @_;

    if ($device_uuid eq '') {
        nm_debug_msg("network_device_change_state(): No device UUID given!\n");
    }
    else {
        if ($state) {
            nm_debug_msg("/usr/bin/nmcli con up uuid $device_uuid\n");
            system("/usr/bin/nmcli con up uuid $device_uuid");
        }
        else {
            nm_debug_msg("/usr/bin/nmcli con up uuid $device_uuid\n");
            system("/usr/bin/nmcli con down uuid $device_uuid");
        }
    }
}

# Subroutine to compare two hashes for equality
sub compare_hashes {
    my ($hash1, $hash2) = @_;

    for my $key (keys %$hash1) {
        if (not exists $hash2->{$key}) {
            nm_debug_msg("Key $key is missing in the second hash.\n");
            return 0;
        }
        if (ref $hash1->{$key} eq 'HASH') {
            if (not compare_hashes($hash1->{$key}, $hash2->{$key})) {
                nm_debug_msg("Hashes under key $key are not equal.\n");
                return 0;
            }
        }
        elsif ($hash1->{$key} ne $hash2->{$key}) {
            nm_debug_msg("Values for key $key are different: $hash1->{$key} vs $hash2->{$key}\n");
            return 0;
        }
    }

    for my $key (keys %$hash2) {
        if (not exists $hash1->{$key}) {
            nm_debug_msg("Key $key is missing in the first hash.\n");
            return 0;
        }
    }

    return 1;
}

# Subroutine to compare CODB_NIC and NIC configurations
sub compare_network_configs {
    my ($data) = @_;
    my $config = $data->{CONFIG};
    my $codb_nic = $data->{CODB_NIC};
    my $nic = $data->{NIC};
    my $bridged_network = $config->{bridged_network};
    my $bridge_master = $config->{bridge_master};
    my $bridge_slave = '';
    if ($bridged_network) {
        $bridge_slave = $config->{bridge_slave};
    }

    my %results;

    if ($bridged_network) {
        # Compare bridge master
        if (exists $codb_nic->{$bridge_slave} && exists $nic->{$bridge_master}) {
            $results{$bridge_master} = compare_hashes($codb_nic->{$bridge_slave}, $nic->{$bridge_master});
            #nm_debug_msg("Comparing bridge master $bridge_master with bridge slave $bridge_slave: " . ($results{$bridge_master} ? "Equal\n" : "Not equal\n"));
        }
        else {
            $results{$bridge_master} = 0;
        }

        # Check bridge slave
        if (exists $nic->{$bridge_slave}) {
            $results{$bridge_slave} = ($nic->{$bridge_slave}->{bridgestate} eq 'slave') ? 1 : 0;
            #nm_debug_msg("Checking bridge slave $bridge_slave: " . ($results{$bridge_slave} ? "Correctly set as slave\n" : "Not set as slave\n"));
        }
        else {
            $results{$bridge_slave} = 0;
        }
    }

    # Compare all other interfaces
    for my $device (keys %$codb_nic) {
        if (exists $nic->{$device}) {
            # Skip bridge master and slave since they were already checked
            next if ($device eq $bridge_master || $device eq $bridge_slave);
            $results{$device} = compare_hashes($codb_nic->{$device}, $nic->{$device});
            #nm_debug_msg("Comparing $device: " . ($results{$device} ? "Equal\n" : "Not equal\n"));
        }
        else {
            $results{$device} = 0;
        }
    }

    return %results;
}

# Function to remove network interfaces by name. If you tell it to get rid of 'eth0',
# then it will remove all instances of 'eth0' regardless how they are named and if
# they are active or not.
sub remove_connections {
    my ($iface) = @_;

    if ($iface eq '') {
        return 0;
    }
    
    # Get the list of UUIDs for the given interface
    my @uuids = `nmcli -g UUID,NAME con show | grep -v incusbr0 | grep $iface | awk -F ':' '{print \$1}'`;
    
    # Remove each connection
    foreach my $uuid (@uuids) {
        chomp $uuid;
        system("nmcli con delete $uuid");
    }
}

sub in_array {
    my ($arr,$search_for) = @_;
    my %items = map {$_ => 1} @$arr; # create a hash out of the array values
    return (exists($items{$search_for}))?1:0;
}

# Function to get DNS servers
sub get_dns_servers {
    my ($actual_device) = @_;

    # If no device is provided, use the primary network interface
    $actual_device //= get_primary_interface();

    my @dns_servers;

    @dns_servers = get_primary_dns_servers($actual_device);

    return @dns_servers;
}

# Checks if a given network device uses DHCP. Reports the following back:
#  0 = No DHCP
#  4 = DHCP for IPv4
#  6 = DHCP for IPv6
# 10 = DCHP for IPv4 and IPv6
sub check_dhcp {
    my ($device) = @_;

    my $uuid = get_nmcli_uuid($device);
    
    # Get the ipv4.method and ipv6.method values
    my $ipv4_method = `LC_ALL=C /usr/bin/nmcli -g ipv4.method con show uuid $uuid`;
    chomp $ipv4_method;

    my $ipv6_method = `LC_ALL=C /usr/bin/nmcli -g ipv6.method con show uuid $uuid`;
    chomp $ipv6_method;

    my $result = 0;

    $result += 4 if $ipv4_method eq 'auto';
    $result += 6 if $ipv6_method eq 'auto';

    return $result;
}

# Simplified DHCP check using check_dhcp() to report back values expected by CODB: 'none' or 'dhcp'
sub blueonyx_dhcp {
    my ($device) = @_;

    my $result = check_dhcp($device);

    # On older BlueOnyx installs it COULD be that DHCP for IPv4 is off, but it's on for IPv6.
    # Hence we report 'none' unless DHCP is enabled for both IPv4 *and* IPv6:
    if ($result < 10) {
        return 'none';
    }
    else {
        return 'dhcp';
    }
}

sub nm_debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "Network.pm: $msg");
        closelog;
    }
}

1;

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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