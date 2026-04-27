<?php
require_once 'session.php';
startSecureSession();
require_once 'config.php';
require_once 'navigation.php';
require_once 'players_support.php';

requireAuthenticatedUser();
requireMenuAccess('single-training');
ensurePlayersTables($pdo);

date_default_timezone_set('Africa/Cairo');

if (!isset($_SESSION['single_training_csrf_token'])) {
    $_SESSION['single_training_csrf_token'] = bin2hex(random_bytes(32));
}

function fetchSingleTrainingAttendanceRecords(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        'SELECT id, player_name, player_phone, training_name, training_price, paid_amount, attendance_date, attended_at
         FROM single_training_attendance
         WHERE game_id = ?
         ORDER BY attended_at DESC, id DESC
         LIMIT 200'
    );
    $stmt->execute([(int)$gameId]);

    return $stmt->fetchAll();
}

function fetchSingleTrainingSummary(PDO $pdo, $gameId, DateTimeImmutable $today)
{
    $trainingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM single_training_prices WHERE game_id = ?');
    $trainingCountStmt->execute([(int)$gameId]);

    $attendanceSummaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total_rows, COALESCE(SUM(paid_amount), 0) AS total_amount
         FROM single_training_attendance
         WHERE game_id = ? AND attendance_date = ?'
    );
    $attendanceSummaryStmt->execute([(int)$gameId, $today->format('Y-m-d')]);
    $attendanceSummary = $attendanceSummaryStmt->fetch() ?: ['total_rows' => 0, 'total_amount' => 0];

    return [
        'trainings_count' => (int)$trainingCountStmt->fetchColumn(),
        'today_attendance_count' => (int)($attendanceSummary['total_rows'] ?? 0),
        'today_amount' => (float)($attendanceSummary['total_amount'] ?? 0),
    ];
}

$success = '';
$error = '';
$currentGameId = (int)($_SESSION['selected_game_id'] ?? 0);
$currentGameName = (string)($_SESSION['selected_game_name'] ?? '');
$today = new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
$now = new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo'));
$egyptDateTimeLabel = formatPlayerAttendanceDateTimeLabel($now);

$settingsStmt = $pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1');
$settings = $settingsStmt->fetch() ?: [];
$sidebarName = $settings['academy_name'] ?? ($_SESSION['site_name'] ?? 'أكاديمية رياضية');
$sidebarLogo = $settings['academy_logo'] ?? ($_SESSION['site_logo'] ?? 'assets/images/logo.png');
$activeMenu = 'single-training';

$gamesStmt = $pdo->query('SELECT id, name FROM games WHERE status = 1 ORDER BY id ASC');
$allGames = $gamesStmt->fetchAll();
$allGameMap = [];
foreach ($allGames as $game) {
    $allGameMap[(int)$game['id']] = $game;
}

if ($currentGameId <= 0 || !isset($allGameMap[$currentGameId])) {
    header('Location: dashboard.php');
    exit;
}

$trainingFormData = [
    'training_name' => '',
    'price' => '',
];

$flashSuccess = $_SESSION['single_training_success'] ?? '';
unset($_SESSION['single_training_success']);
if ($flashSuccess !== '') {
    $success = $flashSuccess;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if ($csrfToken === '' || !hash_equals($_SESSION['single_training_csrf_token'], $csrfToken)) {
        $error = 'الطلب غير صالح.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'add_training') {
            $trainingFormData = [
                'training_name' => limitSingleTrainingText($_POST['training_name'] ?? ''),
                'price' => normalizePlayerMoneyValue($_POST['price'] ?? ''),
            ];

            if ($trainingFormData['training_name'] === '') {
                $error = 'اسم التمرينة مطلوب.';
            } elseif ($trainingFormData['price'] === '' || (float)$trainingFormData['price'] <= 0) {
                $error = 'سعر التمرينة غير صحيح.';
            } else {
                $checkStmt = $pdo->prepare(
                    'SELECT id FROM single_training_prices WHERE game_id = ? AND training_name = ? LIMIT 1'
                );
                $checkStmt->execute([$currentGameId, $trainingFormData['training_name']]);

                if ($checkStmt->fetchColumn()) {
                    $error = 'اسم التمرينة مسجل بالفعل.';
                } else {
                    $saveStmt = $pdo->prepare(
                        'INSERT INTO single_training_prices (game_id, training_name, price) VALUES (?, ?, ?)'
                    );

                    try {
                        $saveStmt->execute([
                            $currentGameId,
                            $trainingFormData['training_name'],
                            $trainingFormData['price'],
                        ]);

                        auditTrack($pdo, "create", "single_training_prices", (int)$pdo->lastInsertId(), "تمرينات منفردة", "إضافة تمرينة منفردة: " . (string)$trainingFormData['training_name'] . " بسعر " . (string)$trainingFormData['price']);
                        $_SESSION['single_training_csrf_token'] = bin2hex(random_bytes(32));
                        $_SESSION['single_training_success'] = 'تم حفظ التمرينة بنجاح.';
                        header('Location: single_training.php');
                        exit;
                    } catch (Throwable $throwable) {
                        $error = 'تعذر حفظ التمرينة.';
                    }
                }
            }
        } else {
            $error = 'الإجراء غير مدعوم.';
        }
    }
}

$trainings = fetchSingleTrainingDefinitions($pdo, $currentGameId);
$attendanceRecords = fetchSingleTrainingAttendanceRecords($pdo, $currentGameId);
$summary = fetchSingleTrainingSummary($pdo, $currentGameId, $today);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سعر تمرينة واحدة</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .single-training-page .single-training-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 22px;
        }
        .single-training-page .single-training-stat {
            padding: 22px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(47, 91, 234, 0.12), rgba(124, 58, 237, 0.1));
            border: 1px solid rgba(47, 91, 234, 0.16);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .single-training-page .single-training-stat strong {
            font-size: 2rem;
            color: var(--text);
        }
        .single-training-page .single-training-grid {
            display: grid;
            grid-template-columns: minmax(0, 420px) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }
        .single-training-page .single-training-stack,
        .single-training-page .single-training-tables {
            display: grid;
            gap: 20px;
        }
        .single-training-page .single-training-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
        }
        .single-training-page .single-training-form-grid .single-training-field-full {
            grid-column: 1 / -1;
        }
        .single-training-page .single-training-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .single-training-page .single-training-price-preview {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 54px;
            padding: 14px 18px;
            border-radius: 18px;
            border: 1px solid rgba(47, 91, 234, 0.16);
            background: rgba(47, 91, 234, 0.08);
            color: var(--text);
            font-weight: 800;
        }
        .single-training-page .single-training-price-preview.is-empty {
            color: var(--text-soft);
        }
        .single-training-page .single-training-table-stack {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .single-training-page .single-training-table-stack span {
            color: var(--text-soft);
            font-size: 0.92rem;
        }
        .single-training-page .single-training-table-card .table-wrap {
            margin-top: 0;
        }
        body.dark-mode .single-training-page .single-training-stat,
        body.dark-mode .single-training-page .single-training-price-preview {
            background: #162133;
            border-color: #334155;
        }
        @media (max-width: 1100px) {
            .single-training-page .single-training-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .single-training-page .single-training-actions .btn {
                flex: 1;
                text-align: center;
            }
            .single-training-page .single-training-summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="dashboard-page players-page single-training-page">
<div class="dashboard-layout">
    <?php require 'sidebar_menu.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>سعر تمرينة واحدة</h1>
                </div>
            </div>
            <div class="topbar-left users-topbar-left">
                <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="context-badge egypt-datetime-badge"><?php echo htmlspecialchars($egyptDateTimeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">🌙</span>
                    </span>
                </label>
            </div>
        </header>

        <?php if ($success !== ''): ?>
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="single-training-summary">
            <div class="single-training-stat">
                <span class="trainer-stat-label">عدد التمرينات</span>
                <strong><?php echo (int)$summary['trainings_count']; ?></strong>
            </div>
            <div class="single-training-stat">
                <span class="trainer-stat-label">حضور اليوم</span>
                <strong><?php echo (int)$summary['today_attendance_count']; ?></strong>
            </div>
            <div class="single-training-stat">
                <span class="trainer-stat-label">إجمالي اليوم</span>
                <strong><?php echo htmlspecialchars(formatPlayerCurrencyLabel($summary['today_amount']), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </section>

        <section class="single-training-grid">
            <div class="single-training-stack">
                <section class="card">
                    <div class="card-head">
                        <div>
                            <h3>إضافة تمرينة</h3>
                        </div>
                    </div>

                    <form method="POST" class="login-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['single_training_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="add_training">

                        <div class="single-training-form-grid">
                            <div class="form-group single-training-field-full">
                                <label for="training_name">اسم التمرينة</label>
                                <input type="text" name="training_name" id="training_name" value="<?php echo htmlspecialchars($trainingFormData['training_name'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="150" required>
                            </div>
                            <div class="form-group single-training-field-full">
                                <label for="training_price">سعر التمرينة</label>
                                <input type="number" name="price" id="training_price" value="<?php echo htmlspecialchars($trainingFormData['price'], ENT_QUOTES, 'UTF-8'); ?>" min="0.01" step="0.01" inputmode="decimal" required>
                            </div>
                        </div>

                        <div class="single-training-actions">
                            <button type="submit" class="btn btn-primary">حفظ التمرينة</button>
                        </div>
                    </form>
                </section>
            </div>

            <div class="single-training-tables">
                <section class="card single-training-table-card">
                    <div class="card-head table-card-head">
                        <div>
                            <h3>جدول أسعار التمرينات</h3>
                        </div>
                        <span class="table-counter"><?php echo count($trainings); ?></span>
                    </div>

                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>اسم التمرينة</th>
                                    <th>السعر</th>
                                    <th>تاريخ الإضافة</th>
                                    <th>أضيف بواسطة</th>
                                    <th>عدّل بواسطة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($trainings) === 0): ?>
                                    <tr>
                                        <td colspan="5" class="empty-cell">لا توجد تمرينات مسجلة.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($trainings as $training): ?>
                                        <tr>
                                            <td data-label="اسم التمرينة"><strong><?php echo htmlspecialchars($training['training_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                            <td data-label="السعر"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($training['price']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="تاريخ الإضافة"><?php echo htmlspecialchars((string)$training['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $training['created_by_user_id'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $training['updated_by_user_id'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card single-training-table-card">
                    <div class="card-head table-card-head">
                        <div>
                            <h3>جدول حضور لاعبي تمرينة واحدة</h3>
                        </div>
                        <span class="table-counter"><?php echo count($attendanceRecords); ?></span>
                    </div>

                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>وقت التسجيل</th>
                                    <th>اسم اللاعب</th>
                                    <th>رقم الهاتف</th>
                                    <th>التمرين</th>
                                    <th>السعر</th>
                                    <th>المدفوع</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($attendanceRecords) === 0): ?>
                                    <tr>
                                        <td colspan="7" class="empty-cell">لا توجد سجلات حضور.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($attendanceRecords as $record): ?>
                                        <tr>
                                            <td data-label="التاريخ"><?php echo htmlspecialchars((string)$record['attendance_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="وقت التسجيل"><?php echo htmlspecialchars(formatPlayerAttendanceActualTime($record['attended_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="اسم اللاعب"><strong><?php echo htmlspecialchars($record['player_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                            <td data-label="رقم الهاتف"><?php echo htmlspecialchars($record['player_phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="التمرين">
                                                <div class="single-training-table-stack">
                                                    <strong><?php echo htmlspecialchars($record['training_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                </div>
                                            </td>
                                            <td data-label="السعر"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($record['training_price']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="المدفوع"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($record['paid_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="assets/js/script.js"></script>
</body>
</html>
