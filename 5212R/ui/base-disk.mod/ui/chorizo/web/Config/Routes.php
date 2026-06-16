<?php
namespace Disk\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('disk/serverDiskUsage', 'Disk\Controllers\ServerDiskUsage::index');
$routes->add('disk/userDiskUsage', 'Disk\Controllers\UserDiskUsage::index');
$routes->add('disk/groupDiskUsage', 'Disk\Controllers\GroupDiskUsage::index');
