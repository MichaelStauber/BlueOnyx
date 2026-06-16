<?php

namespace Wizard\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

// New Elmer Wizard using 100% proper UIFC2:
$routes->add('wizard', 'Wizard\Controllers\WizardElmerProper::index');

// Old Adminica Wizard:
// $routes->add('wizard', 'Wizard\Controllers\Wizard::index');
