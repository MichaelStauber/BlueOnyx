<?php 
namespace Backupcontrol\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Desktopcontrol extends BaseController {
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

        //helper(['form']);

        $CI =& get_instance();

        if (!$CI->getAllowed('serverServerDesktop')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();

        // This pages intends to modify the 'System' Object. So we need to make sure not to work with
        // the data cached in the session-cache, but the real CODB data. Let us fetch that from CODB
        // via a dedicated $CI->cceClient->get() and not a $CI->cceClient->getObject() request:
        $System = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        $CODBDATA = $CI->cceClient->get($System['OID'], "DesktopControl");
        $System_SSH = $CI->cceClient->get($System['OID'], "SSH");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-backupcontrol", "/backupcontrol/desktopcontrol");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        $errors = $BxPage->getErrors();

        //
        //--- GUI theme prep:
        //

        $major_theme_choice = array('elmer' => 'Elmer');
        $major_theme_choice_flipped = array_flip($major_theme_choice);

        $allowed_theme_choice = array('elmer' => 'Elmer');
        $allowed_theme_choice_flipped = array_flip($allowed_theme_choice);

        //
        //--- Handle POST Request:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Has getPost request:
            $form_data = $BxPage->FORM_POST;

            // Form fields that are required to have input:
            $required_keys = array('csrf_protection', 'csrf_expire', 'csrf_regenerate', 'ddos_protection', 'ddos_attempts', 'ddos_window', 'ddos_expire');

            // Empty array for key => values we want to submit to CCE:
            $attributes = array();

            // Items we do NOT want to submit to CCE:
            $ignore_attributes = array("BlueOnyx_Info_Text");

            // Run GetFormAttributes()
            if (is_array($form_data)) {
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


            //
            //--- No errors? Submit to CODB:
            //

            if (count($errors) === 0) {

                // Update CSRF settings:
                if ((isset($attributes['csrf_protection'])) && (isset($attributes['csrf_expire'])) && (isset($attributes['csrf_regenerate']))) {
                    // Set CSRF settings to CODB:
                    $CI->cceClient->set($System['OID'], '',  array('csrf_protection' => $attributes['csrf_protection'], 'csrf_expire' => $attributes['csrf_expire'], 'csrf_regenerate' => $attributes['csrf_regenerate']));

                    // CCE errors that might have happened during submit to CODB:
                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }
                }

                if (isset($attributes['csrf_protection'])) {
                    unset($attributes['csrf_protection']);
                }
                if (isset($attributes['csrf_expire'])) {
                    unset($attributes['csrf_expire']);
                }
                if (isset($attributes['csrf_regenerate'])) {
                    unset($attributes['csrf_regenerate']);
                }

                // Update DDOS-protection settings:
                if ((isset($attributes['ddos_protection'])) && (isset($attributes['ddos_attempts'])) && (isset($attributes['ddos_window'])) && (isset($attributes['ddos_expire']))) {
                    // Set DDOS settings to CODB:
                    $CI->cceClient->set($System['OID'], '',  array('ddos_protection' => $attributes['ddos_protection'], 'ddos_attempts' => $attributes['ddos_attempts'], 'ddos_window' => $attributes['ddos_window'], 'ddos_expire' => $attributes['ddos_expire']));

                    // CCE errors that might have happened during submit to CODB:
                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }
                }

                if (isset($attributes['ddos_protection'])) {
                    unset($attributes['ddos_protection']);
                }
                if (isset($attributes['ddos_attempts'])) {
                    unset($attributes['ddos_attempts']);
                }
                if (isset($attributes['ddos_window'])) {
                    unset($attributes['ddos_window']);
                }
                if (isset($attributes['ddos_expire'])) {
                    unset($attributes['ddos_expire']);
                }

                $GUI_attribs = array();
                if ((isset($attributes['GUI_PORT'])) || (isset($attributes['GUI_URLs']))) {
                    $GUI_attribs['GUI_PORT'] = $attributes['GUI_PORT'];
                    $GUI_attribs['GUI_URLs'] = $attributes['GUI_URLs'];
                    unset($attributes['GUI_PORT']);
                    unset($attributes['GUI_URLs']);
                }

                // Update GUI access type:
                if ((isset($attributes['GUIaccessType'])) || (isset($attributes['GUIredirects']))) {
                    $access_attribs['GUIaccessType'] = $attributes['GUIaccessType'];
                    $access_attribs['GUIredirects'] = $attributes['GUIredirects'];
                    $CI->cceClient->setObject("System", $access_attribs, "");

                    // CCE errors that might have happened during submit to CODB:
                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }

                    unset($attributes['GUIaccessType']);
                    unset($attributes['GUIredirects']);
                }

                // Theme Handling:
                if (count($errors) === 0) {
                    // Hardwired theme choices:
                    $theme_array = array('default_gui_theme' => 'elmer', 'allowed_themes' => 'elmer');

                    if ((count($theme_array) > 0) && (count($errors) === 0)) {
                        $CI->cceClient->set($System['OID'], '',  $theme_array);
                        // CCE errors that might have happened during submit to CODB:
                        $CCEerrors = $CI->cceClient->errors();
                        foreach ($CCEerrors as $object => $objData) {
                            // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                            $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                        }
                    }

                    unset($attributes['default_gui_theme']);
                    unset($attributes['allowed_themes']);
                }

                $lock_script = '/usr/sausalito/sbin/cce_lock.pl';
                if (count($errors) === 0) {

                    // Are we locking or unlocking?
                    if ($attributes['lock'] == "1") {

                        // Tell CCEd to lock it up:
                        $CI->cceClient->set($System['OID'], "DesktopControl",  $attributes);

                        // Lock it up via Perl script:
                        $lock_cmd = "$lock_script --lock --reason=[[base-backupcontrol.locked]]";
                        $ret = $CI->serverScriptHelper->shell($lock_cmd, $output, 'root', $BX_SESSION['sessionId']);

                        if ($ret != 0) {
                            # Suspending failed.  Rollback the lock bit in CODB:
                            $CI->cceClient->set($System['OID'], "DesktopControl",  array('lock' => "0"));

                            // CCE errors that might have happened during submit to CODB:
                            $CCEerrors = $CI->cceClient->errors();
                            foreach ($CCEerrors as $object => $objData) {
                                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                            }
                        }               
                    }
                    else {
                        // We are attempting to unlock the desktop.  Unlock cce first via the Perl script:
                        $ret = $CI->serverScriptHelper->shell("$lock_script --unlock", $output, 'root', $BX_SESSION['sessionId']);

                        if ($ret == 0) {
                            // That went well. Now unset the lock bit in CODB:
                            $CI->cceClient->set($System['OID'], "DesktopControl",  array('lock' => "0"));

                            // CCE errors that might have happened during submit to CODB:
                            $CCEerrors = $CI->cceClient->errors();
                            foreach ($CCEerrors as $object => $objData) {
                                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                            }
                        }
                    }
                }

                //
                //--- Lastly: Handle eventual port or URL changes:
                //

                if ((isset($GUI_attribs['GUI_PORT'])) || (isset($GUI_attribs['GUI_URLs']))) {

                    // We have a port:
                    if (isset($GUI_attribs['GUI_PORT'])) {
                        // Check if this is a request to change the GUI port:
                        $GUIportInUse = 0;

                        if ($GUI_attribs['GUI_PORT'] != $System['GUI_PORT']) {

                            $checkPort = intval($GUI_attribs['GUI_PORT']);

                            // Use PHP to check if the port is already in use (IPv4 and IPv6)
                            $isInUse = false;
                            $sockets = @fsockopen('127.0.0.1', $checkPort);
                            if ($sockets) {
                                fclose($sockets);
                                $isInUse = true;
                            } else {
                                // Try IPv6 localhost
                                $sockets = @fsockopen('::1', $checkPort);
                                if ($sockets) {
                                    fclose($sockets);
                                    $isInUse = true;
                                }
                            }

                            if ($isInUse) {
                                $errors[] = ErrorMessage($i18n->get("[[base-backupcontrol.GUI_PORT_in_use]]"), $type="alert_red", $icon="alert_2", TRUE);
                            }
                        }
                    }

                    if (isset($GUI_attribs['GUI_URLs'])) {
                        $trimmed_URLs = array();
                        $GUI_URLs = preg_split('/\r\n|\r|\n/', $GUI_attribs['GUI_URLs']);
                        foreach ($GUI_URLs as $value) {
                            // Use trim to remove all types of whitespace characters from both ends
                            $trimmed_URLs[] = trim($value);
                        }

                        if (count($trimmed_URLs) === 0) {
                            $errors[] = ErrorMessage($i18n->get("[[base-backupcontrol.GUI_URLs_minimum_fail]]"), $type="alert_red", $icon="alert_2", TRUE);
                        }
                        $GUI_attribs['GUI_URLs'] = $CI->cceClient->array_to_scalar($trimmed_URLs);
                    }

                    if (count($errors) === 0) {
                        $CI->cceClient->setObject("System", $GUI_attribs, "");

                        // CCE errors that might have happened during submit to CODB:
                        $CCEerrors = $CI->cceClient->errors();
                        foreach ($CCEerrors as $object => $objData) {
                            // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                            $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                        }

                        // Pass on errors:
                        $BxPage->setErrors($errors);

                        // Perform the redirect
                        $gui_host = $_SERVER['HTTP_HOST'];
                        $hostWithoutPort = preg_replace('/:\d+$/', '', $gui_host);
                        $url = 'https://' . $hostWithoutPort . ':' . $GUI_attribs['GUI_PORT'] . '/backupcontrol/desktopcontrol';
                        header("Location: $url");
                        exit();
                    }
                }

                //
                //--- Redirect to this page:
                //

                $redirect_URL = "/backupcontrol/desktopcontrol";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_sysmaintenance');
        $page_module = 'base_sysmanage';

        $defaultPage = "basic";

        $block = $factory->getPagedBlock("desktop_control", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        //$AccessTypeMap = array("BOTH" => "BOTH", "HTTPS" => "HTTPS", "HTTP" => "HTTP");
        $AccessTypeMap = array("HTTPS" => "HTTPS");
        $AccessType_select = $factory->getMultiChoice("GUIaccessType", array_values($AccessTypeMap));
        $AccessType_select->setSelected($AccessTypeMap[$System['GUIaccessType']], true);
        $block->addFormField($AccessType_select, $factory->getLabel("GUIaccessType"));

        $ffs = $factory->getBoolean("GUIredirects", $System['GUIredirects']);
        $block->addFormField(
            $ffs,
            $factory->getLabel("GUIredirects")
        );

        //
        //-- GUI Port and GUI URLs:
        //

        // GUI Port:
        $GUI_PortField = $factory->getInteger("GUI_PORT", $System["GUI_PORT"], "81", "65535");
        $GUI_PortField->setWidth(5);
        $GUI_PortField->showBounds(1);
        $block->addFormField(
            $GUI_PortField,
            $factory->getLabel("GUI_PORT")
        );

        // GUI URLs:
        $GUI_URLs_Field = $factory->getTextBlock("GUI_URLs", $CI->cceClient->scalar_to_string($System["GUI_URLs"]), 'rw');
        $GUI_URLs_Field->setOptional(FALSE);
        $GUI_URLs_Field->setType('alphanum_plus_multiline');
        $block->addFormField(
            $GUI_URLs_Field,
            $factory->getLabel("GUI_URLs")
        );

        // Hardwire theme choice to 'elmer';
        $System['allowed_themes'] = 'elmer';


        //
        //-- CSRF:
        //

        // csrf_protection:
        $csrf_protection = $factory->getMultiChoice('csrf_protection');
        $enable = $factory->getOption('csrf_protection', $System['csrf_protection'], 'rw');
        $xxx = $factory->getLabel('csrf_protection', false);
        $enable->setLabel($xxx);
        $csrf_protection->addOption($enable);

        // csrf_expire:
        $csrf_expire = $factory->getInteger('csrf_expire', $System['csrf_expire'], "300", "10800", 'rw');
        $csrf_expire->setWidth(7);
        $csrf_expire->showBounds(1);
        $enable->addFormField($csrf_expire, $factory->getLabel('csrf_expire'));

        // csrf_regenerate:
        $csrf_regenerate = $factory->getBoolean('csrf_regenerate', $System['csrf_regenerate'], 'rw');
        $enable->addFormField($csrf_regenerate, $factory->getLabel('csrf_regenerate'));

        // Out with the enabler:
        $block->addFormField($csrf_protection, $factory->getLabel('csrf_protection'));

        //
        //-- DDOS:
        //

        // ddos_protection:
        $ddos_protection = $factory->getMultiChoice('ddos_protection');
        $ddos_enable = $factory->getOption('ddos_protection', $System['ddos_protection'], 'rw');
        $zzz = $factory->getLabel('ddos_protection', false);
        $ddos_enable->setLabel($zzz);
        $ddos_protection->addOption($ddos_enable);

        // ddos_attempts:
        $ddos_attempts = $factory->getInteger('ddos_attempts', $System['ddos_attempts'], "3", "1000", 'rw');
        $ddos_attempts->setWidth(7);
        $ddos_attempts->showBounds(1);
        $ddos_enable->addFormField($ddos_attempts, $factory->getLabel('ddos_attempts'));

        // ddos_window:
        $ddos_window = $factory->getInteger('ddos_window', $System['ddos_window'], "60", "86400", 'rw');
        $ddos_window->setWidth(7);
        $ddos_window->showBounds(1);
        $ddos_enable->addFormField($ddos_window, $factory->getLabel('ddos_window'));

        // ddos_expire:
        $ddos_expire = $factory->getInteger('ddos_expire', $System['ddos_expire'], "60", "86400", 'rw');
        $ddos_expire->setWidth(7);
        $ddos_expire->showBounds(1);
        $ddos_enable->addFormField($ddos_expire, $factory->getLabel('ddos_expire'));

        // Out with the enabler:
        $block->addFormField($ddos_protection, $factory->getLabel('ddos_protection'));

        //
        //-- Lock Desktop
        //

        $ffs = $factory->getBoolean("lock", $CODBDATA['lock']);
        $block->addFormField(
            $ffs,
            $factory->getLabel("lock_desktop")
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/backupcontrol/desktopcontrol"));

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
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
