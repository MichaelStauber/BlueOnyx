#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: reload_httpd.pl
# Purpose: Reload httpd and manage PHP-FPM services based on Vsite usage

use strict;
use warnings;
use CCE;
use Sauce::Service;
use Digest::SHA qw(sha256_hex);
use File::Slurp;  # For reading file contents

# Debugging switch (0|1|2):
my $DEBUG = 0;
if ($DEBUG) {
    use Sys::Syslog qw(:DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd();

# Get Event Object
my $obj = $cce->event_object();

# Early exit: Skip if CREATE/MODIFY and force_update isn’t set
if ((($cce->event_is_create()) || ($cce->event_is_modify())) && ($obj->{force_update} eq '')) {
    &debug_msg("Early exit: Doing nothing");
    $cce->bye('SUCCESS');
    exit(0);
}

#
### Step 1: Identify active PHP versions used by Vsites
#

my @vhosts = $cce->findx('Vsite');
my @active_php_versions;  # Store PHP versions (e.g., PHP83) used by Vsites

for my $vsite (@vhosts) {
    my ($ok, $my_vsite) = $cce->get($vsite);
    my ($ok_php, $xvsite_php) = $cce->get($vsite, 'PHP');

    if ($xvsite_php->{'enabled'} eq '1' && $xvsite_php->{'fpm_enabled'} eq '1') {
        my $version = $xvsite_php->{'version'};
        if ($version && !grep { $_ eq $version } @active_php_versions) {
            push @active_php_versions, $version;
            if ($DEBUG == 2) {
                &debug_msg("Vsite $my_vsite->{'name'} uses PHP version: $version");
            }
        }
    }
}

#
### Static defines for PHP handling
#

my $extra_PHP_basepath = '/home/solarspeed/';

my %known_php_versions = (
    'PHP56' => '5.6', 'PHP70' => '7.0', 'PHP71' => '7.1', 'PHP72' => '7.2',
    'PHP73' => '7.3', 'PHP74' => '7.4', 'PHP80' => '8.0', 'PHP81' => '8.1',
    'PHP82' => '8.2', 'PHP83' => '8.3', 'PHP84' => '8.4', 'PHP85' => '8.5',
    'PHP86' => '8.6', 'PHP90' => '9.0', 'PHP91' => '9.1', 'PHP92' => '9.2',
    'PHP93' => '9.3', 'PHP94' => '9.4',
);

my %known_php_services = (
    'PHP56' => 'php-fpm-5.6', 'PHP70' => 'php-fpm-7.0', 'PHP71' => 'php-fpm-7.1',
    'PHP72' => 'php-fpm-7.2', 'PHP73' => 'php-fpm-7.3', 'PHP74' => 'php-fpm-7.4',
    'PHP80' => 'php-fpm-8.0', 'PHP81' => 'php-fpm-8.1', 'PHP82' => 'php-fpm-8.2',
    'PHP83' => 'php-fpm-8.3', 'PHP84' => 'php-fpm-8.4', 'PHP85' => 'php-fpm-8.5',
    'PHP86' => 'php-fpm-8.6', 'PHP90' => 'php-fpm-9.0', 'PHP91' => 'php-fpm-9.1',
    'PHP92' => 'php-fpm-9.2', 'PHP93' => 'php-fpm-9.3', 'PHP94' => 'php-fpm-9.4',
);

# Default php-fpm service (system default)
my $default_php_fpm = 'php-fpm';

#
### Step 2: Manage PHP-FPM services
#

my @fpm_to_restart;  # PHP-FPM services that need restarting (including default)

# Ensure default php-fpm is always enabled and running
my $default_running = Sauce::Service::service_get_state($default_php_fpm);
my $default_enabled = Sauce::Service::service_get_init($default_php_fpm);

if (!$default_enabled) {
    &debug_msg("Enabling $default_php_fpm (OS default, must always be enabled)");
    Sauce::Service::service_set_init($default_php_fpm, 'on');
}
if (!$default_running) {
    &debug_msg("Starting $default_php_fpm (OS default, must always be running)");
    Sauce::Service::service_run_init($default_php_fpm, 'start');
}
push @fpm_to_restart, $default_php_fpm;  # Always include for checksum check
&debug_msg("Added $default_php_fpm to restart list for checksum evaluation");

# Check all known PHP-FPM versions (excluding default)
for my $fpm_key (keys %known_php_versions) {
    my $service = $known_php_services{$fpm_key};
    my $extra_dir = "$extra_PHP_basepath/php-$known_php_versions{$fpm_key}";

    # Check if this PHP version is installed (directory exists)
    if (-d $extra_dir) {
        my $is_running = Sauce::Service::service_get_state($service);
        my $is_enabled = Sauce::Service::service_get_init($service);
        my $is_used = grep { $_ eq $fpm_key } @active_php_versions;

        if ($DEBUG == 2) {
            &debug_msg("$service: running=$is_running, enabled=$is_enabled, used=$is_used");
        }

        # Case 1: Stop and disable if present but not used
        if (!$is_used) {
            if ($is_running) {
                &debug_msg("Stopping $service (not used by any Vsite)");
                Sauce::Service::service_run_init($service, 'stop');
            }
            if ($is_enabled) {
                &debug_msg("Disabling $service (not used by any Vsite)");
                Sauce::Service::service_set_init($service, 'off');
            }
        }
        # Case 2: Prepare to restart if used by Vsites
        elsif ($is_used) {
            if (!$is_enabled) {
                &debug_msg("Enabling $service (used by Vsite)");
                Sauce::Service::service_set_init($service, 'on');
            }
            push @fpm_to_restart, $service;
            &debug_msg("Added $service to restart list");
        }
    }
}

#
### Step 3: Reload httpd and restart necessary PHP-FPM services
#

# Reload httpd
&debug_msg("Reloading httpd");
Sauce::Service::service_run_init('httpd', 'reload');

# Reset failed states and restart active PHP-FPM services if checksum changed
if (@fpm_to_restart) {
    my $fpm_service_line = join(' ', @fpm_to_restart);
    &debug_msg("Resetting failed states for: $fpm_service_line");
    system("/usr/bin/systemctl reset-failed $fpm_service_line");

    &debug_msg("Checking PHP-FPM services for restart: $fpm_service_line");
    foreach my $fpm_single (@fpm_to_restart) {
        my $pool_dir;
        if ($fpm_single eq $default_php_fpm) {
            $pool_dir = "/etc/php-fpm.d";  # Default PHP-FPM pool directory
        }
        else {
            $pool_dir = "/etc/$fpm_single.d";  # Version-specific pool directory
        }

        # Calculate current checksum
        my $current_checksum = calculate_pool_checksum($pool_dir);

        # Read stored checksum
        my $checksum_file = "$pool_dir/config.checksum";
        my $stored_checksum = '';
        if (-f $checksum_file) {
            $stored_checksum = read_file($checksum_file, { chomp => 1 });
        }

        # Compare checksums or restart if no stored checksum exists
        if (!-f $checksum_file || $current_checksum ne $stored_checksum) {
            &debug_msg("Checksum changed or missing for $fpm_single ($pool_dir). Restarting...");
            Sauce::Service::service_run_init($fpm_single, 'restart');
            
            # Write new checksum
            write_file($checksum_file, $current_checksum);
            &debug_msg("Updated checksum for $fpm_single: $current_checksum");
        }
        else {
            &debug_msg("No checksum change for $fpm_single ($pool_dir). Skipping restart.");
        }
    }
}

#
### Step 4: Handle Nginx (if present)
#

my @sys_oid = $cce->find('System');
my ($ok, $nginx) = $cce->get($sys_oid[0], 'Nginx');

if ($nginx->{enabled} eq '1') {
    &debug_msg("Handling Nginx (enabled)");
    my $nginx_status = Sauce::Service::service_get_state('nginx');
    if (!$nginx_status) {
        &debug_msg("Starting and enabling Nginx");
        Sauce::Service::service_set_init('nginx', 'on');
    }
    Sauce::Service::service_run_init('nginx', 'reload');
}
else {
    &debug_msg("Handling Nginx (disabled)");
    my $nginx_status = Sauce::Service::service_get_state('nginx');
    if ($nginx_status) {
        &debug_msg("Stopping and disabling Nginx");
        Sauce::Service::service_set_init('nginx', 'off');
        Sauce::Service::service_run_init('nginx', 'stop');
    }
}

$cce->bye('SUCCESS');
exit(0);

#
### Subroutines
#

# Debugging subroutine
sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0, '', 'user');
        syslog('info', "$ARGV[0]: $msg");
        closelog();
    }
}

# Calculate checksum of a PHP-FPM pool directory
sub calculate_pool_checksum {
    my ($dir) = @_;
    
    if (!-d $dir) {
        &debug_msg("Pool directory $dir does not exist. Returning empty checksum.");
        return '';
    }

    opendir(my $dh, $dir) or do {
        &debug_msg("Cannot open directory $dir: $!");
        return '';
    };

    my $combined_data = '';
    my @files = sort grep { /\.conf$/ && -f "$dir/$_" } readdir($dh);
    for my $file (@files) {
        my $full_path = "$dir/$file";
        my $content = read_file($full_path, { binmode => ':raw' });
        $combined_data .= $file . $content;
    }
    closedir($dh);

    return sha256_hex($combined_data);
}

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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