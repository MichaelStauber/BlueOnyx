<?php 
namespace Mysql\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Mysqlconfig extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-mysql", "/mysql/mysqlconfig");
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

        if (is_file("/usr/libexec/mariadb-wait-ready")) {
            $my_cnf_file = "/etc/my.cnf.d/server.cnf";
        }
        else {
            $my_cnf_file = "/etc/my.cnf";
        }

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            $attributes['force_update'] = time();
            $my_cnf_out = $attributes['my_cnf'];
            unset($attributes['my_cnf']);

            // Write the new my.cnf:
            write_file("/tmp/my.cnf", $my_cnf_out);

            $diff = "0";
            if ((is_file("/usr/bin/diff")) && (is_file("/tmp/my.cnf"))) {
                $diff = `/usr/bin/diff $my_cnf_file /tmp/my.cnf | /usr/bin/wc -l`;
            }

            if ($diff != "0") {
                // If we want to activate the new my.cnf, we need to set "soltab" to "two".
                // Only then the handler will take care of it. But we only do so if the new
                // my.cnf's diff result is different from the existing /etc/my.cnf
                $attributes['soltab'] = "two";

                // Define who runs CCEwrap:
                $runas = 'root';
                $ret = $CI->serverScriptHelper->shell("/bin/cp /tmp/my.cnf $my_cnf_file", $nfk, 'root', $CI->BX_SESSION['sessionId']);
                $ret = $CI->serverScriptHelper->shell("/bin/chown root:root $my_cnf_file", $nfk, 'root', $CI->BX_SESSION['sessionId']);
                $ret = $CI->serverScriptHelper->shell("/bin/rm -f /tmp/my.cnf", $nfk, 'root', $CI->BX_SESSION['sessionId']);
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "MYSQLUSERS_DEFAULTS",  $attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors);
        }

        //
        //--- Get CODB-Object of interest: 
        //

        $CODBDATA = $CI->cceClient->get($System['OID'], "MYSQLUSERS_DEFAULTS");


        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //


        // Prepare Page:
        $BxPage->setFormUrl("/mysql/mysqlconfig");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_mysqlconfig');
        $page_module = 'base_sysmanage';

        $defaultPage = "MySQL_TAB_ONE";

        $block = $factory->getPagedBlock("mysql_header", array($defaultPage, 'MySQL_TAB_TWO'));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- MySQL_TAB_ONE
        //

        // Add MySQL userdb switch:
        $udb_switch = $CODBDATA['solsitemysql'];
        $xxx = $factory->getBoolean("solsitemysql", $udb_switch);
        $block->addFormField(
          $xxx,
          $factory->getLabel("udb_switch"),
          'MySQL_TAB_ONE'
        );

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_ZERO", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_ZERO", false),
                $defaultPage
                );

        $my_TEXT = $i18n->getClean("[[base-mysql.MySQL_Info_Text]]");
        $mysql_info_text = $factory->getHtmlField("_", $my_TEXT, 'r');
        $mysql_info_text->setLabelType("nolabel");
        $block->addFormField(
            $mysql_info_text,
            $factory->getLabel(" "),
            'MySQL_TAB_ONE'
            );

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_ONE", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_ONE", false),
                $defaultPage
                );

        $SELECT = $CODBDATA['SELECT'];
        $xxx = $factory->getBoolean("SELECT", $SELECT);
        $block->addFormField(
          $xxx,
          $factory->getLabel("SELECT"),
          'MySQL_TAB_ONE'
        );
        $INSERT = $CODBDATA['INSERT'];
        $xxx = $factory->getBoolean("INSERT", $INSERT);
        $block->addFormField(
          $xxx,
          $factory->getLabel("INSERT"),
          'MySQL_TAB_ONE'
        );
        $UPDATE = $CODBDATA['UPDATE'];
        $xxx = $factory->getBoolean("UPDATE", $UPDATE);
        $block->addFormField(
          $xxx,
          $factory->getLabel("UPDATE"),
          'MySQL_TAB_ONE'
        );
        $DELETE = $CODBDATA['DELETE'];
        $xxx = $factory->getBoolean("DELETE", $DELETE);
        $block->addFormField(
          $xxx,
          $factory->getLabel("DELETE"),
          'MySQL_TAB_ONE'
        );
        //$FILE = $CODBDATA['FILE'];
        //$block->addFormField(
        //  $factory->getBoolean("FILE", $FILE),
        //  $factory->getLabel("FILE"),
        //  'MySQL_TAB_ONE'
        //);

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_TWO", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_TWO", false),
                $defaultPage
                );

        $CREATE = $CODBDATA['CREATE'];
        $xxx = $factory->getBoolean("CREATE", $CREATE);
        $block->addFormField(
          $xxx,
          $factory->getLabel("CREATE"),
          'MySQL_TAB_ONE'
        );
        $ALTER = $CODBDATA['ALTER'];
        $xxx = $factory->getBoolean("ALTER", $ALTER);
        $block->addFormField(
          $xxx,
          $factory->getLabel("ALTER"),
          'MySQL_TAB_ONE'
        );
        $INDEX = $CODBDATA['INDEX'];
        $xxx = $factory->getBoolean("INDEX", $INDEX);
        $block->addFormField(
          $xxx,
          $factory->getLabel("INDEX"),
          'MySQL_TAB_ONE'
        );
        $DROP = $CODBDATA['DROP'];
        $xxx = $factory->getBoolean("DROP", $DROP);
        $block->addFormField(
          $xxx,
          $factory->getLabel("DROP"),
          'MySQL_TAB_ONE'
        );
        $TEMPORARY = $CODBDATA['TEMPORARY'];
        $xxx = $factory->getBoolean("TEMPORARY", $TEMPORARY);
        $block->addFormField(
          $xxx,
          $factory->getLabel("TEMPORARY"),
          'MySQL_TAB_ONE'
        );

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_THREE", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_THREE", false),
                $defaultPage
                );

        // Old code had a check here for version of MySQL, as these functions require MySQL5.
        // Even 5106R now has MySQL5, so we can forego the check:
        $access = 'rw';

        $CREATE_VIEW = $CODBDATA['CREATE_VIEW'];
        $xxx = $factory->getBoolean("CREATE_VIEW", $CREATE_VIEW, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("CREATE_VIEW"),
          'MySQL_TAB_ONE'
        );
        $SHOW_VIEW = $CODBDATA['SHOW_VIEW'];
        $xxx = $factory->getBoolean("SHOW_VIEW", $SHOW_VIEW, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("SHOW_VIEW"),
          'MySQL_TAB_ONE'
        );
        $CREATE_ROUTINE = $CODBDATA['CREATE_ROUTINE'];
        $xxx = $factory->getBoolean("CREATE_ROUTINE", $CREATE_ROUTINE, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("CREATE_ROUTINE"),
          'MySQL_TAB_ONE'
        );
        $ALTER_ROUTINE = $CODBDATA['ALTER_ROUTINE'];
        $xxx = $factory->getBoolean("ALTER_ROUTINE", $ALTER_ROUTINE, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("ALTER_ROUTINE"),
          'MySQL_TAB_ONE'
        );
        $EXECUTE = $CODBDATA['EXECUTE'];
        $xxx = $factory->getBoolean("EXECUTE", $EXECUTE, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("EXECUTE"),
          'MySQL_TAB_ONE'
        );

        // New additions:
        $EVENT = $CODBDATA['EVENT'];
        $xxx = $factory->getBoolean("EVENT", $EVENT, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("EVENT"),
          'MySQL_TAB_ONE'
        );
        $TRIGGER = $CODBDATA['TRIGGER'];
        $xxx = $factory->getBoolean("TRIGGER", $TRIGGER, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("TRIGGER"),
          'MySQL_TAB_ONE'
        );

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_ADM", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_ADM", false),
                $defaultPage
                );
        $GRANT = $CODBDATA['GRANT'];
        $xxx = $factory->getBoolean("GRANT", $GRANT, 'r');
        $block->addFormField(
          $xxx,
          $factory->getLabel("GRANT"),
          'MySQL_TAB_ONE'
        );
        $LOCK_TABLES = $CODBDATA['LOCK_TABLES'];
        $xxx = $factory->getBoolean("LOCK_TABLES", $LOCK_TABLES, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("LOCK_TABLES"),
          'MySQL_TAB_ONE'
        );
        $REFERENCES = $CODBDATA['REFERENCES'];
        $xxx = $factory->getBoolean("REFERENCES", $REFERENCES, $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("REFERENCES"),
          'MySQL_TAB_ONE'
        );

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_FOUR", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_FOUR", false),
                $defaultPage
                );

        $MAX_QUERIES_PER_HOUR = $factory->getInteger("MAX_QUERIES_PER_HOUR", $CODBDATA['MAX_QUERIES_PER_HOUR'], 0, 50000000);
        $MAX_QUERIES_PER_HOUR->showBounds(1);
        $MAX_QUERIES_PER_HOUR->setWidth(8);
        $block->addFormField(
            $MAX_QUERIES_PER_HOUR,
            $factory->getLabel('MAX_QUERIES_PER_HOUR'),
            'MySQL_TAB_ONE'
            );

        $MAX_CONNECTIONS_PER_HOUR = $factory->getInteger("MAX_CONNECTIONS_PER_HOUR", $CODBDATA['MAX_CONNECTIONS_PER_HOUR'], 0, 50000000);
        $MAX_CONNECTIONS_PER_HOUR->showBounds(1);
        $MAX_CONNECTIONS_PER_HOUR->setWidth(8);
        $block->addFormField(
            $MAX_CONNECTIONS_PER_HOUR,
            $factory->getLabel('MAX_CONNECTIONS_PER_HOUR'),
            'MySQL_TAB_ONE'
            );

        $MAX_UPDATES_PER_HOUR = $factory->getInteger("MAX_UPDATES_PER_HOUR", $CODBDATA['MAX_UPDATES_PER_HOUR'], 0, 50000000);
        $MAX_UPDATES_PER_HOUR->showBounds(1);
        $MAX_UPDATES_PER_HOUR->setWidth(8);
        $block->addFormField(
            $MAX_UPDATES_PER_HOUR,
            $factory->getLabel('MAX_UPDATES_PER_HOUR'),
            'MySQL_TAB_ONE'
            );

        //
        //--- MySQL_TAB_TWO
        //

        $the_file_data = file_get_contents("$my_cnf_file");
        $xxx = $factory->getTextBlock("my_cnf", $the_file_data);
        $block->addFormField(
          $xxx,
          $factory->getLabel("my_cnf"),
          'MySQL_TAB_TWO'
        );

        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/mysql/mysqlconfig"));

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