<?php
namespace Support\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
// None of these are done yet, as /swupdate/shop is our entry point. And it's not done yet!
$routes->add('support/supportSettings', 'Support\Controllers\SupportSettings::index');
$routes->add('support/bugreport', 'Support\Controllers\Bugreport::index');
$routes->add('support/ticket', 'Support\Controllers\Ticket::index');

//$routes->add('support/SOSReport', 'Support\Controllers\SOSReport::index'); // <-- Disabled for now and menu file has been removed, too.
