<?php

namespace Gui\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('gui', 'Gui\Controllers\ErrorPages::Redirect');
$routes->add('gui/AuthorizationRequired401', 'Gui\Controllers\ErrorPages::AuthorizationRequired401');
$routes->add('gui/Forbidden403', 'Gui\Controllers\ErrorPages::Forbidden403');
$routes->add('gui/PageNotFound404', 'Gui\Controllers\ErrorPages::PageNotFound404');
$routes->add('gui/InternalServerError500', 'Gui\Controllers\ErrorPages::InternalServerError500');
$routes->add('login_denied', 'Gui\Controllers\ErrorPages::LoginDenied');
$routes->add('gui/validation.js', 'Gui\Controllers\Validation::index');
$routes->add('gui/pluginsmin.js', 'Gui\Controllers\Pluginsmin::index');
$routes->add('gui/working', 'Gui\Controllers\Working::index');
$routes->add('gui/workFrame', 'Gui\Controllers\WorkFrame::index');
$routes->add('gui/processing', 'Gui\Controllers\Processing::index');
$routes->add('gui/processFrame', 'Gui\Controllers\ProcessFrame::index');
$routes->add('gui/check_password', 'Gui\Controllers\Check_password::index');
$routes->add('gui/datepicker', 'Gui\Controllers\Datepicker::index');
$routes->add('gui/fullcalendar', 'Gui\Controllers\Fullcalendar::index');
$routes->add('gui/metrics', 'Gui\Controllers\Metrics::index');
$routes->add('gui/services', 'Gui\Controllers\DaemonServices::index');
