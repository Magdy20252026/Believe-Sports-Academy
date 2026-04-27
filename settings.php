<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("settings");

$success = "";
$error = "";

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();

if (!$settings) {
    $pdo->exec("INSERT INTO settings (academy_name, academy_logo) VALUES ('أكاديمية رياضية', 'assets/images/logo.png')");
    $settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
    $settings = $settingsStmt->fetch();
}

$currentName = $settings["academy_name"] ?? "أكاديمية رياضية";
$currentLogo = $settings["academy_logo"] ?? "assets/images/logo.png";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $academyName = trim($_POST["academy_name"] ?? "");
    $academyLogoPath = $currentLogo;

    if ($academyName === "") {
        $error = "يرجى إدخال اسم الأكاديمية.";
    } else {
        if (isset($_FILES["academy_logo"]) && $_FILES["academy_logo"]["error"] === 0) {
            $allowedExtensions = ["jpg", "jpeg", "png", "gif", "webp"];
            $fileName = $_FILES["academy_logo"]["name"];
            $fileTmp  = $_FILES["academy_logo"]["tmp_name"];
            $fileSize = $_FILES["academy_logo"]["size"];
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedExtensions)) {
                $error = "صيغة اللوجو غير مسموح بها.";
            } elseif ($fileSize > 2 * 1024 * 1024) {
                $error = "حجم اللوجو يجب ألا يتجاوز 2 ميجابايت.";
            } else {
                if (!is_dir("uploads")) {
                    mkdir("uploads", 0777, true);
                }

                $newFileName = "academy_logo_" . time() . "." . $fileExt;
                $destination = "uploads/" . $newFileName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $academyLogoPath = $destination;
                } else {
                    $error = "حدث خطأ أثناء رفع اللوجو.";
                }
            }
        }

        if ($error === "") {
            $updateStmt = $pdo->prepare("UPDATE settings SET academy_name = ?, academy_logo = ? WHERE id = ?");
            $updateStmt->execute([$academyName, $academyLogoPath, $settings["id"]]);
            auditTrack($pdo, "update", "settings", (int)$settings["id"], "إعدادات الموقع", "تحديث إعدادات الأكاديمية: " . (string)$academyName);

            $_SESSION["site_name"] = $academyName;
            $_SESSION["site_logo"] = $academyLogoPath;

            $success = "تم حفظ إعدادات الموقع بنجاح.";
            $currentName = $academyName;
            $currentLogo = $academyLogoPath;
        }
    }
}

$sidebarName = $currentName;
$sidebarLogo = $currentLogo;
$activeMenu  = "settings";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات الموقع</title>
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
                    <h1>⚙️ إعدادات الموقع</h1>
                    <p>تعديل اسم الأكاديمية ورفع لوجو الأكاديمية</p>
                </div>
            </div>

            <div class="topbar-left">
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">🌙</span>
                    </span>
                </label>
            </div>
        </header>

        <section class="content-grid single-grid">
            <div class="card settings-card">
                <h3>🏫 إعدادات الأكاديمية</h3>

                <?php if ($success !== ""): ?>
                    <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($error !== ""): ?>
                    <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="login-form">
                    <div class="form-group">
                        <label for="academy_name">📝 اسم الأكاديمية</label>
                        <input type="text" name="academy_name" id="academy_name" value="<?php echo htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="academy_logo">🖼️ لوجو الأكاديمية</label>
                        <input type="file" name="academy_logo" id="academy_logo" accept=".jpg,.jpeg,.png,.gif,.webp">
                    </div>

                    <div class="logo-preview-box">
                        <p>📌 اللوجو الحالي:</p>
                        <img src="<?php echo htmlspecialchars($currentLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="لوجو الأكاديمية" class="preview-logo">
                    </div>

                    <button type="submit" class="btn btn-primary">💾 حفظ الإعدادات</button>
                </form>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="assets/js/script.js"></script>
</body>
</html>
