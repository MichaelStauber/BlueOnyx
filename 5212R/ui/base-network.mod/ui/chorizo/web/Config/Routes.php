<?php
namespace Network\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('network', 'Network\Controllers\Ethernet::index');
$routes->add('network/ethernet', 'Network\Controllers\Ethernet::index');
$routes->add('network/pooling', 'Network\Controllers\Pooling::index');
$routes->add('network/poolingModify', 'Network\Controllers\PoolingModify::index');
$routes->add('network/network_details', 'Network\Controllers\Network_details::index');

// Deprecated and removed from 5211R:
//
//$routes->add('network/ethernetDeploy', 'Network\Controllers\EthernetDeploy::index');
//$routes->add('network/ethernetIframe', 'Network\Controllers\EthernetIframe::index');
//
// $routes->add('network/aliasModify', 'Network\Controllers\AliasModify::index');
// $routes->add('network/routes', 'Network\Controllers\BXRoutes::index');
// $routes->add('network/routeModify', 'Network\Controllers\RouteModify::index');
