<?php 
namespace Mysql\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Dbload extends BaseController {
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
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-mysql", "/mysql/dbload");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Validate GET data:
        //

        if ((!isset($get_form_data['group'])) || (!isset($get_form_data['action'])) || (!isset($get_form_data['db']))) {
            $get_form_data['group'] = '';
        }

        if (isset($get_form_data['group'])) {
            // We have a delete transaction:
            $group = $get_form_data['group'];
        }
        else {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }

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
            Log403Error("/gui/Forbidden403#2");
        }

        if (isset($get_form_data['db'])) {
            $db_name = $get_form_data['db'];
        }

        if (isset($get_form_data['action'])) {
            $action = $get_form_data['action'];
        }

        // If 'action' is not set correctly, redirect to previous page. 
        // We do have $group or we wouldn't be here.
        $possible_actions = array('load', 'waload');
        if (!in_array($action, $possible_actions)) {
            // Redirect to previous the page:
            $redirect_URL = "/mysql/vsiteMySQL?group=" . $group;
            $errors[] = ErrorMessage($i18n->get('[[palette.500text]]'), 'alert_red', 'alert');
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Prepare data:
        //

        // Get data for the Vsite:
        $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));

        // Get the MySQL settings for this Vsite:
        $vsite_MySQL = $CI->cceClient->get($vsite['OID'], "MYSQL_Vsite");

        // Get PHPVsite for this Vsite:
        $PHPVsite = $CI->cceClient->get($vsite['OID'], "PHPVsite");

        // Get the existing MySQL data from CODB's "System" object:
        $SystemMYSQL = $CI->cceClient->get($System['OID'], "mysql");

        // Get the existing "MySQL" Object:
        $AbsMYSQL = $CI->cceClient->getObject("MySQL");

        // Get Array of extra MySQL databases:
        $mysql_databases_extra = $CI->cceClient->scalar_to_array($vsite_MySQL['DBmulti']);

        //
        //-- DB Name-Check:
        //

        $WA_dbs = array();
        $BX_dbs = array();

        // For WebApps:
        if ($action == 'waload') {
            $NWA_dbList = array();
            $num_nwa_dbs = '0';
            $WebApps_Vsite = $CI->cceClient->find("WebApplications", array('group' => $group));
            if (count($WebApps_Vsite) > '0') {

                // Check if MySQL is generally working and reachable:
                $query_result = $CI->BX_MySQL_Query('mysql', 'SELECT DATABASE()');
                $mysql_status_ok = '0';
                if ($CI->getBX_MySQL_Error('code') == '0') {
                    $mysql_status_ok = '1';
                }

                $sql_exportDir = $vsite['basedir'] . '/wwwroot/webapps_backup/';
                $vsite_group = $vsite['name'];

                // Prepar WebApp DB's for presentation:
                foreach ($WebApps_Vsite as $key => $oid) {
                    $WA = $CI->cceClient->get($oid);
                    if ($mysql_status_ok == '1') {
                        // Check if we can access that WA database in question:
                        $query_result = $CI->BX_MySQL_Query($WA['sqldb'], 'SELECT DATABASE()');
                        if ($CI->getBX_MySQL_Error('code') == '0') {
                            $WA_dbs[] = $WA['sqldb'];
                        }
                        else {
                            // Someone tried to request an action for a file for a DB that doesn't exist yet. 
                            // Nice people say goodbye, or CCEd waits forever:
                            $CI->cceClient->bye();
                            $CI->serverScriptHelper->destructor();
                            Log403Error("/gui/Forbidden403#3");
                        }
                    }
                }
            }
        }

        // For regular DBs:
        if ($action == 'load') {
            $BX_dbs = array();
            $BX_dbs[] = $vsite_MySQL['DB'];
            if (is_array($mysql_databases_extra)) {
                foreach ($mysql_databases_extra as $key => $extra_db_name) {
                    $BX_dbs[] = $extra_db_name;
                }
            }
        }

        // Check if DB exists:
        if (($action == 'waload') && (!in_array($db_name, $WA_dbs))) {
            // Someone tried to request an action for a file for a DB that doesn't exist yet. 
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#4");
        }

        if (($action == 'load') && (!in_array($db_name, $BX_dbs))) {
            // Someone tried to request an action for a file for a DB that doesn't exist yet. 
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#5");
        }

        //
        //--- Handle Backups:
        //

        if ($action == 'waload') {
            $oids = $CI->cceClient->find("WebApplications", array("group" => $group, "sqldb" => $db_name));
            $webAppOid = $oids[0];

            // Do the deeds:
            $CI->cceClient->set($webAppOid, '', array('doRestoreDB' => time()));

            // No errors. Reload the entire page to load it with the updated values:
            if ((count($errors) == "0")) {
                $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=OK';
                $errors[] = ErrorMessage($i18n->get('[[base-mysql.load_OK]]'), 'alert_green', 'info_about');
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
            else {
                $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=NOTOK';
                $errors[] = ErrorMessage($i18n->get('[[base-mysql.dumpfile_missing]]'), 'alert_red', 'alert');
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }
        else {
            $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', array('doRestoreDB' => time(), 'doRestoreDBname' => $db_name));
            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();

            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // No errors. Reload the entire page to load it with the updated values:
            if ((count($errors) == "0")) {
                $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=OK';
                $errors[] = ErrorMessage($i18n->get('[[base-mysql.load_OK]]'), 'alert_green', 'info_about');
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
            else {
                $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=NOTOK';
                $errors[] = ErrorMessage($i18n->get('[[base-mysql.dumpfile_missing]]'), 'alert_red', 'alert');
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }

            $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=OK';
            $errors[] = ErrorMessage($i18n->get('[[base-mysql.load_OK]]'), 'alert_green', 'info_about');
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        // Nice people say goodbye, or CCEd waits forever:
        $redirect_URL = "/mysql/vsiteMySQL?group=" . $group;
        $BxPage->ReturnToThisPage($errors, $redirect_URL);

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