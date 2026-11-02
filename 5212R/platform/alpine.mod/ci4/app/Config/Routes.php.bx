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
$modulesPath = ROOTPATH . 'Modules/';
if (! is_dir($modulesPath)) {
    $modulesPath = '/usr/sausalito/ui/chorizo/ci4/Modules/';
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
                require $file->getPathname();
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
