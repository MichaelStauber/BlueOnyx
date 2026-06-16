#!/usr/bin/perl -I/usr/sausalito/perl

use Sauce::Service;
use Base::HomeDir qw(homedir_get_group_dir);
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/ssl);
use SSL qw(ssl_get_cert_info ssl_create_directory);
use CCE;

my $DEBUG = '0';
if ($DEBUG) { 
    use Data::Dumper;
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE( Namespace => 'Email', Domain => 'base-email' );

$cce->connectfd();

# Get System Object:
my ($sysoid) = $cce->find('System');
my ($ok, $System) = $cce->get($sysoid);

# Get Vsite and ssl information for the Vsite:
$vsite = $cce->event_object();
$oid = $cce->event_oid();
($ok, $vsite_info) = $cce->get($oid);
($ok, $ssl_info) = $cce->get($oid, 'SSL');
($ok, $Email_info) = $cce->get($oid, 'Email');
$ssl = $cce->event_object();

# Is Dovecot actually enabled?
if (($Email_info->{'enablePop'} eq '0') && ($Email_info->{'enablePops'} eq '0') && ($Email_info->{'enableImap'} eq '0') && ($Email_info->{'enableImaps'} eq '0')) {
    $need_dovecot_restart = '0';
}
else {
    $need_dovecot_restart = '1';
}

# Is MTA actually enabled?
if (($Email_info->{'enableSMTPS'} eq '0') && ($Email_info->{'enableSMTP'} eq '0')) {
    $need_postfix_restart = '0';
}
else {
    $need_postfix_restart = '1';
}

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
}

# Full path to Vsite's SNI file:
$SNI_Vsite_file = $SNI_DIR_DOVECOT . $vsite_info->{'name'} . '.conf';

$cert_dir = homedir_get_group_dir($vsite_info->{name}, $vsite_info->{volume}) . $filler . $SSL::CERT_DIR;
&debug_msg("Vsite cert directory: $cert_dir \n");

if ($ssl_info->{'enabled'} eq '0') {
    # Vsite has SSL disabled. Cleanup SNI cert:
    if (-f $SNI_Vsite_file) {
        &debug_msg("Vsite " . $vsite_info->{'name'} . "(" . $vsite_info->{'fqdn'} . ") has SSL turned off. Removing SNI include $SNI_Vsite_file\n");
        system("rm -f $SNI_Vsite_file");
    }
    else {
        &debug_msg("Vsite " . $vsite_info->{'name'} . " (" . $vsite_info->{'fqdn'} . ") has SSL turned off. SNI include $SNI_Vsite_file is already gone.\n");
    }
    # Handle nginx_cert_ca_combined creation and updates:
    &handle_combined_cert();
}
else {
    # Create or update Vsite SNI file:
    &debug_msg("Vsite " . $vsite_info->{'name'} . " (" . $vsite_info->{'fqdn'} . ") has SSL turned on. Creating/updating SNI include $SNI_Vsite_file\n");

    $key = $cert_dir . '/' . 'key';
    $cert = $cert_dir . '/' . 'nginx_cert_ca_combined';

    # Handle nginx_cert_ca_combined creation and updates:
    &handle_combined_cert();

    if ((-f $key) && (-f $cert)) {
        &debug_msg("Vsite cert and key are present.\n");

        # Parse certificate for domain names that the certificate is valid for:
        open my $x_cert, "-|", "openssl x509 -in $cert -text -noout";
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
        $sni_cfg = '# SNI config file for ' . $vsite_info->{'fqdn'} . "\n\n";
        foreach my $x (@Aliases) {
            $sni_cfg .= 'local_name ' . $x . ' {' . "\n";
            $sni_cfg .= '  ssl_cert = <' . $cert . "\n";
            $sni_cfg .= '  ssl_key = <' . $key . "\n";
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
    }
    else {
        if (-f $SNI_Vsite_file) {
            &debug_msg("Runnin 'rm -f $SNI_Vsite_file' because there doesn't seem to be a cert or a key.\n");
            system("rm -f $SNI_Vsite_file");
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
        
            $key = $cert_dir . '/' . 'key';
            $cert = $cert_dir . '/' . 'nginx_cert_ca_combined';
        
            if ((-f $key) && (-f $cert)) {
                &debug_msg("Vsite cert and key are present.\n");
        
                # Parse certificate for domain names that the certificate is valid for:
                open my $x_cert, "-|", "openssl x509 -in $cert -text -noout";
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
                    $postfix_sni_lines .= $x . ' ' . $key . ' ' . $cert . "\n";
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

    if ($need_postfix_restart gt '0') {
        &debug_msg("Restarting Postfix.");
        Sauce::Service::service_run_init('postfix', 'restart');
    }
}

#
### Fin:
#

$cce->bye("SUCCESS");
exit 0;

#
### Subs:
#

sub handle_combined_cert {
    if (-d $cert_dir) {
        &debug_msg("Processing $cert_dir\n");
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
    }
    else {
        &debug_msg("No $cert_dir present. Skipping.\n");
    }
}

sub debug_msg {
    if ($DEBUG) {
    my $msg = shift;
    $DEBUG && print STDERR "$ARGV[0]: ", $msg, "\n";
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