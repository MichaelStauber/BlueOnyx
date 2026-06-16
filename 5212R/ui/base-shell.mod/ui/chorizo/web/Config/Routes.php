<?php
namespace Shell\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('shell/jailkit_amdetails', 'Shell\Controllers\Jailkit_amdetails::index');
$routes->add('shell/shellconfig', 'Shell\Controllers\Shellconfig::index');
$routes->add('shell/vsiteShell', 'Shell\Controllers\VsiteShell::index');
$routes->add('shell/personalSSH', 'Shell\Controllers\PersonalSSH::index');
