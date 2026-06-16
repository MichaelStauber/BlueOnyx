<?php
namespace Api\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('api/apiconfig', 'Api\Controllers\Apiconfig::index');
$routes->add('api/index', 'Api\Controllers\Apiindex::index');
$routes->add('api/', 'Api\Controllers\Apiindex::index');
$routes->add('api/apilogin', 'Api\Controllers\Apilogin::index');
$routes->add('apilogin', 'Api\Controllers\Apilogin::index');
$routes->add('api/api_amdetails', 'Api\Controllers\Api_amdetails::index');
