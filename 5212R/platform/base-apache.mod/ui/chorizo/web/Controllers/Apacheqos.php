<?php
namespace Apache\Controllers;

use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Apacheqos extends BaseController {
    public function __construct() {
    }

    public function index() {
        $CI =& get_instance();
        $action = $this->request->getGet('action') ?? '';

        if (!$CI->getAllowed('serverHttpd')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-apache", "/apache/apacheqos");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array(
            'FORM_GET' => $this->request->getGet(),
            'FORM_POST' => $this->request->getPost(),
            'AGENT' => $this->request->getUserAgent()
        ));
        $BxPage->setFormUrl("/apache/apacheqos");

        $errors = $BxPage->getErrors();
        $action = $this->request->getGet('action') ?? '';

        $System = $CI->getSystem();
        $modQos = $CI->cceClient->getObject("System", array(), "modQos");
        $rules = $this->loadRuleRows($CI, $modQos);

        $state = $this->loadState($modQos, $rules);

        $Profile_Choices = array(
            'conservative' => $i18n->get('modQosProfileConservative'),
            'balanced' => $i18n->get('modQosProfileBalanced'),
            'aggressive' => $i18n->get('modQosProfileAggressive'),
            'custom' => $i18n->get('modQosProfileCustom')
        );

        $Profile_Choices_Reverse = array_flip($Profile_Choices);

        if ($action === 'addRule') {
            return $this->renderRuleEditor($CI, $factory, $BxPage, $i18n, $System, $modQos, $state, $rules);
        }

        $currentPreview = $this->buildConfig($state, $rules);
        $status = $this->detectRuntimeStatus();
        $status['preview_ok'] = $status['config_ok'];
        $status['preview_output'] = $status['config_output'];

        $form_data = $BxPage->getGETPOST('POST');
        $raw_form_data = $this->request->getPost();
        $required_keys = array();
        $ignore_attributes = array("modQosStatus", "generatedConfigPreview");
        $attributes = array();

        if ((is_array($form_data)) && ($this->request->getPost(NULL, NULL, TRUE))) {
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);
            $errors = $BxPage->getErrors();
            bx_error_log("Apacheqos: action=" . ($action ?: 'save') . " form submission received.");

            // Delocalize $form_data['profile']:
            if ((isset($attributes['profile'])) && (!empty($attributes['profile']))) {
                if (isset($Profile_Choices_Reverse[$attributes['profile']])) {
                    $attributes['profile'] = $Profile_Choices_Reverse[$attributes['profile']];
                }
            }

            if ($action === 'applyPreset') {
                $state = $this->stateFromPost($attributes, $state, true, $raw_form_data);
                $rules = $this->rulesFromPost($attributes, $rules);
                $currentPreview = $this->buildConfig($state, $rules);
                $status['preview_ok'] = true;
                $status['preview_output'] = '';
            }
            else {
                $state = $this->stateFromPost($attributes, $state, false, $raw_form_data);
                $rules = $this->rulesFromPost($attributes, $rules);

                $errors = array_merge($errors, $this->validateState($state, $rules, $i18n));

                if (count($errors) == 0) {
                    $candidateConfig = $this->buildConfig($state, $rules);

                    if ($action === 'test') {
                        bx_error_log("Apacheqos: running config test only.");
                        $testResult = $this->testApacheConfig($candidateConfig);
                        $status['preview_ok'] = $testResult['ok'];
                        $status['preview_output'] = $testResult['output'];
                        if ($testResult['ok']) {
                            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosConfigValidationSuccess]]"), 'alert_green', 'info_about');
                            bx_error_log("Apacheqos: config test succeeded.");
                        }
                        else {
                            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosConfigValidationFailed]]") . '<br>&nbsp;');
                            bx_error_log("Apacheqos: config test failed: " . $testResult['output']);
                        }
                        $currentPreview = $candidateConfig;
                    }
                    else {
                        bx_error_log("Apacheqos: saving CODB state" . ($action === 'reload' ? " and requesting reload" : ""));
                        $saveState = $state;
                        $saveState['force_update'] = time();
                        if ($action === 'reload') {
                            $saveState['reload'] = time();
                        }
                        else {
                            $saveState['reload'] = $modQos['reload'] ?? '';
                        }
                        $saveState['rulesInitialized'] = 1;

                        $ruleErrors = $this->saveRuleRows($CI, $rules, $i18n);
                        if (count($ruleErrors)) {
                            $errors = array_merge($errors, $ruleErrors);
                        }

                        if (count($errors) == 0) {
                            $ok = $CI->cceClient->set($System['OID'], "modQos", $this->mapStateToCodb($saveState));
                            if (!$ok) {
                                $CCEerrors = $CI->cceClient->errors();
                                foreach ($CCEerrors as $object => $objData) {
                                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                                }
                                bx_error_log("Apacheqos: CODB save failed.");
                            }
                        }

                        if (count($errors) == 0) {
                            $BxPage->ReturnToThisPage($errors, "/apache/apacheqos");
                        }
                    }
                }
            }
        }

        if (!isset($Profile_Choices[$state['profile']])) {
            $state['profile'] = $this->determineProfile($state);
        }
        $isCustomProfile = ((string)$state['profile'] === 'custom');
        $generalAccess = $isCustomProfile ? 'rw' : 'r';
        $currentPreview = $this->buildConfig($state, $rules);
        $status['preview_ok'] = $status['preview_ok'] ?? $status['config_ok'];
        $status['preview_output'] = $status['preview_output'] ?? $status['config_output'];

        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_apache_qos');
        $page_module = 'base_sysmanage';
        $page_body = array();

        $BxPage->setExtraHeaders('
            <script type="text/javascript">
            function submitApacheThrottle(action) {
                var form = document.getElementById("waiting_overlay");
                if (!form && document.forms && document.forms.length) {
                    form = document.forms[0];
                }
                if (!form) {
                    return false;
                }
                form.action = "/apache/apacheqos?action=" + encodeURIComponent(action);
                if (form.onsubmit && !form.onsubmit()) {
                    return false;
                }
                if (top && top.code && top.code.info_show && document._form_wait) {
                    top.code.info_show(document._form_wait, "wait");
                }
                if (form._save) {
                    form._save.value = 1;
                }
                form.submit();
                return false;
            }
            document.addEventListener("DOMContentLoaded", function() {
                var profile = document.getElementById("profile");
                if (profile) {
                    profile.addEventListener("change", function() {
                        submitApacheThrottle("applyPreset");
                    });
                }
            });
            </script>');

        $defaultPage = "mainStatus";
        $generalPage = "generalLimits";
        $dynamicPage = "dynamicRequestProtection";
        $repeatPage = "repeatOffenderBlocking";
        $advancedPage = "advanced";

        $block = $factory->getPagedBlock("apacheRequestThrottling", array(
            $defaultPage,
            $generalPage,
            $dynamicPage,
            $repeatPage,
            $advancedPage
        ));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        //
        // Main status
        //

        $ffs = $factory->addBXDivider("AllowOverride_OptionsField", "");
        $block->addFormField($ffs, $factory->getLabel("AllowOverride_OptionsField", false), $defaultPage);

        $enabled = $factory->getBoolean("enabled", $state['enabled']);
        $block->addFormField($enabled, $factory->getLabel("modQosEnabled"), $defaultPage);

        $Profile_select = $factory->getMultiChoice("profile", array_values($Profile_Choices));
        $Profile_select->setSelected($Profile_Choices[$state['profile']], true);
        $block->addFormField($Profile_select, $factory->getLabel("profile"), $defaultPage);

        $ffs = $factory->addBXDivider("mainStatusDivider", "");
        $block->addFormField($ffs, $factory->getLabel("mainStatusDivider", false), $defaultPage);

        $statusHtml = $this->renderStatusHtml($i18n, $status);
        $statusField = $factory->getRawHTML("modQosStatus", $statusHtml);
        $block->addFormField($statusField, $factory->getLabel("modQosStatus"), $defaultPage);

        //
        // General limits
        //

        $ffs = $factory->addBXDivider("generalLimitsDivider", "");
        $block->addFormField($ffs, $factory->getLabel("generalLimitsDivider", false), $generalPage);
        $BxPage->setLabel($generalPage, $i18n->get("generalLimits"), $i18n->get("generalLimits_help"));

        $clientEntries = $factory->getInteger("clientEntries", $state['clientEntries'], 1000, 1000000, $generalAccess);
        $clientEntries->setWidth(8);
        $clientEntries->showBounds(1);
        $block->addFormField($clientEntries, $factory->getLabel("clientEntries"), $generalPage);

        $srvMaxConnPerIP = $factory->getInteger("srvMaxConnPerIP", $state['srvMaxConnPerIP'], 1, 500, $generalAccess);
        $srvMaxConnPerIP->setWidth(8);
        $srvMaxConnPerIP->showBounds(1);
        $block->addFormField($srvMaxConnPerIP, $factory->getLabel("srvMaxConnPerIP"), $generalPage);

        $srvMaxConnBusyThreshold = $factory->getInteger("srvMaxConnBusyThreshold", $state['srvMaxConnBusyThreshold'], 0, 10000, $generalAccess);
        $srvMaxConnBusyThreshold->setWidth(8);
        $srvMaxConnBusyThreshold->showBounds(1);
        $block->addFormField($srvMaxConnBusyThreshold, $factory->getLabel("srvMaxConnBusyThreshold"), $generalPage);

        $minDataRate = $factory->getInteger("minDataRate", $state['minDataRate'], 1, 100000, $generalAccess);
        $minDataRate->setWidth(8);
        $minDataRate->showBounds(1);
        $block->addFormField($minDataRate, $factory->getLabel("minDataRate"), $generalPage);

        $maxDataRate = $factory->getInteger("maxDataRate", $state['maxDataRate'], 1, 100000, $generalAccess);
        $maxDataRate->setWidth(8);
        $maxDataRate->showBounds(1);
        $block->addFormField($maxDataRate, $factory->getLabel("maxDataRate"), $generalPage);

        $minDataRateBusyThreshold = $factory->getInteger("minDataRateBusyThreshold", $state['minDataRateBusyThreshold'], 0, 10000, $generalAccess);
        $minDataRateBusyThreshold->setWidth(8);
        $minDataRateBusyThreshold->showBounds(1);
        $block->addFormField($minDataRateBusyThreshold, $factory->getLabel("minDataRateBusyThreshold"), $generalPage);

        //
        // Dynamic request protection
        //

        $ffs = $factory->addBXDivider("dynamicRequestProtectionDivider", "");
        $block->addFormField($ffs, $factory->getLabel("dynamicRequestProtectionDivider", false), $dynamicPage);

        $dynamicEnabled = $factory->getBoolean("dynamicEnabled", $state['dynamicEnabled'], $generalAccess);
        $block->addFormField($dynamicEnabled, $factory->getLabel("dynamicEnabled"), $dynamicPage);

        $eventRequestLimit = $factory->getInteger("eventRequestLimit", $state['eventRequestLimit'], 1, 500, $generalAccess);
        $eventRequestLimit->setWidth(8);
        $eventRequestLimit->showBounds(1);
        $block->addFormField($eventRequestLimit, $factory->getLabel("eventRequestLimit"), $dynamicPage);

        $eventLimitCount = $factory->getInteger("eventLimitCount", $state['eventLimitCount'], 1, 100000, $generalAccess);
        $eventLimitCount->setWidth(8);
        $eventLimitCount->showBounds(1);
        $block->addFormField($eventLimitCount, $factory->getLabel("eventLimitCount"), $dynamicPage);

        $eventLimitSeconds = $factory->getInteger("eventLimitSeconds", $state['eventLimitSeconds'], 1, 86400, $generalAccess);
        $eventLimitSeconds->setWidth(8);
        $eventLimitSeconds->showBounds(1);
        $block->addFormField($eventLimitSeconds, $factory->getLabel("eventLimitSeconds"), $dynamicPage);

        $ffs = $factory->addBXDivider("dynamicRequestProtectionRulesDivider", "");
        $block->addFormField($ffs, $factory->getLabel("dynamicRequestProtectionRulesDivider", false), $dynamicPage);

        $rulesList = $factory->getScrollList("modQosRules", array(
            "modQosRule_enabled",
            "description",
            "regex",
            "weight",
            "eventRequest",
            "sortOrder",
            "delete"
        ), array());
        $rulesList->setAlignments(array("center", "left", "left", "center", "center", "center", "center"));
        $rulesList->setSortDisabled(array('0', '1', '2', '3', '4', '5', '6'));
        $rulesList->setPaginateDisabled(TRUE);
        $rulesList->setSearchDisabled(TRUE);
        $rulesList->setSelectorDisabled(TRUE);
        $rulesList->enableAutoWidth(FALSE);
        $rulesList->setInfoDisabled(TRUE);
        $rulesList->setColumnWidths(array("52", "220", "240", "70", "84", "72", "60"));
        $addRuleButton = $factory->getAddButton("/apache/apacheqos?action=addRule&DetailedTab=tabs-3#tabs-3");
        $rulesList->addButton($addRuleButton);

        foreach ($rules as $rule) {
            $rowId = $rule['rowId'];
            $this->setRuleFieldLabels($BxPage, $i18n, $rowId);
            $enabledField = $factory->getBoolean("modQosRule_enabled_$rowId", $rule['enabled'], $generalAccess);
            $enabledField->setLabelType("nolabel");
            $descriptionField = $factory->getTextField("modQosRule_description_$rowId", $rule['description'], $generalAccess);
            $descriptionField->setWidth(26);
            $descriptionField->setType("modQosText");
            $regexField = $factory->getTextField("modQosRule_regex_$rowId", $rule['regex'], $generalAccess);
            $regexField->setWidth(32);
            $regexField->setType("modQosText");
            $weightField = $factory->getInteger("modQosRule_weight_$rowId", $rule['weight'], 1, 100, $generalAccess);
            $weightField->setWidth(6);
            $weightField->showBounds(1);
            $eventRequestField = $factory->getBoolean("modQosRule_eventRequest_$rowId", $rule['eventRequest'], $generalAccess);
            $eventRequestField->setLabelType("nolabel");
            $sortOrderField = $factory->getInteger("modQosRule_sortOrder_$rowId", $rule['sortOrder'], 0, 1000, $generalAccess);
            $sortOrderField->setWidth(6);
            $sortOrderField->showBounds(1);
            $deleteField = $factory->getBoolean("modQosRule_delete_$rowId", 0);
            $deleteField->setLabelType("nolabel");

            $rulesList->addEntry(array(
                $enabledField,
                $descriptionField,
                $regexField,
                $weightField,
                $eventRequestField,
                $sortOrderField,
                $deleteField
            ), $rowId);
        }

        $block->addFormField($rulesList, $factory->getLabel("modQosRules"), $dynamicPage);

        //
        // Repeat offender blocking
        //

        $ffs = $factory->addBXDivider("repeatOffenderDivider", "");
        $block->addFormField($ffs, $factory->getLabel("repeatOffenderDivider", false), $repeatPage);

        $blockEnabled = $factory->getBoolean("blockEnabled", $state['blockEnabled'], $generalAccess);
        $block->addFormField($blockEnabled, $factory->getLabel("blockEnabled"), $repeatPage);

        $blockCount = $factory->getInteger("blockCount", $state['blockCount'], 1, 100000, $generalAccess);
        $blockCount->setWidth(8);
        $blockCount->showBounds(1);
        $block->addFormField($blockCount, $factory->getLabel("blockCount"), $repeatPage);

        $blockSeconds = $factory->getInteger("blockSeconds", $state['blockSeconds'], 1, 86400, $generalAccess);
        $blockSeconds->setWidth(8);
        $blockSeconds->showBounds(1);
        $block->addFormField($blockSeconds, $factory->getLabel("blockSeconds"), $repeatPage);

        $this->addStatusWeightToggle($factory, $block, $repeatPage, $state, 'count400', 'weight400', $generalAccess);
        $this->addStatusWeightToggle($factory, $block, $repeatPage, $state, 'count403', 'weight403', $generalAccess);
        $this->addStatusWeightToggle($factory, $block, $repeatPage, $state, 'count404', 'weight404', $generalAccess);
        $this->addStatusWeightToggle($factory, $block, $repeatPage, $state, 'count408', 'weight408', $generalAccess);
        $this->addStatusWeightToggle($factory, $block, $repeatPage, $state, 'count500', 'weight500', $generalAccess);

        $countBrokenConnection = $factory->getBoolean("countBrokenConnection", $state['countBrokenConnection'], $generalAccess);
        $block->addFormField($countBrokenConnection, $factory->getLabel("countBrokenConnection"), $repeatPage);

        $countMinDataRate = $factory->getBoolean("countMinDataRate", $state['countMinDataRate'], $generalAccess);
        $block->addFormField($countMinDataRate, $factory->getLabel("countMinDataRate"), $repeatPage);

        $countMaxConnPerIP = $factory->getBoolean("countMaxConnPerIP", $state['countMaxConnPerIP'], $generalAccess);
        $block->addFormField($countMaxConnPerIP, $factory->getLabel("countMaxConnPerIP"), $repeatPage);

        //
        // Advanced
        //

        $ffs = $factory->addBXDivider("advancedDivider", "");
        $block->addFormField($ffs, $factory->getLabel("advancedDivider", false), $advancedPage);

        $extraDirectives = $factory->getTextBlock("extraDirectives", $state['extraDirectives'], $generalAccess);
        $extraDirectives->setOptional(true);
        $extraDirectives->setType("modQosMultiline");
        $extraDirectives->setHeight(8);
        $extraDirectives->setWidth(80);
        $extraDirectives->setWrap(TRUE);
        $block->addFormField($extraDirectives, $factory->getLabel("extraDirectives"), $advancedPage);

        $preview = $factory->getTextBlock("generatedConfigPreview", $currentPreview, 'r');
        $preview->setType("modQosMultiline");
        $preview->setHeight(18);
        $preview->setWidth(80);
        $preview->setWrap(TRUE);
        $block->addFormField($preview, $factory->getLabel("generatedConfigPreview"), $advancedPage);

        // Buttons
        $saveButton = $factory->getSaveButton("/apache/apacheqos?action=reload");
        $applyPresetButton = $factory->getButton("javascript:void(0);", "apply_preset");
        $applyPresetButton->setOnClick("submitApacheThrottle('applyPreset'); return false;");
        $testButton = $factory->getButton("javascript:void(0);", "test_configuration");
        $testButton->setOnClick("submitApacheThrottle('test'); return false;");

        $block->addButton($saveButton);
        $block->addButton($applyPresetButton);
        $block->addButton($testButton);

        $BxPage->setErrors($errors);
        $page_body[] = $block->toHtml();

        return $BxPage->render($page_module, $page_body);
    }

    private function renderRuleEditor($CI, $factory, $BxPage, $i18n, $System, $modQos, $state, $rules) {
        $errors = $BxPage->getErrors();
        $rule = $this->defaultRuleEditorState();

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            $form_data = $BxPage->FORM_POST;
            $rule = $this->ruleFromPost($form_data, $rule);
            $errors = array_merge($errors, $this->validateRule($rule, $i18n));

            if (count($errors) == 0) {
                $rule['sortOrder'] = (int)$rule['sortOrder'];
                $rule['weight'] = (int)$rule['weight'];
                $rule['enabled'] = (int)$rule['enabled'];
                $rule['eventRequest'] = (int)$rule['eventRequest'];
                $rule['delete'] = 0;
                $rule['oid'] = '';

                if (!$CI->cceClient->create('ModQosRule', array(
                    'enabled' => (int)$rule['enabled'],
                    'description' => $rule['description'],
                    'regex' => $rule['regex'],
                    'weight' => (int)$rule['weight'],
                    'eventRequest' => (int)$rule['eventRequest'],
                    'sortOrder' => (int)$rule['sortOrder']
                ))) {
                    foreach ($CI->cceClient->errors() as $object => $objData) {
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }
                    bx_error_log("Apacheqos: Failed to create ModQosRule from add form.");
                }
                else {
                    $saveState = $state;
                    $saveState['rulesInitialized'] = 1;
                    $saveState['force_update'] = time();
                    $saveState['reload'] = $modQos['reload'] ?? '';
                    $ok = $CI->cceClient->set($System['OID'], "modQos", $this->mapStateToCodb($saveState));
                    if (!$ok) {
                        foreach ($CI->cceClient->errors() as $object => $objData) {
                            $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                        }
                        bx_error_log("Apacheqos: Failed to mark rulesInitialized after creating rule.");
                    }
                }

                if (count($errors) == 0) {
                    $BxPage->ReturnToThisPage($errors, "/apache/apacheqos?DetailedTab=tabs-3#tabs-3");
                }
            }
        }

        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_apache_qos');
        $BxPage->setFormUrl("/apache/apacheqos?action=addRule");

        $page_module = 'base_sysmanage';
        $page_body = array();
        $block = $factory->getPagedBlock("apacheRequestThrottling", array("addRule"));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage("addRule");

        $ffs = $factory->addBXDivider("addRuleDivider", "");
        $block->addFormField($ffs, $factory->getLabel("addRuleDivider", false), "addRule");

        $enabled = $factory->getBoolean("modQosRule_enabled", $rule['enabled']);
        $enabled->setLabelType("nolabel");
        $block->addFormField($enabled, $factory->getLabel("modQosRule_enabled"), "addRule");

        $description = $factory->getTextField("modQosRule_description", $rule['description'], 'rw');
        $description->setWidth(32);
        $description->setType("modQosText");
        $block->addFormField($description, $factory->getLabel("modQosRule_description"), "addRule");

        $regex = $factory->getTextField("modQosRule_regex", $rule['regex'], 'rw');
        $regex->setWidth(38);
        $regex->setType("modQosText");
        $block->addFormField($regex, $factory->getLabel("modQosRule_regex"), "addRule");

        $weight = $factory->getInteger("modQosRule_weight", $rule['weight'], 1, 100);
        $weight->setWidth(8);
        $weight->showBounds(1);
        $block->addFormField($weight, $factory->getLabel("modQosRule_weight"), "addRule");

        $eventRequest = $factory->getBoolean("modQosRule_eventRequest", $rule['eventRequest']);
        $eventRequest->setLabelType("nolabel");
        $block->addFormField($eventRequest, $factory->getLabel("modQosRule_eventRequest"), "addRule");

        $sortOrder = $factory->getInteger("modQosRule_sortOrder", $rule['sortOrder'], 0, 1000);
        $sortOrder->setWidth(8);
        $sortOrder->showBounds(1);
        $block->addFormField($sortOrder, $factory->getLabel("modQosRule_sortOrder"), "addRule");

        $saveButton = $factory->getSaveButton($BxPage->getSubmitAction());
        $cancelButton = $factory->getCancelButton("/apache/apacheqos?DetailedTab=tabs-3#tabs-3");
        $block->addButton($saveButton);
        $block->addButton($cancelButton);

        $BxPage->setErrors($errors);
        $page_body[] = $block->toHtml();
        return $BxPage->render($page_module, $page_body);
    }

    private function loadState($modQos, $rules) {
        $state = $this->defaultState();

        if (is_array($modQos) && count($modQos)) {
            foreach ($state as $key => $value) {
                if (isset($modQos[$key])) {
                    $state[$key] = $modQos[$key];
                }
            }
        }

        if (!isset($state['rulesInitialized'])) {
            $state['rulesInitialized'] = 0;
        }

        return $state;
    }

    private function defaultState() {
        return array(
            'enabled' => 0,
            'profile' => 'balanced',
            'clientEntries' => 200000,
            'srvMaxConnPerIP' => 20,
            'srvMaxConnBusyThreshold' => 150,
            'minDataRate' => 120,
            'maxDataRate' => 1200,
            'minDataRateBusyThreshold' => 100,
            'dynamicEnabled' => 1,
            'eventRequestLimit' => 6,
            'eventLimitCount' => 80,
            'eventLimitSeconds' => 60,
            'blockEnabled' => 1,
            'blockCount' => 30,
            'blockSeconds' => 300,
            'count400' => 0,
            'count403' => 1,
            'count404' => 1,
            'count408' => 1,
            'weight400' => 1,
            'weight403' => 1,
            'weight404' => 1,
            'weight408' => 1,
            'count500' => 1,
            'weight500' => 5,
            'countBrokenConnection' => 1,
            'countMinDataRate' => 1,
            'countMaxConnPerIP' => 1,
            'extraDirectives' => '',
            'force_update' => 0,
            'reload' => 0,
            'rulesInitialized' => 0
        );
    }

    private function presetMap() {
        return array(
            'conservative' => array(
                'clientEntries' => 200000,
                'srvMaxConnPerIP' => 30,
                'srvMaxConnBusyThreshold' => 200,
                'minDataRate' => 100,
                'maxDataRate' => 1000,
                'minDataRateBusyThreshold' => 150,
                'eventRequestLimit' => 10,
                'eventLimitCount' => 120,
                'eventLimitSeconds' => 60,
                'blockCount' => 40,
                'blockSeconds' => 300,
                'dynamicEnabled' => 1,
                'blockEnabled' => 1,
                'count400' => 0,
                'count403' => 1,
                'count404' => 1,
                'count408' => 1,
                'count500' => 1,
                'countBrokenConnection' => 1,
                'countMinDataRate' => 1,
                'countMaxConnPerIP' => 1,
                'weight400' => 1,
                'weight403' => 1,
                'weight404' => 1,
                'weight408' => 1,
                'weight500' => 5
            ),
            'balanced' => array(
                'clientEntries' => 200000,
                'srvMaxConnPerIP' => 20,
                'srvMaxConnBusyThreshold' => 150,
                'minDataRate' => 120,
                'maxDataRate' => 1200,
                'minDataRateBusyThreshold' => 100,
                'eventRequestLimit' => 6,
                'eventLimitCount' => 80,
                'eventLimitSeconds' => 60,
                'blockCount' => 30,
                'blockSeconds' => 300,
                'dynamicEnabled' => 1,
                'blockEnabled' => 1,
                'count400' => 0,
                'count403' => 1,
                'count404' => 1,
                'count408' => 1,
                'count500' => 1,
                'countBrokenConnection' => 1,
                'countMinDataRate' => 1,
                'countMaxConnPerIP' => 1,
                'weight400' => 1,
                'weight403' => 1,
                'weight404' => 1,
                'weight408' => 1,
                'weight500' => 5
            ),
            'aggressive' => array(
                'clientEntries' => 200000,
                'srvMaxConnPerIP' => 12,
                'srvMaxConnBusyThreshold' => 100,
                'minDataRate' => 150,
                'maxDataRate' => 1500,
                'minDataRateBusyThreshold' => 75,
                'eventRequestLimit' => 4,
                'eventLimitCount' => 50,
                'eventLimitSeconds' => 60,
                'blockCount' => 15,
                'blockSeconds' => 300,
                'dynamicEnabled' => 1,
                'blockEnabled' => 1,
                'count400' => 0,
                'count403' => 1,
                'count404' => 1,
                'count408' => 1,
                'count500' => 1,
                'countBrokenConnection' => 1,
                'countMinDataRate' => 1,
                'countMaxConnPerIP' => 1,
                'weight400' => 1,
                'weight403' => 1,
                'weight404' => 1,
                'weight408' => 1,
                'weight500' => 5
            )
        );
    }

    private function presetBooleanMap() {
        return array(
            'dynamicEnabled' => 1,
            'blockEnabled' => 1,
            'count400' => 0,
            'count403' => 1,
            'count404' => 1,
            'count408' => 1,
            'count500' => 1,
            'countBrokenConnection' => 1,
            'countMinDataRate' => 1,
            'countMaxConnPerIP' => 1
        );
    }

    private function stateFromPost($form_data, $baseState, $applyPreset = false, $raw_form_data = array()) {
        $state = $baseState;

        foreach (array(
            'enabled',
            'clientEntries',
            'srvMaxConnPerIP',
            'srvMaxConnBusyThreshold',
            'minDataRate',
            'maxDataRate',
            'minDataRateBusyThreshold',
            'eventRequestLimit',
            'eventLimitCount',
            'eventLimitSeconds',
            'blockCount',
            'blockSeconds',
            'weight400',
            'weight403',
            'weight404',
            'weight408',
            'weight500'
        ) as $intKey) {
            if (isset($form_data[$intKey]) && $form_data[$intKey] !== '') {
                $state[$intKey] = (int)$form_data[$intKey];
            }
        }

        if (isset($raw_form_data['extraDirectives'])) {
            $state['extraDirectives'] = $this->normalizeNewlines($raw_form_data['extraDirectives']);
        }
        elseif (isset($form_data['extraDirectives'])) {
            $state['extraDirectives'] = $this->normalizeNewlines($form_data['extraDirectives']);
        }

        if (isset($form_data['profile'])) {
            $state['profile'] = $form_data['profile'];
        }

        if (isset($form_data['profile'])) {
            $profile = $form_data['profile'];
            $presets = $this->presetMap();
            if (isset($presets[$profile]) && $profile !== 'custom') {
                foreach ($presets[$profile] as $key => $value) {
                    $state[$key] = $value;
                }
                foreach ($this->presetBooleanMap() as $key => $value) {
                    $state[$key] = $value;
                }
                $state['profile'] = $profile;
            }
            elseif ($profile === 'custom') {
                $state['profile'] = 'custom';
            }
        }

        return $state;
    }

    private function rulesFromPost($form_data, $existingRules) {
        $rules = array();
        $seenRows = array();

        foreach ($existingRules as $rule) {
            $rowId = $rule['rowId'];
            $prefix = "modQosRule_";
            $row = $rule;
            if (isset($form_data[$prefix . 'enabled_' . $rowId])) {
                $row['enabled'] = 1;
            }
            if (array_key_exists($prefix . 'description_' . $rowId, $form_data)) {
                $row['description'] = trim($form_data[$prefix . 'description_' . $rowId]);
            }
            if (array_key_exists($prefix . 'regex_' . $rowId, $form_data)) {
                $row['regex'] = $this->normalizeNewlines($form_data[$prefix . 'regex_' . $rowId]);
            }
            if (array_key_exists($prefix . 'weight_' . $rowId, $form_data) && $form_data[$prefix . 'weight_' . $rowId] !== '') {
                $row['weight'] = (int)$form_data[$prefix . 'weight_' . $rowId];
            }
            if (isset($form_data[$prefix . 'eventRequest_' . $rowId])) {
                $row['eventRequest'] = 1;
            }
            if (array_key_exists($prefix . 'sortOrder_' . $rowId, $form_data) && $form_data[$prefix . 'sortOrder_' . $rowId] !== '') {
                $row['sortOrder'] = (int)$form_data[$prefix . 'sortOrder_' . $rowId];
            }
            if (isset($form_data[$prefix . 'delete_' . $rowId])) {
                $row['delete'] = 1;
            }
            $rules[] = $row;
            $seenRows[$rowId] = true;
        }

        $newDescription = trim($form_data['modQosRule_description_new'] ?? '');
        $newRegex = $this->normalizeNewlines($form_data['modQosRule_regex_new'] ?? '');
        $newWeight = isset($form_data['modQosRule_weight_new']) && $form_data['modQosRule_weight_new'] !== '' ? (int)$form_data['modQosRule_weight_new'] : 1;
        $newEventRequest = isset($form_data['modQosRule_eventRequest_new']) ? 1 : 0;
        $newSortOrder = isset($form_data['modQosRule_sortOrder_new']) && $form_data['modQosRule_sortOrder_new'] !== '' ? (int)$form_data['modQosRule_sortOrder_new'] : 100;
        $newEnabled = isset($form_data['modQosRule_enabled_new']) ? 1 : 0;
        $newDelete = isset($form_data['modQosRule_delete_new']) ? 1 : 0;

        if (($newDescription !== '') || ($newRegex !== '')) {
            $rules[] = array(
                'rowId' => 'new',
                'oid' => '',
                'enabled' => $newEnabled,
                'description' => $newDescription,
                'regex' => $newRegex,
                'weight' => $newWeight,
                'eventRequest' => $newEventRequest,
                'sortOrder' => $newSortOrder,
                'delete' => $newDelete
            );
        }

        if (isset($form_data['profile']) && $form_data['profile'] !== 'custom') {
            foreach ($rules as &$rule) {
                if (!empty($rule['rowId']) && preg_match('/^default_/', (string)$rule['rowId'])) {
                    $rule['enabled'] = 1;
                    $rule['eventRequest'] = 1;
                }
            }
            unset($rule);
        }

        usort($rules, function ($a, $b) {
            $aSort = (int)($a['sortOrder'] ?? 0);
            $bSort = (int)($b['sortOrder'] ?? 0);
            if ($aSort === $bSort) {
                return strcmp((string)($a['description'] ?? ''), (string)($b['description'] ?? ''));
            }
            return $aSort <=> $bSort;
        });

        return $rules;
    }

    private function loadRuleRows($CI, $modQos) {
        $rows = array();
        $ruleOids = $CI->cceClient->find('ModQosRule');

        foreach ($ruleOids as $oid) {
            $rule = $CI->cceClient->get($oid);
            if (!is_array($rule) || !count($rule)) {
                continue;
            }
            $rows[] = array(
                'rowId' => $oid,
                'oid' => $oid,
                'enabled' => (int)($rule['enabled'] ?? 0),
                'description' => (string)($rule['description'] ?? ''),
                'regex' => (string)($rule['regex'] ?? ''),
                'weight' => (int)($rule['weight'] ?? 1),
                'eventRequest' => (int)($rule['eventRequest'] ?? 0),
                'sortOrder' => (int)($rule['sortOrder'] ?? 10),
                'delete' => 0
            );
        }

        if (count($rows) === 0) {
            $rows = $this->defaultRuleRows();
        }

        usort($rows, function ($a, $b) {
            $aSort = (int)($a['sortOrder'] ?? 0);
            $bSort = (int)($b['sortOrder'] ?? 0);
            if ($aSort === $bSort) {
                return strcmp((string)($a['description'] ?? ''), (string)($b['description'] ?? ''));
            }
            return $aSort <=> $bSort;
        });

        return $rows;
    }

    private function defaultRuleRows() {
        return array(
            array(
                'rowId' => 'default_1',
                'oid' => '',
                'enabled' => 1,
                'description' => 'PHP requests',
                'regex' => '^/.*\.php($|\?)',
                'weight' => 1,
                'eventRequest' => 1,
                'sortOrder' => 10,
                'delete' => 0
            ),
            array(
                'rowId' => 'default_2',
                'oid' => '',
                'enabled' => 1,
                'description' => 'WordPress login/XMLRPC/AJAX',
                'regex' => '^/(wp-login\.php|xmlrpc\.php|wp-admin/admin-ajax\.php)',
                'weight' => 5,
                'eventRequest' => 1,
                'sortOrder' => 20,
                'delete' => 0
            )
        );
    }

    private function saveRuleRows($CI, $rules, $i18n) {
        $errors = array();
        foreach ($rules as $rule) {
            $row = array(
                'enabled' => (int)$rule['enabled'],
                'description' => $rule['description'],
                'regex' => $rule['regex'],
                'weight' => (int)$rule['weight'],
                'eventRequest' => (int)$rule['eventRequest'],
                'sortOrder' => (int)$rule['sortOrder']
            );

            if (!empty($rule['delete'])) {
                if (!empty($rule['oid'])) {
                    if (!$CI->cceClient->destroy($rule['oid'])) {
                        foreach ($CI->cceClient->errors() as $object => $objData) {
                            $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                        }
                        bx_error_log("Apacheqos: Failed to delete ModQosRule OID " . $rule['oid']);
                    }
                }
                continue;
            }

            if (!empty($rule['oid'])) {
                if (!$CI->cceClient->set($rule['oid'], '', $row)) {
                    foreach ($CI->cceClient->errors() as $object => $objData) {
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }
                    bx_error_log("Apacheqos: Failed to update ModQosRule OID " . $rule['oid']);
                }
            }
            else {
                if (!$CI->cceClient->create('ModQosRule', $row)) {
                    foreach ($CI->cceClient->errors() as $object => $objData) {
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }
                    bx_error_log("Apacheqos: Failed to create ModQosRule.");
                }
            }
        }

        return $errors;
    }

    private function defaultRuleEditorState() {
        return array(
            'enabled' => 1,
            'description' => '',
            'regex' => '',
            'weight' => 1,
            'eventRequest' => 1,
            'sortOrder' => 100,
            'delete' => 0,
            'oid' => ''
        );
    }

    private function ruleFromPost($form_data, $baseRule) {
        $rule = $baseRule;
        $rule['enabled'] = isset($form_data['modQosRule_enabled']) ? 1 : 0;
        $rule['description'] = isset($form_data['modQosRule_description']) ? trim($form_data['modQosRule_description']) : '';
        $rule['regex'] = isset($form_data['modQosRule_regex']) ? $this->normalizeNewlines($form_data['modQosRule_regex']) : '';
        $rule['weight'] = isset($form_data['modQosRule_weight']) && $form_data['modQosRule_weight'] !== '' ? (int)$form_data['modQosRule_weight'] : 1;
        $rule['eventRequest'] = isset($form_data['modQosRule_eventRequest']) ? 1 : 0;
        $rule['sortOrder'] = isset($form_data['modQosRule_sortOrder']) && $form_data['modQosRule_sortOrder'] !== '' ? (int)$form_data['modQosRule_sortOrder'] : 100;
        return $rule;
    }

    private function validateRule($rule, $i18n) {
        $errors = array();

        if ($rule['description'] === '') {
            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosRuleDescriptionRequired]]") . '<br>&nbsp;');
        }
        if ($rule['regex'] === '') {
            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosRuleRegexRequired]]") . '<br>&nbsp;');
        }
        if (($rule['regex'] !== '') && preg_match("/[\r\n]/", $rule['regex'])) {
            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosRegexNoNewlines]]") . '<br>&nbsp;');
        }
        if (($rule['regex'] !== '') && preg_match('/["]/', $rule['regex'])) {
            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosRegexInvalidChar]]") . '<br>&nbsp;');
        }
        if ($rule['description'] !== '' && preg_match("/[\r\n]/", $rule['description'])) {
            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosDescriptionNoNewlines]]") . '<br>&nbsp;');
        }
        if ((int)$rule['weight'] < 1 || (int)$rule['weight'] > 100) {
            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosNumericInvalid]]") . '<br>&nbsp;');
        }
        if ((int)$rule['sortOrder'] < 0 || (int)$rule['sortOrder'] > 1000) {
            $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosNumericInvalid]]") . '<br>&nbsp;');
        }

        return $errors;
    }

    private function setRuleFieldLabels($BxPage, $i18n, $rowId) {
        $BxPage->setLabel("modQosRule_enabled_$rowId", $i18n->get("modQosRule_enabled"), $i18n->get("modQosRule_enabled_help"));
        $BxPage->setLabel("modQosRule_description_$rowId", $i18n->get("modQosRule_description"), $i18n->get("modQosRule_description_help"));
        $BxPage->setLabel("modQosRule_regex_$rowId", $i18n->get("modQosRule_regex"), $i18n->get("modQosRule_regex_help"));
        $BxPage->setLabel("modQosRule_weight_$rowId", $i18n->get("modQosRule_weight"), $i18n->get("modQosRule_weight_help"));
        $BxPage->setLabel("modQosRule_eventRequest_$rowId", $i18n->get("modQosRule_eventRequest"), $i18n->get("modQosRule_eventRequest_help"));
        $BxPage->setLabel("modQosRule_sortOrder_$rowId", $i18n->get("modQosRule_sortOrder"), $i18n->get("modQosRule_sortOrder_help"));
        $BxPage->setLabel("modQosRule_delete_$rowId", $i18n->get("modQosRule_delete"), $i18n->get("modQosRule_delete_help"));
    }

    private function addStatusWeightToggle($factory, $block, $page, $state, $name, $weightName, $generalAccess) {
        $multichoice = $factory->getMultiChoice($name);

        $enabler = $factory->getOption($name, $state[$name], $generalAccess);
        $label = $factory->getLabel($name);
        $enabler->setLabel($label);
        $multichoice->addOption($enabler);

        $weight = $factory->getInteger($weightName, $state[$weightName], 1, 100, $generalAccess);
        $weight->setWidth(8);
        $weight->showBounds(1);
        $enabler->addFormField($weight, $factory->getLabel($weightName));

        $block->addFormField($multichoice, $factory->getLabel($name), $page);
    }

    private function mapStateToCodb($state) {
        return array(
            'enabled' => (int)$state['enabled'],
            'profile' => $state['profile'],
            'clientEntries' => (int)$state['clientEntries'],
            'srvMaxConnPerIP' => (int)$state['srvMaxConnPerIP'],
            'srvMaxConnBusyThreshold' => (int)$state['srvMaxConnBusyThreshold'],
            'minDataRate' => (int)$state['minDataRate'],
            'maxDataRate' => (int)$state['maxDataRate'],
            'minDataRateBusyThreshold' => (int)$state['minDataRateBusyThreshold'],
            'dynamicEnabled' => (int)$state['dynamicEnabled'],
            'eventRequestLimit' => (int)$state['eventRequestLimit'],
            'eventLimitCount' => (int)$state['eventLimitCount'],
            'eventLimitSeconds' => (int)$state['eventLimitSeconds'],
            'blockEnabled' => (int)$state['blockEnabled'],
            'blockCount' => (int)$state['blockCount'],
            'blockSeconds' => (int)$state['blockSeconds'],
            'count400' => (int)$state['count400'],
            'count403' => (int)$state['count403'],
            'count404' => (int)$state['count404'],
            'count408' => (int)$state['count408'],
            'weight400' => (int)$state['weight400'],
            'weight403' => (int)$state['weight403'],
            'weight404' => (int)$state['weight404'],
            'weight408' => (int)$state['weight408'],
            'count500' => (int)$state['count500'],
            'weight500' => (int)$state['weight500'],
            'countBrokenConnection' => (int)$state['countBrokenConnection'],
            'countMinDataRate' => (int)$state['countMinDataRate'],
            'countMaxConnPerIP' => (int)$state['countMaxConnPerIP'],
            'extraDirectives' => $state['extraDirectives'],
            'rulesInitialized' => (int)$state['rulesInitialized'],
            'force_update' => $state['force_update'] ?? 0,
            'reload' => $state['reload'] ?? 0
        );
    }

    private function determineProfile($state) {
        $presets = $this->presetMap();
        $compareKeys = array(
            'clientEntries',
            'srvMaxConnPerIP',
            'srvMaxConnBusyThreshold',
            'minDataRate',
            'maxDataRate',
            'minDataRateBusyThreshold',
            'eventRequestLimit',
            'eventLimitCount',
            'eventLimitSeconds',
            'blockCount',
            'blockSeconds',
            'count400',
            'count403',
            'count404',
            'count408',
            'count500',
            'countBrokenConnection',
            'countMinDataRate',
            'countMaxConnPerIP',
            'weight400',
            'weight403',
            'weight404',
            'weight408',
            'weight500',
            'dynamicEnabled',
            'blockEnabled'
        );

        foreach ($presets as $profile => $values) {
            $matches = true;
            foreach ($compareKeys as $key) {
                if ((string)($state[$key] ?? '') !== (string)($values[$key] ?? '')) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $profile;
            }
        }

        return 'custom';
    }

    private function validateState($state, $rules, $i18n) {
        $errors = array();

        $numericRanges = array(
            'clientEntries' => array(1000, 1000000),
            'srvMaxConnPerIP' => array(1, 500),
            'srvMaxConnBusyThreshold' => array(0, 10000),
            'minDataRate' => array(1, 100000),
            'maxDataRate' => array(1, 100000),
            'minDataRateBusyThreshold' => array(0, 10000),
            'eventRequestLimit' => array(1, 500),
            'eventLimitCount' => array(1, 100000),
            'eventLimitSeconds' => array(1, 86400),
            'blockCount' => array(1, 100000),
            'blockSeconds' => array(1, 86400),
            'weight400' => array(1, 100),
            'weight403' => array(1, 100),
            'weight404' => array(1, 100),
            'weight408' => array(1, 100),
            'weight500' => array(1, 100)
        );

        foreach ($numericRanges as $field => $range) {
            $value = (int)($state[$field] ?? 0);
            if ($value < $range[0] || $value > $range[1]) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosNumericInvalid]]") . '<br>&nbsp;');
            }
        }

        foreach ($rules as $rule) {
            if ($rule['regex'] !== '' && preg_match("/[\r\n]/", $rule['regex'])) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosRegexNoNewlines]]") . '<br>&nbsp;');
            }
            if ($rule['regex'] !== '' && preg_match('/["]/', $rule['regex'])) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosRegexInvalidChar]]") . '<br>&nbsp;');
            }
            if (($rule['description'] !== '') && preg_match("/[\r\n]/", $rule['description'])) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosDescriptionNoNewlines]]") . '<br>&nbsp;');
            }
        }

        $extraDirectives = $state['extraDirectives'] ?? '';
        if ($extraDirectives !== '') {
            $lines = preg_split("/\r?\n/", $extraDirectives);
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                if ($this->isDangerousDirectiveLine($line)) {
                    $errors[] = ErrorMessage($i18n->get("[[base-apache.modQosDangerousDirective]]") . '<br>&nbsp;');
                    break;
                }
            }
        }

        return $errors;
    }

    private function isDangerousDirectiveLine($line) {
        return (bool)preg_match('/[`]|;|\$\(|&&|\|\|/', $line);
    }

    private function buildConfig($state, $rules) {
        if (!(int)$state['enabled']) {
            return "# Managed by BlueOnyx. Apache request throttling disabled.\n";
        }

        $out = array();
        $out[] = "# Managed by BlueOnyx. Manual changes inside this file may be overwritten.";
        $out[] = "<IfModule qos_module>";
        $out[] = "    QS_ClientEntries " . (int)$state['clientEntries'];
        $out[] = "    QS_SrvMaxConnPerIP " . (int)$state['srvMaxConnPerIP'] . " " . (int)$state['srvMaxConnBusyThreshold'];
        $out[] = "    QS_SrvMinDataRate " . (int)$state['minDataRate'] . " " . (int)$state['maxDataRate'] . " " . (int)$state['minDataRateBusyThreshold'];
        $out[] = "";

        if ((int)$state['dynamicEnabled']) {
            foreach ($rules as $rule) {
                if (!(int)$rule['enabled']) {
                    continue;
                }
                $regex = $rule['regex'];
                $weight = (int)$rule['weight'];
                $out[] = '    SetEnvIf Request_URI "' . $regex . '" QS_Limit=' . $weight;
                if ((int)$rule['eventRequest']) {
                    $out[] = '    SetEnvIf Request_URI "' . $regex . '" QS_EventRequest=1';
                }
                $out[] = "";
            }
            $out[] = "    QS_ClientEventRequestLimit " . (int)$state['eventRequestLimit'];
            $out[] = "    QS_ClientEventLimitCount " . (int)$state['eventLimitCount'] . " " . (int)$state['eventLimitSeconds'] . " QS_Limit";
            $out[] = "";
        }

        if ((int)$state['blockEnabled']) {
            $out[] = "    QS_ClientEventBlockCount " . (int)$state['blockCount'] . " " . (int)$state['blockSeconds'];
            $out[] = "";
            if ((int)$state['count400']) {
                $out[] = "    QS_SetEnvIfStatus 400 QS_Block=" . (int)$state['weight400'];
            }
            if ((int)$state['count403']) {
                $out[] = "    QS_SetEnvIfStatus 403 QS_Block=" . (int)$state['weight403'];
            }
            if ((int)$state['count404']) {
                $out[] = "    QS_SetEnvIfStatus 404 QS_Block=" . (int)$state['weight404'];
            }
            if ((int)$state['count408']) {
                $out[] = "    QS_SetEnvIfStatus 408 QS_Block=" . (int)$state['weight408'];
            }
            if ((int)$state['count500']) {
                $out[] = "    QS_SetEnvIfStatus 500 QS_Block=" . (int)$state['weight500'];
            }
            if ((int)$state['countBrokenConnection']) {
                $out[] = "    QS_SetEnvIfStatus BrokenConnection QS_Block";
            }
            if ((int)$state['countMinDataRate']) {
                $out[] = "    QS_SetEnvIfStatus QS_SrvMinDataRate QS_Block";
            }
            if ((int)$state['countMaxConnPerIP']) {
                $out[] = "    QS_SetEnvIfStatus QS_SrvMaxConnPerIP QS_Block";
            }
        }

        $extra = trim((string)($state['extraDirectives'] ?? ''));
        if ($extra !== '') {
            $out[] = "";
            $extraLines = preg_split("/\r?\n/", $extra);
            foreach ($extraLines as $line) {
                if ($line === '') {
                    $out[] = "";
                }
                else {
                    $out[] = "    " . rtrim($line);
                }
            }
        }

        $out[] = "</IfModule>";

        return implode("\n", $out) . "\n";
    }

    private function renderStatusHtml($i18n, $status) {
        $installed = $status['installed']
            ? $i18n->get('modQosInstalled')
            : $i18n->get('modQosMissing');
        $loaded = $status['loaded']
            ? $i18n->get('modQosLoaded')
            : $i18n->get('modQosNotLoaded');
        $config = $status['config_ok']
            ? $i18n->get('apacheConfigTestOK')
            : $i18n->get('apacheConfigTestFailed');
        $configDetail = '';
        if (!$status['config_ok'] && !empty($status['config_output'])) {
            $configDetail = '<pre style="white-space: pre-wrap; margin: 10px 0 0 0;">' . htmlspecialchars($status['config_output'], ENT_QUOTES, 'UTF-8') . '</pre>';
        }
        return '<div class="modqos-status">'
            . '<div><strong>' . $i18n->get('modQosInstalled') . ':</strong> ' . htmlspecialchars($installed, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><strong>' . $i18n->get('modQosLoaded') . ':</strong> ' . htmlspecialchars($loaded, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><strong>' . $i18n->get('apacheConfigTestStatus') . ':</strong> ' . htmlspecialchars($config, ENT_QUOTES, 'UTF-8') . '</div>'
            . $configDetail
            . '</div>';
    }

    private function detectRuntimeStatus() {
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $status = array(
            'installed' => false,
            'loaded' => false,
            'config_ok' => false,
            'config_output' => '',
            'preview_ok' => false,
            'preview_output' => ''
        );

        $rpmOutput = array();
        $rpmRc = 1;
        exec('/usr/bin/rpm -q mod_qos 2>&1', $rpmOutput, $rpmRc);
        $status['installed'] = ($rpmRc === 0);

        $moduleOutput = array();
        $moduleRc = 1;
        exec('/usr/sbin/httpd -M 2>&1', $moduleOutput, $moduleRc);
        $status['loaded'] = (preg_grep('/qos_module/', $moduleOutput) ? true : false);

        $testOutput = '';
        $testRc = $CI->serverScriptHelper->shell('/usr/sbin/httpd -t 2>&1', $testOutput, 'root', $BX_SESSION['sessionId']);
        $status['config_ok'] = ($testRc === 0);
        $status['config_output'] = trim($testOutput);
        bx_error_log("Apacheqos: runtime status installed=" . ($status['installed'] ? '1' : '0') . " loaded=" . ($status['loaded'] ? '1' : '0') . " config_ok=" . ($status['config_ok'] ? '1' : '0'));

        return $status;
    }

    private function testApacheConfig($config) {
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $confdir = '/tmp';
        $conf = $confdir . '/00-mod_qos.conf';
        $tmp = tempnam($confdir, 'modqos.');
        $bak = $conf . '.bak';
        $result = array('ok' => false, 'output' => '');

        if ($tmp === false) {
            bx_error_log("Apacheqos: unable to create temporary file for config test.");
            return array('ok' => false, 'output' => 'Unable to create temporary file.');
        }

        file_put_contents($tmp, $config);

        $hadBackup = false;
        if (is_file('/etc/httpd/conf.d/00-mod_qos.conf')) {
            $conf = '/etc/httpd/conf.d/00-mod_qos.conf';
            $backupOutput = '';
            $backupRc = $CI->serverScriptHelper->shell('/bin/cp ' . escapeshellarg($conf) . ' ' . escapeshellarg($bak) . ' 2>&1', $backupOutput, 'root', $BX_SESSION['sessionId']);
            if ($backupRc !== 0) {
                @unlink($tmp);
                bx_error_log("Apacheqos: unable to create backup file for config test.");
                return array('ok' => false, 'output' => 'Unable to create backup file.');
            }
            $hadBackup = true;
        }
        else {
            $conf = '/etc/httpd/conf.d/00-mod_qos.conf';
        }

        $stageOutput = '';
        $stageRc = $CI->serverScriptHelper->shell('/bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($conf) . ' 2>&1', $stageOutput, 'root', $BX_SESSION['sessionId']);
        if ($stageRc !== 0) {
            @unlink($tmp);
            bx_error_log("Apacheqos: unable to stage candidate config for test.");
            return array('ok' => false, 'output' => 'Unable to stage generated configuration.');
        }

        $output = '';
        $rc = $CI->serverScriptHelper->shell('/usr/sbin/httpd -t 2>&1', $output, 'root', $BX_SESSION['sessionId']);
        $result['ok'] = ($rc === 0);
        $result['output'] = trim($output);

        if ($hadBackup) {
            $restoreOutput = '';
            $restoreRc = $CI->serverScriptHelper->shell('/bin/cp ' . escapeshellarg($bak) . ' ' . escapeshellarg($conf) . ' 2>&1', $restoreOutput, 'root', $BX_SESSION['sessionId']);
            if ($restoreRc === 0) {
                @unlink($bak);
            }
            else {
                bx_error_log("Apacheqos: unable to restore original config after test.");
            }
        }
        else {
            $removeOutput = '';
            $removeRc = $CI->serverScriptHelper->shell('/bin/rm -f ' . escapeshellarg($conf) . ' 2>&1', $removeOutput, 'root', $BX_SESSION['sessionId']);
            if ($removeRc !== 0) {
                bx_error_log("Apacheqos: unable to remove staged config after test.");
            }
        }

        @unlink($tmp);

        return $result;
    }

    private function normalizeNewlines($value) {
        return str_replace(array("\r\n", "\r"), "\n", (string)$value);
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
