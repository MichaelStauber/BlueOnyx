<?php 
namespace Power\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Poweroptions extends BaseController {
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

        if (!$CI->getAllowed('serverPower')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-power", "/power/poweroptions");
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

        }

        $action = 'none';
        $sid = 'none';
        $zug_tillim = '0';

        if (isset($get_form_data['p'])) {
            if (preg_match('/:/', $get_form_data['p'])) {
                $urlkeys = explode(':', $get_form_data['p']);
                if ((isset($urlkeys[0])) && (isset($urlkeys[1]))) {
                    $action = $urlkeys[0];
                    $sid = $urlkeys[1];
                    if ($sid != $BX_SESSION['sessionId']) {
                        $action = 'none';
                    }
                }
            }
            
            if ($action == "reboot") {
                $attributes = array("reboot" => time());
                $zug_tillim = '1';

                $errors[] = ErrorMessage($i18n->get('[[base-power.rebooting]]'), 'alert_red', 'alert');
            }
            if ($action == "shutdown") {
                $attributes = array("halt" => time());
                $zug_tillim = '1';
                $errors[] = ErrorMessage($i18n->get('[[base-power.shutting-down]]'), 'alert_red', 'alert');
            }           

            if ((!is_file("/etc/DEMO")) && ($zug_tillim == '1')) {
                // Actual submit to CODB - But we won't do it in DEMO-Mode:
                $CI->cceClient->setObject("System", $attributes, "Power");
            }
            elseif ((is_file("/etc/DEMO")) && ($zug_tillim == '1')) {
                $errors[] = ErrorMessage($i18n->get('[[palette.demo_mode]]'), 'alert_green', 'info_about');
            }
            else {
                // Page has been reloaded with old URL string. Do nothing.
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/power/poweroptions");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_serverconfig');
        $page_module = 'base_sysmanage';


        //
        // -- Button-Header:
        //

        // Reboot button:
        $rebootbutton = $factory->getButton("/power/poweroptions?p=reboot:". $BX_SESSION['sessionId'], 'reboot', 'DEMO-OVERRIDE');
        $rebootbutton->setIcon('fa fa-refresh');
        $rebootbutton->addButtonClass('dialog_button');
        $rebootbutton->setModal('dialog_reboot', "/power/poweroptions?p=reboot:". $BX_SESSION['sessionId']);

        // Shutdown button:
        $shutdownbutton = $factory->getButton("/power/poweroptions?p=shutdown:". $BX_SESSION['sessionId'], 'shutdown_menu', 'DEMO-OVERRIDE');
        $shutdownbutton->setIcon('fa fa-power-off');
        $shutdownbutton->setButtonColor('danger');
        $shutdownbutton->addButtonClass('dialog_button_shutdown');
        $shutdownbutton->setModal('dialog_shutdown', "/power/poweroptions?p=shutdown:". $BX_SESSION['sessionId']);

        $buttonContainer = $factory->getButtonContainer("", array($rebootbutton, $shutdownbutton));

        if ($BX_SESSION['gui_theme'] == 'adminica') {

            // Extra header for Reboot confirmation dialog:

            $adminica_combi_modal_init =<<<HTML
                        <script type="text/javascript">
                        $(document).ready(function () {
                            // Initialize the reboot dialog with "Reboot" and "Cancel" buttons
                            $("#dialog_reboot").dialog({
                                modal: true,
                                bgiframe: true, // Support for older browsers
                                width: 500,
                                height: 330,
                                autoOpen: false,
                                buttons: {
                                    "{$i18n->getHtml("[[base-power.reboot]]")}": function() {
                                        // Redirect to the reboot URL
                                        window.location.href = $(this).data("url");
                                    },
                                    "{$i18n->getHtml("[[palette.cancel]]")}": function() {
                                        $(this).dialog("close");
                                    }
                                }
                            });

                            // Attach click event to the reboot button
                            $(".dialog_button").click(function (e) {
                                e.preventDefault();
                                var url = $(this).data("link"); // Get the URL
                                // Update the reboot dialog's data-url attribute with the new URL
                                $("#dialog_reboot").data("url", url);
                                // Open the reboot dialog
                                $("#dialog_reboot").dialog("open");
                            });

                            // Initialize the shutdown dialog with "Shutdown" and "Cancel" buttons
                            $("#dialog_shutdown").dialog({
                                modal: true,
                                bgiframe: true, // Support for older browsers
                                width: 500,
                                height: 330,
                                autoOpen: false,
                                buttons: {
                                    "{$i18n->getHtml("[[base-power.shutdown_menu]]")}": function() {
                                        // Redirect to the shutdown URL
                                        window.location.href = $(this).data("url");
                                    },
                                    "{$i18n->getHtml("[[palette.cancel]]")}": function() {
                                        $(this).dialog("close");
                                    }
                                }
                            });

                            // Attach click event to the shutdown button
                            $(".dialog_button_shutdown").click(function (e) {
                                e.preventDefault();
                                var url = $(this).data("link"); // Get the URL
                                // Update the shutdown dialog's data-url attribute with the new URL
                                $("#dialog_shutdown").data("url", url);
                                // Open the shutdown dialog
                                $("#dialog_shutdown").dialog("open");
                            });
                        });
                        </script>

            HTML;

            $BxPage->setExtraHeaders($adminica_combi_modal_init);

            // Display error messages:
            if (count($errors) > '0') {
                foreach ($errors as $key => $value) {
                    $page_body[] = $value;
                }
            }

            // Add hidden Modal for Reboot / Shutdown - Confirmation:
            $page_body[] = '
                <div class="display_none">
                            <div id="dialog_reboot" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-power.askRebootConfirmation]]") . '">
                                <div class="block">
                                        <div class="section">
                                                <h1>' . $i18n->getHtml("[[base-power.reboot]]") . '</h1>
                                                <p>' . $i18n->getHtml("[[base-power.askRebootConfirmation]]") . '</p>
                                                <p>' . $i18n->getHtml("[[base-power.rebootMessage]]") . '</p>
                                        </div>
                                </div>
                            </div>
                </div>
                <div class="display_none">
                            <div id="dialog_shutdown" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-power.askShutdownConfirmation]]") . '">
                                <div class="block">
                                        <div class="section">
                                                <h1>' . $i18n->getHtml("[[base-power.shutdown_menu]]") . '</h1>
                                                <div class="dashed_line"></div>
                                                <p>' . $i18n->getHtml("[[base-power.askShutdownConfirmation]]") . '</p>
                                                <p>' . $i18n->getHtml("[[base-power.shutdown_menu_help]]") . '</p>
                                        </div>
                                </div>
                            </div>
                </div>';
        }
        else {
            // Add hidden Modal for Reboot-Confirmation for Elmer:
            $modal_title = $i18n->getHtml("[[base-power.reboot]]");
            $modal_body = '<div><strong>' . $i18n->getHtml("[[base-power.askRebootConfirmation]]") . '</strong></div><div class="mt-15">' . $i18n->getHtml("[[base-power.rebootMessage]]") . '</div>';
            $modal_remove = $i18n->getHtml('reboot');
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
                                        <button class="btn btn-danger btn-anim link_button" id="modalDeleteButton"><i class="fa fa-refresh"></i><span class="btn-text">$modal_remove</span></button>
                                        <button class="btn btn-primary btn-anim" data-dismiss="modal"><i class="fa fa-times"></i><span class="btn-text">$modal_cancel</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- /Delete-Confirm modal -->

            HTML;

            $BxPage->setExtraFooters($modal_html);

            // Set extra-footers for Reboot-Confirmation for Elmer:
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

            // Add hidden Modal for Shutdown-Confirmation for Elmer:
            $modal_title = $i18n->getHtml("[[base-power.shutdown_menu]]");
            $modal_body = '<div><strong><mark>' . $i18n->getHtml("[[base-power.askShutdownConfirmation]]") . '</mark></strong></div><div class="mt-15">' . $i18n->getHtml("[[base-power.shutdown_menu_help]]") . '</div>';
            $modal_remove = $i18n->getHtml('shutdown_menu');
            $modal_cancel = $i18n->getHtml("[[palette.cancel]]");
            $modal_html =<<<HTML

                        <!-- Delete-Confirm modal -->
                        <div id="dialog_shutdown" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="dialogLabel" aria-hidden="true">
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
                                        <button class="btn btn-danger btn-anim link_button" id="modalShutdownButton"><i class="fa fa-power-off"></i><span class="btn-text">$modal_remove</span></button>
                                        <button class="btn btn-primary btn-anim" data-dismiss="modal"><i class="fa fa-times"></i><span class="btn-text">$modal_cancel</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- /Delete-Confirm modal -->

            HTML;

            $BxPage->setExtraFooters($modal_html);

            // Set extra-footers for Shutdown-Confirmation for Elmer:
            $BxPage->setExtraFooters('
                <script>
                    // Activate the tooltip
                    $(\'[data-toggle="tooltip"]\').tooltip();

                    // Add a click event to open the modal
                    $(\'.dialog_button_shutdown\').click(function () {
                        var url = $(this).data(\'url\');
                        $(\'#modalShutdownButton\').data(\'url\', url);
                        $(\'#dialog_shutdown\').modal(\'show\');
                    });

                    // Add a click event to the modal\'s deletion button
                    $(\'#modalShutdownButton\').click(function () {
                        var url = $(this).data(\'url\');
                        // Perform your deletion action or redirect to the specified URL
                        window.location.href = url; // Example: Redirect to the URL
                    });
                </script>
            ');

        }

        // Display error messages:
        if (count($errors) > '0') {
            foreach ($errors as $key => $value) {
                $page_body[] = $value;
            }
        }

        $page_body[] = $buttonContainer->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }       
}
/*
Copyright (c) 2014-2023 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2014-2023 Team BlueOnyx, BLUEONYX.IT
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