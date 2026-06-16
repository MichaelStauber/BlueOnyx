#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email/
# $Id: enable.pl

use Sauce::Service;
use Sauce::Util;
use CCE;
use Email;
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/network /usr/sausalito/handlers/base/email/);
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
    use Sys::Syslog qw(openlog syslog closelog setlogsock);
}

my $cce = new CCE( Namespace => 'Email', Domain => 'base-email' );

$cce->connectfd();

# Get 'System' Object:
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

# Get 'System' . 'Email' Object/Namespace:
my ($ok, $obj) = $cce->get($oids[0], 'Email');
unless ($ok and $obj) {
    $cce->bye('FAIL');
    exit 1;
}

&debug_msg("Running email/enable.pl\n");

# dovecot settings first
Sauce::Util::editfile('/etc/dovecot/dovecot.conf', *make_dovecot_conf, $obj, $System );

# Edit /etc/dovecot/conf.d/10-master.conf:
Sauce::Util::editfile('/etc/dovecot/conf.d/10-master.conf', *edit_master_conf, $obj, $System);

# Edit /etc/dovecot/conf.d/10-auth.conf:
Sauce::Util::editfile('/etc/dovecot/conf.d/10-auth.conf', *edit_auth_conf, $obj, $System);

# Make sure we have our modified 10-ssl.conf in place:
if ((-f '/etc/dovecot/conf.d/10-ssl.conf') && (-f '/etc/dovecot/conf.d/10-ssl.conf.bx')) {
    $check = `cat /etc/dovecot/conf.d/10-ssl.conf|grep '^ssl_dh ='|wc -l`;
    chomp($check);
    if ($check eq "0") {
        system("cp /etc/dovecot/conf.d/10-ssl.conf.bx /etc/dovecot/conf.d/10-ssl.conf");
    }
}

# Dovecot enabler:
my $need_dovecot_auth = (
        (($obj->{enableSMTPAuth}       // '0') eq '1') ||
        (($obj->{enableSubmissionPort} // '0') eq '1') ||
        (($obj->{enableSMTPS}          // '0') eq '1')
    ) ? 1 : 0;

my $all_mail_protocols_off =
    ($obj->{enablePop}  eq '0') &&
    ($obj->{enablePops} eq '0') &&
    ($obj->{enableImap} eq '0') &&
    ($obj->{enableImaps} eq '0');

if ($all_mail_protocols_off && !$need_dovecot_auth) {
    Sauce::Service::service_toggle_init('dovecot', '0');
}
else {
    system("/sbin/chkconfig --add dovecot");
    Sauce::Service::service_set_init('dovecot', 'on', '345');
    Sauce::Service::service_toggle_init('dovecot', '1');
}

# Fix Cyrus SASL config for Sendmail (and keep smtpd.conf aligned):
Sauce::Util::editfile('/etc/sasl2/Sendmail.conf', *fix_sendmail_sasl_conf, $obj, $System);
Sauce::Util::editfile('/etc/sasl2/smtpd.conf',    *fix_sendmail_sasl_conf, $obj, $System);

# settings smtp, smtps and submission port
Sauce::Util::editfile(Email::SendmailMC, *make_sendmail_mc, $obj, $System );

# Add Sendmail Systemd override file to call /usr/sausalito/sbin/email-auth-helper.pl and to fix PID location:
Sauce::Util::editfile('/etc/systemd/system/sendmail.service', *write_sendmail_systemd_override_unit);

# Rebuilding sendmail.cf:
&debug_msg("Rebuilding sendmail.cf from sendmail.mc \n");
system("m4 /usr/share/sendmail-cf/m4/cf.m4 /etc/mail/sendmail.mc > /etc/mail/sendmail.cf");

# need to start sendmail?
my $run = 0;
if ($obj->{enableSMTP} || $obj->{enableSMTPS} || $obj->{enableSubmissionPort}) {
    $run = 1;
}
if ($run) {
    Sauce::Service::service_toggle_init('sendmail', 1);
    Sauce::Service::service_toggle_init('saslauthd', $obj->{enableSMTPAuth});
}
else {
    Sauce::Service::service_toggle_init('sendmail', 0);
    Sauce::Service::service_toggle_init('saslauthd', 0);
}


# pop-before-smtp relaying
my $popRelay = Sauce::Service::service_get_init('poprelayd') ? 'on' : 'off';
my $newpopRelay = $obj->{popRelay} ? 'on' : 'off';

&debug_msg("Think poprelayd is running? $popRelay - Should be? $newpopRelay\n");

Sauce::Service::service_toggle_init('poprelayd', $obj->{popRelay}); 

if($newpopRelay eq 'on') {
    &debug_msg("Linking custodiat into place\n");
    Sauce::Util::linkfile('/usr/local/sbin/poprelayd.custodiat', '/etc/cron.quarter-daily/poprelayd.custodiat');
} else {
    &debug_msg("Unlinking custodiat\n");
    Sauce::Util::unlinkfile('/etc/cron.quarter-daily/poprelayd.custodiat');
}

Sauce::Service::service_restart_xinetd();
# running swatch
#system('/usr/sbin/swatch -c /etc/swatch.conf &');

$cce->bye("SUCCESS");
exit(0);

#
### Subs:
#

#
# REPLACE your entire edit_master_conf() with this one:
#
sub edit_master_conf {

    &debug_msg("Running 'edit_master_conf')\n");

    my $fin    = shift;
    my $fout   = shift;
    my $obj    = shift;
    my $System = shift;

    # Strict booleans (CCE stores '0'/'1' as strings):
    my $enableImap  = ((defined $obj->{enableImap})  && ($obj->{enableImap}  eq '1')) ? 1 : 0;
    my $enableImaps = ((defined $obj->{enableImaps}) && ($obj->{enableImaps} eq '1')) ? 1 : 0;
    my $enablePop   = ((defined $obj->{enablePop})   && ($obj->{enablePop}   eq '1')) ? 1 : 0;
    my $enablePops  = ((defined $obj->{enablePops})  && ($obj->{enablePops}  eq '1')) ? 1 : 0;

    # Defaults with sane fallbacks:
    my $default_process_limit = $obj->{'default_process_limit'} // 100;
    my $default_client_limit  = $obj->{'default_client_limit'}  // 1000;
    my $default_vsz_limit     = $obj->{'default_vsz_limit'}     // 256;
    my $process_limit         = $obj->{'process_limit'}         // 1024;

    print $fout "default_process_limit = $default_process_limit\n";
    print $fout "default_client_limit = $default_client_limit\n\n";
    print $fout "# Default VSZ (virtual memory size) limit for service processes. This is mainly\n";
    print $fout "# intended to catch and kill processes that leak memory before they eat up\n";
    print $fout "# everything.\n";
    print $fout "default_vsz_limit = ${default_vsz_limit}M\n\n";
    print $fout "# Login user is internally used by login processes. This is the most untrusted\n";
    print $fout "# user in Dovecot system. It shouldn't have access to anything at all.\n";
    print $fout "default_login_user = dovenull\n\n";
    print $fout "# Internal user is used by unprivileged processes. It should be separate from\n";
    print $fout "# login user, so that login processes can't disturb other processes.\n";
    print $fout "default_internal_user = dovecot\n\n";

    #
    # IMAP login service
    #
    print $fout "service imap-login {\n";
    print $fout "  inet_listener imap {\n";
    print $fout "    port = " . ($enableImap ? 143 : 0) . "\n";
    print $fout "  }\n";
    print $fout "  inet_listener imaps {\n";
    print $fout "    port = " . ($enableImaps ? 993 : 0) . "\n";
    print $fout "    ssl = "  . ($enableImaps ? "yes" : "no") . "\n";
    print $fout "  }\n\n";
    print $fout "  # Number of connections to handle before starting a new process. Typically\n";
    print $fout "  # the only useful values are 0 (unlimited) or 1. 1 is more secure, but 0\n";
    print $fout "  # is faster. <doc/wiki/LoginProcess.txt>\n";
    print $fout "  service_count = 1\n\n";
    print $fout "  # Number of processes to always keep waiting for more connections.\n";
    print $fout "  process_min_avail = 0\n\n";
    print $fout "  # If you set service_count=0, you probably need to grow this.\n";
    print $fout "  vsz_limit = ${default_vsz_limit}M\n";
    print $fout "}\n\n";

    #
    # POP3 login service
    #
    print $fout "service pop3-login {\n";
    print $fout "  inet_listener pop3 {\n";
    print $fout "    port = " . ($enablePop ? 110 : 0) . "\n";
    print $fout "  }\n";
    print $fout "  inet_listener pop3s {\n";
    print $fout "    port = " . ($enablePops ? 995 : 0) . "\n";
    print $fout "    ssl = "  . ($enablePops ? "yes" : "no") . "\n";
    print $fout "  }\n";
    print $fout "}\n\n";

    #
    # LMTP
    #
    print $fout "service lmtp {\n";
    print $fout "  unix_listener lmtp {\n";
    print $fout "    #mode = 0666\n";
    print $fout "  }\n\n";
    print $fout "  # Create inet listener only if you can't use the above UNIX socket\n";
    print $fout "  #inet_listener lmtp {\n";
    print $fout "    # Avoid making LMTP visible for the entire internet\n";
    print $fout "    #address =\n";
    print $fout "    #port =\n";
    print $fout "  #}\n";
    print $fout "}\n\n";

    #
    # IMAP worker
    #
    print $fout "service imap {\n";
    print $fout "  # Most of the memory goes to mmap()ing files. You may need to increase this\n";
    print $fout "  # limit if you have huge mailboxes.\n";
    print $fout "  #vsz_limit = ${default_vsz_limit}M\n\n";
    print $fout "  # Max. number of IMAP processes (connections)\n";
    print $fout "  process_limit = $process_limit\n";
    print $fout "}\n\n";

    #
    # POP3 worker
    #
    print $fout "service pop3 {\n";
    print $fout "  # Max. number of POP3 processes (connections)\n";
    print $fout "  process_limit = $process_limit\n";
    print $fout "}\n\n";

    #
    # AUTH service (single, correct block)
    #
    print $fout "service auth {\n";
    print $fout "  unix_listener auth-userdb {\n";
    print $fout "    #mode = 0666\n";
    print $fout "    #user =\n";
    print $fout "    #group =\n";
    print $fout "  }\n\n";
    print $fout "  # Postfix smtp-auth (Dovecot SASL socket)\n";
    print $fout "  unix_listener /var/spool/postfix/private/auth {\n";
    print $fout "    mode = 0660\n";
    print $fout "    user = postfix\n";
    print $fout "    group = postfix\n";
    print $fout "  }\n\n";
    print $fout "  # Auth process is run as this user.\n";
    print $fout "  #user = \$default_internal_user\n";
    print $fout "}\n\n";

    #
    # AUTH worker
    #
    print $fout "service auth-worker {\n";
    print $fout "  # Auth worker process is run as root by default, so that it can access\n";
    print $fout "  # /etc/shadow. If this isn't necessary, the user should be changed to\n";
    print $fout "  # \$default_internal_user.\n";
    print $fout "  #user = root\n";
    print $fout "}\n\n";

    #
    # DICT
    #
    print $fout "service dict {\n";
    print $fout "  unix_listener dict {\n";
    print $fout "    #mode = 0600\n";
    print $fout "    #user =\n";
    print $fout "    #group =\n";
    print $fout "  }\n";
    print $fout "}\n";

    return 1;
}

#
# REPLACE your entire make_dovecot_conf() with this one:
# (Fixes "imaps/pop3s protocol obsolete" by NEVER writing imaps/pop3s as protocols,
#  and fixes "starting up without any protocols" by using strict '0'/'1' checks.)
#
sub make_dovecot_conf {
    my $in     = shift;
    my $out    = shift;
    my $obj    = shift;
    my $System = shift;

    # Strict booleans:
    my $enableImap  = ((defined $obj->{enableImap})  && ($obj->{enableImap}  eq '1')) ? 1 : 0;
    my $enableImaps = ((defined $obj->{enableImaps}) && ($obj->{enableImaps} eq '1')) ? 1 : 0;
    my $enablePop   = ((defined $obj->{enablePop})   && ($obj->{enablePop}   eq '1')) ? 1 : 0;
    my $enablePops  = ((defined $obj->{enablePops})  && ($obj->{enablePops}  eq '1')) ? 1 : 0;

    # Listen (IPv4/IPv6)
    my $listen = 'listen = *';
    if (($System->{gateway} // '') ne '' && ($System->{gateway_IPv6} // '') ne '') {
        $listen = 'listen = *,[::]';     # dual stack
    }
    elsif (($System->{gateway} // '') eq '' && ($System->{gateway_IPv6} // '') ne '') {
        $listen = 'listen = [::]';       # v6-only
    }

    # Protocols (NOTE: imaps/pop3s are NOT protocols; they are listeners.)
    my @proto;
    push @proto, 'imap' if ($enableImap || $enableImaps);
    push @proto, 'pop3' if ($enablePop  || $enablePops);

    # Can be empty -> "auth-only" dovecot for Postfix SASL socket (service auth).
    my $protocols = join(' ', @proto);

    select $out;
    while (<$in>) {
        if (/^\s*protocols\s*=/) {
            # Keep it exactly as dovecot expects. Empty is OK.
            if ($protocols eq '') {
                print "protocols =\n";
            }
            else {
                print "protocols = $protocols\n";
            }
        }
        elsif (/^\s*listen\s*=/) {
            print "$listen\n";
        }
        else {
            print $_;
        }
    }
    return 1;
}

sub make_sendmail_mc {
    my $in  = shift;
    my $out = shift;
    my $obj = shift;
    my $System = shift;

    #
    # AUTH policy
    #
    my $enableSMTPAuth =
        ((defined $obj->{enableSMTPAuth}) && ($obj->{enableSMTPAuth} eq '1')) ? 1 : 0;

    my $auth_options_line = $enableSMTPAuth
        ? "define(`confAUTH_OPTIONS', `A')dnl\n"
        : "define(`confAUTH_OPTIONS', `A')dnl\n";

    my $saw_auth_options     = 0;
    my $wrote_auth_options   = 0;
    my $inserted_auth_options = 0;

    #
    # Network info
    #
    my $REAL_PRIMARY_INTERFACE_NAME = get_primary_interface();

    my $ipv6_ip = `LC_ALL=C ip -6 addr show dev $REAL_PRIMARY_INTERFACE_NAME | grep inet6 | grep -v '^fe80::/' | head -1 | awk '{ print \$2 }' | cut -d / -f1`;
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

    #
    # IPv6 part accumulator
    #
    my $ipv6_part = '';

    #
    # Port logic
    #
    my ($smtpPort, $smtpsPort, $submissionPort);

    if (($System->{gateway} ne "") && ($System->{gateway_IPv6} ne "")) {
        #
        # Dual stack
        #
        $smtpPort = $obj->{enableSMTP}
            ? "DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n"
            : "dnl DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";

        $smtpsPort = $obj->{enableSMTPS}
            ? "DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n"
            : "dnl DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";

        $submissionPort = $obj->{enableSubmissionPort}
            ? "DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n"
            : "dnl DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";

        if ($obj->{enableSMTP}) {
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=smtp, Name=MTA-v6, Modifier=O, Addr=$ipv6_ip')\n";
        }
        if ($obj->{enableSMTPS}) {
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=smtps, Name=TLSMTA-v6, M=s, Modifier=O, Addr=$ipv6_ip')\n";
        }
        if ($obj->{enableSubmissionPort}) {
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=submission, Name=MSA-v6, M=Ea, Modifier=O, Addr=$ipv6_ip')\n";
        }
    }
    elsif (($System->{gateway} eq "") && ($System->{gateway_IPv6} ne "")) {
        #
        # IPv6 only
        #
        $smtpPort = "dnl DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";
        $smtpsPort = "dnl DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";
        $submissionPort = "dnl DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";

        if ($obj->{enableSMTP}) {
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=smtp, Name=MTA-v6, Modifier=O')\n";
        }
        if ($obj->{enableSMTPS}) {
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=smtps, Name=TLSMTA-v6, M=s, Modifier=O')\n";
        }
        if ($obj->{enableSubmissionPort}) {
            $ipv6_part .= "DAEMON_OPTIONS(`Familiy=inet6, Port=submission, Name=MSA-v6, M=Ea, Modifier=O')\n";
        }
    }
    else {
        #
        # IPv4 only
        #
        $smtpPort = $obj->{enableSMTP}
            ? "DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n"
            : "dnl DAEMON_OPTIONS(`Port=smtp, Name=MTA')\n";

        $smtpsPort = $obj->{enableSMTPS}
            ? "DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n"
            : "dnl DAEMON_OPTIONS(`Port=smtps, Name=TLSMTA, M=s')\n";

        $submissionPort = $obj->{enableSubmissionPort}
            ? "DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n"
            : "dnl DAEMON_OPTIONS(`Port=submission, Name=MSA, M=Ea')\n";
    }

    #
    # Rewrite sendmail.mc
    #
    select $out;
    while (<$in>) {

        #
        # Normalize confAUTH_OPTIONS:
        # - Write exactly one canonical line.
        # - Drop all duplicates.
        # - This also cleans already-damaged sendmail.mc files on the next run.
        #
        if (/^\s*(dnl\s+)?define\(`confAUTH_OPTIONS'\s*,\s*`[^']*'\)\s*dnl\s*$/) {
            $saw_auth_options = 1;

            if (!$wrote_auth_options) {
                print $auth_options_line;
                $wrote_auth_options = 1;
                $inserted_auth_options = 1;
            }

            next;
        }

        #
        # Insert confAUTH_OPTIONS after confPRIVACY_FLAGS if missing so far.
        # If another confAUTH_OPTIONS exists later, it will be skipped by the
        # normalization block above.
        #
        if (!$wrote_auth_options && /^\s*define\(`confPRIVACY_FLAGS'/) {
            print $_;
            print $auth_options_line;
            $wrote_auth_options = 1;
            $inserted_auth_options = 1;
            next;
        }

        #
        # Replace DAEMON_OPTIONS (IPv4)
        #
        if (/^dnl DAEMON_OPTIONS\(\`Port=smtp, Name=MTA'/ || /^DAEMON_OPTIONS\(\`Port=smtp, Name=MTA'/) {
            print $smtpPort;
            next;
        }
        if (/^dnl DAEMON_OPTIONS\(\`Port=smtps, Name=TLSMTA,/ || /^DAEMON_OPTIONS\(\`Port=smtps, Name=TLSMTA,/ ) {
            print $smtpsPort;
            next;
        }
        if (/^dnl DAEMON_OPTIONS\(\`Port=submission, Name=MSA,/ || /^DAEMON_OPTIONS\(\`Port=submission, Name=MSA,/ ) {
            print $submissionPort;
            next;
        }

        #
        # Strip existing IPv6 listeners
        #
        if (/^DAEMON_OPTIONS\(\`Familiy=inet6/) {
            next;
        }

        #
        # Insert IPv6 listeners at canonical anchor
        #
        if (/^dnl DAEMON_OPTIONS\(\`port=smtp,Addr=::1, Name=MTA-v6, Family=inet6'\)dnl/) {
            print $_;
            print $ipv6_part if $ipv6_part ne '';
            next;
        }

        print $_;
    }

    #
    # Append confAUTH_OPTIONS if still missing
    #
    if (!$wrote_auth_options) {
        print "\n# Added by BlueOnyx handler:\n";
        print $auth_options_line;
    }

    return 1;
}

sub edit_auth_conf {
    my $fin    = shift;
    my $fout   = shift;
    my $obj    = shift;
    my $System = shift;

    my $saw_username_format = 0;
    my $saw_mechanisms      = 0;
    my $saw_include         = 0;

    while (my $line = <$fin>) {

        # Normalize auth_username_format
        if ($line =~ /^\s*auth_username_format\s*=/) {
            print $fout "auth_username_format = %Lu\n";
            $saw_username_format = 1;
            next;
        }

        # Normalize auth_mechanisms
        if ($line =~ /^\s*auth_mechanisms\s*=/) {
            print $fout "auth_mechanisms = plain login\n";
            $saw_mechanisms = 1;
            next;
        }

        # Track include
        if ($line =~ /^\s*!\s*include\s+blueonyx-auth\.conf\.ext\s*$/) {
            $saw_include = 1;
            print $fout $line;
            next;
        }

        # Comment out auth-system.conf.ext include (we don't want it)
        if ($line =~ /^\s*!\s*include\s+auth-system\.conf\.ext\s*$/) {
            print $fout "#!include auth-system.conf.ext\n";
            next;
        }

        # If it's already commented, keep as-is
        if ($line =~ /^\s*#\s*!\s*include\s+auth-system\.conf\.ext\s*$/) {
            print $fout $line;
            next;
        }

        print $fout $line;
    }

    # If not present, append these at the end (Dovecot reads in-order):
    if (!$saw_username_format) {
        print $fout "\n# Added by BlueOnyx handler:\n";
        print $fout "auth_username_format = %Lu\n";
    }
    if (!$saw_mechanisms) {
        print $fout "# Added by BlueOnyx handler:\n";
        print $fout "auth_mechanisms = plain login\n";
    }

    # Ensure include exists near bottom
    if (!$saw_include) {
        print $fout "\n# Added by BlueOnyx handler:\n";
        print $fout "!include blueonyx-auth.conf.ext\n";
    }

    return 1;
}

sub fix_sendmail_sasl_conf {
    my $fin    = shift;
    my $fout   = shift;
    my $obj    = shift;
    my $System = shift;

    # Canonical content we want:
    my $wanted = "pwcheck_method: saslauthd\n" .
                 "mech_list: plain login\n";

    # Read existing file (if any) so we only rewrite when needed:
    my $existing = '';
    if (defined $fin) {
        local $/ = undef;
        $existing = <$fin> // '';
    }

    # Normalize to avoid false negatives on whitespace:
    my $norm = $existing;
    $norm =~ s/\r//g;

    my $needs_fix = 0;

    # Fix if pwcheck_method is missing or malformed (e.g. "pwcheck_method:saslauthd"):
    if ($norm !~ /^\s*pwcheck_method\s*:\s*saslauthd\s*$/mi) {
        $needs_fix = 1;
    }

    # Fix if mech_list is missing or doesn't contain (plain login) in any order:
    # We keep it strict to your known-good setting.
    if ($norm !~ /^\s*mech_list\s*:\s*plain\s+login\s*$/mi) {
        $needs_fix = 1;
    }

    # If there are extra lines, we don't care—unless we need to fix.
    # If we fix, we write canonical minimal config (stable + predictable).
    if ($needs_fix) {
        &debug_msg("Fixing SASL config file (pwcheck_method/mech_list)\n");
        print $fout $wanted;
    }
    else {
        # Preserve original content if already correct:
        print $fout $existing;
    }

    return 1;
}

sub write_sendmail_systemd_override_unit {
    my $fin  = shift;
    my $fout = shift;

    my $helper = '/usr/sausalito/sbin/email-auth-helper.pl';

    my $wanted =
"[Unit]
Description=Sendmail Mail Transport Agent
After=syslog.target network.target
Conflicts=postfix.service exim.service
Wants=sm-client.service
StartLimitIntervalSec=0

[Service]
Type=forking
PIDFile=/run/sendmail.pid
Environment=SENDMAIL_OPTS=-q1h
EnvironmentFile=-/etc/sysconfig/sendmail
ExecStartPre=-/etc/mail/make
ExecStartPre=-/etc/mail/make aliases
ExecStartPre=$helper
ExecStart=/usr/sbin/sendmail -bd \$SENDMAIL_OPTS \$SENDMAIL_OPTARG
ExecReload=/usr/bin/kill -HUP \$MAINPID
# hack to allow async reload to complete, otherwise systemd may signal error
ExecReload=/usr/bin/sleep 2

[Install]
WantedBy=multi-user.target
Also=sm-client.service
";

    # Read existing (if any) and compare so we only daemon-reload when needed
    my $existing = '';
    if (defined $fin) {
        local $/ = undef;
        $existing = <$fin> // '';
    }
    $existing =~ s/\r//g;

    my $changed = ($existing ne $wanted) ? 1 : 0;

    print $fout $wanted;

    if ($changed) {
        &debug_msg("Wrote /etc/systemd/system/sendmail.service override unit; running daemon-reload\n");
        system('/bin/systemctl', 'daemon-reload');
    }

    return 1;
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