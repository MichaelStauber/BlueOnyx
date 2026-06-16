#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: dnsMXmassChange.pl

use CCE;
my $cce = new CCE;

$switch_o = $ARGV[0];
$oldmx = $ARGV[1];
$switch_n = $ARGV[2];
$newmx = $ARGV[3];

print "\n";
print "dnsMXmassChange.pl\n";
print "==================\n\n";
print "This script can change the MX host of all DNS records from one host to another.\n\n";

if (!$switch_o || !$switch_n || !$oldmx || !$newmx) {
    print "To use it, run it with the followinng parameters:\n\n";
    print $0 . " -o old-MX-host -n new-MX-host\n\n";
    print "Example: \n\n";
    print $0 . " -o www.blueonyx.it -n mail.blueonyx.it\n\n";
    print "You can also change each and any MX record to the same one (caution!) using this syntax:\n\n";
    print $0 . " -o ALL -n mail.blueonyx.it\n\n";
    exit 1;
}
elsif (($switch_o == "-o") && ($oldmx == "ALL") && ($switch_n == "-n") && $newmx) {
    $changeall = 1;
    print "Changing each and any MX records to $newmx ... \n\n";
    $cce->connectuds();
    &feedthemonster;
    &setdirty;
    $cce->bye('SUCCESS');
    exit(0);
}
elsif (($switch_o == "-o") && $oldmx && ($switch_n == "-n") && $newmx) {
    $changeall = 0;
    print "Changing all MX records currently using $oldmx to $newmx ... \n\n";
    $cce->connectuds();
    &feedthemonster;
    &setdirty;
    $cce->bye('SUCCESS');
    exit(0);
}
else {
    print "Aborting without doing anything ...\n\n";
    exit 1;
}

sub feedthemonster {
    if ( $changeall == '0') {
        (@oids) = $cce->find('DnsRecord', { 'mail_server_name' => $oldmx, 'type' => "MX" });
        for $object (@oids) {
            ($ok, $rec) = $cce->get($object);
            if ($rec->{'mail_server_name'} ne "") {
                print "Changing MX for " . $rec->{'mail_server_name'} . " to " . $newmx . "\n";
            }
            ($ok) = $cce->set($object, '', { 'mail_server_name' => $newmx });
        }
    }
    else {
        (@oids) = $cce->find('DnsRecord', { 'type' => "MX" });
        for $object (@oids) {
            ($ok, $rec) = $cce->get($object);
            if ($rec->{'mail_server_name'} ne "") {
                print "Changing MX for " . $rec->{'mail_server_name'} . " to " . $newmx . "\n";
            }
            ($ok) = $cce->set($object, '',{ 'mail_server_name' => $newmx });
        }
    }
}

sub setdirty {
    # Get 'System' details:
    @system_main = $cce->find('System');
    if (!defined($system_main[0])) {
        print "Sorry, no 'System' object found in CCE!\n";
        exit(1);
    }
    else {
        # Build Records:
        #($ok, $my_system_main) = $cce->get($system_main[0]);
        ($ok) = $cce->set($system_main[0], 'DNS', { 'dirty' => time() });
    }
}

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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