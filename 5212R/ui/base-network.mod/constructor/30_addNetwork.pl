#!/usr/bin/perl
#
# $Id: 30_addNetwork.pl
#
# Note: This script relies heavily on subroutines from 
# /usr/sausalito/handlers/base/network/Network.pm
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
use List::Util qw(first);
use Fcntl ':flock'; # Import LOCK_* constants
use Sys::Hostname;
use Sauce::Config;
use Sauce::Util;

# Debugging switch:
$DEBUG = '1'; # 0 = off, 1 = syslog, 2 = syslog and screen # 3 = Extra info
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectuds();

# Handle bootproto=dhcp:
my $bootproto = 'none';
if (-f "/etc/is_aws") {
    my $is_aws = "1";
    $bootproto = 'dhcp';
}

my $errors = 0;

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
debug_msg("primary_network_interface: $primary_network_interface\n");

# Get all the names of all current interfaces and aliases
my @devices = find_eth_ifaces();

# find_eth_ifaces failed if @devices is empty
if (!scalar(@devices)) {
    debug_msg("No network devices found!\n");
    $cce->bye('FAIL', '[[base-network.noEthIfsFound]]');
    exit(1);
}

#
### File locking:
#

# Define the lock file
my $lock_file = '/var/lock/constructor_network_apply.lock';

# Open the lock file (or create it if it doesn't exist)
open my $fh, '>', $lock_file or die "Cannot open lock file: $!";

debug_msg("****** Waiting for lock. ****** \n");

# Try to get an exclusive lock on the file
# This will block until it can get the lock
flock($fh, LOCK_EX) or die "Cannot lock file: $!";

debug_msg("****** Got exclusive lock. ****** \n");

# Check if br0 exists to handle bridged network
my $bridged_network = grep { $_ eq 'br0' } @devices;

# Check which IP addresses are in use by (enabled) Vsites via their 'VirtualHost' CODB objects:
@vhost = ();
@vsite_ipv4_secondaries = ();
@vsite_ipv6_secondaries = ();

my $primary_ipv4 = get_primary_ipv4_ip();
my $primary_ipv6 = get_primary_ipv6_ip();

#
### Special Incus privions (Cloud-Init that works!)
#

my %BX_CLOUD = {};
$bx_cloud_cfg = '/tmp/bx_cloud_cfg';
if ((-e '/dev/incus/sock') && (-f $bx_cloud_cfg)) {
    open my $fh, '<', $bx_cloud_cfg or die "Could not open '$bx_cloud_cfg': $!";

    while (my $line = <$fh>) {
        chomp $line;
        my ($key, $value) = split(/\|/, $line, 2);

        # Check if the key is not empty and the value is defined
        if (defined $key && $key ne '' && defined $value && $value ne '') {
            if ($key eq 'FQDN') {
                my ($hostname, $domainname) = split(/\./, $value, 2);
                $BX_CLOUD->{'hostname'} = $hostname;
                $BX_CLOUD->{'domainname'} = $domainname;
            }
            else {
                $BX_CLOUD->{$key} = $value;
            }
        }
    }
    close $fh;

    if ($BX_CLOUD->{'ipaddr'}) {
        $primary_ipv4 = $BX_CLOUD->{'ipaddr'};
    }

    if ($BX_CLOUD->{'ipaddr_IPv6'}) {
        $primary_ipv6 = $BX_CLOUD->{'ipaddr_IPv6'};
    }
}

debug_msg("primary_ipv4: $primary_ipv4 \n");
debug_msg("primary_ipv6: $primary_ipv6 \n");

#
### Special Anaconda ISO-Install handling:
# If we have NO Incus config BUT an existing NM config from Anaconda?
# Then use THAT network configuration (respect user's Anaconda settings!)
#
my %ANACONDA_NET = {};
my $anaconda_nm_file = '/etc/NetworkManager/system-connections/eth0.nmconnection';

if ((!-e '/dev/incus/sock') && (-f $anaconda_nm_file) && ($primary_ipv4 eq '')) {
    # Parse the NM keyfile
    open my $fh, '<', $anaconda_nm_file or debug_msg("Could not open '$anaconda_nm_file': $!");
    if ($fh) {
        my $section = '';
        while (my $line = <$fh>) {
            chomp $line;
            # Section headers like [connection], [ipv4], [ipv6]
            if ($line =~ /^\[(\w+)\]/) {
                $section = $1;
                next;
            }
            # Skip comments and empty lines
            next if $line =~ /^#/ || $line =~ /^\s*$/;
            
            my ($key, $value) = split(/=/, $line, 2);
            next unless defined $key && defined $value;
            
            if ($section eq 'ipv4') {
                if ($key eq 'address1') {
                    # Format: address1=208.77.151.215/28,208.77.151.193
                    my ($ip_prefix, $gw) = split(/,/, $value);
                    if ($ip_prefix =~ /^([\d\.]+)\/(\d+)$/) {
                        $ANACONDA_NET->{'ipaddr'} = $1;
                        $ANACONDA_NET->{'prefix'} = $2;
                        # Convert prefix to netmask
                        $ANACONDA_NET->{'netmask'} = prefix_to_netmask($2);
                    }
                    if ($gw && $gw =~ /^[\d\.]+$/) {
                        $ANACONDA_NET->{'gateway'} = $gw;
                    }
                }
                elsif ($key eq 'dns') {
                    $ANACONDA_NET->{'dns'} = $value;
                }
                elsif ($key eq 'method') {
                    $ANACONDA_NET->{'method'} = $value;
                }
            }
            elsif ($section eq 'ipv6') {
                if ($key eq 'address1') {
                    # Format: address1=2001:db8::1/64
                    if ($value =~ /^([\da-fA-F:]+)\/(\d+)$/) {
                        $ANACONDA_NET->{'ipaddr_IPv6'} = $1;
                        $ANACONDA_NET->{'prefix_IPv6'} = $2;
                    }
                }
                elsif ($key eq 'gateway') {
                    $ANACONDA_NET->{'gateway_IPv6'} = $value;
                }
                elsif ($key eq 'method') {
                    $ANACONDA_NET->{'method_IPv6'} = $value;
                }
            }
        }
        close $fh;
        
        # Apply extracted values if we got an IP
        if ($ANACONDA_NET->{'ipaddr'} || $ANACONDA_NET->{'ipaddr_IPv6'}) {
            debug_msg("Found Anaconda network config! Using it instead of defaults.\n");
            if ($ANACONDA_NET->{'ipaddr'}) {
                $primary_ipv4 = $ANACONDA_NET->{'ipaddr'};
                debug_msg("Anaconda IPv4: $primary_ipv4\n");
            }
            if ($ANACONDA_NET->{'ipaddr_IPv6'}) {
                $primary_ipv6 = $ANACONDA_NET->{'ipaddr_IPv6'};
                debug_msg("Anaconda IPv6: $primary_ipv6\n");
            }
        }
    }
}

# Helper function to convert prefix to netmask
sub prefix_to_netmask {
    my ($prefix) = @_;
    my $mask = 0xffffffff << (32 - $prefix);
    return sprintf "%d.%d.%d.%d", 
        ($mask >> 24) & 0xff,
        ($mask >> 16) & 0xff,
        ($mask >> 8) & 0xff,
        $mask & 0xff;
}

my (@vhost) = $cce->find('VirtualHost', { 'enabled' => '1' });
for my $vsite_oid (@vhost) {
    ($ok, $vsite) = $cce->get($vsite_oid);
    if ($ok) {
        if (defined $vsite->{ipaddr} && $vsite->{ipaddr} ne $server_primary_ipv4) {
            # Add IPv4 secondary if we don't have it already:
            unless (first { $_ eq $vsite->{ipaddr} } @vsite_ipv4_secondaries) {
                if ($primary_ipv4 ne $vsite->{ipaddr}) {
                    push @vsite_ipv4_secondaries, $vsite->{ipaddr};
                }
            }
        }
        if (defined $vsite->{ipaddrIPv6} && $vsite->{ipaddrIPv6} ne $server_primary_ipv6) {
            # Add IPv6 secondary if we don't have it already:
            unless (first { $_ eq $vsite->{ipaddrIPv6} } @vsite_ipv6_secondaries) {
                if ($primary_ipv6 ne $vsite->{ipaddrIPv6}) {
                    push @vsite_ipv6_secondaries, $vsite->{ipaddrIPv6};
                }
            }
        }
    }
}

# Sort the results:
sort(@vsite_ipv4_secondaries);
sort(@vsite_ipv6_secondaries);

# Setup Hash:
my %REALSTATE = {};

# Store relevant info for 'System' Object update:
$REALSTATE->{'CONFIG'}->{'bridged_network'} = $bridged_network;
$REALSTATE->{'CONFIG'}->{'extra_ipaddr'} = array_to_string(@vsite_ipv4_secondaries);
$REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'} = array_to_string(@vsite_ipv6_secondaries);
$REALSTATE->{'CONFIG'}->{'gateway'} = get_primary_ipv4_gateway($primary_network_interface);
$REALSTATE->{'CONFIG'}->{'gateway_IPv6'} = get_primary_ipv6_gateway($primary_network_interface);
$REALSTATE->{'CONFIG'}->{'dns'} = array_to_string(get_primary_dns_servers($primary_network_interface));
$REALSTATE->{'CONFIG'}->{'bootproto'} = $bootproto;
$REALSTATE->{'CONFIG'}->{'primary_network_interface'} = $primary_network_interface;

# Perform Icus Instance configuration:
if ((-e '/dev/incus/sock') && (-f $bx_cloud_cfg)) {
    # Obj 'Network' for 'eth0';
    my $bx_net_device = 'eth0';
    $REALSTATE->{'NIC'}->{$bx_net_device}->{'real'} = 1;
    $REALSTATE->{'NIC'}->{$bx_net_device}->{'netmask'} = $BX_CLOUD->{'netmask'};
    $REALSTATE->{'NIC'}->{$bx_net_device}->{'bootproto'} = 'none';
    if (($BX_CLOUD->{'dhcp4'} eq 'yes') || ($BX_CLOUD->{'dhcp6'} eq 'yes')) {
        $REALSTATE->{'NIC'}->{$bx_net_device}->{'bootproto'} = 'dhcp';
    }
    $REALSTATE->{'NIC'}->{$bx_net_device}->{'ipaddr_IPv6'} = $BX_CLOUD->{'ipaddr_IPv6'};
    $REALSTATE->{'NIC'}->{$bx_net_device}->{'enabled'} = 1;
    $REALSTATE->{'NIC'}->{$bx_net_device}->{'ipaddr'} = $BX_CLOUD->{'ipaddr'};
    $REALSTATE->{'NIC'}->{$bx_net_device}->{'mac'} = get_device_mac($bx_net_device);

    # 'System' object data:
    $REALSTATE->{'CONFIG'}->{'dns'} = $BX_CLOUD->{'dns'};
    $REALSTATE->{'CONFIG'}->{'gateway'} = $BX_CLOUD->{'gateway'};
    $REALSTATE->{'CONFIG'}->{'gateway_IPv6'} = $BX_CLOUD->{'gateway_IPv6'};
    $REALSTATE->{'CONFIG'}->{'extra_ipaddr'} = '';
    $REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'} = '';
    $REALSTATE->{'CONFIG'}->{'hostname'} = $BX_CLOUD->{'hostname'};
    $REALSTATE->{'CONFIG'}->{'domainname'} = $BX_CLOUD->{'domainname'};

    # Type of network:
    my $IPType = 'BOTH';
    if ((($BX_CLOUD->{'ipaddr'} != '') && ($BX_CLOUD->{'ipaddr_IPv6'} != '')) || (($BX_CLOUD->{'dhcp4'} eq 'yes') && ($BX_CLOUD->{'dhcp6'} eq 'yes'))) {
        $IPType = 'VZBOTH';
    }
    elsif ((($BX_CLOUD->{'ipaddr'} != '') && ($BX_CLOUD->{'ipaddr_IPv6'} == '')) || (($BX_CLOUD->{'dhcp4'} eq 'yes') && ($BX_CLOUD->{'dhcp6'} eq 'no'))) {
        $IPType = 'VZv4';
    }
    elsif ((($BX_CLOUD->{'ipaddr'} == '') && ($BX_CLOUD->{'ipaddr_IPv6'} != '')) || (($BX_CLOUD->{'dhcp4'} eq 'no') && ($BX_CLOUD->{'dhcp6'} eq 'yes'))) {
        $IPType = 'VZv6';
    }
    else {
        $IPType = 'BOTH';
    }

    my $primary_ipv6 = get_primary_ipv6_ip();

    if ($REALSTATE->{'NIC'}->{$bx_net_device}->{'ipaddr_IPv6'} != $primary_ipv6) {
        if ($primary_ipv6 != '') {
            $ip_rem_line = $primary_ipv6;
            system("/usr/sbin/ip addr del $ip_rem_line dev eth0");
        }
    }

    # Prepare result for publishing:
    $REALSTATE->{'CONFIG'}->{'IPType'} = $IPType;

    # Early commit:
    my $bx_net_device = 'eth0';
    my @net_oid = $cce->find('Network',  { 'device' => $bx_net_device } );
    if ($net_oid[0]) {
        debug_msg("Have $bx_net_device in CODB as OID: $net_oid[0]\n");

        # Run $cce->update() which will only perform a SET if the data in CODB is different:
        my ($ok) = $cce->set($net_oid[0], '', $REALSTATE->{'NIC'}->{$bx_net_device});

        # Remove OID of this existing Network device from @Network_OIDs):
        @Network_OIDs = grep { $_ ne $net_oid[0] } @Network_OIDs;

    }
    else {
        debug_msg("Do not Have $bx_net_device in CODB\n");

        # No Network object for this device in CODB yet. So we create one:
        $REALSTATE->{'NIC'}->{$bx_net_device}->{device} = $bx_net_device;
        $REALSTATE->{'NIC'}->{$bx_net_device}->{refresh} = time();

        my ($success) = $cce->create('Network', $REALSTATE->{'NIC'}->{$bx_net_device});
        if (!$success) {
            debug_msg("Failed to create Network object for $bx_net_device\n");
            $errors++;
        } 
        else {
            debug_msg("Created Network object for $bx_net_device.\n");
        }
        # Turn on NAT and IPForwarding
        hack_on_nat($sysoid);
    }

    # Update 'System' object:
    my $OUT_HASH = $REALSTATE->{'CONFIG'};
    delete $OUT_HASH->{'bootproto'};
    delete $OUT_HASH->{'primary_network_interface'};
    my ($ok) = $cce->set($sysoid, '', $OUT_HASH);
    if (!$ok) {
        debug_msg("Updating of 'System' object failed!\n");
    }

    # Release the lock and close the file before triggering the actual network update via 'System' 'nw_update':
    close $fh or die "Cannot close lock file: $!";

    # Remove $bx_cloud_cfg:
    system("rm -f $bx_cloud_cfg");

    debug_msg("All done. Exiting.\n");
    $cce->bye('SUCCESS');
    exit(0);
}

#
### Handle Anaconda ISO-Install network config:
# If we parsed a valid Anaconda NM config earlier, apply it to REALSTATE now.
# This ensures Anaconda's network settings are preserved in CODB.
#
if ((defined $ANACONDA_NET->{'ipaddr'} && $ANACONDA_NET->{'ipaddr'} ne '') ||
    (defined $ANACONDA_NET->{'ipaddr_IPv6'} && $ANACONDA_NET->{'ipaddr_IPv6'} ne '')) {
    
    debug_msg("Applying Anaconda network config to REALSTATE.\n");
    
    my $anaconda_device = 'eth0';
    $REALSTATE->{'NIC'}->{$anaconda_device}->{'real'} = 1;
    $REALSTATE->{'NIC'}->{$anaconda_device}->{'enabled'} = 1;
    $REALSTATE->{'NIC'}->{$anaconda_device}->{'mac'} = get_device_mac($anaconda_device);
    
    # IPv4 config
    if ($ANACONDA_NET->{'ipaddr'}) {
        $REALSTATE->{'NIC'}->{$anaconda_device}->{'ipaddr'} = $ANACONDA_NET->{'ipaddr'};
        $REALSTATE->{'NIC'}->{$anaconda_device}->{'netmask'} = $ANACONDA_NET->{'netmask'} || '255.255.255.0';
        $REALSTATE->{'CONFIG'}->{'gateway'} = $ANACONDA_NET->{'gateway'} if $ANACONDA_NET->{'gateway'};
    }
    
    # IPv6 config
    if ($ANACONDA_NET->{'ipaddr_IPv6'}) {
        $REALSTATE->{'NIC'}->{$anaconda_device}->{'ipaddr_IPv6'} = $ANACONDA_NET->{'ipaddr_IPv6'};
        $REALSTATE->{'CONFIG'}->{'gateway_IPv6'} = $ANACONDA_NET->{'gateway_IPv6'} if $ANACONDA_NET->{'gateway_IPv6'};
    }
    
    # DNS
    if ($ANACONDA_NET->{'dns'}) {
        $REALSTATE->{'CONFIG'}->{'dns'} = $ANACONDA_NET->{'dns'};
    }
    
    # bootproto (dhcp vs manual)
    if ($ANACONDA_NET->{'method'} eq 'auto' || $ANACONDA_NET->{'method'} eq 'dhcp') {
        $REALSTATE->{'NIC'}->{$anaconda_device}->{'bootproto'} = 'dhcp';
    }
    else {
        $REALSTATE->{'NIC'}->{$anaconda_device}->{'bootproto'} = 'none';
    }
    
    # Determine IPType
    my $IPType = 'BOTH';
    if ($ANACONDA_NET->{'ipaddr'} && $ANACONDA_NET->{'ipaddr_IPv6'}) {
        $IPType = 'BOTH';
    }
    elsif ($ANACONDA_NET->{'ipaddr'} && !$ANACONDA_NET->{'ipaddr_IPv6'}) {
        $IPType = 'IPv4';
    }
    elsif (!$ANACONDA_NET->{'ipaddr'} && $ANACONDA_NET->{'ipaddr_IPv6'}) {
        $IPType = 'IPv6';
    }
    $REALSTATE->{'CONFIG'}->{'IPType'} = $IPType;
    
    debug_msg("Anaconda config applied: IP=$ANACONDA_NET->{'ipaddr'}, GW=$ANACONDA_NET->{'gateway'}\n");
}

# Do we have DHCP somewhere?
my $HAVE_DHCP = 0;

# Get info for all network interfaces:
foreach my $device (@devices) {

    if ($device eq 'br0') {
        # Skip br0 device:
        next;
    }

    # Check if this is a slave network for a bridge:
    my $slave_check = check_if_slave($device);
    if ($slave_check eq '0') {
        # It isn't. Store the real state of the nic:
        # BUT: Skip if this is eth0 and we have Anaconda config (preserve Anaconda settings!)
        if ($device eq 'eth0' && ((defined $ANACONDA_NET->{'ipaddr'} && $ANACONDA_NET->{'ipaddr'} ne '') ||
                                   (defined $ANACONDA_NET->{'ipaddr_IPv6'} && $ANACONDA_NET->{'ipaddr_IPv6'} ne ''))) {
            debug_msg("Preserving Anaconda network config for eth0, skipping system detection.\n");
            # Just fill in missing pieces (MAC, UUID, enabled, real)
            $REALSTATE->{'NIC'}->{$device}->{'mac'} = get_device_mac($device) unless $REALSTATE->{'NIC'}->{$device}->{'mac'};
            $REALSTATE->{'NIC'}->{$device}->{'UUID'} = get_nmcli_uuid($device);
            $REALSTATE->{'NIC'}->{$device}->{'enabled'} = '1';
            $REALSTATE->{'NIC'}->{$device}->{'real'} = '1';
            $REALSTATE->{'NIC'}->{$device}->{'bridgestate'} = 'none';
            next;
        }
        $REALSTATE->{'NIC'}->{$device}->{'ipaddr'} = get_primary_ipv4_ip($device);
        $REALSTATE->{'NIC'}->{$device}->{'netmask'} = get_primary_ipv4_netmask($device);
        $REALSTATE->{'NIC'}->{$device}->{'ipaddr_IPv6'} = get_primary_ipv6_ip($device);
        $REALSTATE->{'NIC'}->{$device}->{'enabled'} = '1';
        $REALSTATE->{'NIC'}->{$device}->{'real'} = '1';
        $REALSTATE->{'NIC'}->{$device}->{'mac'} = get_device_mac($device);
        if ($bridged_network eq '1') {
            # Can't have bridge and DHCP!
            $REALSTATE->{'NIC'}->{$device}->{'bootproto'} = 'none';
        }
        else {
            $REALSTATE->{'NIC'}->{$device}->{'bootproto'} = blueonyx_dhcp($primary_network_interface);
            if ($REALSTATE->{'NIC'}->{$device}->{'bootproto'} eq 'dhcp') {
                # This NIC uses DHCP!
                $HAVE_DHCP++;                
            }
        }
        $REALSTATE->{'NIC'}->{$device}->{'bridgestate'} = 'none';
        $REALSTATE->{'NIC'}->{$device}->{'UUID'} = get_nmcli_uuid($device);

        if (($device = $primary_network_interface) && ($bridged_network eq '1')) {
            $REALSTATE->{'NIC'}->{$device}->{'bridgestate'} = 'master';
            $REALSTATE->{'CONFIG'}->{'bridge_master'} = $device;
        }

    }
    else {
        # This is the slave device for the bridge:
        $REALSTATE->{'NIC'}->{$device}->{'bridgestate'} = 'slave';
        $REALSTATE->{'NIC'}->{$device}->{'bootproto'} = 'none';
        $REALSTATE->{'CONFIG'}->{'bridge_slave'} = $device;

        $REALSTATE->{'NIC'}->{$device}->{'UUID'} = get_nmcli_uuid($primary_network_interface);
        $REALSTATE->{'NIC'}->{$device}->{'ipaddr'} = get_primary_ipv4_ip($primary_network_interface);
        $REALSTATE->{'NIC'}->{$device}->{'netmask'} = get_primary_ipv4_netmask($primary_network_interface);
        $REALSTATE->{'NIC'}->{$device}->{'ipaddr_IPv6'} = get_primary_ipv6_ip($primary_network_interface);
        $REALSTATE->{'NIC'}->{$device}->{'enabled'} = '1';
        $REALSTATE->{'NIC'}->{$device}->{'real'} = '1';
        $REALSTATE->{'NIC'}->{$device}->{'mac'} = get_device_mac($primary_network_interface);        
    }

    # Special case DHCP:
    #
    # If a device uses DHCP, our calculators for netmask and prefix are thrown off. So we set these manually here:
    if ($REALSTATE->{'NIC'}->{$device}->{'bootproto'} eq 'dhcp') {
        $REALSTATE->{'NIC'}->{$device}->{'ipaddr'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'netmask'} = '';
        $REALSTATE->{'NIC'}->{$device}->{'ipaddr_IPv6'} = '';
    }
}

if ($DEBUG gt 1) {
    print "Established current configuration of \%REALSTATE:\n";
    print Dumper(\$REALSTATE);
}

# We have bridged network and know who slave and master are. We copy the data from 'br0' to the slave and delete 'br0' from the hash:
if (($bridged_network eq '1') && ($REALSTATE->{'CONFIG'}->{'bridge_master'}) && ($REALSTATE->{'CONFIG'}->{'bridge_slave'})) {
    my $bridge_master = $REALSTATE->{'CONFIG'}->{'bridge_master'};
    my $bridge_slave = $REALSTATE->{'CONFIG'}->{'bridge_slave'};

    # Copy 'br0' settings over to the slave interface:
    #$REALSTATE->{'NIC'}->{$bridge_slave} = $REALSTATE->{'NIC'}->{$bridge_master};

    # Delete 'br0' from the 'NIC' hash
    delete $REALSTATE->{'NIC'}->{'br0'};
    debug_msg("Have br0 (which needs no CODB Object)\n");
}

# Get a list of OIDs for all 'Network' objects currently in CODB:
my (@Network_OIDs) = $cce->findx('Network', '', { 'real' => '1' });

# Walk through all discovered network devices and update CODB if existing networks have
# a different configuration than what is stored in CODB:
for my $device (keys %{$REALSTATE->{'NIC'}}) {

    # Remove keys we don't need:
    delete $REALSTATE->{'NIC'}->{$device}->{'UUID'};
    delete $REALSTATE->{'NIC'}->{$device}->{'bridgestate'};

    if ($HAVE_DHCP > 0) {
        # To be double sure to have this off when DHCP is used:
        debug_msg("DHCP is enabled (HAVE_DHCP: $HAVE_DHCP) for device $device. Removing 'netmask'.\n");
        $REALSTATE->{'NIC'}->{$device}->{'netmask'} = '';
    }

    # Check if we have that device in CODB:
    my @net_oid = $cce->find('Network',  { 'device' => $device } );
    if ($net_oid[0]) {
        debug_msg("Have $device in CODB as OID: $net_oid[0]\n");

        # Run $cce->update() which will only perform a SET if the data in CODB is different:
        my ($ok) = $cce->set($net_oid[0], '', $REALSTATE->{'NIC'}->{$device});

        # Remove OID of this existing Network device from @Network_OIDs):
        @Network_OIDs = grep { $_ ne $net_oid[0] } @Network_OIDs;

    }
    else {
        debug_msg("Do not Have $device in CODB\n");

        # No Network object for this device in CODB yet. So we create one:
        $REALSTATE->{'NIC'}->{$device}->{device} = $device;
        $REALSTATE->{'NIC'}->{$device}->{refresh} = time();

        my ($success) = $cce->create('Network', $REALSTATE->{'NIC'}->{$device});
        if (!$success) {
            debug_msg("Failed to create Network object for $device\n");
            $errors++;
        } 
        else {
            debug_msg("Created Network object for $device.\n");
        }
        # Turn on NAT and IPForwarding
        hack_on_nat($sysoid);
    }
}

# Check if CODB has 'Network' objects for network devices that do not exist. If so, delete the CODB entries for those: 
foreach my $net_oid_del (@Network_OIDs) {
    # Disable the device first:
    my ($ok) = $cce->update($net_oid_del, '', {'enabled' => '0'});

    # Fetch the info of the Network device:
    my ($ok, $del_network_info) = $cce->get($net_oid_del);

    # Destroy Network device:
    my ($success) = $cce->destroy($net_oid_del);
    if ($success) {
        &debug_msg("Destroyed surplus Network device '" . $del_network_info->{'device'} . "' with OID '$net_oid_del'\n");
    } 
    else {
        &debug_msg("Failed to destroy surplus Network device '" . $del_network_info->{'device'} . "' with OID '$net_oid_del'\n");
        $errors++;
    }
}

#
### Hostname check
#

# Get Hostname from OS:
my $hostname = hostname();
my $domainname = '';
my $System_FQDN = $System->{'hostname'} . '.' . $System->{'domainname'};

if (defined($BX_CLOUD->{'hostname'}) && $BX_CLOUD->{'hostname'} ne '' && 
    defined($BX_CLOUD->{'domainname'}) && $BX_CLOUD->{'domainname'} ne '') {
    $hostname = $BX_CLOUD->{'hostname'};
    $domainname = $BX_CLOUD->{'domainname'};
    $System_FQDN = $hostname . '.' . $domainname;
} 

if ($System_FQDN ne $hostname) {
    # update /etc/hostname
    {
      my $fn = sub {
            my ($fin, $fout) = (shift, shift);
            my $System_FQDN = shift;
            print $fout $System_FQDN,"\n";
            return 1;
        };
        Sauce::Util::editfile('/etc/hostname', $fn, $System_FQDN)
    }
  
    # run the hostname command 
    Sauce::Util::addrollbackcommand("/bin/hostname " . `/bin/hostname`);
    system('/bin/hostname', $System_FQDN);
    system("/usr/bin/nmcli g hostname $System_FQDN");
    system("/usr/bin/hostnamectl set-hostname $System_FQDN");
    system("/usr/bin/hostnamectl set-hostname $System_FQDN --static");
    system("/usr/bin/hostnamectl set-hostname $System_FQDN --transient");
    system("/usr/bin/systemctl restart systemd-hostnamed");
}

#
### Update 'System' Object:
#

# Remove keys we don't need:
delete $REALSTATE->{'CONFIG'}->{'bridge_slave'};
delete $REALSTATE->{'CONFIG'}->{'bridge_master'};
delete $REALSTATE->{'CONFIG'}->{'bootproto'};
delete $REALSTATE->{'CONFIG'}->{'primary_network_interface'};

# Convert Strings to Scalars:
if ($HAVE_DHCP == 0) {
    $REALSTATE->{'CONFIG'}->{'extra_ipaddr'} = $cce->array_to_scalar(string_to_array($REALSTATE->{'CONFIG'}->{'extra_ipaddr'}));
    $REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'} = $cce->array_to_scalar(string_to_array($REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'}));
}
else {
    # We have DHCP, so we can't have extra-IPs!
    debug_msg("DHCP is enabled (HAVE_DHCP: $HAVE_DHCP) for at least one device. Updating 'System' object accordingly.\n");
    $REALSTATE->{'CONFIG'}->{'extra_ipaddr'} = '';
    $REALSTATE->{'CONFIG'}->{'extra_ipaddr_IPv6'} = '';
}
$REALSTATE->{'CONFIG'}->{'dns'} = $cce->array_to_scalar(string_to_array($REALSTATE->{'CONFIG'}->{'dns'}));

# Define IPType and establish a safe default:
my $IPType = 'BOTH';

# Got only IPv4:
if (($REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_ip'} ne '') && ($REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv6_ip'} eq '')) {
    if (-f '/dev/incus/sock') {
        $IPType = 'VZv4';
    }
    else {
        $IPType = 'IPv4';
    }
}
# Only IPv6
elsif (($REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv4_ip'} eq '') && ($REALSTATE->{'NIC'}->{$primary_network_interface}->{'ipv6_ip'} ne '')) {
    if (-f '/dev/incus/sock') {
        $IPType = 'VZv6';
    }
    else {
        $IPType = 'IPv6';
    }
}
else {
    # Safe fallback if all else fails:
    if (-f '/dev/incus/sock') {
        $IPType = 'VZBOTH';
    }
    else {
        $IPType = 'BOTH';
    }
}

# Prepare result for publishing:
$REALSTATE->{'CONFIG'}->{'IPType'} = $IPType;

# Set flag to trigger a run of handler base/network/network_apply.pl to check for and affect Network updates:
$REALSTATE->{'CONFIG'}->{'nw_update'} = time();

# Release the lock and close the file before triggering the actual network update via 'System' 'nw_update':
close $fh or die "Cannot close lock file: $!";

# Conditionally update the 'System' object if we have changes:
debug_msg("Conditionally updating the 'System' Object.\n");
my ($ok) = $cce->update($sysoid, '', $REALSTATE->{'CONFIG'});
if (!$ok) {
    debug_msg("Updating of 'System' object failed!\n");
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

debug_msg("All done. Exiting.\n");

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub hack_on_nat {
    my ($oid) = @_;
    if ($oid ne '') {
        # Get 'System' / 'Network'
        my ($ok, $System_Network) = $cce->get($oid, 'Network');

        if (($System_Network->{nat} ne '1') || ($System_Network->{ipForwarding} ne '1')) {
            my ($ok) = $cce->update($oid, 'Network', { 'nat' => '1', 'ipForwarding' => '1' });
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
