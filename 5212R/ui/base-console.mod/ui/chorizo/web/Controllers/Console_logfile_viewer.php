<?php 
namespace Console\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Console_logfile_viewer extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-console", "/console/console_logfile_viewer");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-console", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //--- Handle GET requests:
        //

        if ($BX_SESSION['gui_theme'] === 'adminica') {
            $num_of_log_lines_to_parse = 200;
        }
        else {
            $num_of_log_lines_to_parse = 5000;
        }

        if ($this->request->getGet(NULL, NULL, TRUE)) {
            // Has getGet request:
            $get_form_data = $BxPage->FORM_GET;

            $logfile_choices = array(
                            "1" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/cron",
                            "2" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/maillog",
                            "3" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/messages",
                            "4" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/secure",
                            "5" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/httpd/access_log",
                            "6" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/httpd/error_log",
                            "7" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/admserv/adm_access",
                            "8" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/admserv/adm_error",
                            "9" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/letsencrypt/letsencrypt.log",
                            "10" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/nginx/access.log",
                            "11" => "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/nginx/error.log",
                        );

            $need_to_mod_date = array('5', '6', '7', '8');

            // Build selector:
            $logfile = "/usr/bin/tail -$num_of_log_lines_to_parse /var/log/messages";
            if ((isset($get_form_data['type'])) && (isset($logfile_choices[$get_form_data['type']]))) {
                $logfile = $logfile_choices[$get_form_data['type']];
            }
            else {
                // This is not what we're looking for! Stop poking around!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#FU");
            }
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_console_logfiles');
        $BxPage->setVerticalMenuChild('base_console_logfiles');
        $page_module = 'base_sysmanage';

        //
        //-- A bit more security doesn't hurt. Especially before we pass through shell commands!
        //

        // Build array with allowed shell commands:
        $allowed_execs = array_values($logfile_choices);

        // Check if command we wanna pass through is one of the few allowed ones 
        // specified in the array values of $logfile_choices:
        if (in_array($logfile, $allowed_execs)) {
            // It is, so do the exec():
            if (is_file('/etc/DEMO')) {
                $output = "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= "\n";
                $output .= $i18n->getHtml("[[palette.detail]]") . ': ' . $i18n->getHtml("[[palette.demo_mode_short]]") . ' ' . $i18n->getHtml("[[palette.enabled_short]]");
                $output .= "\n=====================\n";
                $output .= "\n\n" . $i18n->getHtml("[[palette.403text]]");
            }
            else {
                $ret = $CI->serverScriptHelper->shell("$logfile", $output, 'root', $CI->BX_SESSION['sessionId']);
            }

            if ($output != '') {
                $output = explode("\n", $output);
            }
            else {
                $output = array();
            }
        }
        else {
            // It is not? Go away, you fine Sir!
            Log403Error("/gui/Forbidden403#FU2");
        }

        if ($BX_SESSION['gui_theme'] === 'adminica') {
            $out = "<pre>";
            foreach($output as $outputline) {
                $out .= formspecialchars($outputline) . "\n";
            }
            $out .= "</pre>";
        }
        else {

            // Get URL-Params:
            $start = $this->request->getGet('start') ?? 0;
            $length = $this->request->getGet('length') ?? 10;
            $searchValue = $this->request->getGet('search')['value'] ?? '';

            // Remove empty entries
            $log_output = array_filter($output, function($entry) {
                return !empty($entry);
            });

            // Filter based on search value
            if (!empty($searchValue)) {
                $log_output = array_filter($log_output, function($line) use ($searchValue) {
                    return stripos($line, $searchValue) !== false;
                });
            }

            $log_output = array_reverse($log_output);

            // Get total records after search filter
            $recordsFiltered = count($log_output);

            // Slice array for pagination
            $log_output = array_slice($log_output, $start, $length);

            $recordsTotal = count($log_output);

            if ($recordsTotal == 0) {
                // Logfile is empty. Show an info text instead:
                $log_output = array($i18n->get('[[palette.emptyList]]'));
            }

            if (in_array($get_form_data['type'], $need_to_mod_date)) {
                $log_output = array_map(function($line) {
                    // Find and extract the date string
                    preg_match("/\[(.*?)\]/", $line, $matches);
                    $date = $matches[0] ?? '';

                    // Remove the date string from the original position
                    $lineWithoutDate = str_replace($date, '', $line);

                    // Reconstruct the line with the date string at the beginning
                    return $date . ' ' . $lineWithoutDate;
                }, $log_output);
            }

            // Prefix 'log_entry' as key for all logfile lines:
            $processed_data = array_map(function($entry) {
                return ['log_entry' => $entry];
            }, $log_output);

            $out_data = array(  'draw' => $this->request->getGet('draw'),
                                'recordsTotal' => $recordsTotal,
                                'recordsFiltered' => $recordsFiltered,
                                'data' => $processed_data
                             );

            $json_output = json_encode($out_data);

            return $this->response->setJSON($json_output);
        }

        // Page body:
        $page_body[] = $out;

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Out with the page:
        $BxPage->setOutOfStyle(TRUE);
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