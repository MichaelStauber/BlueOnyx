<?php 
namespace Subdomains\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class VsiteAddSub extends BaseController {
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
        $SystemSubdomains = $CI->cceClient->get($System['OID'], "subdomains");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-subdomains", "/subdomains/vsiteAddSub");
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
        $vsiteSub = $CI->cceClient->getObject('Vsite', array('name' => $group), "subdomains");

        //
        //--- Handle form validation:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Has getPost request:
            $form_data = $BxPage->FORM_POST;

            // Form fields that are required to have input:
            $required_keys = array("rootpath", 'webdir');

            // Empty array for key => values we want to submit to CCE:
            $attributes = array();

            // Items we do NOT want to submit to CCE:
            $ignore_attributes = array("BlueOnyx_Info_Text");

            // Run GetFormAttributes()
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
                // params: $BxPage              our already declared $BxPage Object (for storing validation Errors)
                // return:                      array with keys and values ready to submit to CCE.
                $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);

                // Get potential errors that GetFormAttributes() ran into from $BxPage:
                $errors = $BxPage->getErrors();

            }

            //
            //--- Own error checks:
            //

            //
            //--- No errors? Submit to CODB:
            //

            if (count($errors) == "0") {

                // Check if someone was sneaky or fat fingered:
                $desiredFqdn = $attributes['hostname'] . '.' . $attributes['domainname'];
                $ExtrawebAliases = $CI->cceClient->scalar_to_array($vsite['webAliases']);
                $usedAliases = array($vsite['fqdn'] => $vsite['fqdn']);
                $OldSubOIDs = $CI->cceClient->findx('Subdomains', array('group' => $group));
                foreach ($OldSubOIDs as $num => $oid) {
                    $OldSub = $CI->cceClient->get($oid);
                    if ($OldSub['domainname'] == '') {
                        $OldSub['domainname'] = $vsite['domain'];
                    }
                    $fqdn = $OldSub['hostname'] . '.' . $OldSub['domainname'];
                    $usedAliases[$fqdn] = $fqdn;
                }

                if (in_array($desiredFqdn, $usedAliases)) {
                    $errors[] = ErrorMessage($i18n->get("[[base-subdomains.duplicateEntry]]"));
                }
                else {
                    // We have no errors. We submit to CODB.
                    $config = array(
                        "hostname" => $attributes['hostname'],
                        "domainname" => $attributes['domainname'],
                        "webpath"=> $attributes['rootpath'] . $attributes['webdir'],
                        "group" => $group,
                        "isUser" => '0',
                    );
                    $CI->cceClient->create("Subdomains", $config);
                }

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Restart Apache and (if applicable) FPM:
                $CI->cceClient->set($vsite['OID'], '',  array('force_update' => time()));

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, "/subdomains/vsiteSub?group=$group");
            }
        }

        //
        //-- Generate page:
        //

        // Determine current user's access rights to view or edit information
        // here.  Only 'manageSite' can modify things on this page. 
        if ($CI->serverScriptHelper->getAllowed('manageSite')) {
            $is_site_admin = TRUE;
            $access = 'rw';
        }
        elseif (($CI->serverScriptHelper->getAllowed('siteAdmin')) && ($group == $CI->serverScriptHelper->loginUser['site'])) {
            $access = 'r';
            $is_site_admin = FALSE;
        }
        else {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }

        // Prepare Page:
        $BxPage->setFormUrl("/subdomains/vsiteAddSub?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteservices');
        $BxPage->setVerticalMenuChild('nuonce_subdomain_vsite');
        $page_module = 'base_sitemanage';

        if ($vsiteSub["max_subdomains"] == 0 ) {
            $cfg["max_subdomains"] = $vsiteSub["max_subdomains"] = $SystemSubdomains["default_max_subdomains"];
            $CI->cceClient->setObject("Vsite", $cfg, "subdomains", array('name' => $group));
        }

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("vsite_add_header", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        $xxx = $factory->getDomainName("hostname");
        $xxx->setOptional(true);
        $block->addFormField(
            $xxx,
            $factory->getLabel("hostname"), 
            $defaultPage);

        $ExtrawebAliases = $CI->cceClient->scalar_to_array($vsite['webAliases']);
        $webAliases = array($vsite['fqdn'] => $vsite['fqdn']);
        foreach ($ExtrawebAliases as $key => $value) {
            $webAliases[$value] = $value;
        }

        if (isset($webAliases[$vsite["domain"]])) {
            $domSelect = $webAliases[$vsite["domain"]];
        }
        else {
            $domSelect = $vsite["fqdn"];
        }

        $queue_select = $factory->getMultiChoice("domainname", array_values($webAliases));
        $queue_select->setSelected($domSelect, true);
        $block->addFormField($queue_select, $factory->getLabel("domainname"), $defaultPage);

        $webpath = $factory->getMultiChoice("rootpath");
        $xaa = $factory->getOption($vsite["basedir"] . "/wwwroot/vhosts/");
        $xbb = $factory->getOption($vsite["basedir"] . "/wwwroot/web/");
        $webpath->addOption($xaa);
        $webpath->addOption($xbb);
        $webpath->setSelected(0, true);
        $webpath->setLabelType("label_top no_lines");

        $webdir = $factory->getTextField("webdir", "");
        $webdir->setLabelType("label_top no_lines");

        $fqdn = $factory->getCompositeFormField(array($factory->getLabel("webpath"), $webpath, $webdir), '');
        $fqdn->setColumnWidths(array('col_25', 'col_50', 'col_25'));

        $block->addFormField(
                $fqdn,
                $factory->getLabel("webpath"),
                $defaultPage
                );

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/subdomains/vsiteSub?group=$group"));

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