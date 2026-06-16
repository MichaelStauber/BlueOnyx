<?php
namespace Organizer\Config;

if(!isset($routes))
{ 
    $routes = \Config\Services::routes(true);
}

$routes->add('organizer/', 'Organizer\Controllers\Organizer::index');
$routes->add('organizer/organizer', 'Organizer\Controllers\Organizer::index');
$routes->add('organizer/personalOrganizer', 'Organizer\Controllers\PersonalOrganizer::index');
$routes->add('organizer/personalOrganizerExt', 'Organizer\Controllers\PersonalOrganizerExt::index');
$routes->add('organizer/organizer_amdetails', 'Organizer\Controllers\Organizer_amdetails::index');
$routes->add('organizer/babackup', 'Organizer\Controllers\Babackup::index');
$routes->add('organizer/organizerradall', 'Organizer\Controllers\OrganizerRadall::index');
$routes->add('organizer/badownload', 'Organizer\Controllers\Badownload::index');
$routes->add('organizer/personalOrganizerEdit', 'Organizer\Controllers\PersonalOrganizerEdit::index');
$routes->add('organizer/delCollection', 'Organizer\Controllers\DelCollection::index');
$routes->add('organizer/addCollection', 'Organizer\Controllers\AddCollection::index');
