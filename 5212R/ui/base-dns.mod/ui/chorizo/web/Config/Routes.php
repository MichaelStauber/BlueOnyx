<?php
namespace Dns\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('dns/dns_amdetails', 'Dns\Controllers\Dns_amdetails::index');
$routes->add('dns/dnsmanager', 'Dns\Controllers\Dnsmanager::index');
$routes->add('dns/secondarydns', 'Dns\Controllers\Secondarydns::index');
$routes->add('dns/secondarydnsmod', 'Dns\Controllers\Secondarydnsmod::index');
$routes->add('dns/primarydns', 'Dns\Controllers\Primarydns::index');
$routes->add('dns/dns_add', 'Dns\Controllers\Dns_add::index');
$routes->add('dns/dns_soa', 'Dns\Controllers\Dns_soa::index');
$routes->add('dns/vsiteDNS', 'Dns\Controllers\VsiteDNS::index');
$routes->add('dns/vsite_dns_add', 'Dns\Controllers\Vsite_dns_add::index');
$routes->add('dns/vsite_dns_soa', 'Dns\Controllers\Vsite_dns_soa::index');
