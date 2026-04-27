<?php
date_default_timezone_set("Africa/Cairo");

require_once "portal_session.php";
startPortalSession("admin");

if (!isset($_SESSION["admin_portal_logged_in"]) || $_SESSION["admin_portal_logged_in"] !== true) {
    header("Location: admin_portal_login.php");
    exit;
}

require_once "config.php";
require_once "salary_collection_helpers.php";

$adminId = (int)$_SESSION["admin_portal_id"];
$adminGameId = (int)$_SESSION["admin_portal_game_id"];
$siteName = (string)($_SESSION["admin_portal_site_name"] ?? "أكاديمية رياضية");
$siteLogo = (string)($_SESSION["admin_portal_site_logo"] ?? "assets/images/logo.png");

$admin = null;
try {
    $adminStmt = $pdo->prepare(
        "SELECT a.id, a.name, a.phone, a.barcode, a.salary, a.game_id,
                a.attendance_time, a.departure_time,
                g.name AS game_name
         FROM admins a
         LEFT JOIN games g ON g.id = a.game_id
         WHERE a.id = ?
         LIMIT 1"
    );
    $adminStmt->execute([$adminId]);
    $admin = $adminStmt->fetch();
} catch (PDOException $ignored) {}

if (!$admin) {
    header("Location: admin_portal_logout.php");
    exit;
}

function adminPortalFormatTime($time) {
    $time = substr((string)$time, 0, 5);
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) return "—";
    $hour = (int)$m[1];
    $min = $m[2];
    $period = $hour >= 12 ? "م" : "ص";
    $display = $hour % 12;
    if ($display === 0) $display = 12;
    return str_pad((string)$display, 2, "0", STR_PAD_LEFT) . ":" . $min . " " . $period;
}

function adminPortalFormatDate($dateStr) {
    $dateStr = trim((string)$dateStr);
    if ($dateStr === "" || $dateStr === "0000-00-00") return "—";
    try {
        $dt = new DateTimeImmutable($dateStr, new DateTimeZone("Africa/Cairo"));
        return $dt->format("Y/m/d");
    } catch (Exception $ignored) {
        return "—";
    }
}

function adminPortalFormatDatetime($datetimeStr) {
    $datetimeStr = trim((string)$datetimeStr);
    if ($datetimeStr === "" || $datetimeStr === "0000-00-00 00:00:00") return "—";
    try {
        $dt = new DateTimeImmutable($datetimeStr, new DateTimeZone("Africa/Cairo"));
        $hour = (int)$dt->format("G");
        $min = $dt->format("i");
        $period = $hour >= 12 ? "م" : "ص";
        $display = $hour % 12;
        if ($display === 0) $display = 12;
        return $dt->format("Y/m/d") . " - " . str_pad((string)$display, 2, "0", STR_PAD_LEFT) . ":" . $min . " " . $period;
    } catch (Exception $ignored) {
        return "—";
    }
}

function adminPortalFormatCurrency($amount) {
    return number_format((float)$amount, 2) . " ج.م";
}

function adminPortalFormatAttendanceStatus($status) {
    $map = [
        "present" => ["label" => "حاضر", "class" => "badge-success"],
        "absent" => ["label" => "غائب", "class" => "badge-danger"],
        "late" => ["label" => "متأخر", "class" => "badge-warning"],
        "early_departure" => ["label" => "انصراف مبكر", "class" => "badge-warning"],
        "late_and_early" => ["label" => "تأخر وانصراف مبكر", "class" => "badge-danger"],
        "day_off" => ["label" => "إجازة", "class" => "badge-info"],
        "overtime" => ["label" => "وقت إضافي", "class" => "badge-purple"],
    ];
    $status = (string)$status;
    return $map[$status] ?? ["label" => ($status !== "" ? $status : "—"), "class" => "badge-muted"];
}

$attendanceRows = [];
try {
    $attStmt = $pdo->prepare(
        "SELECT attendance_date, scheduled_attendance_time, scheduled_departure_time,
                attendance_at, attendance_status, attendance_minutes_late,
                departure_at, departure_status, departure_minutes_early,
                overtime_minutes, day_status
         FROM admin_attendance
         WHERE admin_id = ?
         ORDER BY attendance_date DESC
         LIMIT 60"
    );
    $attStmt->execute([$adminId]);
    $attendanceRows = $attStmt->fetchAll();
} catch (PDOException $ignored) {}

$loanRows = [];
$loansTotal = 0.00;
try {
    $loanStmt = $pdo->prepare(
        "SELECT amount, loan_date, created_at
         FROM admin_loans
         WHERE admin_id = ?
         ORDER BY loan_date DESC, id DESC
         LIMIT 50"
    );
    $loanStmt->execute([$adminId]);
    $loanRows = $loanStmt->fetchAll();
    foreach ($loanRows as $lr) {
        $loansTotal += (float)$lr["amount"];
    }
} catch (PDOException $ignored) {}

$deductionRows = [];
$deductionsTotal = 0.00;
try {
    $dedStmt = $pdo->prepare(
        "SELECT amount, reason, deduction_date, created_at
         FROM admin_deductions
         WHERE admin_id = ?
         ORDER BY deduction_date DESC, id DESC
         LIMIT 50"
    );
    $dedStmt->execute([$adminId]);
    $deductionRows = $dedStmt->fetchAll();
    foreach ($deductionRows as $dr) {
        $deductionsTotal += (float)$dr["amount"];
    }
} catch (PDOException $ignored) {}

$collectionRows = [];
try {
    $colStmt = $pdo->prepare(
        "SELECT salary_month, base_salary, loans_total, deductions_total,
                net_salary, actual_paid_amount, attendance_days, absent_days,
                late_days, early_departure_days, overtime_days, paid_at
         FROM admin_salary_payments
         WHERE admin_id = ?
         ORDER BY salary_month DESC
         LIMIT 24"
    );
    $colStmt->execute([$adminId]);
    $collectionRows = $colStmt->fetchAll();
} catch (PDOException $ignored) {}

$notificationRows = [];
try {
    $notifStmt = $pdo->prepare(
        "SELECT id, title, message, notification_type, priority_level, display_date
         FROM admin_notifications
         WHERE game_id = ? AND visibility_status = 'visible'
         ORDER BY display_date DESC, id DESC
         LIMIT 30"
    );
    $notifStmt->execute([$adminGameId]);
    $notificationRows = $notifStmt->fetchAll();
} catch (PDOException $ignored) {}

$latestNotification = $notificationRows[0] ?? null;

$attendanceSummary = payrollSummarizeAttendanceRows($attendanceRows);
$attendancePresent = (int)$attendanceSummary["attendance_days"];
$attendanceAbsent = (int)$attendanceSummary["absent_days"];
$attendanceLate = (int)$attendanceSummary["late_days"];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Language" content="ar">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>بوابة الإداريين | <?php echo htmlspecialchars($siteName, ENT_QUOTES, "UTF-8"); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
.portal-root {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
.portal-topbar {
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 64px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07);
}
.portal-topbar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.portal-topbar-logo {
    width: 40px;
    height: 40px;
    object-fit: contain;
    border-radius: 8px;
}
.portal-topbar-name {
    font-size: 1rem;
    font-weight: 800;
    color: var(--text);
}
.portal-topbar-badge {
    font-size: 0.75rem;
    font-weight: 700;
    color: #0ea5e9;
    background: rgba(14, 165, 233, 0.1);
    border-radius: 999px;
    padding: 2px 10px;
}
.portal-topbar-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.portal-topbar-user {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-soft);
    display: flex;
    align-items: center;
    gap: 6px;
}
.portal-logout-btn {
    padding: 8px 18px;
    background: rgba(220, 38, 38, 0.1);
    color: var(--danger);
    border: 1px solid var(--danger);
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.portal-logout-btn:hover {
    background: rgba(220, 38, 38, 0.18);
}
.portal-main {
    flex: 1;
    padding: 28px 24px;
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
}
.portal-welcome-card {
    background: linear-gradient(135deg, #0ea5e9, #7c3aed);
    border-radius: var(--card-radius);
    padding: 28px 32px;
    color: #fff;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}
.portal-welcome-text h2 {
    font-size: 1.4rem;
    font-weight: 800;
    margin-bottom: 4px;
}
.portal-welcome-text p {
    font-size: 0.9rem;
    opacity: 0.85;
    font-weight: 600;
}
.portal-welcome-avatar {
    width: 64px;
    height: 64px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    flex-shrink: 0;
}
.portal-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.portal-stat-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px 16px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.portal-stat-icon {
    font-size: 1.8rem;
    margin-bottom: 8px;
    display: block;
}
.portal-stat-value {
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 4px;
}
.portal-stat-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-soft);
}
.portal-tabs {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 6px;
}
.portal-tab-btn {
    padding: 9px 18px;
    border: none;
    border-radius: 10px;
    background: transparent;
    color: var(--text-soft);
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.portal-tab-btn:hover {
    background: rgba(14, 165, 233, 0.07);
    color: #0ea5e9;
}
.portal-tab-btn.active {
    background: #0ea5e9;
    color: #fff;
    box-shadow: 0 2px 8px rgba(14, 165, 233, 0.3);
}
.portal-tab-pane {
    display: none;
}
.portal-tab-pane.active {
    display: block;
}
.portal-section-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: var(--card-radius);
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.portal-section-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.portal-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}
.portal-info-item {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
}
.portal-info-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text-soft);
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.portal-info-value {
    font-size: 1rem;
    font-weight: 800;
    color: var(--text);
}
.portal-table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid var(--border);
}
.portal-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.portal-table thead th {
    background: var(--bg);
    color: var(--text-soft);
    font-weight: 700;
    font-size: 0.8rem;
    padding: 12px 14px;
    text-align: right;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.portal-table tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background 0.15s;
}
.portal-table tbody tr:last-child {
    border-bottom: none;
}
.portal-table tbody tr:hover {
    background: rgba(14, 165, 233, 0.04);
}
.portal-table tbody td {
    padding: 11px 14px;
    color: var(--text);
    font-weight: 600;
    vertical-align: middle;
}
.badge-success {
    background: rgba(22, 163, 74, 0.12);
    color: var(--success);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}
.badge-danger {
    background: rgba(220, 38, 38, 0.1);
    color: var(--danger);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}
.badge-warning {
    background: rgba(234, 88, 12, 0.1);
    color: var(--warning);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}
.badge-info {
    background: rgba(14, 165, 233, 0.1);
    color: #0ea5e9;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}
.badge-purple {
    background: rgba(124, 58, 237, 0.1);
    color: var(--purple);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}
.badge-muted {
    background: rgba(100, 116, 139, 0.1);
    color: var(--text-soft);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}
.portal-empty {
    text-align: center;
    color: var(--text-soft);
    padding: 48px 16px;
    font-weight: 600;
    font-size: 0.95rem;
}
.portal-empty span {
    display: block;
    font-size: 2.5rem;
    margin-bottom: 12px;
}
.notif-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 12px;
}
.notif-card:last-child {
    margin-bottom: 0;
}
.notif-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.notif-title {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--text);
    flex: 1;
}
.notif-meta {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.notif-priority-urgent {
    background: rgba(220, 38, 38, 0.1);
    color: var(--danger);
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.73rem;
    font-weight: 700;
}
.notif-priority-important {
    background: rgba(234, 88, 12, 0.1);
    color: var(--warning);
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.73rem;
    font-weight: 700;
}
.notif-priority-normal {
    background: rgba(100, 116, 139, 0.1);
    color: var(--text-soft);
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.73rem;
    font-weight: 700;
}
.notif-type {
    background: rgba(14, 165, 233, 0.08);
    color: #0ea5e9;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.73rem;
    font-weight: 700;
}
.notif-date {
    font-size: 0.73rem;
    color: var(--text-soft);
    font-weight: 600;
}
.notif-message {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text);
    line-height: 1.6;
    white-space: pre-line;
}
.portal-topbar-theme {
    display: flex;
    align-items: center;
}
@media (max-width: 600px) {
    .portal-topbar {
        padding: 0 14px;
    }
    .portal-main {
        padding: 16px 12px;
    }
    .portal-welcome-card {
        padding: 20px;
    }
    .portal-welcome-text h2 {
        font-size: 1.1rem;
    }
    .portal-tab-btn {
        padding: 8px 12px;
        font-size: 0.82rem;
    }
    .portal-section-card {
        padding: 16px;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
</head>
<body>

<div class="portal-root">
    <div class="portal-topbar">
        <div class="portal-topbar-brand">
            <img src="<?php echo htmlspecialchars($siteLogo, ENT_QUOTES, "UTF-8"); ?>" alt="شعار" class="portal-topbar-logo">
            <div>
                <div class="portal-topbar-name"><?php echo htmlspecialchars($siteName, ENT_QUOTES, "UTF-8"); ?></div>
                <span class="portal-topbar-badge">🛡️ بوابة الإداريين</span>
            </div>
        </div>
        <div class="portal-topbar-actions">
            <div class="portal-topbar-user">
                <span>👤</span>
                <span><?php echo htmlspecialchars($admin["name"], ENT_QUOTES, "UTF-8"); ?></span>
            </div>
            <div class="portal-topbar-theme">
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">🌙</span>
                    </span>
                </label>
            </div>
            <a href="admin_portal_logout.php" class="portal-logout-btn">🚪 خروج</a>
        </div>
    </div>

    <div class="portal-main">
        <div class="portal-welcome-card">
            <div class="portal-welcome-text">
                <h2>أهلاً، <?php echo htmlspecialchars($admin["name"], ENT_QUOTES, "UTF-8"); ?> 👋</h2>
                <p>
                    <?php echo htmlspecialchars((string)($admin["game_name"] ?? ""), ENT_QUOTES, "UTF-8"); ?>
                    &bull; آخر تحديث: <?php echo htmlspecialchars(adminPortalFormatDatetime(date("Y-m-d H:i:s")), ENT_QUOTES, "UTF-8"); ?>
                </p>
            </div>
            <div class="portal-welcome-avatar">🛡️</div>
        </div>

        <div class="portal-stats-grid">
            <div class="portal-stat-card">
                <span class="portal-stat-icon">💰</span>
                <div class="portal-stat-value"><?php echo adminPortalFormatCurrency($admin["salary"]); ?></div>
                <div class="portal-stat-label">الراتب الأساسي</div>
            </div>
            <div class="portal-stat-card">
                <span class="portal-stat-icon">✅</span>
                <div class="portal-stat-value"><?php echo $attendancePresent; ?></div>
                <div class="portal-stat-label">أيام الحضور</div>
            </div>
            <div class="portal-stat-card">
                <span class="portal-stat-icon">❌</span>
                <div class="portal-stat-value"><?php echo $attendanceAbsent; ?></div>
                <div class="portal-stat-label">أيام الغياب</div>
            </div>
            <div class="portal-stat-card">
                <span class="portal-stat-icon">⏰</span>
                <div class="portal-stat-value"><?php echo $attendanceLate; ?></div>
                <div class="portal-stat-label">أيام التأخر</div>
            </div>
            <div class="portal-stat-card">
                <span class="portal-stat-icon">💳</span>
                <div class="portal-stat-value"><?php echo adminPortalFormatCurrency($loansTotal); ?></div>
                <div class="portal-stat-label">إجمالي السلف</div>
            </div>
            <div class="portal-stat-card">
                <span class="portal-stat-icon">📉</span>
                <div class="portal-stat-value"><?php echo adminPortalFormatCurrency($deductionsTotal); ?></div>
                <div class="portal-stat-label">إجمالي الخصومات</div>
            </div>
        </div>

        <div class="portal-tabs">
            <button class="portal-tab-btn active" data-tab="info">👤 البيانات الشخصية</button>
            <button class="portal-tab-btn" data-tab="attendance">📅 الحضور والانصراف</button>
            <button class="portal-tab-btn" data-tab="deductions">📉 الخصومات</button>
            <button class="portal-tab-btn" data-tab="loans">💳 السلف</button>
            <button class="portal-tab-btn" data-tab="collections">🧾 تحصيل الراتب</button>
            <button class="portal-tab-btn" data-tab="notifications">🔔 الإشعارات</button>
        </div>

        <div id="tab-info" class="portal-tab-pane active">
            <div class="portal-section-card">
                <div class="portal-section-title">👤 البيانات الشخصية</div>
                <div class="portal-info-grid">
                    <div class="portal-info-item">
                        <div class="portal-info-label">الاسم</div>
                        <div class="portal-info-value"><?php echo htmlspecialchars($admin["name"], ENT_QUOTES, "UTF-8"); ?></div>
                    </div>
                    <div class="portal-info-item">
                        <div class="portal-info-label">رقم الهاتف</div>
                        <div class="portal-info-value"><?php echo htmlspecialchars($admin["phone"], ENT_QUOTES, "UTF-8"); ?></div>
                    </div>
                    <div class="portal-info-item" style="grid-column:1/-1; text-align:center; background:#ffffff;">
                        <div class="portal-info-label" style="text-align:center;">الباركود</div>
                        <div class="portal-info-value" style="display:flex; flex-direction:column; align-items:center; gap:10px; padding:14px 6px;">
                            <svg id="adminPortalBarcode" style="max-width:100%; height:auto;"></svg>
                            <div style="display:inline-flex; align-items:center; gap:10px; padding:8px 16px; background:#f1f5f9; color:#0f172a; border-radius:10px; font-weight:800; letter-spacing:.04em;">
                                <span>📟</span>
                                <span dir="ltr"><?php echo htmlspecialchars((string)($admin["barcode"] !== "" ? $admin["barcode"] : "—"), ENT_QUOTES, "UTF-8"); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="portal-info-item">
                        <div class="portal-info-label">اللعبة / النشاط</div>
                        <div class="portal-info-value"><?php echo htmlspecialchars((string)($admin["game_name"] ?? "—"), ENT_QUOTES, "UTF-8"); ?></div>
                    </div>
                    <div class="portal-info-item">
                        <div class="portal-info-label">الراتب الأساسي</div>
                        <div class="portal-info-value"><?php echo adminPortalFormatCurrency($admin["salary"]); ?></div>
                    </div>
                    <div class="portal-info-item">
                        <div class="portal-info-label">وقت الحضور المقرر</div>
                        <div class="portal-info-value"><?php echo adminPortalFormatTime($admin["attendance_time"]); ?></div>
                    </div>
                    <div class="portal-info-item">
                        <div class="portal-info-label">وقت الانصراف المقرر</div>
                        <div class="portal-info-value"><?php echo adminPortalFormatTime($admin["departure_time"]); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-attendance" class="portal-tab-pane">
            <div class="portal-section-card">
                <div class="portal-section-title">📅 سجل الحضور والانصراف</div>
                <?php if (count($attendanceRows) === 0): ?>
                    <div class="portal-empty"><span>📋</span>لا توجد سجلات حضور حتى الآن.</div>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>الحضور المقرر</th>
                                    <th>وقت الحضور الفعلي</th>
                                    <th>الانصراف المقرر</th>
                                    <th>وقت الانصراف الفعلي</th>
                                    <th>الحالة</th>
                                    <th>تأخر (دقيقة)</th>
                                    <th>مبكر (دقيقة)</th>
                                    <th>إضافي (دقيقة)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceRows as $ar): ?>
                                    <?php $statusInfo = adminPortalFormatAttendanceStatus($ar["day_status"]); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDate($ar["attendance_date"]), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatTime($ar["scheduled_attendance_time"]), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDatetime((string)($ar["attendance_at"] ?? "")), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatTime($ar["scheduled_departure_time"]), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDatetime((string)($ar["departure_at"] ?? "")), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><span class="<?php echo htmlspecialchars($statusInfo["class"], ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($statusInfo["label"], ENT_QUOTES, "UTF-8"); ?></span></td>
                                        <td><?php echo (int)($ar["attendance_minutes_late"] ?? 0) > 0 ? (int)$ar["attendance_minutes_late"] : "—"; ?></td>
                                        <td><?php echo (int)($ar["departure_minutes_early"] ?? 0) > 0 ? (int)$ar["departure_minutes_early"] : "—"; ?></td>
                                        <td><?php echo (int)($ar["overtime_minutes"] ?? 0) > 0 ? (int)$ar["overtime_minutes"] : "—"; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-deductions" class="portal-tab-pane">
            <div class="portal-section-card">
                <div class="portal-section-title">📉 سجل الخصومات</div>
                <?php if (count($deductionRows) === 0): ?>
                    <div class="portal-empty"><span>📋</span>لا توجد خصومات مسجلة.</div>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>المبلغ</th>
                                    <th>السبب</th>
                                    <th>تاريخ التسجيل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deductionRows as $dr): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDate($dr["deduction_date"]), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><span class="badge-danger"><?php echo htmlspecialchars(adminPortalFormatCurrency($dr["amount"]), ENT_QUOTES, "UTF-8"); ?></span></td>
                                        <td><?php echo htmlspecialchars((string)($dr["reason"] ?? "—"), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDatetime((string)($dr["created_at"] ?? "")), ENT_QUOTES, "UTF-8"); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:16px; text-align:left; font-size:0.9rem; font-weight:800; color:var(--danger);">
                        الإجمالي: <?php echo adminPortalFormatCurrency($deductionsTotal); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-loans" class="portal-tab-pane">
            <div class="portal-section-card">
                <div class="portal-section-title">💳 سجل السلف</div>
                <?php if (count($loanRows) === 0): ?>
                    <div class="portal-empty"><span>📋</span>لا توجد سلف مسجلة.</div>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>تاريخ السلفة</th>
                                    <th>المبلغ</th>
                                    <th>تاريخ التسجيل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loanRows as $lr): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDate($lr["loan_date"]), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><span class="badge-warning"><?php echo htmlspecialchars(adminPortalFormatCurrency($lr["amount"]), ENT_QUOTES, "UTF-8"); ?></span></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDatetime((string)($lr["created_at"] ?? "")), ENT_QUOTES, "UTF-8"); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:16px; text-align:left; font-size:0.9rem; font-weight:800; color:var(--warning);">
                        الإجمالي: <?php echo adminPortalFormatCurrency($loansTotal); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-collections" class="portal-tab-pane">
            <div class="portal-section-card">
                <div class="portal-section-title">🧾 سجل تحصيل الراتب</div>
                <?php if (count($collectionRows) === 0): ?>
                    <div class="portal-empty"><span>📋</span>لا توجد سجلات تحصيل راتب حتى الآن.</div>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>الشهر</th>
                                    <th>الراتب الأساسي</th>
                                    <th>إجمالي السلف</th>
                                    <th>إجمالي الخصومات</th>
                                    <th>صافي الراتب</th>
                                    <th>المبلغ المدفوع</th>
                                    <th>أيام حضور</th>
                                    <th>أيام غياب</th>
                                    <th>تاريخ الصرف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($collectionRows as $cr): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDate((string)($cr["salary_month"] ?? "")), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatCurrency($cr["base_salary"]), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td><span class="badge-warning"><?php echo htmlspecialchars(adminPortalFormatCurrency($cr["loans_total"]), ENT_QUOTES, "UTF-8"); ?></span></td>
                                        <td><span class="badge-danger"><?php echo htmlspecialchars(adminPortalFormatCurrency($cr["deductions_total"]), ENT_QUOTES, "UTF-8"); ?></span></td>
                                        <td><span class="badge-success"><?php echo htmlspecialchars(adminPortalFormatCurrency($cr["net_salary"]), ENT_QUOTES, "UTF-8"); ?></span></td>
                                        <td>
                                            <?php
                                            $paid = $cr["actual_paid_amount"] ?? null;
                                            echo $paid !== null
                                                ? htmlspecialchars(adminPortalFormatCurrency($paid), ENT_QUOTES, "UTF-8")
                                                : "—";
                                            ?>
                                        </td>
                                        <td><?php echo (int)($cr["attendance_days"] ?? 0); ?></td>
                                        <td><?php echo (int)($cr["absent_days"] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars(adminPortalFormatDatetime((string)($cr["paid_at"] ?? "")), ENT_QUOTES, "UTF-8"); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-notifications" class="portal-tab-pane">
            <div class="portal-section-card">
                <div class="portal-section-title">🔔 الإشعارات</div>
                <?php if (count($notificationRows) === 0): ?>
                    <div class="portal-empty"><span>🔔</span>لا توجد إشعارات حالياً.</div>
                <?php else: ?>
                    <?php
                    $notifTypeLabels = ["general" => "عام", "reminder" => "تذكير", "alert" => "تنبيه", "administrative" => "إداري"];
                    $notifPriorityClasses = ["urgent" => "notif-priority-urgent", "important" => "notif-priority-important", "normal" => "notif-priority-normal"];
                    $notifPriorityLabels = ["urgent" => "🚨 عاجل", "important" => "⚠️ مهم", "normal" => "عادي"];
                    foreach ($notificationRows as $nr):
                        $pClass = $notifPriorityClasses[$nr["priority_level"]] ?? "notif-priority-normal";
                        $pLabel = $notifPriorityLabels[$nr["priority_level"]] ?? "عادي";
                        $tLabel = $notifTypeLabels[$nr["notification_type"]] ?? $nr["notification_type"];
                    ?>
                        <div class="notif-card">
                            <div class="notif-header">
                                <div class="notif-title"><?php echo htmlspecialchars($nr["title"], ENT_QUOTES, "UTF-8"); ?></div>
                                <div class="notif-meta">
                                    <span class="<?php echo htmlspecialchars($pClass, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($pLabel, ENT_QUOTES, "UTF-8"); ?></span>
                                    <span class="notif-type"><?php echo htmlspecialchars($tLabel, ENT_QUOTES, "UTF-8"); ?></span>
                                    <span class="notif-date"><?php echo htmlspecialchars(adminPortalFormatDate($nr["display_date"]), ENT_QUOTES, "UTF-8"); ?></span>
                                </div>
                            </div>
                            <div class="notif-message"><?php echo htmlspecialchars($nr["message"], ENT_QUOTES, "UTF-8"); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var tabBtns = document.querySelectorAll(".portal-tab-btn");
    var tabPanes = document.querySelectorAll(".portal-tab-pane");
    var tabStateKey = "admin-portal-active-tab-<?php echo (int)$adminId; ?>";

    function activateTab(target) {
        if (!target) return;
        tabBtns.forEach(function (b) { b.classList.remove("active"); });
        tabPanes.forEach(function (p) { p.classList.remove("active"); });
        var nextBtn = document.querySelector('.portal-tab-btn[data-tab="' + target + '"]');
        var nextPane = document.getElementById("tab-" + target);
        if (!nextBtn || !nextPane) return;
        nextBtn.classList.add("active");
        nextPane.classList.add("active");
        try {
            sessionStorage.setItem(tabStateKey, target);
        } catch (e) {}
    }

    tabBtns.forEach(function (btn) {
        btn.addEventListener("click", function () {
            activateTab(btn.getAttribute("data-tab"));
        });
    });

    try {
        var activeBtn = document.querySelector(".portal-tab-btn.active");
        activateTab(sessionStorage.getItem(tabStateKey) || (activeBtn ? activeBtn.getAttribute("data-tab") : "") || "home");
    } catch (e) {}
})();

(function () {
    var el = document.getElementById("adminPortalBarcode");
    if (el && window.JsBarcode) {
        var code = <?php echo json_encode((string)($admin["barcode"] ?? ""), JSON_UNESCAPED_UNICODE); ?>;
        if (code && code.length > 0) {
            try {
                JsBarcode(el, code, { format: "CODE128", lineColor: "#0f172a", background: "#ffffff", width: 2.4, height: 90, displayValue: false, margin: 6 });
            } catch (e) {
                el.outerHTML = '<div style="color:#dc2626;font-weight:800;">تعذر إنشاء صورة الباركود</div>';
            }
        } else {
            el.outerHTML = '<div style="color:#64748b;font-weight:800;">لا يوجد باركود مسجل</div>';
        }
    }

    var latestNotification = <?php echo json_encode($latestNotification ? [
        "id" => (int)$latestNotification["id"],
        "title" => (string)$latestNotification["title"],
        "message" => (string)$latestNotification["message"],
    ] : null, JSON_UNESCAPED_UNICODE); ?>;
    window.__PORTAL_LIVE_NOTIFICATIONS__ = {
        endpoint: "admin_portal_notifications_feed.php",
        sessionKey: "admin:<?php echo (int)$adminId; ?>",
        latestNotification: latestNotification,
        storageKey: "admin-portal-last-notification-<?php echo (int)$adminId; ?>",
        notificationTab: "notifications",
        showInitialLatest: true,
        pollIntervalMs: 10000,
        reloadDelayMs: 1200
    };
})();
</script>
<script src="assets/js/portal_live_notifications.js"></script>
<script>
window.__PORTAL_SESSION_GUARD__ = {
    key: "admin-portal",
    mode: "protected",
    loginUrl: "admin_portal_login.php",
    logoutUrl: "admin_portal_logout.php"
};
</script>
<script src="assets/js/portal_session_guard.js"></script>
<script src="assets/js/script.js"></script>
</body>
</html>
