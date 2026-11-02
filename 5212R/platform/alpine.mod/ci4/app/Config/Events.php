<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;
use CodeIgniter\HotReloader\HotReloader;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be added
 * at run-time, also, if needed.
 *
 * You create code that can execute by subscribing to events with
 * the 'on()' method. This accepts any form of callable, including
 * Closures, that will be executed when the event is triggered.
 *
 * Example:
 *      Events::on('create', [$myInstance, 'myMethod']);
 */

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        $value = ini_get('zlib.output_compression');

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || (int) $value > 0) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    /*
     * --------------------------------------------------------------------
     * Debug Toolbar Listeners.
     * --------------------------------------------------------------------
     * If you delete, they will no longer be collected.
     */
    if (CI_DEBUG && ! is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\\Debug\\Toolbar\\Collectors\\Database::collect');
        service('toolbar')->respond();
        // Hot Reload route - for framework use on the hot reloader.
        if (ENVIRONMENT === 'development') {
            service('routes')->get('__hot-reload', static function (): void {
                (new HotReloader())->run();
            });
        }
    }

    /*
     * --------------------------------------------------------------------
     * Session numeric-key sanitize (BlueOnyx)
     * --------------------------------------------------------------------
     * PHP cannot serialize top-level numeric $_SESSION keys and warns:
     *   session_write_close(): Skipping numeric key N
     * In CI development that becomes ErrorException after the page body
     * was already sent. Scrub top-level int/digit keys before close.
     * Nested lists (e.g. Capabilities) are left alone.
     *
     * Shutdown functions run LIFO. We register once here and again on
     * post_system so the last registration runs immediately before PHP's
     * session_write_close shutdown handler.
     */
    $sanitize = static function (): void {
        if (function_exists('bx_session_sanitize_numeric_keys')) {
            bx_session_sanitize_numeric_keys();

            return;
        }
        if (! isset($_SESSION) || ! is_array($_SESSION)) {
            return;
        }
        foreach (array_keys($_SESSION) as $key) {
            if (is_int($key) || (is_string($key) && $key !== '' && ctype_digit($key))) {
                unset($_SESSION[$key]);
            }
        }
    };

    register_shutdown_function($sanitize);

    Events::on('post_system', static function () use ($sanitize): void {
        if (! function_exists('bx_session_sanitize_numeric_keys')) {
            helper('blueonyx');
        }
        $sanitize();
        register_shutdown_function($sanitize);
    });
});
