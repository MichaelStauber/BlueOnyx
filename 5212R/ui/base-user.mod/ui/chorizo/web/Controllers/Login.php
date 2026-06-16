<?php

namespace User\Controllers;

use App\Controllers\BaseController;
use App\Libraries\ModernTOTPProvider;
use App\Libraries\TwoFactorRateLimiter;
use App\Libraries\TwoFactorBackupCodes;
use App\Libraries\TwoFactorEncryption;

include_once("I18n.php");
use I18n;
use ServerScriptHelper;

class Login extends BaseController {

    //protected $usersLib;

    var $UserHomeDirectory;

    public function __construct() {

    }

    function getUserHomeDirectory($username) {
        $passwdFile = '/etc/passwd';
        $handle = fopen($passwdFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                // Split the line into parts
                $parts = explode(':', $line);

                // Check if the current line contains the username
                if ($parts[0] == $username) {
                    fclose($handle);
                    $this->UserHomeDirectory = $parts[5];
                    return $parts[5]; // Return the home directory
                }
            }

            fclose($handle);
        }
        return null;
    }

    function getSecretKey($username) {
        $homeDirectory = Login::getUserHomeDirectory($username);
        $filePath = "$homeDirectory/.google_authenticator";
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $content = '';
        $secretKey = NULL;
        $ret = $CI->serverScriptHelper->shell("/bin/cat $filePath", $content, $username, $BX_SESSION['sessionId']);
        if ($ret == 0) {
            $lines = explode("\n", $content);
            $secretKey = $lines[0];
        }
        return $secretKey;
    }

    function getBackupCodes($username) {
        $homeDirectory = Login::getUserHomeDirectory($username);
        $filePath = "$homeDirectory/.google_authenticator";
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $content = '';
        $ret = $CI->serverScriptHelper->shell("/bin/cat $filePath", $content, $username, $BX_SESSION['sessionId']);
        if ($ret == 0) {
            $lines = explode("\n", $content);
            $backupCodes = [];
            foreach ($lines as $line) {
                if (is_numeric($line)) {
                    $backupCodes[] = $line; // Add the line to backup codes if it's numeric
                }
            }
            return $backupCodes;
        }
    }

    /**
     * Modern verifyCode with rate limiting, encryption, and backward compatibility
     * 
     * @param string $username Username to verify
     * @param string $code TOTP or backup code
     * @return bool True if code is valid
     */
    public function verifyCode($username, $code) {
        $CI =& get_instance();
        
        // Initialize rate limiter
        $rateLimiter = new TwoFactorRateLimiter();
        
        // Check if user is locked out
        if ($rateLimiter->isLocked($username)) {
            bx_error_log("Login.php: User $username is locked out due to failed 2FA attempts");
            return false;
        }
        
        // Accept both 6-digit TOTP codes and alphanumeric backup codes.
        if (!preg_match('/^[A-Za-z0-9\-\s]{6,32}$/', $code)) {
            bx_error_log("Login.php: Invalid 2FA code format for user $username");
            return false;
        }
        
        // Try modern encrypted storage first
        $modernResult = $this->verifyModern2FA($username, $code);
        if ($modernResult['found']) {
            if ($modernResult['valid']) {
                $rateLimiter->reset($username);
                return true;
            } else {
                $rateLimiter->recordFailure($username);
                return false;
            }
        }
        
        // Fallback to legacy ~/.google_authenticator
        $legacyResult = $this->verifyLegacy2FA($username, $code);
        if ($legacyResult['valid']) {
            // Auto-migrate on successful legacy verification
            $this->migrateLegacyToModern($username);
            $rateLimiter->reset($username);
            return true;
        }
        
        // Record failure
        $rateLimiter->recordFailure($username);
        return false;
    }
    
    /**
     * Verify using modern encrypted CODB storage
     * 
     * @param string $username Username
     * @param string $code Code to verify
     * @return array ['found' => bool, 'valid' => bool]
     */
    private function verifyModern2FA($username, $code) {
        $CI =& get_instance();
        
        // Get user OID
        $user = $CI->cceClient->getObject('User', ['name' => $username]);
        if (!$user || !isset($user['OID'])) {
            return ['found' => false, 'valid' => false];
        }
        
        // Get TwoFactorAuth namespace
        $tfa = $CI->cceClient->get($user['OID'], 'TwoFactorAuth');
        if (!$tfa || $tfa['enabled'] != '1') {
            return ['found' => false, 'valid' => false];
        }
        
        // Check lockout status
        if (isset($tfa['locked_until']) && $tfa['locked_until'] > time()) {
            return ['found' => true, 'valid' => false];
        }
        
        // Decrypt secret
        if (!isset($tfa['secret_encrypted']) || empty($tfa['secret_encrypted'])) {
            return ['found' => false, 'valid' => false];
        }
        
        $encryption = new TwoFactorEncryption($tfa['encryption_key'] ?? null);
        $secret = $encryption->decrypt($tfa['secret_encrypted']);
        
        if ($secret === null) {
            bx_error_log("Login.php: Failed to decrypt 2FA secret for user $username");
            return ['found' => true, 'valid' => false];
        }
        
        // Verify TOTP
        $totpProvider = new ModernTOTPProvider();
        if ($totpProvider->verifyCode($secret, $code, 1)) {
            $backupCodes = $this->decryptBackupCodes($tfa);
            $tfa = $this->ensurePerUserEncryption($user['OID'], $tfa, $secret, $backupCodes);
            // Update last_used
            $CI->cceClient->set($user['OID'], 'TwoFactorAuth', [
                'last_used' => time(),
                'failed_attempts' => 0,
                'locked_until' => 0
            ]);
            $this->syncSshTwoFactorArtifacts($user, $secret, $backupCodes);
            return ['found' => true, 'valid' => true];
        }
        
        // Check backup codes
        if (isset($tfa['backup_codes']) && !empty($tfa['backup_codes'])) {
            $backupCodesEncrypted = $tfa['backup_codes'];
            $backupCodesData = $encryption->decrypt($backupCodesEncrypted);
            
            if ($backupCodesData) {
                $backupManager = new TwoFactorBackupCodes();
                $codes = $backupManager->deserialize($backupCodesData);
                
                if ($codes !== null) {
                    $result = $backupManager->validateCode($code, $codes);
                    
                    if ($result['valid']) {
                        // Save updated codes
                        $tfa = $this->ensurePerUserEncryption($user['OID'], $tfa, $secret, $result['codes']);
                        $encryption = new TwoFactorEncryption($tfa['encryption_key'] ?? null);
                        $newData = $backupManager->serialize($result['codes']);
                        $encrypted = $encryption->encrypt($newData);
                        
                        $CI->cceClient->set($user['OID'], 'TwoFactorAuth', [
                            'backup_codes' => $encrypted,
                            'last_used' => time()
                        ]);
                        $this->syncSshTwoFactorArtifacts($user, $secret, $result['codes']);
                        
                        return ['found' => true, 'valid' => true];
                    }
                }
            }
        }
        
        // Update failed attempts
        $failedAttempts = ($tfa['failed_attempts'] ?? 0) + 1;
        $lockoutUntil = ($failedAttempts >= 5) ? time() + 900 : 0;
        
        $CI->cceClient->set($user['OID'], 'TwoFactorAuth', [
            'failed_attempts' => $failedAttempts,
            'locked_until' => $lockoutUntil
        ]);
        
        return ['found' => true, 'valid' => false];
    }
    
    /**
     * Verify using legacy ~/.google_authenticator file
     * Maintains backward compatibility
     * 
     * @param string $username Username
     * @param string $code Code to verify
     * @return array ['valid' => bool]
     */
    private function verifyLegacy2FA($username, $code) {
        // Include legacy Sonata for backward compatibility
        require_once APPPATH . '../vendor/autoload.php';
        $g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        
        $secretKey = $this->getSecretKey($username);
        
        if (empty($secretKey)) {
            return ['valid' => false];
        }
        
        // Verify TOTP
        if ($g->checkCode($secretKey, $code)) {
            return ['valid' => true];
        }
        
        // Check backup codes
        $backupCodes = $this->getBackupCodes($username);
        foreach ($backupCodes as $testcode) {
            if ($code === $testcode) {
                return ['valid' => true];
            }
        }
        
        return ['valid' => false];
    }
    
    /**
     * Auto-migrate legacy 2FA to modern encrypted storage
     * 
     * @param string $username Username to migrate
     */
    private function migrateLegacyToModern($username) {
        $CI =& get_instance();
        
        try {
            $user = $CI->cceClient->getObject('User', ['name' => $username]);
            if (!$user || !isset($user['OID'])) {
                return;
            }
            
            // If a modern secret already exists in CODB, there is nothing to migrate.
            $tfa = $CI->cceClient->get($user['OID'], 'TwoFactorAuth');
            if ($tfa && isset($tfa['enabled']) && ($tfa['enabled'] == '1') && !empty($tfa['secret_encrypted'])) {
                return;
            }
            
            // Get legacy secret
            $secretKey = $this->getSecretKey($username);
            if (empty($secretKey)) {
                return;
            }
            
            // Get legacy backup codes
            $backupCodes = $this->getBackupCodes($username);
            
            // Encrypt and store
            $storageKey = TwoFactorEncryption::generateStorageKey();
            $encryption = new TwoFactorEncryption($storageKey);
            $encryptedSecret = $encryption->encrypt($secretKey);
            
            // Convert backup codes to new format
            $backupManager = new TwoFactorBackupCodes();
            $codesData = [];
            foreach ($backupCodes as $bc) {
                $codesData[] = [
                    'code' => $bc,
                    'used' => false,
                    'created_at' => time()
                ];
            }
            $encryptedBackupCodes = $encryption->encrypt($backupManager->serialize($codesData));
            
            // Store in CODB
            $CI->cceClient->set($user['OID'], 'TwoFactorAuth', [
                'secret_encrypted' => $encryptedSecret,
                'backup_codes' => $encryptedBackupCodes,
                'encryption_key' => $storageKey,
                'enabled' => '1',
                'is_legacy' => '0',
                'created_at' => time(),
                'last_used' => time(),
                'failed_attempts' => 0,
                'locked_until' => 0
            ]);
            
            // Rename legacy file (don't delete for safety)
            $homeDir = $this->getUserHomeDirectory($username);
            $legacyFile = "$homeDir/.google_authenticator";
            if (file_exists($legacyFile)) {
                @rename($legacyFile, "$legacyFile.migrated");
            }
            $this->syncSshTwoFactorArtifacts($user, $secretKey, $codesData);
            
            bx_error_log("Login.php: Auto-migrated 2FA for user $username");
            
        } catch (\Exception $e) {
            bx_error_log("Login.php: Failed to migrate 2FA for user $username: " . $e->getMessage());
        }
    }

    /**
     * Check if user has modern CODB-backed 2FA enabled.
     */
    private function hasModern2FA($username) {
        $CI =& get_instance();

        $user = $CI->cceClient->getObject('User', ['name' => $username]);
        if (!$user || !isset($user['OID'])) {
            return false;
        }

        $tfa = $CI->cceClient->get($user['OID'], 'TwoFactorAuth');
        if (!$tfa || !isset($tfa['enabled']) || $tfa['enabled'] != '1') {
            return false;
        }

        if (!isset($tfa['secret_encrypted']) || $tfa['secret_encrypted'] === '') {
            return false;
        }

        return true;
    }

    private function hasLegacy2FA($username) {
        $CI =& get_instance();
        $output = '';
        $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/gauth_check.sh " . $username, $output, 'root', $CI->getBX_SESSION()['sessionId']);
        return ($ret === 0);
    }

    private function isGuiTwoFactorRequired($user) {
        if (!$user || !isset($user['name']) || ($user['name'] === 'admin')) {
            return false;
        }

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

        return ($siteRequired || $serverRequired);
    }

    public function redirect() {

        bx_error_log("Login.php: redirect()");

        // This fires if the URL is /login and we are not yet athenticated.
        if (!session()->get('isLoggedIn')) {
            return redirect()->to(base_url('login'));
        }

        // If we *are* authenticated, we rely on BxPage() to redirect us to whatever start-page we're privileged to in the GUI:
        $CI =& get_instance();

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/news");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();

        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        $page_module = 'gui';
        $page_body[] = '';

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }

    public function logout() {
        bx_error_log("Login.php: logout()");
        setcookie("sessionId", 'expired', "0", "/");
        setcookie("logout", 'true', time()+60, "/");
        // Cleanup errand cookies:
        $keksdose = array('enc_as', 'enc_auth', 'enc_av', 'enc_iv', 'target_url');
        foreach ($keksdose as $keks) {
            setcookie("$keks", "", time() - 3600, "/");
        }
        @session()->destroy();
        return redirect()->to(base_url());
    }

    public function login() {

        $CI =& get_instance();

        helper(['form', 'url']);

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/news");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();

        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        if ($this->request->getMethod() === 'POST') {
            bx_error_log("Login.php: login() with POST data.");
        }
        else {
            bx_error_log("Login.php: login() without POST data.");
        }

        $BX_SESSION = $CI->getBX_SESSION();

        // Set up I18n:
        $i18n = new I18n("base-alpine", $CI->getBX_Locale());
        $system = $CI->getSystem();

        $servername = $system['hostname'] . '.' . $system['domainname'];

        // Strip out the :444 or :81 from the hostname - if present:
        if (preg_match('/:/', $servername)) {
            $hn_pieces = explode(":", $servername);
            $servername = $hn_pieces[0];
        }

        // Handle redirects to HTTP(S) and/or FQDN of server:
        if ((isset($system['GUIaccessType'])) && (isset($system['GUIredirects']))) {
            if ($system['GUIredirects'] === "1") {
                if ($servername != $_SERVER['SERVER_NAME']) {
                    // Correct $https_url to servername:
                    $https_url = 'https://' . $servername . ':' . $BX_SESSION['GUI_PORT'] . $_SERVER['REQUEST_URI'];
                    bx_error_log("BxPage: Redirect to: $https_url");
                    header("Location: $https_url");
                    $this->cceClient->bye();
                    exit;
                }
            }
        }

        // Get URI string:
        $request = \Config\Services::request();
        $get_uri_string = (string) $request->getPath();

        // URI string extraction:
        $uri_elements = mb_split("\/", $get_uri_string);

        $form_data = $this->request->getPost();
        $get_form_data = $this->request->getGet();

        $wizard = FALSE;
        $URLaddParams = '';
        if (isset($get_form_data['action'])) {
            if ($get_form_data['action'] == 'wizard') {
                $wizard = TRUE;
                $URLaddParams = '?action=wizard';
            }
        }

        //    // Our $system['isLicenseAccepted'] is in the session cache, so we manually check it here to be *really* sure what it is:
        //    $TmpSystem = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        //    if (($TmpSystem['isLicenseAccepted'] == '0') && ($wizard == FALSE)) {
        //        //
        //        //-- Run initial setup wizard:
        //        //
        //
        //        header("Location: /wizard");
        //        exit;
        //    }

        // I18n for our text elements on the login page:
        $page_title = $i18n->getHtml("loginPageTitle", "base-alpine", array("hostname" => $servername));
        $WelcomeMsg = $i18n->getHtml("login","base-alpine",array("hostname" =>$servername));
        $login_text = $i18n->getHtml("loginPageLogin");
        $Username =  $i18n->getHtml("loginPageUsername");
        $Password =  $i18n->getHtml("loginPagePassword");
        $SecureConnect = $i18n->getHtml("loginPageSecurity");
        $loginMessage = $i18n->getHtml("loginOkMessage");
        $loginFailed = $i18n->getHtml("loginAuthFailed");
        $twoFAfailed = $i18n->getHtml("[[base-user.wrong_2fa_code]]");
        $twoFAtext = $i18n->getHtml("[[base-user.2fa_input_field]]");
        $twoFAtext_help = $i18n->getHtml("[[base-user.2fa_input_field_help]]");
        $my_yes = $i18n->getHtml("[[base-swupdate.yes]]");
        $my_no = $i18n->getHtml("[[base-swupdate.no]]");
        $noJS = $i18n->getHtml("[[base-alpine.loginNoJsMessage]]");
        $loginPageUnameNotPW = $i18n->getHtml("[[base-alpine.loginPageUnameNotPW]]");
        $loginPageRevelPWDtxt = $i18n->getHtml("[[base-alpine.loginPageRevelPWDtxt]]");

        // Login has expired. Show "Your login has expired ..." instead:
        if (($uri_elements[0] == "expired") && ($uri_elements[1] == "true")) {
            $loginMessage = $i18n->getHtml("loginExpiredMessage");
        }

        // Willfully logged out of the system. Show the farewell message instead:
        $didLogOut = '';
        if (isset($_COOKIE['logout'])) {
            $didLogOut = $_COOKIE['logout'];
        }

        if ((isset($BX_SESSION['loginUser']['name'])) && ($didLogOut === 'authfail')) {
          $loginMessage = $i18n->getHtml("loginByeMessage");
          setcookie("logout", 'false', time()+60, "/");
        }
        elseif ($didLogOut === 'true') {
          $loginMessage = $i18n->getHtml("loginByeMessage");
          setcookie("logout", 'false', time()+60, "/");
        }
        elseif ($didLogOut === '2fafail') {
          $loginMessage = $twoFAfailed;
          setcookie("logout", '2fafail', time()+60, "/");
        }
        elseif ($didLogOut === 'authfail') {
          $loginMessage = $loginFailed;
          setcookie("logout", 'authfail', time()+60, "/");
        }

        $get_data = $BxPage->getGETPOST('GET');
        $where_are_we = $CI->bx_get_gui_url();
        if (($where_are_we['actual'] !== '/gui') && ($where_are_we['actual'] !== '/login') && (!isset($get_data['auth_check']))) {
            setcookie("target_url", $where_are_we['actual'], time()+2592000, "/");
        }
        if ((isset($_COOKIE['target_url'])) && (!empty($_COOKIE['target_url']))) {
            $redirect_target = $_COOKIE['target_url'];
        }

        $auth_stage = 'NONE';
        $STAGE = 'AUTH';

        //
        //--- CSRF check:
        //

        // Load the security configuration
        $securityConfig = config('Security');

        // Check if CSRF protection is enabled by verifying if CSRFTokenName and CSRFHeaderName are set
        $isCSRFEnabled = !empty($securityConfig->CSRFTokenName) && !empty($securityConfig->CSRFHeaderName);

        if ($isCSRFEnabled) {
            // Get the CSRF token from the form data
            $csrfName = csrf_token();
            $csrfHash = csrf_hash();

            // Get the token submitted with the form
            $submittedToken = $this->request->getPost($csrfName);

            // Check if the submitted token matches the session token
            if ($submittedToken !== $csrfHash) {

                // CSRF token is invalid - don't proceed. Go back to previous page:
                bx_error_log("Login.php: Invalid CSRF token. Got $submittedToken and expected to get $csrfHash");
                return redirect()->back()->with('error', 'Invalid CSRF token');
            }
        }

        $URLaddParams='?auth_check=2FA';
        if (isset($get_data['auth_check'])) {
            if (($get_data['auth_check'] === '2FA') && ($BX_SESSION['loginName'] != '') && ($BX_SESSION['sessionId'] != '')) {
                $auth_stage = '2FA';
                $STAGE = '2FA';
                $URLaddParams='?auth_check=2FACHECK';
                $CI->setAUTH_STAGE('2FA');
            }
            else {
                $auth_stage = $get_data['auth_check'];
            }
        }

        if ($wizard == TRUE) {
            $redirect_target = "/wizard?from=urlparser";
        }
        elseif (($BX_SESSION['loginName'] != '') && ($BX_SESSION['sessionId'] != '') && ($auth_stage === '2FACHECK')) {
            // User passed username/password check, but hasn't used 2FA yet:
            $redirect_URL['actual'] = '/login';
            $redirect_target = "/gui";
            bx_error_log("Login: User " . $BX_SESSION['loginName'] . " is authenticated, 2FA needs to be provided. Request-URI rewritten to: " . $redirect_URL['actual']);
        }
        else {
            // Redirect to GUI and let BxPage do another redirect. Yeah, this is lazy.
            $redirect_target = "/gui";
        }

        // Get Theme information from Cookie:
        if (isset($_COOKIE['theme_switcher_php-style'])) {
            $primaryColor = $_COOKIE['theme_switcher_php-style'];
            if ($primaryColor != "") {
                if (preg_match('/^theme_(.*)\.css$/', $primaryColor, $treffer)) {
                    $colorArray = array("blue", "navy", "red", "green", "magenta", "brown");
                    if (in_array($treffer[1], $colorArray)) {
                        $primaryColor = $treffer[1];
                    }
                    else {
                        $primaryColor = 'blue';
                    }
                }
                if (preg_match('/^switcher\.css$/', $primaryColor)) {
                    $primaryColor = 'black';
                }
            }
        }
        else {
            // No cookie for color. Return default color:
            $primaryColor = 'blue';
        }

        $twoFA_username_field = '';
        if (isset($_COOKIE['loginName'])) {
            $twoFA_username_field = $_COOKIE['loginName'];
        }
        $twoFA_sessionId_field = '';
        if (isset($_COOKIE['sessionId'])) {
            $twoFA_sessionId_field = $_COOKIE['sessionId'];
        }

        $ini_langs = initialize_languages(FALSE);
        $locale = $ini_langs['locale'];
        $localization = $ini_langs['localization'];
        $charset = $ini_langs['charset'];

        // We pre-populate the $data array with defaults:
        $data = array(
              'localization' => $localization,
              'stage' => $STAGE, // Which parts of the login form do we show: auth or 2fa?
              'username_field' => '', //$form_data['username_field'],
              'password_field' => '', //$form_data['password_field'],
              'secureConnect' => '', //$secureConnect,
              'page_title' => $page_title,
              'WelcomeMsg' => $WelcomeMsg,
              'Username' => $Username,
              'Password' => $Password,
              'SecureConnect' => $SecureConnect,
              'redirect_target' => $redirect_target,
              'loginMessage' => $loginMessage,
              'loginFailed' => $loginFailed,
              'twoFAfailed' => $twoFAfailed,
              'twoFAtext' => $twoFAtext,
              'twoFAtext_help' => $twoFAtext_help,
              'username_field' => $twoFA_username_field,
              'sessionId_field' => $twoFA_sessionId_field,
              'login_text' => $login_text,
              'noJS' => $noJS,
              'ssl_toggle' => '', //$ssl_toggle,
              'URLaddParams' => $URLaddParams,
              'primaryColor' => $primaryColor,
              'loginPageUnameNotPW' => $loginPageUnameNotPW,
              'loginPageRevelPWDtxt' => $loginPageRevelPWDtxt
        );

        //
        //-- Check if login attempts were excessive:
        //

        $invalid_login_check_result = $CI->check_invalid_login();
        if (!empty($invalid_login_check_result['ERROR'])) {
            $CI->add_invalid_login();
            bx_error_log("Login.php: Invalid login attempt. I will remember this! Max attempts reached, redirecting to /login_denied!");
            return redirect()->to(base_url('login_denied'));
        }

        if ($this->request->getMethod() == 'POST') {

            bx_error_log("Login.php: Processing POST data.");

            // If we have form data, we sanitize it:
            $attributes = array();
            $ignore_attributes = array();
            $form_data = $this->request->getPost();
            foreach ($form_data as $key => $value) {
                // Sanitize data received via form fields:
                $form_data[$key] = self::xssafeLogin(trim($value), 'UTF-8', trim($key));
            }
            $required_keys = array('username_field', 'password_field', 'secureConnect', 'redirect_target');
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes);
            $form_data = $attributes;

            // get session ID
            $sessionId = '';
            if (isset($_COOKIE['sessionId'])) {
                $sessionId = $_COOKIE['sessionId'];
            }

            // Do not allow 'api-admin' to login:
            if ($form_data['username_field'] === 'api-admin') {
                $form_data['password_field'] = '';
            }

            if ((isset($form_data['username_field'])) && (isset($form_data['password_field']))) {
                bx_error_log("Login.php: We have a Username and Password.");
                if (($form_data['username_field'] != '') && ($form_data['password_field'] != '')) {
                    $sessionId = $CI->cceClient->auth($form_data['username_field'], $form_data['password_field']);

                    if (($sessionId != '') && ($sessionId != 'expired')) {
                        $CI->enc_pwd($form_data['password_field']);

                        // Get 'User' Object and 'Shell' NameSpace:
                        $loginUser = $CI->cceClient->get($CI->cceClient->whoami());
                        $userShell = $CI->cceClient->get($loginUser['OID'], 'Shell');

                        // Store BX_SESSION and initialize Session-Data:
                        $CI->setBX_SESSION_sessionId($sessionId);
                        $CI->setAUTH_STAGE('PWDAUTH');
                        $CI->setBX_SESSION($form_data['username_field'], $sessionId, $loginUser, $userShell['enabled']);
                        $CI->setUserLogged($sessionId);

                        // Force ServerScriptHelper to use the new credentials:
                        $CI->serverScriptHelper = new ServerScriptHelper($sessionId, $form_data['username_field']);
                        $CI->setSSH($CI->serverScriptHelper);
                        $CI->cceClient = $CI->serverScriptHelper->getCceClient();

                        // Get BX_SESSION again:
                        $BX_SESSION = $CI->getBX_SESSION();
                    }
                }
                else {
                    bx_error_log("Login.php: Username and Password are INCORRECT!");
                }
            }

            if (($sessionId != '') && ($sessionId != 'expired')) {
                bx_error_log("Login.php: We have a sessionId and we have a Username and Password. Using authkey()");
                $CI->cceClient->authkey($form_data['username_field'], $sessionId);

                bx_error_log("Login.php: Updating Session saved 'System' Object with the full data. auth_stage: $auth_stage");

                // Get System Object WITHOUT using the cache:
                $this->BX_System = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));

                // Update 'System' Object in Redis/Valgrind cache:
                $sys_key = 'admserv:cache:cce:System';
                $this->rset($sys_key, $this->BX_System, 15); // 15s is safe

                // Update 'System' Object in BaseController:
                $CI->setSystem($this->BX_System);

                if ($auth_stage === '2FACHECK') {
                    $submittedToken = '';
                    if (isset($form_data['actual_token_field'])) {
                        $submittedToken = trim((string) $form_data['actual_token_field']);
                    }
                    if (($submittedToken === '') && isset($form_data['token_field'])) {
                        $submittedToken = trim((string) $form_data['token_field']);
                    }

                    // 2FA via Google-Authenticator:
                    if (!Login::verifyCode($form_data['username_field'], $submittedToken)) {
                        bx_error_log("Login.php: Invalid login attempt due to 2FA failure. I will remember this!");
                        $CI->add_invalid_login();

                        setcookie("sessionId", 'expired', "0", "/");
                        setcookie("logout", '2fafail', time()+60, "/");
                        @session()->destroy();
                        $CI->setUserLogged();

                        bx_error_log("Login.php: We had a sessionId, but 2FA for user '" . $form_data['username_field'] . "' failed! Redirecting to /login!");
                        return redirect()->to(base_url('login'));
                    }
                    else {
                        bx_error_log("Login.php: 2FA check for user '" . $form_data['username_field'] . "' passed.");
                    }
                }
                else {
                    // Is 2FA required for GUI logins?

                    //bx_error_log("Login.php: Do we need to use 2FA?");
                    if ($system['gui_2fa'] === '0') {
                        // 2FA is not required for GUI logins. Bypassing check:
                        $need_to_run_2FA_check = 0;
                        bx_error_log("Login.php: 2FA is not required for GUI logins. Bypassing check.");
                    }
                    else {
                        // Who needs to use 2FA:
                        $CI->setAUTH_STAGE('PWDAUTH');
                        $need_to_run_2FA_check = 0;
                        if ($system['gui_2fa_users'] === 'ALL') {
                            // EVERYBODY!
                            $need_to_run_2FA_check = 1;
                            $priv_group = 'ALL';
                        }
                        elseif (($system['gui_2fa_users'] === 'ADMINS') && (($CI->getAllowed('systemAdministrator')) || ($CI->getAllowed('adminUser')))) {
                            // All special admin accounts:
                            $need_to_run_2FA_check = 1;
                            $priv_group = 'ADMINS';
                        }
                        elseif (($system['gui_2fa_users'] === 'PRIVILEGED') && (($CI->getAllowed('systemAdministrator')) || ($CI->getAllowed('adminUser')) || ($CI->getAllowed('siteAdmin')))) {
                            // Admins and siteAdmins:
                            $need_to_run_2FA_check = 1;
                            $priv_group = 'PRIVILEGED';
                        }
                        else {
                            $need_to_run_2FA_check = 0;
                            $priv_group = 'REGULAR';
                        }

                        $auth_line = "doesn't have to use 2FA";
                        if ($need_to_run_2FA_check === 1) {
                            $auth_line = "has to use 2FA";

                            if ($this->hasModern2FA($BX_SESSION['loginName'])) {
                                $need_to_run_2FA_check = 1;
                                $auth_line .= " and has a modern TwoFactorAuth record";
                            }
                            else {
                                // Find out if user has the Google Authenticator config in his homedir:
                                if ($this->hasLegacy2FA($BX_SESSION['loginName'])) {
                                    $need_to_run_2FA_check = 1;
                                    $auth_line .= " and has a .google_authenticator file";
                                }
                                else {
                                    $need_to_run_2FA_check = 0;
                                    $auth_line .= " but has neither modern 2FA nor a .google_authenticator file";
                                }
                            }
                        }
                        bx_error_log("Login.php: User '" . $form_data['username_field'] . "' is in privilege group '" . $priv_group . "' and " . $auth_line . ".");
                    }

                    if ($need_to_run_2FA_check === 1) {
                        $CI->setTwoFactorSetupRequiredState(false);
                        bx_error_log("Login.php: auth_stage: $auth_stage - redirecting to: /login?auth_check=2FA");
                        return redirect()->to(base_url('login') . '?auth_check=2FA');
                    }
                    else {
                        bx_error_log("Login.php: auth_stage: $auth_stage - bypassing 2FA.");
                    }
                }

                // If we have this IP logged as offender, then we remove it:
                $CI->remove_invalid_login();

                // Get theme preference:
                $BX_SESSION = $CI->getBX_SESSION();
                if (!isset($BX_SESSION['loginUser']['gui_theme'])) {
                    $User = $CI->cceClient->getObject('User', array('name' => $BX_SESSION['loginName']));
                    $gui_theme = $User['gui_theme'];
                }
                else {
                    $gui_theme = $BX_SESSION['loginUser']['gui_theme'];
                }

                // Set theme preference:
                setcookie("gui_theme", $gui_theme, "0", "/");
                $CI->setBX_SESSION_GuiTheme($gui_theme);
                $CI->setAUTH_STAGE('SUCCESS');
                $BX_SESSION = $CI->getBX_SESSION();

                if ($this->isGuiTwoFactorRequired($BX_SESSION['loginUser']) && !$this->hasModern2FA($BX_SESSION['loginName']) && !$this->hasLegacy2FA($BX_SESSION['loginName'])) {
                    $CI->setTwoFactorSetupRequiredState(true);
                    bx_error_log("Login.php: GUI 2FA is required but not configured for user '" . $BX_SESSION['loginName'] . "'. Redirecting to /user/personalTwoFactor");
                    return redirect()->to('/user/personalTwoFactor');
                }

                $CI->setTwoFactorSetupRequiredState(false);

                if ((isset($_COOKIE['target_url'])) && (!empty($_COOKIE['target_url'])) && ($_COOKIE['target_url'] !== 'login')) {
                    $redirect_target = $_COOKIE['target_url'];
                }
                else {
                    $redirect_target = '/gui';
                }

                bx_error_log("Login.php: Final redirect to: " . $redirect_target);
                return redirect()->to(base_url($redirect_target));
            }
            else {
                bx_error_log("Login.php: Invalid login attempt. I will remember this!");
                $CI->add_invalid_login();

                setcookie("sessionId", 'expired', "0", "/");
                setcookie("logout", 'authfail', time()+60, "/");
                @session()->destroy();

                bx_error_log("Login.php: No sessionId yet, so the login must have failed! Redirecting to /login!");
                return redirect()->to(base_url('login'));
            }

            // Get 'System' object
            bx_error_log("Login: Fetching System");
            $system = $CI->cceClient->getObject('System');
            bx_error_log("Login: setSystem()");
            $CI->setSystem($system);
            bx_error_log("Login: getSSH()");
            $SSH = $CI->getSSH();

            if ($attributes['redirect_target'] != '') {
                $attributes['redirect_target'] = html_entity_decode($attributes['redirect_target']);
                bx_error_log("Login: Request-URI actual: " . $attributes['redirect_target']);
                return redirect()->to(base_url($attributes['redirect_target']));
            }
            else {
                bx_error_log("Login: Request-URI actual: /gui");
                return redirect()->to(base_url('gui'));
            }
        }

        return view('User\Views\elmer_login', $data);
        exit;
    }

    public function profile() {

        $data = [];
        helper(['form']);

        if ($this->request->getMethod() == 'post') {
            if ($this->request->getVar('fmode') == 'cancel') {
                return redirect()->to(base_url());
            }
            $response = $this->usersLib->profile();
            if ($response->status != \Utils\Libraries\UtilsResponseLib::$SUCCESS) {
                $data['validation'] = $response->error->validation;
            } else {
                return redirect()->to(base_url('profile'));
            }
        }

        $data['user'] = $this->usersLib->getuserById();

        return view('User\Views\profile', $data);
    }

    private function decryptBackupCodes($tfa) {
        if (!isset($tfa['backup_codes']) || empty($tfa['backup_codes'])) {
            return array();
        }

        $encryption = new TwoFactorEncryption($tfa['encryption_key'] ?? null);
        $backupCodesData = $encryption->decrypt($tfa['backup_codes']);
        if (!$backupCodesData) {
            return array();
        }

        $backupManager = new TwoFactorBackupCodes();
        $codes = $backupManager->deserialize($backupCodesData);
        return is_array($codes) ? $codes : array();
    }

    private function ensurePerUserEncryption($userOid, $tfa, $secret, $backupCodes = null) {
        if (!empty($tfa['encryption_key'])) {
            return $tfa;
        }

        $storageKey = TwoFactorEncryption::generateStorageKey();
        $encryption = new TwoFactorEncryption($storageKey);
        $updates = array(
            'encryption_key' => $storageKey,
            'secret_encrypted' => $encryption->encrypt($secret)
        );

        if ($backupCodes !== null) {
            $backupManager = new TwoFactorBackupCodes();
            $updates['backup_codes'] = $encryption->encrypt($backupManager->serialize($backupCodes));
        }

        $CI =& get_instance();
        $CI->cceClient->set($userOid, 'TwoFactorAuth', $updates);
        return array_merge($tfa, $updates);
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

    private function syncRootTwoFactorArtifacts($sourceFile, $sessionId) {
        $CI =& get_instance();
        $command = 'if ! /bin/grep -q ' . escapeshellarg('^PermitRootLogin without-password') . ' /etc/ssh/sshd_config 2>/dev/null; then /bin/cp ' . escapeshellarg($sourceFile) . ' /root/.google_authenticator; /bin/chown root:root /root/.google_authenticator; /bin/chmod 0400 /root/.google_authenticator; id -nG root | /bin/grep -qw google-authenticator || /usr/sbin/usermod -aG google-authenticator root; else /bin/rm -f /root/.google_authenticator; id -nG root | /bin/grep -qw google-authenticator && /usr/bin/gpasswd -d root google-authenticator || :; fi';
        $CI->serverScriptHelper->shell($command, $output, 'root', $sessionId);
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

    // XSS cleaner. 
    //
    // Please note: The regexp are taken from basetypes.schema for the corresponding inputs and conform 
    // with what CODB would accept in the fields 'username' and 'password'.
    public function xssafeLogin($data, $encoding='UTF-8', $type='') {
        if ($type == 'username_field') {
            if (!preg_match('/^[A-Za-z0-9\._-]+$/', $data)) {
                $data = '';
                return $data;
            }
            else {
                return $data;
            }
        }
        elseif ($type == 'password_field') {
            if (!preg_match('/^[^\001-\037\042\177]{6,32}$/', $data)) {
                $data = '';
                return $data;
            }
            else {
                return $data;
            }
        }
        else {
            return htmlspecialchars($data,ENT_QUOTES | ENT_HTML401,$encoding);
        }
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
