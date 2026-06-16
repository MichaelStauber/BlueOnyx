<?php 
namespace Ftp\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Filemanager extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ftp", "/ftp/filemanager");
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

        $FTP = $CI->cceClient->get($System['OID'], "Ftp");

        $get_form_data = $BxPage->getGETPOST('GET');

        $fullscreen = FALSE;
        if (isset($get_form_data['full'])) {
            $fullscreen = TRUE;
        }

        // Set up auth:
        $plaintext = $CI->dec_pwd();

        // Path to encryption key
        $keyPath = '/usr/sausalito/capcache/authkey';
        if (is_file($keyPath)) {
            $keyHex = trim(file_get_contents($keyPath));
            $binaryKey = hex2bin($keyHex);

            // Generate a 16-byte IV
            $ivLength = openssl_cipher_iv_length('AES-256-CBC');
            $iv = random_bytes($ivLength);

            // Encrypt the plaintext
            if (!empty($plaintext)) {
                $encryptedData = openssl_encrypt($plaintext, 'AES-256-CBC', $binaryKey, OPENSSL_RAW_DATA, $iv);

                // Concatenate IV and encrypted data, then encode
                $encryptedDataWithIv = $iv . $encryptedData;
                $encodedData = base64_encode($encryptedDataWithIv);

                // Store the encoded data in a cookie
                setcookie("fm_enc_auth", $encodedData, 0, "/", "", false, true);
            }
        }

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

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
        $BxPage->setFormUrl("/ftp/filemanager/");
        $BxPage->setErrors($errors);

        // Set Menu items:
        if (!isset($group)) {
            $BxPage->setVerticalMenu('base_programsPersonal');
            $BxPage->setVerticalMenuChild('ftpc_personal');
            $page_module = 'base_personalProfile';
            $url_suffix = '';
        }
        else {
            if ($group == "server") {
                $BxPage->setVerticalMenu('base_programs');
                $BxPage->setVerticalMenuChild('ftpc_server');
                $page_module = 'base_sysmanage';
                $url_suffix = '&group=server';
            }
            else {
                
                $BxPage->setVerticalMenu('base_programsSite');
                $BxPage->setVerticalMenuChild('ftpc_vsite');
                $page_module = 'base_sitemanage';
            }
            $url_suffix = '&group=' . $group;
        }

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("connect", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $uri_full = 'https://' . $_SERVER['SERVER_NAME'] . ':' . $BX_SESSION['GUI_PORT'] . '/ftp/filemanager?full=true' . $url_suffix;
        $uri_short = '/ftp/filemgr';

        if ($fullscreen === FALSE) {
            $block->setSelf($uri_full);
        }

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
            $disabled_TEXT = $i18n->getClean("[[base-ftp.service_disabled]]");
            $console_html_data = <<<HTML
                <div id="spice-container" style="width: 100%; display: flex; flex-direction: column;">
                    $disabled_TEXT
                </div>
            HTML;
        }
        elseif ($fullscreen === TRUE) {
            $BxPage->setExtraBodyTag('<body onload="javascript: poponload()">');
            $uri_full = '/ftp/filemgr';
            $BxPage->setExtraHeaders('<script type="text/javascript">');
            $BxPage->setExtraHeaders('function poponload() {');
            $BxPage->setExtraHeaders("  window.open('$uri_full','_blank','toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, copyhistory=yes, width=1024, height=800');");
            $BxPage->setExtraHeaders('}');
            $BxPage->setExtraHeaders('</script>');

            $disabled_TEXT = $i18n->getClean("[[base-ftp.info_text]]");
            $console_html_data = <<<HTML
                <div id="spice-container" style="width: 100%; display: flex; flex-direction: column;">
                    $disabled_TEXT
                </div>
            HTML;
        }
        else {
            // Embed the elFinder in an iframe
            $console_html_data = <<<HTML
                <div id="spice-container" style="width: 100%; display: flex; flex-direction: column;">
                    <iframe id="spice-iframe" src="/ftp/filemgr" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
                
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var iframe = document.getElementById('spice-iframe');
                        if (!iframe) {
                            return;
                        }

                        // Function to resize the iframe to fit the content
                        function resizeIframe() {
                            try {
                                var doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
                                if (!doc || !doc.documentElement) {
                                    return;
                                }
                                var body = doc.body;
                                if (!body) {
                                    return;
                                }
                                var height = Math.max(
                                    body.scrollHeight || 0,
                                    body.offsetHeight || 0,
                                    doc.documentElement.scrollHeight || 0,
                                    doc.documentElement.offsetHeight || 0,
                                    doc.documentElement.clientHeight || 0
                                );
                                if (height > 0) {
                                    iframe.style.height = height + 'px';
                                }
                            }
                            catch (ignore) {}
                        }

                        // Attach the event listener for when the iframe is fully loaded
                        iframe.addEventListener('load', function() {
                            resizeIframe(); // Call the function once the iframe has loaded
                        });

                        // Periodic resize check for dynamic content updates.
                        var resizeTimer = setInterval(function() {
                            if (!document.body.contains(iframe)) {
                                clearInterval(resizeTimer);
                                return;
                            }
                            resizeIframe();
                        }, 500);
                    });
                </script>
            HTML;
        }

        $console_htmlField = $factory->getRawHTML('instance_console', $console_html_data, 'rw');
        $block->addFormField(
            $console_htmlField,
            $factory->getLabel("instance_console"), 
            $defaultPage
        );

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }
}
/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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
