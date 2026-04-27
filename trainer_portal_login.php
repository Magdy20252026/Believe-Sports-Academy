<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("trainer");

if (isset($_SESSION["trainer_portal_logged_in"]) && $_SESSION["trainer_portal_logged_in"] === true) {
    header("Location: trainer_portal.php");
    exit;
}

require_once "config.php";

try {
    $passwordColCheck = $pdo->query("SHOW COLUMNS FROM trainers LIKE 'password'");
    if (!$passwordColCheck->fetch()) {
        $pdo->exec("ALTER TABLE trainers ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT '' AFTER salary");
    }
} catch (PDOException $ignored) {}

$settingsRow = null;
try {
    $settingsStmt = $pdo->query("SELECT academy_name, academy_logo FROM settings ORDER BY id DESC LIMIT 1");
    $settingsRow = $settingsStmt->fetch();
} catch (PDOException $ignored) {}

$siteName = $settingsRow["academy_name"] ?? "أكاديمية رياضية";
$siteLogo = $settingsRow["academy_logo"] ?? "assets/images/logo.png";

$loginError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $barcodeInput = trim($_POST["barcode"] ?? "");
    $passwordInput = trim($_POST["password"] ?? "");

    if ($barcodeInput === "" || $passwordInput === "") {
        $loginError = "يرجى إدخال الباركود وكلمة السر.";
    } else {
        try {
            $trainerStmt = $pdo->prepare(
                "SELECT t.id, t.name, t.phone, t.barcode, t.salary, t.game_id, t.password,
                        t.attendance_time, t.departure_time
                 FROM trainers t
                 WHERE t.barcode = ?
                 LIMIT 1"
            );
            $trainerStmt->execute([$barcodeInput]);
            $trainerRow = $trainerStmt->fetch();

            if (!$trainerRow) {
                $loginError = "الباركود أو كلمة السر غير صحيحة.";
            } else {
                $storedPassword = (string)($trainerRow["password"] ?? "");
                $passwordMatched = false;

                if ($storedPassword === "") {
                    $loginError = "لم يتم تعيين كلمة سر لهذا الحساب. تواصل مع الإدارة.";
                } else {
                    $passwordInfo = password_get_info($storedPassword);
                    if (!empty($passwordInfo["algo"])) {
                        $passwordMatched = password_verify($passwordInput, $storedPassword);
                        if ($passwordMatched && password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
                            $newHash = password_hash($passwordInput, PASSWORD_DEFAULT);
                            if ($newHash !== false) {
                                $rehashStmt = $pdo->prepare("UPDATE trainers SET password = ? WHERE id = ?");
                                $rehashStmt->execute([$newHash, $trainerRow["id"]]);
                            }
                        }
                    } else {
                        $passwordMatched = hash_equals($storedPassword, $passwordInput);
                        if ($passwordMatched) {
                            $newHash = password_hash($passwordInput, PASSWORD_DEFAULT);
                            if ($newHash !== false) {
                                $rehashStmt = $pdo->prepare("UPDATE trainers SET password = ? WHERE id = ?");
                                $rehashStmt->execute([$newHash, $trainerRow["id"]]);
                            }
                        }
                    }

                    if (!$passwordMatched) {
                        $loginError = "الباركود أو كلمة السر غير صحيحة.";
                    } else {
                        session_regenerate_id(true);
                        $_SESSION["trainer_portal_logged_in"] = true;
                        $_SESSION["trainer_portal_id"] = (int)$trainerRow["id"];
                        $_SESSION["trainer_portal_name"] = (string)$trainerRow["name"];
                        $_SESSION["trainer_portal_game_id"] = (int)$trainerRow["game_id"];
                        $_SESSION["trainer_portal_site_name"] = $siteName;
                        $_SESSION["trainer_portal_site_logo"] = $siteLogo;
                        header("Location: trainer_portal.php");
                        exit;
                    }
                }
            }
        } catch (PDOException $dbEx) {
            $loginError = "حدث خطأ في الاتصال بقاعدة البيانات.";
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
<title>بوابة المدربين | <?php echo htmlspecialchars($siteName, ENT_QUOTES, "UTF-8"); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
.portal-login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
}
.portal-login-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: var(--card-radius);
    box-shadow: var(--shadow);
    width: 100%;
    max-width: 440px;
    padding: 40px 36px;
}
.portal-login-brand {
    text-align: center;
    margin-bottom: 32px;
}
.portal-login-logo {
    width: 80px;
    height: 80px;
    object-fit: contain;
    border-radius: 16px;
    margin-bottom: 12px;
}
.portal-login-site-name {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 6px;
}
.portal-login-badge {
    display: inline-block;
    background: linear-gradient(135deg, var(--primary), var(--purple));
    color: #fff;
    font-size: 0.85rem;
    font-weight: 700;
    border-radius: 999px;
    padding: 4px 16px;
}
.portal-login-title {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 24px;
    text-align: center;
}
.portal-form-group {
    margin-bottom: 18px;
}
.portal-form-group label {
    display: block;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 6px;
}
.portal-form-group input {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid var(--border);
    border-radius: 12px;
    background: var(--input-bg);
    color: var(--text);
    font-size: 1rem;
    font-weight: 600;
    transition: border-color 0.2s;
    box-shadow: var(--input-shadow);
}
.portal-form-group input:focus {
    outline: none;
    border-color: var(--primary);
}
.portal-form-group input::placeholder {
    color: var(--text-soft);
    font-weight: 400;
}
.portal-login-btn {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--primary), var(--purple));
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.1s;
    margin-top: 4px;
}
.portal-login-btn:hover {
    opacity: 0.92;
    transform: translateY(-1px);
}
.portal-login-btn:active {
    transform: translateY(0);
}
.portal-error {
    background: rgba(220, 38, 38, 0.1);
    border: 1px solid var(--danger);
    color: var(--danger);
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 0.9rem;
    font-weight: 700;
    margin-bottom: 20px;
    text-align: center;
}
.theme-switch-wrapper {
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1001;
}
</style>
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

<div class="portal-login-wrap">
    <div class="portal-login-card">
        <div class="portal-login-brand">
            <img src="<?php echo htmlspecialchars($siteLogo, ENT_QUOTES, "UTF-8"); ?>" alt="شعار الأكاديمية" class="portal-login-logo">
            <div class="portal-login-site-name"><?php echo htmlspecialchars($siteName, ENT_QUOTES, "UTF-8"); ?></div>
            <span class="portal-login-badge">🏋️ بوابة المدربين</span>
        </div>

        <div class="portal-login-title">🔐 تسجيل الدخول</div>

        <?php if ($loginError !== ""): ?>
            <div class="portal-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="portal-form-group">
                <label for="barcode">📟 الباركود</label>
                <input type="text" id="barcode" name="barcode" placeholder="أدخل الباركود الخاص بك" required autocomplete="off">
            </div>
            <div class="portal-form-group">
                <label for="password">🔑 كلمة السر</label>
                <input type="password" id="password" name="password" placeholder="أدخل كلمة السر" required autocomplete="off">
            </div>
            <button type="submit" class="portal-login-btn">🚀 دخول</button>
        </form>

    </div>
</div>

<script>
if (window.AndroidBridge && typeof window.AndroidBridge.clearPortalState === 'function') {
    window.AndroidBridge.clearPortalState();
}
window.__PORTAL_SESSION_GUARD__ = {
    key: "trainer-portal",
    mode: "login",
    homeUrl: "trainer_portal.php"
};
</script>
<script src="assets/js/portal_session_guard.js"></script>
<script src="assets/js/script.js"></script>
</body>
</html>
