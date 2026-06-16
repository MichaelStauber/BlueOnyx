<?php
namespace System\Controllers;
use App\Controllers\BaseController;
include_once ("I18n.php");
include_once ("BxPage.php");
include_once ("Product.php");
use I18n;
use BxPage;
use Product;

class Sysinfo extends BaseController {
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

        if (!$CI->getAllowed('serverInformation')) {
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
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-system", "/system/sysinfo");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array(
            'FORM_GET' => $this->request->getGet() ,
            'FORM_POST' => $this->request->getPost() ,
            'AGENT' => $this->request->getUserAgent()
        ));

        //
        //-- Re-Use $errors array from Session data:
        //
        $errors = $BxPage->getErrors();

        //
        //-- Prepare data:
        //
        //
        //--- Get CODB-Objects of interest:
        //
        // refresh information
        $unique = time();
        $CI->cceClient->set($System['OID'], "Memory", array(
            "refresh" => $unique
        ));

        $product = new Product($CI->cceClient);

        if (!$product->isRaq()) {
            $CI->cceClient->set($System['OID'], "Disk", array(
                "refresh" => $unique
            ));
        }

        // get objects
        $SystemDisk = $CI->cceClient->get($System['OID'], "Disk");
        $SystemMemory = $CI->cceClient->get($System['OID'], "Memory");

        $devices = find_eth_ifaces();
        $primary_interface = get_primary_interface();
        $codb_primary_interface = $primary_interface;

        $eth0 = $CI->cceClient->getObject("Network", array(
            "device" => "$primary_interface"
        ));

        if (isset($devices[1])) {
            $eth1_name = $devices[1];
            $eth1 = $CI->cceClient->getObject("Network", array(
                "device" => "$eth1_name"
            ));
        }

        //
        //-- Generate page:
        //
        // Prepare Page:
        $BxPage->setFormUrl("/system/sysinfo");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_serverconfig');
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("systemInformation", array(
            $defaultPage
        ));

        $block->setToggle("#");
        $block->setSideTabs(false);
        $block->setDefaultPage($defaultPage);

        if ($System["productName"] != "") {
            $xxx = $factory->getTextField("productNameField", $System["productName"], "r");
            $block->addFormField($xxx, $factory->getLabel("productNameField"));
        }

        if ($System['productBuild']) {
            $xxx = $factory->getTextField("productBuildField", $System["productBuild"], "r");
            $block->addFormField($xxx, $factory->getLabel("productBuildField"));
        }

        // System may contain the literal "Uninitialized"
        $formattedSerial = $System["productSerialNumber"];
        if ($formattedSerial != "") {
            if ($formattedSerial == 'Uninitialized') {
                $formattedSerial = $i18n->get("serialUninitialized");
            }

            $xxx = $factory->getTextField("productSerialNumberField", $formattedSerial, "r");
            $block->addFormField($xxx, $factory->getLabel("productSerialNumberField"));
        }

        if ($System["serialNumber"] != "") {
            $xxx = $factory->getTextField("serialNumberField", $System["serialNumber"], "r");
            $block->addFormField($xxx, $factory->getLabel("serialNumberField"));
        }

        if (isset($eth0["mac"])) {
            if ($eth0["mac"] != "") {
                $xxx = $factory->getMacAddress("mac0Field", $eth0["mac"], "r");
                $block->addFormField($xxx, $factory->getLabel("mac0Field"));
            }
        }

        if (isset($eth1["mac"])) {
            if ($eth1["mac"] != "") {
                $xxx = $factory->getMacAddress("mac1Field", $eth1["mac"], "r");
                $block->addFormField($xxx, $factory->getLabel("mac1Field"));
            }
        }

        // convert to GB
        if (isset($SystemDisk["disk1Total"])) {
            $diskTotal = round($SystemDisk["disk1Total"] * 10 / 1024 / 1024) / 10;
            if ($diskTotal != 0) {
                $xxx = $factory->getInteger("diskField", $diskTotal, "", "", "r");
                $block->addFormField($xxx, $factory->getLabel("diskField"));
            }
        }

        if ($SystemMemory["physicalMemTotal"] != "") {
            $xxx = $factory->getInteger("memoryField", $SystemMemory["physicalMemTotal"], "", "", "r");
            $block->addFormField($xxx, $factory->getLabel("memoryField"));
        }

        // Add Button-Link for the www.BlueOnyx.it website:
        $webLink = $factory->getButton($i18n->get("webLink") , "webLinkText");
        $webLink->setTarget("_blank");
        $webLink->setIcon('fa fa-external-link');
        $buttonContainer = $factory->getButtonContainer("", array(
            $webLink
        ));

        $page_body[] = $block->toHtml();
        $page_body[] = $buttonContainer->toHtml();

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
