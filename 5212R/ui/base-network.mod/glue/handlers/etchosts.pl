#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: etchosts.pl

use CCE;
use Getopt::Long;
use Net::IP qw(:PROC);
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network);
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

my $cce = new CCE;

if ($CMDLINE) {
    $cce->connectuds();
} 
else {
    $cce->connectfd();
}

# Get primary network interface:
my $REAL_PRIMARY_INTERFACE_NAME = get_primary_interface();

@RealNetOids = $cce->find('Network', { 'enabled' => '1', 'real' => '1' } );
@SysOID = $cce->find("System");

($ok, $System) = $cce->get($SysOID[0]);

$hostname = $System->{'hostname'};
$servername = $System->{'hostname'} . '.' . $System->{'domainname'};
$nw_update = $System->{nw_update};

# Set hostname via /usr/bin/hostnamectl set-hostname $servername:
# But not run it if 'nw_update' is '0', because then DBUS will
# restart the network under our ass, which might interfere with
# a GUI inititaed network change.
if ((-e '/usr/bin/hostnamectl') && ($nw_update ne '0')) {
    system('/bin/hostname', $servername);
    system("/usr/bin/hostnamectl set-hostname $servername &>/dev/null || :");
    system("/usr/bin/hostnamectl set-hostname $servername --static &>/dev/null || :");
    system("/usr/bin/hostnamectl set-hostname $servername --transient &>/dev/null || :");
    system("/usr/bin/nmcli g hostname $servername &>/dev/null || :");
    system("/usr/bin/systemctl restart systemd-hostnamed &>/dev/null || :");
}

#
### Handle IPv4:
#

# Set up an array for all IP addresses of this box;
@all_ips = ('127.0.0.1');

$output = '# /etc/hosts' . "\n";
$output .= '# Auto-generated file. Please put your customizations at the very end.' . "\n\n";
$output .= '# Entries for localhost and primary IP address:' . "\n";
$output .= '127.0.0.1' . filler('127.0.0.1') . "\t" . 'localhost' . filler('localhost') . "\t" . 'localhost.localdomain' . "\n";

foreach $oid (@RealNetOids) {
    ($ok, $obj) = $cce->get($oid);
    $MainIpaddr = $obj->{'ipaddr'};
    push (@all_ips, $MainIpaddr);
    $output .= $MainIpaddr . filler($MainIpaddr) . "\t" . $servername . filler($servername) . "\t" . $hostname . "\n";
}

$output .= "\n" . '# Entries for all Vsites on IPv4 IP addresses of this server:' . "\n";

my $ipv4_ip = get_primary_ipv4_ip();
my $uuid = get_nmcli_uuid($REAL_PRIMARY_INTERFACE_NAME);

# Get all IPv4 addresses:
my @all_ips = get_secondary_ipv4_addresses($uuid, '127.0.0.1');

# Remove prefixes from end of each IP:
my @ip_addresses = map { (split('/'))[0] } @all_ips;
@all_ips = sort(@ip_addresses);

foreach $ip (@all_ips) {
    @Vsites_on_IP = $cce->find('Vsite', { 'ipaddr' => $ip } );
    if (scalar(@Vsites_on_IP) gt "0") {
        foreach $oid (@Vsites_on_IP) {
            ($ok, $Vsite) = $cce->get($oid);
            $output .= $ip . filler($ip) . "\t" . $Vsite->{'fqdn'} . filler($Vsite->{'fqdn'}) . "\t" . $Vsite->{'hostname'} . "\n";
        }
    }
}

#
### Handle IPv6:
#

if (($System->{IPType} eq 'IPv6') || ($System->{IPType} eq 'BOTH') || ($System->{IPType} eq 'VZv6') || ($System->{IPType} eq 'VZBOTH')) {

    $ipv6_ip = get_primary_ipv6_ip();

    # Get all IPv6 addresses:
    my @all_ipv6 = get_secondary_ipv6_addresses($uuid, '::1');

    if ($System->{extra_ipaddr_IPv6}) {

        if ($ipv6_ip ne '') {
            $output .= "\n" . '# Entries for primary IPv6 IP address of this server:' . "\n";
            $output .= $ipv6_ip . filler($ipv6_ip) . "\t" . $servername . filler($servername) . "\t" . $hostname . "\n";
        }

        @extra_ipaddr_IPv6 = $cce->scalar_to_array($System->{extra_ipaddr_IPv6});
        $output .= "\n" . '# Entries for all Vsites on IPv6 IP addresses of this server:' . "\n";

        # Remove prefixes from end of each IP:
        my @ip_addresses = map { (split('/'))[0] } @all_ipv6;
        @all_ipv6 = sort(@ip_addresses);

        foreach $ip (@all_ipv6) {
            @Vsites_on_IP = $cce->find('Vsite', { 'ipaddrIPv6' => $ip } );
            if (scalar(@Vsites_on_IP) gt "0") {
                foreach $oid (@Vsites_on_IP) {
                    ($ok, $Vsite) = $cce->get($oid);
                    $output .= $ip . filler($ip) . "\t" . $Vsite->{'fqdn'} . filler($Vsite->{'fqdn'}) . "\t" . $Vsite->{'hostname'} . "\n";
                }
            }
        }
    }
}

$output .= "\n";
$output .= '# The following lines are desirable for IPv6 capable hosts' . "\n";
$output .= '::1                             localhost ip6-localhost ip6-loopback' . "\n";
$output .= 'ff02::1                         ip6-allnodes' . "\n";
$output .= 'ff02::2                         ip6-allrouters' . "\n";
$output .= "\n";
$output .= '# END of auto-generated code. Customize beneath this line.' . "\n";

# Update /etc/hostname:
$etc_hostname = '/etc/hostname';
open(CONF, ">$etc_hostname") || die "Can't write to $etc_hostname!";
print CONF "$servername\n";
close CONF;

# Read existing /etc/hosts to see if we need to keep modifications that the user added:
$user_additions = '';
$etc_hosts = '/etc/hosts';
if (-f $etc_hosts) {
    if (open(my $fh, '<:encoding(UTF-8)', $etc_hosts)) {
        $found_anchor = '0';
        while (my $row = <$fh>) {
            chomp $row;
            if ($row =~ /^# END of auto-generated code. Customize beneath this line./) {
                $found_anchor = "1";
                next;
            }
            if ($found_anchor eq "1") {
                $user_additions .= $row . "\n";
            }
        }
    }
}

# Combine our output with user_additions:
$output .= $user_additions;

# Update /etc/hosts
open(CONF, ">$etc_hosts") || die "Can't write to $etc_hosts!";
print CONF "$output";
close CONF;

$cce->bye('SUCCESS');
exit(0);

sub filler {
    my ($data) = @_;
    $ln = length($data);
    $maxIPlen = '24';
    $fill = $maxIPlen - $ln;
    $spacer = '';
    while ($fill > '0') {
        $spacer .= " ";
        $fill--;
    }
    return $spacer;
}

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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