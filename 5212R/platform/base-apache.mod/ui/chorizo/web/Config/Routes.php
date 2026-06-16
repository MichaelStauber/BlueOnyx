<?php
namespace Apache\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('apache', 'Apache\Controllers\Apache::index');
$routes->add('apache/apache', 'Apache\Controllers\Apache::index');
$routes->add('apache/adm_amdetails', 'Apache\Controllers\Adm_amdetails::index');
$routes->add('apache/web_amdetails', 'Apache\Controllers\Web_amdetails::index');
$routes->add('apache/fpm_amdetails', 'Apache\Controllers\Fpm_amdetails::index');
$routes->add('apache/apacheqos', 'Apache\Controllers\Apacheqos::index');