<?php
namespace Home\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
//$routes->add('home', 'Home\Controllers\Home', ['filter' => 'auth']);
//$routes->add('/', 'Home\Controllers\Home', ['filter' => 'auth']);
