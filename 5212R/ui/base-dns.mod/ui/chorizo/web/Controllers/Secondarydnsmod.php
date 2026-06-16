<?php 
namespace Dns\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Secondarydnsmod extends BaseController {
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

        if (!$CI->getAllowed('siteDNS')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-dns", "/dns/secondarydnsmod");
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

        // -- Actual page logic start:

        $iam = '/dns/secondarydnsmod';
        $parent = '/dns/secondarydns';

        //
        //-- Handle form data:
        //

        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_TARGET");

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
            $errors = $BxPage->getErrors();
        }

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // None
        }

        // Find out the TYPE of entry we're dealing with:
        if (isset($get_form_data['TYPE'])) {
            $TYPE = $get_form_data['TYPE'];
        }

        if ((!isset($TYPE)) && (isset($form_data['TYPE']))) {
            $TYPE = $form_data['TYPE'];
        }

        if ((!isset($TYPE)) && (!isset($get_form_data['_RTARGET']))) {
            // We *still* have no $TYPE set? Then you should not be here!
            // Exception: We want to delete an object specified via _RTARGET
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }

        // Check the $_TARGET to see if this is a new entry or if it contains the OID of an object we edit:
        if ((!isset($_TARGET)) && (isset($form_data['_TARGET']))) {
            // We have form data of a $_TARGET OID:
            $_TARGET =  $form_data['_TARGET'];
        }
        else {
            // We don't? Assume it's a new object:
            $_TARGET = "NEW";
        }


        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {
            // We have no errors. We submit to CODB.
            if ($_TARGET == "NEW") {
                // Create a new Object:
                $CI->cceClient->create("DnsSlaveZone", $attributes);
            }
            else {
                // We update an existing Object:
                $CI->cceClient->set($_TARGET, "", $attributes);
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Also commit the changes to restart the DNS server:
            $update['commit'] = time();
            $CI->cceClient->set($System['OID'], "DNS",  $update);

            // No errors during submit? Redirect to previous page:
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $previous_URL = $_SERVER['HTTP_REFERER'];
            }
            else {
                $previous_URL = $_SERVER['REQUEST_URI'];
            }

            if (count($errors) == "0") {
                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, $parent);
            }
            else {
                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, $previous_URL);
            }
        }

        //
        //-- Page Logic:
        //

        $nm_to_dec = array(
          "0.0.0.0"   => "0",
          "128.0.0.0" => "1",   "255.128.0.0" => "9",   "255.255.128.0" => "17",    "255.255.255.128" => "25",
          "192.0.0.0" => "2",   "255.192.0.0" => "10",  "255.255.192.0" => "18",    "255.255.255.192" => "26",
          "224.0.0.0" => "3",   "255.224.0.0" => "11",  "255.255.224.0" => "19",    "255.255.255.224" => "27",
          "240.0.0.0" => "4",   "255.240.0.0" => "12",  "255.255.240.0" => "20",    "255.255.255.240" => "28",
          "248.0.0.0" => "5",   "255.248.0.0" => "13",  "255.255.248.0" => "21",    "255.255.255.248" => "29",
          "252.0.0.0" => "6",   "255.252.0.0" => "14",  "255.255.252.0" => "22",    "255.255.255.252" => "30",
          "254.0.0.0" => "7",   "255.254.0.0" => "15",  "255.255.248.0" => "23",    "255.255.255.254" => "31",
          "255.0.0.0" => "8",   "255.255.0.0" => "16",  "255.255.255.0" => "24",    "255.255.255.255" => "32" );

        // Get the Object in question for edit:
        if ((isset($get_form_data['_LOAD'])) && (isset($get_form_data['_TARGET']))) {
            $_TARGET = $get_form_data['_TARGET'];
            $DnsSlaveZone = $CI->cceClient->get($_TARGET);
        }

        // Get the Object in question for the delete action:
        if (isset($get_form_data['_RTARGET'])) {
            $_RTARGET = $get_form_data['_RTARGET'];
            $DnsSlaveZone = $CI->cceClient->get($_RTARGET);
        }

        if (isset($DnsSlaveZone)) {
            // Verify if it's an DnsSlaveZone Object:
            if ($DnsSlaveZone['CLASS'] != "DnsSlaveZone") { 
                // This is not what we're looking for! Stop poking around!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#3");
            }
            else {

                // Handle the delete action if appropriate:
                if (isset($_RTARGET)) {
                    $CI->cceClient->destroy($_RTARGET);

                    // CCE errors that might have happened during submit to CODB:
                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }

                    // Also commit the changes to restart the DNS server:
                    $update['commit'] = time();
                    $CI->cceClient->setObject("System", $update, "DNS");

                    // Redirect to previous page:
                    $BxPage->ReturnToThisPage($errors, $parent);
                }

                // Pre-populate the formfield strings for presentation:
                if (isset($DnsSlaveZone['ipaddr'])) { 
                    $slave_ipaddr = $DnsSlaveZone['ipaddr'];
                }
                if (isset($DnsSlaveZone['domain'])) { 
                    $slave_domain = $DnsSlaveZone['domain'];
                }
                if (isset($DnsSlaveZone['netmask'])) { 
                    $slave_netmask = $DnsSlaveZone['netmask'];
                }
                if (isset($DnsSlaveZone['masters'])) { 
                    $slave_masters = $DnsSlaveZone['masters'];
                }
            }
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        if ($TYPE == "NETWORK") {
            $url_suffix = "&TYPE=NETWORK";
        }
        if ($TYPE == "FORWARD") {
            $url_suffix = "&TYPE=FORWARD";
        }

        $BxPage->setFormUrl($iam . "?_TARGET=" . $_TARGET . $url_suffix);
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_dns');
        $page_module = 'base_sysmanage';
        $defaultPage = "basic";

        if ($_TARGET == "NEW") {
            $title = "create_slave_rec";
        }
        else {
            $title = "modify_slave_rec";
        }

        $block = $factory->getPagedBlock($title, array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Basic Tab
        //
    
        if ($TYPE == 'NETWORK') {
            // Secondary Network Auth:
            if (!isset($slave_netmask)) { 
                $slave_netmask = '255.255.255.0';
            }
            if (!isset($slave_ipaddr)) { 
                $slave_ipaddr = '';
            }
            if (!isset($slave_masters)) { 
                $slave_masters = '';
            }

            // Slave IP:
            $slave_ip = $factory->getIpAddress('ipaddr', $slave_ipaddr, 'rw');
            $slave_ip->setOptional(FALSE);
            $block->addFormField(
                $slave_ip,
                $factory->getLabel("slave_ipaddr"), 
                $defaultPage
            );

            // Slave Subnet Netmask:
            $slave_nm = $factory->getIpAddress('netmask', $slave_netmask, 'rw');
            $slave_nm->setOptional(FALSE);
            $block->addFormField(
                $slave_nm,
                $factory->getLabel("slave_netmask"), 
                $defaultPage
            );

            // Slave's Master:
            $slave_master = $factory->getIpAddress('masters', $slave_masters, 'rw');
            $slave_master->setOptional(FALSE);
            $block->addFormField(
                $slave_master,
                $factory->getLabel("slave_net_masters"), 
                $defaultPage
            );
        }
        else {

            if (!isset($slave_domain)) { 
                $slave_domain = '';
            }
            if (!isset($slave_masters)) { 
                $slave_masters = '';
            }

            // Slave Domain:
            $slave_ip = $factory->getDomainName('domain', $slave_domain, 'rw');
            $slave_ip->setOptional(FALSE);
            $block->addFormField(
                $slave_ip,
                $factory->getLabel("slave_domain"), 
                $defaultPage
            );

            // Slave's Master:
            $slave_master = $factory->getIpAddress('masters', $slave_masters, 'rw');
            $slave_master->setOptional(FALSE);
            $block->addFormField(
                $slave_master,
                $factory->getLabel("slave_dom_masters"), 
                $defaultPage
            );
        }

        // We silently pass along the OID of the Object:
        $xff = $factory->getTextField('_TARGET', $_TARGET, '');
        $block->addFormField(
            $xff,
            $factory->getLabel("_TARGET"), 
            $defaultPage
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton($parent));

        $page_body[] = $block->toHtml();

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