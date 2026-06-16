<?php 
namespace Console\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Consoleprocs extends BaseController {
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

        //helper(['form']);

        $CI =& get_instance();
        if (!$CI->getAllowed('serverConfig')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-console", "/console/consoleprocs");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-console", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Get CODB data:
        //

        $ourOID = $CI->cceClient->find("SOL_Console");
        // We now run 'ps auxwf' via CCEWrap and don't need the roundtrip through CODB for this:
        //$CI->cceClient->set($ourOID[0], "", array('gui_list_proctrigger' => time()));
        $errors[] = $CI->cceClient->errors();
        $CODBDATA = $CI->cceClient->get($ourOID[0]);

        //
        //--- Handle POST Request:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Has getPost request:
            $form_data = $BxPage->FORM_POST;

            // Form fields that are required to have input:
            $required_keys = array();

            // Empty array for key => values we want to submit to CCE:
            $attributes = array();

            // Items we do NOT want to submit to CCE:
            $ignore_attributes = array("BlueOnyx_Info_Text", 'pam_abl_location');

            // Run GetFormAttributes()
            if (is_array($form_data)) {
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
                $errors = $BxPage->getErrors();

            }

            //
            //--- No errors? Submit to CODB:
            //

            if (count($errors) == "0") {
                // We have no POST data to handle
            }
        }

        //
        //--- Handle GET requests:
        //

        if ($this->request->getGet(NULL, NULL, TRUE)) {
            // Has getGet request:
            $get_form_data = $BxPage->FORM_GET;

            // Check if we have everything:
            if ((isset($get_form_data['pid'])) && ($get_form_data['pid'] != "")) { 

                $user_kill_action = array(
                    "kill_pid" => $get_form_data['pid'],
                    "kill_trigger" => time()
                  );

                // Actual submit to CODB:
                $CI->cceClient->setObject("SOL_Console", $user_kill_action);        

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, "/console/consoleprocs");
            }
        }

        //
        //-- Generate page:
        //

        $iam = '/console/consoleprocs';

        // Set Menu items:
        $BxPage->setVerticalMenu('base_security');
        $BxPage->setVerticalMenuChild('base_console_procs');
        $page_module = 'base_sysmanage';

        $defaultPage = "basic";
        $block = $factory->getPagedBlock("vserver_processlist", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        //
        //--- Basic Tab
        //

        $ScrollList = $factory->getScrollList("vserver_processlist", array("PID", "USER", "CPU", "MEM", "VSZ", "RSS", "TTY", "STAT", "START", "TIME", "COMMAND", "KILL"), array());
        $ScrollList->setAlignments(array("left", "left", "left", "left", "left", "left", "left", "left", "left", "left", "left", "center"));
        $ScrollList->setDefaultSortedIndex('0');
        $ScrollList->setSortOrder('ascending');
        $ScrollList->setSortDisabled(array('8'));
        $ScrollList->setPaginateDisabled(FALSE);
        $ScrollList->setSearchDisabled(FALSE);
        $ScrollList->setSelectorDisabled(FALSE);
        $ScrollList->enableAutoWidth(FALSE);
        $ScrollList->setInfoDisabled(FALSE);
        $ScrollList->setDisplay(10);
        $ScrollList->setColumnWidths(array("10", "20", "20", "20", "20", "20", "100", "20", "50", "50", "250", "100")); // Max: 739px

        // Fetch the process list by letting CCEWrap run 'ps auxwf':
        $output = '';
        $ret = $CI->serverScriptHelper->shell("/usr/bin/ps auxwf", $output, 'root', $BX_SESSION['sessionId']);
        if ($ret != 0) {
            $errors[] = ErrorMessage($i18n->get('[[palette.500text]]'), 'alert_red', 'alert');
        }

        // Split by EOL:
        $pieces = preg_split('/\r\n|\r|\n/', $output);

        // Filter out lines that contain the specific command we don't want to show:
        $filteredPieces = array_filter($pieces, function($line) {
            return strpos($line, '/usr/bin/ps auxwf') === false;
        });

        // Proceed with splitting the filtered array into columns
        $columns = array_map(function($line) {
            // Split the line by spaces, but limit the number of elements to 11
            // to ensure the COMMAND (and its potential spaces) remains intact
            $parts = preg_split('/\s+/', $line, 11, PREG_SPLIT_NO_EMPTY);

            if (count($parts) >= 11) {
                // Re-merge the COMMAND parts if there were more than 11 columns due to spaces in the COMMAND
                $parts[10] = implode(' ', array_slice($parts, 10));
            }

            return $parts;
        }, $filteredPieces);

        // Remove empty arrays from $columns
        $columns = array_filter($columns, function($item) {
            // Check if the sub-array is not empty
            return !empty($item);
        });

        // Populate scrollList:
        foreach ($columns as $line) {
            if (count($line) === 11) {
                if ($line['1'] != "PID") {
                    $action = $factory->getCompositeFormField();
                    $remove_button = $factory->getModifyButton("$iam?pid=" . $line['1']);
                    $remove_button->setButtonSize("small");
                    $remove_button->setButtonSpecialStyle('square_animated');
                    $remove_button->setIcon('fa fa-trash-o');
                    $remove_button->setButtonColor('danger');
                    $remove_button->setImageOnly(TRUE);
                    $remove_button->setTarget('_self');
                    $remove_button->setDescription($i18n->getHtml("[[palette.remove_help]]"));

                    $action->addFormField($remove_button);

                    // Populate Scrollist
                    $ScrollList->addEntry(array(
                                $line['1'],
                                $line['0'],
                                formspecialchars($line['2']),
                                formspecialchars($line['3']), 
                                formspecialchars($line['4']), 
                                formspecialchars($line['5']), 
                                formspecialchars($line['6']), 
                                formspecialchars($line['7']), 
                                formspecialchars($line['8']), 
                                formspecialchars($line['9']), 
                                word_wrap(formspecialchars($line['10']), 15),
                                $action
                    ));
                }
            }
        }

        $xxx = $factory->getRawHTML("filler", "&nbsp;");
        $block->addFormField(
            $xxx,
            $factory->getLabel(" "),
            $defaultPage
        );

        $cmd = "/usr/bin/w|/usr/bin/head -1";
        exec("$cmd 2>&1", $wline);
        $xxx = $factory->getRawHTML("filler", "&nbsp;" . $wline[0]);
        $block->addFormField(
            $xxx,
            $factory->getLabel(" "),
            $defaultPage
        );

        // Commit-Integer: We need at least one form field to be able to submit data.
        // So we use this hidden one:
        $xxx = $factory->getTextField('commit', time(), '');
        $block->addFormField(
            $xxx,
            $factory->getLabel("commit"), 
            $defaultPage
        );  

        // Show the ScrollList of Logins:
        $xxx = $factory->getRawHTML("vserver_loginlist", $ScrollList->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("vserver_loginlist"),
            $defaultPage
        );

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }
}

/*
Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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