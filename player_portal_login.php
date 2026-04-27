<?php
date_default_timezone_set("Africa/Cairo");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

if (isset($_SESSION["player_portal_logged_in"]) && $_SESSION["player_portal_logged_in"] === true) {
    header("Location: player_portal.php");
    exit;
}

require_once "config.php";

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS players (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL DEFAULT '',
            barcode VARCHAR(100) NOT NULL DEFAULT '',
            password VARCHAR(255) NOT NULL DEFAULT '123456',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $passwordCheck = $pdo->query("SHOW COLUMNS FROM players LIKE 'password'");
    if (!$passwordCheck->fetch()) {
        $pdo->exec("ALTER TABLE players ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT '123456'");
    }
} catch (PDOException $ignored) {}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS store_orders (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            player_id INT(11) NOT NULL,
            product_id INT(11) NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity INT(11) NOT NULL DEFAULT 1,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            customer_name VARCHAR(150) NOT NULL DEFAULT '',
            customer_phone VARCHAR(50) NOT NULL DEFAULT '',
            delivery_address TEXT NOT NULL,
            notes TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_response TEXT NULL,
            responded_by_user_id INT(11) NULL DEFAULT NULL,
            responded_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_orders_game (game_id),
            KEY idx_store_orders_player (player_id),
            KEY idx_store_orders_status (game_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
} catch (PDOException $ignored) {}

try {
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
            target_player_id INT(11) NULL DEFAULT NULL,
            display_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $tpiCheck = $pdo->query("SHOW COLUMNS FROM player_notifications LIKE 'target_player_id'");
    if (!$tpiCheck->fetch()) {
        $pdo->exec("ALTER TABLE player_notifications ADD COLUMN target_player_id INT(11) NULL DEFAULT NULL AFTER target_group_level");
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
$activeView = $_GET["view"] ?? "offers";
if (!in_array($activeView, ["offers", "store", "login"], true)) {
    $activeView = "offers";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $activeView = "login";
    $barcodeInput = trim($_POST["barcode"] ?? "");
    $passwordInput = (string)($_POST["password"] ?? "");

    if ($barcodeInput === "" || $passwordInput === "") {
        $loginError = "يرجى إدخال الباركود وكلمة السر.";
    } else {
        try {
            $playerStmt = $pdo->prepare(
                "SELECT id, name, barcode, password, game_id, group_id, group_name, group_level, player_level
                 FROM players WHERE barcode = ? LIMIT 1"
            );
            $playerStmt->execute([$barcodeInput]);
            $playerRow = $playerStmt->fetch();

            if (!$playerRow) {
                $loginError = "الباركود أو كلمة السر غير صحيحة.";
            } else {
                $storedPassword = (string)($playerRow["password"] ?? "");
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
                                $upStmt = $pdo->prepare("UPDATE players SET password = ? WHERE id = ?");
                                $upStmt->execute([$newHash, $playerRow["id"]]);
                            }
                        }
                    } else {
                        $passwordMatched = hash_equals($storedPassword, $passwordInput);
                        if ($passwordMatched) {
                            $newHash = password_hash($passwordInput, PASSWORD_DEFAULT);
                            if ($newHash !== false) {
                                $upStmt = $pdo->prepare("UPDATE players SET password = ? WHERE id = ?");
                                $upStmt->execute([$newHash, $playerRow["id"]]);
                            }
                        }
                    }

                    if (!$passwordMatched) {
                        $loginError = "الباركود أو كلمة السر غير صحيحة.";
                    } else {
                        session_regenerate_id(true);
                        $_SESSION["player_portal_logged_in"] = true;
                        $_SESSION["player_portal_id"] = (int)$playerRow["id"];
                        $_SESSION["player_portal_name"] = (string)$playerRow["name"];
                        $_SESSION["player_portal_game_id"] = (int)$playerRow["game_id"];
                        $_SESSION["player_portal_site_name"] = $siteName;
                        $_SESSION["player_portal_site_logo"] = $siteLogo;
                        header("Location: player_portal.php");
                        exit;
                    }
                }
            }
        } catch (PDOException $dbEx) {
            $loginError = "حدث خطأ في الاتصال بقاعدة البيانات.";
        }
    }
}

$offersRows = [];
try {
    $offersRows = $pdo->query(
        "SELECT o.id, o.title, o.details, o.image_path, o.created_at, g.name AS game_name
         FROM offers o LEFT JOIN games g ON g.id = o.game_id
         ORDER BY o.created_at DESC, o.id DESC LIMIT 200"
    )->fetchAll();
} catch (PDOException $ignored) {}

$storeRows = [];
try {
    $storeRows = $pdo->query(
        "SELECT sp.id, sp.product_name, sp.price, sp.image_path, sp.is_available, g.name AS game_name
         FROM store_products sp LEFT JOIN games g ON g.id = sp.game_id
         WHERE sp.is_available = 1
         ORDER BY sp.created_at DESC, sp.id DESC LIMIT 200"
    )->fetchAll();
} catch (PDOException $ignored) {}

function ppFormatCurrency($a) { return number_format((float)$a, 2) . " ج.م"; }
function ppEsc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>بوابة اللاعبين | <?php echo ppEsc($siteName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
.pp-shell { min-height:100vh; display:flex; flex-direction:column; }
.pp-topbar {
    background: var(--bg-secondary); border-bottom:1px solid var(--border);
    padding: 0 24px; display:flex; align-items:center; justify-content:space-between;
    gap:12px; min-height:64px; position:sticky; top:0; z-index:100;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07);
}
.pp-brand { display:flex; align-items:center; gap:10px; }
.pp-brand img { width:42px; height:42px; object-fit:contain; border-radius:10px; background:#fff; padding:2px; }
.pp-brand-name { font-size:1.05rem; font-weight:800; color:var(--text); }
.pp-brand-badge { font-size:.72rem; font-weight:800; color:#fff;
    background: linear-gradient(135deg, #16a34a, #2f5bea); border-radius:999px; padding:3px 10px; display:inline-block; margin-top:2px; }
.pp-tabs { display:flex; gap:6px; flex-wrap:wrap; padding:18px 24px 0; max-width:1200px; margin:0 auto; width:100%; }
.pp-tab {
    flex:1; min-width:120px; padding:14px 20px; background:var(--bg-secondary);
    border:1px solid var(--border); border-radius:14px;
    color:var(--text); font-weight:800; cursor:pointer; transition:all .2s;
    display:flex; align-items:center; justify-content:center; gap:8px; font-size:1rem;
    text-decoration:none;
}
.pp-tab:hover { border-color:var(--primary); transform:translateY(-1px); }
.pp-tab.active { background:linear-gradient(135deg,#16a34a,#2f5bea); color:#fff; border-color:transparent;
    box-shadow:0 6px 18px rgba(47,91,234,.25); }
.pp-content { flex:1; padding:24px; max-width:1200px; margin:0 auto; width:100%; }
.pp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:18px; }
.pp-card {
    background:var(--bg-secondary); border:1px solid var(--border); border-radius:16px;
    overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.06); display:flex; flex-direction:column;
    transition: transform .2s, box-shadow .2s;
}
.pp-card:hover { transform: translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,0.10); }
.pp-card-img { width:100%; height:180px; object-fit:cover; background:var(--bg); }
.pp-card-body { padding:14px 16px; flex:1; display:flex; flex-direction:column; gap:8px; }
.pp-card-title { font-size:1rem; font-weight:800; color:var(--text); }
.pp-card-meta { font-size:.78rem; color:var(--text-soft); font-weight:700; display:flex; gap:6px; flex-wrap:wrap; }
.pp-meta-pill { background:rgba(47,91,234,.1); color:var(--primary); padding:2px 10px; border-radius:999px; }
.pp-card-text { font-size:.85rem; color:var(--text); line-height:1.6; font-weight:600; white-space:pre-line; }
.pp-price-row { display:flex; align-items:center; justify-content:space-between; margin-top:auto; padding-top:8px; }
.pp-price { font-size:1.1rem; font-weight:800; color:var(--success); }
.pp-empty { text-align:center; padding:60px 20px; color:var(--text-soft); font-weight:700; }
.pp-empty span { font-size:3rem; display:block; margin-bottom:12px; }
.pp-login-wrap { display:flex; justify-content:center; padding:8px 0 32px; }
.pp-login-card {
    background:var(--bg-secondary); border:1px solid var(--border);
    border-radius:20px; box-shadow:var(--shadow); width:100%; max-width:440px; padding:36px 32px;
}
.pp-login-brand { text-align:center; margin-bottom:24px; }
.pp-login-logo { width:84px; height:84px; object-fit:contain; border-radius:18px; margin-bottom:10px; background:#fff; padding:4px; }
.pp-login-title { font-size:1.15rem; font-weight:800; color:var(--text); text-align:center; margin-bottom:20px; }
.pp-fg { margin-bottom:16px; }
.pp-fg label { display:block; font-size:.88rem; font-weight:800; color:var(--text); margin-bottom:6px; }
.pp-fg input {
    width:100%; padding:12px 14px; border:1.5px solid var(--border);
    border-radius:12px; background:var(--input-bg); color:var(--text);
    font-size:1rem; font-weight:600; box-shadow:var(--input-shadow);
}
.pp-fg input:focus { outline:none; border-color:#16a34a; }
.pp-btn {
    width:100%; padding:14px; background:linear-gradient(135deg,#16a34a,#2f5bea);
    color:#fff; border:none; border-radius:12px; font-size:1rem; font-weight:800; cursor:pointer;
    transition: opacity .2s, transform .1s;
}
.pp-btn:hover { opacity:.92; transform:translateY(-1px); }
.pp-error {
    background:rgba(220,38,38,.1); border:1px solid var(--danger); color:var(--danger);
    border-radius:10px; padding:11px 14px; font-size:.9rem; font-weight:700; text-align:center; margin-bottom:16px;
}
.pp-section-title {
    font-size:1.15rem; font-weight:800; color:var(--text); margin:8px 0 16px;
    display:flex; align-items:center; gap:8px;
}
.pp-theme-wrap { display:flex; align-items:center; gap:8px; }
@media (max-width: 600px) {
    .pp-topbar { padding: 0 14px; }
    .pp-brand-name { font-size:.95rem; }
    .pp-tabs { padding: 14px 12px 0; }
    .pp-tab { font-size:.9rem; padding:12px 14px; }
    .pp-content { padding: 16px 12px; }
    .pp-login-card { padding:26px 22px; }
    .pp-card-img { height:160px; }
}
</style>
</head>
<body class="login-page">

<div class="pp-shell">
    <div class="pp-topbar">
        <div class="pp-brand">
            <img src="<?php echo ppEsc($siteLogo); ?>" alt="logo">
            <div>
                <div class="pp-brand-name"><?php echo ppEsc($siteName); ?></div>
                <span class="pp-brand-badge">⚽ بوابة اللاعبين</span>
            </div>
        </div>
        <div class="pp-theme-wrap">
            <label class="theme-switch" for="themeToggle">
                <input type="checkbox" id="themeToggle">
                <span class="theme-slider">
                    <span class="theme-icon sun">☀️</span>
                    <span class="theme-icon moon">🌙</span>
                </span>
            </label>
        </div>
    </div>

    <div class="pp-tabs">
        <a href="?view=offers" class="pp-tab <?php echo $activeView === 'offers' ? 'active' : ''; ?>">🎁 العروض</a>
        <a href="?view=store" class="pp-tab <?php echo $activeView === 'store' ? 'active' : ''; ?>">🏪 المتجر</a>
        <a href="?view=login" class="pp-tab <?php echo $activeView === 'login' ? 'active' : ''; ?>">🔐 تسجيل الدخول</a>
    </div>

    <div class="pp-content">
    <?php if ($activeView === 'offers'): ?>
        <div class="pp-section-title">🎁 العروض المتاحة</div>
        <?php if (count($offersRows) === 0): ?>
            <div class="pp-empty"><span>🎁</span>لا توجد عروض حالياً.</div>
        <?php else: ?>
            <div class="pp-grid">
                <?php foreach ($offersRows as $offer): ?>
                    <div class="pp-card">
                        <?php if (!empty($offer["image_path"])): ?>
                            <img src="<?php echo ppEsc($offer["image_path"]); ?>" alt="" class="pp-card-img">
                        <?php else: ?>
                            <div class="pp-card-img" style="display:flex;align-items:center;justify-content:center;font-size:3rem;color:var(--text-soft);">🎁</div>
                        <?php endif; ?>
                        <div class="pp-card-body">
                            <div class="pp-card-title"><?php echo ppEsc($offer["title"]); ?></div>
                            <div class="pp-card-meta"><span class="pp-meta-pill">🎮 <?php echo ppEsc($offer["game_name"] ?? "—"); ?></span></div>
                            <?php if (!empty($offer["details"])): ?>
                                <div class="pp-card-text"><?php echo ppEsc($offer["details"]); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($activeView === 'store'): ?>
        <div class="pp-section-title">🏪 منتجات المتجر</div>
        <?php if (count($storeRows) === 0): ?>
            <div class="pp-empty"><span>🏪</span>لا توجد منتجات حالياً.</div>
        <?php else: ?>
            <div class="pp-grid">
                <?php foreach ($storeRows as $product): ?>
                    <div class="pp-card">
                        <?php if (!empty($product["image_path"])): ?>
                            <img src="<?php echo ppEsc($product["image_path"]); ?>" alt="" class="pp-card-img">
                        <?php else: ?>
                            <div class="pp-card-img" style="display:flex;align-items:center;justify-content:center;font-size:3rem;color:var(--text-soft);">📦</div>
                        <?php endif; ?>
                        <div class="pp-card-body">
                            <div class="pp-card-title"><?php echo ppEsc($product["product_name"]); ?></div>
                            <div class="pp-card-meta"><span class="pp-meta-pill">🎮 <?php echo ppEsc($product["game_name"] ?? "—"); ?></span></div>
                            <div class="pp-price-row">
                                <span class="pp-price"><?php echo ppFormatCurrency($product["price"]); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="pp-login-wrap">
            <div class="pp-login-card">
                <div class="pp-login-brand">
                    <img src="<?php echo ppEsc($siteLogo); ?>" class="pp-login-logo" alt="logo">
                    <div class="pp-brand-name" style="font-size:1.2rem;"><?php echo ppEsc($siteName); ?></div>
                    <span class="pp-brand-badge" style="margin-top:8px;">⚽ بوابة اللاعبين</span>
                </div>
                <div class="pp-login-title">🔐 تسجيل دخول اللاعب</div>
                <?php if ($loginError !== ""): ?>
                    <div class="pp-error"><?php echo ppEsc($loginError); ?></div>
                <?php endif; ?>
                <form method="POST" autocomplete="off">
                    <div class="pp-fg">
                        <label for="barcode">📟 الباركود</label>
                        <input type="text" id="barcode" name="barcode" placeholder="أدخل الباركود الخاص بك" required autocomplete="off">
                    </div>
                    <div class="pp-fg">
                        <label for="password">🔑 كلمة السر</label>
                        <input type="password" id="password" name="password" placeholder="أدخل كلمة السر" required autocomplete="off">
                    </div>
                    <button type="submit" class="pp-btn">🚀 دخول</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
    </div>
</div>

<script>
window.__PORTAL_SESSION_GUARD__ = {
    key: "player-portal",
    mode: "login",
    homeUrl: "player_portal.php"
};
</script>
<script src="assets/js/portal_session_guard.js"></script>
<script src="assets/js/script.js"></script>
</body>
</html>
