<?php
namespace Phpmyadmin\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('phpmyadmin/', 'Phpmyadmin\Controllers\PhpmyadminUser::index');
$routes->add('phpmyadmin/signon', 'Phpmyadmin\Controllers\Signon::index');
$routes->add('phpmyadmin/server', 'Phpmyadmin\Controllers\PhpmyadminUser::index');
$routes->add('phpmyadmin/user', 'Phpmyadmin\Controllers\PhpmyadminUser::index');
$routes->add('phpmyadmin/site', 'Phpmyadmin\Controllers\PhpmyadminUser::index');
$routes->add('phpmyadmin/pusher', 'Phpmyadmin\Controllers\Pusher::index');
