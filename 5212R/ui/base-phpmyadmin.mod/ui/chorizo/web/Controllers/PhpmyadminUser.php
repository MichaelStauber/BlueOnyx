<?php 
namespace Phpmyadmin\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class PhpmyadminUser extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }

    /**
     * Index Page for this controller.
     *
     * Note to self: This page has turned into an ugly mess thanks to the
     * Reseller management. Right now Resellers can view MySQL db's of
     * Vsites they own. But only under "Personal Profile". If they use
     * "Programs" / "phpMyAdmin" under Vsite Management, they get
     * redirected to "Personal Profile" instead. Can't be assed to fix
     * that now. Live with it.
     *
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-phpmyadmin", "/phpmyadmin/signon");
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

        if ($CI->getAllowed('systemAdministrator')) {
            if ((preg_match('/phpmyadmin\/site/', uri_string())) && (isset($get_form_data['group']))) {
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
            if (isset($get_form_data['group'])) {
                $group = $get_form_data['group'];
            }
        }
        elseif ((!$CI->getAllowed('systemAdministrator')) && ($CI->getAllowed('manageSite'))) {
            // If we get here, the user is a Reseller. 
            $am_reseller = TRUE;
        }
        elseif ($CI->getAllowed('siteAdmin')) {
            $group = $user["site"];

            // Check if that's the same group he requested access to:
            if (isset($get_form_data['group'])) {
                if ($group != $get_form_data['group']) {
                    // Sneaky Bastard:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#1");
                }
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
        else {
            $CI->BX_SESSION['loginName'] = "";
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }

        // Sanity checks:
        if (!isset($db_host)) {
            $db_host = "localhost";
        }

        if (preg_match("/phpmyadmin\/login/i", uri_string())) {
            $redirect_URL = "/phpmyadmin/signon";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //-- Generate page:
        if ($am_reseller == TRUE) {
            //-- Generate page:

            // Get the Vsites he owns:
            $sites = $CI->cceClient->findx("Vsite", array("createdUser" => $CI->BX_SESSION['loginName']));
            $redir = 'site';

            $phpMyAdminList = array();

            // Get MySQL_Vsite details for all Vsites he owns:
            foreach ($sites as $key => $oid) {
                $Vsite[] = $CI->cceClient->get($oid);
                $MYSQL_Vsite[] = $CI->cceClient->get($oid, 'MYSQL_Vsite');
                $phpMyAdminList[0][$key] = $Vsite[$key]['fqdn'];
                $OwnedVsiteList[] = $Vsite[$key]['name'];

                if ($MYSQL_Vsite[$key]['enabled'] == "1") {
                    $phpMyAdminList[1][$key] = '<button class="tiny text_only has_text tooltip hover" title="' . $i18n->getHtml("[[palette.Yes]]") . '" disabled>'. $i18n->getHtml("[[palette.Yes]]") . '</button>';
                    $phpMyAdminList[2][$key] = '<button title="' . $i18n->getHtml("[[palette.modify]]") . '" class="tiny icon_only div_icon tooltip hover right link_button" data-link="/phpmyadmin/' . $redir . '?group=' . $Vsite[$key]['name'] . '&reseller=1" target="_self" formtarget="_self"><div class="ui-icon ui-icon-pencil"></div></button>';
                }
                else {
                    $phpMyAdminList[1][$key] = '<button class="tiny light text_only has_text tooltip hover" title="' . $i18n->getHtml("[[palette.No]]") . '" disabled>'. $i18n->getHtml("[[palette.No]]") . '</button>';
                    $phpMyAdminList[2][$key] = '<button title="' . $i18n->getHtml("[[palette.modify]]") . '" class="tiny icon_only div_icon tooltip hover right link_button" target="_self" formtarget="_self" disabled><div class="ui-icon ui-icon-circle-close"></div></button>';
                }
            }

            if (isset($get_form_data['group'])) {
                // We have a group URL string:
                $ugroup = $get_form_data['group'];
            }
            if (preg_match('/phpmyadmin\/site/', uri_string())) {
                if (in_array($ugroup, $OwnedVsiteList)) {

                    // Prepare Page:
                    $BxPage = $factory->getPage();

                    if (preg_match('/phpmyadmin\/site/', uri_string())) {
                        $BxPage->setVerticalMenu('base_programsSite');
                        $BxPage->setVerticalMenuChild('base_phpmyadminSite');
                        $page_module = 'base_sitemanage';
                    }
                    else {
                        $BxPage->setVerticalMenu('base_programsPersonal');
                        $BxPage->setVerticalMenuChild('base_phpmyadminPersonal');
                        $page_module = 'base_personalProfile';
                    }

                    $uri = '/phpmyadmin/pusher?group=' . $ugroup . '&pm=' . $page_module;

                    // Get the FQDN of the Vsite:
                    $resVsite = $CI->cceClient->getObject("Vsite", array("name" => $ugroup));

                    // Page body:
                    $page_body[] = addInputForm(
                                                    $i18n->get("[[base-phpmyadmin.PMA_logon]]") . ' - ' . $resVsite['fqdn'],
                                                    array("window" => $uri, "toggle" => "#"), 
                                                    addIframe($uri, "800", $BxPage),
                                                    "",
                                                    $i18n,
                                                    $BxPage,
                                                    $errors
                                                );

                    // If we know the page module, we set the cookie for it:
                    if (isset($pm)) {
                        setcookie("pm", set_value($pm));
                    }

                    // Out with the page:
                    $BxPage->render($page_module, $page_body);

                }
                else {
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#3");
                }
            }
            else {
                // Prepare Page:
                $BxPage->setFormUrl("/phpmyadmin/$redir");
                $BxPage->setErrors($errors);

                // Set Menu items:
                $defaultPage = 'pageID';

                if (preg_match('/phpmyadmin\/site/', uri_string())) {
                    $BxPage->setVerticalMenu('base_programsSite');
                    $BxPage->setVerticalMenuChild('base_phpmyadminSite');
                    $page_module = 'base_sitemanage';
                }
                else {
                    $BxPage->setVerticalMenu('base_programsPersonal');
                    $BxPage->setVerticalMenuChild('base_phpmyadminPersonal');
                    $page_module = 'base_personalProfile';
                }

                $block = $factory->getPagedBlock("phpMyAdmin", array($defaultPage));

                $block->setToggle("#");
                $block->setSideTabs(FALSE);
                $block->setDefaultPage($defaultPage);

                $scrollList = $factory->getScrollList("phpMyAdmin", array("fqdn", "MySQL_menu", " "), $phpMyAdminList); 
                $scrollList->setAlignments(array("left", "center", "center"));
                $scrollList->setDefaultSortedIndex('0');
                $scrollList->setSortOrder('ascending');
                $scrollList->setSortDisabled(array('2'));
                $scrollList->setPaginateDisabled(FALSE);
                $scrollList->setSearchDisabled(FALSE);
                $scrollList->setSelectorDisabled(FALSE);
                $scrollList->enableAutoWidth(FALSE);
                $scrollList->setInfoDisabled(FALSE);
                $scrollList->setColumnWidths(array("500", "200", "39")); // Max: 739px

                // Push out the Scrollist:
                $xff = $factory->getRawHTML("phpMyAdmin", $scrollList->toHtml());
                $block->addFormField(
                    $xff,
                    $factory->getLabel("phpMyAdmin"),
                    $defaultPage
                );

                $page_body[] = $block->toHtml();

                // If we know the page module, we set the cookie for it:
                if (isset($pm)) {
                    setcookie("pm", set_value($pm));
                }

                // Out with the page:
                return $BxPage->render($page_module, $page_body);
            }
        }
        else {
            // Prepare Page:
            $BxPage = $factory->getPage();

            // More dirty hacks to get the correct menu item set. Needed because this page
            // is presented in three different places:
            //
            // - once under 'personal_profile' / 'base_programsPersonal'
            // - once under 'base_siteadmin' / 'base_programs'
            // - once under 'server_management' / 'base_programs'
            //

            // Default: Lowest possible start point:
            $pm = 'personal';

            if ((preg_match("/phpmyadmin\/server/i", uri_string())) && ($CI->getAllowed('systemAdministrator'))) {
                $BxPage->setVerticalMenu('base_programs');
                $page_module = 'base_sysmanage';
                $pm = 'server';
            }
            if ((preg_match("/phpmyadmin\/server/i", uri_string())) && (!$CI->getAllowed('systemAdministrator'))) {
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#4");
            }
            if ((preg_match("/phpmyadmin\/site/i", uri_string())) && ($CI->getAllowed('siteAdmin'))) {
                $BxPage->setVerticalMenu('base_programsSite');
                $page_module = 'base_siteadmin';
                $pm = 'site';
            }
            if ((preg_match("/phpmyadmin\/site/i", uri_string())) && (!$CI->getAllowed('siteAdmin'))) {
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#5");
            }
            if (preg_match("/phpmyadmin\/user/i", uri_string())) {
                $BxPage->setVerticalMenu('base_programsPersonal');
                $page_module = 'base_personalProfile';
                $pm = 'personal';
            }

            if (isset($group)) {
                $uri = '/phpmyadmin/pusher?group=' . $group . '&pm=' . $pm;
            }
            else {
                $uri = '/phpmyadmin/pusher';
            }

            // Are we here via "Site Management" / "Programs"?
            if (isset($get_form_data['group'])) {
                // We have a group URL string:
                $ugroup = $get_form_data['group'];
            }

            // If $pm is unknown, we poll it from the cookie:
            if ((!isset($pm)) && (isset($_COOKIE['pm']))) {
                $pm = $_COOKIE['pm'];
            }

            // If the login credentials in the session data have changed, then we need to do a little
            // round-about. We unset the session data, redirect twice and then get the login form.
            if (!isset($page_module)) {
                $BxPage->setVerticalMenu('base_programsPersonal');
                $page_module = 'base_personalProfile';
                $uri = '/phpmyadmin/signon&pm=' . $pm;

                //    $session_name = 'SignonSession';
                //    session_name($session_name);
                //    session_start();
                //    /* Store the credentials */
                //    $_SESSION['PMA_single_signon_user'] = '';
                //    $_SESSION['PMA_single_signon_password'] = '';
                //    $_SESSION['PMA_single_signon_host'] = '';
                //    $id = session_id();
                //    /* Close that session */
                //    session_write_close();

                if (session()->get('isLoggedIn')) {
                    $data['PMA'] = array('PMA_single_signon_user' => '', 'PMA_single_signon_password' => '', 'PMA_single_signon_host' => '');
                    session()->set($data);
                }

                /* Redirect to phpMyAdmin (should use absolute URL here!) */
                $redirect_URL = "/phpmyadmin/signon";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }

            if (isset($ugroup)) {
                // Set Menu items:
                $BxPage->setVerticalMenu('base_siteadmin');
                $BxPage->setVerticalMenuChild('base_phpmyadminSite');
                $page_module = 'base_sitemanage';
                $pm = 'site';
            }

            // If we know the page module, we set the cookie for it:
            if (isset($pm)) {
                setcookie("pm", set_value($pm));
            }

            // Page body:
            $page_body[] = addInputForm(
                                            $i18n->get("[[base-phpmyadmin.PMA_logon]]"),
                                            array("window" => $uri, "toggle" => "#"), 
                                            addIframe($uri, "auto", $BxPage),
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