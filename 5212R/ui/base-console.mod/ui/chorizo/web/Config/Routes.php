<?php
namespace Console\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('console', 'Console\Controllers\Ablstatus::index');
$routes->add('console/ablstatus', 'Console\Controllers\Ablstatus::index');
$routes->add('console/ablsettings', 'Console\Controllers\Ablsettings::index');
$routes->add('console/consolelogins', 'Console\Controllers\Consolelogins::index');
$routes->add('console/consoleprocs', 'Console\Controllers\Consoleprocs::index');
$routes->add('console/console_logfiles', 'Console\Controllers\Console_logfiles::index');
$routes->add('console/console_logfile_viewer', 'Console\Controllers\Console_logfile_viewer::index');
$routes->add('console/whois', 'Console\Controllers\Whois::index');
$routes->add('console/events', 'Console\Controllers\Events::index');
