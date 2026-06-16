<?php
namespace Time\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('time/timeconfig', 'Time\Controllers\Timeconfig::index');
$routes->add('time/ntpd_amdetails', 'Time\Controllers\Ntpd_amdetails::index');

