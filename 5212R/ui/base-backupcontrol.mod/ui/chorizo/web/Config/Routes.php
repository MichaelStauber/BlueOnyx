<?php
namespace Backupcontrol\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('backupcontrol', 'Backupcontrol\Controllers\Desktopcontrol::index');
$routes->add('backupcontrol/desktopcontrol', 'Backupcontrol\Controllers\Desktopcontrol::index');

