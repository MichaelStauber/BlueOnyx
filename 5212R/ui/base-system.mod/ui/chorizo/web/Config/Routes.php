<?php
namespace System\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('system', 'System\Controllers\Sysinfo::index');
$routes->add('system/sysinfo', 'System\Controllers\Sysinfo::index');

