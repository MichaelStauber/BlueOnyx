<?php 
namespace Dns\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Secondarydns extends BaseController {
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

        if (!$CI->getAllowed('siteDNS')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-dns", "/dns/secondarydnsmod");
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

        // -- Actual page logic start:

        $iam = '/dns/secondarydnsmod';
        $parent = '/dns/secondarydns';

        //
        //-- Handle form data:
        //

        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", 'Add_Secondary_Service___');

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
            $errors = $BxPage->getErrors();
        }

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // None.
        }

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Any additional parameters that we need to pass on?
            $attributes['commit'] = time();

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "DNS",  $attributes);

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
        //-- Page Logic:
        //

        $iam = '/dns/secondarydns';
        $edit = '/dns/secondarydnsmod';
        $parent = '/dns/dnsmanager';

        // Grab system-DNS data
        $sys_dns = $CI->cceClient->get($System['OID'], 'DNS');

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/dns/secondarydns");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_dns');
        $page_module = 'base_sysmanage';

        $defaultPage = "basic";

        $block = $factory->getPagedBlock("sec_list", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        // pull-down add secondary service
        $addList = array(   "add_secondary_forward" => "$edit?TYPE=FORWARD", "add_secondary_network" => "$edit?TYPE=NETWORK");
        $addButton = $factory->getMultiButton("add_secondary", array_values($addList), array_keys($addList));

        //
        //--- Basic Tab
        //

        $ScrollList = $factory->getScrollList("sec_list", array("sec_authority", "sec_primaries", 'listAction'), array());
        $ScrollList->setAlignments(array("left", "center", "center"));
        $ScrollList->setDefaultSortedIndex('0');
        $ScrollList->setSortOrder('ascending');
        $ScrollList->setSortDisabled(array('3'));
        $ScrollList->setPaginateDisabled(FALSE);
        $ScrollList->setSearchDisabled(FALSE);
        $ScrollList->setSelectorDisabled(FALSE);
        $ScrollList->enableAutoWidth(FALSE);
        $ScrollList->setInfoDisabled(FALSE);
        $ScrollList->setColumnWidths(array("319", "319", "100")); // Max: 739px

        // Populate elements in the scroll list
        $rec_oids = $CI->cceClient->find("DnsSlaveZone");

        // display records
        rsort($rec_oids);
        if(count($rec_oids)) { 
            for ($i = 0; $i < $rec_oids[0]; $i++) {
                if(isset($rec_oids[$i])) {
                    $oid = $rec_oids[$i];
                    $rec = $CI->cceClient->get($oid, "");

                    if($rec['ipaddr'] != '') {
                      $label = $rec['ipaddr'].'/'.$rec['netmask'];
                      $type = 'NETWORK';
                    } else {
                      // domain auth
                      $label = $rec['domain'];
                      $type = 'FORWARD';
                    }

                    $msg = $i18n->get("confirm_removal_of_sec");  // .$label.'?';

                    // Construct the buttons:
                    //$modify_button = $factory->getModifyButton("$edit?_TARGET=$oid&_LOAD=1&TYPE=$type");
                    //$modify_button->setImageOnly(TRUE);

                    // Edit-Button:
                    $modify_button = $factory->getModifyButton("$edit?_TARGET=$oid&_LOAD=1&TYPE=$type");
                    $modify_button->setButtonSize("small");
                    $modify_button->setButtonSpecialStyle('square_animated');
                    $modify_button->setImageOnly(TRUE);
                    $modify_button->setTarget('_self');

                    // Remove-Button:
                    $remove_button = $factory->getRemoveButton("$edit?_RTARGET=$oid&TYPE=$type", '[[palette.remove]]');
                    $remove_button->setButtonSize("small");
                    $remove_button->setButtonSpecialStyle('square_animated');
                    $remove_button->setIcon('fa fa-trash-o');
                    $remove_button->setButtonColor('danger');
                    $remove_button->setImageOnly(TRUE);
                    $remove_button->setTarget('_self');
                    $remove_button->setDescription($i18n->getHtml('[[palette.remove_help]]'));

                    $combined_buttons = $factory->getCompositeFormField(array($modify_button, $remove_button));

                    // Populate Scrollist
                    $ScrollList->addEntry(array(
                        $label,
                        $rec['masters'],
                        $combined_buttons
                    ));
                }
            }
        }

        $xff = $factory->getRawHTML("filler", "&nbsp;");
        $block->addFormField(
            $xff,
            $factory->getLabel(" "),
            $defaultPage
        );

        // Add the "Add Secondary Service..." Pulldown:
        if ($BX_SESSION['gui_theme'] == 'adminica') {
            $block->addFormField(
                $addButton,
                $factory->getLabel(" "),
                $defaultPage
            );
        }
        else {
            $elmer_pulldown_list[] = $addButton;
            $pulldown_list = $factory->getCompositeFormField($elmer_pulldown_list, '');
            $pulldown_list->setColumnWidths(array('col_25', 'col_25', 'col_25'));
            $pulldown_list->setClass('pb-20');

            $block->addFormField(
                $pulldown_list,
                $factory->getLabel(" "),
                $defaultPage
            );
        }

        // Commit-Integer: We need at least one form field to be able to submit data.
        // So we use this hidden one:
        $xff = $factory->getTextField('commit', time(), '');
        $block->addFormField(
            $xff,
            $factory->getLabel("commit"), 
            $defaultPage
        );  

        // Show the ScrollList of the DNS Records:
        $xff = $factory->getRawHTML("sec_list", $ScrollList->toHtml());
        $block->addFormField(
            $xff,
            $factory->getLabel("sec_list"),
            $defaultPage
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/dns/dnsmanager"));

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