<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes = Services::routes();

// Load the system's routing file first, so that the app and ENVIRONMENT
// can override as needed.
if (file_exists(SYSTEMPATH . 'Config/Routes.php')) {
    require SYSTEMPATH . 'Config/Routes.php';
}

/**
 * --------------------------------------------------------------------
 * Router Setup
 * --------------------------------------------------------------------
 *
 * Runtime route configuration is intentional for BlueOnyx/Chorizo
 * (module discovery). Config\Routing.php defaults must not replace this.
 */
$routes->setDefaultNamespace('');
$routes->setDefaultController('Gui\Controllers\ErrorPages::Redirect');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(true);
$routes->set404Override('Gui\Controllers\ErrorPages::PageNotFound404');
$routes->setAutoRoute(false);

/**
 * --------------------------------------------------------------------
 * Route Definitions
 * --------------------------------------------------------------------
 */

// Default route
$routes->get('/', 'Gui\Controllers\ErrorPages::Redirect');

// Dynamically load routes from modules.
// ionCube-encoded module Routes.php cannot run while auto_prepend_file /
// auto_append_file are set (admserv-php ships set_php_headers.php as prepend).
// Clear them only for the duration of these requires.
//
// Shop packages encoded with the PHP 8.1 + license pipeline (encode_81.sh)
// fatal the whole GUI on AdmServ PHP 8.2+ if require()'d. Skip those files
// so base YUM updates stay safe until shop RPMs are re-encoded (encode_83).
//
// Config\Modules must NOT list "routes" in $aliases — CI auto-discovery would
// include the same files again without this gate.
//
// Skipped modules: menu XML URLs are mapped to Gui\Controllers\ShopModuleUpgrade
// so menu clicks show an upgrade notice instead of a bare 404.
$modulesPath = ROOTPATH . 'Modules/';
if (! is_dir($modulesPath)) {
    $modulesPath = '/usr/sausalito/ui/chorizo/ci4/Modules/';
}

$bxRoutesHelper = APPPATH . 'Helpers/bx_module_routes_helper.php';
if (is_readable($bxRoutesHelper)) {
    require_once $bxRoutesHelper;
}

$prevPrepend = ini_get('auto_prepend_file') ?: '';
$prevAppend  = ini_get('auto_append_file') ?: '';
// @ - may be blocked under php_admin_*; best-effort for ionCube modules.
@ini_set('auto_prepend_file', '');
@ini_set('auto_append_file', '');

try {
    if (is_dir($modulesPath)) {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'Routes.php') {
                $path = $file->getPathname();
                if (function_exists('bx_module_routes_require_safe')) {
                    bx_module_routes_require_safe($path);
                } else {
                    require $path;
                }
            }
        }
    }
} finally {
    if ($prevPrepend !== '') {
        @ini_set('auto_prepend_file', $prevPrepend);
    }
    if ($prevAppend !== '') {
        @ini_set('auto_append_file', $prevAppend);
    }
}

if (function_exists('bx_module_routes_register_placeholders')) {
    bx_module_routes_register_placeholders($routes);
}

/**
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 *
 * There will often be times that you need additional routing and you
 * need it to be able to override any defaults in this file. Environment
 * based routes is one such time. require() additional route files here
 * to make that happen.
 */
if (file_exists(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
