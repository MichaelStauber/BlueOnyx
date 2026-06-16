#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/power
# $Id: wakemode.pl

use CCE;
use Sauce::Util;
use I18n;

use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network /usr/sausalito/handlers/base/power);
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

my ($return1, $return2);
my $flag = 0;
my $port;
my $kernel_wakeutil = '/usr/sbin/ethtool';
my $eeprom_wakeutil = '/usr/sbin/enet_eeprom';
my $cce = new CCE("Namespace" => "Power");
$cce->connectfd();

my $old = $cce->event_old();
my $object = $cce->event_object();
my $new = $cce->event_new();

# Get primary network interface:
my $REAL_PRIMARY_INTERFACE_NAME = get_primary_interface();

if (-e $kernel_wakeutil && -e $eeprom_wakeutil ) {
    # find out what IO port the ethernet controller uses.
    open PCI, '</proc/pci';
    while (<PCI>) {
        if (/Ethernet/) {
        $flag = 1;
        } elsif ($flag && /I\/O/) {
        /at (\w+)/;
        $port = $1;
        last;
        }
    }
    close(PCI);

    my @eeprom_args = ($eeprom_wakeutil, '-p', $port, '-d', 'natsemi', '-w', 'wol');
    my @kernel_args = ($kernel_wakeutil, '-s', $REAL_PRIMARY_INTERFACE_NAME, 'wol');

    if ($new->{wakemode} || $new->{set_modes_now}) {
        if ($object->{wakemode} eq 'none') {
        push @kernel_args, ('d');
        push @eeprom_args, ('d');
        } elsif ($object->{wakemode} eq 'magic') {
        push @kernel_args, ('g');
        push @eeprom_args, ('g');
        }
    
        $return1 = system(@kernel_args);
        $return2 = system(@eeprom_args);

        if (($return1 != 0) || ($return2 != 0)) {
        $cce->warn("[[base-power.errSettingWakeMode]]");
        $cce->bye('FAIL');
        exit 1;
        }
    }
}

$cce->bye('SUCCESS');
exit 0;

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