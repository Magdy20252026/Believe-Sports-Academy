<?php

const PORTAL_SESSION_LIFETIME_SECONDS = 2592000;
const PORTAL_SESSION_COOKIE_CLEAR_OFFSET_SECONDS = 42000;

function portalSessionResolveName($portalKey)
{
    $portalKey = trim((string)$portalKey);
    $names = [
        'player' => 'BSA_PLAYER_PORTAL_SID',
        'admin' => 'BSA_ADMIN_PORTAL_SID',
        'trainer' => 'BSA_TRAINER_PORTAL_SID',
    ];

    if (!isset($names[$portalKey])) {
        throw new InvalidArgumentException('Unknown portal session key.');
    }

    return $names[$portalKey];
}

function startPortalSession($portalKey)
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionName = portalSessionResolveName($portalKey);
    $lifetime = PORTAL_SESSION_LIFETIME_SECONDS;
    $isSecure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    if (session_name() !== $sessionName) {
        session_name($sessionName);
    }

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params($lifetime, '/', '', $isSecure, true);
    }

    session_start();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

function destroyPortalSession($portalKey)
{
    startPortalSession($portalKey);

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParams = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - PORTAL_SESSION_COOKIE_CLEAR_OFFSET_SECONDS,
                'path' => $cookieParams['path'] ?? '/',
                'domain' => $cookieParams['domain'] ?? '',
                'secure' => !empty($cookieParams['secure']),
                'httponly' => !empty($cookieParams['httponly']),
                'samesite' => $cookieParams['samesite'] ?? 'Lax',
            ]
        );
    }

    session_unset();
    session_destroy();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}
