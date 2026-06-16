#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/vsite
# $Id: vsite_destroy.pl
#
# largely based on siteDel.pm in turbo_ui
# handle cleaning up when a Vsite is deleted
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use Vsite;
use File::Path;
use Sauce::Util;
use Sauce::Config;
use Sauce::Service;
use Base::HomeDir qw(homedir_get_group_dir homedir_create_group_link);
use Base::Group qw(groupdel);
use Config::INI::Reader;
use Config::INI::Writer;

my $cce = new CCE('Domain' => 'base-vsite');

$cce->connectfd();

&debug_msg("vsite_destroy.pl starting up.\n");

my ($ok, $vsite);

my ($sysoid) = $cce->find('System');

$vsite = $cce->event_old();

# Handle custom php.ini:
$custom_php_ini_path = $vsite->{'basedir'} . "/wwwroot/php.ini";
if (-f $custom_php_ini_path) {
    system("/usr/bin/chattr -i $custom_php_ini_path");
}

#
### Handle Dovecot SNI:
#
$SNI_DIR_DOVECOT = '/etc/dovecot/conf.sni.d/';

# Full path to Vsite's SNI file:
$SNI_Vsite_file = $SNI_DIR_DOVECOT . $vsite->{'name'} . '.conf';

&debug_msg("SNI_Vsite_file: $SNI_Vsite_file\n");

if (-f $SNI_Vsite_file) {
    &debug_msg("Removing SNI include $SNI_Vsite_file\n");
    system("rm -f $SNI_Vsite_file");
    Sauce::Service::service_run_init('dovecot', 'restart');
}
else {
    &debug_msg("SNI include $SNI_Vsite_file is already gone.\n");
}

#
###
#

# check if any site members still exist
if (($vsite->{name} ne '') &&
    (scalar($cce->find('User', { 'site' => $vsite->{name} })) > 0)) {
    &debug_msg("FAIL: Vsite still has members\n");
    $cce->bye('FAIL', 'siteMembersFound');
    exit(1);
}

# depopulate dns records
if ($vsite->{dns_auto}) {
    &debug_msg("Dealing with Vsite DNS\n");
    my @dns_records = $cce->find('DnsRecord', 
            { 
                'hostname' => $vsite->{hostname}, 
                'domainname' => $vsite->{domain} 
            });

    for my $rec (@dns_records) {
        $cce->destroy($rec);
    }

    # restart dns server
    my $time = time();
    ($ok) = $cce->set($sysoid, "DNS", { 'commit' => $time });

    if (not $ok) {
        &debug_msg("Warn: cantRestartDns\n");
        $cce->warn('[[base-vsite.cantRestartDns]]');
    }
}

my ($vhost_oid) = $cce->find('VirtualHost', { 'name' => $vsite->{name} });
($ok) = $cce->destroy($vhost_oid);
if (!$ok) {
    &debug_msg("vsite_destroy.pl FAIL: VirtualHostNotFound\n");
    $cce->bye('FAIL', 'VirtualHostNotFound');
    exit(1);
}

# things to do if this is the last vsite using this IP
if ($vsite->{ipaddr} ne "") {
    unless (scalar($cce->find("Vsite", { 'ipaddr' => $vsite->{ipaddr} }))) {
        &debug_msg("vsite_del_network_interface " . $vsite->{ipaddr} . "\n");
        # Use our routine from Vsite.pm to remove the extra-ips if needed:
        ($ok) = vsite_del_network_interface($cce, $vsite->{ipaddr});
    }
    &debug_msg("Done with vsite_del_network_interface " . $vsite->{ipaddr} . "\n");
}

# things to do if this is the last vsite using this IP
if ($vsite->{ipaddrIPv6} ne "") {
    unless (scalar($cce->find("Vsite", { 'ipaddrIPv6' => $vsite->{ipaddrIPv6} }))) {
        &debug_msg("vsite_del_network_interface " . $vsite->{ipaddrIPv6} . "\n");
        # Use our routine from Vsite.pm to remove the extra-ips if needed:
        ($ok) = vsite_del_network_interface($cce, $vsite->{ipaddrIPv6});
    }
    &debug_msg("Done with vsite_del_network_interface " . $vsite->{ipaddrIPv6} . "\n");
}

# delete the home directory for this site
my $base = homedir_get_group_dir($vsite->{name}, $vsite->{volume});

# destroy the command line friendly symlink
my ($site_link, $link_target) = homedir_create_group_link($vsite->{name}, $vsite->{fqdn}, $vsite->{volume});
unlink($site_link);
&debug_msg("Unlinking " . $site_link . "\n");
Sauce::Util::addrollbackcommand("umask 000; /bin/ln -sf \"$link_target\" \"$site_link\"");

# Deal with Vsite's php.ini if present:
my $php_ini = $base . "/php.ini";
if (-f $php_ini) {
    &debug_msg("Dealing with " . $php_ini . "\n");
    system("chattr -i $php_ini");
    system("rm -f $php_ini");
}

# remove site directory: slightly redundant, but sometimes it does NOT get deleted by remove_site_dir.pl
if ($base =~ /^\/.+/) {
    &debug_msg("Running 'rmtree' on " . $base . "\n");
    rmtree($base);
}

# Handle PHP-FPM:
$fpm_pool_cfg = "/etc/php-fpm.d/" . $vsite->{name} . ".conf";
if (-f $fpm_pool_cfg) {
    # Vsite has a PHP-FPM pool which needs to go as well:
    &debug_msg("Unlinking " . $fpm_pool_cfg . "\n");
    unlink($fpm_pool_cfg);
    # Reload PHP-FPM:
    service_run_init('php-fpm', 'reload');
}

#
### Handle Jailkit:
#

$jailconfig = '/etc/jailkit/jk_socketd.ini';

if (-f $jailconfig) {

    my $site_dir = homedir_get_group_dir($vsite->{name}, $vsite->{volume});

    &debug_msg("Checking $jailconfig to see if it has $site_dir related sections:\n");

    # Clean up jk_socketd.ini first - remove comments and deduplicate sections
    &cleanup_jk_socketd_ini($jailconfig);

    # Open config file with eval protection:
    my $config_hash = {};
    eval {
        $config_hash = Config::INI::Reader->read_file($jailconfig);
    };
    if ($@) {
        &debug_msg("Config::INI::Reader failed: $@\n");
    }
    my $new_config = {};
    &debug_msg("Finished reading " . $jailconfig . "\n");

    # Parse slurped in Hash:
    my $found = '0';
    foreach my $key (keys %{$config_hash}) {
        if ($key =~ /^$site_dir\/(.*)$/) {
            $found++;
            &debug_msg("Need to remove key: $key\n");
        }
        else {
            &debug_msg("Keeping unrelated key: $key\n");
            $new_config->{$key} = $config_hash->{$key};
        }
    }

    # Write back an update config file if there were changes:
    if ($found gt '0') {
        &debug_msg("Updating $jailconfig\n");
        # Write manually to avoid spaces around =
        &manual_write_ini($new_config, $jailconfig);
        &debug_msg("Finished writing new " . $jailconfig . "\n");
        system("/bin/chmod 644 $jailconfig");

        &debug_msg("Restarting Jailkit Services\n");
        service_run_init('jailkit', 'restart');
    }
}

# Delete Vsite group:
&debug_msg("Deleting group " . $vsite->{name} . "\n");
my $ret = groupdel($vsite->{name});

# Bring the network up with the updated IP bindings:
&debug_msg("Running final vsite_toggle_network_interface \n");
($ok) = vsite_toggle_network_interface($cce);

$cce->bye('SUCCESS');
&debug_msg("Done with SUCCESS \n");
exit(0);

#
### Subs:
#

# Subroutine to clean up jk_socketd.ini before parsing
sub cleanup_jk_socketd_ini {
    my ($file) = @_;
    &debug_msg("Cleaning up $file\n");
    
    open(my $fh, '<', $file) or return;
    my @lines = <$fh>;
    close($fh);
    
    my @cleaned = ();
    my %seen_sections = ();
    
    foreach my $line (@lines) {
        chomp $line;
        next if ($line =~ /^\s*#/);
        next if ($line =~ /^\s*$/);
        
        if ($line =~ /^\[(.+)\]$/) {
            my $section = $1;
            if (exists $seen_sections{$section}) {
                &debug_msg("Skipping duplicate section: [$section]\n");
                next;
            }
            $seen_sections{$section} = 1;
        }
        push @cleaned, $line;
    }
    
    open(my $out, '>', $file) or return;
    print $out join("\n", @cleaned) . "\n";
    close($out);
    &debug_msg("Cleanup of $file done\n");
}


# Manual INI writer - writes without spaces around = for Jailkit compatibility
sub manual_write_ini {
    my ($config, $file) = @_;
    open(my $fh, '>', $file) or return;
    foreach my $section (keys %{$config}) {
        print $fh "[$section]\n";
        foreach my $key (keys %{$config->{$section}}) {
            print $fh "$key=$config->{$section}->{$key}\n";
        }
        print $fh "\n";
    }
    close($fh);
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
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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
