<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class VsiteList extends BaseController {
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
        if (!$CI->getAllowed('manageSite')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/vsiteList");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- Localization:
        //

        $site_prefix_text = $i18n->get("[[base-vsite.prefix]]");

        //
        //--- Fetch Vsites:
        //

        // Known PHP versions:
        $known_php_versions = array(
                                'PHPOS' => '',
                                'PHP56' => '5.6',
                                'PHP70' => '7.0',
                                'PHP71' => '7.1',
                                'PHP72' => '7.2',
                                'PHP73' => '7.3',
                                'PHP74' => '7.4',
                                'PHP80' => '8.0',
                                'PHP81' => '8.1',
                                'PHP82' => '8.2',
                                'PHP83' => '8.3',
                                'PHP84' => '8.4',
                                'PHP85' => '8.5',
                                'PHP86' => '8.6',
                                'PHP90' => '9.0',
                                'PHP91' => '9.1',
                                'PHP92' => '9.2',
                                'PHP93' => '9.3',
                                'PHP94' => '9.4',
                                );

        // Start with an empty siteList:
        $siteList = array();

        // Determine filter for Vsites depending on user role:
        $exactMatch = array();
        if (!$CI->getAllowed('systemAdministrator')) {
            // If the user is not 'admin', then we only show Vsites that this user owns:
            $exactMatch = array_merge($exactMatch, array('createdUser' => $BX_SESSION['loginName']));  
        }

        // Get all Vsite objects and their namespaces:
        $vsites = $CI->cceClient->getAll("Vsite", $exactMatch);

        // Streamline getting the entire PHP config in one go:
        $all_php_data = $CI->cceClient->getAll("PHP", array());
        $all_php_data = reset($all_php_data);

        // Main PHP Object data:
        $system_php = $all_php_data['OBJECT'];
        unset($all_php_data['OBJECT']);
        $extraPHPs = $all_php_data;

        foreach ($extraPHPs as $php_version => $php_ns_settings) {
            if ($php_version != 'PHPOS') { 
                if (!is_array($extraPHPs[$php_version]) || $extraPHPs[$php_version]['present'] != '1') {
                    unset($extraPHPs[$php_version]);
                }
            }
        }

        // Get all known PHP versions together:
        $all_php_versions = array('PHPOS' => $system_php['PHP_version_os']);
        $all_php_versions_reverse = array($system_php['PHP_version_os'] => 'PHPOS');

        // Auto-detect available features:
        $autoFeatures = new AutoFeatures($CI->cceClient);
        $AutoFeaturesList = $autoFeatures->ListFeatures('modifyWeb.Vsite');
        $AutoFeaturesList_PHP = $autoFeatures->ListFeatures('modifyPHP.Vsite');
        $AutoFeaturesList = array_merge($AutoFeaturesList, $AutoFeaturesList_PHP);

        $Email_plus_helptext = $i18n->get("[[base-email.user_allow_sender_spoof]]");

        $numsite = "0";
        foreach ($vsites as $oid_vsite => $site) {

            // Get Vsite settings:
            if (isset($vsites[$oid_vsite]['OBJECT'])) {
                $vsiteSettings = $vsites[$oid_vsite]['OBJECT'];
            }

            $vsiteSettings['FEATURE'] = array();

            // Check if we have a Vsite prefix active:
            if ((!empty($vsiteSettings['prefix'])) && ($vsiteSettings['userPrefixEnabled'] === '1')) {
                $vsiteSettings['FEATURE']['PREFIX'] = $vsiteSettings['prefix'];
            }

            foreach ($AutoFeaturesList as $key => $FEATURE) {
                if (isset($vsites[$oid_vsite][$FEATURE])) {
                    $featureOID = $vsites[$oid_vsite][$FEATURE];
                }
                else {
                    $featureOID = array();
                }

                if ($FEATURE == "PHP") {
                    if ($featureOID['version'] == '') {
                        $vsiteSettings['PHP_version'] = 'PHPOS';
                    }
                    else {
                        $vsiteSettings['PHP_version'] = $featureOID['version'];
                    }
                    if ($featureOID['mod_ruid_enabled'] == "1") {
                        $vsiteSettings['FEATURE']['RUID'] = $featureOID['mod_ruid_enabled'];
                    }
                    elseif ($featureOID['suPHP_enabled'] == "1") {
                        $vsiteSettings['FEATURE']['suPHP'] = $featureOID['suPHP_enabled'];
                    }
                    elseif ($featureOID['fpm_enabled'] == "1") {
                        $vsiteSettings['FEATURE']['FPM'] = $featureOID['fpm_enabled'];
                    }
                    else {
                        $vsiteSettings['FEATURE']['PHP'] = $featureOID['enabled'];
                    }
                }
                else {
                    $vsiteSettings['FEATURE'][$FEATURE] = $featureOID['enabled'];
                }
            }

            // Manually add the following features as well, although they are not auto-features:
            if ($vsiteSettings['emailDisabled'] == "1") {
                $vsiteSettings['FEATURE']['Email'] = '0';
            }
            else {
                $vsiteSettings['FEATURE']['Email'] = '1';
            }

            // Are Vsite User allowed to spoof email senders?
            if (($vsiteSettings['FEATURE']['Email'] == "1") && ($vsiteSettings['allow_sender_spoof'] == "1")) {
                // This condition is true for:
                // - Vsite with Email enabled
                // - Vsite has allow_sender_spoof set to '1'
                $vsiteSettings['FEATURE']['Email (+)'] = "1";
                unset($vsiteSettings['FEATURE']['Email']);
            }

            // OpenDKIM:
            if (isset($vsites[$oid_vsite]['OpenDKIM']['enabled'])) {
                $vsiteDKIMSettings = $vsites[$oid_vsite]['OpenDKIM']['enabled'];
            }
            else {
                $vsiteDKIMSettings = array('enabled' => '0');
            }

            // DNS:
            $vsiteSettings['FEATURE']['DNS'] = $vsites[$oid_vsite]['OBJECT']['dns_auto'];

            // MYSQL_Vsite:
            if (isset($vsites[$oid_vsite]['MYSQL_Vsite']['enabled'])) {
                $vsiteSettings['FEATURE']['MYSQL_Vsite'] = $vsites[$oid_vsite]['MYSQL_Vsite']['enabled'];
            }
            else {
                $vsiteSettings['FEATURE']['MYSQL_Vsite'] = array('enabled' => '0');
            }

            // subdomains:
            if (isset($vsites[$oid_vsite]['subdomains']['enabled'])) {
                $vsiteSettings['FEATURE']['subdomains'] = $vsites[$oid_vsite]['subdomains']['enabled'];
            }
            else {
                $vsiteSettings['FEATURE']['subdomains'] = array('enabled' => '0');
            }

            // REDIRECT:
            if (isset($vsites[$oid_vsite]['REDIRECT'])) {
                $vsiteRDRSettings = $vsites[$oid_vsite]['REDIRECT'];
            }
            else {
                $vsiteRDRSettings = array('enabled' => '0');
            }
            if ($vsiteRDRSettings['enabled'] > '0') {
                if ($vsiteRDRSettings['type'] == '302') {
                    $vsiteSettings['FEATURE']['REDIRECT'] = '1';
                }
                elseif ($vsiteRDRSettings['type'] == 'permanent') {
                    $vsiteSettings['FEATURE']['REDIRECT'] = '2';
                }
                elseif ($vsiteRDRSettings['type'] == 'proxy') {
                    $vsiteSettings['FEATURE']['REDIRECT'] = '3';
                }
            }

            // SSL:
            if (isset($vsites[$oid_vsite]['SSL'])) {
                $vsiteSettings['FEATURE']['SSL'] = $vsites[$oid_vsite]['SSL']['enabled'];
            }
            else {
                $vsiteSettings['FEATURE']['SSL'] = array('enabled' => '0');
            }

            // Shell:
            $vsiteShellSettings = $vsites[$oid_vsite]['Shell'];
            if ($vsiteShellSettings['enabled'] > '0') {
                $vsiteSettings['FEATURE']['Shell'] = $vsiteShellSettings['enabled'];
            }

            if ($vsiteShellSettings['GoogleAuthentication'] > '0') {
                $vsiteSettings['FEATURE']['2FA'] = $vsiteShellSettings['GoogleAuthentication'];
            }

            // FTPNONADMIN:
            $vsiteFTPNONADMINSettings = $vsites[$oid_vsite]['FTPNONADMIN'];
            if ($vsiteFTPNONADMINSettings == '-1') {
                $vsiteFTPNONADMINSettings = array('enabled' => '0');
            }
            if ($vsiteFTPNONADMINSettings['enabled'] == '1') {
                $vsiteSettings['FEATURE']['FTPNONADMIN'] = $vsiteFTPNONADMINSettings['enabled'];
            }

            // OpenVPN:
            if (isset($vsites[$oid_vsite]['VPN'])) {
                $vsiteVPNSettings = $vsites[$oid_vsite]['VPN'];
            }
            else {
                $vsiteVPNSettings = '-1';
            }

            if ($vsiteVPNSettings == '-1') {
                $vsiteVPNSettings = array('enabled' => '0');
            }
            if ($vsiteVPNSettings['enabled'] == '1') {
                $vsiteSettings['FEATURE']['VPN'] = $vsiteVPNSettings['enabled'];
            }

            $siteList[0][$numsite] = $vsiteSettings['fqdn'];

            if (($vsiteSettings['ipaddr'] != "") && ($vsiteSettings['ipaddrIPv6'] != "")) {
                $siteList[1][$numsite] = $vsiteSettings['ipaddr'] . "<br>" . $vsiteSettings['ipaddrIPv6'];
            }
            elseif (($vsiteSettings['ipaddr'] != "") && ($vsiteSettings['ipaddrIPv6'] == "")) {
                $siteList[1][$numsite] = $vsiteSettings['ipaddr'];
            }
            else {
                $siteList[1][$numsite] = $vsiteSettings['ipaddrIPv6'];
            }

            // Display the Owner of the Vsite:
            if ($vsiteSettings['createdUser'] == "") {
                    $createdUser = "admin";
            }
            else {
                $createdUser = $vsiteSettings['createdUser'];
            } 
            $siteList[2][$numsite] = $createdUser;

            // Suspend icon:
            if ($vsiteSettings['suspend']) {
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
            $siteList[3][$numsite] = $suspended->toHtml();

            $all_selectable_php_versions['PHPOS'] = $system_php['PHP_version_os'];
            foreach ($extraPHPs as $NSkey => $NSvalue) {
                if ($NSvalue['present'] == '1') {
                    $all_php_versions[$NSvalue['NAMESPACE']] = $NSvalue['version'];
                    $all_php_versions_reverse[$NSvalue['version']] = $NSvalue['NAMESPACE'];
                    if ($NSvalue['enabled'] == '1') {
                        $all_selectable_php_versions[$NSvalue['NAMESPACE']] = $NSvalue['version'];
                    }
                }
            }

            // Feature-List Icons:
            $iconlist = array();

            if (isset($vsiteSettings['FEATURE']['USERWEBS'])) {
                unset($vsiteSettings['FEATURE']['USERWEBS']);
            }

            foreach ($vsiteSettings['FEATURE'] as $key => $value) {

                // Expose the used PHP version:
                $php_suffix = "";

                if (isset($vsiteSettings['PHP_version'])) {
                    if (in_array($vsiteSettings['PHP_version'], array_keys($known_php_versions))) {
                        $php_suffix = " " . $known_php_versions[$vsiteSettings['PHP_version']];
                    }
                    else {
                        $php_suffix = $known_php_versions['PHPOS'];
                    }
                }
                else {
                    $php_suffix = $known_php_versions['PHPOS'];
                }

                if ($key == "SSL") { $F_text = "SSL"; $F_tooltip = "SSL"; }
                elseif ($key === "Email (+)") { $F_text = "Email (+)"; $F_tooltip = $Email_plus_helptext;  }
                elseif ($key == "MYSQL_Vsite") { $F_text = "SQL"; $F_tooltip = "MySQL or MariaDB"; }
                elseif ($key == "Java") { $F_text = "JSP"; $F_tooltip = "JSP";  }
                elseif ($key == "USERWEBS") { $F_text = "~"; $F_tooltip = "User owned webs";  }
                elseif ($key == "CGI") { $F_text = "CGI"; $F_tooltip = "CGI";  }
                elseif ($key == "OPENVPN") { $F_text = "VPN"; $F_tooltip = "OpenVPN";  }
                elseif ($key == "LOGS") { $F_text = "Logs"; $F_tooltip = "Web Access Logs enabled";  }
                elseif ($key == "Shell") { 
                    if ($value == '1') { $F_text = "SFTP"; $F_tooltip = "SFTP, SCP & RSYNC"; }
                    elseif ($value == '2') { $F_text = "(#>)"; $F_tooltip = "Chrooted Shell, SFTP, SCP & RSYNC"; }
                    elseif ($value == '3') { $F_text = "#>"; $F_tooltip = "Full Shell Access"; }
                }
                elseif ($key == "2FA") { $F_text = "2FA"; $F_tooltip = "2FA";  }
                elseif ($key == "SSI") { $F_text = "SSI"; $F_tooltip = "SSI";  }
                elseif ($key == "ApacheBandwidth") { $F_text = "Limit"; $F_tooltip = "Bandwidth Limits";  }
                elseif ($key == "PHP") { $F_text = "PHP$php_suffix"; $F_tooltip = "PHP$php_suffix (DSO)"; }
                elseif ($key == "RUID") { $F_text = "PHP$php_suffix+"; $F_tooltip = "PHP$php_suffix (DSO) + mod_ruid2"; }
                elseif ($key == "suPHP") { $F_text = "suPHP$php_suffix"; $F_tooltip = "suPHP$php_suffix"; }
                elseif ($key == "FPM") { $F_text = "PHP-FPM$php_suffix"; $F_tooltip = "PHP$php_suffix via FPM/FastCGI"; }
                elseif ($key == "FTPNONADMIN") { $F_text = "FTP"; $F_tooltip = "FTP"; }
                elseif ($key == "AnonFtp") { $F_text = "anonFTP"; $F_tooltip = "Anonymous FTP"; }
                elseif ($key == "subdomains") { $F_text = "[o|o]"; $F_tooltip = "Domain & Subdomain Assignments allowed"; }
                elseif ($key == "PREFIX") { 
                    $F_text = "ID: $value"; $F_tooltip = $site_prefix_text . ' ' . $value;
                }
                elseif ($key == "REDIRECT") { 
                    if ($value == '1') { $F_text = "-302->"; $F_tooltip = "302 Redirect enabled"; }
                    elseif ($value == '2') { $F_text = "-RPM->"; $F_tooltip = "Permanent Redirect enabled"; }
                    elseif ($value == '3') { $F_text = "-PXY->"; $F_tooltip = "Proxy enabled"; }
                }
                else { $F_text = $key; $F_tooltip = $key; }
                if ($value > "0") {
                    $FeatureIcon = $factory->getFeatureButton('javascript:void(0);', $F_text);
                    $FeatureIcon->MakeTooltip($i18n->getHtml($F_tooltip), 'top');
                    $FeatureIcon->setDescription($i18n->getHtml($F_tooltip));
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
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
            $siteList[4][$numsite] = $wrapped_iconlist;

            //
            //--- Add Buttons for Edit, View and Delete:
            //

            // Edit-Button:
            $editButton = $factory->getModifyButton('/user/userList?group=' . $vsiteSettings['name']);
            $editButton->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $editButton->setButtonSize("xs");
            }
            $editButton->setButtonSpecialStyle('square_animated');
            $editButton->setImageOnly(TRUE);
            $editButton->setTarget('_self');

            // View-Site-Button:
            $fancyButton = $factory->getUrlButton('http://' . $vsiteSettings['fqdn']);
            $fancyButton->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $fancyButton->setButtonSize("xs");
            }
            $fancyButton->setButtonSpecialStyle('square_animated');
            $fancyButton->setImageOnly(TRUE);
            $fancyButton->setTarget('_blank');
            $fancyButton->setDescription($i18n->getHtml("sitePreview"));

            // Delete-Vsite-Button:
            $deleteButton = $factory->getModifyButton('/vsite/vsiteDel?group=' . $vsiteSettings['name']);
            $deleteButton->setButtonSize("small");
            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $deleteButton->setButtonSize("xs");
            }
            $deleteButton->setButtonSpecialStyle('square_animated');
            $deleteButton->setIcon('fa fa-trash-o');
            $deleteButton->setButtonColor('danger');
            $deleteButton->setImageOnly(TRUE);
            $deleteButton->setTarget('_self');
            $deleteButton->setDescription($i18n->getHtml("siteRemove"));
            $deleteButton->addButtonClass('dialog_button');
            $deleteButton->setModal('dialog', '/vsite/vsiteDel?group=' . $vsiteSettings['name']);

            // Add ButtonContainer with the buttons:
            $buttonContainer = $factory->getButtonContainer("", array($editButton, $fancyButton, $deleteButton));
            $buttonContainer->setMargin('pull-right');

            // Out with the ButtonContainer:
            $siteList[5][$numsite] = $buttonContainer->toHtml();
            $numsite++;
        }

        //
        //--- Set Extra-Headers:
        //

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

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteList1');
        $page_module = 'base_sitemanageVSL';
        $defaultPage = 'pageID';
        $block = $factory->getPagedBlock("virtualSiteList", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $scrollList = $factory->getScrollList("virtualSiteList", array("fqdn", "comboIpAddr", "createdUser", "listSuspended", "Features", " "), $siteList); 
        $scrollList->setAlignments(array("left", "right", "center", "center", "center", "center"));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('ascending');
        $scrollList->setSortDisabled(array('5'));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);

        $scrollList->setColumnWidths(array("25%", "10%", "10%", "10%", "35%", "10%")); // Max: 739px

        // Print administrative information for resellers:
        if (!$CI->getAllowed('systemAdministrator')) {
            $vsite_disk = 0;  
            $vsite_user = 0;
            $num_vsites = 0;

            foreach($vsites as $vsites_oid) {  
                if ((isset($vsites_oid['OBJECT'])) && (isset($vsites_oid['Disk']))) {
                    $vsite = $vsites_oid['OBJECT'];
                    $vsite2 = $vsites_oid['Disk'];
                    $vsite_user += $vsite['maxusers'];  
                    $vsite_disk += $vsite2['quota'];
                    $num_vsites++;
                }
            }  


            $sites = $CI->cceClient->get($BX_SESSION['loginUser']['OID'], 'Sites');
            $sites['quota'] = simplify_number($sites['quota']*1000, "K", "1") . "B";

            $text_userSitesMax = $i18n->getClean("userSitesMax");
            $text_userSitesMax_help = $i18n->getWrapped("userSitesMax_help");
            $text_sites_max = $sites['max'];
            $text_num_vsites = $num_vsites;

            $text_userSitesUser = $i18n->getClean("userSitesUser");
            $text_userSitesUser_help = $i18n->getWrapped("userSitesUser_help");
            $text_sites_user = $sites['user'];
            $text_vsite_user = $vsite_user;

            $text_userSitesQuota = $i18n->getClean("userSitesQuota");
            $text_userSitesQuota_help = $i18n->getWrapped("userSitesQuota_help");
            $text_sites_quota = $sites['quota'];
            $text_max_quota = simplify_number($vsite_disk*1000*1000, "K", "1");

            // Build rows. Use raw HTML only where the old table used <dt class="mb-10"> for the reseller total allowances:
            $rows = array(
              array(
                  array('value' => '<dt class="mb-10">' . htmlspecialchars((string) $text_sites_max, ENT_QUOTES, 'UTF-8') . '</dt>', 'escape' => false),
                  array('value' => '<dt class="mb-10">' . htmlspecialchars((string) $text_sites_user, ENT_QUOTES, 'UTF-8') . '</dt>', 'escape' => false),
                  array('value' => '<dt class="mb-10">' . htmlspecialchars((string) $text_sites_quota, ENT_QUOTES, 'UTF-8') . '</dt>', 'escape' => false)
              ),
              array(
                  (string) $text_num_vsites,
                  (string) $text_vsite_user,
                  (string) $text_max_quota
              )
            );

            $resellerTable = $factory->getTable('ResellerStatsTable', array(), $rows);
            $resellerTable->setHeaders(array(
                $resellerTable->makeHeaderLabelCell($text_userSitesMax, $text_userSitesMax_help, 'text_userSitesMax'),
                $resellerTable->makeHeaderLabelCell($text_userSitesUser, $text_userSitesUser_help, 'text_userSitesUser'),
                $resellerTable->makeHeaderLabelCell($text_userSitesQuota, $text_userSitesQuota_help, 'text_userSitesQuota')
            ));
            $resellerTable->setResponsive(true);
            $resellerTable->setStriped(false);
            $resellerTable->setHover(true);
            $resellerTable->setBordered(true);
            $resellerTable->setCompact(false);
            $resellerTable->addTableClass('mb-20');

            $ResellerTableFF = $factory->getRawHTML("ResellerStats", $resellerTable->toHtml());
            $block->addFormField(
              $ResellerTableFF,
              $factory->getLabel("ResellerStats"),
              $defaultPage
            );
        }

        // Check vsite max for administrator 
        $sites = $CI->cceClient->get($BX_SESSION['loginUser']['OID'], 'Sites');
        $user_sites = $CI->cceClient->find('Vsite', array('createdUser' => $BX_SESSION['loginName']));
        // Show "Add"-button if this Vsite hasn't yet reached max number of accounts:
        if ((($sites['max'] > 0) && (count($user_sites) < $sites['max'])) || ($CI->getAllowed('systemAdministrator'))) {
            // Generate +Add button:
            $addAdminUser = "/vsite/vsiteAdd";
            $addbutton = $factory->getAddButton($addAdminUser, '[[base-vsite.siteaddbut_help]]', "DEMO-OVERRIDE");
            $buttonContainer = $factory->getButtonContainer("virtualSiteList", $addbutton);
            $block->addFormField(
                $buttonContainer,
                $factory->getLabel("virtualSiteList"),
                $defaultPage
            );
        }

        // Push out the Scrollist:
        $xxx = $factory->getRawHTML("virtualSiteList", $scrollList->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("virtualSiteList"),
            $defaultPage
        );

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
        $page_body[] = $block->toHtml();

        // Add hidden Modal for Delete-Confirmation for Elmer:
        $modal_title = $i18n->getHtml("[[base-vsite.siteRemoveConfirmNeutral]]");
        $modal_body = $i18n->getHtml("[[base-vsite.removeConfirmInfo]]");
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

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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