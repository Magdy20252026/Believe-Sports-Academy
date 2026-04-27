<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("player");

unset(
    $_SESSION["player_portal_logged_in"],
    $_SESSION["player_portal_id"],
    $_SESSION["player_portal_name"],
    $_SESSION["player_portal_game_id"],
    $_SESSION["player_portal_site_name"],
    $_SESSION["player_portal_site_logo"]
);

destroyPortalSession("player");
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=player_portal_login.php">
    <title>جاري تسجيل الخروج...</title>
</head>
<body>
<script>
if (window.AndroidBridge && typeof window.AndroidBridge.clearPortalState === 'function') {
    window.AndroidBridge.clearPortalState();
}
window.__PORTAL_SESSION_GUARD__ = {
    key: "player-portal",
    mode: "logout",
    loginUrl: "player_portal_login.php"
};
</script>
<script src="assets/js/portal_session_guard.js"></script>
</body>
</html>
