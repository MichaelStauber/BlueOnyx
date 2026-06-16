<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Settings extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/settings");
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

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Page logic start:
        //

        //
        //--- Get CODB-Object of interest: 
        //

        // Get settings
        $swUpdate = $CI->cceClient->get($System['OID'], "SWUpdate");

        // We use the first server object as the default for properties like proxies
        // because they have the same value anyway. These properties should actually be
        // in System.SWUpdate
        $oids = $CI->cceClient->findNSorted("SWUpdateServer", "orderPreference");
        $servers = array();

        for($i = 0; $i < count($oids); $i++) {
            $servers[] = $CI->cceClient->get($oids[$i]);
        }

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text");

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

        if ($this->request->getPost(NULL, NULL, TRUE)) {
                 
            $newScheduleMap = array("hourly" => "Hourly", "daily" => "Daily", "weekly" => "Weekly", "monthly" => "Monthly");
            if ($attributes['updateInterval'] == 'never') {
                $attributes['updateInterval'] = 'monthly';
            }
            $attributes['updateInterval'] = $newScheduleMap[$attributes['updateInterval']];
             
            // If AM email is not set, we may have 'emailField', but in CODB it's called 'updateEmailNotification':
            if (isset($attributes['emailField'])) {
                $attributes['updateEmailNotification'] = $attributes['emailField'];
                unset($attributes['emailField']);
            }
            
            $notificationLightField = $attributes['notificationLightField'];
            unset($attributes['notificationLightField']);
                
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB after making sure we have the minimum of data:
            $AutoUpdateList = array();
            $BasePKG = $CI->cceClient->getObject("Package", array("name" => 'base', 'vendor' => 'Compass', 'installState' => 'Installed'));
            $webappPKG = $CI->cceClient->getObject("Package", array("name" => 'webapp', 'vendor' => 'Compass', 'installState' => 'Installed'));
            if (isset($attributes['AutoUpdateList'])) {
                $AutoUpdateList = $CI->cceClient->scalar_to_array($attributes['AutoUpdateList']);
                if ($BasePKG) {
                    if (!in_array('base', $AutoUpdateList)) {
                        // Base PKG installed but not selected. Set it to autoupdate:
                        $AutoUpdateList[] = 'base';
                    }
                }
                if ($webappPKG) {
                    if (!in_array('webapp', $AutoUpdateList)) {
                        // WebApp PKG installed but not selected. Set it to autoupdate:
                        $AutoUpdateList[] = 'webapp';
                    }
                }
                $attributes['AutoUpdateList'] = $CI->cceClient->array_to_scalar($AutoUpdateList);
            }
            else {
                // AutoUpdatesList was empty. We populate it with the required minimums:
                if ($BasePKG) {
                    // Base PKG installed. Set it to autoupdate:
                    $AutoUpdateList[] = 'base';
                }
                if ($webappPKG) {
                    // WebApp PKG installed. Set it to autoupdate:
                    $AutoUpdateList[] = 'webapp';
                }
                $attributes['AutoUpdateList'] = $CI->cceClient->array_to_scalar($AutoUpdateList);
            }

            if (!isset($attributes['requireSignature'])) {
                $attributes['requireSignature'] = '0';
            }

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "SWUpdate",  $attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Remove all the existing servers first
            $CI->cceClient->destroyObjects("SWUpdateServer");

            // Add back all the specified ones
            $notifyModeMap = array("all" => "AllNew", "updates" => "UpdatesOnly");
            $servers = stringToArray($attributes['servers']);
            if (!count($servers)) { $servers = array(""); }
            for($i = 0; $i < count($servers); $i++) {
              $CI->cceClient->create("SWUpdateServer", array("location" => $servers[$i],
                "notificationMode" => $notifyModeMap[$notificationLightField], "orderPreference" => $i+1));
              $errors = array_merge($errors, $CI->cceClient->errors());
            }

            // Reload the entire page to load it with the updated values:
            $redirect_URL = "/swupdate/settings";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Own page logic:
        //

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/swupdate/settings");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_software');
        $BxPage->setVerticalMenuChild('base_softwareSettings');
        $page_module = 'base_software';

        $defaultPage = "basic";

        $block = $factory->getPagedBlock("softwareInstallSettings", array($defaultPage, 'advanced'));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Basic:
        //

        $scheduleMap = array("Hourly" => "hourly", "Daily" => "daily", "Weekly" => "weekly", "Monthly" => "monthly");
        $scheduleField = $factory->getMultiChoice("updateInterval", array("hourly", "daily", "weekly", "monthly"), array($scheduleMap));
        $scheduleField->setSelected($scheduleMap[$swUpdate['updateInterval']], true);
        $block->addFormField(
          $scheduleField,
          $factory->getLabel("scheduleField"),
          "basic"
        );

        $notifyMap = array("AllNew" => "all", "UpdatesOnly" => "updates");
        $notificationLightField = $factory->getMultiChoice("notificationLightField", array("all", "updates"), array($notifyMap));
        $notificationLightField->setSelected($notifyMap[$servers[0]["notificationMode"]], true);
        $block->addFormField(
          $notificationLightField,
          $factory->getLabel("notificationLightField"),
          "basic"
        );

        // Use ActiveMonitor's email contact list if possible
        $am_obj = $CI->cceClient->getObject('ActiveMonitor', array('cce_nocache' => 'cce_nocache'), '');
        if( ! $am_obj["alertEmailList"] ) {
          $email = $factory->getEmailAddressList("emailField", $swUpdate["updateEmailNotification"]);
          $email->setOptional(true);
          $block->addFormField(
            $email,
            $factory->getLabel("emailField"),
            "basic"
          );
        }

        //$block->addFormField(
        //  $factory->getBoolean("AutoUpdate", $swUpdate["AutoUpdate"], 'rw'),
        //  $factory->getLabel("AutoUpdate"),
        //  'basic'
        //);

        //
        //--- Selector for PHPs with AutoUpdate enabled:
        //

        $allowed_labels = array();
        $possible_labels = array();
        $allowed_caps = array();
        $possible_caps = array();

        $BasePKG = $CI->cceClient->getObject("Package", array("name" => 'base', 'vendor' => 'Compass', 'installState' => 'Installed'));
        if ($BasePKG) {
            if ((isset($BasePKG['nameTag'])) && (isset($BasePKG['name']))) {
                $allowed_labels[] = $i18n->get($BasePKG['nameTag']);
                $allowed_caps[] = $BasePKG['name'];
            }
        }

        $AutoUpdatePKGs = $CI->cceClient->scalar_to_array($swUpdate["AutoUpdateList"]);
        foreach ($AutoUpdatePKGs as $key => $AU_PKG_Name) {
            $PKG = $CI->cceClient->getObject("Package", array("name" => $AU_PKG_Name));

            if ($PKG !== null) { // Check if getObject() returned a valid result
                $allowed_labels[] = $i18n->get($PKG['nameTag']);
                $allowed_caps[] = $AU_PKG_Name;
            }
        }

        $search = array('installState' => 'Installed', 'isVisible' => '1');
        $oids = $CI->cceClient->findNSorted("Package", 'version', $search);
        foreach ($oids as $key => $OID) {
            $PKG = $CI->cceClient->get($OID);
            if (($PKG['vendor'] != "BlueOnyx") && ($PKG['vendor'] != "Project_BlueOnyx")) {
                $possible_labels[] = $i18n->get($PKG['nameTag']);
                $possible_caps[] = $PKG['name'];
            }
        }

        $select_caps = $factory->getSetSelector('AutoUpdateList',
                                $CI->cceClient->array_to_scalar($allowed_labels), 
                                $CI->cceClient->array_to_scalar($possible_labels),
                                'allowedAbilities', 'disallowedAbilities',
                                'rw', 
                                $CI->cceClient->array_to_scalar($allowed_caps),
                                $CI->cceClient->array_to_scalar($possible_caps)
                            );

        $select_caps->setOptional(true);

        if (count($allowed_caps) > '0') {
            $block->addFormField($select_caps, 
                        $factory->getLabel('AutoUpdateList'),
                        'basic'
                        );            
        }

        //
        //--- Advanced
        //

        $locations = array();
        for($i = 0; $i < count($servers); $i++) {
            $locations[] = $servers[$i]["location"];
        }
        $updateServer = $factory->getUrlList("servers", arrayToString($locations));
        $updateServer->setOptional(true);
        $block->addFormField(
          $updateServer,
          $factory->getLabel("serverField"),
          "advanced"
        );

        $httpProxy = $factory->getUrl("httpProxy", $swUpdate["httpProxy"], "", "", "rw");
        $httpProxy->setOptional(true);
        $httpProxy->setType("");
        $block->addFormField(
          $httpProxy,
          $factory->getLabel("httpProxyField"),
          "advanced"
        );

        $ftpProxy = $factory->getUrl("ftpProxy", $swUpdate["ftpProxy"]);
        $ftpProxy->setOptional(true);
        $ftpProxy->setType("");
        $block->addFormField(
          $ftpProxy,
          $factory->getLabel("ftpProxyField"),
          "advanced"
        );

        /*
        $typeMap = array("All" => "all", "Updates" => "updates");
        $block->addFormField(
          $factory->getMultiChoice("checkSetField", array("all", "updates"), array($typeMap[$swUpdate["updateType"]])),
          $factory->getLabel("checkSetField")
        );
        */

        /*
        $block->addFormField(
          $factory->getBoolean("autoField", $servers[0]["autoUpdate"]),
          $factory->getLabel("autoField"),
          "advanced"
        );
        */

        //$xxx = $factory->getBoolean("requireSignature", $swUpdate["requireSignature"]);
        $xxx = $factory->getBoolean("requireSignature", '0');
        $block->addFormField(
          $xxx,
          $factory->getLabel("requireSignatureField"),
          "hidden"
        );


        //
        //--- Add the buttons
        //

        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/swupdate/settings"));

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