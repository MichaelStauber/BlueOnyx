<?php

namespace Ftp\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('ftp/ftp_amdetails', 'Ftp\Controllers\Ftp_amdetails::index');
$routes->add('ftp/filemanager', 'Ftp\Controllers\Filemanager::index');
$routes->add('ftp/filemgr', 'Ftp\Controllers\Filemgr::index');
