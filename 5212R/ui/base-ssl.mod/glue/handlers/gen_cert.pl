#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/ssl
# $Id: gen_cert.pl 
# Use the SSL information for the vsite to generate a private key, self-signed
# certificate and a certificate signing request.

# Debugging switch (0|1):
# 0 = off
# 1 = log to syslog
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
}

use CCE;
use SSL qw(
            ssl_set_identity ssl_get_cert_info 
            ssl_error ssl_create_directory
            ssl_check_days_valid
            );
use Base::HomeDir qw(homedir_get_group_dir);
use Sauce::Util;

&debug_msg("Starting up\n");
my $cce = new CCE('Namespace' => 'SSL', 'Domain' => 'base-ssl');
$cce->connectfd();

# get ssl information and vsite information
my $ssl_info = $cce->event_object();
my ($ok, $vsite) = $cce->get($cce->event_oid());

# The certificate information for Apache will be kept
# in the sites home diretory in the sub-directory specified
# in $SSL::CERT_DIR.  There may be a need to store a modified
# pem format certificate in a common certs directory, if possible, 
# to allow ssl for email servers.
my $cert_dir = ($vsite->{basedir} ? "$vsite->{basedir}/wwwroot/$SSL::CERT_DIR" : homedir_get_group_dir($vsite->{name}, $vsite->{volume}) . "/wwwroot/$SSL::CERT_DIR");

&debug_msg(Dumper($vsite) . "\n");
&debug_msg("Cert directory is $cert_dir\n");

my $gid = getgrnam($vsite->{name});

# make sure the umask is ok
umask(022);

# create cert dir if it doesn't exist
if (! -d $cert_dir) {
    &debug_msg("Cert directory $cert_dir does not exist. Creating it with mask 02770 and gid $gid.\n");
    if (!ssl_create_directory(02770, $gid, $cert_dir)) {
        &debug_msg("Creation of cert directory $cert_dir failed. Exiting.\n");
        $cce->bye('FAIL', 'cantMakeDirectory', { 'dir' => $cert_dir });
        exit(1);
    }
    else {
        &debug_msg("Creation of cert directory $cert_dir suceeded.\n");
    }
}
else {
    &debug_msg("Cert directory $cert_dir does exist.\n");
}

# make sure we don't hit 2038 rollover
$ssl_info->{daysValid} = ssl_check_days_valid($ssl_info->{daysValid}); 
if (!ssl_check_days_valid($ssl_info->{daysValid})) { 
    $cce->bye('FAIL', '[[base-ssl.2038bug]]'); 
    exit(1); 
} 

# call ssl_set_identity which generates a self-signed certificate
my $ret = ssl_set_identity(
            $ssl_info->{daysValid},
            $ssl_info->{country},
            $ssl_info->{state},
            $ssl_info->{city},
            $ssl_info->{orgName},
            $ssl_info->{orgUnit},
            substr($vsite->{fqdn}, 0, 64),
            $ssl_info->{email},
            $cert_dir
          );

&debug_msg("ssl_set_identity returned $ret\n");

# check the return value and return an appropriate error message
# as necessary
if ($ret != 1) {
    $cce->bye('FAIL', ssl_error($ret));
    exit(1);
}

# Handle creation of 'nginx_cert_ca_combined':
$combined_cert = "$cert_dir/nginx_cert_ca_combined";
$the_ca_cert = "$cert_dir/ca-certs";
$the_key = "$cert_dir/key";
$the_cert = "$cert_dir/certificate";
$the_blank = "$cert_dir/blank.txt";

if (! -f $the_blank) {
    &debug_msg("Missing file: $the_blank\n");
    system("echo \"\" > $the_blank");
    system("chmod 640 $the_blank");
}

if ((-f $the_ca_cert) && (-f $the_key) && (-f $the_cert)) {
    &debug_msg("Got files: $the_ca_cert, $the_key and $the_cert - creating $combined_cert\n");
    system("cat $the_cert $the_blank $the_ca_cert > $combined_cert");
    system("chmod 640 $combined_cert");
}
elsif ((! -f $the_ca_cert) && (-f $the_key) && (-f $the_cert)) {
    &debug_msg("Got file: $the_key $the_cert and am missing $the_ca_cert - creating $combined_cert\n");
    # We have no intermediate.
    system("cat $the_cert > $combined_cert");
    system("chmod 640 $combined_cert");
}
if (! -f $combined_cert) {
    &debug_msg("Missing file: $combined_cert - creating it empty.\n");
    # If we still have noting, we go bare:
    system("touch $combined_cert");
    system("chmod 640 $combined_cert");
}

# chown the files, so that site admins can export and import
Sauce::Util::chownfile(0, $gid, "$cert_dir/certificate");
Sauce::Util::chownfile(0, $gid, "$cert_dir/key");
Sauce::Util::chownfile(0, $gid, "$cert_dir/request");

# read the expiration date from the new certificate
my ($sub, $iss, $date) = ssl_get_cert_info($cert_dir);

# munge date because they changed the strtotime function in php
$date =~ s/(\d{1,2}:\d{2}:\d{2})(\s+)(\d{4,})/$3$2$1/;

($ok) = $cce->set($cce->event_oid(), 'SSL', { 'expires' => $date });

# failing to set expires is non-fatal
if (not $ok) {
    $cce->warn('[[base-ssl.cantSetExpires]]');
}

if (length($vsite->{fqdn}) > 64) {
    $cce->baddata(0, 'fqdn', 'fqdnTooLongOkay', { 'fqdn' => $vsite->{fqdn} });
}

$cce->bye('SUCCESS');
exit(0);

#
### Subroutines:
#

sub debug_msg {
    if ($DEBUG eq "1") {
        $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

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
