<?php
require_once "session.php";
startSecureSession();
redirectAuthenticatedUser();
require_once "config.php";
require_once "navigation.php";

$error = "";

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();

$siteName = $settings["academy_name"] ?? "أكاديمية رياضية";
$siteLogo = $settings["academy_logo"] ?? "assets/images/logo.png";

$branchesForLoginStmt = $pdo->query("SELECT id, name FROM branches WHERE status = 1 ORDER BY id ASC");
$loginBranches = $branchesForLoginStmt->fetchAll();

$gamesByBranchForLogin = [];
$gamesByBranchStmt = $pdo->query(
    "SELECT id, name, branch_id FROM games WHERE status = 1 AND branch_id IS NOT NULL ORDER BY branch_id ASC, id ASC"
);
foreach ($gamesByBranchStmt->fetchAll() as $loginGameRow) {
    $branchKey = (int)$loginGameRow["branch_id"];
    if (!isset($gamesByBranchForLogin[$branchKey])) {
        $gamesByBranchForLogin[$branchKey] = [];
    }
    $gamesByBranchForLogin[$branchKey][] = [
        "id" => (int)$loginGameRow["id"],
        "name" => (string)$loginGameRow["name"],
    ];
}

function upgradeLegacyUserPasswordHash(PDO $pdo, $userId, $plainPassword)
{
    $newHash = password_hash((string)$plainPassword, PASSWORD_DEFAULT);
    if ($newHash !== false) {
        $rehashStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $rehashStmt->execute([$newHash, $userId]);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $selectedBranchId = (int)($_POST["branch"] ?? 0);
    $selectedGameId = (int)($_POST["game"] ?? 0);
    $username       = trim($_POST["username"] ?? "");
    $password       = trim($_POST["password"] ?? "");

    if ($selectedBranchId <= 0 || $selectedGameId <= 0 || $username === "" || $password === "") {
        $error = "يرجى تعبئة جميع الحقول.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            $storedPassword = (string)($user["password"] ?? "");
            $passwordInfo = password_get_info($storedPassword);
            $passwordMatches = false;

            if (!empty($passwordInfo["algo"])) {
                $passwordMatches = password_verify($password, $storedPassword);
                if ($passwordMatches && password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
                    upgradeLegacyUserPasswordHash($pdo, $user["id"], $password);
                }
            } else {
                $passwordMatches = hash_equals($storedPassword, $password);
                if ($passwordMatches) {
                    upgradeLegacyUserPasswordHash($pdo, $user["id"], $password);
                }
            }

            if (!$passwordMatches) {
                $error = "اسم المستخدم أو كلمة السر غير صحيحة.";
            } else {
                $branchAllowed = false;
                $allowedBranches = getBranchesForUser($pdo, $user);
                foreach ($allowedBranches as $branchRow) {
                    if ((int)$branchRow["id"] === $selectedBranchId) {
                        $branchAllowed = true;
                        break;
                    }
                }

                if (!$branchAllowed) {
                    $error = "هذا المستخدم غير مصرح له بالدخول إلى الفرع المختار.";
                } else {
                    $allowedGames = getGamesForUserInBranch($pdo, $user, $selectedBranchId);

                    $allowed = false;
                    foreach ($allowedGames as $game) {
                        if ((int)$game["id"] === $selectedGameId) {
                            $allowed = true;
                            break;
                        }
                    }

                    if (!$allowed) {
                        $error = "هذا المستخدم غير مصرح له بالدخول إلى اللعبة المختارة.";
                    } else {
                        $selectedGameStmt = $pdo->prepare("SELECT id, name, branch_id FROM games WHERE id = ? AND status = 1 LIMIT 1");
                        $selectedGameStmt->execute([$selectedGameId]);
                        $selectedGame = $selectedGameStmt->fetch();

                        if (!$selectedGame || (int)$selectedGame["branch_id"] !== $selectedBranchId) {
                            $error = "اللعبة المختارة غير متاحة في الفرع المحدد.";
                        } else {
                            $selectedBranchStmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ? AND status = 1 LIMIT 1");
                            $selectedBranchStmt->execute([$selectedBranchId]);
                            $selectedBranch = $selectedBranchStmt->fetch();

                            if (!$selectedBranch) {
                                $error = "الفرع المختار غير متاح.";
                            } else {
                                session_regenerate_id(true);
                                $_SESSION["logged_in"] = true;
                                $_SESSION["site_name"] = $siteName;
                                $_SESSION["site_logo"] = $siteLogo;
                                $_SESSION["user_id"] = $user["id"];
                                $_SESSION["username"] = $user["username"];
                                $_SESSION["role"] = $user["role"];
                                $_SESSION["selected_branch_id"] = (int)$selectedBranch["id"];
                                $_SESSION["selected_branch_name"] = $selectedBranch["name"];
                                $_SESSION["selected_game_id"] = (int)$selectedGame["id"];
                                $_SESSION["selected_game_name"] = $selectedGame["name"];
                                $_SESSION["can_access_all_games"] = (int)$user["can_access_all_games"];
                                $_SESSION["can_access_all_branches"] = (int)($user["can_access_all_branches"] ?? 0);
                                $_SESSION["games"] = $allowedGames;
                                $_SESSION["branches"] = $allowedBranches;
                                $_SESSION["menu_permissions"] = getAllowedMenuPermissionKeys($pdo, $user, (int)$selectedGame["id"]);

                                header("Location: dashboard.php");
                                exit;
                            }
                        }
                    }
                }
            }
        } else {
            $error = "اسم المستخدم أو كلمة السر غير صحيحة.";
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
    <title>تسجيل الدخول | <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">

<div class="theme-switch-wrapper">
    <label class="theme-switch" for="themeToggle">
        <input type="checkbox" id="themeToggle">
        <span class="theme-slider">
            <span class="theme-icon sun">☀️</span>
            <span class="theme-icon moon">🌙</span>
        </span>
    </label>
</div>

<div class="login-container">
    <div class="login-card">
        <div class="brand-box">
            <img src="<?php echo htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="شعار الأكاديمية" class="academy-logo">
            <h1 class="academy-name"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>

        <div class="login-form-box">
            <h2 class="section-title">🔐 تسجيل الدخول</h2>

            <?php if ($error !== ""): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="branch">🏢 اختر الفرع</label>
                    <select name="branch" id="branch" required>
                        <option value="">-- اختر الفرع --</option>
                        <?php foreach ($loginBranches as $branchOption): ?>
                            <option value="<?php echo (int)$branchOption["id"]; ?>">
                                <?php echo htmlspecialchars($branchOption["name"], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="game">🎯 اختر اللعبة</label>
                    <select name="game" id="game" required disabled>
                        <option value="">-- اختر الفرع أولاً --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="username">👤 اسم المستخدم</label>
                    <input type="text" name="username" id="username" placeholder="أدخل اسم المستخدم" required>
                </div>

                <div class="form-group">
                    <label for="password">🔑 كلمة السر</label>
                    <input type="password" name="password" id="password" placeholder="أدخل كلمة السر" required>
                </div>

                <button type="submit" class="btn btn-primary">🚀 تسجيل الدخول</button>
            </form>

            <script>
                (function () {
                    var gamesByBranch = <?php echo json_encode($gamesByBranchForLogin, JSON_UNESCAPED_UNICODE); ?>;
                    var branchSelect = document.getElementById("branch");
                    var gameSelect = document.getElementById("game");

                    function refreshGames() {
                        var branchId = branchSelect.value;
                        gameSelect.innerHTML = "";

                        if (!branchId || !gamesByBranch[branchId] || gamesByBranch[branchId].length === 0) {
                            var emptyOption = document.createElement("option");
                            emptyOption.value = "";
                            emptyOption.textContent = branchId ? "-- لا توجد ألعاب لهذا الفرع --" : "-- اختر الفرع أولاً --";
                            gameSelect.appendChild(emptyOption);
                            gameSelect.disabled = true;
                            return;
                        }

                        var placeholder = document.createElement("option");
                        placeholder.value = "";
                        placeholder.textContent = "-- اختر اللعبة --";
                        gameSelect.appendChild(placeholder);

                        gamesByBranch[branchId].forEach(function (gameItem) {
                            var option = document.createElement("option");
                            option.value = gameItem.id;
                            option.textContent = gameItem.name;
                            gameSelect.appendChild(option);
                        });

                        gameSelect.disabled = false;
                    }

                    branchSelect.addEventListener("change", refreshGames);
                    refreshGames();
                })();
            </script>
        </div>
    </div>
</div>

<script src="assets/js/script.js"></script>
</body>
</html>
