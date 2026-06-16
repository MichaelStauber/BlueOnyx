<?php
namespace User\Controllers;

use App\Controllers\BaseController;
use App\Libraries\TwoFactorBackupCodes;
use App\Libraries\TwoFactorEncryption;
include_once("I18n.php");
use I18n;

class TwoFactorAdmin extends BaseController {

    public function __construct() {
    }

    public function index() {
        $CI =& get_instance();
        $group = $this->getRequestedGroup($this->request->getGet());
        $this->assertScopeAccess($group);

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/twoFactorAdmin");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        $errors = $BxPage->getErrors();
        $postData = $BxPage->getGETPOST('POST');

        if ((is_array($postData)) && ($this->request->getPost(NULL, NULL, TRUE))) {
            $errors = array_merge($errors, $this->savePolicy($group, $postData, $i18n, $BxPage));
            if (count($errors) === 0) {
                return redirect()->to($this->buildAdminUrl($group))->with('success', $i18n->get('[[base-user.twofactor_policy_saved]]'));
            }
        }

        $success = session()->getFlashdata('success');
        if (!empty($success)) {
            $errors[] = ErrorMessage($success, 'alert_green', 'info_about');
        }

        $error = session()->getFlashdata('error');
        if (!empty($error)) {
            $errors[] = ErrorMessage($error, 'alert_red', 'alarm_bell');
        }

        $scopeConfig = $this->getScopeConfig($group);
        $users = $this->getUsersWith2FA($group, $scopeConfig);

        $BxPage->setErrors($errors);
        $BxPage->setFormUrl($this->buildAdminUrl($group));
        if ($group === 'server') {
            $BxPage->setVerticalMenu('base_security');
            $BxPage->setVerticalMenuChild('base_twoFactorAdminServer');
            $page_module = 'base_sysmanage';
        }
        else {
            $BxPage->setVerticalMenu('base_siteadmin');
            $BxPage->setVerticalMenuChild('base_twoFactorAdmin');
            $page_module = 'base_sitemanage';
        }

        $defaultPage = 'pageID';
        $page_body = array();
        $block = $factory->getPagedBlock('twoFactorAdmin', array($defaultPage));
        $block->setLabel($factory->getLabel('twofactor_admin_title'));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        $this->setPageLabels($BxPage, $i18n);

        $policySummaryHtml = $this->buildPolicySummaryHtml($i18n, $group, $scopeConfig);
        $policySummaryField = $factory->getRawHTML('twoFactorPolicyIntro', $policySummaryHtml);
        $block->addFormField($policySummaryField, $factory->getLabel('spacer'), $defaultPage);

        $this->addPolicyFields($block, $factory, $i18n, $group, $scopeConfig, $defaultPage);

        $scrollList = $factory->getScrollList('twoFactorAdmin', $this->getScrollListLabels($group, $i18n));
        $scrollList->setAlignments($this->getScrollListAlignments($group));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('ascending');
        $scrollList->setSortDisabled(array((string) $this->getActionColumnIndex($group)));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);
        $scrollList->setColumnWidths($this->getScrollListWidths($group));

        foreach ($users as $userData) {
            $scrollListRow = $this->buildScrollListRow($factory, $userData, $group, $i18n);
            $scrollList->addEntry($scrollListRow);
        }

        $scrollListHtml = $scrollList->toHtml();
        $scrollListField = $factory->getRawHTML('twoFactorAdminList', $scrollListHtml);
        $block->addFormField($scrollListField, $factory->getLabel('spacer'), $defaultPage);
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton($this->buildAdminUrl($group)));
        $page_body[] = $block->toHtml();

        return $BxPage->render($page_module, $page_body);
    }

    public function reset() {
        $CI =& get_instance();
        $i18n = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/twoFactorAdmin")->getI18n();
        $getData = $this->request->getGet();
        $username = isset($getData['user']) ? $getData['user'] : '';
        $group = $this->getRequestedGroup($getData);
        $this->assertScopeAccess($group);

        if (empty($username)) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_no_user]]'));
        }

        $user = $CI->cceClient->getObject('User', array('name' => $username));
        if (!$user || !isset($user['OID'])) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_user_not_found]]'));
        }

        if (($group !== 'server') && (!isset($user['site']) || ($user['site'] !== $group))) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_user_wrong_site]]'));
        }

        $this->clearTwoFactorState($user['OID']);
        $this->removeSshTwoFactorArtifacts($user);

        return redirect()->to($this->buildAdminUrl($group))->with('success', $i18n->interpolate('[[base-user.twofactor_success_reset]]', array('user' => $username)));
    }

    public function unlock() {
        $CI =& get_instance();
        $i18n = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/twoFactorAdmin")->getI18n();
        $getData = $this->request->getGet();
        $username = isset($getData['user']) ? $getData['user'] : '';
        $group = $this->getRequestedGroup($getData);
        $this->assertScopeAccess($group);

        if (empty($username)) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_no_user]]'));
        }

        $user = $CI->cceClient->getObject('User', array('name' => $username));
        if (!$user || !isset($user['OID'])) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_user_not_found]]'));
        }

        if (($group !== 'server') && (!isset($user['site']) || ($user['site'] !== $group))) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_user_wrong_site]]'));
        }

        $CI->cceClient->set($user['OID'], 'TwoFactorAuth', array('failed_attempts' => 0, 'locked_until' => 0));
        return redirect()->to($this->buildAdminUrl($group))->with('success', $i18n->interpolate('[[base-user.twofactor_success_unlock]]', array('user' => $username)));
    }

    public function regenerateBackupCodes() {
        $CI =& get_instance();
        $i18n = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/twoFactorAdmin")->getI18n();
        $getData = $this->request->getGet();
        $username = isset($getData['user']) ? $getData['user'] : '';
        $group = $this->getRequestedGroup($getData);
        $this->assertScopeAccess($group);

        if (empty($username)) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_no_user]]'));
        }

        $user = $CI->cceClient->getObject('User', array('name' => $username));
        if (!$user || !isset($user['OID'])) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_user_not_found]]'));
        }

        if (($group !== 'server') && (!isset($user['site']) || ($user['site'] !== $group))) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_user_wrong_site]]'));
        }

        $tfa = $CI->cceClient->get($user['OID'], 'TwoFactorAuth');
        if (!$tfa || !isset($tfa['enabled']) || $tfa['enabled'] != '1') {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_not_enabled]]'));
        }

        if (!isset($tfa['secret_encrypted']) || $tfa['secret_encrypted'] === '') {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->get('[[base-user.twofactor_err_legacy_regen]]'));
        }

        try {
            $tfa = $this->ensurePerUserEncryption($user['OID'], $tfa);
            $backupManager = new TwoFactorBackupCodes();
            $encryption = new TwoFactorEncryption($tfa['encryption_key'] ?? null);
            $backupCodes = $backupManager->generateCodes();
            $encryptedBackupCodes = $encryption->encrypt($backupManager->serialize($backupCodes));
            $CI->cceClient->set($user['OID'], 'TwoFactorAuth', array('backup_codes' => $encryptedBackupCodes));
            $secret = $encryption->decrypt($tfa['secret_encrypted']);
            if (!empty($secret)) {
                $this->syncSshTwoFactorArtifacts($user, $secret, $backupCodes);
            }
            return redirect()->to($this->buildAdminUrl($group))->with('success', $i18n->interpolate('[[base-user.twofactor_success_regen]]', array('user' => $username)));
        }
        catch (\Throwable $e) {
            return redirect()->to($this->buildAdminUrl($group))->with('error', $i18n->interpolate('[[base-user.twofactor_err_regen_failed]]', array('user' => $username)));
        }
    }

    private function savePolicy($group, $formData, $i18n, $BxPage) {
        $CI =& get_instance();
        $errors = array();
        $attributes = GetFormAttributes($i18n, $formData, array(), array('BlueOnyx_Info_Text'), $BxPage);
        $errors = array_merge($errors, $BxPage->getErrors());
        if (count($errors) > 0) {
            return $errors;
        }

        if ($group === 'server') {
            $gui2faUsersValues = $this->getGui2faUsersValues($i18n);
            $gui2faUsersValuesFlipped = array_flip($gui2faUsersValues);
            $system = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
            $systemSSH = $CI->cceClient->get($system['OID'], 'SSH');
            $gui2fa = (isset($attributes['gui_2fa']) && ($attributes['gui_2fa'] == '1')) ? '1' : '0';
            $gui2faUsers = 'ALL';
            if (isset($attributes['gui_2fa_users']) && isset($gui2faUsersValuesFlipped[$attributes['gui_2fa_users']])) {
                $gui2faUsers = $gui2faUsersValuesFlipped[$attributes['gui_2fa_users']];
            }
            if (($systemSSH['GoogleAuthentication'] === '0') && ($gui2fa === '1')) {
                $CI->cceClient->set($system['OID'], 'SSH', array('GoogleAuthentication' => '1'));
            }
            $CI->cceClient->set($system['OID'], '', array('gui_2fa' => $gui2fa, 'gui_2fa_users' => $gui2faUsers));
        }
        else {
            $vsite = $CI->cceClient->getObject('Vsite', array('name' => $group));
            if (!$vsite || !isset($vsite['OID'])) {
                $errors[] = ErrorMessage($i18n->get('[[base-user.twofactor_err_site_not_found]]') . '<br>&nbsp;');
                return $errors;
            }
            $requiredValue = (isset($attributes['site_2fa_required']) && ($attributes['site_2fa_required'] == '1')) ? '1' : '0';
            $CI->cceClient->set($vsite['OID'], 'Shell', array('GoogleAuthentication' => $requiredValue));
        }

        $CCEerrors = $CI->cceClient->errors();
        foreach ($CCEerrors as $object => $objData) {
            $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
        }
        return $errors;
    }

    private function getScopeConfig($group) {
        $CI =& get_instance();
        $system = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        $systemSSH = $CI->cceClient->get($system['OID'], 'SSH');
        $config = array(
            'server_gui_2fa' => $system['gui_2fa'] ?? '0',
            'server_gui_2fa_users' => $system['gui_2fa_users'] ?? 'ALL',
            'server_ssh_2fa' => $systemSSH['GoogleAuthentication'] ?? '0',
            'site_gui_2fa' => '0',
        );
        if ($group !== 'server') {
            $siteShell = $CI->cceClient->getObject('Vsite', array('name' => $group), 'Shell');
            if ($siteShell && isset($siteShell['GoogleAuthentication'])) {
                $config['site_gui_2fa'] = $siteShell['GoogleAuthentication'];
            }
        }
        return $config;
    }

    private function addPolicyFields(&$block, $factory, $i18n, $group, $scopeConfig, $defaultPage) {
        if ($group === 'server') {
            $gui2faUsersValues = $this->getGui2faUsersValues($i18n);
            $gui2faProtection = $factory->getMultiChoice('gui_2fa');
            $enable = $factory->getOption('gui_2fa', $scopeConfig['server_gui_2fa'], 'rw');
            $gui2faProtection->addOption($enable);
            $gui2faUsersField = $factory->getMultiChoice('gui_2fa_users', array_values($gui2faUsersValues));
            if (isset($gui2faUsersValues[$scopeConfig['server_gui_2fa_users']])) {
                $gui2faUsersField->setSelected($gui2faUsersValues[$scopeConfig['server_gui_2fa_users']], true);
            }
            $gui2faUsersField->setOptional(false);
            $enable->addFormField($gui2faUsersField, $factory->getLabel('[[base-system.gui_2fa_users]]'));
            $block->addFormField($gui2faProtection, $factory->getLabel('[[base-system.gui_2fa]]'), $defaultPage);
        }
        else {
            $siteRequired = $factory->getBoolean('site_2fa_required', $scopeConfig['site_gui_2fa'], 'rw');
            $block->addFormField($siteRequired, $factory->getLabel('twofactor_vsite_policy'), $defaultPage);
        }
    }

    private function getUsersWith2FA($group, $scopeConfig) {
        $CI =& get_instance();
        $users = array();
        $siteDisplayCache = array();
        $exactMatch = array();
        if (($group !== null) && ($group !== '') && ($group !== 'server')) {
            $exactMatch['site'] = $group;
        }
        $allUsers = $CI->cceClient->find('User', $exactMatch);

        foreach ($allUsers as $oid) {
            $user = $CI->cceClient->get($oid);
            if (!$user || !isset($user['name']) || ($user['name'] === 'api-admin')) {
                continue;
            }

            $tfa = $CI->cceClient->get($oid, 'TwoFactorAuth');
            $required = $this->isUserRequired($user, $scopeConfig, $group);
            $userData = array(
                'username' => $user['name'],
                'fullname' => $user['fullName'] ?? $user['name'],
                'site' => $this->getSiteDisplayName($user['site'] ?? 'server', $siteDisplayCache),
                'enabled' => '0',
                'required' => $required,
                'type' => 'none',
                'locked' => false,
                'created_at' => 'N/A',
                'last_used' => 'N/A',
                'can_regenerate' => false
            );

            if ($tfa && isset($tfa['enabled']) && $tfa['enabled'] == '1') {
                $userData['enabled'] = '1';
                $userData['type'] = !empty($tfa['secret_encrypted']) ? 'modern' : 'legacy';
                $userData['can_regenerate'] = !empty($tfa['secret_encrypted']);
                $userData['created_at'] = $this->formatTimestamp($tfa['created_at'] ?? null, 'Unknown');
                $userData['last_used'] = $this->formatTimestamp($tfa['last_used'] ?? null, 'Never');
                $lockedUntil = $this->normalizeTimestamp($tfa['locked_until'] ?? null);
                if (($lockedUntil !== null) && ($lockedUntil > time())) {
                    $userData['locked'] = true;
                }
            }
            else {
                $homeDir = $this->getUserHomeDirectory($user['name']);
                if ($homeDir && file_exists("$homeDir/.google_authenticator")) {
                    $userData['enabled'] = '1';
                    $userData['type'] = 'legacy';
                }
            }

            $users[] = $userData;
        }
        return $users;
    }

    private function getSiteDisplayName($siteName, &$siteDisplayCache) {
        $siteName = $siteName ?: 'server';
        if ($siteName === 'server') {
            return 'server';
        }
        if (isset($siteDisplayCache[$siteName])) {
            return $siteDisplayCache[$siteName];
        }

        $CI =& get_instance();
        $vsite = $CI->cceClient->getObject('Vsite', array('name' => $siteName));
        if ($vsite && !empty($vsite['fqdn'])) {
            $siteDisplayCache[$siteName] = $vsite['fqdn'];
        }
        else {
            $siteDisplayCache[$siteName] = $siteName;
        }

        return $siteDisplayCache[$siteName];
    }

    private function isUserRequired($user, $scopeConfig, $group) {
        if (($group !== 'server') && ($scopeConfig['site_gui_2fa'] === '1')) {
            return true;
        }
        if (($scopeConfig['server_gui_2fa'] ?? '0') !== '1') {
            return false;
        }
        $policy = $scopeConfig['server_gui_2fa_users'] ?? 'ALL';
        if ($policy === 'ALL') {
            return true;
        }
        $capLevels = array();
        if (isset($user['capLevels'])) {
            $capLevels = array_filter(explode('&', $user['capLevels']));
        }
        if ($policy === 'ADMINS') {
            return (($user['name'] === 'admin') || in_array('adminUser', $capLevels));
        }
        if ($policy === 'PRIVILEGED') {
            return (($user['name'] === 'admin') || in_array('adminUser', $capLevels) || in_array('siteAdmin', $capLevels));
        }
        return false;
    }

    private function clearTwoFactorState($userOid) {
        $CI =& get_instance();
        $CI->cceClient->set($userOid, 'TwoFactorAuth', array(
            'enabled' => '0',
            'secret_encrypted' => '',
            'backup_codes' => '',
            'encryption_key' => TwoFactorEncryption::generateStorageKey(),
            'created_at' => 0,
            'last_used' => 0,
            'failed_attempts' => 0,
            'locked_until' => 0,
            'is_legacy' => '0'
        ));
    }

    private function ensurePerUserEncryption($userOid, $tfa) {
        if (!empty($tfa['encryption_key'])) {
            return $tfa;
        }

        if (empty($tfa['secret_encrypted'])) {
            $updates = array('encryption_key' => TwoFactorEncryption::generateStorageKey());
            $CI =& get_instance();
            $CI->cceClient->set($userOid, 'TwoFactorAuth', $updates);
            return array_merge($tfa, $updates);
        }

        $legacyEncryption = new TwoFactorEncryption();
        $secret = $legacyEncryption->decrypt($tfa['secret_encrypted']);
        if ($secret === null) {
            return $tfa;
        }

        $backupCodes = array();
        if (!empty($tfa['backup_codes'])) {
            $backupManager = new TwoFactorBackupCodes();
            $decoded = $legacyEncryption->decrypt($tfa['backup_codes']);
            if ($decoded) {
                $codes = $backupManager->deserialize($decoded);
                if (is_array($codes)) {
                    $backupCodes = $codes;
                }
            }
        }

        $storageKey = TwoFactorEncryption::generateStorageKey();
        $encryption = new TwoFactorEncryption($storageKey);
        $backupManager = new TwoFactorBackupCodes();
        $updates = array(
            'encryption_key' => $storageKey,
            'secret_encrypted' => $encryption->encrypt($secret),
            'backup_codes' => $encryption->encrypt($backupManager->serialize($backupCodes))
        );

        $CI =& get_instance();
        $CI->cceClient->set($userOid, 'TwoFactorAuth', $updates);
        return array_merge($tfa, $updates);
    }

    private function buildPolicySummaryHtml($i18n, $group, $scopeConfig) {
        if ($group === 'server') {
            $scope = $i18n->get('[[base-user.twofactor_scope_server]]');
            $policy = ($scopeConfig['server_gui_2fa'] === '1') ? $this->translateServerPolicy($i18n, $scopeConfig['server_gui_2fa_users']) : $i18n->get('[[base-user.twofactor_status_disabled]]');
            $desc = $i18n->interpolate('[[base-user.twofactor_scope_summary_server]]', array('scope' => $scope, 'policy' => $policy));
        }
        else {
            $policy = ($scopeConfig['site_gui_2fa'] === '1') ? $i18n->get('[[base-user.twofactor_vsite_required]]') : $i18n->get('[[base-user.twofactor_vsite_optional]]');
            $desc = $i18n->interpolate('[[base-user.twofactor_scope_summary_site]]', array('scope' => $group, 'policy' => $policy));
        }
        return '<div class="alert alert-info" role="alert"><i class="fa fa-shield"></i> ' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    private function translateServerPolicy($i18n, $policy) {
        if ($policy === 'ADMINS') {
            return $i18n->get('[[base-system.2FA_ADMINS]]');
        }
        if ($policy === 'PRIVILEGED') {
            return $i18n->get('[[base-system.2FA_PRIVILEGED]]');
        }
        return $i18n->get('[[base-system.2FA_ALL]]');
    }

    private function getGui2faUsersValues($i18n) {
        return array(
            'ALL' => $i18n->get('[[base-system.2FA_ALL]]'),
            'ADMINS' => $i18n->get('[[base-system.2FA_ADMINS]]'),
            'PRIVILEGED' => $i18n->get('[[base-system.2FA_PRIVILEGED]]'),
        );
    }

    private function getScrollListLabels($group, $i18n) {
        if ($group === 'server') {
            return array($i18n->get('[[base-user.twofactor_username]]'), $i18n->get('[[base-user.twofactor_full_name]]'), $i18n->get('[[base-user.twofactor_site]]'), $i18n->get('[[base-user.twofactor_status]]'), $i18n->get('[[base-user.twofactor_type]]'), $i18n->get('[[base-user.twofactor_created]]'), $i18n->get('[[base-user.twofactor_last_used]]'), $i18n->get('[[base-user.twofactor_actions]]'));
        }
        return array($i18n->get('[[base-user.twofactor_username]]'), $i18n->get('[[base-user.twofactor_full_name]]'), $i18n->get('[[base-user.twofactor_status]]'), $i18n->get('[[base-user.twofactor_type]]'), $i18n->get('[[base-user.twofactor_created]]'), $i18n->get('[[base-user.twofactor_last_used]]'), $i18n->get('[[base-user.twofactor_actions]]'));
    }

    private function getScrollListAlignments($group) {
        return ($group === 'server') ? array("left", "left", "left", "center", "center", "left", "left", "center") : array("left", "left", "center", "center", "left", "left", "center");
    }

    private function getScrollListWidths($group) {
        return ($group === 'server') ? array("15%", "18%", "12%", "12%", "11%", "12%", "12%", "8%") : array("18%", "20%", "14%", "14%", "14%", "14%", "6%");
    }

    private function getActionColumnIndex($group) {
        return ($group === 'server') ? 7 : 6;
    }

    private function buildScrollListRow($factory, $userData, $group, $i18n) {
        $row = array(htmlspecialchars($userData['username'], ENT_QUOTES, 'UTF-8'), htmlspecialchars($userData['fullname'], ENT_QUOTES, 'UTF-8'));
        if ($group === 'server') {
            $row[] = htmlspecialchars($userData['site'] ?? 'server', ENT_QUOTES, 'UTF-8');
        }
        $row[] = $this->getStatusLabel($i18n, $userData);
        $row[] = $this->getTypeLabel($i18n, $userData['type']);
        $row[] = $userData['created_at'];
        $row[] = $userData['last_used'];
        $row[] = $this->buildActionButtons($factory, $userData, $group, $i18n);
        return $row;
    }

    private function getStatusLabel($i18n, $userData) {
        if (!empty($userData['locked'])) {
            return $i18n->get('[[base-user.twofactor_status_locked]]');
        }
        if ($userData['enabled'] == '1') {
            return $i18n->get('[[base-user.twofactor_status_enabled]]');
        }
        if (!empty($userData['required'])) {
            return $i18n->get('[[base-user.twofactor_status_required_setup]]');
        }
        return $i18n->get('[[base-user.twofactor_status_disabled]]');
    }

    private function buildActionButtons($factory, $userData, $group, $i18n) {
        if (($userData['enabled'] != '1') && empty($userData['required'])) {
            return '<span class="text-muted">-</span>';
        }

        $buttons = array();
        $groupQuery = '&group=' . urlencode($group);

        if (!empty($userData['locked'])) {
            $unlockButton = $factory->getModifyButton('/user/twoFactorUnlock?user=' . urlencode($userData['username']) . $groupQuery);
            $unlockButton->setButtonSize('small');
            $unlockButton->setButtonSpecialStyle('square_animated');
            $unlockButton->setIcon('fa fa-unlock');
            $unlockButton->setButtonColor('success');
            $unlockButton->setImageOnly(TRUE);
            $unlockButton->setTarget('_self');
            $unlockButton->setDescription($i18n->get('[[base-user.twofactor_action_unlock_desc]]'));
            $buttons[] = $unlockButton;
        }

        if (!empty($userData['can_regenerate'])) {
            $regenButton = $factory->getModifyButton('/user/twoFactorRegenerate?user=' . urlencode($userData['username']) . $groupQuery);
            $regenButton->setButtonSize('small');
            $regenButton->setButtonSpecialStyle('square_animated');
            $regenButton->setIcon('fa fa-key');
            $regenButton->setButtonColor('warning');
            $regenButton->setImageOnly(TRUE);
            $regenButton->setTarget('_self');
            $regenButton->setDescription($i18n->get('[[base-user.twofactor_action_regenerate_desc]]'));
            $buttons[] = $regenButton;
        }

        if ($userData['enabled'] == '1') {
            $resetButton = $factory->getModifyButton('/user/twoFactorReset?user=' . urlencode($userData['username']) . $groupQuery);
            $resetButton->setButtonSize('small');
            $resetButton->setButtonSpecialStyle('square_animated');
            $resetButton->setIcon('fa fa-repeat');
            $resetButton->setButtonColor('primary');
            $resetButton->setImageOnly(TRUE);
            $resetButton->setTarget('_self');
            $resetButton->setDescription($i18n->get('[[base-user.twofactor_action_reset_desc]]'));
            $buttons[] = $resetButton;
        }

        if (count($buttons) === 0) {
            return '<span class="text-muted">-</span>';
        }
        return $factory->getButtonContainer('', $buttons)->toHtml();
    }

    private function getTypeLabel($i18n, $type) {
        if ($type === 'modern') {
            return $i18n->get('[[base-user.twofactor_type_modern]]');
        }
        if ($type === 'legacy') {
            return $i18n->get('[[base-user.twofactor_type_legacy]]');
        }
        return $i18n->get('[[base-user.twofactor_type_none]]');
    }

    private function setPageLabels(&$BxPage, $i18n) {
        $BxPage->setLabel('gui_2fa', $i18n->get('[[base-system.gui_2fa]]'), $i18n->get('[[base-system.gui_2fa_help]]'));
        $BxPage->setLabel('gui_2fa_users', $i18n->get('[[base-system.gui_2fa_users]]'), $i18n->get('[[base-system.gui_2fa_users_help]]'));
        $BxPage->setLabel('twofactor_vsite_policy', $i18n->get('[[base-user.twofactor_vsite_policy]]'), $i18n->get('[[base-user.twofactor_vsite_policy_help]]'));
    }

    private function getRequestedGroup($getData) {
        return ((isset($getData['group'])) && (!empty($getData['group']))) ? $getData['group'] : 'server';
    }

    private function buildAdminUrl($group) {
        return (($group !== null) && ($group !== '') && ($group !== 'server')) ? '/user/twoFactorAdmin?group=' . urlencode($group) : '/user/twoFactorAdmin?group=server';
    }

    private function assertScopeAccess($group) {
        $CI =& get_instance();
        if (!$CI->getAllowed('validUser')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }
        if (($group !== 'server') && (!$CI->serverScriptHelper->getGroupAdmin($group))) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }
        if (($group === 'server') && (!$CI->getAllowed('systemAdministrator'))) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }
    }

    private function normalizeTimestamp($value) {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !ctype_digit($value)) {
                return null;
            }
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    private function formatTimestamp($value, $fallback) {
        $timestamp = $this->normalizeTimestamp($value);
        if ($timestamp === null || $timestamp <= 0) {
            return $fallback;
        }
        return date('Y-m-d H:i', $timestamp);
    }

    private function getUserHomeDirectory($username) {
        $handle = @fopen('/etc/passwd', 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $parts = explode(':', $line);
                if ($parts[0] == $username) {
                    fclose($handle);
                    return $parts[5];
                }
            }
            fclose($handle);
        }
        return null;
    }

    private function syncSshTwoFactorArtifacts($user, $secret, $backupCodes = array()) {
        if (empty($user['name']) || empty($secret)) {
            return;
        }

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $homeDir = $this->getUserHomeDirectory($user['name']);
        if (!$homeDir) {
            return;
        }

        $uid = $this->getUserNumericField($user['name'], 2);
        $gid = $this->getUserNumericField($user['name'], 3);
        if ($uid === null || $gid === null) {
            return;
        }

        $tmpFile = tempnam('/tmp', 'gauth_');
        if ($tmpFile === false) {
            return;
        }

        file_put_contents($tmpFile, $this->buildSshTwoFactorFileContent($secret, $backupCodes));

        $targetFile = $homeDir . '/.google_authenticator';
        $targetPng = $homeDir . '/.google_authenticator.png';
        $sessionId = $BX_SESSION['sessionId'];
        $commands = array(
            '/bin/cp ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($targetFile),
            '/bin/chown ' . escapeshellarg($uid . ':' . $gid) . ' ' . escapeshellarg($targetFile),
            '/bin/chmod 0400 ' . escapeshellarg($targetFile),
            '/usr/bin/qrencode -t PNG -o ' . escapeshellarg($targetPng) . ' ' . escapeshellarg($this->buildOtpAuthUrl($user, $secret)),
            '/bin/chown ' . escapeshellarg($uid . ':' . $gid) . ' ' . escapeshellarg($targetPng),
            '/bin/chmod 0400 ' . escapeshellarg($targetPng),
            'getent group google-authenticator >/dev/null 2>&1 || /usr/sbin/groupadd google-authenticator',
            'id -nG ' . escapeshellarg($user['name']) . ' | /bin/grep -qw google-authenticator || /usr/sbin/usermod -aG google-authenticator ' . escapeshellarg($user['name'])
        );

        foreach ($commands as $command) {
            $CI->serverScriptHelper->shell($command, $output, 'root', $sessionId);
        }

        if (isset($user['OID'])) {
            $CI->cceClient->set($user['OID'], 'SSH', array('GoogleAuthentication' => '1'));
        }

        if ($user['name'] === 'admin') {
            $this->syncRootTwoFactorArtifacts($targetFile, $sessionId);
        }

        @unlink($tmpFile);
    }

    private function removeSshTwoFactorArtifacts($user) {
        if (empty($user['name'])) {
            return;
        }

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $homeDir = $this->getUserHomeDirectory($user['name']);
        if ($homeDir) {
            $commands = array(
                '/bin/rm -f ' . escapeshellarg($homeDir . '/.google_authenticator'),
                '/bin/rm -f ' . escapeshellarg($homeDir . '/.google_authenticator.migrated'),
                '/bin/rm -f ' . escapeshellarg($homeDir . '/.google_authenticator.png'),
                'id -nG ' . escapeshellarg($user['name']) . ' | /bin/grep -qw google-authenticator && /usr/bin/gpasswd -d ' . escapeshellarg($user['name']) . ' google-authenticator || :'
            );
            foreach ($commands as $command) {
                $CI->serverScriptHelper->shell($command, $output, 'root', $BX_SESSION['sessionId']);
            }
        }

        if (isset($user['OID'])) {
            $CI->cceClient->set($user['OID'], 'SSH', array('GoogleAuthentication' => '0'));
        }

        if ($user['name'] === 'admin') {
            $this->removeRootTwoFactorArtifacts($BX_SESSION['sessionId']);
        }
    }

    private function syncRootTwoFactorArtifacts($sourceFile, $sessionId) {
        $CI =& get_instance();
        $command = 'if ! /bin/grep -q ' . escapeshellarg('^PermitRootLogin without-password') . ' /etc/ssh/sshd_config 2>/dev/null; then /bin/cp ' . escapeshellarg($sourceFile) . ' /root/.google_authenticator; /bin/chown root:root /root/.google_authenticator; /bin/chmod 0400 /root/.google_authenticator; id -nG root | /bin/grep -qw google-authenticator || /usr/sbin/usermod -aG google-authenticator root; else /bin/rm -f /root/.google_authenticator; id -nG root | /bin/grep -qw google-authenticator && /usr/bin/gpasswd -d root google-authenticator || :; fi';
        $CI->serverScriptHelper->shell($command, $output, 'root', $sessionId);
    }

    private function removeRootTwoFactorArtifacts($sessionId) {
        $CI =& get_instance();
        $commands = array(
            '/bin/rm -f /root/.google_authenticator',
            'id -nG root | /bin/grep -qw google-authenticator && /usr/bin/gpasswd -d root google-authenticator || :'
        );
        foreach ($commands as $command) {
            $CI->serverScriptHelper->shell($command, $output, 'root', $sessionId);
        }
    }

    private function buildSshTwoFactorFileContent($secret, $backupCodes) {
        $lines = array(
            trim($secret),
            '" RATE_LIMIT 3 30',
            '" WINDOW_SIZE 17',
            '" DISALLOW_REUSE',
            '" TOTP_AUTH'
        );

        foreach ($backupCodes as $codeData) {
            $code = is_array($codeData) ? ($codeData['code'] ?? '') : $codeData;
            if ($code !== '') {
                $lines[] = (string) $code;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function buildOtpAuthUrl($user, $secret) {
        $labelDomain = !empty($user['site']) ? $user['site'] : 'server';
        return 'otpauth://totp/' . rawurlencode($user['name'] . '@' . $labelDomain) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode('BlueOnyx');
    }

    private function getUserNumericField($username, $fieldIndex) {
        $handle = @fopen('/etc/passwd', 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $parts = explode(':', trim($line));
                if ($parts[0] == $username && isset($parts[$fieldIndex]) && ctype_digit($parts[$fieldIndex])) {
                    fclose($handle);
                    return $parts[$fieldIndex];
                }
            }
            fclose($handle);
        }
        return null;
    }
}
