<?php 
namespace Email\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Blacklist extends BaseController {
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

        if (!$CI->getAllowed('serverEmail')) {
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

        if ($System['MTA'] == 'POSTFIX') {
            if ($BX_SESSION['gui_theme'] === 'elmer') {
                $cancelUrl = "/email/emailsettings?DetailedTab=tabs-3#tabs-3";
            }
            else {
                $cancelUrl = "/email/emailsettings?blacklist#tabs-3";
            }
        }
        else {
            if ($BX_SESSION['gui_theme'] === 'elmer') {
                $cancelUrl = "/email/emailsettings?DetailedTab=tabs-4#tabs-3";
            }
            else {
                $cancelUrl = "/email/emailsettings?blacklist#tabs-4";
            }
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-email", "/email/blacklist");
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

        // Get TARGET of Modification request:
        if(isset($get_form_data['_TARGET'])) {
            $oid = $CI->cceClient->get($get_form_data['_TARGET']);
            if ($oid['CLASS'] == "dnsbl") {
              $blacklistHost = $oid["blacklistHost"];
              $deferTemporary = $oid["deferTemporary"];
              $active = $oid["active"];
            }
            else {
                // These are not the droids we are looking for!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }
         }
         else {
            $deferTemporary = 1;
            $blacklistHost = "";
            $active = "";
         }

        // Get TARGET of Delete request:
        if(isset($get_form_data['_RTARGET'])) {
            $oid = $get_form_data['_RTARGET'];
            $oidData = $CI->cceClient->get($get_form_data['_RTARGET']);

            if ($oidData['CLASS'] != "dnsbl") {
                // These are not the droids we are looking for!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }

            $CI->cceClient->destroy($oid, "");
            $BxPage->ReturnToThisPage($errors, $cancelUrl);
        }

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

        // Form fields that are required to have input:
        $required_keys = array("blacklistHost", "deferTemporary");

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
            // None
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Any additional parameters that we need to pass on?
            $sysOid = $CI->cceClient->get($System['OID'], "Email");
            $oids = $CI->cceClient->findx("dnsbl", array('blacklistHost' => $form_data['blacklistHost']), array(),"","");

            if ((count($oids) > 0) && (!isset($form_data['_TARGET']))) {
                $errors[] = ErrorMessage($i18n->get("[[base-email.blacklistHostExists_error]]"));
            }
            else {

                if(isset($form_data['_TARGET'])) {
                  $old = $CI->cceClient->get($form_data['_TARGET']);
                  $oldHost = $old["blacklistHost"];
                 }

                if (isset($form_data['active'])) {
                    $activeField = "1";
                }
                else {
                    $activeField = "0";
                }

                if (isset($form_data['deferTemporary'])) {
                    $deferTemporary = "1";
                }
                else {
                    $deferTemporary = "0";
                }

                if (!$errors) {
                    if (isset($form_data['_TARGET'])) {
                      $oid = $form_data['_TARGET'];
                      $vals = array(
                            "blacklistHost" => $form_data['blacklistHost'], 
                            "deferTemporary" => $deferTemporary,
                            "active" => $activeField);
                      
                      $CI->cceClient->set($oid, "", $vals);
                    } 
                    else {
                      $CI->cceClient->create("dnsbl", 
                             array(
                                   "blacklistHost" => $form_data['blacklistHost'], 
                                   "deferTemporary" => $deferTemporary,
                                   "active" => $activeField));
                    }
                }
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // We have no errors and have POST data, we submitted to CODB without errors? Redirect to /email/emailsettings#tabs-3
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $previous_URL = $_SERVER['HTTP_REFERER'];
            }
            else {
                $previous_URL = $_SERVER['REQUEST_URI'];
            }
            if ($this->request->getPost(NULL, NULL, TRUE)) {
                if (count($errors) == "0") {
                    // Return to this page and display errors - if there are any.
                    // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                    $BxPage->ReturnToThisPage($errors, $cancelUrl);
                }
                else {
                    // Return to this page and display errors - if there are any.
                    // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                    $BxPage->ReturnToThisPage($errors, $previous_URL);
                }
            }
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/email/blacklist");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_emailServers');
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("secondarySettings", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $xxx = $factory->getTextField("blacklistHost", $blacklistHost);
        $block->addFormField(
                     $xxx,
                     $factory->getLabel("blacklistHostField"),
                     $defaultPage
                     );

        $xxx = $factory->getBoolean("deferTemporary", $deferTemporary);
        $block->addFormField(
                     $xxx,
                     $factory->getLabel("deferField"),
                     $defaultPage
                     );

        $xxx = $factory->getBoolean("active", $active);
        $block->addFormField(
                     $xxx,
                     $factory->getLabel("activeField"),
                     $defaultPage
                     );


        if (isset($get_form_data['_TARGET'])) {
            $target = $factory->getTextField('_TARGET', $get_form_data['_TARGET'], '');
            $block->addFormField(
                         $target,
                         "",
                         $defaultPage
                         );
        }

        // Add the buttons
        $block->addButton($factory->getSaveButton($cancelUrl));
        $block->addButton($factory->getCancelButton($cancelUrl));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }
}
/*
Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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