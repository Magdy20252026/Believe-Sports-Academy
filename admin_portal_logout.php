<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("admin");

unset(
    $_SESSION["admin_portal_logged_in"],
    $_SESSION["admin_portal_id"],
    $_SESSION["admin_portal_name"],
    $_SESSION["admin_portal_game_id"],
    $_SESSION["admin_portal_site_name"],
    $_SESSION["admin_portal_site_logo"]
);

destroyPortalSession("admin");
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
if (window.AndroidBridge && typeof window.AndroidBridge.clearPortalState === 'function') {
    window.AndroidBridge.clearPortalState();
}
window.__PORTAL_SESSION_GUARD__ = {
    key: "admin-portal",
    mode: "logout",
    loginUrl: "admin_portal_login.php"
};
</script>
<script src="assets/js/portal_session_guard.js"></script>
</body>
</html>
