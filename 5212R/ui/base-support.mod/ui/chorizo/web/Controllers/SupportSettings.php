<?php 
namespace Support\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class SupportSettings extends BaseController {
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

        if (!$CI->getAllowed('managePackage')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-support", "/support/supportSettings");
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


        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //--- Get CODB-Object of interest: 
        //

        // Get settings
        $Support = $CI->cceClient->get($System['OID'], "Support", array('cce_nocache' => 'cce_nocache'));
        $CI->setSupport($Support);

        //
        //--- Handle form validation:
        //

        // Location (URLs) of the various NewLinQ query resources:
        $bluelinq_server    = 'newlinq.blueonyx.it';
        $newlinq_url        = "http://$bluelinq_server/showshops/";
        $serialNumber       = $System['serialNumber'];
        $client_email = get_data("http://$bluelinq_server/username/$serialNumber");

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
            if (($client_email != "0") && ($client_email != "")) {
                $attributes['client_email'] = $client_email;
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // Check if we are online:
        if (areWeOnline($newlinq_url)) {

          // Get Serial:
          $serialNumber = $System['serialNumber'];

          // Poll NewLinQ about our status:
          $snstatus = "RED";
          $snstatus = get_data("http://$bluelinq_server/snstatus/$serialNumber");
          if (!$snstatus === "RED") {
             $string = $i18n->interpolateHtml("[[status-sn$snstatus]]");
          }
          else {
            if ($snstatus === "ORANGE") {
                $string = $i18n->interpolateHtml("[[status-sn$snstatus]]");
                $snstatusx = get_data("http://$bluelinq_server/snchange/$serialNumber");
            } 
            else {
                $ipstatus = get_data("http://$bluelinq_server/ipstatus/$serialNumber");
                $string = $i18n->interpolateHtml("[[status-ip$ipstatus]]");
                if ( $ipstatus === "ORANGE" ) {
                    $string = $i18n->interpolateHtml("[[status-ip$ipstatus]]");
                    $ipstatusx = get_data("http://$bluelinq_server/ipchange/$serialNumber");
                }
            }
          }
          // Are we online and in the green?
          if ($snstatus == "GREEN") {
              $online = "1";
          }
        }
        else {
            // Not online, poll of 'newlinq.blueonyx.it' failed. Show error message:
            $online = "0";
            $errors[] = ErrorMessage($i18n->get('[[base-support.Error_NewLinQ_Down]]'), 'alert_red', 'alarm_bell');
        }

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "Support",  $attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Reload the entire page to load it with the updated values:
            $redirect_URL = "/support/supportSettings";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/support/supportSettings");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_support');
        $BxPage->setVerticalMenuChild('support_settings');
        $page_module = 'base_software';

        $defaultPage = 'basic_tab';
        $wiki_tab = 'wiki_tab';
        $ISP_tab = 'isp_tab';

        $block = $factory->getPagedBlock("support_settings", array($defaultPage, $wiki_tab, $ISP_tab));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- basic_tab:
        //

        $client_name = $factory->getTextField("client_name", $Support['client_name'], "rw");
        $client_name->setOptional(FALSE);
        $client_name->setType("");
        $block->addFormField(
          $client_name,
          $factory->getLabel("client_name"),
          $defaultPage
        );

        $email_access = 'rw';
        if (($client_email != "0") && ($client_email != "")) {
            $Support['client_email'] = $client_email;
            $email_access = 'r';
        }

        $client_email = $factory->getEmailAddress("client_email", $Support['client_email'], $email_access);
        $client_email->setOptional(FALSE);
        $block->addFormField(
          $client_email,
          $factory->getLabel("client_email"),
          $defaultPage
        );

        $support_account = $factory->getTextField("support_account", $Support['support_account'], "r");
        $support_account->setOptional(FALSE);
        $support_account->setType("alphanum_plus");
        $block->addFormField(
          $support_account,
          $factory->getLabel("support_account"),
          $defaultPage
        );

        //
        //--- wiki_tab
        //

        $wiki_enabled = $factory->getBoolean("wiki_enabled", $Support['wiki_enabled'], "rw");
        $block->addFormField(
          $wiki_enabled,
          $factory->getLabel("wiki_enabled"),
          $wiki_tab
        );

        $wiki_baseURL = $factory->getUrl("wiki_baseURL", $Support['wiki_baseURL'], "", "", "rw");
        $wiki_baseURL->setOptional(FALSE);
        $block->addFormField(
          $wiki_baseURL,
          $factory->getLabel("wiki_baseURL"),
          $wiki_tab
        );

        $wiki_tabbed = $factory->getBoolean("wiki_tabbed", $Support['wiki_tabbed'], "rw");
        $block->addFormField(
          $wiki_tabbed,
          $factory->getLabel("wiki_tabbed"),
          $wiki_tab
        );

        //
        //--- isp_tab
        //

        $isp_name = $factory->getTextField("isp_support_name", $Support['isp_support_name'], "rw");
        $isp_name->setOptional(TRUE);
        $isp_name->setType("");
        $block->addFormField(
          $isp_name,
          $factory->getLabel("isp_support_name"),
          $ISP_tab
        );

        $isp_email = $factory->getEmailAddress("isp_support_email", $Support['isp_support_email'], "rw");
        $isp_email->setOptional(TRUE);
        $block->addFormField(
          $isp_email,
          $factory->getLabel("isp_support_email"),
          $ISP_tab
        );

        $isp_identifier = $factory->getTextList("isp_support_identifier", $Support['isp_support_identifier'], "rw");
        $isp_identifier->setOptional(TRUE);
        $isp_identifier->setType("");
        $block->addFormField(
          $isp_identifier,
          $factory->getLabel("isp_support_identifier"),
          $ISP_tab
        );

        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/support/supportSettings"));

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