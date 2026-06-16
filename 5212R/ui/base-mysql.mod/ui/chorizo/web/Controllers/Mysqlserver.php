<?php 
namespace Mysql\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Mysqlserver extends BaseController {
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

        if (!$CI->getAllowed('serverNetwork')) {
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

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-mysql", "/mysql/mysqlserver");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        // Form fields that are required to have input:
        $required_keys = array("sql_host", "sql_port");

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "sql_status", "filesize", "last_backup");

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

            // First we get the existing MySQL data from CODB's "System" object:
            $SystemMYSQL = $CI->cceClient->get($System['OID'], "mysql");

            // Then we get the existing "MySQL" Object:
            $AbsMYSQL = $CI->cceClient->getObject("MySQL");

            $mysql_current_username = $AbsMYSQL['sql_root'];
            $mysql_current_password = $AbsMYSQL['sql_rootpassword'];

            $sql_root = $mysql_current_username;
            $sql_rootpassword = $mysql_current_password;

            if (!isset($attributes['onoff'])) {
                $attributes['onoff'] = date("U");
            }

            if (!isset($attributes['newpass'])) {
                // We don't do a password change. So we write back the username and password we already had in CODB:
                $sql_root = $mysql_current_username;
                $sql_rootpassword = $mysql_current_password;
            }

            if (isset($attributes['sql_root'])) {
                $sql_root = $attributes['sql_root'];
            }
            else {
                $sql_root = $mysql_current_username;
            }

            if (!isset($attributes['username'])) {
                $attributes['username'] = $sql_root;
            }
            if (!isset($attributes['password'])) {
                $attributes['password'] = $sql_rootpassword;
            }

            if (isset($attributes['newpass'])) {
                if (!empty($attributes['newpass'])) {
                    $attributes['changepass'] = date("U");
                    if (!isset($attributes['oldpass'])) {
                        $attributes['oldpass'] = "-1"; 
                    }
                    $attributes['password'] = $attributes['newpass'];
                    $sql_rootpassword = $attributes['newpass'];
                }
            }

            if (isset($attributes['sql_rootpassword'])) {
                $sql_rootpassword = $attributes['sql_rootpassword'];
            }
            else {
                $sql_rootpassword = $mysql_current_password;
            }

            if (isset($attributes['newpass'])) {
                if ($attributes['newpass'] != "") {
                    $sql_rootpassword = $attributes['newpass'];
                }
            }

            // Check Password match:
            $passwd = "";
            if (isset($attributes['newpass'])) {
                $passwd = $attributes['newpass'];
                if ((preg_match('/"/', $passwd)) || (preg_match('/\$/', $passwd)) || (preg_match('/\§/', $passwd))) {
                    $errors[] = ErrorMessage($i18n->getHtml("[[base-alpine.pw_illegal_chars]]"));
                }
            }
            $passwd_repeat = "";
            if (isset($attributes['_newpass_repeat'])) {
                $passwd_repeat = $attributes['_newpass_repeat'];
            }
            if ((isset($attributes['newpass'])) || (isset($attributes['_newpass_repeat']))) {
                if ($attributes['newpass'] != "") {
                    if (bx_pw_check($i18n, $passwd, $passwd_repeat) != "") {
                        $errors[] = bx_pw_check($i18n, $passwd, $passwd_repeat);
                    }
                }
            }

            // We don't need these at this stage anymore:
            if (isset($attributes['sql_root'])) {
                unset($attributes['sql_root']);
            }
            if (isset($attributes['sql_rootpassword'])) {
                unset($attributes['sql_rootpassword']);
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) === 0) && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.
            if (isset($attributes['_newpass_repeat'])) {
                unset($attributes['_newpass_repeat']);
            }
            if (isset($attributes['onoff'])) {
                unset($attributes['onoff']);
            }
            if (isset($attributes['sql_host'])) {
                $MySQL_sql_host = $attributes['sql_host'];
                unset($attributes['sql_host']);
            }
            if (isset($attributes['sql_port'])) {
                $MySQL_sql_port = $attributes['sql_port'];
                unset($attributes['sql_port']);
            }

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "mysql", $attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            if (count($errors) == "0") {

                // Now handle the set to the CODB object "MySQL" as well:
                $getthisOID = $CI->cceClient->find("MySQL");
                $mysql_settings_exists = 0;
                $mysql_settings = $CI->cceClient->get($getthisOID[0]);
                if (!$mysql_settings['timestamp']) {
                    $mysqlOID = $CI->cceClient->create("MySQL",
                        array(
                            'sql_host' => $MySQL_sql_host,
                            'sql_port' => $MySQL_sql_port,
                            'sql_root' => $sql_root,
                            'sql_rootpassword' => $sql_rootpassword,
                            'savechanges' => time(),
                            'timestamp' => time()
                        )
                    );
                }
                else {
                    $mysqlOID = $CI->cceClient->find("MySQL");
                    $CI->cceClient->set($mysqlOID[0], "",
                        array(
                            'sql_host' => $MySQL_sql_host,
                            'sql_port' => $MySQL_sql_port,
                            'sql_root' => $sql_root,
                            'sql_rootpassword' => $sql_rootpassword,
                            'savechanges' => time(),
                            'timestamp' => time()
                        )
                    );
                }

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $redirect_URL = '/mysql/mysqlserver';
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Own page logic:
        //

        // Location of the backup file:
        $backup_file = "/home/.sqlbackup/mysql-dump.sql";

        // MySQL PID:
        $ret = $CI->serverScriptHelper->shell("/usr/bin/systemctl is-active mariadb", $output, 'root', $BX_SESSION['sessionId']);
        // Check if the script call worked:
        $mysql_server_status = '0';
        $my_enabled = 0;
        $access="r";
        if ($ret == 0) {
            // Success!
            $mysql_server_status = rtrim($output);
            if ($mysql_server_status == "active") {
                $my_enabled = 1;
                $access="rw";
            }
        }

        // Find out if we have a MySQL-Dump and if so, get its specs:
        if ( file_exists($backup_file) ) {
            $last_ran = date("M j Y - g:i a", filemtime($backup_file));
            $fs = format_bytes(filesize($backup_file));
        }
        else {
            $last_ran = "- n/a -";
            $fs = "- n/a -";        
        }

        // Assemble date, size and status of MySQL-Dump:
        $dump = date("U");
        $cfg = array(
            "dumpdate" => $last_ran,
            "dumpsize" => $fs,
            "enabled" => $my_enabled,
            "statustrigger" => time()
        );

        // Push that info into CODB:
        $CI->cceClient->set($System['OID'], "mysql",  $cfg);        
        $nuMYSQL = $CI->cceClient->get($System['OID'], "mysql");

        if ($my_enabled == 1) {
            $nuMYSQL["enabled"] = "1";
        }

        $getthisOID = $CI->cceClient->find("MySQL");
        $mysql_settings_exists = 0;
        $mysql_settings = $CI->cceClient->get($getthisOID[0]);

        if ($mysql_settings['timestamp'] != '') {
            $mysql_settings_exists = 1;
        }

        // MySQL settings:
        $sql_root               = $mysql_settings['sql_root'];
        $sql_rootpassword       = $mysql_settings['sql_rootpassword'];
        $sql_host               = $mysql_settings['sql_host'];
        $sql_port               = $mysql_settings['sql_port'];

        // Configure defaults:
        if (!$sql_root) { $sql_root = "root"; }
        if (!$sql_host) { $sql_host = "127.0.0.1"; }
        if (!$sql_port) { $sql_port = "3306"; }

        if (($sql_host != "localhost") && ($sql_host != "127.0.0.1")) {
            $mysql_is_local = "0";
            $my_sql_host = $sql_host . ":" . $sql_port;
            $con_sql_host = $my_sql_host;
        }
        else {
            $mysql_is_local = "1";
        }
        
        if ($sql_host == "localhost") {
            $sql_host = "127.0.0.1";
        }

        // Status of MySQL connection:
        $mysql_no_connect = "0";

        if ($nuMYSQL['connectionstatus'] == '1') {
            // MySQL connection can be established:
            $mysql_status = $i18n->interpolate("[[base-mysql.mysql_status_ok]]");
            $mysql_no_connect = "0";
            // Connection is OK, but no root password configured. Append suggestion to set password:
            if ($sql_rootpassword == "") {
                $mysql_status .= $i18n->interpolate("[[base-mysql.root_has_no_pwd]]");
                $mysql_no_connect = "2";
            }
        }
        else {
            $mysql_status = $i18n->interpolate("[[base-mysql.mysql_status_incorrect]]");
            $mysql_no_connect = "1";            
        }

        // Generate SQL-Dump if appropriate:
        if (isset($get_form_data['dump'])) {
            $dump = date("U");
            $dumpcfg = array(
                "username" => $sql_root, 
                "password" => $sql_rootpassword, 
                "dump" => $dump);
            $CI->cceClient->set($System['OID'], "mysql",  $dumpcfg);
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            if ($BX_SESSION['gui_theme'] === 'elmer') {
                $redirect_URL = "/mysql/mysqlserver?DetailedTab=tabs-3#tabs-3";
            }
            else {
                $redirect_URL = '/mysql/mysqlserver?sqldump#tabs-3';
            }
            $errors[] = ErrorMessage($i18n->get('[[base-mysql.back_OK]]'), 'alert_green', 'info_about');
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }
        // Delete SQL-Dump if appropriate:
        if (isset($get_form_data['delete'])) {
            $delete = date("U");
            $dumpcfg = array("delete" => $delete);
            $CI->cceClient->set($System['OID'], "mysql",  $dumpcfg);
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            if ($BX_SESSION['gui_theme'] === 'elmer') {
                $redirect_URL = "/mysql/mysqlserver?DetailedTab=tabs-3#tabs-3";
            }
            else {
                $redirect_URL = '/mysql/mysqlserver?sqldump#tabs-3';
            }
            $errors[] = ErrorMessage($i18n->get('[[base-mysql.backDel_OK]]'), 'alert_green', 'info_about');
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }
        // Download SQL-Dump if appropriate:
        if ((isset($get_form_data['download'])) && (is_file($backup_file))) {
            $data = file_get_contents($backup_file); // Read the file's contents
            $name = 'sqldump.sql';
            return $this->response->download($name, $data);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/mysql/mysqlserver");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_mysql');
        $page_module = 'base_sysmanage';

        $defaultPage = "server";

        if ($access == "rw") {
            $block = $factory->getPagedBlock("mysql_header", array($defaultPage, "sqlpass", "sqldump"));
        }
        else {
            $block = $factory->getPagedBlock("mysql_header", array($defaultPage));
        }

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- server Tab
        //

        // Add divider:
        $xxx = $factory->addBXDivider("MySQL_Local_divider", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("MySQL_Local_divider", false),
                $defaultPage
                );

        $xxx = $factory->getBoolean("enabled", $nuMYSQL["enabled"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("mysql_enabled"),
            $defaultPage
        );

        // Add divider:
        $xxx = $factory->addBXDivider("MySQL_Remote_divider", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("MySQL_Remote_divider", false),
                $defaultPage
                );

        // sql_host:
        $line_sql_host = $factory->getTextField("sql_host", $sql_host);
        $line_sql_host->setMaxLength(30);
        $block->addFormField($line_sql_host, $factory->getLabel("sql_host"), $defaultPage);

        // sql_port:
        $line_sql_port = $factory->getInteger("sql_port", $sql_port, 1, 65535);
        $line_sql_port->showBounds(1);
        $line_sql_port->setWidth(5);
        $block->addFormField(
            $line_sql_port,
            $factory->getLabel('sql_port'),
            $defaultPage
            );

        // People apparently get confused by the username / password dialogue on the first tab
        // and attempt to change the password there - not on the 2nd tab instead.
        // So we now hide the login details for MySQL user "root" and only show it if a 
        // MySQL-connection cannot be established:
        //
        // Possible $mysql_no_connect values:
        //
        // 0 = MySQL connection OK
        // 1 = MySQL connection not OK
        // 2 = MySQL connection OK, but "root" has no password set.

        if ($mysql_no_connect == "1") {
            // Show 'enter password' dialogue in first tab:
            $db_details_visibility = "server";
        }
        else {
            // Hide 'enter password' dialogue in first tab:
            $db_details_visibility = "hidden";
        }

        // Add divider:
        $xxx = $factory->addBXDivider("MySQL_Login_divider", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("MySQL_Login_divider", false),
                $db_details_visibility
                );

        // sql_root:
        $line_sql_root = $factory->getTextField("sql_root", $sql_root);
        $line_sql_root->setMaxLength(30);
        $block->addFormField($line_sql_root, $factory->getLabel("sql_root"), $db_details_visibility);

        // sql_rootpassword:
        $line_sql_rootpassword = $factory->getPassword("sql_rootpassword", $sql_rootpassword);
        $line_sql_rootpassword->setOptional("silent");
        $line_sql_rootpassword->setConfirm(FALSE);
        $line_sql_rootpassword->setCheckPass(FALSE);
        $block->addFormField($line_sql_rootpassword, $factory->getLabel("sql_rootpassword"), $db_details_visibility);

        // Add divider:
        $xxx = $factory->addBXDivider("MySQL_Status_divider", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("MySQL_Status_divider", false),
                $defaultPage
                );

        // sql_status:
        $line_sql_status = $factory->getHtmlField("sql_status", $mysql_status, 'r');
        $block->addFormField($line_sql_status, $factory->getLabel("sql_status"), $defaultPage);

        //
        //--- sqlpass Tab
        //

        $old_pass = $factory->getPassword("oldpass", "", FALSE, $access);
        $old_pass->setOptional('silent');
        $old_pass->setConfirm(FALSE);
        $old_pass->setCheckPass(FALSE);
        $block->addFormField(
            $old_pass,
            $factory->getLabel("current_pass"),
            "sqlpass");

        $new_pass = $factory->getPassword("newpass", "", TRUE, $access);
        $new_pass->setOptional('silent');
        $block->addFormField(
            $new_pass,
            $factory->getLabel("mysqlpass"),
            "sqlpass");

        //
        //--- sqldump Tab
        //

        // Get results:
        $last_ran = $nuMYSQL["dumpdate"];
        $fs = $nuMYSQL["dumpsize"];

        if ( file_exists($backup_file) ) {
            $last_ran = date("M j Y - g:i a", filemtime($backup_file));
            $fs = format_bytes(filesize($backup_file));
        }

        // generate add mx button:
        $generate_dump = $factory->getButton("/mysql/mysqlserver?dump=1", 'mysqldump', "");
        $generate_dump->setIcon('fa fa-repeat');
        $array_of_buttons[] = $generate_dump;

        if (file_exists($backup_file) ) {
            $download_button = $factory->getButton("/mysql/mysqlserver?download=1", "download_backup");
            $download_button->setIcon('fa fa-download');
            $array_of_buttons[] = $download_button;         
            $delete_button = $factory->getRemoveButton("/mysql/mysqlserver?delete=1", "delete_backup");
            $delete_button->setIcon('fa fa-trash');
            $delete_button->setButtonColor('danger');
            $array_of_buttons[] = $delete_button;
        }

        $buttonContainer = $factory->getButtonContainer("mysqldump", $array_of_buttons);
        $block->addFormField(
            $buttonContainer,
            $factory->getLabel("mysqldump"),
            "sqldump"
        );

        $xxx = $factory->getHtmlField("last_backup", $nuMYSQL["dumpdate"], "r");
        $block->addFormField(
            $xxx,
            $factory->getLabel("last_backup"),
            "sqldump");

        $xxx = $factory->getHtmlField("filesize", $nuMYSQL["dumpsize"], "r");
        $block->addFormField(
            $xxx,
            $factory->getLabel("filesize"),
            "sqldump");

        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/mysql/mysqlserver"));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
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