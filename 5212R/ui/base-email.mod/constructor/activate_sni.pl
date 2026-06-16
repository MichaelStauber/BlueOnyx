#!/usr/bin/perl -I. -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: activate_sni.pl

use Sauce::Service;
use Base::HomeDir qw(homedir_get_group_dir);
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/ssl);
use SSL qw(ssl_get_cert_info ssl_create_directory);
use CCE;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Data::Dumper;
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectuds();

my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $System) = $cce->get($oids[0]);
unless ($ok and $System) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $Email) = $cce->get($oids[0], 'Email');
unless ($ok and $Email) {
    $cce->bye('FAIL');
    exit 1;
}

$need_dovecot_restart = '0';
$need_postfix_restart = '0';

# Config files and paths for SNI:
$DIR_DOVECOT = '/etc/dovecot/';
$SNI_DIR_DOVECOT = '/etc/dovecot/conf.sni.d/';
$SNI_MASTER_FILE = '/etc/dovecot/conf.d/11-sni-master.conf';
$SNI_DUMMY_FILE = '/etc/dovecot/conf.sni.d/00-readme.conf';
$SNI_POSTFIX = '/etc/postfix/vsite_ssl.map';

# Find out where the cert directory is:
if ($System->{'productBuild'} eq '5209R') {
    $filler = '/';
}
else {
    $filler = '/wwwroot/';
}

if (! -f $SNI_MASTER_FILE) {
    # Make sure the SNI config directory and the required SNI Master Include are present:
    if (! -d $SNI_DIR_DOVECOT) {
        system("mkdir $SNI_DIR_DOVECOT");
        system("chmod 755 $SNI_DIR_DOVECOT");
        &debug_msg("Initial creation of $SNI_DIR_DOVECOT");
    }
    if (! -f $SNI_DUMMY_FILE) {
        open(my $fh, '>', $SNI_DUMMY_FILE);
        print $fh "# Config file directory for Vsite SNI certs.\n";
        print $fh "# Do not delete this file!\n";
        close $fh;
        system("chmod 644 $SNI_DUMMY_FILE");
        system("chown root:root $SNI_DUMMY_FILE");
        &debug_msg("Initial creation of $SNI_DUMMY_FILE");    
    }
    if (! -f $SNI_MASTER_FILE) {
        open(my $fh, '>', $SNI_MASTER_FILE);
        print $fh "# Tell Dovecot to load the Vsite SNI config files from their separate config directory:\n";
        print $fh '!include /etc/dovecot/conf.sni.d/*.conf' . "\n";
        close $fh;
        system("chmod 644 $SNI_MASTER_FILE");
        system("chown root:root $SNI_MASTER_FILE");
        &debug_msg("Initial creation of $SNI_MASTER_FILE");
        $need_dovecot_restart++;
    }
}
else {
    &debug_msg("Dovecot SNI is already configured.");
}

# Find all Vsites:
my @vhosts = ();
my (@vhosts) = $cce->findx('Vsite');

# Walk through all Vsites:
for my $vsite (@vhosts) {
    ($ok, my $my_vsite) = $cce->get($vsite);
    &debug_msg("Processing Site: $my_vsite->{fqdn} \n");
    ($ok, my $my_vsite_ssl) = $cce->get($vsite, 'SSL');

    # Full path to Vsite's SNI file:
    $SNI_Vsite_file = $SNI_DIR_DOVECOT . $my_vsite->{'name'} . '.conf';

    if ($my_vsite_ssl->{'enabled'} eq '1') {
        &debug_msg("Vsite " . $my_vsite->{'name'} . " (" . $my_vsite->{'fqdn'} . ") has SSL turned on.\n");

        $cert_dir = homedir_get_group_dir($my_vsite->{name}, $my_vsite->{volume}) . $filler . $SSL::CERT_DIR;
        &debug_msg("Vsite cert directory: $cert_dir \n");
    
        $key_file = $cert_dir . '/' . 'key';
        $cert_file = $cert_dir . '/' . 'nginx_cert_ca_combined';

        if ((-f $key_file) && (-f $cert_file)) {
            &debug_msg("Vsite cert and key are present. Creating/updating SNI include $SNI_Vsite_file\n");

            # Parse certificate for domain names that the certificate is valid for:
            open my $x_cert, "-|", "openssl x509 -in $cert_file -text -noout";
            my %alias_hash;
            while (<$x_cert>) {
                while (/DNS:([^,]+)/g) {
                    my $alias = $1;
                    chomp($alias);
                    $alias_hash{$alias} = 1;
                }
                if (/Subject: CN = ([^\n]+)/) {
                    my $alias = $1;
                    chomp($alias);
                    $alias_hash{$alias} = 1;
                }
            }
            close $x_cert;
            my @Aliases = keys %alias_hash;
    
            # Create contends of Vsite's SNI config file:
            $sni_cfg = '# SNI config file for ' . $my_vsite->{'fqdn'} . "\n\n";
            foreach my $x (@Aliases) {
                $sni_cfg .= 'local_name ' . $x . ' {' . "\n";
                $sni_cfg .= '  ssl_cert = <' . $cert_file . "\n";
                $sni_cfg .= '  ssl_key = <' . $key_file . "\n";
                $sni_cfg .= '}' . "\n";
                $sni_cfg .= "\n\n";
            }

            # Write SNI config file:
            open(my $fh, '>', $SNI_Vsite_file);
            print $fh $sni_cfg;
            close $fh;
            system("chmod 644 $SNI_Vsite_file");
            system("chown root:root $SNI_Vsite_file");
            &debug_msg("Created Vsite SNI config file $SNI_Vsite_file");
            $need_dovecot_restart++;
        }
        else {
            if (-f $SNI_Vsite_file) {
                &debug_msg("Runnin 'rm -f $SNI_Vsite_file' because there doesn't seem to be a cert or a key.\n");
                system("rm -f $SNI_Vsite_file");
                $need_dovecot_restart++;
            }
        }
    }
}

# Conditionally restart Dovecot:
if ($need_dovecot_restart gt '0') {
    &debug_msg("Restarting Dovecot.");
    Sauce::Service::service_run_init('dovecot', 'restart');
}

#
### Postfix Vsite SNI setup:
#

if (-d '/etc/postfix') {
    &debug_msg("Postfix directory found - updating $SNI_POSTFIX.\n");

    # Find all Vsites:
    my @vhosts = ();
    my (@vhosts) = $cce->findx('Vsite');

    $postfix_sni_lines = '';

    # Walk through all Vsites:
    for my $vsite (@vhosts) {
        ($ok, my $my_vsite) = $cce->get($vsite);
        &debug_msg("Processing Site: $my_vsite->{fqdn} \n");
        ($ok, my $my_vsite_ssl) = $cce->get($vsite, 'SSL');

        if ($my_vsite_ssl->{'enabled'} eq '1') {
            &debug_msg("Vsite " . $my_vsite->{'name'} . " (" . $my_vsite->{'fqdn'} . ") has SSL turned on.\n");

            $cert_dir = homedir_get_group_dir($my_vsite->{name}, $my_vsite->{volume}) . $filler . $SSL::CERT_DIR;
            &debug_msg("Vsite cert directory: $cert_dir \n");

            $key_file = $cert_dir . '/' . 'key';
            $cert_file = $cert_dir . '/' . 'nginx_cert_ca_combined';
        
            if ((-f $key_file) && (-f $cert_file)) {
                &debug_msg("Vsite cert and key are present.\n");

                # Parse certificate for domain names that the certificate is valid for:
                open my $x_cert, "-|", "openssl x509 -in $cert_file -text -noout";
                my %alias_hash;
                while (<$x_cert>) {
                    while (/DNS:([^,]+)/g) {
                        my $alias = $1;
                        chomp($alias);
                        $alias_hash{$alias} = 1;
                    }
                    if (/Subject: CN = ([^\n]+)/) {
                        my $alias = $1;
                        chomp($alias);
                        $alias_hash{$alias} = 1;
                    }
                }
                close $x_cert;
                my @Aliases = keys %alias_hash;

                # Create contends of Vsite's SNI config file:
                $sni_cfg = '# SNI config file for ' . $my_vsite->{'fqdn'} . "\n\n";

                foreach my $x (@Aliases) {
                    $postfix_sni_lines .= $x . ' ' . $key_file . ' ' . $cert_file . "\n";
                    $sni_cfg .= "\n\n";
                }
            }
        }
    }
    # Write SNI config file:
    open(my $fh, '>', $SNI_POSTFIX);
    print $fh "# Postfix Vsite SNI configuration\n";
    print $fh $System->{'hostname'} . '.' . $System->{'domainname'} . ' /etc/admserv/certs/key /etc/admserv/certs/nginx_cert_ca_combined' . "\n";
    print $fh $postfix_sni_lines . "\n";
    close $fh;
    system("chmod 644 $SNI_POSTFIX");
    system("chown root:root $SNI_POSTFIX");
    system("postmap -F hash:$SNI_POSTFIX")
    &debug_msg("Initial creation of $SNI_POSTFIX");
    $need_postfix_restart++;
}

# Conditionally restart Postfix:
if ($need_postfix_restart gt '0') {
    &debug_msg("Restarting Postfix.");
    Sauce::Service::service_run_init('postfix', 'restart');
}

#
### Fin:
#

$cce->bye("SUCCESS");
exit 0;

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        $user = $ENV{'USER'};
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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