<?php
namespace Ai\Config;
if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}
$routes->add('ai/chat', 'Ai\Controllers\Ai::index');
$routes->add('ai/chat/send', 'Ai\Controllers\Ai::send');
$routes->add('ai/settings', 'Ai\Controllers\AiSettings::index');
$routes->add('ai/settings/get_models', 'Ai\Controllers\AiSettings::get_models');
$routes->add('ai/api/execute', 'Ai\Controllers\AiApi::execute');
