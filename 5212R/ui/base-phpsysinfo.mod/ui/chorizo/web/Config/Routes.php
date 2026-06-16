<?php
namespace Phpsysinfo\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('phpsysinfo/', 'Phpsysinfo\Controllers\PHPSysinfo::index');
$routes->add('phpsysinfo/sysinfo', 'Phpsysinfo\Controllers\PHPSysinfo::index');

