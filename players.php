<?php
require_once 'session.php';
startSecureSession();
require_once 'config.php';
require_once 'navigation.php';
require_once 'players_support.php';

requireAuthenticatedUser();
requireMenuAccess('players');
ensurePlayersTables($pdo);

if (!isset($_SESSION['players_csrf_token'])) {
    $_SESSION['players_csrf_token'] = bin2hex(random_bytes(32));
}

function fetchPlayersWithAttendance(PDO $pdo, $gameId, array $filters = [])
{
    $presentStatus = PLAYER_ATTENDANCE_STATUS_PRESENT;
    $sql = "SELECT
                p.id,
                p.game_id,
                p.group_id,
                p.barcode,
                p.name,
                p.phone,
                p.phone2,
                p.whatsapp_group_joined,
                p.player_category,
                p.subscription_start_date,
                p.subscription_end_date,
                p.group_name,
                p.group_level,
                p.player_level,
                p.receipt_number,
                p.subscriber_number,
                p.subscription_number,
                p.issue_date,
                p.birth_date,
                p.player_age,
                p.training_days_per_week,
                p.total_training_days,
                p.total_trainings,
                p.trainer_name,
                p.training_time,
                p.subscription_price,
                p.paid_amount,
                p.academy_percentage,
                p.academy_amount,
                p.training_day_keys,
                p.created_at,
                p.updated_at,
                p.created_by_user_id,
                p.updated_by_user_id,
                COUNT(pa.id) AS consumed_sessions_count,
                SUM(
                    CASE
                        WHEN COALESCE(NULLIF(pa.attendance_status, ''), ?) = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS attendance_count
            FROM players p
            LEFT JOIN player_attendance pa ON pa.player_id = p.id
                AND pa.attendance_date BETWEEN p.subscription_start_date AND p.subscription_end_date
            WHERE p.game_id = ?";
    $params = [$presentStatus, $presentStatus, (int)$gameId];

    if (!empty($filters['search'])) {
        $searchLike = '%' . $filters['search'] . '%';
        $sql .= ' AND (
            p.barcode LIKE ? OR p.name LIKE ? OR p.phone LIKE ? OR p.receipt_number LIKE ? OR
            p.subscriber_number LIKE ? OR p.subscription_number LIKE ?
        )';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    if (!empty($filters['group_id'])) {
        $sql .= ' AND p.group_id = ?';
        $params[] = (int)$filters['group_id'];
    }

    if (!empty($filters['group_level'])) {
        $sql .= ' AND p.group_level = ?';
        $params[] = (string)$filters['group_level'];
    }

    if (isset($filters['whatsapp_group_joined']) && $filters['whatsapp_group_joined'] !== '') {
        $sql .= ' AND p.whatsapp_group_joined = ?';
        $params[] = (int)$filters['whatsapp_group_joined'];
    }

    $sql .= ' GROUP BY p.id ORDER BY p.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function buildPlayerDisplayRow(array $player, DateTimeImmutable $today)
{
    $attendanceCount = (int)($player['attendance_count'] ?? 0);
    $consumedSessionsCount = (int)($player['consumed_sessions_count'] ?? 0);
    $daysRemaining = calculatePlayerDaysRemaining($player['subscription_end_date'] ?? '', $today);
    $remainingTrainings = calculatePlayerRemainingTrainings($player['total_trainings'] ?? 0, $consumedSessionsCount);
    $status = getPlayerSubscriptionStatus($daysRemaining, $remainingTrainings);

    $player['attendance_count'] = $attendanceCount;
    $player['consumed_sessions_count'] = $consumedSessionsCount;
    $player['days_remaining'] = $daysRemaining;
    $player['remaining_trainings'] = $remainingTrainings;
    $player['status'] = $status;
    $player['player_age'] = calculatePlayerAgeFromBirthDate($player['birth_date'] ?? '', $today);
    $player['training_day_labels'] = formatPlayerTrainingDaysLabel(getPlayerTrainingDayKeys($player['training_day_keys'] ?? ''));
    $player['training_time_label'] = formatTrainingTimeDisplay($player['training_time'] ?? '');
    return $player;
}

function normalizePlayerReturnTarget($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $allowedTargets = [
        'players.php',
        'groups.php',
        'player_seating.php',
    ];

    if (!in_array($value, $allowedTargets, true)) {
        return '';
    }

    return $value;
}

$success = '';
$error = '';
$isManager = (string)($_SESSION['role'] ?? '') === 'مدير';
$currentGameId = (int)($_SESSION['selected_game_id'] ?? 0);
$currentGameName = (string)($_SESSION['selected_game_name'] ?? '');
$isPentathlon = mb_stripos($currentGameName, 'خماسي') !== false;
$today = new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));

$settingsStmt = $pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1');
$settings = $settingsStmt->fetch() ?: [];
$sidebarName = $settings['academy_name'] ?? ($_SESSION['site_name'] ?? 'أكاديمية رياضية');
$sidebarLogo = $settings['academy_logo'] ?? ($_SESSION['site_logo'] ?? 'assets/images/logo.png');
$activeMenu = 'players';

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

$groupPlayerCounts = fetchGroupPlayerCounts($pdo, $currentGameId);
$groupsStmt = $pdo->prepare(
    'SELECT id, group_name, group_level, training_days_count, training_day_keys, training_time, trainings_count, exercises_count, max_players, trainer_name,
            academy_percentage, walkers_price, other_weapons_price, civilian_price
     FROM sports_groups
     WHERE game_id = ?
     ORDER BY group_level ASC, group_name ASC, id DESC'
);
$groupsStmt->execute([$currentGameId]);
$groups = $groupsStmt->fetchAll();
$groupMap = [];
foreach ($groups as $group) {
    $group['current_players_count'] = $groupPlayerCounts[(int)$group['id']] ?? 0;
    $group['training_day_keys_list'] = getPlayerTrainingDayKeys($group['training_day_keys'] ?? '');
    $group['training_time_label'] = formatTrainingTimeDisplay($group['training_time'] ?? '');
    $groupMap[(int)$group['id']] = $group;
}

$groupLevels = [];
foreach ($groups as $group) {
    $groupLevel = trim((string)($group['group_level'] ?? ''));
    if ($groupLevel !== '') {
        $groupLevels[$groupLevel] = $groupLevel;
    }
}
ksort($groupLevels, SORT_NATURAL);

$formData = [
    'id' => 0,
    'barcode' => '',
    'name' => '',
    'phone' => '',
    'phone2' => '',
    'whatsapp_group_joined' => 0,
    'player_category' => PLAYER_DEFAULT_CATEGORY,
    'subscription_start_date' => $today->format('Y-m-d'),
    'subscription_end_date' => $today->modify('+30 days')->format('Y-m-d'),
    'group_id' => '',
    'paid_amount' => '',
    'academy_percentage' => '0.00',
    'academy_amount' => '0.00',
    'subscription_price' => '0.00',
    'selected_group_level' => '',
    'group_level' => '',
    'player_level' => '',
    'receipt_number' => '',
    'subscriber_number' => '',
    'subscription_number' => '',
    'issue_date' => $today->format('Y-m-d'),
    'birth_date' => '',
    'player_age' => '',
    'training_days_per_week' => '',
    'total_training_days' => '',
    'total_trainings' => '',
    'trainer_name' => '',
    'training_time' => '',
    'training_day_keys' => [],
    'pentathlon_sub_game_sessions' => array_fill_keys(PENTATHLON_SUB_GAMES, 0),
];
$returnTarget = normalizePlayerReturnTarget($_POST['return_to'] ?? ($_GET['return_to'] ?? ''));
$presetGroupId = (int)($_GET['preset_group_id'] ?? 0);
$shouldOpenFormFromQuery = (int)($_GET['open_player_form'] ?? 0) === 1;

$flashSuccess = $_SESSION['players_success'] ?? '';
unset($_SESSION['players_success']);
if ($flashSuccess !== '') {
    $success = $flashSuccess;
}

// ========== معالجة طلبات AJAX للملفات ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    $ajaxAction = $_POST['ajax_action'] ?? '';
    $playerId = (int)($_POST['player_id'] ?? 0);
    
    // التحقق من الصلاحية
    if ($playerId <= 0) {
        echo json_encode(['success' => false, 'error' => 'بيانات غير صالحة.']);
        exit;
    }
    
    // التحقق من وجود اللاعب وانتمائه للعبة الحالية
    $checkPlayer = $pdo->prepare('SELECT id FROM players WHERE id = ? AND game_id = ?');
    $checkPlayer->execute([$playerId, $currentGameId]);
    if (!$checkPlayer->fetch()) {
        echo json_encode(['success' => false, 'error' => 'اللاعب غير موجود.']);
        exit;
    }
    
    if ($ajaxAction === 'get_files') {
        $files = getPlayerFiles($pdo, $playerId);
        $fileList = [];
        foreach ($files as $file) {
            $fileList[] = [
                'id' => $file['id'],
                'file_name' => $file['file_name'],
                'original_name' => $file['original_name'],
                'label' => $file['label'],
                'file_type' => $file['file_type'],
                'file_size' => $file['file_size'],
                'url' => 'uploads/player_files/' . $file['file_name']
            ];
        }
        echo json_encode(['success' => true, 'files' => $fileList]);
        exit;
    }

    if ($ajaxAction === 'get_subscription_history') {
        $currentPlayer = fetchPlayerRowById($pdo, $playerId, $currentGameId);
        $historyRows = fetchPlayerSubscriptionHistory($pdo, $playerId, $currentGameId);
        if ($currentPlayer && count($historyRows) === 0) {
            $historyRows[] = buildPlayerSubscriptionHistoryPayload($currentPlayer, 'save');
        }
        $historyList = [];

        foreach ($historyRows as $historyRow) {
            $dayLabels = formatPlayerTrainingDaysLabel(getPlayerTrainingDayKeys($historyRow['training_day_keys'] ?? ''));
            $daysRemaining = calculatePlayerDaysRemaining($historyRow['subscription_end_date'] ?? '', $today);
            $historyList[] = [
                'id' => (int)$historyRow['id'],
                'subscription_start_date' => (string)$historyRow['subscription_start_date'],
                'subscription_end_date' => (string)$historyRow['subscription_end_date'],
                'group_name' => (string)$historyRow['group_name'],
                'group_level' => (string)$historyRow['group_level'],
                'player_level' => (string)($historyRow['player_level'] ?? ''),
                'receipt_number' => (string)($historyRow['receipt_number'] ?? ''),
                'subscriber_number' => (string)($historyRow['subscriber_number'] ?? ''),
                'subscription_number' => (string)($historyRow['subscription_number'] ?? ''),
                'issue_date' => (string)($historyRow['issue_date'] ?? ''),
                'birth_date' => (string)($historyRow['birth_date'] ?? ''),
                'player_age' => calculatePlayerAgeFromBirthDate($historyRow['birth_date'] ?? '', $today),
                'trainer_name' => (string)$historyRow['trainer_name'],
                'player_category' => (string)$historyRow['player_category'],
                'training_days' => array_values($dayLabels),
                'training_time' => formatTrainingTimeDisplay($historyRow['training_time'] ?? ''),
                'subscription_price' => formatPlayerCurrencyLabel($historyRow['subscription_price'] ?? 0),
                'paid_amount' => formatPlayerCurrencyLabel($historyRow['paid_amount'] ?? 0),
                'academy_percentage' => formatPlayerPercentageLabel($historyRow['academy_percentage'] ?? 0),
                'academy_amount' => formatPlayerCurrencyLabel($historyRow['academy_amount'] ?? 0),
                'days_remaining' => $daysRemaining,
                'status' => getPlayerSubscriptionStatus(
                    $daysRemaining,
                    calculatePlayerRemainingTrainings($historyRow['total_trainings'] ?? 0, 0)
                ),
                'is_current' => $currentPlayer
                    && (string)$historyRow['subscription_start_date'] === (string)$currentPlayer['subscription_start_date']
                    && (string)$historyRow['subscription_end_date'] === (string)$currentPlayer['subscription_end_date'],
            ];
        }

        echo json_encode(['success' => true, 'history' => $historyList]);
        exit;
    }
    
    if ($ajaxAction === 'upload_files') {
        // رفع ملفات متعددة
        if (!isset($_FILES['player_files']) || empty($_FILES['player_files']['name'][0])) {
            echo json_encode(['success' => false, 'error' => 'لم يتم اختيار أي ملف.']);
            exit;
        }
        
        $labels = $_POST['labels'] ?? [];
        $uploaded = [];
        $errors = [];
        
        foreach ($_FILES['player_files']['tmp_name'] as $index => $tmpName) {
            if ($_FILES['player_files']['error'][$index] !== UPLOAD_ERR_OK) {
                $errors[] = 'فشل رفع الملف: ' . $_FILES['player_files']['name'][$index];
                continue;
            }
            $fileItem = [
                'name' => $_FILES['player_files']['name'][$index],
                'tmp_name' => $tmpName,
                'type' => $_FILES['player_files']['type'][$index],
                'size' => $_FILES['player_files']['size'][$index],
                'error' => $_FILES['player_files']['error'][$index]
            ];
            $label = $labels[$index] ?? '';
            $result = uploadPlayerFile($pdo, $playerId, $fileItem, $label);
            if ($result['success']) {
                $uploaded[] = $result['file_id'];
            } else {
                $errors[] = $result['error'] . ' (' . $fileItem['name'] . ')';
            }
        }
        
        echo json_encode(['success' => empty($errors), 'uploaded' => $uploaded, 'errors' => $errors]);
        exit;
    }
    
    if ($ajaxAction === 'delete_file') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if ($fileId <= 0) {
            echo json_encode(['success' => false, 'error' => 'معرف الملف غير صالح.']);
            exit;
        }
        $result = deletePlayerFile($pdo, $fileId, $playerId);
        echo json_encode($result);
        exit;
    }
    
    if ($ajaxAction === 'update_label') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $newLabel = $_POST['label'] ?? '';
        if ($fileId <= 0 || $newLabel === '') {
            echo json_encode(['success' => false, 'error' => 'بيانات غير صالحة.']);
            exit;
        }
        $result = updatePlayerFileLabel($pdo, $fileId, $playerId, $newLabel);
        echo json_encode($result);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'إجراء غير معروف.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['players_csrf_token'], $csrfToken)) {
        $error = 'الطلب غير صالح.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $formData = [
                'id' => (int)($_POST['player_id'] ?? 0),
                'barcode' => trim((string)($_POST['barcode'] ?? '')),
                'name' => trim((string)($_POST['name'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')),
                'phone2' => trim((string)($_POST['phone2'] ?? '')),
                'whatsapp_group_joined' => isset($_POST['whatsapp_group_joined']) ? 1 : 0,
                'player_category' => trim((string)($_POST['player_category'] ?? '')),
                'subscription_start_date' => trim((string)($_POST['subscription_start_date'] ?? '')),
                'subscription_end_date' => trim((string)($_POST['subscription_end_date'] ?? '')),
                'selected_group_level' => trim((string)($_POST['selected_group_level'] ?? '')),
                'player_level' => trim((string)($_POST['player_level'] ?? '')),
                'receipt_number' => trim((string)($_POST['receipt_number'] ?? '')),
                'subscriber_number' => trim((string)($_POST['subscriber_number'] ?? '')),
                'subscription_number' => trim((string)($_POST['subscription_number'] ?? '')),
                'issue_date' => trim((string)($_POST['issue_date'] ?? '')),
                'birth_date' => trim((string)($_POST['birth_date'] ?? '')),
                'player_age' => '',
                'group_id' => trim((string)($_POST['group_id'] ?? '')),
                'paid_amount' => normalizePlayerMoneyValue($_POST['paid_amount'] ?? ''),
                'training_day_keys' => sanitizePlayerTrainingDayKeys($_POST['training_day_keys'] ?? []),
                'academy_percentage' => '0.00',
                'academy_amount' => '0.00',
                'subscription_price' => '0.00',
                'group_level' => '',
                'training_days_per_week' => '',
                'total_training_days' => '',
                'total_trainings' => '',
                'trainer_name' => '',
                'training_time' => normalizeTrainingTimeValue($_POST['training_time'] ?? ''),
            ];

            $selectedGroupId = $formData['group_id'] === '' ? 0 : (int)$formData['group_id'];
            $selectedGroup = $groupMap[$selectedGroupId] ?? null;
            $selectedGroupPrice = $selectedGroup ? getPlayerGroupPriceByCategory($selectedGroup, $formData['player_category']) : 0;
            $selectedGroupTrainingDayKeys = $selectedGroup ? getPlayerTrainingDayKeys($selectedGroup['training_day_keys'] ?? '') : [];
            $selectedGroupTrainingTime = $selectedGroup ? normalizeTrainingTimeValue($selectedGroup['training_time'] ?? '') : '';

            if ($selectedGroup) {
                $formData['group_level'] = (string)$selectedGroup['group_level'];
                $formData['training_days_per_week'] = (string)count($formData['training_day_keys']);
                $formData['total_training_days'] = (string)(int)$selectedGroup['trainings_count'];
                $formData['total_trainings'] = (string)(int)$selectedGroup['exercises_count'];
                $formData['trainer_name'] = (string)$selectedGroup['trainer_name'];
                if (count($formData['training_day_keys']) === 0) {
                    $formData['training_day_keys'] = $selectedGroupTrainingDayKeys;
                    $formData['training_days_per_week'] = (string)count($formData['training_day_keys']);
                }
                if ($formData['training_time'] === '') {
                    $formData['training_time'] = $selectedGroupTrainingTime;
                }
                $formData['subscription_price'] = formatPlayerCurrency($selectedGroupPrice);
                $formData['academy_percentage'] = formatPlayerCurrency($selectedGroup['academy_percentage'] ?? 0);
                $formData['academy_amount'] = formatPlayerCurrency(calculatePlayerAcademyAmount($formData['paid_amount'] === '' ? 0 : $formData['paid_amount'], $selectedGroup['academy_percentage'] ?? 0));
            }

            $formData['player_age'] = $formData['birth_date'] !== ''
                ? (string)calculatePlayerAgeFromBirthDate($formData['birth_date'], $today)
                : '';

            if ($formData['name'] === '') {
                $error = 'اسم اللاعب مطلوب.';
            } elseif ($formData['phone'] === '') {
                $error = 'رقم الهاتف مطلوب.';
            } elseif (preg_match('/^[0-9]{11}$/', $formData['phone']) !== 1) {
                $error = 'رقم الهاتف يجب أن يتكون من 11 رقم بالضبط.';
            } elseif ($formData['phone2'] !== '' && preg_match('/^[0-9]{11}$/', $formData['phone2']) !== 1) {
                $error = 'رقم الهاتف الثاني يجب أن يتكون من 11 رقم بالضبط.';
            } elseif ($formData['barcode'] === '') {
                $error = 'باركود اللاعب مطلوب.';
            } elseif (strlen($formData['barcode']) > PLAYER_BARCODE_MAX_LENGTH) {
                $error = 'باركود اللاعب طويل جدًا.';
            } elseif ($formData['selected_group_level'] === '') {
                $error = 'مستوى مجموعة اللاعب مطلوب.';
            } elseif (!isset($groupLevels[$formData['selected_group_level']])) {
                $error = 'مستوى مجموعة اللاعب غير متاح.';
            } elseif ($formData['player_level'] === '') {
                $error = 'مستوى اللاعب مطلوب.';
            } elseif (strlen($formData['player_level']) > PLAYER_LEVEL_MAX_LENGTH) {
                $error = 'مستوى اللاعب طويل جدًا.';
            } elseif ($formData['receipt_number'] === '') {
                $error = 'رقم الإيصال مطلوب.';
            } elseif (strlen($formData['receipt_number']) > 100) {
                $error = 'رقم الإيصال طويل جدًا.';
            } elseif ($formData['subscriber_number'] === '') {
                $error = 'رقم المشترك مطلوب.';
            } elseif (strlen($formData['subscriber_number']) > 100) {
                $error = 'رقم المشترك طويل جدًا.';
            } elseif ($formData['subscription_number'] === '') {
                $error = 'رقم الاشتراك مطلوب.';
            } elseif (strlen($formData['subscription_number']) > 100) {
                $error = 'رقم الاشتراك طويل جدًا.';
            } elseif (!playerCategoryExists($formData['player_category'])) {
                $error = 'تصنيف اللاعب غير صحيح.';
            } elseif ($selectedGroupId <= 0 || !$selectedGroup) {
                $error = 'المجموعة المحددة غير متاحة.';
            } elseif ($formData['selected_group_level'] !== (string)$selectedGroup['group_level']) {
                $error = 'المجموعة المحددة لا تطابق مستوى المجموعة المختار.';
            } elseif (!isValidPlayerDate($formData['issue_date'])) {
                $error = 'تاريخ الإصدار غير صحيح.';
            } elseif (!isValidPlayerDate($formData['birth_date'])) {
                $error = 'تاريخ ميلاد اللاعب غير صحيح.';
            } elseif (createPlayerDate($formData['birth_date']) > $today) {
                $error = 'تاريخ ميلاد اللاعب لا يمكن أن يكون في المستقبل.';
            } elseif (!isValidPlayerDate($formData['subscription_start_date'])) {
                $error = 'تاريخ بداية الاشتراك غير صحيح.';
            } elseif (!isValidPlayerDate($formData['subscription_end_date'])) {
                $error = 'تاريخ نهاية الاشتراك غير صحيح.';
            } elseif (createPlayerDate($formData['subscription_end_date']) <= createPlayerDate($formData['subscription_start_date'])) {
                $error = 'تاريخ نهاية الاشتراك يجب أن يكون بعد تاريخ البداية.';
            } elseif ($formData['paid_amount'] === '') {
                $error = 'المبلغ المدفوع غير صحيح.';
            } elseif ((float)$formData['paid_amount'] > (float)$selectedGroupPrice) {
                $error = 'المبلغ المدفوع لا يمكن أن يتجاوز سعر الاشتراك.';
            } elseif (count($formData['training_day_keys']) === 0) {
                $error = 'يجب تحديد يوم تمرين واحد على الأقل للاعب.';
            } elseif ($formData['training_time'] === '') {
                $error = 'ميعاد تمرين اللاعب غير صحيح.';
            } elseif (countPlayersInGroup($pdo, $currentGameId, $selectedGroupId, $formData['id']) >= (int)($selectedGroup['max_players'] ?? 0)) {
                $error = 'لا يمكن تسجيل لاعب جديد في هذه المجموعة لأن العدد وصل إلى الحد الأقصى.';
            }

            $existingPlayer = null;
            if ($error === '' && $formData['id'] > 0) {
                $playerExistsStmt = $pdo->prepare(
                    'SELECT id, name, group_level, player_level
                     FROM players
                     WHERE id = ? AND game_id = ?
                     LIMIT 1'
                );
                $playerExistsStmt->execute([$formData['id'], $currentGameId]);
                $existingPlayer = $playerExistsStmt->fetch();
                if (!$existingPlayer) {
                    $error = 'اللاعب غير متاح.';
                }
            }

            if ($error === '' && playerFieldExists($pdo, $currentGameId, 'phone', $formData['phone'], $formData['id'])) {
                $error = 'رقم الهاتف مستخدم بالفعل.';
            }

            if ($error === '' && $formData['barcode'] !== '' && playerFieldExists($pdo, $currentGameId, 'barcode', $formData['barcode'], $formData['id'])) {
                $error = 'باركود اللاعب مستخدم بالفعل.';
            }

            if ($error === '') {
                $subscriptionPrice = getPlayerGroupPriceByCategory($selectedGroup, $formData['player_category']);
                $academyPercentage = (float)($selectedGroup['academy_percentage'] ?? 0);
                $academyAmount = calculatePlayerAcademyAmount($formData['paid_amount'], $academyPercentage);
                $trainingDayValue = implode(PLAYER_DAY_SEPARATOR, $formData['training_day_keys']);
                $trainingTimeValue = $formData['training_time'];
                $trainingDaysPerWeek = count($formData['training_day_keys']);

                try {
                    $pdo->beginTransaction();
                    if ($formData['id'] > 0) {
                        syncPlayerSubscriptionHistoryFromPlayerId($pdo, $formData['id'], $currentGameId, 'pre_save');
                        $saveStmt = $pdo->prepare(
                            'UPDATE players
                             SET group_id = ?, barcode = ?, name = ?, phone = ?, phone2 = ?, whatsapp_group_joined = ?, player_category = ?,
                                  subscription_start_date = ?, subscription_end_date = ?, group_name = ?, group_level = ?,
                                  player_level = ?, receipt_number = ?, subscriber_number = ?, subscription_number = ?, issue_date = ?, birth_date = ?, player_age = ?,
                                  training_days_per_week = ?, total_training_days = ?, total_trainings = ?, trainer_name = ?,
                                  subscription_price = ?, paid_amount = ?, academy_percentage = ?, academy_amount = ?,
                                  training_day_keys = ?, training_time = ?
                              WHERE id = ? AND game_id = ?'
                        );
                        $saveStmt->execute([
                            $selectedGroupId,
                            $formData['barcode'],
                            $formData['name'],
                            $formData['phone'],
                            $formData['phone2'],
                            (int)$formData['whatsapp_group_joined'],
                            $formData['player_category'],
                            $formData['subscription_start_date'],
                            $formData['subscription_end_date'],
                            $selectedGroup['group_name'],
                            $selectedGroup['group_level'],
                            $formData['player_level'],
                            $formData['receipt_number'],
                            $formData['subscriber_number'],
                            $formData['subscription_number'],
                            $formData['issue_date'],
                            $formData['birth_date'],
                            (int)$formData['player_age'],
                            $trainingDaysPerWeek,
                            (int)$selectedGroup['trainings_count'],
                            (int)$selectedGroup['exercises_count'],
                            $selectedGroup['trainer_name'],
                            formatPlayerCurrency($subscriptionPrice),
                            $formData['paid_amount'],
                            formatPlayerCurrency($academyPercentage),
                            formatPlayerCurrency($academyAmount),
                            $trainingDayValue,
                            $trainingTimeValue,
                            $formData['id'],
                            $currentGameId,
                        ]);
                        syncPlayerSubscriptionHistoryFromPlayerId($pdo, $formData['id'], $currentGameId, 'save');
                        if ($isPentathlon) {
                            $pentathlonInputSessions = [];
                            foreach (PENTATHLON_SUB_GAMES as $sg) {
                                $pentathlonInputSessions[$sg] = (int)($_POST['pentathlon_sessions'][$sg] ?? 0);
                            }
                            savePentathlonPlayerSubGameSessions($pdo, $formData['id'], $currentGameId, $pentathlonInputSessions);
                        }
                        $previousPlayerLevel = trim((string)($existingPlayer['player_level'] ?? ''));
                        $newPlayerLevel = trim((string)$formData['player_level']);
                        if ($previousPlayerLevel !== $newPlayerLevel) {
                            $levelMessage = "تم تحديث مستوى اللاعب من الإدارة."
                                . "\nالمستوى السابق: " . ($previousPlayerLevel !== '' ? $previousPlayerLevel : 'غير محدد')
                                . "\nالمستوى الجديد: " . ($newPlayerLevel !== '' ? $newPlayerLevel : 'غير محدد');
                            createPlayerNotification(
                                $pdo,
                                $currentGameId,
                                $formData['id'],
                                '🏆 تم تحديث المستوى',
                                $levelMessage,
                                'administrative',
                                'important'
                            );
                        }
                        auditTrack($pdo, "update", "players", $formData['id'], "اللاعبين", "تعديل بيانات لاعب: " . (string)$formData['name']);
                        $_SESSION['players_success'] = 'تم تحديث بيانات اللاعب.';
                    } else {
                        $saveStmt = $pdo->prepare(
                            'INSERT INTO players (
                                game_id, group_id, barcode, name, phone, phone2, whatsapp_group_joined, player_category,
                                subscription_start_date, subscription_end_date, group_name, group_level,
                                player_level, receipt_number, subscriber_number, subscription_number, issue_date, birth_date, player_age,
                                training_days_per_week, total_training_days, total_trainings, trainer_name,
                                subscription_price, paid_amount, academy_percentage, academy_amount, training_day_keys, training_time, password
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $saveStmt->execute([
                            $currentGameId,
                            $selectedGroupId,
                            $formData['barcode'],
                            $formData['name'],
                            $formData['phone'],
                            $formData['phone2'],
                            (int)$formData['whatsapp_group_joined'],
                            $formData['player_category'],
                            $formData['subscription_start_date'],
                            $formData['subscription_end_date'],
                            $selectedGroup['group_name'],
                            $selectedGroup['group_level'],
                            $formData['player_level'],
                            $formData['receipt_number'],
                            $formData['subscriber_number'],
                            $formData['subscription_number'],
                            $formData['issue_date'],
                            $formData['birth_date'],
                            (int)$formData['player_age'],
                            $trainingDaysPerWeek,
                            (int)$selectedGroup['trainings_count'],
                            (int)$selectedGroup['exercises_count'],
                            $selectedGroup['trainer_name'],
                            formatPlayerCurrency($subscriptionPrice),
                            $formData['paid_amount'],
                            formatPlayerCurrency($academyPercentage),
                            formatPlayerCurrency($academyAmount),
                            $trainingDayValue,
                            $trainingTimeValue,
                            '123456',
                        ]);
                        $newPlayerId = (int)$pdo->lastInsertId();
                        syncPlayerSubscriptionHistoryFromPlayerId($pdo, $newPlayerId, $currentGameId, 'save');
                        if ($isPentathlon) {
                            $pentathlonInputSessions = [];
                            foreach (PENTATHLON_SUB_GAMES as $sg) {
                                $pentathlonInputSessions[$sg] = (int)($_POST['pentathlon_sessions'][$sg] ?? 0);
                            }
                            savePentathlonPlayerSubGameSessions($pdo, $newPlayerId, $currentGameId, $pentathlonInputSessions);
                        }
                        auditTrack($pdo, "create", "players", $newPlayerId, "اللاعبين", "تسجيل لاعب: " . (string)$formData['name']);
                        $_SESSION['players_success'] = 'تم تسجيل اللاعب بنجاح.';
                    }
                    $pdo->commit();
                    header('Location: ' . ($returnTarget !== '' ? $returnTarget : 'players.php'));
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'تعذر حفظ بيانات اللاعب.';
                }
            }
        }

        if ($action === 'change_player_password') {
            $changePlayerId = (int)($_POST['player_id'] ?? 0);
            $newPlayerPassword = (string)($_POST['new_player_password'] ?? '');
            if ($changePlayerId <= 0) {
                $error = 'اللاعب غير صالح.';
            } elseif (strlen($newPlayerPassword) < 4) {
                $error = 'كلمة السر يجب أن تكون 4 أحرف على الأقل.';
            } else {
                $checkChangeStmt = $pdo->prepare('SELECT id, name FROM players WHERE id = ? AND game_id = ? LIMIT 1');
                $checkChangeStmt->execute([$changePlayerId, $currentGameId]);
                $changePlayerRow = $checkChangeStmt->fetch();
                if (!$changePlayerRow) {
                    $error = 'اللاعب غير موجود.';
                } else {
                    try {
                        $newHash = password_hash($newPlayerPassword, PASSWORD_DEFAULT);
                        $updPwd = $pdo->prepare('UPDATE players SET password = ? WHERE id = ? AND game_id = ?');
                        $updPwd->execute([$newHash, $changePlayerId, $currentGameId]);
                        auditLogActivity($pdo, "update", "players", $changePlayerId, "اللاعبين", "تغيير كلمة سر اللاعب: " . (string)$changePlayerRow['name']);
                        $_SESSION['players_success'] = 'تم تغيير كلمة سر اللاعب: ' . (string)$changePlayerRow['name'];
                        header('Location: ' . ($returnTarget !== '' ? $returnTarget : 'players.php'));
                        exit;
                    } catch (Throwable $throwable) {
                        $error = 'تعذر تغيير كلمة السر.';
                    }
                }
            }
        }

        if ($action === 'delete') {
            $deletePlayerId = (int)($_POST['player_id'] ?? 0);
            if ($deletePlayerId <= 0) {
                $error = 'اللاعب غير صالح.';
            } else {
                $deletedNameStmt = $pdo->prepare('SELECT name FROM players WHERE id = ? AND game_id = ? LIMIT 1');
                $deletedNameStmt->execute([$deletePlayerId, $currentGameId]);
                $deletedPlayerName = (string)($deletedNameStmt->fetchColumn() ?: '');
                $deleteStmt = $pdo->prepare('DELETE FROM players WHERE id = ? AND game_id = ?');
                try {
                    $deleteStmt->execute([$deletePlayerId, $currentGameId]);
                    if ($deleteStmt->rowCount() === 0) {
                        $error = 'اللاعب غير متاح.';
                    } else {
                        auditLogActivity($pdo, "delete", "players", $deletePlayerId, "اللاعبين", "حذف لاعب: " . $deletedPlayerName);
                        $_SESSION['players_success'] = 'تم حذف اللاعب.';
                        header('Location: ' . ($returnTarget !== '' ? $returnTarget : 'players.php'));
                        exit;
                    }
                } catch (Throwable $throwable) {
                    $error = 'تعذر حذف اللاعب.';
                }
            }
        }
    }
}

$editPlayerId = (int)($_GET['edit'] ?? 0);
if ($editPlayerId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editStmt = $pdo->prepare(
        'SELECT id, group_id, barcode, name, phone, phone2, whatsapp_group_joined, player_category, subscription_start_date, subscription_end_date,
                group_level, player_level, receipt_number, subscriber_number, subscription_number, issue_date, birth_date, player_age,
                training_days_per_week, total_training_days, total_trainings, trainer_name,
                subscription_price, paid_amount, academy_percentage, academy_amount, training_day_keys, training_time
         FROM players
         WHERE id = ? AND game_id = ?
         LIMIT 1'
    );
    $editStmt->execute([$editPlayerId, $currentGameId]);
    $editPlayer = $editStmt->fetch();
    if ($editPlayer) {
        $formData = [
            'id' => (int)$editPlayer['id'],
            'barcode' => (string)$editPlayer['barcode'],
            'name' => (string)$editPlayer['name'],
            'phone' => (string)$editPlayer['phone'],
            'phone2' => (string)($editPlayer['phone2'] ?? ''),
            'whatsapp_group_joined' => (int)($editPlayer['whatsapp_group_joined'] ?? 0),
            'player_category' => (string)$editPlayer['player_category'],
            'subscription_start_date' => (string)$editPlayer['subscription_start_date'],
            'subscription_end_date' => (string)$editPlayer['subscription_end_date'],
            'selected_group_level' => (string)$editPlayer['group_level'],
            'player_level' => (string)($editPlayer['player_level'] ?? ''),
            'receipt_number' => (string)($editPlayer['receipt_number'] ?? ''),
            'subscriber_number' => (string)($editPlayer['subscriber_number'] ?? ''),
            'subscription_number' => (string)($editPlayer['subscription_number'] ?? ''),
            'issue_date' => (string)($editPlayer['issue_date'] ?? $today->format('Y-m-d')),
            'birth_date' => (string)($editPlayer['birth_date'] ?? ''),
            'player_age' => (string)calculatePlayerAgeFromBirthDate($editPlayer['birth_date'] ?? '', $today),
            'group_id' => (string)(int)$editPlayer['group_id'],
            'paid_amount' => formatPlayerCurrency($editPlayer['paid_amount'] ?? 0),
            'academy_percentage' => formatPlayerCurrency($editPlayer['academy_percentage'] ?? 0),
            'academy_amount' => formatPlayerCurrency($editPlayer['academy_amount'] ?? 0),
            'subscription_price' => formatPlayerCurrency($editPlayer['subscription_price'] ?? 0),
            'group_level' => (string)$editPlayer['group_level'],
            'training_days_per_week' => (string)(int)$editPlayer['training_days_per_week'],
            'total_training_days' => (string)(int)$editPlayer['total_training_days'],
            'total_trainings' => (string)(int)$editPlayer['total_trainings'],
            'trainer_name' => (string)$editPlayer['trainer_name'],
            'training_time' => normalizeTrainingTimeValue($editPlayer['training_time'] ?? ''),
            'training_day_keys' => getPlayerTrainingDayKeys($editPlayer['training_day_keys'] ?? ''),
            'pentathlon_sub_game_sessions' => $isPentathlon
                ? fetchPentathlonPlayerSubGameSessions($pdo, (int)$editPlayer['id'])
                : array_fill_keys(PENTATHLON_SUB_GAMES, 0),
        ];
    }
}

if ($presetGroupId > 0 && $formData['id'] === 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $presetGroup = $groupMap[$presetGroupId] ?? null;
    if ($presetGroup) {
        $presetTrainingTime = normalizeTrainingTimeValue($presetGroup['training_time'] ?? '');
        $presetTrainingDayKeys = getPlayerTrainingDayKeys($presetGroup['training_day_keys'] ?? '');
        $formData['group_id'] = (string)(int)$presetGroup['id'];
        $formData['selected_group_level'] = (string)$presetGroup['group_level'];
        $formData['group_level'] = (string)$presetGroup['group_level'];
        $formData['training_days_per_week'] = (string)count($presetTrainingDayKeys);
        $formData['total_training_days'] = (string)(int)$presetGroup['trainings_count'];
        $formData['total_trainings'] = (string)(int)$presetGroup['exercises_count'];
        $formData['trainer_name'] = (string)$presetGroup['trainer_name'];
        $formData['training_time'] = $presetTrainingTime;
        $formData['training_day_keys'] = $presetTrainingDayKeys;
        $formData['subscription_price'] = formatPlayerCurrency(getPlayerGroupPriceByCategory($presetGroup, $formData['player_category']));
        $formData['academy_percentage'] = formatPlayerCurrency($presetGroup['academy_percentage'] ?? 0);
    }
}

$rawSearchTerm = strip_tags(trim((string)($_GET['search'] ?? '')));
$searchTerm = function_exists('mb_substr') ? mb_substr($rawSearchTerm, 0, 100) : substr($rawSearchTerm, 0, 100);
$filterGroupId = (int)($_GET['filter_group_id'] ?? 0);
$filterGroupLevel = trim((string)($_GET['filter_group_level'] ?? ''));
$filterStatus = trim((string)($_GET['filter_status'] ?? ''));
$filterJoined = isset($_GET['filter_joined']) ? trim((string)$_GET['filter_joined']) : '';

$allPlayers = array_map(function ($row) use ($today) {
    return buildPlayerDisplayRow($row, $today);
}, fetchPlayersWithAttendance($pdo, $currentGameId));

$filteredPlayers = array_map(function ($row) use ($today) {
    return buildPlayerDisplayRow($row, $today);
}, fetchPlayersWithAttendance($pdo, $currentGameId, [
    'search' => $searchTerm,
    'group_id' => $filterGroupId,
    'group_level' => $filterGroupLevel,
    'whatsapp_group_joined' => $filterJoined,
]));

if ($filterStatus !== '') {
    $filteredPlayers = array_values(array_filter($filteredPlayers, function ($player) use ($filterStatus) {
        return $player['status'] === $filterStatus;
    }));
}

$pentathlonSessionsMap = [];
if ($isPentathlon && count($filteredPlayers) > 0) {
    $playerIdsForPentathlon = array_map(fn($p) => (int)$p['id'], $filteredPlayers);
    $placeholdersPentathlon = implode(',', array_fill(0, count($playerIdsForPentathlon), '?'));
    $pentathlonStmt = $pdo->prepare(
        "SELECT player_id, sub_game, total_sessions
         FROM pentathlon_player_sub_game_sessions
         WHERE player_id IN ($placeholdersPentathlon)"
    );
    $pentathlonStmt->execute($playerIdsForPentathlon);
    foreach ($pentathlonStmt->fetchAll() as $pentathlonRow) {
        $pid = (int)$pentathlonRow['player_id'];
        if (!isset($pentathlonSessionsMap[$pid])) {
            $pentathlonSessionsMap[$pid] = array_fill_keys(PENTATHLON_SUB_GAMES, 0);
        }
        $sg = (string)$pentathlonRow['sub_game'];
        if (isset($pentathlonSessionsMap[$pid][$sg])) {
            $pentathlonSessionsMap[$pid][$sg] = (int)$pentathlonRow['total_sessions'];
        }
    }
}

$totalPlayersCount = count($allPlayers);
$activeSubscriptionsCount = count(array_filter($allPlayers, function ($player) {
    return $player['status'] === 'مستمر';
}));
$endedSubscriptionsCount = count(array_filter($allPlayers, function ($player) {
    return $player['status'] === 'منتهي';
}));
$displayedPlayersCount = count($filteredPlayers);
$playersTableColumnCount = $isManager ? 24 : 22;
if ($isPentathlon) {
    $playersTableColumnCount++;
}

// ========== جزء التصدير المعدل لـ XLSX ==========
if (($_GET['export'] ?? '') === 'xlsx') {
    try {
        // تجهيز الرؤوس
        $headers = [
            'رقم الصف',
            'باركود اللاعب',
            'اسم اللاعب',
            'رقم الهاتف',
            'مستوى اللاعب',
            'رقم الإيصال',
            'رقم المشترك',
            'رقم الاشتراك',
            'تاريخ الإصدار',
            'تاريخ الميلاد',
            'سن اللاعب',
            'تصنيف اللاعب',
            'المجموعة',
            'مستوى المجموعة',
            'أيام التمرين المختارة',
            'ميعاد التمرين',
            'عدد أيام التمرين بالأسبوع',
            'إجمالي عدد الأيام',
            'عدد التمرينات',
            'المدرب',
            'تاريخ بداية الاشتراك',
            'تاريخ نهاية الاشتراك',
            'عدد الأيام المتبقية',
            'عدد الحضور',
            'عدد التمرينات المتبقية',
            'سعر الاشتراك',
            'المدفوع',
        ];
        if ($isManager) {
            $headers[] = 'نسبة الأكاديمية';
            $headers[] = 'مبلغ الأكاديمية';
        }
        $headers[] = 'حالة الاشتراك';

        // تجهيز الصفوف
        $rows = [];
        foreach ($filteredPlayers as $index => $player) {
            $row = [
                $index + 1,
                (string)$player['barcode'],
                (string)$player['name'],
                (string)$player['phone'],
                (string)$player['player_level'],
                (string)$player['receipt_number'],
                (string)$player['subscriber_number'],
                (string)$player['subscription_number'],
                (string)$player['issue_date'],
                (string)$player['birth_date'],
                (string)(int)$player['player_age'],
                (string)$player['player_category'],
                (string)$player['group_name'],
                (string)$player['group_level'],
                implode(' - ', $player['training_day_labels']),
                (string)$player['training_time_label'],
                (string)(int)$player['training_days_per_week'],
                (string)(int)$player['total_training_days'],
                (string)(int)$player['total_trainings'],
                (string)$player['trainer_name'],
                (string)$player['subscription_start_date'],
                (string)$player['subscription_end_date'],
                (string)(int)$player['days_remaining'],
                (string)(int)$player['attendance_count'],
                (string)(int)$player['remaining_trainings'],
                formatPlayerCurrencyLabel($player['subscription_price']),
                formatPlayerCurrencyLabel($player['paid_amount']),
            ];
            if ($isManager) {
                $row[] = formatPlayerPercentageLabel($player['academy_percentage']);
                $row[] = formatPlayerCurrencyLabel($player['academy_amount']);
            }
            $row[] = (string)$player['status'];
            $rows[] = $row;
        }

        // استدعاء دالة التصدير المحسّنة
        outputPlayersXlsxDownload('players.xlsx', $headers, $rows);
    } catch (Throwable $throwable) {
        $error = 'تعذر تصدير ملف Excel: ' . $throwable->getMessage();
    }
}
// ========== نهاية جزء التصدير ==========

$shouldOpenForm = $error !== '' || $formData['id'] > 0;
$hasGroups = count($groups) > 0;
$playersExportQuery = http_build_query(array_filter([
    'search' => $searchTerm,
    'filter_group_id' => $filterGroupId > 0 ? $filterGroupId : null,
    'filter_group_level' => $filterGroupLevel !== '' ? $filterGroupLevel : null,
    'filter_status' => $filterStatus !== '' ? $filterStatus : null,
    'export' => 'xlsx',
], function ($value) {
    return $value !== null;
}));

$groupsJson = [];
foreach ($groups as $group) {
    $groupsJson[(string)(int)$group['id']] = [
        'group_name' => (string)$group['group_name'],
        'group_level' => (string)$group['group_level'],
        'training_days_count' => (int)$group['training_days_count'],
        'training_day_keys' => array_values(getPlayerTrainingDayKeys($group['training_day_keys'] ?? '')),
        'training_time' => formatTrainingTimeLabel($group['training_time'] ?? ''),
        'trainings_count' => (int)$group['trainings_count'],
        'exercises_count' => (int)$group['exercises_count'],
        'max_players' => (int)($group['max_players'] ?? 0),
        'current_players_count' => (int)($group['current_players_count'] ?? 0),
        'has_available_slot' => (int)($group['current_players_count'] ?? 0) < (int)($group['max_players'] ?? 0),
        'trainer_name' => (string)$group['trainer_name'],
        'academy_percentage' => formatPlayerCurrency($group['academy_percentage'] ?? 0),
        'walkers_price' => formatPlayerCurrency($group['walkers_price'] ?? 0),
        'other_weapons_price' => formatPlayerCurrency($group['other_weapons_price'] ?? 0),
        'civilian_price' => formatPlayerCurrency($group['civilian_price'] ?? 0),
    ];
}
$groupLevelOptions = array_values($groupLevels);
$cancelTarget = $returnTarget !== '' ? $returnTarget : 'players.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اللاعبين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .player-search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }
        .player-filter-select-wrapper {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 160px;
        }
        .player-filter-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted, #6b7280);
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding-inline-start: 2px;
            white-space: nowrap;
        }
        .player-filter-select {
            position: relative;
        }
        .player-filter-select select {
            width: 100%;
            padding: 8px 32px 8px 12px;
            border: 1.5px solid var(--border-color, #e5e7eb);
            border-radius: 10px;
            background: var(--card-bg, #fff);
            color: var(--text-primary, #111827);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            appearance: none;
            -webkit-appearance: none;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: left 10px center;
            background-size: 14px;
        }
        .player-filter-select select:hover {
            border-color: var(--primary, #2563eb);
            background-color: var(--hover-bg, #f8faff);
        }
        .player-filter-select select:focus {
            outline: none;
            border-color: var(--primary, #2563eb);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .player-filter-select select:not([value=""]):not(:invalid) {
            border-color: var(--primary, #2563eb);
            background-color: rgba(37, 99, 235, 0.04);
        }
        .player-filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            padding-bottom: 0;
        }
        .trainer-search-field {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 220px;
        }
        @media (max-width: 768px) {
            .player-filter-select-wrapper {
                min-width: 140px;
                flex: 1;
            }
            .player-filter-actions {
                width: 100%;
            }
            .player-filter-actions .btn {
                flex: 1;
                text-align: center;
            }
        }
    </style>
</head>
    <body class="dashboard-page" data-open-player-form="<?php echo ($shouldOpenForm || $shouldOpenFormFromQuery) ? '1' : '0'; ?>" data-close-player-form-target="<?php echo htmlspecialchars($cancelTarget, ENT_QUOTES, 'UTF-8'); ?>">
<div class="dashboard-layout">
    <?php require 'sidebar_menu.php'; ?>

    <main class="main-content players-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>اللاعبين</h1>
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

        <section class="players-summary-grid">
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">إجمالي اللاعبين</span>
                <strong class="trainer-stat-value"><?php echo $totalPlayersCount; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">الاشتراكات المستمرة</span>
                <strong class="trainer-stat-value"><?php echo $activeSubscriptionsCount; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">الاشتراكات المنتهية</span>
                <strong class="trainer-stat-value"><?php echo $endedSubscriptionsCount; ?></strong>
            </div>
        </section>

        <section class="card players-hero-card">
            <div class="players-hero-content">
                <div>
                    <span class="players-hero-kicker">إدارة اشتراكات اللاعبين</span>
                </div>
                <div class="players-hero-actions">
                <button type="button" class="btn btn-primary js-open-player-modal" <?php echo $hasGroups ? '' : 'disabled'; ?>>إضافة لاعب</button>
                    <a href="<?php echo htmlspecialchars('players.php' . ($playersExportQuery !== '' ? '?' . $playersExportQuery : '?export=xlsx'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-soft">تصدير إكسل</a>
                </div>
            </div>
            <?php if (!$hasGroups): ?>
                <div class="empty-state players-inline-empty-state">يجب تسجيل مجموعة واحدة على الأقل قبل إضافة اللاعبين.</div>
            <?php endif; ?>
        </section>

        <section class="card player-table-card">
            <div class="card-head table-card-head">
                <div>
                    <h3>جدول اللاعبين</h3>
                </div>
                <span class="table-counter"><?php echo $displayedPlayersCount; ?> / <?php echo $totalPlayersCount; ?></span>
            </div>

            <div class="player-table-toolbar">
                <form method="GET" class="player-search-form">
                    <div class="trainer-search-field">
                        <span aria-hidden="true">🔎</span>
                        <input
                            type="search"
                            name="search"
                            placeholder="ابحث بالباركود أو الاسم أو رقم الهاتف أو أرقام الاشتراك"
                            value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="ابحث بالباركود أو الاسم أو رقم الهاتف أو أرقام الاشتراك"
                        >
                    </div>
                    <div class="player-filter-select-wrapper">
                        <label class="player-filter-label" for="filter_group_id">🏘️ المجموعة</label>
                        <div class="select-shell player-filter-select">
                            <select name="filter_group_id" id="filter_group_id" aria-label="تصفية حسب المجموعة">
                                <option value="0">كل المجموعات</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo (int)$group['id']; ?>" <?php echo $filterGroupId === (int)$group['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name'] . ' — ' . $group['group_level'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="player-filter-select-wrapper">
                        <label class="player-filter-label" for="filter_group_level">📊 المستوى</label>
                        <div class="select-shell player-filter-select">
                            <select name="filter_group_level" id="filter_group_level" aria-label="تصفية حسب مستوى المجموعة">
                                <option value="">كل المستويات</option>
                                <?php foreach ($groupLevels as $groupLevel): ?>
                                    <option value="<?php echo htmlspecialchars($groupLevel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterGroupLevel === $groupLevel ? 'selected' : ''; ?>><?php echo htmlspecialchars($groupLevel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="player-filter-select-wrapper">
                        <label class="player-filter-label" for="filter_status">🔖 الحالة</label>
                        <div class="select-shell player-filter-select">
                            <select name="filter_status" id="filter_status" aria-label="تصفية حسب حالة الاشتراك">
                                <option value="">كل الحالات</option>
                                <option value="مستمر" <?php echo $filterStatus === 'مستمر' ? 'selected' : ''; ?>>✅ مستمر</option>
                                <option value="منتهي" <?php echo $filterStatus === 'منتهي' ? 'selected' : ''; ?>>❌ منتهي</option>
                            </select>
                        </div>
                    </div>
                    <div class="player-filter-select-wrapper">
                        <label class="player-filter-label" for="filter_joined">📱 جروب الواتساب</label>
                        <div class="select-shell player-filter-select">
                            <select name="filter_joined" id="filter_joined" aria-label="تصفية حسب الانضمام لجروب الواتساب">
                                <option value="">الكل</option>
                                <option value="1" <?php echo $filterJoined === '1' ? 'selected' : ''; ?>>✅ منضم</option>
                                <option value="0" <?php echo $filterJoined === '0' ? 'selected' : ''; ?>>❌ لم ينضم</option>
                            </select>
                        </div>
                    </div>
                    <div class="player-filter-actions">
                        <button type="submit" class="btn btn-soft">🔍 بحث</button>
                        <?php if ($searchTerm !== '' || $filterGroupId > 0 || $filterGroupLevel !== '' || $filterStatus !== '' || $filterJoined !== ''): ?>
                            <a href="players.php" class="btn btn-warning">✖ إلغاء</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>رقم الصف</th>
                            <th>باركود اللاعب</th>
                            <th>اسم اللاعب</th>
                            <th>رقم الهاتف</th>
                            <th>هاتف ثانٍ</th>
                            <th>جروب واتساب</th>
                            <th>التصنيف</th>
                            <th>المجموعة</th>
                            <th>أيام التمرين</th>
                            <th>ميعاد التمرين</th>
                            <th>المدرب</th>
                            <th>تاريخ البداية</th>
                            <th>تاريخ النهاية</th>
                            <th>الأيام المتبقية</th>
                            <th>التمرينات المتبقية</th>
                            <?php if ($isPentathlon): ?>
                                <th>تمرينات الخماسي</th>
                            <?php endif; ?>
                            <th>الحالة</th>
                            <th>سعر الاشتراك</th>
                            <th>المدفوع</th>
                            <?php if ($isManager): ?>
                                <th>نسبة الأكاديمية</th>
                                <th>مبلغ الأكاديمية</th>
                            <?php endif; ?>
                            <th>ملفات اللاعب</th>
                            <th>أضيف بواسطة</th>
                            <th>عدّل بواسطة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($displayedPlayersCount === 0): ?>
                            <tr>
                                <td colspan="<?php echo $playersTableColumnCount; ?>" class="empty-cell"><?php echo ($searchTerm !== '' || $filterGroupId > 0 || $filterGroupLevel !== '' || $filterStatus !== '') ? 'لا توجد نتائج مطابقة.' : 'لا يوجد لاعبون مسجلون.'; ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($filteredPlayers as $index => $player): ?>
                                <tr>
                                    <td data-label="رقم الصف"><?php echo $index + 1; ?></td>
                                    <td data-label="باركود اللاعب"><?php echo htmlspecialchars($player['barcode'] !== '' ? $player['barcode'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="اسم اللاعب"><strong><?php echo htmlspecialchars($player['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td data-label="رقم الهاتف"><?php echo htmlspecialchars($player['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="هاتف ثانٍ"><?php echo htmlspecialchars(($player['phone2'] ?? '') !== '' ? $player['phone2'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="جروب واتساب"><?php echo !empty($player['whatsapp_group_joined']) ? '<span style="color:#16a34a; font-weight:700;">✅ منضم</span>' : '<span style="color:#dc2626; font-weight:700;">❌ لا</span>'; ?></td>
                                    <td data-label="التصنيف"><?php echo htmlspecialchars($player['player_category'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="المجموعة">
                                        <div class="players-table-stack">
                                            <strong><?php echo htmlspecialchars($player['group_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span><?php echo htmlspecialchars($player['group_level'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="أيام التمرين">
                                        <div class="table-badges">
                                            <?php foreach ($player['training_day_labels'] as $dayLabel): ?>
                                                <span class="badge"><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td data-label="ميعاد التمرين"><?php echo htmlspecialchars($player['training_time_label'] !== '' ? $player['training_time_label'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="المدرب"><?php echo htmlspecialchars($player['trainer_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="تاريخ البداية"><?php echo htmlspecialchars($player['subscription_start_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="تاريخ النهاية"><?php echo htmlspecialchars($player['subscription_end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="الأيام المتبقية"><?php echo (int)$player['days_remaining']; ?></td>
                                    <td data-label="التمرينات المتبقية">
                                        <div class="players-table-stack">
                                            <strong><?php echo (int)$player['remaining_trainings']; ?></strong>
                                            <span>حضور: <?php echo (int)$player['attendance_count']; ?></span>
                                        </div>
                                    </td>
                                    <?php if ($isPentathlon): ?>
                                        <td data-label="تمرينات الخماسي">
                                            <div class="players-table-stack">
                                                <?php
                                                $playerPentathlonSessions = $pentathlonSessionsMap[(int)$player['id']] ?? array_fill_keys(PENTATHLON_SUB_GAMES, 0);
                                                foreach (PENTATHLON_SUB_GAMES as $sg):
                                                ?>
                                                    <span><?php echo htmlspecialchars($sg, ENT_QUOTES, 'UTF-8'); ?>: <strong><?php echo (int)($playerPentathlonSessions[$sg] ?? 0); ?></strong></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                    <td data-label="الحالة">
                                        <span class="player-status-badge <?php echo $player['status'] === 'مستمر' ? 'status-success' : 'status-danger'; ?>">
                                            <?php echo htmlspecialchars($player['status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td data-label="سعر الاشتراك"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($player['subscription_price']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="المدفوع"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($player['paid_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if ($isManager): ?>
                                        <td data-label="نسبة الأكاديمية"><?php echo htmlspecialchars(formatPlayerPercentageLabel($player['academy_percentage']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="مبلغ الأكاديمية"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($player['academy_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endif; ?>
                                    <td data-label="ملفات اللاعب">
                                        <button type="button" class="btn btn-soft js-manage-files" data-player-id="<?php echo (int)$player['id']; ?>" data-player-name="<?php echo htmlspecialchars($player['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            📁 ملفات
                                        </button>
                                    </td>
                                    <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $player['created_by_user_id'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $player['updated_by_user_id'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="الإجراءات">
                                        <div class="inline-actions">
                                            <button
                                                type="button"
                                                class="btn btn-soft js-view-subscriptions"
                                                data-player-id="<?php echo (int)$player['id']; ?>"
                                                data-player-name="<?php echo htmlspecialchars($player['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            >
                                                الاشتراكات
                                            </button>
                                            <a href="players.php?edit=<?php echo (int)$player['id']; ?>" class="btn btn-warning">تعديل</a>
                                            <form method="POST" class="inline-form" onsubmit="return ppAskPwd(this);">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['players_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="change_player_password">
                                                <input type="hidden" name="player_id" value="<?php echo (int)$player['id']; ?>">
                                                <input type="hidden" name="new_player_password" value="">
                                                <button type="submit" class="btn btn-soft" data-player-name="<?php echo htmlspecialchars($player['name'], ENT_QUOTES, 'UTF-8'); ?>">🔑 كلمة السر</button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['players_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="player_id" value="<?php echo (int)$player['id']; ?>">
                                                <button type="submit" class="btn btn-danger" onclick="return confirm('هل تريد حذف هذا اللاعب؟')">حذف</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- مودال إضافة / تعديل اللاعب -->
<div class="player-modal-overlay<?php echo $shouldOpenForm ? ' is-visible' : ''; ?>" id="playerModalOverlay">
    <div class="card player-modal-card" id="playerModalCard">
        <div class="card-head player-modal-head">
            <div>
                <h3><?php echo $formData['id'] > 0 ? 'تعديل لاعب' : 'إضافة لاعب'; ?></h3>
            </div>
                <div class="player-modal-actions">
                    <?php if ($formData['id'] > 0): ?>
                        <a href="<?php echo htmlspecialchars($cancelTarget, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-soft">إلغاء</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-danger js-close-player-modal">إغلاق</button>
                </div>
            </div>

        <form method="POST" class="login-form player-form" id="playerForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['players_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="player_id" value="<?php echo (int)$formData['id']; ?>">
            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTarget, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="trainer-form-section">
                <div class="trainer-section-heading">
                    <h4 class="trainer-section-title">البيانات الأساسية</h4>
                </div>
                <div class="player-form-grid">
                    <div class="form-group">
                        <label for="player_barcode">باركود اللاعب</label>
                        <input type="text" name="barcode" id="player_barcode" maxlength="<?php echo PLAYER_BARCODE_MAX_LENGTH; ?>" value="<?php echo htmlspecialchars($formData['barcode'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="player_name">اسم اللاعب</label>
                        <input type="text" name="name" id="player_name" value="<?php echo htmlspecialchars($formData['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="player_phone">رقم الهاتف (11 رقم)</label>
                        <input type="tel" name="phone" id="player_phone" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" minlength="11" title="يجب إدخال 11 رقم بالضبط" value="<?php echo htmlspecialchars($formData['phone'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="player_phone2">رقم هاتف ثانٍ (اختياري - 11 رقم)</label>
                        <input type="tel" name="phone2" id="player_phone2" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" title="يجب إدخال 11 رقم بالضبط أو تركه فارغاً" value="<?php echo htmlspecialchars($formData['phone2'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                        <label for="whatsapp_group_joined" style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0;">
                            <input type="checkbox" name="whatsapp_group_joined" id="whatsapp_group_joined" value="1" <?php echo !empty($formData['whatsapp_group_joined']) ? 'checked' : ''; ?> style="width:18px; height:18px;">
                            <span>انضم لجروب الواتساب</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="player_category">تصنيف اللاعب</label>
                        <div class="select-shell">
                            <select name="player_category" id="player_category" required>
                                <?php foreach (PLAYER_CATEGORY_OPTIONS as $categoryValue => $categoryLabel): ?>
                                    <option value="<?php echo htmlspecialchars($categoryValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['player_category'] === $categoryValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="subscription_start_date">تاريخ بداية الاشتراك</label>
                        <input type="date" name="subscription_start_date" id="subscription_start_date" value="<?php echo htmlspecialchars($formData['subscription_start_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="subscription_end_date">تاريخ نهاية الاشتراك</label>
                        <input type="date" name="subscription_end_date" id="subscription_end_date" value="<?php echo htmlspecialchars($formData['subscription_end_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="player_level">مستوى اللاعب</label>
                        <input type="text" name="player_level" id="player_level" value="<?php echo htmlspecialchars($formData['player_level'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="<?php echo PLAYER_LEVEL_MAX_LENGTH; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="receipt_number">رقم الإيصال</label>
                        <input type="text" name="receipt_number" id="receipt_number" value="<?php echo htmlspecialchars($formData['receipt_number'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="subscriber_number">رقم المشترك</label>
                        <input type="text" name="subscriber_number" id="subscriber_number" value="<?php echo htmlspecialchars($formData['subscriber_number'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="subscription_number">رقم الاشتراك</label>
                        <input type="text" name="subscription_number" id="subscription_number" value="<?php echo htmlspecialchars($formData['subscription_number'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="issue_date">تاريخ الإصدار</label>
                        <input type="date" name="issue_date" id="issue_date" value="<?php echo htmlspecialchars($formData['issue_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="birth_date">تاريخ ميلاد اللاعب</label>
                        <input type="date" name="birth_date" id="birth_date" value="<?php echo htmlspecialchars($formData['birth_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="player_age_display">سن اللاعب</label>
                        <input type="text" id="player_age_display" value="<?php echo htmlspecialchars($formData['player_age'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                </div>
            </div>

            <div class="trainer-form-section">
                <div class="trainer-section-heading">
                    <h4 class="trainer-section-title">المجموعة والاشتراك</h4>
                </div>
                <div class="player-form-grid">
                    <div class="form-group player-field-full">
                        <label for="selected_group_level">مستوى مجموعة اللاعب</label>
                        <div class="select-shell">
                            <select name="selected_group_level" id="selected_group_level" <?php echo count($groupLevelOptions) > 0 ? 'required' : 'disabled'; ?>>
                                <option value="">اختر مستوى المجموعة</option>
                                <?php foreach ($groupLevelOptions as $groupLevelOption): ?>
                                    <option value="<?php echo htmlspecialchars($groupLevelOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['selected_group_level'] === $groupLevelOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($groupLevelOption, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group player-field-full">
                        <label for="group_id">المجموعة المتاحة</label>
                        <div class="select-shell">
                            <select name="group_id" id="group_id" <?php echo $hasGroups ? 'required' : 'disabled'; ?>>
                                <option value="">اختر المجموعة</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo (int)$group['id']; ?>" <?php echo (string)(int)$group['id'] === $formData['group_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name'] . ' - ' . $group['group_level'] . ' (' . (int)($group['current_players_count'] ?? 0) . '/' . (int)($group['max_players'] ?? 0) . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="group_level_display">المستوى الفعلي للمجموعة</label>
                        <input type="text" id="group_level_display" value="<?php echo htmlspecialchars($formData['group_level'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="training_days_per_week_display">عدد أيام تمرين اللاعب</label>
                        <input type="text" id="training_days_per_week_display" value="<?php echo htmlspecialchars($formData['training_days_per_week'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="total_training_days_display">إجمالي عدد الأيام</label>
                        <input type="text" id="total_training_days_display" value="<?php echo htmlspecialchars($formData['total_training_days'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="total_trainings_display">عدد التمرينات</label>
                        <input type="text" id="total_trainings_display" value="<?php echo htmlspecialchars($formData['total_trainings'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="trainer_name_display">المدرب</label>
                        <input type="text" id="trainer_name_display" value="<?php echo htmlspecialchars($formData['trainer_name'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="training_time">ميعاد تمرين اللاعب</label>
                        <input type="time" name="training_time" id="training_time" value="<?php echo htmlspecialchars(formatTrainingTimeLabel($formData['training_time']), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="subscription_price_display">سعر الاشتراك</label>
                        <input type="text" id="subscription_price_display" value="<?php echo htmlspecialchars($formData['subscription_price'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="paid_amount">المدفوع</label>
                        <input type="number" name="paid_amount" id="paid_amount" min="0" step="0.01" value="<?php echo htmlspecialchars($formData['paid_amount'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <?php if ($isManager): ?>
                        <div class="form-group">
                            <label for="academy_percentage_display">نسبة الأكاديمية</label>
                            <input type="text" id="academy_percentage_display" value="<?php echo htmlspecialchars($formData['academy_percentage'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="academy_amount_display">مبلغ الأكاديمية</label>
                            <input type="text" id="academy_amount_display" value="<?php echo htmlspecialchars($formData['academy_amount'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="trainer-form-section">
                <div class="trainer-section-heading">
                    <h4 class="trainer-section-title">أيام تمرين اللاعب</h4>
                    <p class="trainer-section-subtitle" id="trainingDaysHelper">اختر مستوى المجموعة ثم المجموعة، وبعدها يمكنك تعديل الأيام والميعاد يدويًا.</p>
                </div>
                <div class="trainer-days-grid player-days-grid" id="trainingDaysContainer"></div>
            </div>

            <?php if ($isPentathlon): ?>
            <div class="trainer-form-section">
                <div class="trainer-section-heading">
                    <h4 class="trainer-section-title">توزيع تمرينات الخماسي</h4>
                    <p class="trainer-section-subtitle">حدد عدد التمرينات المخصصة لكل لعبة من ألعاب الخماسي.</p>
                </div>
                <div class="trainer-fields-grid">
                    <?php foreach (PENTATHLON_SUB_GAMES as $subGame): ?>
                    <div class="form-group">
                        <label for="pentathlon_session_<?php echo htmlspecialchars($subGame, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($subGame, ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="number" name="pentathlon_sessions[<?php echo htmlspecialchars($subGame, ENT_QUOTES, 'UTF-8'); ?>]"
                               id="pentathlon_session_<?php echo htmlspecialchars($subGame, ENT_QUOTES, 'UTF-8'); ?>"
                               min="0" step="1"
                               value="<?php echo (int)($formData['pentathlon_sub_game_sessions'][$subGame] ?? 0); ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="trainer-form-actions">
                <button type="submit" class="btn btn-primary" <?php echo $hasGroups ? '' : 'disabled'; ?>><?php echo $formData['id'] > 0 ? 'تحديث اللاعب' : 'حفظ اللاعب'; ?></button>
            </div>
        </form>
    </div>
</div>

<!-- مودال عرض اشتراكات اللاعب -->
<div class="player-modal-overlay" id="playerSubscriptionsModalOverlay" style="display: none;">
    <div class="card player-modal-card" style="max-width: 900px; width: 90%;">
        <div class="card-head player-modal-head">
            <div>
                <h3 id="playerSubscriptionsModalTitle">اشتراكات اللاعب</h3>
            </div>
            <button type="button" class="btn btn-danger js-close-subscriptions-modal">إغلاق</button>
        </div>

        <div id="playerSubscriptionsList">
            <div class="empty-state">لا توجد اشتراكات متاحة.</div>
        </div>
    </div>
</div>

<!-- مودال إدارة ملفات اللاعب -->
<div class="player-modal-overlay" id="playerFilesModalOverlay" style="display: none;">
    <div class="card player-modal-card" style="max-width: 900px; width: 90%;">
        <div class="card-head player-modal-head">
            <div>
                <h3 id="playerFilesModalTitle">ملفات اللاعب</h3>
            </div>
            <button type="button" class="btn btn-danger js-close-files-modal">إغلاق</button>
        </div>
        
        <div id="playerFilesContent">
            <div class="form-group">
                <label>رفع صور جديدة (اختر أكثر من صورة)</label>
                <input type="file" id="playerFilesInput" multiple accept="image/jpeg,image/png,image/gif,image/webp">
                <div id="fileLabelsContainer" style="margin-top: 10px;"></div>
                <button type="button" id="uploadPlayerFilesBtn" class="btn btn-primary" style="margin-top: 10px;">رفع الملفات</button>
            </div>
            
            <hr style="margin: 20px 0; border-color: var(--border);">
            
            <div id="playerFilesList">
                <div class="empty-state">لا توجد ملفات لهذا اللاعب.</div>
            </div>
        </div>
    </div>
</div>

<!-- نافذة عرض الصورة مكبرة -->
<div class="player-modal-overlay" id="imageViewerModal" style="display: none;">
    <div class="card" style="max-width: 90%; width: auto; padding: 10px; background: #000;">
        <div style="text-align: left; margin-bottom: 10px;">
            <button type="button" class="btn btn-danger js-close-image-viewer">✖</button>
        </div>
        <img id="viewerImage" src="" alt="صورة اللاعب" style="max-width: 100%; max-height: 80vh; display: block; margin: 0 auto;">
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script id="playerGroupsData" type="application/json"><?php echo json_encode($groupsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script id="playerDayLabels" type="application/json"><?php echo json_encode(PLAYER_DAY_OPTIONS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script id="playerInitialDays" type="application/json"><?php echo json_encode(array_values($formData['training_day_keys']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script src="assets/js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // كود أيام التمرين (كما هو موجود سابقاً)
    const modalOverlay = document.getElementById('playerModalOverlay');
    const openButtons = document.querySelectorAll('.js-open-player-modal');
    const closeButtons = document.querySelectorAll('.js-close-player-modal');
    const groupLevelSelect = document.getElementById('selected_group_level');
    const groupSelect = document.getElementById('group_id');
    const categorySelect = document.getElementById('player_category');
    const paidAmountInput = document.getElementById('paid_amount');
    const birthDateInput = document.getElementById('birth_date');
    const playerAgeDisplay = document.getElementById('player_age_display');
    const trainingDaysContainer = document.getElementById('trainingDaysContainer');
    const trainingDaysHelper = document.getElementById('trainingDaysHelper');
    const groupLevelDisplay = document.getElementById('group_level_display');
    const trainingDaysPerWeekDisplay = document.getElementById('training_days_per_week_display');
    const totalTrainingDaysDisplay = document.getElementById('total_training_days_display');
    const totalTrainingsDisplay = document.getElementById('total_trainings_display');
    const trainerNameDisplay = document.getElementById('trainer_name_display');
    const trainingTimeInput = document.getElementById('training_time');
    const subscriptionPriceDisplay = document.getElementById('subscription_price_display');
    const academyPercentageDisplay = document.getElementById('academy_percentage_display');
    const academyAmountDisplay = document.getElementById('academy_amount_display');
    const groupsData = JSON.parse(document.getElementById('playerGroupsData').textContent || '{}');
    const dayLabels = JSON.parse(document.getElementById('playerDayLabels').textContent || '{}');
    let selectedDays = JSON.parse(document.getElementById('playerInitialDays').textContent || '[]');

    const hasPlayerFormQueryParam = function (parameterName, expectedValue) {
        const params = new URLSearchParams(window.location.search);
        if (!params.has(parameterName)) {
            return false;
        }
        if (typeof expectedValue === 'undefined') {
            return true;
        }
        return params.get(parameterName) === expectedValue;
    };
    const openModal = function () {
        if (modalOverlay) modalOverlay.classList.add('is-visible');
    };
    const closeModal = function () {
        if (modalOverlay) modalOverlay.classList.remove('is-visible');
    };
    const closePlayerFormTarget = document.body.dataset.closePlayerFormTarget || 'players.php';
    openButtons.forEach(btn => btn.addEventListener('click', openModal));
    closeButtons.forEach(btn => btn.addEventListener('click', function () {
        if (hasPlayerFormQueryParam('edit') || hasPlayerFormQueryParam('open_player_form', '1')) {
            window.location.href = closePlayerFormTarget;
            return;
        }
        closeModal();
    }));
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay && !hasPlayerFormQueryParam('edit') && !hasPlayerFormQueryParam('open_player_form', '1')) {
                closeModal();
            }
        });
    }

    const getSelectedGroup = function () { return groupsData[groupSelect.value] || null; };
    const getSubscriptionPriceForCategory = function (group) {
        if (!group) return 0;
        if (categorySelect.value === 'مشاة') return Number(group.walkers_price || 0);
        if (categorySelect.value === 'اسلحة اخري') return Number(group.other_weapons_price || 0);
        return Number(group.civilian_price || 0);
    };
    const renderTrainingDays = function () {
        if (!trainingDaysContainer) return;
        selectedDays = selectedDays.filter(dayKey => dayLabels[dayKey]);
        trainingDaysContainer.innerHTML = '';
        Object.keys(dayLabels).forEach(dayKey => {
            const wrapper = document.createElement('label');
            wrapper.className = 'trainer-day-chip';
            if (selectedDays.indexOf(dayKey) !== -1) {
                wrapper.classList.add('is-selected');
            }
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'training_day_keys[]';
            input.value = dayKey;
            input.checked = selectedDays.indexOf(dayKey) !== -1;
            input.addEventListener('change', function () {
                if (input.checked) {
                    if (selectedDays.indexOf(dayKey) === -1) {
                        selectedDays.push(dayKey);
                    }
                } else {
                    selectedDays = selectedDays.filter(function (selectedDayKey) {
                        return selectedDayKey !== dayKey;
                    });
                }
                renderTrainingDays();
                updateDerivedFields(false);
            });
            const span = document.createElement('span');
            span.textContent = dayLabels[dayKey];
            wrapper.appendChild(input);
            wrapper.appendChild(span);
            trainingDaysContainer.appendChild(wrapper);
        });
        updateTrainingDaysHelper();
    };
    const updateTrainingDaysHelper = function () {
        if (!trainingDaysHelper) return;
        if (!groupSelect || groupSelect.value === '') {
            trainingDaysHelper.textContent = 'اختر مستوى المجموعة ثم المجموعة أولًا.';
        } else if (!selectedDays.length) {
            trainingDaysHelper.textContent = 'حدد يوم تمرين واحدًا على الأقل لهذا اللاعب.';
        } else {
            trainingDaysHelper.textContent = 'تم تحديد ' + selectedDays.length + ' يوم تمرين للاعب.';
        }
    };
    const updatePlayerAge = function () {
        if (!playerAgeDisplay) return;
        const rawBirthDate = birthDateInput ? birthDateInput.value : '';
        if (!rawBirthDate) {
            playerAgeDisplay.value = '';
            return;
        }

        const birthDate = new Date(rawBirthDate + 'T00:00:00');
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (Number.isNaN(birthDate.getTime()) || birthDate > today) {
            playerAgeDisplay.value = '';
            return;
        }

        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDifference = today.getMonth() - birthDate.getMonth();
        if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) {
            age -= 1;
        }
        playerAgeDisplay.value = String(Math.max(age, 0));
    };
    const rebuildGroupOptions = function () {
        if (!groupSelect) {
            return;
        }
        const previousValue = groupSelect.value;
        const selectedLevel = groupLevelSelect ? groupLevelSelect.value : '';
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = selectedLevel === '' ? 'اختر مستوى المجموعة أولًا' : 'اختر المجموعة';
        groupSelect.innerHTML = '';
        groupSelect.appendChild(placeholderOption);

        Object.keys(groupsData).forEach(function (groupId) {
            const group = groupsData[groupId];
            if (!group || (selectedLevel !== '' && group.group_level !== selectedLevel)) {
                return;
            }
            const isCurrentSelection = String(groupId) === String(previousValue);
            if (!group.has_available_slot && !isCurrentSelection) {
                return;
            }

            const option = document.createElement('option');
            option.value = groupId;
            option.textContent = group.group_name + ' - ' + group.group_level + ' (' + group.current_players_count + '/' + group.max_players + ')';
            if (isCurrentSelection) {
                option.selected = true;
            }
            groupSelect.appendChild(option);
        });

        const hasPreviousValueOption = Array.from(groupSelect.options).some(function (option) {
            return option.value === previousValue;
        });
        if (!hasPreviousValueOption) {
            groupSelect.value = '';
        }
        groupSelect.disabled = selectedLevel === '' || groupSelect.options.length <= 1;
    };
    const updateDerivedFields = function (resetScheduleFromGroup) {
        const group = getSelectedGroup();
        if (!group) {
            groupLevelDisplay.value = '';
            trainingDaysPerWeekDisplay.value = '';
            totalTrainingDaysDisplay.value = '';
            totalTrainingsDisplay.value = '';
            trainerNameDisplay.value = '';
            if (trainingTimeInput) trainingTimeInput.value = '';
            subscriptionPriceDisplay.value = '0.00';
            if (academyPercentageDisplay) academyPercentageDisplay.value = '0.00';
            if (academyAmountDisplay) academyAmountDisplay.value = '0.00';
            selectedDays = [];
            renderTrainingDays();
            return;
        }
        const subscriptionPrice = getSubscriptionPriceForCategory(group);
        const academyPercentage = Number(group.academy_percentage || 0);
        const paidAmount = Number(paidAmountInput.value || 0);
        const academyAmount = (paidAmount * academyPercentage) / 100;
        groupLevelDisplay.value = group.group_level || '';
        trainingDaysPerWeekDisplay.value = String(selectedDays.length || (Array.isArray(group.training_day_keys) ? group.training_day_keys.length : 0));
        totalTrainingDaysDisplay.value = group.trainings_count || '';
        totalTrainingsDisplay.value = group.exercises_count || '';
        trainerNameDisplay.value = group.trainer_name || '';
        if (resetScheduleFromGroup) {
            selectedDays = Array.isArray(group.training_day_keys) ? group.training_day_keys.slice() : [];
            if (trainingTimeInput) {
                trainingTimeInput.value = group.training_time || '';
            }
        }
        subscriptionPriceDisplay.value = subscriptionPrice.toFixed(2);
        if (academyPercentageDisplay) academyPercentageDisplay.value = academyPercentage.toFixed(2);
        if (academyAmountDisplay) academyAmountDisplay.value = academyAmount.toFixed(2);
        trainingDaysPerWeekDisplay.value = String(selectedDays.length);
        renderTrainingDays();
    };
    if (groupLevelSelect) {
        groupLevelSelect.addEventListener('change', function () {
            rebuildGroupOptions();
            updateDerivedFields(true);
        });
    }
    if (groupSelect) groupSelect.addEventListener('change', function () { updateDerivedFields(true); });
    if (categorySelect) categorySelect.addEventListener('change', function () { updateDerivedFields(false); });
    if (paidAmountInput) paidAmountInput.addEventListener('input', function () { updateDerivedFields(false); });
    if (birthDateInput) birthDateInput.addEventListener('input', updatePlayerAge);
    const shouldResetScheduleFromSelectedGroup = !!(groupSelect && groupSelect.value !== '');
    rebuildGroupOptions();
    updateDerivedFields(shouldResetScheduleFromSelectedGroup);
    updatePlayerAge();
    if (document.body.dataset.openPlayerForm === '1') openModal();

    // ========== إدارة ملفات اللاعبين ==========
    let currentSubscriptionsPlayerId = null;
    let currentFilesPlayerId = null;
    const subscriptionsModal = document.getElementById('playerSubscriptionsModalOverlay');
    const subscriptionsListDiv = document.getElementById('playerSubscriptionsList');
    const closeSubscriptionsModalBtns = document.querySelectorAll('.js-close-subscriptions-modal');
    const filesModal = document.getElementById('playerFilesModalOverlay');
    const filesListDiv = document.getElementById('playerFilesList');
    const filesInput = document.getElementById('playerFilesInput');
    const fileLabelsContainer = document.getElementById('fileLabelsContainer');
    const uploadBtn = document.getElementById('uploadPlayerFilesBtn');
    const closeFilesModalBtns = document.querySelectorAll('.js-close-files-modal');
    const imageViewerModal = document.getElementById('imageViewerModal');
    const viewerImage = document.getElementById('viewerImage');
    const closeImageViewerBtns = document.querySelectorAll('.js-close-image-viewer');

    document.querySelectorAll('.js-view-subscriptions').forEach(btn => {
        btn.addEventListener('click', function() {
            currentSubscriptionsPlayerId = this.dataset.playerId;
            document.getElementById('playerSubscriptionsModalTitle').innerText = 'اشتراكات اللاعب: ' + this.dataset.playerName;
            subscriptionsModal.style.display = 'flex';
            loadPlayerSubscriptions();
        });
    });

    closeSubscriptionsModalBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            subscriptionsModal.style.display = 'none';
            subscriptionsListDiv.innerHTML = '<div class="empty-state">لا توجد اشتراكات متاحة.</div>';
            currentSubscriptionsPlayerId = null;
        });
    });

    // فتح مودال الملفات
    document.querySelectorAll('.js-manage-files').forEach(btn => {
        btn.addEventListener('click', function() {
            currentFilesPlayerId = this.dataset.playerId;
            document.getElementById('playerFilesModalTitle').innerText = 'ملفات اللاعب: ' + this.dataset.playerName;
            filesModal.style.display = 'flex';
            loadPlayerFiles();
        });
    });

    // إغلاق مودال الملفات
    closeFilesModalBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filesModal.style.display = 'none';
            currentFilesPlayerId = null;
            filesInput.value = '';
            fileLabelsContainer.innerHTML = '';
        });
    });

    // عند اختيار ملفات، نعرض حقول لإدخال التسميات
    filesInput.addEventListener('change', function(e) {
        const files = e.target.files;
        fileLabelsContainer.innerHTML = '';
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const div = document.createElement('div');
            div.style.marginBottom = '8px';
            div.innerHTML = `
                <label style="display: inline-block; width: 100px;">${escapeHtml(file.name)}</label>
                <input type="text" class="file-label-input" data-index="${i}" placeholder="تسمية الصورة" style="width: 70%;" value="${escapeHtml(file.name.split('.')[0])}">
            `;
            fileLabelsContainer.appendChild(div);
        }
    });

    // رفع الملفات
    uploadBtn.addEventListener('click', function() {
        if (!currentFilesPlayerId) return;
        const files = filesInput.files;
        if (files.length === 0) {
            alert('اختر ملفات أولاً.');
            return;
        }
        const formData = new FormData();
        formData.append('ajax_action', 'upload_files');
        formData.append('player_id', currentFilesPlayerId);
        for (let i = 0; i < files.length; i++) {
            formData.append('player_files[]', files[i]);
            const labelInput = document.querySelector(`.file-label-input[data-index="${i}"]`);
            const label = labelInput ? labelInput.value : '';
            formData.append('labels[]', label);
        }
        uploadBtn.disabled = true;
        uploadBtn.innerText = 'جاري الرفع...';
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadPlayerFiles();
                filesInput.value = '';
                fileLabelsContainer.innerHTML = '';
            } else {
                alert('فشل رفع بعض الملفات: ' + (data.errors || ['خطأ']).join('\n'));
            }
        })
        .catch(err => { console.error(err); alert('حدث خطأ أثناء الرفع.'); })
        .finally(() => { uploadBtn.disabled = false; uploadBtn.innerText = 'رفع الملفات'; });
    });

    // تحميل قائمة الملفات وعرضها
    function loadPlayerSubscriptions() {
        if (!currentSubscriptionsPlayerId) return;
        subscriptionsListDiv.innerHTML = '<div class="empty-state">جاري تحميل الاشتراكات...</div>';
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: `ajax_action=get_subscription_history&player_id=${currentSubscriptionsPlayerId}`
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.history.length) {
                subscriptionsListDiv.innerHTML = '<div class="empty-state">لا توجد اشتراكات مسجلة لهذا اللاعب.</div>';
                return;
            }

            let html = '<div style="display: grid; gap: 16px;">';
            data.history.forEach(item => {
                const daysHtml = item.training_days.length
                    ? item.training_days.map(day => `<span class="badge">${escapeHtml(day)}</span>`).join('')
                    : '<span class="empty-state" style="padding: 0;">لا توجد أيام مسجلة.</span>';

                html += `
                    <div style="border: 1px solid var(--border); border-radius: 18px; padding: 16px; background: var(--bg-secondary);">
                        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; margin-bottom: 12px;">
                            <div>
                                <strong>${escapeHtml(item.group_name || 'بدون مجموعة')}</strong>
                                <div style="color: var(--text-muted); margin-top: 4px;">${escapeHtml(item.group_level || '—')} • ${escapeHtml(item.trainer_name || '—')}</div>
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                ${item.is_current ? '<span class="badge" style="background: rgba(47, 91, 234, 0.12); color: var(--primary);">الحالي</span>' : ''}
                                <span class="player-status-badge ${item.status === 'مستمر' ? 'status-success' : 'status-danger'}">${escapeHtml(item.status)}</span>
                            </div>
                        </div>
                        <div style="display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                            <div><strong>بداية الاشتراك:</strong><br>${escapeHtml(item.subscription_start_date)}</div>
                            <div><strong>نهاية الاشتراك:</strong><br>${escapeHtml(item.subscription_end_date)}</div>
                            <div><strong>مستوى اللاعب:</strong><br>${escapeHtml(item.player_level || '—')}</div>
                            <div><strong>رقم الإيصال:</strong><br>${escapeHtml(item.receipt_number || '—')}</div>
                            <div><strong>رقم المشترك:</strong><br>${escapeHtml(item.subscriber_number || '—')}</div>
                            <div><strong>رقم الاشتراك:</strong><br>${escapeHtml(item.subscription_number || '—')}</div>
                            <div><strong>تاريخ الإصدار:</strong><br>${escapeHtml(item.issue_date || '—')}</div>
                            <div><strong>تاريخ الميلاد:</strong><br>${escapeHtml(item.birth_date || '—')}</div>
                            <div><strong>سن اللاعب:</strong><br>${escapeHtml(String(item.player_age || 0))}</div>
                            <div><strong>التصنيف:</strong><br>${escapeHtml(item.player_category || '—')}</div>
                            <div><strong>الأيام المتبقية:</strong><br>${escapeHtml(String(item.days_remaining))}</div>
                            <div><strong>ميعاد التمرين:</strong><br>${escapeHtml(item.training_time || '—')}</div>
                            <div><strong>سعر الاشتراك:</strong><br>${escapeHtml(item.subscription_price)}</div>
                            <div><strong>المدفوع:</strong><br>${escapeHtml(item.paid_amount)}</div>
                            <div><strong>نسبة الأكاديمية:</strong><br>${escapeHtml(item.academy_percentage)}</div>
                            <div><strong>مبلغ الأكاديمية:</strong><br>${escapeHtml(item.academy_amount)}</div>
                        </div>
                        <div style="margin-top: 12px;">
                            <strong>أيام التمرين:</strong>
                            <div class="table-badges" style="margin-top: 8px;">${daysHtml}</div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            subscriptionsListDiv.innerHTML = html;
        })
        .catch(err => {
            console.error(err);
            subscriptionsListDiv.innerHTML = '<div class="empty-state">حدث خطأ في تحميل الاشتراكات.</div>';
        });
    }

    function loadPlayerFiles() {
        if (!currentFilesPlayerId) return;
        filesListDiv.innerHTML = '<div class="empty-state">جاري التحميل...</div>';
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: `ajax_action=get_files&player_id=${currentFilesPlayerId}`
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.files.length) {
                filesListDiv.innerHTML = '<div class="empty-state">لا توجد ملفات لهذا اللاعب.</div>';
                return;
            }
            let html = '<div class="files-grid" style="display: grid; gap: 20px; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">';
            data.files.forEach(file => {
                html += `
                    <div class="file-card" data-file-id="${file.id}" style="border: 1px solid var(--border); border-radius: 16px; padding: 12px; background: var(--bg-secondary);">
                        <div style="text-align: center;">
                            <img src="${file.url}" alt="${escapeHtml(file.label)}" style="max-width: 100%; max-height: 160px; cursor: pointer; border-radius: 8px;" class="view-image-btn" data-url="${file.url}">
                        </div>
                        <div style="margin-top: 12px;">
                            <label>التسمية:</label>
                            <input type="text" class="file-label-edit" value="${escapeHtml(file.label)}" style="width: 100%; margin-bottom: 8px;">
                            <button class="btn btn-soft btn-sm save-label-btn" data-id="${file.id}" style="font-size: 12px; padding: 4px 8px;">حفظ التسمية</button>
                            <button class="btn btn-danger btn-sm delete-file-btn" data-id="${file.id}" style="font-size: 12px; padding: 4px 8px; margin-top: 8px;">حذف</button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            filesListDiv.innerHTML = html;

            // عرض الصورة مكبرة
            document.querySelectorAll('.view-image-btn').forEach(img => {
                img.addEventListener('click', function(e) {
                    viewerImage.src = this.dataset.url;
                    imageViewerModal.style.display = 'flex';
                });
            });
            // حفظ التسمية
            document.querySelectorAll('.save-label-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const fileId = this.dataset.id;
                    const newLabel = this.closest('.file-card').querySelector('.file-label-edit').value;
                    if (!newLabel.trim()) { alert('التسمية لا يمكن أن تكون فارغة.'); return; }
                    updateFileLabel(fileId, newLabel);
                });
            });
            // حذف الملف
            document.querySelectorAll('.delete-file-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (confirm('هل تريد حذف هذا الملف نهائياً؟')) {
                        const fileId = this.dataset.id;
                        deleteFile(fileId);
                    }
                });
            });
        })
        .catch(err => { console.error(err); filesListDiv.innerHTML = '<div class="empty-state">حدث خطأ في تحميل الملفات.</div>'; });
    }

    function updateFileLabel(fileId, newLabel) {
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: `ajax_action=update_label&player_id=${currentFilesPlayerId}&file_id=${fileId}&label=${encodeURIComponent(newLabel)}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadPlayerFiles();
            else alert('فشل تحديث التسمية: ' + (data.error || 'خطأ غير معروف'));
        })
        .catch(err => alert('خطأ في الاتصال.'));
    }

    function deleteFile(fileId) {
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: `ajax_action=delete_file&player_id=${currentFilesPlayerId}&file_id=${fileId}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadPlayerFiles();
            else alert('فشل الحذف: ' + (data.error || 'خطأ غير معروف'));
        })
        .catch(err => alert('خطأ في الاتصال.'));
    }

    closeImageViewerBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            imageViewerModal.style.display = 'none';
            viewerImage.src = '';
        });
    });

    function escapeHtml(str) {
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
});

function ppAskPwd(form) {
    var btn = form.querySelector('button[type="submit"]');
    var name = btn ? (btn.getAttribute('data-player-name') || '') : '';
    var newPwd = window.prompt('🔑 تغيير كلمة سر اللاعب' + (name ? ' (' + name + ')' : '') + '\nأدخل كلمة السر الجديدة (4 أحرف على الأقل):');
    if (newPwd === null) return false;
    newPwd = String(newPwd).trim();
    if (newPwd.length < 4) {
        alert('⚠️ كلمة السر يجب أن تكون 4 أحرف على الأقل.');
        return false;
    }
    if (!window.confirm('هل أنت متأكد من تغيير كلمة سر اللاعب؟')) {
        return false;
    }
    var input = form.querySelector('input[name="new_player_password"]');
    if (input) input.value = newPwd;
    return true;
}
</script>
</body>
</html>
