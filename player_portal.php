<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("player");

if (!isset($_SESSION["player_portal_logged_in"]) || $_SESSION["player_portal_logged_in"] !== true) {
    header("Location: player_portal_login.php");
    exit;
}

require_once "config.php";
require_once "players_support.php";
require_once "game_levels_support.php";
require_once "schedule_exceptions_support.php";

const PPORT_ATTENDANCE_STATUS_LATE = "late";

$playerId = (int)$_SESSION["player_portal_id"];
$playerGameId = (int)$_SESSION["player_portal_game_id"];
$siteName = (string)($_SESSION["player_portal_site_name"] ?? "أكاديمية رياضية");
$siteLogo = (string)($_SESSION["player_portal_site_logo"] ?? "assets/images/logo.png");

ensurePlayerNotificationsTableForPortal($pdo);
ensureGameLevelsTable($pdo);

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

if (!isset($_SESSION["player_portal_csrf"])) {
    $_SESSION["player_portal_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["player_portal_csrf"];

$pageMessage = "";
$pageError = "";
$activeSection = $_GET["section"] ?? "home";
$allowedSections = ["home", "info", "attendance", "levels", "password", "store"];
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = "home";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken = $_POST["csrf_token"] ?? "";
    if (!hash_equals($csrfToken, $postedToken)) {
        $pageError = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "change_password") {
            $activeSection = "password";
            $currentPwd = (string)($_POST["current_password"] ?? "");
            $newPwd = (string)($_POST["new_password"] ?? "");
            $confirmPwd = (string)($_POST["confirm_password"] ?? "");

            if ($currentPwd === "" || $newPwd === "" || $confirmPwd === "") {
                $pageError = "يرجى تعبئة جميع الحقول.";
            } elseif (strlen($newPwd) < 6) {
                $pageError = "يجب أن تكون كلمة السر الجديدة 6 أحرف على الأقل.";
            } elseif ($newPwd !== $confirmPwd) {
                $pageError = "كلمتا السر غير متطابقتين.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT password FROM players WHERE id = ? LIMIT 1");
                    $stmt->execute([$playerId]);
                    $row = $stmt->fetch();
                    $stored = (string)($row["password"] ?? "");

                    $matched = false;
                    $info = password_get_info($stored);
                    if (!empty($info["algo"])) {
                        $matched = password_verify($currentPwd, $stored);
                    } else {
                        $matched = hash_equals($stored, $currentPwd);
                    }

                    if (!$matched) {
                        $pageError = "كلمة السر الحالية غير صحيحة.";
                    } else {
                        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
                        $up = $pdo->prepare("UPDATE players SET password = ? WHERE id = ?");
                        $up->execute([$newHash, $playerId]);
                        $pageMessage = "تم تغيير كلمة السر بنجاح.";
                    }
                } catch (PDOException $ex) {
                    $pageError = "حدث خطأ، حاول مرة أخرى.";
                }
            }
        }

        if ($action === "place_order") {
            $activeSection = "store";
            $productId = (int)($_POST["product_id"] ?? 0);
            $quantity = max(1, (int)($_POST["quantity"] ?? 1));
            $customerName = trim((string)($_POST["customer_name"] ?? ""));
            $customerPhone = trim((string)($_POST["customer_phone"] ?? ""));
            $deliveryAddress = trim((string)($_POST["delivery_address"] ?? ""));

            if ($productId <= 0 || $customerName === "" || $customerPhone === "" || $deliveryAddress === "") {
                $pageError = "يرجى تعبئة جميع البيانات المطلوبة.";
            } else {
                try {
                    $pStmt = $pdo->prepare(
                        "SELECT sp.id, sp.product_name, sp.price, sp.is_available, sp.game_id, g.name AS game_name
                         FROM store_products sp
                         LEFT JOIN games g ON g.id = sp.game_id
                         WHERE sp.id = ? LIMIT 1"
                    );
                    $pStmt->execute([$productId]);
                    $product = $pStmt->fetch();

                    if (!$product || (int)$product["is_available"] !== 1) {
                        $pageError = "المنتج غير متاح.";
                    } else {
                        $unitPrice = (float)$product["price"];
                        $total = $unitPrice * $quantity;
                        $ins = $pdo->prepare(
                            "INSERT INTO store_orders
                              (game_id, player_id, product_id, product_name, unit_price, quantity, total_price,
                               customer_name, customer_phone, delivery_address, notes, status)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending')"
                        );
                        $ins->execute([
                            (int)$product["game_id"], $playerId, $productId,
                            (string)$product["product_name"], $unitPrice, $quantity, $total,
                            $customerName, $customerPhone, $deliveryAddress, ""
                        ]);
                        $pageMessage = "تم إرسال طلبك بنجاح. ستصلك رسالة عند مراجعته من الإدارة.";
                    }
                } catch (PDOException $ex) {
                    $pageError = "تعذر إرسال الطلب، حاول مرة أخرى.";
                }
            }
        }
    }
}

$player = null;
try {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.game_id, p.group_id, p.barcode, p.name, p.phone, p.phone2,
                p.player_category, p.subscription_start_date, p.subscription_end_date,
                p.group_name, p.group_level, p.player_level,
                p.receipt_number, p.subscriber_number, p.subscription_number,
                p.issue_date, p.birth_date, p.player_age,
                p.training_days_per_week, p.total_training_days, p.total_trainings,
                p.trainer_name, p.training_time, p.training_day_keys,
                p.subscription_price, p.paid_amount,
                g.name AS game_name, sg.group_name AS sg_group_name, sg.group_level AS sg_group_level,
                sg.trainer_name AS sg_trainer_name, sg.training_time AS sg_training_time,
                sg.training_day_keys AS sg_training_day_keys, sg.training_day_times AS sg_training_day_times
         FROM players p
         LEFT JOIN games g ON g.id = p.game_id
         LEFT JOIN sports_groups sg ON sg.id = p.group_id
         WHERE p.id = ? LIMIT 1"
    );
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
} catch (PDOException $ignored) {}

if (!$player) {
    header("Location: player_portal_logout.php");
    exit;
}

try {
    ensurePlayerSubscriptionStatusNotifications($pdo, $player);
} catch (Throwable $throwable) {
    error_log("Failed to ensure player subscription notifications: " . $throwable->getMessage());
}

$attendanceRows = [];
try {
    $stmt = $pdo->prepare(
        "SELECT attendance_date, attendance_at,
                COALESCE(NULLIF(attendance_status, ''), ?) AS attendance_status
         FROM player_attendance
         WHERE player_id = ?
         ORDER BY attendance_date DESC, id DESC LIMIT 100"
    );
    $stmt->execute([PLAYER_ATTENDANCE_STATUS_PRESENT, $playerId]);
    $attendanceRows = $stmt->fetchAll();
} catch (PDOException $ignored) {}

function pportNormalizeAttendanceStatus($status) {
    $status = trim((string)$status);
    if ($status === "present" || $status === "حاضر") return PLAYER_ATTENDANCE_STATUS_PRESENT;
    if ($status === "absent" || $status === "غائب") return PLAYER_ATTENDANCE_STATUS_ABSENT;
    if ($status === "late" || $status === "متأخر") return PPORT_ATTENDANCE_STATUS_LATE;
    return $status;
}

$presentCount = 0;
$absentCount = 0;
foreach ($attendanceRows as $a) {
    $normalizedStatus = pportNormalizeAttendanceStatus($a["attendance_status"] ?? "");
    if ($normalizedStatus === PLAYER_ATTENDANCE_STATUS_PRESENT) $presentCount++;
    elseif ($normalizedStatus === PLAYER_ATTENDANCE_STATUS_ABSENT) $absentCount++;
}

$playerSpecificLevel = trim((string)($player["player_level"] ?? ""));
$currentLevel = $playerSpecificLevel !== ''
    ? $playerSpecificLevel
    : trim((string)($player["group_level"] !== "" ? $player["group_level"] : ($player["sg_group_level"] ?? "")));
$gameLevelRecords = fetchGameLevelRecords($pdo, $playerGameId);
$groupLevelsList = $gameLevelRecords;
$currentLevelDetails = '';
$levelExists = false;

foreach ($groupLevelsList as $levelRecord) {
    if ((string)($levelRecord["level_name"] ?? '') !== $currentLevel) {
        continue;
    }

    $levelExists = true;
    $currentLevelDetails = trim((string)($levelRecord["level_details"] ?? ''));
    break;
}

if ($currentLevel !== '' && !$levelExists) {
    $groupLevelsList[] = [
        'level_name' => $currentLevel,
        'level_details' => '',
    ];
}

$storeProducts = [];
try {
    $stmt = $pdo->prepare(
        "SELECT sp.id, sp.product_name, sp.price, sp.image_path, sp.is_available, sp.game_id, g.name AS game_name
         FROM store_products sp
         LEFT JOIN games g ON g.id = sp.game_id
         WHERE sp.is_available = 1
         ORDER BY sp.created_at DESC, sp.id DESC"
    );
    $stmt->execute();
    $storeProducts = $stmt->fetchAll();
} catch (PDOException $ignored) {}

$myOrders = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, product_name, quantity, total_price, status, admin_response, responded_at, created_at
         FROM store_orders WHERE player_id = ? ORDER BY created_at DESC, id DESC LIMIT 30"
    );
    $stmt->execute([$playerId]);
    $myOrders = $stmt->fetchAll();
} catch (PDOException $ignored) {}

$notifications = [];
$unreadNotificationCount = 0;
$latestNotification = null;
try {
    $notifications = fetchPlayerPortalNotifications(
        $pdo,
        $playerGameId,
        $playerId,
        (int)$player["group_id"],
        $currentLevel
    );
    $shouldMarkNotificationsAsRead = isset($activeSection) && $activeSection === "home";
    $unreadNotificationIds = [];
    foreach ($notifications as $index => $notificationRow) {
        if ((int)($notificationRow["is_read"] ?? 0) === 0) {
            $unreadNotificationCount++;
            if ($latestNotification === null) {
                $latestNotification = $notificationRow;
            }
            if ($shouldMarkNotificationsAsRead) {
                $unreadNotificationIds[] = (int)$notificationRow["id"];
                $notifications[$index]["is_read"] = 1;
            }
        }
    }

    if ($shouldMarkNotificationsAsRead && $unreadNotificationCount > 0) {
        markPlayerPortalNotificationsAsRead($pdo, $playerId, $unreadNotificationIds);
        $unreadNotificationCount = 0;
        $latestNotification = null;
    }
} catch (Throwable $throwable) {
    error_log("Failed to load player portal notifications: " . $throwable->getMessage());
}

function pportFmtDate($d) {
    $d = trim((string)$d);
    if ($d === "" || $d === "0000-00-00") return "—";
    try {
        $dt = new DateTimeImmutable($d, new DateTimeZone("Africa/Cairo"));
        return $dt->format("Y/m/d");
    } catch (Exception $ex) { return "—"; }
}
function pportFmtTime($time) {
    return formatEgyptTimeForDisplay($time);
}
function pportFmtDateTime($d) {
    return formatEgyptDateTimeForDisplay($d);
}
function pportFmtCurrency($a) { return number_format((float)$a, 2) . " ج.م"; }
function pportEsc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function pportAttBadge($s) {
    $map = [
        PLAYER_ATTENDANCE_STATUS_PRESENT => ["حاضر", "pp-badge-success"],
        PLAYER_ATTENDANCE_STATUS_ABSENT => ["غائب", "pp-badge-danger"],
        PLAYER_ATTENDANCE_STATUS_EMERGENCY_LEAVE => ["إجازة طارئة", "pp-badge-warning"],
        PPORT_ATTENDANCE_STATUS_LATE => ["متأخر", "pp-badge-warning"],
    ];
    $s = pportNormalizeAttendanceStatus($s);
    return $map[$s] ?? [($s !== "" ? $s : "—"), "pp-badge-muted"];
}
function pportOrderStatus($s) {
    $map = [
        "pending" => ["قيد المراجعة", "pp-badge-warning"],
        "accepted" => ["مقبول", "pp-badge-success"],
        "rejected" => ["مرفوض", "pp-badge-danger"],
    ];
    return $map[(string)$s] ?? [(string)$s, "pp-badge-muted"];
}
function pportPriority($p) {
    $map = [
        "urgent" => ["عاجل", "pp-badge-danger"],
        "important" => ["مهم", "pp-badge-warning"],
        "normal" => ["عادي", "pp-badge-info"],
    ];
    return $map[(string)$p] ?? ["—", "pp-badge-muted"];
}

$playerTrainingDayKeys = getPlayerTrainingDayKeys($player["training_day_keys"] ?? "");
$playerTrainingDayTimes = decodePlayerScheduleDayTimes(
    '',
    $playerTrainingDayKeys,
    $player["training_time"] ?? ''
);
$groupTrainingDayKeys = getPlayerTrainingDayKeys($player["sg_training_day_keys"] ?? "");
$groupTrainingDayTimes = decodePlayerScheduleDayTimes(
    $player["sg_training_day_times"] ?? '',
    $groupTrainingDayKeys,
    $player["sg_training_time"] ?? ''
);
$groupScheduleExceptionMap = buildGroupScheduleExceptionMap(fetchScheduleExceptionRows($pdo, $playerGameId));
$groupEmergencyRows = $groupScheduleExceptionMap[(int)($player["group_id"] ?? 0)]["rows"] ?? [];
$effectiveTrainingDayKeys = count($groupTrainingDayKeys) > 0 ? $groupTrainingDayKeys : $playerTrainingDayKeys;
$effectiveTrainingDayTimes = count($groupTrainingDayTimes) > 0 ? $groupTrainingDayTimes : $playerTrainingDayTimes;
$trainingDays = formatPlayerTrainingDaysLabel($effectiveTrainingDayKeys);
$trainingScheduleLabels = formatPlayerTrainingScheduleLabels($effectiveTrainingDayKeys, $effectiveTrainingDayTimes);
$primaryTrainingTime = getPrimaryPlayerScheduleTime($effectiveTrainingDayKeys, $effectiveTrainingDayTimes);
$displayTrainerName = trim((string)($player["sg_trainer_name"] ?? "")) !== ''
    ? trim((string)$player["sg_trainer_name"])
    : trim((string)($player["trainer_name"] ?? ""));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>بوابة اللاعب | <?php echo pportEsc($siteName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
.pp-root { --pp-topbar-height:64px; min-height:100vh; display:flex; flex-direction:column; }
.pp-topbar {
    background:var(--bg-secondary); border-bottom:1px solid var(--border);
    padding:0 20px; display:flex; align-items:center; justify-content:space-between;
    gap:12px; min-height:var(--pp-topbar-height); position:sticky; top:0; z-index:200;
    box-shadow:0 2px 12px rgba(0,0,0,0.07);
}
.pp-topbar-l { display:flex; align-items:center; gap:10px; min-width:0; flex:1 1 auto; }
.pp-burger {
    background:transparent; border:1px solid var(--border); border-radius:10px;
    padding:8px 12px; font-size:1.1rem; cursor:pointer; color:var(--text); font-weight:800;
}
.pp-burger:hover { border-color:var(--primary); color:var(--primary); }
.pp-brand { display:flex; align-items:center; gap:8px; min-width:0; flex:1 1 auto; }
.pp-brand img { width:38px; height:38px; object-fit:contain; border-radius:8px; background:#fff; padding:2px; }
.pp-brand-copy { min-width:0; }
.pp-brand-name { font-size:1rem; font-weight:800; color:var(--text); }
.pp-brand-badge { display:inline-block; font-size:.68rem; font-weight:800; color:#fff;
    background:linear-gradient(135deg,#16a34a,#2f5bea); border-radius:999px; padding:2px 9px; margin-top:2px; }
.pp-topbar-r { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.pp-alert-link {
    position:relative; display:inline-flex; align-items:center; justify-content:center;
    width:42px; height:42px; border-radius:12px; text-decoration:none; color:var(--text);
    border:1px solid var(--border); background:var(--bg); font-size:1.05rem;
}
.pp-alert-link:hover { color:var(--primary); border-color:var(--primary); }
.pp-alert-count {
    position:absolute; top:-6px; left:-6px; min-width:20px; height:20px; padding:0 6px;
    border-radius:999px; background:var(--danger); color:#fff; font-size:.72rem; font-weight:800;
    display:inline-flex; align-items:center; justify-content:center; box-shadow:0 6px 14px rgba(220,38,38,.24);
}
.pp-user { font-size:.88rem; font-weight:700; color:var(--text-soft); display:flex; align-items:center; gap:5px; }
.pp-logout {
    padding:7px 14px; background:rgba(220,38,38,.1); color:var(--danger);
    border:1px solid var(--danger); border-radius:10px; font-size:.82rem; font-weight:800;
    text-decoration:none; transition:background .2s;
}
.pp-logout:hover { background:rgba(220,38,38,.2); }
.pp-body { flex:1; display:flex; }
.pp-sidebar-overlay {
    position:fixed; inset:var(--pp-topbar-height) 0 0 0; background:rgba(15,23,42,.42);
    opacity:0; pointer-events:none; transition:opacity .25s ease; z-index:140;
}
.pp-sidebar-overlay.show { opacity:1; pointer-events:auto; }

.pp-sidebar {
    width:260px; background:var(--bg-secondary); border-left:1px solid var(--border);
    padding:18px 12px; display:flex; flex-direction:column; gap:6px;
    transition: width .25s ease, padding .25s ease, transform .25s ease;
    position:sticky; top:var(--pp-topbar-height); height:calc(100vh - var(--pp-topbar-height)); overflow-y:auto;
}
.pp-sidebar.collapsed { width:64px; padding:18px 6px; }
.pp-sidebar.collapsed .pp-side-label { display:none; }
.pp-sidebar.collapsed .pp-side-item { justify-content:center; padding:12px 8px; }
.pp-side-item {
    display:flex; align-items:center; gap:10px; padding:11px 14px;
    border-radius:12px; text-decoration:none; color:var(--text);
    font-weight:700; font-size:.92rem; transition:all .2s;
    border:1px solid transparent;
}
.pp-side-item:hover { background:rgba(47,91,234,.07); color:var(--primary); }
.pp-side-item.active { background:linear-gradient(135deg,#16a34a,#2f5bea); color:#fff; box-shadow:0 4px 12px rgba(47,91,234,.25); }
.pp-side-icon { font-size:1.15rem; flex-shrink:0; }
.pp-main { flex:1; padding:24px; max-width:100%; overflow-x:hidden; }
.pp-main-inner { max-width:1100px; margin:0 auto; }

.pp-welcome {
    background:linear-gradient(135deg,#16a34a,#2f5bea);
    color:#fff; border-radius:20px; padding:28px 32px; margin-bottom:22px;
    display:flex; align-items:center; justify-content:space-between; gap:18px; flex-wrap:wrap;
    box-shadow:0 8px 24px rgba(47,91,234,.18);
}
.pp-welcome-l { display:flex; align-items:center; gap:18px; }
.pp-welcome-logo { width:72px; height:72px; object-fit:contain; background:rgba(255,255,255,.18); border-radius:18px; padding:8px; }
.pp-welcome-title { font-size:1.45rem; font-weight:800; margin-bottom:4px; }
.pp-welcome-sub { font-size:.92rem; font-weight:600; opacity:.9; }
.pp-welcome-academy { font-size:1.05rem; font-weight:800; opacity:.95; }

.pp-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:22px; }
.pp-stat {
    background:var(--bg-secondary); border:1px solid var(--border);
    border-radius:14px; padding:18px 14px; text-align:center;
    box-shadow:0 2px 8px rgba(0,0,0,.04);
}
.pp-stat-icon { font-size:1.6rem; display:block; margin-bottom:6px; }
.pp-stat-val { font-size:1.25rem; font-weight:800; color:var(--text); margin-bottom:2px; }
.pp-stat-lbl { font-size:.78rem; font-weight:700; color:var(--text-soft); }

.pp-section {
    background:var(--bg-secondary); border:1px solid var(--border);
    border-radius:18px; padding:22px; margin-bottom:18px;
    box-shadow:0 2px 8px rgba(0,0,0,.04);
}
.pp-section-h {
    font-size:1.05rem; font-weight:800; color:var(--text);
    margin-bottom:18px; display:flex; align-items:center; gap:8px;
    padding-bottom:12px; border-bottom:1px solid var(--border);
}

.pp-info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
.pp-info-item {
    background:var(--bg); border:1px solid var(--border);
    border-radius:12px; padding:12px 14px;
}
.pp-info-lbl { font-size:.74rem; font-weight:800; color:var(--text-soft); margin-bottom:4px; letter-spacing:.02em; }
.pp-info-val { font-size:.95rem; font-weight:800; color:var(--text); word-break:break-word; }

.pp-barcode-wrap {
    background:#ffffff; border:1px solid var(--border); border-radius:18px;
    padding:24px 18px; text-align:center; margin-bottom:18px;
    box-shadow:0 2px 8px rgba(0,0,0,.05);
}
.pp-barcode-svg { max-width:100%; height:auto; }
.pp-barcode-caption {
    margin-top:14px; padding:10px 16px; background:#f1f5f9; color:#0f172a;
    border-radius:12px; display:inline-flex; align-items:center; gap:10px;
    font-weight:800; font-size:.95rem; letter-spacing:.04em;
}

.pp-table-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:12px; }
.pp-table { width:100%; border-collapse:collapse; font-size:.88rem; }
.pp-table thead th {
    background:var(--bg); color:var(--text-soft); font-weight:800; font-size:.78rem;
    padding:11px 12px; text-align:right; border-bottom:1px solid var(--border); white-space:nowrap;
}
.pp-table tbody tr { border-bottom:1px solid var(--border); }
.pp-table tbody tr:last-child { border-bottom:none; }
.pp-table tbody td { padding:11px 12px; color:var(--text); font-weight:600; vertical-align:middle; }

.pp-badge-success { background:rgba(22,163,74,.12); color:var(--success); padding:3px 10px; border-radius:999px; font-size:.78rem; font-weight:800; white-space:nowrap; }
.pp-badge-danger { background:rgba(220,38,38,.1); color:var(--danger); padding:3px 10px; border-radius:999px; font-size:.78rem; font-weight:800; white-space:nowrap; }
.pp-badge-warning { background:rgba(234,88,12,.1); color:var(--warning); padding:3px 10px; border-radius:999px; font-size:.78rem; font-weight:800; white-space:nowrap; }
.pp-badge-info { background:rgba(14,165,233,.1); color:#0ea5e9; padding:3px 10px; border-radius:999px; font-size:.78rem; font-weight:800; white-space:nowrap; }
.pp-badge-muted { background:rgba(100,116,139,.1); color:var(--text-soft); padding:3px 10px; border-radius:999px; font-size:.78rem; font-weight:800; white-space:nowrap; }

.pp-empty { text-align:center; padding:42px 20px; color:var(--text-soft); font-weight:700; }
.pp-empty span { display:block; font-size:2.3rem; margin-bottom:10px; }

.pp-levels { display:flex; flex-direction:column; gap:10px; max-width:520px; margin:0 auto; }
.pp-level {
    background:var(--bg); border:2px solid var(--border); border-radius:14px;
    padding:14px 18px; display:flex; align-items:center; gap:14px;
    opacity:.45; transition:all .2s;
}
.pp-level-num {
    width:40px; height:40px; border-radius:50%; background:var(--border); color:var(--text-soft);
    display:flex; align-items:center; justify-content:center; font-weight:800; flex-shrink:0;
}
.pp-level-name { font-size:1rem; font-weight:800; color:var(--text); }
.pp-level-sub { font-size:.78rem; color:var(--text-soft); font-weight:700; }
.pp-level.active {
    opacity:1; background:linear-gradient(135deg, rgba(22,163,74,.08), rgba(47,91,234,.08));
    border-color:#16a34a; box-shadow:0 4px 16px rgba(22,163,74,.16);
}
.pp-level.active .pp-level-num { background:linear-gradient(135deg,#16a34a,#2f5bea); color:#fff; }
.pp-level.active .pp-level-name { color:#16a34a; }

.pp-msg-success {
    background:rgba(22,163,74,.1); border:1px solid var(--success); color:var(--success);
    border-radius:10px; padding:12px 16px; font-weight:800; margin-bottom:16px; text-align:center;
}
.pp-msg-error {
    background:rgba(220,38,38,.1); border:1px solid var(--danger); color:var(--danger);
    border-radius:10px; padding:12px 16px; font-weight:800; margin-bottom:16px; text-align:center;
}

.pp-fg { margin-bottom:14px; }
.pp-fg label { display:block; font-size:.88rem; font-weight:800; color:var(--text); margin-bottom:6px; }
.pp-fg input, .pp-fg textarea, .pp-fg select {
    width:100%; padding:11px 14px; border:1.5px solid var(--border); border-radius:12px;
    background:var(--input-bg); color:var(--text); font-size:.95rem; font-weight:600;
    font-family:inherit; box-shadow:var(--input-shadow);
}
.pp-fg input:focus, .pp-fg textarea:focus, .pp-fg select:focus { outline:none; border-color:#16a34a; }
.pp-fg textarea { resize:vertical; min-height:80px; }
.pp-btn {
    padding:12px 26px; background:linear-gradient(135deg,#16a34a,#2f5bea); color:#fff;
    border:none; border-radius:12px; font-size:.95rem; font-weight:800; cursor:pointer;
    transition: opacity .2s, transform .1s;
}
.pp-btn:hover { opacity:.92; transform:translateY(-1px); }

.pp-store-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:16px; }
.pp-product {
    background:var(--bg); border:1px solid var(--border); border-radius:14px; overflow:hidden;
    display:flex; flex-direction:column;
}
.pp-product-img { width:100%; height:160px; object-fit:cover; background:var(--bg); }
.pp-product-body { padding:12px 14px; flex:1; display:flex; flex-direction:column; gap:8px; }
.pp-product-name { font-size:.98rem; font-weight:800; color:var(--text); }
.pp-product-price { font-size:1.05rem; font-weight:800; color:var(--success); }
.pp-product-btn {
    margin-top:auto; padding:9px 14px; background:linear-gradient(135deg,#16a34a,#2f5bea);
    color:#fff; border:none; border-radius:10px; font-weight:800; cursor:pointer; font-size:.85rem;
}

.pp-modal-back {
    position:fixed; inset:0; background:rgba(15,23,42,.6); display:none;
    align-items:center; justify-content:center; z-index:500; padding:16px;
}
.pp-modal-back.show { display:flex; }
.pp-modal {
    background:var(--bg-secondary); border-radius:16px; padding:24px;
    width:100%; max-width:500px; max-height:90vh; overflow-y:auto;
    box-shadow:0 12px 40px rgba(0,0,0,.3);
}
.pp-modal-h {
    font-size:1.05rem; font-weight:800; color:var(--text); margin-bottom:16px;
    padding-bottom:12px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.pp-modal-close {
    background:transparent; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-soft);
}
.pp-modal-actions { display:flex; gap:10px; margin-top:6px; }
.pp-btn-secondary {
    padding:11px 18px; background:transparent; color:var(--text-soft);
    border:1.5px solid var(--border); border-radius:12px; font-weight:800; cursor:pointer; font-size:.9rem;
}

.pp-notif {
    background:var(--bg); border:1px solid var(--border); border-radius:14px;
    padding:14px 16px; margin-bottom:10px;
}
.pp-notif:last-child { margin-bottom:0; }
.pp-notif-h {
    display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
    margin-bottom:8px; flex-wrap:wrap;
}
.pp-notif-title { font-size:.95rem; font-weight:800; color:var(--text); flex:1; }
.pp-notif-meta { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.pp-notif-msg { font-size:.88rem; font-weight:600; color:var(--text); line-height:1.6; white-space:pre-line; }
.pp-notif-date { font-size:.74rem; font-weight:700; color:var(--text-soft); margin-top:6px; }
.pp-live-notice {
    position:fixed; top:84px; left:20px; z-index:400; width:min(360px, calc(100vw - 24px));
    background:linear-gradient(135deg,#0f172a,#1d4ed8); color:#fff; border-radius:18px;
    padding:16px 18px; box-shadow:0 18px 40px rgba(15,23,42,.28);
    opacity:0; transform:translateY(-12px); pointer-events:none; transition:all .25s ease;
}
.pp-live-notice.show { opacity:1; transform:translateY(0); pointer-events:auto; }
.pp-live-notice-h {
    display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:8px;
}
.pp-live-notice-title { font-size:1rem; font-weight:800; }
.pp-live-notice-close {
    background:rgba(255,255,255,.12); color:#fff; border:none; border-radius:10px;
    width:30px; height:30px; cursor:pointer; font-size:1rem; flex-shrink:0;
}
.pp-live-notice-msg { font-size:.9rem; line-height:1.8; white-space:pre-line; opacity:.95; }
.pp-live-notice-actions { display:flex; justify-content:flex-end; margin-top:12px; }
.pp-live-notice-btn {
    display:inline-flex; align-items:center; gap:6px; border:none; border-radius:10px;
    background:#fff; color:#0f172a; padding:9px 14px; text-decoration:none; font-size:.82rem; font-weight:800;
}

@media (max-width: 900px) {
    .pp-sidebar {
        position:fixed; top:var(--pp-topbar-height); right:0; height:calc(100vh - var(--pp-topbar-height)); z-index:150;
        transform: translateX(100%); width:240px;
    }
    .pp-sidebar.show { transform: translateX(0); box-shadow:-6px 0 18px rgba(0,0,0,.18); }
    .pp-sidebar.collapsed { width:240px; padding:18px 12px; transform:translateX(100%); }
    .pp-sidebar.collapsed .pp-side-label { display:inline; }
    .pp-sidebar.collapsed .pp-side-item { justify-content:flex-start; padding:11px 14px; }
    .pp-main { padding:18px 14px; }
    .pp-welcome { padding:20px; }
    .pp-welcome-title { font-size:1.15rem; }
    .pp-welcome-logo { width:60px; height:60px; }
    .pp-live-notice { top:76px; left:12px; width:calc(100vw - 24px); }
    body.pp-mobile-menu-open { overflow:hidden; }
}
@media (max-width: 600px) {
    .pp-topbar { padding:0 12px; }
    .pp-topbar-l { gap:8px; }
    .pp-topbar-r { gap:6px; }
    .pp-burger { padding:8px 10px; }
    .pp-brand img { width:32px; height:32px; }
    .pp-brand-name {
        font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .pp-brand-badge { display:none; }
    .pp-alert-link { width:38px; height:38px; border-radius:10px; }
    .pp-logout { padding:7px 10px; font-size:.76rem; white-space:nowrap; }
    .pp-user { display:none; }
    .pp-section { padding:16px; }
}
</style>
</head>
<body>
<div class="pp-root">
    <div class="pp-topbar">
        <div class="pp-topbar-l">
            <button class="pp-burger" id="ppBurger" type="button" aria-label="القائمة" aria-controls="ppSidebar">☰</button>
            <div class="pp-brand">
                <img src="<?php echo pportEsc($siteLogo); ?>" alt="logo">
                <div class="pp-brand-copy">
                    <div class="pp-brand-name"><?php echo pportEsc($siteName); ?></div>
                    <span class="pp-brand-badge">⚽ بوابة اللاعب</span>
                </div>
            </div>
        </div>
        <div class="pp-topbar-r">
            <a href="?section=home" class="pp-alert-link" aria-label="الإشعارات">
                🔔
                <?php if ($unreadNotificationCount > 0): ?>
                    <span class="pp-alert-count"><?php echo $unreadNotificationCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="pp-user"><span>👤</span><span><?php echo pportEsc($player["name"]); ?></span></div>
            <label class="theme-switch" for="themeToggle">
                <input type="checkbox" id="themeToggle">
                <span class="theme-slider">
                    <span class="theme-icon sun">☀️</span>
                    <span class="theme-icon moon">🌙</span>
                </span>
            </label>
            <a href="player_portal_logout.php" class="pp-logout">🚪 خروج</a>
        </div>
    </div>

    <div class="pp-body">
        <div class="pp-sidebar-overlay" id="ppSidebarOverlay"></div>
        <aside class="pp-sidebar" id="ppSidebar">
            <a href="?section=home" class="pp-side-item <?php echo $activeSection === 'home' ? 'active' : ''; ?>">
                <span class="pp-side-icon">🏠</span><span class="pp-side-label">الرئيسية</span>
            </a>
            <a href="?section=info" class="pp-side-item <?php echo $activeSection === 'info' ? 'active' : ''; ?>">
                <span class="pp-side-icon">👤</span><span class="pp-side-label">معلومات اللاعب</span>
            </a>
            <a href="?section=attendance" class="pp-side-item <?php echo $activeSection === 'attendance' ? 'active' : ''; ?>">
                <span class="pp-side-icon">📅</span><span class="pp-side-label">سجل الحضور والغياب</span>
            </a>
            <a href="?section=levels" class="pp-side-item <?php echo $activeSection === 'levels' ? 'active' : ''; ?>">
                <span class="pp-side-icon">🏆</span><span class="pp-side-label">مشوار الطالب</span>
            </a>
            <a href="?section=password" class="pp-side-item <?php echo $activeSection === 'password' ? 'active' : ''; ?>">
                <span class="pp-side-icon">🔑</span><span class="pp-side-label">تغيير كلمة السر</span>
            </a>
            <a href="?section=store" class="pp-side-item <?php echo $activeSection === 'store' ? 'active' : ''; ?>">
                <span class="pp-side-icon">🏪</span><span class="pp-side-label">المتجر</span>
            </a>
        </aside>

        <main class="pp-main">
            <div class="pp-main-inner">

                <?php if ($pageMessage !== ""): ?>
                    <div class="pp-msg-success">✅ <?php echo pportEsc($pageMessage); ?></div>
                <?php endif; ?>
                <?php if ($pageError !== ""): ?>
                    <div class="pp-msg-error">⚠️ <?php echo pportEsc($pageError); ?></div>
                <?php endif; ?>

                <?php if ($activeSection === 'home'): ?>
                    <div class="pp-welcome">
                        <div class="pp-welcome-l">
                            <img src="<?php echo pportEsc($siteLogo); ?>" alt="logo" class="pp-welcome-logo">
                            <div>
                                <div class="pp-welcome-title">أهلاً، <?php echo pportEsc($player["name"]); ?> 👋</div>
                                <div class="pp-welcome-academy"><?php echo pportEsc($siteName); ?></div>
                                <div class="pp-welcome-sub"><?php echo pportEsc((string)($player["game_name"] ?? "")); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="pp-stats">
                        <div class="pp-stat"><span class="pp-stat-icon">✅</span><div class="pp-stat-val"><?php echo $presentCount; ?></div><div class="pp-stat-lbl">أيام الحضور</div></div>
                        <div class="pp-stat"><span class="pp-stat-icon">❌</span><div class="pp-stat-val"><?php echo $absentCount; ?></div><div class="pp-stat-lbl">أيام الغياب</div></div>
                        <div class="pp-stat"><span class="pp-stat-icon">🏆</span><div class="pp-stat-val"><?php echo pportEsc($currentLevel !== "" ? $currentLevel : "—"); ?></div><div class="pp-stat-lbl">المستوى الحالي</div></div>
                        <div class="pp-stat"><span class="pp-stat-icon">🛒</span><div class="pp-stat-val"><?php echo count($myOrders); ?></div><div class="pp-stat-lbl">طلبات المتجر</div></div>
                    </div>

                    <div class="pp-section">
                        <div class="pp-section-h">📣 إشعارات الإدارة</div>
                        <?php if (count($notifications) === 0): ?>
                            <div class="pp-empty"><span>📣</span>لا توجد إشعارات حالياً.</div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                                <?php $pri = pportPriority($n["priority_level"]); ?>
                                <div class="pp-notif">
                                    <div class="pp-notif-h">
                                        <div class="pp-notif-title"><?php echo pportEsc($n["title"]); ?></div>
                                        <div class="pp-notif-meta">
                                            <span class="<?php echo $pri[1]; ?>"><?php echo $pri[0]; ?></span>
                                        </div>
                                    </div>
                                    <div class="pp-notif-msg"><?php echo pportEsc($n["message"]); ?></div>
                                    <div class="pp-notif-date">📅 <?php echo pportFmtDate($n["display_date"]); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                <?php elseif ($activeSection === 'info'): ?>
                    <div class="pp-section">
                        <div class="pp-section-h">📟 الباركود الخاص بك</div>
                        <div class="pp-barcode-wrap">
                            <svg id="ppBarcode" class="pp-barcode-svg"></svg>
                            <div>
                                <div class="pp-barcode-caption">
                                    <span>📟</span><span>كود اللاعب</span><span>:</span>
                                    <span dir="ltr"><?php echo pportEsc($player["barcode"] !== "" ? $player["barcode"] : "—"); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pp-section">
                        <div class="pp-section-h">👤 بيانات اللاعب</div>
                        <div class="pp-info-grid">
                            <div class="pp-info-item"><div class="pp-info-lbl">الاسم</div><div class="pp-info-val"><?php echo pportEsc($player["name"]); ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">رقم الهاتف</div><div class="pp-info-val" dir="ltr"><?php echo pportEsc($player["phone"] !== "" ? $player["phone"] : "—"); ?></div></div>
                            <?php if (!empty($player["phone2"])): ?>
                                <div class="pp-info-item"><div class="pp-info-lbl">هاتف إضافي</div><div class="pp-info-val" dir="ltr"><?php echo pportEsc($player["phone2"]); ?></div></div>
                            <?php endif; ?>
                            <div class="pp-info-item"><div class="pp-info-lbl">اللعبة</div><div class="pp-info-val"><?php echo pportEsc($player["game_name"] ?? "—"); ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">المجموعة</div><div class="pp-info-val"><?php echo pportEsc(($player["group_name"] !== "" ? $player["group_name"] : ($player["sg_group_name"] ?? "—"))); ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">المستوى</div><div class="pp-info-val"><?php echo pportEsc($currentLevel !== "" ? $currentLevel : "—"); ?></div></div>
                            <?php if ($currentLevelDetails !== ""): ?>
                                <div class="pp-info-item"><div class="pp-info-lbl">تفاصيل المستوى</div><div class="pp-info-val"><?php echo pportEsc($currentLevelDetails); ?></div></div>
                            <?php endif; ?>
                            <?php if ($playerSpecificLevel !== ""): ?>
                                <div class="pp-info-item"><div class="pp-info-lbl">مستوى اللاعب</div><div class="pp-info-val"><?php echo pportEsc($playerSpecificLevel); ?></div></div>
                            <?php endif; ?>
                            <div class="pp-info-item"><div class="pp-info-lbl">المدرب</div><div class="pp-info-val"><?php echo pportEsc($displayTrainerName !== "" ? $displayTrainerName : "—"); ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">الفئة</div><div class="pp-info-val"><?php echo pportEsc($player["player_category"] ?? "—"); ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">تاريخ الميلاد</div><div class="pp-info-val"><?php echo pportFmtDate($player["birth_date"] ?? ""); ?></div></div>
                            <?php if ((int)$player["player_age"] > 0): ?>
                                <div class="pp-info-item"><div class="pp-info-lbl">العمر</div><div class="pp-info-val"><?php echo (int)$player["player_age"]; ?> سنة</div></div>
                            <?php endif; ?>
                            <div class="pp-info-item"><div class="pp-info-lbl">بداية الاشتراك</div><div class="pp-info-val"><?php echo pportFmtDate($player["subscription_start_date"]); ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">نهاية الاشتراك</div><div class="pp-info-val"><?php echo pportFmtDate($player["subscription_end_date"]); ?></div></div>
                            <?php if (count($trainingDays) > 0): ?>
                                <div class="pp-info-item"><div class="pp-info-lbl">أيام التدريب</div><div class="pp-info-val"><?php echo pportEsc(implode("، ", $trainingDays)); ?></div></div>
                            <?php endif; ?>
                            <?php if (count($trainingScheduleLabels) > 0): ?>
                                <div class="pp-info-item"><div class="pp-info-lbl">مواعيد التمرين</div><div class="pp-info-val"><?php echo pportEsc(implode("، ", $trainingScheduleLabels)); ?></div></div>
                            <?php elseif ($primaryTrainingTime !== ''): ?>
                                <div class="pp-info-item"><div class="pp-info-lbl">موعد التمرين</div><div class="pp-info-val" dir="ltr"><?php echo pportEsc(pportFmtTime($primaryTrainingTime)); ?></div></div>
                            <?php endif; ?>
                            <?php foreach (array_slice($groupEmergencyRows, 0, 2) as $emergencyRow): ?>
                                <div class="pp-info-item">
                                    <div class="pp-info-lbl">تعديل طارئ</div>
                                    <div class="pp-info-val">
                                        <?php echo pportEsc(formatScheduleExceptionDateLabel($emergencyRow["original_date"] ?? "")); ?>
                                        <?php if (!empty($emergencyRow["replacement_date"])): ?>
                                            → <?php echo pportEsc(formatScheduleExceptionDateLabel($emergencyRow["replacement_date"])); ?>
                                            (<?php echo pportEsc(formatScheduleExceptionTimeLabel($emergencyRow["replacement_start_time"] ?? "")); ?>)
                                        <?php else: ?>
                                            - إجازة طارئة
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="pp-info-item"><div class="pp-info-lbl">إجمالي التمارين</div><div class="pp-info-val"><?php echo (int)$player["total_trainings"]; ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">قيمة الاشتراك</div><div class="pp-info-val"><?php echo pportFmtCurrency($player["subscription_price"]); ?></div></div>
                            <div class="pp-info-item"><div class="pp-info-lbl">المبلغ المدفوع</div><div class="pp-info-val"><?php echo pportFmtCurrency($player["paid_amount"]); ?></div></div>
                        </div>
                    </div>

                <?php elseif ($activeSection === 'attendance'): ?>
                    <div class="pp-section">
                        <div class="pp-section-h">📅 سجل الحضور والغياب</div>
                        <div class="pp-stats" style="margin-bottom:16px;">
                            <div class="pp-stat"><span class="pp-stat-icon">✅</span><div class="pp-stat-val"><?php echo $presentCount; ?></div><div class="pp-stat-lbl">أيام الحضور</div></div>
                            <div class="pp-stat"><span class="pp-stat-icon">❌</span><div class="pp-stat-val"><?php echo $absentCount; ?></div><div class="pp-stat-lbl">أيام الغياب</div></div>
                        </div>
                        <?php if (count($attendanceRows) === 0): ?>
                            <div class="pp-empty"><span>📅</span>لا توجد سجلات حضور بعد.</div>
                        <?php else: ?>
                            <div class="pp-table-wrap">
                                <table class="pp-table">
                                    <thead><tr><th>التاريخ</th><th>الحالة</th><th>وقت الحضور</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($attendanceRows as $row): ?>
                                        <?php $b = pportAttBadge($row["attendance_status"]); ?>
                                        <tr>
                                            <td><?php echo pportFmtDate($row["attendance_date"]); ?></td>
                                            <td><span class="<?php echo $b[1]; ?>"><?php echo pportEsc($b[0]); ?></span></td>
                                            <td><?php echo pportFmtDateTime($row["attendance_at"]); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($activeSection === 'levels'): ?>
                    <div class="pp-section">
                        <div class="pp-section-h">🏆 مشوار الطالب - <?php echo pportEsc($player["game_name"] ?? ""); ?></div>
                        <?php if (count($groupLevelsList) === 0): ?>
                            <div class="pp-empty"><span>🏆</span>لا توجد مستويات معرّفة لهذه اللعبة.</div>
                        <?php else: ?>
                            <div class="pp-levels">
                                <?php $i = 1; foreach ($groupLevelsList as $levelRecord): ?>
                                    <?php $levelName = trim((string)($levelRecord["level_name"] ?? "")); ?>
                                    <?php $levelDetails = trim((string)($levelRecord["level_details"] ?? "")); ?>
                                    <?php $isActive = ($levelName === $currentLevel); ?>
                                    <div class="pp-level <?php echo $isActive ? 'active' : ''; ?>">
                                        <div class="pp-level-num"><?php echo $i; ?></div>
                                        <div>
                                            <div class="pp-level-name"><?php echo pportEsc($levelName); ?></div>
                                            <div class="pp-level-sub">
                                                <?php
                                                if ($levelDetails !== '') {
                                                    echo pportEsc($levelDetails);
                                                } elseif (!$isActive) {
                                                    echo 'مستوى باللعبة';
                                                } else {
                                                    echo pportEsc('—');
                                                }
                                                ?>
                                            </div>
                                            <?php if ($isActive): ?>
                                                <div class="pp-level-sub" style="margin-top:6px;">🌟 مستواك الحالي</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php $i++; endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($activeSection === 'password'): ?>
                    <div class="pp-section" style="max-width:500px; margin:0 auto;">
                        <div class="pp-section-h">🔑 تغيير كلمة السر</div>
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo pportEsc($csrfToken); ?>">
                            <input type="hidden" name="action" value="change_password">
                            <div class="pp-fg">
                                <label>كلمة السر الحالية</label>
                                <input type="password" name="current_password" required>
                            </div>
                            <div class="pp-fg">
                                <label>كلمة السر الجديدة (6 أحرف على الأقل)</label>
                                <input type="password" name="new_password" required minlength="6">
                            </div>
                            <div class="pp-fg">
                                <label>تأكيد كلمة السر الجديدة</label>
                                <input type="password" name="confirm_password" required minlength="6">
                            </div>
                            <button type="submit" class="pp-btn">💾 حفظ كلمة السر الجديدة</button>
                        </form>
                    </div>

                <?php elseif ($activeSection === 'store'): ?>
                    <div class="pp-section">
                        <div class="pp-section-h">🏪 المتجر</div>
                        <?php if (count($storeProducts) === 0): ?>
                            <div class="pp-empty"><span>🏪</span>لا توجد منتجات متاحة حالياً.</div>
                        <?php else: ?>
                            <div class="pp-store-grid">
                                <?php foreach ($storeProducts as $prod): ?>
                                    <div class="pp-product">
                                        <?php if (!empty($prod["image_path"])): ?>
                                            <img src="<?php echo pportEsc($prod["image_path"]); ?>" alt="" class="pp-product-img">
                                        <?php else: ?>
                                            <div class="pp-product-img" style="display:flex;align-items:center;justify-content:center;font-size:3rem;color:var(--text-soft);">📦</div>
                                        <?php endif; ?>
                                        <div class="pp-product-body">
                                            <div class="pp-product-name"><?php echo pportEsc($prod["product_name"]); ?></div>
                                            <div class="pp-product-price" style="font-size:.82rem;color:var(--text-soft);"><?php echo pportEsc((string)($prod["game_name"] ?? "—")); ?></div>
                                            <div class="pp-product-price"><?php echo pportFmtCurrency($prod["price"]); ?></div>
                                            <button type="button" class="pp-product-btn"
                                                onclick="ppOpenOrder(<?php echo (int)$prod["id"]; ?>, <?php echo htmlspecialchars(json_encode((string)$prod["product_name"], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>, <?php echo (float)$prod["price"]; ?>)">
                                                🛒 طلب المنتج
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="pp-section">
                        <div class="pp-section-h">📋 طلباتي السابقة</div>
                        <?php if (count($myOrders) === 0): ?>
                            <div class="pp-empty"><span>📋</span>لا توجد طلبات بعد.</div>
                        <?php else: ?>
                            <div class="pp-table-wrap">
                                <table class="pp-table">
                                    <thead><tr><th>المنتج</th><th>الكمية</th><th>الإجمالي</th><th>الحالة</th><th>رد الإدارة</th><th>تاريخ الطلب</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($myOrders as $o): ?>
                                        <?php $st = pportOrderStatus($o["status"]); ?>
                                        <tr>
                                            <td><?php echo pportEsc($o["product_name"]); ?></td>
                                            <td><?php echo (int)$o["quantity"]; ?></td>
                                            <td><?php echo pportFmtCurrency($o["total_price"]); ?></td>
                                            <td><span class="<?php echo $st[1]; ?>"><?php echo pportEsc($st[0]); ?></span></td>
                                            <td><?php echo $o["admin_response"] ? pportEsc($o["admin_response"]) : "—"; ?></td>
                                            <td><?php echo pportFmtDateTime($o["created_at"]); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<div class="pp-live-notice" id="ppLiveNotice" aria-live="polite" aria-atomic="true">
    <div class="pp-live-notice-h">
        <div class="pp-live-notice-title" id="ppLiveNoticeTitle">📣 إشعار جديد</div>
        <button type="button" class="pp-live-notice-close" id="ppLiveNoticeClose" aria-label="إغلاق">✕</button>
    </div>
    <div class="pp-live-notice-msg" id="ppLiveNoticeMessage"></div>
    <div class="pp-live-notice-actions">
        <a href="?section=home" class="pp-live-notice-btn">عرض الإشعارات</a>
    </div>
</div>

<div class="pp-modal-back" id="ppOrderModal">
    <div class="pp-modal">
        <div class="pp-modal-h">
            <span>🛒 إتمام طلب المنتج</span>
            <button type="button" class="pp-modal-close" onclick="ppCloseOrder()">✕</button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo pportEsc($csrfToken); ?>">
            <input type="hidden" name="action" value="place_order">
            <input type="hidden" name="product_id" id="ppOrderProductId" value="">
            <div class="pp-fg">
                <label>المنتج</label>
                <input type="text" id="ppOrderProductName" readonly>
            </div>
            <div class="pp-fg">
                <label>سعر الوحدة</label>
                <input type="text" id="ppOrderUnitPrice" readonly>
            </div>
            <div class="pp-fg">
                <label>الكمية</label>
                <input type="number" name="quantity" id="ppOrderQty" value="1" min="1" required>
            </div>
            <div class="pp-fg">
                <label>الإجمالي</label>
                <input type="text" id="ppOrderTotal" readonly>
            </div>
            <div class="pp-fg">
                <label>اسم المستلم</label>
                <input type="text" name="customer_name" value="<?php echo pportEsc($player["name"]); ?>" required>
            </div>
            <div class="pp-fg">
                <label>رقم الهاتف للتواصل</label>
                <input type="text" name="customer_phone" value="<?php echo pportEsc($player["phone"]); ?>" required>
            </div>
            <div class="pp-fg">
                <label>عنوان التوصيل</label>
                <textarea name="delivery_address" required placeholder="العنوان بالتفصيل"></textarea>
            </div>
            <div class="pp-modal-actions">
                <button type="submit" class="pp-btn">📨 إرسال الطلب</button>
                <button type="button" class="pp-btn-secondary" onclick="ppCloseOrder()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
window.__PORTAL_SESSION_GUARD__ = {
    key: "player-portal",
    mode: "protected",
    loginUrl: "player_portal_login.php",
    logoutUrl: "player_portal_logout.php"
};
</script>
<script src="assets/js/portal_session_guard.js"></script>
<script src="assets/js/script.js"></script>
<script>
(function() {
    var MOBILE_BREAKPOINT = '900px';
    var burger = document.getElementById('ppBurger');
    var sidebar = document.getElementById('ppSidebar');
    var sidebarOverlay = document.getElementById('ppSidebarOverlay');
    var sidebarLinks = sidebar ? sidebar.querySelectorAll('.pp-side-item') : [];
    var mobileMq = window.matchMedia('(max-width: ' + MOBILE_BREAKPOINT + ')');

    function openMobileSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('collapsed');
        sidebar.classList.add('show');
        sidebar.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pp-mobile-menu-open');
        if (sidebarOverlay) sidebarOverlay.classList.add('show');
        if (burger) burger.setAttribute('aria-expanded', 'true');
    }

    function closeMobileSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('show');
        document.body.classList.remove('pp-mobile-menu-open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        if (burger) burger.setAttribute('aria-expanded', 'false');
        if (mobileMq.matches) {
            sidebar.setAttribute('aria-hidden', 'true');
        }
    }

    function syncDesktopSidebarState() {
        if (!sidebar) return;
        sidebar.classList.remove('show');
        sidebar.removeAttribute('aria-hidden');
        document.body.classList.remove('pp-mobile-menu-open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        if (burger) burger.setAttribute('aria-expanded', 'false');
    }

    function syncSidebarMode() {
        if (!sidebar) return;
        if (mobileMq.matches) {
            sidebar.classList.remove('collapsed');
            closeMobileSidebar();
        } else {
            syncDesktopSidebarState();
        }
    }

    if (burger && sidebar) {
        if (mobileMq.matches) {
            sidebar.setAttribute('aria-hidden', 'true');
        } else {
            sidebar.removeAttribute('aria-hidden');
        }
        burger.setAttribute('aria-expanded', 'false');
        burger.addEventListener('click', function() {
            if (mobileMq.matches) {
                if (sidebar.classList.contains('show')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                syncDesktopSidebarState();
                burger.setAttribute('aria-expanded', sidebar.classList.toggle('collapsed') ? 'false' : 'true');
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeMobileSidebar);
    }

    if (sidebarLinks.length > 0) {
        sidebarLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                if (mobileMq.matches) {
                    closeMobileSidebar();
                }
            });
        });
    }

    mobileMq.addEventListener('change', syncSidebarMode);

    var barcodeEl = document.getElementById('ppBarcode');
    if (barcodeEl && window.JsBarcode) {
        var code = <?php echo json_encode((string)$player["barcode"], JSON_UNESCAPED_UNICODE); ?>;
        if (code && code.length > 0) {
            try {
                JsBarcode(barcodeEl, code, {
                    format: 'CODE128',
                    lineColor: '#0f172a',
                    background: '#ffffff',
                    width: 2.4,
                    height: 90,
                    displayValue: false,
                    margin: 6
                });
            } catch (e) {
                barcodeEl.outerHTML = '<div style="padding:14px;color:#dc2626;font-weight:800;">تعذر إنشاء صورة الباركود</div>';
            }
        } else {
            barcodeEl.outerHTML = '<div style="padding:14px;color:#64748b;font-weight:800;">لا يوجد باركود مسجل لهذا اللاعب</div>';
        }
    }

    var latestNotification = <?php echo json_encode($latestNotification ? [
        'id' => (int)$latestNotification['id'],
        'title' => (string)$latestNotification['title'],
        'message' => (string)$latestNotification['message'],
    ] : null, JSON_UNESCAPED_UNICODE); ?>;
    window.__PORTAL_LIVE_NOTIFICATIONS__ = {
        endpoint: 'player_portal_notifications_feed.php',
        sessionKey: 'player:<?php echo (int)$playerId; ?>',
        latestNotification: latestNotification,
        storageKey: 'player-portal-last-notification-<?php echo (int)$playerId; ?>',
        noticeLinkHref: '?section=home',
        notice: {
            containerId: 'ppLiveNotice',
            titleId: 'ppLiveNoticeTitle',
            messageId: 'ppLiveNoticeMessage',
            closeId: 'ppLiveNoticeClose',
            actionSelector: '.pp-live-notice-btn'
        },
        showInitialLatest: true,
        pollIntervalMs: 10000,
        reloadDelayMs: 1200
    };
})();

function ppOpenOrder(productId, productName, unitPrice) {
    document.getElementById('ppOrderProductId').value = productId;
    document.getElementById('ppOrderProductName').value = productName;
    document.getElementById('ppOrderUnitPrice').value = unitPrice.toFixed(2) + ' ج.م';
    document.getElementById('ppOrderQty').value = 1;
    document.getElementById('ppOrderTotal').value = unitPrice.toFixed(2) + ' ج.م';
    var qty = document.getElementById('ppOrderQty');
    qty.oninput = function() {
        var q = Math.max(1, parseInt(qty.value) || 1);
        document.getElementById('ppOrderTotal').value = (unitPrice * q).toFixed(2) + ' ج.م';
    };
    document.getElementById('ppOrderModal').classList.add('show');
}
function ppCloseOrder() {
    document.getElementById('ppOrderModal').classList.remove('show');
}
</script>
<script src="assets/js/portal_live_notifications.js"></script>
</body>
</html>
