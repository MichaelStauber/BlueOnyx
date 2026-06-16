<?php 
namespace Network\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Pooling extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-network", "/network/pooling");
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

        //
        //--- Get CODB-Object of interest: 
        //

        $network = $CI->cceClient->get($System['OID'], 'Network');
        $enabled = $network['pooling'];

        // Get all adminUsers:
        $oids = $CI->cceClient->findx('User', 
                                        array('capLevels' => 'adminUser'), 
                                        array(), 
                                        'ascii', 
                                        'name'); 
         
        foreach ($oids as $oid) { 
            $admins[$oid] = $CI->cceClient->get($oid); 
        } 

        // Add 'admin' as well:
        $oids = $CI->cceClient->findx('User', 
                                        array('name' => 'admin'), 
                                        array(), 
                                        'ascii', 
                                        'name');
        foreach ($oids as $oid) { 
            $admins[$oid] = $CI->cceClient->get($oid); 
        }

        $oids = $CI->cceClient->findx('IPPoolingRange', array(), array(), 'old_numeric', 'creation_time');
        $ranges = array();

        foreach ($oids as $oid) {
          $ranges[$oid] = $CI->cceClient->get($oid);
        }

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

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {
            // Not needed. Thank you, jQuery!
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "Network",  array('pooling' => $attributes['enabled']));

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();

            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '&nbsp;');
            }
            $redirect_URL = "/network/pooling";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/network/pooling");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_serverconfig');
        $BxPage->setVerticalMenuChild('base_sitepooling');
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("pooling_block", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $block->setDisplayErrors(TRUE);

        $xxx = $factory->getBoolean("enabled", $enabled);
        $block->addFormField(
            $xxx,
            $factory->getLabel("enabledField"),
            $defaultPage
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/network/pooling"));

        // Add-Button:
        $block2 = $factory->getPagedBlock("rangeList", array($defaultPage));
        $block2->setDisplayErrors(FALSE);
        $addAlias = "/network/poolingModify";
        $addbutton = $factory->getAddButton($addAlias, '[[base-network.add]]', "DEMO-OVERRIDE");
        $buttonContainer = $factory->getButtonContainer("", $addbutton);
        $block2->addFormField(
            $buttonContainer,
            $factory->getLabel("add"),
            $defaultPage
        );

        // Set up the ScrollList:
        $scrollList = $factory->getScrollList("rangeList", array("min", "max", "admin", " "), array()); 
        $scrollList->setAlignments(array("center", "center", "center", "center"));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('descending');
        $scrollList->setSortDisabled(array('3'));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);
        $scrollList->setColumnWidths(array("30%", "30%", "20%", "50")); // Max: 739px      

        reset($ranges);

        //while (list($oid, $range) = each($ranges)) {
        foreach ($ranges as $oid => $range) {

            // Loop through data and add the entries to scroll list
            // If we need to edit, make the $act_on field read/write, with save buttons
            // Else, just display the data in $range_mins, $range_maxes
            $min_string = "range_min$oid";
            $max_string = "range_max$oid";

            $minField = $range['min'];
            $maxField = $range['max'];

            // Create the buttons

            // Edit-Button:
            $editButton = $factory->getModifyButton("/network/poolingModify?ACTION=M&_oid=$oid");
            $editButton->setButtonSize("small");
            $editButton->setButtonSpecialStyle('square_animated');
            $editButton->setImageOnly(TRUE);
            $editButton->setTarget('_self');

            // Delete-Button:
            $deleteButton = $factory->getModifyButton("/network/poolingModify?ACTION=D&_oid=$oid");
            $deleteButton->setButtonSize("small");
            $deleteButton->setButtonSpecialStyle('square_animated');
            $deleteButton->setIcon('fa fa-trash-o');
            $deleteButton->setButtonColor('danger');
            $deleteButton->setImageOnly(TRUE);
            $deleteButton->setTarget('_self');

            // Add ButtonContainer with the buttons:
            $buttonContainer = $factory->getButtonContainer("", array($editButton, $deleteButton));
            $buttonContainer->setMargin('pull-right');

            if ((isset($range['admin'])) && ($range['admin'] != "")) {
                $adminField = join(', ', $CI->cceClient->scalar_to_array($range['admin']));
            }
            else {
                $adminField = "admin";
            }

            // Finally, add the entry to the list
            $scrollList->addEntry(array($minField, $maxField, $adminField, $factory->getCompositeFormField(array($buttonContainer,))));
        }

        // Push out the Scrollist:
        $xxx = $factory->getRawHTML("rangeList", $scrollList->toHtml());
        $block2->addFormField(
            $xxx,
            $factory->getLabel("rangeList"),
            $defaultPage
        );

        $page_body[] = $block->toHtml();
        $page_body[] = $block2->toHtml();

        // Out with the page:
        $BxPage->HaveErrorMsgDisplayArea(FALSE);
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