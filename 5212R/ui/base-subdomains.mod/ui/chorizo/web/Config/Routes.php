<?php
namespace Subdomains\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('subdomains/subconfig', 'Subdomains\Controllers\Subconfig::index');
$routes->add('subdomains/vsiteSub', 'Subdomains\Controllers\VsiteSub::index');
$routes->add('subdomains/vsiteAddSub', 'Subdomains\Controllers\VsiteAddSub::index');
$routes->add('subdomains/vsiteDelSub', 'Subdomains\Controllers\VsiteDelSub::index');

