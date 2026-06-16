<?php
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once ("I18n.php");
include_once ("BxPage.php");
use I18n;
use BxPage;

class NewSoftware extends BaseController {
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
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/newSoftware");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array(
            'FORM_GET' => $this->request->getGet() ,
            'FORM_POST' => $this->request->getPost() ,
            'AGENT' => $this->request->getUserAgent()
        ));

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

        // -- Actual page logic start:
        //
        //--- Check if we got here via Auto-Install:
        //
        $ai_check = '';
        if (isset($_COOKIE['ai'])) {
            $ai_check = $_COOKIE['ai'];
        }

        $shopemail = '';
        if (isset($_COOKIE['shopemail'])) {
            $shopemail = $_COOKIE['shopemail'];
        }

        // Be really sure to delete the cookie:
        delete_cookie("ai");

        // if ($ai_check == "1") {
        //     $redirect_URL = '/swupdate/autoinstall';
        //     if ($shopemail != "") {
        //         $redirect_URL .= '?em=' . urlencode($shopemail);
        //     }
        //     // Redirect to continue Auto-Install of shop purchases:
        //     $BxPage->ReturnToThisPage($errors, $redirect_URL);
        // }
        //
        //--- Get CODB-Object of interest:
        //
        $CODBDATA = $CI->cceClient->get($System['OID'], "yum");

        //
        //--- Handle form validation:
        //
        // Check for new PKGs on NewLinQ:
        $refresh = '300';
        $nl_check = '';
        if (isset($_COOKIE['nl_check'])) {
            $nl_check = $_COOKIE['nl_check'];
        }
        if (($nl_check == "") || ($nl_check <= time() - $refresh)) {
            $new_msg = array();
            $i = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/grab_updates.pl -u", $ret, 'root', $BX_SESSION['sessionId']);
            setcookie("nl_check", time() , "0", "/");
        }

        $search = array(
            'installState' => 'Available',
            'new' => '1',
            'isVisible' => '1'
        );
        $oids = $CI->cceClient->findNSorted("Package", 'version', $search);
        if (count($oids) > "0") {
            $msg = '[[base-swupdate.NewUpdatesSubject]]';
            $color = 'alert_green';
            $errors[] = ErrorMessage($i18n->get($msg) , $color, 'info_about');
        }
        else {
            $color = 'alert_green';
            $msg = '[[base-swupdate.NoPackagesBody]]';
            $errors[] = ErrorMessage($i18n->get($msg) , $color, 'info_about');
        }

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:
        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array(
            "BlueOnyx_Info_Text"
        );

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
            if (isset($form_data['_serialized_errors'])) {
                if (preg_match('/\[\[(.*)\]\]/', $form_data['_serialized_errors'], $matched_error)) {
                    if (isset($matched_error[0])) {
                        $my_error_msg = $matched_error[0];
                        $errors[] = ErrorMessage($i18n->get("$my_error_msg") . '<br>&nbsp;');
                    }
                }
            }
        }

        // Get the return message from the URL string - if present:
        if (isset($get_form_data['msg'])) {
            $errors[] = ErrorMessage($i18n->get(urldecode($get_form_data['msg'])) , 'alert_green', 'info_about');
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //
        //
        //-- Own page logic:
        //
        //
        //-- Generate page:
        //
        // Prepare Page:
        $BxPage->setFormUrl("/swupdate/newSoftware");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('base_softwareNew');
        $page_module = 'base_software';

        $defaultPage = "yumTitle";

        $block = $factory->getPagedBlock("availableListNew", array(
            $defaultPage
        ));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        // Add ButtonContainer and button to manually check for updates:
        $addAdminUser = "/vsite/vsiteAdd";
        $checkNowButton = $factory->getButton('/swupdate/checkHandler?backUrl=/swupdate/newSoftware', '[[base-swupdate.checkNow]]', "DEMO-OVERRIDE");
        $checkNowButton->setIcon('fa fa-refresh');
        $checkNowButton->setDescription($i18n->getHtml("[[base-swupdate.checkNow_help]]") , 'top');

        $manualInstallButton = $factory->getButton('/swupdate/manualInstall?backUrl=/swupdate/newSoftware', '[[base-swupdate.manualInstall]]', "DEMO-OVERRIDE");
        $manualInstallButton->setIcon('fa fa-wrench');
        $manualInstallButton->setDescription($i18n->getHtml("[[base-swupdate.manualInstall_help]]") , 'top');

        $buttonContainer = $factory->getButtonContainer("", array(
            $checkNowButton,
            $manualInstallButton
        ));

        $block->addFormField($buttonContainer, $factory->getLabel("") , $defaultPage);

        //
        //--- Available YUM updates:
        //
        // Set up ScrollList:
        $ScrollList = $factory->getScrollList("availableListNew", array("typeField", "nameField", "versionField", "vendorField", "descriptionField", "installField"), array());
        $ScrollList->setAlignments(array("center", "left", "left", "left", "left", "center"));
        $ScrollList->setDefaultSortedIndex('0');
        $ScrollList->setSortOrder('ascending');
        $ScrollList->setSortDisabled(array());
        $ScrollList->setPaginateDisabled(FALSE);
        $ScrollList->setSearchDisabled(FALSE);
        $ScrollList->setSelectorDisabled(FALSE);
        $ScrollList->enableAutoWidth(FALSE);
        $ScrollList->setInfoDisabled(FALSE);
        $ScrollList->setDisplay(25);
        $ScrollList->setColumnWidths(array("75", "115", "130", "200", "183", "35")); // Max: 739px

        // Do we have any updates or complete PKGs to install?
        $search = array(
            'installState' => 'Available',
            'isVisible' => 1
        );
        $oids = $CI->cceClient->findNSorted("Package", 'version', $search);

        // Find all installed PKGs:
        $i_search = array(
            'installState' => 'Installed'
        );
        $i_oids = $CI->cceClient->findNSorted("Package", 'version', $i_search);
        $installed_package = array();
        foreach ($i_oids as $key => $i_pkg_oid) {
            $inst_pkg = $CI->cceClient->get($i_pkg_oid);
            // Build an array with all already installed PKGs and their versions:
            if ((isset($inst_pkg['name'])) && (isset($inst_pkg['version']))) {
                $installed_package[$inst_pkg['name']] = $inst_pkg['version'];
            }
        }

        // PKGs only have the locales 'en_US';
        $i18n_EN = new I18n("palette", 'en_US');

        for ($i = 0;$i < count($oids);$i++) {
            $package = $CI->cceClient->get($oids[$i]);
            $oid = & $oids[$i];

            if ((isset($package["nameTag"])) && (isset($package["packageType"]))) {
                $new = $package["new"] ? "new" : "old";
                $packageName = $package["nameTag"] ? $i18n_EN->interpolate($package["nameTag"]) : $package["name"];
                if (($packageName === 'name') || ($packageName === 'nameTag')) {
                    $packageName = $package["name"];
                }
                $version = $package["versionTag"] ? $i18n->interpolate($package["versionTag"]) : substr($package["version"], 1);
                $vendorName = $package["vendorTag"] ? $i18n_EN->interpolate($package["vendorTag"]) : $package["vendor"];
                if (($vendorName === 'vendor') || ($vendorName === 'vendorTag')) {
                    $vendorName = $package["vendor"];
                    if ($vendorName === 'solarspeed_net') {
                        $vendorName = 'Solarspeed.net';
                    }
                }
                $packageType = $package["packageType"];
                $description = $i18n_EN->interpolate($package["shortDesc"]);
                if ($description === 'shortDesc') {
                    $description = $i18n_EN->get('[[base-swupdate.descriptionField]]');
                }
                $url = $package["url"];
                $options = updates_geturloptions($CI->cceClient, $package["urloptions"]);

                if (isset($package["location"])) {
                    if (preg_match("/^file:/", $package["location"])) {
                        $removeButton = $factory->getModifyButton("/swupdate/removeHandler?backUrl=/swupdate/newSoftware&packageOID=$oid");
                        $removeButton->setButtonSize("small");
                        $removeButton->setButtonSpecialStyle('square_animated');
                        $removeButton->setIcon('fa fa-trash-o');
                        $removeButton->setButtonColor('danger');
                        $removeButton->setImageOnly(TRUE);
                        $removeButton->setTarget('_self');
                        $removeButton->setDescription($i18n->getHtml("[[palette.remove_help]]"));
                    }
                }

                $detailButton = $factory->getDetailButton("/swupdate/download?backUrl=/swupdate/newSoftware&packageOID=$oid", "installField");
                $detailButton->setImageOnly(TRUE);
                $detailButton->setButtonSize("small");
                $detailButton->setButtonSpecialStyle('square_animated');
                $detailButton->setIcon('fa fa-search');
                $detailButton->setImageOnly(TRUE);
                $detailButton->setTarget('_self');
                $detailButton->setDescription($i18n->getHtml("[[base-swupdate.installField_help]]"));

                $composite = isset($removeButton) ? array(
                    $detailButton,
                    $removeButton
                ) : array(
                    $detailButton
                );

                // Is this a new complete package? Or a new update?
                if (($new == "new") && ($packageType == "complete") && (!isset($installed_package[$package['name']]))) {
                    $status = $factory->getButton('javascript:void(0);', "[[base-swupdate.BXnewpkg]]");
                    $status->setButtonSize('xs');
                    $status->setTextOnly(TRUE);
                    $status->setDescription($i18n->getHtml("[[base-swupdate.BXnewpkg_help]]") , 'top');
                    $status->toHtml();
                }
                elseif (($new == "new") && ($packageType == "update")) {
                    $status = $factory->getButton('javascript:void(0);', "[[base-swupdate.BXnewupdate]]");
                    $status->setButtonSize('xs');
                    $status->setTextOnly(TRUE);
                    $status->setDescription($i18n->getHtml("[[base-swupdate.BXnewupdate_help]]") , 'top');
                    $status->toHtml();
                }
                elseif (($new == "new") && ($packageType == "complete") && (isset($installed_package[$package['name']]))) {
                    $status = $factory->getButton('javascript:void(0);', "[[base-swupdate.BXnewupdate]]");
                    $status->setButtonSize('xs');
                    $status->setTextOnly(TRUE);
                    $status->setDescription($i18n->getHtml("[[base-swupdate.BXnewupdate_help]]") , 'top');
                    $status->toHtml();
                }
                elseif (($new == "old") && ($packageType == "complete")) {
                    $status = $factory->getButton('javascript:void(0);', "[[base-swupdate.BXpkg]]");
                    $status->setButtonSize('xs');
                    $status->setTextOnly(TRUE);
                    $status->setDescription($i18n->getHtml("[[base-swupdate.BXpkg_help]]") , 'top');
                    $status->toHtml();
                }
                else {
                    $status = $factory->getButton('javascript:void(0);', "[[base-swupdate.BXupdate]]");
                    $status->setButtonSize('xs');
                    $status->setTextOnly(TRUE);
                    $status->setDescription($i18n->getHtml("[[base-swupdate.BXupdate_help]]") , 'top');
                    $status->toHtml();
                }

                $ScrollList->addEntry(array(
                    $status,
                    $packageName,
                    $version,
                    $vendorName,
                    $description,
                    $factory->getCompositeFormField($composite)
                ));
            }
        }

        // Show the ScrollList for the Updates:
        $xxx = $factory->getRawHTML("availableListNew", $ScrollList->toHtml());
        $block->addFormField($xxx, $factory->getLabel("availableListNew") , $defaultPage);

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
