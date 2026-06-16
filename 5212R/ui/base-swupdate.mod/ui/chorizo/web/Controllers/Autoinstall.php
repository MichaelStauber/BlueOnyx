<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Autoinstall extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/autoinstall");
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

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        // -- Actual page logic start:

        //
        //--- Load autolib.php, which is part of Compass-base to check the linked username:
        //

        $email_access_field_rw = "rw";
        if (file_exists('/usr/sausalito/ui/chorizo/ci4/Modules/Compass/Base/Controllers/Autolib.php')) {
            include_once("/usr/sausalito/ui/chorizo/ci4/Modules/Compass/Base/Controllers/Autolib.php");
            $SerialNumber = $System["serialNumber"];
            $setter_return = '';
            if ($SerialNumber == "") {
                if (is_file('/usr/sausalito/sbin/get_serial.pl')) {
                    $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/get_serial.pl", $setter_return, 'root', $BX_SESSION['sessionId']);
                }
            }
            // Refetch 'System' Object directly, bypassing cache:
            $System = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
            $shopEmail = get_nl_username($System['serialNumber']);
            $email_access_field_rw = "r";
        }
        else {
            $shopEmail = '';
            if (isset($get_form_data['em'])) {
                $shopEmail = urldecode($get_form_data['em']);
            }
        }

        // Set 1Y cookie for the shopEmail:
        setcookie("shopemail", $shopEmail, time()+60*10*24*365, "/");

        // ShopEmail doesn't match. Redirect to the right URL:
        if (isset($get_form_data['em'])) {
            if ($get_form_data['em'] != $shopEmail) {
                // Install the NewLinQ PKG and come back here once that's done:
                header('Location: /swupdate/autoinstall?em=' . urlencode($shopEmail));
            }
        }

        //
        //--- Get CODB-Object of interest: 
        //

        // Get settings
        $swUpdate = $CI->cceClient->get($System['OID'], "SWUpdate");

        //
        //--- Check if the NewLinQ PKG is installed. If not, install it:
        //
        $BasePKG = $CI->cceClient->getObject("Package", array("name" => 'base', 'vendor' => 'Compass'));
        if (!isset($BasePKG['OID'])) {
            // NewLinQ PKG not installed! We refresh the list of available updates first:
            $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/grab_updates.pl -u", $result, 'root', $BX_SESSION['sessionId']);
            // Now find out what OID NewLinQ has:
            $BasePKG = $CI->cceClient->getObject("Package", array("name" => 'base', 'vendor' => 'Compass', 'installState' => 'Available'));

            // Set 30 minute 'ai' cookie:
            setcookie("ai", '1', time()+60*30, "/");
        }
        // Check again:
        $BasePKG = $CI->cceClient->getObject("Package", array("name" => 'base', 'vendor' => 'Compass'));
        if (isset($BasePKG['installState'])) {
            if ($BasePKG['installState'] == "Available") {
                // We do a little round-about to install the PKG
                $backUrl = '/swupdate/autoinstall?ai=true';
                if (isset($get_form_data['em'])) {
                    $backUrl .= '&em=' . urlencode($shopEmail);
                }

                // Install the NewLinQ PKG and come back here once that's done:
                $redirect_URL = '/swupdate/download?backUrl=' . $backUrl . '&packageOID=' . $BasePKG['OID'];
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }
        if (isset($BasePKG['installState'])) {
            if ($BasePKG['installState'] == "Installed") {
                // Delete old 'ai' cookie if present:
                delete_cookie("ai");

                // Set new 30 minute 'ai' cookie:
                setcookie("ai", '2', time()+60*30, "/");
            }
        }

        // Double check email-address:
        if (!filter_var($shopEmail, FILTER_VALIDATE_EMAIL)) {
            // No valid Email-Address. Reset access rights to 'rw':
            $email_access_field_rw = "rw";
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

        if ($this->request->getPost(NULL, NULL, TRUE)) {

        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {
            if ((isset($attributes['ShopEmail'])) && (isset($attributes['ShopPass']))) {
                // Next step: Link the packages:
                $redirect_URL = "/base/link";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }

        //
        //-- Own page logic:
        //

        if (!empty($shopEmail)) {
            $redirect_URL = "/swupdate/newSoftware";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/base/link");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('base_autoinstall');
        $page_module = 'base_software';

        $defaultPage = "basic";

        $block = $factory->getPagedBlock("AutoInstallPKGheader", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        //
        //--- Basic:
        //

        // Shop-Email:
        $shopemailField = $factory->getTextField("ShopEmail", $shopEmail, $email_access_field_rw);
        $shopemailField->setOptional (FALSE);
        $shopemailField->setType ('email');
        $block->addFormField(
          $shopemailField,
          $factory->getLabel("ShopEmail"),
          "basic"
        );

        // Shop-Password
        $shopPassField = $factory->getPassword("ShopPass", "", FALSE, 'rw');
        $shopPassField->setOptional(FALSE);
        $shopPassField->setConfirm(FALSE);
        $shopPassField->setCheckPass(FALSE);
        $block->addFormField(
            $shopPassField,
            $factory->getLabel("ShopPass"),
            "basic");

        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));

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