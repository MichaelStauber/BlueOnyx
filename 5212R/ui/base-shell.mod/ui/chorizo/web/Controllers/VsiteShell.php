<?php 
namespace Shell\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class VsiteShell extends BaseController {
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

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $System_SSH = $CI->cceClient->get($System['OID'] , 'SSH');
        $user = $BX_SESSION['loginUser'];

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-shell", "/shell/vsiteShell");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- Handle GET Rrequests (create or download actions):
        //

        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a group set:
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

        $Shell_enabled_Map = 
            array(
                "0" => "none", 
                "1" => "jailed_sftp_scp_rsync", 
                "2" => "jailed_shell", 
                "3" => "full_shell"
            );

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

        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {
            // Not needed. Thank you, jQuery!
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Handle AutoFeatures:
            $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
            $cce_info = array('CCE_OID' => $vsite['OID']);
            list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
            $af_errors = $autoFeatures->handle('modify.FTP', $cce_info);
            $errors = array_merge($errors, $af_errors);

            // Actual submit to CODB:
            if ($attributes['save'] == "1") {

                // Reverse Map to get the numerical value for 'Shell_enabled':
                $Shell_enabled_Map_Reversed = 
                    array(
                        "none" => "0", 
                        "jailed_sftp_scp_rsync" => "1", 
                        "jailed_shell" => "2", 
                        "full_shell" => "3"
                    );

                $attributes['Shell_enabled'] = $Shell_enabled_Map_Reversed[$attributes['Shell_enabled']];

                // Submit to CODB:
                $CI->cceClient->setObject('Vsite', array('enabled' => $attributes['Shell_enabled']), 'Shell', array('name' => $group));
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Redirect to this page:
            $redirect_URL = "/shell/vsiteShell?group=$group";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Generate page:
        //

        if (($CI->getAllowed('adminUser')) || ($CI->getAllowed('manageSite'))) {   
            $access = 'rw';
            $is_site_admin = false;
        }
        elseif ($CI->getAllowed('serverShell') && $group == $CI->serverScriptHelper->loginUser['site']) {
            $access = 'r';
            $is_site_admin = true;
        }
        else {
            $access = 'r';
            $is_site_admin = true;
        }

        // Prepare Page:
        $BxPage->setFormUrl("/shell/vsiteShell?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteadmin');
        $BxPage->setVerticalMenuChild('base_siteshell');
        $page_module = 'base_sitemanage';

        // Get Objects of interest:
        $site = $CI->cceClient->getObject('Vsite', array('name' => $group));
        $siteShell = $CI->cceClient->getObject('Vsite', array('name' => $group), 'Shell');

        //
        //-- Reseller: Can the reseller that owns this Vsite modify this?
        //
        $VsiteOwnerObj = $CI->cceClient->getObject("User", array("name" => $site['createdUser']));
        if ($VsiteOwnerObj['name'] != "admin") {
            $resellerCaps = $CI->cceClient->scalar_to_array($VsiteOwnerObj['capabilities']);
            if (!in_array('resellerShell', $resellerCaps)) {
                $siteShell['enabled'] = '0';
                $access = 'r';
            }
        }

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("siteShellSettings", array($defaultPage));
        $block->setLabel($factory->getLabel('siteShellSettings', false, array('fqdn' => $site['fqdn'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        //
        //--- Add AutoFeatures:
        //

        $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
        $cce_info = array('CCE_OID' => $vsite['OID'], 'FIELD_ACCESS' => $access, 'IS_SITE_ADMIN' => $is_site_admin, 'group' => $group);
        list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
        $cce_info['PAGED_BLOCK_DEFAULT_PAGE'] = $defaultPage;
        $autoFeatures->display($block, 'modify.FTP', $cce_info);


        // Rest of form fields:
        $xxx = $factory->getTextField('group', $group, '');
        $block->addFormField($xxx, $factory->getLabel('group'), $defaultPage);

        $xxx = $factory->getTextField('save', '1', '');
        $block->addFormField($xxx, $factory->getLabel('save'), $defaultPage);

        // Enable Shell Access:
        if (!$is_site_admin) {
            $shellEnable = $factory->getMultiChoice("Shell_enabled", array_values($Shell_enabled_Map));
            $shellEnable->setSelected($Shell_enabled_Map[$siteShell['enabled']], true);
            $block->addFormField($shellEnable, $factory->getLabel("enableShell"), $defaultPage);
        }
        else {
            $word = $Shell_enabled_Map[$siteShell['enabled']];
            $nokey_info = $i18n->getClean("[[base-shell.$word]]", false, array());
            $xxx = $factory->getTextField("Shell_enabled", $nokey_info , 'r');
            $block->addFormField(
                $xxx,
                $factory->getLabel("enableShell"),
                $defaultPage
            );
        }

        $twoFactorInfo = $factory->getRawHTML(
            "GoogleAuthenticationMoved",
            '<p>' . $i18n->get('[[base-user.twofactor_vsite_moved_note]]') . ' <a href="/user/twoFactorAdmin?group='
            . urlencode($group) . '">' . $i18n->get('[[base-user.twofactor_menu_label]]') . '</a></p>'
        );
        $block->addFormField($twoFactorInfo, $factory->getLabel("spacer"), $defaultPage);

        // Add the buttons
        if (!$is_site_admin) {
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/shell/vsiteShell?group=$group"));
        }

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }
}
/*
Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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
