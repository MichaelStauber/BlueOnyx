#!/usr/bin/perl -I. -I/usr/sausalito/perl
# $Id: syncAPIexceptions.pl

use CCE;
use JSON;

# Config file location:
$cfg_file = '/usr/sausalito/configs/api/ips.json';
$cfg_file_dir = '/usr/sausalito/configs/api';

my $cce = new CCE;
$cce->connectuds();

my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $API) = $cce->get($oids[0], 'API');
unless ($ok and $API) {
    $cce->bye('FAIL');
    exit 1;
}

my @apiHosts = ();
if ($API->{'enabled'} eq '1') {
    if ($API->{'apiHosts'}) {
        @apiHosts = $cce->scalar_to_array($API->{'apiHosts'});
    }
}

# Encode and write config file:
my $json = encode_json(\@apiHosts);
if (! -d $cfg_file_dir) {
    system("mkdir $cfg_file_dir");
    system("chown root:root $cfg_file_dir");
    system("chmod 0755 $cfg_file_dir");    
}
open(my $fh, '>', $cfg_file);
print $fh $json . "\n";
close $fh;
system("chown admserv:admserv $cfg_file");
system("chmod 0644 $cfg_file");

# Done:
$cce->bye('SUCCESS');
exit 0;

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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