<?php
namespace Remote\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('remote/', 'Remote\Controllers\Console::index');
$routes->add('remote/console', 'Remote\Controllers\Console::index');
$routes->add('remote/console/full', 'Remote\Controllers\Console::index');
$routes->add('remote/remote_amdetails', 'Remote\Controllers\Remote_amdetails::index');

// Re-Use existing 401 GUI page from module GUI, ErrorPages::AuthorizationRequired401 under new route:
$routes->add('remote/noaccess', 'Gui\Controllers\ErrorPages::AuthorizationRequired401');
