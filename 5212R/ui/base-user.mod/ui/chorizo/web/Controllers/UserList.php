<?php 
namespace User\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;


class UserList extends BaseController {
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
        $user = $BX_SESSION['loginUser'];

        // Most basic ACL:
        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/userList");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-user", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        // Get URL params:
        $get_form_data = $this->request->getGet();

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a group URL string:
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
        //-- Get Vsite data
        //
        if ($group) {
            // Determine filter for Vsites depending on user role:
            $exact = array();
            if (!$CI->getAllowed('systemAdministrator')) {
                // If the user is not 'admin', then we only show Vsites that this user owns:
                $exact = array_merge($exact, array('createdUser' => $BX_SESSION['loginName']));  
            }

            // Get the Vsite object and all its namespaces:
            $vsite = $CI->cceClient->getAll("Vsite", array("name" => $group));
            $vsite_oid = array_key_first($vsite);

            // Do we have Vsites? If not, then we shouldn't be here!
            if (count($vsite) == "0") {
                // Don't play games with us!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#3");
            }

            // Get Vsite UserDefaults:
            if (isset($vsite[$vsite_oid]['UserDefaults'])) {
                $userDefaults = $vsite[$vsite_oid]['UserDefaults'];
            }
            else {
                // Fallback to System UserDefaults:
                $userDefaults = $CI->cceClient->getObject("System", array(), "UserDefaults");
            }

            // Get UserServices and UserDefaults:
            $userServices_full =  $CI->cceClient->getAll("UserServices", array("site" => $group));
            $userServices = reset($userServices_full);
            $userDefaults = $CI->cceClient->getObject("System", array(), "UserDefaults");
        }
        else {
            $userDefaults = $CI->cceClient->getObject("System", array(), "UserDefaults");
        }

        // Streamlined getAll() for Vsite Object and all its NameSpaces:
        $all_vsite_data = $CI->cceClient->getAll("Vsite", array('name' => $group));
        $all_vsite_data = reset($all_vsite_data);
        $vsite_php = $all_vsite_data['PHP'];

        // Get the name of the siteAdmin who owns /web:
        $current_prefered_siteAdmin = $vsite_php['prefered_siteAdmin'];

        // Get the PHP settings for this Vsite:
        $vsite_php = $all_vsite_data['PHP'];

        // Second stage of capability check. More thorough here:
        // Only adminUser and siteAdmin should be here
        if ((!$CI->serverScriptHelper->getAllowed('adminUser')) && 
            (!$CI->serverScriptHelper->getAllowed('siteAdmin')) && 
            (!$CI->serverScriptHelper->getAllowed('manageSite')) && 
            (($user['site'] != $CI->serverScriptHelper->loginUser['site']) && $CI->serverScriptHelper->getAllowed('siteAdmin')) &&
            (($vsiteObj['createdUser'] != $CI->BX_SESSION['loginName']) && $CI->serverScriptHelper->getAllowed('manageSite'))
            ) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#4");
        }
        else {

            // Find out if non-siteAdmin's can FTP or not:
            $FTPNONADMIN = $vsite[$vsite_oid]['FTPNONADMIN'];

            // Start with an empty userList:
            $userList = array();

            if ($group) {
                $exactMatch = array("site" => $group);
            }
            else {
                $exactMatch = array();
            }

            // Use the new getAll() to fetch all Users AND their NameSpaces in one go:
            $UserData = $CI->cceClient->getAll("User", $exactMatch);
            $User_OIDs = array_keys($UserData);

            // Auto-detect available features:
            //$autoFeatures = new AutoFeatures($CI->serverScriptHelper);

            //--- Start: Externalization of CCE-Call:

            // How many User objects are there?
            $totalNumUsers = count($User_OIDs);

            //--- End: Externalization of CCE-Call

            $Email_plus_helptext = $i18n->get("[[base-email.user_allow_sender_spoof]]");

            $numUsers = "0";
            foreach ($User_OIDs as $key => $user) {

                // Full name:
                $raw_userName = stripcslashes($UserData[$user]['OBJECT']['fullName']);
                $userName = htmlspecialchars(bx_charsetsafe($raw_userName), ENT_QUOTES, 'UTF-8');
                $userList[0][$numUsers] = $userName;

                // Username:
                $userList[1][$numUsers] = $UserData[$user]['OBJECT']['name'];

                // Email Aliases:
                $userList[2][$numUsers] = implode(', ', stringToArray($UserData[$user]['Email']['aliases']));

                // Suspend icon:
                if ($UserData[$user]['OBJECT']['enabled'] == "0") {
                    $suspended = $factory->getButton('javascript:void(0);', $i18n->getHtml("[[palette.Yes]]"));
                    $suspended->MakeTooltip($i18n->getHtml("[[palette.Yes]]"), 'top');
                    $suspended->setTextOnly(TRUE);
                    $suspended->setButtonSize('xs');
                    $suspended->setButtonColor('danger');
                }
                else {
                    $suspended = $factory->getButton('javascript:void(0);', $i18n->getHtml("[[palette.No]]"));
                    $suspended->MakeTooltip($i18n->getHtml("[[palette.Yes]]"), 'top');
                    $suspended->setTextOnly(TRUE);
                    $suspended->setButtonSize('xs');
                    $suspended->setButtonColor('default');
                }
                $userList[3][$numUsers] = $suspended->toHtml();

                //
                //-- Feature List:
                //
                //  We could use AutoFeatures here. But in reality the items we are 
                //  interested in are not all realized via AutoFeatures. So let us
                //  break it down to what we need:
                //
                //  - siteAdmin                             via capLevels
                //  - DNS Administrator                     via capLevels
                //  - Shell access                          via $oid . Shell "enabled" = 0/1
                //  - FTP access                            via Vsite $oid "FTPNONADMIN" = 0/1 and Capabilities
                //  - Email (enabled/disabled)              via $oid "emailDisabled" = 0/1
                //  - Vacation Message (enabled/disabled)   via $oid . Email "vacationOn" = 0/1
                //  - Subdomain (enabled/disabled)          via $oid . subdomains "enabled" = 0/1

                // Is User a siteAdmin?
                $UserData[$user]['FEATURE']['siteAdmin'] = "0";
                if (in_array('siteAdmin', scalar_to_array($UserData[$user]['OBJECT']['capLevels']))) {
                    $UserData[$user]['FEATURE']['siteAdmin'] = "1";
                }

                // Is User a dnsAdmin?
                $UserData[$user]['FEATURE']['siteDNS'] = "0";
                if (in_array('siteDNS', scalar_to_array($UserData[$user]['OBJECT']['capLevels']))) {
                    $UserData[$user]['FEATURE']['siteDNS'] = "1";
                }

                // Does User have Shell access?
                $UserData[$user]['FEATURE']['siteShell'] = "0";
                $UserData[$user]['FEATURE']['SFTP'] = "0";
                if ($UserData[$user]['Shell']['enabled'] == "1") {
                    $UserData[$user]['FEATURE']['SFTP'] = "1";
                }
                elseif ($UserData[$user]['Shell']['enabled'] == "2") {
                    $UserData[$user]['FEATURE']['ChrootShell'] = "1";
                    $UserData[$user]['FEATURE']['SFTP'] = "1";
                }
                elseif ($UserData[$user]['Shell']['enabled'] == "3") {
                    $UserData[$user]['FEATURE']['siteShell'] = "1";
                }

                // GUI 2FA badge state:
                $twoFactorBadge = $this->getTwoFactorBadgeState($UserData[$user], $all_vsite_data, $System);
                if ($twoFactorBadge !== null) {
                    $UserData[$user]['FEATURE']['2FA'] = $twoFactorBadge;
                }

                // Can User use FTP?
                if (in_array('siteAdmin', scalar_to_array($UserData[$user]['OBJECT']['capLevels']))) {
                    // siteAdmin's can always use FTP:
                    $UserData[$user]['FEATURE']['FTP'] = "1";
                }
                elseif (($FTPNONADMIN['enabled'] == "1") && (!in_array('siteAdmin', scalar_to_array($UserData[$user]['OBJECT']['capLevels'])))) {
                    // Not siteAdmin, but FTPNONADMIN is enabled:
                    $UserData[$user]['FEATURE']['FTP'] = "1";
                }
                else {
                    // Tough luck. FTPNONADMIN is off and we're just a grunt:
                    $UserData[$user]['FEATURE']['FTP'] = "0";
                }

                // Does User have Email enabled?
                if ($UserData[$user]['OBJECT']['emailDisabled'] == "1") {
                    $UserData[$user]['FEATURE']['Email'] = "0";
                }
                else {
                    $UserData[$user]['FEATURE']['Email'] = "1";
                }

                // Is User allowed to spoof email senders?
                if ((($UserData[$user]['OBJECT']['emailDisabled'] == "0") && ($UserData[$user]['Email']['allow_sender_spoof'] == "1")) || ($UserData[$user]['OBJECT']['name'] == $current_prefered_siteAdmin)) {
                    // This condition is true for:
                    // - Users with Email . allow_sender_spoof set to '1'
                    // - siteAdmin who owns /web:
                    $UserData[$user]['FEATURE']['Email (+)'] = "1";
                    unset($UserData[$user]['FEATURE']['Email']);
                }

                // Is Email disabled for the Vsite?
                if ($all_vsite_data['OBJECT']['emailDisabled'] == '1') {
                    if (isset($UserData[$user]['FEATURE']['Email'])) {
                        unset($UserData[$user]['FEATURE']['Email']);
                    }
                    if (isset($UserData[$user]['FEATURE']['Email (+)'])) {
                        unset($UserData[$user]['FEATURE']['Email (+)']);
                    }
                }

                // Does user have Vacation Message enabled?
                $UserData[$user]['FEATURE']['Vacation'] = "0";
                if ($UserData[$user]['Email']['vacationOn'] == "1") {
                    $UserData[$user]['FEATURE']['Vacation'] = "1";
                }

                // Does User have Subdomain enabled?
                if ($UserData[$user]['subdomains']['enabled'] == "1") {
                    $UserData[$user]['FEATURE']['Subdomain'] = "1";
                }
                else {
                    $UserData[$user]['FEATURE']['Subdomain'] = "0";
                }

                // Does User have OpenVPN enabled?
                if (isset($UserData[$user]['OpenVPN']['enabled'])) {
                    if ($UserData[$user]['OpenVPN']['enabled'] == "1") {
                        $UserData[$user]['FEATURE']['OpenVPN'] = "1";
                    }
                }
                else {
                    $UserData[$user]['FEATURE']['OpenVPN'] = "0";
                }

                // Feature-List Icons:
                $iconlist = array();

                foreach ($UserData[$user]['FEATURE'] as $key => $value) {
                    if ($key === "siteAdmin") { $F_text = '&lt;A&gt;'; $F_tooltip = "siteAdmin"; }
                    elseif ($key === "siteDNS") { $F_text = "DNS"; $F_tooltip = "siteDNS";  }
                    elseif ($key === "ChrootShell") { $F_text = "(#>)"; $F_tooltip = "Chrooted Shell, SFTP, SCP & RSYNC";  }
                    elseif ($key === "siteShell") { $F_text = "#>"; $F_tooltip = "Full Shell Access";  }
                    elseif ($key === "2FA") {
                        $F_text = $this->getTwoFactorBadgeLabel($value);
                        $F_tooltip = $this->getTwoFactorBadgeTooltip($value, $i18n);
                    }
                    elseif ($key === "FTP") { $F_text = "FTP"; $F_tooltip = "FTP";  }
                    elseif ($key === "SFTP") { $F_text = "SFTP"; $F_tooltip = "SFTP, SCP & RSYNC";  }
                    elseif ($key === "Email") { $F_text = "Email"; $F_tooltip = "Email";  }
                    elseif ($key === "Email (+)") { $F_text = "Email (+)"; $F_tooltip = $Email_plus_helptext;  }
                    elseif ($key === "Vacation") { $F_text = "Vacation"; $F_tooltip = "Vacation";  }
                    elseif ($key === "Subdomain") { $F_text = "Subdomain"; $F_tooltip = "Subdomain"; }
                    else { $F_text = $key; $F_tooltip = $key; }
                    if (($value === "1") || ($key === "2FA" && !empty($value))) {
                        $FeatureIcon = $factory->getFeatureButton('javascript:void(0);', $F_text);
                        $FeatureIcon->MakeTooltip($i18n->getHtml($F_tooltip), 'top');
                        $FeatureIcon->setDescription($i18n->getHtml($F_tooltip));
                        if ($key === "2FA" && !empty($value)) {
                            $FeatureIcon->setButtonColor($this->getTwoFactorBadgeColor($value));
                        }
                        elseif ($BX_SESSION['gui_theme'] === 'adminica') {
                            $FeatureIcon->setButtonColor('primary');
                        }
                        $iconlist[] = $FeatureIcon->toHtml();
                    }
                }

                $totalicons = count($iconlist);
                $numicons = '0';
                $wrapped_iconlist = '<div class="btn-group">';
                $wrapped_iconlist .= implode('', $iconlist);
                //foreach ($iconlist as $key => $value) {
                //    $wrapped_iconlist .= $value;
                //    $numicons++;
                //    if ($numicons == '4') {
                //        $wrapped_iconlist .= "<br>";
                //        $numicons = '0';
                //    }
                //}
                $wrapped_iconlist .= '</div>';
                $userList[4][$numUsers] = $wrapped_iconlist;

                //
                //-- Add Buttons for Edit, View and Delete:
                //

                // Edit-Button:
                $editButton = $factory->getModifyButton('/user/userMod?group=' . $UserData[$user]['OBJECT']['site'] . '&name=' . $UserData[$user]['OBJECT']['name']);
                $editButton->setButtonSize("small");
                if ($BX_SESSION['gui_theme'] === 'adminica') {
                    $editButton->setButtonSize("xs");
                }
                $editButton->setButtonSpecialStyle('square_animated');
                $editButton->setImageOnly(TRUE);
                $editButton->setTarget('_self');

                // Only add 'Delete' button for all users but our current user:
                if ($UserData[$user]['OBJECT']['name'] != $CI->BX_SESSION['loginName']) {
                    $deleteButton = $factory->getModifyButton('/user/userDel?group=' . $group . '&name=' . $UserData[$user]['OBJECT']['name']);
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
                    $deleteButton->setModal('dialog', '/user/userDel?group=' . $group . '&name=' . $UserData[$user]['OBJECT']['name']);
                }

                // Add ButtonContainer with the buttons:
                if ($UserData[$user]['OBJECT']['name'] != $CI->BX_SESSION['loginName']) {
                    $buttonContainer = $factory->getButtonContainer("", array($editButton, $deleteButton));
                }
                else {
                    $buttonContainer = $factory->getButtonContainer("", array($editButton));
                }
                $buttonContainer->setMargin('pull-right');

                // Out with the ButtonContainer:
                $userList[5][$numUsers] = $buttonContainer->toHtml();
                $numUsers++;
            }
        }

        //-- Generate page:

        // Prepare Page:
        $BxPage->setExtraHeaders('
                <script>
                    $(document).ready(function() {
                        $(".various").fancybox({
                            overlayColor: "#000",
                            fitToView   : false,
                            width       : "80%",
                            height      : "80%",
                            autoSize    : false,
                            closeClick  : false,
                            openEffect  : "none",
                            closeEffect : "none"
                        });
                    });
                </script>');

        if ($BX_SESSION['gui_theme'] === 'adminica') {
            // Extra header for the "do you really want to delete" dialog for Adminica:

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
        }
        else {
            // Set extra-footers for do you really want to delete" dialog for Elmer:
            $BxPage->setExtraFooters('
                <script>
                    // Activate the tooltip
                    $(\'[data-toggle="tooltip"]\').tooltip();

                    // Add a click event to open the modal
                    $(\'.dialog_button\').click(function () {
                        event.preventDefault(); // Prevent the default action
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

        // Prepare Page:
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteadmin');
        $BxPage->setVerticalMenuChild('base_userList');
        $page_module = 'base_sitemanage';
        $defaultPage = 'pageID';

        $block = $factory->getPagedBlock("userList", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        $scrollList = $factory->getScrollList("userList", array("fullName", "userName", "emailAliases", "userSuspended", "rights", "listAction"), $userList); 
        $scrollList->setAlignments(array("left", "left", "left", "center", "center", "center"));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('ascending');
        $scrollList->setSortDisabled(array('5'));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);

        if ($BX_SESSION['gui_theme'] === 'elmer') {
            $scrollList->setColumnWidths(array("15%", "10%", "10%", "10%", "45%", "10%")); // Max: 739px
        }
        else {
            $scrollList->setColumnWidths(array("200", "120", "180", "4", "200", "35")); // Max: 739px
        }

        // Get VSite object if we don't have it already:
        if (!isset($vsiteObj)) {
            list($vsite) = $CI->cceClient->find("Vsite", array("name" => $group));
            $vsiteObj = $CI->cceClient->get($vsite);
        }

        // Show "Add"-button if this Vsite hasn't yet reached max number of accounts:
        $buttonContainerButtons = array();
        if ($totalNumUsers < $vsiteObj['maxusers']) {
            // Generate +Add button:
            $addAdminUser = "/user/userAdd?group=$group";
            $buttonContainerButtons[] = $factory->getAddButton($addAdminUser, '[[base-user.add_user_help]]', "DEMO-OVERRIDE");
        }

        // Show 'Edit User Template' Button:
        $userTemplateURL = "/user/userDefaults?group=$group";
        $buttonContainerButtons[] = $factory->getButton($userTemplateURL, '[[base-user.userDefaults]]', "DEMO-OVERRIDE");
        $buttonContainer = $factory->getButtonContainer("", $buttonContainerButtons);

        // Out with the Button-Container:
        $block->addFormField(
            $buttonContainer,
            $factory->getLabel("userList"),
            $defaultPage
        );

        // Push out the Scrollist:
        $xff = $factory->getRawHTML("userList", $scrollList->toHtml());
        $block->addFormField(
            $xff,
            $factory->getLabel("userList"),
            $defaultPage
        );

        $page_body[] = $block->toHtml();

        if ($BX_SESSION['gui_theme'] == 'adminica') {
            // Add hidden Modal for Delete-Confirmation for Adminica:
            $page_body[] = '
                <div class="display_none">
                            <div id="modalDeleteButton" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-user.removeConfirmQuestion]]") . '">
                                <div class="block">
                                        <div class="section">
                                                <h1>' . $i18n->getHtml("[[base-user.removeConfirmQuestion]]") . '</h1>
                                                <div class="dashed_line"></div>
                                                <p>' . $i18n->getHtml("[[base-user.userRemoveConfirmInfo]]") . '</p>
                                        </div>
                                </div>
                            </div>
                </div>';
        }
        else {
            // Add hidden Modal for Delete-Confirmation for Elmer:
            $modal_title = $i18n->getHtml("[[base-user.removeConfirmQuestion]]");
            $modal_body = $i18n->getHtml("[[base-user.userRemoveConfirmInfo]]");
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

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }

    private function getTwoFactorBadgeState($userData, $vsiteData, $system) {
        $tfa = $userData['TwoFactorAuth'] ?? array();
        $hasModern = ($tfa && (($tfa['enabled'] ?? '0') == '1') && !empty($tfa['secret_encrypted']));
        $hasLegacy = (!$hasModern && (($userData['SSH']['GoogleAuthentication'] ?? '0') == '1'));

        if ($hasModern) {
            return 'configured';
        }

        if ($hasLegacy) {
            return 'legacy';
        }

        if ($this->isGuiTwoFactorRequiredForUserList($userData['OBJECT'] ?? array(), $vsiteData, $system)) {
            return 'required_setup';
        }

        return null;
    }

    private function getTwoFactorBadgeLabel($state) {
        if ($state === 'legacy') {
            return '2FA*';
        }

        return '2FA';
    }

    private function getTwoFactorBadgeColor($state) {
        if ($state === 'legacy') {
            return 'warning';
        }
        if ($state === 'required_setup') {
            return 'danger';
        }

        return 'default';
    }

    private function getTwoFactorBadgeTooltip($state, $i18n) {
        if ($state === 'configured') {
            return $i18n->get('[[base-user.twofactor_status_enabled]]');
        }
        if ($state === 'legacy') {
            return $i18n->get('[[base-user.twofactor_status_legacy_pending]]');
        }
        if ($state === 'required_setup') {
            return $i18n->get('[[base-user.twofactor_status_required_setup]]');
        }
        return '2FA';
    }

    private function isGuiTwoFactorRequiredForUserList($userObject, $vsiteData, $system) {
        if (($vsiteData['Shell']['GoogleAuthentication'] ?? '0') === '1') {
            return true;
        }

        if (($system['gui_2fa'] ?? '0') !== '1') {
            return false;
        }

        $policy = $system['gui_2fa_users'] ?? 'ALL';
        if ($policy === 'ALL') {
            return true;
        }

        $capLevels = isset($userObject['capLevels']) ? array_filter(explode('&', $userObject['capLevels'])) : array();
        if (($policy === 'ADMINS') && (($userObject['name'] ?? '') === 'admin' || in_array('adminUser', $capLevels))) {
            return true;
        }
        if (($policy === 'PRIVILEGED') && (($userObject['name'] ?? '') === 'admin' || in_array('adminUser', $capLevels) || in_array('siteAdmin', $capLevels))) {
            return true;
        }

        return false;
    }
}
/*
Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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
