<?php 
namespace Phpmyadmin\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Pusher extends BaseController {
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

        $this->session = \Config\Services::session();

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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-phpmyadmin", "/phpmyadmin/pusher");
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
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        // -- Actual page logic start:
        //

        // Default: Lowest possible start point:
        $pm = 'personal';

        // Check if we have a $pm cookie:
        if (isset($_COOKIE['pm'])) {
            $pm = $_COOKIE['pm'];
        }
        if (isset($get_form_data['pm'])) {
            $pm = $get_form_data['pm'];
        }

        // -- Actual page logic start:
        if ($CI->getAllowed('systemAdministrator')) {
            if ((isset($pm)) && (isset($get_form_data['group']))) {
                // Get MYSQL_Vsite settings for this site:
                list($sites) = $CI->cceClient->find("Vsite", array("name" => $get_form_data['group']));
                $MYSQL_Vsite = $CI->cceClient->get($sites, 'MYSQL_Vsite');
                // Fetch MySQL details for this site:
                $db_enabled = $MYSQL_Vsite['enabled'];
                $db_username = $MYSQL_Vsite['username'];
                $db_pass = $MYSQL_Vsite['pass'];
                $db_host = $MYSQL_Vsite['host'];
            }
            else {
                $SystemOid = $CI->cceClient->get($System['OID'], "mysql");
                $db_username = $SystemOid['mysqluser'];
                $mysqlOid = $CI->cceClient->find("MySQL");
                $mysqlData = $CI->cceClient->get($mysqlOid[0]);
                $db_pass = $mysqlData['sql_rootpassword'];
                $db_host = $mysqlData['sql_host'];
            }
        }
        elseif ($CI->getAllowed('siteAdmin')) {
            if ($CI->getAllowed('manageSite')) {
                if (isset($get_form_data['group'])) {
                    $group = $get_form_data['group'];
                }
            }
            else {
                $group = $user["site"];
            }

            if (isset($group)) {
                // Get MYSQL_Vsite settings for this site:
                list($sites) = $CI->cceClient->find("Vsite", array("name" => $group));
                $MYSQL_Vsite = $CI->cceClient->get($sites, 'MYSQL_Vsite');

                // Fetch MySQL details for this site:
                $db_enabled = $MYSQL_Vsite['enabled'];
                $db_username = $MYSQL_Vsite['username'];
                $db_pass = $MYSQL_Vsite['pass'];
                $db_host = $MYSQL_Vsite['host'];
            }
            else {
                $db_enabled = "0";
            }

            if ($db_enabled == "0") {
                $db_host = "localhost";
                $db_username = "";
                $db_pass = "";
            }
        }

        // Sanity checks:
        if (!isset($db_host)) {
            // Nice people say goodbye, or CCEd waits forever:
            $redirect_URL = "/phpmyadmin/signon";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //-- Generate page:

        // Tell BxPage which module we are currently in:
        $page_module = 'base_programs';

        // Assemble page_body:
        $BxPage = $factory->getPage();

        $BxPage->setVerticalMenu('base_phpmyadmin');
        $BxPage->setOutOfStyle('yes');      

        // CSRF:
        $csrf = array(
                'name' => $BX_SESSION['csrf_token_name'],
                'hash' => $BX_SESSION['csrf_cookie_name']
        );

        $csrf_cookie_name = '';
        if (isset($_COOKIE['BlueOnyx_CSRF_cookie'])) {
            $csrf_cookie_name = $_COOKIE['BlueOnyx_CSRF_cookie'];
        }

        // Set PMA Cookies for SignOn to phpMyAdmin:
        setcookie("PMA_USER", $db_username, '0', "/");
        setcookie("PMA_PASSWORD", $db_pass, '0', "/");

        $redirect_URL = "/base/phpMyAdmin/index.php";
        $BxPage->ReturnToThisPage($errors, $redirect_URL);

        // Page body for auto-logins
        $page_body[] = '
            <form action="signon" method="post" name="frm" onLoad="document.frm.submit()">
            <input type="hidden" name="PMA_user" value="' . $db_username . '">
            <input type="hidden" name="PMA_password" value="' . $db_pass . '">
            <input type="hidden" name="hostname" value="' . $db_host . '">
            <input type="image" name="" value="">
            <input type="hidden" name="' . $csrf['name'] . '" value="' . $csrf_cookie_name . '" />
            </form>
            <script language="JavaScript">
                   document.frm.submit();
            </script>
        ';

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }       
}
/*
Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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