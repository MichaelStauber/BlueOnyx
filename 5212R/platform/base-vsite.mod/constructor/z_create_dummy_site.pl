#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: z_create_dummy_site.pl
#
# This is a very dirty work around for fixing Vsite creation issue:
# We need to create a site this way first, otherwise future site 
# creation through the GUI will fail. So we create this dummy site,
# and delete it right afterwards. 
#
# This constructor only runs if the setup wizard hasn't been run yet
# and if there isn't already any site on the box.

use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network);
use CCE;
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

# Disable this constructor for now and directly exit:
#$cce->bye('SUCCESS');
#exit(0);

# Get System object info:
($sys_oid) = $cce->find('System');
($ok, $system) = $cce->get($sys_oid);

# Get primary IPs:
my $primary_ipv4 = get_primary_ipv4_ip();
my $primary_ipv6 = get_primary_ipv6_ip();


# Make sure there is no Vsite yet, that the license isn't accepted yet and that we know the primary IP:
if ((not scalar($cce->find('Vsite'))) && ($network->{'ipaddr'} ne "") && ($system->{'isLicenseAccepted'} == "0") && (! -f "/tmp/.dummyvsite")) {

    # Create the dummy Vsite:
    ($ok) = $cce->create('Vsite', {
            'webAliases' => '',
            'site_preview' => '0',
            'mailAliases' => '',
            'domain' => 'test-blueonyx.it',
            'ipaddr' => $primary_ipv4,
            'ipaddrIPv6' => $primary_ipv6m,
            'maxusers' => '25',
            'prefix' => '',
            'emailDisabled' => '0',
            'volume' => '/home',
            'dns_auto' => '0',
            'fqdn' => 'dummy.test-blueonyx.it',
            'mailCatchAll' => '',
            'webAliasRedirects' => '1',
            'hostname' => 'dummy',
            'name' => 'site1',
            'basedir' => '/home/.sites/28/site1'
    });

    # Delete the dummy Vsite again:
    ($sys_oid) = $cce->find('Vsite', {'name' => 'site1', 'fqdn' => 'dummy.test-blueonyx.it'});
    if ($sys_oid) {
        ($ok, $sys) = $cce->get($sys_oid);
        ($ok) = $cce->destroy($sys_oid);
        system("touch /tmp/.dummyvsite");
    }
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#         notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#         notice, this list of conditions and the following disclaimer in 
#         the documentation and/or other materials provided with the 
#         distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#         contributors may be used to endorse or promote products derived 
#         from this software without specific prior written permission.
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