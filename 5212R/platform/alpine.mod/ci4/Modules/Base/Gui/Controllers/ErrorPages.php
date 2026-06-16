<?php 
namespace Gui\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
use I18n;

//class Vsite extends Controller
class ErrorPages extends BaseController {
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

    public function Redirect() {

        // This handles redirects of logged in users if they access /gui

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $serverScriptHelper = $CI->getSSH();
        $AGENT = $this->request->getUserAgent();
        $IP = getIp();
        $agent = $AGENT->getBrowser() . ' ' . $AGENT->getVersion();

        // Find out if the web based initial setup has been completed:
        $System = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));

        // Redirect-Exception for /wizard if 'isLicenseAccepted' is not yet set and we're not already on /wizard:
        if ($System['isLicenseAccepted'] == "0" && current_url() != site_url('wizard')) {
            // Web based setup has *NOT* been completed. Redirect to /wizard
            header("Location: /wizard");
            exit;
        }

        if ((isset($_COOKIE['sessionId'])) && (isset($_COOKIE['loginName'])) && (session()->get('isLoggedIn')) && (isset($serverScriptHelper)) && (isset($BX_SESSION['loginUser']['OID']))) {

            $access = $serverScriptHelper->getAccessRights($this->cceClient);
            $user = $BX_SESSION['loginUser'];
            $group = $user['site'];

            $vsite = $this->cceClient->getObject("Vsite", array("name" => $user['site']));
            if (isset($vsite['fqdn'])) {
                $hostName = $vsite['fqdn'];
            }
            else {
                // User doesn't belong to a Vsite. So we leave this empty:
                $hostName = "";
                $vsite['fqdn'] = gethostname();
            }

            // Determine what the first URL is the logged in User is allowed to access after login:
            $_SiteMap_items = generateSiteMap(FALSE, $access, $this->cceClient, array('group' => $group, 'fqdn' => $hostName));
            $parent_root = 'root';
            $ignore_items = array('base_manualButton', 'base_updateLight');
            $root_children_sort_order = MenuChildren($parent_root, $ignore_items, $_SiteMap_items, $access);
            $url = getURLofFirstChild($root_children_sort_order, $ignore_items, $_SiteMap_items, $access);

            $serverScriptHelper->debug_log("ErrorPages.Redirect(): User '" . $user['name'] . "' logged in from IP [$IP] with '$agent'. Redirecting to " . base_url($url));
            return redirect()->to(base_url($url));
        }
        else {

            // This bumps users without sufficient login privileges back to the /login page:

            $uri = $CI->bx_get_gui_url();

            $username = 'Unknown user';
            if (isset($_COOKIE['loginName'])) {
                $username = "User '" . $_COOKIE['loginName'] . "'";
            }

            bx_error_log("ErrorPages.Redirect(): " . $username . " without login pre-requisites attempted to acess /gui in from IP [$IP] with '$agent'. Redirecting to " . $uri['expired']);
            @session()->destroy();
            $data['expired_url'] = $uri['expired'];
            session()->set($data);
            header('Location: ' . $uri['expired']);
            exit;
        }
    }

    public function AuthorizationRequired401() {
        $data['text'] = 'AuthorizationRequired401';
        helper(['form']);

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $locale = $BX_SESSION['locale'];
        $localization = $BX_SESSION['localization'];
        $charset = $BX_SESSION['charset'];
        $domain = 'palette';

        $title = PoorMansBabelFish("401title", $locale, $domain);
        $text = PoorMansBabelFish("401text", $locale, $domain);

        // Set Headers:
        $this->response->setStatusCode(401);
        $this->response->setHeader('Cache-Control', 'must-revalidate');

        // Show the HTML Page:
        $page_variables = array(
                                'localization' => $localization,
                                'charset' => $charset,
                                'page_title' => $title,
                                'bx_logo_color' => '#000000',
                                'elmer_style_css' => '/.elm/dist/css/style.css',
                                'extra_headers' => '',
                                'heading' => $title,
                                'text' => $text,
                                'extra_footers' => '',
                                );

        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);

    }

    public function Forbidden403() {
        $data['text'] = 'Forbidden403';
        helper(['form']);

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $locale = $BX_SESSION['locale'];
        $localization = $BX_SESSION['localization'];
        $charset = $BX_SESSION['charset'];
        $domain = 'palette';

        $title = PoorMansBabelFish("403title", $locale, $domain);
        $text = PoorMansBabelFish("403text", $locale, $domain);

        // Set Headers:
        $this->response->setStatusCode(403);
        $this->response->setHeader('Cache-Control', 'must-revalidate');

        // Show the HTML Page:
        $page_variables = array(
                                'localization' => $localization,
                                'charset' => $charset,
                                'page_title' => $title,
                                'bx_logo_color' => '#000000',
                                'elmer_style_css' => '/.elm/dist/css/style.css',
                                'extra_headers' => '',
                                'heading' => $title,
                                'text' => $text,
                                'extra_footers' => '',
                                );

        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);

    }

    public function PageNotFound404() {
        $data['text'] = 'PageNotFound404';
        helper(['form']);

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $locale = $BX_SESSION['locale'];
        $localization = $BX_SESSION['localization'];
        $charset = $BX_SESSION['charset'];
        $domain = 'palette';

        $title = PoorMansBabelFish("404title", $locale, $domain);
        $text = PoorMansBabelFish("404text", $locale, $domain);

        // Set Headers:
        $this->response->setStatusCode(404);
        $this->response->setHeader('Cache-Control', 'must-revalidate');

        // Show the HTML Page:
        $page_variables = array(
                                'localization' => $localization,
                                'charset' => $charset,
                                'page_title' => $title,
                                'bx_logo_color' => '#000000',
                                'elmer_style_css' => '/.elm/dist/css/style.css',
                                'extra_headers' => '',
                                'heading' => $title,
                                'text' => $text,
                                'extra_footers' => '',
                                );

        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);
    }

    public function InternalServerError500() {
        $data['text'] = 'InternalServerError500';
        helper(['form']);

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $locale = $BX_SESSION['locale'];
        $localization = $BX_SESSION['localization'];
        $charset = $BX_SESSION['charset'];
        $domain = 'palette';

        $title = PoorMansBabelFish("500title", $locale, $domain);
        $text = PoorMansBabelFish("500text", $locale, $domain);

        // Set Headers:
        $this->response->setStatusCode(500);
        $this->response->setHeader('Cache-Control', 'must-revalidate');

        // Show the HTML Page:
        $page_variables = array(
                                'localization' => $localization,
                                'charset' => $charset,
                                'page_title' => $title,
                                'bx_logo_color' => '#000000',
                                'elmer_style_css' => '/.elm/dist/css/style.css',
                                'extra_headers' => '',
                                'heading' => $title,
                                'text' => $text,
                                'extra_footers' => '',
                                );

        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);
    }

    public function LoginDenied() {

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        // Set up I18n:
        $i18n = new I18n("base-alpine", $CI->getBX_Locale());
        $system = $CI->getSystem();
        $servername = $system['hostname'] . '.' . $system['domainname'];

        // Strip out the :444 or :81 from the hostname - if present:
        if (preg_match('/:/', $servername)) {
            $hn_pieces = explode(":", $servername);
            $servername = $hn_pieces[0];
        }

        helper(['form']);

        $locale = $BX_SESSION['locale'];
        $localization = $BX_SESSION['localization'];
        $charset = $BX_SESSION['charset'];
        $domain = 'palette';

        $title = $i18n->getHtml("loginPageTitle", "base-alpine", array("hostname" => $servername));
        $title_desc = $i18n->getHtml("login_ddos_desc", "palette", array("hostname" => $servername));
        $text = $i18n->getHtml("login_ddos", "palette", array("hostname" => $servername));

        // Set Headers:
        $this->response->setStatusCode(500);
        $this->response->setHeader('Cache-Control', 'must-revalidate');

        // Show the HTML Page:
        $page_variables = array(
                                'localization' => $localization,
                                'charset' => $charset,
                                'page_title' => $title,
                                'bx_logo_color' => '#000000',
                                'elmer_style_css' => '/.elm/dist/css/style.css',
                                'extra_headers' => '',
                                'heading' => $title,
                                'text' => $text,
                                'extra_footers' => '',
                                );

        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);
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