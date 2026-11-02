<?php
/**
 * BlueOnyx phpMyAdmin configuration (SSO via GUI signon).
 *
 * Upstream ships config.sample.inc.php; we install this as config.inc.php.
 * Do not put MySQL root passwords here - the GUI Signon controller sets
 * PMA_USER / PMA_PASSWORD cookies and redirects here.
 */

declare(strict_types=1);

$i = 0;
$i++;

// --- BlueOnyx SSO (retain across upstream upgrades) ---
// blowfish_secret: random 32+ chars; regenerate on first install if empty via %post optional.
$cfg['blowfish_secret'] = 'b95397a593fed523c20caa10ca12018d';
$cfg['TempDir'] = '/tmp';
$cfg['AllowThirdPartyFraming'] = true;

$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'bx_session';
$cfg['Servers'][$i]['SignonCookieParams'] = [
    'lifetime' => 7200,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
];
$cfg['Servers'][$i]['SignonScript'] = 'signon-script-blueonyx.php';
$cfg['Servers'][$i]['SignonURL'] = '/phpmyadmin/signon';
$cfg['Servers'][$i]['LogoutURL'] = '/phpmyadmin/signon';
// --- end BlueOnyx SSO ---

$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['port'] = '';
$cfg['Servers'][$i]['socket'] = '';
$cfg['Servers'][$i]['connect_type'] = 'tcp';
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['controluser'] = '';
$cfg['Servers'][$i]['controlpass'] = '';
$cfg['Servers'][$i]['user'] = '';
$cfg['Servers'][$i]['password'] = '';
$cfg['Servers'][$i]['only_db'] = '';
$cfg['Servers'][$i]['hide_db'] = '';
$cfg['Servers'][$i]['verbose'] = '';
$cfg['Servers'][$i]['pmadb'] = '';
$cfg['Servers'][$i]['bookmarktable'] = '';
$cfg['Servers'][$i]['relation'] = '';
$cfg['Servers'][$i]['table_info'] = '';
$cfg['Servers'][$i]['table_coords'] = '';
$cfg['Servers'][$i]['pdf_pages'] = '';
$cfg['Servers'][$i]['column_info'] = '';
$cfg['Servers'][$i]['history'] = '';
$cfg['Servers'][$i]['verbose_check'] = true;
$cfg['Servers'][$i]['AllowRoot'] = true;
$cfg['Servers'][$i]['AllowDeny']['order'] = '';
$cfg['Servers'][$i]['AllowDeny']['rules'] = [];
$cfg['Servers'][$i]['DisableIS'] = false;

$cfg['DefaultLang'] = 'en';
$cfg['ServerDefault'] = 1;
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
