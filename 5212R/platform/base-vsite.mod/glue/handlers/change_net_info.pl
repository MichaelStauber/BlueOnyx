#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/vsite
# $Id: change_net_info.pl
# do things like making sure casp, httpd.conf, aliases, maillists, email info,
# etc. all get updated properly if the fqdn or ip address change for a vsite

use CCE;
use Vsite;
use Sauce::Util;
use Sauce::Config;
use Base::HomeDir qw(homedir_get_group_dir homedir_create_group_link);
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

# Debugging switch:
$DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd();

&debug_msg("change_net_info.pl starting up.\n");

# gather some useful information
my $vsite = $cce->event_object();
my $vsite_new = $cce->event_new();
my $vsite_old = $cce->event_old();

my $msg;

# stuff to do if either the ip or fqdn has changed
if ($vsite_new->{ipaddr} || $vsite_new->{ipaddrIPv6} || $vsite_new->{fqdn} || $vsite_new->{webAliases}) {
    # modify VirtualHost entry for this site
    my ($vhost) = $cce->find('VirtualHost', { 'name' => $vsite->{name} });

    &debug_msg("Updating VirtualHost object.\n");

    my ($ok) = $cce->set($vhost, '', { 'ipaddr' => $vsite->{ipaddr}, 'ipaddrIPv6' => $vsite->{ipaddrIPv6}, 'fqdn' => $vsite->{fqdn}, 'webAliases' => $vsite->{webAliases} });

    if (not $ok) {
        &debug_msg("FAILED: Updating VirtualHost object.\n");
        $cce->bye('FAIL', '[[base-vsite.cantUpdateVhost]]');
        exit(1);
    }
}

if ($vsite_new->{fqdn}) {
    # set umask or symlinks get created with funky permissions
    my $old_umask = umask(000);
    
    # update symlink in the filesystem
    my ($old_link, $old_target) = homedir_create_group_link($vsite->{name}, $vsite_old->{fqdn}, $vsite->{volume});
    my ($new_link, $link_target) = homedir_create_group_link($vsite->{name}, $vsite->{fqdn}, $vsite->{volume});

    unlink($old_link);
    Sauce::Util::addrollbackcommand("umask 000; /bin/ln -sf \"$old_target\" \"$old_link\"");
    Sauce::Util::linkfile(
            $link_target, 
            $new_link);

    # restore umask
    umask($old_umask);
} # end of fqdn change specific

&debug_msg("INFO: \$vsite_new->{ipaddr}: " . $vsite_new->{ipaddr} . " - \$vsite_old->{ipaddr}: " . $vsite_old->{ipaddr} . "\n");

# Handle IPv4 address change
if ($vsite_new->{ipaddr}) {
    # Add used IPs ro network interfaces:
    &debug_msg("INFO: Need to add new IPv4: " . $vsite_new->{ipaddr} . "\n");
    vsite_add_network_interface($cce, $vsite_new->{ipaddr});
    # Remove unused IPs from being bound to network interfaces:
    if ($vsite_old->{ipaddr}) {
        &debug_msg("INFO: Need to remove old IPv4: " . $vsite_old->{ipaddr} . "\n");
        vsite_del_network_interface($cce, $vsite_old->{ipaddr});
    }
}
if ((!$vsite_new->{ipaddr}) && ($vsite_old->{ipaddr})) {
    vsite_del_network_interface($cce, $vsite_old->{ipaddr});
}

# Handle IPv6 address change
if ($vsite_new->{ipaddrIPv6}) {
    # Add used IPs ro network interfaces:
    &debug_msg("INFO: Need to add new IPv6: " . $vsite_new->{ipaddrIPv6} . "\n");
    vsite_add_network_interface($cce, $vsite_new->{ipaddrIPv6});
    # Remove unused IPs from being bound to network interfaces:
    if ($vsite_old->{ipaddrIPv6}) {
        &debug_msg("INFO: Need to remove old IPv6: " . $vsite_old->{ipaddrIPv6} . "\n");
        vsite_del_network_interface($cce, $vsite_old->{ipaddrIPv6});
    }
}
if ((!$vsite_new->{ipaddrIPv6}) && ($vsite_old->{ipaddrIPv6})) {
    vsite_del_network_interface($cce, $vsite_old->{ipaddrIPv6});
}

# end of ip address change specific

&debug_msg("vsite_new->{ipaddr} " . $vsite_new->{ipaddr} . "\n");
&debug_msg("vsite_old->{ipaddr} " . $vsite_old->{ipaddr} . "\n");
&debug_msg("vsite->{ipaddr} " . $vsite->{ipaddr} . "\n");
&debug_msg("vsite_new->{ipaddrIPv6} " . $vsite_new->{ipaddrIPv6} . "\n");
&debug_msg("vsite_old->{ipaddrIPv6} " . $vsite_old->{ipaddrIPv6} . "\n");
&debug_msg("vsite->{ipaddrIPv6} " . $vsite->{ipaddrIPv6} . "\n");

# Out with the changes:
my ($sysoid) = $cce->find('System');
&debug_msg("Setting 'System' - 'nw_update' \n");
($ok) = $cce->set($sysoid, '', { 'nw_update' => time() });

# Update primary network interface configs:
my $primary_network_interface = get_primary_interface();
my ($net_oid) = $cce->find('Network', { 'device' => $primary_network_interface, 'real' => 1 });
if ($net_oid) {
    $cce->set($net_oid, '', { 'refresh' => time() });
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$msg");
        closelog;
    }
}

sub in_array {
    my ($arr,$search_for) = @_;
    my %items = map {$_ => 1} @$arr; # create a hash out of the array values
    return (exists($items{$search_for}))?1:0;
}

sub uniq {
    my %seen;
    grep !$seen{$_}++, @_;
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