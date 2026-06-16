<?php 
namespace Wizard\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("ServerScriptHelper.php");
include_once("Product.php");
use I18n;
use ServerScriptHelper;
use Product;

//class Vsite extends Controller
class WizardElmerProper extends BaseController {
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

        if ((!isset($_COOKIE['gui_theme'])) || ($_COOKIE['gui_theme'] === 'adminica')) {
            // We hard wire the Wizard to use 'elmer' for now:
            $CI->setBX_SESSION_GuiTheme('elmer');
            setcookie("gui_theme", 'elmer', "0", "/");
            $BX_SESSION['gui_theme'] = 'elmer';
            if (isset($BX_SESSION['loginUser']['gui_theme'])) {
                $BX_SESSION['loginUser']['gui_theme'] = 'elmer';
            }

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $currentUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

            bx_error_log("Wizard.index(): No cookie 'gui_theme' yet. Setting it and redirecting.");

            header("Location: $currentUrl");
            exit;

        }

        // We hard wire the Wizard to use 'elmer' for now:
        $CI->setBX_SESSION_GuiTheme('elmer');
        setcookie("gui_theme", 'elmer', "0", "/");
        $BX_SESSION['gui_theme'] = 'elmer';
        if (isset($BX_SESSION['loginUser']['gui_theme'])) {
            $BX_SESSION['loginUser']['gui_theme'] = 'elmer';
        }

        if ($BX_SESSION['sessionId'] == "") {

            //
            //--- Try default login details:
            //

            $BX_SESSION['loginName'] = 'admin';
            $defaultPW = 'blueonyx';
            $BX_SESSION['sessionId'] = $CI->cceClient->auth($BX_SESSION['loginName'], $defaultPW);

            if (!empty($BX_SESSION['sessionId'])) {

                $serverScriptHelper = new ServerScriptHelper($BX_SESSION['sessionId'], $BX_SESSION['loginName']);
                $CI->setSSH($serverScriptHelper);
                $System = $CI->getSystem();
                $CI->setCCE($serverScriptHelper->getCceClient());
                $cceClient = $CI->getCCE();
                $serverScriptHelper = $CI->getSSH();
                $locale = $BX_SESSION['locale'];
                $BX_SESSION = $CI->getBX_SESSION();
            }
            else {
                //
                //-- Default login doesn't work. We need to ask for username and password:
                //
                header("Location: /login?action=wizard");
                exit;
            }
        }

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
        }
        else {
            $is_aws = "0";
        }

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
        setcookie("locale", $locale, time()+31536000, "/");

        $i18n = new I18n("base-wizard", $locale);

        // Form fields that are required to have input:
        $required_keys = array();

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();
        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_serialized_errors");

        if ((is_array($form_data)) && ($this->request->getPost(NULL, NULL, TRUE))) {

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
            // params: $BxPage              our already declared $BxPage Object (for storing validation Errors)
            // return:                      array with keys and values ready to submit to CCE.
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);

            // Get potential errors that GetFormAttributes() ran into from $BxPage:
            $errors = array_merge($errors, $BxPage->getErrors());
        }

        //
        //--- Own error checks:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) === 0) && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Sample data:
            //
            //  Array
            //  (
            //      [localeField] => en_US
            //      [hasAliaseseth0] => 0
            //      [oldTime] => 1707018560
            //      [oldTimeZone] => America/Lima
            //      [license_acceptance] => 1
            //      [hostNameField] => tb
            //      [domainNameField] => smd.net
            //      [dnsAddressesField] => &8.8.8.8&1.1.1.1&
            //      [gatewayField] => 208.77.151.193
            //      [gatewayFieldOrig] => 208.77.151.193
            //      [gatewayField_IPv6] => 
            //      [gatewayFieldOrig_IPv6] => 
            //      [ipAddressFieldeth0] => 208.77.151.220
            //      [netMaskFieldeth0] => 255.255.255.224
            //      [IPv6_ipAddressField1] => 
            //      [macAddressFieldeth0] => C4:37:72:F3:58:04
            //      [ipAddressOrigeth0] => 208.77.151.220
            //      [netMaskOrigeth0] => 255.255.255.224
            //      [bootprotoFieldeth0] => none
            //      [enabledeth0] => 0
            //      [deviceList] => %5B%22eth0%22%5D
            //      [adminNameField] => admin
            //      [newPasswordField] => password
            //      [_newPasswordField_repeat] => password
            //      [sql_rootpassword] => password
            //      [systemDate] => 2024-02-03 10:50 PM
            //      [timezoneSelectDropdown] => America/Lima
            //      [ntpAddress] => pool.ntp.org
            //  )

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
        if ((count($errors) === 0) && ($this->request->getPost(NULL, NULL, TRUE))) {

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

            $date = strtotime($attributes['systemDate']);
            if ($date and ($date != $attributes['oldTime'])) {
                $time = $date;
            }
            if (!isset($time)) {
                $time = time();
            }

            $ntpEnabled = '1';
            if ($attributes['ntpAddress'] == '') {
                $ntpEnabled = '0';
            }

            // Actual submit to CODB:
            // "deferCommit" is used by the setup wizard, not here... clean up just in case
            $CI->cceClient->setObject('System', array(
                                        'deferCommit' => '0',
                                        'epochTime' => $time,
                                        'timeZone' => $timeZone,
                                        'ntpAddress' => $attributes['ntpAddress'],
                                        'ntpEnabled' => $ntpEnabled
                                        ), 'Time');

            // Work around for Sausalito oddity. We use the extra handler to set the timezone instead:
            $CI->cceClient->setObject('System', array(
                                        'epochTime' => $time,
                                        'timeZone' => $timeZone,
                                        'ntpAddress' => $attributes['ntpAddress'],
                                        'trigger' => time()
                                        ), 'TempTime');

            $CI->serverScriptHelper->shell("/usr/sausalito/sbin/setTime " . $time . " " . $timeZone . " " . $attributes['ntpAddress'] . " true", $output, "root", $CI->BX_SESSION['sessionId']);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            //
            //-- Set Network:
            //

            //    [hostNameField] => tb
            //    [domainNameField] => smd.net
            //    [dnsAddressesField] => &8.8.8.8&1.1.1.1&
            //    [gatewayField] => 208.77.151.193
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

            if ((!file_exists("/proc/user_beancounters")) && (!file_exists("/etc/is_aws"))) {
                // Handle all Network Interfaces:
                $devices = find_eth_ifaces();
                if (isset($attributes['deviceList'])) {
                    $devices = json_decode(urldecode($attributes['deviceList']));
                }

                $devdetect = get_primary_interface();

                foreach ($devices as $i => $value) {
                    $admin_if_errors = array();
                    $var_name = "ipAddressField" . $devices[$i];
                    $ip_field = $attributes[$var_name];
                    $var_name = "ipAddressOrig" . $devices[$i];
                    $ip_orig = $attributes[$var_name];
                    $var_name = "netMaskField" . $devices[$i];
                    $nm_field = $attributes[$var_name];
                    $var_name = "netMaskOrig" . $devices[$i];
                    $nm_orig = $attributes[$var_name];
                    $var_name = "bootprotoField" . $devices[$i];
                    $boot_field = $attributes[$var_name];

                    $var_name_IPv6_ipAddressField = "IPv6_ipAddressField" . $devices[$i];
                    $IPv6_ipAddressField = $attributes[$var_name_IPv6_ipAddressField];

                    if ($boot_field === 'Manual') {
                        $boot_field = 'none';
                    }
                    if ($boot_field === 'DHCP') {
                        $boot_field = 'dhcp';
                    }

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
                                    'ipaddr_IPv6' => $IPv6_ipAddressField,
                                    'enabled' => '1',
                                    'bootproto' => $boot_field,
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
                                'ipaddr_IPv6' => $IPv6_ipAddressField,
                                'enabled' => '1',
                                'bootproto' => $boot_field,
                                'refresh' => time()
                                ),
                           '', array('device' => $devices[$i]));

                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // If the primary IP changed, redirect to the new IP:
                    if (($devices[$i] === $devdetect) && ($ip_field != $ip_orig)) {
                        $redirect_to_new_ip = $ip_field;
                    }
                }
            }

            //
            //-- Finalize if we have no errors:
            //

            if (count($errors) === 0) {
                $CI->cceClient->setObject('System', array('isLicenseAccepted' => '1', 'isRegistered' => '0'), '');

                // Send cookies that expire in one hour:
                setcookie("loginName", 'admin', time()+60*60*24*365, "/");
                if ($BX_SESSION['sessionId'] != "") {
                    setcookie("sessionId", $BX_SESSION['sessionId'], "0", "/");
                }

                // Set new locale to cookie, too, but set an expiry of 365 days:
                setcookie("locale", $attributes['localeField'], "31536000", "/");

                //
                //-- Set Theme cookies for Adminica (just in case):
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

                // Update Network-Settings for real:
                $CI->cceClient->setObject('System', array('nw_update' => time()));

                $BxPage->setErrors($errors);

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
                    header("Location: https://$redirect_to_new_ip:" . $BX_SESSION['GUI_PORT'] . "/gui");
                    exit;
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
            // Generate a "please wait" page via WizardElmerProper::wizard_reload():
            $good_system_info = "FALSE";
            return WizardElmerProper::wizard_reload();
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

        //
        //-- Check if we are authed against CCEd:
        //

        if ($good_system_info == "TRUE") {

            // Get page title:
            $setup_desc = $i18n->get("[[base-wizard.iso_wizard_title]]");

            // Prepare Page:
            $BxPage->setFormUrl("/wizard");
            $BxPage->setErrors($errors);
            $BxPage->setOutOfStyle('WIZARD');

            // Set Menu items:
            $BxPage->setVerticalMenu('base_controlpanel');
            $page_module = 'gui';

            $step_1 = 'wizard_locale_header';
            $step_2 = 'wizard_license_header';
            $step_3 = $i18n->get("serverconfig", "base-alpine");
            $step_4 = $i18n->get("wiz_finalize", "base-wizard");

            $block = $factory->getPagedBlock($setup_desc, array($step_1, $step_2, $step_3, $step_4));

            //$block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setShowAllTabs(FALSE);
            $block->setDefaultPage($step_1);
            $block->setBlueOnyxHeader(TRUE);

            //
            //--- Step 1: Language:
            //

            // Locale selector:
            $localeField = $factory->getLocale("localeField", $locale);
            $localeField->setPossibleLocales(array('en_US', 'da_DK', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'ja_JP', 'nl_NL', 'pt_PT'));
            $block->addFormField(
              $localeField,
              $factory->getLabel("localeField"), 
              $step_1
            );

            //
            //--- Step 2: License:
            //

            $licenseClick = $factory->getRawHTML("licenseClick", '<h6>' . $i18n->get("licenseClick") . '</h6><br>');
            $block->addFormField(
              $licenseClick,
              $factory->getLabel("licenseClick"), 
              $step_2
            );

            $license = $factory->getRawHTML("license", '<hr style="margin-botton: 10px"><p>' . $i18n->get("license") . '</p><hr style="margin-botton: 10px">', 'html');
            $block->addFormField(
              $license,
              $factory->getLabel("license"), 
              $step_2
            );

            $Year = date('Y');

            $licTextBody =<<<HTML
            ------ SUN-modified-BSD-License for BlueOnyx: ------
            Copyright (c) 2008-$Year Michael Stauber, SOLARSPEED.NET
            Copyright (c) 2008-$Year Team BlueOnyx, BLUEONYX.IT
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
            nuclear facility.
            HTML;

            $licText = $factory->getHtmlField("licText", '<pre>' . $licTextBody . '</pre>', 'html');
            $licText->setLabelType("nolabel");
            $block->addFormField(
              $licText,
              $factory->getLabel("licText"), 
              $step_2
            );

            $license_accept_block = $factory->getBoolean("license_acceptance", '0');
            $block->addFormField(
              $license_accept_block,
              $factory->getLabel("license_acceptance"), 
              $step_2
            );

            //
            //--- Step 2: System Settings:
            //

            // Network settings

            // Add divider:
            $netdivider = $factory->addBXDivider("networkSettings", "");
            $block->addFormField(
                    $netdivider,
                    $factory->getLabel("networkSettings", false),
                    $step_3
                    );

            // host and domain names:
            if (($System['hostname'] == 'localhost') && ($System['domainname'] == '')) {
                // assume this is first boot if domainname is not set
                $defaultHostname = '';
            }
            else {
                $defaultHostname = $System['hostname'];
            }

            $hostfield = $factory->getDomainName("hostNameField", $defaultHostname, $fieldprot);
            $domainfield = $factory->getDomainName("domainNameField", $System["domainname"], $fieldprot);
            $fqdn = $factory->getCompositeFormField(array($hostfield, $domainfield), '&nbsp;.&nbsp;');
            $block->addFormField(
                $fqdn,
                $factory->getLabel("enterFqdn"), 
                $step_3
            );

            // DNS:
            $dns = $factory->getIpAddressList("dnsAddressesField", $System["dns"], $fieldprot);
            $dns->setOptional(true);
            $dns->setType('ipaddr_list_IPv4IPv6');
            $block->addFormField(
              $dns,
              $factory->getLabel("dnsAddressesField"),
              $step_3
            );

            // Gateway IPv4:
            $product = new Product($CI->cceClient);
            if ($product->isRaq()) {
                if ($is_aws == "1") {
                    if (!isset($System["gateway"])) {
                        // AWS and Gateway not defined. Make it editable:
                        $gwFprot = 'rw';
                    }
                    else {
                        if ($System["gateway"] == "") {
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
                    // Not AWS. Allow edits if they are allowed for any of
                    // the other network related fields:
                    $gwFprot = $fieldprot;
                }
                $gw = $factory->getIpAddress("gatewayField", $System["gateway"], $gwFprot);
                $gw->setOptional(true);
                $block->addFormField($gw, $factory->getLabel("gatewayField"), $step_3);

                $gatewayFieldOrig = $factory->getIpAddress("gatewayFieldOrig", $System["gateway"], "");
                $block->addFormField(
                        $gatewayFieldOrig,
                        $factory->getLabel("gatewayField"),
                        $step_3
                        );
            }

            // Gateway IPv6:
            if ($product->isRaq()) {
                if ($is_aws == "1") {
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
                    // Not AWS, but OpenVZ Container: 
                    if (in_array($System['IPType'], array('VZv4', 'VZv6', 'VZBOTH'))) {
                        $gwFprot = '';
                    }
                    else {
                        // Allow edits if they are allowed for any of
                        // the other network related fields:
                        $gwFprot = $fieldprot;
                    }
                }
                $gw_IPv6 = $factory->getIpAddress("gatewayField_IPv6", $System["gateway_IPv6"], $gwFprot);
                $gw_IPv6->setOptional(true);
                $gw_IPv6->setType('ipaddrIPv6');
                $gw_IPv6->setCurrentLabel($i18n->get("[[base-network.gatewayField_IPv6]]", false));
                $gw_IPv6->setDescription($i18n->get("[[base-network.gatewayField_IPv6_help]]", false));
                $block->addFormField($gw_IPv6, 
                    $factory->getLabel("[[base-network.gatewayField_IPv6]]"), 
                    $step_3
                );

                $gatewayFieldOrig_IPv6 = $factory->getIpAddress("gatewayFieldOrig_IPv6", $System["gateway_IPv6"], "");
                $block->addFormField(
                        $gatewayFieldOrig_IPv6,
                        $factory->getLabel("gatewayField_IPv6"),
                        $step_3
                        );
            }

            // Primary Interface:
            //
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

            $primary_interface = get_primary_interface();
            $have_interfaces = find_eth_ifaces();

            if (isset($dev[$primary_interface])) {
                $ipaddr = $dev[$primary_interface]['ipaddr'];
                $IPv6_ipaddr = $dev[$primary_interface]['ipaddr_IPv6'];
                $netmask = $dev[$primary_interface]['netmask'];
                $device = $dev[$primary_interface]['device'];
                $mac = $dev[$primary_interface]['mac'];
                $enabled = $dev[$primary_interface]['enabled'];
                $bootproto = $dev[$primary_interface]['bootproto'];

                if ($bootproto === 'dhcp') {
                    $ipaddr = get_primary_ipv4_ip($primary_interface);
                    $IPv6_ipaddr = get_primary_ipv6_ip($primary_interface);
                    $netmask = get_primary_ipv4_netmask($primary_interface);
                }
                
                $ip_label = '[[base-network.ipAddressField1]]';
                $nm_label = '[[base-network.netMaskField1]]';
                $ipV6_label = '[[base-network.IPv6_ipAddressField1]]';

                // Add divider:
                $divider = $factory->addBXDivider("interface$device", "");
                $divider->setCurrentLabel($i18n->get("[[base-network.interface$device]]", false));
                $block->addFormField(
                        $divider,
                        $factory->getLabel("[[base-network.interface$device]]", false),
                        $step_3
                        );

                if (($is_aws == "0") && ($bootproto == 'none')) {
                    $devprot = "rw";
                }
                else {
                    $devprot = "r";
                }

                // Bootproto:
                $proto_Choices = array("none" => "Manual", "dhcp" => "DHCP");
                $proto_select = $factory->getMultiChoice("bootprotoField$device", array_values($proto_Choices));
                $proto_select->setSelected($proto_Choices[$bootproto], true);
                $block->addFormField($proto_select, $factory->getLabel("bootprotoField"), $step_3);

                // IP Address:
                $ip_field0 = $factory->getIpAddress("ipAddressField$device", $ipaddr, $devprot);
                $ip_field0->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ip_field0->setCurrentLabel($i18n->get($ip_label, true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field0->setDescription($i18n->getWrapped('[[base-network.ipAddressField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field0->setOptional(true);
                $block->addFormField(
                        $ip_field0,
                        $factory->getLabel($ip_label, true,
                        array(), array('name' => "[[base-network.help$device]]")),
                        $step_3
                    );

                // Netmask:
                $netmask_field0 = $factory->getIpAddress("netMaskField$device", $netmask, $devprot);
                $netmask_field0->setInvalidMessage($i18n->getJs('netMaskField_invalid'));

                // Netmask is not optional for the admin iface and for eth0
                $netmask_field0->setOptional(false);
                $netmask_field0->setCurrentLabel($i18n->get($nm_label, true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field0->setDescription($i18n->getWrapped('[[base-network.netMaskField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field0->setOptional(true);
                
                $block->addFormField(
                        $netmask_field0,
                        $factory->getLabel($nm_label, true,
                        array(), array('name' => "[[base-network.help$device]]")),
                        $step_3
                    );

                // IPv6 IP-Address:
                $ipv6_field0 = $factory->getIpAddress("IPv6_ipAddressField$device", $IPv6_ipaddr, $devprot);
                $ipv6_field0->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ipv6_field0->setCurrentLabel($i18n->get($ipV6_label, true, array(), array('name' => "[[base-network.help$device]]")));
                $ipv6_field0->setDescription($i18n->getWrapped('[[base-network.IPv6_ipAddressField_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ipv6_field0->setOptional(true);
                $ipv6_field0->setType('ipaddrIPv6');
                $block->addFormField(
                        $ipv6_field0,
                        $factory->getLabel($ipV6_label, true,
                        array(), array('name' => "[[base-network.help$device]]")),
                        $step_3
                    );

                // MAC Address:
                $mac0 = $factory->getMacAddress("macAddressField$device", $mac, "r");
                $mac0->setCurrentLabel($i18n->get('[[base-network.macAddressField]]', true));
                $mac0->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));
                $block->addFormField(
                        $mac0,
                        $factory->getLabel("macAddressField"),
                        $step_3
                    );

                // retain orginal information
                $has_aliases = $factory->getBoolean("hasAliases$device", 0, '');
                $block->addFormField(
                        $has_aliases,
                        $step_3
                    );

                $x_orig_ip = $factory->getIpAddress("ipAddressOrig$device", $ipaddr, "");
                $block->addFormField(
                        $x_orig_ip,
                        '',
                        $step_3
                    );

                $x_netMaskOrig = $factory->getIpAddress("netMaskOrig$device", $netmask, "");
                $block->addFormField(
                        $x_netMaskOrig,
                        "",
                        $step_3
                    );

                $x_enabled = $factory->getBoolean("enabled$device", $enabled, "");
                $block->addFormField(
                        $x_enabled,
                        "",
                        $step_3
                    );

                // Remove primary interface from list:
                $device_to_remove = $primary_interface;

                // Find the index of the device to remove
                $index = array_search($device_to_remove, $have_interfaces);

                // If the device is found, remove it
                if ($index !== false) {
                    unset($have_interfaces[$index]);
                }

                // Reindex the array to maintain numeric keys
                $have_interfaces = array_values($have_interfaces);
            }

            foreach ($have_interfaces as $device) {

                if (isset($dev[$device])) {
                    $ipaddr = $dev[$device]['ipaddr'];
                    $IPv6_ipaddr = $dev[$device]['ipaddr_IPv6'];
                    $netmask = $dev[$device]['netmask'];
                    $device = $dev[$device]['device'];
                    $mac = $dev[$device]['mac'];
                    $enabled = $dev[$device]['enabled'];
                    $bootproto = $dev[$device]['bootproto'];

                    if ($bootproto === 'dhcp') {
                        $ipaddr = get_primary_ipv4_ip($device);
                        $IPv6_ipaddr = get_primary_ipv6_ip($device);
                        $netmask = get_primary_ipv4_netmask($device);
                    }

                    if ($enabled == "0") {
                        $ipaddr = "";
                        $netmask = "";
                    }

                    if (($is_aws == "0") && ($bootproto == 'none')) {
                        $devprot = "rw";
                    }
                    else {
                        $devprot = "r";
                    }

                    $ip_label = 'ipAddressField1';
                    $nm_label = 'netMaskField1';

                    // Add divider:
                    $divider = $factory->addBXDivider("interface$device", "");
                    $divider->setCurrentLabel($i18n->get("[[base-network.interface$device]]", false));
                    $block->addFormField(
                            $divider,
                            $factory->getLabel("[[base-network.interface$device]]", false),
                            $step_3
                        );

                    // Bootproto:
                    $proto_Choices = array("none" => "Manual", "dhcp" => "DHCP");
                    $proto_select = $factory->getMultiChoice("bootprotoField$device", array_values($proto_Choices));
                    $proto_select->setSelected($proto_Choices[$bootproto], true);
                    $block->addFormField($proto_select, $factory->getLabel("bootprotoField"), $step_3);

                    $ip_field1 = $factory->getIpAddress("ipAddressField$device", $ipaddr, $devprot);
                    $ip_field1->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                    $ip_field1->setCurrentLabel($i18n->get('[[base-network.ipAddressField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                    $ip_field1->setDescription($i18n->getWrapped('[[base-network.ipAddressField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                    $ip_field1->setOptional(true);

                    $block->addFormField(
                            $ip_field1,
                            $factory->getLabel($ip_label, true,
                            array(), array('name' => "[[base-network.help$device]]")),
                            $step_3
                        );

                    $netmask_field1 = $factory->getIpAddress("netMaskField$device", $netmask, $devprot);
                    $netmask_field1->setInvalidMessage($i18n->getJs('netMaskField_invalid'));
                    $netmask_field1->setEmptyMessage($i18n->getJs('netMaskField_empty', 'base-network', array('interface' => "[[base-network.interface$device]]")));
                    $netmask_field1->setCurrentLabel($i18n->get('[[base-network.netMaskField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                    $netmask_field1->setDescription($i18n->getWrapped('[[base-network.netMaskField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                    $netmask_field1->setOptional(true);
                    
                    $block->addFormField(
                            $netmask_field1,
                            $factory->getLabel($nm_label, true,
                            array(), array('name' => "[[base-network.help$device]]")),
                            $step_3
                        );

                    // IPv6 IP-Address:
                    $ipv6_field0 = $factory->getIpAddress("IPv6_ipAddressField$device", $IPv6_ipaddr, $devprot);
                    $ipv6_field0->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                    $ipv6_field0->setCurrentLabel($i18n->get($ipV6_label, true, array(), array('name' => "[[base-network.help$device]]")));
                    $ipv6_field0->setDescription($i18n->getWrapped('[[base-network.IPv6_ipAddressField_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                    $ipv6_field0->setOptional(true);
                    $ipv6_field0->setType('ipaddrIPv6');
                    $block->addFormField(
                            $ipv6_field0,
                            $factory->getLabel($ipV6_label, true,
                            array(), array('name' => "[[base-network.help$device]]")),
                            $step_3
                        );

                    // MAC:
                    $macaddress_field1 = $factory->getMacAddress("macAddressField$device", $mac, "r");
                    $macaddress_field1->setCurrentLabel($i18n->get('[[base-network.macAddressField]]', true));
                    $macaddress_field1->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));
                    $block->addFormField(
                            $macaddress_field1,
                            $factory->getLabel("macAddressField"),
                            $step_3
                        );

                    // retain orginal information
                    $y_has_aliases = $factory->getBoolean("hasAliases$device", 0, '');
                    $block->addFormField(
                            $y_has_aliases,
                            $step_3
                        );

                    $y_orig_ip = $factory->getIpAddress("ipAddressOrig$device", $ipaddr, "");
                    $block->addFormField(
                            $y_orig_ip,
                            '',
                            $step_3
                            );

                    $y_netMaskOrig = $factory->getIpAddress("netMaskOrig$device", $netmask, "");
                    $block->addFormField(
                            $y_netMaskOrig,
                            "",
                            $step_3
                        );

                    $y_enabled = $factory->getBoolean("enabled$device", $enabled, "");
                    $block->addFormField(
                            $y_enabled,
                            "",
                            $step_3
                        );
                }            
            }

            // Add list of seen Network devices:
            $j_list = $factory->getTextField("deviceList", urlencode(json_encode($deviceList)), "");
            $block->addFormField(
                    $j_list,
                    "",
                    $step_3
                );

            //
            //-- Admin Password:
            //

            // Add divider:
            $wizardAdminDivider = $factory->addBXDivider("wizardAdmin", "");
            $block->addFormField(
                    $wizardAdminDivider,
                    $factory->getLabel("wizardAdmin", false),
                    $step_3
                );

            // User-Name:
            $adminName = $factory->getFullName("adminNameField", 'admin', 'r');
            $adminName->setOptional(TRUE);
            $block->addFormField(
                    $adminName,
                    $factory->getLabel("adminNameField"),
                    $step_3
                );

            // Password:
            $mypw = $factory->getPassword("newPasswordField", "", "rw");
            $mypw->setConfirm(TRUE);
            $mypw->setOptional(FALSE);
            $mypw->setCheckPass(TRUE);
            $block->addFormField(
                    $mypw,
                    $factory->getLabel("newPasswordField"), 
                    $step_3
                );

            //
            //--- MySQL password:
            //

            // Add divider:
            $wizardAdminDivider = $factory->addBXDivider("wizardMySQLpassHeader", "");
            $block->addFormField(
                    $wizardAdminDivider,
                    $factory->getLabel("wizardMySQLpassHeader", false),
                    $step_3
                );

            // sql_rootpassword:
            $sqlpwd = $factory->getPassword("sql_rootpassword", "", "rw");
            $sqlpwd->setConfirm(TRUE);
            $sqlpwd->setOptional(FALSE);
            $sqlpwd->setCheckPass(TRUE);
            $block->addFormField(
                    $sqlpwd,
                    $factory->getLabel("sql_rootpassword"), 
                    $step_3
                );

            //
            //-- Timezone:
            //

            // Add divider:
            $wizardAdminDivider = $factory->addBXDivider("wizardTime", "");
            $block->addFormField(
                    $wizardAdminDivider,
                    $factory->getLabel("wizardTime", false),
                    $step_3
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

            $DatePickerField = $factory->getDatePicker("systemDate", $t, "datetime", 'rw');
            $DatePickerField->setCurrentLabel($i18n->get('[[base-time.systemDisplayedDate]]'));
            $DatePickerField->setDescription($i18n->get('[[base-time.systemDisplayedDate_help]]'));
            $DatePickerField->setModus('all');
            $block->addFormField(
                $DatePickerField, 
                $factory->getLabel('systemDisplayedDate'),
                $step_3
            );

            $oldTime = $factory->getTimeStamp("oldTime", $t, "time", "");
            $block->addFormField(
                $oldTime,
                $step_3
            );

            $SystemDisplayedTimeZone = $factory->getTimeZone("systemTimeZone", $CODBDATA["timeZone"], 'rw');
            $SystemDisplayedTimeZone->setCurrentLabel($i18n->get("[[base-time.systemDisplayedTimeZone]]"));
            $SystemDisplayedTimeZone->setDescription($i18n->get("[[base-time.systemDisplayedTimeZone_help]]"));
            $block->addFormField(
                $SystemDisplayedTimeZone, 
                $factory->getLabel("systemDisplayedTimeZone"),
                $step_3
            );

            // Set Label and Description manually:
            $BxPage->setLabel('systemTimeZone', $i18n->get("[[base-time.systemDisplayedTimeZone]]"), $i18n->get("[[base-time.systemDisplayedTimeZone_help]]"));

            $oldTimeZone = $factory->getTextField("oldTimeZone", $CODBDATA["timeZone"], "");
            $block->addFormField(
                $oldTimeZone,
                $step_3
            );

            // NTP server may only be set on stand alone servers, not in a VPS:
            if (empty($CODBDATA["ntpAddress"])) {
                $CODBDATA["ntpAddress"] = 'pool.ntp.org';
            }

            if (!is_file("/proc/user_beancounters")) {
                $ntpAddress = $factory->getNetAddress("ntpAddress",$CODBDATA["ntpAddress"]);
                $ntpAddress->setOptional(true);
                $ntpAddress->setMaxLength(50);
                $ntpAddress->setCurrentLabel($i18n->get("[[base-time.ntpAddress]]"));
                $ntpAddress->setDescription($i18n->getWrapped("[[base-time.ntpAddress_help]]"));
                $block->addFormField(
                    $ntpAddress,
                    $factory->getLabel("ntpAddress"),
                    $step_3
                );
            }
            else {
                $ntpAddress = $factory->getTextField("ntpAddress", '', '');
                $ntpAddress->setOptional(true);
                $ntpAddress->setMaxLength(50);
                $ntpAddress->setCurrentLabel($i18n->get("[[base-time.ntpAddress]]"));
                $ntpAddress->setDescription($i18n->getWrapped("[[base-time.ntpAddress_help]]"));
                $block->addFormField(
                    $ntpAddress,
                    $factory->getLabel("ntpAddress"),
                    $step_3
                );
            }

            // Set Label and Description manually:
            $BxPage->setLabel('ntpAddress', $i18n->get("[[base-time.ntpAddress]]"), $i18n->get("[[base-time.ntpAddress_help]]"));

            //
            //-- Step #4: Finalize
            //

            $finalize_blurb_header = $factory->getRawHTML("finalize_blurb_header", '<div class="mb-15"><H6>' . $i18n->get("finalize_blurb_header") . '</H6></div>');
            $block->addFormField(
              $finalize_blurb_header,
              $factory->getLabel("finalize_blurb_header"), $step_4
            );

            $finalize_blurb_text = $factory->getRawHTML("finalize_blurb_text", '<div class="mb-15">' . $i18n->get("finalize_blurb_text") . '</div>');
            $block->addFormField(
              $finalize_blurb_text,
              $factory->getLabel("finalize_blurb_text"), $step_4
            );

            $finalize_help_us = $factory->getRawHTML("finalize_help_us", '<div class="mb-15">' . $i18n->get("finalize_help_us") . '</div>');
            $block->addFormField(
              $finalize_help_us,
              $factory->getLabel("finalize_help_us"), $step_4
            );

            $PayPal = '
                        <div align="center">
                            <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=KTKZNMW3F2WUU" target="_blank">
                                <img src="https://www.paypalobjects.com/en_US/DE/i/btn/btn_donateCC_LG.gif" alt="PayPal - The safer, easier way to pay online!" />
                            </a>
                        </div>' . "\n";

            $donate = $factory->getRawHTML("finalize_help_us", $PayPal);
            $block->addFormField(
              $donate,
              $factory->getLabel("finalize_help_us"), $step_4
            );

            //
            //--- Add Buttons:
            //

            $save_help = $i18n->get('[[palette.save_help]]');
            $disabled_save_help = $i18n->get('[[palette.disabled_save_help]]'); // 'Not all required fields have been filled out.'

            $save_button_script =<<<HTML
                <!-- Only unlock Save button if form validates, license is accepted, and system date is not empty -->
                <script>
                    $(document).ready(function() {
                        // Initialize Bootstrap tooltip
                        $('#SaveButton').tooltip();

                        function updateSaveButtonState() {
                            var isFormValid = $('#waiting_overlay').find(':input').toArray().every(function(input) {
                                return input.checkValidity();
                            });
                            var isLicenseAccepted = $('#license_acceptance').is(':checked');
                            var isSystemDateSet = $('#waiting_overlay input#systemDate').val().trim() !== '';
                            var isEnabled = isFormValid && isLicenseAccepted && isSystemDateSet;

                            $('#SaveButton').prop('disabled', !isEnabled);

                            // Update tooltip based on the button's state
                            var message = isEnabled ? '$save_help' : '$disabled_save_help';
                            $('#SaveButton').attr('title', message).tooltip('fixTitle');

                            if(!isEnabled) {
                                $('#SaveButton').on('mouseover', function() {
                                    $(this).tooltip('show');
                                });
                            } else {
                                $('#SaveButton').off('mouseover');
                            }
                        }

                        $('#waiting_overlay').validator().on('validate.bs.validator', function (e) {
                            updateSaveButtonState();
                        });

                        $('#waiting_overlay').on('change input', function() {
                            updateSaveButtonState();
                        });  

                        $('#waiting_overlay').on('submit', function(e) {
                            if (!this.checkValidity()) {
                                e.preventDefault(); // Prevent form submission
                                e.stopPropagation(); // Stop propagation of the event
                            }
                        });

                        // Check state initially in case the checkbox is pre-checked or form is pre-filled
                        updateSaveButtonState();
                    });
                </script>
                <!-- /Only unlock Save button if form validates, license is accepted, and system date is not empty -->
            HTML;

            $BxPage->setExtraFooters($save_button_script);

            // Add the buttons
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/wizard"));

            $page_body[] = $block->toHtml();

            // Out with the page:
            return $BxPage->render($page_module, $page_body);
        }
        else {
            // Flip through reloads until System Object is there:
            return WizardElmerProper::wizard_reload();
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