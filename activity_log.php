<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("activity-log");

$isManager = (string)($_SESSION["role"] ?? "") === "مدير";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "activity-log";

$gamesStmt = $pdo->query("SELECT id, name FROM games ORDER BY id ASC");
$allGames = $gamesStmt->fetchAll();
$gamesById = [];
foreach ($allGames as $game) {
    $gamesById[(int)$game["id"]] = (string)$game["name"];
}

$usersStmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
$allUsers = $usersStmt->fetchAll();

$tablesLabelMap = [
    "admins" => "الإداريين",
    "admin_attendance" => "حضور الإداريين",
    "admin_days_off" => "إجازات الإداريين",
    "admin_loans" => "سلف الإداريين",
    "admin_notifications" => "إشعارات الإداريين",
    "admin_weekly_schedule" => "جدول الإداريين",
    "trainers" => "المدربين",
    "trainer_attendance" => "حضور المدربين",
    "trainer_days_off" => "إجازات المدربين",
    "trainer_loans" => "سلف المدربين",
    "trainer_deductions" => "خصومات المدربين",
    "trainer_notifications" => "إشعارات المدربين",
    "trainer_weekly_schedule" => "جدول المدربين",
    "trainer_salary_payments" => "قبض المدربين",
    "players" => "اللاعبين",
    "player_attendance" => "حضور اللاعبين",
    "player_files" => "ملفات اللاعبين",
    "player_notifications" => "إشعارات اللاعبين",
    "player_subscription_history" => "تجديد الاشتراك",
    "sports_groups" => "المجموعات",
    "store_categories" => "الأصناف",
    "store_products" => "المتجر",
    "store_expenses" => "المصروفات",
    "sales_invoices" => "المبيعات",
    "sales_invoice_items" => "أصناف فاتورة المبيعات",
    "single_training_attendance" => "تمرينة واحدة",
    "single_training_prices" => "سعر تمرينة واحدة",
    "offers" => "العروض",
    "settings" => "إعدادات الموقع",
    "users" => "المستخدمين",
    "user_games" => "ألعاب المستخدمين",
    "user_game_permissions" => "صلاحيات المستخدمين",
    "games" => "الألعاب",
    "admin_collections" => "قبض الإداريين",
    "admin_salary_payments" => "قبض الإداريين",
    "admin_deductions" => "خصومات الإداريين",
];

$actionLabelMap = [
    "create" => "إضافة",
    "update" => "تعديل",
    "delete" => "حذف",
];

$filters = [
    "user_id" => (int)($_GET["user_id"] ?? 0),
    "action" => trim((string)($_GET["action"] ?? "")),
    "table" => trim((string)($_GET["table"] ?? "")),
    "from" => trim((string)($_GET["from"] ?? "")),
    "to" => trim((string)($_GET["to"] ?? "")),
    "scope" => trim((string)($_GET["scope"] ?? "current")),
];

if (!in_array($filters["action"], ["", "create", "update", "delete"], true)) {
    $filters["action"] = "";
}
if (!in_array($filters["scope"], ["current", "all"], true)) {
    $filters["scope"] = "current";
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters["from"])) {
    $filters["from"] = "";
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters["to"])) {
    $filters["to"] = "";
}

$where = ["1=1"];
$params = [];

if ($filters["user_id"] > 0) {
    $where[] = "al.user_id = ?";
    $params[] = $filters["user_id"];
}
if ($filters["action"] !== "") {
    $where[] = "al.action_type = ?";
    $params[] = $filters["action"];
}
if ($filters["table"] !== "" && isset($tablesLabelMap[$filters["table"]])) {
    $where[] = "al.table_name = ?";
    $params[] = $filters["table"];
}
if ($filters["from"] !== "") {
    $where[] = "DATE(al.created_at) >= ?";
    $params[] = $filters["from"];
}
if ($filters["to"] !== "") {
    $where[] = "DATE(al.created_at) <= ?";
    $params[] = $filters["to"];
}

if ($filters["scope"] === "current" && $currentGameId > 0) {
    $where[] = "(al.game_id = ? OR al.game_id IS NULL)";
    $params[] = $currentGameId;
} elseif (!$isManager) {
    if ($currentGameId > 0) {
        $where[] = "(al.game_id = ? OR al.game_id IS NULL)";
        $params[] = $currentGameId;
    }
}

$page = max(1, (int)($_GET["page"] ?? 1));
$pageSize = 50;
$offset = ($page - 1) * $pageSize;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log al WHERE " . implode(" AND ", $where));
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
}

$listSql = "SELECT al.*, u.username AS current_username
            FROM activity_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT $pageSize OFFSET $offset";

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

$summarySql = "SELECT al.action_type, COUNT(*) AS cnt
               FROM activity_log al
               WHERE " . implode(" AND ", $where) . "
               GROUP BY al.action_type";
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = ["create" => 0, "update" => 0, "delete" => 0];
foreach ($summaryStmt->fetchAll() as $row) {
    $action = (string)$row["action_type"];
    if (isset($summary[$action])) {
        $summary[$action] = (int)$row["cnt"];
    }
}

function activityLogQueryString(array $overrides, array $current)
{
    $merged = array_merge($current, $overrides);
    foreach ($merged as $key => $value) {
        if ($value === "" || $value === 0 || $value === "0") {
            unset($merged[$key]);
        }
    }
    return $merged ? "?" . http_build_query($merged) : "";
}

$baseFilters = [
    "user_id" => $filters["user_id"],
    "action" => $filters["action"],
    "table" => $filters["table"],
    "from" => $filters["from"],
    "to" => $filters["to"],
    "scope" => $filters["scope"],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل النشاطات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .activity-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .activity-summary .stat-card {
            background: var(--surface, #fff);
            border-radius: 14px;
            padding: 14px 16px;
            box-shadow: 0 4px 14px rgba(0,0,0,.06);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .activity-summary .stat-card span {
            font-size: 13px;
            color: var(--muted, #666);
        }
        .activity-summary .stat-card strong {
            font-size: 22px;
        }
        .activity-filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .activity-filter-form .form-actions {
            grid-column: 1 / -1;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .activity-action-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .activity-action-create { background: rgba(34,197,94,.15); color: #15803d; }
        .activity-action-update { background: rgba(59,130,246,.15); color: #1d4ed8; }
        .activity-action-delete { background: rgba(239,68,68,.15); color: #b91c1c; }
        .activity-pager {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-top: 14px;
            flex-wrap: wrap;
        }
        .activity-pager a, .activity-pager span {
            padding: 6px 12px;
            border-radius: 8px;
            background: var(--surface-2, #f1f1f1);
            text-decoration: none;
            color: inherit;
        }
        .activity-pager .current { background: var(--primary, #4f46e5); color: #fff; }
    </style>
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>📜 سجل النشاطات</h1>
                    <p>عرض كل عمليات الإضافة والتعديل والحذف على مستوى النظام.</p>
                </div>
            </div>

            <div class="topbar-left">
                <?php if ($currentGameName !== ""): ?>
                    <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <?php endif; ?>
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">🌙</span>
                    </span>
                </label>
            </div>
        </header>

        <section class="activity-summary">
            <div class="stat-card">
                <span>إجمالي العمليات (بالفلتر الحالي)</span>
                <strong><?php echo number_format($totalRows); ?></strong>
            </div>
            <div class="stat-card">
                <span>عمليات الإضافة</span>
                <strong><?php echo number_format($summary["create"]); ?></strong>
            </div>
            <div class="stat-card">
                <span>عمليات التعديل</span>
                <strong><?php echo number_format($summary["update"]); ?></strong>
            </div>
            <div class="stat-card">
                <span>عمليات الحذف</span>
                <strong><?php echo number_format($summary["delete"]); ?></strong>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h3>تصفية النتائج</h3>
                </div>
            </div>
            <form method="GET" class="activity-filter-form">
                <div class="form-group">
                    <label for="filter_user">المستخدم</label>
                    <select name="user_id" id="filter_user">
                        <option value="0">— الكل —</option>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?php echo (int)$user["id"]; ?>" <?php echo ((int)$user["id"] === $filters["user_id"] ? "selected" : ""); ?>>
                                <?php echo htmlspecialchars((string)$user["username"], ENT_QUOTES, "UTF-8"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="filter_action">نوع العملية</label>
                    <select name="action" id="filter_action">
                        <option value="">— الكل —</option>
                        <option value="create" <?php echo $filters["action"] === "create" ? "selected" : ""; ?>>إضافة</option>
                        <option value="update" <?php echo $filters["action"] === "update" ? "selected" : ""; ?>>تعديل</option>
                        <option value="delete" <?php echo $filters["action"] === "delete" ? "selected" : ""; ?>>حذف</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="filter_table">الصفحة / الجدول</label>
                    <select name="table" id="filter_table">
                        <option value="">— الكل —</option>
                        <?php foreach ($tablesLabelMap as $tableKey => $tableLabel): ?>
                            <option value="<?php echo htmlspecialchars($tableKey, ENT_QUOTES, "UTF-8"); ?>" <?php echo $filters["table"] === $tableKey ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($tableLabel, ENT_QUOTES, "UTF-8"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="filter_from">من تاريخ</label>
                    <input type="date" name="from" id="filter_from" value="<?php echo htmlspecialchars($filters["from"], ENT_QUOTES, "UTF-8"); ?>">
                </div>
                <div class="form-group">
                    <label for="filter_to">إلى تاريخ</label>
                    <input type="date" name="to" id="filter_to" value="<?php echo htmlspecialchars($filters["to"], ENT_QUOTES, "UTF-8"); ?>">
                </div>
                <div class="form-group">
                    <label for="filter_scope">نطاق العرض</label>
                    <select name="scope" id="filter_scope">
                        <option value="current" <?php echo $filters["scope"] === "current" ? "selected" : ""; ?>>اللعبة الحالية</option>
                        <?php if ($isManager): ?>
                            <option value="all" <?php echo $filters["scope"] === "all" ? "selected" : ""; ?>>كل الألعاب</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">عرض</button>
                    <a href="activity_log.php" class="btn btn-soft">إعادة تعيين</a>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h3>السجلات</h3>
                </div>
                <span class="table-counter">
                    صفحة <?php echo (int)$page; ?> من <?php echo (int)$totalPages; ?>
                </span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>التاريخ والوقت</th>
                            <th>المستخدم</th>
                            <th>العملية</th>
                            <th>الصفحة / الجدول</th>
                            <th>المعرّف</th>
                            <th>الوصف</th>
                            <th>اللعبة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rows) === 0): ?>
                            <tr><td colspan="7" class="empty-cell">لا توجد سجلات مطابقة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $action = (string)$row["action_type"];
                                $tableKey = (string)$row["table_name"];
                                $tableLabel = $tablesLabelMap[$tableKey] ?? ($row["page_label"] ?: $tableKey);
                                $username = (string)($row["current_username"] ?? "");
                                if ($username === "") {
                                    $username = (string)($row["username_snapshot"] ?? "");
                                }
                                if ($username === "") {
                                    $username = "غير معروف";
                                }
                                $gameLabel = "—";
                                $gameId = $row["game_id"] !== null ? (int)$row["game_id"] : 0;
                                if ($gameId > 0 && isset($gamesById[$gameId])) {
                                    $gameLabel = $gamesById[$gameId];
                                }
                                $actionClass = "activity-action-" . $action;
                                $actionText = $actionLabelMap[$action] ?? $action;
                                ?>
                                <tr>
                                    <td data-label="التاريخ والوقت">
                                        <?php echo htmlspecialchars((string)$row["created_at"], ENT_QUOTES, "UTF-8"); ?>
                                    </td>
                                    <td data-label="المستخدم">
                                        <?php echo htmlspecialchars($username, ENT_QUOTES, "UTF-8"); ?>
                                    </td>
                                    <td data-label="العملية">
                                        <span class="activity-action-pill <?php echo $actionClass; ?>">
                                            <?php echo htmlspecialchars($actionText, ENT_QUOTES, "UTF-8"); ?>
                                        </span>
                                    </td>
                                    <td data-label="الصفحة">
                                        <?php echo htmlspecialchars((string)$tableLabel, ENT_QUOTES, "UTF-8"); ?>
                                    </td>
                                    <td data-label="المعرّف">
                                        <?php echo htmlspecialchars((string)($row["record_id"] ?? "—"), ENT_QUOTES, "UTF-8"); ?>
                                    </td>
                                    <td data-label="الوصف">
                                        <?php echo htmlspecialchars((string)($row["description"] ?? ""), ENT_QUOTES, "UTF-8"); ?>
                                    </td>
                                    <td data-label="اللعبة">
                                        <?php echo htmlspecialchars((string)$gameLabel, ENT_QUOTES, "UTF-8"); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="activity-pager">
                    <?php if ($page > 1): ?>
                        <a href="activity_log.php<?php echo htmlspecialchars(activityLogQueryString(["page" => $page - 1], $baseFilters), ENT_QUOTES, "UTF-8"); ?>">السابق</a>
                    <?php endif; ?>

                    <?php
                    $pageStart = max(1, $page - 3);
                    $pageEnd = min($totalPages, $page + 3);
                    if ($pageStart > 1) {
                        echo '<a href="activity_log.php' . htmlspecialchars(activityLogQueryString(["page" => 1], $baseFilters), ENT_QUOTES, "UTF-8") . '">1</a>';
                        if ($pageStart > 2) {
                            echo '<span>…</span>';
                        }
                    }
                    for ($p = $pageStart; $p <= $pageEnd; $p++) {
                        if ($p === $page) {
                            echo '<span class="current">' . $p . '</span>';
                        } else {
                            echo '<a href="activity_log.php' . htmlspecialchars(activityLogQueryString(["page" => $p], $baseFilters), ENT_QUOTES, "UTF-8") . '">' . $p . '</a>';
                        }
                    }
                    if ($pageEnd < $totalPages) {
                        if ($pageEnd < $totalPages - 1) {
                            echo '<span>…</span>';
                        }
                        echo '<a href="activity_log.php' . htmlspecialchars(activityLogQueryString(["page" => $totalPages], $baseFilters), ENT_QUOTES, "UTF-8") . '">' . $totalPages . '</a>';
                    }
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="activity_log.php<?php echo htmlspecialchars(activityLogQueryString(["page" => $page + 1], $baseFilters), ENT_QUOTES, "UTF-8"); ?>">التالي</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="assets/js/script.js"></script>
</body>
</html>
