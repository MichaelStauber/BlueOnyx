<?php 
namespace Mysql\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class VsiteMySQL extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-mysql", "/mysql/vsiteMySQL");
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

        // Determine current user's access rights to view or edit information
        // here.  Only 'manageSite' can modify things on this page.  Site admins
        // can view it for informational purposes.
        if ($CI->getAllowed('manageSite')) {
            $is_site_admin = TRUE;
            $access_basic = 'rw';
            $access_advanced = 'rw';
        }
        elseif (($CI->getAllowed('siteAdmin')) && ($group == $CI->serverScriptHelper->loginUser['site'])) {
            $access_basic = 'rw';
            $access_advanced = 'r';
            $is_site_admin = FALSE;
        }
        else {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
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

        // Get the Timezone data:
        $Time = $CI->cceClient->get($System['OID'], "Time");

        // Get the existing "MySQL" Object:
        $AbsMYSQL = $CI->cceClient->getObject("MySQL");

        // Get Array of extra MySQL databases:
        $mysql_databases_extra = $CI->cceClient->scalar_to_array($vsite_MySQL['DBmulti']);

        //
        //--- Handle form validation:
        //

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

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            // Handle AutoFeatures:
            $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
            $cce_info = array('CCE_OID' => $vsite['OID']);
            list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
            $af_errors = $autoFeatures->handle('modifyMySQL.Vsite', $cce_info);
            $errors = array_merge($errors, $af_errors);

            // Variable cleanup to remove what we don't want to update in CODB:
            if (isset($attributes['solmysql_username'])) {
                unset($attributes['solmysql_username']);
            }
            if (isset($attributes['solmysql_pass'])) {
                unset($attributes['solmysql_pass']);
            }
            if (isset($attributes['solmysql_host'])) {
                unset($attributes['solmysql_host']);
            }
            if (isset($attributes['solmysqlPort'])) {
                unset($attributes['solmysqlPort']);
            }
            if (isset($attributes['maxDBs'])) {
                unset($attributes['maxDBs']);
            }
            if (!isset($attributes['new_db_name'])) {
                // Set 'userPermsUpdate':
                $attributes['userPermsUpdate'] = time();
            }
            if (isset($attributes['NWAdbs_Info_Text'])) {
                unset($attributes['NWAdbs_Info_Text']);
            }
            if (isset($attributes['NWA_uname'])) {
                unset($attributes['NWA_uname']);
            }
            if (isset($attributes['NWA_pass'])) {
                unset($attributes['NWA_pass']);
            }

            if (isset($attributes['solmysql_DB'])) {
                unset($attributes['solmysql_DB']);
            }
            if (isset($attributes['solmysql_Port'])) {
                unset($attributes['solmysql_Port']);
            }
            if (isset($attributes['solmysql_enabled'])) {
                unset($attributes['solmysql_enabled']);
            }
            if (isset($attributes['MySQLVsiteNotEnabled'])) {
                unset($attributes['MySQLVsiteNotEnabled']);
            }

            if ($CI->getAllowed('adminUser')) {
                if (isset($attributes['Unassigned_DBs'])) {
                    $Unassigned_DBs = $attributes['Unassigned_DBs'];
                    unset($attributes['MySQLVsiteNotEnabled']);
                }
            }

            // Special case: siteAdmin has a SAVE button, but not rights to save anything but 'new_db_name'.
            if ($access_advanced == 'r') {
                if (isset($attributes['new_db_name'])) {
                    $new_db_name = $attributes['new_db_name'];
                }
                $attributes = array();
                if (isset($new_db_name)) {
                    $attributes['new_db_name'] = $new_db_name;
                }
                if (isset($Unassigned_DBs)) {
                    $attributes['Unassigned_DBs'] = $Unassigned_DBs;
                }
            }
        }

        //
        //--- Remove existing DB:
        //
        if (isset($get_form_data['db_del'])) {
            // Check if Database exists:
            if (in_array($get_form_data['db_del'], $mysql_databases_extra)) {
                $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', array("DBdel" => $get_form_data['db_del'], 'DBmultiDel' => time()));
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $errors[] = ErrorMessage($i18n->get('[[base-mysql.sqlDel_OK]]'), 'alert_green', 'info_about');
                $redirect_URL = "/mysql/vsiteMySQL?group=$group";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
            else {
                $errors[] = ErrorMessage($i18n->get("[[base-mysql.db_not_found]]"));
            }
        }

        //
        //--- Reset User Permissions to Defaults:
        //
        if ((isset($get_form_data['reset'])) && ($access_advanced == 'rw')) {

            if ($get_form_data['reset'] == "defaults") {
                $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', array("userPermsReset" => time()));
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $redirect_URL = "/mysql/vsiteMySQL?group=$group";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }        

        //
        //--- Grant all Permissions:
        //
        if ((isset($get_form_data['perform'])) && ($access_advanced == 'rw')) {
            if ($get_form_data['perform'] == "all") {
                $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', 
                    array(
                        'SELECT' => '1', 
                        'INSERT' => '1', 
                        'UPDATE' => '1', 
                        'DELETE' => '1', 
                        'FILE' => '1', 
                        'CREATE' => '1', 
                        'ALTER' => '1', 
                        'INDEX' => '1', 
                        'DROP' => '1', 
                        'TEMPORARY' => '1', 
                        'CREATE_VIEW' => '1', 
                        'SHOW_VIEW' => '1', 
                        'CREATE_ROUTINE' => '1', 
                        'ALTER_ROUTINE' => '1', 
                        'EXECUTE' => '1', 
                        'EVENT' => '1',
                        'TRIGGER' => '1',
                        'GRANT' => '0',
                        'LOCK_TABLES' => '1',
                        'REFERENCES' => '1',
                        'MAX_UPDATES_PER_HOUR' => '0', 
                        'MAX_QUERIES_PER_HOUR' => '0', 
                        'MAX_CONNECTIONS_PER_HOUR' => '0', 
                        "userPermsUpdate" => time())
                    );
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $redirect_URL = "/mysql/vsiteMySQL?group=$group";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }  

        //
        //--- Raise Error if adding DB resulted in a name conflict:
        //
        if (isset($get_form_data['nameError'])) {
            //$errors[] = ErrorMessage($i18n->get("[[base-mysql.db_name_already_in_use]]"));
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Add new of previously unassigned DB to Vsite:
            if ((isset($attributes['new_db_name'])) || (isset($attributes['Unassigned_DBs']))) {

                if (isset($Unassigned_DBs)) {
                    if ($attributes['Unassigned_DBs'] != $i18n->get("[[palette.noItems]]")) {

                        // Adding new DB:
                        $mysql_databases_extra[] = $attributes['Unassigned_DBs'];

                        $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', array("DBmulti" => $CI->cceClient->array_to_scalar($mysql_databases_extra), 'DBmultiAdd' => time(), 'userPermsUpdate' => time()));
                        $CCEerrors = $CI->cceClient->errors();
                        foreach ($CCEerrors as $object => $objData) {
                            // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                            $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                        }

                        if (count($errors) === "0") {
                            // Bye and redirect:
                            // Return to this page and display errors - if there are any.
                            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                            $redirect_URL = "/mysql/vsiteMySQL?group=$group";
                            if ($attributes['new_db_name'] != '') {
                                $errors[] = ErrorMessage($i18n->get('[[base-mysql.sqlCreate_OK]]'), 'alert_green', 'info_about');
                            }
                            $BxPage->ReturnToThisPage($errors, $redirect_URL);
                        }
                    }
                }

                if ((count($mysql_databases_extra) + 1) >= $vsite_MySQL['maxDBs']) {
                    // Someone tried to be *really* clever and tried to add more DBs than allowed:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#cheater");
                }

                if (in_array($attributes['new_db_name'], $mysql_databases_extra)) {
                    // Name conflict! DB already exists!
                    // Bye and redirect:

                    // Return to this page and display errors - if there are any.
                    // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                    $redirect_URL = "/mysql/vsiteMySQL?group=$group&addDB=true&nameError=true";
                    $errors[] = ErrorMessage($i18n->get('[[base-mysql.db_name_already_in_use]]'), 'alert_red', 'alert');
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }
                else {
                    // Check if the DB already exists in MySQL. And for this we use MySQLi because we *really*
                    // want to know if a DB with that name already exists. CODB could be wrong on this. And 
                    // polling CODB for DB names over all Vsites is *very* costly and (like said) might not 
                    // tell the whole truth. Imagine a dickhead user specifying 'mysql' as DB name and guess
                    // what kind of hillarity would ensue.
                    $query_result = $CI->BX_MySQL_Query('', "SHOW DATABASES LIKE '" . $attributes['new_db_name'] . "'");

                    if ($CI->getBX_MySQL_Error('code') == '0') {
                        // We have no MySQL related error
                        if ($query_result->getNumRows() > 0) {
                            // A DB with that name already exists in MySQL:
                            $redirect_URL = "/mysql/vsiteMySQL?group=$group&addDB=true&nameError=true";
                            if ($attributes['new_db_name'] != '') {
                                $errors[] = ErrorMessage($i18n->get('[[base-mysql.sqlCreate_OK]]'), 'alert_green', 'info_about');
                            }
                            $BxPage->ReturnToThisPage($errors, $redirect_URL);
                        }
                    }
                    else {
                        $errors[] = ErrorMessage($i18n->get("[[base-mysql.mysql_status_incorrect]]"));
                        $redirect_URL = "/mysql/vsiteMySQL?group=$group&addDB=true";
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }

                    // Adding new DB:
                    if ($attributes['new_db_name'] != '') {
                        $mysql_databases_extra[] = $attributes['new_db_name'];
                    }

                    $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', array("DBmulti" => $CI->cceClient->array_to_scalar($mysql_databases_extra), 'DBmultiAdd' => time()));
                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }

                    if (count($errors) == "0") {
                        // Bye and redirect:

                        // Return to this page and display errors - if there are any.
                        // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                        $redirect_URL = "/mysql/vsiteMySQL?group=$group";
                        if ($attributes['new_db_name'] != '') {
                            $errors[] = ErrorMessage($i18n->get('[[base-mysql.sqlCreate_OK]]'), 'alert_green', 'info_about');
                        }
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                    else {
                        // DB with that name already exists for this Vsite:

                        // Return to this page and display errors - if there are any.
                        // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                        $redirect_URL = "/mysql/vsiteMySQL?group=$group&addDB=true";
                        if ($attributes['new_db_name'] != '') {
                            $errors[] = ErrorMessage($i18n->get('[[base-mysql.CreateFailDatabaseExists]]'), 'alert_red', 'alert');
                        }
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                }
            }

            if (isset($attributes['xsolmysql_username'])) {
                unset($attributes['xsolmysql_username']);
            }
            if (isset($attributes['xsolmysql_pass'])) {
                unset($attributes['xsolmysql_pass']);
            }
            if (isset($attributes['xsolmysql_host'])) {
                unset($attributes['xsolmysql_host']);
            }
            if (isset($attributes['xsolmysqlPort'])) {
                unset($attributes['xsolmysqlPort']);
            }
            if (isset($attributes['xmaxDBs'])) {
                unset($attributes['xmaxDBs']);
            }

            $CI->cceClient->set($vsite['OID'], 'MYSQL_Vsite', $attributes);
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // No errors during submit? Reload page:
            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $redirect_URL = "/mysql/vsiteMySQL?group=$group";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/mysql/vsiteMySQL?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteservices');
        $BxPage->setVerticalMenuChild('base_mysql_vsite');
        $page_module = 'base_sitemanage';

        $defaultPage = "VsiteDBtab";

        if (($vsite_MySQL["enabled"] == "0") || (isset($get_form_data['addDB']))) {
            $block = $factory->getPagedBlock("mysql_vsite_head", array($defaultPage));
        }
        else {
            $block = $factory->getPagedBlock("mysql_vsite_head", array($defaultPage, 'MySQLuserRights'));
        }
        $block->setLabel($factory->getLabel('mysql_vsite_head', false, array('vsite' => $vsite['fqdn'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        //
        //--- Add AutoFeatures:
        //

        $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
        $cce_info = array('CCE_OID' => $vsite['OID'], 'FIELD_ACCESS' => $access_advanced, 'IS_SITE_ADMIN' => $is_site_admin, 'group' => $group);
        list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
        $cce_info['PAGED_BLOCK_DEFAULT_PAGE'] = $defaultPage;
        $autoFeatures->display($block, 'modifyMySQL.Vsite', $cce_info);

        //
        //-- Assemble info about databases that are present for this Vsite:
        //
        $dbList = array();
        $mysql_databases = array();
        $num_dbs = '0';
        $sql_exportDir = $vsite['basedir'] . '/wwwroot/sql/';
        $vsite_group = $vsite['name'];

        $dbList[3][$num_dbs] = '';

        if ($vsite_MySQL['DB'] != "") {
            $mysql_databases[] = $vsite_MySQL['DB'];
            $dbList[0][$num_dbs] = $vsite_MySQL['DB'];
            $file_db = $sql_exportDir . $vsite_MySQL['DB'] . '.sql';

            $upload_button = $factory->getModifyButton('/mysql/dbupload?group=' . $group . '&action=up&db=' . $vsite_MySQL['DB']);
            $upload_button->setDescription($i18n->getHtml("dbUpload"));
            $upload_button->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $upload_button->setButtonSize("xs");
            }
            $upload_button->setIcon('fa fa-upload');
            $upload_button->setButtonSpecialStyle('square_animated');
            $upload_button->setImageOnly(TRUE);
            $upload_button->setTarget('_self');
            $dbList[3][$num_dbs] .= $upload_button->toHtml();

            if (is_file($file_db)) {
                $db_file_info = get_file_info($file_db);
                $db_size = simplify_number($db_file_info['size'], "KB", "2");
                $file_size = $db_file_info['size'];
                $dbList[1][$num_dbs] = $db_size;
                $dbList[2][$num_dbs] = date('Y-m-d H:i:s', $db_file_info['date']);
                if ($file_size > '0') {
                    $restore_button = $factory->getModifyButton('/mysql/dbload?group=' . $group . '&action=load&db=' . $vsite_MySQL['DB']);
                    $restore_button->setDescription($i18n->getHtml("dbLoad"));
                    $restore_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $restore_button->setButtonSize("xs");
                    }
                    $restore_button->setIcon('fa fa-repeat');
                    $restore_button->setButtonSpecialStyle('square_animated');
                    $restore_button->setImageOnly(TRUE);
                    $restore_button->setTarget('_self');
                    $dbList[3][$num_dbs] .= $restore_button->toHtml();
                }
                else {
                    $restore_button = $factory->getModifyButton('javascript:void(0)');
                    $restore_button->setDescription($i18n->getHtml("dbLoad_noFile"));
                    $restore_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $restore_button->setButtonSize("xs");
                    }
                    $restore_button->setIcon('fa fa-repeat');
                    $restore_button->setButtonSpecialStyle('square_animated');
                    $restore_button->setImageOnly(TRUE);
                    $restore_button->setTarget('_self');
                    $restore_button->setButtonDisabled(TRUE);
                    $restore_button->setButtonColor('default');
                    $dbList[3][$num_dbs] .= $restore_button->toHtml();
                }

                $createBackup_button = $factory->getModifyButton('/mysql/dbbackup?group=' . $group . '&action=back&db=' . $vsite_MySQL['DB']);
                $createBackup_button->setDescription($i18n->getHtml("dbBackup"));
                $createBackup_button->setButtonSize("small");
                if ($BX_SESSION['gui_theme'] === 'adminica') {
                    $createBackup_button->setButtonSize("xs");
                }
                $createBackup_button->setIcon('fa fa-cloud-download');
                $createBackup_button->setButtonSpecialStyle('square_animated');
                $createBackup_button->setImageOnly(TRUE);
                $createBackup_button->setTarget('_self');
                $dbList[3][$num_dbs] .= $createBackup_button->toHtml();

                if ($file_size > '0') {
                    $sql_download_button = $factory->getModifyButton('/mysql/dbdownload?group=' . $group . '&action=down&db=' . $vsite_MySQL['DB']);
                    $sql_download_button->setDescription($i18n->getHtml("dbDownload"));
                    $sql_download_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $sql_download_button->setButtonSize("xs");
                    }
                    $sql_download_button->setIcon('fa fa-download');
                    $sql_download_button->setButtonSpecialStyle('square_animated');
                    $sql_download_button->setImageOnly(TRUE);
                    $sql_download_button->setTarget('_self');
                    $dbList[3][$num_dbs] .= $sql_download_button->toHtml();
                }
                else {
                    $sql_download_button = $factory->getModifyButton('javascript:void(0)');
                    $sql_download_button->setDescription($i18n->getHtml("dbDownload_noFile"));
                    $sql_download_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $sql_download_button->setButtonSize("xs");
                    }
                    $sql_download_button->setIcon('fa fa-download');
                    $sql_download_button->setButtonSpecialStyle('square_animated');
                    $sql_download_button->setImageOnly(TRUE);
                    $sql_download_button->setTarget('_self');
                    $sql_download_button->setButtonDisabled(TRUE);
                    $restore_button->setButtonColor('default');
                    $dbList[3][$num_dbs] .= $sql_download_button->toHtml();
                }
            }
            else {
                $dbList[1][$num_dbs] = './.';
                $dbList[2][$num_dbs] = './.';

                $restore_button = $factory->getModifyButton('javascript:void(0)');
                $restore_button->setDescription($i18n->getHtml("dbLoad_noFile"));
                $restore_button->setButtonSize("small");
                if ($BX_SESSION['gui_theme'] === 'adminica') {
                    $restore_button->setButtonSize("xs");
                }
                $restore_button->setIcon('fa fa-repeat');
                $restore_button->setButtonSpecialStyle('square_animated');
                $restore_button->setImageOnly(TRUE);
                $restore_button->setTarget('_self');
                $restore_button->setButtonDisabled(TRUE);
                $restore_button->setButtonColor('default');
                $dbList[3][$num_dbs] .= $restore_button->toHtml();

                $createBackup_button = $factory->getModifyButton('/mysql/dbbackup?group=' . $group . '&action=back&db=' . $vsite_MySQL['DB']);
                $createBackup_button->setDescription($i18n->getHtml("dbBackup"));
                $createBackup_button->setButtonSize("small");
                if ($BX_SESSION['gui_theme'] === 'adminica') {
                    $createBackup_button->setButtonSize("xs");
                }
                $createBackup_button->setIcon('fa fa-cloud-download');
                $createBackup_button->setButtonSpecialStyle('square_animated');
                $createBackup_button->setImageOnly(TRUE);
                $createBackup_button->setTarget('_self');
                $dbList[3][$num_dbs] .= $createBackup_button->toHtml();

                $sql_download_button = $factory->getModifyButton('javascript:void(0)');
                $sql_download_button->setDescription($i18n->getHtml("dbDownload_noFile"));
                $sql_download_button->setButtonSize("small");
                if ($BX_SESSION['gui_theme'] === 'adminica') {
                    $sql_download_button->setButtonSize("xs");
                }
                $sql_download_button->setIcon('fa fa-download');
                $sql_download_button->setButtonSpecialStyle('square_animated');
                $sql_download_button->setImageOnly(TRUE);
                $sql_download_button->setTarget('_self');
                $sql_download_button->setButtonDisabled(TRUE);
                $restore_button->setButtonColor('default');
                $dbList[3][$num_dbs] .= $sql_download_button->toHtml();
            }

            $delete_db_button = $factory->getRemoveButton('javascript:void(0)', "dbRemoveNotPoss");
            $delete_db_button->setDescription($i18n->getHtml("dbRemoveNotPoss"));
            $delete_db_button->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $delete_db_button->setButtonSize("xs");
            }
            $delete_db_button->setIcon('fa fa-trash-o');
            $delete_db_button->setButtonSpecialStyle('square_animated');
            $delete_db_button->setButtonColor('danger');
            $delete_db_button->setImageOnly(TRUE);
            $delete_db_button->setButtonDisabled(TRUE);
            $dbList[3][$num_dbs] .= $delete_db_button->toHtml();
            $num_dbs++;
        }
        if ($vsite_MySQL['DBmulti'] != "") {
            if (is_array($mysql_databases_extra)) {
                foreach ($mysql_databases_extra as $key => $extra_db_name) {
                    $mysql_databases[] = $extra_db_name;
                    $file_db = $sql_exportDir . $extra_db_name . '.sql';
                    $dbList[0][$num_dbs] = $extra_db_name;

                    $upload_button = $factory->getModifyButton('/mysql/dbupload?group=' . $group . '&action=up&db=' . $extra_db_name);
                    $upload_button->setDescription($i18n->getHtml("dbUpload"));
                    $upload_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $upload_button->setButtonSize("xs");
                    }
                    $upload_button->setIcon('fa fa-upload');
                    $upload_button->setButtonSpecialStyle('square_animated');
                    $upload_button->setImageOnly(TRUE);
                    $upload_button->setTarget('_self');
                    $dbList[3][$num_dbs] = $upload_button->toHtml();

                    if (is_file($file_db)) {
                        $db_file_info = get_file_info($file_db);
                        $file_size = $db_file_info['size'];
                        $db_size = simplify_number($db_file_info['size'], "KB", "2");
                        $dbList[1][$num_dbs] = $db_size;
                        $dbList[2][$num_dbs] = date('Y-m-d H:i:s', $db_file_info['date']);
                        if ($file_size > '0') {

                            $restore_button = $factory->getModifyButton('/mysql/dbload?group=' . $group . '&action=load&db=' . $extra_db_name);
                            $restore_button->setDescription($i18n->getHtml("dbLoad"));
                            $restore_button->setButtonSize("small");
                            if ($BX_SESSION['gui_theme'] === 'adminica') {
                                $restore_button->setButtonSize("xs");
                            }
                            $restore_button->setIcon('fa fa-repeat');
                            $restore_button->setButtonSpecialStyle('square_animated');
                            $restore_button->setImageOnly(TRUE);
                            $restore_button->setTarget('_self');
                            $dbList[3][$num_dbs] .= $restore_button->toHtml();

                        }
                        else {

                            $restore_button = $factory->getModifyButton('javascript:void(0)');
                            $restore_button->setDescription($i18n->getHtml("dbLoad_noFile"));
                            $restore_button->setButtonSize("small");
                            if ($BX_SESSION['gui_theme'] === 'adminica') {
                                $restore_button->setButtonSize("xs");
                            }
                            $restore_button->setIcon('fa fa-repeat');
                            $restore_button->setButtonSpecialStyle('square_animated');
                            $restore_button->setImageOnly(TRUE);
                            $restore_button->setTarget('_self');
                            $restore_button->setButtonDisabled(TRUE);
                            $restore_button->setButtonColor('default');
                            $dbList[3][$num_dbs] .= $restore_button->toHtml();

                        }

                        $createBackup_button = $factory->getModifyButton('/mysql/dbbackup?group=' . $group . '&action=back&db=' . $extra_db_name);
                        $createBackup_button->setDescription($i18n->getHtml("dbBackup"));
                        $createBackup_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $createBackup_button->setButtonSize("xs");
                        }
                        $createBackup_button->setIcon('fa fa-cloud-download');
                        $createBackup_button->setButtonSpecialStyle('square_animated');
                        $createBackup_button->setImageOnly(TRUE);
                        $createBackup_button->setTarget('_self');
                        $dbList[3][$num_dbs] .= $createBackup_button->toHtml();

                        if ($file_size > '0') {
                            $sql_download_button = $factory->getModifyButton('/mysql/dbdownload?group=' . $group . '&action=down&db=' . $extra_db_name);
                            $sql_download_button->setDescription($i18n->getHtml("dbDownload"));
                            $sql_download_button->setButtonSize("small");
                            if ($BX_SESSION['gui_theme'] === 'adminica') {
                                $sql_download_button->setButtonSize("xs");
                            }
                            $sql_download_button->setIcon('fa fa-download');
                            $sql_download_button->setButtonSpecialStyle('square_animated');
                            $sql_download_button->setImageOnly(TRUE);
                            $sql_download_button->setTarget('_self');
                            $dbList[3][$num_dbs] .= $sql_download_button->toHtml();
                        }
                        else {

                            $sql_download_button = $factory->getModifyButton('javascript:void(0)');
                            $sql_download_button->setDescription($i18n->getHtml("dbDownload_noFile"));
                            $sql_download_button->setButtonSize("small");
                            if ($BX_SESSION['gui_theme'] === 'adminica') {
                                $sql_download_button->setButtonSize("xs");
                            }
                            $sql_download_button->setIcon('fa fa-download');
                            $sql_download_button->setButtonSpecialStyle('square_animated');
                            $sql_download_button->setImageOnly(TRUE);
                            $sql_download_button->setTarget('_self');
                            $sql_download_button->setButtonDisabled(TRUE);
                            $restore_button->setButtonColor('default');
                            $dbList[3][$num_dbs] .= $sql_download_button->toHtml();
                        }
                    }
                    else {
                        $dbList[1][$num_dbs] = './.';
                        $dbList[2][$num_dbs] = './.';

                        $restore_button = $factory->getModifyButton('javascript:void(0)');
                        $restore_button->setDescription($i18n->getHtml("dbLoad_noFile"));
                        $restore_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $restore_button->setButtonSize("xs");
                        }
                        $restore_button->setIcon('fa fa-repeat');
                        $restore_button->setButtonSpecialStyle('square_animated');
                        $restore_button->setImageOnly(TRUE);
                        $restore_button->setTarget('_self');
                        $restore_button->setButtonDisabled(TRUE);
                        $restore_button->setButtonColor('default');
                        $dbList[3][$num_dbs] .= $restore_button->toHtml();

                        $createBackup_button = $factory->getModifyButton('/mysql/dbbackup?group=' . $group . '&action=back&db=' . $extra_db_name);
                        $createBackup_button->setDescription($i18n->getHtml("dbBackup"));
                        $createBackup_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $createBackup_button->setButtonSize("xs");
                        }
                        $createBackup_button->setIcon('fa fa-cloud-download');
                        $createBackup_button->setButtonSpecialStyle('square_animated');
                        $createBackup_button->setImageOnly(TRUE);
                        $createBackup_button->setTarget('_self');
                        $dbList[3][$num_dbs] .= $createBackup_button->toHtml();

                        $sql_download_button = $factory->getModifyButton('javascript:void(0)');
                        $sql_download_button->setDescription($i18n->getHtml("dbDownload_noFile"));
                        $sql_download_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $sql_download_button->setButtonSize("xs");
                        }
                        $sql_download_button->setIcon('fa fa-download');
                        $sql_download_button->setButtonSpecialStyle('square_animated');
                        $sql_download_button->setImageOnly(TRUE);
                        $sql_download_button->setTarget('_self');
                        $sql_download_button->setButtonDisabled(TRUE);
                        $restore_button->setButtonColor('default');
                        $dbList[3][$num_dbs] .= $sql_download_button->toHtml();

                    }

                    $delete_db_button = $factory->getRemoveButton('/mysql/vsiteMySQL?group=' . $group . '&db_del=' . $extra_db_name, "dbRemove");
                    $delete_db_button->setDescription($i18n->getHtml("dbRemove"));
                    $delete_db_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $delete_db_button->setButtonSize("xs");
                    }
                    $delete_db_button->setIcon('fa fa-trash-o');
                    $delete_db_button->setButtonSpecialStyle('square_animated');
                    $delete_db_button->setButtonColor('danger');
                    $delete_db_button->setTarget('_self');
                    $delete_db_button->setImageOnly(TRUE);
                    $delete_db_button->addButtonClass('dialog_button');
                    $delete_db_button->setModal('dialog', '/mysql/vsiteMySQL?group=' . $group . '&db_del=' . $extra_db_name);
                    $dbList[3][$num_dbs] .= $delete_db_button->toHtml();
                    $num_dbs++;
                }
            }
        }

        //
        //-- Assemble output depending on which transaction needs to be performed:
        //

        if ($vsite_MySQL["enabled"] == "0") {
            // Show error message box if MySQL is not enabled for this vsite:
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $mysqloff_statusbox = $factory->getTextField("MySQLVsiteNotEnabled", $i18n->get("MySQLVsiteNotEnabled"), 'r');
            }
            else {
                $mysqloff_statusbox = $factory->getHtmlField("MySQLVsiteNotEnabled", $i18n->get("MySQLVsiteNotEnabled"), 'r');
            }
            $mysqloff_statusbox->setLabelType("nolabel");
            $block->addFormField(
                $mysqloff_statusbox,
                $factory->getLabel(" "),
                $defaultPage
                );
        }
        elseif (isset($get_form_data['addDB'])) {
            if ($get_form_data['addDB'] == "true") {

                // Run /usr/sausalito/sbin/list_mysql_dbs.pl to check what unassigned DBs we have:
                if ($CI->getAllowed('adminUser')) {
                    $json_output = '';
                    $dummy_DB_selected = array('0' => $i18n->get("[[palette.noItems]]"));
                    $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/list_mysql_dbs.pl", $json_output, 'root', $BX_SESSION['sessionId']);
                    if ($ret == '0') {
                        $Unassigned_DBs = json_decode($json_output, true);
                        if (count($Unassigned_DBs) > '0') {
                            $Unassigned_DBs = array_merge($dummy_DB_selected, $Unassigned_DBs);
                            $Unassigned_DBs_select = $factory->getMultiChoice("Unassigned_DBs", array_values($Unassigned_DBs)); 
                            $Unassigned_DBs_select->setSelected($i18n->get("[[palette.noItems]]"), true); 
                            $block->addFormField($Unassigned_DBs_select, $factory->getLabel("Unassigned_DBs"), $defaultPage); 
                        }
                    }
                }
                else {
                    $Unassigned_DBs = array();
                }

                if ($num_dbs < $vsite_MySQL['maxDBs']) {
                    $ndbField = $factory->getTextField("new_db_name", '', 'rw');
                    $ndbField->setMaxLength("32");
                    $ndbField->setOptional(TRUE);
                    if (count($Unassigned_DBs) > '0') {
                        if ($CI->getAllowed('adminUser')) {
                            $ndbField->setOptional(True);
                        }
                    }
                    $block->addFormField(
                        $ndbField,
                        $factory->getLabel("new_db_name"),
                        $defaultPage
                    );
                }
                else {
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403");
                }
            }
            else {
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403");
            }
        }
        else {
            //
            //-- Show settings: Databases:
            //

            // Add divider:
            $xxx = $factory->addBXDivider("DIVIDER_Vsite_condetails", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("DIVIDER_Vsite_condetails", false),
                    $defaultPage
                    );

            $xxx = $factory->getTextField("xsolmysql_username", $vsite_MySQL["username"], 'r');
            $block->addFormField(
                $xxx,
                $factory->getLabel("solmysql_username"),
                $defaultPage
            );

            $xxx = $factory->getTextField("xsolmysql_pass", $vsite_MySQL["pass"], 'r');
            $block->addFormField(
                $xxx,
                $factory->getLabel("solmysql_pass"),
                $defaultPage
            );

            $xxx = $factory->getTextField("xsolmysql_host", $vsite_MySQL["host"], 'r');
            $block->addFormField(
                $xxx,
                $factory->getLabel("solmysql_host"),
                $defaultPage
            );

            $xxx = $factory->getTextField("xsolmysqlPort", $vsite_MySQL["port"], 'r');
            $block->addFormField(
                $xxx,
                $factory->getLabel("solmysqlPort"),
                $defaultPage
            );

            $xxx = $factory->getTextField("xmaxDBs", $vsite_MySQL["maxDBs"], 'r');
            $block->addFormField(
                $xxx,
                $factory->getLabel("maxDBs"),
                $defaultPage
            );

            // Add divider:
            $xxx = $factory->addBXDivider("DIVIDER_Vsite_DBlist", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("DIVIDER_Vsite_DBlist", false),
                    $defaultPage
                    );

            // Button: Add Database:
            if ($num_dbs < $vsite_MySQL['maxDBs']) {
                $addDatabaseButton = $factory->getAddButton("/mysql/vsiteMySQL?group=$group&addDB=true", '[[base-mysql.DBaddbut_help]]');
                $buttonContainerAddDB = $factory->getButtonContainer("", array($addDatabaseButton));
                $xxx = $factory->getRawHTML("DBaddbut", $buttonContainerAddDB->toHtml());
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("DBaddbut"),
                    $defaultPage
                );
            }

            // Assemble ScrollList for MySQL database names:
            $scrollList = $factory->getScrollList("MySQLdbList", array("db_name", "size", "date", "action"), $dbList); 
            $scrollList->setAlignments(array("left", "right", "right", "right"));
            $scrollList->setDefaultSortedIndex('0');
            $scrollList->setSortOrder('ascending');
            $scrollList->setSortDisabled(array('1'));
            $scrollList->setPaginateDisabled(FALSE);
            $scrollList->setSearchDisabled(FALSE);
            $scrollList->setSelectorDisabled(FALSE);
            $scrollList->enableAutoWidth(FALSE);
            $scrollList->setInfoDisabled(FALSE);
            if ($BX_SESSION['gui_theme'] === 'elmer') {
                $scrollList->setColumnWidths(array("30%", "15%", "15%", "40%")); // Max: 739px
            }
            else {
                $scrollList->setColumnWidths(array("370", "50", "160", "150")); // Max: 739px
            }

            // Push out the Scrollist:
            $xxx = $factory->getRawHTML("MySQLdbList", $scrollList->toHtml());
            $block->addFormField(
                $xxx,
                $factory->getLabel("MySQLdbList"),
                $defaultPage
            );

            //
            //-- WebApp related Databases:
            //

            $NWA_dbList = array();
            $num_nwa_dbs = '0';

            $mysql_status_ok = '1';

            $WebApps_Vsite = $CI->cceClient->find("WebApplications", array('group' => $group));
            if (count($WebApps_Vsite) > '0') {

                // Check if MySQL is generally working and reachable:
                $query_result = $CI->BX_MySQL_Query('mysql', 'SELECT DATABASE();');
                $mysql_status_ok = '0';
                if ($CI->getBX_MySQL_Error('code') == '0') {
                    $mysql_status_ok = '1';
                }

                $sql_exportDir = $vsite['basedir'] . '/wwwroot/webapps_backup/';
                $vsite_group = $vsite['name'];

                // Prepar WebApp DB's for presentation:
                $mysql_errors = array();
                foreach ($WebApps_Vsite as $key => $oid) {
                    $WA = $CI->cceClient->get($oid);

                    if ($mysql_status_ok == '1') {
                        // Check if we can access that WA database in question:
                        $query_result = $CI->BX_MySQL_Query($WA['sqldb'], 'SELECT DATABASE();');
                        if ($CI->getBX_MySQL_Error('code') == '0') {
                            $NWA_dbList[0][$num_nwa_dbs] = $WA['appname'];
                            $NWA_dbList[1][$num_nwa_dbs] = $WA['sqldb'];

                            if (is_file($sql_exportDir . $WA['sqldb'] . '.sql')) {
                                $db_file_info = get_file_info($sql_exportDir . $WA['sqldb'] . '.sql');
                                $db_size = simplify_number($db_file_info['size'], "KB", "2");
                                $NWA_dbList[2][$num_nwa_dbs] = $db_size;
                                $NWA_dbList[3][$num_nwa_dbs] = date('Y-m-d H:i:s', $db_file_info['date']);
                            }
                            else {
                                $NWA_dbList[2][$num_nwa_dbs] = './.';
                                $NWA_dbList[3][$num_nwa_dbs] = './.';
                            }

                            // Import/Export buttons:
                            $NWA_dbList[4][$num_nwa_dbs] = '';
                            $file_db = $sql_exportDir . $WA['sqldb'] . '.sql';

                            $upload_button = $factory->getModifyButton('/mysql/dbupload?group=' . $group . '&action=waup&db=' . $WA['sqldb']);
                            $upload_button->setDescription($i18n->getHtml("dbUpload"));
                            $upload_button->setButtonSize("small");
                            if ($BX_SESSION['gui_theme'] === 'adminica') {
                                $upload_button->setButtonSize("xs");
                            }
                            $upload_button->setIcon('fa fa-upload');
                            $upload_button->setButtonSpecialStyle('square_animated');
                            $upload_button->setImageOnly(TRUE);
                            $upload_button->setTarget('_self');
                            $NWA_dbList[4][$num_nwa_dbs] .= $upload_button->toHtml();

                            if (is_file($file_db)) {
                                if ($db_file_info['size'] > '0') {

                                    $restore_button = $factory->getModifyButton('/mysql/dbload?group=' . $group . '&action=waload&db=' . $WA['sqldb']);
                                    $restore_button->setDescription($i18n->getHtml("dbLoad"));
                                    $restore_button->setButtonSize("small");
                                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                                        $restore_button->setButtonSize("xs");
                                    }
                                    $restore_button->setIcon('fa fa-repeat');
                                    $restore_button->setButtonSpecialStyle('square_animated');
                                    $restore_button->setImageOnly(TRUE);
                                    $restore_button->setTarget('_self');
                                    $NWA_dbList[4][$num_nwa_dbs] .= $restore_button->toHtml();

                                }
                                else {

                                    $restore_button = $factory->getModifyButton('javascript:void(0)');
                                    $restore_button->setDescription($i18n->getHtml("dbLoad_noFile"));
                                    $restore_button->setButtonSize("small");
                                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                                        $restore_button->setButtonSize("xs");
                                    }
                                    $restore_button->setIcon('fa fa-repeat');
                                    $restore_button->setButtonSpecialStyle('square_animated');
                                    $restore_button->setImageOnly(TRUE);
                                    $restore_button->setTarget('_self');
                                    $restore_button->setButtonDisabled(TRUE);
                                    $restore_button->setButtonColor('default');
                                    $NWA_dbList[4][$num_nwa_dbs] .= $restore_button->toHtml();

                                }

                                $createBackup_button = $factory->getModifyButton('/mysql/dbbackup?group=' . $group . '&action=waback&db=' . $WA['sqldb']);
                                $createBackup_button->setDescription($i18n->getHtml("dbBackup"));
                                $createBackup_button->setButtonSize("small");
                                if ($BX_SESSION['gui_theme'] === 'adminica') {
                                    $createBackup_button->setButtonSize("xs");
                                }
                                $createBackup_button->setIcon('fa fa-cloud-download');
                                $createBackup_button->setButtonSpecialStyle('square_animated');
                                $createBackup_button->setImageOnly(TRUE);
                                $createBackup_button->setTarget('_self');
                                $NWA_dbList[4][$num_nwa_dbs] .= $createBackup_button->toHtml();

                                if ($db_file_info['size'] > '0') {

                                    $sql_download_button = $factory->getModifyButton('/mysql/dbdownload?group=' . $group . '&action=wadown&db=' . $WA['sqldb']);
                                    $sql_download_button->setDescription($i18n->getHtml("dbDownload"));
                                    $sql_download_button->setButtonSize("small");
                                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                                        $sql_download_button->setButtonSize("xs");
                                    }
                                    $sql_download_button->setIcon('fa fa-download');
                                    $sql_download_button->setButtonSpecialStyle('square_animated');
                                    $sql_download_button->setImageOnly(TRUE);
                                    $sql_download_button->setTarget('_self');
                                    $NWA_dbList[4][$num_nwa_dbs] .= $sql_download_button->toHtml();

                                }
                                else {

                                    $sql_download_button = $factory->getModifyButton('javascript:void(0)');
                                    $sql_download_button->setDescription($i18n->getHtml("dbDownload_noFile"));
                                    $sql_download_button->setButtonSize("small");
                                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                                        $sql_download_button->setButtonSize("xs");
                                    }
                                    $sql_download_button->setIcon('fa fa-download');
                                    $sql_download_button->setButtonSpecialStyle('square_animated');
                                    $sql_download_button->setImageOnly(TRUE);
                                    $sql_download_button->setTarget('_self');
                                    $sql_download_button->setButtonDisabled(TRUE);
                                    $restore_button->setButtonColor('default');
                                    $NWA_dbList[4][$num_nwa_dbs] .= $sql_download_button->toHtml();

                                }
                            }
                            else {

                                $restore_button = $factory->getModifyButton('javascript:void(0)');
                                $restore_button->setDescription($i18n->getHtml("dbLoad_noFile"));
                                $restore_button->setButtonSize("small");
                                if ($BX_SESSION['gui_theme'] === 'adminica') {
                                    $restore_button->setButtonSize("xs");
                                }
                                $restore_button->setIcon('fa fa-repeat');
                                $restore_button->setButtonSpecialStyle('square_animated');
                                $restore_button->setImageOnly(TRUE);
                                $restore_button->setTarget('_self');
                                $restore_button->setButtonDisabled(TRUE);
                                $restore_button->setButtonColor('default');
                                $NWA_dbList[4][$num_nwa_dbs] .= $restore_button->toHtml();

                                $createBackup_button = $factory->getModifyButton('/mysql/dbbackup?group=' . $group . '&action=waback&db=' . $WA['sqldb']);
                                $createBackup_button->setDescription($i18n->getHtml("dbBackup"));
                                $createBackup_button->setButtonSize("small");
                                if ($BX_SESSION['gui_theme'] === 'adminica') {
                                    $createBackup_button->setButtonSize("xs");
                                }
                                $createBackup_button->setIcon('fa fa-cloud-download');
                                $createBackup_button->setButtonSpecialStyle('square_animated');
                                $createBackup_button->setImageOnly(TRUE);
                                $createBackup_button->setTarget('_self');
                                $NWA_dbList[4][$num_nwa_dbs] .= $createBackup_button->toHtml();

                                $sql_download_button = $factory->getModifyButton('javascript:void(0)');
                                $sql_download_button->setDescription($i18n->getHtml("dbDownload_noFile"));
                                $sql_download_button->setButtonSize("small");
                                if ($BX_SESSION['gui_theme'] === 'adminica') {
                                    $sql_download_button->setButtonSize("xs");
                                }
                                $sql_download_button->setIcon('fa fa-download');
                                $sql_download_button->setButtonSpecialStyle('square_animated');
                                $sql_download_button->setImageOnly(TRUE);
                                $sql_download_button->setTarget('_self');
                                $sql_download_button->setButtonDisabled(TRUE);
                                $restore_button->setButtonColor('default');
                                $NWA_dbList[4][$num_nwa_dbs] .= $sql_download_button->toHtml();

                            }

                            $delete_db_button = $factory->getRemoveButton('javascript:void(0)', "dbRemoveNotPoss");
                            $delete_db_button->setDescription($i18n->getHtml("dbRemoveNotPoss"));
                            $delete_db_button->setButtonSize("small");
                            if ($BX_SESSION['gui_theme'] === 'adminica') {
                                $delete_db_button->setButtonSize("xs");
                            }
                            $delete_db_button->setIcon('fa fa-trash-o');
                            $delete_db_button->setButtonSpecialStyle('square_animated');
                            $delete_db_button->setButtonColor('danger');
                            $delete_db_button->setImageOnly(TRUE);
                            $delete_db_button->setButtonDisabled(TRUE);
                            $NWA_dbList[4][$num_nwa_dbs] .= $delete_db_button->toHtml();

                            $num_nwa_dbs++;

                            // Grant rights on NWA-DBs:
                            $sql = "CREATE USER '" . $vsite_MySQL["username"] . "'@'" . $vsite_MySQL["host"] . "' IDENTIFIED BY '" . $vsite_MySQL["pass"] . "';";
                            $query_result = $CI->BX_MySQL_Query('mysql', $sql);
                            $mysql_errors = array_merge($mysql_errors, $CI->getBX_MySQL_Error());

                            $sql = "GRANT USAGE ON * . * TO '" . $vsite_MySQL["username"] . "'@'" . $vsite_MySQL["host"] . "' IDENTIFIED BY '" . $vsite_MySQL["pass"] . "' WITH MAX_QUERIES_PER_HOUR 0 MAX_CONNECTIONS_PER_HOUR 0 MAX_UPDATES_PER_HOUR 0 MAX_USER_CONNECTIONS 0 ;";
                            $query_result = $CI->BX_MySQL_Query('mysql', $sql);
                            $mysql_errors = array_merge($mysql_errors, $CI->getBX_MySQL_Error());

                            $sql = "GRANT ALL PRIVILEGES ON `" . $WA['sqldb'] . "` . * TO '" . $vsite_MySQL["username"] . "'@'" . $vsite_MySQL["host"] . "';";
                            $query_result = $CI->BX_MySQL_Query('mysql', $sql);
                            $mysql_errors = array_merge($mysql_errors, $CI->getBX_MySQL_Error());
                        }
                        else {
                            // Remove 'WebApplications' Objects for which we no longer have MySQL databases:
                            $ret = $CI->cceClient->destroy($oid);
                        }
                    }
                }
                if (count($mysql_errors) > '0') {
                    $errors[] = ErrorMessage("WebApplications related MySQL statements failed!");
                }

                // Get 'Vsite' . 'Compass_webapps' if present:
                $VsiteWAobj = $CI->cceClient->get($vsite['OID'], "Compass_webapps");

                // Add divider:
                $xxx = $factory->addBXDivider("DIVIDER_NWA_DBlist", "");
                $block->addFormField(
                        $xxx,
                        $factory->getLabel("DIVIDER_NWA_DBlist", false),
                        $defaultPage
                        );

                if ((isset($VsiteWAobj['sql_user'])) && (isset($VsiteWAobj['sql_pass']))) {

                    $my_TEXT = $i18n->getClean("[[base-mysql.NWAdbs_Info_Text]]");
                    $infotext = $factory->getHtmlField("NWAdbs_Info_Text", $my_TEXT, 'r');
                    $infotext->setLabelType("nolabel");
                    $block->addFormField(
                            $infotext,
                            $factory->getLabel(" ", false),
                            $defaultPage
                    );

                    $xxx = $factory->getTextField("NWA_uname", $VsiteWAobj["sql_user"], 'r');
                    $block->addFormField(
                        $xxx,
                        $factory->getLabel("NWA_uname"),
                        $defaultPage
                    );

                    $xxx = $factory->getTextField("NWA_pass", $VsiteWAobj["sql_pass"], 'r');
                    $block->addFormField(
                        $xxx,
                        $factory->getLabel("NWA_pass"),
                        $defaultPage
                    );
                }

                // Assemble ScrollList for NWA MySQL database names:
                $NWA_scrollList = $factory->getScrollList("NWAdbList", array("application", "db_name", "size", "date", "action"), $NWA_dbList); 
                $NWA_scrollList->setAlignments(array("left", "left", "right", "right", "right"));
                $NWA_scrollList->setDefaultSortedIndex('0');
                $NWA_scrollList->setSortOrder('ascending');
                $NWA_scrollList->setSortDisabled(array('2'));
                $NWA_scrollList->setPaginateDisabled(FALSE);
                $NWA_scrollList->setSearchDisabled(FALSE);
                $NWA_scrollList->setSelectorDisabled(FALSE);
                $NWA_scrollList->enableAutoWidth(FALSE);
                $NWA_scrollList->setInfoDisabled(FALSE);
                if ($BX_SESSION['gui_theme'] === 'elmer') {
                    $NWA_scrollList->setColumnWidths(array("20%", "20%", "20%", "20%", "20%")); // Max: 739px
                }
                else {
                    $NWA_scrollList->setColumnWidths(array("150", "220", "50", "160", "150")); // Max: 739px
                }

                // Push out the Scrollist:
                $xxx = $factory->getRawHTML("NWAdbList", $NWA_scrollList->toHtml());
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("NWAdbList"),
                    $defaultPage
                );
            }

            //
            //-- MySQL user rights:
            //

            // Button: Reset to defaults:
            if ($access_advanced == 'rw') {
                $reset_button = $factory->getButton("/mysql/vsiteMySQL?group=$group&reset=defaults", 'resetToDefaults');
                $reset_button->setDescription($i18n->getHtml("resetToDefaults"));
                $reset_button->setIcon('fa fa-times-circle');
                $reset_button->setTarget('_self');
                $reset_button->setButtonColor('danger');

                $grantAll_button = $factory->getButton("/mysql/vsiteMySQL?group=$group&perform=all", 'GrantAllPerms');
                $grantAll_button->setDescription($i18n->getHtml("GrantAllPerms"));
                $grantAll_button->setIcon('fa fa-thumbs-up');
                $grantAll_button->setTarget('_self');
                $grantAll_button->setButtonColor('primary');

                $buttonContainer = $factory->getButtonContainer("", array($reset_button, $grantAll_button));
                $block->addFormField(
                    $buttonContainer,
                    $factory->getLabel(""),
                    'MySQLuserRights'
                );
            }

            // Add divider:
            $xxx = $factory->addBXDivider("DIVIDER_ONE", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("DIVIDER_ONE", false),
                    'MySQLuserRights'
                    );

            $SELECT = $vsite_MySQL['SELECT'];
            $xxx = $factory->getBoolean("SELECT", $SELECT, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("SELECT"),
              'MySQLuserRights'
            );

            $INSERT = $vsite_MySQL['INSERT'];
            $xxx = $factory->getBoolean("INSERT", $INSERT, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("INSERT"),
              'MySQLuserRights'
            );

            $UPDATE = $vsite_MySQL['UPDATE'];
            $xxx = $factory->getBoolean("UPDATE", $UPDATE, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("UPDATE"),
              'MySQLuserRights'
            );

            $DELETE = $vsite_MySQL['DELETE'];
            $xxx = $factory->getBoolean("DELETE", $DELETE, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("DELETE"),
              'MySQLuserRights'
            );
            // File is a GLOBAL privilege and cannot be granted individually for a single DB:
            //$FILE = $vsite_MySQL['FILE'];
            //$block->addFormField(
            //  $factory->getBoolean("FILE", $FILE),
            //  $factory->getLabel("FILE"),
            //  'MySQLuserRights'
            //);

            // Add divider:
            $xxx = $factory->addBXDivider("DIVIDER_TWO", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("DIVIDER_TWO", false),
                    'MySQLuserRights'
                    );

            $CREATE = $vsite_MySQL['CREATE'];
            $xxx = $factory->getBoolean("CREATE", $CREATE, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("CREATE"),
              'MySQLuserRights'
            );

            $ALTER = $vsite_MySQL['ALTER'];
            $xxx = $factory->getBoolean("ALTER", $ALTER, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("ALTER"),
              'MySQLuserRights'
            );

            $INDEX = $vsite_MySQL['INDEX'];
            $xxx = $factory->getBoolean("INDEX", $INDEX, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("INDEX"),
              'MySQLuserRights'
            );

            $DROP = $vsite_MySQL['DROP'];
            $xxx = $factory->getBoolean("DROP", $DROP, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("DROP"),
              'MySQLuserRights'
            );

            $TEMPORARY = $vsite_MySQL['TEMPORARY'];
            $xxx = $factory->getBoolean("TEMPORARY", $TEMPORARY, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("TEMPORARY"),
              'MySQLuserRights'
            );

            // Add divider:
            $xxx = $factory->addBXDivider("DIVIDER_THREE", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("DIVIDER_THREE", false),
                    'MySQLuserRights'
                    );

            $CREATE_VIEW = $vsite_MySQL['CREATE_VIEW'];
            $xxx = $factory->getBoolean("CREATE_VIEW", $CREATE_VIEW, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("CREATE_VIEW"),
              'MySQLuserRights'
            );

            $SHOW_VIEW = $vsite_MySQL['SHOW_VIEW'];
            $xxx = $factory->getBoolean("SHOW_VIEW", $SHOW_VIEW, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("SHOW_VIEW"),
              'MySQLuserRights'
            );

            $CREATE_ROUTINE = $vsite_MySQL['CREATE_ROUTINE'];
            $xxx = $factory->getBoolean("CREATE_ROUTINE", $CREATE_ROUTINE, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("CREATE_ROUTINE"),
              'MySQLuserRights'
            );

            $ALTER_ROUTINE = $vsite_MySQL['ALTER_ROUTINE'];
            $xxx = $factory->getBoolean("ALTER_ROUTINE", $ALTER_ROUTINE, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("ALTER_ROUTINE"),
              'MySQLuserRights'
            );

            $EXECUTE = $vsite_MySQL['EXECUTE'];
            $xxx = $factory->getBoolean("EXECUTE", $EXECUTE, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("EXECUTE"),
              'MySQLuserRights'
            );

            // New additions:
            $EVENT = $vsite_MySQL['EVENT'];
            $xxx = $factory->getBoolean("EVENT", $EVENT, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("EVENT"),
              'MySQLuserRights'
            );
            $TRIGGER = $vsite_MySQL['TRIGGER'];
            $xxx = $factory->getBoolean("TRIGGER", $TRIGGER, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("TRIGGER"),
              'MySQLuserRights'
            );

            // Add divider:
            $xxx = $factory->addBXDivider("DIVIDER_ADM", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("DIVIDER_ADM", false),
                    'MySQLuserRights'
                    );

            $GRANT = $vsite_MySQL['GRANT'];
            $xxx = $factory->getBoolean("GRANT", $GRANT, 'r');
            $block->addFormField(
              $xxx,
              $factory->getLabel("GRANT"),
              'MySQLuserRights'
            );

            $LOCK_TABLES = $vsite_MySQL['LOCK_TABLES'];
            $xxx = $factory->getBoolean("LOCK_TABLES", $LOCK_TABLES, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("LOCK_TABLES"),
              'MySQLuserRights'
            );

            $REFERENCES = $vsite_MySQL['REFERENCES'];
            $xxx = $factory->getBoolean("REFERENCES", $REFERENCES, $access_advanced);
            $block->addFormField(
              $xxx,
              $factory->getLabel("REFERENCES"),
              'MySQLuserRights'
            );

            // Add divider:
            $xxx = $factory->addBXDivider("DIVIDER_FOUR", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("DIVIDER_FOUR", false),
                    'MySQLuserRights'
                    );

            $MAX_QUERIES_PER_HOUR = $factory->getInteger("MAX_QUERIES_PER_HOUR", $vsite_MySQL['MAX_QUERIES_PER_HOUR'], 0, 50000000, $access_advanced);
            $MAX_QUERIES_PER_HOUR->showBounds(1);
            $MAX_QUERIES_PER_HOUR->setWidth(8);
            $block->addFormField(
                $MAX_QUERIES_PER_HOUR,
                $factory->getLabel('MAX_QUERIES_PER_HOUR'),
                'MySQLuserRights'
                );

            $MAX_CONNECTIONS_PER_HOUR = $factory->getInteger("MAX_CONNECTIONS_PER_HOUR", $vsite_MySQL['MAX_CONNECTIONS_PER_HOUR'], 0, 50000000, $access_advanced);
            $MAX_CONNECTIONS_PER_HOUR->showBounds(1);
            $MAX_CONNECTIONS_PER_HOUR->setWidth(8);
            $block->addFormField(
                $MAX_CONNECTIONS_PER_HOUR,
                $factory->getLabel('MAX_CONNECTIONS_PER_HOUR'),
                'MySQLuserRights'
                );

            $MAX_UPDATES_PER_HOUR = $factory->getInteger("MAX_UPDATES_PER_HOUR", $vsite_MySQL['MAX_UPDATES_PER_HOUR'], 0, 50000000, $access_advanced);
            $MAX_UPDATES_PER_HOUR->showBounds(1);
            $MAX_UPDATES_PER_HOUR->setWidth(8);
            $block->addFormField(
                $MAX_UPDATES_PER_HOUR,
                $factory->getLabel('MAX_UPDATES_PER_HOUR'),
                'MySQLuserRights'
                );
        }

        if ($BX_SESSION['gui_theme'] === 'adminica') {

            // Extra header for the "do you really want to delete" dialog:
            $BxPage->setExtraHeaders('
                <script type="text/javascript">
                $(document).ready(function () {
                    // Initialize the dialog with the "Remove" and "Cancel" buttons
                    $("#modalDeleteButton").dialog({
                        modal: true,
                        bgiframe: true,
                        width: 500,
                        height: 280,
                        autoOpen: false,
                        buttons: {
                            "' . $i18n->getHtml("[[palette.remove]]") . '": function() {
                                // Action for the "Remove" button goes here
                                // At this point, we don\'t have the URL yet, it will be set later
                            },
                            "' . $i18n->getHtml("[[palette.cancel]]") . '": function() {
                                $(this).dialog("close");
                            }
                        }
                    });

                    // Attach click event to your delete button
                    $(".dialog_button").click(function (e) {
                        e.preventDefault();
                        
                        // Get the URL from the data-link attribute of the clicked button
                        var deleteUrl = $(this).data("link");

                        // Update the "Remove" button\'s click action dynamically to use the deleteUrl
                        var buttons = $("#modalDeleteButton").dialog("option", "buttons"); // Get the current buttons
                        buttons["' . $i18n->getHtml("[[palette.remove]]") . '"] = function() { // Modify the "Remove" button action
                            window.location.href = deleteUrl; // Redirect to the URL
                            $(this).dialog("close"); // Optionally close the dialog
                        };
                        $("#modalDeleteButton").dialog("option", "buttons", buttons); // Set the updated buttons back

                        // Now open the dialog
                        $("#modalDeleteButton").dialog("open");
                    });
                });
                </script>');

            // Add hidden Modal for Delete-Confirmation:
            $page_body[] = '
                <div class="display_none">
                            <div id="modalDeleteButton" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-mysql.dbRemoveConfirmNeutral]]") . '">
                                <div class="block">
                                        <div class="section">
                                                <h1>' . $i18n->getHtml("[[base-mysql.dbRemoveConfirmNeutral]]") . '</h1>
                                                <div class="dashed_line"></div>
                                                <p>' . $i18n->getHtml("[[base-mysql.DBremoveConfirmInfo]]") . '</p>
                                        </div>
                                </div>
                            </div>
                </div>';
        }
        else {
            //
            //--- Elmer modal for delete-confirmation:
            //

            $BxPage->setExtraFooters('
                <script>
                    // Activate the tooltip
                    $(\'[data-toggle="tooltip"]\').tooltip();

                    // Add a click event to open the modal
                    $(\'.dialog_button\').click(function () {
                        var url = $(this).data(\'url\');
                        $(\'#modalDeleteButton\').data(\'url\', url);
                        $(\'#dialog\').modal(\'show\');
                    });

                    // Add a click event to the modal\'s deletion button
                    $(\'#modalDeleteButton\').click(function () {
                        var url = $(this).data(\'url\');
                        // Perform your deletion action or redirect to the specified URL
                        window.location.href = url; // Example: Redirect to the URL
                    });
                </script>
            ');

            // Add hidden Modal for Delete-Confirmation for Elmer:
            $modal_title = $i18n->getHtml("[[base-mysql.dbRemoveConfirmNeutral]]");
            $modal_body = $i18n->getHtml("[[base-mysql.DBremoveConfirmInfo]]");
            $modal_remove = $i18n->getHtml("[[palette.remove]]");
            $modal_cancel = $i18n->getHtml("[[palette.cancel]]");
            $modal_html =<<<HTML

                        <!-- Delete-Confirm modal -->
                        <div id="dialog" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="dialogLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                                        <h5 class="modal-title" id="dialogLabel">$modal_title</h5>
                                    </div>
                                    <div class="modal-body">
                                        <p>$modal_body</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn btn-danger btn-anim link_button" id="modalDeleteButton"><i class="fa fa-trash-o"></i><span class="btn-text">$modal_remove</span></button>
                                        <button class="btn btn-primary btn-anim" data-dismiss="modal"><i class="fa fa-times"></i><span class="btn-text">$modal_cancel</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- /Delete-Confirm modal -->

            HTML;
            $page_body[] = $modal_html;

        }

        // Add the buttons for those who can edit this page:
        if (((isset($get_form_data['addDB'])) && ($access_advanced == 'r')) || ($access_advanced == 'rw')) {
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/mysql/vsiteMySQL?group=$group"));
        }

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