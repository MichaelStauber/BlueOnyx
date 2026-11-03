<?php
/**
 * Single sign-on for phpMyAdmin from BlueOnyx GUI.
 *
 * Credentials are set by Chorizo Signon controller as cookies PMA_USER / PMA_PASSWORD
 * before redirecting to /base/phpMyAdmin/index.php (auth_type = signon).
 *
 * @see https://docs.phpmyadmin.net/en/latest/setup.html#signon-authentication-mode
 */

declare(strict_types=1);

/**
 * @param string $user Optional configured username (unused)
 *
 * @return array{0: string, 1: string}
 */
function get_login_credentials($user)
{
    $pmaUser = '';
    $pmaPassword = '';

    if (isset($_COOKIE['PMA_USER'], $_COOKIE['PMA_PASSWORD'])) {
        $pmaUser = (string) $_COOKIE['PMA_USER'];
        $pmaPassword = (string) $_COOKIE['PMA_PASSWORD'];
    }

    return [
        $pmaUser,
        $pmaPassword,
    ];
}
