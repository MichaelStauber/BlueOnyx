#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: findalias.pl
#
# Script to generate a list of all sites, their IP and which Email Server 
# Aliasses and Web Server Aliasses they have.

use CCE;
my $cce = new CCE;

$cce->connectuds();

# Find /home in CCE
@oids = $cce->find('Vsite', '');
if ($#oids < 0) {
    print "Could not find any sites.\n";
}
else {
    $number = $#oids;
    $number++;
    print "Found $number sites.\n\n";
    foreach $result (@oids) {
        ($ok, $site) = $cce->get($result);
    print "----------------------------------------------------------------------------\n";
    print "Sitename: \t$site->{fqdn} - $site->{ipaddr}\n\n";
    @web = $cce->scalar_to_array($site->{webAliases});
    print "Web-Alias: \n";
        $web = 0;
        foreach $line (@web) {
        $web++; 
        print "\t\t" . $line . "\n";
    }
    if ($web == "0") { 
        print "\t\t-- n/a --\n"; 
    }
    print "\n";
    @mail = $cce->scalar_to_array($site->{mailAliases});
    print "Mail-Alias: \n";
    $mail = 0;
    foreach $line (@mail) {
        $mail++;
        print "\t\t" . $line . "\n";
    }
    if ($mail == "0") { 
        print "\t\t-- n/a --\n"; 
    }
    print "\n";
    }
    print "----------------------------------------------------------------------------\n";
}

$cce->bye();
exit 0;

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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