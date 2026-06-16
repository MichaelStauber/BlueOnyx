<?php
namespace Raid\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('raid/disk_integrity_amdetails', 'Raid\Controllers\Disk_integrity_amdetails::index');
