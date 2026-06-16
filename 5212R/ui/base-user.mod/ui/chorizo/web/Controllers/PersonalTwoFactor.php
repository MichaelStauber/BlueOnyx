<?php
namespace User\Controllers;

use App\Controllers\BaseController;
use App\Libraries\ModernTOTPProvider;
use App\Libraries\TwoFactorBackupCodes;
use App\Libraries\TwoFactorEncryption;
include_once("I18n.php");
use I18n;

class PersonalTwoFactor extends BaseController {

    public function __construct() {
    }

    public function index() {
        $CI =& get_instance();

        if (!$CI->getAllowed('validUser')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $BX_SESSION = $CI->getBX_SESSION();
        $loginUser = $BX_SESSION['loginUser'];
        $user = $CI->cceClient->getObject("User", array("name" => $loginUser['name'], 'site' => $loginUser['site']));

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/personalTwoFactor");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $this->injectBackupCodeHelpers($BxPage, $i18n);
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));
        $errors = $BxPage->getErrors();

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            $action = (string) $this->request->getPost('twofactor_action');
            $result = $this->handleAction($action, $user, $i18n);
            if (!empty($result['redirect'])) {
                $errors = array_merge($errors, $this->buildReturnMessages($result));
                $BxPage->ReturnToThisPage($errors, '/user/personalTwoFactor');
            }
            if (!empty($result['errors'])) {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        $BxPage->setErrors($errors);
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_personalProfile';
        $defaultPage = 'pageID';
        $page_body = array();

        $policy = $this->getPolicyState($user);
        $status = $this->getTwoFactorStatus($user);
        $forcedSetup = $this->isForcedSetupState($user, $policy, $status);
        if ($forcedSetup) {
            $this->ensurePendingEnrollmentExists();
        }
        $block = $factory->getPagedBlock("personalTwoFactor", array($defaultPage));
        $block->setLabel($factory->getLabel('personalTwoFactor'));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        $policyNoticeHtml = $this->buildPolicyNoticeHtml($i18n, $policy, $status);
        if ($policyNoticeHtml !== '') {
            $policyNoticeField = $factory->getRawHTML('personalTwoFactorPolicyNotice', $policyNoticeHtml);
            $block->addFormField($policyNoticeField, $factory->getLabel('spacer'), $defaultPage);
        }

        $actionButtonsField = $this->buildActionButtonsField($factory, $i18n, $status);
        if ($actionButtonsField !== null) {
            $block->addFormField($actionButtonsField, $factory->getLabel('spacer'), $defaultPage);
        }

        $statusTable = $this->buildStatusTable($factory, $i18n, $policy, $status);
        $block->addFormField($statusTable, $factory->getLabel('spacer'), $defaultPage);

        $contentHtml = $this->buildContentHtml($i18n, $user, $policy, $status, $forcedSetup);
        $contentField = $factory->getRawHTML('personalTwoFactorContent', $contentHtml);
        $block->addFormField($contentField, $factory->getLabel('spacer'), $defaultPage);

        $page_body[] = $block->toHtml();
        return $BxPage->render($page_module, $page_body);
    }

    private function handleAction($action, $user, $i18n) {
        switch ($action) {
            case 'prepare':
                return $this->prepareEnrollment($user, $i18n);
            case 'verify':
                return $this->verifyEnrollment($user, $i18n);
            case 'cancel_setup':
                return $this->cancelEnrollment($i18n);
            case 'reset':
                return $this->resetTwoFactor($user, $i18n);
            case 'regenerate':
                return $this->regenerateCodes($user, $i18n);
        }

        return array('errors' => array());
    }

    private function prepareEnrollment($user, $i18n) {
        $totp = new ModernTOTPProvider();
        $backupManager = new TwoFactorBackupCodes();
        session()->set('personalTwoFactorPending', array(
            'secret' => $totp->generateSecret(),
            'backup_codes' => $backupManager->generateCodes(),
            'created_at' => time()
        ));

        return array(
            'redirect' => true,
            'flash_type' => 'success',
            'flash_message' => $i18n->get('[[base-user.my2fa_setup_intro]]')
        );
    }

    private function verifyEnrollment($user, $i18n) {
        $pending = session()->get('personalTwoFactorPending');
        if (!is_array($pending) || empty($pending['secret'])) {
            return array(
                'redirect' => true,
                'flash_type' => 'error',
                'flash_message' => $i18n->get('[[base-user.my2fa_verify_failed]]')
            );
        }

        $code = (string) $this->request->getPost('twofactor_verify_code');

        $totp = new ModernTOTPProvider();
        if (!$totp->verifyCode($pending['secret'], $code)) {
            return array(
                'redirect' => true,
                'flash_type' => 'error',
                'flash_message' => $i18n->get('[[base-user.my2fa_verify_failed]]')
            );
        }

        $storageKey = TwoFactorEncryption::generateStorageKey();
        $encryption = new TwoFactorEncryption($storageKey);
        $backupManager = new TwoFactorBackupCodes();
        $CI =& get_instance();
        $CI->cceClient->set($user['OID'], 'TwoFactorAuth', array(
            'enabled' => '1',
            'secret_encrypted' => $encryption->encrypt($pending['secret']),
            'backup_codes' => $encryption->encrypt($backupManager->serialize($pending['backup_codes'])),
            'encryption_key' => $storageKey,
            'created_at' => time(),
            'last_used' => 0,
            'failed_attempts' => 0,
            'locked_until' => 0,
            'is_legacy' => 0
        ));
        $this->syncSshTwoFactorArtifacts($user, $pending['secret'], $pending['backup_codes']);

        session()->remove('personalTwoFactorPending');
        $CI->setTwoFactorSetupRequiredState(false);
        session()->setFlashdata('personalTwoFactorBackupCodes', $pending['backup_codes']);

        return array(
            'redirect' => true,
            'flash_type' => 'success',
            'flash_message' => $i18n->get('[[base-user.my2fa_setup_success]]')
        );
    }

    private function cancelEnrollment($i18n) {
        $CI =& get_instance();
        if ($CI->isTwoFactorSetupRestrictionActive()) {
            return array(
                'redirect' => true,
                'flash_type' => 'error',
                'flash_message' => $i18n->get('[[base-user.my2fa_required]]')
            );
        }

        session()->remove('personalTwoFactorPending');
        return array(
            'redirect' => true,
            'flash_type' => 'success',
            'flash_message' => $i18n->get('[[base-user.my2fa_setup_cancelled]]')
        );
    }

    private function resetTwoFactor($user, $i18n) {
        $CI =& get_instance();
        $this->clearTwoFactorState($user['OID']);
        $this->removeSshTwoFactorArtifacts($user);
        session()->remove('personalTwoFactorPending');
        if ($this->getPolicyState($user)['required'] && ($user['name'] !== 'admin')) {
            $CI->setTwoFactorSetupRequiredState(true);
        }
        else {
            $CI->setTwoFactorSetupRequiredState(false);
        }

        return array(
            'redirect' => true,
            'flash_type' => 'success',
            'flash_message' => $i18n->get('[[base-user.my2fa_reset_success]]')
        );
    }

    private function regenerateCodes($user, $i18n) {
        $CI =& get_instance();
        $tfa = $CI->cceClient->get($user['OID'], 'TwoFactorAuth');
        if (!$tfa || empty($tfa['secret_encrypted'])) {
            return array(
                'redirect' => true,
                'flash_type' => 'error',
                'flash_message' => $i18n->get('[[base-user.twofactor_err_not_enabled]]')
            );
        }

        $tfa = $this->ensurePerUserEncryption($user['OID'], $tfa);
        $backupManager = new TwoFactorBackupCodes();
        $encryption = new TwoFactorEncryption($tfa['encryption_key'] ?? null);
        $backupCodes = $backupManager->generateCodes();
        $CI->cceClient->set($user['OID'], 'TwoFactorAuth', array(
            'backup_codes' => $encryption->encrypt($backupManager->serialize($backupCodes))
        ));
        $secret = $encryption->decrypt($tfa['secret_encrypted']);
        if (!empty($secret)) {
            $this->syncSshTwoFactorArtifacts($user, $secret, $backupCodes);
        }

        session()->setFlashdata('personalTwoFactorBackupCodes', $backupCodes);
        return array(
            'redirect' => true,
            'flash_type' => 'success',
            'flash_message' => $i18n->get('[[base-user.my2fa_regen_success]]')
        );
    }

    private function getPolicyState($user) {
        $CI =& get_instance();
        $system = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        $siteRequired = false;
        if (!empty($user['site'])) {
            $siteShell = $CI->cceClient->getObject('Vsite', array('name' => $user['site']), 'Shell');
            $siteRequired = ($siteShell && isset($siteShell['GoogleAuthentication']) && ($siteShell['GoogleAuthentication'] === '1'));
        }

        $serverRequired = false;
        if (($system['gui_2fa'] ?? '0') === '1') {
            $policy = $system['gui_2fa_users'] ?? 'ALL';
            if ($policy === 'ALL') {
                $serverRequired = true;
            }
            else {
                $capLevels = isset($user['capLevels']) ? array_filter(explode('&', $user['capLevels'])) : array();
                if (($policy === 'ADMINS') && (($user['name'] === 'admin') || in_array('adminUser', $capLevels))) {
                    $serverRequired = true;
                }
                if (($policy === 'PRIVILEGED') && (($user['name'] === 'admin') || in_array('adminUser', $capLevels) || in_array('siteAdmin', $capLevels))) {
                    $serverRequired = true;
                }
            }
        }

        return array(
            'required' => ($siteRequired || $serverRequired),
            'site_required' => $siteRequired,
            'server_required' => $serverRequired
        );
    }

    private function getTwoFactorStatus($user) {
        $CI =& get_instance();
        $tfa = $CI->cceClient->get($user['OID'], 'TwoFactorAuth');
        $status = array(
            'enabled' => false,
            'type' => 'none',
            'created_at' => null,
            'last_used' => null,
            'unused_codes' => null
        );

        if ($tfa && isset($tfa['enabled']) && ($tfa['enabled'] === '1')) {
            $status['enabled'] = true;
            $status['type'] = !empty($tfa['secret_encrypted']) ? 'modern' : 'legacy';
            $status['created_at'] = $this->formatTimestamp($tfa['created_at'] ?? null);
            $status['last_used'] = $this->formatTimestamp($tfa['last_used'] ?? null);

            if (!empty($tfa['backup_codes'])) {
                $tfa = $this->ensurePerUserEncryption($user['OID'], $tfa);
                $encryption = new TwoFactorEncryption($tfa['encryption_key'] ?? null);
                $backupManager = new TwoFactorBackupCodes();
                $decoded = $encryption->decrypt($tfa['backup_codes']);
                if ($decoded) {
                    $codes = $backupManager->deserialize($decoded);
                    if (is_array($codes)) {
                        $status['unused_codes'] = $backupManager->countUnused($codes);
                    }
                }
            }
            return $status;
        }

        $homeDir = $this->getUserHomeDirectory($user['name']);
        if ($homeDir && file_exists("$homeDir/.google_authenticator")) {
            $status['enabled'] = true;
            $status['type'] = 'legacy';
        }

        return $status;
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

    private function buildStatusTable($factory, $i18n, $policy, $status) {
        $policyText = $policy['required'] ? $i18n->get('[[base-user.my2fa_required]]') : $i18n->get('[[base-user.my2fa_optional]]');
        $statusText = $i18n->get('[[base-user.twofactor_status_disabled]]');
        if ($status['enabled']) {
            $statusText = $i18n->get('[[base-user.twofactor_status_enabled]]');
        }
        elseif ($policy['required']) {
            $statusText = $i18n->get('[[base-user.twofactor_status_required_setup]]');
        }

        $typeText = $i18n->get('[[base-user.twofactor_type_none]]');
        if ($status['type'] === 'modern') {
            $typeText = $i18n->get('[[base-user.twofactor_type_modern]]');
        }
        if ($status['type'] === 'legacy') {
            $typeText = $i18n->get('[[base-user.twofactor_type_legacy]]');
        }

        $rows = array(
            array($i18n->get('[[base-user.twofactor_status]]'), $statusText),
            array($i18n->get('[[base-user.twofactor_type]]'), $typeText),
            array($i18n->get('[[base-user.my2fa_policy_label]]'), $policyText)
        );

        if (!empty($status['created_at'])) {
            $rows[] = array($i18n->get('[[base-user.twofactor_created]]'), $status['created_at']);
        }

        if (!empty($status['last_used'])) {
            $rows[] = array($i18n->get('[[base-user.twofactor_last_used]]'), $status['last_used']);
        }

        if ($status['unused_codes'] !== null) {
            $rows[] = array($i18n->get('[[base-user.my2fa_unused_codes]]'), (string) $status['unused_codes']);
        }

        $table = $factory->getTable('personalTwoFactorStatusTable', array(), $rows);
        $table->setResponsive(true);
        $table->setStriped(false);
        $table->setHover(false);
        $table->setBordered(true);
        $table->setCompact(true);
        $table->setRowHeaderColumn(0);
        $table->setNoWrapColumns(array(0, 1));
        $table->setColumnClasses(array('text-muted', 'text-right'));
        $table->addTableClass('mb-15');
        return $table;
    }

    private function buildPolicyNoticeHtml($i18n, $policy, $status) {
        if (!$policy['required'] || !$status['enabled']) {
            return '';
        }

        return ErrorMessage($i18n->get('[[base-user.my2fa_required]]'), 'alert_navy', 'info_about');
    }

    private function buildActionButtonsField($factory, $i18n, $status) {
        if (!$status['enabled']) {
            return null;
        }

        $buttonBarHtml = '<div class="button_bar clearfix mb-15">';
        $buttonBarHtml .= $this->renderActionForm('regenerate', $i18n->get('[[base-user.my2fa_regen_button]]'), 'primary', 'fa fa-key');
        $buttonBarHtml .= $this->renderActionForm('reset', $i18n->get('[[base-user.my2fa_reset_button]]'), 'danger', 'fa fa-repeat');
        $buttonBarHtml .= "</div>\n<br>\n";

        return $factory->getRawHTML('personalTwoFactorButtons', $buttonBarHtml);
    }

    private function buildContentHtml($i18n, $user, $policy, $status, $forcedSetup = false) {
        $html = '';

        if ($status['enabled']) {
            $revealedCodes = session()->getFlashdata('personalTwoFactorBackupCodes');
            if (is_array($revealedCodes) && count($revealedCodes) > 0) {
                $html .= $this->renderBackupCodes($i18n, $user, $revealedCodes);
            }
            return $html;
        }

        if ($policy['required']) {
            $html .= ErrorMessage($i18n->get('[[base-user.my2fa_reset_notice]]'), 'alert_red', 'alarm_bell');
        }

        $pending = session()->get('personalTwoFactorPending');
        if (!is_array($pending) || empty($pending['secret'])) {
            $html .= '<p>' . htmlspecialchars($i18n->get('[[base-user.my2fa_setup_intro]]'), ENT_QUOTES, 'UTF-8') . '</p>';
            $html .= $this->renderActionForm('prepare', $i18n->get('[[base-user.my2fa_setup_button]]'), 'primary');
            return $html;
        }

        $accountLabel = $this->getOtpAccountLabel($user);
        $otpAuthUrl = $this->buildOtpAuthUrl($user, $pending['secret']);
        $qrUrl = $this->buildQrCodeImageSource($otpAuthUrl, $accountLabel, $pending['secret'], 220);
        $html .= '<p>' . htmlspecialchars($i18n->get('[[base-user.my2fa_setup_intro]]'), ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<p><button class="btn btn-primary btn-icon left-icon" type="button" onclick="showPersonalTwoFactorQr(); return false;"><i class="fa fa-qrcode mr-10"></i><span class="btn-text">' . htmlspecialchars($i18n->get('[[base-user.my2fa_scan_qr]]'), ENT_QUOTES, 'UTF-8') . '</span></button></p>';
        $html .= '<div id="personalTwoFactorReveal" class="hidden">';
        $html .= '<div class="row">';
        $html .= '<div class="col-sm-4"><p><strong>' . htmlspecialchars($i18n->get('[[base-user.my2fa_scan_qr]]'), ENT_QUOTES, 'UTF-8') . '</strong></p><img id="GAUTHimg" src="' . htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') . '" alt="QR" style="max-width:220px;height:auto;"><p class="mt-10 mb-15"><code>' . htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') . '</code></p>' . $this->renderAuthenticatorAppHelp($i18n) . '</div>';
        $html .= '<div class="col-sm-8">';
        $html .= '<div class="form-group"><label for="ga_key"><strong>' . htmlspecialchars($i18n->get('[[base-user.my2fa_manual_secret]]'), ENT_QUOTES, 'UTF-8') . '</strong></label><input class="form-control" type="text" name="ga_key" id="ga_key" value="' . htmlspecialchars($pending['secret'], ENT_QUOTES, 'UTF-8') . '" readonly></div>';
        $html .= '<div class="form-group"><label for="otpauth_url"><strong>otpauth://</strong></label><input class="form-control" type="text" name="otpauth_url" id="otpauth_url" value="' . htmlspecialchars($otpAuthUrl, ENT_QUOTES, 'UTF-8') . '" readonly></div>';
        $html .= $this->renderBackupCodes($i18n, $user, $pending['backup_codes']);
        $html .= '<form method="post" action="/user/personalTwoFactor">';
        $html .= '<input type="hidden" name="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(csrf_hash(), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="twofactor_action" value="verify">';
        $html .= '<div class="form-group"><label for="twofactor_verify_code">' . htmlspecialchars($i18n->get('[[base-user.2fa_input_field]]'), ENT_QUOTES, 'UTF-8') . '</label>';
        $html .= '<input class="form-control" type="text" name="twofactor_verify_code" id="twofactor_verify_code" autocomplete="one-time-code" autocapitalize="off" spellcheck="false"></div>';
        $html .= '<button class="btn btn-primary" type="submit">' . htmlspecialchars($i18n->get('[[base-user.my2fa_verify_button]]'), ENT_QUOTES, 'UTF-8') . '</button>';
        $html .= '</form> ';
        if (!$forcedSetup) {
            $html .= $this->renderActionForm('cancel_setup', $i18n->get('[[base-user.my2fa_cancel_button]]'), 'default');
        }
        $html .= '</div></div></div>';

        return $html;
    }

    private function renderBackupCodes($i18n, $user, $codes) {
        $items = array();
        foreach ($codes as $codeData) {
            $items[] = is_array($codeData) ? $codeData['code'] : $codeData;
        }

        $siteLabel = $this->getBackupCodesSiteLabel($i18n, $user);
        $copyLabel = $i18n->get('[[base-user.my2fa_backup_codes_copy]]');
        $pdfLabel = $i18n->get('[[base-user.my2fa_backup_codes_print]]');
        $heading = htmlspecialchars($i18n->get('[[base-user.my2fa_backup_codes_heading]]'), ENT_QUOTES, 'UTF-8');
        $userLabel = htmlspecialchars($i18n->get('[[base-user.my2fa_backup_codes_user]]'), ENT_QUOTES, 'UTF-8');
        $siteTextLabel = htmlspecialchars($i18n->get('[[base-user.my2fa_backup_codes_vsite]]'), ENT_QUOTES, 'UTF-8');
        $notice = htmlspecialchars($i18n->get('[[base-user.my2fa_backup_codes_once]]'), ENT_QUOTES, 'UTF-8');
        $codeRows = array_chunk($items, 2);
        $copyPayload = htmlspecialchars(implode("\n", $items), ENT_QUOTES, 'UTF-8');
        $printable = '<div style="font-family:Arial,sans-serif;padding:24px;">';
        $printable .= '<h2 style="margin:0 0 8px 0;">' . $heading . '</h2>';
        $printable .= '<p style="margin:0 0 18px 0;color:#555;">' . $userLabel . ': ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . ' | ' . $siteTextLabel . ': ' . htmlspecialchars($siteLabel, ENT_QUOTES, 'UTF-8') . '</p>';
        $printable .= '<table style="width:100%;border-collapse:collapse;" border="1" cellpadding="8">';
        foreach ($codeRows as $row) {
            $printable .= '<tr>';
            foreach ($row as $cell) {
                $printable .= '<td style="font-family:monospace;font-size:14px;">' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            if (count($row) < 2) {
                $printable .= '<td>&nbsp;</td>';
            }
            $printable .= '</tr>';
        }
        $printable .= '</table></div>';

        $html = '<div class="panel panel-default mb-15">';
        $html .= '<div class="panel-heading clearfix">';
        $html .= '<div class="pull-left">';
        $html .= '<h5 class="panel-title" style="margin:4px 0 6px 0;">' . $heading . '</h5>';
        $html .= '<div class="small text-muted">' . $userLabel . ': <strong>' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . '</strong> | ' . $siteTextLabel . ': <strong>' . htmlspecialchars($siteLabel, ENT_QUOTES, 'UTF-8') . '</strong></div>';
        $html .= '</div>';
        $html .= '<div class="pull-right twofactor-backup-toolbar">' . $this->renderBackupCodeButtons($i18n, $copyLabel, $pdfLabel) . '</div>';
        $html .= '</div>';
        $html .= '<div class="panel-body">';
        $html .= '<p class="text-muted">' . $notice . '</p>';
        $html .= '<table class="table table-bordered table-striped mb-0">';
        foreach ($codeRows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td><code>' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</code></td>';
            }
            if (count($row) < 2) {
                $html .= '<td>&nbsp;</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '<textarea id="personalTwoFactorBackupCodesText" class="hidden">' . $copyPayload . '</textarea>';
        $html .= '<div id="personalTwoFactorBackupCodesPrintable" class="hidden">' . $printable . '</div>';
        $html .= '</div></div>';
        return $html;
    }

    private function renderAuthenticatorAppHelp($i18n) {
        $CI =& get_instance();
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/personalTwoFactor");

        $BxPage = $factory->getPage();

        $AppDL_titleField = $factory->addBXDivider('AppDL_BX_title', "[[base-shell.AppDL_BX_title]]");

        $BxPage->setLabel('AppDL_BX_title', $i18n->get('[[base-ssh.AppDL_BX_title]]'), $i18n->get('[[base-ssh.AppDL_BX_title]]'));

        // Authenticator image:
        $GAUTHimg = $factory->getHTMLField("GAUTHimgBX", '<img src="/.elm/gauth/bx-auth-qr.png" width="200" height="200"><br><button type="button" style="display: block; margin: 0 auto; background-color: black; color: white; padding: 10px 20px; border-radius: 5px; border: none;" onclick="window.open(\'https://www.blueonyx.it/auth\', \'_blank\')">URL</button>');

        $BxPage->setLabel('GAUTHimgBX', $i18n->get('[[base-ssh.GAUTHimgBX]]'), $i18n->get('[[base-ssh.GAUTHimgBX]]'));

        return $AppDL_titleField->toHtml() . $GAUTHimg->toHtml();
    }

    private function renderBackupCodeButtons($i18n, $copyLabel, $pdfLabel) {
        $CI =& get_instance();
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/personalTwoFactor");

        $copyButton = $factory->getButton('', '[[base-user.my2fa_backup_codes_copy]]');
        $copyButton->setOnClick('copyPersonalTwoFactorBackupCodes(); return false;');
        $copyButton->setButtonColor('primary');
        $copyButton->setButtonSize('small');
        $copyButton->setButtonSpecialStyle('icon_left');
        $copyButton->setIcon('fa fa-copy');
        $copyButton->setDescription($copyLabel);

        $printButton = $factory->getButton('', '[[base-user.my2fa_backup_codes_print]]');
        $printButton->setOnClick('printPersonalTwoFactorBackupCodes(); return false;');
        $printButton->setButtonColor('primary');
        $printButton->setButtonSize('small');
        $printButton->setButtonSpecialStyle('icon_left');
        $printButton->setIcon('fa fa-file-pdf-o');
        $printButton->setDescription($pdfLabel);

        return $copyButton->toHtml() . $printButton->toHtml();
    }

    private function getBackupCodesSiteLabel($i18n, $user) {
        if (empty($user['site'])) {
            return $i18n->get('[[base-user.my2fa_backup_codes_server]]');
        }

        $CI =& get_instance();
        $vsite = $CI->cceClient->getObject('Vsite', array('name' => $user['site']));
        if ($vsite && !empty($vsite['fqdn'])) {
            return $vsite['fqdn'];
        }

        return $user['site'];
    }

    private function injectBackupCodeHelpers($BxPage, $i18n) {
        $copiedMessage = json_encode($i18n->get('[[base-user.my2fa_backup_codes_copied]]'));
        $printTitle = json_encode($i18n->get('[[base-user.my2fa_backup_codes_heading]]'));
        $BxPage->setExtraHeaders('
            <style>
            .twofactor-backup-toolbar .btn,
            .twofactor-backup-toolbar .btn .btn-text,
            .twofactor-backup-toolbar .btn i {
                color: #ffffff !important;
            }
            .twofactor-backup-toolbar .btn:hover,
            .twofactor-backup-toolbar .btn:focus,
            .twofactor-backup-toolbar .btn:hover .btn-text,
            .twofactor-backup-toolbar .btn:focus .btn-text,
            .twofactor-backup-toolbar .btn:hover i,
            .twofactor-backup-toolbar .btn:focus i {
                color: #ffffff !important;
            }
            </style>
            <script type="text/javascript">
            function copyPersonalTwoFactorBackupCodes() {
                var field = document.getElementById("personalTwoFactorBackupCodesText");
                if (!field) {
                    return;
                }
                field.classList.remove("hidden");
                field.focus();
                field.select();
                document.execCommand("copy");
                field.classList.add("hidden");
                alert(' . $copiedMessage . ');
            }

            function printPersonalTwoFactorBackupCodes() {
                var printable = document.getElementById("personalTwoFactorBackupCodesPrintable");
                if (!printable) {
                    return;
                }
                var printWindow = window.open("", "_blank", "width=900,height=700,scrollbars=yes");
                if (!printWindow) {
                    return;
                }
                printWindow.document.open();
                printWindow.document.write("<html><head><title>" + ' . $printTitle . ' + "</title></head><body>" + printable.innerHTML + "</body></html>");
                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
            }

            function showPersonalTwoFactorQr() {
                var panel = document.getElementById("personalTwoFactorReveal");
                if (!panel) {
                    return;
                }
                panel.classList.remove("hidden");
                var qr = document.getElementById("GAUTHimg");
                if (qr && qr.scrollIntoView) {
                    qr.scrollIntoView({behavior: "smooth", block: "center"});
                }
            }
            </script>');
    }

    private function renderActionForm($action, $label, $btnClass, $icon = '') {
        $html = '<form method="post" action="/user/personalTwoFactor" style="display:inline-block;margin-right:8px;margin-bottom:8px;">';
        $html .= '<input type="hidden" name="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(csrf_hash(), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="twofactor_action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<button class="btn btn-' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . ' btn-icon left-icon ma-5" type="submit">';
        if ($icon !== '') {
            $html .= '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' mr-10"></i>';
        }
        $html .= '<span class="btn-text">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></button>';
        $html .= '</form>';
        return $html;
    }

    private function buildReturnMessages($result) {
        $messages = array();
        if (empty($result['flash_message'])) {
            return $messages;
        }

        if (($result['flash_type'] ?? '') === 'success') {
            $messages[] = ErrorMessage($result['flash_message'], 'alert_green', 'info_about');
        }
        else {
            $messages[] = ErrorMessage($result['flash_message'], 'alert_red', 'alarm_bell');
        }

        return $messages;
    }

    private function ensurePendingEnrollmentExists() {
        $pending = session()->get('personalTwoFactorPending');
        if (is_array($pending) && !empty($pending['secret'])) {
            return;
        }

        $totp = new ModernTOTPProvider();
        $backupManager = new TwoFactorBackupCodes();
        session()->set('personalTwoFactorPending', array(
            'secret' => $totp->generateSecret(),
            'backup_codes' => $backupManager->generateCodes(),
            'created_at' => time()
        ));
    }

    private function isForcedSetupState($user, $policy, $status) {
        $CI =& get_instance();
        if (!$CI->isTwoFactorSetupRestrictionActive()) {
            return false;
        }

        if (($user['name'] ?? '') === 'admin') {
            return false;
        }

        return ($policy['required'] && !$status['enabled']);
    }

    private function formatTimestamp($value) {
        if ($value === null || $value === '' || !is_numeric($value) || ((int) $value) <= 0) {
            return null;
        }
        return date('Y-m-d H:i', (int) $value);
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
        return 'otpauth://totp/' . $this->getOtpAccountLabel($user) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode('BlueOnyx');
    }

    private function getOtpAccountLabel($user) {
        return $user['name'] . '@' . $this->getOtpLabelDomain($user);
    }

    private function buildQrCodeImageSource($otpAuthUrl, $accountLabel, $secret, $size = 220) {
        $binary = $this->renderQrPng($otpAuthUrl, $size);
        if ($binary !== null) {
            return 'data:image/png;base64,' . base64_encode($binary);
        }

        return \Sonata\GoogleAuthenticator\GoogleQrUrl::generate($accountLabel, $secret, 'BlueOnyx', $size);
    }

    private function renderQrPng($payload, $size = 220) {
        $moduleSize = max(4, (int) floor($size / 37));
        $command = '/usr/bin/qrencode -t PNG -s ' . (int) $moduleSize . ' -m 2 -o - ' . escapeshellarg($payload) . ' 2>/dev/null';
        $output = @shell_exec($command);
        if (!is_string($output) || $output === '') {
            return null;
        }

        return $output;
    }

    private function getOtpLabelDomain($user) {
        $CI =& get_instance();

        if (!empty($user['site'])) {
            $vsite = $CI->cceClient->getObject('Vsite', array('name' => $user['site']));
            if ($vsite && !empty($vsite['fqdn'])) {
                return $vsite['fqdn'];
            }
        }

        $system = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        if ($system) {
            $hostname = trim((string) ($system['hostname'] ?? ''));
            $domainname = trim((string) ($system['domainname'] ?? ''));
            if (($hostname !== '') && ($domainname !== '')) {
                return $hostname . '.' . $domainname;
            }
            if ($hostname !== '') {
                return $hostname;
            }
        }

        return 'server';
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
