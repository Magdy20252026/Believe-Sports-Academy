<?php
function ensureDashboardNotificationReadsTable(PDO $pdo)
{
    static $alreadyEnsured = false;
    if ($alreadyEnsured) {
        return;
    }
    $alreadyEnsured = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dashboard_notification_reads (
            user_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            alert_key VARCHAR(191) NOT NULL,
            read_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, game_id, alert_key),
            KEY idx_dashboard_notification_reads_lookup (user_id, game_id, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function sanitizeDashboardNotificationKeys(array $alertKeys)
{
    $sanitized = [];
    foreach ($alertKeys as $alertKey) {
        $alertKey = trim((string)$alertKey);
        if ($alertKey !== "") {
            $sanitized[] = $alertKey;
        }
    }

    return array_values(array_unique($sanitized));
}

function buildDashboardNotificationKey($alertType, array $parts)
{
    $normalizedParts = [];
    foreach ($parts as $part) {
        $normalizedParts[] = trim((string)$part);
    }

    $payload = json_encode([
        "type" => trim((string)$alertType),
        "parts" => $normalizedParts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return trim((string)$alertType) . ":" . sha1((string)$payload);
}

function fetchDashboardReadAlertKeys(PDO $pdo, $userId, $gameId, array $alertKeys)
{
    ensureDashboardNotificationReadsTable($pdo);

    $userId = (int)$userId;
    $gameId = (int)$gameId;
    $alertKeys = sanitizeDashboardNotificationKeys($alertKeys);
    if ($userId <= 0 || $gameId <= 0 || count($alertKeys) === 0) {
        return [];
    }

    $placeholders = implode(", ", array_fill(0, count($alertKeys), "?"));
    $stmt = $pdo->prepare(
        "SELECT alert_key
         FROM dashboard_notification_reads
         WHERE user_id = ?
           AND game_id = ?
           AND alert_key IN (" . $placeholders . ")"
    );
    $stmt->execute(array_merge([$userId, $gameId], $alertKeys));

    return sanitizeDashboardNotificationKeys(array_column($stmt->fetchAll(), "alert_key"));
}

function markDashboardNotificationKeysAsRead(PDO $pdo, $userId, $gameId, array $alertKeys)
{
    ensureDashboardNotificationReadsTable($pdo);

    $userId = (int)$userId;
    $gameId = (int)$gameId;
    $alertKeys = sanitizeDashboardNotificationKeys($alertKeys);
    if ($userId <= 0 || $gameId <= 0 || count($alertKeys) === 0) {
        return;
    }

    $placeholders = [];
    $params = [];
    foreach ($alertKeys as $alertKey) {
        $placeholders[] = "(?, ?, ?)";
        $params[] = $userId;
        $params[] = $gameId;
        $params[] = $alertKey;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO dashboard_notification_reads (user_id, game_id, alert_key)
         VALUES " . implode(", ", $placeholders) . "
         ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute($params);
}
