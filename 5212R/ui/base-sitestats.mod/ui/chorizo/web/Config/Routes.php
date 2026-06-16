<?php
namespace Sitestats\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('sitestats/logconfig', 'Sitestats\Controllers\Logconfig::index');
$routes->add('sitestats/webalizer', 'Sitestats\Controllers\Webalizer::index');
$routes->add('sitestats/goaccess', 'Sitestats\Controllers\Goaccess::index');
$routes->add('sitestats/summary', 'Sitestats\Controllers\Summary::index');
$routes->add('sitestats/statSettings', 'Sitestats\Controllers\StatSettings::index');
$routes->add('sitestats/summaryEmail', 'Sitestats\Controllers\SummaryEmail::index');
$routes->add('sitestats/summaryWeb', 'Sitestats\Controllers\SummaryWeb::index');
$routes->add('sitestats/goaccessview', 'Sitestats\Controllers\GoAccessView::index');
$routes->add('sitestats/summaryMonitorix', 'Sitestats\Controllers\SummaryMonitorix::index');
$routes->add('sitestats/sitestats_amdetails', 'Sitestats\Controllers\Sitestats_amdetails::index');