<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("player");

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["player_portal_logged_in"]) || $_SESSION["player_portal_logged_in"] !== true) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "session_key" => "",
        "notification" => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once "config.php";
require_once "players_support.php";

$playerId = (int)($_SESSION["player_portal_id"] ?? 0);
$playerGameId = (int)($_SESSION["player_portal_game_id"] ?? 0);

if ($playerId <= 0 || $playerGameId <= 0) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "session_key" => "",
        "notification" => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $playerStmt = $pdo->prepare(
        "SELECT id, group_id, group_level
         FROM players
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $playerStmt->execute([$playerId, $playerGameId]);
    $playerRow = $playerStmt->fetch();

    if (!$playerRow) {
        http_response_code(401);
        echo json_encode([
            "authenticated" => false,
            "session_key" => "",
            "notification" => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $notifications = fetchPlayerPortalNotifications(
        $pdo,
        $playerGameId,
        $playerId,
        (int)($playerRow["group_id"] ?? 0),
        (string)($playerRow["group_level"] ?? "")
    );
    $latestNotification = $notifications[0] ?? null;

    echo json_encode([
        "authenticated" => true,
        "session_key" => "player:" . $playerId,
        "notification" => $latestNotification ? [
            "id" => (int)$latestNotification["id"],
            "title" => (string)$latestNotification["title"],
            "message" => (string)$latestNotification["message"],
        ] : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $throwable) {
    error_log("player_portal_notifications_feed failed: " . $throwable->getMessage());
    http_response_code(500);
    echo json_encode([
        "authenticated" => true,
        "session_key" => "player:" . $playerId,
        "notification" => null,
        "error" => "server_error",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
