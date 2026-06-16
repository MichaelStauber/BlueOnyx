#!/usr/bin/perl -I/usr/sausalito/perl

use CCE;
my $cce = new CCE;

$cce->connectuds();

# Debugging switch:
$DEBUG = "1";
if ($DEBUG) {
  use Sys::Syslog qw( :DEFAULT setlogsock);
}

#############################################################
#
#  Variables and options setup
#
#############################################################
my $device = "";
my $IPADDRESS = "";
my $NETMASK = "";
my $DEFAULTGW = "";
my $IPV6ADDRESS = "";
my $IPV6DEFAULTGW = "";
my $DNS = "";
my $HELP = 0;

use Getopt::Long;
GetOptions( 
    "device=s" => \$device,
    "ipv4ip=s" => \$IPADDRESS,
    "ipv4nm=s" => \$NETMASK,
    "ipv4gw=s" => \$DEFAULTGW,
    "ipv6=s" => \$IPV6ADDRESS,
    "ipv6gw=s" => \$IPV6DEFAULTGW,
    "dns=s" => \$DNS,
    "help"    => \$HELP);

$good_ipv4 = '0';
$good_ipv6 = '0';
$dual_stack = '0';
$nada = '0';

if (($IPADDRESS ne '') && ($NETMASK ne '') && ($DEFAULTGW ne '')) {
    $good_ipv4 = '1';
    $nada = '1';
}

if (($IPV6ADDRESS ne '') && ($IPV6DEFAULTGW ne '')) {
    $good_ipv6 = '1';
    $nada = '1';
}

if (($good_ipv4 eq '1') && ($good_ipv6 eq '1')) {
    $dual_stack = '1';
}

if ($DEBUG gt '1') {
    print "DV:  $device \n";
    print "IP:  $IPADDRESS \n";
    print "NM:  $NETMASK \n";
    print "GW:  $DEFAULTGW \n";
    print "DNS: $DNS \n";
    print "IP6: $IPV6ADDRESS \n";
    print "GW6: $IPV6DEFAULTGW \n";
    print "v4:  $good_ipv4 \n";
    print "v6:  $good_ipv6 \n";
}

if ($HELP || ($nada eq '0') || ($device eq '')) {
    print STDERR <<EOT ;
You can specify these options:
  --device=                  Network Device
  --ipv4ip=                  IP-Address (IPv4)
  --ipv4nm=                  Netmask    (IPv4)
  --ipv4gw=                  Gateway    (IPv4)
  --dns=                     DNS Server IP
  --ipv6=                    IP-Address (IPv6)
  --ipv6gw=                  Gateway    (IPv6)
  --help                     This text.
EOT
    $cce->bye('SUCCESS');
    exit(1);
}
else {

    if ($device eq 'br0') {
        # Run the command pipeline and capture the output to determine slave device of bridge:
        my $slave_interface = `bridge link show | grep -B 1 'master $device' | head -n 1 | awk '{print \$2}' | sed 's/:.*//'`;
        chomp($slave_interface);
        if ($slave_interface) {
            # We have an answer. We use it:
            $device = $slave_interface;
        }
        else {
              # We got nothing, then we fetch the first interface of 'ifconfig' that isn't a bridge:
              $device = `ifconfig | grep -E '^[a-zA-Z0-9]+' | grep -v '^br' | awk '{print \$1}' | sed 's/://g' | head -1`;
              chomp($device);
        }
    }

    &debug_msg("Nethelper processing Network device: $device\n");

    # Update network device:
    (@oids) = $cce->find('Network', { 'device' => $device });
    if ($#oids < 0) {
        # no Network object for this device, so create one
        ($ok) = $cce->create("Network", {
            "device" => $device,
            "bootproto" => "none",
            "ipaddr" => $IPADDRESS,
            "netmask" => $NETMASK,
            "ipaddr_IPv6" => $IPV6ADDRESS,
            "enabled" => "1",
            "real" => "1",
            "refresh" => time()
        });
    }
    else {
        ($ok) = $cce->set($oids[0], '', {
            "device" => $device,
            "bootproto" => "none",
            "ipaddr" => $IPADDRESS,
            "netmask" => $NETMASK,
            "ipaddr_IPv6" => $IPV6ADDRESS,
            "enabled" => "1",
            "real" => "1",
            "refresh" => time()
        });
    }

    # Update 'System' Object:
    ($sys_oid) = $cce->find('System');
    if ($DNS eq '') {
        ($ok) = $cce->set($sys_oid, '', { 'gateway' => $DEFAULTGW, 'gateway_IPv6' => $IPV6DEFAULTGW, 'nw_update' => time() });
    }
    else {
        ($ok) = $cce->set($sys_oid, '', { 'gateway' => $DEFAULTGW, 'gateway_IPv6' => $IPV6DEFAULTGW, 'dns' => "&$DNS&", 'nw_update' => time() });
    }

    # Update 'System' . 'firewall' if present;
    # Try to get 'firewall' namespace:
    ($ok, $firewall) = $cce->get($sys_oid, 'firewall');
    if (defined $firewall->{'possible'}) {
        ($ok) = $cce->set($sys_oid, 'firewall', {
            'IFACE_IN' => $device,
            'IFACE_OUT' => $device,
        });
    }
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
    syslog('info', "$ARGV[0]: $msg");
    closelog;
  }
}

# 
# Copyright (c) 2017-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2017-2024 Team BlueOnyx, BLUEONYX.IT
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

