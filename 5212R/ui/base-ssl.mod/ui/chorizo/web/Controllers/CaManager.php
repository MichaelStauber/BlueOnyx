<?php 
namespace Ssl\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class CaManager extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ssl", "/ssl/caManager");
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
                    Log403Error("/gui/Forbidden403#ohcomeone");
                }
            }

            $CODBDATA = $CI->cceClient->getObject('Vsite', array('name' => $get_form_data['group']), 'SSL');
            $CODBDATA['group'] = $get_form_data['group'];
            list($oid) = $CI->cceClient->find('Vsite', array('name' => $get_form_data['group']));
        }
        else {
            $CODBDATA = $CI->cceClient->get($System['OID'], "SSL");
            $oid = $System['OID'];
            $CODBDATA['group'] = 'server';
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
        //--- Handle deletes:
        //

        if (isset($get_form_data['_RTARGET'])) {
            if ($get_form_data['_RTARGET'] != '') {

                $current_cas = $CI->cceClient->scalar_to_array($CODBDATA['caCerts']);
                $removed_cas = stringToArray($get_form_data['_RTARGET']);
                
                $length = count($current_cas);
                for ($i = 0; $i < $length; $i++) {
                    if (in_array($current_cas[$i], $removed_cas)) {
                        unset($current_cas[$i]);
                    }
                }

                $set_value = $CI->cceClient->array_to_scalar($current_cas);
                $ok = $CI->cceClient->set($oid, 'SSL', array('caCerts' => $set_value));

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Redirect to reload the page:

                if (!empty($CODBDATA['group'])) {
                    $redirect_URL = "/ssl/caManager?group=" . $CODBDATA['group'];
                }
                else {
                    $redirect_URL = "/ssl/caManager";
                }
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
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
        if ((isset($attributes['save'])) && ($this->request->getPost(NULL, NULL, TRUE))) {

            if ((isset($attributes['group'])) && ($attributes['group'] != 'server')) {
                $group = $attributes['group'];
                $redirect_URL = "/ssl/caManager?group=$group";
            }
            else {
                $redirect_URL = "/ssl/caManager";
                $group = '';
            }

            //
            //--- Configure and instantiate the CodeIgniter 'upload' Class:
            //

            $data = $this->request->getFile('caCert');

            // Check if the upload is of type 'application/sql':
            $mime_type = $data->getClientMimeType();
            if ($mime_type != 'text/plain') {
                // Set error message and return:
                $errors[] = ErrorMessage($i18n->get("[[base-ssl.sslImportError4]]"));
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }

            if ($data->isValid() && ! $data->hasMoved()) {

                $newName = $data->getRandomName();
                $realNewName = '/tmp/' . $newName;
                $data->move('/tmp/', $newName);

                $addCaIdent = $attributes['caIdent'];
                $runas = ($CI->serverScriptHelper->getAllowed('adminUser') ? 'root' : $BX_SESSION['loginName']);
                bx_error_log("Running: /usr/sausalito/sbin/ssl_import.pl $realNewName --group=$group --type=caCert --ca-ident=$addCaIdent");
                $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/ssl_import.pl " . escapeshellarg($realNewName) . " --group=" . escapeshellarg($group) . " --type=caCert --ca-ident=" . escapeshellarg($addCaIdent), $output, $runas, $BX_SESSION['sessionId']);
                if ($ret != 0) {
                    // deal with error
                    $errors[] = ErrorMessage($i18n->get("[[base-ssl.sslImportError$ret]]"));
                    if (is_file($realNewName)) {
                        unlink($realNewName);
                    }
                }
                else {
                    if (is_file($realNewName)) {
                        unlink($realNewName);
                    }
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }
            }
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        if (isset($CODBDATA['group'])) {
            $URLsuffix = "?group=" . $CODBDATA['group'];
        }
        else {
            $URLsuffix = "";
        }

        // Prepare Page:
        $BxPage->setFormUrl("/ssl/caManager$URLsuffix");
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

        $header = 'caManager';

        if ($CODBDATA['group'] != 'server') {
            list($vsite) = $CI->cceClient->find("Vsite", array("name" => $CODBDATA['group']));
            $vsiteObj = $CI->cceClient->get($vsite);
            $fqdn = $vsiteObj['fqdn'];
        }
        else {
            $fqdn = $i18n->get('[[base-ssl.serverDesktop]]');
        }

        $defaultPage = "basic";
        $block = $factory->getPagedBlock("caManager", array($defaultPage));
        $block->setCurrentLabel($factory->getLabel($header, false, array('fqdn' => $fqdn)));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Tab: basic
        //

        // Certificate Authority:
        $caIdent = $factory->getTextField('caIdent', "");
        $block->addFormField(
            $caIdent,
            $factory->getLabel('caIdent'),
            $defaultPage
            );

        // Certificate Upload:
        $upload = $factory->getFileUpload('caCert');
        $upload->setEmptyMessage($factory->i18n->get('[[base-ssl.caCert_empty]]'));
        $block->addFormField(
            $upload,
            $factory->getLabel('certUpload'),
            $defaultPage
            );

        // Scrollist of the CA-Certs - if there are any:
        $cas = $CI->cceClient->scalar_to_array($CODBDATA['caCerts']);
        if (count($cas) && $cas[0] != '') {
            $addmod = '/ssl/caManager';
            $scrollList = $factory->getScrollList("removeCAIdent", array("caIdent", " "), array()); 
            $scrollList->setAlignments(array("left", "center", "center"));
            $scrollList->setDefaultSortedIndex('0');
            $scrollList->setSortOrder('ascending');
            $scrollList->setSortDisabled(array('1'));
            $scrollList->setPaginateDisabled(FALSE);
            $scrollList->setSearchDisabled(FALSE);
            $scrollList->setSelectorDisabled(FALSE);
            $scrollList->enableAutoWidth(FALSE);
            $scrollList->setInfoDisabled(FALSE);
            $scrollList->setColumnWidths(array("580", "150")); // Max: 739px

            for($i=0; $i < count($cas); $i++) {
                $CA = urlencode($cas[$i]);
                $group = $CODBDATA['group'];
                $scrollList->addEntry(array(
                            $cas[$i],
                            $factory->getRemoveButton("$addmod?group=$group&_RTARGET=$CA")
                            ));
            }

            $xff = $factory->getRawHTML("removeCAIdent", $scrollList->toHtml());
            $block->addFormField(
                $xff,
                $factory->getLabel("removeCAIdent"),
                $defaultPage
            );
        }

        // Add some hidden fields that we need later:
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