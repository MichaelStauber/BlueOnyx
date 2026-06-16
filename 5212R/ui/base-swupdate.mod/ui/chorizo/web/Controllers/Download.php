<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Download extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/download");
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

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            if (isset($form_data['_serialized_errors'])) {
                $my_errors = safe_deserialize($form_data['_serialized_errors']);
            }
        }

        // Get the return message from the URL string - if present:
        if (isset($get_form_data['msg'])) {
            $errors[] = ErrorMessage($i18n->get(urldecode($get_form_data['msg'])) , 'alert_green', 'info_about');
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/swupdate/license");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('base_softwareNew');
        $page_module = 'base_software';

        $defaultPage = "downloadSoftware";

        $block = $factory->getPagedBlock("downloadSoftware", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        // PKGs only have the locales 'en_US';
        $i18n_EN = new I18n("palette", 'en_US');

        $packageName = $package["nameTag"] ? $i18n_EN->interpolate($package["nameTag"]) : $package["name"];
        if (($packageName === 'name') || ($packageName === 'nameTag')) {
            $packageName = $package["name"];
        }
        $xxx = $factory->getHTMLField("nameField", $packageName, "r");
        $block->addFormField(
          $xxx,
          $factory->getLabel("nameField"),
          $defaultPage
        );

        $version = $package["versionTag"] ? $i18n->interpolate($package["versionTag"]) : substr($package["version"], 1);
        $xxx = $factory->getHTMLField("versionField", $version, "r");
        $block->addFormField(
          $xxx,
          $factory->getLabel("versionField"),
          $defaultPage
        );

        $vendorName = $package["vendorTag"] ? $i18n_EN->interpolate($package["vendorTag"]) : $package["vendor"];
        if (($vendorName === 'vendor') || ($vendorName === 'vendorTag')) {
            $vendorName = $package["vendor"];
            if ($vendorName === 'solarspeed_net') {
                $vendorName = 'Solarspeed.net';
            }
        }
        $xxx = $factory->getHTMLField("vendorField", $vendorName, "r");
        $block->addFormField(
          $xxx,
          $factory->getLabel("vendorField"),
          $defaultPage
        );

        if ($package["copyright"]) {
            $yyy = $i18n_EN->interpolate($package["copyright"]);
            $xxx = $factory->getHTMLField("copyrightField", $yyy, "r");
            $block->addFormField(
                $xxx,
                $factory->getLabel("copyrightField"),
                $defaultPage
            );
        }

        $desc = $package['longDesc'] ? $package['longDesc'] : $package['shortDesc'];
        $xxx = $i18n_EN->interpolate($desc);
        $zzz = $factory->getHTMLField("descriptionField", $xxx, "r");
        $block->addFormField(
          $zzz,
          $factory->getLabel("descriptionField"),
          $defaultPage
        );

        $location = preg_match('/^file:/', $package['location']) ? $i18n_EN->interpolate('[[base-swupdate.locationLocal]]') : $package['location'];
        $xxx = $factory->getHTMLField("locationField", $location, "r");
        $block->addFormField(
          $xxx,
          $factory->getLabel("locationField"),
          $defaultPage
        );

        $size = $package["size"] ? simplify_number($package['size'], "KB", "2") : $i18n_EN->interpolate('[[base-swupdate.unknownSize]]');
        $xxx = $factory->getHTMLField("sizeField", $size . "B", "r");
        $block->addFormField(
          $xxx,
          $factory->getLabel("sizeField"),
          $defaultPage
        );

        if (strstr($package['options'], 'uninstallable')) {
          $uninst = "yes";
        }
        else {
          $uninst = "no";
        }

        $xxx = $factory->getHTMLField("uninstallableField", $i18n->get($uninst), "r");
        $block->addFormField(
          $xxx,
          $factory->getLabel("uninstallableField"),
          $defaultPage
        );

        $dependency = stringToArray($package["visibleList"]);
        if($dependency) {
            $needed = join(', ', $dependency);
            $needed = str_replace(':', ' ', $needed);
        }
        else {
            $needed = $i18n->get('none');
        }

        $xxx = $factory->getHTMLField("packagesNeededField", $needed, "r");
        $block->addFormField(
            $xxx,
            $factory->getLabel("packagesNeededField"),
            $defaultPage
        );

        $xxx = $factory->getTextField("backUrl", $backUrl, "");
        $zzz = $factory->getTextField("packageOID", $packageOID, "");
        $block->addFormField($xxx, $defaultPage);
        $block->addFormField($zzz, $defaultPage);

        $action = "/swupdate/license?" . "packageOID=" . $packageOID . "&backUrl=" . $backUrl;

        // No Package requires a reboot. Seriously! 
        // Therefore we add the normal "Install" button w/o modal:
        $installButtonNoDialog = $factory->getButton($action, "install");
        $installButtonNoDialog->setButtonColor('success');
        $installButtonNoDialog->setIcon("fa fa-wrench");
        $installButtonNoDialog->setButtonSpecialStyle('animated');
        $block->addButton($installButtonNoDialog);

        $block->addButton($factory->getCancelButton($backUrl));

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