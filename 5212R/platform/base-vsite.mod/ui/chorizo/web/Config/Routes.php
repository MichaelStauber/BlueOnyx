<?php
namespace Vsite\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('vsite', 'Vsite\Controllers\VsiteList::index');
$routes->add('vsite/vsiteList', 'Vsite\Controllers\VsiteList::index');
$routes->add('vsite/template', 'Vsite\Controllers\Template::index');
$routes->add('vsite/vsiteAdd', 'Vsite\Controllers\VsiteAdd::index');
$routes->add('vsite/vsiteDel', 'Vsite\Controllers\VsiteDel::index');
$routes->add('vsite/vsiteMod', 'Vsite\Controllers\VsiteMod::index');
$routes->add('vsite/vsiteWeb', 'Vsite\Controllers\VsiteWeb::index');
$routes->add('vsite/vsitePHP', 'Vsite\Controllers\VsitePHP::index');
$routes->add('vsite/fileOwner', 'Vsite\Controllers\FileOwner::index');
$routes->add('vsite/vsiteEmail', 'Vsite\Controllers\VsiteEmail::index');
$routes->add('vsite/phpconfig', 'Vsite\Controllers\Phpconfig::index');
$routes->add('vsite/adminList', 'Vsite\Controllers\AdminList::index');
$routes->add('vsite/manageAdmin', 'Vsite\Controllers\ManageAdmin::index');
$routes->add('vsite/vsite_amdetails', 'Vsite\Controllers\Vsite_amdetails::index');
$routes->add('vsite/suspendMsg', 'Vsite\Controllers\SuspendMsg::index');
