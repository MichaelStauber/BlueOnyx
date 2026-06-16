<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class ManualInstall extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/manualInstall");
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

        $none_text = $i18n->get('[[base-swupdate.none]]');

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        // Get the backUrl:
        if (isset($get_form_data['backUrl'])) {
            // URL string:
            $backUrl = $get_form_data['backUrl'];
        }
        elseif (isset($post_form_data['backUrl'])) {
            // Alternatively POST value:
            $backUrl = $post_form_data['backUrl'];
        }
        else {
            // Nothing? Then it's empty:
            $backUrl = "";
        }

        // Just to make sure it isn't empty or someone tried to be clever:
        if (!preg_match('/^\/swupdate\//', $backUrl)) {
            $backUrl = "/swupdate/newSoftware";
        }

        // Declare some constants
        $prepare_cmd = "/usr/sausalito/sbin/pkg_prepare.pl";
        $packageDir = "/home/packages";
        $magic_cmd = "/usr/bin/file";

        //
        //--- Handle form validation:
        //

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            $runas = ($CI->getAllowed('adminUser') ? 'root' : $BX_SESSION['loginName']);

            // Under Elmer the form is different and has an empty 'locationField'. Run some adjustments to set the proper 'locationField':
            if (($BX_SESSION['gui_theme'] === 'elmer') && ($form_data['locationField'] === '')) {
                if ((isset($form_data['loaded'])) && ($form_data['loaded'] != $none_text)) {
                    // Install from /home/packages:
                    $form_data['locationField'] = 'loaded';
                }
                elseif ($form_data['urlField'] != '') {
                    // We have an URL:
                    $form_data['locationField'] = 'urlField';
                }
                else {
                    // We have an uploaded file:
                    $form_data['locationField'] = 'fileField';
                }
            }

            // Check which install method was selected:
            if ($form_data['locationField'] === 'urlField') {

                //
                //-- Install from URL:
                //

                // Check if URL appears to be valid:
                if (substr($form_data['urlField'], 0, 8) != "https://" && substr($form_data['urlField'], 0, 7) != "http://" && substr($form_data['urlField'], 0, 6) != "ftp://") {
                    $errors[] = ErrorMessage($i18n->get("[[base-swupdate.invalidUrl]]") . '<br>&nbsp;');
                }
                else {
                    // We seem to have a valid URL. Package name is the last piece of the URL:
                    $names = explode("/", $form_data['urlField']);
                    $nameField = $names[count($names)-1];

                    // Install:
                    $urlField = $form_data['urlField'];

                    // Check if we have a valid URL. Because someone could call this with ...
                    // http://www.smd.net/1.pkg";touch "/tmp/yougot0wned;chmod 755 /tmp/yougot0wned;/bin/sh /tmp/yougot0wned
                    // ... and we'd execute that right on the shell as 'admserv'. Sure, that's like user 'admin'
                    // rooting the box that he has already 'root' access for. But no excuses here. Better safe
                    // than sorry. Note to self: This check requires PHP-5.2 or better.
                    $ret = -1;
                    if (filter_var($urlField, FILTER_VALIDATE_URL)) {
                        $ret = $CI->serverScriptHelper->shell("$prepare_cmd -u \"$urlField\"", $output, $runas, $BX_SESSION['sessionId']);
                    }

                    if ($ret != 0) {
                        // Deal with errors:
                        $SWUpdate = $CI->cceClient->get($System['OID'], "SWUpdate");
                        $errors[] = ErrorMessage($i18n->get($SWUpdate['message']));

                        $redirect_URL = '/swupdate/manualInstall';
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                    else {
                        // If the 'prepare_cmd' was sucessful, we now have the raw PKG info in CODB:
                        $SWUpdate = $CI->cceClient->get($System['OID'], "SWUpdate");
                        $raw_packageOID = preg_split('/=/', $SWUpdate['uiCMD']);
                        $packageOID = $raw_packageOID[1];

                        // Ob wir hier richtig sind, oder nicht, sagt uns gleich das Licht.
                        // The "download" page will show us the PKG info and will ask to install.
                        // From there on the further checks handle incorrect package formats and such:
                        $redirect_URL = "/swupdate/download?packageOID=" . $packageOID . "&backUrl=/swupdate/manualInstall?backUrl=$backUrl";
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);

                    }
                }
            }
            elseif ($form_data['locationField'] === 'fileField') {

                //
                //--- Configure and instantiate the CodeIgniter 'upload' Class:
                //

                $data = $this->request->getFile('fileField');

                // Check if the upload is of type 'application/x-xar':
                $mime_type = $data->getClientMimeType();

                if ($mime_type != 'application/x-xar') {
                    // Set error message and return:
                    $errors[] = ErrorMessage($i18n->get("[[base-swupdate.invalidUpload]]"));
                }

                if ($data->isValid() && ! $data->hasMoved()) {

                    // Normalize file name:
                    $newName = $data->getRandomName();
                    $tmp_pkg = '/tmp/' . $newName;
                    $data->move('/tmp/', $newName);

                    // Install uploaded PKG:
                    $ret = $CI->serverScriptHelper->shell("$prepare_cmd -f $tmp_pkg", $output, $runas, $BX_SESSION['sessionId']);
                    if ($ret != 0) {
                        // Deal with errors:
                        $SWUpdate = $CI->cceClient->get($System['OID'], "SWUpdate");
                        $errors[] = ErrorMessage($i18n->get($SWUpdate['message']));

                        //$errors[] = ErrorMessage($i18n->get("[[base-swupdate.badFormat]]"));
                        if (is_file($tmp_pkg)) {
                            unlink($tmp_pkg);
                        }

                        $redirect_URL = '/swupdate/manualInstall';
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                    else {
                        // If the 'prepare_cmd' was sucessful, we now have the raw PKG info in CODB:
                        $SWUpdate = $CI->cceClient->get($System['OID'], "SWUpdate");
                        $raw_packageOID = preg_split('/=/', $SWUpdate['uiCMD']);
                        $packageOID = $raw_packageOID[1];

                        if (is_file($tmp_pkg)) {
                            unlink($tmp_pkg);
                        }

                        // Ob wir hier richtig sind, oder nicht, sagt uns gleich das Licht.
                        // The "download" page will show us the PKG info and will ask to install.
                        // From there on the further checks handle incorrect package formats and such:
                        $redirect_URL = "/swupdate/download?packageOID=" . $packageOID . "&backUrl=/swupdate/manualInstall?backUrl=$backUrl";
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                }
            }
            elseif ($form_data['locationField'] === 'loaded') {

                //
                //-- Install from /home/packages:
                //

                $nameField = $form_data['loaded'];

                // Install uploaded PKG:
                $ret = $CI->serverScriptHelper->shell("$prepare_cmd -f \"$packageDir/$nameField\"", $output, $runas, $BX_SESSION['sessionId']);
                if ($ret != 0) {
                    // Deal with errors:
                    $errors[] = ErrorMessage($i18n->get("[[base-swupdate.badFormat]]"));
                    $redirect_URL = '/swupdate/manualInstall';
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }
                else {
                    // If the 'prepare_cmd' was sucessful, we now have the raw PKG info in CODB:
                    $SWUpdate = $CI->cceClient->get($System['OID'], "SWUpdate");
                    $raw_packageOID = preg_split('/=/', $SWUpdate['uiCMD']);
                    $packageOID = $raw_packageOID[1];

                    // Ob wir hier richtig sind, oder nicht, sagt uns gleich das Licht.
                    // The "download" page will show us the PKG info and will ask to install.
                    // From there on the further checks handle incorrect package formats and such:
                    $redirect_URL = "/swupdate/download?packageOID=" . $packageOID . "&backUrl=/swupdate/manualInstall?backUrl=$backUrl";
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }

            }
            else {
                // Wow. No method selected. Reload page and try that again:
                $redirect_URL = "/swupdate/manualInstall?backUrl=$backUrl";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }

        //
        //--- Get all loaded packages. We'll match anything that's a tar file
        //

        $packages = array();
        if (is_dir($packageDir)) {
            $dir = opendir($packageDir);
            while($file = readdir($dir)) {
                if ($file[0] == '.') {
                    continue;
                }

                $CI->serverScriptHelper->shell("$magic_cmd $packageDir/$file", $output, 'root');

                if (preg_match("/(tar|compressed|PGP\s+armored|\sdata$)/", $output)) {
                    $packages[] = $file;
                }
            }
            closedir($dir);
        }

        // Prepare Page:
        $BxPage->setFormUrl("/swupdate/manualInstall");
        $BxPage->setErrors($errors);

        //-- Generate page:

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('base_softwareNew');
        $page_module = 'base_software';

        $defaultPage = "licenseField";

        $block = $factory->getPagedBlock("manualInstall", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // Add divider:
        $xxx = $factory->addBXDivider("warning_header", "");
        $block->addFormField(
            $xxx,
            $factory->getLabel("warning_header", false),
            $defaultPage
        );

        // 3rd party software warning:
        $my_TEXT = $i18n->getClean("3rdpartypkg_warning");
        $infotext = $factory->getHTMLField("BlueOnyx_Info_Text", $my_TEXT, 'r');
        $infotext->setLabelType("nolabel");
        $block->addFormField(
            $infotext,
            $factory->getLabel(" ", false),
            $defaultPage
        );

        if ($BX_SESSION['gui_theme'] === 'adminica') {

            // Set up MultiChoice:
            $location = $factory->getMultiChoice("locationField");

            // Add URL option:
            $url = $factory->getOption("url", true);
            $urlFieldx = $factory->getTextField("urlField");
            $urlFieldx->setOptional(TRUE);
            $urlFieldx->setType("");
            $url->addFormField($urlFieldx);
            $location->addOption($url);

            // Add Upload option:
            $upload = $factory->getOption("upload");
            $xxx = $factory->getFileUpload("fileField", "");
            $upload->addFormField($xxx, $defaultPage);
            $location->addOption($upload);

            // Add /home/packages as an option if there are packages in there:
            if (count($packages) > 0) {
                $loaded = $factory->getOption("loaded");
                $xxx = $factory->getMultiChoice("loaded", $packages);
                $loaded->addFormField($xxx, $defaultPage);
                $location->addOption($loaded);
            }

            // Push out the MultiChoice:
            $block->addFormField(
                $location,
                $factory->getLabel("locationFieldEnter"),
                $defaultPage
            );
        }
        else {

            // Pulldown from /home/packages:
            if (count($packages) > 0) {

                ksort($packages);
                $new_Packages[] = $none_text;
                foreach ($packages as $key => $value) {
                    $new_Packages[] = $value;
                }

                $local_pkgs_select = $factory->getMultiChoice("loaded",array_values($new_Packages));
                $block->addFormField(
                    $local_pkgs_select,
                    $factory->getLabel("loaded"),
                    $defaultPage
                );
            }

            // URL field:
            $pkg_url_Field = $factory->getTextField("urlField", '');
            $pkg_url_Field->setOptional ('silent');
            $pkg_url_Field->setType ('URL');
            $block->addFormField(
              $pkg_url_Field,
              $factory->getLabel("urlField"),
              $defaultPage
            );

            // File upload field:
            $pkg_FileUpload_Field = $factory->getFileUpload('fileField', "");
            $block->addFormField(
                $pkg_FileUpload_Field,
                $factory->getLabel('upload'),
                $defaultPage
            );

            // Fake 'locationField':
            $locationField = $factory->getTextField("locationField", '', '');
            $block->addFormField(
              $locationField,
              $factory->getLabel("locationField"),
              $defaultPage
            );

        }

        // Submit backUrl as well:
        $xxx = $factory->getTextField("backUrl", $backUrl, "");
        $block->addFormField(
            $xxx, 
            $defaultPage
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton($backUrl));

        // Page parts:
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