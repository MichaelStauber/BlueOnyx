<?php
namespace Ssl\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('ssl/siteSSL', 'Ssl\Controllers\SiteSSL::index');
$routes->add('ssl/createCert', 'Ssl\Controllers\CreateCert::index');
$routes->add('ssl/uploadCert', 'Ssl\Controllers\UploadCert::index');
$routes->add('ssl/caManager', 'Ssl\Controllers\CaManager::index');
$routes->add('ssl/letsencryptCert', 'Ssl\Controllers\LetsencryptCert::index');
