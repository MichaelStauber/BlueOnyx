#!/usr/bin/perl -w -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/ssl
# $Id: gen_csr.pl
#
# generate a certificate signing request on demand from the current info
# in the SSL namespace

use CCE;
use SSL qw(ssl_gen_csr ssl_create_directory ssl_check_days_valid);
use Base::HomeDir qw(homedir_get_group_dir);

my $DEBUG = 0;
if ($DEBUG) { use Data::Dumper; }

my $cce = new CCE('Domain' => 'base-ssl');
$cce->connectfd();

# set a sane umask
umask(022);

my $site = $cce->event_object();
my ($ok, $ssl) = $cce->get($cce->event_oid(), 'SSL');
if (not $ok) {
    $cce->bye('FAIL', '[[base-ssl.cantReadSSLNS]]');
    exit(1);
}

my ($cert_dir, $fqdn);
if (exists($site->{basedir})) {
    # it's a vsite
    $fqdn = $site->{fqdn};
    if ($site->{basedir}) {
        $cert_dir = "$site->{basedir}/wwwroot/$SSL::CERT_DIR";
    }
    else {
        $cert_dir = homedir_get_group_dir($site->{name}, $site->{volume}) . '/wwwroot/' . $SSL::CERT_DIR;
    }

    # make sure the directory exists
    if (!ssl_create_directory(02770, scalar(getgrnam($site->{name})), $cert_dir)) {
        $cce->bye('FAIL', 'cantMakeDirectory', { 'dir' => $cert_dir });
        exit(1);
    }
}
else {
    # must be System
    $fqdn = $site->{hostname} . '.' . $site->{domainname};
    $cert_dir = '/etc/admserv/certs';

    # make sure cert dir exists
    if (!ssl_create_directory(0700, 0, $cert_dir)) {
        $cce->bye('FAIL', 'cantMakeDirectory', { 'dir' => $cert_dir });
        exit(1);
    }
}

# for a csr if fqdn is not 64 or less fail, since the csr is pointless
# because no CA will sign it
if (length($fqdn) > 64) {
    $cce->bye('FAIL', 'fqdnTooLongForCsr', { 'fqdn' => $fqdn });
    exit(1);
}

# need to generate a signing request
my $subject = {
                'C' => $ssl->{country},
                'ST' => $ssl->{state},
                'L' => $ssl->{city},
                'O' => $ssl->{orgName},
                'OU' => $ssl->{orgUnit},
                'CN' => $fqdn,
                'Email' => $ssl->{email}
                };

$DEBUG && print STDERR Dumper($ssl, $subject);

# check for 2038 rollover
$ssl->{daysValid} = ssl_check_days_valid($ssl->{daysValid});
if (!ssl_check_days_valid($ssl->{daysValid})) { 
    $cce->bye('FAIL', '[[base-ssl.2038bug]]'); 
    exit(1); 
} 

if (!ssl_gen_csr($cert_dir, $ssl->{daysValid}, $subject)) {
    $cce->bye('FAIL', '[[base-ssl.cantGenerateCsr]]');
    exit(1);
}

$cce->bye('SUCCESS');
exit(0);

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