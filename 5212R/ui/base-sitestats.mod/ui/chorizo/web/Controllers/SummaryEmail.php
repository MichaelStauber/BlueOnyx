<?php 
namespace Sitestats\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class SummaryEmail extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }

    /**
     * Index Page for this controller.
     *
     * Past the login page this loads the page for /sitestats/summaryEmail.
     *
     */

    // This is mainly a parser and presenter for Sendmail Analyzer data files.
    //
    // For now it provides stats for the most common email parameters encountered 
    // on BlueOnyx. But it is missing some advanced and exotic stats that I have 
    // no data for at the present time. Such as for Amavis, Postgrey, j-ChkMail 
    // and others.
    //
    // All things considered this is the largest and most complex GUI page so far.
    //
    // Many thanks to Gilles Darold from http://sendmailanalyzer.darold.net/ 
    // for the splendid groundwork, coding examples and naturally for the 
    // underlying Sendmail Analyzer which we use for the generation of the 
    // statistics.
    //
    //
    // Gilles Darold: You're my kind of Perl-God. Mad props to you!

    private function setI18n($i18n) {
        $this->i18n = $i18n;
    }

    private function getI18n() {
        return $this->i18n;
    }

    private function setGroup($group) {
        $this->group = $group;
    }

    private function getGroup() {
        if (!isset($this->group)) {
            $this->group = 'server';
        }
        if ($this->group == '') {
            $this->group = 'server';
        }
        return $this->group;
    }

    private function setDomain($domain) {
        $this->domain = $domain;
    }

    private function getDomain() {
        if (!isset($this->domain)) {
            $this->domain = '';
        }
        return $this->domain;
    }

    private function setHour($hour) {
        $this->hour = $hour;
    }

    private function getHour() {
        if (!isset($this->hour)) {
            $this->setHour(date("H"));
        }
        return $this->hour;
    }

    private function setDay($day) {
        $this->day = $day;
    }

    private function getDay() {
        if (!isset($this->day)) {
            $this->day = date('d', strtotime("now"));
        }
        return $this->day;
    }

    private function setWeek($week) {
        $this->week = $week;
    }

    private function calcWeek($startDate) {
        $week = date('W', strtotime($startDate . 'T00:00:01'));
        return $week;
    }

    private function getWeek() {
        if (!isset($this->week)) {
            $this->week = date('W', strtotime("now"));
        }
        return $this->week;
    }
    private function setMonth($month) {
        $this->month = $month;
    }

    private function getMonth() {
        if (!isset($this->month)) {
            $this->month = date('m', strtotime("now"));
        }
        return $this->month;
    }

    private function setYear($year) {
        $this->year = $year;
    }

    private function getYear() {
        if (!isset($this->year)) {
            $this->year = date('Y', strtotime("now"));
        }
        return $this->year;
    }

    private function setStats($stats) {
        $this->stats = $stats;
    }

    private function getStats() {
        return $this->stats;
    }

    private function setPeriod($period) {
        $this->period = $period;
    }

    private function getPeriod() {
        return $this->period;
    }

    private function cleanup_stats(&$item='', &$key='') {
        if (is_array($item)) {
            array_filter($item);
        }
        if (is_array($key)) {
            array_filter($key);
        }
        if ((!preg_match('/^cache\.pm$/', $item)) && (!preg_match('/^(\d+)cache\.pm$/', $item))) {
            $item = "";
            unset($key);
        }
    }

    private function array_remove_empty($haystack) {
        foreach ($haystack as $key => $value) {
            if (is_array($value)) {
                $haystack[$key] = SummaryEmail::array_remove_empty($haystack[$key]);
            }
            if (empty($haystack[$key])) {
                unset($haystack[$key]);
            }
        }
        return $haystack;
    }

    private function is_month($calMonth) {
        $selectedMonth = $this->getMonth();
        $period = $this->getPeriod();
        $group = SummaryEmail::getGroup();
        $domain = SummaryEmail::getDomain();
        $month_locales = array(
                                    '01' => "01month_short",
                                    '02' => "02month_short",
                                    '03' => "03month_short",
                                    '04' => "04month_short",
                                    '05' => "05month_short",
                                    '06' => "06month_short",
                                    '07' => "07month_short",
                                    '08' => "08month_short",
                                    '09' => "09month_short",
                                    '10' => "10month_short",
                                    '11' => "11month_short",
                                    '12' => "12month_short"
                                    );
        $mnt = $month_locales[$calMonth];
        $STATS = $this->getStats();
        $haveYears = array_keys($STATS);
        if (!in_array($this->getYear(), $haveYears)) {
            // Someone tried to set an invalid year. Reset it to this year:
            if (isset($haveYears[0])) {
                $this->setYear($haveYears[0]);
            }
            else {
                $this->setYear(date("Y"));
                $out = '';
                return $out;
            }
        }

        $haveMonths = array_keys($STATS[$this->getYear()]);
        if (!in_array($selectedMonth, $haveMonths)) {
            // Someone tried to set a month for that we don't have stats
            // in the given year. Reset the date to todays month and year:
            $selectedMonth = date("m");
            $this->setMonth($selectedMonth);
            $this->setYear(date("Y"));
        }

        if ($domain == '') {
            $domain_parm = '';
        }
        else {
            $domain_parm = "&domain=$domain";
        }

        if (($calMonth == $selectedMonth) && ($period == "month")) {
            // Highlight selected month, but only make months links if we have stats for them:
            if ((in_array($this->getYear(), $haveYears)) && (in_array($calMonth, $haveMonths))) {
                $out = '<TH><a href="/sitestats/summaryEmail?group=' . $group . '&period=month&month=' . $calMonth  . '&year=' . $this->getYear() . $domain_parm . '">' .  $this->i18n->get("[[palette.$mnt]]") . '</a></TH>';
            }
            else {
                $out = '<TH>' .  $this->i18n->get("[[palette.$mnt]]") . '</TH>';
            }
        }
        else {
            // Only make months links if we have stats for them:
            if ((in_array($this->getYear(), $haveYears)) && (in_array($calMonth, $haveMonths))) {
                $out = '<TD style="padding: 5px;"><a href="/sitestats/summaryEmail?group=' . $group . '&period=month&month=' . $calMonth  . '&year=' . $this->getYear() . $domain_parm . '">' .  $this->i18n->get("[[palette.$mnt]]") . '</a></TD>';
            }
            else {
                $out = '<TD style="padding: 5px;">' .  $this->i18n->get("[[palette.$mnt]]") . '</TD>';
            }
        }
        return $out;
    }

    private function is_hour($calHour) {
        $selectedMonth = $this->getMonth();
        $selectedDay = $this->getDay();
        $selectedHour = $this->getHour();
        $period = $this->getPeriod();
        $group = $this->group;
        $domain = $this->domain;

        if ($domain == '') {
            $domain_parm = '';
        }
        else {
            $domain_parm = "&domain=$domain";
        }

        $STATS = $this->getStats();
        $haveYears = array_keys($STATS);

        if (!in_array($this->getYear(), $haveYears)) {
            $out = '';
            return $out;
        }

        if (!in_array($this->getYear(), $haveYears)) {
            // Someone tried to set an invalid year. Reset it to this year:
            $this->setYear(date("Y"));
        }
        $haveMonths = array_keys($STATS[$this->getYear()]);
        if (!in_array($selectedMonth, $haveMonths)) {
            // Someone tried to set a month for that we don't have stats
            // in the given year. Reset the date to todays month and year:
            $selectedMonth = date("m");
            $this->setMonth($selectedMonth);
            $this->setYear(date("Y"));
        }
        $tmpStats = $STATS;
        if (isset($tmpStats[$this->getYear()][$this->getMonth()]['summary'])) {
            unset($tmpStats[$this->getYear()][$this->getMonth()]['summary']);
        }

        if (isset($tmpStats[$this->getYear()][$this->getMonth()])) {
            $haveDays = array_keys($tmpStats[$this->getYear()][$this->getMonth()]);
        }
        else {
            $haveDays = array();
        }

        if (!in_array($selectedDay, $haveDays)) {
            // We don't have this day. Set to today:
            $this->setMonth(date("m"));
            $this->setYear(date("Y"));
            $this->setDay(date("d"));
        }

        // Now check if we have stats for the individual hours of this day (Yay, finally!):
        if (isset($tmpStats[$this->getYear()][$this->getMonth()][$this->getDay()])) {
            $haveHours = array_keys($tmpStats[$this->getYear()][$this->getMonth()][$this->getDay()]);
        }
        elseif (isset($tmpStats[$this->getYear()][$this->getMonth()][$this->getDay()-1])) {
            // No? How about from one hour ago?
            $haveHours = array_keys($tmpStats[$this->getYear()][$this->getMonth()][$this->getDay()-1]);
        }
        else {
            // Still nothing? Okay, I give up. Must be early in the morning. Like before 1 a.m.:
            $haveHours = array();
        }

        foreach ($haveHours as $key => $hour) {
            // Remove daily summary from the hours:
            if ($hour == "summary") {
                unset($haveHours[$key]);
            }
        }
        if (!in_array($selectedHour, $haveHours)) {
            // The last hour we have stats for is the last hour we present links for:
            //$selectedHour = array_shift(array_values(array_reverse($haveHours)));
            // What the hell was the above line meant to do? If we have no hour, we assume '00':
            $selectedHour = '00';
        }
        if (($period == "hour") && ($calHour == $selectedHour)) {
            // Highlight selected hour when we're in hourly mode and this hour is selected:
            $out = '<TH><a href="/sitestats/summaryEmail?group=' . $group . '&period=hour&month=' . $this->getMonth()  . '&year=' . $this->getYear() . '&day=' . $this->getDay() . '&hour=' . $calHour . $domain_parm . '">' .  $calHour . '</a></TH>';
        }
        else {
            // Only make Hour links if we have stats for the given hour:
            if ((in_array($this->getYear(), $haveYears)) && (in_array($selectedMonth, $haveMonths)) && (in_array($this->getDay(), $haveDays)) && (in_array($calHour, $haveHours))) {
                $out = '<TD style="padding: 5px;"><a href="/sitestats/summaryEmail?group=' . $group . '&period=hour&month=' . $this->getMonth()  . '&year=' . $this->getYear() . '&day=' . $this->getDay() . '&hour=' . $calHour . $domain_parm . '">' .  $calHour . '</a></TD>';
            }
            else {
                $out = '<TD style="padding: 5px;">' .  $calHour . '</TD>';
            }
        }
        return $out;
    }
  
    private function Nuller ($arr) {
        foreach ($arr as $key => $value) {
            if ($value === '') {
                $arr[$key] = 0;
            }
        }
        return $arr;
    }

    // Define a recursive function to clean up the array
    private function cleanup_array(&$array) {
        foreach ($array as $key => &$value) {
            // Trim trailing slashes from the key
            $key = rtrim($key, '/');
            
            if (is_array($value)) {
                SummaryEmail::cleanup_array($value);
                if (empty($value)) {
                    unset($array[$key]);
                }
            }
            else {
                if ((!preg_match('/^cache\.pm$/', $value)) && (!preg_match('/^(\d+)cache\.pm$/', $value))) {
                    $value = "";
                }
            }
        }

        // Remove keys with empty values
        $array = array_filter($array, function ($item) {
            return !empty($item);
        });
    }

    private function removeTrailingSlashRecursively(array $array) {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // Recursively process sub-arrays
                $result[SummaryEmail::removeTrailingSlash($key)] = SummaryEmail::removeTrailingSlashRecursively($value);
            } else {
                // Modify the key for non-array values
                $result[SummaryEmail::removeTrailingSlash($key)] = $value;
            }
        }
        return $result;
    }

    private function removeTrailingSlash($key) {
        return rtrim($key, '/');
    }

    /**
     * Index
     *
     * @return View
     */
    public function index() {

        $CI = get_instance();

        //helper('selector_helper');

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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-sitestats", "/sitestats/summaryEmail");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        SummaryEmail::setI18n($i18n);
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        // This is stupid. Datepicker looses the URI strings if we don't send some garbage to the output first. No idea why.
        if ($BX_SESSION['gui_theme'] === 'adminica') {
            print_r("&nbsp;");
        }

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- URL String parsing:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Actual page logic start:
        //

        // Load pagination library:
        //$this->load->library('pagination');

        $type = '';
        $group = '';
        SummaryEmail::setGroup('');
        $period = 'day';

        if (isset($get_form_data['type'])) {
            $type = formspecialchars($get_form_data['type']);
        }
        if (isset($get_form_data['group'])) {
            $group = formspecialchars($get_form_data['group']);
            SummaryEmail::setGroup($group);
        }
        if (isset($get_form_data['period'])) {
            $period = $get_form_data['period'];
        }

        if (isset($get_form_data['domain'])) {
            $domain = $get_form_data['domain'];
            SummaryEmail::setDomain($domain);
        }
        else {
            SummaryEmail::setDomain($System['hostname'] . '.' . $System['domainname']);
        }

        if (isset($domain)) {
            $domain_parm = "&domain=$domain";
        }
        else {
            $domain_parm = '';
        }

        $maxDate = date("Y-m-d");

        if ((!isset($group)) || ($group == '')) {
            $group = "server";
            SummaryEmail::setGroup($group);
        }
        if (!isset($type)) {
            $type = "mail";
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

        $is_month_view = TRUE;

        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            if ($BX_SESSION['gui_theme'] === 'adminica') { 

                //
                //--- Adminica DatePicker Processing:
                //

                if (isset($form_data['dateSelected'])) {
                    if ($form_data['dateSelected'] != "") {
                        $form_data['dateSelected'] = preg_replace('/\s+/', '', $form_data['dateSelected']);
                        $formdate = explode('-', $form_data['dateSelected']);
                        if (count($formdate) == '6') {
                            $from_date = array('Y' => $formdate[0], 'M' => $formdate[1], 'D' => $formdate[2]);
                            $to_date = array('Y' => $formdate[3], 'M' => $formdate[4], 'D' => $formdate[5]);
                            $this->setYear($formdate[0]);
                            $this->setMonth($formdate[1]);
                            $this->setDay($formdate[2]);
                            if ($from_date == $to_date) {
                                $period = "day";
                            }
                            else {
                                $period = "week";
                                $this->setWeek($this->calcWeek($from_date['Y']."-".$from_date['M']."-".$from_date['D']));
                            }
                        }
                    }
                }
                else {
                    // Safe fallback:
                    $period = "week";
                    $this->setWeek($this->calcWeek(date("Y")."-".date("m")."-".date("d")));
                }
            }
            else {

                //
                //--- Elmer DatePicker Processing:
                //

                if (isset($form_data['dateSelected'])) {
                    if ($form_data['dateSelected'] != "") {
                        $form_data['dateSelected'] = preg_replace('/\s+/', '', $form_data['dateSelected']);
                        $formdate = explode('-', $form_data['dateSelected']);

                        if (count($formdate) == '3') {
                            $this->setYear($formdate[0]);
                            $this->setMonth($formdate[1]);
                            $this->setDay($formdate[2]);
                        }
                    }
                }
                else {
                    // Safe fallback:
                    $period = "week";
                    $this->setWeek($this->calcWeek(date("Y")."-".date("m")."-".date("d")));
                }
            }
        }
        else {
            // No date realated POST data, so get date from URL string:
            if (isset($get_form_data['hour'])) {
                $this->setHour($get_form_data['hour']);
            }
            else {
                $this->setHour(date("H"));
            }
            if (isset($get_form_data['day'])) {
                $this->setDay($get_form_data['day']);
            }
            else {
                $this->setDay(date("d"));
            }
            if (isset($get_form_data['month'])) {
                $this->setMonth($get_form_data['month']);
            }
            else {
                $this->setMonth(date("m"));
            }
            if (isset($get_form_data['year'])) {
                $this->setYear($get_form_data['year']);
            }
            else {
                $this->setYear(date("Y"));
            }
        }

        // Store the period:
        $this->setPeriod($period);

        if (!isset($from_date)) {
            $from_date = array('Y' => $this->getYear(), 'M' => $this->getMonth(), 'D' => $this->getDay());
        }

        if (!isset($to_date)) {
            $to_date = array('Y' => $this->getYear(), 'M' => $this->getMonth(), 'D' => $this->getDay());
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        //
        //--- Get Items of Interest:
        //

        // Update SA Cache:
        //$ret = $CI->serverScriptHelper->shell("/usr/bin/sa_cache -a", $sareport, 'root', $BX_SESSION['sessionId']);

        // Location of the directory with statistics:
        $Stats_dir = '/home/.sendmailanalyzer';

        if (!is_dir($Stats_dir)) {
            // If we don't have stats we don't go any further and throw an error.
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        // Get a fileMap of the stats directory:
        helper('filesystem');
        $map = directory_map($Stats_dir, FALSE, FALSE);

        // Clean up the array
        SummaryEmail::cleanup_array($map);

        $map = SummaryEmail::removeTrailingSlashRecursively($map);

        // Find the name of the server stats directory:
        $firstlevel_keys = array_keys($map);
        $hn = $System['hostname'];
        foreach ($firstlevel_keys as $key => $value) {
            if (preg_match("/^$hn$/", $value)) {
                $server_statsDir = $System['hostname'];
            }
        }
        // OK, that yield a result. We do it the hard way then:
        if (!isset($server_statsDir)) {
            foreach ($map as $key => $value) {
                if (is_array($value)) {
                    $server_statsDir = $key;
                }
            }
        }

        // Cleanup topdir:
        foreach ($map as $key => $value) {
            if (!is_array($value)) {
                unset($map[$key]);
            }
        }

        // This function will remove all empty bits and pieces: (no longer necessary, already done further up)
        //$baremetalStats = SummaryEmail::array_remove_empty($map);
        $baremetalStats = $map;

        // Array setup:
        $STATS = array();
        $good_hours = array('00', '01', '02', '03', '04', '05', '06', '07', '08', '09',
                    '10', '11', '12', '13', '14', '15', '16', '17', '18', '19',
                    '20', '21', '22', '23');

        $Full_Month_Locales = array(
                                '01' => "01month",
                                '02' => "02month",
                                '03' => "03month",
                                '04' => "04month",
                                '05' => "05month",
                                '06' => "06month",
                                '07' => "07month",
                                '08' => "08month",
                                '09' => "09month",
                                '10' => "10month",
                                '11' => "11month",
                                '12' => "12month"
                            );

        // Do we have stats to display? If not, stop here:
        if (!isset($server_statsDir)) {

            // Prepare Page:
            $BxPage->setFormUrl("/sitestats/summaryEmail");
            $BxPage->setErrors($errors);

            $defaultPage = "basicSettingsTab";

            $block = $factory->getPagedBlock("mailusageDescription", array($defaultPage));
            $block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setShowAllTabs('#');
            $block->setDefaultPage($defaultPage);

            // Out with the message_delivery_flows_table:
            $no_data = $this->i18n->get("[[base-mailsitestats.sa_nodata]]");
            $xxx = $factory->getRawHTML("sa_nodata", '<p>' . $no_data . '</p>');
            $block->addFormField(
                $xxx,
                $factory->getLabel("sa_nodata"),
                $defaultPage
            );

            $page_body[] = $block->toHtml();

            // Set Menu items:
            if ($group == "server") {
                $BxPage->setVerticalMenu('base_serverusage');
                $page_module = 'base_sysmanage';
                $BxPage->setVerticalMenuChild('base_server_mailusage');
            }
            else {
                $BxPage->setVerticalMenu('base_siteusage');
                $BxPage->setVerticalMenuChild('base_webusage');
                $page_module = 'base_sitemanage';
                $BxPage->setVerticalMenuChild('base_mailusage');
            }
                        
            // Out with the page:
            return $BxPage->render($page_module, $page_body);
        }

        // Now map it out nicely:
        if (isset($baremetalStats[$server_statsDir])) {
            foreach ($baremetalStats[$server_statsDir] as $year => $y_value) {
                $STATS[$year] = array();
                foreach ($baremetalStats[$server_statsDir][$year] as $month => $m_value) {

                    if (!is_array($m_value)) {
                        if (preg_match('/^cache.pm$/', $m_value)) {
                            $STATS[$year]['summary'] = $m_value;
                        }
                    }
                    if (preg_match('/^weeks$/', $month)) {
                        $STATS[$year]['weeks'] = array();
                    }
                    elseif (is_array($m_value)) {
                        $STATS[$year][$month] = array();
                    }

                    if (is_array($m_value)) {
                        foreach ($baremetalStats[$server_statsDir][$year][$month] as $day => $d_value) {
                            if (!is_array($d_value)) {
                                // Month Summary:
                                if (preg_match('/^cache.pm$/', $d_value)) {
                                    $STATS[$year][$month]['summary'] = $d_value;
                                }
                            }
                            if ($month == 'weeks') {
                                $day_key = array_keys($d_value);
                                $STATS[$year]['weeks'][$day] = $d_value[$day_key['0']];
                                if (is_array($STATS[$year]['weeks'])) {
                                    ksort($STATS[$year]['weeks'], SORT_NUMERIC);
                                }
                            }
                            else {
                                // Day Summary:
                                if (is_array($d_value)) {
                                    foreach ($d_value as $dkey => $h_value) {
                                        // Create copy of $h_value:
                                        $full_h_value = $h_value;
                                        // Daily summary:
                                        if (preg_match('/^cache.pm$/', $h_value)) {
                                            $STATS[$year][$month][$day]['summary'] = $h_value;
                                        }
                                        else {
                                            // Hourly statistics for the given day:
                                            unset($h_matches);
                                            if (preg_match('/^(\d{2})cache\.pm$/', $full_h_value, $h_matches)) {
                                                if ((isset($h_matches[1])) && (in_array($h_matches[1], $good_hours))) {
                                                    $hour = $h_matches[1];
                                                    $STATS[$year][$month][$day][$hour] = $h_value;
                                                }
                                            }
                                        }
                                    }
                                    if (isset($STATS[$year][$month][$day])) {
                                        ksort($STATS[$year][$month][$day], SORT_NUMERIC);
                                    }
                                }
                            }
                        }
                        if (is_array($STATS[$year][$month])) {
                            ksort($STATS[$year][$month], SORT_NUMERIC);
                        }
                    }
                    if (is_array($STATS)) {
                        ksort($STATS, SORT_NUMERIC);
                    }
                }
            }
        }
        else {
            // If we don't have stats we don't go any further and throw an error.
            // We can get at this point if the server is freshly set up and sa_cache
            // hasn't finished its initial run. In that case we just throw a 403.
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        // Find out which years we have:
        $YEARS = array_keys($STATS);
        $this->setStats($STATS);

        // Get the oldest date that we have stats for.
        $tmpStats = $STATS;
        $fYears = array_values($YEARS);
        $oldestYear = array_shift($fYears);

        foreach ($tmpStats[$oldestYear] as $key => $value) {
            if ($key == 'summary') {
                unset($tmpStats[$oldestYear][$key]);
            }
        }
        
        if (isset($tmpStats[$oldestYear]['weeks'])) {
            unset($tmpStats[$oldestYear]['weeks']);
        }
        
        $fMonth = array_keys($tmpStats[$oldestYear]);
        $oldestMonth = array_shift($fMonth);

        if (isset($tmpStats[$oldestYear][$oldestMonth]))  {
            if (is_array($tmpStats[$oldestYear][$oldestMonth])) {
                foreach ($tmpStats[$oldestYear][$oldestMonth] as $key => $value) {
                    if ($key == 'summary') {
                        unset($tmpStats[$oldestYear][$oldestMonth][$key]);
                    }
                }
            }
        }
        $fDay = array_keys($tmpStats[$oldestYear][$oldestMonth]);
        $oldestDay = array_shift($fDay);
        if (!$oldestDay) {
            $oldestDay = 1;
        }

        unset($tmpStats);
        $minDate = $oldestYear . '-' . $oldestMonth . '-' . $oldestDay;

        // Construct the URL parameters based on the currently selected date and group:
        if ($period == "day") {
            $formTargetUrl = "/sitestats/summaryEmail?group=$group&period=$period&year=" . $this->getYear() . "&month=" . $this->getMonth() . "&day=" . $this->getDay();
        }
        elseif ($period == "week") {
            $formTargetUrl = "/sitestats/summaryEmail?group=$group&period=$period&year=" . $this->getYear() . "&week=" . $this->getWeek();
        }
        elseif ($period == "month") {
            $formTargetUrl = "/sitestats/summaryEmail?group=$group&period=$period&year=" . $this->getYear() . "&month=" . $this->getMonth();
        }
        elseif ($period == "year") {
            $formTargetUrl = "/sitestats/summaryEmail?group=$group&period=$period&year=" . $this->getYear();
        }
        else {
            // Default back to current day:
            $formTargetUrl = "/sitestats/summaryEmail?group=$group&period=$period&year=" . $this->getYear() . "&month=" . $this->getMonth() . "&day=" . $this->getDay();
        }

        // Append the domain if one is specified:
        $mainFormTargetUrl = $formTargetUrl . $domain_parm;

        // Prepare Page:
        $BxPage->setFormUrl($mainFormTargetUrl);
        $BxPage->setErrors($errors);

        $defaultPage = "basicSettingsTab";

        //
        //--- Configure $type Reporting Options:
        //

        $block = $factory->getPagedBlock("generateSettings", array($defaultPage));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $typestring = $i18n->interpolate("[[base-sitestats." . $type . "usage]]");
        $i18nvars['type'] = $typestring;
        $mailAliases = array();

        if (isset($group) && $group != 'server') {
            $vsite = $CI->cceClient->find('Vsite', array('name' => $group));
            if (isset($vsite[0])) {
                $vsiteObj = $CI->cceClient->get($vsite[0]);
                $mailAliases = scalar_to_array($vsiteObj['mailAliases']);
            }
            else {
                // Vsite Object doesn't exist.
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }
        }
        $dsp_mnt = $Full_Month_Locales[$this->getMonth()];
        if ($period == "month") {
            $display_period = $this->i18n->get("[[palette.$dsp_mnt]]") . " " . $this->getYear();
        }
        elseif ($period == "hour") {
            $dsp_mnt = $Full_Month_Locales[$this->getMonth()];
            $hourNext = $this->getHour()+1;
            if ($hourNext > '24') {
                $hourNext = "01";
            }
            $display_period = $this->getDay() . ". " . $this->i18n->get("[[palette.$dsp_mnt]]") . " " . $this->getYear() . " -  " . $this->getHour() . ':00-' . $hourNext . ':00';
        }
        elseif ($period == "week") {
            $dsp_mnt = $Full_Month_Locales[$this->getMonth()];
            $dsp_mnt_to = $Full_Month_Locales[$to_date['M']];
            $display_period = $this->getDay() . ". " . $this->i18n->get("[[palette.$dsp_mnt]]") . " " . $this->getYear() . " - " . $to_date['D'] . ". " . $this->i18n->get("[[palette.$dsp_mnt_to]]") . " " . $to_date['Y'];
        }
        elseif ($period == "year") {
            $display_period = $this->getYear();
        }
        else {
            // Default: day
            $dsp_mnt = $Full_Month_Locales[$this->getMonth()];
            $display_period = $this->getDay() . ". " . $this->i18n->get("[[palette.$dsp_mnt]]") . " " . $this->getYear();
        }

        if (isset($domain)) {
            $lbl_prefix = $domain . ': ';
        }
        else {
            $lbl_prefix = '';
        }
        
        $block->setLabel($factory->getLabel($lbl_prefix . $this->i18n->get("[[base-mailsitestats.sa_stats_label]]") . " $display_period"));

        // Set Menu items:
        if ($group == "server") {
            $BxPage->setVerticalMenu('base_serverusage');
            $page_module = 'base_sysmanage';
            $BxPage->setVerticalMenuChild('base_server_mailusage');
        }
        else {
            $BxPage->setVerticalMenu('base_siteusage');
            $BxPage->setVerticalMenuChild('base_webusage');
            $page_module = 'base_sitemanage';
            $BxPage->setVerticalMenuChild('base_mailusage');
        }

        // Paginate Years:
        $Ypages = '';
        $numYears = count($YEARS);
        $tmpYears = array_reverse($YEARS);
        // Show 3 years max per pagination:
        $pages = array_chunk($tmpYears, 3);
        $i = 0;
        $foundkey = 0;
        foreach ($pages as $key => $pyear) {
            if (in_array($this->getYear(), $pyear)) {
                $pyear = array_reverse($pyear);
                $foundkey = $key;
                foreach ($pyear as $key => $actualYear) {
                    if (($this->getYear() == $actualYear) && (($period == "year") || ($period == "month") || ($period == "week"))) {
                        $Ypages .= '<b><a href="/sitestats/summaryEmail?group=' . $group . '&year=' . $actualYear  . '&period=year' . $domain_parm . '">' .  $actualYear . '</a></b>&nbsp;';
                    }
                    else {
                        $Ypages .= '<a href="/sitestats/summaryEmail?group=' . $group . '&year=' . $actualYear  . '&period=year' . $domain_parm . '">' .  $actualYear . '</a>&nbsp;';
                    }
                }
            }
            $i++;
        }

        // Add << - >> to thumb through pages:
        if (isset($pages[$foundkey+1])) {
            $uFlowYears = array_values($pages[$foundkey+1]);
            $Ypages = '<a href="/sitestats/summaryEmail?group=' . $group . '&period=year&year=' . $uFlowYears[0] . $domain_parm . '">&lt;&lt;</a>&nbsp;' . $Ypages;
        }
        if (isset($pages[$foundkey-1])) {
            $oFlowYears = array_reverse(array_values($pages[$foundkey-1]));
            $Ypages .= "&nbsp;" . '<a href="/sitestats/summaryEmail?group=' . $group . '&period=year&year=' . $oFlowYears[0] . $domain_parm . '">&gt;&gt;</a>';
        }

        if ($BX_SESSION['gui_theme'] === 'adminica') {

            //
            //--- Old manual Adminica DatePicker:
            //

            // Explanation: If you run datepicker or datepick on a DIV and not a formfield, then you get
            // no form data back on submit. Hence we use datepick's "altField" to populate the chosen date
            // range into the hidden formfield "dateSelected" below. And can you believe that I needed two
            // fucking days to figure this out and solve it? Incredible. Oh, and the $datepicker variable 
            // has to be populated before we set the extra-headers below. Because in SummaryEmail::is_month()
            // we have a check that resets the date to todays date if someone selects a month and year for
            // which we have no statistics.

            $datepicker = '
                <div class="columns clearfix">
                    <input type="hidden" value="" name="dateSelected" id="dateSelected" class="dateSelected"></input>
                    <div class="col_50">
                        <fieldset class="label_top label_small top bottom">
                            <label>' . $this->i18n->get("[[base-mailsitestats.daily_weekly_summary]]") . '</label>
                            <div class="multiShowPicker">
                                <div id="multiShowPicker"></div>
                            </div>
                        </fieldset>
                    </div>

                    <div class="col_50">';

            $locale_info = initialize_languages(FALSE);
            $shortlocale = $locale_info['loc'];

            $BxPage->setExtraHeaders(
                                '<link rel="stylesheet" type="text/css" href="/.adm/scripts/datepick/ui.datepick.css"> 
                                <script type="text/javascript" src="/.adm/scripts/datepick/jquery.plugin.js"></script>
                                <script type="text/javascript" src="/.adm/scripts/datepick/jquery.datepick.js?update"></script>
                                <script type="text/javascript" src="/.adm/scripts/datepick/jquery.datepick.ext.js"></script>
                                ');

            if ($shortlocale != 'en') {
                $BxPage->setExtraHeaders('<script type="text/javascript" src="/.adm/scripts/datepick/jquery.datepick-' . $shortlocale . '.js"></script>');
            }

            $BxPage->setExtraHeaders("                                <script>
                                        $(function() {
                                            $('#multiShowPicker').datepick({
                                                renderer: $.datepick.weekOfYearRenderer,
                                                firstDay: 1, showOtherMonths: true, rangeSelect: true, 
                                                onShow: $.datepick.multipleEvents($.datepick.selectWeek, $.datepick.showStatus), 
                                                showTrigger: '#calImg',
                                                dateFormat: 'yyyy-mm-dd',
                                                defaultDate: '" . $this->getYear() . "-" . $this->getMonth() . "-" . $this->getDay() . "',
                                                minDate: '" . $minDate . "',
                                                maxDate: '" . $maxDate . "',
                                                altField: '#dateSelected', altFormat: 'yyyy-mm-dd',
                                                onSelect: function(dates) { $('form').submit(); }
                                            });
                                        });
                                    </script>
                                    <style>
                                    .ui-datepicker-calendar {
                                        display: none;
                                        }
                                    </style>
                                    ");
        }
        else {

            //
            //--- Use getDatePicker() UIFC2 element for Elmer:
            //

            $datepicker = '<input type="hidden" value="" name="dateSelected" id="dateSelected" class="dateSelected">';

            $t = strtotime($this->getYear() . "-" . $this->getMonth() . "-" . $this->getDay());

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

        if ($BX_SESSION['gui_theme'] === 'elmer') {

            //
            //--- Elmer
            //

            // Manually construct Label with Tooltip:
            $hourly_summary_label = $factory->getLabel($this->i18n->get("[[base-mailsitestats.hours]]"));
            $hourly_summary_label->setLabel($this->i18n->get("[[base-mailsitestats.hours]]"));
            $hourly_summary_label->setDescription($this->i18n->get("[[base-mailsitestats.hours]]"));
            $hourly_summary_label->setStyleTarget("labelLabel");
            $BxPage->setLabel('yearly_summary', $this->i18n->get("[[base-mailsitestats.hours]]"), $this->i18n->get("[[base-mailsitestats.hours]]"));

            $hourly_summary_label_html = $hourly_summary_label->toHtml() . "\n";

            SummaryEmail::setGroup($group);
            if (($period == 'day') || ($period == 'hour')) {
                $datepicker .= '
                                <div class="clearfix pt-10">
                                    <TABLE class="calborder pb-10">
                                        <TR><TH colspan="12" align="center">' . $hourly_summary_label_html . '</TH></TR>
                                        <TR align=center>' . 
                                            SummaryEmail::is_hour("00") . SummaryEmail::is_hour("01") . SummaryEmail::is_hour("02") . SummaryEmail::is_hour("03") . 
                                            SummaryEmail::is_hour("04") . SummaryEmail::is_hour("05") . SummaryEmail::is_hour("06") . SummaryEmail::is_hour("07") . 
                                            SummaryEmail::is_hour("08") . SummaryEmail::is_hour("09") . SummaryEmail::is_hour("10") . SummaryEmail::is_hour("11") . '</TR>' .
                                        '<TR align=center>' . 
                                            SummaryEmail::is_hour("12") . SummaryEmail::is_hour("13") . SummaryEmail::is_hour("14") . SummaryEmail::is_hour("15") . 
                                            SummaryEmail::is_hour("16") . SummaryEmail::is_hour("17") . SummaryEmail::is_hour("18") . SummaryEmail::is_hour("19") . 
                                            SummaryEmail::is_hour("20") . SummaryEmail::is_hour("21") . SummaryEmail::is_hour("22") . SummaryEmail::is_hour("23") . 
                                        '</TR>
                                    </TABLE>
                                </div>';
            }
        }
        else {

            //
            //--- Adminica
            //

            SummaryEmail::setGroup($group);
            if (($period == 'day') || ($period == 'hour')) {
                $datepicker .= '
                            <fieldset class="label_top top right no_lines">
                                <label>' . $this->i18n->get("[[base-mailsitestats.hourly_summary]]") . '</label>
                                <div class="clearfix">
                                    <TABLE class="calborder">
                                        <TR><TH colspan="12" align="center"><H2 class="box_head">' . $this->i18n->get("[[base-mailsitestats.hours]]") . '</H2></TH></TR>
                                        <TR align=center>' . 
                                            SummaryEmail::is_hour("00") . SummaryEmail::is_hour("01") . SummaryEmail::is_hour("02") . SummaryEmail::is_hour("03") . 
                                            SummaryEmail::is_hour("04") . SummaryEmail::is_hour("05") . SummaryEmail::is_hour("06") . SummaryEmail::is_hour("07") . 
                                            SummaryEmail::is_hour("08") . SummaryEmail::is_hour("09") . SummaryEmail::is_hour("10") . SummaryEmail::is_hour("11") . '</TR>' .
                                        '<TR align=center>' . 
                                            SummaryEmail::is_hour("12") . SummaryEmail::is_hour("13") . SummaryEmail::is_hour("14") . SummaryEmail::is_hour("15") . 
                                            SummaryEmail::is_hour("16") . SummaryEmail::is_hour("17") . SummaryEmail::is_hour("18") . SummaryEmail::is_hour("19") . 
                                            SummaryEmail::is_hour("20") . SummaryEmail::is_hour("21") . SummaryEmail::is_hour("22") . SummaryEmail::is_hour("23") . 
                                        '</TR>
                                    </TABLE>
                                </div>
                            </fieldset>';
            }

        }

        if ($BX_SESSION['gui_theme'] === 'elmer') {

            //
            //--- Elmer
            //

            // Manually construct Label with Tooltip:
            $yearly_summary_label = $factory->getLabel($this->i18n->get("[[base-mailsitestats.yearly_summary]]"));
            $yearly_summary_label->setLabel($this->i18n->get("[[base-mailsitestats.yearly_summary]]"));
            $yearly_summary_label->setDescription($this->i18n->get("[[base-mailsitestats.yearly_summary]]"));
            $yearly_summary_label->setStyleTarget("labelLabel");
            $BxPage->setLabel('yearly_summary', $this->i18n->get("[[base-mailsitestats.yearly_summary]]"), $this->i18n->get("[[base-mailsitestats.yearly_summary]]"));

            $datepicker .= '<div class="pt-10">' . $yearly_summary_label->toHtml() . "</div>\n";
        }
        else {

            //
            //--- Adminica
            //

            $yearly_summary_label = $factory->getLabel($this->i18n->get("[[base-mailsitestats.yearly_summary]]"));
            $yearly_summary_label->setLabel($this->i18n->get("[[base-mailsitestats.yearly_summary]]"));
            $yearly_summary_label->setDescription($this->i18n->get("[[base-mailsitestats.yearly_summary]]"));
            $yearly_summary_label->setStyleTarget("labelLabel");

            $datepicker .= $yearly_summary_label->toHtml() . '
                    <fieldset class="label_top top right no_lines">
                        <label>' . $this->i18n->get("[[base-mailsitestats.yearly_summary]]") . '</label>' . "\n";

        }

        $datepicker .= '
                        <div class="clearfix"> 
                            ' . $Ypages . '
                        </div>' . "\n";

        if ($BX_SESSION['gui_theme'] === 'adminica') {
            $datepicker .= '
                    </fieldset>
                    <fieldset class="label_top top right no_lines">
                        <label>' . $this->i18n->get("[[base-mailsitestats.monthly_summary]]") . '</label>' . "\n";

                        $month_header = '<H2 class="box_head">' . $this->i18n->get("[[base-mailsitestats.months]]") . '</H2>';
        }
        else {
            //
            //--- Elmer
            //

            // Manually construct Label with Tooltip:
            $monthly_summary_label = $factory->getLabel($this->i18n->get("[[base-mailsitestats.months]]"));
            $monthly_summary_label->setLabel($this->i18n->get("[[base-mailsitestats.months]]"));
            $monthly_summary_label->setDescription($this->i18n->get("[[base-mailsitestats.months]]"));
            $monthly_summary_label->setStyleTarget("labelLabel");
            $BxPage->setLabel('yearly_summary', $this->i18n->get("[[base-mailsitestats.months]]"), $this->i18n->get("[[base-mailsitestats.months]]"));

            $month_header = $monthly_summary_label->toHtml() . "\n";
        }

        $datepicker .= '
                        <div class="clearfix pt-10">
                            <TABLE class="calborder">
                                <TR><TH colspan="4" align="center">' . $month_header . '</TH></TR>
                                <TR align=center>' . SummaryEmail::is_month("01") . SummaryEmail::is_month("02") . SummaryEmail::is_month("03") . SummaryEmail::is_month("04") . '</TR>
                                <TR align=center>' . SummaryEmail::is_month("05") . SummaryEmail::is_month("06") . SummaryEmail::is_month("07") . SummaryEmail::is_month("08") . '</TR>
                                <TR align=center>' . SummaryEmail::is_month("09") . SummaryEmail::is_month("10") . SummaryEmail::is_month("11") . SummaryEmail::is_month("12") . '</TR>
                            </TABLE>
                        </div>';
        if ($BX_SESSION['gui_theme'] === 'adminica') {
            $datepicker .= '
                    </fieldset>     
                </div>
            ';
        }

        // Out with the date-selectors:
        $xxx = $factory->getRawHTML("datepicker", $datepicker);
        $block->addFormField(
            $xxx,
            $factory->getLabel("datepicker"),
            $defaultPage
        );

        // Add the mailAliases selector if we're not in 'server' mode:
        if ($group != 'server') {
            $num = '0';
            foreach ($mailAliases as $key => $alias) {
                $mailAliasesList[$alias] =  $formTargetUrl . '&domain=' . $alias;
                $aliasIndex[$alias] = $num;
                $num++;
            }
            $addButton = $factory->getMultiButton("mailAliases",
                          array_values($mailAliasesList),
                          array_keys($mailAliasesList));

            $xxx = $factory->getRawHTML("filler", "&nbsp;");
            $block->addFormField(
                $xxx,
                $factory->getLabel(" "),
                $defaultPage
            );

            if ((isset($domain)) && ($domain != "") && (in_array($domain, $mailAliases))) {
                $addButton->setSelectedIndex($aliasIndex[$domain]);
            }
            else {
                // No domain set via URL param. Pick the first element from the array $mailAliases instead:
                $domain = array_shift($mailAliases);
                if ($domain != "") {
                    SummaryEmail::setDomain($domain);
                    $addButton->setSelectedIndex($aliasIndex[$domain]);
                    $addButton->setText($this->i18n->get("[[base-mailsitestats.select_an_alias]]"));
                    $block->addFormField(
                        $addButton,
                        $factory->getLabel(" "),
                        $defaultPage
                    );
                }
                else {
                    // If we get here, the Vsite doesn't have an email server alias set.
                    // To not present the whole server statistics or an empty pulldown,
                    // we hardwire the domain to the fqdn of the Vsite instead:
                    $this->setDomain($vsiteObj['fqdn']);
                    $domain = $vsiteObj['fqdn'];
                }
            }
        }

        // Parameters to pass to /var/lib/sendmailanalyzer/sa_to_php.pl:
        //
        //  $hn                     = hostname
        //  $this->getYear()        = current year
        //  $this->getWeek()        = current week  (optional)
        //  $this->getMonth()       = current month (optional)
        //  $this->getDay()         = current day   (optional)
        //  $this->getHour()        = current hour  (optional)
        //  $domain                 = Domain name (all in upper case!) for a Vsite

        $sa_params = "--host " . $hn;
        //sol --year=2014 --month=05 --day=22 --domain=SOLARSPEED.NET

        if ($period == "year") {
            $sa_params .= " --year=" . $this->getYear();
        }
        elseif ($period == "week") {
            $sa_params .= " --year=" . $this->getYear() . " --week=" . $this->getWeek();
        }
        elseif ($period == "month") {
            $sa_params .= " --year=" . $this->getYear() . " --month=" . $this->getMonth();
        }
        elseif ($period == "day") {
            $sa_params .= " --year=" . $this->getYear() . " --month=" . $this->getMonth() . " --day=" . $this->getDay();
        }
        elseif ($period == "hour") {
            $sa_params .= " --year=" . $this->getYear() . " --month=" . $this->getMonth() . " --day=" . $this->getDay() . " --hour=" . $this->getHour();
        }
        else {
            // Default to day:
            $sa_params .= " --year=" . $this->getYear() . " --month=" . $this->getMonth() . " --day=" . $this->getDay();
        }

        $Global_Default = $this->i18n->get("[[base-mailsitestats.global_cat_messaging]]");
        $Global_Spamming = $this->i18n->get("[[base-mailsitestats.global_cat_spamming]]");
        $Global_Virus = $this->i18n->get("[[base-mailsitestats.global_cat_virus]]");
        $Global_Notification = $this->i18n->get("[[base-mailsitestats.global_cat_notification]]");
        $Global_Rejections = $this->i18n->get("[[base-mailsitestats.global_cat_rejection]]");
        $Global_Status = $this->i18n->get("[[base-mailsitestats.global_cat_status]]");
        $Global_SMTPAuth = $this->i18n->get("[[base-mailsitestats.global_cat_smtpauth]]");
        
        $Top_Senders = $this->i18n->get("[[base-mailsitestats.top_cat_senders]]");
        $Top_Recipients = $this->i18n->get("[[base-mailsitestats.top_cat_recipients]]");
        $Top_Spamming = $this->i18n->get("[[base-mailsitestats.top_cat_spamming]]");
        $Top_Virus = $this->i18n->get("[[base-mailsitestats.top_cat_virus]]");
        $Top_Notification = $this->i18n->get("[[base-mailsitestats.top_cat_notification]]");
        $Top_Rejection = $this->i18n->get("[[base-mailsitestats.top_cat_rejection]]");
        $Top_SMTPAuth = $this->i18n->get("[[base-mailsitestats.top_cat_smtpauth]]");

        $AV_SPAMdMilter = $this->i18n->get("[[base-mailsitestats.av_spamassassin]]");

        $no_data = $this->i18n->get("[[base-mailsitestats.sa_nodata]]");

        // Handle stats for individual domains or the whole server:
        $SAR = array();
        if (($group != 'server') && (isset($domain))) {
            // Individual domain:
            $domain = strtoupper($domain);
            $sa_params .= ' --domain=' . $domain;
            $output = '';
            $ret = $CI->serverScriptHelper->shell("/var/lib/sendmailanalyzer/sa_to_php.pl $sa_params", $output, 'root', $BX_SESSION['sessionId']);
            if (!empty($output)) {
                $statsObject = json_decode($output); // Returns Object
                $SAR = json_decode(json_encode($statsObject), true); // Returns and Array instead
            }
            else {
                $SAR = array();
            }
        }
        else {
            // Whole server:
            $ret = $CI->serverScriptHelper->shell("/var/lib/sendmailanalyzer/sa_to_php.pl $sa_params", $output, 'root', $BX_SESSION['sessionId']);
            if (!empty($output)) {
                $statsObject = json_decode($output); // Returns Object
                $SAR = json_decode(json_encode($statsObject), true); // Returns and Array instead
            }
            else {
                $SAR = array();
            }
        }

        $dummyGLOBAL_STATUS['Please try again later_bytes'] = '0';
        $dummyGLOBAL_STATUS['User unknown'] = '0';
        $dummyGLOBAL_STATUS['Blocked'] = '0';
        $dummyGLOBAL_STATUS['SysErr'] = '0';
        $dummyGLOBAL_STATUS['No such user here_bytes'] = '0';
        $dummyGLOBAL_STATUS['User unknown_bytes'] = '0';
        $dummyGLOBAL_STATUS['Spam'] = '0';
        $dummyGLOBAL_STATUS['No such user here'] = '0';
        $dummyGLOBAL_STATUS['Blocked_bytes'] = '0';
        $dummyGLOBAL_STATUS['Can\'t create output_bytes'] = '0';
        $dummyGLOBAL_STATUS['Spam_bytes'] = '0';
        $dummyGLOBAL_STATUS['Sent'] = '0';
        $dummyGLOBAL_STATUS['Deferred_bytes'] = '0';
        $dummyGLOBAL_STATUS['Deferred'] = '0';
        $dummyGLOBAL_STATUS['Rejected'] = '0';
        $dummyGLOBAL_STATUS['SysErr_bytes'] = '0';
        $dummyGLOBAL_STATUS['Rejected_bytes'] = '0';
        $dummyGLOBAL_STATUS['Can\'t create output'] = '0';
        $dummyGLOBAL_STATUS['Sent_bytes'] = '0';
        $dummyGLOBAL_STATUS['Please try again later'] = '0';

        if (isset($SAR['GLOBAL_STATUS'])) {
            // Make sure our imported GLOBAL_STATUS has the basic defaults. If not,
            // then set them from the above $dummyGLOBAL_STATUS array:
            foreach ($dummyGLOBAL_STATUS as $key => $value) {
                if (!isset($SAR['GLOBAL_STATUS'][$key])) {
                    $SAR['GLOBAL_STATUS'][$key] = $value;
                }
            }

            //
            //-- Statistic Output:
            //

            $statsTabs = array($Global_Default, $Global_Spamming, $Global_Virus, $Global_Notification, $Global_Rejections, $Global_Status, $Global_SMTPAuth, $Top_Senders, $Top_Recipients, $Top_Spamming, $Top_Virus, $Top_Notification, $Top_Rejection, $Top_SMTPAuth, $AV_SPAMdMilter);

            $statsBlock = $factory->getPagedBlock("mailusageDescription", $statsTabs);
            $statsBlock->setFormDisabled(TRUE);
            $statsBlock->setToggle("#");
            $statsBlock->setSideTabs(TRUE);
            $statsBlock->setDivHeight('600');
            $statsBlock->setDefaultPage($Global_Default);

            //@//
            //@//-- Messaging Tab:
            //@//

            //
            //-- Global Defaults:
            //

            $SAR['messaging']['inbound_mean']           = Meaner($SAR['messaging']['inbound_bytes'], $SAR['messaging']['inbound']);
            $SAR['messaging']['local_inbound_mean']     = Meaner($SAR['messaging']['local_inbound_bytes'], $SAR['messaging']['local_inbound']);
            $SAR['messaging']['total_inbound_mean']     = Meaner($SAR['messaging']['total_inbound_bytes'], $SAR['messaging']['total_inbound']);
            $SAR['messaging']['outbound_mean']          = Meaner($SAR['messaging']['outbound_bytes'], $SAR['messaging']['outbound']);
            $SAR['messaging']['local_outbound_mean']    = Meaner($SAR['messaging']['local_outbound_bytes'], $SAR['messaging']['local_outbound']);
            $SAR['messaging']['total_outbound_mean']    = Meaner($SAR['messaging']['total_outbound_bytes'], $SAR['messaging']['total_outbound']);

            //
            //-- Messaging flows table:
            //

            $mft_items_of_interest = array(
                'inbound', 'inbound_bytes', 'inbound_mean',
                'local_inbound', 'local_inbound_bytes', 'local_inbound_mean',
                'total_inbound', 'total_inbound_bytes', 'total_inbound_mean',
                'outbound', 'outbound_bytes', 'total_inbound_mean',
                'local_outbound', 'local_outbound_bytes', 'local_outbound_mean',
                'total_outbound', 'total_outbound_bytes', 'total_outbound_mean',
            );

            $mf_table = array();

            $mf_table[0][0] = $this->i18n->get("[[base-mailsitestats.incoming]]");
            $mf_table[1][0] = $SAR['messaging']['inbound'];
            $mf_table[2][0] = $SAR['messaging']['inbound_bytes'];
            $mf_table[3][0] = $SAR['messaging']['inbound_mean'];

            $mf_table[0][1] = $this->i18n->get("[[base-mailsitestats.local_incomming]]");
            $mf_table[1][1] = $SAR['messaging']['local_inbound'];
            $mf_table[2][1] = $SAR['messaging']['local_inbound_bytes'];
            $mf_table[3][1] = $SAR['messaging']['local_inbound_mean'];

            $mf_table[0][2] = $this->i18n->get("[[base-mailsitestats.total_incomming]]");
            $mf_table[1][2] = $SAR['messaging']['total_inbound'];
            $mf_table[2][2] = $SAR['messaging']['total_inbound_bytes'];
            $mf_table[3][2] = $SAR['messaging']['total_inbound_mean'];

            $mf_table[0][3] = $this->i18n->get("[[base-mailsitestats.outgoing]]");
            $mf_table[1][3] = $SAR['messaging']['outbound'];
            $mf_table[2][3] = $SAR['messaging']['outbound_bytes'];
            $mf_table[3][3] = $SAR['messaging']['outbound_mean'];

            $mf_table[0][4] = $this->i18n->get("[[base-mailsitestats.local_delivery]]");
            $mf_table[1][4] = $SAR['messaging']['local_outbound'];
            $mf_table[2][4] = $SAR['messaging']['local_outbound_bytes'];
            $mf_table[3][4] = $SAR['messaging']['local_outbound_mean'];

            $mf_table[0][5] = $this->i18n->get("[[base-mailsitestats.total_outgoing]]");
            $mf_table[1][5] = $SAR['messaging']['total_outbound'];
            $mf_table[2][5] = $SAR['messaging']['total_outbound_bytes'];
            $mf_table[3][5] = $SAR['messaging']['total_outbound_mean'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $mean = $this->i18n->get("[[base-mailsitestats.mean]]");

            $messaging_flows_table = $factory->getScrollList("messaging_flows_table", array("", $messages, $size, $mean), $mf_table); 
            $messaging_flows_table->setAlignments(array("left", "left", "left", "left"));
            $messaging_flows_table->setDefaultSortedIndex('0');
            $messaging_flows_table->setSortOrder('ascending');
            //$messaging_flows_table->setSortDisabled(array('1'));
            $messaging_flows_table->setPaginateDisabled(TRUE);
            $messaging_flows_table->setSearchDisabled(TRUE);
            $messaging_flows_table->setSelectorDisabled(FALSE);
            $messaging_flows_table->enableAutoWidth(FALSE);
            $messaging_flows_table->setInfoDisabled(TRUE);
            $messaging_flows_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
            $messaging_flows_table->setCurrentLabel("[[base-mailsitestats.messaging_flows]]");
            $messaging_flows_table->setDescription("[[base-mailsitestats.messaging_flows]]");

            // Out with the messaging_flows_table:
            $messaging_flows_table_out = $factory->getRawHTML("messaging_flows_table", $messaging_flows_table->toHtml());
            $statsBlock->addFormField(
                $messaging_flows_table_out,
                $factory->getLabel("messaging_flows_table"),
                $Global_Default
            );

            //
            //-- Graph for 'Messaging Flow':
            //

            // Items of interest:
            $mfg_inbound = explode(":", $SAR['messaging']['values']);
            $mfg_outbound = explode(":", $SAR['messaging']['values1']);
            $messaging_flow_seenTimes = explode(":", $SAR['messaging']['lbls']);

            foreach ($mfg_inbound as $key => $value) {
                $messaging_flow_Data[$this->i18n->get("[[base-mailsitestats.inbound]]")][$key] = $value;
            }

            foreach ($mfg_outbound as $key => $value) {
                $messaging_flow_Data[$this->i18n->get("[[base-mailsitestats.outbound]]")][$key] = $value;
            }

            $messaging_flow_graph = $factory->getBarGraph("messaging_flow", $messaging_flow_Data, $messaging_flow_seenTimes);
            $messaging_flow_graph->setPoints($this->i18n->get("[[base-mailsitestats.inbound]]"), FALSE);
            $messaging_flow_graph->setPoints($this->i18n->get("[[base-mailsitestats.outbound]]"), FALSE);
            $messaging_flow_graph->setSize("590", "450");
            $messaging_flow_graph->setXLabel($this->i18n->get("[[base-mailsitestats.number_of_messages]]"));
            $statsBlock->addFormField(
                $messaging_flow_graph,
                "",
                $Global_Default);

            //
            //-- Graph for 'Messaging Size Flow':
            //

            // Items of interest:
            $msg_inbound = explode(":", $SAR['messaging']['values_bytes']);
            $msg_outbound = explode(":", $SAR['messaging']['values1_bytes']);
            $message_size_flow_seenTimes = explode(":", $SAR['messaging']['lbls']);

            foreach ($msg_inbound as $key => $value) {
                $message_size_flow_Data[$this->i18n->get("[[base-mailsitestats.inbound]]")][$key] = "'" . sprintf("%.2f", $value/1000000) . "'";
            }

            foreach ($msg_outbound as $key => $value) {
                $message_size_flow_Data[$this->i18n->get("[[base-mailsitestats.outbound]]")][$key] = "'" . sprintf("%.2f", $value/1000000) . "'";
            }

            $message_size_flow_graph = $factory->getBarGraph("Messaging_Size_Flow", $message_size_flow_Data, $message_size_flow_seenTimes);
            $message_size_flow_graph->setPoints($this->i18n->get("[[base-mailsitestats.inbound]]"), FALSE);
            $message_size_flow_graph->setPoints($this->i18n->get("[[base-mailsitestats.outbound]]"), FALSE);
            $message_size_flow_graph->setSize("590", "450");
            $message_size_flow_graph->setXLabel($this->i18n->get("[[base-mailsitestats.size_mb]]"));
            $statsBlock->addFormField(
                $message_size_flow_graph,
                "",
                $Global_Default);

            //
            //-- Message delivery flows (table):
            //

            // Prepare data:
            $SAR['delivery']['total'] = $SAR['GLOBAL_STATUS']['Sent'];
            if (($SAR['delivery']['total'] == "") || ($SAR['delivery']['total'] == "0")) { 
                $SAR['delivery']['total'] = 1; 
            }

            if (!$SAR['delivery']['Ext_Int']) {
                $SAR['delivery']['Ext_Int'] = "0";
            }
            if (!$SAR['delivery']['Ext_Ext']) {
                $SAR['delivery']['Ext_Ext'] = "0";
            }
            if (!$SAR['delivery']['Int_Int']) {
                $SAR['delivery']['Int_Int'] = "0";
            }
            if (!$SAR['delivery']['Int_Ext']) {
                $SAR['delivery']['Int_Ext'] = "0";
            }

            $SAR['delivery']['total_bytes'] = $SAR['GLOBAL_STATUS']['Sent_bytes'];

            $SAR['delivery']['Ext_Int_percent'] = sprintf("%.2f", ($SAR['delivery']['Ext_Int']*100) / $SAR['delivery']['total']);
            $SAR['delivery']['Ext_Ext_percent'] = sprintf("%.2f", ($SAR['delivery']['Ext_Ext']*100) / $SAR['delivery']['total']);
            $SAR['delivery']['Int_Int_percent'] = sprintf("%.2f", ($SAR['delivery']['Int_Int']*100) / $SAR['delivery']['total']);
            $SAR['delivery']['Int_Ext_percent'] = sprintf("%.2f", ($SAR['delivery']['Int_Ext']*100) / $SAR['delivery']['total']);
            $nbsender = 0;
            $nbrcpt = 0;

            $mdf_table = array();

            $mdf_table[0][0] = $this->i18n->get("[[base-mailsitestats.external_to_internal]]");
            $mdf_table[1][0] = $SAR['delivery']['Ext_Int'];
            $mdf_table[2][0] = SimNum($SAR['delivery']['Ext_Ext_bytes']);
            $mdf_table[3][0] = $SAR['delivery']['Ext_Ext_percent'];

            $mdf_table[0][1] = $this->i18n->get("[[base-mailsitestats.external_to_external]]");
            $mdf_table[1][1] = $SAR['delivery']['Ext_Ext'];
            $mdf_table[2][1] = SimNum($SAR['delivery']['Ext_Ext_bytes']);
            $mdf_table[3][1] = $SAR['delivery']['Ext_Ext_percent'];

            $mdf_table[0][2] = $this->i18n->get("[[base-mailsitestats.internal_to_internal]]");
            $mdf_table[1][2] = $SAR['delivery']['Int_Int'];
            $mdf_table[2][2] = SimNum($SAR['delivery']['Int_Int_bytes']);
            $mdf_table[3][2] = $SAR['delivery']['Int_Int_percent'];

            $mdf_table[0][3] = $this->i18n->get("[[base-mailsitestats.internal_to_external]]");
            $mdf_table[1][3] = $SAR['delivery']['Int_Ext'];
            $mdf_table[2][3] = SimNum($SAR['delivery']['Int_Ext_bytes']);
            $mdf_table[3][3] = $SAR['delivery']['Int_Ext_percent'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $percentage = $this->i18n->get("[[base-mailsitestats.percentage]]");

            $message_delivery_flows_table = $factory->getScrollList("message_delivery_flows_table", array("", $messages, $size, $percentage), $mdf_table); 
            $message_delivery_flows_table->setAlignments(array("left", "left", "left", "left"));
            $message_delivery_flows_table->setDefaultSortedIndex('0');
            $message_delivery_flows_table->setSortOrder('ascending');
            $message_delivery_flows_table->setSortDisabled(array('1'));
            $message_delivery_flows_table->setPaginateDisabled(TRUE);
            $message_delivery_flows_table->setSearchDisabled(TRUE);
            $message_delivery_flows_table->setSelectorDisabled(FALSE);
            $message_delivery_flows_table->enableAutoWidth(FALSE);
            $message_delivery_flows_table->setInfoDisabled(TRUE);
            $message_delivery_flows_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
            $message_delivery_flows_table->setCurrentLabel("[[base-mailsitestats.messaging_flows]]");
            $message_delivery_flows_table->setDescription("[[base-mailsitestats.messaging_flows]]");

            // Out with the message_delivery_flows_table:
            $xxx = $factory->getRawHTML("message_delivery_flows_table", $message_delivery_flows_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("message_delivery_flows_table"),
                $Global_Default
            );

            //
            //-- 'Delivery Direction' Pie Chart:
            //

            if ($SAR['GLOBAL_STATUS']['Sent'] != '0') {
                // Setup data:
                $delivery_direction_Data['Ext -> Int'] = $SAR['delivery']['Ext_Int_percent'];
                $delivery_direction_Data['Ext -> Ext'] = $SAR['delivery']['Ext_Ext_percent'];
                $delivery_direction_Data['Int -> Int'] = $SAR['delivery']['Int_Int_percent'];
                $delivery_direction_Data['Int -> Ext'] = $SAR['delivery']['Int_Ext_percent'];

                // Generate Pie Chart:
                $delivery_direction_pieChart = $factory->getPieChart("delivery_direction_pieChart", $delivery_direction_Data);
                $delivery_direction_pieChart->setSize("590", "450");
                $delivery_direction_pieChart->setXLabel($this->i18n->get("[[base-mailsitestats.delivery_direction]]"));
                $statsBlock->addFormField(
                    $delivery_direction_pieChart,
                    "",
                    $Global_Default);
            }

            //
            //-- 'Different senders/recipients' (table):
            //

            // Prepare data:
            $nbsender = "0";
            $nbsenderArr = explode(":", $SAR['messaging']['nbsender']);
            foreach ($nbsenderArr as $value) {
                $nbsender += $value;
            }

            $nbrcpt = "0";
            $nbrcptArr = explode(":", $SAR['messaging']['nbrcpt']);
            foreach ($nbrcptArr as $value) {
                $nbrcpt += $value;
            }

            // Prepare table:
            $different_senders_recipients_data = array();

            $different_senders_recipients_data[0][0] = $nbsender;
            $different_senders_recipients_data[1][0] = $nbrcpt;

            $senders = $this->i18n->get("[[base-mailsitestats.senders]]");
            $recipients = $this->i18n->get("[[base-mailsitestats.recipients]]");

            $spam_delivery_flows_table = $factory->getScrollList("different_senders_recipients", array($senders, $recipients), $different_senders_recipients_data); 
            $spam_delivery_flows_table->setAlignments(array("left", "left"));
            $spam_delivery_flows_table->setDefaultSortedIndex('0');
            $spam_delivery_flows_table->setSortOrder('ascending');
            $spam_delivery_flows_table->setPaginateDisabled(TRUE);
            $spam_delivery_flows_table->setSearchDisabled(TRUE);
            $spam_delivery_flows_table->setSelectorDisabled(FALSE);
            $spam_delivery_flows_table->enableAutoWidth(FALSE);
            $spam_delivery_flows_table->setInfoDisabled(TRUE);
            $spam_delivery_flows_table->setColumnWidths(array("75%", "25%")); // Max: 739px
            $spam_delivery_flows_table->setCurrentLabel("[[base-mailsitestats.different_senders_recipients]]");
            $spam_delivery_flows_table->setDescription("[[base-mailsitestats.different_senders_recipients]]");

            // Out with the spam_delivery_flows_table:
            $xxx = $factory->getRawHTML("different_senders_recipients", $spam_delivery_flows_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("different_senders_recipients"),
                $Global_Default
            );

            //
            //-- Graph for 'Different senders/recipients':
            //

            if ($period != "hour") {
                // Items of interest:
                $diff_sender_reciepient_seenTimes = explode(":", $SAR['messaging']['lbls']);
                foreach ($nbsenderArr as $key => $value) {
                    $diff_sender_reciepient_Data[$this->i18n->get("[[base-mailsitestats.senders]]")][$key] = "'" . sprintf("%.2f", $value) . "'";
                }

                foreach ($nbrcptArr as $key => $value) {
                    $diff_sender_reciepient_Data[$this->i18n->get("[[base-mailsitestats.recipients]]")][$key] = "'" . sprintf("%.2f", $value) . "'";
                }

                $diff_sender_reciepient_graph = $factory->getBarGraph("diff_sender_reciepient", $diff_sender_reciepient_Data, $diff_sender_reciepient_seenTimes);
                $diff_sender_reciepient_graph->setPoints($this->i18n->get("[[base-mailsitestats.senders]]"), FALSE);
                $diff_sender_reciepient_graph->setPoints($this->i18n->get("[[base-mailsitestats.recipients]]"), FALSE);
                $diff_sender_reciepient_graph->setSize("590", "450");
                $diff_sender_reciepient_graph->setXLabel($this->i18n->get("[[base-mailsitestats.different_senders_recipients]]"));
                $statsBlock->addFormField(
                    $diff_sender_reciepient_graph,
                    "",
                    $Global_Default);
            }

            //@//
            //@//-- Spamming Tab:
            //@//

            //
            //--- 'Spamming flows' table:
            //

            // Prepare data for Spamming flows + Spam delivery flows:
            $SAR['spam']['inbound_mean']            = Meaner($SAR['spam']['inbound_bytes'],         $SAR['spam']['inbound']);
            $SAR['spam']['local_inbound_mean']      = Meaner($SAR['spam']['local_inbound_bytes'],   $SAR['spam']['local_inbound']);
            $SAR['spam']['total_inbound_mean']      = Meaner($SAR['spam']['total_inbound_bytes'],   $SAR['spam']['total_inbound']);
            $SAR['spam']['outbound_mean']           = Meaner($SAR['spam']['outbound_bytes'],        $SAR['spam']['outbound']);
            $SAR['spam']['local_outbound_mean']     = Meaner($SAR['spam']['local_outbound_bytes'],  $SAR['spam']['local_outbound']);
            $SAR['spam']['total_outbound_mean']     = Meaner($SAR['spam']['total_outbound_bytes'],  $SAR['spam']['total_outbound']);

            $SAR['spam']['Ext_Int_mean']            = Meaner($SAR['spam']['Ext_Int'],   $SAR['spam']['Ext_Int_bytes']);
            $SAR['spam']['Int_Int_mean']            = Meaner($SAR['spam']['Int_Int'],   $SAR['spam']['Int_Int_bytes']);
            $SAR['spam']['Int_Ext_mean']            = Meaner($SAR['spam']['Int_Ext'],   $SAR['spam']['Int_Ext_bytes']);
            $SAR['spam']['Ext_Ext_mean']            = Meaner($SAR['spam']['Ext_Ext'],   $SAR['spam']['Ext_Ext_bytes']);

            $SAR['spam']['Int_Int_bytes']           = SimNum($SAR['spam']['Int_Int_bytes']);
            $SAR['spam']['Int_Ext_bytes']           = SimNum($SAR['spam']['Int_Ext_bytes']);
            $SAR['spam']['Ext_Ext_bytes']           = SimNum($SAR['spam']['Ext_Ext_bytes']);
            $SAR['spam']['Ext_Int_bytes']           = SimNum($SAR['spam']['Ext_Int_bytes']);

            $spam_flow_table_data = array();

            $spam_flow_table_data[0][0] = $this->i18n->get("[[base-mailsitestats.incoming]]");
            $spam_flow_table_data[1][0] = $SAR['GLOBAL_STATUS']['Spam'];
            $spam_flow_table_data[2][0] = SimNum($SAR['spam']['inbound_bytes']);
            $spam_flow_table_data[3][0] = $SAR['spam']['inbound_mean'];

            $spam_flow_table_data[0][1] = $this->i18n->get("[[base-mailsitestats.local_incomming]]");
            $spam_flow_table_data[1][1] = $SAR['spam']['local_inbound'];
            $spam_flow_table_data[2][1] = SimNum($SAR['spam']['local_inbound_bytes']);
            $spam_flow_table_data[3][1] = $SAR['spam']['inbound_mean'];

            $spam_flow_table_data[0][2] = $this->i18n->get("[[base-mailsitestats.total_incomming]]");
            $spam_flow_table_data[1][2] = $SAR['spam']['total_inbound'];
            $spam_flow_table_data[2][2] = SimNum($SAR['spam']['total_inbound_bytes']);
            $spam_flow_table_data[3][2] = $SAR['spam']['total_inbound_mean'];

            $spam_flow_table_data[0][3] = $this->i18n->get("[[base-mailsitestats.outgoing]]");
            $spam_flow_table_data[1][3] = $SAR['spam']['outbound'];
            $spam_flow_table_data[2][3] = SimNum($SAR['spam']['outbound_bytes']);
            $spam_flow_table_data[3][3] = $SAR['spam']['outbound_mean'];

            $spam_flow_table_data[0][4] = $this->i18n->get("[[base-mailsitestats.local_delivery]]");
            $spam_flow_table_data[1][4] = $SAR['spam']['local_outbound'];
            $spam_flow_table_data[2][4] = SimNum($SAR['spam']['local_outbound_bytes']);
            $spam_flow_table_data[3][4] = $SAR['spam']['local_outbound_mean'];

            $spam_flow_table_data[0][5] = $this->i18n->get("[[base-mailsitestats.total_outgoing]]");
            $spam_flow_table_data[1][5] = $SAR['spam']['total_outbound'];
            $spam_flow_table_data[2][5] = SimNum($SAR['spam']['total_outbound_bytes']);
            $spam_flow_table_data[3][5] = $SAR['spam']['total_outbound_mean'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $size_per_msg = $this->i18n->get("[[base-mailsitestats.size_per_msg]]");

            $spam_flows_table = $factory->getScrollList("messaging_flows", array("", $messages, $size, $size_per_msg), $spam_flow_table_data); 
            $spam_flows_table->setAlignments(array("left", "left", "left", "left"));
            $spam_flows_table->setDefaultSortedIndex('0');
            $spam_flows_table->setSortOrder('ascending');
            //$spam_flows_table->setSortDisabled(array('1'));
            $spam_flows_table->setPaginateDisabled(TRUE);
            $spam_flows_table->setSearchDisabled(TRUE);
            $spam_flows_table->setSelectorDisabled(FALSE);
            $spam_flows_table->enableAutoWidth(FALSE);
            $spam_flows_table->setInfoDisabled(TRUE);
            $spam_flows_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
            $spam_flows_table->setCurrentLabel("[[base-mailsitestats.spam_delivery_flows]]");
            $spam_flows_table->setDescription("[[base-mailsitestats.spam_delivery_flows]]");

            // Out with the 'Spamming flows' table:
            $xxx = $factory->getRawHTML("spamming_flows", $spam_flows_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("spamming_flows"),
                $Global_Spamming
            );

            //
            //--- 'Spam delivery flows' table:
            //

            $spam_delivery_flow_table_data = array();

            $spam_delivery_flow_table_data[0][0] = $this->i18n->get("[[base-mailsitestats.external_to_internal]]");
            $spam_delivery_flow_table_data[1][0] = $SAR['spam']['Ext_Int'];
            $spam_delivery_flow_table_data[2][0] = $SAR['spam']['Ext_Int_bytes'];
            $spam_delivery_flow_table_data[3][0] = $SAR['spam']['Ext_Int_mean'];

            $spam_delivery_flow_table_data[0][1] = $this->i18n->get("[[base-mailsitestats.external_to_external]]");
            $spam_delivery_flow_table_data[1][1] = $SAR['spam']['Ext_Ext'];
            $spam_delivery_flow_table_data[2][1] = $SAR['spam']['Ext_Ext_bytes'];
            $spam_delivery_flow_table_data[3][1] = $SAR['spam']['Ext_Ext_mean'];

            $spam_delivery_flow_table_data[0][2] = $this->i18n->get("[[base-mailsitestats.internal_to_internal]]");
            $spam_delivery_flow_table_data[1][2] = $SAR['spam']['Int_Int'];
            $spam_delivery_flow_table_data[2][2] = $SAR['spam']['Int_Int_bytes'];
            $spam_delivery_flow_table_data[3][2] = $SAR['spam']['Int_Int_mean'];

            $spam_delivery_flow_table_data[0][3] = $this->i18n->get("[[base-mailsitestats.internal_to_external]]");
            $spam_delivery_flow_table_data[1][3] = $SAR['spam']['Int_Ext'];
            $spam_delivery_flow_table_data[2][3] = $SAR['spam']['Int_Ext_bytes'];
            $spam_delivery_flow_table_data[3][3] = $SAR['spam']['Int_Ext_mean'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $size_per_msg = $this->i18n->get("[[base-mailsitestats.size_per_msg]]");

            $spam_delivery_flows_table = $factory->getScrollList("messaging_delivery_flows", array("", $messages, $size, $size_per_msg), $spam_delivery_flow_table_data); 
            $spam_delivery_flows_table->setAlignments(array("left", "left", "left", "left"));
            $spam_delivery_flows_table->setDefaultSortedIndex('0');
            $spam_delivery_flows_table->setSortOrder('ascending');
            //$spam_delivery_flows_table->setSortDisabled(array('1'));
            $spam_delivery_flows_table->setPaginateDisabled(TRUE);
            $spam_delivery_flows_table->setSearchDisabled(TRUE);
            $spam_delivery_flows_table->setSelectorDisabled(FALSE);
            $spam_delivery_flows_table->enableAutoWidth(FALSE);
            $spam_delivery_flows_table->setInfoDisabled(TRUE);
            $spam_delivery_flows_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
            $spam_delivery_flows_table->setCurrentLabel("[[base-mailsitestats.spamming_flows]]");
            $spam_delivery_flows_table->setDescription("[[base-mailsitestats.spamming_flows]]");

            // Out with the spam_delivery_flows_table:
            $xxx = $factory->getRawHTML("spam_delivery_flows_table", $spam_delivery_flows_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("spam_delivery_flows_table"),
                $Global_Spamming
            );

            //
            //-- Graph for 'Spamming Flow':
            //

            // Items of interest:
            $msg_spam_array = explode(":", $SAR['spam']['values']);
            $spamming_flow_seenTimes = explode(":", $SAR['spam']['lbls']);

            foreach ($msg_spam_array as $key => $value) {
                $spamming_flow_Data['#SPAMs'][$key] = "'" . sprintf("%.2f", $value) . "'";
            }

            $spamming_flow_graph = $factory->getBarGraph("Spamming_Flow", $spamming_flow_Data, $spamming_flow_seenTimes);
            $spamming_flow_graph->setPoints('#SPAMs', FALSE);
            $spamming_flow_graph->setSize("590", "450");
            $spamming_flow_graph->setXLabel($this->i18n->get("[[base-mailsitestats.spamming_flow]]"));
            $statsBlock->addFormField(
                $spamming_flow_graph,
                "",
                $Global_Spamming);


            //@//
            //@//-- Virus Tab:
            //@//

            //
            //--- Note: Effectively disabled for now as I don't have any sample data to play with.
            //

            // Start sane:
            $SAR['virus']['inbound'] = defaulter($SAR['virus']['inbound']);
            $SAR['virus']['local_inbound'] = defaulter($SAR['virus']['local_inbound']);
            $SAR['virus']['outbound'] = defaulter($SAR['virus']['outbound']);
            $SAR['virus']['local_outbound'] = defaulter($SAR['virus']['local_outbound']);

            $SAR['virus']['Int_Int'] = defaulter($SAR['virus']['Int_Int']);
            $SAR['virus']['Int_Ext'] = defaulter($SAR['virus']['Int_Ext']);
            $SAR['virus']['Ext_Ext'] = defaulter($SAR['virus']['Ext_Ext']);
            $SAR['virus']['Ext_Int'] = defaulter($SAR['virus']['Ext_Int']);

            $SAR['virus'] = SummaryEmail::Nuller($SAR['virus']);

            // Prepare data for virus statistics:
            $SAR['virus']['total_inbound']          = $SAR['virus']['inbound']          + $SAR['virus']['local_inbound'];
            $SAR['virus']['total_inbound_bytes']    = $SAR['virus']['inbound_bytes']    + $SAR['virus']['local_inbound_bytes'];
            $SAR['virus']['total_outbound']         = $SAR['virus']['outbound']         + $SAR['virus']['local_outbound'];
            $SAR['virus']['total_outbound_bytes']   = $SAR['virus']['outbound_bytes']   + $SAR['virus']['local_outbound_bytes'];

            $SAR['virus']['total_inbound_bytes']    = SimNum($SAR['virus']['total_inbound_bytes']/1000000);
            $SAR['virus']['inbound_bytes']          = SimNum($SAR['virus']['inbound_bytes']/1000000);
            $SAR['virus']['local_inbound_bytes']    = SimNum($SAR['virus']['local_inbound_bytes']/1000000);
            $SAR['virus']['total_outbound_bytes']   = SimNum($SAR['virus']['total_outbound_bytes']/1000000);
            $SAR['virus']['outbound_bytes']         = SimNum($SAR['virus']['outbound_bytes']/1000000);
            $SAR['virus']['local_outbound_bytes']   = SimNum($SAR['virus']['local_outbound_bytes']/1000000);

            $SAR['virus']['Int_Int']                = SimNum($SAR['virus']['Int_Int']);
            $SAR['virus']['Int_Ext']                = SimNum($SAR['virus']['Int_Ext']);
            $SAR['virus']['Ext_Ext']                = SimNum($SAR['virus']['Ext_Ext']);
            $SAR['virus']['Ext_Int']                = SimNum($SAR['virus']['Ext_Int']);

            // Viruses flows / Viruses delivery flows / syserr flows

            $SAR['virus']['inbound_mean']           = Meaner($SAR['virus']['inbound_bytes'],    $SAR['virus']['inbound']);
            $SAR['virus']['local_inbound_mean']     = Meaner($SAR['virus']['local_inbound_bytes'],  $SAR['virus']['local_inbound']);
            $SAR['virus']['total_inbound_mean']     = Meaner($SAR['virus']['total_inbound_bytes'],  $SAR['virus']['total_inbound']);
            $SAR['virus']['outbound_mean']          = Meaner($SAR['virus']['outbound_bytes'],   $SAR['virus']['outbound']);
            $SAR['virus']['local_outbound_mean']    = Meaner($SAR['virus']['local_outbound_bytes'],     $SAR['virus']['local_outbound']);
            $SAR['virus']['total_outbound_mean']    = Meaner($SAR['virus']['total_outbound_bytes'],     $SAR['virus']['total_outbound']);

            $SAR['virus']['Ext_Int_mean']           = Meaner($SAR['virus']['Ext_Int'],  $SAR['virus']['Ext_Int_bytes']);
            $SAR['virus']['Int_Int_mean']           = Meaner($SAR['virus']['Int_Int'],  $SAR['virus']['Int_Int_bytes']);
            $SAR['virus']['Int_Ext_mean']           = Meaner($SAR['virus']['Int_Ext'],  $SAR['virus']['Int_Ext_bytes']);
            $SAR['virus']['Ext_Ext_mean']           = Meaner($SAR['virus']['Ext_Ext'],  $SAR['virus']['Ext_Ext_bytes']);

            $SAR['virus']['Int_Int_bytes']          = SimNum($SAR['virus']['Int_Int_bytes']/1000000);
            $SAR['virus']['Int_Ext_bytes']          = SimNum($SAR['virus']['Int_Ext_bytes']/1000000);
            $SAR['virus']['Ext_Ext_bytes']          = SimNum($SAR['virus']['Ext_Ext_bytes']/1000000);
            $SAR['virus']['Ext_Int_bytes']          = SimNum($SAR['virus']['Ext_Int_bytes']/1000000);

            $virus_data = array();

            $virus_data[0][0] = $this->i18n->get("[[base-mailsitestats.external_to_internal]]");
            $virus_data[1][0] = $SAR['virus']['Ext_Int'];
            $virus_data[2][0] = $SAR['virus']['Ext_Int_bytes'];
            $virus_data[3][0] = $SAR['virus']['Ext_Int_mean'];

            $virus_data[0][1] = $this->i18n->get("[[base-mailsitestats.external_to_external]]");
            $virus_data[1][1] = $SAR['virus']['Ext_Ext'];
            $virus_data[2][1] = $SAR['virus']['Ext_Ext_bytes'];
            $virus_data[3][1] = $SAR['virus']['Ext_Ext_mean'];

            $virus_data[0][2] = $this->i18n->get("[[base-mailsitestats.internal_to_internal]]");
            $virus_data[1][2] = $SAR['virus']['Int_Int'];
            $virus_data[2][2] = $SAR['virus']['Int_Int_bytes'];
            $virus_data[3][2] = $SAR['virus']['Int_Int_mean'];

            $virus_data[0][3] = $this->i18n->get("[[base-mailsitestats.internal_to_external]]");
            $virus_data[1][3] = $SAR['virus']['Int_Ext'];
            $virus_data[2][3] = $SAR['virus']['Int_Ext_bytes'];
            $virus_data[3][3] = $SAR['virus']['Int_Ext_mean'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $size_per_msg = $this->i18n->get("[[base-mailsitestats.size_per_msg]]");

            $virus_table = $factory->getScrollList("global_virus", array("", $messages, $size, $size_per_msg), $virus_data); 
            $virus_table->setAlignments(array("left", "left", "left", "left"));
            $virus_table->setDefaultSortedIndex('0');
            $virus_table->setSortOrder('ascending');
            //$virus_table->setSortDisabled(array('1'));
            $virus_table->setPaginateDisabled(TRUE);
            $virus_table->setSearchDisabled(TRUE);
            $virus_table->setSelectorDisabled(FALSE);
            $virus_table->enableAutoWidth(FALSE);
            $virus_table->setInfoDisabled(TRUE);
            $virus_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
            $virus_table->setCurrentLabel("[[base-mailsitestats.spamming_flows]]");
            $virus_table->setDescription("[[base-mailsitestats.spamming_flows]]");

            // Out with the Virus Table:
            $xxx = $factory->getRawHTML("global_virus", $virus_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("global_virus"),
                $Global_Virus
            );

            //@//
            //@//-- Notification Tab:
            //@//

            // Prep data:

            if ((!isset($SAR['dsn']['outbound']))       || ($SAR['dsn']['outbound'] == ""))         { $SAR['dsn']['outbound'] = '0'; }
            if ((!isset($SAR['dsn']['local_outbound']))     || ($SAR['dsn']['local_outbound'] == ""))   { $SAR['dsn']['local_outbound'] = '0'; }
            if ((!isset($SAR['dsn']['error']))          || ($SAR['dsn']['error'] == ""))            { $SAR['dsn']['error'] = '0'; }

            $SAR['dsn']['total_outbound'] = $SAR['dsn']['outbound'] + $SAR['dsn']['local_outbound'];

            if ((!isset($SAR['dsn']['Int_Int']))        || ($SAR['dsn']['Int_Int'] == ""))          { $SAR['dsn']['Int_Int'] = '0'; }
            if ((!isset($SAR['dsn']['Int_Ext']))        || ($SAR['dsn']['Int_Ext'] == ""))          { $SAR['dsn']['Int_Ext'] = '0'; }

            $total_dsn = $SAR['dsn']['total_outbound'] + $SAR['dsn']['error'];

            // We have data:

            $notification_data = array();

            $notification_data[0][0] = $this->i18n->get("[[base-mailsitestats.outgoing]]");
            $notification_data[1][0] = $SAR['dsn']['total_outbound'];

            $notification_data[0][1] = $this->i18n->get("[[base-mailsitestats.in_error]]");
            $notification_data[1][1] = $SAR['dsn']['error'];

            $notification_data[0][2] = $this->i18n->get("[[base-mailsitestats.total]]");
            $notification_data[1][2] = $total_dsn;

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");

            $notification_data = $factory->getScrollList("delivery_status_notification", array("", $messages), $notification_data); 
            $notification_data->setAlignments(array("left", "left"));
            $notification_data->setDefaultSortedIndex('0');
            $notification_data->setSortOrder('ascending');
            //$notification_data->setSortDisabled(array('1'));
            $notification_data->setPaginateDisabled(TRUE);
            $notification_data->setSearchDisabled(TRUE);
            $notification_data->setSelectorDisabled(FALSE);
            $notification_data->enableAutoWidth(FALSE);
            $notification_data->setInfoDisabled(TRUE);
            $notification_data->setColumnWidths(array("75%", "25%")); // Max: 739px
            $notification_data->setCurrentLabel("[[base-mailsitestats.delivery_status_notification]]");
            $notification_data->setDescription("[[base-mailsitestats.delivery_status_notification]]");

            // Out with the notification_data Table:
            $xxx = $factory->getRawHTML("delivery_status_notification", $notification_data->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("delivery_status_notification"),
                $Global_Notification
            );

            $dsn_delivery_flows_data = array();

            $dsn_delivery_flows_data[0][0] = $this->i18n->get("[[base-mailsitestats.internal_to_internal]]");
            $dsn_delivery_flows_data[1][0] = $SAR['dsn']['Int_Int'];

            $dsn_delivery_flows_data[0][1] = $this->i18n->get("[[base-mailsitestats.internal_to_external]]");
            $dsn_delivery_flows_data[1][1] = $SAR['dsn']['Int_Ext'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");

            $dsn_delivery_flows_table = $factory->getScrollList("dsn_delivery_flows_table", array("", $messages), $dsn_delivery_flows_data); 
            $dsn_delivery_flows_table->setAlignments(array("left", "left"));
            $dsn_delivery_flows_table->setDefaultSortedIndex('0');
            $dsn_delivery_flows_table->setSortOrder('ascending');
            //$dsn_delivery_flows_table->setSortDisabled(array('1'));
            $dsn_delivery_flows_table->setPaginateDisabled(TRUE);
            $dsn_delivery_flows_table->setSearchDisabled(TRUE);
            $dsn_delivery_flows_table->setSelectorDisabled(FALSE);
            $dsn_delivery_flows_table->enableAutoWidth(FALSE);
            $dsn_delivery_flows_table->setInfoDisabled(TRUE);
            $dsn_delivery_flows_table->setColumnWidths(array("75%", "25%")); // Max: 739px
            $dsn_delivery_flows_table->setCurrentLabel("[[base-mailsitestats.dsn_delivery_flows]]");
            $dsn_delivery_flows_table->setDescription("[[base-mailsitestats.dsn_delivery_flows]]");

            // Out with the dsn_delivery_flows_table Table:
            $xxx = $factory->getRawHTML("dsn_delivery_flows", $dsn_delivery_flows_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("dsn_delivery_flows"),
                $Global_Notification
            );

            //
            //-- DSN Flow (graph):
            //

            // Items of interest:
            $diff_dsn_seenTimes = explode(":", $SAR['dsn']['lbls']);
            $dsn_flow_Data = explode(":", $SAR['dsn']['values']);
            foreach ($dsn_flow_Data as $key => $value) {
                $diff_dsn_flow_Data['#dsn'][$key] = "'" . $value . "'";
            }

            $dsn_flow_Data_graph = $factory->getBarGraph("dsn_flow_Data", $diff_dsn_flow_Data, $diff_dsn_seenTimes);
            $dsn_flow_Data_graph->setPoints('#dsn', FALSE);
            $dsn_flow_Data_graph->setSize("590", "450");
            $dsn_flow_Data_graph->setXLabel($this->i18n->get("[[base-mailsitestats.dsn_flow]]"));
            $statsBlock->addFormField(
                $dsn_flow_Data_graph,
                "",
                $Global_Notification);

            //@//
            //@//-- Rejection Tab:
            //@//

            // Prep data:

            $SAR['reject'] = SummaryEmail::Nuller($SAR['reject']);
            $SAR['err'] = SummaryEmail::Nuller($SAR['err']);

            if ((!isset($SAR['reject']['inbound']))         || ($SAR['reject']['inbound'] == ""))           { $SAR['reject']['inbound'] = '0'; }
            if ((!isset($SAR['reject']['local_inbound']))   || ($SAR['reject']['local_inbound'] == ""))     { $SAR['reject']['local_inbound'] = '0'; }

            $SAR['reject']['total_inbound'] = $SAR['reject']['inbound'] + $SAR['reject']['local_inbound'];
            $SAR['reject']['total_inbound_bytes'] = $SAR['reject']['inbound_bytes'] + $SAR['reject']['local_inbound_bytes'];

            if ((!isset($SAR['err']['inbound']))        || ($SAR['err']['inbound'] == ""))                  { $SAR['err']['inbound'] = '0'; }
            if ((!isset($SAR['err']['local_inbound']))  || ($SAR['err']['local_inbound'] == ""))            { $SAR['err']['local_inbound'] = '0'; }

            $SAR['err']['total_inbound'] = $SAR['err']['inbound'] + $SAR['err']['local_inbound'];
            $SAR['err']['total_inbound_bytes'] = $SAR['err']['inbound_bytes'] + $SAR['err']['local_inbound_bytes'];

            $SAR['reject']['total_inbound_bytes']   = SimNum($SAR['reject']['total_inbound_bytes']);
            $SAR['reject']['inbound_bytes']         = SimNum($SAR['reject']['inbound_bytes']);
            $SAR['reject']['local_inbound_bytes']   = SimNum($SAR['reject']['local_inbound_bytes']);

            $SAR['err']['total_inbound_bytes']      = SimNum($SAR['err']['total_inbound_bytes']);
            $SAR['err']['inbound_bytes']            = SimNum($SAR['err']['inbound_bytes']);
            $SAR['err']['local_inbound_bytes']      = SimNum($SAR['err']['local_inbound_bytes']);

            $SAR['reject']['inbound_mean']          = Meaner($SAR['reject']['inbound_bytes'],   $SAR['reject']['inbound']);
            $SAR['reject']['local_inbound_mean']    = Meaner($SAR['reject']['local_inbound_bytes'],     $SAR['reject']['local_inbound']);
            $SAR['reject']['total_inbound_mean']    = Meaner($SAR['reject']['total_inbound_bytes'],     $SAR['reject']['total_inbound']);

            $SAR['err']['inbound_mean']             = Meaner($SAR['err']['inbound_bytes'],          $SAR['err']['inbound']);
            $SAR['err']['local_inbound_mean']       = Meaner($SAR['err']['local_inbound_bytes'],    $SAR['err']['local_inbound']);
            $SAR['err']['total_inbound_mean']       = Meaner($SAR['err']['total_inbound_bytes'],    $SAR['err']['total_inbound']);

            $rejection_data = array();

            $rejection_data[0][0] = $this->i18n->get("[[base-mailsitestats.incoming]]");
            $rejection_data[1][0] = $SAR['reject']['inbound'];
            $rejection_data[2][0] = $SAR['reject']['inbound_bytes'];
            $rejection_data[3][0] = $SAR['reject']['inbound_mean'];

            $rejection_data[0][1] = $this->i18n->get("[[base-mailsitestats.local_incomming]]");
            $rejection_data[1][1] = $SAR['reject']['local_inbound'];
            $rejection_data[2][1] = $SAR['reject']['local_inbound_bytes'];
            $rejection_data[3][1] = $SAR['reject']['local_inbound_mean'];

            $rejection_data[0][2] = $this->i18n->get("[[base-mailsitestats.total_incomming]]");
            $rejection_data[1][2] = $SAR['reject']['total_inbound'];
            $rejection_data[2][2] = $SAR['reject']['total_inbound_bytes'];
            $rejection_data[3][2] = $SAR['reject']['total_inbound_mean'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $size_per_msg = $this->i18n->get("[[base-mailsitestats.size_per_msg]]");

            $rejection_flows_table = $factory->getScrollList("rejection_flows_table", array("", $messages, $size, $size_per_msg), $rejection_data); 
            $rejection_flows_table->setAlignments(array("left", "left", "left", "left"));
            $rejection_flows_table->setDefaultSortedIndex('0');
            $rejection_flows_table->setSortOrder('ascending');
            $rejection_flows_table->setPaginateDisabled(TRUE);
            $rejection_flows_table->setSearchDisabled(TRUE);
            $rejection_flows_table->setSelectorDisabled(FALSE);
            $rejection_flows_table->enableAutoWidth(FALSE);
            $rejection_flows_table->setInfoDisabled(TRUE);
            $rejection_flows_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
            $rejection_flows_table->setCurrentLabel("[[base-mailsitestats.rejection_flows]]");
            $rejection_flows_table->setDescription("[[base-mailsitestats.rejection_flows]]");

            // Out with the messaging_flows_table:
            $xxx = $factory->getRawHTML("rejection_flows", $rejection_flows_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("rejection_flows"),
                $Global_Rejections
            );

            // Syserr flows

            $syserr_data = array();

            $syserr_data[0][0] = $this->i18n->get("[[base-mailsitestats.incoming]]");
            $syserr_data[1][0] = $SAR['err']['inbound'];
            $syserr_data[2][0] = $SAR['err']['inbound_bytes'];
            $syserr_data[3][0] = $SAR['err']['inbound_mean'];

            $syserr_data[0][1] = $this->i18n->get("[[base-mailsitestats.local_incomming]]");
            $syserr_data[1][1] = $SAR['err']['local_inbound'];
            $syserr_data[2][1] = $SAR['err']['local_inbound_bytes'];
            $syserr_data[3][1] = $SAR['err']['local_inbound_mean'];

            $syserr_data[0][2] = $this->i18n->get("[[base-mailsitestats.total_incomming]]");
            $syserr_data[1][2] = $SAR['err']['total_inbound'];
            $syserr_data[2][2] = $SAR['err']['total_inbound_bytes'];
            $syserr_data[3][2] = $SAR['err']['total_inbound_mean'];

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $size_per_msg = $this->i18n->get("[[base-mailsitestats.size_per_msg]]");

            $syserr_flows_table = $factory->getScrollList("syserr_flows_table", array("", $messages, $size, "$size_per_msg"), $syserr_data); 
            $syserr_flows_table->setAlignments(array("left", "left", "left", "left"));
            $syserr_flows_table->setDefaultSortedIndex('0');
            $syserr_flows_table->setSortOrder('ascending');
            $syserr_flows_table->setPaginateDisabled(TRUE);
            $syserr_flows_table->setSearchDisabled(TRUE);
            $syserr_flows_table->setSelectorDisabled(FALSE);
            $syserr_flows_table->enableAutoWidth(FALSE);
            $syserr_flows_table->setInfoDisabled(TRUE);
            $syserr_flows_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
            $syserr_flows_table->setCurrentLabel("[[base-mailsitestats.syserr_flows]]");
            $syserr_flows_table->setDescription("[[base-mailsitestats.syserr_flows]]");

            // Out with the messaging_flows_table:
            $xxx = $factory->getRawHTML("syserr_flows", $syserr_flows_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("syserr_flows"),
                $Global_Rejections
            );

            //@//
            //@//-- Status Tab:
            //@//

            // Prep data:

            $delivery_global_total = 1;
            $total_percent = 0;
            $new_GLOBAL_STATUS = array();
            foreach ($SAR['GLOBAL_STATUS'] as $key => $value) {
                if (!preg_match('/_bytes/', $key)) {
                    $delivery_global_total += $value;
                    $new_GLOBAL_STATUS[$key] = $value;
                }
            }

            $delivery_total = $SAR['GLOBAL_STATUS']['Sent'];
            $delivery_total_bytes = $SAR['GLOBAL_STATUS']['Sent_bytes'];
            $total_percent = 0;
            $piecount = 0;
            $messaging_status = array();
            arsort($new_GLOBAL_STATUS);
            $messaging_status_pieChart = array();
            $messaging_status_pieChart_Data_unlock = '0';
            $messaging_status_num = 0;
            foreach ($new_GLOBAL_STATUS as $key => $value) {
                if ((!preg_match('/_bytes/', $key)) && (!preg_match('/_bytes/', $key)) && 
                    (!preg_match('/Virus/', $key)) && (!preg_match('/Spam/', $key)) && 
                    (!preg_match('/Command rejected/', $key))) {

                    $percent = sprintf("%.2f", ($value/$delivery_global_total * 100));

                    // Prep pieChart while we're at it:
                    $messaging_status_pieChart_Data[$key] = $percent;
                    if ($percent != '0.00') {
                        $messaging_status_pieChart_Data_unlock = '1';
                    }
                    $messaging_status[0][$messaging_status_num] = $key;
                    $messaging_status[1][$messaging_status_num] = $value;
                    $messaging_status[2][$messaging_status_num] = SimNum($SAR['GLOBAL_STATUS'][$key . '_bytes']);
                    $messaging_status[3][$messaging_status_num] = $percent;
                    $messaging_status_num++;
                }
            }

            $messages = $this->i18n->get("[[base-mailsitestats.messages]]");
            $size = $this->i18n->get("[[base-mailsitestats.size]]");
            $percentage = $this->i18n->get("[[base-mailsitestats.percentage]]");

            if (count($messaging_status) > 0) {
                $messaging_status_table = $factory->getScrollList("messaging_status_table", array("", $messages, $size, $percentage), $messaging_status); 
                $messaging_status_table->setAlignments(array("left", "left", "left", "left"));
                $messaging_status_table->setDefaultSortedIndex('0');
                $messaging_status_table->setSortOrder('ascending');
                $messaging_status_table->setPaginateDisabled(TRUE);
                $messaging_status_table->setSearchDisabled(TRUE);
                $messaging_status_table->setSelectorDisabled(FALSE);
                $messaging_status_table->enableAutoWidth(FALSE);
                $messaging_status_table->setInfoDisabled(TRUE);
                $messaging_status_table->setColumnWidths(array("25%", "25%", "25%", "25%")); // Max: 739px
                $messaging_status_table->setCurrentLabel("[[base-mailsitestats.messaging_status]]");
                $messaging_status_table->setDescription("[[base-mailsitestats.messaging_status]]");

                // Out with the messaging_flows_table:
                $xxx = $factory->getRawHTML("messaging_status", $messaging_status_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("messaging_status"),
                    $Global_Status
                );
            }

            // Generate Pie Chart:
            if ((count($messaging_status_pieChart_Data) > 1) && ($messaging_status_pieChart_Data_unlock == '1')) {
                $messaging_status_pieChart = $factory->getPieChart("messaging_status_pieChart_Data", $messaging_status_pieChart_Data);
                $messaging_status_pieChart->setSize("590", "450");
                $messaging_status_pieChart->setXLabel($this->i18n->get("[[base-mailsitestats.messaging_status]]"));
                $statsBlock->addFormField(
                    $messaging_status_pieChart,
                    "",
                    $Global_Status
                );
            }

            //@//
            //@//-- SMTP Auth Tab:
            //@//

            // Prepare data:
            $authkeys_unwanted = array('x_label', 'lbls', 'values');
            $auth_mechanisms = array();
            $total_auth = '0';

            if (isset($SAR['auth']['server'])) {
                foreach ($SAR['auth']['server'] as $key => $value) {
                    if (!in_array($key, $authkeys_unwanted)) {
                        $auth_mechanisms[$key] = $value;
                        $total_auth += $value;
                    }
                }
            }

            //
            //--- 'SMTP Auth: server' table:
            //

            arsort($auth_mechanisms);
            $smtp_auth_server = array();
            $smtp_auth_server_num = 0;
            foreach ($auth_mechanisms as $key => $value) {
                // Prep pieChart while we're at it:
                $messaging_status_pieChart_Data[$key] = $percent;

                $smtp_auth_server[0][$smtp_auth_server_num] = $key;
                $smtp_auth_server[1][$smtp_auth_server_num] = $value;
                $smtp_auth_server_num++;
            }

            $smtp_auth_server[0][$smtp_auth_server_num] = $this->i18n->get("[[base-mailsitestats.total]]");
            $smtp_auth_server[1][$smtp_auth_server_num] = $total_auth;

            $mechanism = $this->i18n->get("[[base-mailsitestats.mechanism]]");
            $count = $this->i18n->get("[[base-mailsitestats.count]]");

            $smtp_auth_server_table = $factory->getScrollList("smtpauth_server_table", array($mechanism, $count), $smtp_auth_server); 
            $smtp_auth_server_table->setAlignments(array("left", "left"));
            $smtp_auth_server_table->setDefaultSortedIndex('0');
            $smtp_auth_server_table->setSortOrder('ascending');
            $smtp_auth_server_table->setPaginateDisabled(TRUE);
            $smtp_auth_server_table->setSearchDisabled(TRUE);
            $smtp_auth_server_table->setSelectorDisabled(FALSE);
            $smtp_auth_server_table->enableAutoWidth(FALSE);
            $smtp_auth_server_table->setInfoDisabled(TRUE);
            $smtp_auth_server_table->setColumnWidths(array("75%", "25%")); // Max: 739px
            $smtp_auth_server_table->setCurrentLabel("[[base-mailsitestats.smtpauth_server]]");
            $smtp_auth_server_table->setDescription("[[base-mailsitestats.smtpauth_server]]");

            // Out with the spam_delivery_flows_table:
            $xxx = $factory->getRawHTML("smtpauth_server", $smtp_auth_server_table->toHtml());
            $statsBlock->addFormField(
                $xxx,
                $factory->getLabel("smtpauth_server"),
                $Global_SMTPAuth
            );

            if ((isset($SAR['auth']['server']['lbls'])) && (isset($SAR['auth']['server']['values']))) {
                $smtp_auth_server_seenTimes = explode(":", $SAR['auth']['server']['lbls']);
                $smtp_auth_server_valArr = explode(":", $SAR['auth']['server']['values']);
                foreach ($smtp_auth_server_valArr as $key => $value) {
                    $smtp_auth_server_Data['auth'][$key] = "'" . sprintf("%.2f", $value) . "'";
                }

                // Out with the barGraph:
                $smtp_auth_server_Data_graph = $factory->getBarGraph("smtp_auth_server_Data", $smtp_auth_server_Data, $smtp_auth_server_seenTimes);
                $smtp_auth_server_Data_graph->setPoints('#auth', FALSE);
                $smtp_auth_server_Data_graph->setSize("590", "450");
                $smtp_auth_server_Data_graph->setXLabel($this->i18n->get("[[base-mailsitestats.authentication_flow_server]]"));
                $statsBlock->addFormField(
                    $smtp_auth_server_Data_graph,
                    "",
                    $Global_SMTPAuth);                    
            }

            //@//
            //@//-- 'Top Senders' Tab:
            //@//

            if ((isset($SAR['topsender'])) && (isset($SAR['topsender']['domain'])) && (isset($SAR['topsender']['relay']))  && (isset($SAR['topsender']['email']))) {

                $topdomain = '';
                $top = 0;

                arsort($SAR['topsender']['domain']);
                arsort($SAR['topsender']['relay']);
                arsort($SAR['topsender']['email']);

                $i = '0';
                $top_sender_relay_Data[$this->i18n->get("[[base-mailsitestats.other]]")] = '0';
                foreach ($SAR['topsender']['relay'] as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = '<>';
                        $SAR['topsender']['relay']['<>'] = $value;
                        unset($SAR['topsender']['relay'][$key]);
                    }
                    $t_relay[$key] = $value;
                    // Collect data for pieChart, but only the first three are of interest.
                    // Rest goes into 'Other' anyway:
                    if ($i < 3) {
                        $top_sender_relay_Data[$key] = $value;
                    }
                    else {
                        $top_sender_relay_Data[$this->i18n->get("[[base-mailsitestats.other]]")] += $value;
                    }
                    $i++;
                }

                arsort($top_sender_relay_Data);
                if (count($top_sender_relay_Data) > 1) {
                    // Generate Pie Chart:
                    $top_sender_relay_pieChart = $factory->getPieChart("top_sender_relay_pieChart", $top_sender_relay_Data);
                    $top_sender_relay_pieChart->setSize("590", "450");
                    $top_sender_relay_pieChart->setXLabel($this->i18n->get("[[base-mailsitestats.top_sender_relay]]"));
                    $statsBlock->addFormField(
                        $top_sender_relay_pieChart,
                        "",
                        $Top_Senders);
                }

                //
                //--- 'Senders Statistics (top 100)' tables:
                //

                $top_sender_domains_data = array();
                $top_sender_domains_num = 0;
                foreach ($SAR['topsender']['domain'] as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = htmlspecialchars('<>');
                    }
                    $top_sender_domains_data[0][$top_sender_domains_num] = $key;
                    $top_sender_domains_data[1][$top_sender_domains_num] = $value;
                    $top_sender_domains_num++;
                }

                $top_sender_domain = $this->i18n->get("[[base-mailsitestats.top_sender_domain]]");

                $top_sender_domains_table = $factory->getScrollList("top_sender_domains_table", array($top_sender_domain, "#"), $top_sender_domains_data); 
                $top_sender_domains_table->setAlignments(array("left", "left"));
                $top_sender_domains_table->setDefaultSortedIndex('0');
                $top_sender_domains_table->setSortOrder('ascending');
                $top_sender_domains_table->setPaginateDisabled(TRUE);
                $top_sender_domains_table->setSearchDisabled(TRUE);
                $top_sender_domains_table->setSelectorDisabled(FALSE);
                $top_sender_domains_table->enableAutoWidth(FALSE);
                $top_sender_domains_table->setInfoDisabled(TRUE);
                $top_sender_domains_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $top_sender_domains_table->setCurrentLabel("[[base-mailsitestats.top_sender_domains]]");
                $top_sender_domains_table->setDescription("[[base-mailsitestats.top_sender_domains]]");

                // Out with the top_sender_domains_table:
                $xxx = $factory->getRawHTML("top_sender_domains", $top_sender_domains_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_sender_domains"),
                    $Top_Senders
                );

                $top_sender_relays_data = array();
                $top_sender_relays_num = 0;
                foreach ($SAR['topsender']['relay'] as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = htmlspecialchars('<>');
                    }
                    $top_sender_relays_data[0][$top_sender_relays_num] = $key;
                    $top_sender_relays_data[1][$top_sender_relays_num] = $value;
                    $top_sender_relays_num++;
                }

                $top_sender_relay = $this->i18n->get("[[base-mailsitestats.top_sender_relay]]");

                $top_sender_relays_table = $factory->getScrollList("top_sender_relays_table", array($top_sender_relay, "#"), $top_sender_relays_data); 
                $top_sender_relays_table->setAlignments(array("left", "left"));
                $top_sender_relays_table->setDefaultSortedIndex('0');
                $top_sender_relays_table->setSortOrder('ascending');
                $top_sender_relays_table->setPaginateDisabled(TRUE);
                $top_sender_relays_table->setSearchDisabled(TRUE);
                $top_sender_relays_table->setSelectorDisabled(FALSE);
                $top_sender_relays_table->enableAutoWidth(FALSE);
                $top_sender_relays_table->setInfoDisabled(TRUE);
                $top_sender_relays_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $top_sender_relays_table->setCurrentLabel("[[base-mailsitestats.top_sender_relays]]");
                $top_sender_relays_table->setDescription("[[base-mailsitestats.top_sender_relays]]");

                // Out with the top_sender_domains_table:
                $xxx = $factory->getRawHTML("top_sender_relays", $top_sender_relays_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_sender_relays"),
                    $Top_Senders
                );
                
                $top_sender_addresses_data = array();
                $top_sender_addresses_num = 0;
                foreach ($SAR['topsender']['email'] as $key => $value) {
                    $top_sender_addresses_data[0][$top_sender_addresses_num] = $key;
                    $top_sender_addresses_data[1][$top_sender_addresses_num] = $value;
                    $top_sender_addresses_num++;
                }

                $top_sender_address = $this->i18n->get("[[base-mailsitestats.top_sender_address]]");

                $top_sender_addresses_table = $factory->getScrollList("top_sender_addresses_table", array($top_sender_address, "#"), $top_sender_addresses_data); 
                $top_sender_addresses_table->setAlignments(array("left", "left"));
                $top_sender_addresses_table->setDefaultSortedIndex('0');
                $top_sender_addresses_table->setSortOrder('ascending');
                $top_sender_addresses_table->setPaginateDisabled(TRUE);
                $top_sender_addresses_table->setSearchDisabled(TRUE);
                $top_sender_addresses_table->setSelectorDisabled(FALSE);
                $top_sender_addresses_table->enableAutoWidth(FALSE);
                $top_sender_addresses_table->setInfoDisabled(TRUE);
                $top_sender_addresses_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $top_sender_addresses_table->setCurrentLabel("[[base-mailsitestats.top_sender_addresses]]");
                $top_sender_addresses_table->setDescription("[[base-mailsitestats.top_sender_addresses]]");

                // Out with the top_sender_addresses_table:
                $xxx = $factory->getRawHTML("top_sender_addresses", $top_sender_addresses_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_sender_addresses"),
                    $Top_Senders
                );
            }
            else {
                $xxx = $factory->getRawHTML("top_sender_addresses", $no_data);
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_sender_addresses"),
                    $Top_Senders
                );
            }

            //@//
            //@//-- 'Top Recipients' Tab:
            //@//

            if ((isset($SAR['toprcpt'])) && (isset($SAR['toprcpt']['domain'])) && (isset($SAR['toprcpt']['relay']))  && (isset($SAR['toprcpt']['email']))) {

                $topdomain = '';
                $top = 0;

                arsort($SAR['toprcpt']['domain']);
                arsort($SAR['toprcpt']['relay']);
                arsort($SAR['toprcpt']['email']);

                $i = '0';
                $top_recipient_relay_Data[$this->i18n->get("[[base-mailsitestats.other]]")] = '0';
                foreach ($SAR['toprcpt']['relay'] as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = htmlspecialchars('<>');
                        $SAR['toprcpt']['relay'][$key] = $value;
                        unset($SAR['toprcpt']['relay'][$key]);
                    }
                    $t_relay[$key] = $value;
                    // Collect data for pieChart, but only the first three are of interest.
                    // Rest goes into 'Other' anyway:
                    if ($i < 3) {
                        $top_recipient_relay_Data[$key] = $value;
                    }
                    else {
                        $top_recipient_relay_Data[$this->i18n->get("[[base-mailsitestats.other]]")] += $value;
                    }
                    $i++;
                }
                arsort($top_recipient_relay_Data);

                // Generate Pie Chart:
                if (count($top_recipient_relay_Data) > 1) {
                    $top_recipient_relay_pieChart = $factory->getPieChart("top_recipient_relay_pieChart", $top_recipient_relay_Data);
                    $top_recipient_relay_pieChart->setSize("590", "450");
                    $top_recipient_relay_pieChart->setXLabel($this->i18n->get("[[base-mailsitestats.top_recipient relay]]"));
                    $statsBlock->addFormField(
                        $top_recipient_relay_pieChart,
                        "",
                        $Top_Recipients);
                }

                //
                //--- 'Senders Statistics (top 100)' tables:
                //

                $top_recipient_domains_data = array();
                $top_recipient_domains_num = 0;
                foreach ($SAR['toprcpt']['domain'] as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = htmlspecialchars('<>');
                    }
                    $top_recipient_domains_data[0][$top_recipient_domains_num] = $key;
                    $top_recipient_domains_data[1][$top_recipient_domains_num] = $value;
                    $top_recipient_domains_num++;
                }

                $top_recipient_domain = $this->i18n->get("[[base-mailsitestats.top_recipient_domain]]");

                $top_recipient_domains_table = $factory->getScrollList("top_recipient_domains_table", array($top_recipient_domain, "#"), $top_recipient_domains_data); 
                $top_recipient_domains_table->setAlignments(array("left", "left"));
                $top_recipient_domains_table->setDefaultSortedIndex('0');
                $top_recipient_domains_table->setSortOrder('ascending');
                $top_recipient_domains_table->setPaginateDisabled(TRUE);
                $top_recipient_domains_table->setSearchDisabled(TRUE);
                $top_recipient_domains_table->setSelectorDisabled(FALSE);
                $top_recipient_domains_table->enableAutoWidth(FALSE);
                $top_recipient_domains_table->setInfoDisabled(TRUE);
                $top_recipient_domains_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $top_recipient_domains_table->setCurrentLabel("[[base-mailsitestats.top_recipient_domains]]");
                $top_recipient_domains_table->setDescription("[[base-mailsitestats.top_recipient_domains]]");

                // Out with the top_sender_addresses_table:
                $xxx = $factory->getRawHTML("top_recipient_domains", $top_recipient_domains_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_recipient_domains"),
                    $Top_Recipients
                );

                $top_recipient_relay_data = array();
                $top_recipient_relay_num = 0;
                foreach ($SAR['toprcpt']['relay'] as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = htmlspecialchars('<>');
                    }
                    $top_recipient_relay_data[0][$top_recipient_relay_num] = $key;
                    $top_recipient_relay_data[1][$top_recipient_relay_num] = $value;
                    $top_recipient_relay_num++;
                }

                $top_recipient_relay = $this->i18n->get("[[base-mailsitestats.top_recipient_relay]]");

                $top_recipient_relays_table = $factory->getScrollList("top_recipient_relays_table", array($top_recipient_relay, "#"), $top_recipient_relay_data); 
                $top_recipient_relays_table->setAlignments(array("left", "left"));
                $top_recipient_relays_table->setDefaultSortedIndex('0');
                $top_recipient_relays_table->setSortOrder('ascending');
                $top_recipient_relays_table->setPaginateDisabled(TRUE);
                $top_recipient_relays_table->setSearchDisabled(TRUE);
                $top_recipient_relays_table->setSelectorDisabled(FALSE);
                $top_recipient_relays_table->enableAutoWidth(FALSE);
                $top_recipient_relays_table->setInfoDisabled(TRUE);
                $top_recipient_relays_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $top_recipient_relays_table->setCurrentLabel("[[base-mailsitestats.top_recipient_relays]]");
                $top_recipient_relays_table->setDescription("[[base-mailsitestats.top_recipient_relays]]");

                // Out with the top_recipient_relays_table:
                $xxx = $factory->getRawHTML("top_recipient_relays", $top_recipient_relays_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_recipient_relays"),
                    $Top_Recipients
                );

                $top_recipient_addresses_data = array();
                $top_recipient_addresses_num = 0;
                foreach ($SAR['toprcpt']['email'] as $key => $value) {
                    $top_recipient_addresses_data[0][$top_recipient_addresses_num] = $key;
                    $top_recipient_addresses_data[1][$top_recipient_addresses_num] = $value;
                    $top_recipient_addresses_num++;
                }

                $top_recipient_address = $this->i18n->get("[[base-mailsitestats.top_recipient_address]]");

                $top_recipient_addresses_table = $factory->getScrollList("top_recipient_addresses_table", array($top_recipient_address, "#"), $top_recipient_addresses_data); 
                $top_recipient_addresses_table->setAlignments(array("left", "left"));
                $top_recipient_addresses_table->setDefaultSortedIndex('0');
                $top_recipient_addresses_table->setSortOrder('ascending');
                $top_recipient_addresses_table->setPaginateDisabled(TRUE);
                $top_recipient_addresses_table->setSearchDisabled(TRUE);
                $top_recipient_addresses_table->setSelectorDisabled(FALSE);
                $top_recipient_addresses_table->enableAutoWidth(FALSE);
                $top_recipient_addresses_table->setInfoDisabled(TRUE);
                $top_recipient_addresses_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $top_recipient_addresses_table->setCurrentLabel("[[base-mailsitestats.top_recipient_addresses]]");
                $top_recipient_addresses_table->setDescription("[[base-mailsitestats.top_recipient_addresses]]");

                // Out with the top_recipient_addresses_table:
                $xxx = $factory->getRawHTML("top_recipient_addresses", $top_recipient_addresses_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_recipient_addresses"),
                    $Top_Recipients
                );
            }
            else {
                // No data:
                $top_recipients_nodata = $factory->getRawHTML("top_recipients_nodata", '<p>' . $no_data . '</p>');
                $statsBlock->addFormField(
                        $top_recipients_nodata,
                        $factory->getLabel("top_recipients_nodata"),
                        $Top_Recipients
                );
            }

            //@//
            //@//-- 'Top Spamming' Tab:
            //@//

            $spam_ids = array(
                    'rule' => $this->i18n->get("[[base-mailsitestats.lbl_top_spam_rules]]"),
                    'domain' => $this->i18n->get("[[base-mailsitestats.lbl_top_spammers_domain]]"), 
                    'sender_relay' => $this->i18n->get("[[base-mailsitestats.lbl_top_spammers_relay]]"), 
                    'sender' => $this->i18n->get("[[base-mailsitestats.lbl_top_spammers_address]]"), 
                    'rcpt' => $this->i18n->get("[[base-mailsitestats.lbl_top_recipients_address]]"), 
                    );

            $spam_ids_bare = array(
                    'rule' => 'lbl_top_spam_rules',
                    'domain' => 'lbl_top_spammers_domain', 
                    'sender_relay' => 'lbl_top_spammers_relay', 
                    'sender' => 'lbl_top_spammers_address', 
                    'rcpt' => 'lbl_top_recipients_address', 
                    );

            if ((isset($SAR['topspam'])) && (count($SAR['topspam']) > 0)) {

                foreach ($SAR['topspam'] as $key => $value) {
                    arsort($SAR['topspam'][$key]);
                }
                foreach ($spam_ids as $key => $label) {

                    $spam_statistics_data = array();
                    $spam_statistics_num = 0;
                    foreach ($SAR['topspam'][$key] as $tkey => $tvalue) {
                        if ((preg_match('/_empty_/', $tkey)) || ($tkey === '')) {
                            $tkey = htmlspecialchars('<>');
                        }
                        $spam_statistics_data[0][$spam_statistics_num] = $tkey;
                        $spam_statistics_data[1][$spam_statistics_num] = $tvalue;
                        $spam_statistics_num++;
                    }

                    $key_18n = $spam_ids_bare[$key];
                    $table_name = $key_18n . '_table';

                    $$table_name = $factory->getScrollList($table_name, array($label, "#"), $spam_statistics_data); 
                    $$table_name->setAlignments(array("left", "left"));
                    $$table_name->setDefaultSortedIndex('0');
                    $$table_name->setSortOrder('ascending');
                    $$table_name->setPaginateDisabled(TRUE);
                    $$table_name->setSearchDisabled(TRUE);
                    $$table_name->setSelectorDisabled(FALSE);
                    $$table_name->enableAutoWidth(FALSE);
                    $$table_name->setInfoDisabled(TRUE);
                    $$table_name->setColumnWidths(array("75%", "25%")); // Max: 739px
                    $$table_name->setCurrentLabel("[[base-mailsitestats.$key_18n]]");
                    $$table_name->setDescription("[[base-mailsitestats.$key_18n]]");

                    // Out with the top_recipient_addresses_table:
                    $xxx = $factory->getRawHTML($key_18n, $$table_name->toHtml());
                    $statsBlock->addFormField(
                        $xxx,
                        $factory->getLabel($key_18n),
                        $Top_Spamming
                    );
                }
            }
            else {
                // No data:
                $top_spam_nodata = $factory->getRawHTML("top_spam_nodata", '<p>' . $no_data . '</p>');
                $statsBlock->addFormField(
                        $top_spam_nodata,
                        $factory->getLabel("top_spam_nodata"),
                        $Top_Spamming
                );
            }

            //@//
            //@//-- 'Top Virus' Tab:
            //@//

            $tvirus_nodata = $factory->getRawHTML("tvirus_nodata", '<p>' . $no_data . '</p>');
            $statsBlock->addFormField(
                    $tvirus_nodata,
                    $factory->getLabel("tvirus_nodata"),
                    $Top_Virus
                    );

            //@//
            //@//-- 'Top Notification' Tab:
            //@//

            $dsn_ids = array(
                    'dsnstatus' => $this->i18n->get("[[base-mailsitestats.lbl_top_dsn_status]]"),
                    'sender' => $this->i18n->get("[[base-mailsitestats.lbl_top_dsn_senders]]"), 
                    'relay' => $this->i18n->get("[[base-mailsitestats.lbl_top_dsn_relays]]"), 
                    'rcpt' => $this->i18n->get("[[base-mailsitestats.lbl_top_dsn_recipients]]")
                    );

            $dsn_ids_bare = array(
                    'dsnstatus' => 'lbl_top_dsn_status',
                    'sender' => 'lbl_top_dsn_senders', 
                    'relay' => 'lbl_top_dsn_relays', 
                    'rcpt' => 'lbl_top_dsn_recipients'
                    );

            if ((isset($SAR['topdsn'])) && (count($SAR['topdsn']) > 0)) {

                foreach ($SAR['topdsn'] as $key => $value) {
                    arsort($SAR['topdsn'][$key]);
                }
                foreach ($dsn_ids as $key => $label) {

                    $dsn_statistics_data = array();
                    $dsn_statistics_num = 0;
                    foreach ($SAR['topdsn'][$key] as $tkey => $tvalue) {
                        if ((preg_match('/_empty_/', $tkey)) || ($tkey === '')) {
                            $tkey = htmlspecialchars('<>');
                        }
                        $dsn_statistics_data[0][$dsn_statistics_num] = $tkey;
                        $dsn_statistics_data[1][$dsn_statistics_num] = $tvalue;
                        $dsn_statistics_num++;
                    }

                    $key_18n = $dsn_ids_bare[$key];
                    $table_name = $key_18n . '_table';

                    $$table_name = $factory->getScrollList($table_name, array($label, "#"), $dsn_statistics_data); 
                    $$table_name->setAlignments(array("left", "left"));
                    $$table_name->setDefaultSortedIndex('0');
                    $$table_name->setSortOrder('ascending');
                    $$table_name->setPaginateDisabled(TRUE);
                    $$table_name->setSearchDisabled(TRUE);
                    $$table_name->setSelectorDisabled(FALSE);
                    $$table_name->enableAutoWidth(FALSE);
                    $$table_name->setInfoDisabled(TRUE);
                    $$table_name->setColumnWidths(array("75%", "25%")); // Max: 739px
                    $$table_name->setCurrentLabel("[[base-mailsitestats.$key_18n]]");
                    $$table_name->setDescription("[[base-mailsitestats.$key_18n]]");

                    // Out with the top_recipient_addresses_table:
                    $xxx = $factory->getRawHTML($key_18n, $$table_name->toHtml());
                    $statsBlock->addFormField(
                        $xxx,
                        $factory->getLabel($key_18n),
                        $Top_Notification
                    );
                }
            }
            else {
                // No data:
                $top_dsn_nodata = $factory->getRawHTML("top_dsn_nodata", '<p>' . $no_data . '</p>');
                $statsBlock->addFormField(
                        $top_dsn_nodata,
                        $factory->getLabel("top_dsn_nodata"),
                        $Top_Notification
                );
            }

            //@//
            //@//-- 'Top Rejection' Tab:
            //@//

            // DNSBL:

            if (isset($SAR['topspamdetail']['dnsbl']['rule'])) {
                $top_rbl_data = array();
                $top_rbl_num = 0;
                foreach ($SAR['topspamdetail']['dnsbl']['rule'] as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = '<>';
                    }
                    $top_rbl_data[0][$top_rbl_num] = $key;
                    $top_rbl_data[1][$top_rbl_num] = $value;
                    $top_rbl_num++;
                }

                $rbl_header = $this->i18n->get("[[base-email.blackList]]");

                $top_rbl_statistics_table = $factory->getScrollList("top_rbl_statistics_table", array($rbl_header, "#"), $top_rbl_data); 
                $top_rbl_statistics_table->setAlignments(array("left", "left"));
                $top_rbl_statistics_table->setDefaultSortedIndex('0');
                $top_rbl_statistics_table->setSortOrder('ascending');
                $top_rbl_statistics_table->setPaginateDisabled(TRUE);
                $top_rbl_statistics_table->setSearchDisabled(TRUE);
                $top_rbl_statistics_table->setSelectorDisabled(FALSE);
                $top_rbl_statistics_table->enableAutoWidth(FALSE);
                $top_rbl_statistics_table->setInfoDisabled(TRUE);
                $top_rbl_statistics_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $top_rbl_statistics_table->setCurrentLabel("[[base-email.blackList]]");
                $top_rbl_statistics_table->setDescription("[[base-email.blackList]]");

                // Out with the top_rbl_statistics_table:
                $xxx = $factory->getRawHTML("top_rbl_statistics_table", $top_rbl_statistics_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_rbl_statistics_table"),
                    $Top_Rejection
                );
            }

            // Anything else:

            $reject_ids = array(
                    'rule' => $this->i18n->get("[[base-mailsitestats.lbl_top_rules]]"),
                    'domain' => $this->i18n->get("[[base-mailsitestats.lbl_top_domains]]"), 
                    'relay' => $this->i18n->get("[[base-mailsitestats.lbl_top_relays]]"), 
                    'sender' => $this->i18n->get("[[base-mailsitestats.lbl_top_senders]]"),
                    'chck_status' => $this->i18n->get("[[base-mailsitestats.lbl_top_status]]")
                    );

            $reject_ids_bare = array(
                    'rule' => 'lbl_top_rules',
                    'domain' => 'lbl_top_domains', 
                    'relay' => 'lbl_top_relays', 
                    'sender' => 'lbl_top_senders',
                    'chck_status' => 'lbl_top_status'
                    );

            $top_reject_nodata_shown = 0;

            if ((isset($SAR['topreject'])) && (count($SAR['topreject']) > 0)) {

                foreach ($SAR['topreject'] as $key => $value) {
                    arsort($SAR['topreject'][$key]);
                }
                foreach ($reject_ids as $key => $label) {

                    $reject_statistics_data = array();
                    $reject_statistics_num = 0;
                    foreach ($SAR['topreject'][$key] as $tkey => $tvalue) {
                        if ((preg_match('/_empty_/', $tkey)) || ($tkey === '')) {
                            $tkey = htmlspecialchars('<>');
                        }
                        if (!empty($tvalue)) {
                            $reject_statistics_data[0][$reject_statistics_num] = $tkey;
                            $reject_statistics_data[1][$reject_statistics_num] = $tvalue;
                            $reject_statistics_num++;
                            $top_reject_nodata_shown++;
                        }
                    }

                    $key_18n = $reject_ids_bare[$key];
                    $table_name = $key_18n . '_table';

                    $$table_name = $factory->getScrollList($table_name, array($label, "#"), $reject_statistics_data); 
                    $$table_name->setAlignments(array("left", "left"));
                    $$table_name->setDefaultSortedIndex('0');
                    $$table_name->setSortOrder('ascending');
                    $$table_name->setPaginateDisabled(TRUE);
                    $$table_name->setSearchDisabled(TRUE);
                    $$table_name->setSelectorDisabled(FALSE);
                    $$table_name->enableAutoWidth(FALSE);
                    $$table_name->setInfoDisabled(TRUE);
                    $$table_name->setColumnWidths(array("75%", "25%")); // Max: 739px
                    $$table_name->setCurrentLabel("[[base-mailsitestats.$key_18n]]");
                    $$table_name->setDescription("[[base-mailsitestats.$key_18n]]");

                    $have_data_one = count($reject_statistics_data);

                    if ($have_data_one > 0) {
                        // Out with the top_recipient_addresses_table:
                        $xxx = $factory->getRawHTML($key_18n, $$table_name->toHtml());
                        $statsBlock->addFormField(
                            $xxx,
                            $factory->getLabel($key_18n),
                            $Top_Rejection
                        );
                    }
                }
            }

            // Now add 'toperr' as well:
            $have_data_two = 0;
            if ((isset($SAR['toperr'])) && (count($SAR['toperr']) > 0)) {
                arsort($SAR['toperr']);

                $toperr_statistics_data = array();
                $toperr_statistics_num = 0;

                foreach ($SAR['toperr'] as $tkey => $tvalue) {
                    if ((preg_match('/_empty_/', $tkey)) || ($tkey === '')) {
                        $tkey = '<>';
                    }
                    if (!empty($tvalue)) {
                        $toperr_statistics_data[0][$toperr_statistics_num] = $tkey;
                        $toperr_statistics_data[1][$toperr_statistics_num] = $tvalue;
                        $toperr_statistics_num++;
                        $top_reject_nodata_shown++;
                    }
                }

                $toperr_statistics_address = $this->i18n->get("[[base-mailsitestats.system_messages]]");

                $toperr_statistics_table = $factory->getScrollList("toperr_statistics_table", array($toperr_statistics_address, "#"), $toperr_statistics_data); 
                $toperr_statistics_table->setAlignments(array("left", "left"));
                $toperr_statistics_table->setDefaultSortedIndex('0');
                $toperr_statistics_table->setSortOrder('ascending');
                $toperr_statistics_table->setPaginateDisabled(TRUE);
                $toperr_statistics_table->setSearchDisabled(TRUE);
                $toperr_statistics_table->setSelectorDisabled(FALSE);
                $toperr_statistics_table->enableAutoWidth(FALSE);
                $toperr_statistics_table->setInfoDisabled(TRUE);
                $toperr_statistics_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                $toperr_statistics_table->setCurrentLabel("[[base-mailsitestats.system_messages]]");
                $toperr_statistics_table->setDescription("[[base-mailsitestats.system_messages]]");

                $have_data_two = count($toperr_statistics_data);

                if ($have_data_two > 0) {
                    // Out with the top_recipient_addresses_table:
                    $xxx = $factory->getRawHTML("toperr_statistics_table", $toperr_statistics_table->toHtml());
                    $statsBlock->addFormField(
                        $xxx,
                        $factory->getLabel("toperr_statistics_table"),
                        $Top_Rejection
                    );
                }
            }

            if (($have_data_one === 0) && ($have_data_two === 0)) {
                // No data:
                $top_reject_nodata_system = $factory->getRawHTML("top_reject_nodata_system", '<p>' . $no_data . '</p>');
                $statsBlock->addFormField(
                        $top_reject_nodata_system,
                        $factory->getLabel("top_reject_nodata_system"),
                        $Top_Rejection
                );
            }

            //@//
            //@//-- 'Top SMTP-Auth' Tab:
            //@//

            $top_smtpAuth_ids = array(
                    'mech' => $this->i18n->get("[[base-mailsitestats.lbl_top_mechanism]]"),
                    'relay' => $this->i18n->get("[[base-mailsitestats.lbl_top_relay]]"), 
                    'authid' => $this->i18n->get("[[base-mailsitestats.lbl_top_AUTHID]]") 
                    );

            $top_smtpAuth_ids_bare = array(
                    'mech' => 'lbl_top_mechanism',
                    'relay' => 'lbl_top_relay', 
                    'authid' => 'lbl_top_AUTHID'
                    );

            if ((isset($SAR['topauth'])) && (count($SAR['topauth']) > 0)) {
                $top_dsn_nodata_shown = '1';

                foreach ($SAR['topauth'] as $key => $value) {
                    arsort($SAR['topauth'][$key]);
                }
                foreach ($top_smtpAuth_ids as $key => $label) {

                    $top_smtpAuth_data = array();
                    $top_smtpAuth_num = 0;
                    foreach ($SAR['topauth'][$key] as $tkey => $tvalue) {
                        if ((preg_match('/_empty_/', $tkey)) || ($tkey === '')) {
                            $tkey = htmlspecialchars('<>');
                        }
                        $top_smtpAuth_data[0][$top_smtpAuth_num] = $tkey;
                        $top_smtpAuth_data[1][$top_smtpAuth_num] = $tvalue;
                        $top_smtpAuth_num++;
                    }

                    $key_18n = $top_smtpAuth_ids_bare[$key];
                    $table_name = $key_18n . '_table';

                    $$table_name = $factory->getScrollList($table_name, array($label, "#"), $top_smtpAuth_data); 
                    $$table_name->setAlignments(array("left", "left"));
                    $$table_name->setDefaultSortedIndex('0');
                    $$table_name->setSortOrder('ascending');
                    $$table_name->setPaginateDisabled(TRUE);
                    $$table_name->setSearchDisabled(TRUE);
                    $$table_name->setSelectorDisabled(FALSE);
                    $$table_name->enableAutoWidth(FALSE);
                    $$table_name->setInfoDisabled(TRUE);
                    $$table_name->setColumnWidths(array("50%", "50%")); // Max: 739px
                    $$table_name->setCurrentLabel("[[base-mailsitestats.$key_18n]]");
                    $$table_name->setDescription("[[base-mailsitestats.$key_18n]]");

                    // Out with the top_recipient_addresses_table:
                    $xxx = $factory->getRawHTML($key_18n, $$table_name->toHtml());
                    $statsBlock->addFormField(
                        $xxx,
                        $factory->getLabel($key_18n),
                        $Top_SMTPAuth
                    );
                }
            }
            else {
                // No data:
                $topauth_nodata_system = $factory->getRawHTML("topauth_nodata_system", '<p>' . $no_data . '</p>');
                $statsBlock->addFormField(
                        $topauth_nodata_system,
                        $factory->getLabel("topauth_nodata_system"),
                        $Top_SMTPAuth
                );
            }

            //@//
            //@//-- 'SpamAssassin' Tab:
            //@//

            $top_spam_ids = array(
                    'rule' => $this->i18n->get("[[base-mailsitestats.lbl_topspam_spams]]"),
                    'score' => $this->i18n->get("[[base-mailsitestats.lbl_topspam_scores]]"), 
                    'autolearn' => $this->i18n->get("[[base-mailsitestats.lbl_topspam_autolearnstats]]")
                    );

            $top_spam_ids_bare = array(
                    'rule' => 'lbl_topspam_spams',
                    'score' => 'lbl_topspam_scores', 
                    'autolearn' => 'lbl_topspam_autolearnstats'
                    );

            $individual_rules = array();

            if ((isset($SAR['topspamdetail'])) && (isset($SAR['topspamdetail']['spamdmilter']))) {
                foreach ($SAR['topspamdetail']['spamdmilter'] as $key => $value) {
                    if ($key == 'score') {
                        krsort($SAR['topspamdetail']['spamdmilter'][$key]);
                    }
                    else {
                        arsort($SAR['topspamdetail']['spamdmilter'][$key]);
                    }
                }
                foreach ($top_spam_ids as $key => $label) {

                    //
                    //--- Tables:
                    //

                    $topspam_statistics_table_data = array();
                    $spam_cnt = 0;

                    foreach ($SAR['topspamdetail']['spamdmilter'][$key] as $tkey => $tvalue) {

                        if ((preg_match('/_empty_/', $tkey)) || ($tkey === '')) {
                            $tkey = '<>';
                        }
                        $tkey = preg_replace('/\\\n\\\t/', '', $tkey);
                        $tkey = preg_replace('/autolearn=[no|spam|yes]/', '', $tkey);
                        if ($key == 'rule') {

                            // While we are here, we also break it down into statistics for individual rules
                            // that got fired. This needs some computing, but will be worthwhile:
                            $tmprule = explode(',', $tkey);
                            foreach ($tmprule as $tmpkey => $tmpvalue) {
                                $tmpvalue = preg_replace('/\\\n\\\t/', '', $tmpvalue);
                                $tmpvalue = preg_replace('/autolearn=[no|spam|yes]/', '', $tmpvalue);
                                if ($tmpvalue != '') {
                                    if (!isset($individual_rules[$tmpvalue])) {
                                        $individual_rules[$tmpvalue] = '0';
                                    }
                                    (int) $individual_rules[$tmpvalue] += (int) $tvalue;
                                }
                            }
                        }

                        // Real:
                        $topspam_statistics_table_data[0][$spam_cnt] = $tkey;
                        $topspam_statistics_table_data[1][$spam_cnt] = $tvalue;
                        $spam_cnt++;
                    }

                    $key_18n = $top_spam_ids_bare[$key];
                    $table_name = $key_18n . '_table';

                    $lbl_topspam_spams = $this->i18n->get("[[base-mailsitestats.lbl_topspam_spams]]");

                    $topspam_statistics_table = $factory->getScrollList("topspam_statistics_table", array($label, "#"), $topspam_statistics_table_data); 
                    $topspam_statistics_table->setAlignments(array("left", "left"));
                    $topspam_statistics_table->setDefaultSortedIndex('0');
                    $topspam_statistics_table->setSortOrder('ascending');
                    $topspam_statistics_table->setPaginateDisabled(TRUE);
                    $topspam_statistics_table->setSearchDisabled(TRUE);
                    $topspam_statistics_table->setSelectorDisabled(FALSE);
                    $topspam_statistics_table->enableAutoWidth(FALSE);
                    $topspam_statistics_table->setInfoDisabled(TRUE);
                    $topspam_statistics_table->setColumnWidths(array("75%", "25%")); // Max: 739px
                    $topspam_statistics_table->setCurrentLabel("[[base-mailsitestats.$key_18n]]");
                    $topspam_statistics_table->setDescription("[[base-mailsitestats.$key_18n]]");

                    // Out with the topspam_statistics_table_:
                    $xxx = $factory->getRawHTML("topspam_statistics_table_", $topspam_statistics_table->toHtml());
                    $statsBlock->addFormField(
                        $xxx,
                        $factory->getLabel("topspam_statistics_table_"),
                        $AV_SPAMdMilter
                    );

                }

                //
                //-- Detailed SPAM rule table:
                //

                arsort($individual_rules);
                $topspamrule_data = array();
                $topspamrule_num = 0;
                foreach ($individual_rules as $key => $value) {
                    if ((preg_match('/_empty_/', $key)) || ($key === '')) {
                        $key = '<>';
                    }
                    $topspamrule_data[0][$topspamrule_num] = $key;
                    $topspamrule_data[1][$topspamrule_num] = $value;
                    $topspamrule_num++;
                }

                $top_individual_spam_rules = $this->i18n->get("[[base-mailsitestats.top_individual_spam_rules]]");

                $topspam_statistics_table = $factory->getScrollList("topspamrule_statistics_table", array($top_individual_spam_rules, "#"), $topspamrule_data); 
                $topspam_statistics_table->setAlignments(array("left", "left"));
                $topspam_statistics_table->setDefaultSortedIndex('0');
                $topspam_statistics_table->setSortOrder('ascending');
                $topspam_statistics_table->setPaginateDisabled(TRUE);
                $topspam_statistics_table->setSearchDisabled(TRUE);
                $topspam_statistics_table->setSelectorDisabled(FALSE);
                $topspam_statistics_table->enableAutoWidth(FALSE);
                $topspam_statistics_table->setInfoDisabled(TRUE);
                $topspam_statistics_table->setColumnWidths(array("50%", "50%")); // Max: 739px
                $topspam_statistics_table->setCurrentLabel("[[base-mailsitestats.top_individual_spam_rules]]");
                $topspam_statistics_table->setDescription("[[base-mailsitestats.top_individual_spam_rules]]");

                // Out with the topspam_statistics_table:
                $xxx = $factory->getRawHTML("top_individual_spam_rules", $topspam_statistics_table->toHtml());
                $statsBlock->addFormField(
                    $xxx,
                    $factory->getLabel("top_individual_spam_rules"),
                    $AV_SPAMdMilter
                );
            }
            else {
                // No data:
                $topspam_nodata_system = $factory->getRawHTML('topspam_nodata_system', '<p>' . $no_data . '</p>', 'r');
                $statsBlock->addFormField(
                        $topspam_nodata_system,
                        $factory->getLabel("topspam_nodata_system"),
                        $AV_SPAMdMilter
                );
            }
        }

        //--- Finalize the page:
        $page_body[] = $block->toHtml();

        //
        //--- AutoFeatures (via Extensions 'Email.Stats'): 
        //

        // Figure out which services are available
        list($vsiteServices) = $CI->cceClient->find('VsiteServices');
        $autoFeatures = new AutoFeatures($CI->serverScriptHelper);
        $EmailStats_ID = 'EmailStats';
        $EmailStats = $factory->getPagedBlock("EmailStats", array($EmailStats_ID));

        // add all generic enabled/disabled type services detected above
        $autoFeatures->display($EmailStats, 'Email.Stats', 
                array(
                    'CCE_SERVICES_OID' => $vsiteServices,
                    'PAGE_ID' => $EmailStats_ID,
                    'GROUP' => $group,
                    'YEAR' => $this->getYear(),
                    'MONTH' => $this->getMonth(),
                    'DAY' => $this->getDay(),
                    'CAN_ADD_PAGE' => TRUE
                    ));

        // Only print anything if AutoFeatures has added FormFields to $EmailStats.
        // Because if there are no FormFields in it, then there were no AutoFeatures.
        if (isset($EmailStats->formFields)) {
            if (count($EmailStats->formFields) > "0") {
                $page_body[] = "<p>&nbsp;</p>\n";
                $page_body[] = $EmailStats->toHtml();
            }
        }

        //
        //--- End AutoFeatures
        //

        $page_body[] = "<p>&nbsp;</p>\n";
        if (isset($SAR['GLOBAL_STATUS'])) {
            $page_body[] = $statsBlock->toHtml(). "\n";
        }
        else {

            $no_data_Block = $factory->getPagedBlock("mailusageDescription", array($defaultPage));
            $no_data_Block->setToggle("#");
            $no_data_Block->setShowAllTabs("#");
            $no_data_Block->setSideTabs(FALSE);

                // No data:
                $no_data_FF = $factory->getRawHTML("zno_data", '<p>' . $no_data . '</p>');
                $no_data_Block->addFormField(
                    $no_data_FF,
                    $factory->getLabel("zno_data"),
                    $defaultPage
                );

            $page_body[] = $no_data_Block->toHtml();
        }
                    
        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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