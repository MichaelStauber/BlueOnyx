<?php 
namespace Organizer\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class PersonalOrganizerExt extends BaseController {

    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }

    public function addExtIframe ($url, $height, $BxPage) {
        // Generate an iframe from the passed on URL:
        //
        // $url:        URL of the iframe content.
        // $height:     Height. If "auto", it will be auto-calculated. 
        // $BxPage:     parents BXPage object

        $CI = get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];
        $userName = $user['name'];
        $auth_token = $CI->dec_pwd();

        if ($height == "auto") {
            $height = '';
            $BxPage->setExtraHeaders('<style>
                        #iframeContainer {
                          width: 100%;
                          height: 900px;
                          overflow: shown;
                        }

                        #radicaleIframe {
                          width: 100%;
                          height: 100%;
                        }
                        </style>
                ');

        }
        else {
            $height = 'height="' . $height . '" ';
        }

        $BxPage->setExtraHeaders('
        <!-- JavaScript code to auto-fill and submit the login form -->
        ');

        if (is_HTTPS() == TRUE) {
            $url = 'https://' . $_SERVER['SERVER_NAME'] . ':' . $BX_SESSION['GUI_PORT'] . $url;
        }
        else {
            $url = 'https://' . $_SERVER['SERVER_NAME'] . ':' . $BX_SESSION['GUI_PORT'] . $url;
        }

        $out = '
            <div id="iframeContainer">
                <iframe id="radicaleIframe" src="' . $url . '" frameborder="0" width="100%" ' . $height . '></iframe>
            </div>

            <script>
                // Function to adjust the height of the iframe
                function adjustIframeHeight() {
                    // ...
                }

                // Call the function initially and whenever the iframe content changes
                // ...

                $(document).ready(function() {
                    // Wait for the iframe to load
                    $(\'#radicaleIframe\').on(\'load\', function() {
                        // Get the document inside the iframe
                        var iframeDocument = $(\'#radicaleIframe\').contents().get(0);

                        // Find the login form and fill in the fields
                        var usernameInput = $(iframeDocument).find(\'input[data-name="user"]\');
                        var passwordInput = $(iframeDocument).find(\'input[data-name="password"]\');
                        var submitButton = $(iframeDocument).find(\'button[type="submit"]\');

                        // Set the username and password
                        $(usernameInput).val(\'' . "$userName" . '\');
                        $(passwordInput).val(\'' . "$auth_token" . '\');

                        // Delay the form submission for three seconds
                        //setTimeout(function() {
                        //    // Submit the form
                        //    $(iframeDocument).find(\'form\').submit();
                        //
                        //    // Remove the load event listener to prevent multiple submissions
                        //    $(\'#radicaleIframe\').off(\'load\');
                        //}, 3000); // 3000 milliseconds = 3 seconds
                    });
                });
            </script>
        ';

        return $out;
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

        // get Radicale info from CCE:
        $radicale = $CI->cceClient->getObject("System", array(), "Radicale");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-organizer", "/organizer/personalOrganizerExt");
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
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/organizer/personalOrganizerExt");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('radicale_personal');
        $page_module = 'base_personalProfile';

        if ($radicale['enabled'] == '0') {
            // Radicale is disabled. Show info text:
            $defaultPage = "basicSettingsTab";
            $block = $factory->getPagedBlock("radicale_server_long", array($defaultPage));

            $block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setShowAllTabs('#');
            $block->setDefaultPage($defaultPage);

            // Show a text description why this is turned off and what it can do if turned on:
            $radicale_off_desc = $factory->getHtmlField("radicale_off_desc", "<br>" . $i18n->get("[[base-organizer.radicale_off_desc]]"), 'r');
            $radicale_off_desc->setLabelType("nolabel");
            $block->addFormField(
                    $radicale_off_desc,
                    $factory->getLabel("radicale_off_desc"),
                    $defaultPage
                    );

            $block->addButton($factory->getCancelButton("/organizer/personalOrganizer"));

            $page_body[] = $block->toHtml();

        }
        else {
            // Show Radicale iframe:
            $uri_day = '/radicale/.web/';
            $uri_init = '/radicale/.web/';

            // Page body:
            $page_body[] = addInputForm(
                                            $i18n->get("[[base-organizer.radicale_server_long]]"),
                                            array("window" => $uri_init, "toggle" => "#"), 
                                            PersonalOrganizerExt::addExtIframe($uri_day, "auto", $BxPage),
                                            "",
                                            $i18n,
                                            $BxPage,
                                            $errors
                                        );
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