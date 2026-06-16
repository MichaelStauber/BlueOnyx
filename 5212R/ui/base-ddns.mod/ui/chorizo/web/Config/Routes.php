<?php
namespace Ddns\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('ddns', 'Ddns\Controllers\Ddnsconfig::index');
$routes->add('ddns/ddnsconfig', 'Ddns\Controllers\Ddnsconfig::index');
$routes->add('ddns/ddnsapi', 'Ddns\Controllers\Ddnsapi::index');
