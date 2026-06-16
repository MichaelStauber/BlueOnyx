<?php 
namespace Sitestats\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class SummaryWeb extends BaseController {
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
        $user = $BX_SESSION['loginUser'];

        //
        //--- Restrict access:
        //

        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-sitestats", "/sitestats/summaryWeb");
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

        // This is stupid. Adminica Datepicker looses the URI strings if we don't send some garbage to the output first. No idea why.
        if ($BX_SESSION['gui_theme'] === 'adminica') {
            print_r("&nbsp;");
        }

        $group = 'server';
        if (isset($get_form_data['group'])) {
            $group = $get_form_data['group'];
        }

        //
        // Access Rules:
        //

        //
        //-- Access Rights Check for Vsite level pages:
        // 
        // 1.) Checks if the Group/Vsite exists.
        // 2.) Checks if the user is systemAdministrator
        // 3.) Checks if the user is Reseller of the given Group/Vsite
        // 4.) Checks if the iser is siteAdmin of the given Group/Vsite
        // Returns Forbidden403 if *none* of that is the case.
        if ((!$CI->getAllowed('adminUser')) && 
            (!$CI->getAllowed('siteAdmin')) && 
            (!$CI->getAllowed('manageSite')) && 
            (($user['site'] != $CI->serverScriptHelper->loginUser['site']) && $CI->getAllowed('siteAdmin')) &&
            (($vsiteObj['createdUser'] != $BX_SESSION['loginName']) && $CI->getAllowed('manageSite'))
            ) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $BxPage->setFormUrl("/sitestats/summaryWeb?group=$group");

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

        $selectedYear = date("Y");
        $selectedMonth = date("m");
        $selectedDay = date("d");

        $defaultDate = $selectedYear . '-' . $selectedMonth . '-' . $selectedDay;

        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            if (isset($attributes['dateSelected'])) {
                $dateSelected = $attributes['dateSelected'];

                $DatePieces = explode("-", $dateSelected);
                if (count($DatePieces) == '3') {
                    $selectedYear = $DatePieces['0'];
                    $selectedMonth = $DatePieces['1'];
                    $selectedDay = $DatePieces['2'];
                }
            }
            $BxPage->ReturnToThisPage($errors, "/sitestats/summaryWeb?group=$group&YEAR=$selectedYear&MONTH=$selectedMonth&DAY=$selectedDay");
        }

        //
        //-- Prepare Page:
        //

        $BxPage->setErrors($errors);

        // Set Menu items:
        if ($group == "server") {
            $BxPage->setVerticalMenu('base_serverusage');
            $BxPage->setVerticalMenuChild('base_server_webusage');
            $page_module = 'base_sysmanage';
        }
        else {
            $BxPage->setVerticalMenu('base_siteusage');
            $BxPage->setVerticalMenuChild('base_webusage');
            $page_module = 'base_sitemanage';
        }

        //
        //-- Check if we have stats:
        //

        if ($group != 'server') {
            // Get data for the Vsite:
            $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));
            $statspath_top = $vsite['basedir'] . '/var/logs/';
            $most_recent_stats = $statspath_top . 'web.json';
        }
        else {
            if (!$CI->getAllowed('adminUser')) {
                // Yeah. Nope! Only 'adminUser' can see this!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }

            // Set access-cookie for GoAccess that expires at end of the browser session. 
            setcookie("BXGoAccess", $CI->BX_SESSION['sessionId'], "0", "/");

            $statspath_top = '/home/.sites/server/logs/';
            $most_recent_stats = $statspath_top . 'web.json';
        }

        //
        //--- Fetch list of available stats:
        //

        $output = '';
        $failed_to_fetch = '0';
        $stats_dir_array = array();
        $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/get_web_stats.pl --group $group", $output, 'root', $BX_SESSION['sessionId']);
        if ($ret != 0) {
            // File not present.
            $failed_to_fetch = '1';
        }
        else {
            $stats_dir_array = json_decode($output, true, JSON_FORCE_OBJECT);
            $json_error = json_last_error();
            if ($json_error == '1') {
                // Failed to decode JSON:
                $failed_to_fetch = '1';
            }
        }

        //
        //--- Do we have stats for the selected day?
        //

        if ((isset($get_form_data['YEAR'])) && (isset($get_form_data['MONTH'])) && (isset($get_form_data['DAY']))) {
            $selectedYear = $get_form_data['YEAR'];

            $selectedMonth = $get_form_data['MONTH'];
            // Remove leading zero from month if they have one:
            if (preg_match('/^0(.*)$/', $selectedMonth)) {
                $selectedMonth = ltrim($selectedMonth, "0");
            }

            $selectedDay = $get_form_data['DAY'];
            // Remove leading zero from day if they have one:
            if (preg_match('/^0(.*)$/', $selectedDay)) {
                $selectedDay = ltrim($selectedDay, "0");
            }

            if (!isset($stats_dir_array[$selectedYear][$selectedMonth][$selectedDay])) {
                // We don't have stats for the selected date!
                $failed_to_fetch = '1';
            }
            $defaultDate = $selectedYear . '-' . $selectedMonth . '-' . $selectedDay;
        }

        // Get Years:
        $STATS = $stats_dir_array;

        if (isset($STATS['actual'])) {
            unset($STATS['actual']);
        }

        $YEARS = array_keys($STATS);

        if (count($YEARS) == '0') {
            // We have no archived years!
            $oldestYear = $selectedYear;
            $oldestMonth = $selectedMonth;
            $oldestDay = $selectedDay;
        }
        else {
            $fYears = array_values($YEARS);
            asort($fYears);
            $oldestYear = array_shift($fYears);

            $fMonth = array_keys($STATS[$oldestYear]);
            asort($fMonth);
            $oldestMonth = array_shift($fMonth);

            $fDay = array_keys($STATS[$oldestYear][$oldestMonth]);
            asort($fDay);
            $oldestDay = array_shift($fDay);
        }

        $minDate = $oldestYear . '-' . $oldestMonth . '-' . $oldestDay;
        $maxDate = date("Y-m-d");

        $locale_info = initialize_languages(FALSE);
        $shortlocale = $locale_info['loc'];

        if ($BX_SESSION['gui_theme'] === 'adminica') {

            //
            //--- Old manual Adminica DatePicker:
            //

            $BxPage->setExtraHeaders(
                                '<link rel="stylesheet" type="text/css" href="/.adm/scripts/datepick/ui.datepick.css"> 
                                <script type="text/javascript" src="/.adm/scripts/datepick/jquery.plugin.js"></script>
                                <script type="text/javascript" src="/.adm/scripts/datepick/jquery.datepick.js?update"></script>
                                <script type="text/javascript" src="/.adm/scripts/datepick/jquery.datepick.ext.js"></script>
                                ');

            if ($shortlocale != 'en') {
                $BxPage->setExtraHeaders('<script type="text/javascript" src="/.adm/scripts/datepick/jquery.datepick-' . $shortlocale . '.js"></script>');
            }

            $BxPage->setExtraHeaders(
                                    "                                <script>
                                        $(function() {
                                            $('#inlineDatepicker').datepick({
                                                    showTrigger: '#calImg',
                                                    dateFormat: 'yyyy-mm-dd',
                                                    defaultDate: " . $defaultDate . ",
                                                    minDate: '" . $minDate . "',
                                                    maxDate: '" . $maxDate . "',
                                                    altField: '#dateSelected', altFormat: 'yyyy-mm-dd', 
                                                    onSelect: function(dates) { $('form').submit(); }
                                                });
                                        });
                                    </script>
                                    <style>
                                        .ui-datepicker-calendar { display: none;}
                                    </style>
                                    ");
        }

        $defaultPage = "basicSettingsTab";
        $block = $factory->getPagedBlock("GoAccess_header", array($defaultPage));

        $block->setToggle("#");

        if ($group == 'server') {
            // Only show the window opener for he live stats if we're in the server view:
            $block->setWindow('/base/sitestats/index.html');
        }

        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        if ($BX_SESSION['gui_theme'] === 'adminica') {

            // Out with the date-selectors:
            $datepicker = '<input type="hidden" value="" name="dateSelected" id="dateSelected" class="dateSelected"></input><div id="inlineDatepicker"></div>';
            $xxx = $factory->getRawHTML("datepicker", $datepicker);
            $block->addFormField(
                $xxx,
                $factory->getLabel("datepicker"),
                $defaultPage
            );
        }
        else {
            //
            //--- Use getDatePicker() UIFC2 element for Elmer:
            //

            $datepicker = '<input type="hidden" value="" name="dateSelected" id="dateSelected" class="dateSelected">';
            $xxx = $factory->getRawHTML("datepicker", $datepicker);
            $block->addFormField(
                $xxx,
                $factory->getLabel("datepicker"),
                $defaultPage
            );

            $t = strtotime($defaultDate);

            $DatePickerField = $factory->getDatePicker("datepicker", $t, "datetime", 'rw');
            $DatePickerField->setCurrentLabel($i18n->get('startDate'));
            $DatePickerField->setDescription($i18n->get('startDate_help'));
            $DatePickerField->setModus('days');
            $DatePickerField->setSubmit('dateSelected');

            $DatePickerField->setMinDate($minDate);
            $DatePickerField->setMaxDate($maxDate);

            $block->addFormField(
                $DatePickerField, 
                $factory->getLabel('startDate'),
                $defaultPage
            );
        }

        $myGroup = $factory->getTextField('group', $group, '');
        $block->addFormField(
                $myGroup,
                $factory->getLabel("group"),
                $defaultPage
                );

        $nowDate = date("Y-m-d");
        $checkDate = $selectedYear . '-' . $selectedMonth . '-' . $selectedDay;

        // If this request is for the server and we have no YEAR on the URL string OR the selected date is today?
        // Then show the live data:
        if (($group == 'server') && ((!isset($get_form_data['YEAR'])) || ($checkDate == $nowDate))) {

            $uri = '/base/sitestats/index.html';
            $block->setWindow($uri);

            $iframe = addIframe($uri, "3600", $BxPage);
            $xxx = $factory->getRawHTML("iframe", $iframe);
            $block->addFormField(
                $xxx,
                $factory->getLabel("iframe"),
                $defaultPage
            );
        }

        // The specified JSON file couldn't be found. Show empty hands:
        if ((!is_file($most_recent_stats)) || ($failed_to_fetch == '1')) {
            //
            //--- We don't have statistics yet!
            //

            $my_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-sitestats.no_stats_yet_text]]") . "</div>";
            $infotext = $factory->getHtmlField("info_text", $my_TEXT, 'r');
            $infotext->setLabelType("nolabel");
            $block->addFormField(
              $infotext,
              $factory->getLabel(" ", false),
              $defaultPage
            );

            $page_body[] = $block->toHtml();

        }
        else {

            // Show archived JSON file for the given date range:
            $uri = '/sitestats/goaccessview?group=' . $group . "&YEAR=$selectedYear&MONTH=$selectedMonth&DAY=$selectedDay";
            $block->setWindow($uri);
            $iframe = addIframe($uri, "3600", $BxPage);
            $xxx = $factory->getRawHTML("iframe", $iframe);
            $block->addFormField(
                $xxx,
                $factory->getLabel("iframe"),
                $defaultPage
            );


            $page_body[] = $block->toHtml();
        }

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