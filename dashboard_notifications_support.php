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
            PRIMARY KEY (user_id, game_id, alert_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === "") {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'dashboard_notification_reads'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    $indexesStmt = $pdo->prepare(
        "SELECT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'dashboard_notification_reads'
           AND INDEX_NAME = 'idx_dashboard_notification_reads_lookup'"
    );
    $indexesStmt->execute([$databaseName]);
    if ($indexesStmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE dashboard_notification_reads DROP INDEX idx_dashboard_notification_reads_lookup");
    }

    if (!in_array("fk_dashboard_notification_reads_user", $existingConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE dashboard_notification_reads
             ADD CONSTRAINT fk_dashboard_notification_reads_user
             FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE"
        );
    }

    if (!in_array("fk_dashboard_notification_reads_game", $existingConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE dashboard_notification_reads
             ADD CONSTRAINT fk_dashboard_notification_reads_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }
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
