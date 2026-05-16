<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "schedule_exceptions_support.php";

requireAuthenticatedUser();
requireMenuAccess("emergency-schedule");
ensureScheduleExceptionsTable($pdo);

function emergencyDateKeyToDayKey(DateTimeInterface $date)
{
    $dayMap = [
        "0" => "sunday",
        "1" => "monday",
        "2" => "tuesday",
        "3" => "wednesday",
        "4" => "thursday",
        "5" => "friday",
        "6" => "saturday",
    ];

    return $dayMap[$date->format("w")] ?? "";
}

function isValidEmergencyDateValue($value)
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("Y-m-d", $value, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $value;
}

function createEmergencyDateValue($value)
{
    return new DateTimeImmutable((string)$value . " 00:00:00", new DateTimeZone("Africa/Cairo"));
}

function getEmergencyWeekStart(DateTimeImmutable $date)
{
    $cursor = $date;
    while (emergencyDateKeyToDayKey($cursor) !== 'saturday') {
        $cursor = $cursor->modify('-1 day');
    }

    return $cursor;
}

function fetchEmergencyGroups(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, group_name, group_level, trainer_name, training_time, training_day_keys, training_day_times
         FROM sports_groups
         WHERE game_id = ?
         ORDER BY group_level ASC, group_name ASC, id ASC"
    );
    $stmt->execute([(int)$gameId]);

    $groups = [];
    foreach ($stmt->fetchAll() as $group) {
        $dayKeys = getPlayerTrainingDayKeys($group['training_day_keys'] ?? '');
        $dayTimes = decodePlayerScheduleDayTimes($group['training_day_times'] ?? '', $dayKeys, $group['training_time'] ?? '');
        $groups[(int)$group['id']] = [
            'id' => (int)$group['id'],
            'group_name' => (string)$group['group_name'],
            'group_level' => (string)$group['group_level'],
            'trainer_name' => trim((string)($group['trainer_name'] ?? '')),
            'training_time' => (string)($group['training_time'] ?? ''),
            'training_day_keys' => $dayKeys,
            'training_day_times' => $dayTimes,
        ];
    }

    return $groups;
}

function fetchEmergencyTrainerSchedulesByName(PDO $pdo, $gameId)
{
    $tablesStmt = $pdo->prepare(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('trainers', 'trainer_days_off', 'trainer_weekly_schedule')"
    );
    $tablesStmt->execute();
    $existingTables = array_column($tablesStmt->fetchAll(), 'TABLE_NAME');
    if (!in_array('trainers', $existingTables, true)) {
        return [];
    }

    $trainerStmt = $pdo->prepare(
        "SELECT id, name, attendance_time, departure_time
         FROM trainers
         WHERE game_id = ?
         ORDER BY name ASC, id ASC"
    );
    $trainerStmt->execute([(int)$gameId]);
    $trainers = $trainerStmt->fetchAll();
    if (count($trainers) === 0) {
        return [];
    }

    $trainerIds = array_map(static function ($trainer) {
        return (int)($trainer['id'] ?? 0);
    }, $trainers);
    $placeholders = implode(', ', array_fill(0, count($trainerIds), '?'));

    $daysOffByTrainer = [];
    if (in_array('trainer_days_off', $existingTables, true)) {
        $daysOffStmt = $pdo->prepare(
            "SELECT trainer_id, day_key
             FROM trainer_days_off
             WHERE trainer_id IN (" . $placeholders . ")"
        );
        $daysOffStmt->execute($trainerIds);
        foreach ($daysOffStmt->fetchAll() as $row) {
            $trainerId = (int)($row['trainer_id'] ?? 0);
            $dayKey = trim((string)($row['day_key'] ?? ''));
            if ($trainerId > 0 && isset(PLAYER_DAY_OPTIONS[$dayKey])) {
                $daysOffByTrainer[$trainerId][] = $dayKey;
            }
        }
    }

    $scheduleRowsByTrainer = [];
    if (in_array('trainer_weekly_schedule', $existingTables, true)) {
        $scheduleStmt = $pdo->prepare(
            "SELECT trainer_id, day_key, attendance_time, departure_time
             FROM trainer_weekly_schedule
             WHERE trainer_id IN (" . $placeholders . ")"
        );
        $scheduleStmt->execute($trainerIds);
        foreach ($scheduleStmt->fetchAll() as $row) {
            $trainerId = (int)($row['trainer_id'] ?? 0);
            $dayKey = trim((string)($row['day_key'] ?? ''));
            if ($trainerId > 0 && isset(PLAYER_DAY_OPTIONS[$dayKey])) {
                $scheduleRowsByTrainer[$trainerId][$dayKey] = [
                    'attendance_time' => normalizeTrainingTimeValue($row['attendance_time'] ?? ''),
                    'departure_time' => normalizeTrainingTimeValue($row['departure_time'] ?? ''),
                ];
            }
        }
    }

    $result = [];
    foreach ($trainers as $trainer) {
        $trainerId = (int)($trainer['id'] ?? 0);
        $trainerName = trim((string)($trainer['name'] ?? ''));
        if ($trainerId <= 0 || $trainerName === '') {
            continue;
        }

        $daysOff = sanitizePlayerTrainingDayKeys($daysOffByTrainer[$trainerId] ?? []);
        $defaultAttendance = normalizeTrainingTimeValue($trainer['attendance_time'] ?? '');
        $defaultDeparture = normalizeTrainingTimeValue($trainer['departure_time'] ?? '');
        $scheduleMap = [];
        foreach (PLAYER_DAY_OPTIONS as $dayKey => $_label) {
            if (in_array($dayKey, $daysOff, true)) {
                continue;
            }
            $rowSchedule = $scheduleRowsByTrainer[$trainerId][$dayKey] ?? null;
            $attendanceTime = normalizeTrainingTimeValue($rowSchedule['attendance_time'] ?? $defaultAttendance);
            $departureTime = normalizeTrainingTimeValue($rowSchedule['departure_time'] ?? $defaultDeparture);
            if ($attendanceTime === '' || $departureTime === '') {
                continue;
            }
            $scheduleMap[$dayKey] = [
                'attendance_time' => $attendanceTime,
                'departure_time' => $departureTime,
            ];
        }
        $result[$trainerName] = $scheduleMap;
    }

    return $result;
}

function getEmergencyGroupTrainingTime(array $group, DateTimeImmutable $date)
{
    $dayKey = emergencyDateKeyToDayKey($date);
    $time = normalizeTrainingTimeValue($group['training_day_times'][$dayKey] ?? '');
    if ($time !== '') {
        return $time;
    }

    if (in_array($dayKey, $group['training_day_keys'], true)) {
        return normalizeTrainingTimeValue($group['training_time'] ?? '');
    }

    return '';
}

function getEmergencyTrainerShift(array $trainerScheduleMap, $trainerName, DateTimeImmutable $date, $fallbackStart)
{
    $trainerName = trim((string)$trainerName);
    $dayKey = emergencyDateKeyToDayKey($date);
    $scheduleMap = $trainerScheduleMap[$trainerName] ?? [];
    $attendanceTime = normalizeTrainingTimeValue($scheduleMap[$dayKey]['attendance_time'] ?? $fallbackStart);
    $departureTime = normalizeTrainingTimeValue($scheduleMap[$dayKey]['departure_time'] ?? '');
    if ($departureTime === '' && $attendanceTime !== '') {
        try {
            $departureTime = (new DateTimeImmutable('2000-01-01 ' . $attendanceTime, new DateTimeZone('Africa/Cairo')))
                ->modify('+60 minutes')
                ->format('H:i:s');
        } catch (Throwable $throwable) {
            $departureTime = $attendanceTime;
        }
    }

    return [
        'attendance_time' => $attendanceTime,
        'departure_time' => $departureTime,
    ];
}

if (!isset($_SESSION['emergency_schedule_csrf_token'])) {
    $_SESSION['emergency_schedule_csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentGameId = (int)($_SESSION['selected_game_id'] ?? 0);
$currentGameName = (string)($_SESSION['selected_game_name'] ?? '');
$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings['academy_name'] ?? ($_SESSION['site_name'] ?? 'أكاديمية رياضية');
$sidebarLogo = $settings['academy_logo'] ?? ($_SESSION['site_logo'] ?? 'assets/images/logo.png');
$activeMenu = 'emergency-schedule';

$gamesStmt = $pdo->query("SELECT id, name FROM games WHERE status = 1 ORDER BY id ASC");
$allGames = $gamesStmt->fetchAll();
$allGameMap = [];
foreach ($allGames as $game) {
    $allGameMap[(int)$game['id']] = $game;
}

if ($currentGameId <= 0 || !isset($allGameMap[$currentGameId])) {
    header('Location: dashboard.php');
    exit;
}

$today = new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
$groups = fetchEmergencyGroups($pdo, $currentGameId);
$trainerSchedulesByName = fetchEmergencyTrainerSchedulesByName($pdo, $currentGameId);
$rows = fetchScheduleExceptionRows($pdo, $currentGameId);
$formData = [
    'group_id' => 0,
    'original_date' => $today->format('Y-m-d'),
    'replacement_date' => '',
    'replacement_start_time' => '',
    'replacement_end_time' => '',
    'apply_trainer_leave' => true,
    'apply_players_leave' => true,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['emergency_schedule_csrf_token'], $csrfToken)) {
        $error = 'الطلب غير صالح.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $formData = [
                'group_id' => (int)($_POST['group_id'] ?? 0),
                'original_date' => trim((string)($_POST['original_date'] ?? '')),
                'replacement_date' => trim((string)($_POST['replacement_date'] ?? '')),
                'replacement_start_time' => normalizeTrainingTimeValue($_POST['replacement_start_time'] ?? ''),
                'replacement_end_time' => normalizeTrainingTimeValue($_POST['replacement_end_time'] ?? ''),
                'apply_trainer_leave' => isset($_POST['apply_trainer_leave']),
                'apply_players_leave' => isset($_POST['apply_players_leave']),
            ];

            $group = $groups[$formData['group_id']] ?? null;
            $hasReplacement = $formData['replacement_date'] !== '' || $formData['replacement_start_time'] !== '' || $formData['replacement_end_time'] !== '';
            if (!$group) {
                $error = 'اختر مجموعة صالحة.';
            } elseif (!isValidEmergencyDateValue($formData['original_date'])) {
                $error = 'تاريخ الموعد الأصلي غير صالح.';
            } elseif (!$formData['apply_trainer_leave'] && !$formData['apply_players_leave']) {
                $error = 'اختر على الأقل احتساب إجازة للمدرب أو اللاعبين.';
            } else {
                $originalDate = createEmergencyDateValue($formData['original_date']);
                $originalStartTime = getEmergencyGroupTrainingTime($group, $originalDate);
                if ($originalStartTime === '') {
                    $error = 'لا يوجد ميعاد تدريب أصلي مسجل لهذه المجموعة في التاريخ المحدد.';
                } elseif ($hasReplacement && !isValidEmergencyDateValue($formData['replacement_date'])) {
                    $error = 'تاريخ الموعد البديل غير صالح.';
                } elseif ($hasReplacement && ($formData['replacement_start_time'] === '' || $formData['replacement_end_time'] === '')) {
                    $error = 'أدخل ميعاد الحضور والانصراف للموعد البديل.';
                } else {
                    $replacementDate = $hasReplacement ? createEmergencyDateValue($formData['replacement_date']) : null;
                    if ($replacementDate instanceof DateTimeImmutable && getEmergencyWeekStart($originalDate)->format('Y-m-d') !== getEmergencyWeekStart($replacementDate)->format('Y-m-d')) {
                        $error = 'يجب أن يكون الموعد البديل في نفس الأسبوع.';
                    }
                }
            }

            if ($error === '') {
                $group = $groups[$formData['group_id']];
                $originalDate = createEmergencyDateValue($formData['original_date']);
                $originalStartTime = getEmergencyGroupTrainingTime($group, $originalDate);
                $trainerShift = getEmergencyTrainerShift($trainerSchedulesByName, $group['trainer_name'], $originalDate, $originalStartTime);
                try {
                    $existingStmt = $pdo->prepare(
                        "SELECT id
                         FROM group_schedule_exceptions
                         WHERE game_id = ? AND group_id = ? AND original_date = ?
                         LIMIT 1"
                    );
                    $existingStmt->execute([(int)$currentGameId, (int)$group['id'], $originalDate->format('Y-m-d')]);
                    $existingId = (int)($existingStmt->fetchColumn() ?: 0);

                    if ($existingId > 0) {
                        $stmt = $pdo->prepare(
                            "UPDATE group_schedule_exceptions
                             SET trainer_name = ?, original_start_time = ?, original_end_time = ?, replacement_date = ?, replacement_start_time = ?, replacement_end_time = ?,
                                 apply_trainer_leave = ?, apply_players_leave = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $stmt->execute([
                            $group['trainer_name'],
                            $originalStartTime,
                            $trainerShift['departure_time'],
                            $formData['replacement_date'] !== '' ? $formData['replacement_date'] : null,
                            $formData['replacement_start_time'] !== '' ? $formData['replacement_start_time'] : null,
                            $formData['replacement_end_time'] !== '' ? $formData['replacement_end_time'] : null,
                            $formData['apply_trainer_leave'] ? 1 : 0,
                            $formData['apply_players_leave'] ? 1 : 0,
                            $currentUserId > 0 ? $currentUserId : null,
                            $existingId,
                            $currentGameId,
                        ]);
                        auditTrack($pdo, 'update', 'group_schedule_exceptions', $existingId, 'الطوارئ', 'تعديل طوارئ المجموعة: ' . $group['group_name']);
                        $success = 'تم تحديث الحالة الطارئة.';
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO group_schedule_exceptions (
                                game_id, group_id, trainer_name, original_date, original_start_time, original_end_time, replacement_date, replacement_start_time,
                                replacement_end_time, apply_trainer_leave, apply_players_leave, created_by_user_id, updated_by_user_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $stmt->execute([
                            $currentGameId,
                            $group['id'],
                            $group['trainer_name'],
                            $originalDate->format('Y-m-d'),
                            $originalStartTime,
                            $trainerShift['departure_time'],
                            $formData['replacement_date'] !== '' ? $formData['replacement_date'] : null,
                            $formData['replacement_start_time'] !== '' ? $formData['replacement_start_time'] : null,
                            $formData['replacement_end_time'] !== '' ? $formData['replacement_end_time'] : null,
                            $formData['apply_trainer_leave'] ? 1 : 0,
                            $formData['apply_players_leave'] ? 1 : 0,
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        auditTrack($pdo, 'create', 'group_schedule_exceptions', (int)$pdo->lastInsertId(), 'الطوارئ', 'إضافة طوارئ للمجموعة: ' . $group['group_name']);
                        $success = 'تم حفظ الحالة الطارئة.';
                    }
                    $rows = fetchScheduleExceptionRows($pdo, $currentGameId);
                } catch (Throwable $throwable) {
                    $error = 'تعذر حفظ الحالة الطارئة.';
                    error_log('Emergency schedule save error: ' . $throwable->getMessage());
                }
            }
        }

        if ($action === 'delete') {
            $exceptionId = (int)($_POST['exception_id'] ?? 0);
            if ($exceptionId <= 0) {
                $error = 'الحالة الطارئة غير صالحة.';
            } else {
                try {
                    $nameStmt = $pdo->prepare("SELECT group_name FROM group_schedule_exceptions gse INNER JOIN sports_groups sg ON sg.id = gse.group_id WHERE gse.id = ? AND gse.game_id = ? LIMIT 1");
                    $nameStmt->execute([$exceptionId, $currentGameId]);
                    $groupName = (string)($nameStmt->fetchColumn() ?: '');
                    $deleteStmt = $pdo->prepare("DELETE FROM group_schedule_exceptions WHERE id = ? AND game_id = ?");
                    $deleteStmt->execute([$exceptionId, $currentGameId]);
                    auditTrack($pdo, 'delete', 'group_schedule_exceptions', $exceptionId, 'الطوارئ', 'حذف طوارئ المجموعة: ' . $groupName);
                    $rows = fetchScheduleExceptionRows($pdo, $currentGameId);
                    $success = 'تم حذف الحالة الطارئة.';
                } catch (Throwable $throwable) {
                    $error = 'تعذر حذف الحالة الطارئة.';
                    error_log('Emergency schedule delete error: ' . $throwable->getMessage());
                }
            }
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
    <title>طوارئ المواعيد</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content categories-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>طوارئ المواعيد</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
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

        <?php if ($success !== ''): ?>
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="categories-grid">
            <div class="card categories-form-card">
                <div class="card-head">
                    <div><h3>تسجيل حالة طارئة</h3></div>
                </div>
                <form method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['emergency_schedule_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save">
                    <div class="categories-form-grid">
                        <div class="form-group category-field-full">
                            <label for="group_id">المجموعة</label>
                            <select name="group_id" id="group_id" required>
                                <option value="">اختر المجموعة</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo (int)$group['id']; ?>" <?php echo (int)$formData['group_id'] === (int)$group['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name'] . ' - ' . $group['group_level'] . ' - ' . $group['trainer_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="original_date">تاريخ الموعد الأصلي</label>
                            <input type="date" name="original_date" id="original_date" value="<?php echo htmlspecialchars($formData['original_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="replacement_date">تاريخ الموعد البديل</label>
                            <input type="date" name="replacement_date" id="replacement_date" value="<?php echo htmlspecialchars($formData['replacement_date'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="replacement_start_time">حضور الموعد البديل</label>
                            <input type="time" name="replacement_start_time" id="replacement_start_time" value="<?php echo htmlspecialchars(formatTrainingTimeLabel($formData['replacement_start_time']), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="replacement_end_time">انصراف الموعد البديل</label>
                            <input type="time" name="replacement_end_time" id="replacement_end_time" value="<?php echo htmlspecialchars(formatTrainingTimeLabel($formData['replacement_end_time']), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="form-group category-field-full">
                            <label class="pricing-option">
                                <input type="checkbox" name="apply_trainer_leave" value="1" <?php echo $formData['apply_trainer_leave'] ? 'checked' : ''; ?>>
                                <span class="pricing-option-body">احتساب المدرب إجازة في الموعد الأصلي</span>
                            </label>
                        </div>
                        <div class="form-group category-field-full">
                            <label class="pricing-option">
                                <input type="checkbox" name="apply_players_leave" value="1" <?php echo $formData['apply_players_leave'] ? 'checked' : ''; ?>>
                                <span class="pricing-option-body">احتساب لاعبي المجموعة إجازة في الموعد الأصلي</span>
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الحالة</button>
                </form>
            </div>

            <div class="card categories-table-card">
                <div class="card-head table-card-head">
                    <div><h3>الحالات المسجلة</h3></div>
                    <span class="table-counter">الإجمالي: <?php echo count($rows); ?></span>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>المجموعة</th>
                                <th>المدرب</th>
                                <th>الموعد الأصلي</th>
                                <th>البديل</th>
                                <th>الإجازة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rows) === 0): ?>
                                <tr><td colspan="6" class="empty-cell">لا توجد حالات مسجلة.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td data-label="المجموعة"><?php echo htmlspecialchars((string)$row['group_name'] . ' - ' . (string)$row['group_level'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="المدرب"><?php echo htmlspecialchars((string)$row['trainer_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الموعد الأصلي"><?php echo htmlspecialchars(formatScheduleExceptionDateLabel($row['original_date']) . ' - ' . formatScheduleExceptionTimeLabel($row['original_start_time']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="البديل">
                                            <?php
                                            $replacementLabel = '—';
                                            if (!empty($row['replacement_date'])) {
                                                $replacementLabel = formatScheduleExceptionDateLabel($row['replacement_date']) . ' - ' . formatScheduleExceptionTimeLabel($row['replacement_start_time']);
                                            }
                                            echo htmlspecialchars($replacementLabel, ENT_QUOTES, 'UTF-8');
                                            ?>
                                        </td>
                                        <td data-label="الإجازة"><?php echo htmlspecialchars(((int)$row['apply_trainer_leave'] === 1 ? 'المدرب' : '') . (((int)$row['apply_trainer_leave'] === 1 && (int)$row['apply_players_leave'] === 1) ? ' + ' : '') . ((int)$row['apply_players_leave'] === 1 ? 'اللاعبون' : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الإجراءات">
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['emergency_schedule_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="exception_id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="btn btn-danger" onclick="return confirm('هل تريد حذف الحالة الطارئة؟')">🗑️</button>
                                            </form>
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
