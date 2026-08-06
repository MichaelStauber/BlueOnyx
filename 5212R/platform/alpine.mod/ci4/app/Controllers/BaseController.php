<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use CodeIgniter\Cookie\Cookie;
//use Config\Cookie as CookieConfig;

include_once("BXEncoding.php");
include_once("I18n.php");
include_once("CceClient.php");
include_once("ArrayPacker.php");
include_once("ServerScriptHelper.php");
include_once("Capabilities.php");
include_once("PasswordGenerator.php");
include_once("StupidPass.php");

use BXEncoding;
use I18n;
use CceClient;
use ArrayPacker;
use ServerScriptHelper;
use Capabilities;
use PasswordGenerator;
use StupidPass;
use DateTime;

/**
 * Class BaseController
 *
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 * Extend this class in any new controllers:
 *     class Home extends BaseController
 *
 * For security be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Instance of the main Request object.
     *
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var array
     */
    //protected $helpers = ['blueonyx', 'uifc_ng', 'filesystem', 'text', 'raid_helper', 'updatelib_helper'];
    protected $helpers = ['form', 'blueonyx', 'uifc_ng', 'filesystem', 'text', 'raid_helper', 'updatelib_helper', 'htmlpurifier', 'validation'];

    public $serverScriptHelper;
    public $active_theme;
    public $default_theme_timer;
    public $allowed_themes;
    public $elmer_theme;
    public $auth_stage = 'NONE';
    public $DEBUG;
    public $session;
    protected $cceClient;
    protected $BX_SESSION;
    protected $BX_System;
    protected $BX_Support;
    protected $BX_MySQL;
    protected $BX_MySQL_Error;
    protected $ini_langs;
    protected $UserCapabilities;
    protected $agent;
    protected $GUI_Login_DDOS_Protection;
    protected $GUI_Login_MAX_Attempts;
    protected $GUI_Login_MAX_Attempts_TimeFrame;
    protected $GUI_Login_Grace_Time;
    protected $redis;

    protected function rprefix(): string {
        $r = $this->redis();
        if (!$r) return 'admserv:cache:v0:'; // fallback

        $epochKey = 'admserv:cache:epoch';
        $epoch = $r->get($epochKey);
        if ($epoch === false || $epoch === null || $epoch === '') {
            $epoch = '1';
            // keep epoch around basically forever
            $r->set($epochKey, $epoch);
        }
        return 'admserv:cache:v' . $epoch . ':';
    }

    protected function rkey(string $key): string {
        return $this->rprefix() . $key;
    }

    public function redis() {
        if ($this->redis) return $this->redis;

        try {
            $r = new \Redis();
            $r->pconnect('127.0.0.1', 6379, 1.0);
            $r->select(3);
            $this->redis = $r;
            return $r;
        } catch (\Throwable $e) {
            return null; // fail open
        }
    }

    public function rget($key) {
        $r = $this->redis();
        if (!$r) return null;
        $v = $r->get($this->rkey($key));
        if ($v === false) return null;
        // Try JSON first (safe, no object injection)
        $decoded = json_decode($v, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        // Legacy PHP serialized — safe deserialize, no objects
        $result = @unserialize($v, ['allowed_classes' => false]);
        return ($result !== false || $v === 'b:0;') ? $result : null;
    }

    public function rset($key, $value, $ttl) {
        $r = $this->redis();
        if (!$r) return false;
        // Use JSON instead of PHP serialize to prevent Object Injection
        return $r->setex($this->rkey($key), $ttl, json_encode($value));
    }

    public function redisZapCache(): bool {
        $r = $this->redis();
        if (!$r) return false;

        $epochKey = 'admserv:cache:epoch';
        $oldEpoch = $r->get($epochKey);
        $oldEpoch = (is_numeric($oldEpoch) ? (int)$oldEpoch : 1);

        $newEpoch = $oldEpoch + 1;
        $r->set($epochKey, (string)$newEpoch);

        // Mark old epoch as discardable after 24h
        $r->setex("admserv:cache:epoch:{$oldEpoch}:obsolete", 86400, '1');

        return true;
    }

    /**
     * Constructor.
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        if (is_file("/etc/DEBUG")) {
            $this->setDebug(TRUE);
        }
        else {
            $this->setDebug(FALSE);
        }

        // Start Profiler-Timer:
        $timer = timer();
        timer('BaseController');

        // Making this CI instance available to subordinate classes:
        register_ci_instance($this);

        //
        //--- Handle Elmer Theme integration:
        //

        //$this->elmer_theme = array('header_color' => 'theme-6-active', 'primaryColor' => 'pimary-color-blue', 'css' => 'style.css');
        //$this->elmer_theme = array('header_color' => 'theme-6-active', 'primaryColor' => 'pimary-color-blue', 'css' => 'style_dark.css');

        // Establish a default:
        $this->active_theme = 'elmer';

        // Establish allowed values:
        $possible_elmer_header_colors = array(
                                                'theme-1-active',
                                                'theme-2-active',
                                                'theme-3-active',
                                                'theme-4-active',
                                                'theme-5-active',
                                                'theme-6-active'
                                            );

        $possible_elmer_primaryColors = array(
                                                'pimary-color-red',
                                                'pimary-color-blue',
                                                'pimary-color-green',
                                                'pimary-color-yellow',
                                                'pimary-color-pink',
                                                'pimary-color-orange',
                                                'pimary-color-gold',
                                                'pimary-color-silver'
                                            );

        $possible_elmer_css = array('style.css', 'style_dark.css');

        // Establish defaults:
        $elmer_header_color = 'theme-6-active';
        $elmer_primaryColor = 'pimary-color-blue';
        $elmer_css = 'style.css';

        // Compare cookies with allowed values and use them if they are within the allowed ranges:
        if ((isset($_COOKIE['header_color'])) && (isset($_COOKIE['primaryColor'])) && (isset($_COOKIE['css']))) {
            if (in_array($_COOKIE['header_color'], $possible_elmer_header_colors)) {
                $elmer_header_color = $_COOKIE['header_color'];
            }
            if (in_array($_COOKIE['primaryColor'], $possible_elmer_primaryColors)) {
                $elmer_primaryColor = $_COOKIE['primaryColor'];
            }
            if (in_array($_COOKIE['css'], $possible_elmer_css)) {
                $elmer_css = $_COOKIE['css'];
            }
        }

        // Set Theme active with either the defaults OR the sanitized values from the cookies:
        $this->elmer_theme = array('header_color' => $elmer_header_color, 'primaryColor' => $elmer_primaryColor, 'css' => $elmer_css);

        // Set up BX_SESSION:
        $loginName = '';
        if (isset($_COOKIE['loginName'])) {
            $loginName = $_COOKIE['loginName'];
        }
        $sessionId = '';
        if (isset($_COOKIE['sessionId'])) {
            $sessionId = $_COOKIE['sessionId'];
            if ($sessionId == '000000000000000000000000000000000000000000000000000000000000000') {
                $sessionId = '';
            }
        }
        $locale = 'en_US';
        if (isset($_COOKIE['locale'])) {
            $locale = $_COOKIE['locale'];
        }
        $localization = 'en-US';
        if (isset($_COOKIE['localization'])) {
            $locale = $_COOKIE['localization'];
        }
        $charset = 'UTF-8';
        if (isset($_COOKIE['charset'])) {
            $charset = $_COOKIE['charset'];
        }
        $bx_session = '';
        if (isset($_COOKIE['bx_session'])) {
            $bx_session = $_COOKIE['bx_session'];
        }
        $csrf_cookie_name = '';
        if (isset($_COOKIE['BlueOnyx_CSRF_cookie'])) {
            $csrf_cookie_name = $_COOKIE['BlueOnyx_CSRF_cookie'];
        }

        //
        // security.tokenName = 'BlueOnyx_CSRF_token'
        // security.headerName = 'X-CSRF-TOKEN'
        // security.cookieName = 'BlueOnyx_CSRF_cookie'
        //

        $this->BX_SESSION = array(
            'loginName' => $loginName, 
            'sessionId' => $sessionId, 
            'loginUser' => '', 
            'userShell' => '',
            'locale' => $locale,
            'localization' => $localization,
            'charset' => $charset,
            'csrf_protection' => '0',
            'csrf_token_name' => 'BlueOnyx_CSRF_token', //$bx_session,
            'csrf_cookie_name' => $csrf_cookie_name,
            'gui_theme' => $this->active_theme,
            'elmer_theme' => $this->elmer_theme,
            'auth_stage' => $this->auth_stage
            );
        global $BX_SESSION;

        // Fast check to see if CCEd is responding as expected:
        //timer('BaseController_CCE_Check');
        //$cce_check = `echo "BYE"|/usr/sausalito/bin/cceclient|grep -E '^200 READY|^202 GOODBYE'|wc -l`;
        //ltrim($cce_check);
        //if ($cce_check == "0") {
        //    // Nope! It is not!
        //    // We run the unstuck script vi SUDO. This is the ONLY command that user
        //    // 'admserv' has sudo capabilities for and it kills off stray CCEd processes,
        //    // pperld and cced.init. It then does a fast cced.init rehash to get us back up:
        //    timer('BaseController_CCE_Restart');
        //    $cce_unstuck = shell_exec('/usr/bin/sudo /usr/sausalito/bin/cced_unstuck.sh');
        //    timer('BaseController_CCE_Restart');
        //}
        //timer('BaseController_CCE_Check');

        timer('BaseController_CCE_Check');

        $cce_key = 'admserv:cache:cced:alive';
        $cce_check = $this->rget($cce_key);

        if ($cce_check === null) {
            $out = `echo "BYE"|/usr/sausalito/bin/cceclient|grep -E '^200 READY|^202 GOODBYE'|wc -l`;
            $out = trim($out);
            $cce_check = ($out === '0') ? 0 : 1;
            $this->rset($cce_key, $cce_check, 2);   // 2s micro-cache
        }

        if ((int)$cce_check === 0) {
            timer('BaseController_CCE_Restart');
            shell_exec('/usr/bin/sudo /usr/sausalito/bin/cced_unstuck.sh');
            $this->rset($cce_key, 1, 2); // optimistic: avoid a restart storm
            timer('BaseController_CCE_Restart');
        }

        // Also check if cced-api is alive. The Unix socket check above only
        // tests CCEd's native socket. cced-api (the HTTP/JSON proxy on port
        // 9092) can be dead while the socket is fine — e.g. after a CCEd
        // restart where cced-api crashed during the constructor phase and
        // systemd gave up restarting it. Without this check, getAll() and
        // other API-dependent calls silently fail.
        $api_key = 'admserv:cache:ccedapi:alive';
        $api_check = $this->rget($api_key);

        if ($api_check === null) {
            // Check if cced-api is active via systemd. This is a local
            // D-Bus call (~1ms) — much faster than a TLS HTTP request
            // (~2s). Since we fixed cced-api.service with Restart=always
            // and StartLimitIntervalSec=0, systemd will never give up
            // restarting it, so is-active is a reliable health indicator.
            $api_check = 0;
            if (!is_file('/etc/NOAPI')) {
                $out = trim(`/usr/bin/systemctl is-active cced-api.service 2>/dev/null`);
                $api_check = ($out === 'active') ? 1 : 0;
            }
            $this->rset($api_key, $api_check, 2);   // 2s micro-cache
        }

        if ((int)$api_check === 0 && (int)$cce_check === 1) {
            // CCEd socket is alive but cced-api is dead. The retry logic
            // in CceApiClient::callApi() (3 attempts with 1s backoff) and
            // the socket fallback in CceClient::getAll() handle this
            // transparently — no separate restart needed here, as admserv
            // lacks permission for systemctl. If CCEd's socket is also
            // dead, cced_unstuck.sh (which now restarts cced-api) runs above.
            if ($this->DEBUG) bx_error_log("BaseController: cced-api is down, relying on retry/fallback");
        }

        timer('BaseController_CCE_Check');

        // Start with empty Capabilities:
        $this->UserCapabilities = array();

        //--------------------------------------------------------------------
        // Preload any models, libraries, etc, here.
        //--------------------------------------------------------------------
        // E.g.:
        $this->session = \Config\Services::session();

        //--------------------------------------------------------------------
        // Load all routes from /usr/sausalito/ui/chorizo/ci4/Modules/...
        //--------------------------------------------------------------------
        //
        // <-- Deprecated. We now use bx_modules_classmap() in /usr/sausalito/ui/chorizo/ci4/vendor/codeigniter4/framework/system/Autoloader/Autoloader.php
        //
        //      $routes = \Config\Services::routes(true);
        //      $files = array_filter(explode(PHP_EOL, `find /usr/sausalito/ui/chorizo/ci4/Modules/ -name Routes.php`));
        //      foreach ($files as $key => $value) {
        //          include $value;
        //      }
        //      print_r("<pre>");
        //      print_r($routes);

        $this->agent = $this->request->getUserAgent();

        // locale and charset setup:
        timer('BaseController_initialize_languages');
        $this->ini_langs = initialize_languages(TRUE);

        // Set cookie for locale if we do NOT have one yet. This HAS to be done here, as the entire
        // i18n she-bang heavily depends on it.
        $this->setBX_Locale($this->ini_langs['locale'], $this->ini_langs['localization'], $this->ini_langs['charset']);
        timer('BaseController_initialize_languages');

        // Find out if CCEd is running. If it is not, we display an error message and quit:
        timer('BaseController_initialize_CceClient');
        if (!$this->serverScriptHelper) {
            $this->cceClient = new CceClient();
        }
        timer('BaseController_initialize_CceClient');

        // Pre-load the 'System' Object from CCEd. This helps to speed things up:
        timer('BaseController_getSystem');
        $this->getSystem();
        timer('BaseController_getSystem');

        // Adminica support is gone. The runtime theme is always Elmer.
        $this->active_theme = 'elmer';
        $this->BX_SESSION['gui_theme'] = 'elmer';
        if (isset($this->BX_SESSION['loginUser']['gui_theme'])) {
            $this->BX_SESSION['loginUser']['gui_theme'] = 'elmer';
        }

        // Popuplate 'BX_SESSION' with CSRF state from CODB 'System' object:
        if (isset($this->BX_System['csrf_protection'])) {
            $this->set_CSRF_State($this->BX_System['csrf_protection']);
        }

        // Popuplate 'BX_SESSION' with DDOS protection state from CODB 'System' object:
        if ($this->BX_System['ddos_protection'] == '1') {
            $this->GUI_Login_DDOS_Protection = $this->BX_System['ddos_protection'];
            $this->GUI_Login_MAX_Attempts = $this->BX_System['ddos_attempts'];
            $this->GUI_Login_MAX_Attempts_TimeFrame = $this->BX_System['ddos_window'];
            $this->GUI_Login_Grace_Time = $this->BX_System['ddos_expire'];
        }
        else {
            // Hardcoded DDOS-Protection values for when the service is disabled:
            $this->GUI_Login_DDOS_Protection = '0';                 // <--- Service disabled
            $this->GUI_Login_MAX_Attempts = '30';                   // <--- 30 Failure attempts
            $this->GUI_Login_MAX_Attempts_TimeFrame = '1800';       // <--- in 30 minutes
            $this->GUI_Login_Grace_Time = '1800';                   // <--- cause a 30 minutes wait time
        }

        // Popuplate 'BX_SESSION' with GUI port we're using:
        if (isset($this->BX_System['GUI_PORT'])) {
            $this->BX_SESSION['GUI_PORT'] = $this->BX_System['GUI_PORT'];
        }
        else {
            $this->BX_SESSION['GUI_PORT'] = '81';
        }

        $this->auth_stage = $this->getAUTH_STAGE();

        // End Profiler-Timer:
        timer('BaseController');
    }

    public function set_CSRF_State($csrf_protection) {
        $this->BX_SESSION['csrf_protection'] = $csrf_protection;
    }

    /* setBX_SESSION($loginName='', $sessionId='', $loginUser='', $userShell='')
     * 
     *  $loginName (String) : Contains Linux Username of the logged in user and must match CODB 'User' Object of User
     *  $sessionId (String) : SessionID as generated by CCE-Auth upon successful CCE login with username/password
     *  $loginUser (Array)  : All data of the CODB 'User' Object of the logged in User
     *  $userShell (Integer): Result of the key 'enabled' from $cce->get('<User-Object-OID>' . 'Shell')
     *
     */

    public function setBX_SESSION($loginName='', $sessionId='', $loginUser='', $userShell='') {

        // Make sure the used locale matches what the User has configured. Otherwise after a User switch we might
        // continue with the previously configured Cookie-Locale of the previous User.
        if (!empty($loginUser['localePreference'])) {
            $override_localization = preg_replace('/_/', '-', $loginUser['localePreference']);
            $this->setBX_Locale($loginUser['localePreference'], $override_localization='en-US', $charset='UTF-8');
        }

        if (isset($loginUser['crypt_password'])) {
            $loginUser['crypt_password'] = '';
        }
        if (isset($loginUser['md5_password'])) {
            $loginUser['md5_password'] = '';
        }
        $this->BX_SESSION['loginName'] = $loginName;
        $this->BX_SESSION['sessionId'] = $sessionId;
        $this->BX_SESSION['loginUser'] = $loginUser;
        $this->BX_SESSION['userShell'] = $userShell;
        if ($this->BX_SESSION['loginName'] == '') {
            if (isset($_COOKIE['loginName'])) {
                delete_cookie("loginName");
            }
        }
        else {
            setcookie("loginName", $this->BX_SESSION['loginName'], [
                'expires' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        if (($this->BX_SESSION['sessionId'] == '') || ($this->BX_SESSION['sessionId'] == '000000000000000000000000000000000000000000000000000000000000000')) {
            if (isset($_COOKIE['sessionId'])) {
                delete_cookie("sessionId");
            }
            if (session()->get('isLoggedIn')) {
                $this->logout();
            }
        }
        else {
            setcookie("sessionId", $this->BX_SESSION['sessionId'], [
                'expires' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        //
        //-- Start: Chorizo's Style handling:
        //

        // Read the Chorizo's Style from User's CODB object:
        if (isset($this->BX_SESSION['loginUser']['ChorizoStyle'])) {
            $usersChorizoStyleObject = json_decode(urldecode($this->BX_SESSION['loginUser']['ChorizoStyle']));
            // Turn Style Object into an Array:
            $usersChorizoStyle = (array) $usersChorizoStyleObject;
        }
        else {
            $usersChorizoStyle = array(
                                    'theme_switcher_php-style' => 'theme_blue.css',
                                    'layout_switcher_php-style' => 'layout_fixed.css',
                                    'nav_switcher_php-style' => 'switcher.css',
                                    'skin_switcher_php-style' => 'skin_light.css',
                                    'bg_switcher_php-style' => 'switcher.css'
                                    );
        }

        // If the user uses a mobile device, we override the fixed layout and switch
        // to the fluid one for a better user-experience:
        if ($this->agent->isMobile()) {
            $usersChorizoStyle['layout_switcher_php-style'] = 'layout_fluid.css';
        }

        // Set ChorizoStyle cookies from session data:
        if (session()->get('ChorizoStyle')) {
            $Session_ChorizoStyle = session()->get('ChorizoStyle');
            foreach ($Session_ChorizoStyle as $key => $value) {
                setcookie($key, $value, '0', '/');
            }
        }
        else {
            foreach ($usersChorizoStyle as $key => $value) {
                // Push out cookies for the Users last known Style:
                setcookie($key, $value, '0', '/');
            }
        }

        //
        //-- Start: Elmer Style handling:
        //

        $ElmerStyle_Default_Array = array('header_color' => 'theme-6-active', 'primaryColor' => 'pimary-color-blue', 'css' => 'style.css');

        if (isset($this->BX_SESSION['loginUser']['ElmerStyle'])) {
            $usersElmerStyleObject = json_decode(urldecode($this->BX_SESSION['loginUser']['ElmerStyle']));
            // Turn Style Object into an Array:
            $usersElmerStyle = (array) $usersElmerStyleObject;
        }
        else {
            // Default;
            $usersElmerStyle = $ElmerStyle_Default_Array;
        }

        // Sense check:
        if (count($usersElmerStyle) === 0) {
            $this->BX_SESSION['elmer_theme'] = $ElmerStyle_Default_Array;
        }

        // Update BX_SESSION['elmer_theme']:
        $this->BX_SESSION['elmer_theme'] = $usersElmerStyle;

        // Set ElmerStyle cookies from session data:
        if (session()->get('ElmerStyle')) {
            $Session_ElmerStyle = session()->get('ElmerStyle');
            foreach ($Session_ElmerStyle as $key => $value) {
                setcookie($key, $value, '0', '/');
            }
        }
        else {
            foreach ($usersElmerStyle as $key => $value) {
                // Push out cookies for the Users last known Style:
                setcookie($key, $value, '0', '/');
            }
        }

        // Set auth status:
        $this->BX_SESSION['auth_stage'] = $this->getAUTH_STAGE();

        // Popuplate 'BX_SESSION' with GUI port we're using:
        if (isset($this->BX_System['GUI_PORT'])) {
            $this->BX_SESSION['GUI_PORT'] = $this->BX_System['GUI_PORT'];
        }
        else {
            $this->BX_SESSION['GUI_PORT'] = '81';
        }

    }

    public function setBX_SESSION_loginName($loginName='') {
        $this->BX_SESSION['loginName'] = $loginName;
        if ($this->BX_SESSION['loginName'] == '') {
            if (isset($_COOKIE['loginName'])) {
                delete_cookie("loginName");
            }
        }
        else {
            setcookie("loginName", $this->BX_SESSION['loginName'], [
                'expires' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
    }

    public function setBX_SESSION_sessionId($sessionId='') {
        $this->BX_SESSION['sessionId'] = $sessionId;
        if (($this->BX_SESSION['sessionId'] == '') || ($this->BX_SESSION['sessionId'] == '000000000000000000000000000000000000000000000000000000000000000')) {
            if (isset($_COOKIE['sessionId'])) {
                //delete_cookie("sessionId");
                setcookie("sessionId", '', [
                    'expires' => 30,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
            if (session()->get('isLoggedIn')) {
                $this->logout();
            }
        }
        else {
            setcookie("sessionId", $this->BX_SESSION['sessionId'], [
                'expires' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
    }

    public function setBX_SESSION_GuiTheme($gui_theme='elmer') {
        $gui_theme = 'elmer';

        $this->BX_SESSION['gui_theme'] = $gui_theme;
        $data['gui_theme'] = $gui_theme;
        session()->set($data);

        if (isset($this->BX_SESSION['loginUser']['gui_theme'])) {
            $this->BX_SESSION['loginUser']['gui_theme'] = $gui_theme;
        }
        $this->BX_SESSION['gui_theme'] = $gui_theme;
        setcookie("gui_theme", $gui_theme, "0", "/");
    }

    // Get status of auth procedure:
    public function getBX_SESSION_GuiTheme() {
        if (session()->get('gui_theme')) {
            return session()->get('gui_theme');
        }
        else {
            return $this->gui_theme;
        }
    }

    public function getBX_SESSION() {
        return $this->BX_SESSION;
    }

    public function getEncrypterWithCustomKey() {
        $keyPath = '/usr/sausalito/capcache/authkey';
        $key = file_exists($keyPath) ? file_get_contents($keyPath) : null;

        // If the key does not exist, generate and save it
        if (!$key) {
            bx_error_log("BC.getEncrypterWithCustomKey(): Regenerating $keyPath");
            $key = bin2hex(random_bytes(32)); // Generate a 256-bit key
            file_put_contents($keyPath, $key);
            chmod($keyPath, 0600);
        }

        // Load the encryption configuration
        $config = new \Config\Encryption();
        $config->key = $key;
        $config->driver = 'OpenSSL';

        // Get the encryption service with the custom key
        $encrypter = \Config\Services::encrypter($config);
        return $encrypter;
    }

    public function enc_as($string) {
        $encrypter = $this->getEncrypterWithCustomKey();
        $encryptedString = $encrypter->encrypt($string);

        // Store the encrypted string in cookie
        setcookie("enc_as", base64_encode($encryptedString), 0, "/", "", false, true); // Last parameters are for security flags
    }

    public function dec_as() {
        if (isset($_COOKIE['enc_as'])) {
            $encrypter = $this->getEncrypterWithCustomKey();
            try {
                $decrypted = $encrypter->decrypt(base64_decode($_COOKIE['enc_as']));
                return $decrypted;
            }
            catch (\Exception $e) {
                // Handle decryption error or invalid data
                return null;
            }
        }
        return null;
    }

    // Set status of auth procedure:
    public function setAUTH_STAGE($auth_stage) {
        $this->auth_stage = $auth_stage;
        $this->BX_SESSION['auth_stage'] = $auth_stage;
        $this->enc_as($auth_stage);
        $data['auth_stage'] = $auth_stage;
        session()->set($data);
    }

    // Get status of auth procedure:
    public function getAUTH_STAGE() {
        if (isset($_COOKIE['enc_as'])) {
            $decryptedAuthStage = $this->dec_as();
            // If decryption is successful, update the auth_stage property and return the decrypted value
            if ($decryptedAuthStage !== null) {
                $this->auth_stage = $decryptedAuthStage;
                // bx_error_log("BC.getAUTH_STAGE(): Returning live 'auth_stage': " . $this->auth_stage);
                return $decryptedAuthStage;
            }
        }

        // If the encrypted 'auth_stage' is not available or decryption failed, fall back to session data
        if (session()->get('auth_stage')) {
            // bx_error_log("BC.getAUTH_STAGE(): Returning session 'auth_stage': " . session()->get('auth_stage'));
            return session()->get('auth_stage');
        }

        // If neither encrypted cookie nor session data is available, return the stored property value
        // bx_error_log("BC.getAUTH_STAGE(): Returning stored 'auth_stage': " . $this->auth_stage);
        return $this->auth_stage;
    }

    public function setBX_Locale($locale='en_US', $localization='en-US', $charset='UTF-8') {
        $this->BX_SESSION['locale'] = $locale;
        $this->BX_SESSION['localization'] = $localization;
        $this->BX_SESSION['charset'] = $charset;
        setcookie("locale", $this->BX_SESSION['locale'], "0", "/");
        setcookie("localization", $this->BX_SESSION['localization'], "0", "/");
        setcookie("charset", $this->BX_SESSION['charset'], "0", "/");
    }

    public function getBX_Locale() {
        return $this->BX_SESSION['locale'];
    }

    public function getBX_ini_langs() {
        return $this->ini_langs;
    }

    public function getBX_Charset() {
        return $this->BX_SESSION['charset'];
    }

    public function setSSH($SSH) {
        $this->serverScriptHelper = $SSH;
    }

    public function getSSH() {
        if (isset($this->serverScriptHelper)) {
            return $this->serverScriptHelper;
        }
        else {
            // We must return an instanciated $this->serverScriptHelper. If it hasn't be instanciated yet, then we must do so now:
            //bx_error_log("BC.getSSH(): serverScriptHelper not yet instanciated. Doing so now.");
            $this->serverScriptHelper = new ServerScriptHelper($this->BX_SESSION['sessionId'], $this->BX_SESSION['loginName']);
            $this->setSSH($this->serverScriptHelper);
            return $this->serverScriptHelper;
        }
    }

    public function setCCE($CCE) {
        $this->cceClient = $CCE;
    }

    public function getCCE() {
        if (isset($this->cceClient)) {           
            return $this->cceClient;
        }
        else {
            return NULL;
        }
    }

    public function setSystem($sys) {
        $this->BX_System = $sys;
        // Set Session-Data:
        $data['System'] = $this->BX_System;
        session()->set($data);
    }

    public function getSystem($forceReload = false) {

        // We want to know which URI caused this:
        $uri = $this->bx_get_gui_url();

        $ignore_uris = array('gui/validation.js', 'gui/pluginsmin.js', 'gui/check_password', 'gui/fullcalendar', 'gui/datepicker', '.elm/dist/css/style.css.map', '.elm/dist/css/lightgallery.css.map', 'gui/services', 'gui/services?q=services', 'gui/metrics', 'gui/metrics?q=shortmem', 'gui/metrics?q=shortload', 'gui/metrics?q=aggnet', 'gui/metrics?q=shortload');

        if ((!empty($this->BX_SESSION['loginName'])) && (!in_array($uri['actual'], $ignore_uris))) {
            bx_error_log("BC.getSystem(): User " . $this->BX_SESSION['loginName'] . ' [' . $_SERVER['REMOTE_ADDR'] . ']' . " is accessing /" . $uri['actual'] . " with " . $this->request->getUserAgent());
        }
        elseif (!in_array($uri['actual'], $ignore_uris)) {
            bx_error_log("BC.getSystem(): Unknown User " . '[' . $_SERVER['REMOTE_ADDR'] . ']' . " is accessing /" . $uri['actual'] . " with " . $this->request->getUserAgent());
        }

        if (!empty($this->BX_System) && !$forceReload) {
            if ($this->getDebug()) {
                bx_error_log("BC.getSystem(): Returning Known System object");
            }
            return $this->BX_System;
        }
        else {
            // Find out if serverScriptHelper has already been initialized:
            $this->serverScriptHelper = $this->getSSH();
            if (!$this->serverScriptHelper) {

                // It has not been initialized yet, so we do it here:
                timer('BaseController_serverScriptHelper');
                if ((empty($this->BX_SESSION['sessionId'])) && (empty($this->BX_SESSION['loginName']))) {
                    if ($this->getDebug()) {
                        bx_error_log("BC.getSystem(): NOT doing init of new ServerScriptHelper() as we're not logged in yet! URI: " . $uri['actual']);
                    }
                    $this->cceClient = new CceClient();
                    $this->cceClient->connect();
                    if ($this->getDebug()) {
                        bx_error_log("BC.getSystem(): Initialized cceClient w/o login details for basic functions.");
                    }
                }
                else {
                    if ($this->getDebug()) {
                        bx_error_log("BC.getSystem(): Doing init of new ServerScriptHelper() from session data.");
                    }
                    $this->serverScriptHelper = new ServerScriptHelper($this->BX_SESSION['sessionId'], $this->BX_SESSION['loginName']);
                    timer('BaseController_getCceClient');
                    $this->cceClient = $this->serverScriptHelper->getCceClient();
                    timer('BaseController_getCceClient');
                }
                timer('BaseController_serverScriptHelper');
            }
            else {
                // Was already initialized. Reuse it:
                $this->cceClient = $this->getCCE();
            }
            timer('BaseController_get_System_Object');
            //$this->BX_System = $this->cceClient->getObject('System');

            $sys_key = 'admserv:cache:cce:System';

            if (!$forceReload) {
                $sys = $this->rget($sys_key);

                // Cache must contain these mandatory keys; otherwise it's corrupt/stale:
                $required_keys = array('OID', 'productName', 'productBuild', 'IPType', 'gateway', 'gateway_IPv6');
                $cache_valid = is_array($sys);
                if ($cache_valid) {
                    foreach ($required_keys as $key) {
                        if (!isset($sys[$key])) {
                            $cache_valid = false;
                            break;
                        }
                    }
                }

                if ($cache_valid) {
                    $this->BX_System = $sys;
                }
                else {
                    if ($this->getDebug()) {
                        bx_error_log("BC.getSystem(): Cache entry for System object is incomplete or missing keys. Reloading from CCE.");
                    }
                    $this->BX_System = $this->cceClient->getObject('System');
                    $this->rset($sys_key, $this->BX_System, 15); // 15s is safe
                }
            }
            else {
                // Force reload from CCE, bypass Redis cache:
                if ($this->getDebug()) {
                    bx_error_log("BC.getSystem(): Force-reloading System object from CCE (bypassing cache)");
                }
                $this->BX_System = $this->cceClient->getObject('System');
                $this->rset($sys_key, $this->BX_System, 15);
            }

            timer('BaseController_get_System_Object');
            if ($this->BX_SESSION['sessionId'] != '') {
                timer('BaseController_get_Support_NameSpace');
                if (isset($_SESSION['Support'])) {
                    if (is_array($_SESSION['Support'])) {
                        $this->BX_Support = $_SESSION['Support'];
                    }
                }
                else {
                    $this->BX_Support = $this->cceClient->get($this->BX_System['OID'], "Support");
                    $data['Support'] = $this->BX_Support;
                    session()->set($data);
                }
                timer('BaseController_get_Support_NameSpace');
            }
            return $this->BX_System;
        }
    }

    public function setSupport($sys) {
        $this->BX_Support = $sys;
    }

    public function getSupport() {
        if (isset($this->BX_Support)) {
            return $this->BX_Support;
        }
        else {
            // Our getSystem() already fetches 'System' . 'Support', so we just re-use it:
            if ($this->BX_SESSION['sessionId'] != '') {
                $this->getSystem();
            }
            return $this->BX_Support;
        }
    }

    public function setUserLogged($sessionId='') {
        if ($sessionId === '') {
            // No sessionId? Reset session data:
            $data = [
                'loginName' => '', 
                'sessionId' => '', 
                'loginUser' => '', 
                'userShell' => '0',
                'locale' => 'en_US',
                'localization' => 'en-US',
                'charset' => 'UTF-8',
                'Capabilities' => '',
                //'System' => '',
                'isLoggedIn' => false
            ];
            $this->UserCapabilities = array();
        }
        else {
            // Get Capabilities this user has and store them im SessionData:
            $sessCaps = session()->get('Capabilities');
            if (is_array($sessCaps)) {
                // 'Capabilities' already present in Session data. Reuse them:
                $this->UserCapabilities = $sessCaps;
            }
            else {
                // 'Capabilities' for this User have not yet been determined. Fetch them for storage in SessionData:
                $Capabilities = new Capabilities($this->cceClient, $this->BX_SESSION['loginName'], $this->BX_SESSION['sessionId']);
                $this->UserCapabilities = $Capabilities->listAllowed();
            }

            // Set Session-Data:
            $data = [
                'loginName' => $this->BX_SESSION['loginName'], 
                'sessionId' => $this->BX_SESSION['sessionId'], 
                'loginUser' => $this->BX_SESSION['loginUser'], 
                'userShell' => $this->BX_SESSION['userShell'],
                'locale' => $this->BX_SESSION['locale'],
                'localization' => $this->BX_SESSION['localization'],
                'charset' => $this->BX_SESSION['charset'],
                'Capabilities' => $this->UserCapabilities,
                //'System' => $this->BX_System,
                'isLoggedIn' => true
            ];
        }

        // Handle Elmer Theme integration:
        if ($this->active_theme === 'elmer') {
            $data['elmer_theme'] = $this->elmer_theme;
        }

        if (isset($this->BX_SESSION['loginUser']['gui_theme'])) {
            $data['gui_theme'] = $this->BX_SESSION['loginUser']['gui_theme'];

        }
        else {
            $data['gui_theme'] = $this->active_theme;
        }

        $data['auth_stage'] = $this->getAUTH_STAGE();

        if (isset($this->BX_SESSION['loginUser']['gui_theme'])) {
            $this->BX_SESSION['loginUser']['gui_theme'] = 'elmer';
        }
        $data['gui_theme'] = 'elmer';

        setcookie("gui_theme", $data['gui_theme'], "0", "/");
        session()->set($data);
        return true;
    }

    public function logout() {
        bx_error_log("BaseController.logout(): Performing logout and destroying session data.");
        // Reset session data:
        $this->setUserLogged('');
        $this->setTwoFactorSetupRequiredState(false);

        // Cleanup errand cookies:
        $keksdose = array('sessionId', 'enc_as', 'enc_auth', 'enc_av', 'enc_iv', 'target_url', 'twofactor_setup_required');
        foreach ($keksdose as $keks) {
            setcookie("$keks", "", time() - 3600, "/");
        }

        if (session()->get('isLoggedIn')) {
            // Destroy session as well:
            @session()->destroy();
            return true;
        }
        else {
            return false;
        }
        return true;
    }

    public function bx_get_gui_url() {
        // Handle redirects to login URL with /expired/true/target/<target> set:
        $request = \Config\Services::request();
        $security = \Config\Services::security();
        $uri = (string) $request->getPath();
        $queryString = http_build_query($request->getGet()); // Get the query string

        // If we timed out and the last call was to /gui/services or /gui/metrics?
        // Then we redirect to start page after renewed login:
        $expired_during_metrics = FALSE;
        if ((preg_match('/expired\/true\/target\/gui\/services/', $uri)) || (preg_match('/expired\/true\/target\/gui\/metrics/', $uri))) {
            $expired_during_metrics = TRUE;
        }

        // Filter out duplicates:
        if (preg_match('/expired\/true\/target/', $uri)) {
            $uri = preg_replace('/expired\/true\/target/', '', $uri);
        }

        if (($uri == '/expired/true/target/') || ($uri == '/expired/true/target/login') || ($uri == '/') || ($uri == '')) {
            $uri = 'login';
            $redir_uri = 'login';
        }
        else {
            $redir_uri = '/expired/true/target/' . $uri;
        }

        if (((preg_match('/gui\/services/', $uri)) || (preg_match('/gui\/metrics/', $uri))) && ($expired_during_metrics === TRUE)) {
            $uri = 'gui';
            $redir_uri = 'gui';
        }

        // Normalize the URI by removing double slashes
        $uri = preg_replace('/\/\//', '/', $uri);
        $redir_uri = preg_replace('/\/\//', '/', $redir_uri);

        // Append the query string if it's not empty
        if ($queryString) {
            $redir_uri .= '?' . $queryString;
            $uri .= '?' . $queryString;

            // Set Session-Data:
            $data['expired_url'] = $redir_uri;
            $data['actual_url'] = $uri;
            session()->set($data);

        }
        return array('actual' => $uri, 'expired' => $redir_uri);
    }

    public function isTwoFactorSetupRestrictionActive() {
        $required = session()->get('twofactor_setup_required');
        if (!$required && isset($_COOKIE['twofactor_setup_required']) && ($_COOKIE['twofactor_setup_required'] === '1')) {
            $required = 1;
            session()->set(['twofactor_setup_required' => 1]);
        }

        if (!$required) {
            return false;
        }

        if (!isset($this->BX_SESSION['loginUser']['name'])) {
            return false;
        }

        return ($this->BX_SESSION['loginUser']['name'] !== 'admin');
    }

    public function setTwoFactorSetupRequiredState($required = false) {
        if ($required) {
            session()->set(['twofactor_setup_required' => 1]);
            setcookie('twofactor_setup_required', '1', 0, '/', '', false, true);
            return;
        }

        session()->remove('twofactor_setup_required');
        setcookie('twofactor_setup_required', '', time() - 3600, '/');
    }

    public function isTwoFactorSetupAllowedUri($uri = null) {
        if (!$this->isTwoFactorSetupRestrictionActive()) {
            return true;
        }

        if ($uri === null) {
            $uri = $this->bx_get_gui_url()['actual'];
        }

        $path = parse_url((strpos($uri, '/') === 0) ? $uri : '/' . $uri, PHP_URL_PATH);
        $path = ltrim((string) $path, '/');

        return in_array($path, array(
            'user/personalTwoFactor',
            'logout',
            'logout/true',
            'login'
        ), true);
    }

    public function enforceTwoFactorSetupRestriction() {
        if (!$this->isTwoFactorSetupRestrictionActive()) {
            return;
        }

        $uri = $this->bx_get_gui_url();
        if ($this->isTwoFactorSetupAllowedUri($uri['actual'])) {
            return;
        }

        bx_error_log("BaseController.enforceTwoFactorSetupRestriction(): Redirecting to /user/personalTwoFactor from " . $uri['actual']);
        header('Location: /user/personalTwoFactor');
        exit;
    }

    // description: checks to see if a user is granted the given capability.
    // param: the name of the CapabilityGroup or CCE-Level capability to check
    // param: the user to check for (default: current)
    // returns: true if the current user has this capability, false otherwise

    public function getAllowed($capName, $oid = -1) {
        $auth_stage = $this->getAUTH_STAGE();
        if (($this->BX_SESSION['sessionId'] == '') || (!in_array($auth_stage, ['SUCCESS', 'PWDAUTH']))) {

            if ($this->BX_SESSION['sessionId'] == '') {
                // No sessionId? Then the session is expired. Run cleanup:
                $auth_stage = 'EXPIRED';
                $this->logout();
            }

            $uri = $this->bx_get_gui_url();
            $log_middle = "BX_SESSION: 'sessionId' = '" . $this->BX_SESSION['sessionId'] . "' - 'auth_stage' = '" . $auth_stage . "'";
            bx_error_log("BaseController.getAllowed(): Fail: $log_middle - Redirecting: " . $uri['actual']);
            header('Location: ' . $uri['expired']);
            exit;
        }

        $this->enforceTwoFactorSetupRestriction();

        // this is quicker besides systemAdministrator should be
        // able to view everything whether there is a capability group
        // or not
        $currentuser = 0;
        if ($oid == -1) {
            $currentuser = 1;
            if (isset($this->BX_SESSION['loginUser']["OID"])) {
                $oid = $this->BX_SESSION['loginUser']["OID"];
            }
            else {
                // No loginUser? Then we have no rights!
                return 0;
            }
        }

        if (($currentuser == 1) && ($this->BX_SESSION['loginUser']['systemAdministrator'])) {
            // We want to know the caps for the current users. AND that user is
            // 'systemAdministrator'. Spare the trouble and return a fast 'yes':
            return 1;
        }

        if ((!$this->BX_SESSION['loginUser']['systemAdministrator']) && ($oid == -1) && ($capName == 'adminUser')) { 
            // Fast 'no' to the question for 'adminUser', because we simply aren't.
            // Do not get get confused here. Resellers are 'adminUser', but we do
            // NOT treat them as such unless they also have the 'systemAdministrator'
            // flag. Without that flag, we do not rate them as 'adminUser':
            return 0;
        }

        if ($capName == 'validUser') {
            // Very basic check to see if a user does exist.
            if (isset($this->BX_SESSION['loginUser']["OID"])) {
                return 1;
            }
            else {
                return 0;
            }
        }

        // Determine full caplevels:
        if ($oid == -1) {
            // We're asking for caps of the currently logged in user:
            if (in_array($capName, $this->UserCapabilities)) {
                return 1;
            }
            else {
                return 0;
            }
            return 0;
        }
        else {
            // We're asking for the caps of anyone else, but NOT the currently logged in User. We do have the $oid of the User we ask for, though:
            $Capabilities = new Capabilities($this->cceClient, $this->BX_SESSION['loginName'], $this->BX_SESSION['sessionId']);
            // Now that $Capabilities has been instanciated, ask for the Caps of the other User identified by $oid:
            $caps = $Capabilities->listAllowed($oid);
            // Check if that other User has the required cap level:
            if (in_array($capName, $caps)) {
                return 1;
            }
            else {
                return 0;
            }
            return 0;
        }
        return 0;
    }

    //--- BX_MySQL_Query():
    //
    // Helper function to connect to MySQL and to execute a query against a database.
    // $database:   The DB we want to run the query against
    // $query:      The SQL statement we want to run
    // returns:     CodeIgniter Object of the result. See: https://codeigniter.com/user_guide/database/results.html
    // Errors:      Can be fetched via $CI->getBX_MySQL_Error() and the optional parameters 'code' and 'message'
    public function BX_MySQL_Query($database = '', $query = '') {
        $this->BX_MySQL = $this->cceClient->getObject('MySQL');
        shell_exec('/usr/bin/sudo /usr/sausalito/bin/mysql_statustrigger.pl');
        $System_MYSQL = $this->cceClient->get($this->BX_System['OID'], "mysql");

        if (($System_MYSQL["enabled"] == "1") && ($System_MYSQL["connectionstatus"] == "1") && (!empty($query))) {

            if ($this->BX_MySQL['sql_host'] == 'localhost') {
                $this->BX_MySQL['sql_host'] = '127.0.0.1';
            }

            $custom = [
                'DSN'      => '',
                'hostname' => $this->BX_MySQL['sql_host'],
                'username' => $this->BX_MySQL['sql_root'],
                'password' => $this->BX_MySQL['sql_rootpassword'],
                'database' => '',
                'DBDriver' => 'MySQLi',
                'DBPrefix' => '',
                'pConnect' => false,
                'DBDebug'  => false,
                'charset'  => 'utf8',
                'DBCollat' => 'utf8_general_ci',
                'swapPre'  => '',
                'encrypt'  => false,
                'compress' => false,
                'strictOn' => false,
                'failover' => [],
                'port'     => is_numeric($this->BX_MySQL['sql_port']) ? (int)$this->BX_MySQL['sql_port'] : 3306,
            ];

            try {
                $db = \Config\Database::connect($custom);
                $db->initialize();

                if (!$db->connID) {
                    throw new \Exception("DB: $database - MySQL connection failed: null connID");
                }

                // Confirm database exists and is usable
                if (!empty($database)) {
                    $check = $db->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '" . addslashes($database) . "'");
                    if ($check === false || $db->error()['code'] !== 0) {
                        $err = $db->error();
                        $this->setBX_MySQL_Error([
                            'code' => $err['code'] ?? '500',
                            'message' => "DB: $database - " . ($err['message'] ?? 'Unknown error')
                        ]);
                        $db->close();
                        return false;
                    }

                    $db->query("USE `" . addslashes($database) . "`");
                    if ($db->error()['code'] !== 0) {
                        $err = $db->error();
                        $this->setBX_MySQL_Error([
                            'code' => $err['code'] ?? '500',
                            'message' => "DB: $database - " . ($err['message'] ?? 'Unknown error')
                        ]);
                        $db->close();
                        return false;
                    }
                }

                $query_result = $db->query($query);

                if ($query_result === false) {
                    $err = $db->error();
                    $this->setBX_MySQL_Error([
                        'code' => $err['code'] ?? '500',
                        'message' => "DB: $database - " . ($err['message'] ?? 'Unknown error')
                    ]);
                    $db->close();
                    return false;
                }

                $this->setBX_MySQL_Error(['code' => '0', 'message' => '']);
                $db->close();
                return $query_result;

            } catch (\Throwable $e) {
                $this->setBX_MySQL_Error([
                    'code' => '500',
                    'message' => $e->getMessage()
                ]);
                return false;
            }
        }

        $CI =& get_instance();
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-mysql", "/gui");
        $i18n = $factory->getI18n();
        $mysql_status_incorrect = $i18n->get('[[base-mysql.mysql_status_incorrect]]');

        $this->setBX_MySQL_Error([
            'code' => '500',
            'message' => $mysql_status_incorrect
        ]);
        return false;
    }

    //--- BX_MySQL_Select():
    //
    // Helper function to connect to MySQL and to execute a SELECT against a database.
    // $database:   The DB we want to run the SELECT against
    // $query:      The SQL statement we want to run
    // returns:     CodeIgniter Object of the result. See: https://codeigniter.com/user_guide/database/results.html
    // Errors:      Can be fetched via $CI->getBX_MySQL_Error() and the optional parameters 'code' and 'message'
    public function BX_MySQL_Select($database = '', $query = '') {
        $this->BX_MySQL = $this->cceClient->getObject('MySQL');
        shell_exec('/usr/bin/sudo /usr/sausalito/bin/mysql_statustrigger.pl');
        $System_MYSQL = $this->cceClient->get($this->BX_System['OID'], "mysql");

        if (($System_MYSQL["enabled"] == "1") && ($System_MYSQL["connectionstatus"] == "1") && (!empty($query))) {

            if ($this->BX_MySQL['sql_host'] == 'localhost') {
                $this->BX_MySQL['sql_host'] = '127.0.0.1';
            }

            $custom = [
                'DSN'      => '',
                'hostname' => $this->BX_MySQL['sql_host'],
                'username' => $this->BX_MySQL['sql_root'],
                'password' => $this->BX_MySQL['sql_rootpassword'],
                'database' => $database,
                'DBDriver' => 'MySQLi',
                'DBPrefix' => '',
                'pConnect' => false,
                'DBDebug'  => false,
                'charset'  => 'utf8',
                'DBCollat' => 'utf8_general_ci',
                'swapPre'  => '',
                'encrypt'  => false,
                'compress' => false,
                'strictOn' => false,
                'failover' => [],
                'port'     => is_numeric($this->BX_MySQL['sql_port']) ? (int)$this->BX_MySQL['sql_port'] : 3306,
            ];

            try {
                $db = \Config\Database::connect($custom);
                $db->initialize();

                if (!$db->connID) {
                    throw new \Exception("DB: $database - MySQL connection failed: null connID");
                }

                $query_result = $db->query($query);

                if ($query_result === false) {
                    $err = $db->error();
                    $this->setBX_MySQL_Error([
                        'code' => $err['code'] ?? '500',
                        'message' => "DB: $database - " . ($err['message'] ?? 'Unknown error')
                    ]);
                    $db->close();
                    return [];
                }

                $this->setBX_MySQL_Error(['code' => '0', 'message' => '']);
                $result = (is_object($query_result) && method_exists($query_result, 'getResultArray'))
                    ? $query_result->getResultArray()
                    : [];

                $db->close();
                return $result;

            } catch (\Throwable $e) {
                $this->setBX_MySQL_Error([
                    'code' => '500',
                    'message' => $e->getMessage()
                ]);
                return [];
            }
        }

        $CI =& get_instance();
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-mysql", "/gui");
        $i18n = $factory->getI18n();
        $mysql_status_incorrect = $i18n->get('[[base-mysql.mysql_status_incorrect]]');

        $this->setBX_MySQL_Error([
            'code' => '500',
            'message' => $mysql_status_incorrect
        ]);
        return [];
    }

    // Set MySQL Errors so that GUI Pages can poll them:
    public function setBX_MySQL_Error($error = '') {
        $this->BX_MySQL_Error = $error;
    }

    // Function that allows GUI pages to poll MySQL errors. Either detailed as array with 'code' and 'message',
    // or by directly asking for 'code' or 'message':
    public function getBX_MySQL_Error($what='') {
        if ((isset($this->BX_MySQL_Error['code'])) && ($what == 'code')) {
            return $this->BX_MySQL_Error['code'];
        }
        if ((isset($this->BX_MySQL_Error['message'])) && ($what == 'message')) {
            return $this->BX_MySQL_Error['message'];
        }
        if (!empty($this->BX_MySQL_Error)) {
            return $this->BX_MySQL_Error;
        }
        else {
            return FALSE;
        }
    }

    // Function to return the remote address of a caller:
    public function bruteForceKey() {
        return $_SERVER["REMOTE_ADDR"];
    }

    public function add_invalid_login() {

        // DDOS protection is disabled. Return early:
        if ($this->GUI_Login_DDOS_Protection == '0') {
            return TRUE;
        }

        $offending_ip = $this->bruteForceKey();
        $login_logfile = '/usr/sausalito/sessions/.gui-invalid-login-attempts';

        $invalids_in = file_get_contents($login_logfile);
        $invalids = json_decode($invalids_in, true);

        $time = time();

        if (is_array($invalids)) {
            foreach ($invalids as $rec_ip => $rec_data) {
                if ((isset($rec_data['time'])) && (isset($rec_data['attempts']))) {
                    // The last recorded time stamp for a failed transaction of this IP has expired by now:
                    if ($rec_data['time'] < $time) {
                        unset($invalids[$rec_ip]);
                    }
                }
            }
        }

        $attempts = '1';
        $penalty = $time + $this->GUI_Login_MAX_Attempts_TimeFrame;
        if (isset($invalids[$offending_ip])) {
            // Update existing record with new expiry time and current number of attempts:
            $attempts = $invalids[$offending_ip]['attempts'];
            $attempts++;
            $invalids[$offending_ip] = array('time' => $penalty, 'attempts' => $attempts);
        }

        if (!isset($invalids[$offending_ip])) {
            // Create new record for this previously unknown IP:
            $invalids[$offending_ip] = array('time' => $penalty, 'attempts' => $attempts); // active for 30 minutes
        }

        $invalids_out = json_encode($invalids);
        write_file($login_logfile, $invalids_out);
    }

    public function remove_invalid_login() {

        // DDOS protection is disabled. Return early:
        if ($this->GUI_Login_DDOS_Protection == '0') {
            return TRUE;
        }

        $offending_ip = $this->bruteForceKey();
        $login_logfile = '/usr/sausalito/sessions/.gui-invalid-login-attempts';

        if (!is_file($login_logfile)) {
            system("touch $login_logfile");
        }

        $invalids_in = file_get_contents($login_logfile);
        $invalids = json_decode($invalids_in, true);

        $time = time();

        if (is_array($invalids)) {
            if (isset($invalids[$offending_ip])) {
                unset($invalids[$offending_ip]);
            }
        }

        $invalids_out = json_encode($invalids);
        write_file($login_logfile, $invalids_out);
    }

    public function check_invalid_login() {

        $invalid_login_check_result = array('ERROR' => FALSE, 'ERROR_MESSAGE' => '');

        // DDOS protection is disabled. Return early:
        if ($this->GUI_Login_DDOS_Protection == '0') {
            return $invalid_login_check_result;
        }

        $offending_ip = $this->bruteForceKey();
        $login_logfile = '/usr/sausalito/sessions/.gui-invalid-login-attempts';

        if (is_file($login_logfile)) {

            $invalids_in = file_get_contents($login_logfile);
            $invalids = json_decode($invalids_in, true);
            $time = time();
            $invalid_results = '';

            if (is_array($invalids)) {
                if (isset($invalids[$offending_ip])) {
                    $offender_timeStamp = $invalids[$offending_ip]['time'];
                    $offender_attempts = $invalids[$offending_ip]['attempts'];

                    //    Possible cases:
                    //
                    //    1.) incorrect login    Attempts <  Allowed    Time <  Limit  (waited long enough)    Result: Retry     OK,       increment fail record 
                    //    2.) incorrect login    Attempts <  Allowed    Time >  Limit                          Result: Retry     OK,       increment fail record 
                    //    3.) incorrect login    Attempts >= Allowed    Time >  Limit                          Result: Retry NOT OK,       increment fail record
                    //    4.) incorrect login    Attempts >= Allowed    Time <  Limit  (waited long enough)    Result: Retry     OK,       delete record
                    //    5.) correct   login                                                                  Result: Retry not needed,   delete records (/login does this for us)
                    //
                    // And yes: The code below could be shorter, but for readability and future extension this is better.

                    if (($offender_attempts < $this->GUI_Login_MAX_Attempts) && ($offender_timeStamp - time() < $this->GUI_Login_MAX_Attempts_TimeFrame - $this->GUI_Login_Grace_Time)) {
                        // Case #1:
                        // User has FEWER than the allowed login attempts and block-time has EXPIRED:

                        // This is fine. Try again.
                    }
                    elseif (($offender_attempts < $this->GUI_Login_MAX_Attempts) && ($offender_timeStamp - time() > $this->GUI_Login_MAX_Attempts_TimeFrame - $this->GUI_Login_Grace_Time)) {
                        // Case #2:
                        // User has FEWER than the allowed login attempts and block-time has NOT EXPIRED:

                        // This is fine. Try again.
                    }
                    elseif (($offender_attempts >= $this->GUI_Login_MAX_Attempts) && ($offender_timeStamp - time() > $this->GUI_Login_MAX_Attempts_TimeFrame - $this->GUI_Login_Grace_Time)) {
                        // Case #3:
                        // User has MORE than the allowed login attempts and block-time has NOT EXPIRED:

                        $invalid_login_check_result = array('ERROR' => TRUE, 'ERROR_MESSAGE' => 'Too many attempts before expiry of penalty!');
                    }
                    elseif (($offender_attempts >= $this->GUI_Login_MAX_Attempts) && ($offender_timeStamp - time() < $this->GUI_Login_MAX_Attempts_TimeFrame - $this->GUI_Login_Grace_Time)) {
                        // Case #4:
                        // User has MORE than the allowed login attempts and block-time has FINALLY EXPIRED:

                        // Remove database entry:
                        $this->remove_invalid_login();
                    }
                }
            }
            if (!empty($invalid_results)) {
                bx_error_log("BC.check_invalid_login(): Too many unsuccessful logins for " . $offending_ip);
            }
        }
        return $invalid_login_check_result;
    }

    public function enc_pwd($string) {
        $encrypter = $this->getEncrypterWithCustomKey();
        $encryptedString = $encrypter->encrypt($string);

        // Store the encrypted string in cookie
        setcookie("enc_auth", base64_encode($encryptedString), 0, "/", "", false, true); // Last parameters are for security flags
    }

    public function dec_pwd() {
        if (isset($_COOKIE['enc_auth'])) {
            $encrypter = $this->getEncrypterWithCustomKey();
            try {
                $decrypted = $encrypter->decrypt(base64_decode($_COOKIE['enc_auth']));
                return $decrypted;
            } catch (\Exception $e) {
                // Handle decryption error or invalid data
                return null;
            }
        }

        return null;
    }

    function setDebug($DEBUG = "") {
        $this->DEBUG = $DEBUG;
    }

    function getDebug() {
        return $this->DEBUG;
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
