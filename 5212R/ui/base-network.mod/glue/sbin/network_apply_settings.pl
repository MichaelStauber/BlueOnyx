#!/usr/bin/perl
#
# $Id: network_apply_settings.pl
#
# Note: This script relies heavily on subroutines from 
# /usr/sausalito/handlers/base/network/Network.pm
#
# When you run it, this script checks the Network configuration in CODB
# and compares it to the real network configuration that your server has.
#
# If it detects that one or more interfaces have a DIFFERENT config than
# they SHOULD have? Then it corrects the network settings of THOSE devices
# that aren't configured correctly. 
#
# This script also checks if the server is supposed to have a br0 network
# bridge with eth0 slave. If it SHOULD have one, but hasn't? Then it does
# perform the required changes. Likewise: If the server HAS a br0 bridge
# when it SHOULD NOT have one? Then it removes the bridge and configures
# the primary network interface correctly again.
#
# FWIW: This script is the culmination and condensation of eight days of 
# development. A reduction to a rather minimalistic approach that still
# has a mindboggling complexity. Tell me again what NetworkManager actually
# DOES make easier? Guess what? Nothing.
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
use Net::DBus;
use File::Find;
use File::Slurp;
use Data::Dumper;
use Fcntl ':flock'; # Import LOCK_* constants

# Debugging switch:
$DEBUG = '2'; # 0 = off, 1 = syslog, 2 = syslog and screen # 3 = Extra info
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectuds();

# Set flag that we are NOT a Container:
my $WE_ARE_A_CONTAINER = 0;

# Handle bootproto=dhcp on AWS and OVZ:
if ((-f "/etc/is_aws") || (-e '/proc/user_beancounters')) {
    # Cleanup and release the lock before exiting
    $DEBUG = '2';
    debug_msg("This is a containerized install. We don't really change the network settings.");
    $WE_ARE_A_CONTAINER = 1;
}

# Fix compat issue with older NewLinQ PKGs:
if (-f '/usr/sausalito/constructor/Compass/base/30_addNetwork.pl') {
    system("rm -f /usr/sausalito/constructor/Compass/base/30_addNetwork.pl");
}

#
### File locking:
#

# Define the lock file
my $lock_file = '/var/lock/network_apply.lock';

# Open the lock file (or create it if it doesn't exist)
open my $fh, '>', $lock_file or die "Cannot open lock file: $!";

debug_msg("****** Waiting for lock. ****** \n");

# Try to get an exclusive lock on the file
# This will block until it can get the lock
flock($fh, LOCK_EX) or die "Cannot lock file: $!";

debug_msg("****** Got exclusive lock. ****** \n");

#
### Information gathering phase (lining up the ducks):
#

# Get 'System' object:
my ($sysoid) = $cce->find('System');
my ($ok, $System) = $cce->get($sysoid);
if (!$ok) {
    # Release the lock and close the file
    close $fh or die "Cannot close lock file: $!";    

    debug_msg("Failed to get 'System' object!\n");
    $cce->bye('FAIL');
    exit 1;
}

# Determine primary interface:
my $primary_network_interface = get_primary_interface();

my $primary_ipv4 = get_primary_ipv4_ip();
my $primary_ipv6 = get_primary_ipv6_ip();

debug_msg("Primary IPv4: $primary_ipv4 \n");
debug_msg("Primary IPv6: $primary_ipv6 \n");

my %REALSTATE = {};

# Store relevant info from 'System' Object:
$REALSTATE->{'CONFIG'}->{'bridged_network'} = $System->{bridged_network};
$REALSTATE->{'CONFIG'}->{'extra_ipaddr'} = array_to_string($cce->scalar_to_array($System->{extra_ipaddr}));
$REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'} = array_to_string($cce->scalar_to_array($System->{extra_ipaddr_IPv6}));
$REALSTATE->{'CONFIG'}->{'gateway'} = $System->{gateway};
$REALSTATE->{'CONFIG'}->{'gateway_IPv6'} = $System->{gateway_IPv6};
$REALSTATE->{'CONFIG'}->{'IPType'} = $System->{IPType};
$REALSTATE->{'CONFIG'}->{'dns'} = array_to_string($cce->scalar_to_array($System->{dns}));
$REALSTATE->{'CONFIG'}->{'hostname'} = $System->{hostname};
$REALSTATE->{'CONFIG'}->{'domainname'} = $System->{domainname};
$REALSTATE->{'CONFIG'}->{'bootproto'} = 'none';
$REALSTATE->{'CONFIG'}->{'primary_network_interface'} = $primary_network_interface;
if (-f "/etc/is_aws") {
    debug_msg("File /etc/is_aws is present! Activating DHCP!\n");
    $REALSTATE->{'CONFIG'}->{'bootproto'} = 'dhcp';
}

$REALSTATE->{'NIC'}->{$primary_network_interface}->{'UUID'} = get_nmcli_uuid($primary_network_interface);

# Get real network config off the primary network interface:
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_ip'} = get_primary_ipv4_ip();
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_nm'} = get_primary_ipv4_netmask();
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_prefix'} = netmask_to_prefix($REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_nm'});
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_gw'} = get_primary_ipv4_gateway();
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'dns'} = array_to_string(get_primary_dns_servers());
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv6_ip'} = get_primary_ipv6_ip();
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv6_gw'} = get_primary_ipv6_gateway();
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_extra'} = array_to_string(remove_prefixes(get_secondary_ipv4_addresses($REALSTATE->{'NIC'}->{$primary_network_interface}->{'UUID'}, $REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_ip'})));
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv6_extra'} = array_to_string(remove_prefixes(get_secondary_ipv6_addresses($REALSTATE->{'NIC'}->{$primary_network_interface}->{'UUID'}, $REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv6_ip'})));
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'enabled'} = '1';
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'real'} = '1';
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'mac'} = get_device_mac();
$REALSTATE->{'NIC'}->{$primary_network_interface}->{'bootproto'} = blueonyx_dhcp($primary_network_interface);

if ($REALSTATE->{'CONFIG'}->{'bridged_network'} eq '1') {
    $REALSTATE->{'NIC'}->{$primary_network_interface}->{'bridgestate'} = 'master';
    $REALSTATE->{'CONFIG'}->{'bridge_master'} = $primary_network_interface;
}
else {
    $REALSTATE->{'NIC'}->{$primary_network_interface}->{'bridgestate'} = 'none';
    $REALSTATE->{'CONFIG'}->{'bridge_master'} = '';
}

# We cannot have Bridged Network *and* DHCP on the primary interface:
if (($REALSTATE->{'CONFIG'}->{'bridged_network'} eq '1') && ($REALSTATE->{'NIC'}->{$primary_network_interface}->{'bootproto'} eq 'dhcp')) {
    debug_msg("Fail: We cannot use DHCP on a Bridge!\n");

    # Release the lock and close the file
    close $fh or die "Cannot close lock file: $!";    

    $cce->bye('FAIL', "[[base-network.CantHaveDHCPonBridge]]");
    exit(1);
}

# Show DHCP state of primary network interface:
debug_msg("Device $primary_network_interface DHCP state: " . $REALSTATE->{'NIC'}->{$primary_network_interface}->{'bootproto'} . "\n");

# Get all 'Network' objects and store two copies of them in $REALSTATE:
# 
# 1.) $REALSTATE->{'NIC'}->{$device}
# 
#     Contains the current status obtained from the actual network interface.
#
# 2.) $REALSTATE->{'CODB_NIC'}->{$device}
#
#     Contains the settings in CODB of that particular network interface.
#
# Special consideration:
#
# - If the network device is a bridge slave, then we update
#   $REALSTATE->{'CONFIG'}->{'bridge_slave'} with the device name of the slave
#
# Reasoning for doing all this:
# =============================     
#
# We can now easily compare $REALSTATE->{'NIC'}->{$device} (active network config!)
# with $REALSTATE->{'CODB_NIC'}->{$device} and compare key by key if the running and
# active network config coincides with what's configured in CODB.
#
# That way we can easily decide if we actually NEED to update/change the actual network
# configuration and/or need to perform network restarts. If the configuration has not
# changed? Then we have next to nothing to do an don't need to bring the net down and up
# again.
#
# This is especially useful in cases where a Vsite got added, but uses an IPv4 or IPv6
# IP address that is already in use.
#

# Start sane:
my $first_non_bridge_device = `ifconfig | grep -E '^[a-zA-Z0-9]+' | grep -v '^br' | awk '{print \$1}' | sed 's/://g' | head -1`;
chomp($first_non_bridge_device);

# Do we have DHCP somewhere?
my $HAVE_DHCP = 0;

# If we have no bridge, then we also don't have a bridge_slave:
if (($REALSTATE->{'CONFIG'}->{'bridged_network'} eq '1') && ($first_non_bridge_device ne $primary_network_interface)) {
    $REALSTATE->{'CONFIG'}->{'bridge_slave'} = $first_non_bridge_device;
}

my (@Network_OIDs) = $cce->findx('Network', '', { 'real' => '1' });
foreach my $oid (@Network_OIDs) {
    my ($ok, $net_info) = $cce->get($oid);

    my $device = $net_info->{'device'};

    # Check if this is a slave network for a brige:
    my $slave_check = check_if_slave($device);
    if ($slave_check eq '0') {
        # It isn't. Store the real state of the nic:
        $REALSTATE->{'NIC'}->{$device}->{'ipv4_ip'} = get_primary_ipv4_ip($device);
        $REALSTATE->{'NIC'}->{$device}->{'ipv4_nm'} = get_primary_ipv4_netmask($device);
        $REALSTATE->{'NIC'}->{$device}->{'ipv4_prefix'} = netmask_to_prefix($REALSTATE->{'NIC'}->{$device}->{'ipv4_nm'});

        $REALSTATE->{'NIC'}->{$device}->{'ipv4_gw'} = get_primary_ipv4_gateway($device);
        $REALSTATE->{'NIC'}->{$device}->{'dns'} = $REALSTATE->{'NIC'}->{$primary_network_interface}->{'dns'};
        $REALSTATE->{'NIC'}->{$device}->{'ipv6_ip'} = get_primary_ipv6_ip($device);
        $REALSTATE->{'NIC'}->{$device}->{'ipv6_gw'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'ipv4_extra'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'ipv6_extra'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'enabled'} = '1';
        $REALSTATE->{'NIC'}->{$device}->{'real'} = '1';
        $REALSTATE->{'NIC'}->{$device}->{'mac'} = get_device_mac($device);
        $REALSTATE->{'NIC'}->{$device}->{'bootproto'} = blueonyx_dhcp($device);
        $REALSTATE->{'NIC'}->{$device}->{'bridgestate'} = 'none';
        $REALSTATE->{'NIC'}->{$device}->{'UUID'} = get_nmcli_uuid($device);

        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_ip'} = $net_info->{'ipaddr'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_nm'} = $net_info->{'netmask'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_prefix'} = netmask_to_prefix($net_info->{'netmask'});
        $REALSTATE->{'CODB_NIC'}->{$device}->{'dns'} = $REALSTATE->{'NIC'}->{$primary_network_interface}->{'dns'};
        if ($device eq $primary_network_interface) {
            # We're not in bridged mode and this is the primary network inteface: Set Gateways:
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_gw'} = $REALSTATE->{'CONFIG'}->{'gateway'};
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_gw'} = $REALSTATE->{'CONFIG'}->{'gateway_IPv6'};
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_extra'} = $REALSTATE->{'CONFIG'}->{'extra_ipaddr'};
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_extra'} = $REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'};

            # Add secondary IP addresses:
            $REALSTATE->{'NIC'}->{$device}->{'ipv4_extra'} = array_to_string(remove_prefixes(get_secondary_ipv4_addresses($REALSTATE->{'NIC'}->{$device}->{'UUID'}, $REALSTATE->{'NIC'}->{$device}->{'ipv4_ip'})));
            $REALSTATE->{'NIC'}->{$device}->{'ipv6_extra'} = array_to_string(remove_prefixes(get_secondary_ipv6_addresses($REALSTATE->{'NIC'}->{$device}->{'UUID'}, $REALSTATE->{'NIC'}->{$device}->{'ipv6_ip'})));

            # Add gateway back:
            $REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_gw'} = get_primary_ipv4_gateway();
            $REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv6_gw'} = get_primary_ipv6_gateway();

            # Add the DNS back:
            $REALSTATE->{'CODB_NIC'}->{$device}->{'dns'} = $REALSTATE->{'CONFIG'}->{'dns'};
        }
        else {
            # We're not a primary network inteface: Unset Gateways:
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_gw'} = '';
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_gw'} = '';
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_extra'} = '';
            $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_extra'} = '';
        }
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_ip'} = $net_info->{'ipaddr_IPv6'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'enabled'} = $net_info->{'enabled'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'real'} = '1';
        $REALSTATE->{'CODB_NIC'}->{$device}->{'mac'} = $net_info->{'mac'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'bootproto'} = $net_info->{'bootproto'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'bridgestate'} = 'none';
        $REALSTATE->{'CODB_NIC'}->{$device}->{'UUID'} = get_nmcli_uuid($device);
    }
    else {
        # This is the slave device for the bridge:
        $REALSTATE->{'NIC'}->{$device}->{'bridgestate'} = 'slave';
        $REALSTATE->{'CONFIG'}->{'bridge_slave'} = $device;
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_ip'} = $net_info->{'ipaddr'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_nm'} = $net_info->{'netmask'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_prefix'} = netmask_to_prefix($net_info->{'netmask'});
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_gw'} = $REALSTATE->{'CONFIG'}->{'gateway'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'dns'} = $REALSTATE->{'CONFIG'}->{'dns'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_ip'} = $net_info->{'ipaddr_IPv6'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_gw'} = $REALSTATE->{'CONFIG'}->{'gateway_IPv6'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_extra'} = $REALSTATE->{'CONFIG'}->{'extra_ipaddr'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_extra'} = $REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'enabled'} = $net_info->{'enabled'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'real'} = '1';
        $REALSTATE->{'CODB_NIC'}->{$device}->{'mac'} = $net_info->{'mac'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'bootproto'} = $net_info->{'bootproto'};
        $REALSTATE->{'CODB_NIC'}->{$device}->{'bridgestate'} = 'master';
        $REALSTATE->{'CODB_NIC'}->{$device}->{'UUID'} = $REALSTATE->{'NIC'}->{$primary_network_interface}->{'UUID'};
    }

    # Special case DHCP:
    #
    # If a device uses DHCP, our calculators for netmask and prefix are thrown off. So we set these manually here:
    if ($REALSTATE->{'NIC'}->{$device}->{'bootproto'} eq 'dhcp') {

        debug_msg("Final DHCP conclusion: $device DHCP state: " . $REALSTATE->{'NIC'}->{$device}->{'bootproto'} . "\n");

        $REALSTATE->{'NIC'}->{$device}->{'ipv4_ip'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'ipv4_nm'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'ipv4_prefix'} = '0';
        $REALSTATE->{'NIC'}->{$device}->{'ipv6_ip'} = '';

        # We have DHCP, so no extra-IPs are possible:
        $REALSTATE->{'NIC'}->{$device}->{'ipv4_extra'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'ipv6_extra'} = '';

        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv4_extra'} = '';
        $REALSTATE->{'CODB_NIC'}->{$device}->{'ipv6_extra'} = '';
        $HAVE_DHCP++;
    }
}

if (!defined $REALSTATE->{'CONFIG'}->{'bridge_slave'}) {
    $REALSTATE->{'CONFIG'}->{'bridge_slave'} = '';
}

#
### Logic to determine if a network change is needed:
#

my %results = compare_network_configs($REALSTATE);

# On highest debug level we show the generated $REALSTATE hash:
if ($DEBUG gt '2') {
    print Dumper(\$REALSTATE);
}

my $network_reconfigure_required = 0;
my $bridge_reconfigure_required = 0;
my $have_bridge_device = 0;
my @devices_that_need_updates = ();
for my $device (keys %results) {
    if ($device eq 'br0') {
        # We have the br0 bridge:
        $have_bridge_device = 1;
    }
    if ($device) {
        if ($results{$device}) {
            debug_msg("Network configuration for $device is already fully up to date.\n");
        }
        else {
            debug_msg("Network configuration for $device needs to be updated.\n");

            if (($REALSTATE->{'CONFIG'}->{'bridged_network'} eq '1') && ($have_bridge_device eq 1) && ($device eq $primary_network_interface) && ($device ne 'br0')) {
                $device = 'br0';
            }
            push @devices_that_need_updates, $device;
            $network_reconfigure_required++;
        }
    }
}

# If we are supposed to have bridged network, but we don't have a br0? Then we set an alarm:
if (($REALSTATE->{'CONFIG'}->{'bridged_network'} eq '0') && ($primary_network_interface eq 'br0')) {
    # We have the br0 bridge:
    $have_bridge_device = 1;

    # We need to create the br0 bridge:
    push @devices_that_need_updates, $primary_network_interface;
    $network_reconfigure_required++;
}

# If we are supposed to NOT have bridged network, but we actually DO have a br0? Then we set an alarm:
if (($REALSTATE->{'CONFIG'}->{'bridged_network'} eq '0') && ($have_bridge_device eq 1)) {

    # We need to create the br0 bridge:
    push @devices_that_need_updates, $primary_network_interface;
    $network_reconfigure_required++;

    # Reset 'bridge_master', as we obviously have none yet:
    $REALSTATE->{'CONFIG'}->{'bridge_master'} = 'br0';
}

# Migrate network settings to keyfile format if it hasn't been done yet:
$old_rh_format = `ls /etc/sysconfig/network-scripts/ifcfg-* 2>/dev/null | wc -l`;
chomp($old_rh_format);
if ($old_rh_format ne '0') {
    debug_msg("Migrating network config to keyfile format configuration.\n");
    system("/usr/bin/nmcli connection migrate");
    system("rm -f /etc/sysconfig/network-scripts/ifcfg-*");

    # Force an update of the network configs of ALL interfaces:
    my @all_interfaces = find_eth_ifaces();
    my %seen;
    foreach my $interface (@all_interfaces) {
        unless ($seen{$interface}++) {
            push @devices_that_need_updates, $interface;
        }
    }
    $network_reconfigure_required++;

    # Remove all active network aliases:
    my @aliases = `/sbin/ifconfig | grep '^[^ ]*:[0-9]' | awk '{print \$1}' | sed 's/:\$//'`;
    chomp(@aliases);

    foreach my $alias (@aliases) {
        debug_msg("Removing network alias $alias\n");
        system("/sbin/ifconfig $alias down");
    }
}

#
### Update Network Configuration:
#

#################################################
# For debug to force or ignore network updates:
#$network_reconfigure_required = 1;
#$network_reconfigure_required = 0;
#################################################

# Only update the Network settings if an interface has pending changes AND we're not a Container:
if (($network_reconfigure_required > 0) && ($WE_ARE_A_CONTAINER == 0)) {

    #
    ### Handle primary interface:
    #

    if ($REALSTATE->{'CONFIG'}->{'bridged_network'} eq '1') {

        #
        ### We are supposed to have bridged network: br0 + eth0 slave:
        #

        my $bridge_device = 'br0';
        my $slave_device = $primary_network_interface; # If we have no bridge yet, then this is the real primary network interface

        # Check if we have br0 and that eth0 is a slave:
        if (($REALSTATE->{'CONFIG'}->{'bridge_master'}) && ($REALSTATE->{'CONFIG'}->{'bridge_slave'})) {
            # We have a bridge configured. Get the device names:
            $bridge_device = $REALSTATE->{'CONFIG'}->{'bridge_master'} || 'br0';
            $slave_device = $REALSTATE->{'CONFIG'}->{'bridge_slave'};
            debug_msg("INFO: $bridge_device is present and $slave_device is a slave. Skipping brige and slave creation.\n");
        }
        else {

            debug_msg("INFO: br0 isn't present and/or $slave_device isn't a slave. Setting up proper bridge with slave.\n");

            my $br0_uuid = get_nmcli_uuid($bridge_device);

            #remove_connections('br0');

            # Get CODB network settings for primary network device from Hash:
            my $codb_pri_netdev = $REALSTATE->{'CODB_NIC'}->{$slave_device};

            # Get primary IPv4, Prefix and Gateway:
            my $ipaddr = $codb_pri_netdev->{'ipv4_ip'};
            my $prefix = $codb_pri_netdev->{'ipv4_prefix'};
            my $gateway = $codb_pri_netdev->{'ipv4_gw'};

            # Ensure $ipaddr, $netmask, and $gateway are defined for the bridge
            my $br0_ip_config = '';
            if ($ipaddr && $prefix) {
                $br0_ip_config = "ipv4.method manual ipv4.addresses ${ipaddr}/${prefix} ipv4.gateway ${gateway}";
            }
            else {
                debug_msg("IPv4 address or netmask for br0 is missing; cannot configure static IPv4\n");
            }

            # Get primary IPv6 and Gateway:
            my $ipaddr_IPv6 = $codb_pri_netdev->{'ipv6_ip'};
            my $gateway_IPv6 = $REALSTATE->{'CONFIG'}->{'gateway_IPv6'};

            if ($ipaddr_IPv6) {
                $br0_ip_config .= " ipv6.method manual ipv6.addresses ${ipaddr_IPv6} ipv6.gateway ${gateway_IPv6}";
            }
            else {
                debug_msg("IPv6 address or gateway for br0 is missing; cannot configure static IPv6\n");
            }

            if (!$br0_uuid) {
                debug_msg("br0 does not exist, creating br0 with static IP configuration\n");
                debug_msg("/usr/bin/nmcli con add type bridge ifname br0 con-name $bridge_device $br0_ip_config\n");
                system("/usr/bin/nmcli con add type bridge ifname br0 con-name $bridge_device $br0_ip_config");
            }
            else {
                if ($br0_ip_config) {
                    debug_msg("br0 does exist, setting up br0 with static IP configuration\n");
                    debug_msg("/usr/bin/nmcli con mod uuid $br0_uuid con-name $bridge_device $br0_ip_config\n");
                    system("/usr/bin/nmcli con mod uuid $br0_uuid con-name $bridge_device $br0_ip_config");
                }
                else {
                    debug_msg("br0 does exist, but we don't have a config for it!\n");
                }
            }

            # Configure br0 to automatically turn on:
            $br0_uuid = get_nmcli_uuid($bridge_device);
            debug_msg("/usr/bin/nmcli con modify uuid $br0_uuid con-name $bridge_device connection.autoconnect yes\n");
            system("/usr/bin/nmcli con modify uuid $br0_uuid con-name $bridge_device connection.autoconnect yes");

            # Fetch UUIDs for eth0 and br0
            debug_msg("Getting UUID for: $slave_device (\$slave_interface)\n");
            my $uuid_eth0 = get_nmcli_uuid($slave_device);
            debug_msg("Getting UUID for: $bridge_device\n");
            my $uuid_br0 = get_nmcli_uuid($bridge_device);

            # Modify existing eth0 connection to make it slave-ready:
            if ($uuid_eth0) {
                debug_msg("Modifying existing connection for $slave_device with UUID $uuid_eth0 to drop all IP settings. Expect a briefly interrupted network!\n");
                # Note these may throw errors, but we simply ignore these. The important part is that the persistent config is clean:
                system("/usr/bin/nmcli con modify uuid $uuid_eth0 con-name $slave_device ipv4.method auto ipv6.method auto");
                system("/usr/bin/nmcli con modify uuid $uuid_eth0 con-name $slave_device ipv4.addresses \"\" ipv6.addresses \"\" ipv4.gateway \"\" ipv6.gateway \"\"");
                system("/usr/bin/nmcli con modify uuid $uuid_eth0 con-name $slave_device ipv4.dns \"\" ipv4.dns-search \"\" ipv4.dns-options \"\" ipv4.ignore-auto-dns no");
                system("/usr/bin/nmcli con modify uuid $uuid_eth0 con-name $slave_device ipv4.method disabled ipv6.method ignore");
            }
            else {
                debug_msg("We have no $slave_device yet. Creating $slave_device with DHCP disabled.\n");
                system("/usr/bin/nmcli con add type ethernet ifname $slave_device con-name $slave_device ipv4.method disabled ipv6.method ignore");

                # Fetch new UUID for eth0
                debug_msg("Getting UUID for: $slave_device\n");
                $uuid_eth0 = get_nmcli_uuid($slave_device);
            }

            # Ensure eth0 is configured as a bridge slave
            if ($uuid_eth0 && $uuid_br0) {
                debug_msg("Modifying $slave_device with UUID $uuid_eth0 to be a slave of br0 with UUID $uuid_br0.\n");
                system("/usr/bin/nmcli con mod uuid $uuid_eth0 ipv4.method disabled ipv6.method disabled connection.slave-type bridge connection.master $uuid_br0");
            }
            else {
                debug_msg("UUID not found for $slave_device or br0; cannot modify slave-master relationship.\n");
            }
            # Configure eth0 to automatically turn on:
            debug_msg("/usr/bin/nmcli con modify uuid $uuid_eth0 connection.autoconnect yes\n");
            system("/usr/bin/nmcli con modify uuid $uuid_eth0 connection.autoconnect yes");
        }

        #
        ### We are now a bridge and configure our br0 with the proper Network settings:
        #

        my $uuid_br0 = get_nmcli_uuid('br0');

        if ($uuid_br0) {
            # Wipe the slate and remove all IPv4 and IPv6 configuration from the primary network interface:
            debug_msg("Wiping the slate for network device br0 - START\n");
            system("/usr/bin/nmcli con modify uuid $uuid_br0 ipv4.method auto ipv6.method auto");
            system("/usr/bin/nmcli con modify uuid $uuid_br0 ipv4.addresses \"\" ipv6.addresses \"\" ipv4.gateway \"\" ipv6.gateway \"\"");
            system("/usr/bin/nmcli con modify uuid $uuid_br0 ipv4.dns \"\" ipv4.dns-search \"\" ipv4.dns-options \"\" ipv4.ignore-auto-dns no");
            system("/usr/bin/nmcli con modify uuid $uuid_br0 ipv4.method disabled ipv6.method ignore");
            debug_msg("Wiping the slate for network device br0 - DONE\n");
        }

        # Get CODB network settings for primary network device from Hash:
        my $codb_br0 = $REALSTATE->{'CODB_NIC'}->{$slave_device};

        # Apply Network Settings to br0:
        my $is_primary = 1;
        configure_network_device('br0', $codb_br0, $is_primary);

        if ($uuid_br0) {
            # Set STP and VLAN filtering for br0
            system("/usr/bin/nmcli connection modify uuid $uuid_br0 bridge.stp no");
            system("/usr/bin/nmcli connection modify uuid $uuid_br0 bridge.vlan-filtering yes");
        }

        # Configure all other network devices:
        for my $device (keys %{$REALSTATE->{'CODB_NIC'}}) {
            if ($device eq $slave_device) {
                next;
            }
            $is_primary = 0;
            if (in_array(\@devices_that_need_updates, $device)) {
                debug_msg("1 *** Need to process $device ***\n");
                configure_network_device($device, $REALSTATE->{'CODB_NIC'}->{$device}, $is_primary);
            }
        }

        # This is a freshly configured bridge, because before we didn't have one. Therefore we need to
        # at least once bring br0 and its slave down and then up again:
        if ($have_bridge_device eq 0) {
            # Bring up br0 and eth0 - in that order!
            my $br0_uuid = get_nmcli_uuid('br0');
            my $uuid_eth0 = get_nmcli_uuid($primary_network_interface);
            network_device_change_state($uuid_eth0, 0);
            network_device_change_state($br0_uuid, 0);
            network_device_change_state($br0_uuid, 1);
            network_device_change_state($uuid_eth0, 1);
        }
    }
    else {

        #
        ### Check if primary network interface IS a bridge, but it SHOULD NOT be a bridge:
        #

        if ($have_bridge_device) {
            debug_msg("*** Primary Network Interface $primary_network_interface IS a bridge. But it SHOULD NOT be a bridge but a plain '" . $REALSTATE->{'CONFIG'}->{'bridge_slave'} . "' ***\n");

            my $br0_uuid = get_nmcli_uuid($primary_network_interface);
            &debug_msg("Bringing down br0 - This will cause a brief network interruption!\n");
            system("/usr/bin/nmcli con down uuid $br0_uuid");
            &debug_msg("Deleting interface br0\n");
            system("/usr/bin/nmcli con delete uuid $br0_uuid");
            remove_connections('br0');
            system("ip link delete br0");

            $primary_network_interface = $REALSTATE->{'CONFIG'}->{'bridge_slave'};

            my $eth0_uuid = get_nmcli_uuid($primary_network_interface);
            &debug_msg("/usr/bin/nmcli has determined primary Network device $primary_network_interface UUID to be '$eth0_uuid'\n");

            # Turn off slave mode for this device:
            &debug_msg("Telling eth0 that it is no longer a slave\n");
            system("/usr/bin/nmcli con modify uuid $eth0_uuid connection.master \"\" connection.slave-type \"\"");
            system("/usr/bin/nmcli con modify uuid $eth0_uuid ipv4.method disabled ipv6.method ignore");

            # Reset 'primary_network_interface' from br0 to the first network device that isn't a bridge: 
            $REALSTATE->{'CONFIG'}->{'primary_network_interface'} = $first_non_bridge_device;
        }

        #
        ### Primary Network Interface is NOT a bridge:
        #

        # Just to be sure we delete all ghosts of 'br0' that may linger around:
        remove_connections('br0');

        # Configure all network devices:
        for my $device (keys %{$REALSTATE->{'CODB_NIC'}}) {
            $is_primary = 0;
            if (in_array(\@devices_that_need_updates, $device)) {
                debug_msg("3 *** Need to process $device ***\n");

                # Check if this is the primary network device:
                if ($device eq $REALSTATE->{'CONFIG'}->{'primary_network_interface'}) {
                    $is_primary = 1;
                }

                # Apply correct network settings:
                configure_network_device($device, $REALSTATE->{'CODB_NIC'}->{$device}, $is_primary);

                # Check if device is up:
                my $is_up = network_device_check_state($device);
                debug_msg("Device $device has state: $is_up\n");

                if (($REALSTATE->{'CODB_NIC'}->{$device}->{'enabled'} eq '1') && ($is_up eq '0')) {
                    # Device should be up, but isn't. Fix that:
                    debug_msg("Bringing up device $device \n");
                    network_device_change_state($device_uuid, 1);
                }
            }
        }
    }
}

#
### Handle DHCP & IP Address Pooling: All Vsites get the IPv4 and/or IPv6 of the primary interface:
#

if ($HAVE_DHCP > 0) {

    $primary_dhcp_ipv4 = get_primary_ipv4_ip($primary_network_interface);
    $primary_dhcp_ipv6 = get_primary_ipv6_ip($primary_network_interface);

    debug_msg("DHCP is enabled (HAVE_DHCP: $HAVE_DHCP). Reconfiguring all Vsites to use current IPv4 ($primary_dhcp_ipv4) and/or IPv6 ($primary_dhcp_ipv6) IPs.\n");

    # Update IP in Virtual Site Template:
    my ($ok, $System_VsiteDefaults) = $cce->get($sysoid, 'VsiteDefaults');
    if (($System_VsiteDefaults->{ipaddr} ne $primary_dhcp_ipv4) || ($System_VsiteDefaults->{ipaddrIPv6} ne $primary_dhcp_ipv6)) {
        ($ok) = $cce->update($sysoid, 'VsiteDefaults', { 'ipaddr' => $primary_dhcp_ipv4, 'ipaddrIPv6' => $primary_dhcp_ipv6 });
    }

    # Find all Vsites:
    my @vhosts = ();
    my (@vhosts) = $cce->findx('Vsite');
 
    # Walk through all Vsites and update their IP's if necessary:
    for my $vsite (@vhosts) {
        ($ok, my $my_vsite) = $cce->get($vsite);
        if (($my_vsite->{'ipaddr'} ne $primary_dhcp_ipv4) || ($my_vsite->{'ipaddrIPv6'} ne $primary_dhcp_ipv6)) {
            ($ok) = $cce->update($vsite, '', { 'ipaddr' => $primary_dhcp_ipv4, 'ipaddrIPv6' => $primary_dhcp_ipv6 });
        }
    }

    # Handle IP Address Allocation:
    my ($ok, $System_Network) = $cce->get($sysoid, 'VsiteDefaults');

    if ($System_Network->{interfaceConfigure} eq '1') {
        $cce->update($sysoid, 'Network', { 'interfaceConfigure' => '0', 'pooling' => '0' });
    }

    # Find all 'IPPoolingRange' and delete those that don't match our current IPv4 and/or IPv6:
    my @pool_oids = $cce->find('IPPoolingRange');
    foreach my $oid (@pool_oids) {
        my ($ok, $pool_Object) = $cce->get($oid);
        if (($pool_Object->{min} ne $primary_dhcp_ipv4) && ($pool_Object->{min} ne $primary_dhcp_ipv6)) {
            my ($ok) = $cce->destroy($oid);
        }
    }

    # Check if we already have an 'IPPoolingRange' for our new IPv4:
    my @pri_ipv4_oids = $cce->find('IPPoolingRange', {'min' => $primary_dhcp_ipv4});
    if ($#pri_ipv4_oids < 0) {
        # We don't. Create it:
        $ok = $cce->create('IPPoolingRange', {'min' => $primary_dhcp_ipv4, 'max' => $primary_dhcp_ipv4, 'admin' => '&admin&' });
    }

    # Check if we already have an 'IPPoolingRange' for our new IPv6:
    my @pri_ipv6_oids = $cce->find('IPPoolingRange', {'min' => $primary_dhcp_ipv6});
    if ($#pri_ipv6_oids < 0) {
        # We don't. Create it:
        $ok = $cce->create('IPPoolingRange', {'min' => $primary_dhcp_ipv6, 'max' => $primary_dhcp_ipv6, 'admin' => '&admin&' });
    }

    # Turn IP Address Allocation back on:
    $cce->update($sysoid, 'Network', { 'interfaceConfigure' => '1', 'pooling' => '1' });
}

#
### Make sure these devices (if present) autoconnect and are up:
#

if ($WE_ARE_A_CONTAINER eq 0) {

    # Device 'lo':
    system("/usr/bin/nmcli con modify lo connection.autoconnect yes");
    my $check_lo_state = `/usr/bin/nmcli con show |grep ^lo|grep '\\-\\-'|wc -l`;
    chomp($check_lo_state);
    if ($check_lo_state eq '1') {
        system("/usr/bin/nmcli con up lo");
    }

    # Device 'incusbr0':
    my $incus_check = `/usr/bin/nmcli con show |grep ^incusbr0|wc -l`;
    chomp($incus_check);
    if ($incus_check eq '1') {
        system("/usr/bin/nmcli con modify incusbr0 connection.autoconnect yes");
    }
    my $check_incus_state = `/usr/bin/nmcli con show |grep ^incusbr0|grep '\\-\\-'|wc -l`;
    chomp($check_incus_state);
    if ($check_incus_state eq '1') {
        system("/usr/bin/nmcli con up incusbr0");
    }
}

#
### Update /etc/issue:
#

# Re-fetch the IPs:
$primary_ipv4 = get_primary_ipv4_ip();
$primary_ipv6 = get_primary_ipv6_ip();

# Open /etc/issue for writing
open(my $fhi, '>', '/etc/issue') or die "Could not open file '/etc/issue' $!";

# Print the basic system information
print $fhi $System->{productName} . " on \\S\n";
print $fhi "Kernel \\r on an \\m\n";
print $fhi "\n";
print $fhi "Server Name:  " . $System->{hostname} . "." . $System->{domainname} .  "\n";

# Print the primary IPv4 address if it is defined
if ($primary_ipv4) {
    print $fhi "IPv4 Address: $primary_ipv4\n";
}

# Print the primary IPv6 address if it is defined
if ($primary_ipv6) {
    print $fhi "IPv6 Address: $primary_ipv6\n";
}

print $fhi "\n";

# Close the file handle
close($fhi);

#
### All done!
#

debug_msg("All done. Exiting.\n");

# Release the lock and close the file
close $fh or die "Cannot close lock file: $!";

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub configure_network_device {
    my ($device, $codb_data, $is_primary) = @_;

    # Get primary IPv4, Prefix and Gateway:
    my $ipaddr = $codb_data->{'ipv4_ip'};
    my $netmask = $codb_data->{'ipv4_nm'};
    my $prefix = $codb_data->{'ipv4_prefix'};
    my $gateway = $codb_data->{'ipv4_gw'};
    my @dns = string_to_array($codb_data->{'dns'});

    # Get primary IPv6 and Gateway:
    my $ipaddr_IPv6 = $codb_data->{'ipv6_ip'} // '';
    my $gateway_IPv6 = $codb_data->{'ipv6_gw'} // '';

    # Extra IP addresses:
    my @ipv4_extra = string_to_array($codb_data->{'ipv4_extra'});
    my @ipv6_extra = string_to_array($codb_data->{'ipv6_extra'});

    # Is device enabled and real:
    my $device_enabled = $codb_data->{'enabled'};
    my $device_real = $codb_data->{'real'};

    # Get UUID of network device:
    my $uuid_pri = get_nmcli_uuid($device);

    # Get bootproto:
    my $bootproto = $codb_data->{'bootproto'};

    # Special case: Handle DHCP:
    if ($bootproto eq 'dhcp') {
        debug_msg("Network Device $device is supposed to use DHCP.\n");

        # Delete existing connection
        debug_msg("/usr/bin/nmcli con delete uuid $uuid_pri\n");
        system("/usr/bin/nmcli con delete uuid $uuid_pri");

        # Recreate connection with the same device name and configure for DHCP
        debug_msg("/usr/bin/nmcli con add type ethernet ifname $device con-name $device\n");
        system("/usr/bin/nmcli con add type ethernet ifname $device con-name $device");

        # Fetch UUID of freshly re-created network device:
        $uuid_pri = get_nmcli_uuid($device);

        # Set IPv4 and IPv6 method to DHCP
        debug_msg("/usr/bin/nmcli con mod uuid $uuid_pri ipv4.method auto ipv4.ignore-auto-dns no\n");
        system("/usr/bin/nmcli con mod uuid $uuid_pri ipv4.method auto ipv4.ignore-auto-dns no");
        debug_msg("/usr/bin/nmcli con mod uuid $uuid_pri ipv6.method auto ipv6.ignore-auto-dns no\n");
        system("/usr/bin/nmcli con mod uuid $uuid_pri ipv6.method auto ipv6.ignore-auto-dns no");

        debug_msg("Configured DHCP for device $device\n");

        # Configure device to automatically turn on:
        debug_msg("/usr/bin/nmcli con mod uuid $uuid_pri connection.autoconnect yes\n");
        system("/usr/bin/nmcli con mod uuid $uuid_pri connection.autoconnect yes");

        my (@Network_OIDs) = $cce->findx('Network', '', { 'device' => $device, 'real' => '1' });
        my ($ok) = $cce->update($Network_OIDs[0], '', { 'enabled' => 1, 'ipaddr' => '', 'netmask' => '', 'ipaddr_IPv6' => '', 'bootproto' => 'dhcp' });
        if ($ok) {
            debug_msg("Updated CODB settings for: $device\n");
        }

        # Bring up connection:
        debug_msg("Bringing up device: $device\n");
        network_device_change_state($uuid_pri, 1);

        # Return early, as there is nothing more to do:
        return 1;
    }

    # Set IPv4 configuration:
    if ($ipaddr ne '' && $netmask ne '' && $uuid_pri) {
        my $ipv4_dns = '';

        # If we have at least one IPv4 we set this up here:
        my $ipv4_extra_line = $ipaddr . '/' . $prefix;

        # This is a primary interface
        if ($is_primary eq 1) {
            # Aggregate DNS into a complete config line:
            if ($dns[0]) {
                $ipv4_dns = 'ipv4.dns "';
                $ipv4_dns .= join(',', @dns);
                $ipv4_dns .= '"';
            }

            # Aggregate IPv4 extra IPs into a config line:
            my @all_wanted_ipv4 = ();
            foreach my $x (@ipv4_extra) {
                if (($x ne '') && ($x ne $primary_ipv4)) {
                    debug_msg("Adding IPv4 IP $x/32 to \@all_wanted_ipv4\n");
                    push @all_wanted_ipv4, $x . '/32';
                }
            }

            # We have a primary IPv4 with prefix:
            my $pri_ipv4 = $ipaddr . '/' . $prefix;

            # Push our primary IPv4 to the beginning of the array:
            unshift(@all_wanted_ipv4, $pri_ipv4);
            $ipv4_extra_line = join(",", @all_wanted_ipv4);
        }

        my $gw_line = '';
        if ($gateway) {
            $gw_line = "ipv4.gateway ${gateway}";
        }
        else {
            # We have no gateway and we're not creating default routes for this:
            $gw_line = "ipv4.gateway \"\" ipv4.never-default true";
        }

        # Setting IPv4:
        debug_msg("Configuring IPv4 network settings for network device $device\n");
        debug_msg("/usr/bin/nmcli con mod uuid $uuid_pri con-name $device ipv4.method manual ipv4.addresses '${ipv4_extra_line}' $gw_line $ipv4_dns\n");
        system("/usr/bin/nmcli con mod uuid $uuid_pri con-name $device ipv4.method manual ipv4.addresses '${ipv4_extra_line}' $gw_line $ipv4_dns");
    }
    else {
        # We don't have IPv4!
        debug_msg("I DO NOT HAVE IPv4!\n");
        system("/usr/bin/nmcli con modify uuid $uuid_pri con-name $device ipv4.method auto");
        system("/usr/bin/nmcli con modify uuid $uuid_pri ipv4.addresses \"\" ipv4.gateway \"\"");
        system("/usr/bin/nmcli con modify uuid $uuid_pri ipv4.dns \"\" ipv4.dns-search \"\" ipv4.dns-options \"\" ipv4.ignore-auto-dns no");
        debug_msg("/usr/bin/nmcli con modify uuid $uuid_pri ipv4.method disabled \n");
        system("/usr/bin/nmcli con modify uuid $uuid_pri ipv4.method disabled");
    }

    # Set IPv6 configuration:
    if ($ipaddr_IPv6 ne '' && $uuid_pri) {
        # We have a primary IPv6 without netmask. We set this up here:
        my $ipv6_extra_line = $ipaddr_IPv6;

        # This is a primary interface
        if ($is_primary eq 1) {
            # Aggregate IPv6 extra IPs into a config line:
            my @all_wanted_ipv6 = ();
            foreach my $x (@ipv6_extra) {
                if (($x ne '') && ($x ne $primary_ipv6)) {
                    debug_msg("Adding IPv6 IP $x/128 to \@all_wanted_ipv6\n");
                    push @all_wanted_ipv6, $x . '/128';
                }
            }

            # Push our primary IPv6 to the beginning of the array:
            unshift(@all_wanted_ipv6, $ipaddr_IPv6);
            $ipv6_extra_line = join(",", @all_wanted_ipv6);
        }

        my $gw_line = '';
        debug_msg("Processing network device $device and I have gateway_IPv6: $gateway_IPv6\n");
        if ($gateway_IPv6) {
            $gw_line = "ipv6.gateway ${gateway_IPv6}";
        }
        else {
            # We have no gateway and we're not creating default routes for this:
            $gw_line = "ipv6.gateway \"\" ipv6.never-default true";
        }

        # Setting IPv6:
        debug_msg("Configuring IPv6 network settings for network device $device\n");
        debug_msg("/usr/bin/nmcli con mod uuid $uuid_pri con-name $device ipv6.method manual ipv6.addresses '${ipv6_extra_line}' $gw_line\n");
        system("/usr/bin/nmcli con mod uuid $uuid_pri con-name $device ipv6.method manual ipv6.addresses '${ipv6_extra_line}' $gw_line");
    }
    else {
        # We don't have IPv6!
        debug_msg("I DO NOT HAVE IPv6!\n");
        debug_msg("/usr/bin/nmcli con modify uuid $uuid_pri con-name $device ipv6.method ignore\n");
        system("/usr/bin/nmcli con modify uuid $uuid_pri con-name $device ipv6.method ignore");
        debug_msg("/usr/bin/nmcli con modify uuid $uuid_pri ipv6.addresses \"\" ipv6.gateway \"\" \n");
        system("/usr/bin/nmcli con modify uuid $uuid_pri ipv6.addresses \"\" ipv6.gateway \"\"");
    }

    # Set device to automatically turn on/off depending on settings:

    # If we have IPv4 and/or IPv6 and/or DHCP we turn the interface on:
    if (((($ipaddr ne '' && $netmask ne '' && $uuid_pri) || ($ipaddr_IPv6 ne '')) && ($device_enabled && $device_real)) || ($bootproto eq 'dhcp')) {
        # Configure device to automatically turn on:
        debug_msg("/usr/bin/nmcli con modify uuid $uuid_pri con-name $device connection.autoconnect yes\n");
        system("/usr/bin/nmcli con modify uuid $uuid_pri con-name $device connection.autoconnect yes");

        # Bring up connection:
        debug_msg("Bringing up device: $device\n");
        network_device_change_state($uuid_pri, 1);
    }
    else {
        # Configure device to NOT turn on automatically:
        debug_msg("/usr/bin/nmcli con modify uuid $uuid_pri con-name $device connection.autoconnect no\n");
        system("/usr/bin/nmcli con modify uuid $uuid_pri con-name $device connection.autoconnect no");

        # Bring down connection:
        debug_msg("Bringing down device: $device\n");
        network_device_change_state($uuid_pri, 0);

        my (@Network_OIDs) = $cce->findx('Network', '', { 'device' => $device, 'real' => '1' });
        my ($ok) = $cce->update($Network_OIDs[0], '', { 'enabled' => 0, 'ipaddr' => '', 'netmask' => '', 'ipaddr_IPv6' => '' });
        if ($ok) {
            debug_msg("Updated CODB settings for: $device\n");
        }
    }
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
