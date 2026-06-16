<?php
namespace Telnet\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('telnet/telnet_amdetails', 'Telnet\Controllers\Telnet_amdetails::index');

