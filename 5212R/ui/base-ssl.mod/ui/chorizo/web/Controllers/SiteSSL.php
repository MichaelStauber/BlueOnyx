<?php 
namespace Ssl\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class SiteSSL extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ssl", "/ssl/siteSSL");
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

        //
        //--- Get CODB-Object of interest: 
        //

        // We get our $get_form_data early, as this page handles both Vsite and AdmServ SSL certs.
        // Depending on what we modify, we have the "group" information on the URL string - or not.
        if ((!isset($get_form_data['group'])) && (empty($get_form_data['group'])) && ($CI->getAllowed('serverSSL'))) {
            $get_form_data['group'] = 'server';
        }

        if ((isset($get_form_data['group'])) && ($get_form_data['group'] != 'server')) {
            $siteGroup = $get_form_data['group'];
        }
        else {
            $siteGroup = '';
        }

        if ((!empty($get_form_data['group'])) && ($get_form_data['group'] != 'server')) {
            // Extra check to make sure a siteAdmin isn't messing with the URL param for "group"
            // and then tries to get access to another Vsites certs:

            if ((!$CI->getAllowed('adminUser')) && 
                (!$CI->getAllowed('siteAdmin')) && 
                (!$CI->getAllowed('manageSite')) && 
                (($user['site'] != $CI->serverScriptHelper->loginUser['site']) && $CI->getAllowed('siteAdmin')) &&
                (($vsiteObj['createdUser'] != $BX_SESSION['loginName']) && $CI->getAllowed('manageSite'))
                ) {

                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#ohcomeon");
            }

            $CODBDATA = $CI->cceClient->getObject('Vsite', array('name' => $get_form_data['group']), 'SSL');
            if ($CODBDATA == "") {
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#donthavethat");
            }
            $CODBDATA['group'] = $siteGroup;

            $NginxSystem = $CI->cceClient->getObject("System", array(), "Nginx");
            $NginxVsite = $CI->cceClient->getObject('Vsite', array('name' => $get_form_data['group']), 'Nginx');

        }
        else {
            $CODBDATA = $CI->cceClient->get($System['OID'], "SSL");
            $CODBDATA['group'] = "";

            $NginxSystem['enabled'] = '0';
            $NginxVsite['HSTS'] = '0';
            $NginxVsite['max_age'] = '0';
            $NginxVsite['include_subdomains'] = '0';
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
        // -- Export Certs:
        //

        if (isset($get_form_data['action'])) {
            if ($get_form_data['action'] == "export") {
                $cert = '';
                if ($CI->getAllowed('adminUser')) {
                    $runas = "root";
                }
                else {
                    $runas = "root";                    
                }

                // Extra check to make sure a siteAdmin isn't messing with the URL param for "group"
                // and then tries to get access to another Vsites certs:
                if (!$CI->getAllowed('manageSite')) {
                    if (($CI->getAllowed('siteAdmin')) && ($get_form_data['group'] != $CI->serverScriptHelper->loginUser['site'])) {
                        // Nice people say goodbye, or CCEd waits forever:
                        $CI->cceClient->bye();
                        $CI->serverScriptHelper->destructor();
                        Log403Error("/gui/Forbidden403#ohcomeon-seriously");
                    }
                }

                if ($get_form_data['group'] == 'server') {
                    $groupParam = '';
                }
                else {
                    $groupParam = $get_form_data['group'];
                }

                $ssl_cmd = "/usr/sausalito/sbin/ssl_get.pl " . escapeshellarg($get_form_data['type']);
                if ($groupParam != '') {
                    $ssl_cmd .= " " . escapeshellarg($groupParam);
                }
                if ($CI->serverScriptHelper->shell($ssl_cmd, $cert, $runas, $BX_SESSION['sessionId']) != 0) {
                    // Command failed - Raise an error:
                    $errors[] = ErrorMessage($i18n->get("[[base-ssl.sslGetFailed]]"));
                }        
                else {
                    // Prepare download:
                    if ($get_form_data['type'] == 'cert') {
                        $filename = 'ssl-certificate.txt';
                    }
                    else if ($get_form_data['type'] == 'csr') {
                        $filename = 'signing-request.txt';
                    }

                    // Force download:
                    return $this->response->download($filename, $cert);
                }
            }
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
            if ($get_form_data['group'] != '') {
                list($oid) = $CI->cceClient->find('Vsite', array('name' => $get_form_data['group']));
                if ($attributes['enabled'] == "0") {
                    $CI->cceClient->set($oid, 'SSL', array('uses_letsencrypt' => '0'));
                }
                $CI->cceClient->set($oid, 'SSL', array('enabled' => $attributes['enabled'], 'Force_HTTPS' => $attributes['Force_HTTPS']));

                if ((isset($attributes['HSTS_Nginx_enabled'])) && (isset($attributes['max_age'])) && (isset($attributes['include_subdomains']))) {
                    $CI->cceClient->set($oid, 'Nginx', array('HSTS' => $attributes['HSTS_Nginx_enabled'], 'max_age' => $attributes['max_age'], 'include_subdomains' => $attributes['include_subdomains']));
                }

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                if (isset($attributes['sub_ssl'])) {
                    $cfg = array("sub_ssl" => $attributes['sub_ssl']);
                }
                else {
                    $cfg = array("sub_ssl" => '0');
                }
                $CI->cceClient->set($oid, "subdomains", $cfg);

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
            }

            // Reload the entire page to load it with the updated values:
            if (!empty($get_form_data['group'])) {
                $redirect_URL = "/ssl/siteSSL?group=" . $get_form_data['group'];
            }
            else {
                $redirect_URL = "/ssl/siteSSL";
            }
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        // Pass group along in URL's:
        $urlAppendix = "";
        if (isset($siteGroup)) {
            $urlAppendix = "?group=" . $siteGroup;
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/ssl/siteSSL$urlAppendix");
        $BxPage->setErrors($errors);

        // Set Menu items:

        if (preg_match('/^site(.*)$/', $siteGroup)) {
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
        // -- Add the buttons to create/import/export a certificate
        //

        // add buttons to create/import/export a certificate
        if (preg_match('/^site(.*)$/', $CODBDATA['group'])) {
            $create = $factory->getButton('/ssl/createCert?group=' . $CODBDATA['group'], 'createCert', 'DEMO-OVERRIDE');
            $create->setIcon('fa fa-circle-o-notch');
            $request = $factory->getButton('/ssl/createCert?group=' . $CODBDATA['group'] . '&type=csr', 'request', 'DEMO-OVERRIDE');
            $request->setIcon('fa fa-circle-o');
            $ca_certs = $factory->getButton('/ssl/caManager?group=' . $CODBDATA['group'], 'manageCAs', 'DEMO-OVERRIDE');
            $ca_certs->setIcon('fa fa-cogs');
            $import = $factory->getButton('/ssl/uploadCert?group=' . $CODBDATA['group'], 'import', 'DEMO-OVERRIDE');
            $import->setIcon('fa fa-upload');
            $exportButton = $factory->getButton('/ssl/siteSSL?group=' . $CODBDATA['group'] . '&type=cert&action=export', 'export');
            $exportButton->setIcon('fa fa-download');
            $letsEncryptButton = $factory->getButton('/ssl/letsencryptCert?group=' . $CODBDATA['group'], 'LetsEncrypt', 'DEMO-OVERRIDE');
            $letsEncryptButton->setIcon('fa fa-lock');
        }
        else {
            $create = $factory->getButton('/ssl/createCert', 'createCert', 'DEMO-OVERRIDE');
            $create->setIcon('fa fa-circle-o-notch');
            $request = $factory->getButton('/ssl/createCert?group' . '&type=csr', 'request', 'DEMO-OVERRIDE');
            $request->setIcon('fa fa-circle-o');
            $ca_certs = $factory->getButton('/ssl/caManager', 'manageCAs', 'DEMO-OVERRIDE');
            $ca_certs->setIcon('fa fa-cogs');
            $import = $factory->getButton('/ssl/uploadCert', 'import', 'DEMO-OVERRIDE');
            $import->setIcon('fa fa-upload');
            $exportButton = $factory->getButton('/ssl/siteSSL?group' . '&type=cert&action=export', 'export');
            $exportButton->setIcon('fa fa-download');
            $letsEncryptButton = $factory->getButton('/ssl/letsencryptCert', 'LetsEncrypt', 'DEMO-OVERRIDE');
            $letsEncryptButton->setIcon('fa fa-lock');
        }

        // Set export button to TRUE by default:
        $exportButton->setDisabled(TRUE);

        if (preg_match('/^site(.*)$/', $CODBDATA['group'])) {
            list($oid) = $CI->cceClient->find('Vsite', array('name' => $CODBDATA['group']));
            $vsite_info = $CI->cceClient->get($oid);
            $fqdn = $vsite_info['fqdn'];
            $fqdnLabel = $vsite_info['fqdn'];
        }
        else {
            $fqdn = '[[base-ssl.serverDesktop]]';
            $fqdnLabel = $i18n->get('[[base-ssl.serverDesktop]]');
        }

        // Check if certificate and key are present:
        if ($fqdn != '[[base-ssl.serverDesktop]]') {
            $file = $vsite_info['basedir'] . '/wwwroot/certs/certificate';
        }
        else {
            $file = '/etc/admserv/certs/certificate';
        }

        $cmd = '/bin/cat ' . $file . '|/usr/bin/wc -l';
        $CI->serverScriptHelper->shell($cmd, $cert_cmd_return, 'root', $BX_SESSION['sessionId']);
        $certificate_present = rtrim($cert_cmd_return);

        if ($fqdn != '[[base-ssl.serverDesktop]]') {
            $file = $vsite_info['basedir'] . '/wwwroot/certs/key';
        }
        else {
            $file = '/etc/admserv/certs/key';
        }
        $cmd = '/bin/cat ' . $file . '|/usr/bin/wc -l';
        $CI->serverScriptHelper->shell($cmd, $key_cmd_return, 'root', $BX_SESSION['sessionId']);
        $key_present = rtrim($key_cmd_return);

        // If we have an expiration date, a key and a cert, then we allow the cert to be exported:
        if (($CODBDATA['expires'] != "") && ($certificate_present > 0) && ($key_present > 0)) {
            $exportButton->setDisabled(FALSE);
        }

        // Add #1 Button-Container:
        $buttonContainer_a = $factory->getButtonContainer("", array($create, $request, $ca_certs));

        // Add #2 Button-Container:
        $buttonContainer_b = $factory->getButtonContainer("", array($import, $exportButton, $letsEncryptButton));

        //
        // -- Add PagedBlock with Cert Info:
        //

        $defaultPage = "basic";
        $block = $factory->getPagedBlock("sslCertInfo", array($defaultPage));
        $block->setCurrentLabel($factory->getLabel('sslCertInfo', false, array('fqdn' => $fqdnLabel)));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Tab: basic
        //

        // Show enabled/disabled checkbox as read only if on the admin server and the user is the adminUser:
        if ((($CODBDATA['group'] == '') || ($CODBDATA['group'] == 'server')) && ($CI->getAllowed('adminUser'))) {
            $access = "";
        }
        elseif ((($CODBDATA['group'] != '') || ($CODBDATA['group'] == 'server')) && ($CI->getAllowed('manageSite'))) {
            $access = "rw";
        }
        else {
            $access = "r";
        }

        //
        //-- Reseller: Can the reseller that owns this Vsite modify this?
        //
        if ($CODBDATA['group']) {
            $VsiteOwnerObj = $CI->cceClient->getObject("User", array("name" => $vsite_info['createdUser']));
            if ($VsiteOwnerObj['name'] != "admin") {
                $resellerCaps = $CI->cceClient->scalar_to_array($VsiteOwnerObj['capabilities']);
                if (!in_array('resellerSSL', $resellerCaps)) {
                    $CODBDATA["enabled"] = '0';
                    $access = 'r';
                }
            }
        }

        // If we don't have a certificate or key, then we do not allow to enable:
        if (($certificate_present == 0) || ($key_present == 0)) {
            $CODBDATA['enabled'] = '0';
            $access = 'r';
        }
        $xxx = $factory->getBoolean('enabled', $CODBDATA['enabled'], $access);
        $block->addFormField(
            $xxx,
            $factory->getLabel('enabled'),
            $defaultPage
            );

        //
        //-- HSTS for Nginx:
        //

        if (($NginxSystem['enabled'] == '1') && ($CODBDATA['group'] != '')) {

            $HSTS_Nginx = $factory->getMultiChoice('HSTS_Nginx_enabled');
            $enable = $factory->getOption('HSTS_Nginx', $NginxVsite["HSTS"], $access);
            $xxx = $factory->getLabel('enable', false);
            $enable->setLabel($xxx);
            $HSTS_Nginx->addOption($enable);

            $max_age = $factory->getInteger("max_age", $NginxVsite["max_age"], '0', '31536000');
            $max_age->setWidth(8);
            $max_age->showBounds(1);
            $enable->addFormField(
                $max_age,
                $factory->getLabel("max_age")
            );

            $include_subdomains = $factory->getBoolean("include_subdomains", $NginxVsite["include_subdomains"], 'rw');
            $enable->addFormField(
                $include_subdomains,
                $factory->getLabel("include_subdomains")
            );

            $block->addFormField($HSTS_Nginx, $factory->getLabel('HSTS_Nginx_enabled'), $defaultPage);
        }

        //
        //--- Force HTTPS:
        //

        if ($CODBDATA['enabled'] == '0') {
            $CODBDATA["Force_HTTPS"] = '0';
        }
        if ($siteGroup != "") {
            $Force_HTTPS_Field = $factory->getBoolean("Force_HTTPS", $CODBDATA["Force_HTTPS"], $access);
            $block->addFormField(
                $Force_HTTPS_Field,
                $factory->getLabel("Force_HTTPS"), 
                $defaultPage);
        }

        //
        //--- SSL for Subdomains (actual handling is via base-subdomains)
        //

        if ($siteGroup != "") {
            $vsiteSub = $CI->cceClient->getObject('Vsite', array('name' => $siteGroup), "subdomains");
            $xxx = $factory->getBoolean("sub_ssl", $vsiteSub["sub_ssl"], $access);
            $block->addFormField(
                $xxx,
                $factory->getLabel("[[base-subdomains.sub_ssl]]"), 
                $defaultPage);
        }

        //---

        // If we have an expiration date, a key and a cert, then we show the cert information:
        if (($CODBDATA['expires'] != "") && ($certificate_present > 0) && ($key_present > 0)) {
            $cert_sections = array(
                            'location' => array('city', 'state', 'country'), 
                            'orgInfo' => array('orgName', 'orgUnit'),
                            'otherInfo' => array('email'));

            foreach ($cert_sections as $section => $fields) {

                // Add divider:
                $xxx = $factory->addBXDivider($section, "");
                $block->addFormField(
                        $xxx,
                        $factory->getLabel($section, false),
                        $defaultPage
                        );

                foreach ($fields as $var) {
                    $value = $CODBDATA[$var];
                    if ($var == 'country') {
                        $value = $i18n->get($CODBDATA[$var]);
                        if (preg_match('/^Project-Id-Version.*/', $value)) {
                            $value = "";
                        }
                    }
                        
                    $xxx = $factory->getTextField($var, $value, 'r');
                    $block->addFormField(
                        $xxx,
                        $factory->getLabel($var),
                        $defaultPage
                        );
                }
            }

            // Special case expires field
            $xxx = $factory->getTimeStamp('expires', strtotime($CODBDATA['expires']), 'date', 'r');
            $block->addFormField(
                $xxx,
                $factory->getLabel('expires'),
                $defaultPage
                );

        }
        else {
            // We don't have any Cert info:
            $my_TEXT = $i18n->interpolate('[[base-ssl.noCertInfo]]');
            $cert_info_text = $factory->getHtmlField("_", $my_TEXT, 'r');
            $cert_info_text->setLabelType("nolabel");
            $block->addFormField(
                $cert_info_text,
                $factory->getLabel(" "),
                $defaultPage
                );
        }

        //
        //--- Add the Save/Cancel buttons, but only in Vsite management:
        //
        if (($CODBDATA['group'] != '') && (($CI->getAllowed('adminUser')) || ($CI->getAllowed('manageSite')))) {
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/ssl/siteSSL?group=" . $CODBDATA['group']));
        }

        $page_body[] = $buttonContainer_a->toHtml();
        $page_body[] = $buttonContainer_b->toHtml();
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