<?php 
namespace Time\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Timeconfig extends BaseController {
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

        if (!$CI->getAllowed('serverTime')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-time", "/time/timeconfig");
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
        //--- Get CODB-Object of interest: 
        //

        $CODBDATA = $CI->cceClient->get($System['OID'], "Time");
        @date_default_timezone_set($CODBDATA["timeZone"]);

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

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            //          Array
            //          (
            //              [oldTime] => 1382493492
            //              [systemDate] => 1382493492
            //              [_systemDate_oyear] => 2013
            //              [_systemDate_omonth] => 9
            //              [_systemDate_ohour] => 21
            //              [_systemDate_ominute] => 58
            //              [_systemDate_osecond] => 12
            //              [_systemDate_month] => 09
            //              [_systemDate_day] => 21
            //              [_systemDate_year] => 2013
            //              [_systemDate_hour] => 9
            //              [_systemDate_minute] => 58
            //              [_systemDate_amPm] => AM
            //              [timezoneSelectDropdown] => America/Lima
            //              [oldTimeZone] => US/Eastern
            //          )

            if ($attributes['timezoneSelectDropdown'] != $attributes['oldTimeZone']) {
                $timeZone = $attributes['timezoneSelectDropdown'];
                putenv("TZ=$timeZone");
            }
            else {
                $timeZone = $attributes['timezoneSelectDropdown'];
            }

            if ($timeZone == "") {
                // Got nothing? Set a default:
                $timeZone == "US/Eastern";
            }

            if ($attributes['timezoneSelectDropdown'] == 'BST') {
                $timeZone = 'Europe/London';
                putenv("TZ=$timeZone");
            }

            if ($BX_SESSION['gui_theme'] === 'adminica') {

                //
                //--- Handle Adminica form inputs:
                //

                if (!isset($attributes['_systemDate_amPm'])) {
                    $attributes['_systemDate_amPm'] = "AM";
                }

                if ($attributes['_systemDate_amPm'] == "PM") {
                    $attributes['_systemDate_hour'] += 12;
                }

                $date = mktime($attributes['_systemDate_hour'], $attributes['_systemDate_minute'], "00", $attributes['_systemDate_month'], $attributes['_systemDate_day'], $attributes['_systemDate_year']);
                if ($date and ($date != $attributes['oldTime'])) {
                    $time = $date;
                }
                if (!isset($time)) {
                    $time = time();
                }
            }
            else {

                //
                //--- Handle Elmer form inputs:
                //

                $date = strtotime($attributes['systemDate']);
                if ($date and ($date != $attributes['oldTime'])) {
                    $time = $date;
                }
                if (!isset($time)) {
                    $time = time();
                }
            }

            $ntpEnabled = '1';
            if ($attributes['ntpAddress'] == '') {
                $ntpEnabled = '0';
            }

            // Actual submit to CODB:
            // "deferCommit" is used by the setup wizard, not here... clean up just in case
            $CI->cceClient->setObject('System', array(
                                        'deferCommit' => '0',
                                        'epochTime' => $time,
                                        'timeZone' => $timeZone,
                                        'ntpAddress' => $attributes['ntpAddress'],
                                        'ntpEnabled' => $ntpEnabled
                                        ), 'Time');

            // Work around for 5106R oddity. We use the extra handler to set the timezone instead:
            $CI->cceClient->setObject('System', array(
                                        'epochTime' => $time,
                                        'timeZone' => $timeZone,
                                        'ntpAddress' => $attributes['ntpAddress'],
                                        'trigger' => time()
                                        ), 'TempTime');

            $CI->serverScriptHelper->shell("/usr/sausalito/sbin/setTime " . escapeshellarg($time) . " " . escapeshellarg($timeZone) . " " . escapeshellarg($attributes['ntpAddress']) . " true", $output, "root", $CI->BX_SESSION['sessionId']);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Reload the entire page to load it with the updated values:
            $redirect_URL = "/time/timeconfig";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/time/timeconfig");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_serverconfig');
        $BxPage->setVerticalMenuChild('base_time');
        $page_module = 'base_sysmanage';

        $defaultPage = "basic";

        $block = $factory->getPagedBlock("timeSetting", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- TAB: basic
        //

        // Get current time from time():
        $t = time();

        if ($CODBDATA["timeZone"] == "") {
            // Got nothing? Set a default:
            $CODBDATA["timeZone"] == "US/Eastern";
        }

        if ($CODBDATA["timeZone"] == 'Europe/London') {
            // Got nothing? Set a default:
            $CODBDATA["timeZone"] = "BST";
        }

        if ($BX_SESSION['gui_theme'] === 'elmer') {

            //
            //--- Use getDatePicker() UIFC2 element for Elmer:
            //

            $DatePickerField = $factory->getDatePicker("systemDate", $t, "datetime", 'rw');
            $DatePickerField->setCurrentLabel($i18n->get('systemDisplayedDate'));
            $DatePickerField->setDescription($i18n->get('systemDisplayedDate_help'));
            $DatePickerField->setModus('all');
            $block->addFormField($DatePickerField, $factory->getLabel('systemDisplayedDate'));

            $oldTime = $factory->getTimeStamp("oldTime", $t, "time", "");
            $block->addFormField($oldTime);
        }
        else {

            //
            //--- Use getTimeStamp() UIFC1 element for Adminica:
            //

            $SystemDisplayedDate = $factory->getTimeStamp("systemDate", $t, "datetime", 'rw');
            $oldTime = $factory->getTimeStamp("oldTime", $t, "time", "");
            $block->addFormField($oldTime);
            $block->addFormField($SystemDisplayedDate, $factory->getLabel("systemDisplayedDate"));
        }

        $SystemDisplayedTimeZone = $factory->getTimeZone("systemTimeZone", $CODBDATA["timeZone"], 'rw');
        $block->addFormField($SystemDisplayedTimeZone, $factory->getLabel("systemDisplayedTimeZone"));

        $oldTimeZone = $factory->getTextField("oldTimeZone", $CODBDATA["timeZone"], "");
        $block->addFormField($oldTimeZone);

        // NTP server may only be set on stand alone servers, not in a Container:
        if ((!file_exists("/proc/user_beancounters")) && (!file_exists("/dev/incus/sock"))) {
            $ntpAddress = $factory->getNetAddress("ntpAddress", $CODBDATA["ntpAddress"]);
            $ntpAddress->setOptional(true);  
            $ntpAddress->setMaxLength(50);
            $block->addFormField($ntpAddress, $factory->getLabel("ntpAddress"));
          
        }
        else {
            $ntpAddress = $factory->getTextField("ntpAddress", "", "");
            $ntpAddress->setOptional(true);
            $ntpAddress->setMaxLength(50);
            $block->addFormField($ntpAddress, $factory->getLabel("ntpAddress"));
        }

        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/time/timeconfig"));

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