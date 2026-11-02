<?php

namespace Config;

use CodeIgniter\Modules\Modules as BaseModules;

/**
 * Modules Configuration.
 *
 * NOTE: This class is required prior to Autoloader instantiation,
 *       and does not extend BaseConfig.
 */
class Modules extends BaseModules
{
    /**
     * --------------------------------------------------------------------------
     * Enable Auto-Discovery?
     * --------------------------------------------------------------------------
     *
     * If true, then auto-discovery will happen across all elements listed in
     * $aliases below. If false, no auto-discovery will happen at all,
     * giving a slight performance boost.
     *
     * @var bool
     */
    public $enabled = true;

    /**
     * --------------------------------------------------------------------------
     * Enable Auto-Discovery Within Composer Packages?
     * --------------------------------------------------------------------------
     *
     * If true, then auto-discovery will happen across all namespaces loaded
     * by Composer, as well as the namespaces configured locally.
     *
     * @var bool
     */
    public $discoverInComposer = true;

    /**
     * The Composer package list for Auto-Discovery
     * This setting is optional.
     *
     * E.g.:
     *   [
     *       'only' => [
     *           // List up all packages to auto-discover
     *           'codeigniter4/shield',
     *       ],
     *   ]
     *   or
     *   [
     *       'exclude' => [
     *           // List up packages to exclude.
     *           'pestphp/pest',
     *       ],
     *   ]
     *
     * @var array{only?: list<string>, exclude?: list<string>}
     */
    public $composerPackages = [];

    /**
     * --------------------------------------------------------------------------
     * Auto-Discovery Rules
     * --------------------------------------------------------------------------
     *
     * Aliases list of all discovery classes that will be active and used during
     * the current application request.
     *
     * If it is not listed, only the base application elements will be used.
     *
     * BlueOnyx/Chorizo: do NOT auto-discover "routes". Module Config/Routes.php
     * files are loaded explicitly from app/Config/Routes.php with an ionCube
     * compatibility gate (bx_module_routes_helper). CI discovery would bypass
     * that gate and 500 the GUI on PHP 8.2+ when old encode_81 shop packages
     * are still installed.
     *
     * @var list<string>
     */
    public $aliases = [
        'events',
        'filters',
        'registrars',
        // 'routes' — intentional: manual load only (see app/Config/Routes.php)
        'services',
    ];
}
