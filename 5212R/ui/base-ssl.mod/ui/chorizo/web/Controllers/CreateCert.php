<?php 
namespace Ssl\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class CreateCert extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ssl", "/ssl/createCert");
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
            $CODBDATA['group'] = "server";
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
                // if $attributes['group'] is empty
                if (($attributes['group'] != '') && ($attributes['group'] != 'server')) {
                    list($vsite) = $CI->cceClient->find('Vsite', array('name' => $attributes['group']));
                }
                else {
                    $vsite = $System['OID'];
                }

                $settings = array(
                            'country' => strtoupper($attributes['country']),
                            'state' => $attributes['state'],
                            'city' => $attributes['city'],
                            'orgName' => $attributes['orgName'],
                            'orgUnit' => $attributes['orgUnit'],
                            'email' => $attributes['email'],
                            'daysValid' => ($attributes['daysValid'] * $attributes['multiplier']),
                            'LEclientRet' => '',
                            'uses_letsencrypt' => '0',
                            'performLEinstall' => '',
                            'performLErenew' => '',
                            'LEcreationDate' => ''
                            );

                if ($attributes['type'] != 'csr' || $attributes['genCert']) {
                    $settings['createCert'] = time();
                }

                // gen csr if necessary
                if ($attributes['type'] == 'csr')
                    $settings['createCsr'] = time();

                $ok = $CI->cceClient->set($vsite, 'SSL', $settings);

                //    // check for fqdn to long baddata message and remove if necessary
                //    if($attributes['type'] == 'csr' && $attributes['genCert']) {
                //        $new_errors = array();
                //        // check for and remove bad data about fqdn if necessary
                //        for($i = 0; $i < count($errors); $i++) {
                //            if (!method_exists($errors[$i], 'getKey') ||
                //                $errors[$i]->getKey() != 'fqdn') {
                //                $new_errors[] = $errors[$i];
                //            }
                //        }
                //    }

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                if ($ok) {
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
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }
            }

            // No errors. Reload the entire page to load it with the updated values:
            if ((count($errors) == "0")) {
                $redirect_URL = "/ssl/createCert";
            }
            else {
                $redirect_URL = "/ssl/siteSSL";
            }
            $BxPage->ReturnToThisPage($errors, $redirect_URL);

        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/ssl/createCert");
        $BxPage->setErrors($errors);
        $BxPage->setOverlay(""); // Set an empty wait overlay as it would mess with our download.

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
        // -- Add the buttons to create/import/export a certificate
        //

        // add buttons to create/import/export a certificate
        $create = $factory->getButton('/ssl/createCert?group=' . $CODBDATA['group'], 'createCert');
        $request = $factory->getButton('/ssl/createCert?group=' . $CODBDATA['group'] . '&type=csr', 'request');
        $ca_certs = $factory->getButton('/ssl/caManager?group=' . $CODBDATA['group'], 'manageCAs');
        $import = $factory->getButton('/ssl/uploadCert?group=' . $CODBDATA['group'], 'import');
        $exportButton = $factory->getButton('/ssl/exportCert?group=' . $CODBDATA['group'] . '&type=cert', 'export');

        // Assume that if the expires field is blank there is no cert to export
        if ($CODBDATA['expires'] == '') {
            $exportButton->setDisabled(true);
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
                $xxx = $factory->getBoolean('genCert', 0);
                $block->addFormField(
                    $xxx,
                    $factory->getLabel('genSSCert'),
                    $defaultPage);
            }
        }
        else {
            $type = '';
        }

        // Add divider:
        $xxx = $factory->addBXDivider("location", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("location", false),
                $defaultPage
                );

        // City:
        $city = $factory->getTextField('city', $CODBDATA['city']);
        $city->setType('alphanum_plus_space');
        $block->addFormField(
            $city,
            $factory->getLabel('city'),
            $defaultPage
            );

        // State:
        $stateOrProvince = $factory->getTextField('state', $CODBDATA['state']);
        $stateOrProvince->setOptional('silent');
        $stateOrProvince->setType('alphanum_plus_space');
        $block->addFormField(
            $stateOrProvince,
            $factory->getLabel('state'),
            $defaultPage
            );

        if ($CODBDATA['country'] == '') {
            // If no country is set, use the current locale as indicator for the country:
            $CODBDATA['country'] = strtolower(substr($BX_SESSION['loginUser']['localePreference'], -2));
        }
        $country_list = $factory->getCountryName('country', strtolower($CODBDATA['country']), "rw");
        $block->addFormField(
            $country_list,
            $factory->getLabel('country'),
            $defaultPage
            );

        // Add divider:
        $xxx = $factory->addBXDivider("orgInfo", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("orgInfo", false),
                $defaultPage
                );

        // Organization Name:
        $orgName = $factory->getTextField('orgName', $CODBDATA['orgName']);
        $orgName->setType('alphanum_plus_space');
        $block->addFormField(
            $orgName,
            $factory->getLabel('orgName'),
            $defaultPage
            );

        // Unit:
        $org_unit = $factory->getTextField('orgUnit', $CODBDATA['orgUnit']);
        $org_unit->setOptional(true);
        $org_unit->setType('alphanum_plus_space');
        $block->addFormField(
            $org_unit,
            $factory->getLabel('orgUnit'),
            $defaultPage
            );

        // Add divider:
        $xxx = $factory->addBXDivider("otherInfo", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("otherInfo", false),
                $defaultPage
                );

        // Email:
        $email_field = $factory->getEmailAddress('email', $CODBDATA['email']);
        $email_field->setOptional(true);
        $block->addFormField(
            $email_field,
            $factory->getLabel('email'),
            $defaultPage
            );

        if ($BX_SESSION['gui_theme'] === 'adminica') {

            // Validity period:
            $time_frame = $factory->getMultiChoice('multiplier', array(365, 30, 7, 1));
            $time_frame->setLabelType("nolabel");
            $time_frame->setOptional(false);

            $days = $factory->getInteger('daysValid', 1, 1);
            $days->setLabelType("nolabel");

            // Well, now it gets nasty. We need to put a getInteger() and getMultiChoice() on the same
            // bloody line. In a perfect world we'd do that with getCompositeFormField(), which was 
            // designed for that very purpose. Works quite well, too. As long as the form fields are of 
            // more or the less of the same type. If they're not, it'll look like someone has cut out
            // individual characters from a newspaper to glue a blackmail letter together. 
            //
            // So being between a rock and a hard place here, I just cheat and use a table to format 
            // the output of naked fields without labels and manually add a lable around it:

            $ohfuckthis = '
                                        <fieldset class="label_side top">
                                                <label for="daysValid" title="' . $i18n->getWrapped('daysValid_help') . '" class="tooltip right uniform">' . $i18n->getHtml('daysValid') . '<span></span></label>
                                                <div>
                                                    <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                                        <tbody>
                                                            <tr>
                                                                <td align="center">' . $days->toHtml() . '</td>
                                                                <td align="center">' . $time_frame->toHtml() . '</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                        </fieldset>';

            // Show resulting validity period hybrid without using getCompositeFormField():
            $xxx = $factory->getRawHTML("daysValid", $ohfuckthis);
            if (!isset($attributes['save'])) {
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("daysValid"), 
                    $defaultPage
                );
            }
            else {
                $fftdaysValid = $factory->getTextField('daysValid', $attributes['daysValid'], '');
                $block->addFormField(
                    $fftdaysValid,
                    $factory->getLabel('daysValid'),
                    $defaultPage
                );
                $ffmultiplier = $factory->getTextField('multiplier', $attributes['multiplier'], '');
                $block->addFormField(
                    $ffmultiplier,
                    $factory->getLabel('multiplier'),
                    $defaultPage
                );
            }
        }
        else {
            // Elmer:
            $time_frame = $factory->getTextField("", $i18n->get('[[sitestats.year]]'), 'r');
            $time_frame->setOptional(true);
            $days = $factory->getInteger('daysValid', 1, 1);

            $daysValid_Field = $factory->getCompositeFormField(array($days, $time_frame), '');
            $daysValid_Field->setColumnWidths(array('col_25', 'col_25 mt-30', 'col_50'));

            $block->addFormField(
                    $daysValid_Field,
                    $factory->getLabel("daysValid"),
                    $defaultPage
                    );

            // Add hidden field for real time_frame multiplier:
            $time_frame_real = $factory->getTextField("multiplier", '365', '');
            $block->addFormField(
                $time_frame_real,
                $factory->getLabel("multiplier"), 
                $defaultPage
            );
        }

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
        if ($CODBDATA['group'] != "") {
            $block->addButton($factory->getCancelButton("/ssl/siteSSL?group=" . $CODBDATA['group']));
        }
        else {
            $block->addButton($factory->getCancelButton("/ssl/siteSSL"));
        }

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