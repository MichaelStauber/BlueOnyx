<?php 
namespace Sitestats\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("ServerScriptHelper.php");
use I18n;
use ServerScriptHelper;

//class Vsite extends Controller
class GoAccessView extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {

    }

    public function index() {

        $CI = get_instance();

        //
        // Access Rules:
        //

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

        // locale and charset setup:
        $ini_langs = initialize_languages(FALSE);
        $locale = $ini_langs['locale'];
        $localization = $ini_langs['localization'];
        $charset = $ini_langs['charset'];

        //
        //-- Prepare Page:
        //

        // Set headers:
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Cache-Control', 'post-check=0, pre-check=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Content-language', $locale);
        $this->response->setHeader('Content-type', "text/html; charset=$charset");

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-sitestats", "/sitestats/goaccessview");
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

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $get_form_data = $BxPage->getGETPOST('GET');

        $group = 'server';
        if (isset($get_form_data['group'])) {
            $group = formspecialchars($get_form_data['group']);
        }

        //
        // Access Rules:
        //

        //
        //-- Access Rights Check for Vsite level pages:
        // 
        // 1.) Checks if the Group/Vsite exists.
        // 2.) Checks if the user is systemAdministrator
        // 3.) Checks if the user is Reseller of the given Group/Vsite
        // 4.) Checks if the iser is siteAdmin of the given Group/Vsite
        // Returns Forbidden403 if *none* of that is the case.
        if (!$CI->serverScriptHelper->getGroupAdmin($group)) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }

        if ((!$CI->getAllowed('adminUser')) && 
            (!$CI->getAllowed('siteAdmin')) && 
            (!$CI->getAllowed('manageSite')) && 
            (($user['site'] != $CI->serverScriptHelper->loginUser['site']) && $CI->getAllowed('siteAdmin')) &&
            (($vsiteObj['createdUser'] != $BX_SESSION['loginName']) && $CI->getAllowed('manageSite'))
            ) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        if ($group != 'server') {
            // Get data for the Vsite:
            $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));
            $statspath_top = $vsite['basedir'] . '/var/logs/';
            $most_recent_stats = $statspath_top . 'web.json';
        }
        else {
            if (!$CI->getAllowed('adminUser')) {
                // Yeah. Nope! Only 'adminUser' can see this!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }
            $statspath_top = '/home/.sites/server/logs/';
            $most_recent_stats = $statspath_top . 'web.json';            
        }

        if ((isset($get_form_data['YEAR'])) && (isset($get_form_data['MONTH'])) && (isset($get_form_data['DAY']))) {
            $selectedYear = $get_form_data['YEAR'];
            $selectedMonth = $get_form_data['MONTH'];
            $selectedDay = $get_form_data['DAY'];

            $date_path = $statspath_top . $selectedYear . '/' . $selectedMonth . '/' . $selectedDay . '/web.json';
        }

        if ((isset($date_path)) && (is_file($date_path))) {
            $json_data = file_get_contents($date_path);
        }
        elseif ((is_file($most_recent_stats)) && (!isset($json_data))) {
            $json_data = file_get_contents($most_recent_stats);
        }
        else {

            //
            //--- We don't have statistics yet!
            //

            $page_module = 'base_sysmanage';
            $defaultPage = "basicSettingsTab";

            $BxPage->setOutOfStyle(TRUE);

            $block = $factory->getPagedBlock("header", array($defaultPage));

            $block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setShowAllTabs('#');
            $block->setDefaultPage($defaultPage);

            $my_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-sitestats.no_stats_yet_text]]") . "</div>";
            $infotext = $factory->getHtmlField("info_text", $my_TEXT, 'r');
            $infotext->setLabelType("nolabel");
            $block->addFormField(
              $infotext,
              $factory->getLabel(" ", false),
              $defaultPage
            );

            $page_body[] = $block->toHtml();

            // Out with the page:
            return $BxPage->render($page_module, $page_body);

        }

        // Assemble data:
        $data = array(
            'page_title' => $i18n->getHtml("[[base-sitestats.GoAccess_header]]"),
            'json_data' => $json_data
        );

        // Show the Stats Page:
        return view('../../Modules/Base/Sitestats/Views/goaccess_view', $data);
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