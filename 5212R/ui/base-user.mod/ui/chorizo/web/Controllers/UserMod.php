<?php 
namespace User\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
include_once("BXTime.php");
use AutoFeatures;
use I18n;
use BxPage;
use PHP81_BC\BXTime;

class UserMod extends BaseController {
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

        $CI =& get_instance();

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        // Most basic ACL:
        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/userMod");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Handle form data:
        //

        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a group set:
            $group = $get_form_data['group'];
        }
        if (isset($get_form_data['name'])) {
            // We have a name set:
            $name = $get_form_data['name'];
        }
        if ((!isset($group)) || (!isset($name))) {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }

        //
        //-- Access Rights Check for Vsite level pages:
        // 
        // 1.) Checks if the Group/Vsite exists.
        // 2.) Checks if the user is systemAdministrator
        // 3.) Checks if the user is Reseller of the given Group/Vsite
        // 4.) Checks if the iser is siteAdmin of the given Group/Vsite
        // Returns Forbidden403 if *none* of that is the case.
        if (!$CI->serverScriptHelper->getGroupAdmin($group)) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }

        //
        //-- Prepare data:
        //

        // Get data for the Vsite via getAll():
        $all_vsite_data = $CI->cceClient->getAll("Vsite", array('name' => $group));
        $all_vsite_data = reset($all_vsite_data);
        $vsite = $all_vsite_data['OBJECT'];
        $vsiteDisk = $all_vsite_data['Disk'];
        $vsite_php = $all_vsite_data['PHP'];
        $ftpPermsObj = $all_vsite_data['FTPNONADMIN'];
        $userDefaults = $all_vsite_data['UserDefaults'];

        // Get 'System' NameSpace 'Email':
        $System_Email = $CI->cceClient->get($System['OID'], "Email");

        // Get the name of the siteAdmin who owns /web:
        $current_prefered_siteAdmin = $vsite_php['prefered_siteAdmin'];

        // Get User data via GetAll():
        $User_Data = $CI->cceClient->getAll("User", array("name" => $name, 'site' => $group));
        $firstKey = array_key_first($User_Data);
        if ($firstKey !== null) {
            // OID of the $user:
            $useroid = $firstKey;
        }
        else {
            // Error: No User found!
            //
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }

        $all_user_data = reset($User_Data);
        // User Object:
        $User = $all_user_data['OBJECT'];
        // User Object ID:
        $useroid = $User['OID'];
        // User Object NameSpace 'Disk':
        $UserDisk = $all_user_data['Disk'];
        // User Object NameSpace 'Email':
        $userEmail = $all_user_data['Email'];

        // Find out if FTP access for non-siteAdmins is enabled or disabled for this site:
        $ftpnonadmin = $ftpPermsObj['enabled'];

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text");

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

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            // modify user
            $settings = array("fullName" => $attributes['fullNameField']);

            $settings["description"] = $attributes['userDescField'];

            # don't set this attribute now if a siteadmin is trying to demote himself
            if (isset($attributes['siteAdministrator']) && ($attributes['siteAdministrator'] || (!$attributes['siteAdministrator'] && ($BX_SESSION['loginName'] != $attributes['userName'])))) {
                $settings["capLevels"] = ($attributes['siteAdministrator'] ? '&siteAdmin&' : '');
            }

            if (isset($attributes['dnsAdministrator']) && ($attributes['dnsAdministrator'] || (!$attributes['dnsAdministrator'] && ($BX_SESSION['loginName'] != $attributes['userName'])))) {
                $settings["capLevels"] .= ($attributes['dnsAdministrator'] ? '&siteDNS&' : '');
            }

            // dirty trick
            $settings["capLevels"] = str_replace("&&", "&", $settings["capLevels"]);

            $settings['emailDisabled'] = $attributes['emailDisabled'];

            if (isset($attributes['suspendUser'])) {
                $settings['ui_enabled'] = ($attributes['suspendUser']) ? '0' : '1';
                if ($attributes['suspendUser'] == '1') {
                    $settings['emailDisabled'] = '1';
                }
            }

            // Handle FTP access clauses:
            if (!isset($ftpnonadmin)) {
                $ftpnonadmin = "0";
            }
            if ($ftpnonadmin == "0") {
                $settings['ftpDisabled'] = "1";
            }
            else {
                $settings['ftpDisabled'] = "0";
            }

            if ($attributes['siteAdministrator'] == "1") {
                $settings['ftpDisabled'] = "0";
            }

            // Password change?
            if (($attributes['passwordField'] == "") && ($attributes['_passwordField_repeat'] == "")) {
                // No password change:
                $settings["password"] = "";
            }
            else {
                // Password change requested. Check strength and take the new password:
                if (bx_pw_check($i18n, $attributes['passwordField'], $attributes['_passwordField_repeat']) != "") {
                    $errors[] = bx_pw_check($i18n, $attributes['passwordField'], $attributes['_passwordField_repeat']);
                }
                $settings['password'] = $attributes['passwordField'];
            }

            // Username = Password? Baaaad idea!
            if (strcasecmp($attributes['userName'], $attributes['passwordField']) == 0) {
                $settings["password"] = "1";
                $error_msg = "[[base-user.error-password-equals-username]] [[base-user.error-invalid-password]]";
                $errors[] = ErrorMessage($error_msg . '<br>&nbsp;');
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) === 0) && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Modify the User:
            $big_ok = $CI->cceClient->set($useroid, "", $settings);
            $errors = array_merge($errors, $CI->cceClient->errors());

            // Get the OID of this transaction:
            if ($big_ok) {

                // Set quota:
                if (!isset($attributes['maxDiskSpaceField'])) {
                    // Somehow no quota was set. Assume unlimited:
                    $quota = "-1";
                }
                else {
                    $attributes['maxDiskSpaceField'] = preg_replace('/\,/', '.', $attributes['maxDiskSpaceField']);
                    // Quota is set. Check if it has a unit or not:
                    $pattern = '/^(\d*[(\.)|(\,)]{0,1}\d+)(K|M|G|T)$/';
                    if (preg_match($pattern, $attributes['maxDiskSpaceField'], $matches, PREG_OFFSET_CAPTURE)) {
                        // Quota has a unit:
                        $quota = (unsimplify_number($attributes['maxDiskSpaceField'], "K")/1000);
                    }
                    else {
                        // Quota has no unit:
                        $quota = $attributes['maxDiskSpaceField'];
                    }
                    if ($quota > $vsiteDisk['quota']) {
                        // Someone is trying to set more quota for this User than allowed for the entire Vsite:
                        $errors[] = ErrorMessage($i18n->get("[[base-vsite.quota]]") . '<br>&nbsp;');
                    }
                }

                if (count($errors) === 0) {
                    $CI->cceClient->set($useroid, "Disk", array("quota" => $quota));
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }

                //
                //-- Handle AutoFeatures for UserExtraServices:
                //
                list($userservices) = $CI->cceClient->findx("UserExtraServices");
                $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $form_data);
                $af_errors = $autoFeatures->handle("User.Email", array("CCE_SERVICES_OID" => $userservices, "CCE_OID" => $useroid, 'i18n' => $i18n), $form_data);
                $errors = array_merge($errors, $af_errors);

                //
                //-- Handle AutoFeatures for UserServices:
                //
                list($userservices) = $CI->cceClient->find("UserServices", array("site" => $group));
                $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
                $af_errors = $autoFeatures->handle("modify.User", array("CCE_SERVICES_OID" => $userservices, "CCE_OID" => $useroid, 'i18n' => $i18n), $attributes);
                $errors = array_merge($errors, $af_errors);

                //
                //-- Set email aliases info
                //

                //Prune the duplicate email aliases
                $emailAliasesFieldArray = $CI->cceClient->scalar_to_array($attributes['emailAliasesField']);
                $emailAliasesFieldArray = array_unique($emailAliasesFieldArray);
                $emailAliasesField = $CI->cceClient->array_to_scalar($emailAliasesFieldArray);

                // replace && with &, to avoid always getting a blank alias in the field
                // in cce, this also skirts around dealing with browser issues
                $emailAliasesField = str_replace("&&", "&", $emailAliasesField);
                if ($emailAliasesField == '&') {
                  $emailAliasesField = '';
                }
                $settings = array("aliases" => $emailAliasesField);

                // Handle allow_sender_spoof:
                if ($CI->serverScriptHelper->getGroupAdmin($group)) {
                    $settings['allow_sender_spoof'] = $attributes['allow_sender_spoof'];
                    if ($vsite['allow_sender_spoof'] == '0') {
                        // If feature is disabled, enforce '0':
                        $settings['allow_sender_spoof'] = '0';
                    }

                    if ($name == $current_prefered_siteAdmin) {
                        // siteAdmin may always spoof:
                        $settings['allow_sender_spoof'] = '1';
                    }
                }

                $CI->cceClient->set($useroid, "Email", $settings);
                $errors = array_merge($errors, $CI->cceClient->errors());
                // At this point we're done. We may have errors, though.
            }

            if (!empty($_SERVER['HTTP_REFERER'])) {
                $previous_URL = $_SERVER['HTTP_REFERER'];
            }
            else {
                $previous_URL = $_SERVER['REQUEST_URI'];
            }

            if (count($errors) === 0) {
                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $redirect_URL = "/user/userList?group=$group";
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

        // Prepare Page:
        $BxPage->setFormUrl("/user/userMod?group=$group&name=$name");

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteadmin');
        $BxPage->setVerticalMenuChild('base_userList');
        $page_module = 'base_sitemanage';

        // Set extra headers for fullcalendar and datepicker:
        if ($BX_SESSION['gui_theme'] == 'adminica') {
            $BxPage->setExtraHeaders('<script src="/gui/fullcalendar"></script>');
            $BxPage->setExtraHeaders('<script src="/gui/datepicker"></script>');
        }

        // Find out which modules are active and use their names as Tab headers:
        $autoFeatures = new AutoFeatures($CI->serverScriptHelper);

        $defaultPage = "account";
        $TABs = array_merge(array($defaultPage), array_values($autoFeatures->ListFeatures("User.Email")));
        $block = $factory->getPagedBlock("modifyUser", $TABs);
        $block->setLabel($factory->getLabel('modifyUser', false, array('userName' => $User['name'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        // Add hidden field for Group:
        $xff = $factory->getTextField("group", $group, '');
        $block->addFormField(
                $xff,
                $factory->getLabel("group"), 
                $defaultPage
        );

        $raw_userName = stripcslashes($User['fullName']);
        $userName = htmlspecialchars(bx_charsetsafe($raw_userName), ENT_QUOTES, 'UTF-8');
        $User["fullName"] = $userName;

        $raw_desc = stripcslashes($User['description']);
        $description = htmlspecialchars(bx_charsetsafe($raw_desc), ENT_QUOTES, 'UTF-8');
        $User["description"] = $description;

        $xff = $factory->getFullName("fullNameField", $User["fullName"]);
        $block->addFormField(
            $xff,
            $factory->getLabel("fullNameField"),
            $defaultPage
        );

        // # Username - start
        $userNameField = $factory->getTextField("userName", $User["name"], 'r');
        $block->addFormField( 
            $userNameField, 
            $factory->getLabel("userNameField"),
            $defaultPage
        ); 
        // # Username - end

        // Password:
        $pw_change = $factory->getPassword("passwordField", "");
        $pw_change->setOptional(TRUE);
        $block->addFormField(
            $pw_change,
            $factory->getLabel("passwordField"),
            $defaultPage
        );

        // Load site quota
        $vsite_quota = $vsiteDisk['quota']*1000*1000;
        $default_quota = $UserDisk['quota']*1000*1000;

        $site_quota = $factory->getInteger('maxDiskSpaceField', simplify_number($default_quota, "K", "2"), '1000000', $vsite_quota, 'rw'); 
        $site_quota->showBounds('dezi');
        $site_quota->setType('memdisk');
        $block->addFormField(
                $site_quota,
                $factory->getLabel('maxDiskSpaceField'),
                $defaultPage
                );


        // Suspend / Unsuspend:

        $suspendstate = '1';
        if (($User["ui_enabled"] == '1') && ($User["enabled"] == '1')) {
            $suspendstate = '0';
        }

        $xff = $factory->getBoolean("suspendUser", $suspendstate);
        $block->addFormField(
            $xff,
            $factory->getLabel("suspendUser"),
            $defaultPage
        );

        $textblock = $factory->getTextBlock("userDescField", $User["description"]);
        $textblock->setWidth(2*$textblock->getWidth());
        $textblock->setOptional(true);
        $block->addFormField(
            $textblock,
            $factory->getLabel("userDescField"),
            $defaultPage
        );

        //
        //--- Email related stuff:
        //

        $email_access = 'rw';
        if ($vsite['emailDisabled'] == '1') {
            $email_access = 'r';
        }
        $xff = $factory->getBoolean("emailDisabled", $User["emailDisabled"], $email_access);
        $block->addFormField(
            $xff,
            $factory->getLabel("emailDisabled"),
            "EmailSettings"
        );

        // Allow user to spoof sender address:
        $spoof_access = 'r';
        if (($CI->serverScriptHelper->getGroupAdmin($group)) && ($vsite['allow_sender_spoof'] == '1')) {
            // Only siteAdmin or higher may change this IF the VsiteDefaults allow it:
            $spoof_access = 'rw';
        }

        // Check if this is the siteAdmin who owns /web:
        if ($name == $current_prefered_siteAdmin) {
            // siteAdmin may always spoof and his setting can't be changed:
            $userEmail["allow_sender_spoof"] = '1';
            $spoof_access = 'r';
        }
        else {
            // User is NOT siteAdmin who owns /web:
            if ($vsite['allow_sender_spoof'] == '0') {
                $spoof_access = 'r';
                if ($userEmail["allow_sender_spoof"] == '1') {
                    $userEmail["allow_sender_spoof"] = '0';
                }
            }
        }

        // If feature is disabled on the server, hide the element and set defaults:
        if (($System_Email['authsend_protect'] == '0') && ($System['MTA'] == 'POSTFIX')) {
            $userEmail["allow_sender_spoof"] = '0';
            $spoof_access = '';
        }

        $spoof_allow = $factory->getBoolean("allow_sender_spoof", $userEmail["allow_sender_spoof"], $spoof_access);
        $block->addFormField(
            $spoof_allow,
            $factory->getLabel("allow_sender_spoof"),
            "EmailSettings"
        );

        $emailAliases = $factory->getEmailAliasList("emailAliasesField", $userEmail["aliases"]);
        $emailAliases->setOptional(true);
        $block->addFormField(
            $emailAliases,
            $factory->getLabel("emailAliasesField"),
            "EmailSettings"
        );

        //
        //--- Add other features
        //

        $autoFeatures = new AutoFeatures($CI->serverScriptHelper);

        if ((isset($group) && $group != "") && (isset($vsite['OID']))) {
            list($userServices) = $CI->cceClient->find("UserServices", array("site" => $group));
            $autoFeatures->display($block, "modify.User", array("CCE_SERVICES_OID" => $userServices, 'CCE_OID' => $useroid, "VSITE_OID" => $vsite['OID']));

            $xff = $factory->getBoolean("siteAdministrator", $CI->getAllowed('siteAdmin', $useroid));
            $block->addFormField(
                $xff,
                $factory->getLabel("siteAdministratorField"),
                $defaultPage
            );

            $xff = $factory->getBoolean("dnsAdministrator", $CI->getAllowed('siteDNS', $useroid));
            $block->addFormField(
                $xff,
                $factory->getLabel("dnsAdministratorField"),
                $defaultPage
            );
         
        }
        else {
            list($userServices) = $CI->cceClient->find("UserServices");
            $autoFeatures->display($block, "modify.User", array("CCE_SERVICES_OID" => $userServices));
        }

        //
        //---- Start: Email Forwarding and Vacation Message
        //

        $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
        $cce_info = array('CCE_OID' => $useroid, 'FIELD_ACCESS' => 'rw');
        list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('UserExtraServices');
        $autoFeatures->display($block, 'User.Email', $cce_info);

        //
        //---- End: Email Forwarding and Vacation Message
        //

        // Add the buttons for those who can edit this page:
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/user/userList?group=$group"));

        // Make sure to grab the AutoFeature errors from stack as well and merge w/o duplicates:
        $errors = array_merge($errors, $BxPage->getErrors());
        $errors = array_unique($errors);

        $BxPage->setErrors($errors);

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