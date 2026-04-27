<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("user-permissions");

if (!isset($_SESSION["user_permissions_csrf_token"])) {
    $_SESSION["user_permissions_csrf_token"] = bin2hex(random_bytes(32));
}

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "user-permissions";
$flashSuccess = $_SESSION["user_permissions_success"] ?? "";
unset($_SESSION["user_permissions_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

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

$selectedGameId = $currentGameId;
$currentGameName = (string)$allGameMap[$currentGameId]["name"];

$supervisorsStmt = $pdo->prepare(
    "SELECT DISTINCT u.id, u.username
     FROM users u
     LEFT JOIN user_games ug ON ug.user_id = u.id
     WHERE u.status = 1
       AND u.role = 'مشرف'
       AND (u.can_access_all_games = 1 OR ug.game_id = ?)
     ORDER BY u.username ASC"
);
$supervisorsStmt->execute([$selectedGameId]);
$supervisors = $supervisorsStmt->fetchAll();
$supervisorIds = array_map("intval", array_column($supervisors, "id"));

$selectedUserId = (int)($_GET["user_id"] ?? $_POST["user_id"] ?? 0);
if (!in_array($selectedUserId, $supervisorIds, true)) {
    $selectedUserId = count($supervisors) > 0 ? (int)$supervisors[0]["id"] : 0;
}

$managedItems = getPermissionManagedNavigationItems();
$selectedPermissionKeys = [];

if ($selectedUserId > 0) {
    $selectedPermissionKeys = getAllowedMenuPermissionKeys($pdo, ["id" => $selectedUserId, "role" => "مشرف"], $selectedGameId);
    $selectedPermissionKeys = array_values(array_filter($selectedPermissionKeys, function ($key) {
        return $key !== "home";
    }));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["user_permissions_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } elseif (!in_array($selectedUserId, $supervisorIds, true)) {
        $error = "اختر مستخدمًا صالحًا.";
    } else {
        $selectedPermissionKeys = sanitizeMenuPermissionKeys($_POST["permissions"] ?? []);

        if (count($selectedPermissionKeys) === 0) {
            $error = "اختر زرًا واحدًا على الأقل.";
        } else {
            ensureUserGamePermissionsTable($pdo);

            try {
                $pdo->beginTransaction();

                $deleteStmt = $pdo->prepare("DELETE FROM user_game_permissions WHERE user_id = ? AND game_id = ?");
                $deleteStmt->execute([$selectedUserId, $selectedGameId]);

                $insertStmt = $pdo->prepare(
                    "INSERT INTO user_game_permissions (user_id, game_id, permission_key)
                     VALUES (?, ?, ?)"
                );
                foreach ($selectedPermissionKeys as $permissionKey) {
                    $insertStmt->execute([$selectedUserId, $selectedGameId, $permissionKey]);
                }

                auditLogActivity($pdo, "update", "user_game_permissions", $selectedUserId, "صلاحيات المستخدمين", "تحديث صلاحيات المستخدم رقم " . $selectedUserId . " للعبة رقم " . $selectedGameId . " (" . count($selectedPermissionKeys) . " صلاحيات)");
                $pdo->commit();
                $_SESSION["user_permissions_success"] = "تم حفظ الصلاحيات.";
                header("Location: user_permissions.php?" . http_build_query([
                    "user_id" => $selectedUserId,
                ]));
                exit;
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "تعذر حفظ الصلاحيات.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>صلاحيات المستخدمين</title>
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
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>🛡️ صلاحيات المستخدمين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <?php if ($currentGameName !== ""): ?>
                    <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></span>
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
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="permissions-layout">
            <div class="card permissions-toolbar">
                <form method="GET" class="permissions-selectors">
                    <div class="form-group">
                        <label>🎮 اللعبة الحالية</label>
                        <div class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>

                    <div class="form-group">
                        <label for="user_id">👤 المستخدم</label>
                        <select name="user_id" id="user_id" onchange="this.form.submit()">
                            <?php if (count($supervisors) === 0): ?>
                                <option value="">لا يوجد مشرفون</option>
                            <?php else: ?>
                                <?php foreach ($supervisors as $supervisor): ?>
                                    <option value="<?php echo (int)$supervisor["id"]; ?>" <?php echo $selectedUserId === (int)$supervisor["id"] ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($supervisor["username"], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>
            </div>

            <div class="card permissions-card">
                <?php if (count($supervisors) === 0): ?>
                    <div class="empty-state">لا يوجد مستخدمون بصلاحية مشرف داخل هذه اللعبة.</div>
                <?php else: ?>
                    <form method="POST" class="permissions-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["user_permissions_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$selectedUserId; ?>">

                        <div class="permissions-grid">
                            <?php foreach ($managedItems as $item): ?>
                                <label class="permission-card">
                                    <input
                                        type="checkbox"
                                        name="permissions[]"
                                        value="<?php echo htmlspecialchars($item["key"], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo in_array($item["key"], $selectedPermissionKeys, true) ? "checked" : ""; ?>
                                    >
                                    <span class="permission-card-body">
                                        <span class="permission-card-title"><?php echo htmlspecialchars($item["label"], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="btn btn-primary permissions-save-btn">💾 حفظ الصلاحيات</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/script.js"></script>
</body>
</html>
