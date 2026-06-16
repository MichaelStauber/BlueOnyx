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

class UserAdd extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/userAdd");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-user", $CI->getBX_Locale());
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
            // We have a group URL string:
            $group = $get_form_data['group'];
        }
        else {
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

        // Get data for the Vsite:
        $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));

        // Get System . Email:
        $System_Email = $CI->cceClient->get($System['OID'], "Email");

        if (!isset($vsite['name'])) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }

        // Get UserDefaults:
        $defaults = $CI->cceClient->getObject("Vsite", array("name" => $group), "UserDefaults");

        // Find out if FTP access for non-siteAdmins is enabled or disabled for this site:
        list($ftpvsite) = $CI->cceClient->find("Vsite", array("name" => $group));
        $ftpPermsObj = $CI->cceClient->get($ftpvsite, 'FTPNONADMIN');
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
            $errors = $BxPage->getErrors();
        }

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            // Handle setting the proper volume for vsite users
            if (isset($group)) {
                $vsite = $CI->cceClient->getObject("Vsite", array("name" => $group));
                $vsiteDisk = $CI->cceClient->get($vsite['OID'], 'Disk');
                $attributes['volume'] = $vsite['volume'];
            }
            else {
                // Default to wherever the home directory is:
                $attributes['volume'] = "/home";
            }

            // Handle FTP access clauses:
            if (!isset($ftpnonadmin)) {
                $ftpnonadmin = "0";
            }
            if ($ftpnonadmin == "0") {
                $attributes['ftpDisabled'] = "1";
            }
            else {
                $attributes['ftpDisabled'] = "0";
            }

            if ($attributes['siteAdministrator'] == "1") {
                $attributes['ftpDisabled'] = "0";
            }

            // If a prefix is given, prepend it to the userName:
            if (isset($attributes['prefix'])) {
                $UserNameArray = array($attributes['prefix'], $attributes['userName']);
                $newUserName = implode("_", $UserNameArray);
                
                // If someone uses a really long username, then a prefix may make it too long.
                // So we need to check how long the username now is and if need be, we need to shorten it:
                $unameLength = strlen($newUserName);
                if ($unameLength > '31') {
                    // Ok, the name is too long. We need to shorten it back down to 32 characters:
                    $newUserNameShort = (mb_substr($newUserName, '0', '31'));
                    $newUserName = $newUserNameShort;
                }
            }
            else {
                $newUserName = $attributes['userName'];
            }

            $out_attributes = array(
                            "name" => $newUserName, 
                            "sortName" => "", 
                            "fullName" =>$attributes['fullNameField'], 
                            "password" => $attributes['passwordField'], 
                            "emailDisabled" => $attributes['emailDisabled'],
                            "ftpDisabled" => $attributes['ftpDisabled'],
                            "localePreference" => "browser", 
                            "stylePreference" => "BlueOnyx", 
                            "volume" => $attributes['volume'],
                            "description" => $attributes['userDescField']);

            if (isset($group)) {
                $out_attributes["site"] = $group;
                $out_attributes['enabled'] = ($vsite['suspend'] ? 0 : 1);
            }

            if (isset($attributes['siteAdministrator'])) {
                $out_attributes["capLevels"] = ($attributes['siteAdministrator'] ? '&siteAdmin&' : '');
            }

            if (isset($attributes['dnsAdministrator'])) {
                $out_attributes["capLevels"] .= ($attributes['dnsAdministrator'] ? '&siteDNS&' : '');
            }

            // Dirty trick for cleanup:
            $out_attributes["capLevels"] = str_replace("&&", "&", $out_attributes["capLevels"]);

            // Username = Password? Baaaad idea!
            if (strcasecmp($newUserName, $attributes['passwordField']) === 0) {
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

            // Handle create of user if necessary:
            if (!isset($_oid)) {

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
                    $errors[] = ErrorMessage($i18n->get("[[failed-to-add-user]]") . '<br>&nbsp;');
                }

                // Get the OID of this transaction:
                if (($big_ok) && (count($errors) == "0")) {
                    $_oid = $big_ok;

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
                    }

                    if (isset($vsiteDisk['quota'])) {
                        if ($quota > $vsiteDisk['quota']) {
                            // Someone is trying to set more quota for this User than allowed for the entire Vsite:
                            $errors[] = ErrorMessage($i18n->get("[[base-vsite.quota]]") . '<br>&nbsp;');
                        }
                    }

                    if (count($errors) == "0") {
                        $CI->cceClient->set($_oid, "Disk", array("quota" => $quota));
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }

                    // Handle AutoFeatures:
                    list($userservices) = $CI->cceClient->find("UserServices", array("site" => $group));
                    $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
                    $af_errors = $autoFeatures->handle("create.User", array("CCE_SERVICES_OID" => $userservices, "CCE_OID" => $_oid), $attributes);
                    $errors = array_merge($errors, $af_errors);

                    // Set email information and prune the duplicate email aliases:
                    $emailAliasesFieldArray = $CI->cceClient->scalar_to_array($attributes['emailAliasesField']);
                    $emailAliasesFieldArray = array_unique($emailAliasesFieldArray);
                    $emailAliasesField = $CI->cceClient->array_to_scalar($emailAliasesFieldArray);

                    // Replace && with & to avoid always getting a blank alias in the field
                    // in cce. This also skirts around dealing with browser issues:
                    $emailAliasesField = str_replace("&&", "&", $emailAliasesField);
                    if ($emailAliasesField == '&') {
                        $emailAliasesField = '';
                    }

                    // Set allow_sender_spoof:
                    $alias_attributes = array("aliases" => $emailAliasesField, 'allow_sender_spoof' => $attributes['allow_sender_spoof']);

                    $CI->cceClient->set($_oid, "Email", $alias_attributes);
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // At this point we're done. We may have errors, though.
                }
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();

            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[$object] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            if (!empty($_SERVER['HTTP_REFERER'])) {
                $previous_URL = $_SERVER['HTTP_REFERER'];
            }
            else {
                $previous_URL = $_SERVER['REQUEST_URI'];
            }

            // No errors during submit? Reload page:
            if (count($errors) == "0") {
                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $redirect_URL = "/user/userList?group=$group";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
            else {
                // We do have errors. So we roll back and destroy the created User object:
                if (isset($_oid)) {
                    $CI->cceClient->destroy($_oid);
                }
                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, $previous_URL);
            }
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/user/userAdd?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteadmin');
        $BxPage->setVerticalMenuChild('base_userList');
        $page_module = 'base_sitemanage';

        $defaultPage = "pageID";
        $block = $factory->getPagedBlock("addNewUser", array($defaultPage));
        $block->setLabel($factory->getLabel('addNewUserTitle', false, array('fqdn' => $vsite['fqdn'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // Add hidden field for Group:
        $xff = $factory->getTextField("group", $group, "");
        $block->addFormField($xff, $defaultPage);

        // Full name:
        $xff = $factory->getFullName("fullNameField", "");
        $block->addFormField(
            $xff,
            $factory->getLabel("fullNameField"),
            $defaultPage
        );

        // # Username - start
        if ($vsite['userPrefixEnabled'] == "1") { 
            $userPrefixField = $vsite['userPrefixField']; 
             
            if (!$userPrefixField) { 
               $octets = explode(".", $vsite['fqdn']); 
               $userPrefixField = ""; 
               foreach($octets as $octet) { 
                    $userPrefixField .= substr($octet, 0, 1); 
               } 
               $userPrefixField .= time(); 
               $userPrefixField .= "_"; 
            } 

            $userPrefix = $factory->getUserName("prefix", $userPrefixField, 'r'); 
            // We MUST ensure usernames do NOT start with a number but a letter:
            //$userPrefix->setType("lc_alphanum_plus");
            $userPrefix->setType("lc_alphanum");
            $userPrefix->setLabelType("label_top no_lines");

            $userSuffix = $factory->getTextField("userName", "", 'rw');
            $userSuffix->setType("lc_alphanum_plus");
            $userSuffix->setLabelType("label_top no_lines");

            $userNameField = $factory->getCompositeFormField(array($factory->getLabel("userNameField"), $userPrefix, $userSuffix), '');
            $userNameField->setColumnWidths(array('col_25', 'col_25', 'col_50'));

        } 
        else { 
            $userNameField = $factory->getTextField("userName", "");
            // We MUST ensure usernames do NOT start with a number but a letter:
            //$userNameField->setType("lc_alphanum_plus");
            $userNameField->setType("accountname");
        } 
         
        $block->addFormField( 
            $userNameField, 
            $factory->getLabel("userNameField"),
            $defaultPage
        ); 
        // # Username - end

        // Password:
        $xff = $factory->getPassword("passwordField", "");
        $block->addFormField(
            $xff,
            $factory->getLabel("passwordField"),
            $defaultPage
        );

        // Load site quota
        list($vsite_oid) = $CI->cceClient->find('Vsite', array("name" => $group));
        $disk = $CI->cceClient->get($vsite_oid, 'Disk');
        $vsite_quota = $disk['quota']*1000*1000;
        $default_quota = $defaults['quota']*1000*1000;

        $site_quota = $factory->getInteger('maxDiskSpaceField', simplify_number($default_quota, "K", "2"), '1000000', $vsite_quota, 'rw'); 
        $site_quota->showBounds('dezi');
        $site_quota->setType('memdisk');
        $block->addFormField(
                $site_quota,
                $factory->getLabel('maxDiskSpaceField'),
                $defaultPage
                );

        // Add other features
        $autoFeatures = new AutoFeatures($CI->serverScriptHelper);

        if (isset($group) && $group != "") {
            list($userServices) = $CI->cceClient->find("UserServices", array("site" => $group));
            list($vsite_OID) = $CI->cceClient->find("Vsite", array("name" => $group));
            $autoFeatures->display($block, "create.User", array("CCE_SERVICES_OID" => $userServices, 'PAGED_BLOCK_DEFAULT_PAGE' => $defaultPage, "VSITE_OID" => $vsite_OID));

            $xff = $factory->getBoolean("siteAdministrator", "");
            $block->addFormField(
                $xff,
                $factory->getLabel("siteAdministratorField"),
                $defaultPage
            );

            $xff = $factory->getBoolean("dnsAdministrator", "");
            $block->addFormField(
                $xff,
                $factory->getLabel("dnsAdministratorField"),
                $defaultPage
            );
         
        }
        else {
            list($userServices) = $CI->cceClient->find("UserServices");
            $autoFeatures->display($block, "create.User", array("CCE_SERVICES_OID" => $userServices));
        }

        $xff = $factory->getBoolean("emailDisabled", $defaults["emailDisabled"]);
        $block->addFormField(
          $xff,
          $factory->getLabel("emailDisabled"),
          $defaultPage
        );

        // Allow user to spoof sender address:
        $spoof_access = 'r';

        if ($CI->getAllowed('manageSite')) {
            // Only siteAdmin or higher may change this:
            $spoof_access = 'rw';
        }

        if ($vsite["allow_sender_spoof"] == '1') {
            $spoof_access = 'rw';
        }
        else {
            $defaults["allow_sender_spoof"] = '0';
            $spoof_access = 'r';
        }

        // If feature is disabled on the server, hide the element and set defaults:
        if (($System_Email['authsend_protect'] == '0') && ($System['MTA'] == 'POSTFIX')) {
            $defaults["allow_sender_spoof"] = '0';
            $spoof_access = '';
        }

        $spoof_allow = $factory->getBoolean("allow_sender_spoof", $defaults["allow_sender_spoof"], $spoof_access);
        $block->addFormField(
            $spoof_allow,
            $factory->getLabel("allow_sender_spoof"),
            $defaultPage
        );

        $emailAliases = $factory->getEmailAliasList("emailAliasesField");
        $emailAliases->setOptional(true);
        $block->addFormField(
            $emailAliases,
            $factory->getLabel("emailAliasesField"),
            $defaultPage
        );

        if (isset($defaults["description"])) {
            $description = $i18n->interpolate($defaults["description"]);
        }
        else {
            $description = "";
        }

        $textblock = $factory->getTextBlock("userDescField", $description);
        $textblock->setWidth(2*$textblock->getWidth());
        $textblock->setOptional(true);
        $block->addFormField(
            $textblock,
            $factory->getLabel("userDescField"),
            $defaultPage
        );

        // Add the buttons for those who can edit this page:
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/user/userList?group=$group"));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

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