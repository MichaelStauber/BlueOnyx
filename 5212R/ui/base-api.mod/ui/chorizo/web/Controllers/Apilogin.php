<?php 
namespace Api\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
use I18n;
use ServerScriptHelper;

class Apilogin extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }

    /**
     * Index Page for this controller and slimmed down API-login page to the GUI.
     * 
     * Note: This doesn't show the login form. It accepts POST requests of UN/PW/URL
     * if the API is enabled. Otherwise it logs errors and redirects to /login.
     */

    // XSS cleaner. 
    //
    // Please note: The regexp are taken from basetypes.schema for the corresponding inputs and conform 
    // with what CODB would accept in the fields 'username' and 'password'.
    public function xssafeLogin($data, $encoding='UTF-8', $type='') {
        if ($type == 'username_field') {
            if (!preg_match('/^[A-Za-z0-9\._-]+$/', $data)) {
                $data = '';
                return $data;
            }
            else {
                return $data;
            }
        }
        elseif ($type == 'password_field') {
            if (!preg_match('/^[^\001-\037\042\177]{6,32}$/', $data)) {
                $data = '';
                return $data;
            }
            else {
                return $data;
            }
        }
        else {
            return htmlspecialchars($data,ENT_QUOTES | ENT_HTML401,$encoding);
        }
    }

    public function index() {

        $CI =& get_instance();

        if ($this->request->getMethod() == 'POST') {
            bx_error_log("Apilogin.php: login() with POST data.");
        }
        else {
            bx_error_log("Apilogin.php: login() without POST data.");
        }

        // Set up I18n:
        $i18n = new I18n("base-alpine", $CI->getBX_Locale());
        $System = $CI->getSystem();

        $servername = $System['hostname'] . '.' . $System['domainname'];

        // Strip out the :444 or :81 from the hostname - if present:
        if (preg_match('/:/', $servername)) {
            $hn_pieces = explode(":", $servername);
            $servername = $hn_pieces[0];
        }

        // Get URI string:
        $request = \Config\Services::request();
        $get_uri_string = (string) $request->getPath();

        // URI string extraction:
        $uri_elements = mb_split("\/", $get_uri_string);

        $form_data = $this->request->getPost();
        $get_form_data = $this->request->getGet();

        // locale and charset setup:
        $ini_langs = initialize_languages(TRUE);
        $locale = $ini_langs['locale'];
        $charset = $ini_langs['charset'];

        // Set cookie for locale if we do NOT have one yet. This HAS to be done here, as the entire
        // i18n she-bang heavily depends on it. 
        setcookie("locale", $locale, "0", "/");

        // Get the IP address of the user accessing the GUI:
        $userip = $_SERVER["REMOTE_ADDR"];

        if (!isset($i18n)) {
            $i18n = new I18n("base-alpine", $locale);
        }

        // If we have form data, we sanitize it:
        $attributes = array();
        $ignore_attributes = array();
        if ($this->request->getPost(NULL, NULL, TRUE)) {
            foreach ($form_data as $key => $value) {
                // Sanitize data received via form fields:
                $form_data[$key] = Apilogin::xssafeLogin($value, 'UTF-8', $key);
            }
            $required_keys = array('username_field', 'password_field', 'secureConnect', 'redirect_target');
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes);
            $form_data = $attributes;
        }
        else {
            // Not a POST request. Redirect to /login instead:
            error_log("FAILED API GET ACCESS: IP address: " . $userip);
            delete_cookie("sessionId");
            header('Location: /login');
            exit;
        }

        // Get 'System' object
        $System = $CI->cceClient->getObject('System');
        $API = $CI->cceClient->get($System['OID'], 'API');

        // Is API access allowed?
        $api_access = '0';
        if (isset($API['enabled'])) {
            if ($API['enabled'] == '1') {
                $api_access = '1';
            }
        }
        if ($api_access == '0') {
            // Not a POST request. Redirect to /login instead:
            error_log("ACCESS AGAINST DISABLED API FROM: IP address: " . $userip);
            delete_cookie("sessionId");
            $form_data = array();
            header('Location: /login');
            exit;
        }

        if ((!isset($form_data['username_field'])) || (!isset($form_data['password_field']))) {
            // No login data posted. Redirect to /login instead:
            error_log("FAILED API POST ACCESS: " . json_encode($form_data) . " - IP address: " . $userip);
            delete_cookie("sessionId");
            header('Location: /login');
            exit;
        }

        //
        //-- Form data has been sanitized and validated. Now we check if it matches:
        //

        // Rate limiting check first:
        $invalid_login_check_result = $CI->check_invalid_login();
        if (!empty($invalid_login_check_result['ERROR'])) {
            $CI->add_invalid_login();
            bx_error_log("Apilogin.php: Invalid login attempt. Max attempts reached, redirecting to /login_denied!");
            return redirect()->to(base_url('login_denied'));
        }

        if ((isset($form_data['username_field'])) && (isset($form_data['password_field']))) {
            bx_error_log("Login.php: We have a Username and Password.");
            if (($form_data['username_field'] != '') && ($form_data['password_field'] != '')) {
                $sessionId = $CI->cceClient->auth($form_data['username_field'], $form_data['password_field']);

                if (($sessionId != '') && ($sessionId != 'expired')) {
                    $CI->enc_pwd($form_data['password_field']);

                    // Get 'User' Object and 'Shell' NameSpace:
                    $loginUser = $CI->cceClient->get($CI->cceClient->whoami());
                    $userShell = $CI->cceClient->get($loginUser['OID'], 'Shell');

                    // Store BX_SESSION and initialize Session-Data:
                    $CI->setBX_SESSION_sessionId($sessionId);
                    $CI->setAUTH_STAGE('PWDAUTH');
                    $CI->setBX_SESSION($form_data['username_field'], $sessionId, $loginUser, $userShell['enabled']);
                    $CI->setUserLogged($sessionId);

                    // Force ServerScriptHelper to use the new credentials:
                    $CI->serverScriptHelper = new ServerScriptHelper($sessionId, $form_data['username_field']);
                    $CI->setSSH($CI->serverScriptHelper);
                    $CI->cceClient = $CI->serverScriptHelper->getCceClient();

                    // Get BX_SESSION again:
                    $BX_SESSION = $CI->getBX_SESSION();

                    // If we have this IP logged as offender, then we remove it:
                    $CI->remove_invalid_login();
                }
                else {
                    // Auth failed — track this attempt:
                    $CI->add_invalid_login();
                    bx_error_log("Apilogin.php: Username and Password are INCORRECT!");
                    return redirect()->to(base_url('/login'));
                }
            }
            else {
                $CI->add_invalid_login();
                bx_error_log("Apilogin.php: Username and Password are INCORRECT!");
                return redirect()->to(base_url('/login'));
            }
        }

        // Get theme preference:
        $BX_SESSION = $CI->getBX_SESSION();
        if (!isset($BX_SESSION['loginUser']['gui_theme'])) {
            $User = $CI->cceClient->getObject('User', array('name' => $BX_SESSION['loginName']));
            $gui_theme = $User['gui_theme'];
        }
        else {
            $gui_theme = $BX_SESSION['loginUser']['gui_theme'];
        }

        // Set theme preference:
        setcookie("gui_theme", $gui_theme, "0", "/");
        $CI->setBX_SESSION_GuiTheme($gui_theme);
        $CI->setAUTH_STAGE('SUCCESS');
        $BX_SESSION = $CI->getBX_SESSION();

        if (isset($form_data['redirect_target'])) {
            $redirect_target = $form_data['redirect_target'];
        }
        else {
            $redirect_target = '/gui';
        }

        bx_error_log("Login.php: Final redirect to: " . $redirect_target);
        return redirect()->to(base_url() . $redirect_target);
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