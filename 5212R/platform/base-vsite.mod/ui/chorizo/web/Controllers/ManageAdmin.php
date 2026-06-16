<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class ManageAdmin extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }
    
    /**
     * Index
     *
     * @return View
     */
    public function index() {

        $CI = get_instance();

        if (!$CI->getAllowed('systemAdministrator')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/manageAdmin");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Prepare data:
        //

        // Set up possible capabilities:
        $possible_caps = array(
                                'serverShowActiveMonitor' => 1,
                                'serverInformation' => 1,
                                'serverHttpd' => 1,
                                'serverFTP' => 1,
                                'serverEmail' => 1,
                                'serverDNS' => 1,
                                'serverSNMP' => 1,
                                'serverShell' => 1,
                                'serverSSL' => 1,
                                'serverNetwork' => 1,
                                'serverIpPooling' => 1,
                                'serverPower' => 1,
                                'serverTime' => 1,
                                'serverServerDesktop' => 1,
                                'menuServerServerStats' => 1,
                                'serverActiveMonitor' => 1,
                                'managePackage' => 1,
                                'menuServerSecurity' => 1,
                                'systemAdministrator' => 1
                                );

        if (is_file('/usr/sausalito/license/aventurine.pem')) {
            $possible_caps['ManageIncus'] = 1;
            $possible_caps['ManageVPS'] = 1;
        }

        //'manageSite' => 1, <- Handled via Checkbox for now
        //'siteDNS' => 1,    <- Handled via Checkbox for now

        //'serverVsite' => 1, <- Removed for now. Stand alone it makes no sense.

        // Get 'reseller' CapabilityGroup and get the possible reseller Capabilities from within that:
        $reseller_caps = $CI->cceClient->getObject("CapabilityGroup", array("name" => 'reseller'));
        $possible_reseller_caps = scalar_to_array($reseller_caps['capabilities']);
        $stray_reseller_caps = array('siteAnonFTP', 'manageSite', 'siteShell', 'siteSSL', 'siteAdmin');

        // Caps for legacy PHP DSO that we ignore:
        $ignoreCaps = array('resellerPHP', 'resellerRUID');

        // Build an associative array with Capabilities and their default states:
        $possible_reseller_caps_with_defaults = array();
        foreach ($possible_reseller_caps as $key => $value) {
            if (!in_array($value, $ignoreCaps)) {
                $thisCap = $CI->cceClient->getObject('Capabilities');
                $thisCapValue = $CI->cceClient->get($thisCap['OID'], $value);
                $possible_reseller_caps_with_defaults[$value] = $thisCapValue['capable'];
            }
        }

        //
        //--- Get CODB-Object of interest: 
        //

        // We get our $get_form_data early, as this page handles both Add/Edit of admin-users.

        // Get Support-Settings:
        $Support = $CI->getSupport();
        $get_form_data = $BxPage->getGETPOST('GET');
        $CODBDATA = array('fullName' => '', 'sortName' => '', 'name' => '', 'ui_enabled' => '', 'capLevels' => '');
        if (isset($get_form_data['_oid'])) {
            if ($get_form_data['_oid'] != '') {
                $_oid = $get_form_data['_oid'];
                $tempdata = $CI->cceClient->get($_oid);
                if ($tempdata['CLASS'] != 'User') {
                    // Object is not a User-Object!
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#right-notsofastthere");
                }
                $CurrCaps = scalar_to_array($tempdata['capLevels']);
                if (in_array('adminUser', $CurrCaps)) {
                    $CODBDATA = $tempdata;
                }
                else {
                    // Sneaky bastard. Trying to modify something you're not supposed to modify?
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#notsofastthere");
                }
            }
        }

        // A 'systemAdministrator' tries to edit or delete himself. Can't have that!
        if (($user['systemAdministrator'] == "1") && ($CODBDATA['name'] == $BX_SESSION['loginName'])) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#etutbrutus");
        }

        //
        //--- Handle User Deletion:
        //

        if (isset($get_form_data['DELETE'])) {
            if ($get_form_data['DELETE'] == "1") {
                if (isset($_oid)) {
                    if (!is_file("/etc/DEMO")) {

                        // If this user has 'root' access or is 'systemAdministrator',
                        // then we take his elevated abilities away first:
                        $ok = $CI->cceClient->set($_oid, 'RootAccess', array('enabled' => '0'));
                        $errors = array_merge($errors, $CI->cceClient->errors());

                        $ok = $CI->cceClient->set($_oid, '', array('systemAdministrator' => '0'));
                        $errors = array_merge($errors, $CI->cceClient->errors());

                        $ok = $CI->cceClient->set($_oid, 'Shell', array('enabled' => 0));
                        $errors = array_merge($errors, $CI->cceClient->errors());

                        // Now with that out of the way we delete him:
                        $ok = $CI->cceClient->destroy($_oid);
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }
                }
                if (count($errors) == "0") {
                    // Return to this page and display errors - if there are any.
                    // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                    $BxPage->ReturnToThisPage($errors, "/vsite/adminList?done");
                }
                else {
                    // Return to this page and display errors - if there are any.
                    // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                    $BxPage->ReturnToThisPage($errors, "/vsite/adminList?edone");
                }
            }
        }

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_password_repeat");

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
            $errors = $BxPage->getErrors();
        }

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // For new users a password MUST be set:
            if (!isset($_oid)) {
                // Check Password match:
                $passwd = "";
                if (isset($form_data['password'])) {
                    $passwd = $form_data['password'];
                }
                $passwd_repeat = "";
                if (isset($form_data['_password_repeat'])) {
                    $passwd_repeat = $form_data['_password_repeat'];
                }
                if (bx_pw_check($i18n, $passwd, $passwd_repeat) != "") {
                    $errors[] = bx_pw_check($i18n, $passwd, $passwd_repeat);
                }

                // Support-Module has a reserved username for the support account:
                if ((isset($Support['support_account'])) && (!isset($_oid))) {
                    if ($Support['support_account'] == $attributes['userName']) {
                        $errors[] = ErrorMessage($i18n->get("[[base-support.Error_support_account_reserved]]"));
                    }
                }
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        $is_create_Admin = 0;

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Remove the special capabilities from the user's current ones:
            if (isset($attributes['adminPowers'])) {
                $current_caps = $CI->cceClient->scalar_to_array($attributes['adminPowers']);
            }
            else {
                $attributes['adminPowers'] = "";
                $current_caps = array();
            }

            if (!in_array('adminUser', $current_caps)) {
                $current_caps[] = 'adminUser';
            }

            // Hack root access back out
            $rootAccess = 0;
            if (($key = array_search('rootAccess', $current_caps)) !== false) {
                unset($current_caps[$key]);
                $rootAccess = 1;
            }

            // Hack out systemAdministrator's except for user 'admin':
            $systemAdministrator = 0;
            if (($key = array_search('systemAdministrator', $current_caps)) !== false) {
                unset($current_caps[$key]);
                $systemAdministrator = 1;
                $rootAccess = 1;
            }

            // Add 'manageSite' to $current_caps if the checkbox was ticked:
            if (isset($attributes['manageSite'])) {
                if ($attributes['manageSite'] == '1') {
                    $current_caps[] = 'manageSite';
                }
            }

            // Add 'siteDNS' to $current_caps if the checkbox was ticked:
            if (isset($attributes['siteDNS'])) {
                if ($attributes['siteDNS'] == '1') {
                    $current_caps[] = 'siteDNS';
                }
            }

            // Handle create of user if necessary:
            if (!isset($_oid)) {
                $is_create_Admin = 1;
                $big_ok = $CI->cceClient->create('User',
                                array(
                                    'fullName' => $attributes['fullName'],
                                    'sortName' => "",
                                    'name' => $attributes['userName'],
                                    'password' => $attributes['password'],
                                    'capLevels' => $CI->cceClient->array_to_scalar($current_caps)
                                    ));

                // CCE errors that might have happened during submit to CODB:
                $errors = array_merge($errors, $CI->cceClient->errors());

                // Get the OID of this transaction:
                if ($big_ok) {
                    $_oid = $big_ok;
                }
            }
            else {
                // It's an existing user and we update him:

                $ui_enabled = "0";
                if (isset($attributes['suspend'])) {
                    if ($attributes['suspend'] == "1") {
                        $ui_enabled = "0";
                    }
                    else {
                        $ui_enabled = "1";
                    }
                }

                $new_settings = array(
                                    'fullName' => $attributes['fullName'],
                                    'sortName' => "",
                                    'capLevels' => $CI->cceClient->array_to_scalar($current_caps),
                                    'ui_enabled' => $ui_enabled
                                    );

                if (isset($attributes['password'])) {
                    if ($attributes['password'] != "") {
                        $new_settings['password'] = $attributes['password'];
                    }
                }

                $big_ok = $CI->cceClient->set($_oid, '', $new_settings);
                $errors = array_merge($errors, $CI->cceClient->errors());

                if ((isset($Support['support_account'])) && (!isset($_oid))) {
                    if ($Support['support_account'] == $attributes['userName']) {
                        $errors[] = ErrorMessage($i18n->get("[[base-support.Error_support_account_reserved]]"));
                    }
                }

                // Handle expiry date changes:
                if ((isset($Support['support_account'])) && (isset($_oid))) {
                    // Run if SAExpiry is supplied, this is the defined support account *and* it has an expiry defined to begin with:
                    if ((isset($attributes['SAExpiry'])) && ($Support['support_account'] == $attributes['userName']) && ($Support['access_epoch'] != '0')) {
                        // Puzzle the date and time back together:
                        if (isset($attributes['_SAExpiry_day']) && isset($attributes['_SAExpiry_month']) && isset($attributes['_SAExpiry_year']) && isset($attributes['_SAExpiry_hour']) && isset($attributes['_SAExpiry_minute'])) {
                            $attributes['SAExpiry'] = mktime ($attributes['_SAExpiry_hour'], $attributes['_SAExpiry_minute'], '00', $attributes['_SAExpiry_month'], $attributes['_SAExpiry_day'], $attributes['_SAExpiry_year']);
                        }
                        // Update expiry date in CODB:
                        $sup_cfg = array('access_epoch' => $attributes['SAExpiry']);
                        $CI->cceClient->setObject("System", $sup_cfg, "Support");
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }
                }

            }

            // --- Added for DEBUG
            if ($is_create_Admin === 1) {
                $iterations = 0;
                $CABA = $CI->cceClient->get($big_ok);
                if (!isset($CABA['OID'])) {
                    // Yeah, this is ugly. The creation of the serverAdmin can take a moment. We really need to wait some time
                    // here before we progress with any extra steps related to the creation. So we try GET requests in a loop
                    // if the first attempt didn't return an OID. As long as $CABA itself reports '-1' the Object isn't there yet.
                    // We try GET requests until the Object is there *or* we reached 20 iterations (~100 seconds) w/o result.
                    while (($CABA <= 0) && ($iterations <= 20)) {
                        sleep(5);
                        $CABA = $CI->cceClient->get($big_ok);
                        $iterations++;
                    }
                }
                // If we don't have an OID from the User object yet, then we bail:
                if (!isset($CABA['OID'])) {
                    $errors[] = ErrorMessage('Unable to create serverAdmin User. Timeout during creation. Please try again.<br>&nbsp;');
                    unset($_oid);
                    $big_ok = FALSE;
                }
            }
            // --- Added for DEBUG

            // Set the disk quota:
            if ($big_ok) {
                $attributes['diskQuota'] = preg_replace('/\,/', '.', $attributes['diskQuota']);
                $diskQuota = floor(unsimplify_number($attributes['diskQuota'], "KB")/1024);
                $CI->cceClient->set($_oid, 'Disk', array('quota' => $diskQuota));
                $errors = array_merge($errors, $CI->cceClient->errors());
            }

            // Set the root access flag:
            if ($big_ok) {
                $ok = $CI->cceClient->set($_oid, 'RootAccess', array('enabled' => $rootAccess));
                $errors = array_merge($errors, $CI->cceClient->errors());
            }

            // Set the systemAdministrator flag:
            if ($big_ok) {
                $ok = $CI->cceClient->set($_oid, '', array('systemAdministrator' => $systemAdministrator));
                $errors = array_merge($errors, $CI->cceClient->errors());
            }

            // Handle Shell access:
            // Granted if Shell is ticked, OR user is systemAdministrator OR has rootAccess:
            if ($big_ok) {
                if (($attributes['shell'] == "1") || ($systemAdministrator == "1") || ($rootAccess == "1")) {
                    $ok = $CI->cceClient->set($_oid, 'Shell', array('enabled' => 1));
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }
                else {
                    $ok = $CI->cceClient->set($_oid, 'Shell', array('enabled' => 0));
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }
            }

            // Set Site Management information
            if ($big_ok) {
                $attributes['siteQuota'] = preg_replace('/\,/', '.', $attributes['siteQuota']);
                $siteQuota = unsimplify_number($attributes['siteQuota'], "K");
                $CI->cceClient->set($_oid, 'Sites',
                    array('quota' => ($siteQuota == '' ? '0' : $siteQuota),
                          'max' => ($attributes['siteMax'] == '' ? '0' : $attributes['siteMax']),
                          'user' => ($attributes['siteUser'] == '' ? '0' : $attributes['siteUser'])));
                $errors = array_merge($errors, $CI->cceClient->errors());
            }

            // Handle 'resellerPowers' if the user has 'manageSite' Capability:
            if ($big_ok) {
                if ((in_array('manageSite', $current_caps)) && (isset($attributes['resellerPowers']))) {
                    // Get current User object:
                    $tempResData = $CI->cceClient->get($_oid);
                    $tempCurrCaps = scalar_to_array($tempResData['capabilities']);
                    foreach ($possible_reseller_caps as $key => $value) {
                        // Remove all reseller caps from currently used caps:
                        if (($key = array_search($value, $tempCurrCaps)) !== false) {
                            unset($tempCurrCaps[$key]);
                        }
                    }
                    $modified_settings = array(
                        'capabilities' => $CI->cceClient->array_to_scalar(array_unique(array_merge($tempCurrCaps, $CI->cceClient->scalar_to_array($attributes['resellerPowers']))))
                        );
                    $big_ok = $CI->cceClient->set($_oid, '', $modified_settings);
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }
                else {
                    $tmpresellerPowers = array();
                    if (isset($_oid)) {
                        // Get current User object:
                        $tempResData = $CI->cceClient->get($_oid);
                        $tempCurrCaps = scalar_to_array($tempResData['capabilities']);
                        foreach ($possible_reseller_caps as $key => $value) {
                            // Remove all reseller caps from currently used caps:
                            if (($key = array_search($value, $tempCurrCaps)) !== false) {
                                unset($tempCurrCaps[$key]);
                            }
                        }

                        if ((!isset($attributes['manageSite'])) || ($attributes['manageSite'] == '0')) {
                            foreach ($stray_reseller_caps as $key => $value) {
                                // Remove all reseller caps from currently used caps:
                                if (($key = array_search($value, $tempCurrCaps)) !== false) {
                                    unset($tempCurrCaps[$key]);
                                }
                            }
                        }

                        $modified_settings = array(
                            'capabilities' => $CI->cceClient->array_to_scalar(array_unique(array_merge($tempCurrCaps, $tmpresellerPowers)))
                            );
                        $big_ok = $CI->cceClient->set($_oid, '', $modified_settings);
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }
                }
            }

            // Out with the errors:
            foreach ($errors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                if ((isset($objData->message)) && (isset($objData->key))) {
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
            }

            $redirect_URL = '/vsite/adminList';
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $previous_URL = $_SERVER['HTTP_REFERER'];
            }
            else {
                $previous_URL = $_SERVER['REQUEST_URI'];
            }

            if (count($errors) == "0") {
                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
            else {
                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, $previous_URL);
            }
        }

        //
        //-- Generate page:
        //

        $iam = "/vsite/manageAdmin";
        if (isset($get_form_data['_oid'])) {
            if ($get_form_data['_oid'] != '') {
                $iam = "/vsite/manageAdmin?_oid=" . $get_form_data['_oid'];
            }
        }

        // Prepare Page:
        $BxPage->setFormUrl($iam);
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_manageAdmin');
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";
        $advancedPage = "advancedSettingsTab";

        $block = $factory->getPagedBlock("manageAdmin", array($defaultPage, $advancedPage));

        // Modify getPagedBlock()'s lable based on if we add/modify a user:
        if (isset($_oid)) {
            $block->setCurrentLabel($i18n->get('manageAdmin', false, array('name' => $CODBDATA['name'])));
        }
        else {
            $block->setCurrentLabel($i18n->get('createAdminUser', false));
        }

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        // Add Divider:
        $ffs = $factory->addBXDivider("userInformation", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("userInformation", false),
                $defaultPage
                );  

        // Full name field
        $ffs = $factory->getFullName('fullName', bx_charsetsafe($CODBDATA['fullName']));
        $block->addFormField(
            $ffs,
            $factory->getLabel('fullName'),
            $defaultPage
            );

        // Add the sort name field if necessary. Not sure what this is, though.
        if ($i18n->getProperty('needSortName') == 'yes') {
            $sortName = $factory->getFullName('sortNameField', $CODBDATA['sortName']);
            $sortName->setOptional('silent');
            $block->addFormField(
                $sortName,
                $factory->getLabel('sortNameField'),
                $defaultPage
            );
        }

        // If this is a create, add the username field
        if (!isset($_oid)) {
            $ffs = $factory->getUserName('userName');
            $block->addFormField(
                $ffs,
                $factory->getLabel('userNameCreate'),
                $defaultPage
                );
        }
        else {
            $uname_field = $factory->getUserName('userName', $CODBDATA['name'], "r");
            $block->addFormField(
                $uname_field,
                $factory->getLabel('userName'),
                $defaultPage
            );
        }

        // Don't pass back data for password fields
        $pass_field = $factory->getPassword('password');
        if (isset($_oid)) {
            $pass_field->setOptional(TRUE);
        }    

        $block->addFormField(
            $pass_field,
            $factory->getLabel('userPassword'),
            $defaultPage
            );

        if (isset($_oid)) {
            $disk = $CI->cceClient->get($_oid, 'Disk');
            $displayed_quota = simplify_number($disk['quota']*1024*1024, "KB", "2");
        }
        else {
            $displayed_quota = "200M";
        }

        $disk_quota = $factory->getTextField('diskQuota', $displayed_quota, 1);
        $disk_quota->setOptional(FALSE);
        $disk_quota->setType('memdisk');
        $block->addFormField(
            $disk_quota,
            $factory->getLabel('userDiskQuota'),
            $defaultPage
            );

        // Server Admin Shell
        if (isset($_oid)) {
            $userShell = $CI->cceClient->get($_oid, 'Shell');
        }
        else {
            $userShell['enabled'] = '0';
        }
        $ffs = $factory->getBoolean('shell', ($userShell['enabled'] ? 1 : 0));
        $block->addFormField(
            $ffs,
            $factory->getLabel('userShell'),
            $defaultPage);

        // Add suspend check box to be consistent
        $suspend_ui = "1";
        if ($CODBDATA['ui_enabled'] == "1") {
            $suspend_ui = "0";
        }
        if (isset($_oid)) {
            $ffs = $factory->getBoolean('suspend', $suspend_ui);
            $block->addFormField(
                    $ffs,
                    $factory->getLabel('suspendUser'),
                    $defaultPage
                    );
        }

        //
        //--- Reseller Controls
        //

        // Display site controls
        $ffs = $factory->addBXDivider("adminSites_new", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("adminSites_new", false),
                $defaultPage
                );

        $Currcaps = $CI->cceClient->scalar_to_array($CODBDATA['capLevels']);

        $resCAP['manageSite'] = '0';
        if (in_array('manageSite', $Currcaps)) {
            $resCAP['manageSite'] = '1';
        }
        $resCAP['siteDNS'] = '0';
        if (in_array('siteDNS', $Currcaps)) {
            $resCAP['siteDNS'] = '1';
        }

        // Checkbox for capLevel 'manageSite':
        $ffs = $factory->getBoolean('manageSite', $resCAP['manageSite']);
        $block->addFormField(
            $ffs,
            $factory->getLabel('CapManageSite'),
            $defaultPage);

        // Checkbox for capLevel 'siteDNS':
        $ffs = $factory->getBoolean('siteDNS', $resCAP['siteDNS']);
        $block->addFormField(
            $ffs,
            $factory->getLabel('CapSiteDNS'),
            $defaultPage);

        if (isset($_oid)) {
            $site = $CI->cceClient->get($_oid, 'Sites');
            $sites_quota = ($site['quota'] == -1 ? '' : $site['quota']);
            $sites_quota = simplify_number($sites_quota*1000, "K", "2");
            $sites_max = ($site['max'] == -1 ? '' : $site['max']);
            $sites_user = ($site['user'] == -1 ? '' : $site['user']);
        }
        else {
            $sites_quota = "500M";
            $sites_max = 5;
            $sites_user = 100;
        }

        $site_quota = $factory->getInteger('siteQuota', $sites_quota, 1);
        $site_quota->setOptional('silent');
        $site_quota->setType('memdisk');
        $block->addFormField(
            $site_quota,
            $factory->getLabel('userSitesQuota'),
            $defaultPage
            );

        $site_max = $factory->getInteger('siteMax', $sites_max, 1);
        $site_max->setOptional('silent');
        $block->addFormField(
            $site_max,
            $factory->getLabel('userSitesMax'),
            $defaultPage
            );

        $site_user = $factory->getInteger('siteUser', $sites_user, 1);
        $site_user->setOptional('silent');
        $block->addFormField(
            $site_user,
            $factory->getLabel('userSitesUser'),
            $defaultPage
            );

        //
        //--- 'manageSite' extraCaps:
        //

        // Get strings to use as labels
        list($caps_oid) = $CI->cceClient->find('Capabilities');
        $possible_reseller_labels = array();
        foreach ($possible_reseller_caps_with_defaults as $cap => $junk) {
            $ns = $CI->cceClient->get($caps_oid, $cap);
            $possible_reseller_labels[$cap] = $i18n->get($ns['nameTag']);
        }

        $reseller_allowed_caps = array();
        $reseller_allowed_labels = array();
        if (isset($_oid)) {
            if (count($CI->cceClient->scalar_to_array($CODBDATA['capabilities'])) > "0") {
                $resCaps = $CI->cceClient->scalar_to_array($CODBDATA['capabilities']);
            }
            else {
                $resCaps = array();
            }
        }
        else {
            $resCaps = array();
            foreach ($possible_reseller_caps_with_defaults as $key => $value) {
                if ($value == "1") {
                    $resCaps[] =  $key;
                }
            }
        }

        foreach ($resCaps as $capability) {
            if (isset($possible_reseller_caps_with_defaults[$capability])) {
                $reseller_allowed_caps[] = $capability;
                $reseller_allowed_labels[] = $possible_reseller_labels[$capability];
            }
        }

        // If this account is the support-account, then we do not allow anyone to
        // modify the capabilities OR the extraCaps. You can delete the account,
        // but you cannot revoke capabilities. For that purpose we set the 
        // getSetSelector()'s to read-only, which is a bit of a hack. As the 
        // bloody getSetSelector() didn't really support it yet and I had to hack
        // that capability into the code. Which required a change of CCEClient's
        // scalar_to_array() as well <sigh>.
        $cap_access = 'rw';
        if ((isset($Support['support_account'])) && (isset($_oid))) {
            // This is a support-account AND we're editing it.
            if ($Support['support_account'] == $CODBDATA['name']) {
                // In that case we set it to read-only:
                $cap_access = 'r';
            }
        }

        $select_reseller_caps = $factory->getSetSelector('resellerPowers',
                                $CI->cceClient->array_to_scalar($reseller_allowed_labels), 
                                $CI->cceClient->array_to_scalar($possible_reseller_labels),
                                'allowedAbilities', 'disallowedAbilities',
                                $cap_access, 
                                $CI->cceClient->array_to_scalar($reseller_allowed_caps),
                                $CI->cceClient->array_to_scalar(array_keys($possible_reseller_caps_with_defaults))
                            );

        $select_reseller_caps->setOptional(true);

        $block->addFormField($select_reseller_caps, 
                    $factory->getLabel('resellerPowers'),
                    $defaultPage
                    );

        // Hmmm .... not ideal. Need to throw in a spacer or the getSetSelector() displays oddly:
        $ffs = $factory->getRawHTML("Spacer", '<IMG BORDER="0" WIDTH="120" HEIGHT="0" SRC="/libImage/spaceHolder.gif">');
        $block->addFormField(
            $ffs,
            $factory->getLabel("Spacer"),
            $defaultPage
        );

        // Add Divider:
        $ffs = $factory->addBXDivider("adminOptions_new", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("adminOptions_new", false),
                $advancedPage
                );  

        // Show a text description of what this tab is for:
        if ($BX_SESSION['gui_theme'] === 'elmer') {
            $adminOptions_desc_txt = $i18n->getHtml("[[base-vsite.adminOptions_desc]]") . '<br><br>';
        }
        else {
            $adminOptions_desc_txt = '<br>' . $i18n->getHtml("[[base-vsite.adminOptions_desc]]");
        }
        $adminOptions_desc = $factory->getHtmlField("adminOptions_desc", $adminOptions_desc_txt, 'r');
        $adminOptions_desc->setLabelType("nolabel");
        $block->addFormField(
                $adminOptions_desc,
                $factory->getLabel("adminOptions_desc"),
                $advancedPage
                );

        //
        //--- Get the capabilities and populate the getSetSelector():
        //

        // display admin controls
        if (isset($_oid)) {
            $root_access = $CI->cceClient->get($_oid, 'RootAccess');
        }

        // Get strings to use as labels
        list($caps_oid) = $CI->cceClient->find('Capabilities');
        $possible_labels = array();
        foreach ($possible_caps as $cap => $junk) {
            $ns = $CI->cceClient->get($caps_oid, $cap);
            if (isset($ns['nameTag'])) {
                $possible_labels[$cap] = $i18n->get($ns['nameTag']);
            }
            else {
                $possible_labels[$cap] = $cap;
            }
            
        }

        $allowed_caps = array();
        $allowed_labels = array();
        $caps = array();
        if (is_array($CODBDATA['capLevels'])) {
            if (count($CODBDATA['capLevels']) > "0") {
                $caps = $CI->cceClient->scalar_to_array($CODBDATA['capLevels']);
            }
        }

        foreach ($caps as $capability) {
            if (isset($possible_caps[$capability])) {
                $allowed_caps[] = $capability;
                $allowed_labels[] = $possible_labels[$capability];
            }
        }

        if (isset($root_access['enabled'])) {
            if ($root_access['enabled'] == "1") {
                $allowed_labels[] = $i18n->get('[[base-vsite.rootAccess]]');
                $allowed_caps[] = 'rootAccess';
            }
        }

        $possible_labels['rootAccess'] = $i18n->get('[[base-vsite.rootAccess]]');
        $possible_caps['rootAccess'] = 1;

        // Manually add 'systemAdministrator' if the flag is set for this User:
        if (isset($CODBDATA['systemAdministrator'])) {
            if ($CODBDATA['systemAdministrator'] == "1") {
                $CODBDATA[] = $i18n->get('[[base-vsite.cap_systemAdministrator]]');
                $allowed_caps[] = 'systemAdministrator';
            }
        }

        if (is_file('/usr/sausalito/license/aventurine.pem')) {
            $ave_capcheck = [];
            if (!is_array($CODBDATA['capLevels'])) {
                $ave_capcheck = $CI->cceClient->scalar_to_array($CODBDATA['capLevels']);
            }

            if (in_array('ManageVPS', $ave_capcheck)) {
                $CODBDATA[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageVPS]]');
                $allowed_caps[] = 'ManageVPS';
                $allowed_labels[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageVPS]]');
            }
            if (in_array('ManageIncus', $ave_capcheck)) {
                $CODBDATA[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageIncus]]');
                $allowed_caps[] = 'ManageIncus';
                $allowed_labels[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageIncus]]');

                $CODBDATA[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageVPS]]');
                $allowed_caps[] = 'ManageVPS';
                $allowed_labels[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageVPS]]');
            }

            // A 'systemAdministrator' gets it anyway:
            if ((isset($CODBDATA['systemAdministrator'])) && ($CODBDATA['systemAdministrator'] === '1')) {
                $CODBDATA[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageIncus]]');
                $allowed_caps[] = 'ManageIncus';
                $allowed_labels[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageIncus]]');

                $CODBDATA[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageVPS]]');
                $allowed_caps[] = 'ManageVPS';
                $allowed_labels[] = $i18n->get('[[solarspeed-aventurine.capgroup_ManageVPS]]');
            }
        }

        // If this account is the support-account, then we do not allow anyone to
        // modify the capabilities OR the extraCaps. You can delete the account,
        // but you cannot revoke capabilities. For that purpose we set the 
        // getSetSelector()'s to read-only, which is a bit of a hack. As the 
        // bloody getSetSelector() didn't really support it yet and I had to hack
        // that capability into the code. Which required a change of CCEClient's
        // scalar_to_array() as well <sigh>.
        $cap_access = 'rw';
        if ((isset($Support['support_account'])) && (isset($_oid))) {
            // This is a support-account AND we're editing it.
            if ($Support['support_account'] == $CODBDATA['name']) {
                // In that case we set it to read-only:
                $cap_access = 'r';

                // Add explanation why the capabilities cannot be changed if this is the support-account:
                $sa_desc = $factory->getTextField("sa_desc", $i18n->get("[[base-support.sa_desc_manageAdmin]]"), 'r');
                $sa_desc->setLabelType("nolabel");
                $block->addFormField(
                        $sa_desc,
                        $factory->getLabel("sa_desc", false),
                        $defaultPage
                        );

                // Show expiry date of this account:
                if ($Support['access_epoch'] != "0") {
                    $sa_epoch = $factory->getTimeStamp("SAExpiry", $Support['access_epoch'], "r");
                    $sa_epoch->setFormat("datetime");
                    $block->addFormField(
                      $sa_epoch,
                      $factory->getLabel("SAExpiry"),
                      $defaultPage
                    );
                }
                else {
                    // If the account is set to never expire, we show that as getTextField() instead:
                    $sa_epoch = $factory->getTextField("SAExpiry", $i18n->get("[[base-support.never]]"), 'r');
                    $block->addFormField(
                            $sa_epoch,
                            $factory->getLabel("SAExpiry", false),
                            $defaultPage
                            );
                }
            }
        }

        $select_caps = $factory->getSetSelector('adminPowers',
                                $CI->cceClient->array_to_scalar($allowed_labels), 
                                $CI->cceClient->array_to_scalar($possible_labels),
                                'allowedAbilities', 'disallowedAbilities',
                                $cap_access, 
                                $CI->cceClient->array_to_scalar($allowed_caps),
                                $CI->cceClient->array_to_scalar(array_keys($possible_caps))
                            );

        $select_caps->setOptional(true);

        $block->addFormField($select_caps, 
                    $factory->getLabel('adminPowers'),
                    $advancedPage
                    );


        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/vsite/adminList"));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

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