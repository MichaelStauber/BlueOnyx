<?php
namespace Snmp\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('snmp/snmp_amdetails', 'Snmp\Controllers\Snmp_amdetails::index');
$routes->add('snmp/snmpconfig', 'Snmp\Controllers\Snmpconfig::index');
