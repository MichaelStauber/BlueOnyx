<?php 
namespace Ddns\Controllers;
use App\Controllers\BaseController;
include_once('ServerScriptHelper.php');
include_once("I18n.php");
include_once("BxPage.php");
use ServerScriptHelper;
use I18n;
use BxPage;

class Ddnsapi extends BaseController {
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

        // Force HTTPS:
        if (is_HTTPS() == FALSE) {
            $redirect_URL = 'https://' . $_SERVER['SERVER_NAME'] . ':81/ddns/ddnsapi';
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        $accessip = $_SERVER['REMOTE_ADDR'];

        // Get URL strings:
        $get_form_data = $this->request->getGet();

        //
        //--- Handle GET requests:
        //

        if ($this->request->getGet(NULL, NULL, TRUE)) {
            if ((isset($get_form_data['pw'])) && (isset($get_form_data['un']))) {

                $SSH = $CI->getSSH();
                $SSH->debug_log("Ddnsapi: Login request from IP $accessip with userName '" . $get_form_data['un'] . "'");

                $sessionId = '';
                if (($get_form_data['un'] != '') && ($get_form_data['pw'] != '')) {
                    $sessionId = $CI->cceClient->auth($get_form_data['un'], $get_form_data['pw']);
                    $SSH->debug_log("Ddnsapi: Login resulted in sessionId: " . $sessionId);
                }

                if ($sessionId != '') {
                    // Authenticate:
                    $CI->cceClient->authkey($get_form_data['un'], $sessionId);                

                    // Get 'User' Object and 'Shell' NameSpace:
                    $loginUser = $CI->cceClient->get($CI->cceClient->whoami());
                    $userShell = $CI->cceClient->get($loginUser['OID'], 'Shell');

                    // Store BX_SESSION and initialize Session-Data:
                    $CI->setBX_SESSION($get_form_data['un'], $sessionId, $loginUser, $userShell['enabled']);
                    $CI->setUserLogged($sessionId);

                    // Force ServerScriptHelper to use the new credentials:
                    $CI->serverScriptHelper = new ServerScriptHelper($sessionId, $get_form_data['un']);
                    $CI->setSSH($CI->serverScriptHelper);
                    $CI->cceClient = $CI->serverScriptHelper->getCceClient();
                }
            }
        }

        //
        //--- Handle POST Request (do no longe work! CSRF protection!):
        //

        //if ($this->request->getPost(NULL, NULL, TRUE)) {
        //    // Has getPost request:
        //    $form_data = $this->request->getPost();
        //
        //    if ((isset($form_data['pw'])) && (isset($form_data['un']))) {
        //
        //        $sessionId = '';
        //        if (($form_data['un'] != '') && ($form_data['pw'] != '')) {
        //            $sessionId = $CI->cceClient->auth($form_data['un'], $form_data['pw']);
        //            //$SSH->debug_log("Ddnsapi: Login resulted in sessionId: " . $sessionId);
        //        }
        //
        //        if ($sessionId != '') {
        //            // Authenticate:
        //            $CI->cceClient->authkey($form_data['un'], $sessionId);
        //
        //            // Get 'User' Object and 'Shell' NameSpace:
        //            $loginUser = $CI->cceClient->get($CI->cceClient->whoami());
        //            $userShell = $CI->cceClient->get($loginUser['OID'], 'Shell');
        //
        //            // Store BX_SESSION and initialize Session-Data:
        //            $CI->setBX_SESSION($form_data['un'], $sessionId, $loginUser, $userShell['enabled']);
        //            $CI->setUserLogged($sessionId);
        //
        //            // Force ServerScriptHelper to use the new credentials:
        //            $CI->serverScriptHelper = new ServerScriptHelper($sessionId, $form_data['un']);
        //            $CI->setSSH($CI->serverScriptHelper);
        //            $CI->cceClient = $CI->serverScriptHelper->getCceClient();
        //        }
        //    }
        //}

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $DDNS = $CI->cceClient->get($System['OID'], "DDNS");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ddns", "/ddns/ddnsapi");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-ddns", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Access control check:
        //

        if (((isset($BX_SESSION['loginUser']['systemAdministrator'])) && ($BX_SESSION['loginUser']['systemAdministrator'] === '0')) || ($BX_SESSION['sessionId'] === '')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_ddns');
        $BxPage->setOutOfStyle(TRUE);
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("ddns_header", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        //
        //--- Formfields:
        //

        // Get CODB data:
        $DDNS = $CI->cceClient->get($System['OID'], "DDNS");

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("ddns_header", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        //--- Perform DNS Record updates:

        if ($DDNS['ddns_enabled'] == '1') {

            if (!$accessip) {
                $accessip = '127.0.0.1';
            }

            $update_domains = $CI->cceClient->scalar_to_array($DDNS['ddns_domains']);

            $need_daemon_restart = '0';
            foreach ($update_domains as $key => $DN) {
                $DNSoids = array();
                $DNSoids = $CI->cceClient->find("DnsSOA", array('domainname' => $DN));
                if ($DNSoids[0]) {
                    $DNS_records = $CI->cceClient->find("DnsRecord", array('domainname' => $DN, 'type' => 'A'));
                    foreach ($DNS_records as $key => $DR) {
                        $current_record = $CI->cceClient->get($DR);
                        if ($current_record['ipaddr'] != $accessip) {
                            $CI->cceClient->set($DR, '',  array('ipaddr' => $accessip));
                            $need_daemon_restart++;
                        }
                    }
                }
            }
            if ($need_daemon_restart > '0') {
                $CI->cceClient->set($System['OID'], 'DNS',  array('commit' => time()));
            }

            $shorty = '|DNSOK| ';
            $msg = $i18n->get("dns_update_success");

        }
        else {
            $shorty = '|DNSFAIL| ';
            $msg = $i18n->get("dns_update_disabled");
        }

        $ff_status = $factory->getTextField("Status", $shorty . $msg . " $accessip", 'r');
        $block->addFormField(
                $ff_status,
                $factory->getLabel("Status"),
                $defaultPage
            );

        // Get DNS Records:
        $DNS_SOAs = array();
        $DNSoids = $CI->cceClient->find("DnsSOA");

        foreach ($DNSoids as $key => $oid) {
            $rec = $CI->cceClient->get($oid, '');
            if ($rec['domainname'] != '') {
                $DNS_SOAs[$oid] = $rec['domainname'];
            }
        }

        // Sort the SOAs:
        asort($DNS_SOAs);
        $dval = $CI->cceClient->array_to_scalar(array_values($DNS_SOAs));

        // Sort the DDNS domains:
        $selval = $CI->cceClient->scalar_to_array($DDNS['ddns_domains']);
        asort($selval);
        $DDNS['ddns_domains'] = $CI->cceClient->array_to_scalar(array_values($selval));  

        // Build selector:
        $select_dnsRecords = $factory->getSetSelector('ddns_domains',
                $DDNS['ddns_domains'], 
                $dval,
                'allowedAbilities', 'disallowedAbilities',
                'r', 
                $DDNS['ddns_domains'],
                $dval
            );
        $select_dnsRecords->setOptional(true);

        // Out with selector:
        $block->addFormField($select_dnsRecords, 
                $factory->getLabel('ddns_domains'),
                $defaultPage
            );

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }
}

/*
Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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