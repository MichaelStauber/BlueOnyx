<?php 
namespace Ssl\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class LetsencryptCert extends BaseController {
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

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ssl", "/ssl/letsencryptCert");
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

        $get_form_data = $BxPage->getGETPOST('GET');

        // We get our $get_form_data early, as this page handles both Vsite and AdmServ SSL certs.
        // Depending on what we modify, we have the "group" information on the URL string - or not.

        if ((!isset($get_form_data['group'])) && (empty($get_form_data['group'])) && ($CI->getAllowed('serverSSL'))) {
            $get_form_data['group'] = 'server';
        }

        //
        //--- Access rights stuff: 
        //

        if (($get_form_data['group'] != '') && ($get_form_data['group'] != 'server') && ($CI->getAllowed('siteAdmin'))) {

            // Extra check to make sure a siteAdmin isn't messing with the URL param for "group"
            // and then tries to get access to another Vsites certs:
            if (!$CI->getAllowed('manageSite')) {
                if (($CI->getAllowed('siteAdmin')) && ($get_form_data['group'] != $CI->serverScriptHelper->loginUser['site'])) {
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#ohcomeon");
                }
            }

            $CODBDATA = $CI->cceClient->getObject('Vsite', array('name' => $get_form_data['group']), 'SSL');
            $CODBDATA['group'] = $get_form_data['group'];
        }
        else {
            $CODBDATA = $CI->cceClient->get($System['OID'], "SSL");
            $CODBDATA['group'] = 'server';
        }

        $group = $CODBDATA['group'];
        $form_url = '/ssl/letsencryptCert';
        $redirect_URL = '/ssl/siteSSL';
        if ($group != "") {
            $form_url .= '?group=' . $group;
            $redirect_URL .= '?group=' . $group;
        }

        // Only 'serverSSL', 'manageSite' and 'siteAdmin' should be here
        if (!$CI->getAllowed('serverSSL') && !$CI->getAllowed('manageSite') && 
            !($CI->getAllowed('siteAdmin') && $CODBDATA['group'] == $user['site'])) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

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
        $ignore_attributes = array("BlueOnyx_Info_Text", "_");

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

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.
            if ($attributes['save']) {
                // actually save the information

                // use the same ui for admin server and vhosts, so assume System
                // if $attributes['group'] is empty or set to 'server':
                if (($attributes['group'] != '') && ($attributes['group'] != 'server')) {
                    list($vsite) = $CI->cceClient->find('Vsite', array('name' => $attributes['group']));
                }
                else {
                    $vsite = $System['OID'];
                }

                // Always push these out to CODB:
                $settings = array(
                            'LEemail' => strtolower($attributes['LEemail']),
                            'autoRenew' => $attributes['autoRenew'],
                            'autoRenewDays' => $attributes['autoRenewDays'],
                            'LEclientRet' => ''
                            );

                // Set 'LEwantedAliases' if we have it:
                if (isset($attributes['LEwantedAliases'])) {
                    $settings['LEwantedAliases'] = $attributes['LEwantedAliases'];
                }
                else {
                    $settings['LEwantedAliases'] = "";
                }

                // Only set these during install transaction:
                if ($attributes['LErequestCert'] == "1") {
                    $settings['uses_letsencrypt'] = '1';
                    $settings['performLEinstall'] = time();
                }

                $ok = $CI->cceClient->set($vsite, 'SSL', $settings);
                if ($ok) {

                    // Poll the freshly set Object/Namespace and check 'LEclientRet' for errors:
                    $CODBDATA = $CI->cceClient->get($vsite, "SSL");

                    $raw = (string)($CODBDATA['LEclientRet'] ?? '');
                    if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
                        $raw = substr($raw, 1, -1);
                    }

                    // Now unescape the string
                    $unescaped = stripcslashes($raw);

                    // Then decode
                    $LEclientRet = json_decode($unescaped, true);

                    if ((isset($LEclientRet['Error'])) && (isset($LEclientRet['Status'])) && (isset($LEclientRet['ErrMsg']))) {
                        if ($LEclientRet['Status'] == '1') {
                            // Encountered an error during LE transaction:
                            $errorMsgFromFile = $LEclientRet['ErrMsg'];

                            if (is_file($errorMsgFromFile) && is_readable($errorMsgFromFile)) {
                                $logContents = file_get_contents($errorMsgFromFile);

                                // Cleanup:
                                if (is_file($errorMsgFromFile) && is_writable($errorMsgFromFile)) {
                                    unlink($errorMsgFromFile);
                                }

                                // Finalie output:
                                $errorMsgFromFile = nl2br(htmlspecialchars($logContents));
                            }

                            if ((isset($errorMsgFromFile)) && (!empty($errorMsgFromFile))) {
                                if (preg_match('/LE_CA_Request_Error/', $LEclientRet['Error'])) {
                                    $errors[] = ErrorMessage($i18n->get("[[base-ssl.LE_CA_Request_Error,msg=\"\"]]"));
                                    $errors[] = ErrorMessage($errorMsgFromFile);
                                }
                                if (preg_match('/doNotHaveValidLECert/', $LEclientRet['Error'])) {
                                    $errors[] = ErrorMessage($i18n->get("[[base-ssl.LE_CA_Request_Error,msg=\"\"]]"));
                                    $errors[] = ErrorMessage($errorMsgFromFile);
                                }
                            }
                            else {
                                $errors[] = ErrorMessage($i18n->get("[[base-ssl.LE_CA_Request_Error,msg=\"Unknown Error: Please check /var/log/letsencrypt/letsencrypt.log\"]]"));
                            }
                        }
                    }
                    else {
                        // We didn't get a JSON back, so all ought to be good. If not: We have no error to show anyway.
                    }

                    if ((count($errors) == "0")) {
                        // Redirect the web browser
                        if ($attributes['type'] == 'csr') {
                            $redirect_URL = "/ssl/siteSSL?group=" . $attributes['group'] . "&action=export&type=csr";
                        }
                        else {
                            if (($attributes['group'] == '') || ($attributes['group'] == 'server')) {
                                $redirect_URL = '/ssl/siteSSL';
                            }
                            else {
                                $redirect_URL = '/ssl/siteSSL?group=' . $attributes['group'];
                            }
                        }
                        // Return to sender:
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                }
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Reload the entire page to load it with the updated values:
            $BxPage->ReturnToThisPage($errors, $form_url);
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl($form_url);
        $BxPage->setErrors($errors);

        // Set Menu items:
        if (($CODBDATA['group'] != "") && ($CODBDATA['group'] != "server")) {
            // We are in "Site Management" / "SSL":
            $BxPage->setVerticalMenu('base_sitemanage');
            $BxPage->setVerticalMenuChild('base_ssl');
            $page_module = 'base_sitemanage';
        }
        else {
            // We are in "Security" / "SSL"
            $BxPage->setVerticalMenu('base_security');
            $BxPage->setVerticalMenuChild('base_admin_ssl');
            $page_module = 'base_sysmanage';
        }

        //
        // -- Add PagedBlock with Cert Info:
        //

        $header = 'sslCertInfo';
        if (isset($get_form_data['type'])) {
            $header = 'requestInformation';
        }

        if ($CODBDATA['group'] != 'server') {
            list($vsite) = $CI->cceClient->find("Vsite", array("name" => $CODBDATA['group']));
            $vsiteObj = $CI->cceClient->get($vsite);
            $fqdn = $vsiteObj['fqdn'];
        }
        else {
            $fqdn = $i18n->get('[[base-ssl.serverDesktop]]');
        }

        $defaultPage = "basic";
        $block = $factory->getPagedBlock("sslCertInfo", array($defaultPage));
        $block->setCurrentLabel($factory->getLabel($header, false, array('fqdn' => $fqdn)));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Tab: basic
        //

        if (isset($get_form_data['type'])) {
            $type = $get_form_data['type'];
            if ($get_form_data['type'] == 'csr') {
                $block->addFormField(
                    $factory->getBoolean('genCert', 0),
                    $factory->getLabel('genSSCert'),
                    $defaultPage);
            }
        }
        else {
            $type = '';
        }

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_INTRO", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_INTRO", false),
                $defaultPage
                );

        $my_TEXT = "<div class='flat_area grid_16'><br>" . $i18n->getClean("[[base-ssl.LetsEncrypt_info_text]]") . "</div>";
        $infotext = $factory->getHtmlField("LetsEncrypt_info_text", $my_TEXT, 'r');
        $infotext->setLabelType("nolabel");
        $block->addFormField(
          $infotext,
          $factory->getLabel(" ", false),
          $defaultPage
        );

        // Add divider:
        $xxx = $factory->addBXDivider("DIVIDER_OPTIONS", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("DIVIDER_OPTIONS", false),
                $defaultPage
                );

        // Email:
        $email_field = $factory->getEmailAddress('LEemail', $CODBDATA['LEemail']);
        $email_field->setOptional(false);
        $block->addFormField(
            $email_field,
            $factory->getLabel('email'),
            $defaultPage
            );

        // Perform SSL Cert request:
        if (($CODBDATA['uses_letsencrypt'] == "0") || ($CODBDATA['enabled'] == "0")) {
            $request_one = "1";
        }
        else {
            $request_one = "0";
        }
        $LErequestCert = $factory->getBoolean('LErequestCert', $request_one, 'rw');
        $block->addFormField(
            $LErequestCert,
            $factory->getLabel('LErequestCert'),
            $defaultPage
            );

        //
        //--- Wanted Aliases:
        //

        if (isset($vsiteObj)) {
            // This is a Vsite and not 'admserv':

            $AggregatedWebAliases = $vsiteObj['webAliases'];

            $SubdomainFQDNS = array();
            $SubdomainOIDs = $CI->cceClient->findx('Subdomains', array('group' => $get_form_data['group']));
            if (count($SubdomainOIDs) > '0') {
                foreach ($SubdomainOIDs as $key => $subOID) {
                    $SubDATA = $CI->cceClient->get($subOID);
                    if ($SubDATA['domainname'] != '') {
                        $SubdomainFQDNS[] = $SubDATA['hostname'] . '.' . $SubDATA['domainname'];
                    }
                    else {
                        // 'Subdomain' Object is legacy and doesn't have 'domainname' yet. Inherit
                        // 'domain' from Vsite object instead:
                        $SubdomainFQDNS[] = $SubDATA['hostname'] . '.' . $vsiteObj['domain'];
                    }
                }
            }

            if ((isset($vsiteObj['webAliases'])) || (count($SubdomainFQDNS) > '0')) {
                // We do have 'webAliases':
                if (($vsiteObj['webAliases'] != "") || (count($SubdomainFQDNS) > '0')) {
                    // They're not empty either. See what we've got:
                    $webAliases = $CI->cceClient->scalar_to_array($vsiteObj['webAliases']);

                    // If we have subdomains, then we merge them into the web aliases for this request:
                    if (count($SubdomainFQDNS) > '0') {
                        $webAliases = array_merge($webAliases, $SubdomainFQDNS);
                        $AggregatedWebAliases = $CI->cceClient->array_to_scalar($webAliases);
                    }

                    $LEwantedAliases = $CI->cceClient->scalar_to_array($CODBDATA['LEwantedAliases']);

                    // If a webAliases equals the domain of the FQDN, add it to the list of items enabled by default: 
                    if (in_array($vsiteObj['domain'], $webAliases)) {
                        if ((!in_array($vsiteObj['domain'], $LEwantedAliases)) && ($CODBDATA['LEwantedAliases'] == "")) {
                            // But we only add it if the stored aliases for SSL aren't empty:
                            $LEwantedAliases[] = $vsiteObj['domain'];
                            $CODBDATA['LEwantedAliases'] = $CI->cceClient->array_to_scalar($LEwantedAliases);
                        }
                    }

                    // Build selector:
                    $select_webAliases = $factory->getSetSelector('LEwantedAliases',
                                            $CODBDATA['LEwantedAliases'], 
                                            $AggregatedWebAliases,
                                            'allowedAbilities', 'disallowedAbilities',
                                            'rw', 
                                            $CODBDATA['LEwantedAliases'],
                                            $AggregatedWebAliases
                                        );
                    $select_webAliases->setOptional(true);

                    // Out with selector:
                    $block->addFormField($select_webAliases, 
                                $factory->getLabel('LEwantedAliases'),
                                $defaultPage
                                );
                }
            }
        }

        //
        //--- Auto-Renew:
        //

        $AutorRenew_Field = $factory->getMultiChoice('autoRenew');
        $autoRenew = $factory->getOption('autoRenew', $CODBDATA['autoRenew'], 'rw');
        $ARLabel = $factory->getLabel('autoRenew', false);
        $autoRenew->setLabel($ARLabel);
        $AutorRenew_Field->addOption($autoRenew);

        // autoRenewDays:
        if (($CODBDATA["autoRenewDays"] > '85') || ($CODBDATA["autoRenewDays"] < '30')) {
            $CODBDATA["autoRenewDays"] = '60';
        }
        $autoRenewDays = $factory->getInteger("autoRenewDays", $CODBDATA["autoRenewDays"], "30", "85", 'rw');
        $autoRenewDays->setOptional(FALSE);
        $autoRenewDays->setWidth(4);
        $autoRenewDays->showBounds(1);
        $autoRenew->addFormField($autoRenewDays, $factory->getLabel('autoRenewDays'));

        // Out with the Element:
        $block->addFormField($AutorRenew_Field, $factory->getLabel('autoRenew'), $defaultPage);

        // Add some hidden fields that we need later:
        $fftype = $factory->getTextField('type', $type, '');
        $block->addFormField(
            $fftype,
            $factory->getLabel('type'),
            $defaultPage
        );
        $ffsave = $factory->getTextField('save', '1', '');
        $block->addFormField(
            $ffsave,
            $factory->getLabel('save'),
            $defaultPage
        );
        $ffgroup = $factory->getTextField('group', $CODBDATA['group'], '');
        $block->addFormField(
            $ffgroup,
            $factory->getLabel('group'),
            $defaultPage
        );

        //
        //--- Add the Save/Cancel buttons (not for AdmServ-Cert, though)
        //
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton($redirect_URL));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
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