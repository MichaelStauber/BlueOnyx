<?php
namespace Swupdate\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('swupdate', 'Swupdate\Controllers\Swupdate::news');
$routes->add('swupdate/news', 'Swupdate\Controllers\Swupdate::news');
$routes->add('swupdate/shop', 'Swupdate\Controllers\Shop::index');

// The following includes Compass/Base/Controllers/Autolib.php which isn't done yet:
$routes->add('swupdate/autoinstall', 'Swupdate\Controllers\Autoinstall::index');

// Done:
$routes->add('swupdate/newSoftware', 'Swupdate\Controllers\NewSoftware::index');
$routes->add('swupdate/checkHandler', 'Swupdate\Controllers\CheckHandler::index');
$routes->add('swupdate/manualInstall', 'Swupdate\Controllers\ManualInstall::index');
$routes->add('swupdate/download', 'Swupdate\Controllers\Download::index');
$routes->add('swupdate/license', 'Swupdate\Controllers\License::index');
$routes->add('swupdate/downloadHandler', 'Swupdate\Controllers\DownloadHandler::index');
$routes->add('swupdate/status', 'Swupdate\Controllers\Status::index');
$routes->add('swupdate/statusFrame', 'Swupdate\Controllers\StatusFrame::index');
$routes->add('swupdate/settings', 'Swupdate\Controllers\Settings::index');
$routes->add('swupdate/softwareList', 'Swupdate\Controllers\SoftwareList::index');
$routes->add('swupdate/uninstallHandler', 'Swupdate\Controllers\UninstallHandler::index');
$routes->add('swupdate/yum', 'Swupdate\Controllers\Yum::index');
$routes->add('swupdate/checkupdates', 'Swupdate\Controllers\Checkupdates::index');
$routes->add('swupdate/yumupdate', 'Swupdate\Controllers\Yumupdate::index');
$routes->add('swupdate/removeHandler', 'Swupdate\Controllers\RemoveHandler::index');
$routes->add('swupdate/rsscron', 'Swupdate\Controllers\Rsscron::index');
$routes->add('swupdate/updates_amdetails', 'Swupdate\Controllers\Updates_amdetails::index');

