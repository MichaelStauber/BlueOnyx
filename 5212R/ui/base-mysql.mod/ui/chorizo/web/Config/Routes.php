<?php
namespace Mysql\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('mysql', 'Mysql\Controllers\Mysqlserver::index');
$routes->add('mysql/mysqlserver', 'Mysql\Controllers\Mysqlserver::index');
$routes->add('mysql/mysqlconfig', 'Mysql\Controllers\Mysqlconfig::index');
$routes->add('mysql/mysql_amdetails', 'Mysql\Controllers\Mysql_amdetails::index');
$routes->add('mysql/vsiteMySQL', 'Mysql\Controllers\VsiteMySQL::index');
$routes->add('mysql/dbupload', 'Mysql\Controllers\Dbupload::index');
$routes->add('mysql/dbdownload', 'Mysql\Controllers\Dbdownload::index');
$routes->add('mysql/dbbackup', 'Mysql\Controllers\Dbbackup::index');
$routes->add('mysql/dbload', 'Mysql\Controllers\Dbload::index');
