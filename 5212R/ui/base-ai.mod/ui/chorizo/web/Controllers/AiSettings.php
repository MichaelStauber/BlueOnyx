<?php
/**
 * AiSettings - AI Configuration Controller
 * Manages AI provider, model, and general settings stored in CCEd.
 * Uses uifc2 form fields for proper input validation and styling.
 */
namespace Ai\Controllers;

use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("CceClient.php");
include_once("ServerScriptHelper.php");
use I18n;
use BxPage;
use CceClient;
use ServerScriptHelper;

class AiSettings extends BaseController
{
    public function index()
    {
        $CI = get_instance();

        if (!$CI->getAllowed('serverAdministrator')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ai", "/ai/settings");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        $errors = $BxPage->getErrors();


        //
        //-- Prepare data:
        //

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

        // Form fields that are required to have input:
        $required_keys = array('enabled');

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

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {
            $provider = $this->resolveProviderKey(isset($attributes['provider']) ? $attributes['provider'] : '', $i18n);
            if (!empty($attributes['enabled']) && $provider === 'local') {
                $capability = $this->getLocalInferenceCapability(isset($attributes['model']) ? $attributes['model'] : '');
                if (empty($capability['available'])) {
                    $reason = !empty($capability['reason']) ? $capability['reason'] : $i18n->get('[[base-ai.local_provider_unknown_error]]');
                    $message = $i18n->get('[[base-ai.local_provider_unavailable]]') . ' ' . $reason;
                    $errors[] = ErrorMessage(htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '<br>&nbsp;');
                }
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Reverse-map provider label back to key (MultiChoice sends the label)
            $providerLabels = array(
                $i18n->get('[[base-ai.provider_openai]]')     => 'openai',
                $i18n->get('[[base-ai.provider_openrouter]]') => 'openrouter',
                $i18n->get('[[base-ai.provider_ollama]]')     => 'ollama',
                $i18n->get('[[base-ai.provider_anthropic]]')  => 'anthropic',
                $i18n->get('[[base-ai.provider_custom]]')     => 'custom',
                $i18n->get('[[base-ai.provider_local]]')      => 'local',
            );
            $providerLabel = $attributes['provider'];
            $provider = isset($providerLabels[$providerLabel]) ? $providerLabels[$providerLabel] : 'local';
            $modelValue = $attributes['model'] ?: '';
            $AI_config = $CI->cceClient->get($System['OID'], "AI");
            $postedApiKeys = $this->getSubmittedProviderApiKeys();
            $resolvedApiKeys = $this->resolveProviderApiKeys($AI_config, $postedApiKeys, $provider);
            $providerApiFieldMap = $this->getProviderApiKeyFieldMap();

            $fields = array(
                'provider'        => $provider,
                'default_model'   => $modelValue,
                'custom_endpoint' => $attributes['custom_endpoint'] ?: '',
                'idle_timeout'    => (int)($attributes['idle_timeout'] ?: 5),
                'enabled'         => $attributes['enabled'] ? '1' : '0',
                'tools_enabled'   => $attributes['tools_enabled'] ? '1' : '0',
                'priv_tools_available' => $attributes['priv_tools_available'] ?: '',
                'force_update'    => time(),
            );

            foreach ($providerApiFieldMap as $providerKey => $fieldName) {
                if (!empty($resolvedApiKeys[$providerKey])) {
                    $fields[$fieldName] = $resolvedApiKeys[$providerKey];
                }
            }

            // System prompt
            $system_prompt = $attributes['system_prompt'];
            if ($system_prompt !== null) {
                $fields['system_prompt'] = $system_prompt;
            }

            // Clear stale model list on provider switch (prevents showing wrong provider's models)
            if ($provider === 'local' || $provider === 'anthropic') {
                $fields['models_cache'] = array();
            }

            // Scalarize Array:
            if (isset($fields['models_cache'])) {
                $fields['models_cache'] = $CI->cceClient->array_to_scalar($fields['models_cache']);
            }

            // Actual submit to CODB:
            $CI->cceClient->setObject("System", $fields, "AI");

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors);
        }

        // Load current config from CCEd
        $AI_config = $CI->cceClient->get($System['OID'], "AI");
        $currentProvider = !empty($AI_config['provider']) ? $AI_config['provider'] : 'local';
        $currentModel = !empty($AI_config['default_model']) ? $AI_config['default_model'] : (!empty($AI_config['model']) ? $AI_config['model'] : '');
        $probeLocalCapabilityForDisplay = ($currentProvider === 'local');
        $localCapability = $probeLocalCapabilityForDisplay ? $this->getLocalInferenceCapability($currentModel) : array();

        // Set Menu items:
        $BxPage->setVerticalMenu('base_programsPersonal');
        $BxPage->setVerticalMenuChild('base_admin_ai');
        $page_module = 'base_sysmanage';

        // Use a PagedBlock — single tab for now, but extensible
        $defaultPage = 'settings_title';
        $block = $factory->getPagedBlock('settings_title', array($defaultPage));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        // --- Divider: Provider Configuration ---
        $div_provider = $factory->addBXDivider('DIVIDER_PROVIDER', '');
        $block->addFormField(
            $div_provider,
            $factory->getLabel('DIVIDER_PROVIDER', false),
            $defaultPage
        );

        // --- Enabled toggle (Boolean checkbox) ---
        $enabled = $factory->getBoolean('enabled', !empty($AI_config['enabled']) ? $AI_config['enabled'] : '0');
        $block->addFormField(
            $enabled,
            $factory->getLabel('enabled'),
            $defaultPage
        );

        // --- Provider selector (MultiChoice dropdown) ---
        $providerLabels = array(
            'openai'     => $i18n->get('[[base-ai.provider_openai]]'),
            'openrouter' => $i18n->get('[[base-ai.provider_openrouter]]'),
            'ollama'     => $i18n->get('[[base-ai.provider_ollama]]'),
            'anthropic'  => $i18n->get('[[base-ai.provider_anthropic]]'),
            'custom'     => $i18n->get('[[base-ai.provider_custom]]'),
            'local'      => $i18n->get('[[base-ai.provider_local]]'),
        );
        if ($probeLocalCapabilityForDisplay && empty($localCapability['available'])) {
            $providerLabels['local'] .= ' (' . $i18n->get('[[base-ai.local_provider_unavailable_short]]') . ')';
        }
        $providerKeyByLabel = array_flip($providerLabels);

        $provider_select = $factory->getMultiChoice('provider', array_values($providerLabels));
        if (isset($providerLabels[$currentProvider])) {
            $provider_select->setSelected($providerLabels[$currentProvider], true);
        }
        $block->addFormField(
            $provider_select,
            $factory->getLabel('provider'),
            $defaultPage
        );

        $backend = !empty($localCapability['cpu_backend']) ? $localCapability['cpu_backend'] : 'runtime-selected';
        if ($probeLocalCapabilityForDisplay && !empty($localCapability['available'])) {
            $statusText = $i18n->get('[[base-ai.local_provider_status_ok]]') . ' ' . $backend;
            if (!empty($localCapability['warning'])) {
                $statusText .= ' ' . $localCapability['warning'];
            }
            $statusClass = 'alert alert-success';
        }
        else if ($probeLocalCapabilityForDisplay) {
            $reason = !empty($localCapability['reason']) ? $localCapability['reason'] : $i18n->get('[[base-ai.local_provider_unknown_error]]');
            $statusText = $i18n->get('[[base-ai.local_provider_unavailable]]') . ' ' . $reason;
            $statusClass = 'alert alert-warning';
        }
        else {
            $statusText = '';
            $statusClass = 'alert alert-info';
        }
        $localStatusHtml = '<div class="' . $statusClass . '">' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</div>';
        $localStatusStyle = $probeLocalCapabilityForDisplay ? '' : ' style="display:none"';
        $local_status = $factory->getHtmlField(
            'BlueOnyx_Info_Text',
            '<div id="local-provider-status-row"' . $localStatusStyle . '>' . $localStatusHtml . '</div>',
            'r'
        );
        $block->addFormField($local_status, $factory->getLabel('local_provider_status'), $defaultPage);

        // --- Model selector ---
        // Keep the initial value untouched if we already have a model for the active provider.
        // Discovery only runs when the provider changes or when the field is empty on load.
        $initialModels = $this->getInitialModelOptions($currentProvider, $currentModel);
        $model_select = $factory->getMultiChoice('model', array_values($initialModels));
        if ($currentModel !== '' && in_array($currentModel, $initialModels, true)) {
            $model_select->setSelected($currentModel, true);
        }
        else if (isset($initialModels[0]) && $initialModels[0] !== '') {
            $model_select->setSelected($initialModels[0], true);
        }
        $block->addFormField(
            $model_select,
            $factory->getLabel('model'),
            $defaultPage
        );

        // --- Custom Endpoint (TextField, optional) ---
        $custom_endpoint = $factory->getTextField('custom_endpoint', !empty($AI_config['custom_endpoint']) ? $AI_config['custom_endpoint'] : '', 'rw');
        $custom_endpoint->setOptional(true);
        $block->addFormField(
            $custom_endpoint,
            $factory->getLabel('custom_endpoint'),
            $defaultPage
        );

        // --- Idle Timeout (Integer min=1 max=30) ---
        $idle_timeout = $factory->getInteger('idle_timeout', !empty($AI_config['idle_timeout']) ? $AI_config['idle_timeout'] : 5, 1, 30);
        $idle_timeout->setWidth(3);
        $idle_timeout->showBounds(1);
        $block->addFormField(
            $idle_timeout,
            $factory->getLabel('idle_timeout'),
            $defaultPage
        );

        // --- Divider: Provider API Keys ---
        $div_keys = $factory->addBXDivider('DIVIDER_API_KEYS', '');
        $block->addFormField(
            $div_keys,
            $factory->getLabel('DIVIDER_API_KEYS', false),
            $defaultPage
        );

        $openai_api_key = $factory->getPassword('openai_api_key', '', false, 'rw', false);
        $openai_api_key->setOptional(true);
        $block->addFormField(
            $openai_api_key,
            $factory->getLabel('openai_api_key', false),
            $defaultPage
        );
        $BxPage->setLabel('openai_api_key', $i18n->get('[[base-ai.openai_api_key]]'), $this->formatApiKeyHint($this->getStoredApiKeyForDisplay($AI_config, 'openai'), $i18n));

        $openrouter_api_key = $factory->getPassword('openrouter_api_key', '', false, 'rw', false);
        $openrouter_api_key->setOptional(true);
        $block->addFormField(
            $openrouter_api_key,
            $factory->getLabel('openrouter_api_key', false),
            $defaultPage
        );
        $BxPage->setLabel('openrouter_api_key', $i18n->get('[[base-ai.openrouter_api_key]]'), $this->formatApiKeyHint($this->getStoredApiKeyForDisplay($AI_config, 'openrouter'), $i18n));

        $ollama_api_key = $factory->getPassword('ollama_api_key', '', false, 'rw', false);
        $ollama_api_key->setOptional(true);
        $block->addFormField(
            $ollama_api_key,
            $factory->getLabel('ollama_api_key', false),
            $defaultPage
        );
        $BxPage->setLabel('ollama_api_key', $i18n->get('[[base-ai.ollama_api_key]]'), $this->formatApiKeyHint($this->getStoredApiKeyForDisplay($AI_config, 'ollama'), $i18n));

        $custom_api_key = $factory->getPassword('custom_api_key', '', false, 'rw', false);
        $custom_api_key->setOptional(true);
        $block->addFormField(
            $custom_api_key,
            $factory->getLabel('custom_api_key', false),
            $defaultPage
        );
        $BxPage->setLabel('custom_api_key', $i18n->get('[[base-ai.custom_api_key]]'), $this->formatApiKeyHint($this->getStoredApiKeyForDisplay($AI_config, 'custom'), $i18n));

        // --- Divider: System Prompt ---
        $div_prompt = $factory->addBXDivider('DIVIDER_PROMPT', '');
        $block->addFormField(
            $div_prompt,
            $factory->getLabel('DIVIDER_PROMPT', false),
            $defaultPage
        );

        // --- System Prompt (multiline TextList) ---
        $system_prompt = $factory->getTextList('system_prompt', !empty($AI_config['system_prompt']) ? $AI_config['system_prompt'] : '', 'rw');
        $system_prompt->setOptional(true);
        $block->addFormField(
            $system_prompt,
            $factory->getLabel('system_prompt'),
            $defaultPage
        );

        // --- Divider: System Prompt ---
        $div_prompt = $factory->addBXDivider('EXEC_DIVIDER_PROMPT', '');
        $block->addFormField(
            $div_prompt,
            $factory->getLabel('EXEC_DIVIDER_PROMPT', false),
            $defaultPage
        );

        // --- Tools Enabled toggle ---
        $tools_enabled = $factory->getBoolean('tools_enabled', isset($AI_config['tools_enabled']) ? $AI_config['tools_enabled'] : '1');
        $block->addFormField(
            $tools_enabled,
            $factory->getLabel('tools_enabled'),
            $defaultPage
        );
        $BxPage->setLabel('tools_enabled', $i18n->get('[[base-ai.tools_enabled]]'), $i18n->get('[[base-ai.tools_enabled_help]]'));

        $read_only_tools_enabled = $factory->getBoolean('read_only_tools_enabled', isset($AI_config['read_only_tools_enabled']) ? $AI_config['read_only_tools_enabled'] : '1');
        $block->addFormField(
            $read_only_tools_enabled,
            $factory->getLabel('read_only_tools_enabled'),
            $defaultPage
        );
        $BxPage->setLabel(
            'read_only_tools_enabled',
            'Read Only tools enabled',
            'Allow the assistant to use safe read-only tools for logs, files, directory listings, search, and hash checks.'
        );

        $diagnostics_tools_enabled = $factory->getBoolean('diagnostics_tools_enabled', isset($AI_config['diagnostics_tools_enabled']) ? $AI_config['diagnostics_tools_enabled'] : '1');
        $block->addFormField(
            $diagnostics_tools_enabled,
            $factory->getLabel('diagnostics_tools_enabled'),
            $defaultPage
        );
        $BxPage->setLabel(
            'diagnostics_tools_enabled',
            'Diagnostics tools enabled',
            'Allow the assistant to use system information tools such as uname, service status, and journal queries.'
        );

        $actions_tools_enabled = $factory->getBoolean('actions_tools_enabled', isset($AI_config['actions_tools_enabled']) ? $AI_config['actions_tools_enabled'] : '1');
        $block->addFormField(
            $actions_tools_enabled,
            $factory->getLabel('actions_tools_enabled'),
            $defaultPage
        );
        $BxPage->setLabel(
            'actions_tools_enabled',
            'Actions tools enabled',
            'Allow confirmation-gated action tools such as service restarts and file writes.'
        );

        // --- Generic privileged command toggle ---
        $allow_generic_privileged_command = $factory->getBoolean('allow_generic_privileged_command', isset($AI_config['allow_generic_privileged_command']) ? $AI_config['allow_generic_privileged_command'] : '0');
        $block->addFormField(
            $allow_generic_privileged_command,
            $factory->getLabel('allow_generic_privileged_command'),
            $defaultPage
        );
        $BxPage->setLabel(
            'allow_generic_privileged_command',
            $i18n->get('[[base-ai.allow_generic_privileged_command]]'),
            $i18n->get('[[base-ai.allow_generic_privileged_command_help]]')
        );

        // --- Wrapper whitelist for the generic privileged command ---
        $priv_tools_available = $factory->getTextList('priv_tools_available', !empty($AI_config['priv_tools_available']) ? $AI_config['priv_tools_available'] : '', 'rw');
        $priv_tools_available->setOptional(true);
        $block->addFormField(
            $priv_tools_available,
            $factory->getLabel('priv_tools_available'),
            $defaultPage
        );
        $BxPage->setLabel(
            'priv_tools_available',
            $i18n->get('[[base-ai.priv_tools_available]]'),
            $i18n->get('[[base-ai.priv_tools_available_help]]')
        );

        // --- Buttons ---
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton('/ai/chat'));

        // --- Render ---
        $page_body = array();
        $page_body[] = $block->toHtml();

        // --- Inline JavaScript für Model-Discovery im Footer ---
        $providerMapJson = $this->encodeJsObject($providerKeyByLabel);
        $providerApiFieldMapJson = $this->encodeJsObject($this->getProviderApiKeyFieldMap());
        $localStatusCanDisplayJson = $probeLocalCapabilityForDisplay ? 'true' : 'false';

        $no_models_available = $i18n->get('[[base-ai.no_models_available]]');

        $BxPage->setExtraFooters(<<<JS
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                var providerSelect = document.getElementById("provider");
                var modelField = document.getElementById("model");
                var customEndpointField = document.getElementById("custom_endpoint");
                var providerMap = $providerMapJson;
                var providerApiFieldMap = $providerApiFieldMapJson;

                if (!providerSelect || !modelField) {
                    return;
                }

                function resolveProviderKey(rawValue) {
                    return providerMap[rawValue] || rawValue;
                }

                function getActiveApiKeyField() {
                    var providerKey = resolveProviderKey(providerSelect.value);
                    var fieldId = providerApiFieldMap[providerKey] || "";
                    return fieldId ? document.getElementById(fieldId) : null;
                }

                function refreshLocalStatusVisibility() {
                    var statusRow = document.getElementById("local-provider-status-row");
                    if (!statusRow) {
                        return;
                    }
                    if (!$localStatusCanDisplayJson) {
                        statusRow.style.display = "none";
                        return;
                    }
                    statusRow.style.display = resolveProviderKey(providerSelect.value) === "local" ? "" : "none";
                }

                function setLoadingState() {
                    while (modelField.options.length > 0) {
                        modelField.remove(0);
                    }
                    var loading = document.createElement("option");
                    loading.value = "";
                    loading.textContent = "Loading...";
                    loading.selected = true;
                    modelField.appendChild(loading);
                }

                function setModelOptions(models, preferredModel) {
                    while (modelField.options.length > 0) {
                        modelField.remove(0);
                    }

                    if (!models || !models.length) {
                        var empty = document.createElement("option");
                        empty.value = "";
                        empty.textContent = "$no_models_available";
                        empty.selected = true;
                        modelField.appendChild(empty);
                        return;
                    }

                    var selectedModel = preferredModel && models.indexOf(preferredModel) !== -1 ? preferredModel : models[0];

                    models.forEach(function(model) {
                        var opt = document.createElement("option");
                        opt.value = model;
                        opt.textContent = model;
                        if (model === selectedModel) {
                            opt.selected = true;
                        }
                        modelField.appendChild(opt);
                    });

                    modelField.value = selectedModel;
                }

                var discoveryTimer = null;
                function scheduleDiscovery() {
                    if (discoveryTimer) {
                        clearTimeout(discoveryTimer);
                    }
                    discoveryTimer = setTimeout(function() {
                        fetchModels(true);
                    }, 0);
                }

                function fetchModels(force) {
                    var providerValue = resolveProviderKey(providerSelect.value);
                    var currentModel = modelField.value;
                    var apiKeyField = getActiveApiKeyField();
                    var apiKey = apiKeyField ? apiKeyField.value : "";
                    var customEndpoint = customEndpointField ? customEndpointField.value : "";

                    if (!force && currentModel) {
                        return;
                    }

                    setLoadingState();

                    var params = new URLSearchParams();
                    params.append("provider", providerValue);
                    if (apiKey) {
                        params.append("api_key", apiKey);
                    }
                    if (customEndpoint) {
                        params.append("custom_endpoint", customEndpoint);
                    }

                    fetch("/ai/settings/get_models?" + params.toString(), {
                        method: "GET",
                        headers: {"X-Requested-With": "XMLHttpRequest"}
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data && data.success && data.models) {
                            setModelOptions(data.models, currentModel);
                            return;
                        }

                        setModelOptions([], "");
                    })
                    .catch(function() {
                        setModelOptions([], "");
                    });
                }

                providerSelect.addEventListener("change", function() {
                    refreshLocalStatusVisibility();
                    scheduleDiscovery();
                });

                if (typeof window.jQuery !== "undefined") {
                    window.jQuery(providerSelect).on("select2:select", function() {
                        refreshLocalStatusVisibility();
                        scheduleDiscovery();
                    });
                }

                ["openai_api_key", "openrouter_api_key", "ollama_api_key", "custom_api_key", "custom_endpoint"].forEach(function(fieldId) {
                    var field = document.getElementById(fieldId);
                    if (!field) {
                        return;
                    }
                    field.addEventListener("input", function() {
                        var activeField = getActiveApiKeyField();
                        var providerKey = resolveProviderKey(providerSelect.value);
                        if ((activeField && activeField.id === fieldId) || (fieldId === "custom_endpoint" && (providerKey === "ollama" || providerKey === "custom"))) {
                            scheduleDiscovery();
                        }
                    });
                    field.addEventListener("change", function() {
                        var activeField = getActiveApiKeyField();
                        var providerKey = resolveProviderKey(providerSelect.value);
                        if ((activeField && activeField.id === fieldId) || (fieldId === "custom_endpoint" && (providerKey === "ollama" || providerKey === "custom"))) {
                            scheduleDiscovery();
                        }
                    });
                });

                refreshLocalStatusVisibility();

                // On load, discover remote provider models even if a saved model is already present.
                // For local models, keep the file-backed list as rendered unless the user changes provider.
                if (!modelField.value || resolveProviderKey(providerSelect.value) !== "local") {
                    fetchModels(true);
                }
            });
            </script>
JS
        );
        // --- Ende Inline JavaScript im Footer ---

        return $BxPage->render($page_module, $page_body);
    }

    /**
     * Scan /usr/sausalito/ai/models/ for .gguf files.
     * Returns array of friendly model names.
     */
    private function scanLocalModels($modelDir)
    {
        $models = array();
        if (!is_dir($modelDir)) {
            return $models;
        }
        $files = glob($modelDir . '/*.gguf');
        if ($files === false) {
            return $models;
        }
        foreach ($files as $file) {
            $basename = basename($file);
            // Skip the default symlink (not a real file)
            if ($basename === 'default.gguf' && is_link($file)) {
                continue;
            }
            $models[] = $basename;
        }
        sort($models);
        return $models;
    }

    private function getLocalInferenceCapability($model = '')
    {
        $helper = '/home/ai/bin/blueonyx-llama-check';
        if (!is_executable($helper)) {
            return array('available' => false, 'reason' => 'The local inference capability helper is not installed.');
        }

        $command = escapeshellarg($helper);
        if ($model !== '') {
            $command .= ' --model ' . escapeshellarg(basename($model));
        }
        $output = array();
        $status = 1;
        exec($command . ' 2>/dev/null', $output, $status);
        $result = json_decode(implode("\n", $output), true);
        if (!is_array($result)) {
            return array('available' => false, 'reason' => 'The local inference capability check returned an invalid response.');
        }
        if ($status !== 0) {
            $result['available'] = false;
        }
        return $result;
    }

    /**
     * AJAX endpoint: Fetch available models for a given provider
     */
    public function get_models()
    {
        $CI = get_instance();

        if (!$CI->getAllowed('serverAdministrator')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ai", "/ai/settings");
        $i18n = $factory->getI18n();
        $AI_config = $CI->cceClient->get($CI->getSystem()['OID'], 'AI');

        $provider = $this->request->getPost('provider');
        if (!$provider) {
            $provider = $this->request->getGet('provider');
        }
        $api_key = $this->request->getPost('api_key');
        if ($api_key === null || $api_key === '') {
            $api_key = $this->request->getGet('api_key');
        }
        $custom_endpoint = $this->request->getPost('custom_endpoint');
        if ($custom_endpoint === null || $custom_endpoint === '') {
            $custom_endpoint = $this->request->getGet('custom_endpoint');
        }

        $provider = $this->resolveProviderKey($provider, $i18n);
        if (!$provider) {
            return $this->response->setJSON([
                'success' => false,
                'models'  => [],
                'error'   => $i18n->get('[[base-ai.no_provider_specified]]'),
            ]);
        }

        $models = $this->getModelsForProvider($provider, $api_key, $custom_endpoint, $AI_config);

        return $this->response->setJSON([
            'success' => true,
            'provider' => $provider,
            'models'   => $models,
        ]);
    }
    
    /**
     * Fetch models from self-hosted Ollama instance
     */
    private function fetch_ollama_models($endpoint, $api_key)
    {
        $url = rtrim($endpoint, '/') . '/api/tags';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                $api_key ? ["Authorization: Bearer $api_key"] : []
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $models = array();
        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['models'])) {
                foreach ($data['models'] as $model) {
                    $models[] = $model['name'];
                }
            }
        }
        return $models;
    }

    /**
     * Resolve a provider value coming from the UI back to the internal provider key.
     */
    private function resolveProviderKey($provider, $i18n = null)
    {
        $provider = trim((string)$provider);
        if ($provider === '') {
            return '';
        }

        $knownProviders = array('openai', 'openrouter', 'ollama', 'anthropic', 'custom', 'local');
        if (in_array($provider, $knownProviders, true)) {
            return $provider;
        }

        if ($i18n) {
            $providerLabels = array(
                $i18n->get('[[base-ai.provider_openai]]')     => 'openai',
                $i18n->get('[[base-ai.provider_openrouter]]') => 'openrouter',
                $i18n->get('[[base-ai.provider_ollama]]')     => 'ollama',
                $i18n->get('[[base-ai.provider_anthropic]]')  => 'anthropic',
                $i18n->get('[[base-ai.provider_custom]]')     => 'custom',
                $i18n->get('[[base-ai.provider_local]]')      => 'local',
                $i18n->get('[[base-ai.provider_local]]') . ' (' . $i18n->get('[[base-ai.local_provider_unavailable_short]]') . ')' => 'local',
            );
            if (isset($providerLabels[$provider])) {
                return $providerLabels[$provider];
            }
        }

        $normalized = strtolower(trim(str_replace(' ', '-', $provider)));
        return in_array($normalized, $knownProviders, true) ? $normalized : '';
    }

    /**
     * Build the initial model list for the current provider without forcing network discovery on load.
     */
    private function getInitialModelOptions($provider, $currentModel)
    {
        $provider = $this->resolveProviderKey($provider);
        $currentModel = trim((string)$currentModel);

        if ($provider === 'local') {
            $models = $this->scanLocalModels('/home/ai/models');
            if ($currentModel !== '' && !in_array($currentModel, $models, true)) {
                array_unshift($models, $currentModel);
            }
            return $models;
        }

        if ($currentModel !== '') {
            return array($currentModel);
        }

        return array();
    }

    /**
     * Fetch models for a provider when discovery is explicitly requested.
     */
    private function getModelsForProvider($provider, $api_key = '', $custom_endpoint = '', $AI_config = array())
    {
        $provider = $this->resolveProviderKey($provider);
        $api_key = trim((string)$api_key);
        $custom_endpoint = trim((string)$custom_endpoint);
        $AI_config = is_array($AI_config) ? $AI_config : array();

        switch ($provider) {
            case 'openai':
                return $this->fetch_openai_compatible_models('https://api.openai.com/v1/models', $this->getProviderApiKeyForDiscovery('openai', $api_key, $AI_config));

            case 'openrouter':
                return $this->fetch_openai_compatible_models('https://openrouter.ai/api/v1/models', $this->getProviderApiKeyForDiscovery('openrouter', $api_key, $AI_config));

            case 'custom':
                if ($custom_endpoint !== '') {
                    $openai_models = $this->fetch_openai_compatible_models(rtrim($custom_endpoint, '/') . '/v1/models', $this->getProviderApiKeyForDiscovery('custom', $api_key, $AI_config));
                    if (!empty($openai_models)) {
                        return $openai_models;
                    }

                    $ollama_models = $this->fetch_ollama_models($custom_endpoint, $this->getProviderApiKeyForDiscovery('custom', $api_key, $AI_config));
                    if (!empty($ollama_models)) {
                        return $ollama_models;
                    }
                }
                return array();

            case 'ollama':
                return $this->fetch_ollama_models(
                    'https://ollama.com',
                    $this->getProviderApiKeyForDiscovery('ollama', $api_key, $AI_config)
                );

            case 'local':
                return $this->scanLocalModels('/home/ai/models');

            case 'anthropic':
                $anthropic_key = $this->getProviderApiKeyForDiscovery('anthropic', $api_key, $AI_config);
                if ($anthropic_key !== '') {
                    return $this->fetch_anthropic_models($anthropic_key);
                }
                return array();
        }

        return array();
    }

    /**
     * Fetch models from an OpenAI-compatible API endpoint.
     */
    private function fetch_openai_compatible_models($url, $api_key)
    {
        $api_key = trim((string)$api_key);
        $headers = array('Content-Type: application/json');
        if ($api_key !== '') {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $models = array();
        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $model) {
                    if (isset($model['id'])) {
                        $models[] = $model['id'];
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Fetch models from Anthropic's API.
     */
    private function fetch_anthropic_models($api_key)
    {
        $api_key = trim((string)$api_key);
        if ($api_key === '') {
            return array();
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.anthropic.com/v1/models',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $models = array();
        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $model) {
                    if (isset($model['id'])) {
                        $models[] = $model['id'];
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Encode a PHP array for insertion into a JavaScript object literal.
     */
    private function encodeJsObject($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Safely read a config value from array or object storage.
     */
    private function getConfigValue($config, $key, $default = '')
    {
        if (is_array($config) && array_key_exists($key, $config)) {
            return $config[$key];
        }

        if (is_object($config) && isset($config->$key)) {
            return $config->$key;
        }

        return $default;
    }

    /**
     * Returns the mapped provider API-key field names.
     */
    private function getProviderApiKeyFieldMap()
    {
        return array(
            'openai' => 'openai_api_key',
            'openrouter' => 'openrouter_api_key',
            'ollama' => 'ollama_api_key',
            'custom' => 'custom_api_key',
        );
    }

    /**
     * Mask API keys for display in the GUI.
     */
    private function formatApiKeyHint($value, $i18n)
    {
        $value = trim((string)$value);
        if ($value === '') {
            $out = $i18n->get('[[base-ai.no_key_stored]]');
            return $out;
        }

        if (strlen($value) <= 4) {
            $out = $i18n->get('[[base-ai.key_stored]]');
            return $out;
        }

        $out = $i18n->get('[[base-ai.key_stored]]');
        return $out . substr($value, -4);
    }

    /**
     * Build the provider -> key map from persisted config for display.
     */
    private function getStoredProviderApiKeys($AI_config)
    {
        $keys = array(
            'openai' => trim((string)$this->getConfigValue($AI_config, 'openai_api_key', '')),
            'openrouter' => trim((string)$this->getConfigValue($AI_config, 'openrouter_api_key', '')),
            'ollama' => trim((string)$this->getConfigValue($AI_config, 'ollama_api_key', '')),
            'custom' => trim((string)$this->getConfigValue($AI_config, 'custom_api_key', '')),
        );

        return $keys;
    }

    /**
     * Return the best API key hint for display, including legacy fallback.
     */
    private function getStoredApiKeyForDisplay($AI_config, $provider)
    {
        $provider = $this->resolveProviderKey($provider);
        $storedKeys = $this->getStoredProviderApiKeys($AI_config);

        if ($provider !== '' && !empty($storedKeys[$provider])) {
            return $storedKeys[$provider];
        }

        return '';
    }

    /**
     * Return the API keys submitted with the form.
     */
    private function getSubmittedProviderApiKeys()
    {
        return array(
            'openai' => trim((string)$this->request->getPost('openai_api_key')),
            'openrouter' => trim((string)$this->request->getPost('openrouter_api_key')),
            'ollama' => trim((string)$this->request->getPost('ollama_api_key')),
            'custom' => trim((string)$this->request->getPost('custom_api_key')),
        );
    }

    /**
     * Resolve submitted and stored provider API keys into a single map.
     */
    private function resolveProviderApiKeys($AI_config, $postedApiKeys, $activeProvider)
    {
        $resolved = $this->getStoredProviderApiKeys($AI_config);
        $activeProvider = $this->resolveProviderKey($activeProvider);

        foreach ($postedApiKeys as $provider => $value) {
            if ($value !== '') {
                $resolved[$provider] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Resolve the API key to use for discovery for one provider.
     */
    private function getProviderApiKeyForDiscovery($provider, $requestApiKey = '', $AI_config = array())
    {
        $provider = $this->resolveProviderKey($provider);
        $requestApiKey = trim((string)$requestApiKey);
        if ($requestApiKey !== '') {
            return $requestApiKey;
        }

        $storedKeys = $this->getStoredProviderApiKeys($AI_config);
        if (!empty($storedKeys[$provider])) {
            return $storedKeys[$provider];
        }

        return '';
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
