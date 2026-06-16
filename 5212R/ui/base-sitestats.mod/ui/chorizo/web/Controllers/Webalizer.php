<?php 
namespace Sitestats\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Webalizer extends BaseController {
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

        //
        //--- Restrict access:
        //

        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-sitestats", "/sitestats/statSettings");
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
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //--- URL String parsing:
        //
        $group = 'server';
        $file = "index.html";
        $inframe = "0";

        if (isset($get_form_data['group'])) {
            $group = formspecialchars($get_form_data['group']);
        }
        if (isset($get_form_data['file'])) {
            $file = formspecialchars($get_form_data['file']);
        }
        if (isset($get_form_data['inframe'])) {
            $inframe = $get_form_data['inframe'];
        }

        // Prevent directory traversal attempts in $file
        if (strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
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

        if ($inframe == "1") {
            if ($group) {
                if ($group != 'server') {
                    @list($oid) = $CI->cceClient->find('Vsite', array('name' => $group));
                    if ($oid == '') {
                        // Nice people say goodbye, or CCEd waits forever:
                        $CI->cceClient->bye();
                        $CI->serverScriptHelper->destructor();                      
                        header("Location: /404");
                        exit;
                    }
                    $vsite_info = $CI->cceClient->get($oid);
                    $fqdn = $vsite_info['fqdn'];
                    $partial_path = "/home/sites/" . $vsite_info['fqdn'] . "/var/webalizer/";
                    $fullPath = $partial_path . $file;
                }
                else {
                    if (is_dir("/var/www/html/usage")) {
                        $partial_path = "/var/www/html/usage/";
                        $fullPath = $partial_path . $file;
                    }
                    else {
                        $partial_path = "/var/www/usage/";
                        $fullPath = $partial_path . $file;
                    }
                }
            }
            else {
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                header("Location: /404");
                exit;
            }

            // Extra file check: Only allow access to files that actually exist within the target dir:
            $allowed_files = scandir($partial_path);
            if (!in_array($file, $allowed_files)) {
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }

            if (file_exists($fullPath)) {
                $fp = fopen ($fullPath, "r");
                $data = array();
                $data['result'] = "";
                while(!feof($fp)) {
                    $string = fgets($fp, 4096);
                    $string=str_replace("<A HREF=\"./", "<A HREF=\"/sitestats/webalizer?inframe=" . $inframe . "&group=" . $group . "&file=", $string); 
                    $string=str_replace("<A HREF=\"usage", "<A HREF=\"/sitestats/webalizer?inframe=" . $inframe . "&group=" . $group . "&file=usage", $string); 
                    $string=str_replace("<IMG SRC=\"", "<IMG SRC=\"/sitestats/webalizer?inframe=" . $inframe . "&group=" . $group . "&file=", $string); 
                    $data['result'] .= $string;
                }
                if (preg_match("/.png$/", $file)) {
                    $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
                    $this->response->setHeader('Cache-Control', 'post-check=0, pre-check=0');
                    $this->response->setHeader('Pragma', 'no-cache');
                    $this->response->setContentType('image/png')->send();
                }
                // Show the results:
                return view('Gui\Views\check_password_view', $data);
                @fclose($fp);
            }
            else {

                // Prepare Page:
                $BxPage->setFormUrl("/sitestats/statSettings?group=$group");
                $BxPage->setErrors($errors);
                $BxPage->setOutOfStyle('yes');

                // Set Menu items:
                if ($group != 'server') {
                    $BxPage->setVerticalMenu('base_siteusage');
                    $BxPage->setVerticalMenuChild('base_webalizer');
                    $page_module = 'base_sitemanage';
                }
                else {
                    $BxPage->setVerticalMenu('base_serverusage');
                    $BxPage->setVerticalMenuChild('base_server_webalizer');
                    $page_module = 'base_sysmanage';
                }

                $defaultPage = "pageID";
                $block = $factory->getPagedBlock("webusageDescription", array($defaultPage));

                $block->setToggle('#');
                $block->setSideTabs(FALSE);
                $block->setShowAllTabs('#');
                $block->setDefaultPage($defaultPage);

                // Stretch the PagedBlock() to a width of 720 pixels:
                $xff = $factory->getRawHTML("Spacer", '<IMG BORDER="0" WIDTH="720" HEIGHT="0" SRC="/libImage/spaceHolder.gif">');
                $block->addFormField(
                    $xff,
                    $factory->getLabel("Spacer"),
                    $defaultPage
                );

                $warning = $i18n->getClean("[[palette.sZeroRecords]]");
                $nodata = $factory->getTextField("_", $warning, 'r');
                $nodata->setLabelType("nolabel");
                $block->addFormField(
                    $nodata,
                    $factory->getLabel(" "),
                    $defaultPage
                    );

                $page_body[] = "<p>&nbsp;</p>" . $block->toHtml();

                // Out with the page:
                return $BxPage->render($page_module, $page_body);
            }
        }
        else {

            //-- Generate page:

            // Set Menu items:
            if ($group !== 'server') {
                $BxPage->setVerticalMenu('base_siteusage');
                $BxPage->setVerticalMenuChild('base_webalizer');
                $page_module = 'base_sitemanage';
            }
            else {
                $BxPage->setVerticalMenu('base_serverusage');
                $BxPage->setVerticalMenuChild('base_server_webalizer');
                $page_module = 'base_sysmanage';
            }

            $url = "/sitestats/webalizer?inframe=1&group=" . $group;

            // Page body:
            $page_body[] = addInputForm(
                                            $i18n->get("[[base-websitestats.summaryStats]]"),
                                            array("window" => $url, "toggle" => "#"), 
                                            addIframe($url, "1200", $BxPage),
                                            "",
                                            $i18n,
                                            $BxPage,
                                            $errors
                                        );


            // Out with the page:
            return $BxPage->render($page_module, $page_body);
        }

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