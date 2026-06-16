<?php 
namespace Support\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Bugreport extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-support", "/support/bugreport");
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

        // Tempfile for the JSON encoded bugreport:
        $BugreportTmpPath = '/var/cache/admserv/' . $BX_SESSION['loginName'] . '_bugreport.tmp';

        $prio_forward_num = array(
            'prio_urgent'       => '0',
            'prio_high'         => '0',
            'prio_medium'       => '0',
            'prio_low'          => '0',
            'prio_unspecified'  => '1'
        );

        $severity_forward_num = array(
            'severity_urgent'       => '0',
            'severity_high'         => '0',
            'severity_medium'       => '0',
            'severity_low'          => '0',
            'severity_unspecified'  => '1'
        );

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

            $cleaned_attributes = array();

            // Clone $attributes:
            $attributes_clone = $attributes;
            if (isset($attributes_clone['include_sos'])) {
                // Bugreport includes SOS-Report:
                if ($attributes_clone['include_sos'] == '1') {
                    unset($attributes_clone['include_sos']);
                    $cleaned_attributes['sos_generate'] = time();
                    $cleaned_attributes['include_sos'] = '1';
                    $SOSreportUrl = 'http://' . $System['hostname'] . '.' . $System['domainname'] . ':444' . $Support['sos_external'];
                    $attributes_clone['sos_report'] = $SOSreportUrl;
                }
                else {
                    // Bugreport does NOT include SOS-Report:
                    $cleaned_attributes['include_sos'] = '0';
                }
            }

            // Set trigger:
            $cleaned_attributes['bugreport_trigger'] = time();

            // Prefix Bugreport Subject with type of message and build number:
            $attributes_clone['bugreport_subject'] = 'Bug(' . $System['productBuild'] . '): ' . $attributes_clone['bugreport_subject'];

            // We use the raw 'bugDescription', as GetFormAttributes() has stripped the formatting
            // turned it into a scalar. Which is not what we want to email:
            unset($attributes_clone['bugDescription']);
            $attributes_clone['bugDescription'] = $form_data['bugDescription'];

            //
            //-- Priority/Severity:
            //

            $attributes_clone['Priority'] = $CI->cceClient->scalar_to_string($attributes_clone['Priority']);
            $attributes_clone['Severity'] = $CI->cceClient->scalar_to_string($attributes_clone['Severity']);

            // Assemble JSON encoded Bug-Report:
            $bugreport = json_encode($attributes_clone);

            // Write the Bugreport temporary file:
            if (!write_file($BugreportTmpPath, $bugreport)) {
                $errors[] = ErrorMessage($i18n->get('[[base-support.Err_writing_tempfile]]'), 'alert_red', 'alarm_bell');
            }
            else {
                $ret = $CI->serverScriptHelper->shell("/bin/chmod 00640 $BugreportTmpPath", $output, 'admserv', $BX_SESSION['sessionId']);
            }

            // Add bugreport tempfile path to CODB:
            $cleaned_attributes['bugreport'] = $BugreportTmpPath;

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "Support",  $cleaned_attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // No errors. Reload the entire page to load it with the updated values:
            if ((count($errors) == "0")) {
                // No errors. Reload the entire page to load it with the updated values:
                $redirect_URL = "/support/bugreport?sent=TRUE";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
            else {
                $errors[] = ErrorMessage($i18n->get('[[base-support.Err_problem_sending_bugreport]]'), 'alert_red', 'alarm_bell');
                // Got errors. Reload the entire page to load it with the updated values:
                $redirect_URL = "/support/bugreport";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }

        //
        //-- Own page logic:
        //

        if (($Support['client_name'] == "") || ($Support['client_email'] == "")) {
            $errors[] = ErrorMessage($i18n->get('[[base-support.Err_sender_contact_details]]'), 'alert_red', 'alarm_bell');
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/support/bugreport");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_support');
        $page_module = 'base_software';

        $defaultPage = 'default';

        $block = $factory->getPagedBlock("bugreportTitle", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- defaultPage:
        //

        // Check if we're here after a submit transaction:
        if (isset($get_form_data['sent'])) {
            if ($get_form_data['sent'] == 'TRUE') {
                // Report has been sent:
                $report_sent = $factory->getHTMLField("report_sent", $i18n->getHtml("[[base-support.BugreportSent]]"), "r");
                $report_sent->setLabelType("nolabel");
                $block->addFormField(
                  $report_sent,
                  $factory->getLabel("report_sent"),
                  $defaultPage
                );
            }
        }
        else {

            // Show the form:

            // Add divider:
            $xxx = $factory->addBXDivider("sender", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("sender", false),
                    $defaultPage
                    );

            $client_name = $factory->getTextField("client_name", $Support['client_name'], 'r');
            $client_name->setType("");
            $block->addFormField(
              $client_name,
              $factory->getLabel("client_name"),
              $defaultPage
            );

            $client_email = $factory->getEmailAddress("client_email", $Support['client_email'], 'r');
            $block->addFormField(
              $client_email,
              $factory->getLabel("client_email"),
              $defaultPage
            );

            // Add divider:
            $xxx = $factory->addBXDivider("recipient", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("recipient", false),
                    $defaultPage
                    );

            $recipient_name = $factory->getTextField("recipient_name", $Support['bx_bugreport_name'], 'r');
            $client_name->setType("");
            $block->addFormField(
              $recipient_name,
              $factory->getLabel("recipient_name"),
              $defaultPage
            );

            $recipient_email = $factory->getEmailAddress("recipient_email", $Support['bx_bugreport_email'], 'r');
            $block->addFormField(
              $recipient_email,
              $factory->getLabel("recipient_email"),
              $defaultPage
            );

            // Add divider:
            $xxx = $factory->addBXDivider("bugreportTitle", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("bugreportTitle", false),
                    $defaultPage
                    );

            $bugreport_subject = $factory->getTextField("bugreport_subject", '', 'rw');
            $bugreport_subject->setType("");
            $block->addFormField(
              $bugreport_subject,
              $factory->getLabel("bugreport_subject"),
              $defaultPage
            );

            $server_model = $factory->getTextField("server_model", $System['productName'] . ' (' . $System['productBuildString'] . ')', 'r');
            $server_model->setType("");
            $block->addFormField(
              $server_model,
              $factory->getLabel("server_model"),
              $defaultPage
            );

            // Priority:
            $xxx = $factory->getRadio("Priority", $prio_forward_num, "rw");
            $block->addFormField(
                $xxx,
                $factory->getLabel("Priority"),
                $defaultPage
            );

            // Severity:
            $xxx = $factory->getRadio("Severity", $severity_forward_num, "rw");
            $block->addFormField(
                $xxx,
                $factory->getLabel("Severity"),
                $defaultPage
            );

            $bugURL = $factory->getTextField("bugURL", '', 'rw');
            $bugURL->setOptional(TRUE);
            $bugURL->setType("");
            $block->addFormField(
              $bugURL,
              $factory->getLabel("bugURL"),
              $defaultPage
            );

            // $include_sos = $factory->getBoolean("include_sos", '0', "rw");
            // $block->addFormField(
            //   $include_sos,
            //   $factory->getLabel("include_sos"),
            //   $defaultPage
            // );

            $bugDescription = $factory->getTextList("bugDescription", '', 'rw');
            $bugDescription->setOptional(FALSE);
            $bugDescription->setType("");
            $block->addFormField(
              $bugDescription,
              $factory->getLabel("bugDescription"),
              $defaultPage
            );

            //
            //--- Add the buttons
            //

            // Disable the Save-Button if the Support-Settings haven't been configured yet:
            $save_button = $factory->getSaveButton($BxPage->getSubmitAction());
            if (($Support['client_name'] == "") || ($Support['client_email'] == "")) {
                $save_button->setDisabled(TRUE);
            }

            $block->addButton($save_button);
            $block->addButton($factory->getCancelButton("/support/bugreport"));
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