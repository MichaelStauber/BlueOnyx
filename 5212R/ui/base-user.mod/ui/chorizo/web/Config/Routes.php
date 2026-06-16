<?php
namespace User\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

//
//-- Login related routes:
//

$routes->add('login', 'User\Controllers\Login::login');
$routes->add('loginHandler', 'User\Controllers\LoginHandler::login');
$routes->add('logout', 'User\Controllers\Login::logout');
$routes->add('logout/true', 'User\Controllers\Login::logout');
$routes->add('expired/true/target', 'User\Controllers\Login::login');
$routes->add('expired/true/target/(:segment)', 'User\Controllers\Login::login');
$routes->add('expired/true/target/(:segment)/(:segment)', 'User\Controllers\Login::login');
$routes->add('expired/true/target/(:segment)/(:segment)/(:segment)/(:segment)', 'User\Controllers\Login::login');
$routes->add('gui', 'User\Controllers\Login::redirect');

//
//-- Old Login related routes for using Filters. Good idea, but not ideal for us.
//

// $routes->add('login', 'User\Controllers\Login::login', ['filter' => 'noauth']);
// $routes->add('logout', 'User\Controllers\Login::logout', ['filter' => 'auth']);
// $routes->add('logout/true', 'User\Controllers\Login::logout', ['filter' => 'auth']);
// $routes->add('expired/true/target', 'User\Controllers\Login::login', ['filter' => 'noauth']);
// $routes->add('expired/true/target/(:segment)', 'User\Controllers\Login::login', ['filter' => 'noauth']);
// $routes->add('expired/true/target/(:segment)/(:segment)', 'User\Controllers\Login::login', ['filter' => 'noauth']);
// $routes->add('expired/true/target/(:segment)/(:segment)/(:segment)/(:segment)', 'User\Controllers\Login::login', ['filter' => 'noauth']);
// $routes->add('gui', 'User\Controllers\Login::redirect', ['filter' => 'auth']);
// $routes->add('login', 'User\Controllers\Login::redirect', ['filter' => 'auth']);

//
//-- User management related routes:
//

$routes->add('user/userList', 'User\Controllers\UserList::index');
$routes->add('user/personalAccount', 'User\Controllers\PersonalAccount::index');
$routes->add('user/personalTwoFactor', 'User\Controllers\PersonalTwoFactor::index');
$routes->add('user/personalEmail', 'User\Controllers\PersonalEmail::index');
$routes->add('user/imapSyncLog', 'User\Controllers\ImapSyncLog::index');
$routes->add('user/userDefaults', 'User\Controllers\UserDefaults::index');
$routes->add('user/userAdd', 'User\Controllers\UserAdd::index');
$routes->add('user/userMod', 'User\Controllers\UserMod::index');
$routes->add('user/userDel', 'User\Controllers\UserDel::index');

//
//-- TwoFactorAuth Admin routes:
//
$routes->add('user/twoFactorAdmin', 'User\Controllers\TwoFactorAdmin::index');
$routes->add('user/twoFactorReset', 'User\Controllers\TwoFactorAdmin::reset');
$routes->add('user/twoFactorUnlock', 'User\Controllers\TwoFactorAdmin::unlock');
$routes->add('user/twoFactorRegenerate', 'User\Controllers\TwoFactorAdmin::regenerateBackupCodes');
