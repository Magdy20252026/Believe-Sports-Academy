<?php
date_default_timezone_set("Africa/Cairo");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

unset(
    $_SESSION["admin_portal_logged_in"],
    $_SESSION["admin_portal_id"],
    $_SESSION["admin_portal_name"],
    $_SESSION["admin_portal_game_id"],
    $_SESSION["admin_portal_site_name"],
    $_SESSION["admin_portal_site_logo"]
);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=admin_portal_login.php">
    <title>جاري تسجيل الخروج...</title>
</head>
<body>
<script>
window.__PORTAL_SESSION_GUARD__ = {
    key: "admin-portal",
    mode: "logout",
    loginUrl: "admin_portal_login.php"
};
</script>
<script src="assets/js/portal_session_guard.js"></script>
</body>
</html>
