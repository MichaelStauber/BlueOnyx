<?php 
namespace Phpmyadmin\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Signon extends BaseController {
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

        if (!$CI->getAllowed('validUser')) {
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

        // Make the users fullName safe for all charsets:
        $user['fullName'] = bx_charsetsafe($user['fullName']);

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-phpmyadmin", "/phpmyadmin/signon");
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

        $am_reseller = FALSE;

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

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
        // -- Actual page logic start:
        //

        // Sanity checks:
        if (isset($db_enabled)) {
            if ($db_enabled == "0") {
                $db_host = "localhost";
                $db_username = "";
                $db_pass = "";
            }
        }
        else {
            $db_username = "";
            $db_pass = "";
        }
        if (!isset($db_host)) {
            $db_host = "localhost";
        }


        /* Was data posted? */
        if ((is_array($attributes)) && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Set PMA Cookies for SignOn to phpMyAdmin:
            setcookie("PMA_USER", $attributes['PMA_user'], '0', "/");
            setcookie("PMA_PASSWORD", $attributes['PMA_password'], '0', "/");

            // Redirect to phpMyAdmin URL:
            $redirect_URL = "/base/phpMyAdmin/index.php";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        } 
        else {

            //
            //-- No credentials - display login form:
            //

            // Tell BxPage which module we are currently in:
            $page_module = 'base_programs';

            // New Page:
            $BxPage = $factory->getPage();

            // Manually set the correct vertical menu entry:
            $BxPage->setVerticalMenu('base_phpmyadmin');
            $BxPage->setOutOfStyle('yes');

            $defaultPage = "basic";
            $block = $factory->getPagedBlock("PMA_logon", array($defaultPage));
            $block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setDefaultPage($defaultPage);

            $PMA_user_field = $factory->getTextField('PMA_user', $db_username, 'rw');
            $block->addFormField(
                $PMA_user_field,
                $factory->getLabel("PMA_user"), 
                $defaultPage
            );

            $PMA_password_field = $factory->getPassword('PMA_password', $db_pass, 'rw');
            $PMA_password_field->setOptional("silent");
            $PMA_password_field->setConfirm(FALSE);
            $PMA_password_field->setCheckPass(FALSE);
            $block->addFormField(
                $PMA_password_field,
                $factory->getLabel("PMA_password"), 
                $defaultPage
            );

            $PMA_hostname_field = $factory->getTextField('hostname', $db_pass, '');
            $block->addFormField(
                $PMA_hostname_field,
                $factory->getLabel("hostname"), 
                $defaultPage
            );

            // Stretcher:
            $xff = $factory->getRawHTML("stretcher", '<IMG BORDER="0" WIDTH="680" HEIGHT="0" SRC="/libImage/spaceHolder.gif">');
            $block->addFormField(
                $xff,
                $factory->getLabel("stretcher"),
                $defaultPage
            );

            // Add the button
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));

            $page_body[] = $block->toHtml();

            // Out with the page:
            return $BxPage->render($page_module, $page_body);
        }
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