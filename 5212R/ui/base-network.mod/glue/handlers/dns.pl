#!/usr/bin/perl -w -I/usr/sausalito/perl
# $Id: dns.pl
#
# dns.pl
# This handler make sure that the Qube has at least one DNS server
# specified if it is connected to the internet in any way.  This
# is mostly for the case when the network is auto-configured at
# first boot.  This helps users who may not be aware of the need
# for a DNS server to use the Qube as a router.

use CCE;

my $cce = new CCE;

$cce->connectfd();

my $system = $cce->event_object();
my ($ok, $sysnet) = $cce->get($cce->event_oid(), 'Network');

if (not $ok) 
{
    $cce->warn('[[base-network.cantGetNetwork]]');
    $cce->bye('FAIL');
    exit(1);
}

# if the internetMode is anything other than none, make sure
# there is a DNS server specified.  If there isn't, make the
# Qube its own DNS server.
if ($sysnet->{internetMode} ne 'none') 
{
    # check to make sure we have at least one dns server specified
    my @dns_servers = $cce->scalar_to_array($system->{dns});

    if (scalar(@dns_servers) > 0) 
    {
        # already have dns servers specified
        $cce->bye('SUCCESS');
        exit(0);
    }

    # other wise, there is currently no DNS server specified
    # insert local host as dns server
    ($ok) = $cce->set($cce->event_oid(), '', { 'dns' => '&127.0.0.1&' });
    
    if (not $ok) 
    {
        $cce->warn('[[base-network.couldntChangeDns]]');
        $cce->bye('FAIL');
        exit(1);
    }

    # start the DNS server
    ($ok) = $cce->set($cce->event_oid(), 'DNS', { 'enabled' => 1 });

    if (not $ok) 
    {
        $cce->warn('[[base-network.couldntStartDns]]');
        $cce->bye('FAIL');
        exit(1);
    }
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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