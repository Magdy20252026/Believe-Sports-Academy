<?php
/**
 * Lightweight change-feed endpoint for real-time sync.
 *
 * Query params:
 *  - since_id (int, optional): last activity_log id seen by the client.
 *      If omitted, the endpoint returns the current MAX(id) as the baseline
 *      and reports no changes (so the client can seed itself without reload).
 *  - tables (csv, optional): comma-separated table names to watch.
 *      If omitted or "*", changes from any table count.
 *
 * Response (JSON):
 *  {
 *    "last_id": 1234,                // latest activity_log id seen by the server
 *    "changed": true|false,          // whether relevant changes were found
 *    "changes": [                    // recent relevant rows (capped, may be empty)
 *      {"id": 1233, "table": "players", "action": "create"},
 *      ...
 *    ],
 *    "server_time": 1714000000
 *  }
 */

define("REALTIME_SYNC_DISABLED", true);

require_once __DIR__ . "/session.php";
startSecureSession();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["error" => "unauthenticated"]);
    exit;
}

require_once __DIR__ . "/config.php";

$rawSince  = isset($_GET["since_id"]) ? (string)$_GET["since_id"] : "";
$sinceId   = ctype_digit($rawSince) ? (int)$rawSince : -1;
$rawTables = isset($_GET["tables"]) ? (string)$_GET["tables"] : "*";

$watchAll = false;
$watchedTables = [];
if ($rawTables === "" || $rawTables === "*") {
    $watchAll = true;
} else {
    foreach (explode(",", $rawTables) as $t) {
        $t = trim($t);
        if ($t !== "" && preg_match('/^[A-Za-z0-9_]+$/', $t) === 1) {
            $watchedTables[] = $t;
        }
    }
    if (count($watchedTables) === 0) {
        $watchAll = true;
    }
}

$response = [
    "last_id"     => 0,
    "changed"     => false,
    "changes"     => [],
    "server_time" => time(),
];

try {
    if ($sinceId < 0) {
        // Baseline request: just return current head.
        $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM activity_log");
        $response["last_id"] = (int)$stmt->fetchColumn();
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($watchAll) {
        $sql = "SELECT id, table_name, action_type
                FROM activity_log
                WHERE id > :sid
                ORDER BY id ASC
                LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(":sid", $sinceId, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $placeholders = implode(",", array_fill(0, count($watchedTables), "?"));
        $sql = "SELECT id, table_name, action_type
                FROM activity_log
                WHERE id > ? AND table_name IN ($placeholders)
                ORDER BY id ASC
                LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $params = array_merge([$sinceId], $watchedTables);
        $idx = 1;
        $stmt->bindValue($idx++, $sinceId, PDO::PARAM_INT);
        foreach ($watchedTables as $tbl) {
            $stmt->bindValue($idx++, $tbl, PDO::PARAM_STR);
        }
        $stmt->execute();
    }

    $rows = $stmt->fetchAll();
    $maxIdSeen = $sinceId;
    $changes = [];
    foreach ($rows as $row) {
        $rid = (int)$row["id"];
        if ($rid > $maxIdSeen) {
            $maxIdSeen = $rid;
        }
        $changes[] = [
            "id"     => $rid,
            "table"  => (string)$row["table_name"],
            "action" => (string)$row["action_type"],
        ];
    }

    // Even if the row set was filtered (or trimmed at LIMIT), advance
    // the cursor to the global MAX(id) so the client doesn't keep
    // re-fetching unrelated changes forever.
    $stmtMax = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM activity_log");
    $globalMax = (int)$stmtMax->fetchColumn();
    if ($globalMax > $maxIdSeen) {
        $maxIdSeen = $globalMax;
    }

    $response["last_id"] = $maxIdSeen;
    $response["changes"] = $changes;
    $response["changed"] = count($changes) > 0;

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log("realtime_check failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error"       => "server_error",
        "last_id"     => $sinceId >= 0 ? $sinceId : 0,
        "changed"     => false,
        "changes"     => [],
        "server_time" => time(),
    ]);
    exit;
}
