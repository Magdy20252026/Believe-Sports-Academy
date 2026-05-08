<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "branches_support.php";
require_once "audit.php";
require_once "game_levels_support.php";

requireAuthenticatedUser();
requireMenuAccess("games");

try {
    $bootstrapAdminId = (int)($_SESSION["user_id"] ?? 0);
    if ($bootstrapAdminId > 0) {
        $pdo->prepare(
            "INSERT IGNORE INTO user_branches (user_id, branch_id)
             SELECT ?, b.id FROM branches b WHERE b.status = 1"
        )->execute([$bootstrapAdminId]);
        $pdo->prepare(
            "INSERT IGNORE INTO user_games (user_id, game_id)
             SELECT ?, g.id FROM games g WHERE g.status = 1"
        )->execute([$bootstrapAdminId]);
    }
} catch (Throwable $bootstrapErr) {
    error_log("games.php manager auto-link failed: " . $bootstrapErr->getMessage());
}

function ensureGamesAuditColumns(PDO $pdo)
{
    $columns = [
        "created_by_user_id" => "ALTER TABLE games ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL",
        "updated_by_user_id" => "ALTER TABLE games ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL",
        "created_at" => "ALTER TABLE games ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
    ];

    $checkStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'games'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );

    foreach ($columns as $columnName => $alter) {
        try {
            $checkStmt->execute([$columnName]);
            if (!$checkStmt->fetchColumn()) {
                $pdo->exec($alter);
            }
        } catch (Throwable $throwable) {
            error_log("ensureGamesAuditColumns {$columnName} failed: " . $throwable->getMessage());
        }
    }
}

function fetchGameById(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, name, branch_id, status FROM games WHERE id = ? LIMIT 1"
    );
    $stmt->execute([(int)$gameId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function gameNameTakenInBranch(PDO $pdo, $branchId, $name, $excludeId = 0)
{
    if ((int)$excludeId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM games WHERE branch_id = ? AND name = ? AND id <> ? LIMIT 1");
        $stmt->execute([(int)$branchId, (string)$name, (int)$excludeId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM games WHERE branch_id = ? AND name = ? LIMIT 1");
        $stmt->execute([(int)$branchId, (string)$name]);
    }
    return (bool)$stmt->fetchColumn();
}

function gameDependentTables(PDO $pdo, $gameId)
{
    $skip = ["user_games", "user_game_permissions"];
    $blocking = [];
    try {
        $tStmt = $pdo->prepare(
            "SELECT TABLE_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND COLUMN_NAME = 'game_id'"
        );
        $tStmt->execute();
        $tables = $tStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }

    foreach ((array)$tables as $tableName) {
        $tableName = (string)$tableName;
        if ($tableName === "" || in_array($tableName, $skip, true)) {
            continue;
        }
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM `" . str_replace("`", "", $tableName) . "` WHERE game_id = ?");
            $check->execute([(int)$gameId]);
            $count = (int)$check->fetchColumn();
            if ($count > 0) {
                $blocking[$tableName] = $count;
            }
        } catch (Throwable $e) {
            // ignore tables we can't query
        }
    }
    return $blocking;
}

if (!isset($_SESSION["games_csrf_token"])) {
    $_SESSION["games_csrf_token"] = bin2hex(random_bytes(32));
}

ensureBranchSchema($pdo);
ensureGamesAuditColumns($pdo);
ensureGameLevelsTable($pdo);
ensureGameGroupLevelsTable($pdo);

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$currentBranchId = (int)($_SESSION["selected_branch_id"] ?? 0);
$currentBranchName = (string)($_SESSION["selected_branch_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "games";

$flashSuccess = $_SESSION["games_success"] ?? "";
unset($_SESSION["games_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

$allBranchesStmt = $pdo->query("SELECT id, name, status FROM branches ORDER BY id ASC");
$allBranches = $allBranchesStmt->fetchAll();
$allBranchesMap = [];
foreach ($allBranches as $b) {
    $allBranchesMap[(int)$b["id"]] = $b;
}

$activeBranchOptions = array_values(array_filter($allBranches, function ($b) {
    return (int)$b["status"] === 1;
}));

$formData = [
    "id" => 0,
    "name" => "",
    "branch_id" => $currentBranchId > 0 && isset($allBranchesMap[$currentBranchId]) ? $currentBranchId : 0,
    "status" => 1,
    "levels_text" => "",
    "group_levels_text" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["games_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = (string)($_POST["action"] ?? "");

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["game_id"] ?? 0),
                "name" => trim((string)($_POST["name"] ?? "")),
                "branch_id" => (int)($_POST["branch_id"] ?? 0),
                "status" => ((int)($_POST["status"] ?? 1) === 0) ? 0 : 1,
                "levels_text" => trim((string)($_POST["levels_text"] ?? "")),
                "group_levels_text" => trim((string)($_POST["group_levels_text"] ?? "")),
            ];
            $gameLevelRecords = normalizeGameLevelRecordsInput($formData["levels_text"]);
            $gameGroupLevels = normalizeGameLevelsInput($formData["group_levels_text"]);

            if ($formData["name"] === "") {
                $error = "اسم اللعبة مطلوب.";
            } elseif (mb_strlen($formData["name"]) > 150) {
                $error = "اسم اللعبة يجب ألا يتجاوز 150 حرفًا.";
            } elseif ($formData["branch_id"] <= 0 || !isset($allBranchesMap[$formData["branch_id"]])) {
                $error = "اختر فرعًا صالحًا للعبة.";
            } elseif ($formData["id"] > 0 && !fetchGameById($pdo, $formData["id"])) {
                $error = "اللعبة غير متاحة.";
            } elseif (gameNameTakenInBranch($pdo, $formData["branch_id"], $formData["name"], $formData["id"])) {
                $error = "هذه اللعبة موجودة بالفعل في هذا الفرع.";
            } elseif ($formData["levels_text"] !== "" && count($gameLevelRecords) === 0) {
                $error = "مستويات اللعبة غير صالحة. استخدم الصيغة: اسم المستوى أو اسم المستوى | التفاصيل";
            } elseif ($formData["group_levels_text"] !== "" && count($gameGroupLevels) === 0) {
                $error = "مستويات المجموعات غير صالحة.";
            } else {
                try {
                    $pdo->beginTransaction();
                    if ($formData["id"] > 0) {
                        $stmt = $pdo->prepare(
                            "UPDATE games SET name = ?, branch_id = ?, status = ? WHERE id = ?"
                        );
                        $stmt->execute([
                            $formData["name"],
                            $formData["branch_id"],
                            $formData["status"],
                            $formData["id"],
                        ]);
                        saveGameLevels($pdo, $formData["id"], $gameLevelRecords);
                        saveGameGroupLevels($pdo, $formData["id"], $gameGroupLevels);
                        auditTrack(
                            $pdo,
                            "update",
                            "games",
                            $formData["id"],
                            "الألعاب",
                            "تعديل لعبة: " . $formData["name"]
                        );
                        if ($currentGameId === $formData["id"]) {
                            $_SESSION["selected_game_name"] = $formData["name"];
                        }
                        $_SESSION["games_success"] = "تم تحديث بيانات اللعبة ✅";
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO games (name, branch_id, status) VALUES (?, ?, ?)"
                        );
                        $stmt->execute([
                            $formData["name"],
                            $formData["branch_id"],
                            $formData["status"],
                        ]);
                        $newGameId = (int)$pdo->lastInsertId();
                        saveGameLevels($pdo, $newGameId, $gameLevelRecords);
                        saveGameGroupLevels($pdo, $newGameId, $gameGroupLevels);
                        $creatorId = (int)($_SESSION["user_id"] ?? 0);
                        if ($creatorId > 0 && $newGameId > 0) {
                            try {
                                $grantBranchStmt = $pdo->prepare(
                                    "INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (?, ?)"
                                );
                                $grantBranchStmt->execute([$creatorId, (int)$formData["branch_id"]]);
                                $grantGameStmt = $pdo->prepare(
                                    "INSERT IGNORE INTO user_games (user_id, game_id) VALUES (?, ?)"
                                );
                                $grantGameStmt->execute([$creatorId, $newGameId]);
                            } catch (Throwable $grantErr) {
                                error_log("auto-grant new game to creator failed: " . $grantErr->getMessage());
                            }
                        }
                        auditTrack(
                            $pdo,
                            "create",
                            "games",
                            $newGameId,
                            "الألعاب",
                            "إضافة لعبة: " . $formData["name"]
                        );
                        $_SESSION["games_success"] = "تم إضافة اللعبة بنجاح ✅";
                    }
                    $pdo->commit();
                    header("Location: games.php");
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("games save failed: " . $throwable->getMessage());
                    $error = "تعذر حفظ بيانات اللعبة.";
                }
            }
        } elseif ($action === "toggle_status") {
            $gameId = (int)($_POST["game_id"] ?? 0);
            $game = $gameId > 0 ? fetchGameById($pdo, $gameId) : null;
            if (!$game) {
                $error = "اللعبة غير متاحة.";
            } else {
                try {
                    $newStatus = ((int)$game["status"] === 1) ? 0 : 1;
                    $stmt = $pdo->prepare("UPDATE games SET status = ? WHERE id = ?");
                    $stmt->execute([$newStatus, $gameId]);
                    auditTrack(
                        $pdo,
                        "update",
                        "games",
                        $gameId,
                        "الألعاب",
                        ($newStatus === 1 ? "تفعيل" : "تعطيل") . " لعبة: " . $game["name"]
                    );
                    $_SESSION["games_success"] = $newStatus === 1
                        ? "تم تفعيل اللعبة ✅"
                        : "تم تعطيل اللعبة 🚫";
                    header("Location: games.php");
                    exit;
                } catch (Throwable $throwable) {
                    error_log("games toggle_status failed: " . $throwable->getMessage());
                    $error = "تعذر تحديث حالة اللعبة.";
                }
            }
        } elseif ($action === "delete") {
            $gameId = (int)($_POST["game_id"] ?? 0);
            $game = $gameId > 0 ? fetchGameById($pdo, $gameId) : null;
            if (!$game) {
                $error = "اللعبة غير متاحة.";
            } elseif ($gameId === $currentGameId) {
                $error = "لا يمكن حذف اللعبة المُحدّدة حاليًا في جلستك. اختر لعبة أخرى من تسجيل الدخول أولًا.";
            } else {
                $blocking = gameDependentTables($pdo, $gameId);
                if (count($blocking) > 0) {
                    $sample = array_slice(array_keys($blocking), 0, 4);
                    $error = "لا يمكن حذف اللعبة لوجود بيانات مرتبطة بها في: "
                        . htmlspecialchars(implode("، ", $sample), ENT_QUOTES, "UTF-8")
                        . (count($blocking) > 4 ? " و " . (count($blocking) - 4) . " جدول آخر." : ".")
                        . " يمكنك تعطيلها بدلًا من حذفها.";
                } else {
                    try {
                        $pdo->beginTransaction();
                        try {
                            $delPerms = $pdo->prepare("DELETE FROM user_game_permissions WHERE game_id = ?");
                            $delPerms->execute([$gameId]);
                        } catch (Throwable $e) { /* table may not exist */ }
                        try {
                            $delUserGames = $pdo->prepare("DELETE FROM user_games WHERE game_id = ?");
                            $delUserGames->execute([$gameId]);
                        } catch (Throwable $e) { /* table may not exist */ }
                        $delGame = $pdo->prepare("DELETE FROM games WHERE id = ?");
                        $delGame->execute([$gameId]);
                        $pdo->commit();
                        auditLogActivity(
                            $pdo,
                            "delete",
                            "games",
                            $gameId,
                            "الألعاب",
                            "حذف لعبة: " . $game["name"]
                        );
                        $_SESSION["games_success"] = "تم حذف اللعبة 🗑️";
                        header("Location: games.php");
                        exit;
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("games delete failed: " . $throwable->getMessage());
                        $error = "تعذر حذف اللعبة.";
                    }
                }
            }
        }
    }
}

$editId = (int)($_GET["edit"] ?? 0);
if ($editId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editGame = fetchGameById($pdo, $editId);
    if ($editGame) {
        $formData = [
            "id" => (int)$editGame["id"],
            "name" => (string)$editGame["name"],
            "branch_id" => (int)($editGame["branch_id"] ?? 0),
            "status" => (int)$editGame["status"],
            "levels_text" => formatGameLevelRecordsForTextarea(fetchGameLevelRecords($pdo, (int)$editGame["id"])),
            "group_levels_text" => implode(PHP_EOL, fetchGameGroupLevels($pdo, (int)$editGame["id"])),
        ];
    }
}

$gamesStmt = $pdo->query(
    "SELECT g.id, g.name, g.branch_id, g.status, b.name AS branch_name
     FROM games g
     LEFT JOIN branches b ON b.id = g.branch_id
     ORDER BY (b.name IS NULL) ASC, b.name ASC, g.name ASC"
);
$games = $gamesStmt->fetchAll();
$gameLevelsByGame = fetchGameLevelRecordsGrouped($pdo);
$gameGroupLevelsByGame = fetchGameGroupLevelsGrouped($pdo);

$totalGames = count($games);
$activeGames = 0;
foreach ($games as $g) {
    if ((int)$g["status"] === 1) {
        $activeGames++;
    }
}
$inactiveGames = $totalGames - $activeGames;
$totalBranches = count($allBranches);

$submitButtonLabel = $formData["id"] > 0 ? "تحديث اللعبة" : "إضافة لعبة جديدة";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الألعاب</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .games-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .games-stat-card {
            background: var(--card-bg, #fff);
            border-radius: 14px;
            padding: 16px 18px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .games-stat-card span { color: var(--text-soft, #6b7280); font-size: 13px; }
        .games-stat-card strong { font-size: 26px; }
        .games-grid {
            display: grid;
            grid-template-columns: minmax(280px, 360px) 1fr;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .games-grid { grid-template-columns: 1fr; }
        }
        .games-form-card .login-form { display: flex; flex-direction: column; gap: 14px; }
        .games-status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .games-status-pill.is-active { background: rgba(16, 185, 129, 0.15); color: #047857; }
        .games-status-pill.is-inactive { background: rgba(148, 163, 184, 0.2); color: #475569; }
        .games-current-pill {
            display: inline-block;
            margin-inline-start: 6px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(47, 91, 234, 0.12);
            color: #2f5bea;
            font-size: 11px;
            font-weight: 700;
        }
        .games-branch-pill {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            background: rgba(47, 91, 234, 0.08);
            color: #2f5bea;
            font-size: 12px;
            font-weight: 700;
        }
        .games-levels-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .games-level-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.05);
            color: var(--text, #0f172a);
            font-size: 12px;
            font-weight: 700;
        }
        body.dark-mode .games-stat-card {
            background: #162133;
            border-color: #334155;
        }
        body.dark-mode .games-status-pill.is-inactive {
            background: rgba(148, 163, 184, 0.25);
            color: #e2e8f0;
        }
        body.dark-mode .games-branch-pill {
            background: rgba(96, 165, 250, 0.18);
            color: #bfdbfe;
        }
        body.dark-mode .games-level-chip {
            background: rgba(148, 163, 184, 0.18);
            color: #e2e8f0;
        }
    </style>
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content games-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>الألعاب</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <?php if ($currentBranchName !== ""): ?>
                    <span class="context-badge">🏢 <?php echo htmlspecialchars($currentBranchName, ENT_QUOTES, "UTF-8"); ?></span>
                <?php endif; ?>
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

        <?php if ($success !== ""): ?>
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <section class="games-stat-grid">
            <div class="games-stat-card">
                <span>إجمالي الألعاب</span>
                <strong><?php echo (int)$totalGames; ?></strong>
            </div>
            <div class="games-stat-card">
                <span>الألعاب المُفعّلة</span>
                <strong><?php echo (int)$activeGames; ?></strong>
            </div>
            <div class="games-stat-card">
                <span>الألعاب المُعطّلة</span>
                <strong><?php echo (int)$inactiveGames; ?></strong>
            </div>
            <div class="games-stat-card">
                <span>عدد الفروع</span>
                <strong><?php echo (int)$totalBranches; ?></strong>
            </div>
        </section>

        <?php if (count($activeBranchOptions) === 0): ?>
            <div class="alert-error">
                لا يوجد فروع مُفعّلة. أضف فرعًا أولًا من <a href="branches.php">صفحة الفروع</a> ثم عُد لإضافة الألعاب.
            </div>
        <?php endif; ?>

        <section class="games-grid">
            <div class="card games-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل بيانات اللعبة" : "إضافة لعبة جديدة"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="games.php" class="btn btn-soft" aria-label="إلغاء التعديل">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["games_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="game_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="form-group">
                        <label for="name">اسم اللعبة</label>
                        <input type="text" name="name" id="name" maxlength="150" value="<?php echo htmlspecialchars($formData["name"], ENT_QUOTES, "UTF-8"); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="branch_id">الفرع التابع له</label>
                        <select name="branch_id" id="branch_id" required <?php echo count($activeBranchOptions) === 0 ? "disabled" : ""; ?>>
                            <option value="">— اختر الفرع —</option>
                            <?php foreach ($activeBranchOptions as $branchOpt): ?>
                                <option value="<?php echo (int)$branchOpt["id"]; ?>" <?php echo (int)$formData["branch_id"] === (int)$branchOpt["id"] ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($branchOpt["name"], ENT_QUOTES, "UTF-8"); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($formData["id"] > 0 && (int)$formData["branch_id"] > 0 && isset($allBranchesMap[(int)$formData["branch_id"]]) && (int)$allBranchesMap[(int)$formData["branch_id"]]["status"] === 0): ?>
                                <option value="<?php echo (int)$formData["branch_id"]; ?>" selected>
                                    <?php echo htmlspecialchars($allBranchesMap[(int)$formData["branch_id"]]["name"] . " (مُعطّل)", ENT_QUOTES, "UTF-8"); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">حالة اللعبة</label>
                        <select name="status" id="status">
                            <option value="1" <?php echo $formData["status"] === 1 ? "selected" : ""; ?>>مُفعّلة</option>
                            <option value="0" <?php echo $formData["status"] === 0 ? "selected" : ""; ?>>مُعطّلة</option>
                        </select>
                        <small style="color:var(--text-soft,#6b7280);">الألعاب المُعطّلة لا تظهر في صفحة تسجيل الدخول.</small>
                    </div>

                    <div class="form-group">
                        <label for="levels_text">مستويات اللعبة</label>
                        <textarea name="levels_text" id="levels_text" rows="6" placeholder="مثال: مبتدئ | أساسيات اللعبة&#10;متوسط | تطوير المهارات&#10;متقدم | بطولات ومنافسات"><?php echo htmlspecialchars($formData["levels_text"], ENT_QUOTES, "UTF-8"); ?></textarea>
                        <small style="color:var(--text-soft,#6b7280);">اكتب كل مستوى في سطر مستقل، ويمكنك إضافة تفاصيله بعد علامة | ليظهر المستوى وتفاصيله داخل بوابة اللاعب.</small>
                    </div>

                    <div class="form-group">
                        <label for="group_levels_text">مستويات المجموعات</label>
                        <textarea name="group_levels_text" id="group_levels_text" rows="6" placeholder="اكتب كل مستوى مجموعة في سطر مستقل"><?php echo htmlspecialchars($formData["group_levels_text"], ENT_QUOTES, "UTF-8"); ?></textarea>
                        <small style="color:var(--text-soft,#6b7280);">سيتم استخدام هذه المستويات فقط داخل صفحة المجموعات في قائمة مستوى المجموعة.</small>
                    </div>

                    <button type="submit" class="btn btn-primary" <?php echo count($activeBranchOptions) === 0 ? "disabled" : ""; ?>>
                        <?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, "UTF-8"); ?>
                    </button>

                    <?php if ($formData["id"] === 0): ?>
                        <small style="color:var(--text-soft,#6b7280);">
                            بعد الإضافة، امنح المستخدمين صلاحية الوصول للعبة من صفحة <a href="users.php">المستخدمين</a>،
                            ثم حدّد القوائم المسموحة لكل مشرف من <a href="user_permissions.php">صلاحيات المستخدمين</a>.
                        </small>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card games-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>الألعاب المُسجّلة</h3>
                    </div>
                    <span class="table-counter">الإجمالي: <?php echo (int)$totalGames; ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>اللعبة</th>
                                <th>الفرع</th>
                                <th>مستويات اللعبة</th>
                                <th>مستويات المجموعات</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($totalGames === 0): ?>
                                <tr><td colspan="6" class="empty-cell">لا توجد ألعاب مُسجّلة بعد.</td></tr>
                            <?php else: ?>
                                <?php foreach ($games as $game): $gameId = (int)$game["id"]; ?>
                                    <?php $gameLevels = $gameLevelsByGame[$gameId] ?? []; ?>
                                    <?php $gameGroupLevels = $gameGroupLevelsByGame[$gameId] ?? []; ?>
                                    <tr>
                                        <td data-label="اللعبة">
                                            <strong><?php echo htmlspecialchars($game["name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            <?php if ($gameId === $currentGameId): ?>
                                                <span class="games-current-pill">الحالية</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="الفرع">
                                            <span class="games-branch-pill">
                                                🏢 <?php echo htmlspecialchars((string)($game["branch_name"] ?? "(بدون فرع)"), ENT_QUOTES, "UTF-8"); ?>
                                            </span>
                                        </td>
                                        <td data-label="المستويات">
                                            <?php if (count($gameLevels) === 0): ?>
                                                <span style="color:var(--text-soft,#6b7280);">—</span>
                                            <?php else: ?>
                                                <div class="games-levels-list">
                                                    <?php foreach ($gameLevels as $level): ?>
                                                        <div class="games-level-chip" style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;">
                                                            <span><?php echo htmlspecialchars((string)($level["level_name"] ?? ""), ENT_QUOTES, "UTF-8"); ?></span>
                                                            <?php if (!empty($level["level_details"])): ?>
                                                                <small style="color:var(--text-soft,#6b7280);font-weight:600;"><?php echo htmlspecialchars((string)$level["level_details"], ENT_QUOTES, "UTF-8"); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="مستويات المجموعات">
                                            <?php if (count($gameGroupLevels) === 0): ?>
                                                <span style="color:var(--text-soft,#6b7280);">—</span>
                                            <?php else: ?>
                                                <div class="games-levels-list">
                                                    <?php foreach ($gameGroupLevels as $levelName): ?>
                                                        <span class="games-level-chip"><?php echo htmlspecialchars($levelName, ENT_QUOTES, "UTF-8"); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="الحالة">
                                            <?php if ((int)$game["status"] === 1): ?>
                                                <span class="games-status-pill is-active">مُفعّلة</span>
                                            <?php else: ?>
                                                <span class="games-status-pill is-inactive">مُعطّلة</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="games.php?edit=<?php echo $gameId; ?>" class="btn btn-warning" aria-label="تعديل اللعبة">✏️</a>

                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["games_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="game_id" value="<?php echo $gameId; ?>">
                                                    <button type="submit" class="btn btn-soft" aria-label="تبديل حالة اللعبة">
                                                        <?php echo (int)$game["status"] === 1 ? "🚫 تعطيل" : "✅ تفعيل"; ?>
                                                    </button>
                                                </form>

                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["games_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="game_id" value="<?php echo $gameId; ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-danger"
                                                        aria-label="حذف اللعبة"
                                                        onclick="return confirm('هل أنت متأكد من حذف اللعبة؟ سيتم حذف صلاحيات الوصول إليها أيضًا. هذا الإجراء لا يمكن التراجع عنه.');"
                                                        <?php echo $gameId === $currentGameId ? "disabled title='لا يمكن حذف اللعبة المُحدّدة حاليًا'" : ""; ?>
                                                    >🗑️</button>
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
