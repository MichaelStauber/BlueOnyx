<?php 
namespace Ftp\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Filemgr extends BaseController {
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

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        // Very basic access check for 'validUser':
        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Validate GET data:
        //

        $group = 'personal';
        if (isset($get_form_data['group'])) {
            // We have a delete transaction:
            $group = $get_form_data['group'];
        }

        //
        //-- Setup clean $errors array:
        //

        $errors = array();

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ftp", "/ftp/filemgr");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        $FTP = $CI->cceClient->get($System['OID'], "Ftp");

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
        $BxPage->setVerticalMenu('base_programsPersonal');
        $BxPage->setVerticalMenuChild('ftpc_personal');
        $page_module = 'base_personalProfile';
        $defaultPage = 'FileManager';

        $block = $factory->getPagedBlock("connect", array($defaultPage));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $ftp_allowed = 0;

        if (($CI->getAllowed('systemAdministrator')) || ($CI->getAllowed('serverFTP'))) {
            $group = 'server';
            $access = 'rw';
            $ftp_allowed = 1;
        }
        elseif ($CI->getAllowed('manageSite')) {
            $access = 'rw';
            $ftp_allowed = 1;
        }
        elseif ($CI->getAllowed('siteAdmin') && (!empty($user['site'])) && ($CI->serverScriptHelper->getGroupAdmin($user['site']))) {
            $group = $user['site'];
            $access = 'r';
            $ftp_allowed = 1;
        }
        else {
            $group = $user['site'];

            // Get data for the Vsite:
            $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));

            // Get the FTPNONADMIN settings for this Vsite:
            $FTPNONADMIN = $CI->cceClient->get($vsite['OID'], "FTPNONADMIN");
            if ($FTPNONADMIN['enabled'] === '1') {
                $ftp_allowed = 1;
            }
        }

        if (($FTP['enabled'] === '0') || ($ftp_allowed === 0)) {
            $service_disabled = $i18n->getHtml('service_disabled');
            $console_html_data =<<<HTML
            <p>$service_disabled</p>
            HTML;
        }
        else {

            // Display the elFinder HTML template and fill in the blanks:

            $localization = $BX_SESSION['localization'];
            $charset = $BX_SESSION['charset'];

            $page_variables = array(
                'localization' => $localization,
                'charset' => $charset,
                'page_title' => $i18n->getHtml('connect'),
                'extra_footers' => '',
            );

            return view('../../Modules/Base/Ftp/Views/elmer_file_manager', $page_variables);
        }

        $filemanager_htmlField = $factory->getRawHTML('connect', $console_html_data, 'rw');
        $block->addFormField(
            $filemanager_htmlField,
            $factory->getLabel("instance_console"), 
            $defaultPage
        );

        // Out with the page:
        $BxPage->setOutOfStyle(TRUE);

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
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