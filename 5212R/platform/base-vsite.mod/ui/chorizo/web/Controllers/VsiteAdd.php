<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class VsiteAdd extends BaseController {
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

        //helper(['form']);

        $CI =& get_instance();
        if (!$CI->getAllowed('manageSite')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();

        // Streamlined System Object/NameSpace fetching:
        $all_System_data = $CI->cceClient->getAll("System", array());
        $all_System_data = reset($all_System_data);
        $System = $all_System_data['OBJECT'];
        $CI->cceClient->set($System['OID'], "PHP_mgmt", array('version_update' => time()));
        $vsiteDefaults = $all_System_data['VsiteDefaults'];

        //
        //-- Prepare Page:
        //

        $extra_headers = array();
        $access = 'rw';

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/vsiteAdd");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Determine visibility of IP protocol related fields:
        //

        $show_IPv4 = FALSE;
        $show_IPv6 = FALSE;
        $access_ipv6 = 'r';
        if (in_array($System['IPType'], array('IPv4', 'VZv4', 'BOTH', 'VZBOTH'))) {
            $show_IPv4 = TRUE;
            if (in_array($System['IPType'], array('IPv4', 'BOTH'))) {
                if ($System['gateway'] != "") {
                    $show_IPv4 = TRUE;
                }
                else {
                    $show_IPv4 = FALSE;
                }
            }
        }
        if (in_array($System['IPType'], array('IPv6', 'VZv6', 'BOTH', 'VZBOTH'))) {
            $show_IPv6 = TRUE;
            if (in_array($System['IPType'], array('IPv6', 'BOTH'))) {
                if ($System['gateway_IPv6'] != "") {
                    $access_ipv6 = 'rw';
                    $show_IPv6 = TRUE;
                }
                else {
                    $access_ipv6 = 'r';
                    $show_IPv6 = FALSE;
                }
            }
            else {
                // Special case: OpenVZ
                $access_ipv6 = 'rw';
            }
        }

        //
        //--- Handle POST Request:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $this->request->getPost();
        $get_data = $this->request->getGet();

        // Form fields that are required to have input:
        $required_keys = array('hostName', 'domain', 'createdUser', 'volume', 'maxusers');

        $TMPerrors = array();

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();
        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_serialized_errors");

        // Get $errors from ServerScriptHandler POST vars:
        if (isset($form_data['_serialized_errors'])) {
            if (!is_array($form_data['_serialized_errors'])) {
                if (preg_match('/<div(.*)<\/div>/', $form_data['_serialized_errors'], $sematches, PREG_OFFSET_CAPTURE)) {
                    if (is_array($sematches)) {
                        foreach ($sematches as $key => $value) {
                            if (is_array($value)) {
                                foreach ($value as $vkey => $vvalue) {
                                    if (preg_match('/<(.*)>/', $vvalue)) {
                                        $form_data['_serialized_errors'] = array($vkey => '<div' . $vvalue . '</div>');
                                    }
                                }
                            }
                            else {
                                if (preg_match('/<(.*)>/', $vvalue)) {
                                    $form_data['_serialized_errors'] = array($key => '<div' . $value . '</div>');
                                }
                            }
                        }
                    }
                }
                if (is_array($form_data['_serialized_errors'])) {
                    foreach ($form_data['_serialized_errors'] as $vkey => $vvalue) {
                        $form_data['_serialized_errors'] = $vvalue;
                    }
                }
                if (preg_match('/(?<=\[\[).*?(?=\]\])/', $form_data['_serialized_errors'], $sematches, PREG_OFFSET_CAPTURE)) {
                    if (isset($sematches[0][0])) {
                        $value = '[[' . $sematches[0][0] . ']]';
                        $form_data['_serialized_errors'] = $value;
                        $TMPerrors[] = ErrorMessage($i18n->get($form_data['_serialized_errors'], true) . '<br>&nbsp;');
                    }
                }
                else {
                    $TMPerrors[] = array_merge($errors, array($form_data['_serialized_errors']));
                }
            }
            else {
                $TMPerrors[] = array_merge($errors, safe_deserialize($form_data['_serialized_errors']));
            }
            foreach ($TMPerrors as $errNum => $errMsg) {
                if ((!is_object($errMsg)) && (!is_array($errMsg))) {
                    // Error message is not an Object. We urldecode() it and use it as is:
                    $errors[$errNum] = urldecode($errMsg);
                }
                else {
                    if ((isset($errMsg->key)) && (isset($errMsg->message))) {
                        // We already have an error Object. Use it:
                        $errors[$errNum] = ErrorMessage($i18n->get($errMsg->message, true, array('key' => $errMsg->key)) . '<br>&nbsp;');
                    }
                    elseif (isset($errMsg->message)) {
                        // Error message object without key:
                        $errors[$errNum] = ErrorMessage($i18n->get($errMsg->message, true) . '<br>&nbsp;');
                    }
                    else {
                        # Nothing to see.
                    }
                }
            }
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);
        }
        else {

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

                if ((isset($form_data['prefix'])) && (strlen($form_data['prefix']) > "0")) {
                    $attributes['userPrefixEnabled'] = "1";
                }
                else {
                    $attributes['userPrefixEnabled'] = "0";
                }
                if (!isset($attributes['ipAddr'])) {
                    $attributes['ipAddr'] = "";
                }
                if (!isset($attributes['ipaddrIPv6'])) {
                    $attributes['ipaddrIPv6'] = "";
                }                

                //
                //--- Check if siteAdmin Username is available:
                //

                // If a prefix is given, prepend it to the userName:
                if ((isset($attributes['prefix'])) && (!empty($attributes['prefix']))) {
                    $UserNameArray = array($attributes['prefix'], $attributes['userName']);
                    $siteAdminUserName = implode("_", $UserNameArray);
                    
                    // If someone uses a really long username, then a prefix may make it too long.
                    // So we need to check how long the username now is and if need be, we need to shorten it:
                    $unameLength = strlen($siteAdminUserName);
                    if ($unameLength > '31') {
                        // Ok, the name is too long. We need to shorten it back down to 32 characters:
                        $newUserNameShort = (mb_substr($siteAdminUserName, '0', '31'));
                        $siteAdminUserName = $newUserNameShort;
                    }
                }
                else {
                    $siteAdminUserName = $attributes['userName'];
                }

                $userOids = $CI->cceClient->find("User", array("name" => $siteAdminUserName));
                if (count($userOids) > 0) {
                    $errors[] = ErrorMessage($i18n->getClean('[[base-user.userNameAlreadyTaken]]'), 'alert_red', 'alarm_bell', FALSE);
                }

                // Username = Password? Baaaad idea!
                if (strcasecmp($siteAdminUserName, $attributes['passwordField']) === 0) {
                    $errors[] = ErrorMessage($i18n->get("[[base-user.error-password-equals-username]]") . " ". $i18n->get("[[base-user.error-invalid-password]]"));
                }

                // Password Check:
                $passwd = '';
                if (isset($attributes['passwordField'])) {
                    $passwd = $attributes['passwordField'];
                }
                $passwd_repeat = '';
                if (isset($attributes['_passwordField_repeat'])) {
                    $passwd_repeat = $attributes['_passwordField_repeat'];
                }

                if (bx_pw_check($i18n, $passwd, $passwd_repeat) != "") {
                    $errors[] = bx_pw_check($i18n, $passwd, $passwd_repeat);
                }
            }

            //
            //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
            //

            // If we have no errors and have POST data, we submit to CODB:
            if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

                // We have no errors. We submit to CODB.
                $vsiteOID = $CI->cceClient->create("Vsite", 
                             array(
                                'hostname' => $attributes['hostName'],
                                'domain' => $attributes['domain'],
                                'fqdn' => ($attributes['hostName'] . '.' . $attributes['domain']),
                                'ipaddr' => $attributes['ipAddr'],
                                'ipaddrIPv6' => $attributes['ipaddrIPv6'],
                                'createdUser' => $attributes['createdUser'], 
                                'webAliases' => $attributes['webAliases'],
                                'webAliasRedirects' => $attributes['webAliasRedirects'],
                                'emailDisabled' => $attributes['emailDisabled'],
                                'mailAliases' => $attributes['mailAliases'],
                                "mailCatchAll" => $attributes['mailCatchAll'],
                                'volume' => $attributes['volume'],
                                'maxusers' => $attributes['maxusers'],
                                'dns_auto' => $attributes['dns_auto'],
                                'prefix' => $attributes['prefix'],
                                "userPrefixEnabled" => $attributes['userPrefixEnabled'],
                                "userPrefixField" => $attributes['prefix'],
                                'site_preview' => $attributes['site_preview']
                             )
                            );

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Setup Quota information:
                if ($vsiteOID) {
                    $attributes['quota'] = preg_replace('/\,/', '.', $attributes['quota']);
                    // Check if our quota has a unit:
                    $pattern = '/^(\d*[(\.)|(\,)]{0,1}\d+)(K|M|G|T)$/';
                    if (preg_match($pattern, $attributes['quota'], $matches, PREG_OFFSET_CAPTURE)) {
                        // Quota has a unit:
                        $quota = (unsimplify_number($attributes['quota'], "K")/1000);
                    }
                    else {
                        // Quota has no unit:
                        $quota = $attributes['quota'];
                    }

                    // If this is a reseller, check if the disk space changes would make him exceed his allowance:
                    if (!$CI->serverScriptHelper->getAllowed('systemAdministrator')) {
                        // Get a list of all sites he owns: 
                        $Userowned_Sites = $CI->cceClient->find('Vsite', array('createdUser' => $attributes['createdUser'])); 
                        $Quota_of_Userowned_Sites = $quota; // Set start quota to the value of the Quota the user wants this Vsite to have after the change. 
                        foreach ($Userowned_Sites as $oid) { 
                            $user_vsiteDisk = $CI->cceClient->get($oid, 'Disk'); 
                            $Quota_of_Userowned_Sites += $user_vsiteDisk['quota']; 
                        }
                        $Quota_of_Userowned_Sites = $Quota_of_Userowned_Sites*1000;

                        // Get the info about the 'manageSite' administrator:
                        @list($user_oid) = $CI->cceClient->find('User', array('name' => $attributes['createdUser'])); 

                        // Get the site allowance settings for this 'manageSite' user:
                        $AdminAllowances = $CI->cceClient->get($user_oid, 'Sites'); 
                        if ($Quota_of_Userowned_Sites > $AdminAllowances['quota']) {
                            // Reseller is trying to set more quota than he's allowed to:
                            $errors[] = ErrorMessage($i18n->get("[[base-vsite.quota]]") . '<br>&nbsp;');
                        }
                        else {
                            // Set the quota:
                            $ok = $CI->cceClient->set($vsiteOID, 'Disk', array('quota' => $quota));                        
                        }
                    }
                    else {
                        // Not a reseller:

                        // Set the quota:
                        $ok = $CI->cceClient->set($vsiteOID, 'Disk', array('quota' => $quota));
                    }

                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // If the WebApp Installer is present and RoundCube Autoinstall is enabled,
                    // then we might get a weird runtime issue with CCEd. So if the above SET
                    // for diskspace doesn't go through, we wait 30 seconds and try again.
                    // If THAT fails, too, then we do it yet again after 30 secs. If that STILL
                    // fails, we try once more and finally raise an error if the third attempt
                    // buggers out as well.
                    if ($ok === "0") {
                        // Sleep and do it again:
                        sleep(30);
                        $ok = $CI->cceClient->set($vsiteOID, 'Disk', array('quota' => $quota));
                        if ($ok === "0") {
                            // Sleep and do it again:
                            sleep(30);
                            $ok = $CI->cceClient->set($vsiteOID, 'Disk', array('quota' => $quota));
                            if ($ok === "0") {
                                // Sleep and do it again:
                                sleep(30);
                                $ok = $CI->cceClient->set($vsiteOID, 'Disk', array('quota' => $quota));
                                if ($ok === "0") {
                                    $errors[] = ErrorMessage($i18n->get("[[base-vsite.quota]]") . '<br>&nbsp;');
                                }
                            }
                        }
                    }
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }

                /*
                 * Setup services only if the site was created successfully
                 * any errors after site creation above are non-fatal
                 */
                if ($vsiteOID) {
                    // Handle automatically detected services
                    list($servicesoid) = $CI->cceClient->find("VsiteServices");
                    $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
                    $af_errors = $autoFeatures->handle("create.Vsite", array("CCE_SERVICES_OID" => $servicesoid, "CCE_OID" => $vsiteOID), $attributes);
                    $errors = array_merge($errors, $af_errors);
                }
                /*
                 * Defer the httpd reload/restart until 'force_update' is set:
                 */

                if ($vsiteOID) {
                    $ok = $CI->cceClient->set($vsiteOID, '', array('force_update' => time()));
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }

                // Error check:
                if (count($errors) == "0") {

                    //
                    //--- We have no error. Create siteAdmin:
                    //

                    // Get Vsite data:
                    $createdVsite = $CI->cceClient->get($vsiteOID); 

                    // Get Vsite group:
                    if ((isset($createdVsite['name'])) && (!empty($createdVsite['name']))) {
                        $group = $createdVsite['name'];
                    }

                    // Caplevels:
                    $siteAdmCapLevels = '&siteAdmin&';
                    if (isset($attributes['dns_auto'])) {
                        // DNS is enabled, append caps:
                        $siteAdmCapLevels .= 'siteDNS&';
                    }

                    $out_attributes = array(
                                    "name" => $siteAdminUserName, 
                                    "sortName" => "", 
                                    "fullName" =>$attributes['fullNameField'], 
                                    "password" => $attributes['passwordField'], 
                                    "emailDisabled" => $attributes['emailDisabled'],
                                    "ftpDisabled" => '0',
                                    "localePreference" => "browser", 
                                    "stylePreference" => "BlueOnyx", 
                                    "volume" => $attributes['volume'],
                                    "description" => '',
                                    "enabled" => '1',
                                    "capLevels" => $siteAdmCapLevels,
                                    "site" => $group,
                                );

                    // Create the User:
                    $big_ok = $CI->cceClient->create('User', $out_attributes);
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    if (count($errors) == "0") {
                        error_log("No errors. CCE reports User creation as done. User OID is: " . $big_ok);

                        // CREATE User *might* report back an OID before it actually is done. This issue
                        // is under investigation. Meanwhile we throw in this contraption to temporarily
                        // mitigate this obvious runtime issue. 
                        for ($i=1; $i < 7; $i++) { 
                            if ($big_ok) {
                                error_log("Attempt #" . $i . ": Running GET on User Object: " . $big_ok);
                                $BOcreatedUser = $CI->cceClient->get($big_ok, '');
                                if (isset($BOcreatedUser['name'])) {
                                    error_log("User Object: " . $big_ok . " found. Continuing with userAdd procedure.");
                                    $i = 10;
                                }
                                else {
                                    error_log("User Object: " . $big_ok . " NOT yet found. Trying again in 10 seconds.");
                                    sleep('10');
                                }
                            }
                        }
                    }

                    // Check if that really worked and if not, raise an error:
                    if (!isset($BOcreatedUser['name'])) {
                        $errors[] = ErrorMessage($i18n->get("[[base-user.failed-to-add-user]]") . '<br>&nbsp;');
                    }

                    // Set user shell access (same as Vsite)
                    if (count($errors) == "0") {
                        // Reverse Map to get the numerical value for 'Shell_enabled':
                        $Shell_enabled_Map_Reversed = 
                            array(
                                "none" => "0", 
                                "jailed_sftp_scp_rsync" => "1", 
                                "jailed_shell" => "2", 
                                "full_shell" => "3"
                            );

                        $attributes['Shell_enabled'] = $Shell_enabled_Map_Reversed[$attributes['Shell_enabled']];
                        $CI->cceClient->set($big_ok, "Shell", array("enabled" => $attributes['Shell_enabled']));
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }

                    // Set user disk quota (same as Vsite):
                    if (count($errors) == "0") {
                        $CI->cceClient->set($big_ok, "Disk", array("quota" => $quota));
                        // Check if that worked. If not, we try again several times as we might have a run-time error where creation is still in progress:
                        $BOcreatedUserDisk = $CI->cceClient->get($big_ok, 'Disk');
                        if ($BOcreatedUserDisk['quota'] != $quota) {
                            for ($i=1; $i < 7; $i++) { 
                                $CI->cceClient->set($big_ok, "Disk", array("quota" => $quota));
                            }
                        }
                        // Fetch only the last error (if any):
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }

                    // Set additional user settings
                    $CI->cceClient->set($big_ok, "SSH", ["GoogleAuthentication" => $attributes['GoogleAuthentication']]);
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    $use_emailAlias = $vsiteDefaults["siteAdminAliases"];
                    if (($vsiteDefaults["defaultSiteAdminAliasChanged"] == '0') && ($vsiteDefaults["siteAdminAliases"] == '')) {
                        $use_emailAlias = '&webmaster&';
                    }

                    $emailSettings = ["aliases" => $use_emailAlias ];
                    $CI->cceClient->set($big_ok, "Email", $emailSettings);
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // Step 4: Set Vsite 'prefered_siteAdmin' / web owner
                    $CI->cceClient->set($createdVsite['OID'], "PHP", ["prefered_siteAdmin" => $siteAdminUserName]);
                    $errors = array_merge($errors, $CI->cceClient->errors());
                    $CI->cceClient->set($createdVsite['OID'], "PHPVsite", ["force_update" => time()]);
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    //
                    //--- We have no error. Redirect to Vsite-List:
                    //

                    // Return to this page and display errors - if there are any.
                    // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                    $BxPage->ReturnToThisPage($errors, '/vsite/vsiteList');
                }
                else {
                    // We do have an error. And a partially create Vsite. So we destroy it:
                    if ((isset($vsiteOID)) && ($vsiteOID != "1") && ($vsiteOID != "0")) {
                        $ok = $CI->cceClient->destroy($vsiteOID);
                        if ($ok === FALSE) {
                            // If the first destroy() failed, we wait 5 secs and try again:
                            sleep(5);
                            $ok = $CI->cceClient->destroy($vsiteOID);
                            if ($ok === FALSE) {
                                // Try it one more time:
                                sleep(5);
                                $ok = $CI->cceClient->destroy($vsiteOID);
                                if ($ok === FALSE) {
                                    // Pointless. Give up and raise error:
                                    $errors[] = ErrorMessage($i18n->get("[[base-vsite.removeFailed]]") . '<br>&nbsp;');
                                }
                            }
                        }
                    }

                    // Remove duplicate Errors:
                    $errors = array_map("unserialize", array_unique(array_map("serialize", $errors)));
                    // Then we redirect back to this page by passing the errors:
                    if (!empty($_SERVER['HTTP_REFERER'])) {
                        $previous_URL = $_SERVER['HTTP_REFERER'];
                    }
                    else {
                        $previous_URL = $_SERVER['REQUEST_URI'];
                    }
                    // Return to this page and display errors - if there are any.
                    // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                    $BxPage->ReturnToThisPage($errors, $previous_URL);
                }
            }
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteList1');
        $page_module = 'base_sitemanageVSL';

        // Line up the ducks:
        /*
         *  DATA PRESERVATION
         *  One other possible use of the Errors array is to determine whether 
         *  any information needs to be read from CCE.  In the case of Vsite 
         *  addition, if there are errors present then there is no need to 
         *  get information from CCE for most things, because the data that was 
         *  in the fields, when the user clicked save, are available as
         *  global variables.  This should give a slight performance gain, but it isn't
         *  necessary for things to work correctly.
         */

        list($sysoid) = $CI->cceClient->find("System");
        if (count($errors) == 0) {
            // We have no errors. So we use the VsiteDefaults from CODB_
            $vsiteDefaults = $CI->cceClient->get($sysoid, "VsiteDefaults");

            // Add fields of this form that aren't in 'VsiteDefaults':
            $vsiteDefaults['fullNameField'] = '';
            $vsiteDefaults['userName'] = '';

        }       
        else {
            // We have at least one error. Which means this is probably a
            // page reload after a post. Which means we have the post vars
            // and should use them instead of the VsiteDefaults. That way
            // we preserve the data that the user has entered before:
            // $vsiteDefaults = $attributes;
            //
            // Note:
            //
            // At this time this ONLY handles everything (but the password)
            // from the 'Basic Settings'-tab. The 'Services and Features'-tab
            // uses AUTOFEATURES, which operate off their own CODB saved
            // defaults. So we cannot override them that easily here:

            $vsiteDefaults = $CI->cceClient->get($sysoid, "VsiteDefaults");

            // Small correction due to naming inconsistencies:
            if (isset($attributes['ipAddr'])) {
                $vsiteDefaults['ipaddr'] = $attributes['ipAddr'];
            }
            if (isset($attributes['ipaddrIPv6'])) {
                $vsiteDefaults['ipaddrIPv6'] = $attributes['ipaddrIPv6'];
            }
            if (isset($attributes['hostName'])) {
                $vsiteDefaults['hostname'] = $attributes['hostName'];
            }
            if (isset($attributes['domain'])) {
                $vsiteDefaults['domain'] = $attributes['domain'];
            }
            if (isset($attributes['prefix'])) {
                $vsiteDefaults['prefix'] = $attributes['prefix'];
            }
            if (isset($attributes['webAliases'])) {
                $vsiteDefaults['webAliases'] = $attributes['webAliases'];
            }
            if (isset($attributes['webAliasRedirects'])) {
                $vsiteDefaults['webAliasRedirects'] = $attributes['webAliasRedirects'];
            }
            if (isset($attributes['emailDisabled'])) {
                $vsiteDefaults['emailDisabled'] = $attributes['emailDisabled'];
            }
            if (isset($attributes['mailAliases'])) {
                $vsiteDefaults['mailAliases'] = $attributes['mailAliases'];
            }
            if (isset($attributes['mailCatchAll'])) {
                $vsiteDefaults['mailCatchAll'] = $attributes['mailCatchAll'];
            }
            if (isset($attributes['quota'])) {
                $vsiteDefaults['quota'] = $attributes['quota'];;
            }
            if (isset($attributes['maxusers'])) {
                $vsiteDefaults['maxusers'] = $attributes['maxusers'];
            }
            if (isset($attributes['dns_auto'])) {
                $vsiteDefaults['dns_auto'] = $attributes['dns_auto'];
            }

            if (isset($attributes['fullNameField'])) {
                $vsiteDefaults['fullNameField'] = $attributes['fullNameField'];
            }
            else {
                $vsiteDefaults['fullNameField'] = '';
            }

            if (isset($attributes['userName'])) {
                $vsiteDefaults['userName'] = $attributes['userName'];
            }
            else {
                $vsiteDefaults['userName'] = '';
            }
        }

        if (!isset($vsiteDefaults['ipaddr'])) {
            $vsiteDefaults['ipaddr'] = '';
        }
        if (!isset($vsiteDefaults['ipaddrIPv6'])) {
            $vsiteDefaults['ipaddrIPv6'] = '';
        }

        $vsite = $CI->cceClient->get($sysoid, "Vsite"); 
        $vsiteoids = $CI->cceClient->find("Vsite"); 
        if ($vsite['maxVsite'] <= count($vsiteoids)) { 
            // The limit doesn't apply to systemAdministrators! 
            if (!$CI->serverScriptHelper->getAllowed('systemAdministrator')) {
                // But to everyone else:
                $errors[] = ErrorMessage($i18n->get("[[base-vsite.maxVsiteAlreadyMade]]") . '<br>&nbsp;');
            }
        } 

        $defaultPage = "basicSettingsTab";
        $secondPage = "otherServices";

        // Check vsite max for administrator 
        list($user_oid) = $CI->cceClient->find('User', array('name' => $CI->BX_SESSION['loginName'])); 
        $sites = $CI->cceClient->get($user_oid, 'Sites'); 
         
        $user_sites = $CI->cceClient->find('Vsite', array('createdUser' => $CI->BX_SESSION['loginName'])); 
        if ($sites['max'] > 0 && $sites['max'] <= count($user_sites)) { 
            $errors[] = ErrorMessage($i18n->getClean('[[base-vsite.maxVsiteAlreadyMade]]'), 'alert_red', 'alarm_bell', FALSE);

            $settings =& $factory->getPagedBlock("newVsiteSettings", array($defaultPage));

            // Hidden dummy text field. We need one or the error message won't show.
            $error_static = $factory->getTextField("dummy", $i18n->getClean("[[base-vsite.maxVsiteAlreadyMade]]"), '');
            $error_static->setLabelType("nolabel");
            $settings->addFormField(
                    $error_static,
                    $factory->getLabel("dummy"),
                    $defaultPage
                    );

            $settings->addButton($factory->getCancelButton("/vsite/vsiteList"));

            //
            //-- Error message handing:
            //
            $BXerrors = $BxPage->getErrors();
            foreach ($errors as $object => $objData) {
                if (is_object($object)) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $BXerrors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
                else {
                    $BXerrors[] = $objData;
                }
            }

            // Publish error messages:
            $BxPage->setErrors($BXerrors);

            //-- Generate page:
            $page_body[] = $settings->toHtml();

            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();

            // Out with the page:
            return $BxPage->render($page_module, $page_body);
        }
        else { 

            $settings = $factory->getPagedBlock("newVsiteSettings", array($defaultPage, $secondPage));
            $settings->setToggle("#");
            //$settings->setWindow("#");
            //$settings->setGrabber("#");
            $settings->setShowAllTabs("#");
            $settings->setSideTabs(FALSE);
            //$settings->setDefaultPage($secondPage);

            $net_opts = $CI->cceClient->get($sysoid, "Network");
            if ($net_opts["pooling"] == "1") {
                $range_strings = array();

                $reseller_first_range = array('IPv4' => '');
                $reseller_first_range = array('IPv6' => '');

                $oids = $CI->cceClient->findx('IPPoolingRange', array(), array(), 'old_numeric', 'creation_time');
                foreach ($oids as $oid) {
                    $range = $CI->cceClient->get($oid);
                    $adminArray = $CI->cceClient->scalar_to_array($range['admin']);
                    sort($adminArray);
                    $owner_names = implode(", ", $adminArray);
                    if (($CI->serverScriptHelper->getAllowed('systemAdministrator')) || (in_array($CI->BX_SESSION['loginName'], $adminArray))) { 
                        if (filter_var($range['min'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            if ((($CI->serverScriptHelper->getAllowed('systemAdministrator'))) && (count($adminArray) > '0')) {
                                $range_strings['v4'][] = $range['min'] . ' - ' . $range['max'] . ' [' . $owner_names . ']';
                            }
                            else {
                                $range_strings['v4'][] = $range['min'] . ' - ' . $range['max'] . ' [' . $CI->BX_SESSION['loginName'] . ']';
                            }
                            if ((!isset($reseller_first_range['IPv4']['min'])) && (in_array($CI->BX_SESSION['loginName'], $adminArray))) {
                                $reseller_first_range['IPv4'] = $range;
                            }
                        }
                        else {
                            if ((($CI->serverScriptHelper->getAllowed('systemAdministrator'))) && (count($adminArray) > '0')) {
                                $range_strings['v6'][] = $range['min'] . ' - ' . $range['max'] . ' [' . $owner_names . ']';
                            }
                            else {
                                $range_strings['v6'][] = $range['min'] . ' - ' . $range['max'] . ' [' . $CI->BX_SESSION['loginName'] . ']';
                            }
                            if ((!isset($reseller_first_range['IPv6']['min'])) && (in_array($CI->BX_SESSION['loginName'], $adminArray))) {
                                $reseller_first_range['IPv6'] = $range;
                            }
                        }
                    }
                }

                $new_range_string = '';
                $nrs_num = "0";
                if (isset($range_strings['v4'])) {
                    foreach ($range_strings['v4'] as $key => $value) {
                        if ($nrs_num > "0") {
                            $new_range_string .= "<br>";
                        }
                        $new_range_string .= $value;
                        $nrs_num++;
                    }
                }

                if (!isset($reseller_first_range['IPv4']["min"])) {
                    $reseller_first_range = array('IPv4' => array('min' => $vsiteDefaults['ipaddr']));
                }

                if ($CI->serverScriptHelper->getAllowed('systemAdministrator')) {
                    // User 'admin' or systemAdministrator
                    $ip_address = $factory->getIpAddress("ipAddr", $vsiteDefaults['ipaddr']);
                }
                else {
                    // Reseller:
                    $ip_address = $factory->getIpAddress("ipAddr", $reseller_first_range['IPv4']["min"]);
                }
                $ip_address->setRange($new_range_string);
            }
            else {
                // IP Address, without ranges
                $ip_address = $factory->getIpAddress("ipAddr", $vsiteDefaults['ipaddr']);
            }

            // IPv4 IP Address
            if ($show_IPv4) {

                if (in_array($System['IPType'], array('BOTH', 'VZBOTH'))) {
                    $ip_address->setOptional(TRUE);
                }
                else {
                    $ip_address->setOptional(FALSE);
                }
                $settings->addFormField(
                        $ip_address,
                        $factory->getLabel("ipAddr"),
                        $defaultPage
                        );
            }

            // IPv6:
            $new_range_string = '';
            $nrs_num = "0";
            if (($net_opts["pooling"] == "1") && (isset($range_strings['v6']))) {
                // IPv6 IP Address, with ranges
                foreach ($range_strings['v6'] as $key => $value) {
                    if ($nrs_num > "0") {
                        $new_range_string .= "<br>";
                    }
                    $new_range_string .= $value;
                    $nrs_num++;
                }

                if (!isset($reseller_first_range['IPv6']["min"])) {
                    $reseller_first_range = array('IPv6' => array('min' => $vsiteDefaults['ipaddrIPv6']));
                }

                if ($CI->serverScriptHelper->getAllowed('systemAdministrator')) { 
                    $ipv6_address = $factory->getIpAddress("ipaddrIPv6", $vsiteDefaults["ipaddrIPv6"], $access_ipv6);
                }
                elseif (in_array($CI->BX_SESSION['loginName'], $adminArray)) {
                    $ipv6_address = $factory->getIpAddress("ipaddrIPv6", $reseller_first_range['IPv6']["min"], $access_ipv6);
                }
                $ipv6_address->setRange($new_range_string);
            }
            else {
                // IPv6 IP Address, without ranges
                $ipv6_address = $factory->getIpAddress("ipaddrIPv6", $vsiteDefaults["ipaddrIPv6"], $access_ipv6);
            }

            // IPv6 IP Address, without ranges
            if ($show_IPv6) {
                $ipv6_address->setType("ipaddrIPv6");
                if (in_array($System['IPType'], array('BOTH', 'VZBOTH'))) {
                    $ipv6_address->setOptional(TRUE);
                }
                else {
                    $ipv6_address->setOptional(FALSE);
                }
                $settings->addFormField(
                        $ipv6_address,
                        $factory->getLabel("ipaddrIPv6"),
                        $defaultPage
                        );
            }

            // host and domain names
            if (isset($vsiteDefaults['hostname'])) {
                $server_hostname = $vsiteDefaults['hostname'];
            }
            else {
                $server_hostname = "";
            }       
            if (isset($vsiteDefaults['domain'])) {
                $server_domain = $vsiteDefaults['domain'];
            }
            else {
                $server_domain = "";
            }       

            // host and domain names
            $hostname_field = $factory->getDomainName("hostName", $server_hostname); 
            $hostname_field->setType("hostname");
            $hostname_field->setLabelType("label_top no_lines");

            $domainname_field = $factory->getDomainName("domain", $server_domain);
            $domainname_field->setType("domainname");
            $domainname_field->setLabelType("label_top no_lines");

            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $fqdn = $factory->getCompositeFormField(array($factory->getLabel("enterFqdn"), $hostname_field, $domainname_field), '');
                $fqdn->setColumnWidths(array('col_25', 'col_25', 'col_50'));
            }
            else {
                $fqdn = $factory->getCompositeFormField(array($hostname_field, $domainname_field), '');
            }

            $settings->addFormField(
                    $fqdn,
                    $factory->getLabel("enterFqdn"),
                    $defaultPage
                    );

            //-- Start Owner Management

            // Find all 'adminUsers' with the capability 'manageSite':
            $admins = $CI->cceClient->findx('User', 
                            array('systemAdministrator' => 0, 'capLevels' => 'manageSite'),
                            array());

            // Set up an array that - at least - has 'admin' in it:
            $adminNames = array('admin');
            foreach ($admins as $num => $oid) {
                $current = $CI->cceClient->get($oid);
                // Found a reseller, adding him to the array as well:
                $adminNames[] = $current['name'];
            }

            // Do we have form POST data with a 'createdUser'? If so, we use it:
            if (isset($form_data['createdUser'])) {
                $current_createdUser = $form_data['createdUser'];
            }
            else {
                // Else we need to set a current owner. Assume the current user:
                $current_createdUser = $CI->BX_SESSION['loginName'];
            }

            // If the current user has the cap 'serverManage', then he is allowed to change the owner:
            if ($CI->serverScriptHelper->getAllowed('serverManage')) { 
                // Sort the array values:
                asort($adminNames);
                // Build the MultiChoice selector:
                $current_createdUser_select = $factory->getMultiChoice("createdUser", array_values($adminNames));
                $current_createdUser_select->setSelected($current_createdUser, true);
                $settings->addFormField($current_createdUser_select, $factory->getLabel("createdUser"), $defaultPage);
            }
            else {
                // Current user doesn't have the cap 'serverManage'. So we just add a hidden TextField with the current owner in it:
                $xff = $factory->getTextField("createdUser", $CI->BX_SESSION['loginName'], "r");
                $settings->addFormField(
                        $xff,
                        $factory->getLabel("createdUser"),
                        $defaultPage
                        );
            }

            // Prefix:
            if (isset($vsiteDefaults['prefix'])) {
                $server_prefix = $vsiteDefaults['prefix'];
            }
            else {
                $server_prefix = "";
            }
            $vsite_prefix = $factory->getTextField("prefix", $server_prefix);
            $vsite_prefix->setOptional(TRUE);
            $vsite_prefix->setWidth(5);
            $vsite_prefix->setMaxLength(5);
            $vsite_prefix->setType("lc_alphanum");

            $settings->addFormField(
                $vsite_prefix,
                $factory->getLabel("prefix"),
                $defaultPage
                    );

            //-- End Owner Management

            // Disk Volume:
            $xxx = $factory->getTextField('volume', '/home', '');
            $settings->addFormField(
                    $xxx,
                    $factory->getLabel("volume"),
                    $defaultPage
                    );

            //-- Start: siteAdmin creation:

            // Add divider:
            $ffs = $factory->addBXDivider("siteAdminDivider", "");
            $settings->addFormField(
                    $ffs,
                    $factory->getLabel("[[base-user.siteAdminEnabled]]", false),
                    $defaultPage
                    );

            // Full name:
            $ff_fullNameField = $factory->getFullName("fullNameField", $vsiteDefaults['fullNameField']);

            // Set Label/Desription directly in BxPage);
            $BxPage->setLabel('fullNameField', $i18n->get("[[base-user.fullNameField]]"), $i18n->get("[[base-user.fullNameField_help]]"));

            $settings->addFormField(
                $ff_fullNameField,
                $factory->getLabel("fullNameField"),
                $defaultPage
            );

            // Username
            $userNameField = $factory->getTextField("userName", $vsiteDefaults['userName']);
            // We MUST ensure usernames do NOT start with a number but a letter:
            //$userNameField->setType("lc_alphanum_plus");
            $userNameField->setType("accountname");

            // Set Label/Desription directly in BxPage);
            $BxPage->setLabel('userName', $i18n->get("[[base-user.userNameField]]"), $i18n->get("[[base-user.userNameField_help]]"));

            $settings->addFormField( 
                $userNameField, 
                $factory->getLabel('userNameField'),
                $defaultPage
            ); 

            // Password:
            $ff_passwordField = $factory->getPassword("passwordField", "");

            // Set Label/Desription directly in BxPage);
            $BxPage->setLabel('passwordField', $i18n->get("[[base-user.passwordField]]"), $i18n->get("[[base-user.passwordField_help]]"));

            $settings->addFormField(
                $ff_passwordField,
                $factory->getLabel("passwordField"),
                $defaultPage
            );

            //-- Stop: siteAdmin creation

            // Add divider:
            $ffs = $factory->addBXDivider("siteConfigData", "");
            $settings->addFormField(
                    $ffs,
                    $factory->getLabel("[[base-api.basicSettingsTab]]", false),
                    $defaultPage
                    );

            // web server aliases
            if (isset($vsiteDefaults['webAliases'])) {
                $webAliases_defaults = $vsiteDefaults['webAliases'];
            }
            else {
                $webAliases_defaults = "";
            }       
            $webAliasesField = $factory->getDomainNameList("webAliases", $webAliases_defaults);
            $webAliasesField->setOptional(TRUE);

            $settings->addFormField(
                    $webAliasesField,
                    $factory->getLabel("webAliases"),
                    $defaultPage
                    );

            # webAliasRedirect:
            $xxx = $factory->getBoolean('webAliasRedirects', $vsiteDefaults['webAliasRedirects'], 'rw');
            $settings->addFormField(
                $xxx,
                $factory->getLabel('webAliasRedirects'), $defaultPage
                );      

            // enable & disable Email
            $xxx = $factory->getBoolean("emailDisabled", $vsiteDefaults["emailDisabled"], "rw");
            $settings->addFormField(
                    $xxx,
                    $factory->getLabel("emailDisabled"),
                    $defaultPage
                    );

            // mail server aliases
            if (isset($vsiteDefaults['mailAliases'])) {
                $mailAliases_defaults = $vsiteDefaults['mailAliases'];
            }
            else {
                $mailAliases_defaults = "";
            }               
            $mailAliasesField = $factory->getDomainNameList("mailAliases", $mailAliases_defaults);
            $mailAliasesField->setOptional(TRUE);
            $settings->addFormField(
                    $mailAliasesField,
                    $factory->getLabel("mailAliases"),
                    $defaultPage
                    );

            // site email catch-all
            $mailCatchAllField = $factory->getEmailAddress("mailCatchAll", $vsiteDefaults["mailCatchAll"], 1);
            $mailCatchAllField->setOptional(TRUE);
            $settings->addFormField(
                $mailCatchAllField,
                $factory->getLabel("mailCatchAll"),
                $defaultPage
                );

            //
            //-- Resource Management:
            //

            $disk_dev = $CI->cceClient->find("Disk", array('isHomePartition' => 1, 'mounted' => 1));

            // Dirty hack not to use /home partition. Kicks in if we don't have a disk partition
            // after our last search or if the reported disk has no size information. Then we use
            // the size information from the / partition instead:
            if (count($disk_dev) == 0) { 
                $disk_dev = $CI->cceClient->getObject('Disk', array('mountPoint' => '/'), ''); 
            }
            else {
                $disk_dev = $CI->cceClient->get($disk_dev[0]); 
            } 

            if (isset($disk_dev['total'])) {
                $partitionMax = sprintf("%.0f", ($disk_dev['total'])); 
            } 

            if (isset($disk_dev['used'])) {
                $partitionUsed = sprintf("%.0f", ($disk_dev['used'])); 
            } 

            // We now know how large the partition is and how much of it is used.
            $partitionMax = ($partitionMax-$partitionUsed);
            if (preg_match('/^[0-9]{0,99}$/', $vsiteDefaults["quota"])) {
                $VsiteTotalDiskSpace = $vsiteDefaults["quota"]*1000*1000;
            }
            else {
                $VsiteTotalDiskSpace = $vsiteDefaults["quota"];;
            }
            $VsiteUsedDiskSpace = "0";

            //
            //-- Start: Poll "server admin" resource settings and usage:
            //

            // If the site is not owned by 'admin', we need to gather information
            // about the allowances and usage info for this 'manageSite' administrator:
            $exact = array();
            $exact = array_merge($exact, array('createdUser' => $CI->BX_SESSION['loginName']));

            // Get the info about the 'manageSite' administrator:
            list($user_oid) = $CI->cceClient->find('User', array('name' => $CI->BX_SESSION['loginName'])); 

            // Get the site allowance settings for this 'manageSite' user:
            $AdminAllowances = $CI->cceClient->get($user_oid, 'Sites'); 
            
            // Get a list of all sites he owns: 
            $Userowned_Sites = $CI->cceClient->find('Vsite', array('createdUser' => $CI->BX_SESSION['loginName'])); 
            $Quota_of_Userowned_Sites = 0; 
            foreach ($Userowned_Sites as $oid) { 
                $user_vsiteDisk = $CI->cceClient->get($oid, 'Disk'); 
                $Quota_of_Userowned_Sites += $user_vsiteDisk['quota']; 
            }
            // Variable $Quota_of_Userowned_Sites includes the quota of the current Vsite. We need
            // to substract it from the total for now:
            $Quota_of_Userowned_Sites -= $disk_dev['quota'];
            // Multiply the quota to get it in bytes:
            $Quota_of_Userowned_Sites = $Quota_of_Userowned_Sites*1000*1000;
            $AdminAllowances['quota'] = $AdminAllowances['quota']*1000;

            // How many users accounts are allocated to Vsites this 'manageSite' administrator created?
            $AllocatedUserAccounts = 0;
            $CreatedUserAccountsAllSites = 0;
            $CreatedUserAccountsThisSite = 0;
            foreach ($Userowned_Sites as $oid) { 
                $user_vsite = $CI->cceClient->get($oid);
                if ($user_vsite['maxusers'] == '') {
                    $user_vsite['maxusers'] = '25';
                }
                $AllocatedUserAccounts += $user_vsite['maxusers'];

                // How many accounts are set up on this Vsite?
                $useduser_oids = $CI->cceClient->find('User', array("site" => $user_vsite['name']));
                // Add them to the total:
                $CreatedUserAccountsAllSites += count($useduser_oids);
                // How many accounts does THIS site have at the moment?
                $CreatedUserAccountsThisSite = "0";
            }

            // Check if the amount of allocated accounts is greater than what the user is allowed to:
            if (($CI->serverScriptHelper->getAllowed('manageSite')) && ($CI->BX_SESSION['loginName'] == 'admin')) {
                $Can_Modify_Quantity_of_Users = "1";
            }
            elseif ($AllocatedUserAccounts > $AdminAllowances['user']) {
                $Can_Modify_Quantity_of_Users = "0";
            }
            else {
                $Can_Modify_Quantity_of_Users = "1";
            }

            //
            //-- End: Poll "server admin" resource settings and usage.
            //

            //
            //-- Site quota
            //

            if (!$CI->serverScriptHelper->getAllowed('systemAdministrator')) {
                $partitionMin = '1000000';
                $partitionMax = ($AdminAllowances['quota']-$Quota_of_Userowned_Sites);
            }
            else {
                $partitionMin = '1048576';
                $partitionMax = $partitionMax*1024;
            }

            // If the Disk Space is editable, we show it as editable:
            if ($access == 'rw') {
                $site_quota = $factory->getInteger('quota', simplify_number($VsiteTotalDiskSpace, "K", "2"), $partitionMin, $partitionMax, $access); 
                if ($CI->serverScriptHelper->getAllowed('systemAdministrator')) {
                    $site_quota->showBounds('diskquota');   // NOTE: This affects only the display of the range below the getInteger() field.
                }                                           // Quota for disk off the actual disk is stored with base 1024.
                else {
                    $site_quota->showBounds('dezi');        // NOTE: This affects only the display of the range below the getInteger() field.
                }                                           // Quota for Resellers is stored with base 1000.
                $site_quota->setType('memdisk');
                $settings->addFormField(
                        $site_quota,
                        $factory->getLabel('quota'),
                        $defaultPage
                        );
            }
            else {
                // Else we show it as shiny bargraph:
                $percent = round(100 * ($disk['used'] / $disk['quota']));
                $disk_bar = $factory->getBar("quota", floor($percent), "");
                $disk_bar->setBarText($i18n->getHtml("[[base-disk.userDiskPercentage_moreInfo]]", false, array("percentage" => $percent, "used" => simplify_number($VsiteUsedDiskSpace, "KB", "2"), "total" => simplify_number($VsiteTotalDiskSpace, "KB", "0"))));
                $disk_bar->setLabelType("quota");
                $disk_bar->setHelpTextPosition("bottom");   

                $settings->addFormField(
                        $disk_bar,
                        $factory->getLabel('quota'),
                        $defaultPage
                        );
            }

            //
            //-- Max user settings:
            //

            // Show an editable getInteger() if we have 'rw' access and can still modify the quantity of users:
            if (($access == 'rw') && ($Can_Modify_Quantity_of_Users == "1")) {
                $userMaxField = $factory->getInteger("maxusers", $vsiteDefaults["maxusers"], $CreatedUserAccountsThisSite, "50000", $access);
                $userMaxField->showBounds(FALSE);
                $settings->addFormField( 
                        $userMaxField, 
                        $factory->getLabel("maxUsers"),
                        $defaultPage
                        );
            }
            else {
                // This kicks on if the page visitor is 'siteAdmin', but is also used for anyone else
                // in case the number of users exceeds all limits for the 'manageSite' user in question:

                // We show it as shiny bargraph:
                if ((!isset($vsite['maxusers'])) || ($vsite['maxusers'] == "") || ($vsite['maxusers'] == "0")) {
                    // We sure don't want a division by zero in case 'maxusers' is not set or is "0":
                    $percent = "100";
                    $vsite['maxusers'] = "0";
                }
                else {
                    $percent = round(100 * ($CreatedUserAccountsThisSite / $vsite['maxusers']));
                }
                $user_bar = $factory->getBar("user_bar", floor($percent), "");
                $user_bar->setBarText($i18n->getHtml("[[base-disk.userDiskPercentage_moreInfo]]", false, array("percentage" => $percent, "used" => $CreatedUserAccountsThisSite, "total" => $vsite['maxusers'])));
                $user_bar->setLabelType("quota");
                $user_bar->setHelpTextPosition("bottom");   
                $settings->addFormField(
                        $user_bar,
                        $factory->getLabel('maxUsers'),
                        $defaultPage
                        );

                // Add hidden field with the current $vsite["maxusers"] value:
                $xxx = $factory->getTextField("maxusers", $vsite["maxusers"], '');
                $settings->addFormField(
                        $xxx,
                        $factory->getLabel("maxUsers"),
                        $defaultPage
                        );
            }

            //---- END: Resource Management

            // auto dns option
            $xxx = $factory->getBoolean("dns_auto", $vsiteDefaults["dns_auto"]);
            $settings->addFormField(
                    $xxx,
                    $factory->getLabel("dns_auto"),
                    $defaultPage
                    );      

            // preview site option (disabled and hidden for now:)
            $vsiteDefaults["site_preview"] = '0';
            $xxx = $factory->getBoolean("site_preview", $vsiteDefaults["site_preview"], '');
            $settings->addFormField(
                    $xxx,
                    $factory->getLabel("site_preview"),
                    $defaultPage
                    );

            //
            // --> AutoServices
            //
            // Add Divider:
            $xxx = $factory->addBXDivider("otherServices", "");
            $settings->addFormField(
                    $xxx,
                    $factory->getLabel("otherServices", false),
                    $secondPage
                    );      
            // Figure out which services are available
            list($vsiteServices) = $CI->cceClient->find('VsiteServices');
            $autoFeatures = new AutoFeatures($CI->serverScriptHelper);

            // add all generic enabled/disabled type services detected above
            $autoFeatures->display($settings, 'create.Vsite', 
                    array(
                        'CCE_SERVICES_OID' => $vsiteServices,
                        'PAGED_BLOCK_DEFAULT_PAGE' => $secondPage,
                        'CAN_ADD_PAGE' => false
                        ));

            // Add the buttons
            $btn = $factory->getSaveButton($BxPage->getSubmitAction());

            $settings->addButton($btn);
            $settings->addButton($factory->getCancelButton("/vsite/vsiteList"));

            //
            //-- Error message handing:
            //
            $BXerrors = $BxPage->getErrors();
            foreach ($errors as $object => $objData) {
                if (is_object($object)) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $BXerrors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
                else {
                    $BXerrors[] = $objData;
                }
            }

            // Publish error messages:
            $BxPage->setErrors($BXerrors);

            //-- Generate page:
            $page_body[] = $settings->toHtml();

            // Out with the page:
            return $BxPage->render($page_module, $page_body);

        }
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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