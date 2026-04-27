<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

date_default_timezone_set("Africa/Cairo");

const ADMIN_NOTIFICATION_EMPTY_VALUE = "—";

requireAuthenticatedUser();
requireMenuAccess("admins-notifications");

function getAdminNotificationTypes()
{
    return [
        "general" => "عام",
        "reminder" => "تذكير",
        "alert" => "تنبيه",
        "administrative" => "إداري",
    ];
}

function getAdminNotificationPriorities()
{
    return [
        "normal" => "عادي",
        "important" => "مهم",
        "urgent" => "عاجل",
    ];
}

function getAdminNotificationStatuses()
{
    return [
        "visible" => "ظاهر",
        "hidden" => "مخفي",
    ];
}

function limitAdminNotificationText($value, $maxLength)
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

function normalizeAdminNotificationTitle($title)
{
    $title = preg_replace('/\s+/u', ' ', trim((string)$title));
    return limitAdminNotificationText($title, 160);
}

function normalizeAdminNotificationMessage($message)
{
    $message = str_replace(["\r\n", "\r"], "\n", (string)$message);
    $message = preg_replace("/\n{3,}/", "\n\n", $message);
    $message = trim($message);
    return limitAdminNotificationText($message, 3000);
}

function sanitizeAdminNotificationChoice($value, array $allowedValues, $defaultValue)
{
    $value = trim((string)$value);
    if ($value !== "" && array_key_exists($value, $allowedValues)) {
        return $value;
    }

    return $defaultValue;
}

function isValidAdminNotificationDate($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("Y-m-d", $date, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $date;
}

function formatAdminNotificationEgyptDateTimeLabel(DateTimeInterface $dateTime)
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

function formatAdminNotificationDateTimeValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return ADMIN_NOTIFICATION_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return ADMIN_NOTIFICATION_EMPTY_VALUE;
    }

    return formatAdminNotificationEgyptDateTimeLabel($dateTime);
}

function ensureAdminNotificationsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority_level VARCHAR(20) NOT NULL DEFAULT 'normal',
            visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible',
            display_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_notifications_game_date (game_id, display_date),
            KEY idx_admin_notifications_status (game_id, visibility_status),
            KEY idx_admin_notifications_priority (game_id, priority_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "title" => "ALTER TABLE admin_notifications ADD COLUMN title VARCHAR(160) NOT NULL AFTER game_id",
        "message" => "ALTER TABLE admin_notifications ADD COLUMN message TEXT NOT NULL AFTER title",
        "notification_type" => "ALTER TABLE admin_notifications ADD COLUMN notification_type VARCHAR(50) NOT NULL DEFAULT 'general' AFTER message",
        "priority_level" => "ALTER TABLE admin_notifications ADD COLUMN priority_level VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER notification_type",
        "visibility_status" => "ALTER TABLE admin_notifications ADD COLUMN visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible' AFTER priority_level",
        "display_date" => "ALTER TABLE admin_notifications ADD COLUMN display_date DATE NOT NULL AFTER visibility_status",
        "created_by_user_id" => "ALTER TABLE admin_notifications ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER display_date",
        "updated_by_user_id" => "ALTER TABLE admin_notifications ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
    ];

    foreach ($requiredColumns as $columnName => $sql) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM admin_notifications LIKE " . $pdo->quote($columnName));
        if (!$columnStmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

function fetchAdminNotificationRecord(PDO $pdo, $notificationId, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, title, message, notification_type, priority_level, visibility_status, display_date
         FROM admin_notifications
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$notificationId, (int)$gameId]);
    return $stmt->fetch();
}

function fetchAdminNotificationRows(PDO $pdo, $gameId, $searchQuery, $statusFilter, $typeFilter)
{
    $sql = "SELECT
            n.id,
            n.title,
            n.message,
            n.notification_type,
            n.priority_level,
            n.visibility_status,
            n.display_date,
            n.created_at,
            n.updated_at,
            COALESCE(created_user.username, " . $pdo->quote(ADMIN_NOTIFICATION_EMPTY_VALUE) . ") AS created_by_name,
            COALESCE(updated_user.username, " . $pdo->quote(ADMIN_NOTIFICATION_EMPTY_VALUE) . ") AS updated_by_name
        FROM admin_notifications n
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

    if ($typeFilter !== "all") {
        $sql .= " AND n.notification_type = ?";
        $params[] = $typeFilter;
    }

    if ($searchQuery !== "") {
        $sql .= " AND (n.title LIKE ? OR n.message LIKE ?)";
        $searchValue = "%" . $searchQuery . "%";
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    $sql .= " ORDER BY n.display_date DESC, n.created_at DESC, n.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetchAdminNotificationSummary(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN visibility_status = 'visible' THEN 1 ELSE 0 END) AS visible_count,
            SUM(CASE WHEN visibility_status = 'hidden' THEN 1 ELSE 0 END) AS hidden_count,
            SUM(CASE WHEN priority_level = 'urgent' THEN 1 ELSE 0 END) AS urgent_count
         FROM admin_notifications
         WHERE game_id = ?"
    );
    $stmt->execute([(int)$gameId]);
    $summary = $stmt->fetch();

    return [
        "total_count" => (int)($summary["total_count"] ?? 0),
        "visible_count" => (int)($summary["visible_count"] ?? 0),
        "hidden_count" => (int)($summary["hidden_count"] ?? 0),
        "urgent_count" => (int)($summary["urgent_count"] ?? 0),
    ];
}

function getAdminNotificationStatusClass($status)
{
    if ($status === "visible") {
        return "status-success";
    }

    return "status-neutral";
}

function getAdminNotificationPriorityClass($priority)
{
    if ($priority === "urgent") {
        return "status-danger";
    }

    if ($priority === "important") {
        return "status-warning";
    }

    return "status-info";
}

function buildAdminNotificationsPageUrl(array $params = [])
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

        if (in_array($key, ["status_filter", "type_filter"], true) && $value === "all") {
            continue;
        }

        $filteredParams[$key] = $value;
    }

    $query = http_build_query($filteredParams);
    return "admin_notifications.php" . ($query !== "" ? "?" . $query : "");
}

function logAdminNotificationException(Throwable $throwable)
{
    error_log("Admin notifications page error: " . $throwable->getMessage());
}

if (!isset($_SESSION["admin_notifications_csrf_token"])) {
    $_SESSION["admin_notifications_csrf_token"] = bin2hex(random_bytes(32));
}

ensureAdminNotificationsTable($pdo);

$success = "";
$error = "";
$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$notificationTypes = getAdminNotificationTypes();
$notificationPriorities = getAdminNotificationPriorities();
$notificationStatuses = getAdminNotificationStatuses();

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "admins-notifications";

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

$egyptNow = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$todayDate = $egyptNow->format("Y-m-d");
$egyptDateTimeLabel = formatAdminNotificationEgyptDateTimeLabel($egyptNow);

$searchQuery = trim((string)($_GET["search"] ?? ""));
$searchQuery = limitAdminNotificationText($searchQuery, 100);
$statusFilter = sanitizeAdminNotificationChoice($_GET["status_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationStatuses), "all");
$typeFilter = sanitizeAdminNotificationChoice($_GET["type_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationTypes), "all");

$formData = [
    "id" => 0,
    "title" => "",
    "message" => "",
    "notification_type" => "general",
    "priority_level" => "normal",
    "visibility_status" => "visible",
    "display_date" => $todayDate,
];

$flashSuccess = $_SESSION["admin_notifications_success"] ?? "";
unset($_SESSION["admin_notifications_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["admin_notifications_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";
        $redirectSearch = limitAdminNotificationText(trim((string)($_POST["redirect_search"] ?? "")), 100);
        $redirectStatusFilter = sanitizeAdminNotificationChoice($_POST["redirect_status_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationStatuses), "all");
        $redirectTypeFilter = sanitizeAdminNotificationChoice($_POST["redirect_type_filter"] ?? "all", array_merge(["all" => "الكل"], $notificationTypes), "all");

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["notification_id"] ?? 0),
                "title" => normalizeAdminNotificationTitle($_POST["title"] ?? ""),
                "message" => normalizeAdminNotificationMessage($_POST["message"] ?? ""),
                "notification_type" => sanitizeAdminNotificationChoice($_POST["notification_type"] ?? "general", $notificationTypes, "general"),
                "priority_level" => sanitizeAdminNotificationChoice($_POST["priority_level"] ?? "normal", $notificationPriorities, "normal"),
                "visibility_status" => sanitizeAdminNotificationChoice($_POST["visibility_status"] ?? "visible", $notificationStatuses, "visible"),
                "display_date" => trim((string)($_POST["display_date"] ?? $todayDate)),
            ];

            if ($formData["title"] === "") {
                $error = "عنوان الإشعار مطلوب.";
            } elseif ($formData["message"] === "") {
                $error = "نص الإشعار مطلوب.";
            } elseif (!isValidAdminNotificationDate($formData["display_date"])) {
                $error = "تاريخ الظهور غير صحيح.";
            }

            if ($error === "") {
                $existingNotification = $formData["id"] > 0 ? fetchAdminNotificationRecord($pdo, $formData["id"], $currentGameId) : null;
                if ($formData["id"] > 0 && !$existingNotification) {
                    $error = "الإشعار غير متاح.";
                }
            }

            if ($error === "") {
                try {
                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE admin_notifications
                             SET title = ?, message = ?, notification_type = ?, priority_level = ?, visibility_status = ?, display_date = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["title"],
                            $formData["message"],
                            $formData["notification_type"],
                            $formData["priority_level"],
                            $formData["visibility_status"],
                            $formData["display_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "admin_notifications", $formData["id"], "إشعارات الإداريين", "تعديل إشعار: " . $formData["title"]);
                        $_SESSION["admin_notifications_success"] = "تم تحديث الإشعار.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO admin_notifications (game_id, title, message, notification_type, priority_level, visibility_status, display_date, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["title"],
                            $formData["message"],
                            $formData["notification_type"],
                            $formData["priority_level"],
                            $formData["visibility_status"],
                            $formData["display_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newNotificationId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "admin_notifications", $newNotificationId, "إشعارات الإداريين", "إضافة إشعار: " . $formData["title"]);
                        $_SESSION["admin_notifications_success"] = "تم تسجيل الإشعار.";
                    }

                    header("Location: " . buildAdminNotificationsPageUrl([
                        "search" => $redirectSearch,
                        "status_filter" => $redirectStatusFilter,
                        "type_filter" => $redirectTypeFilter,
                    ]));
                    exit;
                } catch (Throwable $throwable) {
                    logAdminNotificationException($throwable);
                    $error = "تعذر حفظ الإشعار.";
                }
            }
        }

        if ($action === "delete") {
            $notificationId = (int)($_POST["notification_id"] ?? 0);
            if ($notificationId <= 0) {
                $error = "الإشعار غير صالح.";
            } else {
                $notificationToDelete = fetchAdminNotificationRecord($pdo, $notificationId, $currentGameId);
                $deleteStmt = $pdo->prepare("DELETE FROM admin_notifications WHERE id = ? AND game_id = ?");

                try {
                    $deleteStmt->execute([$notificationId, $currentGameId]);
                    if ($deleteStmt->rowCount() === 0) {
                        $error = "الإشعار غير متاح.";
                    } else {
                        auditLogActivity($pdo, "delete", "admin_notifications", $notificationId, "إشعارات الإداريين", "حذف إشعار: " . (string)($notificationToDelete["title"] ?? ""));
                        $_SESSION["admin_notifications_success"] = "تم حذف الإشعار.";
                        header("Location: " . buildAdminNotificationsPageUrl([
                            "search" => $redirectSearch,
                            "status_filter" => $redirectStatusFilter,
                            "type_filter" => $redirectTypeFilter,
                        ]));
                        exit;
                    }
                } catch (Throwable $throwable) {
                    logAdminNotificationException($throwable);
                    $error = "تعذر حذف الإشعار.";
                }
            }
        }
    }
}

$editNotificationId = (int)($_GET["edit"] ?? 0);
if ($editNotificationId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editNotification = fetchAdminNotificationRecord($pdo, $editNotificationId, $currentGameId);
    if ($editNotification) {
        $formData = [
            "id" => (int)$editNotification["id"],
            "title" => (string)($editNotification["title"] ?? ""),
            "message" => (string)($editNotification["message"] ?? ""),
            "notification_type" => sanitizeAdminNotificationChoice($editNotification["notification_type"] ?? "general", $notificationTypes, "general"),
            "priority_level" => sanitizeAdminNotificationChoice($editNotification["priority_level"] ?? "normal", $notificationPriorities, "normal"),
            "visibility_status" => sanitizeAdminNotificationChoice($editNotification["visibility_status"] ?? "visible", $notificationStatuses, "visible"),
            "display_date" => (string)($editNotification["display_date"] ?? $todayDate),
        ];
    }
}

$summary = fetchAdminNotificationSummary($pdo, $currentGameId);
$records = fetchAdminNotificationRows($pdo, $currentGameId, $searchQuery, $statusFilter, $typeFilter);
$cancelUrl = buildAdminNotificationsPageUrl([
    "search" => $searchQuery,
    "status_filter" => $statusFilter,
    "type_filter" => $typeFilter,
]);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إشعارات الإداريين</title>
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
                    <h1>إشعارات الإداريين</h1>
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
            <div class="card trainer-stat-card notification-summary-card-hidden">
                <span class="trainer-stat-label">الإشعارات المخفية</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["hidden_count"]; ?></strong>
            </div>
            <div class="card trainer-stat-card notification-summary-card-urgent">
                <span class="trainer-stat-label">الإشعارات العاجلة</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["urgent_count"]; ?></strong>
            </div>
        </section>

        <section class="attendance-layout-grid">
            <div class="attendance-stack">
                <div class="card attendance-filter-card">
                    <div class="card-head">
                        <div>
                            <h3><?php echo $formData["id"] > 0 ? "تعديل إشعار" : "تسجيل إشعار جديد"; ?></h3>
                        </div>
                        <?php if ($formData["id"] > 0): ?>
                            <a href="<?php echo htmlspecialchars($cancelUrl, ENT_QUOTES, "UTF-8"); ?>" class="btn btn-soft">إلغاء</a>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="attendance-filter-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admin_notifications_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="notification_id" value="<?php echo (int)$formData["id"]; ?>">
                        <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="redirect_status_filter" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="redirect_type_filter" value="<?php echo htmlspecialchars($typeFilter, ENT_QUOTES, "UTF-8"); ?>">
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
                                <label for="filterType">النوع</label>
                                <select name="type_filter" id="filterType">
                                    <option value="all" <?php echo $typeFilter === "all" ? "selected" : ""; ?>>الكل</option>
                                    <?php foreach ($notificationTypes as $typeValue => $typeLabel): ?>
                                        <option value="<?php echo htmlspecialchars($typeValue, ENT_QUOTES, "UTF-8"); ?>" <?php echo $typeFilter === $typeValue ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض النتائج</button>
                            <a href="admin_notifications.php" class="btn btn-soft">إعادة ضبط</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card attendance-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>جدول الإشعارات المسجلة</h3>
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
                                <th>النوع</th>
                                <th>الأولوية</th>
                                <th>الحالة</th>
                                <th>تاريخ الظهور</th>
                                <th>وقت التسجيل</th>
                                <th>أضيف بواسطة</th>
                                <th>آخر تعديل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) === 0): ?>
                                <tr>
                                    <td colspan="11" class="empty-cell">لا توجد إشعارات مسجلة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $index => $record): ?>
                                    <?php $editUrl = buildAdminNotificationsPageUrl([
                                        "search" => $searchQuery,
                                        "status_filter" => $statusFilter,
                                        "type_filter" => $typeFilter,
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
                                        <td data-label="النوع">
                                            <span class="notification-type-badge"><?php echo htmlspecialchars($notificationTypes[$record["notification_type"]] ?? (string)$record["notification_type"], ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="الأولوية">
                                            <span class="status-chip <?php echo htmlspecialchars(getAdminNotificationPriorityClass((string)$record["priority_level"]), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($notificationPriorities[$record["priority_level"]] ?? (string)$record["priority_level"], ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="الحالة">
                                            <span class="status-chip <?php echo htmlspecialchars(getAdminNotificationStatusClass((string)$record["visibility_status"]), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($notificationStatuses[$record["visibility_status"]] ?? (string)$record["visibility_status"], ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="تاريخ الظهور"><?php echo htmlspecialchars((string)$record["display_date"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="وقت التسجيل"><?php echo htmlspecialchars(formatAdminNotificationDateTimeValue($record["created_at"] ?? ""), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars((string)($record["created_by_name"] ?? ADMIN_NOTIFICATION_EMPTY_VALUE), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="آخر تعديل بواسطة"><?php echo htmlspecialchars((string)($record["updated_by_name"] ?? ADMIN_NOTIFICATION_EMPTY_VALUE), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="<?php echo htmlspecialchars($editUrl, ENT_QUOTES, "UTF-8"); ?>" class="btn btn-warning">تعديل</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admin_notifications_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="notification_id" value="<?php echo (int)$record["id"]; ?>">
                                                    <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="redirect_status_filter" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="redirect_type_filter" value="<?php echo htmlspecialchars($typeFilter, ENT_QUOTES, "UTF-8"); ?>">
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
</body>
</html>
