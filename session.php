<?php
require_once __DIR__ . "/realtime_sync.php";

function startSecureSession()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

    realtimeSyncStart();
}

function isUserLoggedIn()
{
    return isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true;
}

function redirectAuthenticatedUser($location = "dashboard.php")
{
    if (isUserLoggedIn()) {
        header("Location: " . $location);
        exit;
    }
}

function requireAuthenticatedUser($location = "index.php")
{
    if (!isUserLoggedIn()) {
        header("Location: " . $location);
        exit;
    }
}
?>
