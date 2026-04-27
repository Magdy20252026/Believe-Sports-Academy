<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("trainer");

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["trainer_portal_logged_in"]) || $_SESSION["trainer_portal_logged_in"] !== true) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "session_key" => "",
        "notification" => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once "config.php";

$trainerId = (int)($_SESSION["trainer_portal_id"] ?? 0);
$trainerGameId = (int)($_SESSION["trainer_portal_game_id"] ?? 0);

if ($trainerId <= 0 || $trainerGameId <= 0) {
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
         FROM trainer_notifications
         WHERE game_id = ? AND visibility_status = 'visible'
         ORDER BY display_date DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$trainerGameId]);
    $latestNotification = $stmt->fetch();

    echo json_encode([
        "authenticated" => true,
        "session_key" => "trainer:" . $trainerId,
        "notification" => $latestNotification ? [
            "id" => (int)$latestNotification["id"],
            "title" => (string)$latestNotification["title"],
            "message" => (string)$latestNotification["message"],
        ] : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $throwable) {
    error_log("trainer_portal_notifications_feed failed: " . $throwable->getMessage());
    http_response_code(500);
    echo json_encode([
        "authenticated" => true,
        "session_key" => "trainer:" . $trainerId,
        "notification" => null,
        "error" => "server_error",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
