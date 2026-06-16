<?php 
namespace Wizard\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("ServerScriptHelper.php");
use I18n;
use ServerScriptHelper;

//class Vsite extends Controller
class WizardElmer extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {

    }

    /**
     * Index Page for the web based Setup-Wizard.
     *
     * NOTE: This page doesn't follow the usual semantics that we use for the
     * rest of the GUI. You HAVE to be REALLY careful with $CI->cceClient, or you
     * leave a lot of unneeded /usr/sausalito/sbin/cced childs around.
     * So be REALLY sure to close all of them that you don't need. 
     * And there is a REASON why we use $CI->cceClient->bye(); so often in here!
     */

    public function wizard_reload() {

        // Start with blank debug info:
        $debug = "";

        $CI =& get_instance();

        // locale and charset setup:
        $ini_langs = initialize_languages(FALSE);
        $locale = $ini_langs['locale'];
        $localization = $ini_langs['localization'];
        $charset = $ini_langs['charset'];

        $domain = 'base-wizard';

        // Set headers:
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Cache-Control', 'post-check=0, pre-check=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Content-language', $locale);
        $this->response->setHeader('Content-type', "text/html; charset=$charset");

        $title = PoorMansBabelFish("wizard_refresh_header", $locale, $domain);
        $text = PoorMansBabelFish("wizard_refresh_text", $locale, $domain);

        // Prepare page:
        $page_variables = array(
                                'localization' => $localization,
                                'charset' => $charset,
                                'page_title' => $title,
                                'bx_logo_color' => '#000000',
                                'elmer_style_css' => '/.elm/dist/css/style.css',
                                'extra_headers' => "<meta http-equiv=\"refresh\" content=\"10\" />",
                                'heading' => $title,
                                'text' => $text,
                                'extra_footers' => '',
                                );

        // Show the HTML Page:
        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);
    }

    public function index() {

        // We load the BlueOnyx helper library first of all, as we heavily depend on it:
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $user = $BX_SESSION['loginUser'];
        $System = $CI->getSystem();
        $serverScriptHelper = $CI->getSSH();
        $locale = $BX_SESSION['locale'];

        // We hard wire the Wizard to use 'adminica' for now:
        $CI->setBX_SESSION_GuiTheme('adminica');
        setcookie("gui_theme", 'adminica', "0", "/");
        $BX_SESSION['gui_theme'] = 'adminica';
        if (isset($BX_SESSION['loginUser']['gui_theme'])) {
            $BX_SESSION['loginUser']['gui_theme'] = 'adminica';
        }

        if ($BX_SESSION['sessionId'] == "") {

            //
            //--- Try default login details:
            //

            $BX_SESSION['loginName'] = 'admin';
            $defaultPW = 'blueonyx';
            $BX_SESSION['sessionId'] = $CI->cceClient->auth($BX_SESSION['loginName'], $defaultPW);

            if (!empty($BX_SESSION['sessionId'])) {

                $serverScriptHelper = new ServerScriptHelper($BX_SESSION['sessionId'], $BX_SESSION['loginName'], 'adminica');
                $CI->setSSH($serverScriptHelper);
                $System = $CI->getSystem();
                $CI->setCCE($serverScriptHelper->getCceClient());
                $cceClient = $CI->getCCE();
                $serverScriptHelper = $CI->getSSH();
                $locale = $BX_SESSION['locale'];
                $BX_SESSION = $CI->getBX_SESSION();

        // We hard wire the Wizard to use 'adminica' for now:
        $CI->setBX_SESSION_GuiTheme('adminica');
        setcookie("gui_theme", 'adminica', "0", "/");
        $BX_SESSION['gui_theme'] = 'adminica';
        $BX_SESSION['loginUser']['gui_theme'] = 'adminica';

            }
            else {
                //
                //-- Default login doesn't work. We need to ask for username and password:
                //
                header("Location: /login?action=wizard");
                exit;
            }
        }

//print_rp($BX_SESSION);
//dd();


        //
        //-- Prepare Page:
        //

        $factory = $serverScriptHelper->getHtmlComponentFactory("base-wizard", "/wizard");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_data = $BxPage->getGETPOST('GET');

        // locale and charset setup:
        $browsercheck = TRUE;
        if (isset($get_data['action'])) {
            if ($get_data['action'] == "post") {
                $browsercheck = FALSE;
            }
        }
        if ($locale != "") {
            $browsercheck = FALSE;
        }
        $ini_langs = initialize_languages($browsercheck);
        $locale = $ini_langs['locale'];
        $localization = $ini_langs['localization'];
        $charset = $ini_langs['charset'];

        // Send cookies that expire in one hour: 
        setcookie("loginName", 'admin', time()+60*60*24*365, "/");
        if ($BX_SESSION['sessionId'] != "") {
            setcookie("sessionId", $BX_SESSION['sessionId'], "0", "/");
        }

        // Set new locale to cookie, too, but set an expiry of 365 days:
        setcookie("locale", $locale, "31536000", "/");

        // Check if the visitor is using a browser or a mobile device:
        $mobile = '';
        if (!$CI->agent->isMobile()) {
            $layout = "layout_fixed.css";
        }
        else {
            $layout = "layout_fixed.css";
        }

        $i18n = new I18n("base-wizard", $locale);

        // Form fields that are required to have input:
        $required_keys = array();

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();
        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_serialized_errors");

        // Get $errors from ServerScriptHandler POST vars:
        if (isset($form_data['_serialized_errors'])) {
            $TMPerrors = array_merge($errors, safe_deserialize($form_data['_serialized_errors']));
            foreach ($TMPerrors as $errNum => $errMsg) {
                $errors[$errNum] = urldecode($errMsg);
            }
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $i18n);
        }
        else {

            if (is_array($form_data)) {
                // Function GetFormAttributes() walks through the $form_data and returns us the $parameters we want to
                // submit to CCE. It intelligently handles checkboxes, which only have "on" set when they are ticked.
                // In that case it pulls the unticked status from the hidden checkboxes and addes them to $parameters.
                // It also transformes the value of the ticked checkboxes from "on" to "1". 
                //
                // Additionally it generates the form_validation rules for CodeIgniter.
                //
                // params: $i18n                i18n Object of the error messages
                // params: $form_data           array with form_data array from CI
                // params: $required_keys       array with keys that must have data in it. Needed for CodeIgniter's error checks
                // params: $ignore_attributes   array with items we want to ignore. Such as Labels.
                // return:                      array with keys and values ready to submit to CCE.
                $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);

                // Get potential errors that GetFormAttributes() ran into from $BxPage:
                $errors = array_merge($errors, $BxPage->getErrors());

            }

            //
            //--- Own error checks:
            //

            if ($this->request->getPost(NULL, NULL, TRUE)) {
                //    [localeField] => en_US
                //    [license_acceptance] => on
                //    [hostNameField] => ng2
                //    [domainNameField] => blueonyx.it
                //    [dnsAddressesField] => &127.0.0.1&
                //    [gatewayField] => 186.116.135.82
                //    [gatewayField_IPv6] => 2001:470:1f0e:7da::3
                //    [ipAddressFieldeth0] => 186.116.135.83
                //    [netMaskFieldeth0] => 255.255.255.240
                //    [macAddressFieldeth0] => 08:00:27:D4:2C:4E
                //    [hasAliaseseth0] => 0
                //    [ipAddressOrigeth0] => 186.116.135.83
                //    [netMaskOrigeth0] => 255.255.255.240
                //    [IPv6_ipAddressField1] => 2001:470:1f0e:7da::30
                //    [bootProtoFieldeth0] => none
                //    [enabledeth0] => 0
                //    [deviceList] => &eth0&
                //    [adminNameField] => admin
                //    [newPasswordField] => XXXXX
                //    [_newPasswordField_repeat] => XXXXX
                //    [sql_rootpassword] => XXXXX
                //    [systemDate] => 1401198752
                //    [_systemDate_oyear] => 2014
                //    [_systemDate_omonth] => 5
                //    [_systemDate_ohour] => 9
                //    [_systemDate_ominute] => 52
                //    [_systemDate_osecond] => 32
                //    [_systemDate_month] => 05
                //    [_systemDate_day] => 27
                //    [_systemDate_year] => 2014
                //    [_systemDate_hour] => 9
                //    [_systemDate_minute] => 52
                //    [_systemDate_amPm] => AM
                //    [timezoneSelectDropdown] => US/Eastern
                //    [oldTimeZone] => US/Eastern

                // Password empty?
                if (bx_pw_check($i18n, $attributes['newPasswordField'], $attributes['_newPasswordField_repeat']) != "") {
                    $errors[] = bx_pw_check($i18n, $attributes['newPasswordField'], $attributes['_newPasswordField_repeat']);
                }
                // License accepted?
                if (!isset($attributes['license_acceptance'])) {
                    $errors[] = ErrorMessage($i18n->get("[[base-wizard.accept_help]]"). '<br>' . $i18n->get("[[base-wizard.decline_help]]"));
                }
                if ((!isset($attributes['hostNameField'])) || (!isset($attributes['domainNameField']))) {
                    $errors[] = ErrorMessage($i18n->get("[[base-wizard.enterFqdn_help]]"));
                }
            }

            //
            //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
            //

            // If we have no errors and have POST data, we submit to CODB:
            if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

                print_rp($attributes);
                dd();

                // We have no errors. We submit to CODB.

                //
                //-- Set Locale:
                //

                $user_attributes = array("localePreference" => $attributes['localeField']);

                // Username = Password? Baaaad idea!
                if (strcasecmp('admin', $attributes['newPasswordField']) == 0) {
                        $error_msg = "[[base-user.error-password-equals-username]] [[base-user.error-invalid-password]]";
                        $errors[] = new Error($error_msg);
                }

                // Password Check:
                if (isset($attributes['newPasswordField'])) {
                    $passwd = $attributes['newPasswordField'];
                }
                $passwd_repeat = "";
                if (isset($attributes['_newPasswordField_repeat'])) {
                    $passwd_repeat = $attributes['_newPasswordField_repeat'];
                }
                if (bx_pw_check($i18n, $passwd, $passwd_repeat) != "") {
                    $my_errors = bx_pw_check($i18n, $passwd, $passwd_repeat);
                }
                if ($attributes['newPasswordField']) {
                    $user_attributes["password"] = $attributes['newPasswordField'];
                }

                // Set User locale and password first:
                $CI->cceClient->setObject("User", $user_attributes, "", array("name" => 'admin'));
                $errors = array_merge($errors, $CI->cceClient->errors());

                // Set system-language
                $oids = $CI->cceClient->find("System");
                $CI->cceClient->set($oids[0], "", array("productLanguage" => $attributes['localeField']));
                $errors = array_merge($errors, $CI->cceClient->errors());

                // Set new locale to cookie, too:
                $cookie = array('name' => 'locale', 'path' => '/', 'value' => $attributes['localeField'], 'expire' => '31536000');
                setcookie("locale", $attributes['localeField'], "0", "/");

                //
                //-- Set MySQL root password:
                //

                $mysql_data = array(
                        'oldpass' => '',
                        'username' => 'root',
                        'newpass' => $attributes['sql_rootpassword'],
                        'mysqluser' => 'root',
                        'onoff' => time(),
                        'password' => '',
                        'changepass' => time(),
                        'enabled' => '1'
                    );

                // Actual submit to CODB:
                $CI->cceClient->setObject("System", $mysql_data, "mysql");
                $errors = array_merge($errors, $CI->cceClient->errors());

                // Now handle the set to the CODB object "MySQL" as well.
                $getthisOID = $CI->cceClient->find("MySQL");
                $mysql_settings_exists = 0;
                $mysql_settings = $CI->cceClient->get($getthisOID[0]);
                if (!isset($mysql_settings['timestamp'])) {
                    $mysqlOID = $CI->cceClient->create("MySQL",
                        array(
                            'sql_host' => 'localhost',
                            'sql_port' => '3306',
                            'sql_root' => 'root',
                            'sql_rootpassword' => $attributes['sql_rootpassword'],
                            'savechanges' => time(),
                            'timestamp' => time()
                        )
                    );
                }
                else {
                    $mysqlOID = $CI->cceClient->find("MySQL");
                    $CI->cceClient->set($mysqlOID[0], "",
                        array(
                            'sql_host' => 'localhost',
                            'sql_port' => '3306',
                            'sql_root' => 'root',
                            'sql_rootpassword' => $attributes['sql_rootpassword'],
                            'savechanges' => time(),
                            'timestamp' => time()
                        )
                    );
                }
                $errors = array_merge($errors, $CI->cceClient->errors());

                //
                //-- Activate CSRF:
                //

                $CI->cceClient->setObject('System', array(
                                            'csrf_protection' => '1',
                                            'csrf_regenerate' => '0'
                                            ), '');
                $errors = array_merge($errors, $CI->cceClient->errors());

                //
                //-- Set TimeZone:
                //

                if ($attributes['timezoneSelectDropdown'] != $attributes['oldTimeZone']) {
                    $timeZone = $attributes['timezoneSelectDropdown'];
                    putenv("TZ=$timeZone");
                }
                else {
                    $timeZone = $attributes['timezoneSelectDropdown'];
                }
                if ($timeZone == "") {
                    // Got nothing? Set a default:
                    $timeZone == "US/Eastern";
                }
                if ($attributes['timezoneSelectDropdown'] == 'BST') {
                    $timeZone = 'Europe/London';
                    putenv("TZ=$timeZone");
                }

                $date = mktime($attributes['_systemDate_hour'], $attributes['_systemDate_minute'], "00", $attributes['_systemDate_month'], $attributes['_systemDate_day'], $attributes['_systemDate_year']);

                // Actual submit to CODB:
                // "deferCommit" is used by the setup wizard:
                $CI->cceClient->setObject('System', array(
                                            'deferCommit' => '1',
                                            'ntpAddress' => 'pool.ntp.org',
                                            'ntpEnabled' => '1',
                                            'epochTime' => $date,
                                            'timeZone' => $timeZone,
                                            ), 'Time');
                $errors = array_merge($errors, $CI->cceClient->errors());

                // Work around for 5106R oddity. We use the extra handler to set the timezone instead:
                $CI->cceClient->setObject('System', array(
                                            'epochTime' => $date,
                                            'ntpAddress' => 'pool.ntp.org',
                                            'timeZone' => $timeZone,
                                            'trigger' => time()
                                            ), 'TempTime');
                $errors = array_merge($errors, $CI->cceClient->errors());

                $CI->serverScriptHelper->shell("/usr/sausalito/sbin/setTime " . $date . " " . $timeZone . " " . "" . " false", $output, "root", $BX_SESSION['sessionId']);

                //
                //-- Set Network:
                //

                //    [hostNameField] => ng
                //    [domainNameField] => blueonyx.it
                //    [dnsAddressesField] => &208.67.251.180&208.77.221.199&8.8.8.8&4.2.2.2&
                //    [gatewayField] => 192.0.2.1
                //    [gatewayField_IPv6] => 2001:470:1f0e:7da::3

                if (!file_exists("/proc/user_beancounters")) {
                    // Regular Network Interfaces
                    $ok = $CI->cceClient->set($oids[0], "", array("hostname" => $attributes['hostNameField'], "domainname" => $attributes['domainNameField'], "dns" => $attributes['dnsAddressesField'], "gateway" => $attributes['gatewayField'], "gateway_IPv6" => $attributes['gatewayField_IPv6']));
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }
                else {
                    // OpenVZ Network Interfaces
                    $ok = $CI->cceClient->set($oids[0], "", array("hostname" => $attributes['hostNameField'], "domainname" => $attributes['domainNameField'], "dns" => $attributes['dnsAddressesField']));
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }               

                // Check if 'localhost' or our own IP are used as DNS servers:
                if (isset($attributes['ipAddressFieldeth0'])) {
                    // We're using our own DNS. Enable the DNS server:
                    $CI->cceClient->setObject("System", array("enabled" => '1'), "DNS");
                }
                $ownDNS = $CI->cceClient->scalar_to_array($attributes['dnsAddressesField']);
                if ((in_array('127.0.0.1', $ownDNS)) || (in_array('::1', $ownDNS))) {
                    // We're using our own DNS. Enable the DNS server:
                    $CI->cceClient->setObject("System", array("enabled" => '1'), "DNS");
                }

                $adminIf = "eth1";

                if ((!file_exists("/proc/user_beancounters")) && (!file_exists("/etc/is_aws"))) {
                    // Regular Network Interfaces

                  //  // Handle all devices
                  //  $devices = array('eth0', 'eth1');
                  //  if (isset($attributes['deviceList'])) {
                  //      $devices = json_decode(urldecode($attributes['deviceList']));
                  //  }
                  //  // Screw ith. Only handle eth0 and eth1:
                  //  if (!is_array($devices)) {
                  //      //$devices = array('eth0', 'eth1');
                  //      $devdetect = `ls -k1 /etc/sysconfig/network-scripts/ifcfg-*|grep -v lo|cut -d - -f3|head -1`;
                  //      $devices = array("$devdetect");
                  //  }

                    $devdetect = `ls -k1 /etc/sysconfig/network-scripts/ifcfg-*|grep -v lo|cut -d - -f3|sort -n|head -1`;
                    $devdetect = preg_replace('~[[:cntrl:]]~', '', $devdetect);
                    $devices = array("$devdetect");

                    // Only do the below shebang if we have an eth0:
                    if ($devdetect == "eth0") {

                        // special array for admin if errors
                        $admin_if_errors = array();
                        for ($i = 0; $i < 1; $i++) { // Screw it, we only do the first device.
                            if (isset($devices[$i])) {
                                $var_name = "ipAddressField" . $devices[$i];
                                $ip_field = $attributes[$var_name];
                                $var_name = "ipAddressOrig" . $devices[$i];
                                $ip_orig = $attributes[$var_name];
                                $var_name = "netMaskField" . $devices[$i];
                                $nm_field = $attributes[$var_name];
                                $var_name = "netMaskOrig" . $devices[$i];
                                $nm_orig = $attributes[$var_name];
                                $var_name = "bootProtoField" . $devices[$i];
                                $boot_field = $attributes[$var_name];
        
                                // setup or set disabled
                                if ($ip_field == '') {
                                    // first migrate any aliases to eth0 (possibly do this better)
                                    $aliases = $CI->cceClient->findx('Network', array(), array('device' => "^$devices[$i]:"));
                                    for ($k = 0; $k < count($aliases); $k++) {
                                        $new_device = find_free_device($CI->cceClient, 'eth0');
                                        $ok = $CI->cceClient->set($aliases[$k], '', array('device' => $new_device));
                                        $errors = array_merge($errors, $CI->cceClient->errors());
                                    }
        
                                    $CI->cceClient->setObject(
                                        'Network', 
                                        array("enabled" => "0"), 
                                        "",
                                        array("device" => $devices[$i])
                                    );
        
                                    if ($devices[$i] == $adminIf) {
                                        $admin_if_errors = $CI->cceClient->errors();
                                    }
                                    else {
                                        $errors = array_merge($errors, $CI->cceClient->errors());
                                    }
                                }
                                //elseif ($ip_field && $attributes['IPv6_ipAddressField1'] && (($ip_field != $ip_orig) || ($nm_field != $nm_orig))) {
                                elseif ($ip_field && $attributes['IPv6_ipAddressField1']) {
        
                                    // Set redirect IP for when we're done:
                                    $redirect_to_new_ip = $ip_field;
        
                                    // since we only deal with real interfaces here, things are simpler
                                    // than they could be
                                    if ($ip_field != $ip_orig) {
                                        // check to see if there is an alias that is already using
                                        // the new ip address.  if there is, destroy the Network object
                                        // for this device, and assign the alias this device name.
        
                                        $alias = $CI->cceClient->find('Network', 
                                                            array(
                                                                'real' => '0',
                                                                'ipaddr' => $ip_field
                                                                ));
        
                                        if (isset($alias[0])) {
                                            $ok = $CI->cceClient->set($alias, '',
                                                array(
                                                    'device' => $devices[$i],
                                                    'real' => '1',
                                                    'ipaddr' => $ip_field,
                                                    'netmask' => $nm_field,
                                                    'ipaddr_IPv6' => $attributes['IPv6_ipAddressField1'],
                                                    'enabled' => '1',
                                                    'bootproto' => 'none',
                                                    'refresh' => time()
                                                    ));
                                            $errors = array_merge($errors, $CI->cceClient->errors());
                                            if (!$ok) {
                                                break;
                                            }
                                            else {
                                                continue;
                                            }
                                        }
                                    }
                                    $CI->cceClient->setObject('Network',
                                            array(
                                                'ipaddr' => $ip_field,
                                                'netmask' => $nm_field,
                                                'ipaddr_IPv6' => $attributes['IPv6_ipAddressField1'],
                                                'enabled' => '1',
                                                'bootproto' => 'none',
                                                'refresh' => time()
                                                ),
                                           '', array('device' => $devices[$i]));
        
                                    if ($devices[$i] == $adminIf) {
                                        $admin_if_errors = $CI->cceClient->errors();
                                    }
                                    else {
                                        $errors = array_merge($errors, $CI->cceClient->errors());
                                    }
                                }
                            }
                        }
                    }
                }

                //
                //-- Finalize if we have no errors:
                //

                if (count($errors) == "0") {
                    $CI->cceClient->setObject('System', array('isLicenseAccepted' => '1', 'isRegistered' => '0'), '');

                    // Send cookies that expire in one hour:
                    setcookie("loginName", 'admin', time()+60*60*24*365, "/");
                    if ($BX_SESSION['sessionId'] != "") {
                        setcookie("sessionId", $BX_SESSION['sessionId'], "0", "/");
                    }

                    // Set new locale to cookie, too, but set an expiry of 365 days:
                    setcookie("locale", $attributes['localeField'], "31536000", "/");

                    //
                    //-- Set Theme cookies:
                    //

                    // Default Style:
                    $ChorizoDefaultStyle =  array(
                            'theme_switcher_php-style'   => 'theme_blue.css',
                            'layout_switcher_php-style'  => 'layout_fixed.css',
                            'nav_switcher_php-style'     => 'switcher.css',
                            'skin_switcher_php-style'    => 'skin_light.css',
                            'bg_switcher_php-style'      => 'switcher.css'
                        );

                    // Push out cookies for the Users known Style:
                    foreach ($ChorizoDefaultStyle as $key => $value) {
                        setcookie($key, $value, "31536000", "/");
                    }

                    $data['gui_theme'] = 'elmer';
                    setcookie("gui_theme", $data['gui_theme'], "0", "/");
                    session()->set($data);

                    $CI->setBX_SESSION_GuiTheme($data['gui_theme']);
                    $BX_SESSION['gui_theme'] = $data['gui_theme'];
                    $BX_SESSION['loginUser']['gui_theme'] = $data['gui_theme'];

                    if (!isset($redirect_to_new_ip)) {
                        // Nice people say goodbye, or CCEd waits forever:
                        $CI->cceClient->bye();
                        $CI->serverScriptHelper->destructor();
                        // Simple redirect as IP hasn't changed:
                        header("Location: /gui");
                        exit;
                    }
                    else {
                        // Nice people say goodbye, or CCEd waits forever:
                        $CI->cceClient->bye();
                        $CI->serverScriptHelper->destructor();
                        // Redirect to the new IP:
                        header("Location: http://$redirect_to_new_ip:444/gui");
                        exit;
                    }
                }
            }
        }

        //
        //-- Generate page:
        //

        // Find out if the web based initial setup has been completed:
        $System = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        $TZ = $CI->cceClient->getObject("System", array(), "Time");

        // Unless we have 'productLanguage', 'dns' and 'timeZone' set and at least ONE gateway (ipv4 or ipv6) we know that CCEd is not yet done running constructors
        // AND someone hasn't run /root/network_settings.sh yet. So we loop until those conditions are met.
        if ((!isset($System['productLanguage'])) || (!isset($System['dns'])) || (!isset($TZ['timeZone'])) || ((!isset($System['gateway'])) && (!isset($System['gateway_IPv6'])))) {
            // Vital information in CODB object 'System' is missing.
            // Or the 'System' object is not yet there.
            //
            // Generate a "please wait" page via WizardElmer::wizard_reload():
            $good_system_info = "FALSE";
            return WizardElmer::wizard_reload();
        }
        else {
            $good_system_info = "TRUE";
        }

        if ($System['isLicenseAccepted'] == "1" ) {
            // Web based setup *has* been completed. Redirect to /gui
            header("Location: /gui");
            exit;
        }

        // Set headers:
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Cache-Control', 'post-check=0, pre-check=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Content-language', $locale);
        $this->response->setHeader('Content-type', "text/html; charset=$charset");

        // Get default theme cookie (if it exists):
        $skin = 'skin_light.css';
        if (isset($_COOKIE['skin_switcher_php-style'])) {
            $skin = $_COOKIE['skin_switcher_php-style'];
        }

        // Set page title:
        preg_match("/^([^:]+)/", $_SERVER['HTTP_HOST'], $matches);
        $hostname = $matches[0];
        // Strip out the :444 or :81 from the hostname - if present:
        if (preg_match('/:/', $hostname)) {
            $hn_pieces = explode(":", $hostname);
            $hostname = $hn_pieces[0];
        }
        //$i18n = new I18n("base-wizard", $locale);
        preg_match("/([^:]+):?.*/", $hostname, $matches);
        $hostname_new = $matches[1] ? $matches[1] : `/bin/hostname --fqdn`;

        //
        //-- Check if we are authed against CCEd:
        //

        if ($good_system_info == "TRUE") {
            $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-wizard", "/wizard");
            $defaultPage = "Basic";
            //$i18n = new I18n("base-wizard", $locale);
            $BxPage = $factory->getPage();
            $BxPage->setI18n($i18n);

            //
            //-- Step #1: Language
            //
            $step_1_title = $i18n->get("wizard_locale_header", "base-wizard");
            $step_1_title_sub = $i18n->get("wizard_locale_header_sub", "base-wizard");

            // Locale selector:
            $step_1 = $factory->getSimpleBlock(" ", $i18n);
            $localeField = $factory->getLocale("localeField", $locale);
            $localeField->setPossibleLocales(array('en_US', 'da_DK', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'ja_JP', 'nl_NL', 'pt_PT'));
            $step_1->addHtmlComponent(
              $localeField,
              $factory->getLabel("localeField"), $defaultPage
            );

            //
            //-- Step #2: License
            //

            $step_2_title = $i18n->get("wizard_license_header", "base-wizard");
            $step_2_title_sub = $i18n->get("wizard_license_header_sub", "base-wizard");

            $step_2 = $factory->getSimpleBlock(" ", $i18n);
            $licenseClick = $factory->getRawHTML("licenseClick", '<h6>' . $i18n->get("licenseClick") . '</h6><br>');
            $step_2->addHtmlComponent(
              $licenseClick,
              $factory->getLabel("licenseClick"), $defaultPage
            );

            $license = $factory->getRawHTML("license", '<hr style="margin-botton: 10px"><p>' . $i18n->get("license") . '</p><hr style="margin-botton: 10px">', 'html');
            $step_2->addHtmlComponent(
              $license,
              $factory->getLabel("license"), $defaultPage
            );

            $Year = date('Y');

$licTextBody = '
------ SUN-modified-BSD-License for BlueOnyx: ------
Copyright (c) 2008-' . $Year . ' Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-' . $Year . ' Team BlueOnyx, BLUEONYX.IT
Copyright (c) 2003 Sun Microsystems, Inc. 
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.';

            $licText = $factory->getHtmlField("licText", '<pre>' . $licTextBody . '</pre>', 'html');
            $licText->setLabelType("nolabel");
            $step_2->addHtmlComponent(
              $licText,
              $factory->getLabel("licText"), $defaultPage
            );

            $license_accept_block = $factory->getBoolean("license_acceptance", '0');

            $step_2->addHtmlComponent(
              $factory->getRawHTML("license_acceptance", $license_accept_block->toHtml()),
              $factory->getLabel("license_acceptance"), $defaultPage
            );

            //
            //-- Step #3: System Settings
            //

            $step_3_title = $i18n->get("serverconfig", "base-alpine");
            $step_3_title_sub = $i18n->get("wizardSysSettings_help", "base-wizard");

            $step_3 = $factory->getSimpleBlock(" ", $i18n);

            // Network settings

            $CI->serverScriptHelper = new ServerScriptHelper($BX_SESSION['sessionId'], $BX_SESSION['loginName']);
            $CI->cceClient = $CI->serverScriptHelper->getCceClient();

            $networkObj = $CI->cceClient->getObject("System", array(), "Network");

            // Add divider:
            $step_3->addHtmlComponent(
                    $factory->addBXDivider("networkSettings", ""),
                    $factory->getLabel("networkSettings", false),
                    $defaultPage
                    );

            //host and domain names
            if (($System['hostname'] == 'localhost') &&
                ($System['domainname'] == '')) {
                // assume this is first boot if domainname is not set
                $defaultHostname = '';
            } else {
                $defaultHostname = $System['hostname'];
            }

            // host and domain names
            $hostfield = $factory->getDomainName("hostNameField", $defaultHostname, 'rw');
            $hostfield->setOptional(FALSE);
            $domainfield = $factory->getDomainName("domainNameField", $System["domainname"], 'rw');
            $domainfield->setOptional(FALSE);

            $fqdn = $factory->getCompositeFormField(array($hostfield, $domainfield), '&nbsp;.&nbsp;');

            $step_3->addHtmlComponent(
                $fqdn,
                $factory->getLabel("enterFqdn"), 
                $defaultPage
            );

            $dns = $factory->getIpAddressList("dnsAddressesField", $System["dns"]);
            $dns->setOptional(TRUE);
            $dns->setType('ipaddr_list_IPv4IPv6');
            $step_3->addHtmlComponent(
              $dns,
              $factory->getLabel("dnsAddressesField")
            );

            // Protect certain form fields read-only inside VPS's:
            if (is_file("/proc/user_beancounters")) {
                $fieldprot = "r";
            }
            else {
                $fieldprot = "rw";
            }

            // Are we running on AWS?
            if (is_file("/etc/is_aws")) {
                $is_aws = "1";
                if (!isset($System["gateway_IPv6"])) {
                    // AWS and Gateway not defined. Make it editable:
                    $gwFprot = 'rw';
                }
                else {
                    if ($System["gateway_IPv6"] == "") {
                        // AWS and Gateway not set. Make it editable:
                        $gwFprot = 'rw';
                    }
                    else {
                        // AWS, Gateway is set and not empty. Show it.
                        // But do not allow to edit it:
                        $gwFprot = 'r';
                    }
                }                
            }
            else {
                $is_aws = "0";
                // Not AWS. Allow edits if they are allowed for any of
                // the other network related fields:
                $gwFprot = $fieldprot;
            }

            // Gateway IPv4
            $gw = $factory->getIpAddress("gatewayField", $System["gateway"], $fieldprot);
            $gw->setOptional(true);
            $step_3->addHtmlComponent($gw, $factory->getLabel("gatewayField"), $defaultPage);

            // Gateway IPv6
            $gw_IPv6 = $factory->getIpAddress("gatewayField_IPv6", $System["gateway_IPv6"], $gwFprot);
            $gw_IPv6->setOptional(true);
            $gw_IPv6->setType('ipaddrIPv6');
            $gw_IPv6->setCurrentLabel($i18n->get("[[base-network.gatewayField_IPv6]]", false));
            $step_3->addHtmlComponent($gw_IPv6, $factory->getLabel("gatewayField_IPv6"), $defaultPage);

            // real interfaces
            // ascii sorted, this may be a problem if there are more than 10 interfaces
            $interfaces = $CI->cceClient->findx('Network', array('real' => '1', 'enabled' => '1'), array(), 'ascii', 'device');
            $devices = array();
            $deviceList = array();
            $devnames = array();
            $admin_if = '';
            for ($i = 0; $i < count($interfaces); $i++) {

                $is_admin_if = false;
                $iface = $CI->cceClient->get($interfaces[$i]);
                $device = $iface['device'];
                
                // save the devices and strings for javascript fun
                $deviceList[] = $device;
                $devices[] = "'$device'";    
                $devnames[] = "'" . $i18n->getJs("[[base-network.interface$device]]") . "'";

                    // Devices:
                    $dev[$device] = array (
                                    'ipaddr' => $iface["ipaddr"],
                                    'ipaddr_IPv6' => $iface["ipaddr_IPv6"],
                                    'netmask' => $iface["netmask"],
                                    'mac' => $iface["mac"],
                                    'device' => $device,
                                    'bootproto' => $iface["bootproto"],
                                    'enabled' => $iface["enabled"]
                                    );

            }
            if (isset($dev['eth0'])) {
                $ipaddr = $dev['eth0']['ipaddr'];
                $IPv6_ipaddr = $dev['eth0']['ipaddr_IPv6'];
                $netmask = $dev['eth0']['netmask'];
                $device = $dev['eth0']['device'];
                $mac = $dev['eth0']['mac'];
                $enabled = $dev['eth0']['enabled'];
                $bootproto = $dev['eth0']['bootproto'];
                
                $ip_label = '[[base-network.ipAddressField1]]';
                $nm_label = '[[base-network.netMaskField1]]';
                $ipV6_label = '[[base-network.IPv6_ipAddressField1]]';

                // Add divider:
                $divider = $factory->addBXDivider("interface$device", "");
                $divider->setCurrentLabel($i18n->get("[[base-network.interface$device]]", false));
                $step_3->addHtmlComponent(
                        $divider,
                        $factory->getLabel("[[base-network.interface$device]]", false),
                        $defaultPage
                        );

                if ($is_aws == "0") {
                    $devprot = "rw";
                }
                else {
                    $devprot = "r";
                }

                // IP Address:
                $ip_field0 = $factory->getIpAddress("ipAddressField$device", $ipaddr, $devprot);
                $ip_field0->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ip_field0->setCurrentLabel($i18n->get($ip_label, true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field0->setDescription($i18n->getWrapped('[[base-network.ipAddressField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field0->setOptional(true);
                $step_3->addHtmlComponent(
                        $ip_field0,
                        $factory->getLabel($ip_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                // Netmask:
                $netmask_field0 = $factory->getIpAddress("netMaskField$device", $netmask, $devprot);
                $netmask_field0->setInvalidMessage($i18n->getJs('netMaskField_invalid'));

                // Netmask is not optional for the admin iface and for eth0
                $netmask_field0->setOptional(false);
                $netmask_field0->setCurrentLabel($i18n->get($nm_label, true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field0->setDescription($i18n->getWrapped('[[base-network.netMaskField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field0->setOptional(true);
                
                $step_3->addHtmlComponent(
                        $netmask_field0,
                        $factory->getLabel($nm_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                // IPv6 IP-Address:
                $ipv6_field0 = $factory->getIpAddress("IPv6_ipAddressField1", $IPv6_ipaddr, $devprot);
                $ipv6_field0->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ipv6_field0->setCurrentLabel($i18n->get($ipV6_label, true, array(), array('name' => "[[base-network.help$device]]")));
                $ipv6_field0->setDescription($i18n->getWrapped('[[base-network.IPv6_ipAddressField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ipv6_field0->setOptional(true);
                $ipv6_field0->setType('ipaddrIPv6');
                $step_3->addHtmlComponent(
                        $ipv6_field0,
                        $factory->getLabel($ipV6_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                // MAC Address:
                $mac0 = $factory->getMacAddress("macAddressField$device", $mac, "r");
                $mac0->setCurrentLabel($i18n->get('[[base-network.macAddressField]]', true));
                $mac0->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));
                $step_3->addHtmlComponent(
                        $mac0,
                        $factory->getLabel("macAddressField"),
                        $defaultPage
                    );

                // retain orginal information
                $step_3->addHtmlComponent(
                        $factory->getBoolean("hasAliases$device", 0, ''));

                $step_3->addHtmlComponent(
                        $factory->getIpAddress("ipAddressOrig$device", $ipaddr, ""),
                        '',
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getIpAddress("netMaskOrig$device", $netmask, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getTextField("bootProtoField$device", $bootproto, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getBoolean("enabled$device", $enabled, ""),
                        "",
                        $defaultPage
                        );

            }
            if (isset($dev['eth1'])) {
                $ipaddr = $dev['eth1']['ipaddr'];
                $netmask = $dev['eth1']['netmask'];
                $device = $dev['eth1']['device'];
                $mac = $dev['eth1']['mac'];
                $enabled = $dev['eth1']['enabled'];
                $bootproto = $dev['eth1']['bootproto'];

                if ($enabled == "0") {
                    $ipaddr = "";
                    $netmask = "";
                }
                
                $ip_label = 'ipAddressField1';
                $nm_label = 'netMaskField1';

                // Add divider:
                $divider = $factory->addBXDivider("interface$device", "");
                $divider->setCurrentLabel($i18n->get("[[base-network.interface$device]]", false));
                $step_3->addHtmlComponent(
                        $divider,
                        $factory->getLabel("[[base-network.interface$device]]", false),
                        $defaultPage
                        );

                $ip_field1 = $factory->getIpAddress("ipAddressField$device", $ipaddr);
                $ip_field1->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ip_field1->setCurrentLabel($i18n->get('[[base-network.ipAddressField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field1->setDescription($i18n->getWrapped('[[base-network.ipAddressField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));

                $ip_field1->setOptional(true);

                $step_3->addHtmlComponent(
                        $ip_field1,
                        $factory->getLabel($ip_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                $netmask_field1 = $factory->getIpAddress("netMaskField$device", $netmask);
                $netmask_field1->setInvalidMessage($i18n->getJs('netMaskField_invalid'));
                $netmask_field1->setEmptyMessage($i18n->getJs('netMaskField_empty', 'base-network', array('interface' => "[[base-network.interface$device]]")));
                $netmask_field1->setCurrentLabel($i18n->get('[[base-network.netMaskField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field1->setDescription($i18n->getWrapped('[[base-network.netMaskField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));

                $netmask_field1->setOptional(true);
                
                $step_3->addHtmlComponent(
                        $netmask_field1,
                        $factory->getLabel($nm_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                // MAC:
                $macaddress_field1 = $factory->getMacAddress("macAddressField$device", $mac, "r");
                $macaddress_field1->setCurrentLabel($i18n->get('[[base-network.macAddressField]]', true));
                $macaddress_field1->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));

                $step_3->addHtmlComponent(
                        $macaddress_field1,
                        $factory->getLabel("macAddressField"),
                        $defaultPage
                    );

                // retain orginal information
                $step_3->addHtmlComponent(
                        $factory->getBoolean("hasAliases$device", 0, ''));
                $step_3->addHtmlComponent(
                        $factory->getIpAddress("ipAddressOrig$device", $ipaddr, ""),
                        '',
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getIpAddress("netMaskOrig$device", $netmask, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getTextField("bootProtoField$device", $bootproto, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getBoolean("enabled$device", $enabled, ""),
                        "",
                        $defaultPage
                        );

            }
            if (isset($dev['eth2'])) {
                $ipaddr = $dev['eth2']['ipaddr'];
                $netmask = $dev['eth2']['netmask'];
                $device = $dev['eth2']['device'];
                $mac = $dev['eth2']['mac'];
                $enabled = $dev['eth2']['enabled'];
                $bootproto = $dev['eth2']['bootproto'];
                
                if ($enabled == "0") {
                    $ipaddr = "";
                    $netmask = "";
                }

                $ip_label = 'ipAddressField';
                $nm_label = 'netMaskField';

                // Add divider:
                $divider = $factory->addBXDivider("interface$device", "");
                $divider->setCurrentLabel($i18n->get("[[base-network.interface$device]]", false));
                $step_3->addHtmlComponent(
                        $divider,
                        $factory->getLabel("[[base-network.interface$device]]", false),
                        $defaultPage
                        );

                $ip_field2 = $factory->getIpAddress("ipAddressField$device", $ipaddr);
                $ip_field2->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ip_field2->setCurrentLabel($i18n->get('[[base-network.ipAddressField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field2->setDescription($i18n->getWrapped('[[base-network.ipAddressField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field2->setOptional(true);

                $step_3->addHtmlComponent(
                        $ip_field2,
                        $factory->getLabel($ip_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                $netmask_field2 = $factory->getIpAddress("netMaskField$device", $netmask);
                $netmask_field2->setInvalidMessage($i18n->getJs('netMaskField_invalid'));
                $netmask_field2->setEmptyMessage($i18n->getJs('netMaskField_empty', 'base-network', array('interface' => "[[base-network.interface$device]]")));
                $netmask_field2->setCurrentLabel($i18n->get('[[base-network.netMaskField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field2->setDescription($i18n->getWrapped('[[base-network.netMaskField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));

                $netmask_field2->setOptional(true);
                
                $step_3->addHtmlComponent(
                        $netmask_field2,
                        $factory->getLabel($nm_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                // MAC:
                $macaddress_field2 = $factory->getMacAddress("macAddressField$device", $mac, "r");
                $macaddress_field2->setCurrentLabel($i18n->get('[[base-network.macAddressField]]', true));
                $macaddress_field2->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));

                $step_3->addHtmlComponent(
                        $macaddress_field2,
                        $factory->getLabel("macAddressField"),
                        $defaultPage
                    );

                // retain orginal information
                $step_3->addHtmlComponent(
                        $factory->getBoolean("hasAliases$device", 0, ''));
                $step_3->addHtmlComponent(
                        $factory->getIpAddress("ipAddressOrig$device", $ipaddr, ""),
                        '',
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getIpAddress("netMaskOrig$device", $netmask, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getTextField("bootProtoField$device", $bootproto, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getBoolean("enabled$device", $enabled, ""),
                        "",
                        $defaultPage
                        );

            }
            if (isset($dev['eth3'])) {
                $ipaddr = $dev['eth3']['ipaddr'];
                $netmask = $dev['eth3']['netmask'];
                $device = $dev['eth3']['device'];
                $mac = $dev['eth3']['mac'];
                $enabled = $dev['eth3']['enabled'];
                $bootproto = $dev['eth3']['bootproto'];
                
                if ($enabled == "0") {
                    $ipaddr = "";
                    $netmask = "";
                }

                $ip_label = 'ipAddressField';
                $nm_label = 'netMaskField';

                // Add divider:
                $divider = $factory->addBXDivider("interface$device", "");
                $divider->setCurrentLabel($i18n->get("[[base-network.interface$device]]", false));
                $step_3->addHtmlComponent(
                        $divider,
                        $factory->getLabel("[[base-network.interface$device]]", false),
                        $defaultPage
                        );

                $ip_field3 = $factory->getIpAddress("ipAddressField$device", $ipaddr);
                $ip_field3->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ip_field3->setCurrentLabel($i18n->get('[[base-network.ipAddressField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field3->setDescription($i18n->getWrapped('[[base-network.ipAddressField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));

                $ip_field3->setOptional(true);

                $step_3->addHtmlComponent(
                        $ip_field3,
                        $factory->getLabel($ip_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                $netmask_field3 = $factory->getIpAddress("netMaskField$device", $netmask);
                $netmask_field3->setInvalidMessage($i18n->getJs('netMaskField_invalid'));
                $netmask_field3->setEmptyMessage($i18n->getJs('netMaskField_empty', 'base-network', array('interface' => "[[base-network.interface$device]]")));
                $netmask_field3->setCurrentLabel($i18n->get('[[base-network.netMaskField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field3->setDescription($i18n->getWrapped('[[base-network.netMaskField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));

                $netmask_field3->setOptional(true);
                
                $step_3->addHtmlComponent(
                        $netmask_field3,
                        $factory->getLabel($nm_label, true,
                                    array(), array('name' => "[[base-network.help$device]]")),
                        $defaultPage
                    );

                // MAC:
                $macaddress_field3 = $factory->getMacAddress("macAddressField$device", $mac, "r");
                $macaddress_field3->setCurrentLabel($i18n->get('[[base-network.macAddressField]]', true));
                $macaddress_field3->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));

                $step_3->addHtmlComponent(
                        $macaddress_field3,
                        $factory->getLabel("macAddressField"),
                        $defaultPage
                    );

                // retain orginal information
                $step_3->addHtmlComponent(
                        $factory->getBoolean("hasAliases$device", 0, ''));
                $step_3->addHtmlComponent(
                        $factory->getIpAddress("ipAddressOrig$device", $ipaddr, ""),
                        '',
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getIpAddress("netMaskOrig$device", $netmask, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getTextField("bootProtoField$device", $bootproto, ""),
                        "",
                        $defaultPage
                        );
                $step_3->addHtmlComponent(
                        $factory->getBoolean("enabled$device", $enabled, ""),
                        "",
                        $defaultPage
                        );
            }

            // Add list of seen Network devices:
            $step_3->addHtmlComponent(
                    $factory->getTextField("deviceList", urlencode(json_encode($deviceList)), ""),
                    "",
                    $defaultPage
                    );

            //
            //-- Admin Password:
            //

            // Add divider:
            $step_3->addHtmlComponent(
                    $factory->addBXDivider("wizardAdmin", ""),
                    $factory->getLabel("wizardAdmin", false),
                    $defaultPage
                    );

            // User-Name:
            $adminName = $factory->getFullName("adminNameField", 'admin', 'r');
            $adminName->setOptional(TRUE);
            $step_3->addHtmlComponent(
                    $adminName,
                    $factory->getLabel("adminNameField"),
                    $defaultPage
                    );

            // Password:
            $mypw = $factory->getPassword("newPasswordField", "", "rw");
            $mypw->setConfirm(TRUE);
            $mypw->setOptional(FALSE);
            $mypw->setCheckPass(TRUE);
            $step_3->addHtmlComponent(
              $mypw,
              $factory->getLabel("newPasswordField"), $defaultPage
            );

                // Password is a REQUIRED input:
                $id = 'newPasswordField';
                $id_confirm = '_newPasswordField_repeat';
                $topdiv_id = 'newPasswordField_topdiv';
                $topdiv_id_confirm = '_newPasswordField_repeat_topdiv';
                $pw_way_too_short = $i18n->getHtml("[[palette.pw_way_too_short]]");
                $strong_password_msg = $i18n->getHtml("[[palette.pw_strong_password]]");

                $extra_headers = '
                    <script language="Javascript" type="text/javascript" src="/libJs/ajax_lib.js"></script>

                    <script language="Javascript">
                    <!--

                    checkpassOBJ = function () {
                        this.onFailure = function () {
                            alert("Unable to validate password");
                        }
                        this.OnSuccess = function () {
                            var response = this.GetResponseText();
                            // console.log("Response from server:", response); // Log the actual response
                            var passwordTopDiv = document.getElementById("' . $topdiv_id . '");
                            var pwResultsDiv = document.getElementById("pwresults");
                            var passwordRepeatDiv = document.getElementById("' . $topdiv_id_confirm .'");
                            var passwordRepeatHelpBlock = document.querySelector("#' . $topdiv_id_confirm .' .help-block.with-errors ul li");

                            // Reset classes for both fields
                            pwResultsDiv.classList.remove("has-error");
                            passwordTopDiv.classList.remove("has-error");
                            passwordRepeatDiv.classList.remove("has-error");

                            // Compare passwords
                            var passwordValue = document.getElementById("' . $id . '").value;
                            var passwordRepeatValue = document.getElementById("' . $id_confirm . '").value;

                            // Add "has-error" class if the "password" field is required and empty
                            if (document.getElementById("' . $id . '").hasAttribute("required") && passwordValue === "") {
                                pwResultsDiv.innerHTML = "This field is required.";
                                pwResultsDiv.classList.add("has-error");
                                passwordTopDiv.classList.add("has-error");
                                return;
                            }

                            // Add "has-error" class if the "password" field is required and too short
                            if (document.getElementById("' . $id . '").hasAttribute("required") && passwordValue.length < 8) {
                                pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                pwResultsDiv.classList.add("has-error");
                                passwordTopDiv.classList.add("has-error");
                                return;
                            }

                            if (passwordValue.length < 8) {
                                // console.log("Password is too short");
                                pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                pwResultsDiv.classList.add("has-error");
                                passwordTopDiv.classList.add("has-error");
                            } else {
                                if (response.includes("' . $strong_password_msg . '")) {
                                    // console.log("We have: Strong password", response);
                                    pwResultsDiv.innerHTML = "' . $strong_password_msg . '";

                                    // Remove "has-error" class
                                    pwResultsDiv.classList.remove("has-error");
                                } else {
                                    // console.log("We DO NOT have: Strong password", response);
                                    pwResultsDiv.innerHTML = response;
                                    pwResultsDiv.classList.add("has-error");
                                    passwordTopDiv.classList.add("has-error");
                                }
                            }

                            // Add "has-error" class if the "password_repeat" field is required and empty
                            if (document.getElementById("' . $id_confirm . '").hasAttribute("required") && passwordRepeatValue === "") {
                                passwordRepeatHelpBlock.innerHTML = "This field is required.";
                                passwordRepeatDiv.classList.add("has-error");
                                return;
                            }

                            if (passwordValue === passwordRepeatValue) {
                                passwordRepeatHelpBlock.innerHTML = "";

                                // Remove "has-error" class
                                passwordRepeatDiv.classList.remove("has-error");
                                // console.log("Passwords match");
                            } else {
                                // console.log("Passwords DO NOT match");
                                passwordRepeatHelpBlock.innerHTML = "Passwords don\'t match";
                                passwordRepeatDiv.classList.add("has-error");
                            }
                        }
                    }

                    function validate_password(word) {
                        checkpassOBJ.prototype = new ajax_lib();
                        checkpass = new checkpassOBJ();
                        var URL = "/gui/check_password";
                        var PARAM = "password=" + word;
                        checkpass.post(URL, PARAM);
                    }

                    document.getElementById("' . $id_confirm . '").addEventListener("input", function () {
                        // console.log("Cross-checking password/password_repeat");
                        validate_password(document.getElementById("' . $id . '").value);
                    });

                    // Trigger the validation on page load if "password_repeat" is required
                    window.addEventListener(\'DOMContentLoaded\', function () {
                        var passwordRepeatElement = document.getElementById("' . $id_confirm . '");
                        if (passwordRepeatElement && passwordRepeatElement.hasAttribute("required")) {
                            validate_password(passwordRepeatElement.value);
                        }
                    });

                    //-->
                    </script>' . "\n";

            //
            //--- MySQL password:
            //

            // Add divider:
            $step_3->addHtmlComponent(
                    $factory->addBXDivider("wizardMySQLpassHeader", ""),
                    $factory->getLabel("wizardMySQLpassHeader", false),
                    $defaultPage
                    );

            // sql_rootpassword:
            $line_sql_rootpassword = $factory->getPassword("sql_rootpassword", "", "rw");
            $line_sql_rootpassword->setOptional(FALSE);
            $line_sql_rootpassword->setConfirm(FALSE);
            $line_sql_rootpassword->setCheckPass(FALSE);
            $step_3->addHtmlComponent($line_sql_rootpassword, $factory->getLabel("sql_rootpassword"), $defaultPage);            

            //
            //-- Timezone:
            //

            // Add divider:
            $step_3->addHtmlComponent(
                    $factory->addBXDivider("wizardTime", ""),
                    $factory->getLabel("wizardTime", false),
                    $defaultPage
                    );

            // Get current time from time():
            $t = time();

            $CODBDATA = $CI->cceClient->getObject("System", array(), "Time");
            if ($CODBDATA["timeZone"] == "") {
                // Got nothing? Set a default:
                $CODBDATA["timeZone"] == "US/Eastern";
            }

            if ($CODBDATA["timeZone"] == 'Europe/London') {
                // Got nothing? Set a default:
                $CODBDATA["timeZone"] = "BST";
            }

            //$SystemDisplayedDate = $factory->getTimeStamp("systemDate", $t, "datetime");
            //$SystemDisplayedDate->setCurrentLabel($i18n->get("[[base-time.systemDisplayedDate]]"));
            //$SystemDisplayedDate->setDescription($i18n->getWrapped("[[base-time.systemDisplayedDate_help]]"));
            //$step_3->addHtmlComponent($SystemDisplayedDate, $factory->getLabel("systemDisplayedDate"));

            $DatePickerField = $factory->getDatePicker("systemDate", $t, "datetime", 'rw');
            $DatePickerField->setCurrentLabel($i18n->get("[[base-time.systemDisplayedDate]]"));
            $DatePickerField->setDescription($i18n->get("[[base-time.systemDisplayedDate_help]]"));
            $DatePickerField->setModus('all');
            $step_3->addHtmlComponent($DatePickerField, $factory->getLabel("systemDisplayedDate"));

            $format = "'YYYY-MM-DD hh:mm A'";
            $viewMode = "";
            $date = new \DateTime();
            $date->setTimestamp($t);
            $time_stamp = "'" . $date->format('Y-m-d h:i A') . "'";
            $date_range = '';
            $id = 'systemDate';
            $do_submit = '';

            $extra_footers =<<<HTML
                    <!-- uifc2/DatePicker.php -->
                    <script type="text/javascript" src="/.elm/vendors/bower_components/eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js"></script>

                    <script>
                        $(document).ready(function() {
                            "use strict";
                            
                            /* Datetimepicker Init*/
                            $('#$id').datetimepicker({
                                    useCurrent: false,
                                    format: $format,
                                    $viewMode
                                    defaultDate: $time_stamp,
                                    $date_range
                                    icons: {
                                            time: "fa fa-clock-o",
                                            date: "fa fa-calendar",
                                            up: "fa fa-arrow-up",
                                            down: "fa fa-arrow-down"
                                        },
                                }).on('dp.show', function() {
                                if($(this).data("DateTimePicker").date() === null)
                                    $(this).data("DateTimePicker").date(moment());
                                })$do_submit
                        });
                    </script>
                    <!-- uifc2/DatePicker.php -->

            HTML;

            $SystemDisplayedTimeZone = $factory->getTimeZone("systemTimeZone", $CODBDATA["timeZone"], 'rw');
            $SystemDisplayedTimeZone->setCurrentLabel($i18n->get("[[base-time.systemDisplayedTimeZone]]"));
            $SystemDisplayedTimeZone->setDescription($i18n->getWrapped("[[base-time.systemDisplayedTimeZone_help]]"));
            $step_3->addHtmlComponent($SystemDisplayedTimeZone, $factory->getLabel("systemDisplayedTimeZone"));

            $oldTimeZone = $factory->getTextField("oldTimeZone", $CODBDATA["timeZone"], "");
            $step_3->addHtmlComponent($oldTimeZone);

            // NTP server may only be set on stand alone servers, not in a VPS:
            if (!is_file("/proc/user_beancounters")) {
                $ntpAddress = $factory->getNetAddress("ntpAddress",$CODBDATA["ntpAddress"]);
                $ntpAddress->setOptional(true);
                $ntpAddress->setMaxLength(50);
                $ntpAddress->setCurrentLabel($i18n->get("[[base-time.ntpAddress]]"));
                $ntpAddress->setDescription($i18n->getWrapped("[[base-time.ntpAddress_help]]"));
                $step_3->addHtmlComponent($ntpAddress);

            }
            else {
                $ntpAddress = $factory->getTextField("ntpAddress", '', '');
                $ntpAddress->setOptional(true);
                $ntpAddress->setMaxLength(50);
                $ntpAddress->setCurrentLabel($i18n->get("[[base-time.ntpAddress]]"));
                $ntpAddress->setDescription($i18n->getWrapped("[[base-time.ntpAddress_help]]"));
                $step_3->addHtmlComponent($ntpAddress);
            }

            // Set Label and Description manually:
            $BxPage->setLabel('ntpAddress', $i18n->get("[[base-time.ntpAddress]]"), $i18n->get("[[base-time.ntpAddress_help]]"));

            //
            //-- Step #4: Finalize
            //

            $step_4_title = $i18n->get("wiz_finalize", "base-wizard");
            $step_4_title_sub = $i18n->get("wiz_finalize_help", "base-wizard");

            $step_4 = $factory->getSimpleBlock(" ", $i18n);

            $finalize_blurb_header = $factory->getRawHTML("finalize_blurb_header", '<p><H3>' . $i18n->get("finalize_blurb_header") . '</H3></p>');
            $step_4->addHtmlComponent(
              $finalize_blurb_header,
              $factory->getLabel("finalize_blurb_header"), $defaultPage
            );

            $finalize_blurb_text = $factory->getRawHTML("finalize_blurb_text", '<p>' . $i18n->get("finalize_blurb_text") . '</p>');
            $step_4->addHtmlComponent(
              $finalize_blurb_text,
              $factory->getLabel("finalize_blurb_text"), $defaultPage
            );

            $finalize_help_us = $factory->getRawHTML("finalize_help_us", '<p>' . $i18n->get("finalize_help_us") . '</p>');
            $step_4->addHtmlComponent(
              $finalize_help_us,
              $factory->getLabel("finalize_help_us"), $defaultPage
            );

            $PayPal = '
                        <div align="center">
                            <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=KTKZNMW3F2WUU" target="_blank">
                                <img src="https://www.paypalobjects.com/en_US/DE/i/btn/btn_donateCC_LG.gif" alt="PayPal - The safer, easier way to pay online!" />
                            </a>
                        </div>' . "\n";

            $donate = $factory->getRawHTML("finalize_help_us", $PayPal);
            $step_4->addHtmlComponent(
              $donate,
              $factory->getLabel("finalize_help_us"), $defaultPage
            );

            // Register with BlueOnyx: No reason to get paranoid. We just track
            // the usage of the Wizard and get to know your servers IP and which
            // version of BlueOnyx you are using. The Serial Number is usually 
            // empty at this point and is passed along, too. Beyond this no further
            // tracking is done.
            $CI->serverScriptHelper = new ServerScriptHelper($BX_SESSION['sessionId'], $BX_SESSION['loginName']);
            $CI->cceClient = $CI->serverScriptHelper->getCceClient();
            if (file_exists("/proc/user_beancounters")) {
                // VENET interface:
                $venetNetObj = $CI->cceClient->find('Network', 
                                    array(
                                        'device' => 'venet0:0'
                                        ));
                $venetNet = $CI->cceClient->get($venetNetObj[0]);
                $venetNetipAddr = $venetNet['ipaddr'];
            }
            $productBuild = $System['productBuild'];
            if (isset($dev['eth0'])) {
                $ipaddr = $dev['eth0']['ipaddr'];
            }
            elseif (isset($venetNetipAddr)) {
                $ipaddr = $venetNetipAddr;
            }
            else {
                $ipaddr = $System['gateway'];
            }
            $serialNumber = $System['serialNumber'];

            //
            //--- Error handling:
            //

            // If we have errors, they're in a format like this:
            //
            //      Array
            //      (
            //          [0] => CceError Object
            //              (
            //                  [code] => 302 BAD DATA
            //                  [oid] => 17
            //                  [key] => makeErr
            //                  [message] => "[[base-cce.unknownAttr]]"
            //                  [vars] => Array
            //                      (
            //                          [code] => 302 BAD DATA
            //                          [oid] => 17
            //                          [key] => makeErr
            //                      )
            //              )
            //      )
            //
            // So that's an array containing separate Error Objects. But we might as well get an
            // Array that contains an Array with an Error instead of an CceError Object. We need
            // to handle this flexibly.

            $errors_string = '';
            // Toplevel $errors is an Array? If not we simply ignore it.
            if (is_array($errors)) {
                // It is an Array.
                if (count($errors) > 0) {
                    // It has one or more elements. Loop through them:
                    foreach ($errors as $key => $value) {
                        if (!is_object($value)) {
                            // Not an Object, but an Array?
                            if (is_array($value)) {
                                // Grrr .... got another array inside the array? Deal with it:
                                foreach ($value as $newkey => $newvalue) {
                                    $errors_string .= $newvalue;
                                }
                            }
                            else {
                                // No separate array insite the error array? Out with it:
                                $errors_string .= $value;
                            }
                        }
                        else {
                            // Error is an Object? Nice. Deal with that, too:
                            if (is_array($value->vars)) {
                                // CceError Object has vars set. Use them:
                                $errors_string .= ErrorMessage($i18n->get($value->message, "", $value->vars)) . "<br>";
                            }
                            else {
                                // CceError Object has no vars set. Fine, too:
                                $errors_string .= ErrorMessage($i18n->get($value->message)) . "<br>";
                            }
                        }
                    }
                }
            }

            // Assemble data:
            $data = array(
                'charset' => $charset,
                'localization' => $localization,
                'elmer_style_css' => '/.elm/dist/css/style.css',
                'loginName' => 'admin',
                'page_title' => $hostname_new . ': ' . $i18n->get("[[base-wizard.iso_wizard_title]]"),
                'errors' => $errors_string,
                'fullName' => 'Administrator',
                'layout' => $layout,
                'extra_headers' => $extra_headers,
                'body_open_tag' => '<body>',
                'overlay' => '',
                'debug' => '',
                'iso_wizard_title' => $i18n->get("[[base-wizard.iso_wizard_title]]"),
                'step_1_title' => $step_1_title,
                'step_1_title_sub' => $step_1_title_sub,
                'step_1' => $step_1->toHtml(),
                'step_2_title' => $step_2_title,
                'step_2_title_sub' => $step_2_title_sub,
                'step_2' => $step_2->toHtml(),
                'step_3_title' => $step_3_title,
                'step_3_title_sub' => $step_3_title_sub,
                'step_3' => $step_3->toHtml(),
                'step_4_title' => $step_4_title,
                'step_4_title_sub' => $step_4_title_sub,
                'step_4' => $step_4->toHtml(),
                'next' => $i18n->get("[[palette.next]]"),
                'previous' => $i18n->get("[[palette.previous]]"),
                'done' => $i18n->get("[[palette.done]]"),
                'productBuild' => $productBuild,
                'ipaddr' => $ipaddr,
                'serialNumber' => $serialNumber,
                'extra_footers' => $extra_footers,
            );

            // Show the Wizard Page:
            return view('../../Modules/Base/Wizard/Views/wizard_view_elmer', $data);
        }
        else {
            // Flip through reloads until System Object is there:
            return WizardElmer::wizard_reload();
        }
    }
}

/*
Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
?>