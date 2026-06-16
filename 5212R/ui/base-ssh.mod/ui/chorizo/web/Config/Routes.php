<?php
namespace Ssh\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('ssh/ssh_amdetails', 'Ssh\Controllers\Ssh_amdetails::index');

