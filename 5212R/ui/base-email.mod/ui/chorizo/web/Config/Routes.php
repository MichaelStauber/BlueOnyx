<?php
namespace Email\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('email/email_amdetails', 'Email\Controllers\Email_amdetails::index');
$routes->add('email/emailsettings', 'Email\Controllers\Emailsettings::index');
$routes->add('email/blacklist', 'Email\Controllers\Blacklist::index');
$routes->add('email/secondarymx', 'Email\Controllers\Secondarymx::index');

$routes->get('/autoconfig/mail/config-v1.1.xml', 'Email\Controllers\EmailAutoconfig::thunderbird');
$routes->get('/autodiscover/autodiscover.xml', 'Email\Controllers\EmailAutoconfig::autodiscover');

// For POSTs from Outlook:
$routes->post('/autodiscover/autodiscover.xml', 'Email\Controllers\EmailAutoconfig::autodiscover');

$routes->get('/mail/config-v1.1.xml', 'Email\Controllers\EmailAutoconfig::thunderbird');
$routes->get('/.well-known/autoconfig/mail/config-v1.1.xml', 'Email\Controllers\EmailAutoconfig::thunderbird');
$routes->get('/autodiscover/autodiscover.xml', 'Email\Controllers\EmailAutoconfig::autodiscover');
$routes->post('/autodiscover/autodiscover.xml', 'Email\Controllers\EmailAutoconfig::autodiscover');

// Outlook (Classic):
$routes->get('autodiscover/autodiscover.json/v1.0/(:any)', 'Email\Controllers\EmailAutoconfig::autodiscoverJson/$1');
