<?php 
namespace Console\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Console_logfiles extends BaseController {
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
        $timer = timer();
        if (!$CI->getAllowed('serverConfig')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Setup clean $errors array:
        //

        $errors = array();

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-console", "/console/console_logfiles");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-console", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Handle form data:
        //

        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_security');
        $BxPage->setVerticalMenuChild('base_console_logfiles');
        $page_module = 'base_sysmanage';

        //
        //--- Basic Tab
        //

        if ($BX_SESSION['gui_theme'] === 'adminica') {
            $a_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=1", '/var/log/cron', "DEMO-OVERRIDE");
            $b_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=2", '/var/log/maillog', "DEMO-OVERRIDE");
            $c_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=3", '/var/log/messages', "DEMO-OVERRIDE");
            $d_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=4", '/var/log/secure', "DEMO-OVERRIDE");
            $buttonContainer_a = $factory->getButtonContainer("", array($a_button, $b_button, $c_button, $d_button));

            $e_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=5", '/var/log/httpd/access_log', "DEMO-OVERRIDE");
            $f_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=6", '/var/log/httpd/error_log', "DEMO-OVERRIDE");
            $buttonContainer_b = $factory->getButtonContainer("", array($e_button, $f_button));

            $g_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=7", '/var/log/admserv/adm_access', "DEMO-OVERRIDE");
            $h_button = $factory->getFancyTextButton("/console/console_logfile_viewer?type=8", '/var/log/admserv/adm_error', "DEMO-OVERRIDE");
            $buttonContainer_c = $factory->getButtonContainer("", array($g_button, $h_button));

            $page_body[] = $buttonContainer_a->toHtml();
            $page_body[] = $buttonContainer_b->toHtml();
            $page_body[] = $buttonContainer_c->toHtml();
        }
        else {

            //--- Datatable stuff:

            $defaultPage = 'pageID';
            $block = $factory->getPagedBlock("logfilesMenu", array($defaultPage));

            $block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setShowAllTabs('#');
            $block->setDefaultPage($defaultPage);

            //
            //--- Elmer related pulldown definitions:
            //

            $elmer_pulldown_list = array();

            if (isset($get_form_data['logfile'])) {
                $chosen_logfile_id = $get_form_data['logfile'];
            }
            else {
                $chosen_logfile_id = 2;
            }

            if (isset($get_form_data['refresh'])) {
                $chosen_refresh = $get_form_data['refresh'];

                // Check if the string is numeric and consists only of digits (thus an integer)
                if (is_numeric($chosen_refresh) && ctype_digit((string) $chosen_refresh)) {
                    // Convert the string to an integer
                    $chosen_refresh = (int) $chosen_refresh;
                }
                else {
                    // Handle the case where it's not an integer
                    $chosen_refresh = 5;
                }
            }
            else {
                $chosen_refresh = 5;
            }

            if ($chosen_refresh < 1) {
                $chosen_refresh = 1;
            }

            if ($chosen_refresh > 1800) {
                $chosen_refresh = 1800;
            }

            // Array of labels => actions for chosing logfiles:
            $AvailableLogfilesArray = array(
                        "/var/log/cron" => "/console/console_logfiles?logfile=1&refresh=$chosen_refresh",
                        "/var/log/maillog" => "/console/console_logfiles?logfile=2&refresh=$chosen_refresh",
                        "/var/log/messages" => "/console/console_logfiles?logfile=3&refresh=$chosen_refresh",
                        "/var/log/secure" => "/console/console_logfiles?logfile=4&refresh=$chosen_refresh",
                        "/var/log/httpd/access_log" => "/console/console_logfiles?logfile=5&refresh=$chosen_refresh",
                        "/var/log/httpd/error_log" => "/console/console_logfiles?logfile=6&refresh=$chosen_refresh",
                        "/var/log/admserv/adm_access" => "/console/console_logfiles?logfile=7&refresh=$chosen_refresh",
                        "/var/log/admserv/adm_error" => "/console/console_logfiles?logfile=8&refresh=$chosen_refresh",
                        "/var/log/letsencrypt/letsencrypt.log" => "/console/console_logfiles?logfile=9&refresh=$chosen_refresh",
                        "/var/log/nginx/access.log" => "/console/console_logfiles?logfile=10&refresh=$chosen_refresh",
                        "/var/log/nginx/error.log" => "/console/console_logfiles?logfile=11&refresh=$chosen_refresh",
                    );

            $LogFileButton = $factory->getMultiButton("chosen_logfile", array_values($AvailableLogfilesArray), array_keys($AvailableLogfilesArray));
            $selected_logfile_id = $chosen_logfile_id - 1;
            $LogFileButton->setSelectedIndex($selected_logfile_id);
            $LogFileButton->setText('');
            $elmer_pulldown_list[] = $LogFileButton;

            $seconds_string = $i18n->getHtml("[[palette.page_render_part_two]]");

            // Array of labels => actions for chosing a refresh:
            $AvailableRefreshArray = array(
                        "1 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=1",
                        "2 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=2",
                        "3 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=3",
                        "5 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=5",
                        "10 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=10",
                        "15 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=15",
                        "20 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=20",
                        "25 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=25",
                        "30 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=30",
                        "60 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=60",
                        "90 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=90",
                        "120 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=120",
                        "180 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=180",
                        "240 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=240",
                        "300 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=300",
                        "600 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=600",
                        "900 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=900",
                        "1800 $seconds_string" => "/console/console_logfiles?logfile=$chosen_logfile_id&refresh=1800",
                    );

            $ReversedRefreshArray = array(
                "1" => "0",
                "2" => "1",
                "3" => "2",
                "5" => "3",
                "10" => "4",
                "15" => "5",
                "20" => "6",
                "25" => "7",
                "30" => "8",
                "60" => "9",
                "90" => "10",
                "120" => "11",
                "180" => "12",
                "240" => "13",
                "300" => "14",
                "600" => "15",
                "900" => "16",
                "1800" => "17",
            );

            $RefreshButton = $factory->getMultiButton("chosen_refresh", array_values($AvailableRefreshArray), array_keys($AvailableRefreshArray));
            $RefreshButton->setSelectedIndex($ReversedRefreshArray[$chosen_refresh]);
            $RefreshButton->setText('');
            $elmer_pulldown_list[] = $RefreshButton;

            $pulldown_list = $factory->getCompositeFormField($elmer_pulldown_list, '');
            $pulldown_list->setColumnWidths(array('col_25', 'col_25', 'col_25'));
            $pulldown_list->setClass('pb-20');

            $block->addFormField(
                $pulldown_list,
                $factory->getLabel(" "),
                $defaultPage
            );

            //
            //--- ScrollList:
            //

            $scrollList = $factory->getScrollList("logTable", array("log_entry"), array()); 
            $scrollList->setAlignments(array("left"));
            $scrollList->setDefaultSortedIndex('0');
            $scrollList->setSortOrder('ascending');
            $scrollList->setSortDisabled(array('0'));
            $scrollList->setPaginateDisabled(FALSE);
            $scrollList->setSearchDisabled(FALSE);
            $scrollList->setSelectorDisabled(FALSE);
            $scrollList->enableAutoWidth(FALSE);
            $scrollList->setInfoDisabled(FALSE);
            $scrollList->setAjax('log_entry', "/console/console_logfile_viewer?type=$chosen_logfile_id", $chosen_refresh);
            $scrollList->setColumnWidths(array("100%")); // Max: 739px

            // Push out the Scrollist:
            $xxx = $factory->getRawHTML("logTable", $scrollList->toHtml());
            $block->addFormField(
                $xxx,
                $factory->getLabel("logTable"),
                $defaultPage
            );

            // Assemble page body:
            $page_body[] = $block->toHtml();
        }

        //-- Rest:

        // Pass on errors:
        $BxPage->setErrors($errors);

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