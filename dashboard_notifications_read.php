<?php
require_once "session.php";
startSecureSession();

require_once "config.php";
require_once "dashboard_notifications_support.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "method_not_allowed"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isUserLoggedIn()) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "unauthenticated"], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($_SESSION["user_id"] ?? 0);
$gameId = (int)($_SESSION["selected_game_id"] ?? 0);
if ($userId <= 0 || $gameId <= 0) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "invalid_context"], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents("php://input"), true);
$notificationKeys = is_array($payload["notification_keys"] ?? null) ? $payload["notification_keys"] : [];

try {
    markDashboardNotificationKeysAsRead($pdo, $userId, $gameId, $notificationKeys);
    echo json_encode([
        "ok" => true,
        "marked_count" => count(sanitizeDashboardNotificationKeys($notificationKeys)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $throwable) {
    error_log("dashboard_notifications_read failed: " . $throwable->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "server_error"], JSON_UNESCAPED_UNICODE);
    exit;
}
