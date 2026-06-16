#!/usr/bin/perl -I. -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: syncEmailService.pl

use Sauce::Util;
use Sauce::Config;
use Sauce::Service;
use CCE;
use Email;
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network /usr/sausalito/handlers/base/email);
use CCE;
use Sauce::Util;
use Network qw(find_eth_ifaces get_primary_interface get_device_mac check_if_slave 
                    reinitialize_network get_nmcli_uuid get_uuid_by_device 
                    get_secondary_ipv4_addresses get_secondary_ipv6_addresses calcnetwork 
                    find_config_file netmask_to_prefix get_primary_ipv4_ip 
                    get_primary_ipv4_netmask get_primary_ipv6_ip nm_debug_msg 
                    get_primary_ipv4_gateway get_primary_ipv6_gateway get_primary_dns_servers
                    remove_prefixes array_to_string string_to_array network_device_change_state
                    network_device_check_state remove_connections get_dns_servers compare_hashes 
                    in_array compare_network_configs check_dhcp blueonyx_dhcp
                    );

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
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
my ($ok, $obj) = $cce->get($oids[0], 'Email');
unless ($ok and $obj) {
    $cce->bye('FAIL');
    exit 1;
}

# Make sure /etc/shells has all the shells we need or Sendmail might complain:
ensure_shells();

#
### Build ONE update for Email namespace (queueTime + optional relay sync)
#

my %updates = ();

# Always enforce queueTime=immediate (but only write if it differs):
if (!defined($obj->{queueTime}) || ($obj->{queueTime} ne 'immediate')) {
    $updates{queueTime} = 'immediate';
}

# Transitional Smart Relay sync:
# Truth order:
#  1) /etc/postfix/sasl_passwd (new)
#  2) SMART_HOST in /etc/mail/sendmail.mc (legacy)
#  3) otherwise disable

my $sasl_passwd = '/etc/postfix/sasl_passwd';
my $sendmail_mc  = '/etc/mail/sendmail.mc';

if (-f $sasl_passwd) {

    my $relay = parse_postfix_sasl_passwd($sasl_passwd);

    if ($relay->{valid}) {

        $updates{enable_relay}   = 1;
        $updates{smartRelay}     = $relay->{host};
        $updates{relay_port}     = $relay->{port};
        $updates{relay_security} = $relay->{security} || 'enforced';

        if ($relay->{has_auth}) {
            $updates{relay_user} = $relay->{user};
            $updates{relay_pass} = $relay->{pass};
        }
        else {
            $updates{relay_user} = '';
            $updates{relay_pass} = '';
        }
    }
    else {
        &debug_msg("Smart Relay sync: WARNING: $sasl_passwd present, but no valid mapping found\n");
        # Conservative: disable if file exists but is unusable
        $updates{enable_relay} = 0;
    }

}
else {
    # No sasl_passwd -> check legacy SMART_HOST in sendmail.mc
    my $legacy = parse_sendmail_mc_smart_host($sendmail_mc);

    if ($legacy->{valid}) {
        $updates{enable_relay}   = 1;
        $updates{smartRelay}     = $legacy->{host};

        # Legacy sendmail SMART_HOST doesn't carry a port: keep CODB default:
        $updates{relay_port}     = 587;
        $updates{relay_security} = 'enforced';

        # Legacy config didn't encode SMTP AUTH here; leave empty:
        $updates{relay_user}     = '';
        $updates{relay_pass}     = '';
    }
    else {
        # Neither new nor legacy config -> MUST disable relay
        $updates{enable_relay}   = 0;

        # Optional cleanup:
        $updates{smartRelay}     = '';
        $updates{relay_port}     = 587;
        $updates{relay_user}     = '';
        $updates{relay_pass}     = '';
        $updates{relay_security} = 'enforced';
    }
}

# Do ONE set() if anything changed:
if (keys %updates) {

    # Only commit if at least one value differs (reduces churn):
    my $need_set = 0;
    foreach my $k (keys %updates) {
        my $cur = defined($obj->{$k}) ? $obj->{$k} : '';
        my $new = defined($updates{$k}) ? $updates{$k} : '';
        if ("$cur" ne "$new") { $need_set = 1; last; }
    }

    if ($need_set) {
        ($ok) = $cce->set($oids[0], 'Email', \%updates);

        if ($ok) {
            # refresh cached $obj
            foreach my $k (keys %updates) { $obj->{$k} = $updates{$k}; }
            &debug_msg("Email namespace updated (queueTime + relay sync if applicable)\n");
        }
        else {
            &debug_msg("WARNING: could not write CODB Email namespace\n");
        }
    }
}

#
### Email Security: Prevent Sender Identity Spoofing
#

if (($System->{'isLicenseAccepted'} eq '0') && ($obj->{'authsend_protect'} eq '0')) {
    # On freshly set up servers turn this feature on immediately:
    ($ok) = $cce->set($oids[0], 'Email', { 'authsend_protect' => '1' });
    $obj->{'authsend_protect'} = '1';
}

if ($obj->{'authsend_protect'} eq '1') {
    &debug_msg("Email Security: Prevent Sender Identity Spoofing: ENABLED\n");
    system("echo 'ENABLED' > /etc/sysconfig/BX-Email-Sender-Security");
}
else {
    &debug_msg("Email Security: Prevent Sender Identity Spoofing: DISABLED\n");
    system("rm -f /etc/sysconfig/BX-Email-Sender-Security");
}

# make certs file
if (! -d "/usr/share/ssl") {
    system("mkdir /usr/share/ssl");
}
if (! -d "/usr/share/ssl/certs") {
    system("mkdir /usr/share/ssl/certs");
}
system("/bin/cp /etc/pki/tls/certs/ca-bundle.crt /usr/share/ssl/certs/");
system("echo \"\" > /etc/admserv/certs/blank.txt"); 
system("cat /etc/admserv/certs/key /etc/admserv/certs/blank.txt /etc/admserv/certs/certificate > /usr/share/ssl/certs/sendmail.pem");
system("chmod 0600 /usr/share/ssl/certs/sendmail.pem");
system("cp /usr/share/ssl/certs/sendmail.pem /etc/pki/tls/certs/postfix.pem");
system("chmod 0644 /etc/pki/tls/certs/postfix.pem");

system("/bin/cp /etc/admserv/certs/key /etc/pki/dovecot/private/dovecot.pem");
system("/bin/cp /etc/admserv/certs/certificate /etc/pki/dovecot/certs/dovecot.pem");
if (-f '/etc/admserv/certs/ca-certs') {
    # If AdmServ has an intermediate, then copy it to the ca-bundle as well:
    system("cat /etc/admserv/certs/ca-certs >> /usr/share/ssl/certs/ca-bundle.crt")
}

#
## Handle TLS oddity on 5106R:
#
# read build date
my ($fullbuild) = `cat /etc/build`;
chomp($fullbuild);

# figure out our product
my ($build, $model, $lang) = ($fullbuild =~ m/^build (\S+) for a (\S+) in (\S+)/);

# Create 2048 bit Diffie-Hellman file:
if (! -e "/usr/share/ssl/certs/sendmail-2048.dh") {
    system("/usr/bin/openssl dhparam -out /usr/share/ssl/certs/sendmail-2048.dh 2048");
    system("chmod 0600 /usr/share/ssl/certs/sendmail-2048.dh");
}

# Create 4096 Bit DH:
if ((-f '/var/lib/dovecot/ssl-parameters.dat') && (! -f '/etc/dovecot/dh.pem')) {
    system("dd if=/var/lib/dovecot/ssl-parameters.dat bs=1 skip=88 | openssl dhparam -inform der > /etc/dovecot/dh.pem");
}

# Disable NetworkManager check in /usr/libexec/dovecot/prestartscript:
system("sed -i 'sX^/bin/systemctlX#/bin/systemctlXg' /usr/libexec/dovecot/prestartscript");

# Cleanup leftover config backup files:
system('rm -f /etc/dovecot/dovecot.conf.backup.*');

# Handle Dovecot intermediate cert:
if (-f "/etc/admserv/certs/ca-certs") {
    system("/bin/cp /etc/admserv/certs/ca-certs /etc/pki/dovecot/certs/ca.pem");
    system("cat /etc/pki/dovecot/certs/dovecot.pem /etc/admserv/certs/blank.txt /etc/pki/dovecot/certs/ca.pem > /etc/pki/dovecot/certs/combined.pem");
    system("mv /etc/pki/dovecot/certs/combined.pem /etc/pki/dovecot/certs/dovecot.pem");
    chmod 0600, "/etc/pki/dovecot/certs/dovecot.pem";
}
else {
    system("touch /etc/pki/dovecot/certs/ca.pem");
}
chmod 0600, "/etc/pki/dovecot/certs/ca.pem";

# Edit /etc/dovecot/conf.d/10-ssl.conf:
&edit_dovecot_intermediate;

# Toggle base/email/enable.pl handler to write updated Dovecot config:
($ok) = $cce->set($oids[0], 'Email', { 'force_mc_rebuild' => time() });

# sync sendmail settings

$md5_sm_orig = `cat /etc/mail/sendmail.mc|md5sum`;

# submission port
my $run = 0;
if ($obj->{enableSMTP} || $obj->{enableSMTPS} || $obj->{enableSubmissionPort}) {
    $run = 1;
}

# settings smtp, smtps and submission port
my $maxMessageSize = $obj->{maxMessageSize};
my $maxRecipientsPerMessage = $obj->{maxRecipientsPerMessage};

#
## Remove existing RBLs from sendmail.mc:
#
system("/bin/cat /etc/mail/sendmail.mc|/bin/grep -v '^FEATURE(dnsbl' > /etc/mail/sendmail.mc.norbl");
system("/bin/mv /etc/mail/sendmail.mc.norbl /etc/mail/sendmail.mc");

# Get primary IP:
my (@network_oids) = $cce->findx('Network', { 'enabled' => 1, 'real' => 1 });
my $primaryIP = '127.0.0.1';
&debug_msg("Primary IP: $primaryIP");
foreach my $oid (@network_oids) {
    my ($ok, $net) = $cce->get($oid);
    &debug_msg("OID: $oid");
    if ($ok) {
        &debug_msg("Device: " . $net->{device});
        if (($net->{device} ne "venet0") && ($primaryIP eq '127.0.0.1')) {
            $primaryIP = $net->{ipaddr};
        }
    }
    &debug_msg("Using primary IP: $primaryIP");
}

#
## Update sendmail.mc:
#

Sauce::Util::editfile(Email::SendmailMC, *make_sendmail_mc, $obj, $System );

#
## Get RBL config (if present):
#
@rbloids = $cce->find('dnsbl');
foreach $rbloid (@rbloids) {
    &debug_msg("Found RBL-OID $rbloid \n");
    ($ok, $RBLSettings) = $cce->get($rbloid, '');

    # Fiddle the active RBL settings back into sendmail.mc:
    $ret = Sauce::Util::editfile(Email::SendmailMC, *make_sendmail_mc_rbl, $RBLSettings);
    if (!$ret) {
        $cce->bye('FAIL', 'cantEditFile', {'file' => Email::SendmailMC});
        exit(0);
    } 
}

# Cleanup:
system('/bin/rm -f /etc/mail/sendmail.mc.backup.*');

# Rebuilding sendmail.cf:
&debug_msg("Rebuilding sendmail.cf");
system("m4 /usr/share/sendmail-cf/m4/cf.m4 /etc/mail/sendmail.mc > /etc/mail/sendmail.cf");

# Fix EL7 related Systemd fuck-up (thank you, RedHat!):
if (-f "/lib/systemd/system/sendmail.service") {
    system("/bin/sed -i -e 's#^PIDFile=/run/sendmail.pid#PIDFile=/var/run/sendmail.pid#' /lib/systemd/system/sendmail.service");
    system("/usr/bin/systemctl daemon-reload");
}

# Check if the config has changed:
$md5_sm_new = `cat /etc/mail/sendmail.mc|md5sum`;

# need to start sendmail?
if ($run) {
    if ($md5_sm_orig ne $md5_sm_new) {
        # Config has changed, toggle service:
        Sauce::Service::service_toggle_init('sendmail', 1);
        Sauce::Service::service_toggle_init('saslauthd', $obj->{enableSMTPAuth});
    }
}
else {
    Sauce::Service::service_toggle_init('sendmail', 0);
    Sauce::Service::service_toggle_init('saslauthd', 0);
}

# Handle activation / deactivation of Z-Push on older BlueOnyx platforms:
if ($System['productBuild'] == '5210R') {
    if ($obj->{enableZpush}) {
        system("/bin/rm -f /usr/sausalito/ui/web/z-push/.disabled");
    }
    else {
        system("/bin/touch /usr/sausalito/ui/web/z-push/.disabled");
    }
}

$cce->bye('SUCCESS');
exit 0;

#
### Subs:
#

sub make_sendmail_mc {
    my $in  = shift;
    my $out = shift;
    my $obj = shift;

    my $System = shift;

    my $primary_network_interface = get_primary_interface();

    # Are we an OpenVZ mastern node?
    if ((-e "/proc/user_beancounters") && (-f "/etc/vz/conf/0.conf")) {
        # Yes, we are.
        $device = 'venet0:0';
    }
    else {
        # No, we are not.
        $device = $primary_network_interface;
    }

    # Step 2: Extract the primary IPv6 address
    my $ipv6_ip = `LC_ALL=C ip -6 addr show dev $primary_network_interface | grep -oP '(?<=inet6\\s)[\\da-f:]+(?=/)' | head -1`;
    chomp($ipv6_ip);

    #
    ## Cheat Sheet:
    #
    # IPv4 only:
    # DAEMON_OPTIONS(`Port=smtp, Name=MTA')
    # DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')
    # DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')
    # 
    # IPv6 only:
    # DAEMON_OPTIONS(`Familiy=inet6, Port=smtp, Name=MTA, Modifier=O')
    # DAEMON_OPTIONS(`Familiy=inet6, Port=submission, Name=MSA, M=Ea, Modifier=O')
    # DAEMON_OPTIONS(`Familiy=inet6, Port=smtps, Name=TLSMTA, M=s, Modifier=O')
    # 
    # IPv6-Part in Dual Stack. Requires IPv4 part as well. And yes: We can only bind to the primary IPv6 IP, not all of them:
    # DAEMON_OPTIONS(`Familiy=inet6, port=smtp, Name=MTA-v6, Modifier=O, Addr=2001:470:1f0e:7ee::30')
    # DAEMON_OPTIONS(`Familiy=inet6, Port=submission, Name=MSA-v6, M=Ea, Modifier=O, Addr=2001:470:1f0e:7ee::30')
    # DAEMON_OPTIONS(`Familiy=inet6, Port=smtps, Name=TLSMTA-v6, M=s, Modifier=O, Addr=2001:470:1f0e:7ee::30')

    # Start with empty IPv6 related lines:
    my $ipv6_part = '';

    my $privacy_line;
    my $maxMessageSize_line;
    my $maxRecipientsPerMessage_line;
    my $smartRelay_line;
    my $hideHeaders_line;
    my $masqDomain_line;
    my $deliveryMode_line;
    my $delayChecks_line;

    my %Printed_line = ( 
        enableSMTP => 0,
        enableSMTPS => 0,
        enableSubmissionPort => 0,
        maxMessageSize => 0,
        smartRelay => 0,
        privacy => 0,
        queueTime => 0,
        maxRecipientsPerMessage => 0,
        masqAddress => 0,
        delayChecks => 0,
        hideHeaders => 0,
        popRelay => 0,
        Dh_found => 0,
        accept_unresolv => 0,
        LC_found => 0 );

    my @Mailer_line = ();

    if (($System->{gateway} ne "") && ($System->{gateway_IPv6} ne "")) {
        #
        ### Dual Stack:
        #

        # smtp port
        if ($obj->{enableSMTP}) {
            $smtpPort = "DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, port=smtp, Name=MTA-v6, Modifier=O, Addr=$ipv6_ip')\n";
        }
        else {
            $smtpPort = "dnl DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";
        }

        # smtps port
        if ($obj->{enableSMTPS}) {
            $smtpsPort = "DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=smtps, Name=TLSMTA-v6, M=s, Modifier=O, Addr=$ipv6_ip')\n";
        }
        else {
            $smtpsPort = "dnl DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";
        }

        # submission(587) port
        if ($obj->{enableSubmissionPort}) {
            $submissionPort = "DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=submission, Name=MSA-v6, M=Ea, Modifier=O, Addr=$ipv6_ip')\n";
        }
        else {
            $submissionPort = "dnl DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";
        }
    }
    elsif (($System->{gateway} eq "") && ($System->{gateway_IPv6} ne "")) {
        #
        ### IPv6 only:
        #
        # smtp port
        if ($obj->{enableSMTP}) {
            $smtpPort = "dnl DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, port=smtp, Name=MTA-v6, Modifier=O')\n";
        }
        else {
            $smtpPort = "dnl DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";
        }

        # smtps port
        if ($obj->{enableSMTPS}) {
            $smtpsPort = "dnl DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=smtps, Name=TLSMTA-v6, M=s, Modifier=O')\n";
        }
        else {
            $smtpsPort = "dnl DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";
        }

        # submission(587) port
        if ($obj->{enableSubmissionPort}) {
            $submissionPort = "dnl DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=submission, Name=MSA-v6, M=Ea, Modifier=O')\n";
        }
        else {
            $submissionPort = "dnl DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";
        }
    }
    else {
        #
        ### IPv4 only:
        #
        # smtp port
        if ($obj->{enableSMTP}) {
            $smtpPort = "DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";
        }
        else {
            $smtpPort = "dnl DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";
        }

        # smtps port
        if ($obj->{enableSMTPS}) {
            $smtpsPort = "DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";
        }
        else {
            $smtpsPort = "dnl DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";
        }

        # submission(587) port
        if ($obj->{enableSubmissionPort}) {
            $submissionPort = "DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";
        }
        else {
            $submissionPort = "dnl DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";
        }
    }

    # accept_unresolv
    if ($obj->{accept_unresolv}) {
        $accept_unresolv = "FEATURE(`accept_unresolvable_domains')\n";
    }
    else {
        $accept_unresolv = "dnl FEATURE(`accept_unresolvable_domains')dnl\n";
    }

    if ($obj->{smartRelay} ) {
        $smartRelay_line = "define(`SMART_HOST', `". $obj->{smartRelay} . "')\n";
    }
    else {
        $smartRelay_line = "define(`SMART_HOST', `')\n";
    }

    if ($obj->{maxMessageSize} ) {
        # Max message size is in kilos. Sendmail needs bytes.
        $maxMessageSize_line = "define(`confMAX_MESSAGE_SIZE',". $obj->{maxMessageSize}*1024 .")\n";
    }
    else {
        $maxMessageSize_line = "define(`confMAX_MESSAGE_SIZE',0)\n";
    }

    # SmartRelay:
    if ($obj->{smartRelay}) {
        $smartRelay_out = "define(`SMART_HOST', `" . $obj->{smartRelay} . "')\n";
    }
    else {
        $smartRelay_out = "dnl define(`SMART_HOST', `')dnl\n";
    }

    if ($obj->{privacy} ) {
        $privacy_line = "define(`confPRIVACY_FLAGS', `goaway,authwarnings,novrfy,noexpn,noreceipts,restrictqrun')dnl\n";
    }
    else {
        $privacy_line = "define(`confPRIVACY_FLAGS', `authwarnings')\n";
    }

    if ($obj->{queueTime} eq 'immediate') {
        $deliveryMode_line = "define(`confDELIVERY_MODE', `background')\n";
    }
    else {
        # This is how deferred would look. But for security reasons we now ALWAYS deliver immediately:
        #$deliveryMode_line = "define(`confDELIVERY_MODE', `deferred')\n";
        $deliveryMode_line = "define(`confDELIVERY_MODE', `background')\n";
    }

    if ($obj->{maxRecipientsPerMessage} ) {
        # Maximum number of recipients per SMTP envelope:
        $maxRecipientsPerMessage_line = "define(`confMAX_RCPTS_PER_MESSAGE',". $obj->{maxRecipientsPerMessage} .")\n";
    }
    else {
        $maxRecipientsPerMessage_line = "define(`confMAX_RCPTS_PER_MESSAGE',0)\n";
    }

    if ($obj->{masqAddress} ) {
        $masqDomain_line = "MASQUERADE_AS(`". $obj->{masqAddress} ."')\n"
    }
    else {
        $masqDomain_line = "MASQUERADE_AS(`')\n";
    }

    if ($obj->{delayChecks} ) {
        $delayChecks_line = "FEATURE(delay_checks)dnl\n";
    }
    else {
        $delayChecks_line = "dnl FEATURE(delay_checks)dnl\n";
    }

    if ($obj->{hideHeaders} ) {
        $hideHeaders_line = "define(`confRECEIVED_HEADER',`\$?{auth_type}from \${auth_authen} (\$j [$primaryIP]) \$|_REC_HDR_\$.\n\t_REC_BY_\n\t_REC_TLS_\n\t_REC_END_')\n";
    }
    else {
        $hideHeaders_line = "dnl define(`confRECEIVED_HEADER',`by \$j \$?r with \$r\$. id \$i; \$b')dnl\n";
    }

    if ($obj->{popRelay} ) {
        $popRelay_line = "HACK(popauth)dnl\n";
    }
    else {
        $popRelay_line = "";
    }

    # Diffie-Hellmann File:
    $DiffieHellmann = "define(`confDH_PARAMETERS',`/usr/share/ssl/certs/sendmail-2048.dh')\n";

    # Configure LOCAL_CONFIG:
    # 
    # Special note here for the CipherList:
    #
    # Ideally we only want secure cipgers. So we would enable HIGH and turn off all the rest.
    # Bad idea. As crappy as TLS_RSA_WITH_RC4_128_SHA or TLS_RSA_WITH_RC4_128_MD5 are, we do
    # need to allow them at a minimum as that is a fallback level which even the most decrepid
    # mailservers ought to understand. So we enable HIGH, MEDIUM and LOW and then explicitly
    # forbid anything that we consider too weak. The net result of this is that we (at the bare
    # minimum) end up with something like this on a 5106R:
    #
    # TLS_DHE_RSA_WITH_AES_128_CBC_SHA - strong
    # TLS_DHE_RSA_WITH_AES_256_CBC_SHA - strong
    # TLS_RSA_WITH_AES_128_CBC_SHA - strong
    # TLS_RSA_WITH_AES_256_CBC_SHA - strong
    # TLS_RSA_WITH_RC4_128_MD5 - semi-strong
    # TLS_RSA_WITH_RC4_128_SHA - semi-strong
    #
    # On other platforms (5107R, 5108R, 5207R, 5208R and 5209R) we end up with 15-18 unique 
    # ciphers in total. None of them really bad and both TLS_RSA_WITH_RC4_128_SHA and
    # TLS_RSA_WITH_RC4_128_MD5 are present to represent the bottom end of the spectrum.
    #
    # 5107R, 5108R, 5207R and 5208R:
    #
    # TLS_DHE_RSA_WITH_AES_128_CBC_SHA256 - strong
    # TLS_DHE_RSA_WITH_AES_128_GCM_SHA256 - strong
    # TLS_DHE_RSA_WITH_AES_256_CBC_SHA256 - strong
    # TLS_DHE_RSA_WITH_AES_256_CBC_SHA - strong
    # TLS_DHE_RSA_WITH_AES_256_GCM_SHA384 - strong
    # TLS_DHE_RSA_WITH_CAMELLIA_128_CBC_SHA - strong
    # TLS_DHE_RSA_WITH_CAMELLIA_256_CBC_SHA - strong
    # TLS_RSA_WITH_AES_128_CBC_SHA - strong
    # TLS_RSA_WITH_AES_256_CBC_SHA256 - strong
    # TLS_RSA_WITH_AES_256_CBC_SHA - strong
    # TLS_RSA_WITH_AES_256_GCM_SHA384 - strong
    # TLS_RSA_WITH_CAMELLIA_128_CBC_SHA - strong
    # TLS_RSA_WITH_CAMELLIA_256_CBC_SHA - strong
    # TLS_RSA_WITH_RC4_128_MD5 - semi-strong
    # TLS_RSA_WITH_RC4_128_SHA - semi-strong
    #
    # 5209R:
    #
    # TLS_DHE_RSA_WITH_AES_128_CBC_SHA256 - strong
    # TLS_DHE_RSA_WITH_AES_128_CBC_SHA - strong
    # TLS_DHE_RSA_WITH_AES_128_GCM_SHA256 - strong
    # TLS_DHE_RSA_WITH_AES_256_CBC_SHA256 - strong
    # TLS_DHE_RSA_WITH_AES_256_CBC_SHA - strong
    # TLS_DHE_RSA_WITH_AES_256_GCM_SHA384 - strong
    # TLS_DHE_RSA_WITH_CAMELLIA_128_CBC_SHA - strong
    # TLS_DHE_RSA_WITH_CAMELLIA_256_CBC_SHA - strong
    # TLS_RSA_WITH_AES_128_CBC_SHA256 - strong
    # TLS_RSA_WITH_AES_128_CBC_SHA - strong
    # TLS_RSA_WITH_AES_128_GCM_SHA256 - strong
    # TLS_RSA_WITH_AES_256_CBC_SHA256 - strong
    # TLS_RSA_WITH_AES_256_CBC_SHA - strong
    # TLS_RSA_WITH_AES_256_GCM_SHA384 - strong
    # TLS_RSA_WITH_CAMELLIA_128_CBC_SHA - strong
    # TLS_RSA_WITH_CAMELLIA_256_CBC_SHA - strong
    # TLS_RSA_WITH_RC4_128_MD5 - semi-strong
    # TLS_RSA_WITH_RC4_128_SHA - semi-strong

    $local_config = 'LOCAL_CONFIG' . "\n";
    $local_config .= 'O CipherList=HIGH:MEDIUM:LOW:!aNULL:!eNULL:!3DES:!EXP:!PSK:!DSS:!SEED:!DES:!IDEA' . "\n";
    $local_config .= 'O ServerSSLOptions=+SSL_OP_NO_SSLv2 +SSL_OP_NO_SSLv3 +SSL_OP_CIPHER_SERVER_PREFERENCE' . "\n";
    $local_config .= 'O ClientSSLOptions=+SSL_OP_NO_SSLv2 +SSL_OP_NO_SSLv3' . "\n";

    #
    ### Process changes:
    #

    my $mailer_lines = 0;
    my @Mailer_line = ();    
    select $out;
    while (<$in> ) {
        if (/^dnl DAEMON_OPTIONS\(\`Port=smtp, Name=MTA'/o || /^DAEMON_OPTIONS\(\`Port=smtp, Name=MTA'/o ) {
            $Printed_line{'enableSMTP'}++;
            print $smtpPort;
        }
        elsif (/^dnl DAEMON_OPTIONS\(\`Port=smtps, Name=TLSMTA,/o || /^DAEMON_OPTIONS\(\`Port=smtps, Name=TLSMTA,/o ) {
            $Printed_line{'enableSMTPS'}++;
            print $smtpsPort;
        }
        elsif (/^dnl DAEMON_OPTIONS\(\`Port=submission, Name=MSA,/o || /^DAEMON_OPTIONS\(\`Port=submission, Name=MSA,/o ) {
            $Printed_line{'enableSubmissionPort'}++;
            print $submissionPort;
        }
        elsif (/^DAEMON_OPTIONS\(\`Familiy=inet6/) {
            # We remove any existing IPv6 DAEMON_OPTIONS lines first (and add the correct ones later).
            next;
        }
        elsif (/^dnl FEATURE\(\`accept_unresolvable_domains/o || /^FEATURE\(\`accept_unresolvable_domains/o ) {
            $Printed_line{'accept_unresolv'}++;
            print $accept_unresolv;
        }
        elsif (/^dnl DAEMON_OPTIONS\(\`port=smtp,Addr=::1, Name=MTA-v6, Family=inet6'\)dnl/) {
            # Insert IPv6 related lines below this marker:
            print "dnl DAEMON_OPTIONS(`port=smtp,Addr=::1, Name=MTA-v6, Family=inet6')dnl\n";
            # Insert IPv6 related lines (if there are any to insert):
            print $ipv6_part;
        }
        elsif ( /^define\(`confMAX_MESSAGE_SIZE'/o || /^dnl define\(`confMAX_MESSAGE_SIZE'/o ) { #`
            $Printed_line{'maxMessageSize'}++;
            print $maxMessageSize_line;
        }
        elsif ( /^define\(`SMART_HOST'/o || /^dnl define\(`SMART_HOST'/o ) { #`
            $Printed_line{'smartRelay'}++;
            print $smartRelay_line;
        }
        elsif ((/^define\(`confPRIVACY_FLAGS'/o || /^dnl define\(`confPRIVACY_FLAGS'/o ) && ! $Printed_line{'privacy'} ) {
            $Printed_line{'privacy'}++;
            print $privacy_line;
        }
        elsif ( /^define\(`confDELIVERY_MODE'/o || /dnl ^define\(`confDELIVERY_MODE'/o ) { #`
            $Printed_line{'queueTime'}++;
            print $deliveryMode_line;
        }
        elsif (( /^define\(`confMAX_RCPTS_PER_MESSAGE'/o || /^dnl define\(`confMAX_RCPTS_PER_MESSAGE'/o ) && ! $Printed_line{'maxRecipientsPerMessage'}) {
            $Printed_line{'maxRecipientsPerMessage'}++;
            print $maxRecipientsPerMessage_line;
        }
        elsif ( /^MASQUERADE_AS/o || /^dnl MASQUERADE_AS/o ) {
            $Printed_line{'masqAddress'}++;
            print $masqDomain_line;
        }
        elsif ( /^FEATURE\(delay_checks/o || /^dnl FEATURE\(delay_checks/o ) {
            $Printed_line{'delayChecks'}++;
            print $delayChecks_line;
        }
        elsif( ( /^define\(`confRECEIVED_HEADER/ || /_REC_BY_/ || /_REC_TLS_/ || /_REC_END_/ || /^dnl define\(`confRECEIVED_HEADER/ ) && ! $Printed_line{'hideHeaders'} ) {
            # If we find any of the above regexp, then we ignore them. The 'hideheaders' line will be added further below.
        }
        elsif ( /^HACK\(popauth\)dnl$/o  ) {
            &debug_msg("Found POPAUTH line!");
            $Printed_line{'popRelay'}++;
            print $popRelay_line;
        }
        elsif ( /^MAILER\(procmail\)dnl/o ) {
            print $_;
            if ($Printed_line{'Dh_found'} eq "0") {
                # Add the Diffie-Hellmann line:
                print $DiffieHellmann;
                $Printed_line{'Dh_found'} = "1";
            }
            if ($Printed_line{'LC_found'} eq "0") {
                # Add the LOCAL_CONFIG line:
                print $local_config;
                $Printed_line{'LC_found'} = "1";
            }
        }        
        elsif (( /^LOCAL_CONFIG$/o ) || (/^O CipherList(.*)$/o) || (/^O ServerSSLOptions(.*)$/o) || (/^O ClientSSLOptions(.*)$/o)) {
            # If the information was already in sendmail.mc, then we ignore it as we're printing it again anyway.
            next;
        }
        elsif ( /^define\(\`confDH_PARAMETERS/o ) { 
            # Do nothing and remove this line.
        }
        elsif ( /^MAILER\(/o ) {
            $Mailer_line[$mailer_lines] = $_;
            $mailer_lines++;
        }
        elsif ( /^FEATURE\(dnsbl/ || /^dnl FEATURE\(dnsbl/ )  {
            # We strip these out, because further down they are added anyway.
        }
        elsif (( /^define\(`confMILTER_MACROS(.*)$/ ) || ( /^INPUT_MAIL_FILTER(.*)$/ )) {
            # We strip these out, because further down they are added anyway.
        }
        else {
            print $_;
        }
    }

    foreach my $key ( keys %Printed_line ) {
        if ($Printed_line{$key} == 0) {
            if ($key eq 'enableSMTP') {
                print $smtpPort;
            }
            elsif ($key eq 'enableSMTPS') {
                print $smtpsPort;
            }
            elsif ($key eq 'enableSubmissionPort') {
                print $submissionPort;
            }
            elsif ($key eq 'enableSubmissionPort') {
                print $submissionPort;
            }
            elsif ($key eq 'accept_unresolv') {
                print $accept_unresolv;
            }
            elsif ($key eq 'maxMessageSize') {
                print $maxMessageSize_line;
            }
            elsif ($key eq 'smartRelay') {
                print $smartRelay_line;
            }
            elsif ($key eq 'privacy') {
                print $privacy_line;
            }
            elsif ($key eq 'queueTime') {
                print $deliveryMode_line;
            }
            elsif ($key eq 'maxRecipientsPerMessage') {
                print $maxRecipientsPerMessage_line;
            }
            elsif ($key eq 'masqAddress') {
                print $masqDomain_line;
            }
            elsif ($key eq 'masqAddress') {
                print $masqDomain_line;
            }
            elsif ($key eq 'delayChecks') {
                print $delayChecks_line;
            }
            elsif ($key eq 'hideHeaders') {
                print $hideHeaders_line;
                $Printed_line{'hideHeaders'}++;
            }
            elsif ($key eq 'popRelay') {
                print $popRelay_line;
                $Printed_line{'popRelay'}++;
            }
            else {
                $cce->warn("error_writing_sendmail_mc");
                print STDERR "Writing sendmail_mc found $Printed_line{$key} occurences of $key\n";
            }
        }
    }

    if ($mailer_lines) {
        foreach my $line (@Mailer_line) {
            print $line;
        }
    }

    # Print Milter Stuff:
    print "define(`confMILTER_MACROS_CONNECT', confMILTER_MACROS_CONNECT`, {daemon_port}')dnl\n";
    print "define(`confMILTER_MACROS_HELO',    confMILTER_MACROS_HELO`, {verify},{client_resolve}')dnl\n";
    print "define(`confMILTER_MACROS_ENVRCPT', confMILTER_MACROS_ENVRCPT`,{client_resolve}')dnl\n";

    if (-d '/etc/mail/milters.d') {
        $milter_lines = `cat /etc/mail/milters.d/*.cf|grep -v "#"|awk 'NF'`;
        chomp($milter_lines);
        print "$milter_lines\n";
    }

    return 1;
}

sub edit_dovecot_intermediate {

    # Build output hash:
    $server_dovecot_settings_writeoff = { 
        'ssl_ca' => "</etc/pki/dovecot/certs/ca.pem"
    };

    # Write changes using Sauce::Util::hash_edit_function:

    $ok = Sauce::Util::editfile(
        "/etc/dovecot/conf.d/10-ssl.conf",
        *Sauce::Util::hash_edit_function,
        '#',
        { 're' => '=', 'val' => ' = ' },
        $server_dovecot_settings_writeoff);

    system('/bin/rm -f /etc/dovecot/conf.d/10-ssl.conf.backup.*');

    # Error handling:
    unless ($ok) {
        $cce->bye('FAIL', "Error while editing /etc/dovecot/conf.d/10-ssl.conf!");
        exit(1);
    }
}

sub make_sendmail_mc_rbl {
    my $in  = shift;
    my $out = shift;
    my $obj = shift;

    my $blacklistHost;
    my $deferTemporary;
    my $active;
    my $prefix;
    my $defer;
    my $searchString;
    my %Printed_line = ( blacklistHost => 0);
    my $mailer_lines = 0;
    my @Mailer_line = ();

    if ($obj->{active} ) {
        $prefix = "";
    }
    else {
        $prefix = "dnl ";
    }
    if ($obj->{deferTemporary} ) {
        $defer = "`t'";
    }
    else {
        $defer = "";
    }
    if ($obj->{blacklistHost} ) {
        $blacklistHost = $prefix . "FEATURE(dnsbl, `". $obj->{blacklistHost} ."',,$defer)\n";
    }
    else {
        $blacklistHost = "";
    }
    
    select $out;
    while( <$in> ) {
        if ( /^MAILER\(/o ) {
            $Mailer_line[$mailer_lines] = $_;
            $mailer_lines++;
        }
        else {
            print $_;
        }
    }

    foreach my $key (keys %Printed_line ) {
        if ($Printed_line{$key} != 1) {
            print $blacklistHost;
        }
    }
    
    if ($mailer_lines) {
        foreach my $line (@Mailer_line) {
            print $line;
        }
    }
    
    return 1;
}

sub ensure_shells {
    my $shells_file = '/etc/shells';

    my @required_shells = (
        '/bin/sh',
        '/bin/bash',
        '/bin/badsh',
        '/bin/usersh',
        '/usr/sbin/jk_lsh',
        '/usr/sbin/jk_chrootsh',
    );

    # Read existing shells into a hash
    open my $fh, '<', $shells_file or die "Can't open $shells_file: $!";
    my %present;
    while (my $line = <$fh>) {
        chomp $line;
        $present{$line} = 1 if $line =~ m{^/};
    }
    close $fh;

    # Find missing shells
    my @missing;
    foreach my $shell (@required_shells) {
        push @missing, $shell unless exists $present{$shell};
    }

    if (@missing) {
        &debug_msg("Adding missing shells: @missing");

        # Manual backup
        my $backup = "$shells_file.bak";
        open my $in,  '<', $shells_file or die "Can't read $shells_file: $!";
        open my $out, '>', $backup      or die "Can't write $backup: $!";
        while (my $line = <$in>) {
            print $out $line;
        }
        close $in;
        close $out;

        # Append missing shells
        open my $fh_out, '>>', $shells_file or die "Can't write $shells_file: $!";
        foreach my $shell (@missing) {
            print $fh_out "$shell\n";
        }
        close $fh_out;
    }
    else {
        &debug_msg("All required shells are already present.");
    }
}

sub parse_postfix_sasl_passwd {
    my $file = shift;

    my %r = (
        valid    => 0,
        host     => '',
        port     => 25,
        user     => '',
        pass     => '',
        has_auth => 0,
        security => 'enforced',
    );

    open my $fh, '<', $file or return \%r;

    my $first_map = '';
    while (my $line = <$fh>) {
        chomp $line;

        # Header hint: # BX_RELAY_SECURITY=enforced|optional|insecure
        if ($line =~ /^\s*#\s*BX_RELAY_SECURITY\s*=\s*(\S+)\s*$/i) {
            my $sec = lc($1);
            $r{security} = $sec if ($sec =~ /^(enforced|optional|insecure)$/);
            next;
        }

        next if ($line =~ /^\s*#/);
        next if ($line =~ /^\s*$/);

        $first_map = $line;
        last;
    }
    close $fh;

    return \%r if (!$first_map);

    # Expect: [host]:port  user:pass
    # Or:     [host]:port  :
    my ($key, $val) = split(/\s+/, $first_map, 2);
    $val = '' if (!defined $val);

    # Parse key: [host] or [host]:port
    if ($key =~ /^\[([^\]]+)\](?::(\d+))?$/) {
        $r{host} = $1;
        $r{port} = $2 || 25;
    }
    else {
        return \%r; # invalid key format
    }

    $val =~ s/^\s+|\s+$//g;

    # Determine auth
    if ($val ne '' && $val ne ':') {
        if ($val =~ /^([^:]+):(.*)$/) {
            $r{user} = $1;
            $r{pass} = $2;
            $r{has_auth} = 1 if ($r{user} ne '');
        }
    }

    $r{valid} = 1 if ($r{host} ne '');
    return \%r;
}

sub parse_sendmail_mc_smart_host {
    my $file = shift;

    my %r = (
        valid => 0,
        host  => '',
    );

    return \%r if (!-f $file);

    open my $fh, '<', $file or return \%r;

    while (my $line = <$fh>) {
        next unless $line =~ /SMART_HOST/;

        # define(`SMART_HOST', `relay.example.net')
        if ($line =~ /define\(\s*`SMART_HOST'\s*,\s*`([^']+)'\s*\)/) {
            $r{host}  = $1;
            $r{valid} = 1;
            last;
        }
    }

    close $fh;
    return \%r;
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