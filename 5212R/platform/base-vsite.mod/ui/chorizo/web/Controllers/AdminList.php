<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class AdminList extends BaseController {
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

        if (!$CI->getAllowed('systemAdministrator')) {
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
        //-- Generate page:
        //

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/adminList");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_sysmanage';

        $defaultPage = "basic";

        $block = $factory->getPagedBlock("adminUsersList", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Basic Tab
        //

        $adminList = $factory->getScrollList("adminUsersList", array('fullName', 'userName', 'userSuspended', 'actions'), array()); 
        $adminList->setAlignments(array("left", "left", "center", "center"));
        $adminList->setDefaultSortedIndex('0');
        $adminList->setSortOrder('ascending');
        $adminList->setSortDisabled(array('3'));
        $adminList->setPaginateDisabled(FALSE);
        $adminList->setSearchDisabled(FALSE);
        $adminList->setSelectorDisabled(FALSE);
        $adminList->enableAutoWidth(FALSE);
        $adminList->setInfoDisabled(FALSE);
        $adminList->setColumnWidths(array("320", "178", "120", "120")); // Max: 739px

        // Get a list of all 'adminUser'. As is this excludes user 'admin':
        $admins = $CI->cceClient->findx('User', 
                        array("capLevels" => 'adminUser'),
                        array(), 
                        "",
                        "");

        for($i=0; $i < count($admins); $i++) {
            $oid = $admins[$i];
            $current = $CI->cceClient->get($admins[$i]);

            // Add the Modify / Delete buttons. The Delete button is done manually as
            // we need to wiggle a confirm dialog into it.
            $actions = $factory->getCompositeFormField();
            $actions->setAlignment('right');

            // Edit-Button:
            $modify = $factory->getModifyButton("/vsite/manageAdmin?MODIFY=1&_oid=$admins[$i]");
            $modify->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $modify->setButtonSize("xs");
            }
            $modify->setButtonSpecialStyle('square_animated');
            $modify->setImageOnly(TRUE);
            $modify->setTarget('_self');
            $actions->addFormField($modify);

            $remove = $factory->getModifyButton("/vsite/manageAdmin?DELETE=1&_oid=$admins[$i]");
            $remove->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $remove->setButtonSize("xs");
            }
            $remove->setButtonSpecialStyle('square_animated');
            $remove->setIcon('fa fa-trash-o');
            $remove->setButtonColor('danger');
            $remove->setImageOnly(TRUE);
            $remove->setTarget('_self');
            $remove->setDescription($i18n->getHtml("remove_help", "palette"));
            $remove->addButtonClass('dialog_button');
            $remove->setModal('dialog', "/vsite/manageAdmin?DELETE=1&_oid=$admins[$i]");

            $actions->addFormField($remove);

            // Enabled / Disabled display:
            if ($current['ui_enabled'] == "1") {
                $activeStatus = $factory->getButton('javascript:void(0);', $i18n->getHtml("[[palette.enabled_short]]"));
                $activeStatus->MakeTooltip($i18n->getHtml("[[palette.enabled]]"), 'top');
                $activeStatus->setTextOnly(TRUE);
                $activeStatus->setButtonSize('xs');
                $activeStatus->setButtonColor('success');

            }
            else {
                $activeStatus = $factory->getButton('javascript:void(0);', $i18n->getHtml("[[palette.disabled_short]]"));
                $activeStatus->MakeTooltip($i18n->getHtml("[[palette.disabled]]"), 'top');
                $activeStatus->setTextOnly(TRUE);
                $activeStatus->setButtonSize('xs');
                $activeStatus->setButtonColor('danger');
            }

            if ($current['name'] == $CI->BX_SESSION['loginName']) {
                // We don't allow a systemAdministrator to edit or delete himself.
                // Remove the buttons and add s spacer to stretch to correct height.
                $actions = '<IMG BORDER="0" WIDTH="0" HEIGHT="45" SRC="/libImage/spaceHolder.gif">';
            }

            if ($current['name'] != 'admin') {
                // Populate Scrollist:
                $adminList->addEntry(array(
                            bx_charsetsafe($current['fullName']),
                            bx_charsetsafe($current['name']),
                            $activeStatus,
                            $actions
                            ));
            }
        }

        // Generate +Add button:
        $addAdminUser = "/vsite/manageAdmin";
        $addbutton = $factory->getAddButton($addAdminUser, '[[base-vsite.addAdminHelp]]', "DEMO-OVERRIDE");
        $buttonContainer = $factory->getButtonContainer("adminUsersList", $addbutton);
        $block->addFormField(
            $buttonContainer,
            $factory->getLabel("adminUsersList"),
            $defaultPage
        );

        // Push out the Scrollist with the admin-users:
        $ffs = $factory->getRawHTML("adminUsersList", $adminList->toHtml());
        $block->addFormField(
            $ffs,
            $factory->getLabel("adminUsersList"),
            $defaultPage
        );

        if (isset($current['name'])) {
            // Add hidden Modal for Admin delete confirmation:
            if ($BX_SESSION['gui_theme'] == 'adminica') {

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

                $page_body[] = '
                    <div class="display_none">
                                <div id="modalDeleteButton" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-vsite.adminOptions]]") . '">
                                    <div class="block">
                                            <div class="section">
                                                    <h1>' . $i18n->getHtml("[[base-vsite.adminOptions]]") . '</h1>
                                                    <div class="dashed_line"></div>
                                                    <p>' . $i18n->getHtml("[[base-vsite.deleteQuestion]]", false, array('name' => $current['name'])) . '</p>
                                            </div>
                                    </div>
                                </div>
                    </div>';
            }
            else {
                // Add hidden Modal for Delete-Confirmation for Elmer:
                $modal_title = $i18n->getHtml("[[base-vsite.adminOptions]]");
                $modal_body = $i18n->getHtml("[[base-vsite.deleteQuestion]]", false, array('name' => $current['name']));
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
            }
        }

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