<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "players_support.php";

requireAuthenticatedUser();
requireMenuAccess("player-seating");

const PLAYER_SEATING_PAGE_HREF = "player_seating.php";

function ensurePlayerSeatingGroupsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sports_groups (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            group_name VARCHAR(150) NOT NULL,
            group_level VARCHAR(150) NOT NULL,
            training_days_count INT(11) NOT NULL DEFAULT 1,
            training_day_keys VARCHAR(255) NOT NULL DEFAULT '',
            training_time TIME NULL DEFAULT NULL,
            trainings_count INT(11) NOT NULL DEFAULT 1,
            exercises_count INT(11) NOT NULL DEFAULT 1,
            max_players INT(11) NOT NULL DEFAULT 1,
            trainer_name VARCHAR(150) NOT NULL,
            academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            walkers_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            other_weapons_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            civilian_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sports_groups_game (game_id),
            KEY idx_sports_groups_game_level (game_id, group_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "training_days_count" => "ALTER TABLE sports_groups ADD COLUMN training_days_count INT(11) NOT NULL DEFAULT 1 AFTER group_level",
        "training_day_keys" => "ALTER TABLE sports_groups ADD COLUMN training_day_keys VARCHAR(255) NOT NULL DEFAULT '' AFTER training_days_count",
        "training_time" => "ALTER TABLE sports_groups ADD COLUMN training_time TIME NULL DEFAULT NULL AFTER training_day_keys",
        "trainings_count" => "ALTER TABLE sports_groups ADD COLUMN trainings_count INT(11) NOT NULL DEFAULT 1 AFTER training_time",
        "exercises_count" => "ALTER TABLE sports_groups ADD COLUMN exercises_count INT(11) NOT NULL DEFAULT 1 AFTER trainings_count",
        "max_players" => "ALTER TABLE sports_groups ADD COLUMN max_players INT(11) NOT NULL DEFAULT 1 AFTER exercises_count",
        "academy_percentage" => "ALTER TABLE sports_groups ADD COLUMN academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER trainer_name",
    ];

    $existingColumnsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );

    foreach ($requiredColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(["sports_groups", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log("Failed to ensure sports_groups.{$columnName} exists: " . $throwable->getMessage());
            }
        }
    }

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === "") {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'sports_groups'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    if (!in_array("fk_sports_groups_game", $existingConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE sports_groups
             ADD CONSTRAINT fk_sports_groups_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }
}

ensurePlayersTables($pdo);
ensurePlayerSeatingGroupsTable($pdo);

if (!isset($_SESSION["player_seating_csrf_token"])) {
    $_SESSION["player_seating_csrf_token"] = bin2hex(random_bytes(32));
}

$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$today = new DateTimeImmutable("today", new DateTimeZone("Africa/Cairo"));
$success = (string)($_SESSION["player_seating_success"] ?? "");
$error = "";
unset($_SESSION["player_seating_success"]);
$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch() ?: [];
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "player-seating";

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

// ===================== معالجة تحديث مستوى اللاعب =====================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = (string)($_POST["csrf_token"] ?? "");

    if (!hash_equals($_SESSION["player_seating_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = (string)($_POST["action"] ?? "");

        if ($action === "update_player_level") {
            $playerId = (int)($_POST["player_id"] ?? 0);
            $playerLevel = trim((string)($_POST["player_level"] ?? ""));

            if ($playerId <= 0) {
                $error = "اللاعب غير صالح.";
            } elseif ($playerLevel === "") {
                $error = "مستوى اللاعب مطلوب.";
            } elseif (strlen($playerLevel) > PLAYER_LEVEL_MAX_LENGTH) {
                $error = "مستوى اللاعب طويل جدًا.";
            } else {
                $playerStmt = $pdo->prepare(
                    "SELECT id, player_level
                     FROM players
                     WHERE id = ? AND game_id = ?
                     LIMIT 1"
                );
                $playerStmt->execute([$playerId, $currentGameId]);
                $playerRow = $playerStmt->fetch();
                if (!$playerRow) {
                    $error = "اللاعب غير متاح.";
                } else {
                    try {
                        $pdo->beginTransaction();
                        syncPlayerSubscriptionHistoryFromPlayerId($pdo, $playerId, $currentGameId, "pre_save");
                        $updateStmt = $pdo->prepare(
                            "UPDATE players
                             SET player_level = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([$playerLevel, $playerId, $currentGameId]);
                        notifyPlayerLevelChanged(
                            $pdo,
                            $currentGameId,
                            $playerId,
                            (string)($playerRow["player_level"] ?? ""),
                            $playerLevel
                        );
                        syncPlayerSubscriptionHistoryFromPlayerId($pdo, $playerId, $currentGameId, "save");
                        auditTrack($pdo, "update", "players", $playerId, "تجلوس اللاعبين", "تحديث مستوى لاعب رقم " . $playerId . " إلى: " . (string)$playerLevel);
                        $pdo->commit();

                        $_SESSION["player_seating_success"] = "تم تحديث مستوى اللاعب.";
                        header("Location: " . PLAYER_SEATING_PAGE_HREF);
                        exit;
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = "تعذر تحديث مستوى اللاعب.";
                    }
                }
            }
        }
    }
}

// ===================== الفلاتر =====================
$filterLevel = isset($_GET['filter_level']) ? trim((string)$_GET['filter_level']) : '';
$filterDaysCount = isset($_GET['filter_days_count']) && $_GET['filter_days_count'] !== '' ? (int)$_GET['filter_days_count'] : null;
$filterDays = isset($_GET['filter_days']) && is_array($_GET['filter_days']) ? array_map('trim', $_GET['filter_days']) : [];
$filterTime = isset($_GET['filter_time']) ? trim((string)$_GET['filter_time']) : '';

// جلب قيم الفلاتر المتاحة من قاعدة البيانات (للقوائم المنسدلة)
$levelsStmt = $pdo->prepare("SELECT DISTINCT group_level FROM sports_groups WHERE game_id = ? ORDER BY group_level ASC");
$levelsStmt->execute([$currentGameId]);
$availableLevels = array_column($levelsStmt->fetchAll(), 'group_level');

$daysCountsStmt = $pdo->prepare("SELECT DISTINCT training_days_count FROM sports_groups WHERE game_id = ? ORDER BY training_days_count ASC");
$daysCountsStmt->execute([$currentGameId]);
$availableDaysCounts = array_column($daysCountsStmt->fetchAll(), 'training_days_count');

$timesStmt = $pdo->prepare("SELECT DISTINCT training_time FROM sports_groups WHERE game_id = ? AND training_time IS NOT NULL AND training_time <> '' ORDER BY training_time ASC");
$timesStmt->execute([$currentGameId]);
$availableTimes = array_filter(array_column($timesStmt->fetchAll(), 'training_time'), function ($v) { return $v !== null && $v !== ''; });

// بناء استعلام المجموعات مع تطبيق الفلاتر
$groupsSql = "SELECT id, group_name, group_level, max_players, trainer_name, training_days_count, training_day_keys, training_time
              FROM sports_groups
              WHERE game_id = ?";
$params = [$currentGameId];

if ($filterLevel !== '') {
    $groupsSql .= " AND group_level = ?";
    $params[] = $filterLevel;
}
if ($filterDaysCount !== null) {
    $groupsSql .= " AND training_days_count = ?";
    $params[] = $filterDaysCount;
}
if ($filterTime !== '') {
    $groupsSql .= " AND training_time = ?";
    $params[] = $filterTime;
}

$groupsSql .= " ORDER BY group_level ASC, group_name ASC, id DESC";

// تطبيق فلتر الأيام المحددة بعد الجلب (لأنه يحتاج فحص training_day_keys)
$groupsStmt = $pdo->prepare($groupsSql);
$groupsStmt->execute($params);
$groups = $groupsStmt->fetchAll();

if (!empty($filterDays)) {
    $filteredGroups = [];
    foreach ($groups as $group) {
        $groupDaysKeys = !empty($group['training_day_keys']) ? explode(PLAYER_DAY_SEPARATOR, $group['training_day_keys']) : [];
        // نتحقق إذا كانت كل الأيام المحددة موجودة في أيام المجموعة
        $containsAll = true;
        foreach ($filterDays as $day) {
            if (!in_array($day, $groupDaysKeys)) {
                $containsAll = false;
                break;
            }
        }
        if ($containsAll) {
            $filteredGroups[] = $group;
        }
    }
    $groups = $filteredGroups;
}

// جلب عدد اللاعبين في كل مجموعة
$groupPlayerCounts = fetchGroupPlayerCounts($pdo, $currentGameId);
foreach ($groups as &$group) {
    $group["current_players_count"] = $groupPlayerCounts[(int)$group["id"]] ?? 0;
    $group["can_add_players"] = (int)$group["current_players_count"] < (int)$group["max_players"];
}
unset($group);

// جلب اللاعبين الموجودين بالمجموعات
$groupPlayersStmt = $pdo->prepare(
    "SELECT id, group_id, name, player_level, birth_date
     FROM players
     WHERE game_id = ? AND group_id IS NOT NULL
     ORDER BY name ASC, id ASC"
);
$groupPlayersStmt->execute([$currentGameId]);
$groupPlayersMap = [];
foreach ($groupPlayersStmt->fetchAll() as $playerRow) {
    $groupId = (int)($playerRow["group_id"] ?? 0);
    if ($groupId <= 0) continue;
    if (!isset($groupPlayersMap[$groupId])) {
        $groupPlayersMap[$groupId] = [];
    }
    $groupPlayersMap[$groupId][] = [
        "id" => (int)$playerRow["id"],
        "name" => (string)($playerRow["name"] ?? ""),
        "player_level" => (string)($playerRow["player_level"] ?? ""),
        "birth_date" => (string)($playerRow["birth_date"] ?? ""),
        "player_age" => calculatePlayerAgeFromBirthDate($playerRow["birth_date"] ?? "", $today),
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسكين لاعبين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .filter-bar {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 160px;
        }
        .filter-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text-muted);
        }
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-secondary);
            font-family: inherit;
        }
        .days-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }
        .days-checkboxes label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: normal;
            font-size: 14px;
            cursor: pointer;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; align-items: stretch; }
            .filter-actions { flex-direction: row; justify-content: space-between; }
        }
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
                    <h1>تسكين لاعبين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></span>
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

        <!-- شريط الفلاتر -->
        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label for="filter_level">📊 مستوى المجموعة</label>
                    <select name="filter_level" id="filter_level">
                        <option value="">الكل</option>
                        <?php foreach ($availableLevels as $level): ?>
                            <option value="<?php echo htmlspecialchars($level, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterLevel === $level ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($level, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_days_count">📅 عدد أيام التمرين أسبوعياً</label>
                    <select name="filter_days_count" id="filter_days_count">
                        <option value="">الكل</option>
                        <?php foreach ($availableDaysCounts as $daysCount): ?>
                            <option value="<?php echo (int)$daysCount; ?>" <?php echo ($filterDaysCount !== null && $filterDaysCount === (int)$daysCount) ? 'selected' : ''; ?>>
                                <?php echo (int)$daysCount; ?> أيام
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_time">⏰ ميعاد التمرين</label>
                    <select name="filter_time" id="filter_time">
                        <option value="">الكل</option>
                        <?php foreach ($availableTimes as $timeValue): ?>
                            <option value="<?php echo htmlspecialchars($timeValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterTime === $timeValue ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(formatTrainingTimeDisplay($timeValue), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>🗓️ أيام محددة (اختر الأيام التي تريد أن تتضمنها المجموعة)</label>
                    <div class="days-checkboxes">
                        <?php foreach (PLAYER_DAY_OPTIONS as $dayKey => $dayLabel): ?>
                            <label>
                                <input type="checkbox" name="filter_days[]" value="<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo in_array($dayKey, $filterDays) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">تصفية</button>
                    <a href="<?php echo PLAYER_SEATING_PAGE_HREF; ?>" class="btn btn-soft">إلغاء الفلاتر</a>
                </div>
            </form>
        </div>

        <section class="card groups-table-card">
            <div class="card-head table-card-head">
                <div>
                    <h3>تسكين لاعبي <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <span class="table-counter"><?php echo count($groups); ?> مجموعة</span>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>اسم المجموعة</th>
                            <th>المدرب</th>
                            <th>أقصى عدد لاعبين</th>
                            <th>عدد لاعبي المجموعة</th>
                            <th>اللاعبون</th>
                            <th>مسموح إضافة لاعب</th>
                            <th>إضافة لاعب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($groups) === 0): ?>
                            <tr>
                                <td colspan="7" class="empty-cell">لا توجد مجموعات متاحة تطابق الفلاتر المختارة.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($groups as $group): ?>
                                <?php $groupPlayers = $groupPlayersMap[(int)$group["id"]] ?? []; ?>
                                <tr>
                                    <td data-label="اسم المجموعة">
                                        <div class="players-table-stack">
                                            <strong><?php echo htmlspecialchars((string)$group["group_name"], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span><?php echo htmlspecialchars((string)$group["group_level"], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($group['training_days_count'])): ?>
                                                <span class="badge" style="margin-top: 4px;">أيام التمرين: <?php echo (int)$group['training_days_count']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="المدرب"><?php echo htmlspecialchars((string)$group["trainer_name"], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="أقصى عدد لاعبين"><?php echo (int)$group["max_players"]; ?></td>
                                    <td data-label="عدد لاعبي المجموعة"><?php echo (int)$group["current_players_count"]; ?></td>
                                    <td data-label="اللاعبون">
                                        <div class="table-wrap">
                                            <table class="data-table">
                                                <thead>
                                                    <tr>
                                                        <th>اسم اللاعب</th>
                                                        <th>مستوى اللاعب</th>
                                                        <th>تاريخ الميلاد</th>
                                                        <th>سن اللاعب</th>
                                                        <th>الإجراءات</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($groupPlayers) === 0): ?>
                                                        <tr>
                                                            <td colspan="5" class="empty-cell">لا يوجد لاعبون في هذه المجموعة.</td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($groupPlayers as $player): ?>
                                                            <tr>
                                                                <td data-label="اسم اللاعب"><?php echo htmlspecialchars((string)$player["name"], ENT_QUOTES, 'UTF-8'); ?></td>
                                                                <td data-label="مستوى اللاعب">
                                                                    <form method="POST" class="inline-form seating-level-form">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["player_seating_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                                        <input type="hidden" name="action" value="update_player_level">
                                                                        <input type="hidden" name="player_id" value="<?php echo (int)$player["id"]; ?>">
                                                                        <input
                                                                            type="text"
                                                                            name="player_level"
                                                                            value="<?php echo htmlspecialchars((string)$player["player_level"], ENT_QUOTES, "UTF-8"); ?>"
                                                                            maxlength="<?php echo PLAYER_LEVEL_MAX_LENGTH; ?>"
                                                                            class="seating-level-input"
                                                                            required
                                                                        >
                                                                        <button type="submit" class="btn btn-warning">حفظ</button>
                                                                    </form>
                                                                </td>
                                                                <td data-label="تاريخ الميلاد"><?php echo htmlspecialchars((string)($player["birth_date"] !== "" ? $player["birth_date"] : "—"), ENT_QUOTES, 'UTF-8'); ?></td>
                                                                <td data-label="سن اللاعب"><?php echo (int)$player["player_age"]; ?></td>
                                                                <td data-label="الإجراءات">
                                                                    <a href="players.php?edit=<?php echo (int)$player["id"]; ?>&amp;return_to=<?php echo rawurlencode(PLAYER_SEATING_PAGE_HREF); ?>" class="btn btn-soft">فتح اللاعب</a>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                    <td data-label="مسموح إضافة لاعب">
                                        <span class="status-chip <?php echo $group["can_add_players"] ? "status-success" : "status-danger"; ?>">
                                            <?php echo $group["can_add_players"] ? "نعم" : "لا"; ?>
                                        </span>
                                    </td>
                                    <td data-label="إضافة لاعب">
                                        <?php if ($group["can_add_players"]): ?>
                                            <a href="players.php?open_player_form=1&amp;preset_group_id=<?php echo (int)$group["id"]; ?>&amp;return_to=<?php echo rawurlencode(PLAYER_SEATING_PAGE_HREF); ?>" class="btn btn-primary">إضافة لاعب</a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-soft" disabled>المجموعة ممتلئة</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/script.js"></script>
</body>
</html>
