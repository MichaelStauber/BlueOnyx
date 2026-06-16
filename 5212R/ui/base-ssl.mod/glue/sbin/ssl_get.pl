#!/usr/bin/perl
# output the csr or full key and cert to stdout for the specified group
# or the admin server by default

use strict;
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/ssl);
use CCE;
use SSL;
use Base::HomeDir qw(homedir_get_group_dir);

my $DEBUG = 0;
if ($DEBUG) { use Data::Dumper; }

$DEBUG && print STDERR join(' : ', @ARGV), "\n";

# set a sane umask
umask(022);

# files to output
my @files = ();

if ($ARGV[0] eq 'cert') {
    push @files, 'key', 'certificate';
}
elsif ($ARGV[0] eq 'csr') {
    push @files, 'request';
}
else {
    exit(4);
}

# default to reading the admin server cert info
my $cert_dir = '/etc/admserv/certs';

# only bother finding the vsite if a group was passed as the second argument
if ($ARGV[1] ne '') {
    my $cce = new CCE;
    $cce->connectuds();
  
    $cce->authkey($ENV{CCE_USERNAME}, $ENV{CCE_SESSIONID});

    my ($oid, $ok);
    if ($ARGV[1]) {
        ($oid) = $cce->find('Vsite', { 'name' => $ARGV[1] });
    }

    if ($ARGV[1]) {
        ($ok, my $vsite) = $cce->get($oid);
        if (not $ok) {
            $cce->bye();
            exit(1);
        }

        if ($vsite->{basedir}) {
            $cert_dir = "$vsite->{basedir}/wwwroot/$SSL::CERT_DIR";
        }
        else {
            $cert_dir = homedir_get_group_dir($ARGV[1], $vsite->{volume}) . '/' . $SSL::CERT_DIR;
        }
    }
    else {
        # no group, do System
        $cert_dir = '/etc/admserv/certs';
    }

    $cce->bye();
} # end if ($ARGV[1])

for my $file (@files) {
    if (!open(FILE, "$cert_dir/$file")) {
        exit(2);
    }
    my @lines = <FILE>;
    close FILE;

    print @lines;
    print "\n"; # make sure lines get seperated correctly
}

exit(0);

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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