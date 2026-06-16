#!/usr/bin/perl -I/usr/sausalito/perl
# Initial Author: Brian N. Smith
# $Id: subdomain-new.pl

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debugging enabled for virtual_host.pl\n");
}

use CCE;
use Sauce::Util;
use Switch;
use Sauce::Service;

umask(002);

$cce = new CCE;
$cce->connectfd();

$oid = $cce->event_oid();
($ok, $subdomain) = $cce->get($oid);

&debug_msg("Event OID is: " . $oid . " of type " . $subdomain->{'CLASS'} . "\n");
if ($subdomain->{'CLASS'} ne 'Subdomains') {
    $cce->bye('SUCCESS');
    exit(0);
}

($soid) = $cce->find('System');
($ok, $obj) = $cce->get($soid);

# Get "System" . "Web":
my ($oid) = $cce->find('System');
my ($ok, $System) = $cce->get($oid, '');
($ok, $objWeb) = $cce->get($soid, 'Web');
my ($ok, $Nginx) = $cce->get($soid, 'Nginx');
my ($ok, $tzdata) = $cce->get($soid, "Time");
my $timezone = $tzdata->{'timeZone'};

my $php_fpm_socket_path = '';

# HTTP and SSL ports:
$httpPort = "80";
if ($objWeb->{'httpPort'}) {
    $httpPort = $objWeb->{'httpPort'};
}
$sslPort = "443";
if ($objWeb->{'sslPort'}) {
    $sslPort = $objWeb->{'sslPort'};
}

$HSTS = '0';
$HSTS_head = "";
$HSTS_tail = "";
$HSTS_line = '';
if ($objWeb->{'HSTS'} == '1') {
    $HSTS = $objWeb->{'HSTS'};
    $HSTS_head = "Header always set Strict-Transport-Security";
    $HSTS_tail = "max-age=15768000";
    $HSTS_line = $HSTS_head . ' "' . $HSTS_tail .';"';
}

## Lets search existing Subdomains to verify this is unquie.
@oids = $cce->find('Subdomains', { 'group' => $subdomain->{'group'},  'hostname' => $subdomain->{'hostname'},  'domainname' => $subdomain->{'domainname'}});
($ok, $sd) = $cce->get($oids[0]);
$size = @oids;
if ( $size > 1 ) {
  ## Duplicate Entry
  $cce->warn('[[base-subdomains.duplicateEntry]]');
  $cce->bye('FAIL');
  exit(1);
}

$master_config = "/etc/httpd/conf.d/subdomains.conf";
if ( ! -e $master_config ) {
  open(OUT, ">$master_config");
  print OUT "IncludeOptional /etc/httpd/conf.d/subdomains/*.conf";
  close(OUT);
}

$subdomain_config_dir = "/etc/httpd/conf.d/subdomains";
if ( ! -e $subdomain_config_dir ) {
  mkdir($subdomain_config_dir, 0755);
  Sauce::Util::chmodfile(02775, "$subdomain_config_dir");
}

@oids = $cce->find('Vsite', { 'name' => $subdomain->{'group'} });
($ok, $vsite) = $cce->get($oids[0]);
($ok, $vsite_php) = $cce->get($oids[0], 'PHP');
($ok, $PHP_Vsite) = $cce->get($oids[0], "PHPVsite");
($ok, $vsite_ssl) = $cce->get($oids[0], 'SSL');
($ok, $vsite_subdomains) = $cce->get($oids[0], 'subdomains');
($ok, $vsite_disk) = $cce->get($oids[0], "Disk");
$vsiteOID = $oids[0];
$prefered_siteAdmin = $vsite_php->{prefered_siteAdmin};
if ($prefered_siteAdmin eq "") {
    $prefered_siteAdmin = 'apache';
}

&debug_msg("subdomain->{'isUser'}: " . $subdomain->{'isUser'} . "\n");
&debug_msg("sd->{'isUser'}: " . $sd->{'isUser'} . "\n");


if ($subdomain->{'isUser'} == "1") {
    $prefered_siteAdmin = $subdomain->{'hostname'};
}

&debug_msg("prefered_siteAdmin: " . $prefered_siteAdmin . "\n");

$group = $vsite->{name};

$web_dir = $subdomain->{'webpath'};
if ( ! -e $web_dir ) {
  system("/bin/mkdir -p -m 775 $web_dir");
  Sauce::Util::chmodfile(02775, "$web_dir");
  system("/bin/cp -R /etc/skel/vsite/en/web/* $web_dir");
  Sauce::Util::chmodfile(02775, "$web_dir/error");
  Sauce::Util::chmodfile(0664, "$web_dir/index.html");
}
if (-d $web_dir ) {
    system("/bin/chown -R $prefered_siteAdmin:$group $web_dir");
}

$index_file = $web_dir . "/index.html";
$index_file_php = $web_dir . "/index.php";
$ipadd = $vsite->{'ipaddr'};
if ($subdomain->{'domainname'} ne '') {
    $thatDomain = $subdomain->{'domainname'};
}
else {
    $thatDomain = $vsite->{'domain'};
}
if ($subdomain->{'hostname'} ne '') {
    $fqdn = $subdomain->{'hostname'} . "." . $thatDomain;
}
else {
    $fqdn = $thatDomain;
}

$subdomain_config_file = $subdomain_config_dir . "/" . $subdomain->{'group'} . "-" . $fqdn . ".conf";

my @services = ("PHP", "SSI", "CGI");
foreach $service (@services) {
  ($ok, $$service) = $cce->get($vsiteOID, $service);
  switch ($service) {
    case "PHP" {
          if ( $$service->{'enabled'} ) {

            # Get Object PHP from CODB to find out which PHP version we use:
            @sysoids = $cce->find('PHP');
            ($ok, $mySystem) = $cce->get($sysoids[0]);
            $platform = $mySystem->{'PHP_version'};
            if ($platform >= "5.3") {
                # More modern PHP found:
                $legacy_php = "0";
            }
            else {
                # Older PHP found:
                $legacy_php = "1";
            }

            # Get PHP:
            $vgroup = $subdomain->{'group'};
            @vsiteoid = $cce->find('Vsite', { 'name' => $vgroup });
            ($ok, $vsite_php) = $cce->get($vsiteoid[0], "PHP");

            # Get PHPVsite:
            ($ok, $vsite_php_settings) = $cce->get($vsiteoid[0], "PHPVsite");

            $serviceCFG .= "# created by subdomain-new.pl\n";

            # Handle FPM/FastCGI:
            if ($$service->{fpm_enabled}) {

                &debug_msg("subdomain->{'isUser'}: " . $subdomain->{'isUser'} . "\n");

                $php_fpm_socket_path = $vsite->{'basedir'} . '/wwwroot/' . $vsite->{"name"} . '.sock';
                &debug_msg("php_fpm_socket_path: $php_fpm_socket_path\n");

                # Use the new method that uses proxy and Sethandler in a way that .htaccess auth still work:
                $serviceCFG .= "<Proxy \"unix:" . $php_fpm_socket_path . "|fcgi://localhost\" retry=0>\n";
                $serviceCFG .= "    ProxySet connectiontimeout=5 timeout=7200\n";
                $serviceCFG .= "</Proxy>\n";
                $serviceCFG .= "<If \"\%{REQUEST_FILENAME} =~ /\\.php\$/ && -f \%{REQUEST_FILENAME}\">\n";
                $serviceCFG .= "    SetEnvIfNoCase ^Authorization\$ \"(.+)\" HTTP_AUTHORIZATION=\$1\n";
                $serviceCFG .= "    Sethandler proxy:unix:" . $php_fpm_socket_path . "|fcgi://localhost\n";
                $serviceCFG .= "</If>\n";
            }
            # Default to suPHP:
            else { 
                # Handle suPHP:
                $serviceCFG .= "#<IfModule mod_suphp.c>\n";
                $serviceCFG .= "    suPHP_Engine on\n";
                $serviceCFG .= "    suPHP_UserGroup $prefered_siteAdmin $subdomain->{'group'}\n";
                $serviceCFG .= "    AddType application/x-httpd-suphp .php\n";
                $serviceCFG .= "    AddHandler x-httpd-suphp .php .php5 .php4 .php3 .phtml\n";
                $serviceCFG .= "    suPHP_AddHandler x-httpd-suphp\n";
                $serviceCFG .= "    suPHP_ConfigPath $Vsite->{'basedir'}/\n";
                $serviceCFG .= "#</IfModule>\n";
            }

            if ($System->{'productBuild'} eq "5210R") {

                # Making sure 'safe_mode_include_dir' has the bare minimum defaults:
                @smi_temporary = split(":", $vsite_php_settings->{"safe_mode_include_dir"});
                @smi_baremetal_minimums = ('/usr/sausalito/configs/php/', '.');
                @smi_temp_joined = (@smi_temporary, @smi_baremetal_minimums);
                 
                # Remove duplicates:
                foreach my $var ( @smi_temp_joined ) {
                    if ( ! grep( /$var/, @safe_mode_include_dir ) ){
                        push(@safe_mode_include_dir, $var );
                    }
                }
                $vsite_php_settings->{"safe_mode_include_dir"} = join(":", @safe_mode_include_dir);
                
                # Making sure 'safe_mode_allowed_env_vars' has the bare minimum defaults:
                @smaev_temporary = split(",", $vsite_php_settings->{"safe_mode_allowed_env_vars"});
                @smi_baremetal_minimums = ('PHP_','_HTTP_HOST','_SCRIPT_NAME','_SCRIPT_FILENAME','_DOCUMENT_ROOT','_REMOTE_ADDR','_SOWNER');
                @smaev_temp_joined = (@smaev_temporary, @smi_baremetal_minimums);
                    
                # Remove duplicates:
                foreach my $var ( @smaev_temp_joined ){
                    if ( ! grep( /$var/, @safe_mode_allowed_env_vars ) ){
                        push(@safe_mode_allowed_env_vars, $var );
                    } 
                }
                $vsite_php_settings->{"safe_mode_allowed_env_vars"} = join(",", @safe_mode_allowed_env_vars);

                # OpenBaseDir Fix:
                my $mySystem = do {
                    my @sysoids = $cce->find('PHP');
                    my ($ok, $object) = $cce->get($sysoids[0]);
                    die unless $ok;
                    $object;
                };

                my @vsite_php_settings_temporary   = split(":", $vsite_php_settings->{"open_basedir"});
                my @my_server_php_settings_temp    = split(":", $mySystem->{'open_basedir'});
                my @vsite_php_settings_temp_joined = (@vsite_php_settings_temporary, @my_server_php_settings_temp);

                # For debugging:
                &debug_msg("mySystem settings for 'open_basedir': " . $mySystem->{'open_basedir'} . "\n");
                &debug_msg("vsite_php_settings for 'open_basedir' : " . $vsite_php_settings->{"open_basedir"} . "\n");

                my %obd_helper                     = map { $_ => 1 } @vsite_php_settings_temp_joined;
                my @vsite_php_settings_temp        = keys %obd_helper;
                $vsite_php_settings->{"open_basedir"} = join ":", @vsite_php_settings_temp;
                &debug_msg("Final for 'open_basedir' : " . $vsite_php_settings->{'open_basedir'} . "\n");

                # Make sure that the path to the prepend file directory is allowed, too:
                unless ($vsite_php_settings->{"open_basedir"} =~ m/\/usr\/sausalito\/configs\/php\//) {
                    $vsite_php_settings->{"open_basedir"} .= $vsite_php_settings->{"open_basedir"} . ':/usr/sausalito/configs/php/';
                }

                if ($vsite_php_settings->{"allow_open_basedir_off"} eq '1') {
                    $vsite_php_settings->{"open_basedir"} = 'none';
                    &debug_msg("Override vsite_php_settings for 'open_basedir' : " . $vsite_php_settings->{"open_basedir"} . "\n");
                }

                if ($legacy_php == "1") {
                    # These options only apply to PHP versions prior to PHP-5.3:
                    if ($vsite_php_settings->{"safe_mode"} ne "") {
                        $serviceCFG .= 'php_admin_flag safe_mode ' . $vsite_php_settings->{"safe_mode"} . "\n";
                    }
                    if ($vsite_php_settings->{"safe_mode_gid"} ne "") {
                        $serviceCFG .= 'php_admin_flag safe_mode_gid ' . $vsite_php_settings->{"safe_mode_gid"} . "\n";
                    }
                    if ($vsite_php_settings->{"safe_mode_allowed_env_vars"} ne "") {
                        $serviceCFG .= 'php_admin_value safe_mode_allowed_env_vars ' . $vsite_php_settings->{"safe_mode_allowed_env_vars"} . "\n";
                    }
                    if ($vsite_php_settings->{"safe_mode_exec_dir"} ne "") {
                        $serviceCFG .= 'php_admin_value safe_mode_exec_dir ' . $vsite_php_settings->{"safe_mode_exec_dir"} . "\n";
                    }
                    if ($vsite_php_settings->{"safe_mode_include_dir"} ne "") {
                        $serviceCFG .= 'php_admin_value safe_mode_include_dir ' . $vsite_php_settings->{"safe_mode_include_dir"} . "\n";
                    }
                    if ($vsite_php_settings->{"safe_mode_protected_env_vars"} ne "") {
                        $serviceCFG .= 'php_admin_value safe_mode_protected_env_vars ' . $vsite_php_settings->{"safe_mode_protected_env_vars"} . "\n";
                    }
                }

                # Set 'date.timezone':
                #$serviceCFG .= 'php_admin_value date.timezone ' . $timezone . "\n";

                if ($vsite_php_settings->{"register_globals"} ne "") {
                    $serviceCFG .= 'php_admin_flag register_globals ' . $vsite_php_settings->{"register_globals"} . "\n";
                }
                if ($vsite_php_settings->{"allow_url_fopen"} ne "") {
                    $serviceCFG .= 'php_admin_flag allow_url_fopen ' . $vsite_php_settings->{"allow_url_fopen"} . "\n";
                }
                if ($vsite_php_settings->{"allow_url_include"} ne "") {
                    $serviceCFG .= 'php_admin_flag allow_url_include ' . $vsite_php_settings->{"allow_url_include"} . "\n";
                }

                # We need to remove any site path references from open_basedir, because they could be from the wrong site,
                # like during a cmuImport, when it inherited the path it had on the server it was exported from.

                @vsite_php_settings_temp = split(":", $vsite_php_settings->{"open_basedir"});
                foreach $entry (@vsite_php_settings_temp) {
                    #system("echo $entry >> /tmp/debug.ms");
                    $entry =~ s/\/home\/.sites\/(.*)\/(.*)\///;
                    if ($entry ne "") {
                        push(@vsite_php_settings_new, $entry);
                    }
                }
                if ($vsite_php_settings->{"open_basedir"} ne "") {
                    $vsite_php_settings->{"open_basedir"} = join(":", @vsite_php_settings_new);
                }
                # Decision if we write 'open_basedir' to the site include file or not. We do NOT
                # write an empty open_basedir. So if it is empty, we simply skip this step:

                if ($vsite_php_settings->{"open_basedir"} eq 'none') {
                    # Vsite is allowed to use 'open_basedir' = 'none';
                    $serviceCFG .= 'php_admin_value open_basedir ' . $vsite_php_settings->{"open_basedir"} . "\n";
                }
                else {
                    # Assemble the whole 'open_basedir' string and write it out:
                    # Decide if we need to add the sites homedir to open_basedir or not:
                    if ($vsite_php_settings->{"open_basedir"} =~ m/$vsite->{"basedir"}\//) {
                        # If the site's basedir path is already present, we use whatever paths open_basedir currently has:
                        $serviceCFG .= 'php_admin_value open_basedir ' . $vsite_php_settings->{"open_basedir"} . "\n";
                    }
                    else {
                        # If the sites path to it's homedir is missing, we add it here:
                        $serviceCFG .= 'php_admin_value open_basedir ' . $vsite_php_settings->{"open_basedir"} . ':' . $vsite->{"basedir"} . '/' . "\n";
                    }
                }

                if ($vsite_php_settings->{"post_max_size"} ne "") {
                    $serviceCFG .= 'php_admin_value post_max_size ' . $vsite_php_settings->{"post_max_size"} . "\n";
                }
                if ($vsite_php_settings->{"upload_max_filesize"} ne "") {
                    $serviceCFG .= 'php_admin_value upload_max_filesize ' . $vsite_php_settings->{"upload_max_filesize"} . "\n";
                }
                if ($vsite_php_settings->{"max_execution_time"} ne "") {
                    $serviceCFG .= 'php_admin_value max_execution_time ' . $vsite_php_settings->{"max_execution_time"} . "\n";
                }
                if ($vsite_php_settings->{"max_input_time"} ne "") {
                    $serviceCFG .= 'php_admin_value max_input_time ' . $vsite_php_settings->{"max_input_time"} . "\n";
                }
                if ($vsite_php_settings->{"memory_limit"} ne "") {
                    $serviceCFG .= 'php_admin_value memory_limit ' . $vsite_php_settings->{"memory_limit"} . "\n";
                }

                # Email related:
                $serviceCFG .= 'php_admin_flag mail.add_x_header On' . "\n";
                $serviceCFG .= 'php_admin_value sendmail_path /usr/sausalito/sbin/phpsendmail' . "\n";
                $serviceCFG .= 'php_admin_value auto_prepend_file /usr/sausalito/configs/php/set_php_headers.php' . "\n";

                #
                ### Handle 'vsite_over_quota' situation:
                #
                if ($vsite_disk->{vsite_over_quota} eq "1") {
                    $serviceCFG .= 'php_admin_value disable_functions "exec,system,passthru,shell_exec,popen,escapeshellcmd,proc_open,proc_nice,ini_restore,fwrite,file_put_contents,chmod,chown,mkdir,copy"' . "\n";
                    $serviceCFG .= 'php_admin_value disable_classes "SplFileObject"' . "\n";
                }
            }
        }
    }

    case "CGI" {
      if ( $$service->{'enabled'} ) {
        if (-f "/usr/bin/systemctl") {
            # 5209R and therefore Apache 2.4:
            # Note: This doesn't use CGI-Wrapper, as I can't get that to work in subdomains.
            $serviceCFG .= "  <Directory $web_dir>\n";
            $serviceCFG .= "      AddHandler cgi-script .cgi .pl\n";
            $serviceCFG .= "      Options +ExecCGI\n";
            $serviceCFG .= "  </Directory>\n";
        }
        else {
            $serviceCFG .= "  AddHandler cgi-wrapper .pl\n";
            $serviceCFG .= "  AddHandler cgi-wrapper .cgi\n";
            $serviceCFG .= "  ScriptAlias /cgi-bin/ /usr/local/blueonyx/cgiwrap/cgiwrap/\n";
            $serviceCFG .= "  Action cgi-wrapper /cgi-bin\n";
        }
      }
    }

    case "SSI" {
      if ( $$service->{'enabled'} ) {
        $serviceCFG .= "  AddHandler server-parsed .shtml\n";
        $serviceCFG .= "  AddType text/html .shtml\n";
      }
    }
  }
}

# Assemble VirtualHost HTTP/HTTPS IP Address lines and IP related Rewrite-Conditions:
my $http_ipline = '';
my $https_ipline = '';
my $ip_rewrite_cond_http = '';
my $ip_rewrite_cond_https = '';
if (($vsite->{ipaddr} ne "") && ($vsite->{ipaddrIPv6} ne "")) {
    # Dual stack:
    $http_ipline = $vsite->{ipaddr} . ':' . $httpPort . ' [' . $vsite->{ipaddrIPv6} . ']:' . $httpPort;
    $https_ipline = $vsite->{ipaddr} . ':' . $sslPort . ' [' . $vsite->{ipaddrIPv6} . ']:' . $sslPort;
    $ip_rewrite_cond_http .= 'RewriteCond %{HTTP_HOST}                !^' . $vsite->{ipaddr} . '(:' . $httpPort . ')?$' . "\n";
    $ip_rewrite_cond_http .= 'RewriteCond %{HTTP_HOST}                !^\[' . $vsite->{ipaddrIPv6} . '\](:' . $httpPort . ')?$';

    $ip_rewrite_cond_https .= 'RewriteCond %{HTTP_HOST}                !^' . $vsite->{ipaddr} . '(:' . $sslPort . ')?$' . "\n";
    $ip_rewrite_cond_https .= 'RewriteCond %{HTTP_HOST}                !^\[' . $vsite->{ipaddrIPv6} . '\](:' . $sslPort . ')?$';
}
elsif (($vsite->{ipaddr} eq "") && ($vsite->{ipaddrIPv6} ne "")) {
    # IPv6 only:
    $http_ipline = '[' . $vsite->{ipaddrIPv6} . ']:' . $httpPort;
    $https_ipline = '[' . $vsite->{ipaddrIPv6} . ']:' . $sslPort;
    $ip_rewrite_cond_http .= 'RewriteCond %{HTTP_HOST}                !^\[' . $vsite->{ipaddrIPv6} . '\](:' . $httpPort . ')?$';
    $ip_rewrite_cond_https .= 'RewriteCond %{HTTP_HOST}                !^\[' . $vsite->{ipaddrIPv6} . '\](:' . $sslPort . ')?$';
}
else {
    # IPv4 only (default):
    $http_ipline = $vsite->{ipaddr} . ':' . $httpPort;
    $https_ipline = $vsite->{ipaddr} . ':' . $sslPort;
    $ip_rewrite_cond_http .= 'RewriteCond %{HTTP_HOST}                !^' . $vsite->{ipaddr} . '(:' . $httpPort . ')?$';
    $ip_rewrite_cond_https .= 'RewriteCond %{HTTP_HOST}                !^' . $vsite->{ipaddr} . '(:' . $sslPort . ')?$';
}

$site_config = "#NameVirtualHost $ipadd:$httpPort
ServerRoot /etc/httpd

<VirtualHost $http_ipline>
  ServerName  $fqdn
  Protocols h2 http/1.1
  ServerAdmin admin
  DocumentRoot $web_dir
  # BEGIN WebScripting SECTION.  DO NOT EDIT MARKS OR IN BETWEEN.
$serviceCFG
  # END WebScripting SECTION.  DO NOT EDIT MARKS OR IN BETWEEN.
</VirtualHost>";

#### HTTPS part start

$ssl_conf = "\n";

# write SSL config
my $cafile;
&debug_msg("SSL <VirtualHost>: \$vsite_ssl->{enabled}: $vsite_ssl->{enabled} - \$vsite_ssl->{expires}: $vsite_ssl->{expires} - vsite_subdomains->{sub_ssl}: $vsite_subdomains->{sub_ssl}\n");
if (($vsite_ssl->{enabled} eq "1") && (-f "$vsite->{basedir}/wwwroot/certs/certificate") && (-f "$vsite->{basedir}/wwwroot/certs/key") && ($Nginx->{enabled} ne '1') && ($vsite_subdomains->{sub_ssl} eq "1")) {
    &debug_msg("SSL <VirtualHost>: Condition #1: TRUE\n");
    if (-f "$vsite->{basedir}/wwwroot/certs/ca-certs") {
        $cafile = "SSLCACertificateFile $vsite->{basedir}/wwwroot/certs/ca-certs";
        &debug_msg("SSL <VirtualHost>: Condition #2: TRUE\n");
    }

    $ssl_conf .=<<END;

#NameVirtualHost $https_ipline
<VirtualHost $https_ipline>
Protocols h2 http/1.1
SSLengine on
SSLCompression off
SSLProtocol -all +TLSv1.3 +TLSv1.2
SSLHonorCipherOrder On
SSLCipherSuite    TLSv1.3   TLS_CHACHA20_POLY1305_SHA256:TLS_AES_256_GCM_SHA384:TLS_AES_128_GCM_SHA256
SSLCipherSuite    SSL       ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:EECDH+AESGCM:EDH+AESGCM
SSLOpenSSLConfCmd Curves    X25519:secp521r1:secp384r1:prime256v1
$HSTS_line
$cafile
SSLCertificateFile $vsite->{basedir}/wwwroot/certs/certificate
SSLCertificateKeyFile $vsite->{basedir}/wwwroot/certs/key
ServerName $fqdn
ServerAdmin admin
DocumentRoot $web_dir
  # BEGIN WebScripting SECTION.  DO NOT EDIT MARKS OR IN BETWEEN.
$serviceCFG
  # END WebScripting SECTION.  DO NOT EDIT MARKS OR IN BETWEEN.
</VirtualHost>
END
}

$site_config .= $ssl_conf;

#### HTTPS part end

open(FO, ">$subdomain_config_file");
print FO $site_config;
close(FO);
Sauce::Util::chmodfile(00544, "$subdomain_config_file");

$index = "";
if ((! -f $index_file) && (! -f $index_file_php)) {
    &debug_msg("Creating Subdomain's $index_file\n");

    system("/bin/cp /etc/skel/vsite/en/web/index.html $web_dir/index.html");
    Sauce::Util::chmodfile(0664, "$web_dir/index.html");
    system("/bin/chown $prefered_siteAdmin:$group $web_dir/index.html");

    open(FI, "<$index_file");
    while ( <FI> ) {
        chomp;
        s/\[DOMAIN\]/$fqdn/;
        $index .= $_ . "\n";
    }
    close(FI);

    open(FO,">$index_file");
    print FO $index;
    close(FO);
}
else {
    &debug_msg("Subdomain's index file already present. Skipping creation of it.\n");
}

##### Start Handle Nginx

#
### Handle Nginx SSL-Proxy Vhost config files:
#

$nginx_vhosts_file = '/etc/nginx/vsites/' . $subdomain->{'group'} . "-" . $fqdn;

$server_name = $vsite->{fqdn};
if ($nginx_ServerAlias ne '') {
    $server_name .= ' ' . $nginx_ServerAlias;
    chomp($server_name);
}

my $cafile;
&debug_msg("SSL <Nginx-Vhost>: \$vsite_ssl->{enabled}: $vsite_ssl->{enabled} - \$vsite_ssl->{expires}: $vsite_ssl->{expires}\n");
if (($vsite_ssl->{enabled} eq "1") && (-f "$vsite->{basedir}/wwwroot/certs/certificate") && (-f "$vsite->{basedir}/wwwroot/certs/key") && ($vsite_subdomains->{sub_ssl} eq "1")) {
    &debug_msg("SSL <Nginx-Vhost>: Condition #1: TRUE\n");

    $combined_cert = $vsite->{basedir} . '/wwwroot/certs/nginx_cert_ca_combined';
    $the_ca_cert = $vsite->{basedir} . '/wwwroot/certs/ca-certs';
    $the_cert = $vsite->{basedir} . '/wwwroot/certs/certificate';
    $the_blank = $vsite->{basedir} . '/wwwroot/certs/blank.txt';

    if (! -f $the_blank) {
        system("echo \"\" > $the_blank");
        system("chmod 640 $the_blank");
    }

    if ((-f $the_ca_cert) && (-f $the_cert)) {
        system("cat $the_cert $the_blank $the_ca_cert > $combined_cert");
        system("chmod 640 $combined_cert");
    }
    elsif ((! -f $the_ca_cert) && (-f $the_cert)) {
        # We have no intermediate.
        system("cat $the_cert > $combined_cert");
        system("chmod 640 $combined_cert");
    }
    if (! -f $combined_cert) {
        # If we still have noting, we go bare:
        system("touch $combined_cert");
        system("chmod 640 $combined_cert");
    }

    #
    ### Start: Nginx Vsite HSTS:
    #

    $includeSubDomains = '';
    if ($NginxVsite->{include_subdomains} eq "1") {
        $includeSubDomains = ' includeSubDomains';
    }
    
    if ($NginxVsite->{HSTS} eq "1") {
        $Nginx_HSTS = 'add_header Strict-Transport-Security "max-age=' . $NginxVsite->{max_age} . ';' . $includeSubDomains . '" always;' . "\n";
        $Nginx_HSTS .= '      include /etc/nginx/headers.d/security.conf;' . "\n";
    }
    else {
        $Nginx_HSTS = '';
    }

    #
    ### End: Nginx Vsite HSTS:
    #

    $nginx_vhost_conf .=<<END;
# Do NOT edit this file. The GUI will replace this file on edits.
server {

    listen              [::]:443 ssl http2;
    listen              443 ssl http2;
    server_name         $fqdn;

    include /etc/nginx/headers.d/*.conf;

    ssl_certificate         $combined_cert;
    ssl_certificate_key     $vsite->{basedir}/wwwroot/certs/key;
    ssl_trusted_certificate $combined_cert;

    # Insert external protocols and chiffres for SSL:
    include /etc/nginx/ssl_proto_chiffres.conf;

    # Insert external SSL Session cfg, resolver and OSCP-Stapling:
    include /etc/nginx/ssl_defaults.conf;

    $logging_line_nginx

    error_page 502 /502-bad-gateway.html;
    location = /502-bad-gateway.html {
        internal;
        root  /var/www/html/error/;
    }

    # Special provisions for /libImage/ for error page gfx:
    location ~ ^/libImage/*.*\$ {
        root   /usr/sausalito/ui/web/;
    }

    location / {
      $Nginx_HSTS
      proxy_http_version   1.1;
      proxy_set_header     Connection "";
      proxy_set_header     Host \$host;
      proxy_set_header     X-Real-IP \$remote_addr;
      proxy_set_header     X-Forwarded-For \$proxy_add_x_forwarded_for;
      proxy_set_header     X-Forwarded-Proto \$scheme;
      proxy_pass           http://$fqdn:80/;
      proxy_read_timeout   90;

      client_max_body_size $PHP_Vsite->{"upload_max_filesize"};

    }
}
END

    # Edit the Nginx-Vhost file for subdomain:
    if(!Sauce::Util::editfile($nginx_vhosts_file, *nginx_printer)) {
        &debug_msg("Failed to edit $nginx_vhosts_file through subdomain-new.pl \n");
        $cce->bye('FAIL', '[[base-nginx.cantEditCfg]]');
        exit(1);
    }
    else {
        &debug_msg("Editing $nginx_vhosts_file through subdomain-new.pl - DONE \n");
        system("/usr/bin/chmod 644 $nginx_vhosts_file");
    }
}
else {
    # Delete /etc/nginx/vsites/<vsite-subdomain>
    if (-f $nginx_vhosts_file) {
        &debug_msg("Deleting $nginx_vhosts_file as SSL is either turned off or the certificate files are missing.\n");
        system("rm -f $nginx_vhosts_file");
    }
}
##### Stop: Handle Nginx

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub nginx_printer {
    ($in, $out) = @_;
    print $out $nginx_vhost_conf;
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
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2008 NuOnce Networks, Inc.
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