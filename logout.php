<?php
require_once "session.php";
startSecureSession();

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $cookieParams = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        [
            "expires"  => time() - 42000,
            "path"     => $cookieParams["path"],
            "domain"   => $cookieParams["domain"],
            "secure"   => $cookieParams["secure"],
            "httponly" => $cookieParams["httponly"],
            "samesite" => $cookieParams["samesite"] ?? "Lax",
        ]
    );
}

session_unset();
session_destroy();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
header('Clear-Site-Data: "cache", "storage"');

header("Location: index.php");
exit;
?>
