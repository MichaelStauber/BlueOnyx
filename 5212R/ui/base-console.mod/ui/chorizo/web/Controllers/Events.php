<?php 
namespace Console\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Events extends BaseController {
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
        //--- Get 'fail_hosts' information from CCEWrap:
        //

        $runas = 'root';
        $ret = $CI->serverScriptHelper->shell("/usr/bin/pam_abl -v", $nfk, $runas, $BX_SESSION['sessionId']);
        $hostList = explode(PHP_EOL, $nfk);
        $clean_hostlist = array();
        foreach ($hostList as $key => $value) {
            $value = preg_replace('/\s+/', ';', $value);
            $stripper = array();
            $stripper = explode(';', $value);
            // Remove anything we're not interested in:
            if (($stripper[0] != "Reading") && ($stripper[0] != "No") && ($stripper[0] != "Failed") && ($value != "")) {
                $clean_hostlist[] = $value;
            }
        }

        $RecordedHosts = array();
        $auth_types = array('USER', 'HOST', 'BOTH', 'AUTH');
        foreach ($clean_hostlist as $key => $value) {
            $value = explode(';', $value);
            unset($value[0]);
            if (count($value) != "1") {
                if (count($value) == "2") {
                    // We may have the IP. But check if it is an IP:
                    if (filter_var($value[1], FILTER_VALIDATE_IP)) {
                        $event_IP = $value[1];
                    }
                    else {
                        // $value[1] is not an IP. Could be a hostname? Check it:
                        $resolved_event_ip = @gethostbyname($value[1]);
                        if (filter_var($resolved_event_ip, FILTER_VALIDATE_IP)) {
                            // If we now have an IP, then we use it:
                            $event_IP = $resolved_event_ip;
                        }
                        else {
                            // Still don't have an IP? We give up.
                            $event_IP = "n/a";
                        }
                    }
                    $event_count = $value[2];
                    $RecordedHosts[$event_IP]['failcnt'] = $event_count;
                    $event_num = "1";
                }
                else {
                    $event_service = $value[1];
                    if (in_array($value[2], $auth_types)) {
                        $event_user = "n/a";
                        $event_type = $value[2];
                        unset($value[1]);
                        unset($value[2]);
                    }
                    else {
                        $event_user = $value[2];
                        $event_type = $value[3];
                        unset($value[1]);
                        unset($value[2]);
                        unset($value[3]);
                    }
                    $event_date = implode(" ", $value);
                    $RecordedHosts[$event_IP]['event'][$event_num] = array('event_service' => $event_service, 'event_user' => $event_user, 'event_type' => $event_type, 'event_date' => $event_date);
                    $event_num++;
                }
            }
        }

        //
        //-- Setup clean $errors array:
        //

        $errors = array();

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-console", "/console/events");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

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
            $ignore_attributes = array("BlueOnyx_Info_Text");

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
                // This page has no POST requests to handle
            }
        }

        //
        //--- Handle GET requests:
        //

        if ($this->request->getGet(NULL, NULL, TRUE)) {
            // Has getGet request:
            $get_form_data = $BxPage->FORM_GET;

            // Get query string:
            if (isset($get_form_data['q'])) {
                $query = $get_form_data['q'];
            }
            else {
                // This is not what we're looking for! Stop poking around!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('pam_abl_status');
        $BxPage->setVerticalMenuChild('pam_abl_status');
        $page_module = 'base_sysmanage';

        $defaultPage = "blocked_hosts";
        $header_text = $i18n->get("pam_abl_blocked_users_and_hosts") . " (" . $query . ")";
        $block = $factory->getPagedBlock($header_text, array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        //
        //--- TAB: blocked_hosts
        //

        $scrollList = $factory->getScrollList("pam_abl_blocked_hosts", array("service", "user", "type", "date"), array()); 
        $scrollList->setAlignments(array("center", "center", "center", "center"));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('ascending');
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);
        $scrollList->setColumnWidths(array("184", "184", "184", "184")); // Max: 739px

        if (isset($RecordedHosts[$query])) {
            // Populate host table rows with the data:
            foreach ($RecordedHosts[$query]['event'] as $host => $data) {
                $scrollList->addEntry(array(
                    $data['event_service'],
                    $data['event_user'],
                    $data['event_type'],
                    $data['event_date']
                ));
            }
        }

        $xxx = $factory->getRawHTML("pam_abl_blocked_hosts", $scrollList->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("pam_abl_blocked_hosts"),
            "blocked_hosts"
        );

        // Page body:
        $page_body[] = $block->toHtml();

        // Out with the page:
        $BxPage->setOutOfStyle(TRUE);

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