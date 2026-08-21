<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class vsiteEmail extends BaseController {
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

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/vsiteEmail");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        // -- Actual page logic start:
        //

        // Get URL strings:
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

        // Only 'manageSite' can modify things on this page.
        // Site admins can view it for informational purposes.
        if ($CI->getAllowed('manageSite')) {
            $is_site_admin = FALSE;
            $access = 'rw';
        }
        elseif (($CI->getAllowed('siteAdmin')) && ($group == $CI->serverScriptHelper->loginUser['site'])) {
            $access = 'r';
            $is_site_admin = TRUE;
        }
        else {
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
        $vsiteSSL = $CI->cceClient->get($vsite['OID'], "SSL");

        // Get System . Email:
        $System_Email = $CI->cceClient->get($System['OID'], "Email");

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text");

        if ($is_site_admin) {
            $ignore_attributes = array("BlueOnyx_Info_Text", 'email_autoconfig', 'mailAliases', 'emailDisabled', 'mailCatchAll', 'allow_sender_spoof');
        }

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

        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Handle AutoFeatures:
            $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
            $cce_info = array('CCE_OID' => $vsite['OID'], 'group' => $group, 'i18n' => $i18n);
            list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
            $af_errors = $autoFeatures->handle('modifyEmail.Vsite', $cce_info);
            $errors = array_merge($errors, $af_errors);

            // Process POST data of server-admin users:
            if (!$is_site_admin) {
                if ($System_Email['authsend_protect'] == '0') {
                    $attributes['allow_sender_spoof'] = '0';
                }

                if (($attributes['emailDisabled'] == '1') && ($attributes['email_autoconfig'] == '1')) {
                    $attributes['email_autoconfig'] = '0';
                }

                // --- Maintain webAliases for email autoconfig/autodiscover:
                $webAliasesArr  = $CI->cceClient->scalar_to_array($vsite['webAliases'] ?? '');
                $webAliasesOrig = $webAliasesArr;

                // Base domain choice:
                $domain = trim((string)($vsite['domain'] ?? ''));   // e.g. smd.net
                $fqdn   = trim((string)($vsite['fqdn']   ?? ''));   // e.g. banana.smd.net
                $hn     = strtolower(trim((string)($vsite['hostname'] ?? '')));

                // If Vsite doesn't have hostname 'www' or 'mail', we use the FQDN:
                if ($hn !== 'www' && $hn !== 'mail') {
                    if ($fqdn !== '') {
                        $domain = $fqdn;
                    }
                }

                // Safety: trim trailing dot (some places store FQDNs with a dot)
                $domain = strtolower(trim($domain, ". \t\n\r\0\x0B"));

                if ($domain !== '') {
                    $wantAuto  = 'autoconfig.'   . $domain;
                    $wantDisco = 'autodiscover.' . $domain;

                    if ((string)$attributes['email_autoconfig'] === '1') {
                        // Ensure autoconfig.* and autodiscover.* exist (case-insensitive):
                        $lower = array_map('strtolower', $webAliasesArr);

                        foreach ([$wantAuto, $wantDisco] as $fqdnNeed) {
                            $lf = strtolower($fqdnNeed);
                            if (!in_array($lf, $lower, true)) {
                                $webAliasesArr[] = $fqdnNeed;
                                $lower[] = $lf;
                            }
                        }
                    } else {
                        // Strip ONLY the autoconfig/autodiscover aliases for this vsite's chosen domain:
                        $reDomain = preg_quote($domain, '/');
                        $webAliasesArr = array_values(array_filter($webAliasesArr, function ($a) use ($reDomain) {
                            $a = strtolower(trim((string)$a, ". \t\n\r\0\x0B"));
                            return !preg_match('/^(autoconfig|autodiscover)\.' . $reDomain . '$/i', $a);
                        }));
                    }
                }

                // Only set webAliases if it changed (case-insensitive compare):
                $webAliasesChanged = (array_map('strtolower', $webAliasesArr) !== array_map('strtolower', $webAliasesOrig));
                $webAliasesScalar  = $CI->cceClient->array_to_scalar($webAliasesArr);

                if ($webAliasesChanged) {
                    $setData['webAliases'] = $webAliasesScalar;
                }

                $setData = array(
                    "emailDisabled" => $attributes['emailDisabled'], 
                    "email_autoconfig" => $attributes['email_autoconfig'], 
                    "allow_sender_spoof" => $attributes['allow_sender_spoof'],
                    "mailAliases" => $attributes['mailAliases'], 
                    "mailCatchAll" => $attributes['mailCatchAll'],
                    "force_update" => time()
                );

                if ($webAliasesChanged) {
                    $setData['webAliases'] = $webAliasesScalar;
                }

                $CI->cceClient->set($vsite['OID'], '', $setData);

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // --- Maintain SSL.LEwantedAliases for email autoconfig/autodiscover:
                $leAliasesArr  = $CI->cceClient->scalar_to_array($vsiteSSL['LEwantedAliases'] ?? '');
                $leAliasesOrig = $leAliasesArr;

                if ($domain !== '') {
                    $wantAuto  = 'autoconfig.'   . $domain;
                    $wantDisco = 'autodiscover.' . $domain;

                    if ((string)$attributes['email_autoconfig'] === '1') {
                        // Ensure aliases exist (case-insensitive)
                        $lower = array_map('strtolower', $leAliasesArr);

                        foreach ([$wantAuto, $wantDisco] as $need) {
                            $l = strtolower($need);
                            if (!in_array($l, $lower, true)) {
                                $leAliasesArr[] = $need;
                                $lower[] = $l;
                            }
                        }
                    } else {
                        // Remove ONLY this vsite's autoconfig/autodiscover entries
                        $reDomain = preg_quote($domain, '/');
                        $leAliasesArr = array_values(array_filter(
                            $leAliasesArr,
                            function ($a) use ($reDomain) {
                                $a = strtolower(trim((string)$a, ". \t\n\r\0\x0B"));
                                return !preg_match('/^(autoconfig|autodiscover)\.' . $reDomain . '$/i', $a);
                            }
                        ));
                    }
                }

                // Write SSL namespace only if changed
                $leChanged = (array_map('strtolower', $leAliasesArr) !== array_map('strtolower', $leAliasesOrig));

                if ($leChanged) {
                    $CI->cceClient->set($vsite['OID'], 'SSL', ['LEwantedAliases' => $CI->cceClient->array_to_scalar($leAliasesArr)]);

                    // CCE errors that might have happened during submit to CODB:
                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }
                }
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $redirect_URL = "/vsite/vsiteEmail?group=$group";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/vsite/vsiteEmail?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteservices');
        $BxPage->setVerticalMenuChild('base_sitemail');
        $page_module = 'base_sitemanage';

        $defaultPage = "siteDefaultsTab";
        $block = $factory->getPagedBlock("siteEmailSettings", array($defaultPage));
        $block->setLabel($factory->getLabel('siteEmailSettings', false, array('fqdn' => $vsite['fqdn'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        // Enable & disable Email
        $xff = $factory->getBoolean("emailDisabled", $vsite["emailDisabled"], $access);
        $block->addFormField(
            $xff,
            $factory->getLabel("emailDisabled"),
            $defaultPage
            );

        // Email Autoconfigure
        $xff = $factory->getBoolean("email_autoconfig", $vsite["email_autoconfig"], $access);
        $block->addFormField(
            $xff,
            $factory->getLabel("email_autoconfig"),
            $defaultPage
            );

        // Allow Vsite Users to spoof email address:
        $spoof_access = 'r';
        if ($CI->getAllowed('manageSite')) {
            $spoof_access = 'rw';
        }

        // If feature is disabled on the server, hide the element and set defaults:
        if (($System_Email['authsend_protect'] == '0') && ($System['MTA'] == 'POSTFIX')) {
            $vsite["allow_sender_spoof"] = '0';
            $spoof_access = '';
        }

        $spoof_allow = $factory->getBoolean("allow_sender_spoof", $vsite["allow_sender_spoof"], $spoof_access);
        $block->addFormField(
            $spoof_allow,
            $factory->getLabel("vsite_allow_sender_spoof"),
            $defaultPage
        );

        // Mail server aliases
        $mailAliasesField = $factory->getDomainNameList("mailAliases", $vsite["mailAliases"], $access);
        $mailAliasesField->setOptional(true);
        $block->addFormField(
                $mailAliasesField,
                $factory->getLabel("mailAliases"), $defaultPage
                );

        // Site email catch-all
        $mailCatchAllField = $factory->getTextField("mailCatchAll", $vsite["mailCatchAll"], $access);
        $mailCatchAllField->setOptional(true);
        $mailCatchAllField->setType('fq_email_address_or_username');
        $block->addFormField(
                $mailCatchAllField,
                $factory->getLabel("mailCatchAll"), $defaultPage
                );

        //
        //--- DNS Explainer for 'email_autoconfig':
        //
        if ($vsite["email_autoconfig"] == '1') {

            // Add divider:
            $xxx = $factory->addBXDivider("email_autoconfig_dns_explainer", "");
            $block->addFormField(
                $xxx,
                $factory->getLabel("email_autoconfig_dns_explainer", false),
                $defaultPage
            );

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

            // ---- Build BIND snippet (SRV points to the SAME target as the CNAMEs) ----

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

            // ---------- Build record sets ----------

            $autodisco_recs    = $i18n->getClean("[[base-vsite.email_autoconfig_dns_short_explainer_Text]]");
            $autodisco_regMx   = $i18n->getClean("[[base-vsite.email_autoconfig_dns_Header_Required_MX_Target]]");
            $autodisco_regRecs = $i18n->getClean("[[base-vsite.email_autoconfig_dns_Header_Regular_Vsite_DNS]]");
            $autodisco_regMX   = $i18n->getClean("[[base-vsite.email_autoconfig_dns_MX_Records]]");

            // 1) Autoconfig/Autodiscover:
            $dnsLines = [];
            $dnsLines[] = "; --- $autodisco_recs ---";
            $dnsLines[] = "autoconfig.$zone.\t\t$ttl IN CNAME\t$mailTarget.";
            $dnsLines[] = "autodiscover.$zone.\t\t$ttl IN CNAME\t$mailTarget.";
            $dnsLines[] = "_autodiscover._tcp.$zone.\t$ttl IN SRV\t0 0 $port $mailTarget.";

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


            $intro = $i18n->getClean("[[base-vsite.email_autoconfig_dns_explainer_Text]]");
            $intro_dns = $i18n->getClean("[[base-vsite.email_autoconfig_dns_stupid_explainer_Text]]");
            $intro_short = $i18n->getClean("[[base-vsite.email_autoconfig_dns_short_explainer_Text]]");

            $my_TEXT = $intro
                     . "<br><br><b>$intro_dns</b><br><br><b>$intro_short</b>"
                     . "<pre style=\"white-space:pre; margin:8px 0; padding:10px; border:1px solid #ddd; border-radius:4px;\">"
                     . htmlspecialchars($dnsBlock, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                     . "</pre>";

            $infotext = $factory->getHtmlField("BlueOnyx_Info_Text", $my_TEXT, 'r');
            $infotext->setLabelType("nolabel");
            $block->addFormField(
                $infotext,
                $factory->getLabel(" ", false),
                $defaultPage
            );
        }

        // Need to embed this or things get confused:
        $xff = $factory->getTextField('group', $group, '');
        $block->addFormField($xff, $defaultPage);

        // Add the buttons for those who can edit this page:
        if ($access == 'rw') {
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/vsite/vsiteEmail?group=$group"));
        }

        //
        //--- Add AutoFeatures:
        //

        $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
        $cce_info = array('CCE_OID' => $vsite['OID'], 'FIELD_ACCESS' => $access, 'IS_SITE_ADMIN' => $is_site_admin, 'group' => $group);
        list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
        $cce_info['PAGED_BLOCK_DEFAULT_PAGE'] = $defaultPage;
        $autoFeatures->display($block, 'modifyEmail.Vsite', $cce_info);

        $page_body[] = $block->toHtml();

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