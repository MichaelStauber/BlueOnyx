<?php
namespace Power\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('power/', 'Power\Controllers\Poweroptions::index');
$routes->add('power/poweroptions', 'Power\Controllers\Poweroptions::index');

