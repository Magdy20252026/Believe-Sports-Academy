<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "players_support.php";

date_default_timezone_set("Africa/Cairo");

const PLAYER_NOTIFICATION_EMPTY_VALUE = "—";

requireAuthenticatedUser();
requireMenuAccess("players-notifications");
ensurePlayersTables($pdo);

function getPlayerNotificationTypes()
{
    return [
        "general" => "عام",
        "reminder" => "تذكير",
        "alert" => "تنبيه",
        "administrative" => "إداري",
    ];
}

function getPlayerNotificationPriorities()
{
    return [
        "normal" => "عادي",
        "important" => "مهم",
        "urgent" => "عاجل",
    ];
}

function getPlayerNotificationStatuses()
{
    return [
        "visible" => "ظاهر",
        "hidden" => "مخفي",
    ];
}

function getPlayerNotificationScopes()
{
    return [
        "all" => "كل اللاعبين",
        "level" => "مستوى محدد",
        "group" => "مجموعة محددة",
    ];
}

function limitPlayerNotificationText($value, $maxLength)
{
    $value = (string)$value;
    $maxLength = (int)$maxLength;
    if ($maxLength <= 0) {
        return "";
    }

    if (function_exists("mb_substr")) {
        return mb_substr($value, 0, $maxLength, "UTF-8");
    }

    return substr($value, 0, $maxLength);
}

function normalizePlayerNotificationTitle($title)
{
    $title = preg_replace('/\s+/u', ' ', trim((string)$title));
    return limitPlayerNotificationText($title, 160);
}

function normalizePlayerNotificationMessage($message)
{
    $message = str_replace(["\r\n", "\r"], "\n", (string)$message);
    $message = preg_replace("/\n{3,}/", "\n\n", $message);
    $message = trim($message);
    return limitPlayerNotificationText($message, 3000);
}

function sanitizePlayerNotificationChoice($value, array $allowedValues, $defaultValue)
{
    $value = trim((string)$value);
    if ($value !== "" && array_key_exists($value, $allowedValues)) {
        return $value;
    }

    return $defaultValue;
}

function isValidPlayerNotificationDate($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("Y-m-d", $date, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $date;
}

function formatPlayerNotificationEgyptDateTimeLabel(DateTimeInterface $dateTime)
{
    $hour = (int)$dateTime->format("G");
    $minute = $dateTime->format("i");
    $period = $hour >= 12 ? "م" : "ص";
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return $dateTime->format("Y/m/d") . " - " . str_pad((string)$displayHour, 2, "0", STR_PAD_LEFT) . ":" . $minute . " " . $period;
}

function formatPlayerNotificationDateTimeValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return PLAYER_NOTIFICATION_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return PLAYER_NOTIFICATION_EMPTY_VALUE;
    }

    return formatPlayerNotificationEgyptDateTimeLabel($dateTime);
}

function doesTableExist(PDO $pdo, $tableName)
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([(string)$tableName]);
    return (bool)$stmt->fetchColumn();
}

function ensurePlayerNotificationsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_notifications (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority_level VARCHAR(20) NOT NULL DEFAULT 'normal',
            visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible',
            target_scope VARCHAR(20) NOT NULL DEFAULT 'all',
            target_group_id INT(11) NULL DEFAULT NULL,
            target_group_name VARCHAR(150) NULL DEFAULT NULL,
            target_group_level VARCHAR(150) NULL DEFAULT NULL,
            display_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player_notifications_game_date (game_id, display_date),
            KEY idx_player_notifications_status (game_id, visibility_status),
            KEY idx_player_notifications_scope (game_id, target_scope),
            KEY idx_player_notifications_group (game_id, target_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "title" => "ALTER TABLE player_notifications ADD COLUMN title VARCHAR(160) NOT NULL AFTER game_id",
        "message" => "ALTER TABLE player_notifications ADD COLUMN message TEXT NOT NULL AFTER title",
        "notification_type" => "ALTER TABLE player_notifications ADD COLUMN notification_type VARCHAR(50) NOT NULL DEFAULT 'general' AFTER message",
        "priority_level" => "ALTER TABLE player_notifications ADD COLUMN priority_level VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER notification_type",
        "visibility_status" => "ALTER TABLE player_notifications ADD COLUMN visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible' AFTER priority_level",
        "target_scope" => "ALTER TABLE player_notifications ADD COLUMN target_scope VARCHAR(20) NOT NULL DEFAULT 'all' AFTER visibility_status",
        "target_group_id" => "ALTER TABLE player_notifications ADD COLUMN target_group_id INT(11) NULL DEFAULT NULL AFTER target_scope",
        "target_group_name" => "ALTER TABLE player_notifications ADD COLUMN target_group_name VARCHAR(150) NULL DEFAULT NULL AFTER target_group_id",
        "target_group_level" => "ALTER TABLE player_notifications ADD COLUMN target_group_level VARCHAR(150) NULL DEFAULT NULL AFTER target_group_name",
        "display_date" => "ALTER TABLE player_notifications ADD COLUMN display_date DATE NOT NULL AFTER target_group_level",
        "created_by_user_id" => "ALTER TABLE player_notifications ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER display_date",
        "updated_by_user_id" => "ALTER TABLE player_notifications ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
    ];

    foreach ($requiredColumns as $columnName => $sql) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM player_notifications LIKE " . $pdo->quote($columnName));
        if (!$columnStmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

function fetchPlayerNotificationRecord(PDO $pdo, $notificationId, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, title, message, notification_type, priority_level, visibility_status, target_scope, target_group_id,
                target_group_name, target_group_level, display_date
         FROM player_notifications
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$notificationId, (int)$gameId]);
    return $stmt->fetch();
}

function fetchPlayerNotificationRows(PDO $pdo, $gameId, $searchQuery, $statusFilter, $scopeFilter)
{
    $sql = "SELECT
            n.id,
            n.title,
            n.message,
            n.notification_type,
            n.priority_level,
            n.visibility_status,
            n.target_scope,
            n.target_group_id,
            n.target_group_name,
            n.target_group_level,
            n.display_date,
            n.created_at,
            n.updated_at,
            COALESCE(updated_user.username, created_user.username, " . $pdo->quote(PLAYER_NOTIFICATION_EMPTY_VALUE) . ") AS actor_name,
            CASE
                WHEN n.target_scope = 'all' THEN 'كل اللاعبين'
                WHEN n.target_scope = 'level' THEN CONCAT('المستوى: ', COALESCE(NULLIF(n.target_group_level, ''), " . $pdo->quote(PLAYER_NOTIFICATION_EMPTY_VALUE) . "))
                WHEN n.target_scope = 'group' THEN CONCAT(
                    'المجموعة: ',
                    COALESCE(NULLIF(n.target_group_name, ''), " . $pdo->quote(PLAYER_NOTIFICATION_EMPTY_VALUE) . "),
                    ' - ',
                    COALESCE(NULLIF(n.target_group_level, ''), " . $pdo->quote(PLAYER_NOTIFICATION_EMPTY_VALUE) . ")
                )
                ELSE " . $pdo->quote(PLAYER_NOTIFICATION_EMPTY_VALUE) . "
            END AS audience_label,
            CASE
                WHEN n.target_scope = 'all' THEN (
                    SELECT COUNT(*)
                    FROM players p
                    WHERE p.game_id = n.game_id
                )
                WHEN n.target_scope = 'level' THEN (
                    SELECT COUNT(*)
                    FROM players p
                    WHERE p.game_id = n.game_id
                      AND p.group_level = n.target_group_level
                )
                WHEN n.target_scope = 'group' THEN (
                    SELECT COUNT(*)
                    FROM players p
                    WHERE p.game_id = n.game_id
                      AND p.group_id = n.target_group_id
                )
                ELSE 0
            END AS audience_count
        FROM player_notifications n
        LEFT JOIN users created_user
            ON created_user.id = n.created_by_user_id
        LEFT JOIN users updated_user
            ON updated_user.id = n.updated_by_user_id
        WHERE n.game_id = ?";

    $params = [(int)$gameId];

    if ($statusFilter !== "all") {
        $sql .= " AND n.visibility_status = ?";
        $params[] = $statusFilter;
    }

    if ($scopeFilter !== "all") {
        $sql .= " AND n.target_scope = ?";
        $params[] = $scopeFilter;
    }

    if ($searchQuery !== "") {
        $sql .= " AND (n.title LIKE ? OR n.message LIKE ? OR COALESCE(n.target_group_name, '') LIKE ? OR COALESCE(n.target_group_level, '') LIKE ?)";
        $searchValue = "%" . $searchQuery . "%";
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    $sql .= " ORDER BY n.display_date DESC, n.created_at DESC, n.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetchPlayerNotificationSummary(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN visibility_status = 'visible' THEN 1 ELSE 0 END) AS visible_count,
            SUM(CASE WHEN target_scope = 'all' THEN 1 ELSE 0 END) AS all_players_count,
            SUM(CASE WHEN target_scope <> 'all' THEN 1 ELSE 0 END) AS targeted_count
         FROM player_notifications
         WHERE game_id = ?"
    );
    $stmt->execute([(int)$gameId]);
    $summary = $stmt->fetch();

    return [
        "total_count" => (int)($summary["total_count"] ?? 0),
        "visible_count" => (int)($summary["visible_count"] ?? 0),
        "all_players_count" => (int)($summary["all_players_count"] ?? 0),
        "targeted_count" => (int)($summary["targeted_count"] ?? 0),
    ];
}

function getPlayerNotificationStatusClass($status)
{
    if ($status === "visible") {
        return "status-success";
    }

    return "status-neutral";
}

function getPlayerNotificationPriorityClass($priority)
{
    if ($priority === "urgent") {
        return "status-danger";
    }

    if ($priority === "important") {
        return "status-warning";
    }

    return "status-info";
}

function getPlayerNotificationScopeClass($scope)
{
    if ($scope === "group") {
        return "status-success";
    }

    if ($scope === "level") {
        return "status-warning";
    }

    return "status-info";
}

function buildPlayerNotificationsPageUrl(array $params = [])
{
    $filteredParams = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }

        $value = is_string($value) ? trim($value) : $value;
        if ($value === "") {
            continue;
        }

        if (in_array($key, ["status_filter", "scope_filter"], true) && $value === "all") {
            continue;
        }

        $filteredParams[$key] = $value;
    }

    $query = http_build_query($filteredParams);
    return "players_notifications.php" . ($query !== "" ? "?" . $query : "");
}

function logPlayerNotificationException(Throwable $throwable)
{
    error_log("Player notifications page error: " . $throwable->getMessage());
}

if (!isset($_SESSION["players_notifications_csrf_token"])) {
    $_SESSION["players_notifications_csrf_token"] = bin2hex(random_bytes(32));
}

ensurePlayerNotificationsTable($pdo);

$success = "";
$error = "";
$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$notificationTypes = getPlayerNotificationTypes();
$notificationPriorities = getPlayerNotificationPriorities();
$notificationStatuses = getPlayerNotificationStatuses();
$notificationScopes = getPlayerNotificationScopes();

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "players-notifications";

$gamesStmt = $pdo->query("SELECT id, name FROM games WHERE status = 1 ORDER BY id ASC");
$allGames = $gamesStmt->fetchAll();
$allGameMap = [];
foreach ($allGames as $game) {
    $allGameMap[(int)$game["id"]] = $game;
}

if ($currentGameId <= 0 || !isset($allGameMap[$currentGameId])) {
    header("Location: dashboard.php");
    exit;
}

$groups = [];
$groupMap = [];
$groupLevels = [];
$levelPlayerCounts = [];

if (doesTableExist($pdo, "sports_groups")) {
    $groupsStmt = $pdo->prepare(
        "SELECT sg.id, sg.group_name, sg.group_level, COUNT(p.id) AS players_count
         FROM sports_groups sg
         LEFT JOIN players p
             ON p.group_id = sg.id
            AND p.game_id = sg.game_id
         WHERE sg.game_id = ?
         GROUP BY sg.id, sg.group_name, sg.group_level
         ORDER BY sg.group_level ASC, sg.group_name ASC, sg.id DESC"
    );
    $groupsStmt->execute([$currentGameId]);
    $groups = $groupsStmt->fetchAll();

    foreach ($groups as $group) {
        $groupId = (int)$group["id"];
        $groupMap[$groupId] = $group;
    }
}

$levelsStmt = $pdo->prepare(
    "SELECT group_level, COUNT(*) AS players_count
     FROM players
     WHERE game_id = ?
       AND group_level <> ''
     GROUP BY group_level"
);
$levelsStmt->execute([$currentGameId]);
foreach ($levelsStmt->fetchAll() as $levelRow) {
    $levelName = trim((string)($levelRow["group_level"] ?? ""));
    if ($levelName !== "") {
        $levelPlayerCounts[$levelName] = (int)($levelRow["players_count"] ?? 0);
    }
}

foreach ($groups as $group) {
    $levelName = trim((string)($group["group_level"] ?? ""));
    if ($levelName === "") {
        continue;
    }

    if (!isset($groupLevels[$levelName])) {
        $groupLevels[$levelName] = [
            "label" => $levelName,
            "players_count" => (int)($levelPlayerCounts[$levelName] ?? 0),
        ];
    }
}

foreach ($levelPlayerCounts as $levelName => $playersCount) {
    if (!isset($groupLevels[$levelName])) {
        $groupLevels[$levelName] = [
            "label" => $levelName,
            "players_count" => (int)$playersCount,
        ];
    }
}

ksort($groupLevels, SORT_NATURAL);

$egyptNow = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$todayDate = $egyptNow->format("Y-m-d");
$egyptDateTimeLabel = formatPlayerNotificationEgyptDateTimeLabel($egyptNow);

$searchQuery = trim((string)($_GET["search"] ?? ""));
$searchQuery = limitPlayerNotificationText($searchQuery, 100);
$statusFilter = sanitizePlayerNotificationChoice($_GET["status_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationStatuses), "all");
$scopeFilter = sanitizePlayerNotificationChoice($_GET["scope_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationScopes), "all");

$formData = [
    "id" => 0,
    "title" => "",
    "message" => "",
    "notification_type" => "general",
    "priority_level" => "normal",
    "visibility_status" => "visible",
    "target_scope" => "all",
    "target_group_id" => "",
    "target_group_name" => "",
    "target_group_level" => "",
    "display_date" => $todayDate,
];

$flashSuccess = $_SESSION["players_notifications_success"] ?? "";
unset($_SESSION["players_notifications_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["players_notifications_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";
        $redirectSearch = limitPlayerNotificationText(trim((string)($_POST["redirect_search"] ?? "")), 100);
        $redirectStatusFilter = sanitizePlayerNotificationChoice($_POST["redirect_status_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationStatuses), "all");
        $redirectScopeFilter = sanitizePlayerNotificationChoice($_POST["redirect_scope_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationScopes), "all");

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["notification_id"] ?? 0),
                "title" => normalizePlayerNotificationTitle($_POST["title"] ?? ""),
                "message" => normalizePlayerNotificationMessage($_POST["message"] ?? ""),
                "notification_type" => sanitizePlayerNotificationChoice($_POST["notification_type"] ?? "general", $notificationTypes, "general"),
                "priority_level" => sanitizePlayerNotificationChoice($_POST["priority_level"] ?? "normal", $notificationPriorities, "normal"),
                "visibility_status" => sanitizePlayerNotificationChoice($_POST["visibility_status"] ?? "visible", $notificationStatuses, "visible"),
                "target_scope" => sanitizePlayerNotificationChoice($_POST["target_scope"] ?? "all", $notificationScopes, "all"),
                "target_group_id" => (int)($_POST["target_group_id"] ?? 0),
                "target_group_name" => "",
                "target_group_level" => limitPlayerNotificationText(trim((string)($_POST["target_group_level"] ?? "")), 150),
                "display_date" => trim((string)($_POST["display_date"] ?? $todayDate)),
            ];

            if ($formData["title"] === "") {
                $error = "عنوان الإشعار مطلوب.";
            } elseif ($formData["message"] === "") {
                $error = "نص الإشعار مطلوب.";
            } elseif (!isValidPlayerNotificationDate($formData["display_date"])) {
                $error = "تاريخ الظهور غير صحيح.";
            } elseif ($formData["target_scope"] === "level" && $formData["target_group_level"] === "") {
                $error = "اختر المستوى المستهدف.";
            } elseif ($formData["target_scope"] === "group" && $formData["target_group_id"] <= 0) {
                $error = "اختر المجموعة المستهدفة.";
            }

            if ($error === "" && $formData["target_scope"] === "level") {
                if (!isset($groupLevels[$formData["target_group_level"]])) {
                    $error = "المستوى المحدد غير متاح.";
                } else {
                    $formData["target_group_id"] = 0;
                    $formData["target_group_level"] = $groupLevels[$formData["target_group_level"]]["label"];
                }
            }

            if ($error === "" && $formData["target_scope"] === "group") {
                if (!isset($groupMap[$formData["target_group_id"]])) {
                    $error = "المجموعة المحددة غير متاحة.";
                } else {
                    $selectedGroup = $groupMap[$formData["target_group_id"]];
                    $formData["target_group_name"] = trim((string)($selectedGroup["group_name"] ?? ""));
                    $formData["target_group_level"] = trim((string)($selectedGroup["group_level"] ?? ""));
                }
            }

            if ($formData["target_scope"] === "all") {
                $formData["target_group_id"] = 0;
                $formData["target_group_name"] = "";
                $formData["target_group_level"] = "";
            } elseif ($formData["target_scope"] === "level") {
                $formData["target_group_name"] = "";
            }

            if ($error === "") {
                $existingNotification = $formData["id"] > 0 ? fetchPlayerNotificationRecord($pdo, $formData["id"], $currentGameId) : null;
                if ($formData["id"] > 0 && !$existingNotification) {
                    $error = "الإشعار غير متاح.";
                }
            }

            if ($error === "") {
                try {
                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE player_notifications
                             SET title = ?, message = ?, notification_type = ?, priority_level = ?, visibility_status = ?, target_scope = ?,
                                 target_group_id = ?, target_group_name = ?, target_group_level = ?, display_date = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["title"],
                            $formData["message"],
                            $formData["notification_type"],
                            $formData["priority_level"],
                            $formData["visibility_status"],
                            $formData["target_scope"],
                            $formData["target_group_id"] > 0 ? $formData["target_group_id"] : null,
                            $formData["target_group_name"] !== "" ? $formData["target_group_name"] : null,
                            $formData["target_group_level"] !== "" ? $formData["target_group_level"] : null,
                            $formData["display_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "player_notifications", $formData["id"], "إشعارات اللاعبين", "تعديل إشعار: " . $formData["title"]);
                        $_SESSION["players_notifications_success"] = "تم تحديث الإشعار.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO player_notifications (
                                game_id, title, message, notification_type, priority_level, visibility_status, target_scope,
                                target_group_id, target_group_name, target_group_level, display_date, created_by_user_id, updated_by_user_id
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["title"],
                            $formData["message"],
                            $formData["notification_type"],
                            $formData["priority_level"],
                            $formData["visibility_status"],
                            $formData["target_scope"],
                            $formData["target_group_id"] > 0 ? $formData["target_group_id"] : null,
                            $formData["target_group_name"] !== "" ? $formData["target_group_name"] : null,
                            $formData["target_group_level"] !== "" ? $formData["target_group_level"] : null,
                            $formData["display_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newNotificationId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "player_notifications", $newNotificationId, "إشعارات اللاعبين", "إضافة إشعار: " . $formData["title"]);
                        $_SESSION["players_notifications_success"] = "تم حفظ الإشعار.";
                    }

                    header("Location: " . buildPlayerNotificationsPageUrl([
                        "search" => $redirectSearch,
                        "status_filter" => $redirectStatusFilter,
                        "scope_filter" => $redirectScopeFilter,
                    ]));
                    exit;
                } catch (Throwable $throwable) {
                    logPlayerNotificationException($throwable);
                    $error = "تعذر حفظ الإشعار.";
                }
            }
        }

        if ($action === "delete") {
            $notificationId = (int)($_POST["notification_id"] ?? 0);
            if ($notificationId <= 0) {
                $error = "الإشعار غير صالح.";
            } else {
                $notificationToDelete = fetchPlayerNotificationRecord($pdo, $notificationId, $currentGameId);
                $deleteStmt = $pdo->prepare("DELETE FROM player_notifications WHERE id = ? AND game_id = ?");

                try {
                    $deleteStmt->execute([$notificationId, $currentGameId]);
                    if ($deleteStmt->rowCount() === 0) {
                        $error = "الإشعار غير متاح.";
                    } else {
                        auditLogActivity($pdo, "delete", "player_notifications", $notificationId, "إشعارات اللاعبين", "حذف إشعار: " . (string)($notificationToDelete["title"] ?? ""));
                        $_SESSION["players_notifications_success"] = "تم حذف الإشعار.";
                        header("Location: " . buildPlayerNotificationsPageUrl([
                            "search" => $redirectSearch,
                            "status_filter" => $redirectStatusFilter,
                            "scope_filter" => $redirectScopeFilter,
                        ]));
                        exit;
                    }
                } catch (Throwable $throwable) {
                    logPlayerNotificationException($throwable);
                    $error = "تعذر حذف الإشعار.";
                }
            }
        }
    }
}

$editNotificationId = (int)($_GET["edit"] ?? 0);
if ($editNotificationId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editNotification = fetchPlayerNotificationRecord($pdo, $editNotificationId, $currentGameId);
    if ($editNotification) {
        $formData = [
            "id" => (int)$editNotification["id"],
            "title" => (string)($editNotification["title"] ?? ""),
            "message" => (string)($editNotification["message"] ?? ""),
            "notification_type" => sanitizePlayerNotificationChoice($editNotification["notification_type"] ?? "general", $notificationTypes, "general"),
            "priority_level" => sanitizePlayerNotificationChoice($editNotification["priority_level"] ?? "normal", $notificationPriorities, "normal"),
            "visibility_status" => sanitizePlayerNotificationChoice($editNotification["visibility_status"] ?? "visible", $notificationStatuses, "visible"),
            "target_scope" => sanitizePlayerNotificationChoice($editNotification["target_scope"] ?? "all", $notificationScopes, "all"),
            "target_group_id" => (int)($editNotification["target_group_id"] ?? 0),
            "target_group_name" => (string)($editNotification["target_group_name"] ?? ""),
            "target_group_level" => (string)($editNotification["target_group_level"] ?? ""),
            "display_date" => (string)($editNotification["display_date"] ?? $todayDate),
        ];
    }
}

$summary = fetchPlayerNotificationSummary($pdo, $currentGameId);
$records = fetchPlayerNotificationRows($pdo, $currentGameId, $searchQuery, $statusFilter, $scopeFilter);
$cancelUrl = buildPlayerNotificationsPageUrl([
    "search" => $searchQuery,
    "status_filter" => $statusFilter,
    "scope_filter" => $scopeFilter,
]);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إشعارات اللاعبين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">القائمة</button>
                <div>
                    <h1>إشعارات اللاعبين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge"><?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <span class="context-badge egypt-datetime-badge" id="egyptDateTime"><?php echo htmlspecialchars($egyptDateTimeLabel, ENT_QUOTES, "UTF-8"); ?></span>
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">🌙</span>
                    </span>
                </label>
            </div>
        </header>

        <?php if ($success !== ""): ?>
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <section class="trainers-summary-grid">
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">إجمالي الإشعارات</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["total_count"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">الإشعارات الظاهرة</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["visible_count"]; ?></strong>
            </div>
            <div class="card trainer-stat-card player-notification-summary-card-all">
                <span class="trainer-stat-label">إشعارات لكل اللاعبين</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["all_players_count"]; ?></strong>
            </div>
            <div class="card trainer-stat-card player-notification-summary-card-targeted">
                <span class="trainer-stat-label">الإشعارات الموجهة</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["targeted_count"]; ?></strong>
            </div>
        </section>

        <section class="attendance-layout-grid">
            <div class="attendance-stack">
                <div class="card attendance-filter-card">
                    <div class="card-head">
                        <div>
                            <h3><?php echo $formData["id"] > 0 ? "تعديل إشعار" : "إضافة إشعار"; ?></h3>
                        </div>
                        <?php if ($formData["id"] > 0): ?>
                            <a href="<?php echo htmlspecialchars($cancelUrl, ENT_QUOTES, "UTF-8"); ?>" class="btn btn-soft">إلغاء</a>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="attendance-filter-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["players_notifications_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="notification_id" value="<?php echo (int)$formData["id"]; ?>">
                        <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="redirect_status_filter" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="redirect_scope_filter" value="<?php echo htmlspecialchars($scopeFilter, ENT_QUOTES, "UTF-8"); ?>">
                        <div class="attendance-filter-grid">
                            <div class="form-group form-group-full">
                                <label for="notificationTitle">عنوان الإشعار</label>
                                <input type="text" name="title" id="notificationTitle" maxlength="160" value="<?php echo htmlspecialchars((string)$formData["title"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="notificationType">نوع الإشعار</label>
                                <select name="notification_type" id="notificationType" required>
                                    <?php foreach ($notificationTypes as $typeValue => $typeLabel): ?>
                                        <option value="<?php echo htmlspecialchars($typeValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $formData["notification_type"] === $typeValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="notificationPriority">الأولوية</label>
                                <select name="priority_level" id="notificationPriority" required>
                                    <?php foreach ($notificationPriorities as $priorityValue => $priorityLabel): ?>
                                        <option value="<?php echo htmlspecialchars($priorityValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $formData["priority_level"] === $priorityValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($priorityLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="notificationStatus">الحالة</label>
                                <select name="visibility_status" id="notificationStatus" required>
                                    <?php foreach ($notificationStatuses as $statusValue => $statusLabel): ?>
                                        <option value="<?php echo htmlspecialchars($statusValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $formData["visibility_status"] === $statusValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="notificationDate">تاريخ الظهور</label>
                                <input type="date" name="display_date" id="notificationDate" value="<?php echo htmlspecialchars((string)$formData["display_date"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="targetScope">الفئة المستهدفة</label>
                                <select name="target_scope" id="targetScope" required>
                                    <?php foreach ($notificationScopes as $scopeValue => $scopeLabel): ?>
                                        <option value="<?php echo htmlspecialchars($scopeValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $formData["target_scope"] === $scopeValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($scopeLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="targetLevelField" <?php echo $formData["target_scope"] === "level" ? "" : "hidden"; ?>>
                                <label for="targetLevel">المستوى</label>
                                <select name="target_group_level" id="targetLevel">
                                    <option value="">اختر المستوى</option>
                                    <?php foreach ($groupLevels as $levelValue => $levelData): ?>
                                        <option value="<?php echo htmlspecialchars($levelValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $formData["target_group_level"] === $levelValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($levelData["label"] . " - " . (int)$levelData["players_count"], ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="targetGroupField" <?php echo $formData["target_scope"] === "group" ? "" : "hidden"; ?>>
                                <label for="targetGroup">المجموعة</label>
                                <select name="target_group_id" id="targetGroup">
                                    <option value="">اختر المجموعة</option>
                                    <?php foreach ($groups as $group): ?>
                                        <?php $groupId = (int)$group["id"]; ?>
                                        <option value="<?php echo $groupId; ?>" <?php echo (int)$formData["target_group_id"] === $groupId ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars((string)$group["group_name"] . " - " . (string)$group["group_level"] . " - " . (int)$group["players_count"], ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group form-group-full">
                                <label for="notificationMessage">نص الإشعار</label>
                                <textarea name="message" id="notificationMessage" class="notification-message-input" required><?php echo htmlspecialchars((string)$formData["message"], ENT_QUOTES, "UTF-8"); ?></textarea>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary"><?php echo $formData["id"] > 0 ? "تحديث الإشعار" : "حفظ الإشعار"; ?></button>
                        </div>
                    </form>
                </div>

                <div class="card attendance-filter-card">
                    <div class="card-head">
                        <div>
                            <h3>فلترة الإشعارات</h3>
                        </div>
                    </div>

                    <form method="GET" class="attendance-filter-form">
                        <div class="attendance-filter-grid">
                            <div class="form-group form-group-full">
                                <label for="notificationSearch">بحث</label>
                                <input type="text" name="search" id="notificationSearch" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="filterStatus">الحالة</label>
                                <select name="status_filter" id="filterStatus">
                                    <option value="all" <?php echo $statusFilter === "all" ? "selected" : ""; ?>>الكل</option>
                                    <?php foreach ($notificationStatuses as $statusValue => $statusLabel): ?>
                                        <option value="<?php echo htmlspecialchars($statusValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $statusFilter === $statusValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filterScope">الفئة المستهدفة</label>
                                <select name="scope_filter" id="filterScope">
                                    <option value="all" <?php echo $scopeFilter === "all" ? "selected" : ""; ?>>الكل</option>
                                    <?php foreach ($notificationScopes as $scopeValue => $scopeLabel): ?>
                                        <option value="<?php echo htmlspecialchars($scopeValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $scopeFilter === $scopeValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($scopeLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض النتائج</button>
                            <a href="players_notifications.php" class="btn btn-soft">إعادة ضبط</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card attendance-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>جدول الإشعارات</h3>
                    </div>
                    <span class="table-counter"><?php echo count($records); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table attendance-table">
                        <thead>
                            <tr>
                                <th>م</th>
                                <th>العنوان</th>
                                <th>الرسالة</th>
                                <th>الفئة المستهدفة</th>
                                <th>النوع</th>
                                <th>الأولوية</th>
                                <th>الحالة</th>
                                <th>تاريخ الظهور</th>
                                <th>عدد اللاعبين</th>
                                <th>آخر تحديث</th>
                                <th>بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) === 0): ?>
                                <tr>
                                    <td colspan="12" class="empty-cell">لا توجد إشعارات مسجلة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $index => $record): ?>
                                    <?php $editUrl = buildPlayerNotificationsPageUrl([
                                        "search" => $searchQuery,
                                        "status_filter" => $statusFilter,
                                        "scope_filter" => $scopeFilter,
                                        "edit" => (int)$record["id"],
                                    ]); ?>
                                    <tr>
                                        <td data-label="م" class="trainer-row-number"><?php echo (int)($index + 1); ?></td>
                                        <td data-label="العنوان">
                                            <div class="notification-title-cell">
                                                <strong><?php echo htmlspecialchars((string)$record["title"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="الرسالة">
                                            <div class="notification-message-cell"><?php echo htmlspecialchars((string)$record["message"], ENT_QUOTES, "UTF-8"); ?></div>
                                        </td>
                                        <td data-label="الفئة المستهدفة">
                                            <div class="player-notification-target-cell">
                                                <span class="status-chip <?php echo htmlspecialchars(getPlayerNotificationScopeClass((string)$record["target_scope"]), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars((string)$record["audience_label"], ENT_QUOTES, "UTF-8"); ?></span>
                                                <span class="player-notification-target-meta">عدد اللاعبين: <?php echo (int)($record["audience_count"] ?? 0); ?></span>
                                            </div>
                                        </td>
                                        <td data-label="النوع">
                                            <span class="notification-type-badge"><?php echo htmlspecialchars($notificationTypes[$record["notification_type"]] ?? (string)$record["notification_type"], ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="الأولوية">
                                            <span class="status-chip <?php echo htmlspecialchars(getPlayerNotificationPriorityClass((string)$record["priority_level"]), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($notificationPriorities[$record["priority_level"]] ?? (string)$record["priority_level"], ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="الحالة">
                                            <span class="status-chip <?php echo htmlspecialchars(getPlayerNotificationStatusClass((string)$record["visibility_status"]), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($notificationStatuses[$record["visibility_status"]] ?? (string)$record["visibility_status"], ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="تاريخ الظهور"><?php echo htmlspecialchars((string)$record["display_date"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="عدد اللاعبين"><?php echo (int)($record["audience_count"] ?? 0); ?></td>
                                        <td data-label="آخر تحديث"><?php echo htmlspecialchars(formatPlayerNotificationDateTimeValue($record["updated_at"] ?? ""), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="بواسطة"><?php echo htmlspecialchars((string)($record["actor_name"] ?? PLAYER_NOTIFICATION_EMPTY_VALUE), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="<?php echo htmlspecialchars($editUrl, ENT_QUOTES, "UTF-8"); ?>" class="btn btn-warning">تعديل</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["players_notifications_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="notification_id" value="<?php echo (int)$record["id"]; ?>">
                                                    <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="redirect_status_filter" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="redirect_scope_filter" value="<?php echo htmlspecialchars($scopeFilter, ENT_QUOTES, "UTF-8"); ?>">
                                                    <button type="submit" class="btn btn-danger" onclick="return confirm('هل تريد حذف هذا الإشعار؟')">حذف</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/script.js"></script>
<script>
    (function () {
        var scopeSelect = document.getElementById('targetScope');
        var levelField = document.getElementById('targetLevelField');
        var groupField = document.getElementById('targetGroupField');
        var levelSelect = document.getElementById('targetLevel');
        var groupSelect = document.getElementById('targetGroup');

        if (!scopeSelect || !levelField || !groupField) {
            return;
        }

        function toggleTargetFields() {
            var scope = scopeSelect.value;
            var showLevel = scope === 'level';
            var showGroup = scope === 'group';

            levelField.hidden = !showLevel;
            groupField.hidden = !showGroup;

            if (levelSelect) {
                levelSelect.required = showLevel;
                if (!showLevel) {
                    levelSelect.value = '';
                }
            }

            if (groupSelect) {
                groupSelect.required = showGroup;
                if (!showGroup) {
                    groupSelect.value = '';
                }
            }
        }

        scopeSelect.addEventListener('change', toggleTargetFields);
        toggleTargetFields();
    })();
</script>
</body>
</html>
