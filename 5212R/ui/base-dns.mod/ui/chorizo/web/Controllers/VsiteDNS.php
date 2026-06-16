<?php 
namespace Dns\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class VsiteDNS extends BaseController {
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

    private function DNSExplainer($CI = null, $i18n = null, $System = null, $vsite = null) {

        //
        //--- DNS Explainer for 'email_autoconfig':
        //

        // ---- Compute zone (domain) the same way as in submit path ----
        $baseDomain = strtolower(trim((string)($vsite['domain'] ?? ''), ". \t\n\r\0\x0B")); // smd.net
        $fqdn       = strtolower(trim((string)($vsite['fqdn']   ?? ''), ". \t\n\r\0\x0B")); // banana.smd.net
        $hn         = strtolower(trim((string)($vsite['hostname'] ?? '')));

        // Zone = base domain OR vsite FQDN (for non-www/non-mail sites)
        $zone = $baseDomain;
        if ($hn !== 'www' && $hn !== 'mail' && $fqdn !== '') {
            $zone = $fqdn;   // banana.smd.net
        }
        $zone = strtolower(trim((string)$zone, ". \t\n\r\0\x0B"));

        // ---- Pick target host for CNAME+SRV ----
        // Prefer a mailAlias starting with "mail." (e.g. mail.kinofreak.com)
        // Else fallback to vsite FQDN (banana.smd.net w/o mailAliases)
        $mailTarget = $fqdn ?: $baseDomain;

        $mailAliases = $CI->cceClient->scalar_to_array($vsite['mailAliases'] ?? '');
        foreach ($mailAliases as $a) {
            $a = strtolower(trim((string)$a, ". \t\n\r\0\x0B"));
            if ($a !== '' && preg_match('/^mail\./', $a)) {
                $mailTarget = $a;
                break;
            }
        }

        $port = 443;

        // Fetch System DNS settings (contains auto_mx, auto_a, etc.)
        $System_DNS = $CI->cceClient->get($System['OID'], "DNS");

        // Normalize helper
        $norm = function($s) {
            $s = strtolower(trim((string)$s, ". \t\n\r\0\x0B"));
            return $s;
        };

        // Pick the “zone” we’re talking about (same rule you use elsewhere)
        $baseDomain = $norm($vsite['domain'] ?? '');
        $fqdn       = $norm($vsite['fqdn'] ?? '');
        $hn         = $norm($vsite['hostname'] ?? '');
        $zone       = $baseDomain;
        if ($hn !== 'www' && $hn !== 'mail' && $fqdn !== '') {
            $zone = $fqdn; // banana.smd.net style
        }

        // Determine mail target:
        // - if any mailAlias starts with mail. use that
        // - else default to vsite fqdn (or baseDomain)
        $mailAliases = $CI->cceClient->scalar_to_array($vsite['mailAliases'] ?? '');
        $mailTarget  = $fqdn ?: $baseDomain;
        foreach ($mailAliases as $a) {
            $a = $norm($a);
            if ($a && preg_match('/^mail\./', $a)) {
                $mailTarget = $a;
                break;
            }
        }

        $normMailTarget = $norm($mailTarget);

        // TTL and port
        $ttl  = 3600;
        $port = 443;

        // ---------- Localization: ----------

        if ((isset($vsite['email_autoconfig'])) && ($vsite['email_autoconfig'] == 1)) {
            $autodisco_recs    = $i18n->getClean("[[base-vsite.email_autoconfig_dns_short_explainer_Text]]");
            $autodisco_regMx   = $i18n->getClean("[[base-vsite.email_autoconfig_dns_Header_Required_MX_Target]]");
            $autodisco_regRecs = $i18n->getClean("[[base-vsite.email_autoconfig_dns_Header_Regular_Vsite_DNS]]");
            $autodisco_regMX   = $i18n->getClean("[[base-vsite.email_autoconfig_dns_MX_Records]]");
            $intro = $i18n->getClean("[[base-vsite.email_autoconfig_dns_explainer_Text]]");
            $intro_dns = $i18n->getClean("[[base-vsite.email_autoconfig_dns_stupid_explainer_Text]]");
            $intro_short = $i18n->getClean("[[base-vsite.email_autoconfig_dns_short_explainer_Text]]");
        }
        else {
            $autodisco_recs    = $i18n->getClean("[[base-vsite.email_autoconfig_dns_short_explainer_Text]]");
            $autodisco_regMx   = $i18n->getClean("[[base-vsite.email_autoconfig_dns_Header_Required_MX_Target]]");
            $autodisco_regRecs = $i18n->getClean("[[base-vsite.email_autoconfig_dns_Header_Regular_Vsite_DNS]]");
            $autodisco_regMX   = $i18n->getClean("[[base-vsite.email_autoconfig_dns_MX_Records]]");
            $intro = $i18n->getClean("[[base-vsite.dns_recommend_bind_format]]");
        }

        // ---------- Build record sets ----------

        if ($vsite["email_autoconfig"] == '1') {
            // 1) Autoconfig/Autodiscover:
            $dnsLines = [];
            $dnsLines[] = "; --- $autodisco_recs ---";
            $dnsLines[] = "autoconfig.$zone.\t\t$ttl IN CNAME\t$mailTarget.";
            $dnsLines[] = "autodiscover.$zone.\t\t$ttl IN CNAME\t$mailTarget.";
            $dnsLines[] = "_autodiscover._tcp.$zone.\t$ttl IN SRV\t0 0 $port $mailTarget.";
        }

        // 2) Mail target MUST exist as A (otherwise CNAME/SRV are useless):
        $dnsLines[] = "";
        $dnsLines[] = "; --- $autodisco_regMx ---";
        $dnsLines[] = "$mailTarget.\t\t$ttl IN A\t" . ($vsite['ipaddr'] ?? 'X.X.X.X');

        // 3) Regular vsite A records (hostname + webAliases + auto_a + optional apex):
        $dnsLines[] = "";
        $dnsLines[] = "; --- $autodisco_regRecs ---";

        // Return true if host/FQDN is reserved for autoconfig/autodiscover
        $isReservedAuto = function(string $name, string $baseDomain) {
            $n = strtolower(trim($name, ". \t\n\r\0\x0B"));
            if ($n === '') return false;

            // If passed as FQDN in our domain, reduce to host part
            $bd = strtolower(trim($baseDomain, ". \t\n\r\0\x0B"));
            if ($bd !== '' && preg_match('/\.' . preg_quote($bd, '/') . '$/i', $n)) {
                $n = preg_replace('/\.' . preg_quote($bd, '/') . '$/i', '', $n);
            }

            // Block exact and dotted variants
            return (bool)preg_match('/^(autoconfig|autodiscover)(\.|$)/i', $n)
                || (bool)preg_match('/^_autodiscover\._tcp(\.|$)/i', $n);
        };

        $wantHosts = [];

        // Main vsite hostname
        if ($hn !== '') {
            $f = "$hn.$baseDomain";
            if (!$isReservedAuto($f, $baseDomain)) $wantHosts[] = $f;
        }

        // Web aliases
        $webAliases = $CI->cceClient->scalar_to_array($vsite['webAliases'] ?? '');
        foreach ($webAliases as $a) {
            $a = $norm($a);
            if ($a && !$isReservedAuto($a, $baseDomain)) $wantHosts[] = $a;
        }

        // auto_a entries (hostnames)
        $autoA = $CI->cceClient->scalar_to_array($System_DNS['auto_a'] ?? '');
        foreach ($autoA as $h) {
            $h = $norm($h);
            if ($h === '') continue;
            $f = "$h.$baseDomain";
            if (!$isReservedAuto($f, $baseDomain)) $wantHosts[] = $f;
        }

        // Optional apex A
        if ($baseDomain !== '' && !$isReservedAuto($baseDomain, $baseDomain)) {
            $wantHosts[] = $baseDomain;
        }

        // De-dup case-insensitive
        $uniq = [];
        foreach ($wantHosts as $f) {
            $f = $norm($f);
            if (!$f) continue;

            // Don't repeat the REQUIRED mail target A record here:
            if ($f === $normMailTarget) continue;

            $uniq[$f] = true;
        }

        foreach (array_keys($uniq) as $f) {
            $dnsLines[] = "$f.\t\t$ttl IN A\t" . ($vsite['ipaddr'] ?? 'X.X.X.X');
        }

        // 4) MX record(s): show apex MX (and optionally others)
        $dnsLines[] = "";
        $dnsLines[] = "; --- $autodisco_regMX ---";

        // If your logic is “auto_mx.<domain> is the MX host”, mirror it:
        $mxHost = $System_DNS['auto_mx'] ? $norm($System_DNS['auto_mx']) . "." . $baseDomain : $mailTarget;

        // Ensure the MX host is also resolvable (A record) — again, anti-footgun
        $dnsLines[] = "$baseDomain.\t\t$ttl IN MX\t10 $mxHost.";

        // Only add MX-host A if it's not the same as the REQUIRED mail target:
        if ($norm($mxHost) !== $normMailTarget) {
            $dnsLines[] = "$mxHost.\t\t$ttl IN A\t" . ($vsite['ipaddr'] ?? 'X.X.X.X');
        }

        // Render as one block
        $dnsBlock = implode("\n", $dnsLines) . "\n";

        if ((isset($vsite['email_autoconfig'])) && ($vsite['email_autoconfig'] == 1)) {
            $my_TEXT = $intro
                     . "<br><br><b>$intro_dns</b><br><br><b>$intro_short</b>"
                     . "<pre style=\"white-space:pre; margin:8px 0; padding:10px; border:1px solid #ddd; border-radius:4px;\">"
                     . htmlspecialchars($dnsBlock, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                     . "</pre>";
        }
        else {
            $my_TEXT = $intro
                     . "<pre style=\"white-space:pre; margin:8px 0; padding:10px; border:1px solid #ddd; border-radius:4px;\">"
                     . htmlspecialchars($dnsBlock, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                     . "</pre>";
        }

        // Return HTML:
        return $my_TEXT;
    }
    
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-dns", "/dns/vsiteDNS");
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
            // We have a delete transaction:
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

        // Lastly, check if we have 'siteDNS' caps:
        if (!$CI->getAllowed('siteDNS')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }

        //
        //-- Prepare data:
        //

        $DNS_Help_Printed = 0;

        $nm_to_dec = array(
            "0.0.0.0"  => "0",
            "128.0.0.0" => "1", "255.128.0.0" => "9", "255.255.128.0" => "17", "255.255.255.128" => "25",
            "192.0.0.0" => "2", "255.192.0.0" => "10", "255.255.192.0" => "18", "255.255.255.192" => "26",
            "224.0.0.0" => "3", "255.224.0.0" => "11", "255.255.224.0" => "19", "255.255.255.224" => "27",
            "240.0.0.0" => "4", "255.240.0.0" => "12", "255.255.240.0" => "20", "255.255.255.240" => "28",
            "248.0.0.0" => "5", "255.248.0.0" => "13", "255.255.248.0" => "21", "255.255.255.248" => "29",
            "252.0.0.0" => "6", "255.252.0.0" => "14", "255.255.252.0" => "22", "255.255.255.252" => "30",
            "254.0.0.0" => "7", "255.254.0.0" => "15", "255.255.248.0" => "23", "255.255.255.254" => "31",
            "255.0.0.0" => "8", "255.255.0.0" => "16", "255.255.255.0" => "24", "255.255.255.255" => "32" ); 
            $dec_to_nm = array_flip($nm_to_dec);

        // Get data for the Vsite:
        $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));

        // Get Vsite DNS data:
        $vsite_dns = $CI->cceClient->getObject('Vsite', array('name' => $group), "DNS");

        $default_domauth = $vsite['domain'];
        $default_netauth = "";

        if(isset($get_form_data['domauth'])) {
            $domauth = urldecode($get_form_data['domauth']);
            $netauth = '';
        }
        else {
            $domauth = $default_domauth;
        }
        if ($domauth == "") {
            $domauth = $default_domauth;
        }

        if(isset($get_form_data['netauth'])) {
            $netauth = urldecode($get_form_data['netauth']);
            $domauth = '';
        }
        else {
            $netauth = '';
        }

        $iam = "/dns/vsiteDNS?group=$group"; 
        $addmod = "/dns/vsite_dns_add?group=$group"; 
        $soamod = "/dns/vsite_dns_soa?group=$group";

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", 'Select_Domain___', 'Add_Record___');

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
            // Make sure we have the $group set:
            if ((isset($group)) && (!isset($attributes['group']))) {
                $attributes['group'] = $group;
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {
            // Update list of domains we're responsible for:
            if ((isset($attributes['dnsNames'])) && (isset($attributes['group']))) {
                $CI->cceClient->set($vsite['OID'], 'DNS', array("domains" => $attributes['dnsNames']));
            }
            else {
                $CI->cceClient->set($vsite['OID'], 'DNS', array("domains" => ""));
            }
            $errors = array_merge($errors, $CI->cceClient->errors());

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors, "/dns/vsiteDNS?group=$group");
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl($iam);
        $BxPage->setErrors($errors);

        $confirm_removal = $i18n->get('confirm_removal'); 
        $confirm_delall = $i18n->get('confirm_delall'); 
        $records_title_separator = ' - ';

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteservices');
        $BxPage->setVerticalMenuChild('base_dns_vsite');
        $page_module = 'base_sitemanage';
        $defaultPage = "defaultPage";
        $dns_help = "DNS_help";

        // Determine current user's access rights to view or edit information
        // here.  Only 'manageSite' can modify things on this page.  Site admins
        // can view it for informational purposes.
        if ($CI->getAllowed('manageSite')) {
            $is_site_admin = TRUE;
            $access = 'rw';
        }
        elseif (($CI->getAllowed('siteAdmin')) && ($group == $CI->serverScriptHelper->loginUser['site'])) {
            $access = 'rw';
            $is_site_admin = FALSE;
        }
        else {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#4");
        }

        $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group)); 
        $vsite_dns = $CI->cceClient->getObject('Vsite', array('name' => $group), "DNS");

        // Find out which domains we can manage:
        $allAliases = array();
        if ($vsite_dns["domains"] != "") {
            $allAliases = $CI->cceClient->scalar_to_array($vsite_dns["domains"]);
        }
        else {
            $allAliases = array();
        }

        // Only 'systemAdministrator' and Resellers with 'siteDNS' can see this part:
        if (($CI->getAllowed('adminUser')) || (($CI->getAllowed('manageSite')) && ($CI->getAllowed('siteDNS')))) {

            // how to get web aliases & email aliases? 
            $_settings = $factory->getPagedBlock("dnsNames_header", array($defaultPage, $dns_help));
            $_settings->setDefaultPage($defaultPage);
            $_settings->setToggle("#");
            $_settings->setSideTabs(FALSE);
            $_settings->setShowAllTabs('#');

            $webAliases = $CI->cceClient->scalar_to_array($vsite["webAliases"]); 
            $mailAliases = $CI->cceClient->scalar_to_array($vsite["mailAliases"]); 
            $availableAliases = array_merge_alt($webAliases, $mailAliases);

            $key = array_search($vsite["domain"], $availableAliases);
            if ( $key === FALSE ) {
                array_push($availableAliases, $vsite["domain"]);
            }

            if ($vsite['email_autoconfig'] == '1') {
                $availableAliases[] = 'autoconfig.' . $vsite["domain"];
                $availableAliases[] = 'autodiscover.' . $vsite["domain"];
                $availableAliases[] = '_autodiscover._tcp.' . $vsite["domain"];
                $availableAliases = array_unique($availableAliases);
            }

            $picklist = $factory->getSetSelector('dnsNames',
                                    $CI->cceClient->array_to_scalar($allAliases), 
                                    $CI->cceClient->array_to_scalar($availableAliases),
                                    'selected', 'notSelected',
                                    'rw', 
                                    $CI->cceClient->array_to_scalar(array_values($allAliases)),
                                    $CI->cceClient->array_to_scalar(array_values($availableAliases))
                                );

            $picklist->setOptional(true);

            $_settings->addFormField(
                        $picklist, 
                        $factory->getLabel('adminPowers'),
                        $defaultPage
                        );

            $xxx = $factory->getRawHTML("group", '<INPUT TYPE="HIDDEN" NAME="group" VALUE="' . $group . '">');
            $_settings->addFormField(
                $xxx,
                $factory->getLabel("group"), 
                $defaultPage
            );

            // DNS Suggestions:
            $infotext = $factory->getHtmlField("BlueOnyx_Info_Text", $this->DNSExplainer($CI, $i18n, $System, $vsite), 'r');
            $infotext->setLabelType("nolabel");
            $_settings->addFormField(
                $infotext,
                $factory->getLabel(" ", false),
                $dns_help
            );
            $DNS_Help_Printed = 1;

            $_settings->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        }

        //
        //--- Handle mass deletion and single record deletion:
        //

        $addUrl = "";
        if (isset($domauth)) {
            $addUrl = "&domauth=$domauth";
        }

        // Handle mass deletion:
        if (isset($get_form_data['_DELMANY'])) {
            if (preg_match('/^[1-9][0-9]{0,15}$/', $get_form_data['_DELMANY'])) {
                // Single OID to delete:
                $_DELMANY = array($get_form_data['_DELMANY']);
            }
            else {
                // Multiple OID's to delete:
                $_DELMANY = explode("x", $get_form_data['_DELMANY']);
            }
            // Check the input we have to make sure it is what we think it might be:
            foreach ($_DELMANY as $oid) {
                // Check if it is numeric:
                if (preg_match('/^[1-9][0-9]{0,15}$/', $oid)) {
                    // Verify if it's an DnsRecord Object:
                    $DnsRecord = $CI->cceClient->get($oid);

                    if ($DnsRecord['CLASS'] != "DnsRecord") { 
                        // This is not what we're looking for! Stop poking around!
                        // Nice people say goodbye, or CCEd waits forever:
                        $CI->cceClient->bye();
                        $CI->serverScriptHelper->destructor();
                        Log403Error("/gui/Forbidden403#MD1");
                    }
                    else {
                        // Handle the delete action if appropriate and not in demo-mode:
                        if ((isset($oid)) && (!is_file("/etc/DEMO"))) {
                            // Before we destroy the record, we check if it is really among
                            // the records that this domain can manage. If it is not, we 
                            // simply skip over it without throwing an error. Because we're
                            // in a good mood and take into account that the originating
                            // page might have been cached:
                            if (in_array($DnsRecord['domainname'], $allAliases)) {
                                $CI->cceClient->destroy($oid);
                                // CCE errors that might have happened during submit to CODB:
                                $CCEerrors = $CI->cceClient->errors();
                                foreach ($CCEerrors as $object => $objData) {
                                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                                }
                            }
                        }
                    }
                }
                else {
                    // Non-numeric OID!
                    // This is not what we're looking for! Stop poking around!
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#MD2");
                }
            }

            // Also commit the changes to restart the DNS server:
            $update['commit'] = time();
            $CI->cceClient->set($System['OID'], "DNS",  $update);

            // Redirect to previous page:
            $redirect_URL = "$iam$addUrl";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        // Get the Object in question for the delete action:
        if (isset($get_form_data['_RTARGET'])) {
            $_RTARGET = $get_form_data['_RTARGET'];
            $DnsRecord = $CI->cceClient->get($_RTARGET);

            if (isset($DnsRecord['domainname'])) {
                if (!in_array($DnsRecord['domainname'], $allAliases)) {
                    // Is this domain amongst the ones that this domain can manage?
                    // It is not? Silently return to the previous page. We could
                    // throw an error, but this might be a cached page. So we forgive.
                    $redirect_URL = "$iam$addUrl";
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }
            }

            if (!is_array($DnsRecord)) {
                // We didn't get back an Object. Softfail.
                $redirect_URL = "$iam$addUrl";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }

            if ($DnsRecord['CLASS'] != "DnsRecord") { 
                // Verify if it's an DnsRecord Object to begin with:
                // This is not what we're looking for! Stop poking around!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#MD3");
            }
            else {
                // Handle the delete action if appropriate:
                if (isset($_RTARGET)) {
                    if (!is_file("/etc/DEMO")) {
                        $CI->cceClient->destroy($_RTARGET);
                    }

                    // CCE errors that might have happened during submit to CODB:
                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }

                    // Also commit the changes to restart the DNS server:
                    $update['commit'] = time();
                    $CI->cceClient->setObject("System", $update, "DNS");

                    // Redirect to previous page:
                    $redirect_URL = "$iam$addUrl";
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }               
            }
        }

        // Grab system-DNS data
        list($sys_oid) = $CI->cceClient->find('System');
        $sys_dns = $CI->cceClient->get($sys_oid, 'DNS');

        // Abstract our authorities list
        // build a pull-down menu, select a default authority
        $oids = $CI->cceClient->find("DnsSOA");
        $rec_oids = array();
        $smallnet = array();
        $auth_dom_oids = array();
        $auth_net_oids = array();
        $authorities_dom_label = array();
        $authorities_net_label = array();
        $auth_oids = array();

        rsort($oids);
        if (count($oids)) { // Any current records?
            for ($i = 0; $i <= $oids[0]; $i++) {
                if (isset($oids[$i])) {
                    $rec = $CI->cceClient->get($oids[$i], "");
                    if (in_array($rec["domainname"], $allAliases)) {
                        $authorities_dom[$rec["domainname"]] = "$iam&domauth=".urlencode($rec["domainname"]);
                        $authorities_dom_label[$rec["domainname"]] = "$iam&domauth=".urlencode($rec["domainname"]);
                        $auth_oids[$rec['domainname']] = $oids[$i];
                        array_push($auth_dom_oids, $oids[$i]);
                    }
                }
            }
        }

        // Make sure that we have populated the pulldown with all domains that we have
        // under management for this Viste. If one or more is missing, add them here:
        foreach ($allAliases as $keydomain) {
            if (!in_array($keydomain, $authorities_dom_label)) {
                $authorities_dom_label[$keydomain] = "$iam&domauth=".urlencode($keydomain);
            }
        }

        $block = $factory->getPagedBlock($i18n->get('dnsSetting') . " - " . $domauth, array($defaultPage));

        $block->setToggle("#");
        $block->setShowAllTabs('#');
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        $ScrollList = $factory->getScrollList("dnsSetting", array("source", "direction", "resolution", "listAction"), array()); 
        $ScrollList->setAlignments(array("left", "center", "left", "center"));
        $ScrollList->setDefaultSortedIndex('0');
        $ScrollList->setSortOrder('ascending');
        $ScrollList->setSortDisabled(array('3', '4'));
        $ScrollList->setPaginateDisabled(FALSE);
        $ScrollList->setSearchDisabled(FALSE);
        $ScrollList->setSelectorDisabled(FALSE);
        $ScrollList->enableAutoWidth(FALSE);
        $ScrollList->setInfoDisabled(FALSE);
        $ScrollList->setColumnWidths(array("244", "150", "244", "100")); // Max: 739px

        // We only show the SOA and Delete buttons if we're actually 
        // looking at the records of a domain:
        $DNS_top_buttons = array();
        if ($domauth != '') {
            $domauth = urldecode($domauth);

            if ((count($allAliases) === 0) && ((!$CI->getAllowed('adminUser')) || ((!$CI->getAllowed('manageSite')) && ($CI->getAllowed('siteDNS'))))) {

                //
                //--- No DNS zones have been granted for administration AND we're just a siteAdmin:
                //

                // Dummy field:
                $xxx = $factory->getRawHTML("group", '<INPUT TYPE="HIDDEN" NAME="group" VALUE="' . $group . '">');
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("group"), 
                    $defaultPage
                );

                // Set error message:
                $errors[] = ErrorMessage($i18n->get("[[base-dns.no_records]]"));
                $BxPage->setErrors($errors);

                // DNS Suggestions:
                if (!isset($_settings)) {
                    $whichtab = 'defaultPage';
                }
                else {
                    $whichtab = 'dns_help';
                }
                $infotext = $factory->getHtmlField("BlueOnyx_Info_Text", $this->DNSExplainer($CI, $i18n, $System, $vsite), 'r');
                $infotext->setLabelType("nolabel");
                $block->addFormField(
                    $infotext,
                    $factory->getLabel(" ", false),
                    $whichtab
                );

                // Add block to page:
                $page_body[] = $block->toHtml();

                // Out with the page:
                return $BxPage->render($page_module, $page_body);
            }

            // Check if the domain is among the ones we are allowed to edit:
            //if ((!in_array($domauth, $allAliases)) && (count($allAliases) > 0)) {
            //    // It is not? Reload page:
            //    $redirect_URL = "/dns/vsiteDNS?group=$group";
            //    $BxPage->ReturnToThisPage($errors, $redirect_URL);
            //}

            $rec_oids = $CI->cceClient->find("DnsRecord", array('domainname' => $domauth));
            $auth_link = '&domauth=' . $domauth;
            if (isset($auth_oids[$domauth])) {
                $DNS_top_buttons[] = $factory->getButton("$soamod&_LOAD=" . $auth_oids[$domauth] . $auth_link,"edit_soa");
            }
            else {
                if (isset($auth_oids[$default_domauth])) {
                    $DNS_top_buttons[] = $factory->getButton("$soamod&_LOAD=" . $auth_oids[$default_domauth] . $auth_link,"edit_soa");
                }
            }
            $many_oids = join('x', $rec_oids);
            $DNS_top_buttons[] = $factory->getRawHTML("del_records", '<a class="lb" href="' . "$iam&_DELMANY=$many_oids". '"><button class="no_margin_bottom div_icon tooltip hover dialog_button" title="' . $i18n->getHtml("del_records") . '"><div class="ui-icon ui-icon-trash"></div><span>' . $i18n->getHtml("del_records") . '</span></button></a>');

            $rblbuttonContainer = $factory->getButtonContainer("dnsSetting", $DNS_top_buttons);

            // If we have records, we show the button container:
            if (count($rec_oids) > 0) {
                $block->addFormField(
                    $rblbuttonContainer,
                    $factory->getLabel("dnsrecords"),
                    $defaultPage
                );
            }
        }

        if (!isset($auth_link)) {
            $auth_link = '&domauth=' . $domauth;
        }

        // Array of labels => actions for "add a record" menu
        $addRecordsList = array(
                    "a_record" => "$addmod&TYPE=A" . $auth_link,
                    "aaaa_record" => "$addmod&TYPE=AAAA" . $auth_link,
                    "mx_record" => "$addmod&TYPE=MX" . $auth_link,
                    "cname_record" => "$addmod&TYPE=CNAME" . $auth_link,
                    "txt_record" => "$addmod&TYPE=TXT" . $auth_link,
                    "srv_record" => "$addmod&TYPE=SRV" . $auth_link
                );

        if ((isset($domauth)) && (isset($netauth))) {
            if ($domauth != '') {
                $addRecordsList['subdom'] = "$addmod&TYPE=SUBDOM" . $auth_link;
            }
            elseif ($netauth != '') {
                $addRecordsList['subnet'] = "$addmod&TYPE=SUBNET" . $auth_link;
            }
        }

        // Display records:
        rsort($rec_oids);
        if(count($rec_oids)) { 
            for ($i = 0; $i < $rec_oids[0]; $i++) {
                if(isset($rec_oids[$i])) {
                    $oid = $rec_oids[$i];
                    $rec = $CI->cceClient->get($oid, "");

                    /*
                     * we could add a recordtype if structure to build the 
                     * scrollist entries aesthetically
                     * all records define 
                     * { $source, $direction, $resolution, $label }
                     */
                    $direction = $rec['type'];
                    $resolution = '';
                    $source = '';
                
                    if ($rec['type'] == 'A') {
                        if($rec['hostname']) { 
                            $source = $rec['hostname'] . ' . '; 
                        }
                        $source .= $rec['domainname'];
                        $direction = $i18n->get('a_dir');
                        $resolution = $rec['ipaddr'];
                        $label = $rec['hostname'] . '.' . $rec['domainname'];

                    }
                    elseif($rec['type'] == 'AAAA') {
                        if($rec['hostname']) { 
                            $source = $rec['hostname'] . ' . '; 
                        }
                        $source .= $rec['domainname'];
                        $direction = $i18n->get('aaaa_dir');
                        $resolution = $rec['ipaddr'];
                        $label = $rec['hostname'] . '.' . $rec['domainname'];
                    }
                    elseif($rec['type'] == 'PTR') {
                        $source = $rec['ipaddr'];
                        if ($domauth) {
                            $source .= '/' . $rec['netmask'];
                        }
                        if ($rec['hostname'] != '') { 
                            $resolution = $rec['hostname'] . ' . '; 
                        }
                        $direction = $i18n->get('ptr_dir');
                        $resolution .= $rec['domainname'];
                        $label = $rec['ipaddr'] . '/' . $rec['netmask'];

                    }
                    elseif ($rec['type'] == 'CNAME') {
                        if($rec['hostname'] != '') { 
                            $source = $rec['hostname'].' . '; 
                        } 
                        $source .= $rec['domainname'];
                        $direction = $i18n->get('cname_dir');
                        if ($rec['alias_hostname'] != '') {
                            $resolution = $rec['alias_hostname'] . ' . ';
                        }
                        $resolution .= $rec['alias_domainname'];
                        $label = $rec['alias_hostname'] . '.' . $rec['domainname'];

                    }
                    elseif ($rec['type'] == 'MX') {
                        if($rec['hostname']) { 
                            $source = $rec['hostname'] . ' . '; 
                        }
                        $source .= $rec['domainname'];
                        $resolution = $rec['mail_server_name'];
                        $direction = $i18n->get('mx_dir_' . 
                            $rec['mail_server_priority']);
                        $label = $rec['hostname'] . '.' . $rec['domainname'];

                    }
                    elseif ($rec['type'] == 'TXT') {
                        if($rec['hostname']) {
                            $source = $rec['hostname'] . ' . ';
                        }
                        $source .= $rec['domainname'];
                        $resolution = $rec['strings'];
                        $direction = $i18n->get('txt_dir');
                        $label = $rec['hostname'] . '.' . $rec['domainname'];
                    }
                    elseif ($rec['type'] == 'SRV') {
                        if ($rec['hostname'] != '') {
                            $source = $rec['hostname'] . ' . ';
                        }
                        $source .= $rec['domainname'];

                        $direction = $i18n->get('srv_dir');

                        // SRV content: priority weight port target
                        $resolution = $rec['srv_priority'] . ' ' .
                                      $rec['srv_weight']   . ' ' .
                                      $rec['srv_port']     . ' ' .
                                      $rec['srv_target'];

                        $label = $rec['hostname'] . '.' . $rec['domainname'];
                    }
                    elseif ($rec['type'] == 'SN') {
                        if($rec['ipaddr']) { 
                            $rec['type'] = 'SUBNET';
                            $direction = $i18n->get('subnet_dir');

                            $smallnet = preg_split('/\//', $rec['network_delegate']);
                            $source = $smallnet[0] . '/' .
                                $dec_to_nm[$smallnet[1]];
                            $resolution = $rec['delegate_dns_servers'];
                            $label = $rec['ipaddr'] . '/' . $rec["netmask"];
                        }
                        else {
                            $rec['type'] = 'SUBDOM';
                            $direction = $i18n->get('subdom_dir');

                            $source = $rec['hostname'].' . '.$rec['domainname'];
                            $resolution = $rec['delegate_dns_servers'];
                            $label = $rec['hostname'].'.'.$rec['domainname'];
                        }
                        $resolution = preg_replace('/^&/', '', $resolution);
                        $resolution = preg_replace('/&$/', '', $resolution);
                        $resolution = preg_replace('/&/', ' ', $resolution);
                    }
                    else {
                        //next;
                        //echo "unkown type: ".$rec['type']."\n";
                    }

                    // Edit-Button:
                    $modify_button = $factory->getModifyButton("$addmod&_BlockID=_".$rec['type']."&_TARGET=$oid&_LOAD=1&TYPE=".$rec['type'].$auth_link);
                    $modify_button->setButtonSize("small");
                    $modify_button->setButtonSpecialStyle('square_animated');
                    $modify_button->setImageOnly(TRUE);
                    $modify_button->setTarget('_self');

                    // Remove-Button:
                    $remove_button = $factory->getRemoveButton("$iam&_RTARGET=$oid$auth_link", '[[palette.remove]]');
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
                        $source,
                        $direction,
                        $resolution,
                        $combined_buttons,
                        ''
                    ));
                }
            }
        }

        $elmer_pulldown_list = array();

        // -- Add Pulldown for Domain-Selection:
        if (count($authorities_dom_label) > 0) {
            // select-an-authority button
            ksort($authorities_dom_label);
            $authorityDomButton = $factory->getMultiButton("select_dom", array_values($authorities_dom_label), array_keys($authorities_dom_label));

            if ($BX_SESSION['gui_theme'] == 'adminica') {
                $xxx = $factory->getRawHTML("filler", "&nbsp;");
                $block->addFormField(
                    $xxx,
                    $factory->getLabel(" "),
                    $defaultPage
                );
                $filler_present = "1";

                $block->addFormField(
                    $authorityDomButton,
                    $factory->getLabel(" "),
                    $defaultPage
                );
            }
            else {
                $elmer_pulldown_list[] = $authorityDomButton;
            }
        }

        $addButton = $factory->getMultiButton("add_record",
                                              array_values($addRecordsList),
                                              array_keys($addRecordsList)
                                            );

        if ($BX_SESSION['gui_theme'] == 'adminica') {
            // Add the "Add Record..." Pulldown:
            $block->addFormField(
                $addButton,
                $factory->getLabel(" "),
                $defaultPage
            );
        }
        else {
            $elmer_pulldown_list[] = $addButton;
        }

        if ($BX_SESSION['gui_theme'] == 'elmer') {
            $pulldown_list = $factory->getCompositeFormField($elmer_pulldown_list, '');
            $pulldown_list->setColumnWidths(array('col_25', 'col_25', 'col_25'));
            $pulldown_list->setClass('pb-20');

            $block->addFormField(
                $pulldown_list,
                $factory->getLabel(" "),
                $defaultPage
            );
        }

        // Show the ScrollList of the DNS Records:
        $xxx = $factory->getRawHTML("dnsrecords", $ScrollList->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("dnsrecords"),
            $defaultPage
        );

        // DNS Suggestions:
        if ($DNS_Help_Printed == 0) {
            $whichtab = 'defaultPage';
            $infotext = $factory->getHtmlField("BlueOnyx_Info_Text", $this->DNSExplainer($CI, $i18n, $System, $vsite), 'r');
            $infotext->setLabelType("nolabel");
            $block->addFormField(
                $infotext,
                $factory->getLabel(" ", false),
                $whichtab
            );
        }

        if ($BX_SESSION['gui_theme'] == 'adminica') {

            //
            //--- Add hidden Modal for Delete-Confirmation for Adminica:
            //


            // Extra header for the "do you really want to delete" dialog:
            $BxPage->setExtraHeaders('
                <script type="text/javascript">
                $(document).ready(function () {

                  $("#dialog").dialog({
                    modal: true,
                    bgiframe: true,
                    width: 500,
                    height: 200,
                    autoOpen: false
                  });

                  $(".lb").click(function (e) {
                    e.preventDefault();
                    var hrefAttribute = $(this).attr("href");

                    $("#dialog").dialog(\'option\', \'buttons\', {
                      "' . $i18n->getHtml("[[palette.remove]]") . '": function () {
                        window.location.href = hrefAttribute;
                      },
                      "' . $i18n->getHtml("[[palette.cancel]]") . '": function () {
                        $(this).dialog("close");
                      }
                    });

                    $("#dialog").dialog("open");

                  });
                });
                </script>');

            // Add hidden Modal for Delete-Confirmation
            $page_body[] = '
                <div class="display_none">
                            <div id="dialog" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-dns.del_records]]") . '">
                                <div class="block">
                                        <div class="section">
                                                <h1>' . $i18n->getHtml("[[base-dns.del_records]]") . '</h1>
                                                <div class="dashed_line"></div>
                                                <p>' . $i18n->getHtml("[[base-dns.confirm_delall]]") . '</p>
                                        </div>
                                </div>
                            </div>
                </div>' . "\n";
        }
        else {
            // Add hidden Modal for Delete-Confirmation for Elmer:
            $modal_title = $i18n->getHtml("[[base-dns.del_records]]");
            $modal_body = $i18n->getHtml("[[base-dns.confirm_delall]]");
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

            $BxPage->setExtraFooters($modal_html);

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

        // Add the DNS picklist. Only 'systemAdministrator' and Resellers with 'siteDNS' can see this:
        if (($CI->getAllowed('adminUser')) || (($CI->getAllowed('manageSite')) && ($CI->getAllowed('siteDNS')))) {
            $page_body[] = $_settings->toHtml();
            $page_body[] = "<br>\n";
        }

        // Only show the ScrollList and the shebang around it if we have DNS
        // records to manage for this Vsite and the authority for it:
        if (count($allAliases) > 0) {
            $page_body[] = $block->toHtml();
        }

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