<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("admin");

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["admin_portal_logged_in"]) || $_SESSION["admin_portal_logged_in"] !== true) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "session_key" => "",
        "notification" => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once "config.php";

$adminId = (int)($_SESSION["admin_portal_id"] ?? 0);
$adminGameId = (int)($_SESSION["admin_portal_game_id"] ?? 0);

if ($adminId <= 0 || $adminGameId <= 0) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "session_key" => "",
        "notification" => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, title, message
         FROM admin_notifications
         WHERE game_id = ? AND visibility_status = 'visible'
         ORDER BY display_date DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$adminGameId]);
    $latestNotification = $stmt->fetch();

    echo json_encode([
        "authenticated" => true,
        "session_key" => "admin:" . $adminId,
        "notification" => $latestNotification ? [
            "id" => (int)$latestNotification["id"],
            "title" => (string)$latestNotification["title"],
            "message" => (string)$latestNotification["message"],
        ] : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $throwable) {
    error_log("admin_portal_notifications_feed failed: " . $throwable->getMessage());
    http_response_code(500);
    echo json_encode([
        "authenticated" => true,
        "session_key" => "admin:" . $adminId,
        "notification" => null,
        "error" => "server_error",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
