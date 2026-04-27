<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "salary_collection_helpers.php";

date_default_timezone_set("Africa/Cairo");

requireAuthenticatedUser();
requireMenuAccess("trainers-collections");

$trainerSalaryConfig = [
    "entity_table" => "trainers",
    "entity_id_column" => "trainer_id",
    "entity_name_column" => "trainer_name",
    "attendance_table" => "trainer_attendance",
    "attendance_entity_id_column" => "trainer_id",
    "attendance_has_actual_hours" => true,
    "loan_table" => "trainer_loans",
    "loan_entity_id_column" => "trainer_id",
    "loan_name_column" => "trainer_name",
    "deduction_table" => "trainer_deductions",
    "deduction_entity_id_column" => "trainer_id",
    "deduction_name_column" => "trainer_name",
    "salary_table" => "trainer_salary_payments",
    "salary_unique_key" => "unique_trainer_salary_payment_month",
    "salary_game_month_key" => "idx_trainer_salary_payments_game_month",
    "salary_entity_key" => "idx_trainer_salary_payments_trainer",
    "has_hourly_rate" => true,
];

payrollEnsureTrainerAttendanceHoursColumn($pdo);
payrollEnsureSalaryPaymentsTable($pdo, $trainerSalaryConfig);

if (!isset($_SESSION["trainer_collections_csrf_token"])) {
    $_SESSION["trainer_collections_csrf_token"] = bin2hex(random_bytes(32));
}

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$isSwimming = function_exists("mb_stripos") ? mb_stripos($currentGameName, "سباح") !== false : strpos($currentGameName, "سباح") !== false;
$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "trainers-collections";
$gamesMap = payrollFetchAllGamesMap($pdo);
if ($currentGameId <= 0 || !isset($gamesMap[$currentGameId])) {
    header("Location: dashboard.php");
    exit;
}
if ($currentGameName === "") {
    $currentGameName = (string)$gamesMap[$currentGameId]["name"];
}
$now = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$egyptDateTimeLabel = payrollGetEgyptDateTimeLabel($now);
$monthOptions = payrollGetMonthOptions($now, 24);
$defaultSalaryMonth = $now->format("Y-m-01");
$selectedSalaryMonth = trim((string)($_GET["salary_month"] ?? $_POST["salary_month"] ?? $defaultSalaryMonth));
if (!payrollIsValidMonthValue($selectedSalaryMonth)) {
    $selectedSalaryMonth = $defaultSalaryMonth;
}
$monthValues = array_column($monthOptions, "value");
if (!in_array($selectedSalaryMonth, $monthValues, true)) {
    array_unshift($monthOptions, [
        "value" => $selectedSalaryMonth,
        "label" => payrollFormatMonthLabel($selectedSalaryMonth),
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["trainer_collections_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $postedMonth = trim((string)($_POST["salary_month"] ?? ""));
        $postedTrainerId = (int)($_POST["trainer_id"] ?? 0);
        $postedActualPaidAmount = trim((string)($_POST["actual_paid_amount"] ?? ""));
        if (!payrollIsValidMonthValue($postedMonth)) {
            $error = "الشهر غير صالح.";
        } elseif ($postedTrainerId <= 0) {
            $error = "اختر المدرب أولاً.";
        } elseif ($postedActualPaidAmount === "" || !is_numeric($postedActualPaidAmount) || (float)$postedActualPaidAmount < 0) {
            $error = "المبلغ المستحق غير صالح.";
        } else {
            $trainer = payrollFetchEmployee($pdo, $trainerSalaryConfig, $currentGameId, $postedTrainerId);
            if (!$trainer) {
                $error = "المدرب غير موجود.";
            } else {
                $alreadyPaidStmt = $pdo->prepare(
                    "SELECT id FROM trainer_salary_payments WHERE game_id = ? AND trainer_id = ? AND salary_month = ? LIMIT 1"
                );
                $alreadyPaidStmt->execute([$currentGameId, $postedTrainerId, $postedMonth]);
                if ($alreadyPaidStmt->fetch()) {
                    $error = "تم صرف راتب هذا المدرب في الشهر المحدد بالفعل.";
                } else {
                    try {
                        $details = payrollBuildEmployeeMonthDetails($pdo, $trainerSalaryConfig, $currentGameId, $trainer, $postedMonth, $isSwimming);
                        $pdo->beginTransaction();
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO trainer_salary_payments (
                                game_id,
                                trainer_id,
                                trainer_name,
                                salary_month,
                                base_salary,
                                loans_total,
                                deductions_total,
                                net_salary,
                                actual_paid_amount,
                                attendance_days,
                                absent_days,
                                late_days,
                                early_departure_days,
                                overtime_days,
                                paid_by_user_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            (int)$trainer["id"],
                            (string)$trainer["name"],
                            $postedMonth,
                            payrollNormalizeAmount($details["base_salary"]),
                            payrollNormalizeAmount($details["loans_total"]),
                            payrollNormalizeAmount($details["deductions_total"]),
                            payrollNormalizeAmount($details["net_salary"]),
                            payrollNormalizeAmount($postedActualPaidAmount),
                            (int)$details["attendance_summary"]["attendance_days"],
                            (int)$details["attendance_summary"]["absent_days"],
                            (int)$details["attendance_summary"]["late_days"],
                            (int)$details["attendance_summary"]["early_departure_days"],
                            (int)$details["attendance_summary"]["overtime_days"],
                            (int)($_SESSION["user_id"] ?? 0),
                        ]);
                        auditTrack($pdo, "create", "trainer_salary_payments", (int)$pdo->lastInsertId(), "قبض المدربين", "صرف راتب المدرب: " . (string)$trainer["name"] . " عن شهر " . payrollFormatMonthLabel($postedMonth));
                        $pdo->commit();
                        $_SESSION["trainer_collections_csrf_token"] = bin2hex(random_bytes(32));
                        $_SESSION["trainer_collections_success"] = "تم صرف راتب المدرب بنجاح.";
                        header("Location: trainer_collections.php?salary_month=" . urlencode($postedMonth));
                        exit;
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Trainer collections pay error: " . $throwable->getMessage());
                        $error = "تعذر صرف الراتب حالياً.";
                    }
                }
            }
        }
    }
}

$flashSuccess = $_SESSION["trainer_collections_success"] ?? "";
unset($_SESSION["trainer_collections_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

$unpaidTrainers = payrollFetchUnpaidEmployees($pdo, $trainerSalaryConfig, $currentGameId, $selectedSalaryMonth);
$selectedTrainerId = (int)($_GET["trainer_id"] ?? $_POST["trainer_id"] ?? 0);
$selectedTrainer = null;
foreach ($unpaidTrainers as $trainerRow) {
    if ((int)$trainerRow["id"] === $selectedTrainerId) {
        $selectedTrainer = $trainerRow;
        break;
    }
}
if ($selectedTrainerId > 0 && !$selectedTrainer) {
    $selectedTrainerId = 0;
}

$selectedTrainerDetails = null;
if ($selectedTrainer) {
    $selectedTrainerDetails = payrollBuildEmployeeMonthDetails($pdo, $trainerSalaryConfig, $currentGameId, $selectedTrainer, $selectedSalaryMonth, $isSwimming);
}

$paidRows = payrollFetchPaidRows($pdo, $trainerSalaryConfig, $currentGameId, $selectedSalaryMonth);
$totalPaidAmount = payrollSumPaidRows($paidRows);
$defaultPaidInputValue = $selectedTrainerDetails ? max((float)$selectedTrainerDetails["net_salary"], 0) : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قبض المدربين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .collections-main-stack{display:flex;flex-direction:column;gap:24px;}
        .collections-meta-row{display:flex;flex-wrap:wrap;gap:12px;}
        .collections-summary-card{display:flex;flex-direction:column;gap:18px;}
        .collections-form-card form{display:flex;flex-direction:column;gap:18px;}
        .collections-empty-box{padding:22px;border:1px dashed var(--border);border-radius:18px;background:rgba(47,91,234,.04);color:var(--text);line-height:1.9;}
        .collections-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
        .collection-stat-card-negative{background:linear-gradient(135deg,rgba(239,68,68,.14),rgba(234,88,12,.12));}
        body.dark-mode .collection-stat-card-negative{background:linear-gradient(135deg,rgba(239,68,68,.22),rgba(249,115,22,.16));}
        @media (max-width:768px){.collections-grid-2{grid-template-columns:1fr;}}
    </style>
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content collections-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">القائمة</button>
                <div>
                    <h1>قبض المدربين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge"><?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="context-badge" id="egyptDateTime"><?php echo htmlspecialchars($egyptDateTimeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
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

        <section class="trainers-summary-grid">
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">الشهر المحدد</span>
                <strong class="trainer-stat-value" style="font-size:1.15rem;"><?php echo htmlspecialchars(payrollFormatMonthLabel($selectedSalaryMonth), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">مدربون لم يقبضوا</span>
                <strong class="trainer-stat-value"><?php echo count($unpaidTrainers); ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">مرتبات مصروفة</span>
                <strong class="trainer-stat-value"><?php echo count($paidRows); ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">إجمالي المصروف</span>
                <strong class="trainer-stat-value"><?php echo htmlspecialchars(payrollFormatCurrency($totalPaidAmount), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="card trainer-stat-card <?php echo $selectedTrainerDetails && (float)$selectedTrainerDetails["net_salary"] < 0 ? 'collection-stat-card-negative' : ''; ?>">
                <span class="trainer-stat-label">مستحق المدرب المحدد</span>
                <strong class="trainer-stat-value"><?php echo htmlspecialchars(payrollFormatCurrency($selectedTrainerDetails["net_salary"] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </section>

        <section class="attendance-layout-grid">
            <div class="attendance-stack">
                <div class="card attendance-filter-card collections-form-card">
                    <div class="card-head">
                        <div>
                            <h3>اختيار الشهر والمدرب</h3>
                        </div>
                    </div>

                    <form method="GET" id="trainerCollectionsFilterForm">
                        <div class="attendance-filter-grid">
                            <div class="form-group">
                                <label for="salaryMonth">الشهر</label>
                                <select name="salary_month" id="salaryMonth">
                                    <?php foreach ($monthOptions as $monthOption): ?>
                                        <option value="<?php echo htmlspecialchars($monthOption["value"], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedSalaryMonth === $monthOption["value"] ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthOption["label"], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="trainerId">المدرب</label>
                                <select name="trainer_id" id="trainerId">
                                    <option value="0">اختر المدرب</option>
                                    <?php foreach ($unpaidTrainers as $trainerRow): ?>
                                        <option value="<?php echo (int)$trainerRow["id"]; ?>" <?php echo $selectedTrainerId === (int)$trainerRow["id"] ? 'selected' : ''; ?>><?php echo htmlspecialchars($trainerRow["name"], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض البيانات</button>
                            <a href="trainer_collections.php?salary_month=<?php echo urlencode($selectedSalaryMonth); ?>" class="btn btn-soft">إعادة ضبط</a>
                        </div>
                    </form>
                </div>

                <div class="card collections-summary-card">
                    <div class="card-head">
                        <div>
                            <h3>حالة الشهر</h3>
                        </div>
                    </div>
                    <?php if ($selectedTrainer && $selectedTrainerDetails): ?>
                        <div class="collection-status-banner is-pending">
                            <span class="status-chip status-warning collection-status-chip">مستحق الصرف</span>
                            <strong><?php echo htmlspecialchars($selectedTrainer["name"], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span class="collection-status-meta"><?php echo $isSwimming ? 'الحساب حسب ساعات العمل الفعلية وسعر الساعة المسجل.' : 'الحساب حسب الراتب المسجل للمدرب.'; ?></span>
                        </div>
                        <div class="collection-breakdown-grid">
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label"><?php echo $isSwimming ? 'إجمالي أجر الساعات' : 'الراتب الأساسي'; ?></span>
                                <strong class="collection-breakdown-value"><?php echo htmlspecialchars(payrollFormatCurrency($selectedTrainerDetails["base_salary"]), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label">إجمالي السلف</span>
                                <strong class="collection-breakdown-value"><?php echo htmlspecialchars(payrollFormatCurrency($selectedTrainerDetails["loans_total"]), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label">إجمالي الخصومات</span>
                                <strong class="collection-breakdown-value"><?php echo htmlspecialchars(payrollFormatCurrency($selectedTrainerDetails["deductions_total"]), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label">الصافي الحالي</span>
                                <strong class="collection-breakdown-value"><?php echo htmlspecialchars(payrollFormatCurrency($selectedTrainerDetails["net_salary"]), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label">أيام الحضور</span>
                                <strong class="collection-breakdown-value"><?php echo (int)$selectedTrainerDetails["attendance_summary"]["attendance_days"]; ?></strong>
                            </div>
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label">أيام الغياب</span>
                                <strong class="collection-breakdown-value"><?php echo (int)$selectedTrainerDetails["attendance_summary"]["absent_days"]; ?></strong>
                            </div>
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label">الحضور المتأخر</span>
                                <strong class="collection-breakdown-value"><?php echo (int)$selectedTrainerDetails["attendance_summary"]["late_days"]; ?></strong>
                            </div>
                            <div class="collection-breakdown-item">
                                <span class="collection-breakdown-label">الإضافي</span>
                                <strong class="collection-breakdown-value"><?php echo (int)$selectedTrainerDetails["attendance_summary"]["overtime_days"]; ?></strong>
                            </div>
                            <?php if ($isSwimming): ?>
                                <div class="collection-breakdown-item">
                                    <span class="collection-breakdown-label">ساعات العمل الفعلية</span>
                                    <strong class="collection-breakdown-value"><?php echo htmlspecialchars(number_format((float)$selectedTrainerDetails["attendance_summary"]["actual_work_hours"], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                                <div class="collection-breakdown-item">
                                    <span class="collection-breakdown-label">سعر الساعة</span>
                                    <strong class="collection-breakdown-value"><?php echo htmlspecialchars(payrollFormatCurrency($selectedTrainer["hourly_rate"] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["trainer_collections_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="salary_month" value="<?php echo htmlspecialchars($selectedSalaryMonth, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="trainer_id" value="<?php echo (int)$selectedTrainer["id"]; ?>">
                            <div class="collections-grid-2">
                                <div class="form-group">
                                    <label for="trainerNameReadonly">اسم المدرب</label>
                                    <input type="text" id="trainerNameReadonly" value="<?php echo htmlspecialchars($selectedTrainer["name"], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label for="actualPaidAmount">المبلغ المستحق للصرف</label>
                                    <input type="number" name="actual_paid_amount" id="actualPaidAmount" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format((float)$defaultPaidInputValue, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>
                            <div class="attendance-filter-actions">
                                <button type="submit" class="btn btn-primary">صرف الراتب</button>
                            </div>
                        </form>
                    <?php elseif (count($unpaidTrainers) === 0): ?>
                        <div class="collection-status-banner is-paid">
                            <span class="status-chip status-success collection-status-chip">مكتمل</span>
                            <strong>تم صرف جميع مرتبات المدربين في الشهر المحدد.</strong>
                        </div>
                    <?php else: ?>
                        <div class="collections-empty-box">اختر مدرباً من القائمة لعرض الحضور والغياب والسلف والخصومات والمرتب المستحق.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="collections-main-stack">
                <div class="card attendance-table-card">
                    <div class="card-head table-card-head">
                        <div>
                            <h3>المرتبات المصروفة خلال <?php echo htmlspecialchars(payrollFormatMonthLabel($selectedSalaryMonth), ENT_QUOTES, 'UTF-8'); ?></h3>
                        </div>
                        <span class="table-counter"><?php echo count($paidRows); ?></span>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table attendance-table">
                            <thead>
                                <tr>
                                    <th>اسم المدرب</th>
                                    <th>الراتب الأساسي</th>
                                    <th>السلف</th>
                                    <th>الخصومات</th>
                                    <th>الصافي</th>
                                    <th>المبلغ المصروف</th>
                                    <th>الحضور</th>
                                    <th>الغياب</th>
                                    <th>التأخير</th>
                                    <th>وقت الصرف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($paidRows) === 0): ?>
                                    <tr>
                                        <td colspan="10" class="empty-cell">لا توجد مرتبات مصروفة في هذا الشهر.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paidRows as $paidRow): ?>
                                        <tr>
                                            <td data-label="اسم المدرب"><?php echo htmlspecialchars($paidRow["entity_name"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="الراتب الأساسي"><?php echo htmlspecialchars(payrollFormatCurrency($paidRow["base_salary"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="السلف"><?php echo htmlspecialchars(payrollFormatCurrency($paidRow["loans_total"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="الخصومات"><?php echo htmlspecialchars(payrollFormatCurrency($paidRow["deductions_total"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="الصافي"><?php echo htmlspecialchars(payrollFormatCurrency($paidRow["net_salary"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="المبلغ المصروف"><?php echo htmlspecialchars(payrollFormatCurrency($paidRow["actual_paid_amount"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="الحضور"><?php echo (int)$paidRow["attendance_days"]; ?></td>
                                            <td data-label="الغياب"><?php echo (int)$paidRow["absent_days"]; ?></td>
                                            <td data-label="التأخير"><?php echo (int)$paidRow["late_days"]; ?></td>
                                            <td data-label="وقت الصرف"><?php echo htmlspecialchars(payrollFormatDateTimeValue($paidRow["paid_at"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card attendance-table-card">
                    <div class="card-head table-card-head">
                        <div>
                            <h3>سجل حضور وغياب الشهر</h3>
                        </div>
                        <span class="table-counter"><?php echo $selectedTrainerDetails ? count($selectedTrainerDetails["attendance_rows"]) : 0; ?></span>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table attendance-table">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>ميعاد الحضور</th>
                                    <th>الحضور الفعلي</th>
                                    <th>حالة الحضور</th>
                                    <th>التأخير</th>
                                    <th>ميعاد الانصراف</th>
                                    <th>الانصراف الفعلي</th>
                                    <th>حالة الانصراف</th>
                                    <th>الانصراف المبكر</th>
                                    <th>الإضافي</th>
                                    <th>الحالة اليومية</th>
                                    <?php if ($isSwimming): ?>
                                        <th>ساعات العمل الفعلية</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$selectedTrainerDetails || count($selectedTrainerDetails["attendance_rows"]) === 0): ?>
                                    <tr>
                                        <td colspan="<?php echo $isSwimming ? 12 : 11; ?>" class="empty-cell">لا توجد سجلات حضور لهذا الشهر.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($selectedTrainerDetails["attendance_rows"] as $attendanceRow): ?>
                                        <tr>
                                            <td data-label="التاريخ"><?php echo htmlspecialchars((string)$attendanceRow["attendance_date"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="ميعاد الحضور"><?php echo htmlspecialchars(payrollFormatTimeValue($attendanceRow["attendance_date"] . ' ' . $attendanceRow["scheduled_attendance_time"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="الحضور الفعلي"><?php echo htmlspecialchars(payrollFormatDateTimeValue($attendanceRow["attendance_at"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="حالة الحضور"><span class="status-chip <?php echo htmlspecialchars(($attendanceRow["attendance_status"] ?? '') === 'غياب' ? 'status-danger' : (($attendanceRow["attendance_status"] ?? '') === 'حضور متأخر' ? 'status-warning' : 'status-success'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($attendanceRow["attendance_status"] ?: PAYROLL_EMPTY_VALUE, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td data-label="التأخير"><?php echo (int)($attendanceRow["attendance_minutes_late"] ?? 0); ?></td>
                                            <td data-label="ميعاد الانصراف"><?php echo htmlspecialchars(payrollFormatTimeValue($attendanceRow["attendance_date"] . ' ' . $attendanceRow["scheduled_departure_time"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="الانصراف الفعلي"><?php echo htmlspecialchars(payrollFormatDateTimeValue($attendanceRow["departure_at"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="حالة الانصراف"><span class="status-chip <?php echo htmlspecialchars(($attendanceRow["departure_status"] ?? '') === 'انصراف مبكر' ? 'status-warning' : (($attendanceRow["departure_status"] ?? '') === 'انصراف مع إضافي' ? 'status-info' : 'status-success'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($attendanceRow["departure_status"] ?: PAYROLL_EMPTY_VALUE, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td data-label="الانصراف المبكر"><?php echo (int)($attendanceRow["departure_minutes_early"] ?? 0); ?></td>
                                            <td data-label="الإضافي"><?php echo (int)($attendanceRow["overtime_minutes"] ?? 0); ?></td>
                                            <td data-label="الحالة اليومية"><span class="status-chip <?php echo htmlspecialchars(($attendanceRow["day_status"] ?? '') === 'غياب' ? 'status-danger' : 'status-success', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($attendanceRow["day_status"] ?: PAYROLL_EMPTY_VALUE, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <?php if ($isSwimming): ?>
                                                <td data-label="ساعات العمل الفعلية"><?php echo htmlspecialchars(number_format((float)($attendanceRow["actual_work_hours"] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card attendance-table-card">
                    <div class="card-head table-card-head">
                        <div>
                            <h3>سجل السلف بالتفاصيل</h3>
                        </div>
                        <span class="table-counter"><?php echo $selectedTrainerDetails ? count($selectedTrainerDetails["loan_rows"]) : 0; ?></span>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table attendance-table">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>اسم المدرب</th>
                                    <th>المبلغ</th>
                                    <th>وقت التسجيل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$selectedTrainerDetails || count($selectedTrainerDetails["loan_rows"]) === 0): ?>
                                    <tr>
                                        <td colspan="4" class="empty-cell">لا توجد سلف في هذا الشهر.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($selectedTrainerDetails["loan_rows"] as $loanRow): ?>
                                        <tr>
                                            <td data-label="التاريخ"><?php echo htmlspecialchars((string)$loanRow["loan_date"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="اسم المدرب"><?php echo htmlspecialchars($loanRow["entity_name"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="المبلغ"><?php echo htmlspecialchars(payrollFormatCurrency($loanRow["amount"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="وقت التسجيل"><?php echo htmlspecialchars(payrollFormatDateTimeValue($loanRow["created_at"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card attendance-table-card">
                    <div class="card-head table-card-head">
                        <div>
                            <h3>سجل الخصومات</h3>
                        </div>
                        <span class="table-counter"><?php echo $selectedTrainerDetails ? count($selectedTrainerDetails["deduction_rows"]) : 0; ?></span>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table attendance-table">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>المبلغ</th>
                                    <th>السبب</th>
                                    <th>وقت التسجيل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$selectedTrainerDetails || count($selectedTrainerDetails["deduction_rows"]) === 0): ?>
                                    <tr>
                                        <td colspan="4" class="empty-cell">لا توجد خصومات في هذا الشهر.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($selectedTrainerDetails["deduction_rows"] as $deductionRow): ?>
                                        <tr>
                                            <td data-label="التاريخ"><?php echo htmlspecialchars((string)$deductionRow["deduction_date"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="المبلغ"><?php echo htmlspecialchars(payrollFormatCurrency($deductionRow["amount"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="السبب" class="deduction-reason-cell"><?php echo htmlspecialchars($deductionRow["reason"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="وقت التسجيل"><?php echo htmlspecialchars(payrollFormatDateTimeValue($deductionRow["created_at"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="assets/js/script.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const dateTimeBadge = document.getElementById("egyptDateTime");
    const monthField = document.getElementById("salaryMonth");
    const formatter = new Intl.DateTimeFormat("ar-EG", {
        timeZone: "Africa/Cairo",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        hour12: true
    });
    const updateDateTime = function () {
        const parts = formatter.formatToParts(new Date());
        const values = {};
        parts.forEach(function (part) {
            values[part.type] = part.value;
        });
        if (dateTimeBadge) {
            dateTimeBadge.textContent = values.year + "/" + values.month + "/" + values.day + " - " + values.hour + ":" + values.minute + " " + (values.dayPeriod || "");
        }
    };
    updateDateTime();
    setInterval(updateDateTime, 1000);
    if (monthField) {
        monthField.addEventListener("change", function () {
            const trainerField = document.getElementById("trainerId");
            if (trainerField) {
                trainerField.value = "0";
            }
            document.getElementById("trainerCollectionsFilterForm").submit();
        });
    }
});
</script>
</body>
</html>
