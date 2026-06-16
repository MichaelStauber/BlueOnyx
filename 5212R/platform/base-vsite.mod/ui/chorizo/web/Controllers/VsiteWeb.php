<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class VsiteWeb extends BaseController {
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

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/vsiteWeb");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        // -- Actual page logic start:
        //

        // Get URL strings:
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a delete transaction:
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
            $errors = $BxPage->getErrors();
        }

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Add host- and domain name and $group to $attributes as the MySQL plugin needs them:
            $attributes['hostName'] = $vsite['hostname'];
            $attributes['domain'] = $vsite['domain'];
            $attributes['group'] = $group;

            // Remove doublettes from WebAliases and also don't allow FQDN as webAlias:
            $fqdnVsite = $vsite['hostname'] . '.' . $vsite['domain'];
            $wa = array_unique($CI->cceClient->scalar_to_array($attributes['webAliases']));
            if (in_array($fqdnVsite, $wa)) {
                foreach ($wa as $key => $value) {
                    if ($value == $fqdnVsite) {
                        unset($wa[$key]);
                    }
                }
            }
            $attributes['webAliases'] = $CI->cceClient->array_to_scalar($wa);
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Handle AutoFeatures:
            $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
            $cce_info = array('CCE_OID' => $vsite['OID']);
            list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
            $af_errors = $autoFeatures->handle('modifyWeb.Vsite', $cce_info);
            $errors = array_merge($errors, $af_errors);

            // Set webAliases & webAliasRedirects in 'Vsite':
            $CI->cceClient->set($vsite['OID'], '', array("webAliases" => $attributes['webAliases'], "webAliasRedirects" => $attributes['webAliasRedirects'], 'force_update' => time()));

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $redirect_URL = "/vsite/vsiteWeb?group=$group";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/vsite/vsiteWeb?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteservices');
        $BxPage->setVerticalMenuChild('base_siteweb');
        $page_module = 'base_sitemanage';

        // Do we need tabs?
        $defaultPage = "basicSettings";
        $block = $factory->getPagedBlock("siteWebSettings", array($defaultPage));
        $block->setLabel($factory->getLabel('siteWebSettings', false, array('fqdn' => $vsite['fqdn'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        // Determine current user's access rights to view or edit information
        // here.  Only 'manageSite' can modify things on this page.  Site admins
        // can view it for informational purposes.
        if ($CI->getAllowed('manageSite')) {
            $is_site_admin = TRUE;
            $access = 'rw';
        }
        elseif (($CI->getAllowed('siteAdmin')) && ($group == $CI->serverScriptHelper->loginUser['site'])) {
            $access = 'r';
            $is_site_admin = FALSE;
        }
        else {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }

        // Web server aliases
        $webAliasesField = $factory->getDomainNameList("webAliases", $vsite["webAliases"], $access);
        $webAliasesField->setOptional(true);
        $block->addFormField(
               $webAliasesField,
               $factory->getLabel("webAliases"), $defaultPage
               );

        # webAliasRedirects:
        if ($vsite['webAliasRedirects'] ) {
            $xff = $factory->getBoolean('webAliasRedirects', $vsite['webAliasRedirects'], $access);
            $block->addFormField(
                $xff,
                $factory->getLabel('webAliasRedirects'), $defaultPage
                );
        }
        else {
            $xff = $factory->getBoolean('webAliasRedirects', $vsite['webAliasRedirects'], $access);
            $block->addFormField(
                $xff,
                $factory->getLabel('webAliasRedirects'), $defaultPage
                );
        }

        //
        //--- Add AutoFeatures:
        //

        $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);

        $cce_info = array('CCE_OID' => $vsite['OID'], 'FIELD_ACCESS' => $access, 'IS_SITE_ADMIN' => $is_site_admin, 'group' => $group);
        list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
        $cce_info['PAGED_BLOCK_DEFAULT_PAGE'] = $defaultPage;
        $autoFeatures->display($block, 'modifyWeb.Vsite', $cce_info);

        $xaa = $factory->getTextField('group', $group, '');
        $xbb = $factory->getTextField('save', '1', '');
        $block->addFormField($xaa);
        $block->addFormField($xbb);

        // Add the buttons for those who can edit this page:
        if ($access == 'rw') {
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/vsite/vsiteWeb?group=$group"));
        }

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }       
}

/*
Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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