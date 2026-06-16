<?php 
namespace Subdomains\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Subconfig extends BaseController {
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

        if (!$CI->getAllowed('serverHttpd')) {
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
        $web = $CI->cceClient->get($System['OID'], "subdomains");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-subdomains", "/subdomains/subconfig");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- Handle POST Request:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Has getPost request:
            $form_data = $BxPage->FORM_POST;

            // Form fields that are required to have input:
            $required_keys = array("default_max_subdomains");

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
            //--- Own error checks:
            //

            //
            //--- No errors? Submit to CODB:
            //

            if (count($errors) == "0") {
                // Actual submit to CODB:
                $CI->cceClient->set($System['OID'], "subdomains",  $attributes);

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors);
        }

        //
        //-- Generate page:
        //

        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_sysmanage';
        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("subdomain_header", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // Add divider:
        $xxx = $factory->addBXDivider("default_settings", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("default_settings", false),
                $defaultPage
                );          

        $xxx = $factory->getBoolean("vsite_enabled", $web["vsite_enabled"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("vsite_enabled")
        );

        $default_max_subdomains = $factory->getInteger("default_max_subdomains", $web["default_max_subdomains"], 1, 1000);
        $default_max_subdomains->setWidth(4);
        $default_max_subdomains->showBounds(1);

        $block->addFormField(
            $default_max_subdomains,
            $factory->getLabel("default_max_subdomains")
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/subdomains/subconfig"));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }       
}
/*
Copyright (c) 2008-2024Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2024Team BlueOnyx, BLUEONYX.IT
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