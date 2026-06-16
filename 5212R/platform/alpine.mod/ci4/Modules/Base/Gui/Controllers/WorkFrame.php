<?php 
namespace Gui\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("System.php");
use I18n;
use System;

class WorkFrame extends BaseController {
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

    /**
     * Index Page for this controller.
     *
     * Past the login page this loads the page for /gui/workFrame, which reads the CCE Replay-File,
     * performs one transaction and reloads itself (showing a progress bar) until all transactions are done.
     * Once all transactions are done, it frame-breaks and goes to a return-URL.
     *
     */

    public function index() {

        $CI =& get_instance();
        init_libraries();

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        //
        //-- Setup clean $errors array:
        //

        $errors = array();

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/gui/workFrame");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-swupdate", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        // Not 'manageSite'? Bye, bye!
        if (!$CI->getAllowed('manageSite')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }

        // -- Actual page logic start:

        // Get URL params:
        $get_form_data = $this->request->getGet();

        if (!isset($get_form_data['statusId'])) {
            // Nice people say goodbye, or CCEd waits forever:
            $this->cceClient->bye();
            $this->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }
        else {
            $statusId = $get_form_data['statusId'];
        }

        if (!isset($get_form_data['ReplayType'])) {
            $ReplayType = 'step';
        }
        if (isset($get_form_data['ReplayType'])) {
            if ($get_form_data['ReplayType'] == "step") {
                $ReplayType = 'step';
            }
            if ($get_form_data['ReplayType'] == "full") {
                $ReplayType = 'full';
            }
        }

        $current_URL = $_SERVER['REQUEST_URI'];
        $new_cur_URL = preg_replace('/statusId=1/', 'statusId=2', $current_URL);
        $newBackURL = "/network/ethernet";

        // Prepare Page:
        $BxPage->setFormUrl($newBackURL);
        $BxPage->setErrors(array()); // We do have an $errors array set, but intentionally don't use it as we show 'messages' anyway.
        $BxPage->setOutOfStyle(TRUE);

        if (!isset($get_form_data['redirectUrl'])) {
            $redirectUrl = '/network/ethernet';
        }
        else {
            $redirectUrl = $get_form_data['redirectUrl'];
        }

        $known_redirect_types = array('ipv4', 'ipv6', 'hn', 'standard');
        if (!isset($get_form_data['redirectType'])) {
            $redirectType = 'standard';
        }
        else {
            $redirectType = $get_form_data['redirectType'];
        }
        if (!in_array($redirectType, $known_redirect_types)) {
            $redirectType = 'standard';
        }

        //-- Generate page:

        // Set Menu items:
        if ((!isset($get_form_data['VM'])) && (!isset($get_form_data['VMC'])) && (!isset($get_form_data['PM']))) {
            $BxPage->setVerticalMenu('base_serverconfig');
            $BxPage->setVerticalMenuChild('base_ethernet');
            $page_module = 'base_sysmanage';
        }
        else {
            $BxPage->setVerticalMenu($get_form_data['VM']);
            $BxPage->setVerticalMenuChild($get_form_data['VMC']);
            $page_module = $get_form_data['PM'];
        }

        // Spacer at the top:
        $page_body[] = '<div><br></div>';

        // When the title is empty, we use a blank default:
        if (!isset($title)) {
            $title = "[[palette.wait]]";
        }
        $defaultPage = $title;

        //
        //--- Start: Handle CCE-Replay:
        //

        $num_of_trans = $CI->cceClient->replayStatus();
        if (($num_of_trans <= '2') || (!is_int($num_of_trans))) {
            $progress = '85';
        }
        else {
            $num_of_trans++;
            $progress = ceil('100' / $num_of_trans);
        }

        // Perform replay from Replay-File:
        if ($ReplayType == 'step') {
            // Step through it one by one:
            $CI->cceClient->replay("stepByStep");
        }

        if (($ReplayType == 'full') && ($statusId == '2')) {
            // Do the whole shebang all at once:
            $CI->cceClient->replay();
        }

        // If there are no more replays in the file after this, then we insert the header to redirect back to our desired URL:

        // New attempt:
        $servername = $System['hostname'] . '.' . $System['domainname'];
        $http_server_name = $_SERVER['SERVER_NAME'];
        $http_server_name = preg_replace('/\[/', '', $http_server_name);
        $http_server_name = preg_replace('/\]/', '', $http_server_name);

        if ((filter_var($http_server_name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) || (filter_var($http_server_name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
            // We're here by IP:
            $target_hn_or_ip = $http_server_name;
        }
        else {
            $target_hn_or_ip = $servername;
        }

        if (is_HTTPS() == FALSE) {
            $shorty = 'http://' . $target_hn_or_ip . ':444';
            $our_redirect_URL = 'http://' . $target_hn_or_ip . ':444' . $redirectUrl;
        }
        else {
            $shorty = 'https://' . $target_hn_or_ip . ':' . $BX_SESSION['GUI_PORT'];
            $our_redirect_URL = 'https://' . $target_hn_or_ip . ':' . $BX_SESSION['GUI_PORT'] . $redirectUrl;
        }

        bx_error_log("shorty: " . $shorty);
        bx_error_log("our_redirect_URL: " . $our_redirect_URL);

        $num_of_trans = $CI->cceClient->replayStatus();
        if (($num_of_trans <= '0') || (($ReplayType == 'full') && ($statusId == '2'))) {

            // Assemble framebreaker-Script:
            $framebreak = '<script language="JavaScript" type="text/javascript">' . "\n";
            $framebreak .= '<!--' . "\n";
            $framebreak .= '    top.location.href = "' . $our_redirect_URL . '";' . "\n";
            $framebreak .= '-->' . "\n";
            $framebreak .= '</script>' . "\n";
            $BxPage->setExtraHeaders($framebreak);
            $BxPage->setExtraBodyTag('<body onload=top.location.href=\'' . $our_redirect_URL . '\'>');
        }
        elseif (($ReplayType == 'full') && ($statusId == '1')) {
            // If we're done sooner than the meta-equif in ethernetDeploy, then we try to break out of the iframe for an early return:
            $BxPage->setExtraHeaders('<meta http-equiv="refresh" content="0; URL=' . $shorty . $new_cur_URL . '">');
        }
        else {
            // Add a refresh to reload this page:
            $BxPage->setExtraHeaders('<SCRIPT LANGUAGE="javascript">setTimeout("window.location.reload();", 7000);</SCRIPT>');
        }

        //
        //--- End: Handle CCE-Replay
        //

        $block = $factory->getPagedBlock($i18n->getHtml($title), array($defaultPage));

        if (isset($message)) {
            // Make sure the $message is not empty!
            if ($message == "") {
                $message = "[[palette.500text]]";
            }

            $xxx = $factory->getTextField("messageField", $i18n->interpolate($message), "r");
            $block->addFormField(
              $xxx,
              $factory->getLabel("messageField"),
              $defaultPage
            );
        }

        if (isset($progress)) {
            if ($progress != "") {
              $xxx = $factory->getBar("progressField", $progress);
              $block->addFormField(
                $xxx,
                $factory->getLabel("progressField"),
                $defaultPage
              );
            }
        }

        // add sub-status if it is supplied
        if (isset($submessage)) {
            if ($submessage != "") {
              $xxx = $factory->getTextField("submessageField", $i18n->interpolate($submessage), "r");
              $block->addFormField(
                $xxx,
                $factory->getLabel("submessageField"),
                $defaultPage
              );
            }
        }

        if (isset($subprogress)) {
            if($subprogress != "") {
              $xxx = $factory->getBar("subprogressField", $subprogress);
              $block->addFormField(
                $xxx,
                $factory->getLabel("subprogressField"),
                $defaultPage
              );
            }
        }
        //--

        // Stretch the PagedBlock() to a width of 720 pixels:
        $xxx = $factory->getRawHTML("Spacer", '<IMG BORDER="0" WIDTH="720" HEIGHT="0" SRC="/libImage/spaceHolder.gif">');
        $block->addFormField(
            $xxx,
            $factory->getLabel("Spacer"),
            $defaultPage
        );


        // Page body:
        $page_body[] = $block->toHtml();

        // Spacer at the bottom:
        $page_body[] = '<div><br></div>';

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