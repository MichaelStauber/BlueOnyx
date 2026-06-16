<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class SoftwareList extends BaseController {
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

        if (!$CI->getAllowed('managePackage')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/softwareList");
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

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Page logic start:
        //

        // Start with an empty siteList:
        $pkgList = array();

        // Prepare Page:
        $BxPage->setFormUrl("/swupdate/softwareList");
        $BxPage->setErrors($errors);

        // Get a list of installed PKGs:
        $pkgOIDs = $CI->cceClient->findNSorted("Package", 'version', array('installState' => 'Installed'));

        // PKGs only have the locales 'en_US';
        $i18n_EN = new I18n("palette", 'en_US');

        for($i = 0; $i < count($pkgOIDs); $i++) {
            $package = $CI->cceClient->get($pkgOIDs[$i]);

            $packageName = $package["nameTag"] ? $i18n_EN->interpolate($package["nameTag"]) : $package["name"];
            if (($packageName === 'name') || ($packageName === 'nameTag')) {
                $packageName = $package["name"];
            }
            $version = $package["versionTag"] ? $i18n_EN->interpolate($package["versionTag"]) : substr($package["version"], 1);
            $vendorName = $package["vendorTag"] ? $i18n_EN->interpolate($package["vendorTag"]) : $package["vendor"];
            if (($vendorName === 'vendor') || ($vendorName === 'vendorTag')) {
                $vendorName = $package["vendor"];
                if ($vendorName === 'solarspeed_net') {
                    $vendorName = 'Solarspeed.net';
                }
            }
            $description = $i18n_EN->interpolate($package["shortDesc"]);
            if ($description === 'shortDesc') {
                $description = $i18n_EN->get('[[base-swupdate.descriptionField]]');
            }
            $uninstallable = strstr($package['options'], 'uninstallable');
            $oid = &$pkgOIDs[$i];

            //
            // Create the 'Uninstall'-button. We could use getUninstallButton(), but this will do:
            //

            // Escape PKG info for usage in URL:
            $escName=$i18n->interpolateJs("[[VAR.foo,foo=\"$packageName\"]]");
            if ($uninstallable) {

                // Only allow uninstall if we're not in DEMO mode:
                if (!is_file("/etc/DEMO")) {

                    if ($BX_SESSION['gui_theme'] === 'adminica') {

                        // PKG is uninstallable:
                        $button = '<a class="lb' . $oid. '" href="/swupdate/uninstallHandler?nameField=' . $escName . '&packageOID=' . $oid . '"><button class="small icon_only ui-icon-circle-close tooltip hover dialog_button" title="' . $i18n->getWrapped("uninstall_help", "palette") . '"><div class="ui-icon ui-icon-trash"></div></button></a>';

                        // Extra header for the "do you really want to uninstall " dialog Modal:
                        $BxPage->setExtraHeaders('
                                <script type="text/javascript">
                                $(document).ready(function () {

                                  $("#dialog' . $oid . '").dialog({
                                    modal: true,
                                    bgiframe: true,
                                    width: 500,
                                    height: 200,
                                    autoOpen: false
                                  });

                                  $(".lb' . $oid . '").click(function (e) {
                                    e.preventDefault();
                                    var hrefAttribute = $(this).attr("href");

                                    $("#dialog' . $oid . '").dialog(\'option\', \'buttons\', {
                                      "' . $i18n->getHtml("[[base-swupdate.uninstall]]") . '": function () {
                                        window.location.href = hrefAttribute;
                                      },
                                      "' . $i18n->getHtml("[[palette.cancel]]") . '": function () {
                                        $(this).dialog("close");
                                      }
                                    });

                                    $("#dialog' . $oid . '").dialog("open");

                                  });
                                });
                                </script>');

                                // Add hidden Modal for Delete-Confirmation:
                                $page_body[] = '
                                    <!-- Start: Hidden uninstall confirm Modal for ' . $packageName . '-->
                                    <div class="display_none">
                                                <div id="dialog' . $oid . '" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-swupdate.uninstall]]") . '">
                                                    <div class="block">
                                                            <div class="section">
                                                                    <h1>' . $i18n->getHtml("[[base-swupdate.uninstall]]") . '</h1>
                                                                    <div class="dashed_line"></div>
                                                                    <p>' . $i18n->interpolate("[[base-swupdate.uninstallConfirm]]", array('packageName' => $packageName)) . '</p>
                                                            </div>
                                                    </div>
                                                </div>
                                    </div>
                                    <!-- End: Hidden uninstall confirm Modal for ' . $packageName . '-->';
                    }
                    else {

                        // Uninstall-Button:
                        $dialog_id = 'dialog' . $oid;
                        $dialog_id_modalDeleteButton = 'modalDeleteButton' . $oid;
                        $escName = urlencode($packageName);
                        $uninstall_URL = '/swupdate/uninstallHandler?nameField=' . $escName . '&packageOID=' . $oid;

                        $uninstallButton = $factory->getModifyButton($uninstall_URL);
                        $uninstallButton->setButtonSize("small");
                        $uninstallButton->setButtonSpecialStyle('square_animated');
                        $uninstallButton->setIcon('fa fa-trash-o');
                        $uninstallButton->setButtonColor('danger');
                        $uninstallButton->setImageOnly(TRUE);
                        $uninstallButton->setTarget('_self');
                        $uninstallButton->setDescription($i18n->getHtml("[[palette.uninstall_help]]"));
                        $uninstallButton->addButtonClass('dialog_button');
                        $uninstallButton->setModal($dialog_id, $uninstall_URL);

                        // Add ButtonContainer with the buttons:
                        $buttonContainer = $factory->getButtonContainer("", array($uninstallButton));
                        $buttonContainer->setMargin('pull-right');

                        $button = $buttonContainer->toHtml();

                        // Add hidden Modal for Delete-Confirmation for Elmer:
                        $modal_title = $i18n->getHtml("[[base-swupdate.uninstall]]");
                        $modal_body = $i18n->interpolate("[[base-swupdate.uninstallConfirm]]", array('packageName' => $packageName));
                        $modal_remove = $i18n->getHtml("[[palette.remove]]");
                        $modal_cancel = $i18n->getHtml("[[palette.cancel]]");

                        // Note to self: We use bootstrap, so we don't need any extra scripts here to fire up the modal via our button:
                        $modal_html =<<<HTML

                                    <!-- Start: Hidden uninstall confirm Modal for $packageName -->
                                    <div id="$dialog_id" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="dialogLabel" aria-hidden="true">
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
                                                    <button type="button" class="btn btn-danger btn-anim link_button" title="$modal_remove" data-original-title="$modal_remove" data-container="body" onclick="openUrl('$uninstall_URL', '_self')">
                                                        <i class="fa fa-trash-o"></i><span class="btn-text">$modal_remove</span>
                                                    </button>
                                                    <button class="btn btn-primary btn-anim" data-dismiss="modal"><i class="fa fa-times"></i><span class="btn-text">$modal_cancel</span></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- End: Hidden uninstall confirm Modal for $packageName -->

                        HTML;
                        $page_body[] = $modal_html;

                        if ($BX_SESSION['gui_theme'] === 'elmer') {
                            // Set extra-footers for do you really want to delete" dialog for Elmer:
                            $modalScript =<<<HTML

                                <script>
                                    $(document).ready(function() {
                                        // Add a click event to open the corresponding modal
                                        $('.dialog_button').click(function () {
                                            var modalId = $(this).data('modal-id');
                                            var url = $(this).data('url');
                                            $('#' + modalId + ' .link_button').attr('onclick', "openUrl('" + url + "', '_self')");
                                            $('#' + modalId).modal('show');
                                        });
                                    });
                                </script>

                            HTML;
                            $BxPage->setExtraFooters($modalScript);
                        }
                    }
                }
                else {
                    // Demo mode: Button disabled
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $helptext = $i18n->getWrapped("uninstall_help", "palette") . " " . $i18n->getWrapped("demo_mode", "palette");
                        $button = '<button title="' . $helptext . '" class="close_dialog small tooltip right link_button" data-link="javascript: void()" target="_self"><div class="ui-icon ui-icon-trash"></div></button>';
                    }
                    else {
                        // Uninstall-Button:
                        $dialog_id = 'dialog' . $oid;
                        $dialog_id_modalDeleteButton = 'modalDeleteButton' . $oid;
                        $escName = urlencode($packageName);
                        $uninstall_URL = '/swupdate/uninstallHandler?nameField=' . $escName . '&packageOID=' . $oid;

                        $uninstallButton = $factory->getModifyButton('javascript:void(0);');
                        $uninstallButton->setButtonSize("small");
                        $uninstallButton->setButtonSpecialStyle('square_animated');
                        $uninstallButton->setIcon('fa fa-trash-o');
                        $uninstallButton->setButtonColor('danger');
                        $uninstallButton->setImageOnly(TRUE);
                        $uninstallButton->setTarget('_self');
                        $uninstallButton->setDescription($i18n->getHtml("[[palette.demo_mode]]"));
                        $uninstallButton->setDisabled(TRUE);
                        $uninstallButton->setModal($dialog_id, $uninstall_URL);

                        // Add ButtonContainer with the buttons:
                        $buttonContainer = $factory->getButtonContainer("", array($uninstallButton));
                        $buttonContainer->setMargin('pull-right');

                        $button = $buttonContainer->toHtml();
                    }
                }
            }
            else {
                // Disable button if not uninstallable
                if ($BX_SESSION['gui_theme'] === 'adminica') {
                    $button = '<button title="' . $i18n->getWrapped("uninstall_disabled_help", "palette") . '" class="close_dialog small tooltip right link_button" data-link="javascript: void()" target="_self"><div class="ui-icon ui-icon-circle-close"></div></button>';
                }
                else {
                    // Uninstall-Button:
                    $dialog_id = 'dialog' . $oid;
                    $dialog_id_modalDeleteButton = 'modalDeleteButton' . $oid;
                    $escName = urlencode($packageName);
                    $uninstall_URL = '/swupdate/uninstallHandler?nameField=' . $escName . '&packageOID=' . $oid;

                    $uninstallButton = $factory->getModifyButton('javascript:void(0);');
                    $uninstallButton->setButtonSize("small");
                    $uninstallButton->setButtonSpecialStyle('square_animated');
                    $uninstallButton->setIcon('fa fa-lock');
                    $uninstallButton->setButtonColor('default');
                    $uninstallButton->setImageOnly(TRUE);
                    $uninstallButton->setTarget('_self');
                    $uninstallButton->setDescription($i18n->getHtml("[[palette.uninstall_disabled_help]]"));
                    $uninstallButton->setDisabled(TRUE);
                    $uninstallButton->addButtonClass('dialog_button');
                    $uninstallButton->setModal($dialog_id, $uninstall_URL);

                    // Add ButtonContainer with the buttons:
                    $buttonContainer = $factory->getButtonContainer("", array($uninstallButton));
                    $buttonContainer->setMargin('pull-right');

                    $button = $buttonContainer->toHtml();
                }
            }

            // Populate the output array with the results:
            $pkgList[0][$i] = $packageName;
            $pkgList[1][$i] = $version;
            $pkgList[2][$i] = $vendorName;
            $pkgList[3][$i] = $description;
            $pkgList[4][$i] = $button;
        }

        //-- Generate page:

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('base_softwareInstalled');
        $page_module = 'base_software';

        $scrollList = $factory->getScrollList("installedList", array("nameField", "versionField", "vendorField", "descriptionField", "uninstall"), $pkgList); 
        $scrollList->setAlignments(array("left", "left", "left", "left", "center"));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('ascending');
        $scrollList->setSortDisabled(array('5'));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);
        $scrollList->setColumnWidths(array("180", "80", "200", "243", "35")); // Max: 739px

        $page_body[] = $scrollList->toHtml();

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