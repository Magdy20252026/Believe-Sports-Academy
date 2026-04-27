<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

const GAME_SEPARATOR = " ✨ ";
const BRANCH_SEPARATOR = " ✨ ";

requireAuthenticatedUser();
requireMenuAccess("users");

if (!isset($_SESSION["users_csrf_token"])) {
    $_SESSION["users_csrf_token"] = bin2hex(random_bytes(32));
}

$success = "";
$error = "";
$allowedRoles = ["مدير", "مشرف"];
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = $_SESSION["selected_game_name"] ?? "";
$currentBranchId = (int)($_SESSION["selected_branch_id"] ?? 0);
$currentBranchName = (string)($_SESSION["selected_branch_name"] ?? "");
$editorUserId = (int)($_SESSION["user_id"] ?? 0);
$editorUser = null;
if ($editorUserId > 0) {
    $editorStmt = $pdo->prepare("SELECT id, role, can_access_all_games, can_access_all_branches FROM users WHERE id = ? LIMIT 1");
    $editorStmt->execute([$editorUserId]);
    $editorUser = $editorStmt->fetch();
}
$canManageAllGames = (int)($editorUser["can_access_all_games"] ?? ($_SESSION["can_access_all_games"] ?? 0)) === 1;
$canManageAllBranches = (int)($editorUser["can_access_all_branches"] ?? ($_SESSION["can_access_all_branches"] ?? 0)) === 1;

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "users";

$gamesStmt = $pdo->query(
    "SELECT g.id, g.name, g.branch_id, b.name AS branch_name
     FROM games g
     LEFT JOIN branches b ON b.id = g.branch_id
     WHERE g.status = 1
     ORDER BY g.branch_id ASC, g.id ASC"
);
$allGames = $gamesStmt->fetchAll();
$allGameMap = [];
foreach ($allGames as $gameRow) {
    $allGameMap[(int)$gameRow["id"]] = $gameRow;
}

$allBranchesMap = [];
foreach (getAllActiveBranches($pdo) as $branchRow) {
    $allBranchesMap[(int)$branchRow["id"]] = $branchRow;
}

$availableBranchesMap = [];
if ($canManageAllBranches) {
    $availableBranchesMap = $allBranchesMap;
} elseif ($editorUserId > 0) {
    $editorBranchStmt = $pdo->prepare(
        "SELECT branch_id FROM user_branches WHERE user_id = ?"
    );
    $editorBranchStmt->execute([$editorUserId]);
    foreach ($editorBranchStmt->fetchAll() as $row) {
        $branchId = (int)($row["branch_id"] ?? 0);
        if ($branchId > 0 && isset($allBranchesMap[$branchId])) {
            $availableBranchesMap[$branchId] = $allBranchesMap[$branchId];
        }
    }
    if ($currentBranchId > 0 && isset($allBranchesMap[$currentBranchId])) {
        $availableBranchesMap[$currentBranchId] = $allBranchesMap[$currentBranchId];
    }
}

$availableGameMap = [];
if ($canManageAllGames) {
    foreach ($allGameMap as $gameId => $gameRow) {
        $branchId = (int)($gameRow["branch_id"] ?? 0);
        if (isset($availableBranchesMap[$branchId])) {
            $availableGameMap[$gameId] = $gameRow;
        }
    }
} elseif ($editorUserId > 0) {
    $editorGameStmt = $pdo->prepare(
        "SELECT game_id FROM user_games WHERE user_id = ?"
    );
    $editorGameStmt->execute([$editorUserId]);
    foreach ($editorGameStmt->fetchAll() as $row) {
        $gameId = (int)($row["game_id"] ?? 0);
        if ($gameId > 0 && isset($allGameMap[$gameId])) {
            $availableGameMap[$gameId] = $allGameMap[$gameId];
        }
    }
    if ($currentGameId > 0 && isset($allGameMap[$currentGameId])) {
        $availableGameMap[$currentGameId] = $allGameMap[$currentGameId];
    }
}

if ($currentGameId <= 0 || !isset($allGameMap[$currentGameId])) {
    header("Location: dashboard.php");
    exit;
}

$availableGamesGrouped = [];
foreach ($availableGameMap as $gameId => $gameRow) {
    $branchId = (int)($gameRow["branch_id"] ?? 0);
    if (!isset($availableGamesGrouped[$branchId])) {
        $availableGamesGrouped[$branchId] = [
            "branch_id" => $branchId,
            "branch_name" => (string)($gameRow["branch_name"] ?? ($allBranchesMap[$branchId]["name"] ?? "بدون فرع")),
            "games" => [],
        ];
    }
    $availableGamesGrouped[$branchId]["games"][] = $gameRow;
}
ksort($availableGamesGrouped);

$formData = [
    "id" => 0,
    "username" => "",
    "password" => "",
    "role" => "مشرف",
    "games" => [$currentGameId],
    "branches" => $currentBranchId > 0 ? [$currentBranchId] : [],
];

$flashSuccess = $_SESSION["users_success"] ?? "";
unset($_SESSION["users_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}
$allGameNamesLabel = implode(GAME_SEPARATOR, array_map(function ($game) {
    $branchLabel = (string)($game["branch_name"] ?? "");
    return $branchLabel !== ""
        ? ($game["name"] . " — " . $branchLabel)
        : $game["name"];
}, $allGames));

$allBranchNamesLabel = implode(BRANCH_SEPARATOR, array_map(function ($branchRow) {
    return $branchRow["name"];
}, array_values($allBranchesMap)));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["users_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["user_id"] ?? 0),
                "username" => trim($_POST["username"] ?? ""),
                "password" => trim($_POST["password"] ?? ""),
                "role" => trim($_POST["role"] ?? "مشرف"),
                "games" => array_values(array_unique(array_map("intval", $_POST["games"] ?? []))),
                "branches" => array_values(array_unique(array_map("intval", $_POST["branches"] ?? []))),
            ];

            $selectedGameIds = [];
            foreach ($formData["games"] as $gameId) {
                if (isset($availableGameMap[$gameId])) {
                    $selectedGameIds[] = $gameId;
                }
            }
            $selectedGameIds = array_values(array_unique($selectedGameIds));
            $formData["games"] = $selectedGameIds;

            $selectedBranchIds = [];
            foreach ($formData["branches"] as $branchId) {
                if (isset($availableBranchesMap[$branchId])) {
                    $selectedBranchIds[] = $branchId;
                }
            }
            foreach ($selectedGameIds as $gameId) {
                $branchOfGame = (int)($allGameMap[$gameId]["branch_id"] ?? 0);
                if ($branchOfGame > 0
                    && isset($availableBranchesMap[$branchOfGame])
                    && !in_array($branchOfGame, $selectedBranchIds, true)
                ) {
                    $selectedBranchIds[] = $branchOfGame;
                }
            }
            $selectedBranchIds = array_values(array_unique($selectedBranchIds));
            $formData["branches"] = $selectedBranchIds;

            if ($formData["username"] === "") {
                $error = "اسم المستخدم مطلوب.";
            } elseif (!in_array($formData["role"], $allowedRoles, true)) {
                $error = "الصلاحية غير صحيحة.";
            } elseif ($formData["id"] === 0 && $formData["password"] === "") {
                $error = "كلمة السر مطلوبة.";
            } elseif (count($selectedBranchIds) === 0) {
                $error = "اختر فرعاً واحداً على الأقل.";
            } elseif (count($selectedGameIds) === 0) {
                $error = "اختر لعبة واحدة على الأقل.";
            } else {
                if ($formData["id"] > 0) {
                    $visibleUserStmt = $pdo->prepare("
                        SELECT u.id
                        FROM users u
                        WHERE u.status = 1
                          AND (u.can_access_all_games = 1 OR EXISTS (
                              SELECT 1
                              FROM user_games ug2
                              WHERE ug2.user_id = u.id AND ug2.game_id = ?
                          ))
                          AND u.id = ?
                        LIMIT 1
                    ");
                    $visibleUserStmt->execute([$currentGameId, $formData["id"]]);
                    if (!$visibleUserStmt->fetch()) {
                        $error = "المستخدم غير متاح.";
                    }
                }

                if ($error === "") {
                    $duplicateSql = $formData["id"] > 0
                        ? "SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1"
                        : "SELECT id FROM users WHERE username = ? LIMIT 1";
                    $duplicateStmt = $pdo->prepare($duplicateSql);
                    $duplicateParams = $formData["id"] > 0
                        ? [$formData["username"], $formData["id"]]
                        : [$formData["username"]];
                    $duplicateStmt->execute($duplicateParams);

                    if ($duplicateStmt->fetch()) {
                        $error = "اسم المستخدم مستخدم بالفعل.";
                    }
                }

                if ($error === "") {
                    $canAccessAllGames = 0;
                    $canAccessAllBranches = 0;
                    $passwordHash = "";

                    if ($formData["password"] !== "") {
                        $passwordHash = password_hash($formData["password"], PASSWORD_DEFAULT);
                        if ($passwordHash === false) {
                            $error = "تعذر حفظ كلمة السر.";
                        }
                    }
                }

                if ($error === "") {
                    try {
                        $pdo->beginTransaction();

                        if ($formData["id"] > 0) {
                            if ($formData["password"] !== "") {
                                $updateStmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, role = ?, can_access_all_games = ?, can_access_all_branches = ? WHERE id = ?");
                                $updateStmt->execute([
                                    $formData["username"],
                                    $passwordHash,
                                    $formData["role"],
                                    $canAccessAllGames,
                                    $canAccessAllBranches,
                                    $formData["id"],
                                ]);
                            } else {
                                $updateStmt = $pdo->prepare("UPDATE users SET username = ?, role = ?, can_access_all_games = ?, can_access_all_branches = ? WHERE id = ?");
                                $updateStmt->execute([
                                    $formData["username"],
                                    $formData["role"],
                                    $canAccessAllGames,
                                    $canAccessAllBranches,
                                    $formData["id"],
                                ]);
                            }
                            $userId = $formData["id"];
                        } else {
                            $insertStmt = $pdo->prepare("INSERT INTO users (username, password, role, can_access_all_games, can_access_all_branches, status) VALUES (?, ?, ?, ?, ?, 1)");
                            $insertStmt->execute([
                                $formData["username"],
                                $passwordHash,
                                $formData["role"],
                                $canAccessAllGames,
                                $canAccessAllBranches,
                            ]);
                            $userId = (int)$pdo->lastInsertId();
                        }

                        $deleteGamesStmt = $pdo->prepare("DELETE FROM user_games WHERE user_id = ?");
                        $deleteGamesStmt->execute([$userId]);

                        $insertGameStmt = $pdo->prepare("INSERT INTO user_games (user_id, game_id) VALUES (?, ?)");
                        foreach ($selectedGameIds as $gameId) {
                            $insertGameStmt->execute([$userId, $gameId]);
                        }

                        $deleteBranchesStmt = $pdo->prepare("DELETE FROM user_branches WHERE user_id = ?");
                        $deleteBranchesStmt->execute([$userId]);

                        $insertBranchStmt = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)");
                        foreach ($selectedBranchIds as $branchId) {
                            $insertBranchStmt->execute([$userId, $branchId]);
                        }

                        auditTrack($pdo, $formData["id"] > 0 ? "update" : "create", "users", $userId, "المستخدمين", ($formData["id"] > 0 ? "تعديل مستخدم: " : "إضافة مستخدم: ") . (string)$formData["username"] . " (" . (string)$formData["role"] . ")");
                        $pdo->commit();

                        if ((int)($_SESSION["user_id"] ?? 0) === $userId) {
                            if ($canAccessAllGames !== 1 && !in_array($currentGameId, $selectedGameIds, true)) {
                                header("Location: logout.php");
                                exit;
                            }
                            if ($canAccessAllBranches !== 1 && $currentBranchId > 0 && !in_array($currentBranchId, $selectedBranchIds, true)) {
                                header("Location: logout.php");
                                exit;
                            }

                            session_regenerate_id(true);
                            $_SESSION["username"] = $formData["username"];
                            $_SESSION["role"] = $formData["role"];
                            $_SESSION["can_access_all_games"] = $canAccessAllGames;
                            $_SESSION["can_access_all_branches"] = $canAccessAllBranches;
                            $_SESSION["games"] = [];
                            foreach ($selectedGameIds as $selectedGameId) {
                                if (isset($allGameMap[$selectedGameId])) {
                                    $_SESSION["games"][] = $allGameMap[$selectedGameId];
                                }
                            }
                            $_SESSION["branches"] = [];
                            foreach ($selectedBranchIds as $selectedBranchId) {
                                if (isset($allBranchesMap[$selectedBranchId])) {
                                    $_SESSION["branches"][] = $allBranchesMap[$selectedBranchId];
                                }
                            }
                            $sessionUser = [
                                "id" => $userId,
                                "role" => $formData["role"],
                            ];
                            $_SESSION["menu_permissions"] = getAllowedMenuPermissionKeys($pdo, $sessionUser, $currentGameId);
                        }

                        $_SESSION["users_success"] = $formData["id"] > 0 ? "تم التعديل ✅" : "تم الحفظ ✅";
                        header("Location: users.php");
                        exit;
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = "تعذر حفظ البيانات.";
                    }
                }
            }
        }

        if ($action === "delete") {
            $deleteUserId = (int)($_POST["user_id"] ?? 0);

            if ($deleteUserId <= 0) {
                $error = "المستخدم غير صالح.";
            } else {
                $visibleUserStmt = $pdo->prepare("
                    SELECT u.id
                    FROM users u
                    WHERE u.status = 1
                      AND (u.can_access_all_games = 1 OR EXISTS (
                          SELECT 1
                          FROM user_games ug2
                          WHERE ug2.user_id = u.id AND ug2.game_id = ?
                      ))
                      AND u.id = ?
                    LIMIT 1
                ");
                $visibleUserStmt->execute([$currentGameId, $deleteUserId]);
                $userRow = $visibleUserStmt->fetch();

                if (!$userRow) {
                    $error = "المستخدم غير متاح.";
                } else {
                    try {
                        ensureUserGamePermissionsTable($pdo);
                        $deletedUsernameStmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                        $deletedUsernameStmt->execute([$deleteUserId]);
                        $deletedUsername = (string)($deletedUsernameStmt->fetchColumn() ?: "");
                        $pdo->beginTransaction();
                        $deletePermissionsStmt = $pdo->prepare("DELETE FROM user_game_permissions WHERE user_id = ?");
                        $deletePermissionsStmt->execute([$deleteUserId]);
                        $deleteGamesStmt = $pdo->prepare("DELETE FROM user_games WHERE user_id = ?");
                        $deleteGamesStmt->execute([$deleteUserId]);
                        $deleteBranchesStmt = $pdo->prepare("DELETE FROM user_branches WHERE user_id = ?");
                        $deleteBranchesStmt->execute([$deleteUserId]);
                        $deleteUserStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $deleteUserStmt->execute([$deleteUserId]);
                        auditLogActivity($pdo, "delete", "users", $deleteUserId, "المستخدمين", "حذف مستخدم: " . $deletedUsername);
                        $pdo->commit();

                        if ((int)($_SESSION["user_id"] ?? 0) === $deleteUserId) {
                            header("Location: logout.php");
                            exit;
                        }

                        $_SESSION["users_success"] = "تم الحذف 🗑️";
                        header("Location: users.php");
                        exit;
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = "تعذر حذف المستخدم.";
                    }
                }
            }
        }
    }
}

$editUserId = (int)($_GET["edit"] ?? 0);
if ($editUserId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editStmt = $pdo->prepare("
        SELECT u.id, u.username, u.role, u.can_access_all_games, u.can_access_all_branches
        FROM users u
        WHERE u.status = 1
          AND (u.can_access_all_games = 1 OR EXISTS (
              SELECT 1
              FROM user_games ug2
              WHERE ug2.user_id = u.id AND ug2.game_id = ?
          ))
          AND u.id = ?
        LIMIT 1
    ");
    $editStmt->execute([$currentGameId, $editUserId]);
    $editUser = $editStmt->fetch();

    if ($editUser) {
        $editGamesStmt = $pdo->prepare("SELECT game_id FROM user_games WHERE user_id = ? ORDER BY game_id ASC");
        $editGamesStmt->execute([$editUserId]);
        $editGameIds = array_map("intval", array_column($editGamesStmt->fetchAll(), "game_id"));

        if (count($editGameIds) === 0) {
            if ((int)($editUser["can_access_all_games"] ?? 0) === 1) {
                $editGameIds = array_map("intval", array_keys($availableGameMap));
            } elseif (isset($allGameMap[$currentGameId])) {
                $editGameIds = [$currentGameId];
            }
        }

        $filteredEditGameIds = [];
        foreach ($editGameIds as $gameId) {
            if (isset($availableGameMap[$gameId])) {
                $filteredEditGameIds[] = $gameId;
            }
        }

        $editBranchesStmt = $pdo->prepare("SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id ASC");
        $editBranchesStmt->execute([$editUserId]);
        $editBranchIds = array_map("intval", array_column($editBranchesStmt->fetchAll(), "branch_id"));

        if (count($editBranchIds) === 0) {
            if ((int)($editUser["can_access_all_branches"] ?? 0) === 1) {
                $editBranchIds = array_map("intval", array_keys($availableBranchesMap));
            } elseif ($currentBranchId > 0 && isset($allBranchesMap[$currentBranchId])) {
                $editBranchIds = [$currentBranchId];
            }
        }

        $filteredEditBranchIds = [];
        foreach ($editBranchIds as $bId) {
            if (isset($availableBranchesMap[$bId])) {
                $filteredEditBranchIds[] = $bId;
            }
        }

        $formData = [
            "id" => (int)$editUser["id"],
            "username" => $editUser["username"],
            "password" => "",
            "role" => $editUser["role"],
            "games" => count($filteredEditGameIds) > 0 ? $filteredEditGameIds : [$currentGameId],
            "branches" => count($filteredEditBranchIds) > 0 ? $filteredEditBranchIds : ($currentBranchId > 0 ? [$currentBranchId] : []),
        ];
    }
}

$listStmt = $pdo->prepare(
    "SELECT
        u.id,
        u.username,
        u.role,
        u.can_access_all_games,
        u.can_access_all_branches,
        u.created_at,
        u.created_by_user_id,
        u.updated_by_user_id,
        (
            SELECT GROUP_CONCAT(DISTINCT g.name ORDER BY g.id SEPARATOR " . $pdo->quote(GAME_SEPARATOR) . ")
            FROM user_games ug
            INNER JOIN games g ON g.id = ug.game_id AND g.status = 1
            WHERE ug.user_id = u.id
        ) AS game_names,
        (
            SELECT GROUP_CONCAT(DISTINCT b.name ORDER BY b.id SEPARATOR " . $pdo->quote(BRANCH_SEPARATOR) . ")
            FROM user_branches ub
            INNER JOIN branches b ON b.id = ub.branch_id AND b.status = 1
            WHERE ub.user_id = u.id
        ) AS branch_names
    FROM users u
    WHERE u.status = 1
      AND (u.can_access_all_games = 1 OR EXISTS (
          SELECT 1
          FROM user_games ug2
          WHERE ug2.user_id = u.id AND ug2.game_id = ?
      ))
    ORDER BY u.id DESC"
);
$listStmt->execute([$currentGameId]);
$users = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المستخدمين</title>
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
                    <h1>👥 المستخدمين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <?php if ($currentBranchName !== ""): ?>
                    <span class="context-badge">🏢 <?php echo htmlspecialchars($currentBranchName, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
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
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="users-grid">
            <div class="card user-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "✏️ تعديل بيانات المستخدم" : "➕ إضافة مستخدم جديد"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="users.php" class="btn btn-soft" aria-label="إلغاء التعديل">✨</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["users_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="user_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="form-group">
                        <label for="username">👤 اسم المستخدم</label>
                        <input type="text" name="username" id="username" placeholder="أدخل اسم المستخدم" value="<?php echo htmlspecialchars($formData["username"], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="password">🔑 كلمة المرور</label>
                        <input type="password" name="password" id="password" placeholder="<?php echo $formData["id"] > 0 ? "كلمة مرور جديدة (اختياري)" : "أدخل كلمة المرور"; ?>" <?php echo $formData["id"] === 0 ? "required" : ""; ?>>
                    </div>

                    <div class="form-group">
                        <label for="role">🛡️ الصلاحية</label>
                        <select name="role" id="role" required>
                            <?php foreach ($allowedRoles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData["role"] === $role ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>🏢 الفروع المسموح بها</label>
                        <div class="games-selector">
                            <?php foreach ($availableBranchesMap as $branchId => $branchRow): ?>
                                <label class="game-chip">
                                    <input type="checkbox" name="branches[]" value="<?php echo (int)$branchId; ?>" <?php echo in_array((int)$branchId, $formData["branches"], true) ? "checked" : ""; ?>>
                                    <span>🏢 <?php echo htmlspecialchars($branchRow["name"], ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>🎮 الألعاب المسموح بها</label>
                        <?php foreach ($availableGamesGrouped as $groupedBranch): ?>
                            <div class="games-selector-group" style="margin-bottom: 12px;">
                                <div style="font-weight: 700; margin-bottom: 6px;">🏢 <?php echo htmlspecialchars($groupedBranch["branch_name"], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="games-selector">
                                    <?php foreach ($groupedBranch["games"] as $gameRowItem): ?>
                                        <label class="game-chip">
                                            <input type="checkbox" name="games[]" value="<?php echo (int)$gameRowItem["id"]; ?>" <?php echo in_array((int)$gameRowItem["id"], $formData["games"], true) ? "checked" : ""; ?>>
                                            <span>🏅 <?php echo htmlspecialchars($gameRowItem["name"], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo $formData["id"] > 0 ? "💾 تحديث بيانات المستخدم" : "🚀 حفظ المستخدم"; ?></button>
                </form>
            </div>

            <div class="card user-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>📋 قائمة المستخدمين</h3>
                    </div>
                    <span class="table-counter">إجمالي المستخدمين: <?php echo count($users); ?></span>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>👤 اسم المستخدم</th>
                                <th>🔑 كلمة المرور</th>
                                <th>🛡️ الصلاحية</th>
                                <th>🏢 الفروع</th>
                                <th>🎮 الألعاب</th>
                                <th>أضيف بواسطة</th>
                                <th>عدّل بواسطة</th>
                                <th>⚙️ الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($users) === 0): ?>
                                <tr>
                                    <td colspan="8" class="empty-cell">🫥 لا يوجد مستخدمون مضافون حالياً.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $gameNames = $user["game_names"];
                                    if ((int)$user["can_access_all_games"] === 1) {
                                        $gameNames = $allGameNamesLabel;
                                    }
                                    $branchNames = $user["branch_names"] ?? "";
                                    if ((int)($user["can_access_all_branches"] ?? 0) === 1) {
                                        $branchNames = $allBranchNamesLabel;
                                    }
                                    ?>
                                    <tr>
                                        <td data-label="👤 اسم المستخدم"><?php echo htmlspecialchars($user["username"], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="🔑 كلمة المرور"><span class="password-pill">••••••••</span></td>
                                        <td data-label="🛡️ الصلاحية"><span class="role-pill<?php echo $user["role"] === "مدير" ? " role-admin" : " role-supervisor"; ?>"><?php echo htmlspecialchars($user["role"], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td data-label="🏢 الفروع">
                                            <div class="table-badges">
                                                <?php foreach (array_filter(explode(BRANCH_SEPARATOR, (string)$branchNames)) as $branchNameLabel): ?>
                                                    <span class="badge"><?php echo htmlspecialchars($branchNameLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td data-label="🎮 الألعاب">
                                            <div class="table-badges">
                                                <?php foreach (array_filter(explode(GAME_SEPARATOR, (string)$gameNames)) as $gameName): ?>
                                                    <span class="badge"><?php echo htmlspecialchars($gameName, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $user["created_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $user["updated_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="⚙️ الإجراءات">
                                            <div class="inline-actions">
                                                <a href="users.php?edit=<?php echo (int)$user["id"]; ?>" class="btn btn-warning" aria-label="تعديل المستخدم">✏️</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["users_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$user["id"]; ?>">
                                                    <button type="submit" class="btn btn-danger" aria-label="حذف المستخدم" onclick="return confirm('هل أنت متأكد من حذف المستخدم؟')">🗑️</button>
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
