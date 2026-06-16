<?php 
namespace Mysql\Controllers;
use App\Controllers\BaseController;
use CodeIgniter\Files\File;
include_once("I18n.php");
include_once("BxPage.php");
include_once("CceError.php");
use CceError;
use I18n;
use BxPage;

class Dbupload extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-mysql", "/mysql/dbupload");
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
        $possible_actions = array('up', 'waup');
        if (!in_array($action, $possible_actions)) {
            // Redirect to previous the page:
            $redirect_URL = "/mysql/vsiteMySQL?group=" . $group;
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
        if ($action == 'waup') {
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
                            // Someone tried to upload a file for a DB that doesn't exist yet. 
                            // Nice people say goodbye, or CCEd waits forever:
                            $CI->cceClient->bye();
                            $CI->serverScriptHelper->destructor();
                            Log403Error("/gui/Forbidden403#3");
                        }
                    }
                }
            }
        }

        // Check if DB exists:
        if (($action == 'waup') && (!in_array($db_name, $WA_dbs))) {
            // Someone tried to upload a file for a DB that doesn't exist yet. 
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#4");
        }

        if (($action == 'upload') && (!in_array($db_name, $BX_dbs))) {
            // Someone tried to upload a file for a DB that doesn't exist yet. 
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#5");
        }


        //
        //-- Path Setups:
        //

        if ($action == 'waup') {
            $sql_exportDir = $vsite['basedir'] . '/wwwroot/webapps_backup/';
        }
        else {
            $sql_exportDir = $vsite['basedir'] . '/wwwroot/sql/';
        }

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_");

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

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            //
            //--- Configure and instantiate the CodeIgniter 'upload' Class:
            //

            $data = $this->request->getFile('sql_file');

            // Check if the upload is of type 'application/sql':
            $mime_type = $data->getClientMimeType();
            if (($mime_type != 'application/sql') && ($mime_type != 'text/plain')) {
                $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=NPT';
                $errors[] = ErrorMessage($i18n->get('[[base-mysql.up_NOTOK]]'), 'alert_red', 'alert');
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }

            if ($data->isValid() && ! $data->hasMoved()) {

                $newName = $data->getRandomName();
                $realNewName = '/tmp/' . $newName;
                $data->move('/tmp/', $newName);

                $target_db_file = $attributes['db_name'] . '.sql';
                $group = $attributes['group'];
                $newName = $target_db_file;

                if ($attributes['save']) {
                    if (!is_file($realNewName)) {
                        //file opening problems
                        $errors[] = ErrorMessage($i18n->get('[[base-mysql.DBuploadError1]]'), 'alert_red', 'alert');
                        $redirect_URL = "/mysql/vsiteMySQL?group=" . $group;
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                    else {
                        $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', array('fileTrigger' => time(), 'fileSource' => $realNewName, 'fileTarget' => $target_db_file));
                        $errors = array_merge($errors, $CI->cceClient->errors());
                        // Bye and redirect:
                        if (count($errors) == '0') {
                            $errors[] = ErrorMessage($i18n->get('[[base-mysql.up_OK]]'), 'alert_green', 'info_about');
                        }
                        $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=OK';
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                }
            }
            else {
                // Invalid file!
                $errors[] = ErrorMessage($i18n->get('[[base-mysql.DBuploadError1]]'), 'alert_red', 'alert');
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.
            if ($attributes['save']) {
                // actually save the information

                // use the same ui for admin server and vhosts, so assume System
                // if $attributes['group'] is empty
                if ($attributes['group'] != '') {
                    list($vsite) = $CI->cceClient->find('Vsite', array('name' => $attributes['group']));
                }
                else {
                    list($vsite) = $CI->cceClient->find('System');
                }
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Nice people say goodbye, or CCEd waits forever:
            $redirect_URL = "/mysql/vsiteMySQL?group=" . $group . '&action=' . $action . '&msg=OK';
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $URLsuffix = "?group=" . $group . '&action=' . $action . '&db=' . $db_name;
        $BxPage->setFormUrl("/mysql/dbupload$URLsuffix");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteservices');
        $BxPage->setVerticalMenuChild('base_mysql_vsite');
        $page_module = 'base_sitemanage';

        //
        // -- Add PagedBlock with Cert Info:
        //

        $header = 'DB_Upload_Header';
        list($vsite) = $CI->cceClient->find("Vsite", array("name" => $group));
        $vsiteObj = $CI->cceClient->get($vsite);
        $fqdn = $vsiteObj['fqdn'];

        $defaultPage = "basic";
        $block = $factory->getPagedBlock("DB_Upload_Header", array($defaultPage));
        $block->setCurrentLabel($factory->getLabel($header, false, array('fqdn' => $fqdn)));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Tab: basic
        //

        // Certificate Authority:
        $caIdent = $factory->getTextField('db_name', $db_name, 'r');
        $block->addFormField(
            $caIdent,
            $factory->getLabel('db_name'),
            $defaultPage
            );

        // DB Upload:
        $upload = $factory->getFileUpload('sql_file');
        $upload->setEmptyMessage($factory->i18n->get('[[base-mysql.db_file_empty]]'));
        $block->addFormField(
            $upload,
            $factory->getLabel('db_file'),
            $defaultPage
            );

        // Add some hidden fields that we need later:
        $ffsave = $factory->getTextField('save', '1', '');
        $block->addFormField(
            $ffsave,
            $factory->getLabel('save'),
            $defaultPage
        );
        $ffgroup = $factory->getTextField('group', $group, '');
        $block->addFormField(
            $ffgroup,
            $factory->getLabel('group'),
            $defaultPage
        );

        //
        //--- Add the Save/Cancel buttons:
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/mysql/vsiteMySQL?group=" . $group));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

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