<?php 
namespace Gui\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("ServerScriptHelper.php");
include_once("StupidPass.php");
use I18n;
use ServerScriptHelper;
use StupidPass;

//class Vsite extends Controller
class Check_Password extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {

    }

    /**
     * BlueOnyx Password strength check utility
     *
     * @package   Check_Password
     * @author    Michael Stauber
     * @link      http://www.solarspeed.net
     * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
     * @version   4.0
     */
    public function index() {

        $CI =& get_instance();

        // locale and charset setup:
        $ini_langs = initialize_languages(FALSE);
        $locale = $ini_langs['locale'];
        $localization = $ini_langs['localization'];
        $charset = $ini_langs['charset'];

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-alpine", "/gui/check_password");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_data = $BxPage->getGETPOST('GET');
        $raw_form_data = $_POST;

        // Set headers:
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Cache-Control', 'post-check=0, pre-check=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Content-language', $locale);
        $this->response->setHeader('Content-type', "text/html; charset=$charset");

        // Handle form data:
        if (isset($form_data["password"])) {
            $password = $form_data["password"];
        }
        else {
            $password = "";
        }
        if (isset($raw_form_data["password"])) {
            $raw_password = $raw_form_data["password"];
        }
        else {
            $raw_password = "";
        }

        // Start sane:
        $data['result'] = $i18n->getHtml("[[palette.pw_way_too_short]]");

        if (function_exists('crack_opendict')) {
            // Poll cracklib:
            $dictionary = crack_opendict('/usr/share/dict/pw_dict') or die('Unable to open CrackLib dictionary');
            $check = crack_check($dictionary, $password);
            $diag = crack_getlastmessage();
            crack_closedict($dictionary);

            // Note to self: The XSS filter is on globally due to $config['global_xss_filtering'] = TRUE; 
            // in application/config/config.php. This will transform stuff into HTML entities or even 
            // filter characters like '&' straight out. Hence the extended check for forbidden chars must
            // run against the RAW post data. 

            // Standard error text for illegal chars: "Password contains illegal characters."

            // Check if Password matches our 'password' regexp:
            $illegal_chars = "0";
            if ((preg_match('/"/', $raw_password)) || (preg_match('/\$/', $raw_password)) || (preg_match('/\§/', $raw_password))) {
                $data['result'] = $i18n->getHtml("[[base-alpine.pw_illegal_chars]]");
                $illegal_chars = "1";
            }
            elseif ($illegal_chars == "0") {

                // Parse the return strings from cracklib and localize them:
                if (preg_match('/^it\'s WAY too short$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_way_too_short]]");
                }
                elseif (preg_match('/^it is too short$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_too_short]]");
                }
                elseif (preg_match('/^it does not contain enough DIFFERENT characters$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_not_nuff_different]]");
                }
                elseif (preg_match('/^it is all whitespace$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_all_whitespace]]");
                }
                elseif (preg_match('/^it is too simplistic\/systematic$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_too_simple]]");
                }
                elseif (preg_match('/^it looks like a National Insurance (.*)$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_insurance_number]]");
                }
                elseif (preg_match('/^it is based on a dictionary word$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_dictionary_word]]");
                }
                elseif (preg_match('/^it is based on a \(reversed\) dictionary word$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_reversed_dictionary_word]]");
                }
                elseif (preg_match('/^strong password$/', $diag)) {
                    $data['result'] = $i18n->getHtml("[[palette.pw_strong_password]]");
                }
                else {
                    // In case the localization fails, return the cracklib output directly:
                    $data['result'] = $diag;
                }
            }
        }
        else {

            // Check if Password matches our 'password' regexp:
            $illegal_chars = "0";
            if ((preg_match('/"/', $raw_password)) || (preg_match('/\$/', $raw_password)) || (preg_match('/\§/', $raw_password))) {
                $data['result'] = $i18n->getHtml("[[base-alpine.pw_illegal_chars]]");
                $illegal_chars = "1";
            }
            elseif (strlen($password) < '8') {
                $data['result'] = $i18n->getHtml("[[palette.pw_too_short]]");
            }
            else {
                // Override the default errors messages
                $hardlang = array(
                'length' => $i18n->getHtml("[[palette.pw_way_too_short]]"),
                'upper'  => $i18n->getHtml("[[palette.pw_not_nuff_different]]"),
                'lower'  => $i18n->getHtml("[[palette.pw_not_nuff_different]]"),
                'numeric'=> $i18n->getHtml("[[palette.pw_too_simple]]"),
                'special'=> $i18n->getHtml("[[palette.pw_too_simple]]"),
                'common' => $i18n->getHtml("[[palette.pw_dictionary_word]]"),
                'environ'=> $i18n->getHtml("[[palette.pw_too_simple]]"));

                // Supply reference of the environment (company, hostname, username, etc)
                $environmental = array('blueonyx', 'admin');
                $sp = new StupidPass(40, $environmental, '/usr/sausalito/ui/chorizo/ci4/app/Libraries/stupid-pass/StupidPass.default.dict', $hardlang);
                if ($sp->validate($password) === false) {
                    $PWerrors = $sp->get_errors();
                    $data['result'] = $PWerrors[0];
                }
                else {
                    $data['result'] = $i18n->getHtml("[[palette.pw_strong_password]]");
                }
            }
        }

        // Show the results:
        return view('../../Modules/Base/Gui/Views/check_password_view', $data);

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