<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Yum extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-yum", "/swupdate/yum");
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

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Page logic start:
        //

        //
        //--- Get CODB-Object of interest: 
        //

        $CODBDATA = $CI->cceClient->get($System['OID'], "yum");

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_", "yumlog", "yum_last_updated");

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
            if ($attributes['autoupdate'] == "1") {
                $attributes['autoupdate'] = "On";
            }
            else {
                $attributes['autoupdate'] = "Off";
            }
            $attributes['y_force_update'] = mt_rand();
        }
        else {
            // We're not saving changes. So we set 'skiplock' to call a handler that runs a
            // chmod 444 over our files in /tmp so that this PHP page can access them:
            mt_srand((int)microtime() * 1000000);
            $skiplock = mt_rand();
            $config = array(
            "skiplock" => $skiplock
            );
            $CI->cceClient->set($System['OID'], "yum",  $config);
            $errors = $CI->cceClient->errors();         
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Actual submit to CODB:
            $CI->cceClient->setObject("System", $attributes, "yum");

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Reload the entire page to load it with the updated values:
            $redirect_URL = "/swupdate/yum";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Own page logic:
        //

        if (file_exists("/tmp/yum.updating")) {
            $errors[] = ErrorMessage($i18n->get('[[base-yum.yum_is_pulling_updates_help]]'), 'alert_navy', 'info_about');
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/swupdate/yum");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('yum_gui');
        $page_module = 'base_software';

        $defaultPage = "yumTitle";

        $block = $factory->getPagedBlock("yumgui_head", array($defaultPage, "Settings", "Logs"));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        // Display date of last update:
        if (file_exists("/var/log/dnf.log") ) {
            $yum_last_updated = `/usr/bin/stat /var/log/dnf.log |grep "Modify:"|sed 's/Modify: //g'|sed 's/\..*//g'`;
            $xxx = $factory->getTextField("yum_last_updated", $yum_last_updated, "r");
            $block->addFormField(
                $xxx,
                $factory->getLabel("yum_last_updated"),
                $defaultPage
            );
        }

        // Display notice that system is currently installing updates:
        if (!file_exists("/tmp/yum.updating")) {
            // Add ButtonContainer and button to manually check for updates:
            $yumCheck = $factory->getButton("/swupdate/checkupdates", "yumCheck");
            $yumCheck->setIcon('fa fa-cloud-download');
            $yumNOW = $factory->getButton("/swupdate/yumupdate", "yumNOW");
            $yumNOW->setIcon('fa fa-repeat');

            $buttonContainer = $factory->getButtonContainer("", array($yumCheck, $yumNOW));
            $block->addFormField(
                $buttonContainer,
                $factory->getLabel("yumCheck"),
                $defaultPage
            );
        }

        //
        //--- Available YUM updates:
        //

        // Set up ScrollList:
        $ScrollList = $factory->getScrollList("yumTitle", array("name", "version", "status"), array()); 
        $ScrollList->setAlignments(array("left", "center", "right"));
        $ScrollList->setDefaultSortedIndex('0');
        $ScrollList->setSortOrder('ascending');
        $ScrollList->setSortDisabled(array());
        $ScrollList->setPaginateDisabled(FALSE);
        $ScrollList->setSearchDisabled(FALSE);
        $ScrollList->setSelectorDisabled(FALSE);
        $ScrollList->enableAutoWidth(FALSE);
        $ScrollList->setInfoDisabled(FALSE);
        $ScrollList->setDisplay(25);
        $ScrollList->setColumnWidths(array("33%", "33%", "33%")); // Max: 739px

        // Do we have any updates to install?
        if (file_exists("/tmp/yum.check-update") ) {
            $yum_output = file_get_contents("/tmp/yum.check-update");
            $a_yum = preg_split("/\n/", $yum_output);
            $count = count($a_yum);

            $updates = [];
            $relevantLineCount = 0;
            $obsoletingPackagesFound = false; // Flag to mark when 'Obsoleting Packages' section starts

            $start = 0;
            for ( $i = 0; $i < $count; $i++ ) {

                // Check if the line is 'Obsoleting Packages'
                if (trim($a_yum[$i]) == "Obsoleting Packages") {
                    $obsoletingPackagesFound = true;
                    continue;
                }

                // Skip processing if in the 'Obsoleting Packages' section
                if ($obsoletingPackagesFound) {
                    continue;
                }

                if (preg_match('/^Last meta(.*)$/', $a_yum[$i])) {
                    // nada
                }
                elseif ( $a_yum[$i] != "" ) {
                    $updates[] = $a_yum[$i];
                    $relevantLineCount++;
                }
            }

            if (isset($updates) && count($updates) > 0 ) { 
                foreach ( $updates as $entry ) {
                    $yum_update = 1;
                    $entry = preg_replace("/\s+/", " ", $entry);
                    $a_entry = preg_split("/ /", $entry);

                    if (($a_entry[0] != "") && ((isset($a_entry[0])) && (isset($a_entry[1])) && (isset($a_entry[2])))) {
                        // Remove trailing colon from repository name:
                        $a_entry[0] = preg_replace('/:$/', '', $a_entry[0]);
                        $ScrollList->addEntry(array(
                            $a_entry[0],
                            $a_entry[1],
                            $a_entry[2],
                        ));
                    }
                }
            }
        }
        else {
            $yum_output = "";
        }

        // Show the ScrollList for the Updates:
        $xxx = $factory->getRawHTML("yumTitle", $ScrollList->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("yumTitle"),
            $defaultPage
        );

        //
        //--- Settings:
        //

        if ($CODBDATA["autoupdate"] == "On") {
            $CODBDATA["autoupdate"] = "1";
        }
        else {
            $CODBDATA["autoupdate"] = "0";
        }

        $xxx = $factory->getBoolean("autoupdate", $CODBDATA["autoupdate"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("autoupdate"),
          "Settings"
        );

        $exclude_box = $factory->getTextBlock("yumguiEXCLUDE", $CODBDATA["yumguiEXCLUDE"]);
        $exclude_box->setHeight("5");
        $exclude_box->setWidth("40");
        $exclude_box->setOptional(true);

        $block->addFormField(
          $exclude_box,
          $factory->getLabel("yumguiEXCLUDE"),
          "Settings"
          );

        $xxx = $factory->getBoolean("yumguiEMAIL", $CODBDATA["yumguiEMAIL"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumguiEMAIL"),
          "Settings"
          );

        // Work around for yumguiEMAILADDY: Need to change it to fully qualified email address:
        if (!preg_match('/\@/', $CODBDATA["yumguiEMAILADDY"])) {
            $CODBDATA["yumguiEMAILADDY"] = $CODBDATA["yumguiEMAILADDY"] . '@' . $System['hostname'] . '.' . $System['domainname'];
        }

        $yumguiEMAILADDYField = $factory->getTextField("yumguiEMAILADDY", $CODBDATA['yumguiEMAILADDY']);
        $yumguiEMAILADDYField->setOptional ('silent');
        $yumguiEMAILADDYField->setType ('email');
        $block->addFormField(
          $yumguiEMAILADDYField,
          $factory->getLabel("yumguiEMAILADDY"),
          "Settings"
        );

        $time_to_update = array();
        for ($i = 0; $i < 24 ; $i++ ) {
          $time_to_update []= "$i:00";
          $time_to_update []= "$i:30";
        }

        $yumUpdateTime= $factory->getMultiChoice("yumUpdateTime", $time_to_update);
        $yumUpdateTime->setSelected($CODBDATA["yumUpdateTime"], true);
        $block->addFormField(
          $yumUpdateTime,
          $factory->getLabel("yumUpdateTime"),
          "Settings"
        );

        $xxx = $factory->getBoolean("yumUpdateSU", $CODBDATA["yumUpdateSU"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumUpdateSU"),
          "Settings"
        );

        $xxx = $factory->getBoolean("yumUpdateMO", $CODBDATA["yumUpdateMO"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumUpdateMO"),
          "Settings"
        );

        $xxx = $factory->getBoolean("yumUpdateTU", $CODBDATA["yumUpdateTU"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumUpdateTU"),
          "Settings"
        );

        $xxx = $factory->getBoolean("yumUpdateWE", $CODBDATA["yumUpdateWE"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumUpdateWE"),
          "Settings"
        );

        $xxx = $factory->getBoolean("yumUpdateTH", $CODBDATA["yumUpdateTH"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumUpdateTH"),
          "Settings"
        );

        $xxx = $factory->getBoolean("yumUpdateFR", $CODBDATA["yumUpdateFR"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumUpdateFR"),
          "Settings"
        );

        $xxx = $factory->getBoolean("yumUpdateSA", $CODBDATA["yumUpdateSA"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("yumUpdateSA"),
          "Settings"
        );

        //
        //-- YUM Logfile:
        //

        // Logfile viewer:
        $the_file_data = $i18n->getClean("yumlog_empty");
        if ((file_exists("/var/log/dnf.log")) && (is_readable("/var/log/dnf.log"))) {
            $file_info = get_file_info("/var/log/dnf.log");
            $datei_yum = "/var/log/dnf.log";
            $array_yum = file($datei_yum);
            $array_yum = array_reverse($array_yum);

            $the_file_data = '';
            for($x=0;$x<count($array_yum);$x++){
                // Replace
                $array_yum[$x] = nl2br($array_yum[$x]); //#newline conversion
                $array_yum[$x] = br2nl($array_yum[$x]);
                // Get last 500 lines of dnf.log:
                if ($x < 500) {
                    $rest_after_date = preg_split('#\s+#', $array_yum[$x], 2);
                    if (!isset($currently_processed_date)) {
                        $currently_processed_date = $rest_after_date[0];
                    }
                    $currently_processed_date = $rest_after_date[0];
                    if (isset($rest_after_date[1])) {
                        $typedata = preg_split('#\s+#', $rest_after_date[1], 2);
                        if (isset($typedata[0])) {
                            if (($typedata[0] != "DEBUG") && ($typedata[0] != "DDEBUG")) {
                                $new_line = $currently_processed_date . ": " . $rest_after_date[1];
                                $the_file_data = $the_file_data.$new_line;
                            }
                        }
                    }
                }
            }

            // Logfile is present, but empty:
            if ($file_info['size'] == '0') {
                $the_file_data = $i18n->getClean("yumlog_empty");
            }
        }

        $box = $factory->getTextBlock("yumlog", $the_file_data, "r");
        $block->addFormField($box, $factory->getLabel("yumlog"), "Logs");

        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/swupdate/yum"));

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