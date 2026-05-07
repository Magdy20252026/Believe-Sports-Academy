<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

date_default_timezone_set("Africa/Cairo");

requireAuthenticatedUser();
requireMenuAccess("statistics");

$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings LIMIT 1");
$settings = $settingsStmt ? ($settingsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");

$egyptNow = new DateTime("now", new DateTimeZone("Africa/Cairo"));
$egyptDateTimeLabel = formatEgyptDateTimeForDisplay($egyptNow, "");

$tab = $_GET["tab"] ?? "daily";
if (!in_array($tab, ["daily", "weekly", "monthly"], true)) {
    $tab = "daily";
}

$today = $egyptNow->format("Y-m-d");
$currentWeek = $egyptNow->format("Y-\WW");
$currentMonth = $egyptNow->format("Y-m");

$selectedDay = $_GET["day"] ?? $today;
$selectedWeek = $_GET["week"] ?? $currentWeek;
$selectedMonth = $_GET["month"] ?? $currentMonth;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay)) {
    $selectedDay = $today;
}
if (!preg_match('/^\d{4}-W\d{2}$/', $selectedWeek)) {
    $selectedWeek = $currentWeek;
}
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = $currentMonth;
}

if ($tab === "daily") {
    $dateFrom = $selectedDay;
    $dateTo = $selectedDay;
    $salaryMonthStr = substr($selectedDay, 0, 7);
    $periodLabel = $selectedDay;
} elseif ($tab === "weekly") {
    [$wYear, $wWeek] = explode("-W", $selectedWeek);
    $dtW = new DateTime();
    $dtW->setISODate((int)$wYear, (int)$wWeek);
    $dateFrom = $dtW->format("Y-m-d");
    $dtW->modify("+6 days");
    $dateTo = $dtW->format("Y-m-d");
    $salaryMonthStr = substr($dateFrom, 0, 7);
    $periodLabel = $dateFrom . " — " . $dateTo;
} else {
    $dateFrom = $selectedMonth . "-01";
    $dateTo = date("Y-m-t", strtotime($dateFrom));
    $salaryMonthStr = $selectedMonth;
    $periodLabel = $selectedMonth;
}

function statsFmt($amount)
{
    return number_format((float)$amount, 2, ".", ",") . " ج.م";
}

$newPlayersRows = [];
$singleRows = [];
$renewalRows = [];
$salesRows = [];
$adminSalaryRows = [];
$trainerSalaryRows = [];
$expensesRows = [];

if ($currentGameId > 0) {
    try {
        $st = $pdo->prepare(
            "SELECT psh.id, psh.player_name, psh.paid_amount, psh.academy_amount, psh.issue_date, psh.subscription_price
             FROM player_subscription_history psh
             WHERE psh.game_id = ? AND psh.source_action = 'save' AND psh.issue_date BETWEEN ? AND ?
             ORDER BY psh.issue_date DESC"
        );
        $st->execute([$currentGameId, $dateFrom, $dateTo]);
        $newPlayersRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $newPlayersRows = [];
    }

    try {
        $st = $pdo->prepare(
            "SELECT id, player_name, player_phone, training_name, training_price, paid_amount, attendance_date
             FROM single_training_attendance
             WHERE game_id = ? AND attendance_date BETWEEN ? AND ?
             ORDER BY attendance_date DESC"
        );
        $st->execute([$currentGameId, $dateFrom, $dateTo]);
        $singleRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $singleRows = [];
    }

    try {
        $st = $pdo->prepare(
            "SELECT psh.id, psh.player_name, psh.paid_amount, psh.academy_amount, psh.issue_date, psh.subscription_price
             FROM player_subscription_history psh
             WHERE psh.game_id = ? AND psh.source_action = 'renewal' AND psh.issue_date BETWEEN ? AND ?
             ORDER BY psh.issue_date DESC"
        );
        $st->execute([$currentGameId, $dateFrom, $dateTo]);
        $renewalRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $renewalRows = [];
    }

    try {
        $st = $pdo->prepare(
            "SELECT id, invoice_date, total_amount, paid_amount
             FROM sales_invoices
             WHERE game_id = ? AND invoice_date BETWEEN ? AND ?
             ORDER BY invoice_date DESC"
        );
        $st->execute([$currentGameId, $dateFrom, $dateTo]);
        $salesRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $salesRows = [];
    }

    try {
        if ($tab === "monthly") {
            $st = $pdo->prepare(
                "SELECT asp.id, asp.admin_name, asp.net_salary, asp.actual_paid_amount, asp.salary_month, asp.paid_at
                 FROM admin_salary_payments asp
                 WHERE asp.game_id = ? AND asp.salary_month = ?
                 ORDER BY asp.paid_at DESC"
            );
            $st->execute([$currentGameId, $salaryMonthStr]);
        } else {
            $st = $pdo->prepare(
                "SELECT asp.id, asp.admin_name, asp.net_salary, asp.actual_paid_amount, asp.salary_month, asp.paid_at
                 FROM admin_salary_payments asp
                 WHERE asp.game_id = ? AND DATE(asp.paid_at) BETWEEN ? AND ?
                 ORDER BY asp.paid_at DESC"
            );
            $st->execute([$currentGameId, $dateFrom, $dateTo]);
        }
        $adminSalaryRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $adminSalaryRows = [];
    }

    try {
        if ($tab === "monthly") {
            $st = $pdo->prepare(
                "SELECT tsp.id, tsp.trainer_name, tsp.net_salary, tsp.actual_paid_amount, tsp.salary_month, tsp.paid_at
                 FROM trainer_salary_payments tsp
                 WHERE tsp.game_id = ? AND tsp.salary_month = ?
                 ORDER BY tsp.paid_at DESC"
            );
            $st->execute([$currentGameId, $salaryMonthStr]);
        } else {
            $st = $pdo->prepare(
                "SELECT tsp.id, tsp.trainer_name, tsp.net_salary, tsp.actual_paid_amount, tsp.salary_month, tsp.paid_at
                 FROM trainer_salary_payments tsp
                 WHERE tsp.game_id = ? AND DATE(tsp.paid_at) BETWEEN ? AND ?
                 ORDER BY tsp.paid_at DESC"
            );
            $st->execute([$currentGameId, $dateFrom, $dateTo]);
        }
        $trainerSalaryRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $trainerSalaryRows = [];
    }

    try {
        $st = $pdo->prepare(
            "SELECT id, expense_date, statement_text, amount
             FROM store_expenses
             WHERE game_id = ? AND expense_date BETWEEN ? AND ?
             ORDER BY expense_date DESC"
        );
        $st->execute([$currentGameId, $dateFrom, $dateTo]);
        $expensesRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $expensesRows = [];
    }
}

$newPlayersCount = count($newPlayersRows);
$newPlayersSum = array_sum(array_column($newPlayersRows, "academy_amount"));

$singleCount = count($singleRows);
$singleSum = array_sum(array_column($singleRows, "paid_amount"));

$renewalCount = count($renewalRows);
$renewalSum = array_sum(array_column($renewalRows, "academy_amount"));

$salesCount = count($salesRows);
$salesSum = array_sum(array_column($salesRows, "paid_amount"));

$adminSalaryCount = count($adminSalaryRows);
$adminSalarySum = array_sum(array_map(fn($r) => (float)($r["actual_paid_amount"] !== null ? $r["actual_paid_amount"] : $r["net_salary"]), $adminSalaryRows));

$trainerSalaryCount = count($trainerSalaryRows);
$trainerSalarySum = array_sum(array_map(fn($r) => (float)($r["actual_paid_amount"] !== null ? $r["actual_paid_amount"] : $r["net_salary"]), $trainerSalaryRows));

$expensesCount = count($expensesRows);
$expensesSum = array_sum(array_column($expensesRows, "amount"));

$totalIncome = $newPlayersSum + $singleSum + $renewalSum + $salesSum;
$totalOutgo = $adminSalarySum + $trainerSalarySum + $expensesSum;
$netTotal = $totalIncome - $totalOutgo;

function statsJsonRows(array $rows): string
{
    return htmlspecialchars(json_encode($rows, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإحصائيات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .stats-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stats-tab-btn {
            padding: 10px 24px;
            border-radius: 10px;
            border: 2px solid transparent;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            background: var(--card-bg, #fff);
            color: var(--text-secondary, #666);
            border-color: var(--border-color, #e5e7eb);
            transition: all .18s;
        }
        .stats-tab-btn.active {
            background: var(--primary-color, #3b82f6);
            color: #fff;
            border-color: var(--primary-color, #3b82f6);
        }
        .stats-date-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .stats-date-bar input[type="date"],
        .stats-date-bar input[type="week"],
        .stats-date-bar input[type="month"] {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1.5px solid var(--border-color, #e5e7eb);
            background: var(--card-bg, #fff);
            color: var(--text-primary, #111);
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        .stats-date-bar button[type="submit"] {
            padding: 9px 22px;
            border-radius: 10px;
            border: none;
            background: var(--primary-color, #3b82f6);
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }
        .stats-period-badge {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary, #666);
            background: var(--hover-bg, #f3f4f6);
            border-radius: 8px;
            padding: 6px 14px;
        }
        .stats-section-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-primary, #111);
            margin: 28px 0 14px;
            padding-right: 10px;
            border-right: 4px solid var(--primary-color, #3b82f6);
        }
        .stats-section-title.expense-title {
            border-right-color: #ef4444;
        }
        .stats-section-title.total-title {
            border-right-color: #10b981;
        }
        .stats-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }
        .stat-card {
            background: var(--card-bg, #fff);
            border-radius: 14px;
            border: 1.5px solid var(--border-color, #e5e7eb);
            padding: 18px 20px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: box-shadow .15s;
        }
        .stat-card:hover {
            box-shadow: 0 4px 18px rgba(0,0,0,.07);
        }
        .stat-card-label {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary, #666);
        }
        .stat-card-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-primary, #111);
            line-height: 1.2;
        }
        .stat-card-sub {
            font-size: 12px;
            color: var(--text-secondary, #888);
            font-weight: 600;
        }
        .stat-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 6px;
            gap: 8px;
        }
        .stat-count-pill {
            background: var(--hover-bg, #f3f4f6);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary, #333);
        }
        .stat-details-btn {
            padding: 5px 14px;
            border-radius: 8px;
            border: 1.5px solid var(--primary-color, #3b82f6);
            background: transparent;
            color: var(--primary-color, #3b82f6);
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all .15s;
        }
        .stat-details-btn:hover {
            background: var(--primary-color, #3b82f6);
            color: #fff;
        }
        .stat-card.income-card {
            border-right: 4px solid #10b981;
        }
        .stat-card.expense-card {
            border-right: 4px solid #ef4444;
        }
        .stat-card.total-card {
            border-right: 4px solid #f59e0b;
        }
        .stat-card-value.positive {
            color: #10b981;
        }
        .stat-card-value.negative {
            color: #ef4444;
        }
        .stats-total-card {
            background: var(--card-bg, #fff);
            border-radius: 14px;
            border: 2px solid #10b981;
            padding: 22px 24px;
            margin-top: 8px;
        }
        .stats-total-card.negative-total {
            border-color: #ef4444;
        }
        .stats-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color, #e5e7eb);
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary, #111);
        }
        .stats-total-row:last-child {
            border-bottom: none;
        }
        .stats-total-row .trow-label {
            color: var(--text-secondary, #666);
            font-size: 13px;
        }
        .stats-total-row .trow-val {
            font-weight: 800;
            font-size: 16px;
        }
        .stats-total-row .trow-val.green {
            color: #10b981;
        }
        .stats-total-row .trow-val.red {
            color: #ef4444;
        }
        .stats-total-row .trow-val.net-positive {
            color: #10b981;
            font-size: 20px;
        }
        .stats-total-row .trow-val.net-negative {
            color: #ef4444;
            font-size: 20px;
        }
        .stats-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .stats-modal-overlay.open {
            display: flex;
        }
        .stats-modal {
            background: var(--card-bg, #fff);
            border-radius: 16px;
            width: 100%;
            max-width: 780px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }
        .stats-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1.5px solid var(--border-color, #e5e7eb);
        }
        .stats-modal-header h2 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-primary, #111);
            margin: 0;
        }
        .stats-modal-close {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: var(--text-secondary, #888);
            padding: 4px 8px;
            border-radius: 8px;
        }
        .stats-modal-close:hover {
            background: var(--hover-bg, #f3f4f6);
        }
        .stats-modal-body {
            overflow-y: auto;
            padding: 18px 22px;
            flex: 1;
        }
        .stats-modal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .stats-modal-table th {
            background: var(--hover-bg, #f3f4f6);
            color: var(--text-primary, #333);
            font-weight: 800;
            padding: 10px 12px;
            text-align: right;
            border-bottom: 2px solid var(--border-color, #e5e7eb);
        }
        .stats-modal-table td {
            padding: 9px 12px;
            color: var(--text-primary, #222);
            border-bottom: 1px solid var(--border-color, #f0f0f0);
            font-weight: 600;
        }
        .stats-modal-table tr:hover td {
            background: var(--hover-bg, #f9fafb);
        }
        .stats-modal-empty {
            text-align: center;
            color: var(--text-secondary, #888);
            padding: 32px;
            font-size: 15px;
            font-weight: 700;
        }
        @media (max-width: 640px) {
            .stats-cards-grid {
                grid-template-columns: 1fr;
            }
            .stats-modal-table {
                font-size: 13px;
            }
            .stats-modal-table th,
            .stats-modal-table td {
                padding: 7px 8px;
            }
        }
    </style>
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">القائمة</button>
                <div>
                    <h1>الإحصائيات</h1>
                </div>
            </div>
            <div class="topbar-left users-topbar-left">
                <span class="context-badge"><?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <span class="context-badge egypt-datetime-badge" id="egyptDateTime"><?php echo htmlspecialchars($egyptDateTimeLabel, ENT_QUOTES, "UTF-8"); ?></span>
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">🌙</span>
                    </span>
                </label>
            </div>
        </header>

        <section class="content-section">

            <div class="stats-tabs">
                <a href="?tab=daily&day=<?php echo urlencode($selectedDay); ?>" class="stats-tab-btn <?php echo $tab === "daily" ? "active" : ""; ?>">يومية</a>
                <a href="?tab=weekly&week=<?php echo urlencode($selectedWeek); ?>" class="stats-tab-btn <?php echo $tab === "weekly" ? "active" : ""; ?>">أسبوعية</a>
                <a href="?tab=monthly&month=<?php echo urlencode($selectedMonth); ?>" class="stats-tab-btn <?php echo $tab === "monthly" ? "active" : ""; ?>">شهرية</a>
            </div>

            <form method="GET" action="statistics.php" class="stats-date-bar">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, "UTF-8"); ?>">
                <?php if ($tab === "daily"): ?>
                    <input type="date" name="day" value="<?php echo htmlspecialchars($selectedDay, ENT_QUOTES, "UTF-8"); ?>">
                <?php elseif ($tab === "weekly"): ?>
                    <input type="week" name="week" value="<?php echo htmlspecialchars($selectedWeek, ENT_QUOTES, "UTF-8"); ?>">
                <?php else: ?>
                    <input type="month" name="month" value="<?php echo htmlspecialchars($selectedMonth, ENT_QUOTES, "UTF-8"); ?>">
                <?php endif; ?>
                <button type="submit">عرض</button>
                <span class="stats-period-badge"><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, "UTF-8"); ?></span>
            </form>

            <div class="stats-section-title">الإيرادات</div>
            <div class="stats-cards-grid">

                <div class="stat-card income-card">
                    <span class="stat-card-label">لاعبون جدد</span>
                    <span class="stat-card-value"><?php echo htmlspecialchars(statsFmt($newPlayersSum), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="stat-card-sub">مجموع مبالغ الأكاديمية من اللاعبين الجدد</span>
                    <div class="stat-card-footer">
                        <span class="stat-count-pill">العدد: <?php echo $newPlayersCount; ?></span>
                        <button class="stat-details-btn"
                            data-title="تفاصيل اللاعبين الجدد"
                            data-type="new_players"
                            data-rows='<?php echo statsJsonRows($newPlayersRows); ?>'>تفاصيل</button>
                    </div>
                </div>

                <div class="stat-card income-card">
                    <span class="stat-card-label">تمرينة واحدة</span>
                    <span class="stat-card-value"><?php echo htmlspecialchars(statsFmt($singleSum), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="stat-card-sub">مجموع مبالغ التمرينة الواحدة</span>
                    <div class="stat-card-footer">
                        <span class="stat-count-pill">العدد: <?php echo $singleCount; ?></span>
                        <button class="stat-details-btn"
                            data-title="تفاصيل التمرينة الواحدة"
                            data-type="single"
                            data-rows='<?php echo statsJsonRows($singleRows); ?>'>تفاصيل</button>
                    </div>
                </div>

                <div class="stat-card income-card">
                    <span class="stat-card-label">تجديد الاشتراك</span>
                    <span class="stat-card-value"><?php echo htmlspecialchars(statsFmt($renewalSum), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="stat-card-sub">مجموع مبالغ الأكاديمية من التجديدات</span>
                    <div class="stat-card-footer">
                        <span class="stat-count-pill">العدد: <?php echo $renewalCount; ?></span>
                        <button class="stat-details-btn"
                            data-title="تفاصيل تجديد الاشتراك"
                            data-type="renewal"
                            data-rows='<?php echo statsJsonRows($renewalRows); ?>'>تفاصيل</button>
                    </div>
                </div>

                <div class="stat-card income-card">
                    <span class="stat-card-label">فواتير البيع</span>
                    <span class="stat-card-value"><?php echo htmlspecialchars(statsFmt($salesSum), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="stat-card-sub">مجموع المبالغ من فواتير البيع</span>
                    <div class="stat-card-footer">
                        <span class="stat-count-pill">العدد: <?php echo $salesCount; ?></span>
                        <button class="stat-details-btn"
                            data-title="تفاصيل فواتير البيع"
                            data-type="sales"
                            data-rows='<?php echo statsJsonRows($salesRows); ?>'>تفاصيل</button>
                    </div>
                </div>

            </div>

            <div class="stats-section-title expense-title">المصروفات</div>
            <div class="stats-cards-grid">

                <div class="stat-card expense-card">
                    <span class="stat-card-label">مرتبات الإداريين</span>
                    <span class="stat-card-value"><?php echo htmlspecialchars(statsFmt($adminSalarySum), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="stat-card-sub">مجموع المبالغ المصروفة فعلياً للإداريين</span>
                    <div class="stat-card-footer">
                        <span class="stat-count-pill">العدد: <?php echo $adminSalaryCount; ?></span>
                        <button class="stat-details-btn"
                            data-title="تفاصيل مرتبات الإداريين"
                            data-type="admin_salary"
                            data-rows='<?php echo statsJsonRows($adminSalaryRows); ?>'>تفاصيل</button>
                    </div>
                </div>

                <div class="stat-card expense-card">
                    <span class="stat-card-label">مرتبات المدربين</span>
                    <span class="stat-card-value"><?php echo htmlspecialchars(statsFmt($trainerSalarySum), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="stat-card-sub">مجموع المبالغ المصروفة فعلياً للمدربين</span>
                    <div class="stat-card-footer">
                        <span class="stat-count-pill">العدد: <?php echo $trainerSalaryCount; ?></span>
                        <button class="stat-details-btn"
                            data-title="تفاصيل مرتبات المدربين"
                            data-type="trainer_salary"
                            data-rows='<?php echo statsJsonRows($trainerSalaryRows); ?>'>تفاصيل</button>
                    </div>
                </div>

                <div class="stat-card expense-card">
                    <span class="stat-card-label">المصروفات</span>
                    <span class="stat-card-value"><?php echo htmlspecialchars(statsFmt($expensesSum), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="stat-card-sub">مجموع مبالغ المصروفات</span>
                    <div class="stat-card-footer">
                        <span class="stat-count-pill">العدد: <?php echo $expensesCount; ?></span>
                        <button class="stat-details-btn"
                            data-title="تفاصيل المصروفات"
                            data-type="expenses"
                            data-rows='<?php echo statsJsonRows($expensesRows); ?>'>تفاصيل</button>
                    </div>
                </div>

            </div>

            <div class="stats-section-title total-title">الإجمالي</div>
            <div class="stats-total-card <?php echo $netTotal < 0 ? "negative-total" : ""; ?>">
                <div class="stats-total-row">
                    <span class="trow-label">إجمالي الإيرادات</span>
                    <span class="trow-val green"><?php echo htmlspecialchars(statsFmt($totalIncome), ENT_QUOTES, "UTF-8"); ?></span>
                </div>
                <div class="stats-total-row">
                    <span class="trow-label">إجمالي المصروفات</span>
                    <span class="trow-val red"><?php echo htmlspecialchars(statsFmt($totalOutgo), ENT_QUOTES, "UTF-8"); ?></span>
                </div>
                <div class="stats-total-row">
                    <span class="trow-label" style="font-size:15px;font-weight:800;color:var(--text-primary);">الصافي</span>
                    <span class="trow-val <?php echo $netTotal >= 0 ? "net-positive" : "net-negative"; ?>">
                        <?php echo htmlspecialchars(statsFmt($netTotal), ENT_QUOTES, "UTF-8"); ?>
                    </span>
                </div>
            </div>

        </section>
    </main>
</div>

<div class="stats-modal-overlay" id="statsModalOverlay">
    <div class="stats-modal">
        <div class="stats-modal-header">
            <h2 id="statsModalTitle">التفاصيل</h2>
            <button class="stats-modal-close" id="statsModalClose">✕</button>
        </div>
        <div class="stats-modal-body" id="statsModalBody"></div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/script.js"></script>
<script>
(function () {
    var overlay = document.getElementById("statsModalOverlay");
    var modalTitle = document.getElementById("statsModalTitle");
    var modalBody = document.getElementById("statsModalBody");
    var closeBtn = document.getElementById("statsModalClose");

    function fmtMoney(val) {
        if (val === null || val === undefined || val === "") return "—";
        var n = parseFloat(val);
        if (isNaN(n)) return "—";
        return n.toLocaleString("ar-EG", {minimumFractionDigits: 2, maximumFractionDigits: 2}) + " ج.م";
    }

    function buildTable(type, rows) {
        if (!rows || rows.length === 0) {
            return '<div class="stats-modal-empty">لا توجد بيانات.</div>';
        }
        var html = '<table class="stats-modal-table"><thead><tr>';
        var cols = [];
        if (type === "new_players" || type === "renewal") {
            cols = [
                {key: "player_name", label: "اسم اللاعب"},
                {key: "issue_date", label: "تاريخ"},
                {key: "subscription_price", label: "سعر الاشتراك"},
                {key: "paid_amount", label: "المبلغ المدفوع"},
                {key: "academy_amount", label: "نصيب الأكاديمية"},
            ];
        } else if (type === "single") {
            cols = [
                {key: "player_name", label: "اسم اللاعب"},
                {key: "player_phone", label: "الهاتف"},
                {key: "training_name", label: "نوع التمرين"},
                {key: "attendance_date", label: "تاريخ الحضور"},
                {key: "training_price", label: "سعر التمرينة"},
                {key: "paid_amount", label: "المبلغ المدفوع"},
            ];
        } else if (type === "sales") {
            cols = [
                {key: "invoice_date", label: "التاريخ"},
                {key: "total_amount", label: "إجمالي الفاتورة"},
                {key: "paid_amount", label: "المبلغ المدفوع"},
            ];
        } else if (type === "admin_salary") {
            cols = [
                {key: "admin_name", label: "اسم الإداري"},
                {key: "salary_month", label: "الشهر"},
                {key: "net_salary", label: "صافي المستحق"},
                {key: "actual_paid_amount", label: "المبلغ المصروف فعلياً"},
                {key: "paid_at", label: "وقت الصرف"},
            ];
        } else if (type === "trainer_salary") {
            cols = [
                {key: "trainer_name", label: "اسم المدرب"},
                {key: "salary_month", label: "الشهر"},
                {key: "net_salary", label: "صافي المستحق"},
                {key: "actual_paid_amount", label: "المبلغ المصروف فعلياً"},
                {key: "paid_at", label: "وقت الصرف"},
            ];
        } else if (type === "expenses") {
            cols = [
                {key: "expense_date", label: "التاريخ"},
                {key: "statement_text", label: "البيان"},
                {key: "amount", label: "المبلغ"},
            ];
        }

        cols.forEach(function (c) {
            html += "<th>" + c.label + "</th>";
        });
        html += "</tr></thead><tbody>";

        var moneyKeys = ["paid_amount","academy_amount","subscription_price","training_price","total_amount","net_salary","actual_paid_amount","amount"];

        rows.forEach(function (row, idx) {
            html += "<tr>";
            cols.forEach(function (c) {
                var val = row[c.key];
                if (val === null || val === undefined) {
                    if (c.key === "actual_paid_amount") {
                        val = row["net_salary"];
                    } else {
                        val = "—";
                    }
                }
                if (moneyKeys.indexOf(c.key) !== -1 && val !== "—") {
                    html += "<td>" + fmtMoney(val) + "</td>";
                } else {
                    html += "<td>" + String(val) + "</td>";
                }
            });
            html += "</tr>";
        });

        html += "</tbody></table>";
        return html;
    }

    document.querySelectorAll(".stat-details-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var title = btn.getAttribute("data-title");
            var type = btn.getAttribute("data-type");
            var rows = JSON.parse(btn.getAttribute("data-rows") || "[]");
            modalTitle.textContent = title;
            modalBody.innerHTML = buildTable(type, rows);
            overlay.classList.add("open");
        });
    });

    function closeModal() {
        overlay.classList.remove("open");
    }

    closeBtn.addEventListener("click", closeModal);
    overlay.addEventListener("click", function (e) {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeModal();
    });
})();
</script>
</body>
</html>
