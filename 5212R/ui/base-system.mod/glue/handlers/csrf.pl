#!/usr/bin/perl -I/usr/sausalito/perl -I.
# $Id: csrf.pl
# Updates CSRF settings of CodeIgniter 4
#

use Sauce::Util;
use CCE;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd();

# get system and network object ids:
my ($System_oid) = $cce->find("System");

# get system object:
my ($ok, $System) = $cce->get($System_oid);
if (!$ok) { 
    $cce->bye('FAIL');
    exit(1);
}

# CodeIgniter config file location:
my $CSRF_CFG = '/usr/sausalito/ui/chorizo/ci4/.env';

# CI4 Config files that turn CSRF on/off
my $CSRF_actual = '/usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php';
my $CSRF_on = '/usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php.csrf';
my $CSRF_off = '/usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php.nocsrf';

$csrf_protection = $System->{'csrf_protection'};
$csrf_expire = $System->{'csrf_expire'};
$csrf_regenerate = $System->{'csrf_regenerate'};

# Lining up the ducks:
$csrf_protection = '1';
if ($System->{'csrf_protection'} eq '0') {
    $csrf_protection = '0';
}

$csrf_expire = '7200';
if (($System->{'csrf_expire'} > '300') && ($System->{'csrf_expire'} < '10801')) {
    $csrf_expire = $System->{'csrf_expire'};
}

$csrf_regenerate = 'true';
if ($System->{'csrf_regenerate'} eq '0') {
    $csrf_regenerate = 'false';
}

# Set CSRF to off if web based initial setup hasn't been completed yet:
if ($System->{'isLicenseAccepted'} eq '0') {
    $csrf_protection = '0';
    $csrf_expire = '7200';
    $csrf_regenerate = 'true';
}

# Build output hash:
$new_csrf = {
        'csrf_expire' => $csrf_expire,
        'csrf_regenerate' => $csrf_regenerate,
        };

Sauce::Util::editfile($CSRF_CFG, *edit_config, $new_csrf);

system("rm -f /usr/sausalito/ui/chorizo/ci4/.env.backup.*");

if ($csrf_protection == '1') {
    system("cp $CSRF_on $CSRF_actual");
}
else {
    system("cp $CSRF_off $CSRF_actual");
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub edit_config {
    my ($in, $out, $new_csrf) = @_;

    &debug_msg("Parsing $CSRF_CFG");

    while (<$in>) {
        # Handle 'csrf_expire':
        if (/^security\.expires(\s+){0,}=.*$/) {
            &debug_msg("Replacing: $_");
            $nl_two = 'security.expires = ' . $new_csrf->{'csrf_expire'} . "\n";
            print $out $nl_two;
            &debug_msg("Replaced with: $nl_two");
            next;
        }

        # Handle 'csrf_regenerate':
        if (/^security\.regenerate(\s+){0,}=.*$/) {
            &debug_msg("Replacing: $_");
            $nl_three = 'security.regenerate = ' . $new_csrf->{'csrf_regenerate'} . "\n"; 
            print $out $nl_three;
            &debug_msg("Replaced with: $nl_three");
            next;
        }

        #&debug_msg("Writing out: $_");
        print $out $_;
    }
    return 1;
}

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
