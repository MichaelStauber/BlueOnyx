<?php
namespace Am\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('am', 'Am\Controllers\Amstatus::index');
$routes->add('am/amStatus', 'Am\Controllers\Amstatus::index');
$routes->add('am/amStatusUpdate', 'Am\Controllers\Amstatus::update');
$routes->add('am/amSettings', 'Am\Controllers\Amsettings::index');
$routes->add('am/cpu_details', 'Am\Controllers\Cpu_details::index');
$routes->add('am/memory_details', 'Am\Controllers\Memory_details::index');
