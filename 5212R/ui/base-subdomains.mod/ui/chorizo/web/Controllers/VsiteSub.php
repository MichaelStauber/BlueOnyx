<?php 
namespace Subdomains\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class VsiteSub extends BaseController {
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

        $CI =& get_instance();

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $SystemSubdomains = $CI->cceClient->get($System['OID'], "subdomains");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-subdomains", "/subdomains/vsiteSub");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Handle form data:
        //

        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a group set:
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

        //
        //-- Prepare data:
        //

        // Get data for the Vsite:
        $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));
        $vsiteSub = $CI->cceClient->getObject('Vsite', array('name' => $group), "subdomains");

        //
        //--- Handle form validation:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Has getPost request:
            $form_data = $BxPage->FORM_POST;

            // Form fields that are required to have input:
            $required_keys = array("vsite_enabled", "max_subdomains");

            // Empty array for key => values we want to submit to CCE:
            $attributes = array();

            // Items we do NOT want to submit to CCE:
            $ignore_attributes = array("BlueOnyx_Info_Text");

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
            //--- Own error checks:
            //


            //
            //--- No errors? Submit to CODB:
            //

            if (count($errors) == "0") {

                // We have no errors. We submit to CODB.
                $cfg = array(
                    "enabled" => $attributes['vsite_enabled'],
                    "vsite_enabled" => $attributes['vsite_enabled'],
                    "max_subdomains" => $attributes['max_subdomains'],
                    "sub_ssl" => $attributes['sub_ssl']
                );
                $CI->cceClient->setObject("Vsite", $cfg, "subdomains", array('name' => $attributes['group']));

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Restart Apache and (if applicable) FPM:
                $CI->cceClient->set($vsite['OID'], '',  array('force_update' => time()));

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, "/subdomains/vsiteSub?group=$group");
            }
        }

        //
        //-- Generate page:
        //

        // Determine current user's access rights to view or edit information
        // here.  Only 'manageSite' can modify things on this page. 
        if ($CI->serverScriptHelper->getAllowed('manageSite')) {
            $is_site_admin = TRUE;
            $access = 'rw';
        }
        elseif (($CI->serverScriptHelper->getAllowed('siteAdmin')) && ($group == $CI->serverScriptHelper->loginUser['site'])) {
            $access = 'r';
            $is_site_admin = FALSE;
        }
        else {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }

        // Prepare Page:
        $BxPage->setFormUrl("/subdomains/vsiteSub?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteservices');
        $BxPage->setVerticalMenuChild('nuonce_subdomain_vsite');
        $page_module = 'base_sitemanage';

        if ($vsiteSub["max_subdomains"] == 0 ) {
            $cfg["max_subdomains"] = $vsiteSub["max_subdomains"] = $SystemSubdomains['default_max_subdomains'];
            $CI->cceClient->setObject("Vsite", $cfg, "subdomains", array('name' => $group));
        }

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("vsite_header", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        $subdomainOIDs = $CI->cceClient->find("Subdomains", array("group" => $group));
        $domain = $vsite["domain"];
        $count = count($subdomainOIDs);

        if ( $vsiteSub["vsite_enabled"] ) {
            if ( $count < $vsiteSub["max_subdomains"] ) {

                // Generate +Add button:
                $addbutton = $factory->getAddButton("/subdomains/vsiteAddSub?group=$group", '[[base-subdomains.vsite_add_header]]', "DEMO-OVERRIDE");
                $buttonContainer = $factory->getButtonContainer("", $addbutton);
                $block->addFormField(
                    $buttonContainer,
                    $factory->getLabel(""),
                    $defaultPage
                );
            }
        }

        $xxx = $factory->getTextField("group", $vsite["name"], "");
        $block->addFormField(
            $xxx,
            $factory->getLabel("group"), 
            $defaultPage);

        $xxx = $factory->getBoolean("vsite_enabled", $vsiteSub["vsite_enabled"], $access);
        $block->addFormField(
            $xxx,
            $factory->getLabel("vsite_enabled"), 
            $defaultPage);

        $xxx = $factory->getBoolean("sub_ssl", $vsiteSub["sub_ssl"], $access);
        $block->addFormField(
            $xxx,
            $factory->getLabel("sub_ssl"), 
            $defaultPage);


        $max_subdomains = $factory->getInteger("max_subdomains", $vsiteSub["max_subdomains"], 1, $SystemSubdomains['default_max_subdomains'], $access);
        $max_subdomains->showBounds(1);
        $max_subdomains->setWidth(4);

        $block->addFormField(
            $max_subdomains,
            $factory->getLabel("max_subdomains"), 
            $defaultPage);

        // Add the buttons
        if ( $access == "rw" ) {
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/subdomains/vsiteSub?group=$group"));
        }

        //
        //-- Scrollist:
        //

        // ScrolList for Subdomain management:
        $scrollList = $factory->getScrollList("sub_title", array("sub_domain", "sub_path", " "), array()); 
        $scrollList->setAlignments(array("left", "left", "center"));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('ascending');
        $scrollList->setSortDisabled(array('2'));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);
        $scrollList->setCurrentLabel("[[base-subdomains.menu_vsite_subdomains]]");
        $scrollList->setDescription("[[base-subdomains.menu_vsite_subdomains_help]]");
        $scrollList->setColumnWidths(array("35%", "35%", "30%")); // Max: 739px

        foreach ( $subdomainOIDs as $OID ) {
            $subdomain = $CI->cceClient->get($OID);
            if ($subdomain["domainname"] == "") {
                $subdomain["domainname"] = $vsite['domain'];
            }
            if ($subdomain["hostname"] != '') {
                $fqdn = $subdomain["hostname"] . '.' . $subdomain["domainname"];
            }
            else {
                $fqdn = $subdomain["domainname"];
            }
            
            // Delete-Subdomain-Button:
            $deleteButton = $factory->getModifyButton('/subdomains/vsiteDelSub?group=' . $group . '&OID=' . $OID . '&fqdn=' . $fqdn);
            $deleteButton->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $deleteButton->setButtonSize("xs");
            }
            $deleteButton->setButtonSpecialStyle('square_animated');
            $deleteButton->setIcon('fa fa-trash-o');
            $deleteButton->setButtonColor('danger');
            $deleteButton->setImageOnly(TRUE);
            $deleteButton->setTarget('_self');
            $deleteButton->setDescription($i18n->getHtml("[[palette.remove_help]]"));
            $deleteButton->addButtonClass('dialog_button');
            $deleteButton->setModal('dialog', '/subdomains/vsiteDelSub?group=' . $group . '&OID=' . $OID . '&fqdn=' . $fqdn);

            if ($subdomain["isUser"]) {
                $deleteButton->setDisabled(TRUE);
            }

            // Add ButtonContainer with the buttons:
            $buttonContainer = $factory->getButtonContainer("", array($deleteButton));
            $buttonContainer->setMargin('pull-right');

            // Populate ScrollList:
            $scrollList->addEntry(array($fqdn, $subdomain["webpath"], $buttonContainer->toHtml()));
        }

        // Push out the Scrollist:
        $xxx = $factory->getRawHTML("subDomainList", $scrollList->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("subDomainList"),
            $defaultPage
        );

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
                            <div id="modalDeleteButton" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-subdomains.sub_dom_remove_header]]") . '">
                                <div class="block">
                                        <div class="section">
                                                <h1>' . $i18n->getHtml("[[base-subdomains.sub_dom_remove_header]]") . '</h1>
                                                <div class="dashed_line"></div>
                                                <p>' . $i18n->getHtml("[[base-subdomains.sub_dom_remove_question]]") . '</p>
                                        </div>
                                </div>
                            </div>
                </div>';
        }
        else {
            // Set extra-footers for do you really want to delete" dialog for Elmer:
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
            $modal_title = $i18n->getHtml("[[base-subdomains.sub_dom_remove_header]]");
            $modal_body = $i18n->getHtml("[[base-subdomains.sub_dom_remove_question]]");
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