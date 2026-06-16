<?php 
namespace Remote\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Console extends BaseController {
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
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-remote", "/remote/console");
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

        setcookie("remote", $BX_SESSION['sessionId'], "0", "/");

        $userShell = $CI->cceClient->getObject("User", array("name" => $BX_SESSION['loginName']), "Shell");
        $vsiteShell = $CI->cceClient->getObject("Vsite", array("name" => $user['site']), "Shell");

        // Fallback if User doesn't belong to a Vsite:
        if (!isset($vsiteShell['enabled'])) {
            $vsiteShell['enabled'] = '0';
        }

        $SystemRemote = $CI->cceClient->get($System['OID'], "Remote");

        // No Shell access? Bye, bye!
        if (($userShell['enabled'] < '1') || (($vsiteShell['enabled'] < '1') && ($user['systemAdministrator'] != '1'))) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

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
        //--- Own error checks:
        //

        // Get URL strings:
        if (isset($get_form_data['group'])) {
            // We have a group:
            $group = $get_form_data['group'];
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/remote/console/");
        $BxPage->setErrors($errors);

        // Set Menu items:
        if (!isset($group)) {
            $BxPage->setVerticalMenu('base_programsPersonal');
            $BxPage->setVerticalMenuChild('console_personal');
            $page_module = 'base_personalProfile';
            $url_suffix = '';
        }
        else {
            if ($group == "server") {
                $BxPage->setVerticalMenu('base_programs');
                $BxPage->setVerticalMenuChild('console_server');
                $page_module = 'base_sysmanage';
            }
            else {
                
                $BxPage->setVerticalMenu('base_programsSite');
                $BxPage->setVerticalMenuChild('console_vsite');
                $page_module = 'base_sitemanage';
            }
            $url_suffix = '?group=' . $group;
        }

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("header", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $uri_full = 'https://' . $_SERVER['SERVER_NAME'] . ':' . $BX_SESSION['GUI_PORT'] . '/bxshell/?' . $BX_SESSION['loginName'] . '=' . time();
        $uri_short = '/bxshell/?' . $BX_SESSION['loginName'] . '=' . time();

        if (!isset($userShell['enabled'])) {
            $uri_full = 'https://' . $_SERVER['SERVER_NAME'] . ':' . $BX_SESSION['GUI_PORT'] . '/remote/noaccess/?' . $BX_SESSION['loginName'] . '=' . time();
            $uri_short = '/remote/noaccess/?' . $BX_SESSION['loginName'] . '=' . time();
        }

        if (uri_string() != "remote/console/full") {

            if ($SystemRemote['enabled'] == "0") {
                    $disabled_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-remote.service_disabled]]") . "</div>";
                    $disabledtext = $factory->getHtmlField("admin_text", $disabled_TEXT, 'r');
                    $disabledtext->setLabelType("nolabel");
                    $block->addFormField(
                      $disabledtext,
                      $factory->getLabel(" ", false),
                      $defaultPage
                    );
            }
            else {

                $my_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-remote.info_text]]") . "</div>";
                $infotext = $factory->getHtmlField("info_text", $my_TEXT, 'r');
                $infotext->setLabelType("nolabel");
                $block->addFormField(
                  $infotext,
                  $factory->getLabel(" ", false),
                  $defaultPage
                );

                // On 5211R 'admin' can use the console to 'su -', so we don't need to show this text here:
                //if ($BX_SESSION['loginName'] == 'admin') {
                //    $admin_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-remote.admin_text]]") . "</div>";
                //    $admintext = $factory->getHtmlField("admin_text", $admin_TEXT, 'r');
                //    $admintext->setLabelType("nolabel");
                //    $block->addFormField(
                //      $admintext,
                //      $factory->getLabel(" ", false),
                //      $defaultPage
                //    );
                //}

                // With fluid GUI style we use 45% padding for the IFrame, which gives us a nice screen height.
                $bottom_padding = '45%';
                if (isset($_COOKIE['layout_switcher_php-style'])) {
                    if ($_COOKIE['layout_switcher_php-style'] == 'layout_fixed.css') {
                        // With fixed GUI style we use 68% padding for the IFrame, which gives us a nice screen height there, too.
                        $bottom_padding = '68%';
                    }
                }

                $BxPage->setExtraHeaders('
                    <style>
                    .iframe-embed {
                        position: absolute;
                        top: 0;
                        left: 0;
                        bottom: 0;
                        height: 100%;
                        width: 100%;
                        border: 0;
                    }
                    .iframe-embed-wrapper {
                        position: relative;
                        display: block;
                        height: 0;
                        padding: 0;
                        overflow: hidden;
                    }
                    .iframe-embed-responsive-16by9 {
                        padding-bottom: ' . $bottom_padding . ';
                    }
                    </style>
                ');

                $block->setSelf("/remote/console/full$url_suffix");
                $applet = '
                <div class="iframe-embed-wrapper iframe-embed-responsive-16by9">
                    <iframe class="iframe-embed" src="' . $uri_short . '"></iframe>
                </div>';

                $xxx = $factory->getRawHTML("applet", $applet);
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("AllowOverride_OptionsField"),
                    $defaultPage
                );
            }

            $page_body[] = $block->toHtml();
        }
        else {

            if ($SystemRemote['enabled'] == "0") {
                    $disabled_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-remote.service_disabled]]") . "</div>";
                    $disabledtext = $factory->getHtmlField("admin_text", $disabled_TEXT, 'r');
                    $disabledtext->setLabelType("nolabel");
                    $block->addFormField(
                      $disabledtext,
                      $factory->getLabel(" ", false),
                      $defaultPage
                    );
            }
            else {

                $BxPage->setExtraBodyTag('<body onload="javascript: poponload()">');

                $BxPage->setExtraHeaders('<script type="text/javascript">');
                $BxPage->setExtraHeaders('function poponload() {');
                $BxPage->setExtraHeaders("  window.open('$uri_full','_blank','toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, copyhistory=yes, width=1024, height=800');");
                $BxPage->setExtraHeaders('}');
                $BxPage->setExtraHeaders('</script>');

                $my_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-remote.info_text]]") . "</div>";
                $infotext = $factory->getHtmlField("info_text", $my_TEXT, 'r');
                $infotext->setLabelType("nolabel");
                $block->addFormField(
                  $infotext,
                  $factory->getLabel(" ", false),
                  $defaultPage
                );

                if ($BX_SESSION['loginName'] == 'admin') {

                    $admin_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-remote.admin_text]]") . "</div>";
                    $admintext = $factory->getHtmlField("admin_text", $admin_TEXT, 'r');
                    $admintext->setLabelType("nolabel");
                    $block->addFormField(
                      $admintext,
                      $factory->getLabel(" ", false),
                      $defaultPage
                    );
                }
            }

            $page_body[] = $block->toHtml();

        }
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