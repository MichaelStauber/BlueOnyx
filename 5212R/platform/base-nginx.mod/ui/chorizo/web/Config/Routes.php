<?php
namespace Nginx\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('nginx/nginx_amdetails', 'Nginx\Controllers\Nginx_amdetails::index');
