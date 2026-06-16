#!/usr/bin/perl -w -I/usr/sausalito/perl
# $Id: set_servername.pl
#
# Script to set server FQDN in CCE.
#
# Example Syntax:
# ./set_servername.pl configure 5211r.blueonyx.it 

use CCE;
use String::ShortHostname;
my $cce = new CCE;

$cce->connectuds();

my $SERVERNAME = '';
my $fqdn = '';
my $short = '';
my $hostname = '';
my $serv_name = '';

if (defined($ARGV[1])) {
    if ($ARGV[0] eq "configure") {
       $SERVERNAME = $ARGV[1];
    }
}
if (!$SERVERNAME) {
    print "Syntax: /usr/sausalito/sbin/set_servername.pl configure <Fully Qualified Server Name>\n";
    $cce->bye('FAIL');
    exit 1;
}
else {

    $fqdn = $SERVERNAME;
    $hostname = short_hostname( $fqdn );
    $serv_name = $SERVERNAME;
    $serv_name =~ s/^$hostname\.//;

    # Ensure that host and domain are lower case:
    $hostname = lc($hostname);
    $serv_name = lc($serv_name);

    (my $sys_oid) = $cce->find('System', '');
    (my $ok) = $cce->set($sys_oid, '',{
        "hostname" => $hostname,
        "domainname" => $serv_name
    });

    if (-e '/usr/bin/hostnamectl') {
        system('/bin/hostname', $SERVERNAME);
        system("/usr/bin/hostnamectl set-hostname $SERVERNAME");
        system("/usr/bin/hostnamectl set-hostname $SERVERNAME --static");
        system("/usr/bin/hostnamectl set-hostname $SERVERNAME --transient &>/dev/null || :");
        system("/usr/bin/nmcli general hostname $SERVERNAME");
        system("/usr/bin/systemctl restart systemd-hostnamed");
    }
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2022-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2022-2024 Team BlueOnyx, BLUEONYX.IT
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