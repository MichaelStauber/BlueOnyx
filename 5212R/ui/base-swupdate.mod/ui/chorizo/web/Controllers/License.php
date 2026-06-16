<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class License extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/license");
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

        if ((!isset($get_form_data['packageOID'])) || (!isset($get_form_data['backUrl']))) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $packageOID = $get_form_data['packageOID'];
        $backUrl = $get_form_data['backUrl'];

        //
        //--- Get CODB-Object of interest: 
        //

        $package = $CI->cceClient->get($packageOID);
        $license = $package["licenseDesc"];
        $splash = strstr($package["splashPages"], 'pre-install');

        //
        //-- Own page logic:
        //

        // Redirect if we don't have license info:
        $redirect_URL = "/swupdate/downloadHandler?packageOID=$packageOID&backUrl=$backUrl";
        if (!($license || $splash)) {
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        // We got a splash page.
        // Note: Not sure if this will still work. We don't actually have PKGs with splash pages at
        // the moment. 
        if ($splash) {
            $splashdir = updates_splashdir();
            $stage = 'pre-install';
            $name = updates_splashname($package["vendor"], $package["name"], $package["version"], $stage);
            if (file_exists("$splashdir/$name") && $dhandle = opendir("$splashdir/$name")) {
                while ($file = readdir($dhandle)) {
                    if (strstr($file, 'index.')) {
                        $submit = urlencode($redirect_URL);
                        $redirect_URL = "/$name/?submitURL=$submit&cancelURL=$backUrl";
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                }
                closedir($dhandle);
            }

            if (!$license) {
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }

        // Otherwise, we generate a standard license page:

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/swupdate/newSoftware");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('base_softwareNew');
        $page_module = 'base_software';

        $defaultPage = "licenseField";

        $block = $factory->getPagedBlock("licenseField", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        $xxx = $factory->getTextField("nameField", $package['name'], "");
        $block->addFormField($xxx, $defaultPage);
        $xxx = $factory->getTextField("backUrl", $backUrl, "");
        $block->addFormField($xxx, $defaultPage);
        $xxx = $factory->getTextField("packageOID", $packageOID, "");
        $block->addFormField($xxx, $defaultPage);

        $AcceptButton = $factory->getButton($redirect_URL, "accept");
        $AcceptButton->setIcon("fa fa-check-square");
        $AcceptButton->setButtonColor('success');
        $AcceptButton->setButtonSpecialStyle('animated');

        $block->addButton($AcceptButton);
        $block->addButton($factory->getCancelButton($backUrl, "decline"));

        $stage = 'pre-install';
        updates_prependsrc($license, $package['vendor'], $package['name'], $package['version'], $stage);

        // PKGs only have the locales 'en_US';
        $i18n_EN = new I18n("palette", 'en_US');

        $LICENSE = $i18n_EN->interpolate($license);
        $LICENSE_info_text = $factory->getHtmlField("_", "<br>" . $LICENSE, 'r');
        $LICENSE_info_text->setLabelType("nolabel");
        $block->addFormField(
            $LICENSE_info_text,
            $factory->getLabel(" "),
            $defaultPage
            );

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