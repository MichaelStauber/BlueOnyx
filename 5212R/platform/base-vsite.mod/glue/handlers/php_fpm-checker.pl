#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: php_fpm-checker.pl
#
# This runs at the end of 'Vsite._DESTROY', 'Vsite.PHP.*' or 'PHP.*'
# transactions and makes sure that PHP-FPM is enabled and running.
#
# It also handles the extra PHP-FPM pools of extra PHP packages.
# The 'master' PHP-FPM process of the OS should always be running.
# Those of extra PHP versions naturally should only be running if
# that extra PHP package in question is there *and* there is 
# actually a group config file in it. Which would indicate that at
# least one Vsite is using that pool.

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# Uncomment correct type:
#$whatami = "constructor";
$whatami = "handler";

#
#### No configureable options below!
#

$extra_PHP_basepath = '/home/solarspeed/';

use CCE;
use Data::Dumper;
use Sauce::Service;
use Sauce::Util;
use Sauce::Config;
use FileHandle;
use File::Copy;

my $cce = new CCE;

if ($whatami eq "handler") {
    $cce->connectfd();

    # Known PHP versions:
    %known_php_versions = (
                            'PHP56' => '5.6',
                            'PHP70' => '7.0',
                            'PHP71' => '7.1',
                            'PHP72' => '7.2',
                            'PHP73' => '7.3',
                            'PHP74' => '7.4',
                            'PHP80' => '8.0',
                            'PHP81' => '8.1',
                            'PHP82' => '8.2',
                            'PHP83' => '8.3',
                            'PHP84' => '8.4',
                            'PHP85' => '8.5',
                            'PHP86' => '8.6',
                            'PHP90' => '9.0',
                            'PHP91' => '9.1',
                            'PHP92' => '9.2',
                            'PHP93' => '9.3',
                            'PHP94' => '9.4',
                            );


    # Known PHP Paths:
    %known_php_paths = (
                            'PHP56' => '/etc/php-fpm-5.6.d/',
                            'PHP70' => '/etc/php-fpm-7.0.d/',
                            'PHP71' => '/etc/php-fpm-7.1.d/',
                            'PHP72' => '/etc/php-fpm-7.2.d/',
                            'PHP73' => '/etc/php-fpm-7.3.d/',
                            'PHP74' => '/etc/php-fpm-7.4.d/',
                            'PHP80' => '/etc/php-fpm-8.0.d/',
                            'PHP81' => '/etc/php-fpm-8.1.d/',
                            'PHP82' => '/etc/php-fpm-8.2.d/',
                            'PHP83' => '/etc/php-fpm-8.3.d/',
                            'PHP84' => '/etc/php-fpm-8.4.d/',
                            'PHP85' => '/etc/php-fpm-8.5.d/',
                            'PHP86' => '/etc/php-fpm-8.6.d/',
                            'PHP90' => '/etc/php-fpm-9.0.d/',
                            'PHP91' => '/etc/php-fpm-9.1.d/',
                            'PHP92' => '/etc/php-fpm-9.2.d/',
                            'PHP93' => '/etc/php-fpm-9.3.d/',
                            'PHP94' => '/etc/php-fpm-9.4.d/',
                            );

    # Known PHP Services:
    %known_php_services = (
                            'PHP56' => 'php-fpm-5.6',
                            'PHP70' => 'php-fpm-7.0',
                            'PHP71' => 'php-fpm-7.1',
                            'PHP72' => 'php-fpm-7.2',
                            'PHP73' => 'php-fpm-7.3',
                            'PHP74' => 'php-fpm-7.4',
                            'PHP80' => 'php-fpm-8.0',
                            'PHP81' => 'php-fpm-8.1',
                            'PHP82' => 'php-fpm-8.2',
                            'PHP83' => 'php-fpm-8.3',
                            'PHP84' => 'php-fpm-8.4',
                            'PHP85' => 'php-fpm-8.5',
                            'PHP86' => 'php-fpm-8.6',
                            'PHP90' => 'php-fpm-9.0',
                            'PHP91' => 'php-fpm-9.1',
                            'PHP92' => 'php-fpm-9.2',
                            'PHP93' => 'php-fpm-9.3',
                            'PHP94' => 'php-fpm-9.4',
                            );

    # Get OID of 'ActiveMonitor':
    @AMOID = $cce->find('ActiveMonitor');
    for $phpVer (keys %known_php_paths) {

        # Set 'ActiveMonitor' NameSpace:
        $am_NameSpace = 'FPM' . $phpVer;

        # Get current state of ActiveMonitor.$am_NameSpace Obj:
        ($ok, $ActiveMonitor) = $cce->get($AMOID[0], "$am_NameSpace");

        # Is the service in question enabled?
        $ServiceStatus = service_get_init($known_php_services{$phpVer});

        &debug_msg("Processing PHP-FPM check for $known_php_versions{$phpVer} \n");
        if (-d '/home/solarspeed/php-' . $known_php_versions{$phpVer}) {
            &debug_msg("Directory /home/solarspeed/php-$known_php_versions{$phpVer} exists. \n");

            &debug_msg("Service Status for: " . $known_php_services{$phpVer} . " is: $ServiceStatus\n");

            # Check for pools files in this pool:
            $xcheck_file = $known_php_paths{$phpVer} . 'site*.conf';
            $xcheck = `ls -k1 $xcheck_file|wc -l`;
            chomp($xcheck);
            if ($xcheck eq '0') {
                $new_ServiceStatus = '0';
                ($ok, $AMNS) = $cce->get($AMOID[0], "$am_NameSpace");
                &debug_msg("$am_NameSpace enabled = $AMNS->{'enabled'}\n");
                if ($AMNS->{'enabled'} ne $new_ServiceStatus) {
                    ($ok) = $cce->update($AMOID[0], "$am_NameSpace", { 'enabled' => $new_ServiceStatus });
                    &debug_msg("Turning off PHP-FPM ($known_php_services{$phpVer}) as no Vsite is using it.\n");
                    service_set_init($known_php_services{$phpVer}, 'off');
                }
            }
            else {
                $new_ServiceStatus = '1';
                ($ok, $AMNS) = $cce->get($AMOID[0], "$am_NameSpace");
                &debug_msg("$am_NameSpace enabled = $AMNS->{'enabled'}\n");
                if ($AMNS->{'enabled'} ne $new_ServiceStatus) {
                    &debug_msg("Enabling PHP-FPM ($known_php_services{$phpVer}) as Vsites are using it.\n");
                    ($ok) = $cce->update($AMOID[0], "$am_NameSpace", { 'enabled' => $new_ServiceStatus });
                    service_set_init($known_php_services{$phpVer}, 'on');
                }
            }
        }
        else {
            &debug_msg("Service Status for: " . $known_php_services{$phpVer} . " is: $ServiceStatus\n");
            $new_ServiceStatus = '0';
            ($ok, $AMNS) = $cce->get($AMOID[0], "$am_NameSpace");
            &debug_msg("$am_NameSpace enabled = $AMNS->{'enabled'}\n");
            if ($AMNS->{'enabled'} ne $new_ServiceStatus) {
                &debug_msg("Turning off PHP-FPM ($known_php_services{$phpVer}) as this PKG is not installed!\n");
                ($ok) = $cce->update($AMOID[0], "$am_NameSpace", { 'enabled' => $new_ServiceStatus });
                service_set_init($known_php_services{$phpVer}, 'off');
            }
        }
    }
    # Unconditionally enable and restart master PHP-FPM:
    ($ok, $ActiveMonitor) = $cce->get($AMOID[0], 'PHPFPMMASTER');
    if ($ActiveMonitor->{enabled} ne "1") {
        ($ok) = $cce->set($AMOID[0], "PHPFPMMASTER", { 'enabled' => '1' });
    }
    service_set_init('php-fpm', 'on');
}

$cce->bye('SUCCESS');
exit(0);

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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