<?php 
namespace Console\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Ablsettings extends BaseController {
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
        if (!$CI->getAllowed('serverConfig')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-console", "/console/ablsettings");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-console", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- Handle POST Request:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Has getPost request:
            $form_data = $BxPage->FORM_POST;

            // Form fields that are required to have input:
            $required_keys = array();

            // Empty array for key => values we want to submit to CCE:
            $attributes = array();

            // Items we do NOT want to submit to CCE:
            $ignore_attributes = array("BlueOnyx_Info_Text", 'pam_abl_location');

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
            //--- No errors? Submit to CODB:
            //

            if (count($errors) == "0") {

                // Any additional parameters that we need to pass on?
                $attributes['update_config'] = time();
                $attributes['force_update'] = time();

                if ($attributes['host_rule'] == "disabled") {
                    $attributes['host_rule'] = "50000/1m";
                }
                $attributes['host_rule'] = "*:" . $attributes['host_rule'];

                // Actual submit to CODB:
                $CI->cceClient->setObject("pam_abl_settings", $attributes);

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors);
            }
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_security');
        $BxPage->setVerticalMenuChild('pam_abl');
        $page_module = 'base_sysmanage';

        $defaultPage = "pam_abl_config_location";
        $block = $factory->getPagedBlock("pam_abl_head", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // pam_abl.conf location:
        $pam_abl_location_Field = $factory->getTextField("pam_abl_location", "/etc/security/pam_abl.conf", "r");
        $pam_abl_location_Field->setOptional ('silent');
        $block->addFormField(
            $pam_abl_location_Field,
            $factory->getLabel("pam_abl_location"),
            "pam_abl_config_location"
        );

        // host_rule:
        $CODBDATA = $CI->cceClient->getObject("pam_abl_settings");
        $host_rule_raw = $CODBDATA['host_rule'];
        $hr_diss = explode(':', $host_rule_raw);
        if (!isset($hr_diss[1])) {
            // 'host_rule' in CODB is fubar. Set it to default:
            $attributes['host_rule'] = "*:30/1h";
            $CI->cceClient->setObject("pam_abl_settings", $attributes);

            // Now try it again:
            $CODBDATA = $CI->cceClient->getObject("pam_abl_settings");
            $host_rule_raw = $CODBDATA['host_rule'];
            $hr_diss = explode(':', $host_rule_raw);
        }
        $host_rule = $hr_diss[1];

        // build array:
        $host_rule_choices=array(
            "1/1h"    => "1/1h", 
            "3/1h"    => "3/1h", 
            "5/1h"    => "5/1h", 
            "10/1h"   => "10/1h", 
            "20/1h"   => "20/1h", 
            "30/1h"   => "30/1h", 
            "40/1h"   => "40/1h", 
            "50/1h"   => "50/1h", 
            "60/1h"   => "60/1h", 
            "100/1h"  => "100/1h",
            "50000/1m"=> "disabled"
            );

        // If we don't have current value in array, add it:
        if (!in_array($host_rule, $host_rule_choices)) {
            $host_rule_choices[$host_rule] = $host_rule;
        }

        // Sort the array:
        array_multisort($host_rule_choices, SORT_NUMERIC, SORT_ASC);

        // host_rule Input:
        $host_rule_select = $factory->getMultiChoice("host_rule",array_values($host_rule_choices));
        $host_rule_select->setSelected($host_rule_choices[$host_rule], true);
        $block->addFormField($host_rule_select,$factory->getLabel("host_rule"), "pam_abl_config_location");

        $greylist_extraField = $factory->getTextList("host_whitelist", $CODBDATA["host_whitelist"], 'rw');
        $greylist_extraField->setOptional(true);
        $greylist_extraField->setType('InetAddressList');
        $block->addFormField(
            $greylist_extraField,
            $factory->getLabel("host_whitelist"),
            "pam_abl_config_location"
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/console/ablsettings"));

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
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