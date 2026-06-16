#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: dso_deprecator.pl
#

use CCE;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG)
{
        use Sys::Syslog qw( :DEFAULT setlogsock);
        use Data::Dumper;
        &debug_msg("Debugging enabled for dso_deprecator.pl\n");
}

my $cce = new CCE;

$cce->connectfd();

my $vhost = $cce->event_object();
my $vhost_new = $cce->event_new();
my $vhost_old = $cce->event_old();

# write SSL configuration in /etc/httpd/conf/vhosts/siteX, not use mod_perl

#
### Get all Parameters from CODB that we need:
#

my ($void) = $cce->find('Vsite', {'name' => $vhost->{name}});
my ($ok, $vsite) = $cce->get($void);
my ($ok, $userwebs) = $cce->get($void, 'USERWEBS');
my ($ok, $LOGS) = $cce->get($void, 'LOGS');
my ($PHPoid) = $cce->find('PHP');
my ($ok, $PHP_server) = $cce->get($PHPoid);

my ($ok, $PHP_Vsite) = $cce->get($void, "PHPVsite");
my ($ok, $vsite_php) = $cce->get($void, "PHP");

#
### Do the Deeds:
#

if ($vsite_php->{enabled}) {
    &debug_msg("dso_deprecator.pl: Vsite has PHP enabled.\n");
    if ((!$vsite_php->{suPHP_enabled}) && (!$vsite_php->{fpm_enabled})) {
        # Fallback:
        # If PHP is enabled, but neither suPHP or FPM are used? Then this is a wrong setting from a 5209R or 5210R import.
        # We make sure that 'mod_ruid_enabled' is set to '0' (in case it is not) and default to PHP-FPM:
        $vsite_php->{mod_ruid_enabled} = '0';
        $vsite_php->{fpm_enabled} = '1';
        &debug_msg("Deprecated DSO based PHP implementation found. Changing it to PHP-FPM.\n");
        my ($ok) = $cce->set($void, 'PHP', { 'mod_ruid_enabled' => $vsite_php->{mod_ruid_enabled}, 'fpm_enabled' => $vsite_php->{fpm_enabled} });
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