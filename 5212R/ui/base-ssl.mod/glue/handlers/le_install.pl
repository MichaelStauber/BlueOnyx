#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: le_install.pl
#

# Debugging switch (0|1):
# 0 = off
# 1 = log to syslog
$DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    use Data::Dumper;
}

use CCE;
use lib qw(/usr/sausalito/perl /usr/sausalito/handlers/base/ssl);
use Sauce::Service;
use Base::Vsite qw(vsite_update_site_admin_caps);
use Base::HomeDir qw(homedir_get_group_dir);
use SSL qw(ssl_get_cert_info ssl_create_directory);
use JSON;

use Sys::Hostname;
use I18n;
use MIME::Lite;
use Encode::Encoder;
use Encode qw(from_to);

my $host = hostname();
my $i18n = new I18n();

my $cce = new CCE('Domain' => 'base-ssl');
$cce->connectfd();

# Get Vsite and ssl information for the Vsite:
$vsite = $cce->event_object();
$oid = $cce->event_oid();
($ok, $ssl_info) = $cce->get($oid, 'SSL');
($ok, $PHP_info) = $cce->get($oid, 'PHP');

# Update Vsite Disk usage information:
if (!exists $vsite->{'productBuild'}) {
    # This is indeed a Vsite - and NOT the 'System' object:
    ($disk_update_ok) = $cce->set($oid, 'Disk', { 'refresh' => time() });
    if ($disk_update_ok) {
        &debug_msg("Successfully updated current Disk usage for Vsite.\n");
    }
    else {
        &debug_msg("Failed to updated current Disk usage for Vsite!\n");
        $cce->bye('FAIL');
        exit(1);
    }
}

($ok, $Disk_info) = $cce->get($oid, 'Disk');
$ssl = $cce->event_object();
$ssl_old = $cce->event_old();
$ssl_new = $cce->event_new();

if ($ssl_info->{'performLEinstall'} eq "") {
    &debug_msg("Not using LE for this SSL certificiate install.\n");
    $cce->bye('SUCCESS');
    exit(0);
}

if ($vsite->{'name'}) {
    $siteName = $vsite->{'name'};
}
else {
    $siteName = '';
}

#
### Quota checks:
#

if ($siteName ne '') {

    # We only do a Vsite Quota-Check if we have a $siteName. For AdmServ we just skip this as it has no $siteName.

    $quotafailure = '0';

    #
    #-- Group Quota:
    #

    $group_free_space = $Disk_info->{'quota'} - $Disk_info->{'used'};
    $disk_used = $Disk_info->{'used'};
    $disk_limit = $Disk_info->{'quota'};

    # At least 1MB ought to be free:
    if ($group_free_space > 1) {
        &debug_msg("INFO: Quota Check: Vsite $siteName has enough free group quota (Used: $disk_used - Quota: $disk_limit).\n");
    }
    else {
        &debug_msg("WARNING: Quota Check: Vsite $siteName does NOT have enough free group quota! (Used: $disk_used - Quota: $disk_limit)\n");
        $quotafailure = '1';
    }

    #
    #-- SiteAdmin Quota:
    #

    # Find and get User Object of 'prefered_siteAdmin' - but only if the User belongs to the same group as the Vsite itself:
    @user_oid = $cce->findx('User', {}, {'name' => $PHP_info->{'prefered_siteAdmin'}, 'site' => $vsite->{'name'}});

    if (defined($user_oid[0])) {
        &debug_msg("USER OID: " . $user_oid[0] . "\n");
        ($ok, $Vsite_Admin) = $cce->get($user_oid[0]);
        if ($ok) {
            ($ok, $Vsite_Admin_Disk) = $cce->get($user_oid[0], 'Disk');
            &debug_msg("INFO: Quota Check: Vsite $siteName has User " . $Vsite_Admin->{'name'} . " as 'Web Owner'. Doing User quota ckeck!\n");

            # Check siteAdmin quota::
            if (check_siteadmin_quota($PHP_info->{'prefered_siteAdmin'})) {
                # At least 1MB of free disk space are available:
                &debug_msg("Quota Check: User " . $Vsite_Admin->{'name'} . " of $siteName does have enough free group quota.\n");
            }
            else {
                &debug_msg("WARNING: Quota Check: User " . $Vsite_Admin->{'name'} . " of $siteName does NOT have enough free group quota!\n");
                $quotafailure = '1';
            }
        }
        else {
            &debug_msg("INFO: Quota Check: Vsite $siteName does not have a qualified User as 'Web Owner'. Skipping User quota ckeck!\n");
        }
    }
    else {
        &debug_msg("INFO: Quota Check: Vsite $siteName does not have a qualified User as 'Web Owner'. Skipping User quota ckeck!\n");
    }

    # Send warning email:
    if ($quotafailure eq '1') {

        &debug_msg("INFO: Preparing warning emails to all relevant parties that Vsite is over quota and cert cannot be renewed.");

        my @sysoid = $cce->find('System');
        my ($ok, $sysobj) = $cce->get($sysoid[0]);
        my $system_lang = $sysobj->{productLanguage};
        my $platform = $sysobj->{productBuild};

        my $conf = '/etc/swatch.conf';

        if (-f $conf) {
            open(CONF, "< $conf");

            my @email_list;
            my $body = "";

            my $lang = "en";
            my $enabled = "true";

            while (<CONF>) {
                chomp;
                my($key, $val) = split /\s*=\s*/, $_, 2;
                if ($key eq "email_list") {
                    @email_list = split /\s*,\s*/, $val;
                }
                elsif ($key eq "lang") {
                    $lang = $val;
                }
                elsif ($key eq "enabled") {
                    $enabled = $val;
                }
            }

            push(@email_list, $Vsite_Admin->{'name'});

            # We can't email in Japanese yet, as MIME:Lite alone doesn't support it. We'd need MIME::Lite:TT:Japanese
            # and a hell of a lot of dependencies to sort that out. So for now we hard code them to 'en_US' or 'en'
            # for emailing purpose from within this script:
            if (($system_lang eq "ja") || ($system_lang eq "ja_JP")) {
                $i18n->setLocale("en_US");

            }
            else {
                $i18n->setLocale($system_lang);
            }
            my $body_head = $i18n->get('[[base-ssl.LEquotaWarningText]]');

            my $body_footer = "\n\n" . $i18n->get("[[base-vsite.fqdn]]") . ': ' . $siteName . "\n";
            $body_footer .= $i18n->get("[[base-vsite.prefered_siteAdmin]]") . ' ' . $Vsite_Admin->{'name'} . "\n\n";

            $body = $body_head . $body_footer;

            # Need to convert to UTF-8. Ain'that funny. The source *IS* UTF-8:
            from_to($body, "windows-1252", "utf-8");

            my $subject = $host . ": " . Encode::encode("MIME-B", $i18n->get("[[base-ssl.LEemailSubject]]"));
            my $to;
            foreach $to (@email_list) {
              
                # Build the message using MIME::Lite instead:
                my $send_msg = MIME::Lite->new(
                    From     => "root",
                    To       => $to,
                    Subject  => $subject,
                    Data     => $body,
                    Charset => 'utf-8'
                );

                # Set content type:
                $send_msg->attr("content-type"         => 'text/plain');
                $send_msg->attr("content-type.charset" => "utf-8");

                # Out with the email:
                $send_msg->send;
                &debug_msg("INFO: Quota Check: Sending warning email to: $to\n");
            }
        }
        &debug_msg("WARNING: Quota Check: Sent warning message(s) and am now exiting!\n");
        $cce->bye('FAIL', "[[base-ssl.LEquotaWarningText]]"); 
        exit(1); 
    }
}
else {
    &debug_msg("INFO: Request is for an AdmServ certificate. Ignoring quota checks.\n");
}

&debug_msg("Performing LE SSL install for $vsite->{'CLASS'} $siteName\n");

if (($vsite->{'CLASS'} eq "Vsite") || ($vsite->{'CLASS'} eq "System")) {

    if ($vsite->{'CLASS'} eq "Vsite") {
        $fqdn = $vsite->{'fqdn'};
    }
    elsif ($vsite->{'CLASS'} eq "System") {
        $fqdn = $vsite->{'hostname'} . '.' . $vsite->{'domainname'};
    }
    else {
        &debug_msg("WARNING: Unable to detect fqdn!\n");
        $cce->bye('FAIL', "[[base-ssl.LE_CA_Request_FQDN_Error]]"); 
        exit(1); 
    }

    &debug_msg("FQDN: $fqdn\n");

    # Sane default:
    $original_webAliasRedirects = '0';
    $original_webRedirect_enabled = '0';

    # Get WebAliases:
    $DoIhaveWebAliases = 0;
    $alias_line = '';
    if ($vsite->{'CLASS'} eq "Vsite") {
        $need_httpd_restart = '0';
        $original_webAliasRedirects = $vsite->{'webAliasRedirects'};
        &debug_msg("CLASS is Vsite - webAliasRedirects is: $original_webAliasRedirects\n");
        @webAliases = $cce->scalar_to_array($ssl_info->{LEwantedAliases});
        $numAliases = '0';
        foreach $alias (@webAliases) {
            if ($alias ne "") {
                $alias_line .= '-d ' . $alias . ' ';
                $numAliases++;
            }
        }
        chop($alias_line);

        # Special Case: If 'Web Alias Redirects' is ticked and we request cert validity for more
        # than the primary FQDN, then the validation will fail due to the redirects. Hence:
        # If someone requests validity for more than just FQDN, then we turn 'Web Alias Redirects'
        # off until the renewal procedure is through:
        if ($numAliases gt '0') {
            $DoIhaveWebAliases = 1;
            &debug_msg("Web-Aliases: Multiple aliases requested. Turning 'webAliasRedirects' off.\n");
            $cce->set($vsite->{'OID'}, '', { 'webAliasRedirects' => '0' });
            $need_httpd_restart++;
        }
        else {
            &debug_msg("Web-Aliases: None wanted for SSL.\n");
        }

        # Special Case: If 'Redirect/Proxy Website' is ticked and we request cert validity, then
        # the validation will fail due to the redirects. Hence: If someone requests a LE-Cert, we
        # for more than just FQDN, then we turn 'Web Alias Redirects'
        # must briefly turn off redirects until the renewal procedure is through:
        ($ok, $REDIRECT_VSITE) = $cce->get($vsite->{'OID'}, 'REDIRECT');
        if ($REDIRECT_VSITE->{'enabled'} eq '1') {
            $original_webRedirect_enabled = 1;
            &debug_msg("'Redirect/Proxy Website': Turning 'Redirect/Proxy Website' off.\n");
            $cce->set($vsite->{'OID'}, 'REDIRECT', { 'enabled' => '0' });
            $need_httpd_restart++;
        }
        else {
            &debug_msg("'Redirect/Proxy Website': Not enabled. Great!\n");
        }

        # Restart Apache if required due to changes in configuration:
        if ($need_httpd_restart gt '0') {
            $cce->set($vsite->{'OID'}, '', { 'force_update' => time() });
        }
    }
    &debug_msg("Web-Aliases: $alias_line\n");

    # Get webroot:
    if ($vsite->{'CLASS'} eq "Vsite") {
        $webroot = $vsite->{'basedir'} . "/web";

        # Find and get System Object:
        ($sysoid) = $cce->find('System');
        ($ok, $System_web) = $cce->get($sysoid, 'Web');
    }
    else {
        $webroot = '/var/www/html';
    }

    # Auto-Renew:
    $autoRenew = '0';
    if ($ssl_info->{'autoRenew'} eq "1") {
        $autoRenew = '1';
    }

    # After how many days do we renew?
    $autoRenewDays = $ssl_info->{'autoRenewDays'};

    # Email:
    $email = '';
    if ($ssl_info->{'LEemail'} ne "") {
        $email = ' --accountemail ' . $ssl_info->{'LEemail'};
        $account_settings_writeoff = 'ACCOUNT_EMAIL=' . "'" . $ssl_info->{'LEemail'} . "'";
    }
    else {
        ($sysoid) = $cce->find('System');
        ($ok, $System) = $cce->get($oid);
        $account_settings_writeoff = 'ACCOUNT_EMAIL=' . "'admin" . '@' .  $System->{'hostname'} . '.' . $System->{'domainname'} . "'"; 
    }

    # Edit /usr/sausalito/acme/account.conf to update account email:
    $account_conf = '/usr/sausalito/acme/account.conf';
    system("sed -i -e \"s|^ACCOUNT_EMAIL=.*|$account_settings_writeoff|\" $account_conf");

    # Old location of the ./well-known directory (we now use '/home/.acme/' instead:)
    $well_known_location = $webroot . '/.well-known';

    # Get certificate directory:
    if ($vsite->{'CLASS'} eq "Vsite") {
        if ($vsite->{basedir}) {
            $cert_dir = "$vsite->{basedir}/wwwroot/$SSL::CERT_DIR";
            &debug_msg("Cert-Directory: $vsite->{basedir}/wwwroot/$SSL::CERT_DIR \n");
        }
        else {
            $cert_dir = homedir_get_group_dir($vsite->{name}, $vsite->{volume}) . '/' . $SSL::CERT_DIR;
        }
    }
    else {
        $cert_dir = '/etc/admserv/certs';
        &debug_msg("Cert-Directory: $cert_dir \n");
    }
    if ($vsite->{'CLASS'} eq "Vsite") {
        if (!-d $cert_dir) {
            if (!ssl_create_directory(02770, scalar(getgrnam($vsite->{name})), $cert_dir)) {
                &debug_msg("Couldn't create $cert_dir!\n");
                $cce->bye('FAIL', "[[base-ssl.CouldnotCreateCertDir]]");
                exit(1);
            }
        }
    }

    #if (-f "/usr/sausalito/acme/acme_wrapper.sh") {
    #    $acme_bin = "/usr/sausalito/acme/acme_wrapper.sh"
    #}
    #else {
    #    $acme_bin = "/usr/sausalito/acme/acme.sh"
    #}
    $acme_bin = "/usr/sausalito/acme/acme.sh --config-home '/usr/sausalito/acme/data'";

    # Obtain SSL cert:
    $dry_run = '';
    #$dry_run = "--staging";
    &debug_msg("Running: $acme_bin $dry_run --apache --issue -d $fqdn $alias_line -w /home/.acme/ --keylength 4096 --days $autoRenewDays --cert-file $cert_dir/certificate --key-file $cert_dir/key --fullchain-file $cert_dir/nginx_cert_ca_combined --ca-file $cert_dir/ca-certs --auto-upgrade 1 $email --force --debug --reloadcmd \"/usr/sausalito/sbin/reload_webservers.pl\" --log /var/log/letsencrypt/letsencrypt.log\n");
    $result = `$acme_bin $dry_run --apache --issue -d $fqdn $alias_line -w /home/.acme/ --keylength 4096 --days $autoRenewDays --cert-file $cert_dir/certificate --key-file $cert_dir/key --fullchain-file $cert_dir/nginx_cert_ca_combined --ca-file $cert_dir/ca-certs --auto-upgrade $autoRenew $email --force --debug --reloadcmd \"/usr/sausalito/sbin/reload_webservers.pl\" --log /var/log/letsencrypt/letsencrypt.log 2>&1`;

    $CertFail = '0';

    if ($result =~ /NXDOMAIN/) {
        &clean_well_known;
        &deal_with_services;
        &debug_msg("WARNING: Error during SSL cert request!\n");
        $CertFail = '1';
        $FailMsg = "[[base-ssl.LE_CA_Request_Error]]";
    }

    if ($result =~ /Cert success./) {
        &debug_msg("Certificate request successful!\n");
        &debug_msg("INFO: Certificate request successful!\n");
    }
    else {
        &clean_well_known;
        &deal_with_services;
        &debug_msg("WARNING: Error during SSL cert request!\n");
        $CertFail = '1';
        $FailMsg = "[[base-ssl.LE_CA_Request_Error]]";
    }

    if ($CertFail gt '0') {
        &debug_msg("WARNING: CertFail: $CertFail - NO VALID CERT WAS GENERATED!!\n");
        $FailMsg = "[[base-ssl.LE_CA_Request_Error]]";
    }
    else {
        &debug_msg("INFO: CertFail: $CertFail - Looks like a valid cert was generated.\n");
        $FailMsg = '';

       &debug_msg("Checking cert dir: $cert_dir\n");
       if ((-d $cert_dir) && ($cert_dir ne "")) {
           # Check if we have a good cert:
           ($subject, $issuer, $expires) = ssl_get_cert_info($cert_dir);

           &debug_msg("Cert info (subject): "  . Dumper($subject) . "\n");
           &debug_msg("Cert info (Issuer): " . Dumper($issuer) . "\n");
           &debug_msg("Cert info (expires): $expires\n");

           # Make sure this is really a Let's Encrypt cert:
           $uses_letsencrypt = '0';
           if (($issuer->{'O'} eq 'Let\'s Encrypt') || ($issuer->{'CN'} eq 'Fake LE Intermediate X1') || ($expires ne "")) {
               # Note: Recently we're getting an empty issuer. So we let it pass if we have an expiry date.
               &debug_msg("SSL issuer: Let's Encrypt\n");
               $uses_letsencrypt = '1';
           }

           if (($expires ne "") && ($uses_letsencrypt eq "1")) {
               # Munge date because they changed the strtotime function in php:
               $expires =~ s/(\d{1,2}:\d{2}:\d{2})(\s+)(\d{4,})/$3$2$1/;
               &debug_msg("expires: $expires\n");

               # Update CODB to activate the whole shebang:
               $cce->set($vsite->{'OID'}, 'SSL', { 'uses_letsencrypt' => $uses_letsencrypt, 'country' => 'US', 'state' => 'Other', 'expires' => $expires, 'enabled' => '1', 'email' => $ssl_info->{'LEemail'}, 'orgName' => "Let's Encrypt", 'ACME' => '1', 'caCerts' => '&LetsEncrypt&', 'LEcreationDate' => time() });
           }
           else {
               # Turn off the 'uses_letsencrypt' flag and fail:
               $cce->set($vsite->{'OID'}, 'SSL', { 'uses_letsencrypt' => $uses_letsencrypt });
               &clean_well_known;
               &deal_with_services;
               &debug_msg("Did not get a valid certificate back!\n");
               $CertFail = '1';
               $FailMsg = "[[base-ssl.doNotHaveValidLECert]]";
           }
       }
    }

    ### Start: To CCE ####
    $Z = '0';
    $cleanedResult = '';
    @ResArray = split("\n",$result);
    foreach my $x (@ResArray) {
        if (($x =~ /^(.*)_(.*)$/) || ($x =~ /(.*)estore(.*)$/) || ($x =~ /^(.*)\] httpd(.*)$/) || ($x =~ /(.*)httpd\.conf(.*)$/)) {
            next;
        }

        # Only extract lines with "error:" and clean them up
        if ($x =~ /^\[.*?\]\s+\S+:\s*(.*error:.*)$/i) {
            $cleanedResult .= "$1\n";  # Just the error message part
        }

        if ($x =~ /(.*)Please check log file for more details(.*)$/) {
            $Z = '1';
        }
    }

    # Default return structure
    my %TheResult = (
        'Status' => $CertFail,
        'Error'  => $FailMsg,
        'ErrMsg' => '',
    );

    # Tempfile cleanup:
    system("rm -f /tmp/LElog*.log");

    # Write $cleanedResult into a tempfile and then populate $cleanedResult with the filename instead of writing a large blob to CODB:
    if ($cleanedResult ne '') {
        use File::Temp qw/ tempfile /;
        my ($fh, $filename) = tempfile('LElogXXXXXX', DIR => '/tmp', SUFFIX => '.log', UNLINK => 0);
        print $fh $cleanedResult;
        close($fh);

        # Set permissions for AdmServ GUI access
        system("chown admserv:admserv $filename");
        system("chmod 0600 $filename");

        # Store only filename in CODB
        $TheResult{'ErrMsg'} = $filename;
    }

    $json_result = encode_json(\%TheResult);
    $cce->set($vsite->{'OID'}, 'SSL', { 'LEclientRet' => $json_result });

    ### End: To CCE ####

    ### Turn 'webAliasRedirects' and 'Redirect/Proxy Website' back on if they were enabled before:
    if ($vsite->{'CLASS'} eq "Vsite") {
        $need_httpd_restart = '0';
        if ($original_webAliasRedirects eq '1') {
            &debug_msg("Web-Aliases: Turning 'webAliasRedirects' back on as it was on before.\n");
            $cce->set($vsite->{'OID'}, '', { 'webAliasRedirects' => '1' });
            $need_httpd_restart++;
        }
        if ($original_webRedirect_enabled eq '1') {
            &debug_msg("'Redirect/Proxy Website': Turning 'Redirect/Proxy Website' back on as it was on before.\n");
            $cce->set($vsite->{'OID'}, 'REDIRECT', { 'enabled' => '1' });
            $need_httpd_restart++;
        }

        # Restart Apache if required due to changes in configuration:
        if ($need_httpd_restart gt '0') {
            $cce->set($vsite->{'OID'}, '', { 'force_update' => time() });
        }
    }
    ### End: Turn 'webAliasRedirects' and 'Redirect/Proxy Website' back on if they were enabled before
}

# Cleanup:
&deal_with_services;
&clean_well_known;

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

sub deal_with_services {
    if ($vsite->{'CLASS'} eq "System") {
        # Reload admserv:
        &debug_msg("Reloading Admserv\n");
        service_run_init('admserv', 'reload');
    }
}

sub clean_well_known {
    &debug_msg("Request logged to delete $well_known_location\n");
    if (-d "$well_known_location") {
        if (($well_known_location ne '') || ($well_known_location ne '/')) {
            &debug_msg("Deleting $well_known_location\n");
            system("rm -Rf $well_known_location");
        }
        else {
            &debug_msg("Not deleting $well_known_location!\n");
        }
    }
    else {
        &debug_msg("Directory $well_known_location not present. Good.\n");
    }
}

sub check_siteadmin_quota {

    # Note:
    #
    # Object 'User' has NameSpace 'Disk'. Which has a 'used' key. That ought to show the used disk space.
    # However: Since ages Swatch's am_disk.pl no longer updates it, as that would lead to excessive CODB
    # usage on each Swatch run. That info is not relevant to us anyway, as it is only used in the GUI, which
    # fetches it via /usr/sausalito/sbin/get_quotas.pl on an as needed basis.
    #
    # However, here we need it. Hence we run /usr/sausalito/sbin/get_quotas.pl and grep out the line with
    # the username in question and then extrapolate how much free space he has.

    my ($username) = @_;
    my $quota_script = '/usr/sausalito/sbin/get_quotas.pl';

    # Run the quota script and grep for the specific username
    my $result = qx($quota_script | grep -w '^$username');
    chomp $result;

    # Check if we got a valid line
    if ($result) {
        # Split the result into columns
        my ($user, $used_quota, $max_quota) = split(/\s+/, $result);

        # Calculate remaining quota
        my $remaining_quota = $max_quota - $used_quota;

        &debug_msg("check_siteadmin_quota(): Remaining quota for User $username: $remaining_quota\n");

        # Return TRUE if remaining quota is greater than 1000
        return $remaining_quota > 1000 ? 1 : 0;
    }
    else {
        #warn "User $username not found in quota output.";
        &debug_msg("check_siteadmin_quota(): Quota for User $username: Unable to determine - proceeding\n");
        return 1; # Return TRUE even though the user was NOT found in the quota-check
    }
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
