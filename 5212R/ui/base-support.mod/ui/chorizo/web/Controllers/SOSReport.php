<?php 
namespace Support\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class SOSReport extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-support", "/support/SOSReport");
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

        //
        //--- Get CODB-Object of interest: 
        //

        $Support = $CI->getSupport();

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

        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.
            $cleaned_attributes = array();
            if (isset($attributes['sos_delete'])) {
                if ($attributes['sos_delete'] == '1') {
                    $cleaned_attributes['sos_delete'] = time();
                }
            }
            if (isset($attributes['sos_generate'])) {
                if ($attributes['sos_generate'] == '1') {
                    $cleaned_attributes['sos_generate'] = time();
                }
            }

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "Support",  $cleaned_attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // No errors. Reload the entire page to load it with the updated values:
            $redirect_URL = "/support/SOSReport";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/support/SOSReport");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_support');
        $page_module = 'base_software';

        $defaultPage = 'default';

        $block = $factory->getPagedBlock("sos_reportMenu", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- defaultPage:
        //

        // SOS-Report already present?
        if (($Support['sos_present'] == '1') && 
            (is_file('/usr/sausalito/ui/web' . $Support['sos_external'])) && (is_dir('/usr/sausalito/ui/web/debug/') . $Support['sos_internal']) &&
            ($Support['sos_trigger'] == '0')) {

            $sos_present = $factory->getBoolean("sos_present", $Support['sos_present'], "r");
            $block->addFormField(
              $sos_present,
              $factory->getLabel("sos_present"),
              $defaultPage
            );

            $sos_epoch = $factory->getTimeStamp("sos_epoch", $Support['sos_epoch'], "r");
            $sos_epoch->setFormat("datetime");
            $block->addFormField(
              $sos_epoch,
              $factory->getLabel("sos_epoch"),
              $defaultPage
            );

            $sos_delete = $factory->getBoolean("sos_delete", '0', "rw");
            $block->addFormField(
              $sos_delete,
              $factory->getLabel("sos_delete"),
              $defaultPage
            );

        }
        elseif (($Support['sos_present'] == '0') && ($Support['sos_trigger'] == '1')) {
            $errors[] = ErrorMessage($i18n->get('[[base-support.Err_sos_report_being_generated]]'), 'alert_green', 'info_about');
            $BxPage->setErrors($errors);

            $sos_generate = $factory->getBoolean("sos_generate", '1', "r");
            $block->addFormField(
              $sos_generate,
              $factory->getLabel("sos_generate"),
              $defaultPage
            );
        }
        else {
            $sos_generate = $factory->getBoolean("sos_generate", '0', "rw");
            $block->addFormField(
              $sos_generate,
              $factory->getLabel("sos_generate"),
              $defaultPage
            );
        }

        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/support/SOSReport"));

        $page_body[] = $block->toHtml();

        // Page body with iFrame for SOS-Report (if present):
        if (($Support['sos_present'] == '1') && 
            (is_file('/usr/sausalito/ui/web' . $Support['sos_external'])) && (is_dir('/usr/sausalito/ui/web/debug/') . $Support['sos_internal'])) {
            $uri = '/debug/' . $Support['sos_internal'] . '/sos_reports/sosreport.html';
            $page_body[] = addInputForm(
                                            $i18n->get("[[base-support.sos_reportMenu]]"),
                                            array("window" => $uri, "toggle" => "#"), 
                                            addIframe($uri, "1200", $BxPage),
                                            "",
                                            $i18n,
                                            $BxPage,
                                            $errors
                                        );
        }

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