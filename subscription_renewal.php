<?php
require_once 'session.php';
startSecureSession();
require_once 'config.php';
require_once 'navigation.php';
require_once 'players_support.php';

requireAuthenticatedUser();
requireMenuAccess('subscription-renewal');
ensurePlayersTables($pdo);

date_default_timezone_set('Africa/Cairo');

if (!isset($_SESSION['subscription_renewal_csrf_token'])) {
    $_SESSION['subscription_renewal_csrf_token'] = bin2hex(random_bytes(32));
}

function fetchSubscriptionRenewalPlayers(PDO $pdo, $gameId, $search = '')
{
    $presentStatus = PLAYER_ATTENDANCE_STATUS_PRESENT;
    $sql = "SELECT
                p.id,
                p.game_id,
                p.group_id,
                p.barcode,
                p.name,
                p.phone,
                p.player_category,
                p.subscription_start_date,
                p.subscription_end_date,
                p.group_name,
                p.group_level,
                p.receipt_number,
                p.subscriber_number,
                p.subscription_number,
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

    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        $sql .= ' AND (p.barcode LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    $sql .= ' GROUP BY p.id ORDER BY p.subscription_end_date ASC, p.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function buildSubscriptionRenewalPlayerRow(array $player, DateTimeImmutable $today)
{
    $consumedSessionsCount = (int)($player['consumed_sessions_count'] ?? 0);
    $player['attendance_count'] = (int)($player['attendance_count'] ?? 0);
    $player['consumed_sessions_count'] = $consumedSessionsCount;
    $player['days_remaining'] = calculatePlayerDaysRemaining($player['subscription_end_date'] ?? '', $today);
    $player['remaining_trainings'] = calculatePlayerRemainingTrainings($player['total_trainings'] ?? 0, $consumedSessionsCount);
    $player['status'] = getPlayerSubscriptionStatus($player['days_remaining'], $player['remaining_trainings']);
    $player['training_day_labels'] = formatPlayerTrainingDaysLabel(getPlayerTrainingDayKeys($player['training_day_keys'] ?? ''));
    $player['training_time_label'] = formatTrainingTimeDisplay($player['training_time'] ?? '');

    return $player;
}

function fetchSubscriptionRenewalPlayerById(PDO $pdo, $gameId, $playerId, DateTimeImmutable $today)
{
    $players = fetchSubscriptionRenewalPlayers($pdo, $gameId);
    foreach ($players as $player) {
        if ((int)$player['id'] === (int)$playerId) {
            return buildSubscriptionRenewalPlayerRow($player, $today);
        }
    }

    return null;
}

function getDefaultRenewalStartDate(array $player, DateTimeImmutable $today)
{
    $endDate = trim((string)($player['subscription_end_date'] ?? ''));
    if (isValidPlayerDate($endDate)) {
        $currentEnd = createPlayerDate($endDate);
        if ($currentEnd >= $today) {
            return $currentEnd->modify('+1 day')->format('Y-m-d');
        }
    }

    return $today->format('Y-m-d');
}

function getRenewalInitialTrainingDayKeys(array $player, ?array $group)
{
    if (!$group) {
        return [];
    }

    return getPlayerTrainingDayKeys($group['training_day_keys'] ?? '');
}

function buildSubscriptionRenewalUrl($searchTerm, $playerId)
{
    $params = array_filter([
        'search' => $searchTerm !== '' ? $searchTerm : null,
        'renew' => (int)$playerId,
    ], function ($value) {
        return $value !== null;
    });

    return 'subscription_renewal.php?' . http_build_query($params);
}

$success = '';
$error = '';
$isManager = (string)($_SESSION['role'] ?? '') === 'مدير';
$currentGameId = (int)($_SESSION['selected_game_id'] ?? 0);
$currentGameName = (string)($_SESSION['selected_game_name'] ?? '');
$today = new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));

$settingsStmt = $pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1');
$settings = $settingsStmt->fetch() ?: [];
$sidebarName = $settings['academy_name'] ?? ($_SESSION['site_name'] ?? 'أكاديمية رياضية');
$sidebarLogo = $settings['academy_logo'] ?? ($_SESSION['site_logo'] ?? 'assets/images/logo.png');
$activeMenu = 'subscription-renewal';

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

$formData = [
    'player_id' => 0,
    'barcode' => '',
    'name' => '',
    'phone' => '',
    'player_category' => PLAYER_DEFAULT_CATEGORY,
    'group_id' => '',
    'subscription_start_date' => $today->format('Y-m-d'),
    'subscription_end_date' => $today->modify('+30 days')->format('Y-m-d'),
    'receipt_number' => '',
    'subscriber_number' => '',
    'paid_amount' => '',
    'academy_percentage' => '0.00',
    'academy_amount' => '0.00',
    'subscription_price' => '0.00',
    'group_level' => '',
    'training_days_per_week' => '',
    'total_training_days' => '',
    'total_trainings' => '',
    'trainer_name' => '',
    'training_time' => '',
    'training_day_keys' => [],
];

$flashSuccess = $_SESSION['subscription_renewal_success'] ?? '';
unset($_SESSION['subscription_renewal_success']);
if ($flashSuccess !== '') {
    $success = $flashSuccess;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if ($csrfToken === '' || !hash_equals($_SESSION['subscription_renewal_csrf_token'], $csrfToken)) {
        $error = 'الطلب غير صالح.';
    } else {
        $playerId = (int)($_POST['player_id'] ?? 0);
        $selectedPlayer = $playerId > 0 ? fetchSubscriptionRenewalPlayerById($pdo, $currentGameId, $playerId, $today) : null;

        if (!$selectedPlayer) {
            $error = 'اللاعب غير متاح.';
        } elseif (($selectedPlayer['status'] ?? '') !== 'منتهي') {
            $error = 'يمكن تجديد الاشتراك للاعبين المنتهية اشتراكاتهم فقط.';
        } else {
            $formData = [
                'player_id' => (int)$selectedPlayer['id'],
                'barcode' => (string)$selectedPlayer['barcode'],
                'name' => (string)$selectedPlayer['name'],
                'phone' => (string)$selectedPlayer['phone'],
                'player_category' => (string)$selectedPlayer['player_category'],
                'group_id' => trim((string)($_POST['group_id'] ?? '')),
                'subscription_start_date' => trim((string)($_POST['subscription_start_date'] ?? '')),
                'subscription_end_date' => trim((string)($_POST['subscription_end_date'] ?? '')),
                'receipt_number' => trim((string)($_POST['receipt_number'] ?? '')),
                'subscriber_number' => trim((string)($_POST['subscriber_number'] ?? '')),
                'paid_amount' => normalizePlayerMoneyValue($_POST['paid_amount'] ?? ''),
                'academy_percentage' => '0.00',
                'academy_amount' => '0.00',
                'subscription_price' => '0.00',
                'group_level' => '',
                'training_days_per_week' => '',
                'total_training_days' => '',
                'total_trainings' => '',
                'trainer_name' => '',
                'training_time' => '',
                'training_day_keys' => [],
            ];

            $selectedGroupId = $formData['group_id'] === '' ? 0 : (int)$formData['group_id'];
            $selectedGroup = $groupMap[$selectedGroupId] ?? null;
            $selectedGroupPrice = $selectedGroup ? getPlayerGroupPriceByCategory($selectedGroup, $formData['player_category']) : 0;
            $selectedGroupTrainingDaysCount = $selectedGroup ? (int)($selectedGroup['training_days_count'] ?? 0) : 0;
            $selectedGroupTrainingDayKeys = $selectedGroup ? getPlayerTrainingDayKeys($selectedGroup['training_day_keys'] ?? '') : [];
            $selectedGroupTrainingTime = $selectedGroup ? normalizeTrainingTimeValue($selectedGroup['training_time'] ?? '') : '';

            if ($selectedGroup) {
                $formData['group_level'] = (string)$selectedGroup['group_level'];
                $formData['training_days_per_week'] = (string)(int)$selectedGroup['training_days_count'];
                $formData['total_training_days'] = (string)(int)$selectedGroup['trainings_count'];
                $formData['total_trainings'] = (string)(int)$selectedGroup['exercises_count'];
                $formData['trainer_name'] = (string)$selectedGroup['trainer_name'];
                $formData['training_time'] = formatTrainingTimeLabel($selectedGroupTrainingTime);
                $formData['training_day_keys'] = $selectedGroupTrainingDayKeys;
                $formData['subscription_price'] = formatPlayerCurrency($selectedGroupPrice);
                $formData['academy_percentage'] = formatPlayerCurrency($selectedGroup['academy_percentage'] ?? 0);
                $formData['academy_amount'] = formatPlayerCurrency(calculatePlayerAcademyAmount($formData['paid_amount'] === '' ? 0 : $formData['paid_amount'], $selectedGroup['academy_percentage'] ?? 0));
            }

            if ($selectedGroupId <= 0 || !$selectedGroup) {
                $error = 'المجموعة المحددة غير متاحة.';
            } elseif (!isValidPlayerDate($formData['subscription_start_date'])) {
                $error = 'تاريخ بداية الاشتراك غير صحيح.';
            } elseif (!isValidPlayerDate($formData['subscription_end_date'])) {
                $error = 'تاريخ نهاية الاشتراك غير صحيح.';
            } elseif (createPlayerDate($formData['subscription_end_date']) <= createPlayerDate($formData['subscription_start_date'])) {
                $error = 'تاريخ نهاية الاشتراك يجب أن يكون بعد تاريخ البداية.';
            } elseif ($formData['receipt_number'] === '') {
                $error = 'رقم الإيصال مطلوب.';
            } elseif (strlen($formData['receipt_number']) > 100) {
                $error = 'رقم الإيصال طويل جدًا.';
            } elseif ($formData['subscriber_number'] === '') {
                $error = 'رقم المشترك مطلوب.';
            } elseif (strlen($formData['subscriber_number']) > 100) {
                $error = 'رقم المشترك طويل جدًا.';
            } elseif ($formData['paid_amount'] === '') {
                $error = 'المبلغ المدفوع غير صحيح.';
            } elseif ((float)$formData['paid_amount'] > (float)$selectedGroupPrice) {
                $error = 'المبلغ المدفوع لا يمكن أن يتجاوز سعر الاشتراك.';
            } elseif ($selectedGroupTrainingDaysCount <= 0) {
                $error = 'عدد أيام التمرين في المجموعة غير صحيح.';
            } elseif (count($selectedGroupTrainingDayKeys) !== $selectedGroupTrainingDaysCount) {
                $error = 'يجب استكمال أيام التمرين في بيانات المجموعة أولًا.';
            } elseif ($selectedGroupTrainingTime === '') {
                $error = 'يجب تحديد ميعاد التمرين في بيانات المجموعة أولًا.';
            } elseif (playerGroupReachedCapacity($pdo, $currentGameId, $selectedGroupId, $selectedGroup['max_players'] ?? 0, $formData['player_id'])) {
                $error = 'لا يمكن نقل أو تجديد اللاعب في هذه المجموعة لأن العدد وصل إلى الحد الأقصى.';
            } else {
                $formData['training_day_keys'] = $selectedGroupTrainingDayKeys;
            }

            if ($error === '') {
                $subscriptionPrice = getPlayerGroupPriceByCategory($selectedGroup, $formData['player_category']);
                $academyPercentage = (float)($selectedGroup['academy_percentage'] ?? 0);
                $academyAmount = calculatePlayerAcademyAmount($formData['paid_amount'], $academyPercentage);
                $trainingDayValue = implode(PLAYER_DAY_SEPARATOR, $formData['training_day_keys']);
                $trainingTimeValue = $selectedGroupTrainingTime;

                $saveStmt = $pdo->prepare(
                    'UPDATE players
                     SET group_id = ?, subscription_start_date = ?, subscription_end_date = ?, group_name = ?, group_level = ?,
                         receipt_number = ?, subscriber_number = ?,
                          training_days_per_week = ?, total_training_days = ?, total_trainings = ?, trainer_name = ?,
                          subscription_price = ?, paid_amount = ?, academy_percentage = ?, academy_amount = ?, training_day_keys = ?, training_time = ?
                       WHERE id = ? AND game_id = ?'
                );

                try {
                    $pdo->beginTransaction();
                    syncPlayerSubscriptionHistoryFromPlayerId($pdo, $formData['player_id'], $currentGameId, 'pre_renewal');
                    $saveStmt->execute([
                        $selectedGroupId,
                        $formData['subscription_start_date'],
                        $formData['subscription_end_date'],
                        $selectedGroup['group_name'],
                        $selectedGroup['group_level'],
                        $formData['receipt_number'],
                        $formData['subscriber_number'],
                        (int)$selectedGroup['training_days_count'],
                        (int)$selectedGroup['trainings_count'],
                        (int)$selectedGroup['exercises_count'],
                        $selectedGroup['trainer_name'],
                        formatPlayerCurrency($subscriptionPrice),
                        $formData['paid_amount'],
                        formatPlayerCurrency($academyPercentage),
                        formatPlayerCurrency($academyAmount),
                        $trainingDayValue,
                        $trainingTimeValue,
                        $formData['player_id'],
                        $currentGameId,
                    ]);
                    syncPlayerSubscriptionHistoryFromPlayerId($pdo, $formData['player_id'], $currentGameId, 'renewal');
                    auditTrack($pdo, "update", "players", (int)$formData['player_id'], "تجديد الاشتراكات", "تجديد اشتراك لاعب رقم " . (int)$formData['player_id'] . " للمجموعة: " . (string)$selectedGroup['group_name']);
                    $pdo->commit();
                    $_SESSION['subscription_renewal_success'] = 'تم تجديد الاشتراك بنجاح.';
                    header('Location: subscription_renewal.php');
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'تعذر تجديد الاشتراك.';
                }
            }
        }
    }
}

$renewPlayerId = (int)($_GET['renew'] ?? 0);
if ($renewPlayerId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $renewPlayer = fetchSubscriptionRenewalPlayerById($pdo, $currentGameId, $renewPlayerId, $today);
    if ($renewPlayer && ($renewPlayer['status'] ?? '') === 'منتهي') {
        $defaultStartDate = getDefaultRenewalStartDate($renewPlayer, $today);
        $defaultGroupId = isset($groupMap[(int)$renewPlayer['group_id']]) ? (string)(int)$renewPlayer['group_id'] : '';
        $defaultGroup = $defaultGroupId !== '' ? $groupMap[(int)$defaultGroupId] : null;
        $defaultPrice = $defaultGroup ? getPlayerGroupPriceByCategory($defaultGroup, $renewPlayer['player_category']) : 0;
        $defaultPaid = formatPlayerCurrency($defaultPrice);
        $formData = [
            'player_id' => (int)$renewPlayer['id'],
            'barcode' => (string)$renewPlayer['barcode'],
            'name' => (string)$renewPlayer['name'],
            'phone' => (string)$renewPlayer['phone'],
            'player_category' => (string)$renewPlayer['player_category'],
            'group_id' => $defaultGroupId,
            'subscription_start_date' => $defaultStartDate,
            'subscription_end_date' => createPlayerDate($defaultStartDate)->modify('+30 days')->format('Y-m-d'),
            'receipt_number' => (string)($renewPlayer['receipt_number'] ?? ''),
            'subscriber_number' => (string)($renewPlayer['subscriber_number'] ?? ''),
            'paid_amount' => $defaultPaid,
            'academy_percentage' => formatPlayerCurrency($defaultGroup['academy_percentage'] ?? 0),
            'academy_amount' => formatPlayerCurrency(calculatePlayerAcademyAmount($defaultPaid, $defaultGroup['academy_percentage'] ?? 0)),
            'subscription_price' => formatPlayerCurrency($defaultPrice),
            'group_level' => (string)($defaultGroup['group_level'] ?? ''),
            'training_days_per_week' => (string)(int)($defaultGroup['training_days_count'] ?? 0),
            'total_training_days' => (string)(int)($defaultGroup['trainings_count'] ?? 0),
            'total_trainings' => (string)(int)($defaultGroup['exercises_count'] ?? 0),
            'trainer_name' => (string)($defaultGroup['trainer_name'] ?? ''),
            'training_time' => formatTrainingTimeLabel($defaultGroup['training_time'] ?? ''),
            'training_day_keys' => getRenewalInitialTrainingDayKeys($renewPlayer, $defaultGroup),
        ];
    } elseif ($renewPlayerId > 0) {
        $error = 'اللاعب غير متاح للتجديد.';
    }
}

$rawSearchTerm = strip_tags(trim((string)($_GET['search'] ?? '')));
$searchTerm = function_exists('mb_substr') ? mb_substr($rawSearchTerm, 0, 100) : substr($rawSearchTerm, 0, 100);
$allPlayers = array_map(function ($row) use ($today) {
    return buildSubscriptionRenewalPlayerRow($row, $today);
}, fetchSubscriptionRenewalPlayers($pdo, $currentGameId));
$filteredPlayers = array_map(function ($row) use ($today) {
    return buildSubscriptionRenewalPlayerRow($row, $today);
}, fetchSubscriptionRenewalPlayers($pdo, $currentGameId, $searchTerm));
$endedPlayers = array_values(array_filter($filteredPlayers, function ($player) {
    return ($player['status'] ?? '') === 'منتهي';
}));
$totalEndedPlayers = count(array_filter($allPlayers, function ($player) {
    return ($player['status'] ?? '') === 'منتهي';
}));
$displayedPlayersCount = count($endedPlayers);
$hasGroups = count($groups) > 0;
$shouldOpenForm = $error !== '' || $formData['player_id'] > 0;
$tableColumnCount = $isManager ? 12 : 10;
$resetSearchUrl = 'subscription_renewal.php';
$closeFormUrl = $searchTerm !== '' ? 'subscription_renewal.php?' . http_build_query(['search' => $searchTerm]) : 'subscription_renewal.php';
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
        'trainer_name' => (string)$group['trainer_name'],
        'academy_percentage' => formatPlayerCurrency($group['academy_percentage'] ?? 0),
        'walkers_price' => formatPlayerCurrency($group['walkers_price'] ?? 0),
        'other_weapons_price' => formatPlayerCurrency($group['other_weapons_price'] ?? 0),
        'civilian_price' => formatPlayerCurrency($group['civilian_price'] ?? 0),
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تجديد الاشتراك</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .renewal-page .renewal-search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: stretch;
        }
        .renewal-page .renewal-search-field {
            flex: 1;
            min-width: 220px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 16px;
            border: 1px solid var(--border);
            border-radius: 18px;
            background: var(--bg-secondary);
            box-shadow: var(--shadow);
        }
        .renewal-page .renewal-search-field input {
            border: 0;
            background: transparent;
            box-shadow: none;
            padding-inline: 0;
        }
        .renewal-page .renewal-search-field input:focus {
            box-shadow: none;
        }
        .renewal-page .renewal-toolbar-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .renewal-page .renewal-highlight {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }
        .renewal-page .renewal-stat-card {
            padding: 22px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(47, 91, 234, 0.12), rgba(124, 58, 237, 0.1));
            border: 1px solid rgba(47, 91, 234, 0.14);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .renewal-page .renewal-stat-card strong {
            font-size: 2rem;
            color: var(--text);
        }
        .renewal-page .renewal-hero-card {
            overflow: hidden;
        }
        .renewal-page .renewal-hero-content {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: space-between;
            align-items: center;
        }
        .renewal-page .renewal-kicker {
            display: inline-flex;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(47, 91, 234, 0.12);
            color: var(--primary);
            font-size: 0.92rem;
        }
        .renewal-page .renewal-hero-title {
            margin-top: 14px;
            color: var(--text);
            line-height: 1.8;
            font-size: 1.35rem;
        }
        .renewal-page .renewal-player-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            padding: 18px;
            border-radius: 18px;
            background: rgba(47, 91, 234, 0.05);
            border: 1px solid rgba(47, 91, 234, 0.12);
        }
        .renewal-page .renewal-player-card span {
            display: block;
            font-size: 0.85rem;
            color: var(--text-soft);
            margin-bottom: 4px;
        }
        .renewal-page .renewal-player-card strong {
            color: var(--text);
            word-break: break-word;
        }
        .renewal-page .renewal-modal-card {
            width: min(1000px, calc(100vw - 24px));
            max-height: calc(100vh - 24px);
            overflow: auto;
        }
        .renewal-page .renewal-empty {
            padding: 30px 18px;
            text-align: center;
        }
        .renewal-page .renewal-table-stack {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .renewal-page .renewal-table-stack span {
            color: var(--text-soft);
            font-size: 0.92rem;
        }
        .renewal-page .renewal-day-count {
            display: inline-flex;
            min-width: 36px;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(47, 91, 234, 0.12);
            color: var(--primary);
            font-weight: 800;
        }
        .renewal-page .player-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .renewal-page .player-field-full {
            grid-column: 1 / -1;
        }
        .renewal-page .select-shell select {
            width: 100%;
        }
        .renewal-page .trainer-day-chip.is-disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .renewal-page .trainer-day-chip input:disabled + span {
            cursor: not-allowed;
        }
        .renewal-page .renewal-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }
        body.dark-mode .renewal-page .renewal-search-field,
        body.dark-mode .renewal-page .renewal-stat-card,
        body.dark-mode .renewal-page .renewal-player-card {
            background: #162133;
            border-color: #334155;
        }
        body.dark-mode .renewal-page .renewal-kicker,
        body.dark-mode .renewal-page .renewal-day-count {
            background: rgba(79, 124, 255, 0.18);
            color: #c7d2fe;
        }
        @media (max-width: 768px) {
            .renewal-page .renewal-toolbar-actions {
                width: 100%;
            }
            .renewal-page .renewal-toolbar-actions .btn,
            .renewal-page .renewal-form-actions .btn {
                flex: 1;
                text-align: center;
            }
            .renewal-page .renewal-search-field {
                min-width: 100%;
            }
            .renewal-page .renewal-modal-card {
                width: calc(100vw - 12px);
                max-height: calc(100vh - 12px);
            }
        }
    </style>
</head>
<body class="dashboard-page renewal-page" data-open-renewal-form="<?php echo $shouldOpenForm ? '1' : '0'; ?>">
<div class="dashboard-layout">
    <?php require 'sidebar_menu.php'; ?>
    <main class="main-content players-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>تجديد الاشتراك</h1>
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

        <section class="renewal-highlight">
            <div class="renewal-stat-card">
                <span class="trainer-stat-label">الاشتراكات المنتهية</span>
                <strong><?php echo $totalEndedPlayers; ?></strong>
            </div>
            <div class="renewal-stat-card">
                <span class="trainer-stat-label">المعروض حالياً</span>
                <strong><?php echo $displayedPlayersCount; ?></strong>
            </div>
        </section>

        <section class="card renewal-hero-card">
            <div class="renewal-hero-content">
                <div>
                    <span class="renewal-kicker">إدارة تجديد الاشتراكات</span>
                </div>
            </div>
        </section>

        <section class="card player-table-card">
            <div class="card-head table-card-head">
                <div>
                    <h3>اللاعبون المنتهي اشتراكهم</h3>
                </div>
                <span class="table-counter"><?php echo $displayedPlayersCount; ?> / <?php echo $totalEndedPlayers; ?></span>
            </div>

            <div class="player-table-toolbar">
                <form method="GET" class="renewal-search-form">
                    <div class="renewal-search-field">
                        <span aria-hidden="true">🔎</span>
                        <input
                            type="search"
                            name="search"
                            placeholder="ابحث بالباركود أو اسم اللاعب أو رقم الهاتف"
                            value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="ابحث بالباركود أو اسم اللاعب أو رقم الهاتف"
                        >
                    </div>
                    <div class="renewal-toolbar-actions">
                        <button type="submit" class="btn btn-soft">بحث</button>
                        <?php if ($searchTerm !== ''): ?>
                            <a href="<?php echo htmlspecialchars($resetSearchUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">إلغاء</a>
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
                            <th>التصنيف</th>
                            <th>المجموعة الحالية</th>
                            <th>تاريخ النهاية</th>
                            <th>التمرينات المتبقية</th>
                            <th>المدفوع</th>
                            <?php if ($isManager): ?>
                                <th>نسبة الأكاديمية</th>
                                <th>مبلغ الأكاديمية</th>
                            <?php endif; ?>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($displayedPlayersCount === 0): ?>
                            <tr>
                                <td colspan="<?php echo $tableColumnCount; ?>" class="empty-cell renewal-empty"><?php echo $searchTerm !== '' ? 'لا توجد نتائج مطابقة.' : 'لا يوجد لاعبون يحتاجون إلى تجديد حالياً.'; ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($endedPlayers as $index => $player): ?>
                                <?php $renewUrl = buildSubscriptionRenewalUrl($searchTerm, $player['id']); ?>
                                <tr>
                                    <td data-label="رقم الصف"><?php echo $index + 1; ?></td>
                                    <td data-label="باركود اللاعب"><?php echo htmlspecialchars($player['barcode'] !== '' ? $player['barcode'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="اسم اللاعب"><strong><?php echo htmlspecialchars($player['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td data-label="رقم الهاتف"><?php echo htmlspecialchars($player['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="التصنيف"><?php echo htmlspecialchars($player['player_category'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="المجموعة الحالية">
                                        <div class="renewal-table-stack">
                                            <strong><?php echo htmlspecialchars($player['group_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span><?php echo htmlspecialchars($player['group_level'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="تاريخ النهاية"><?php echo htmlspecialchars($player['subscription_end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="التمرينات المتبقية"><span class="renewal-day-count"><?php echo (int)$player['remaining_trainings']; ?></span></td>
                                    <td data-label="المدفوع"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($player['paid_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if ($isManager): ?>
                                        <td data-label="نسبة الأكاديمية"><?php echo htmlspecialchars(formatPlayerPercentageLabel($player['academy_percentage']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="مبلغ الأكاديمية"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($player['academy_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endif; ?>
                                    <td data-label="الإجراء"><a href="<?php echo htmlspecialchars($renewUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">تجديد</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<div class="player-modal-overlay<?php echo $shouldOpenForm ? ' is-visible' : ''; ?>" id="renewalModalOverlay">
    <div class="card player-modal-card renewal-modal-card">
        <div class="card-head player-modal-head">
            <div>
                <h3>تجديد اشتراك اللاعب</h3>
            </div>
            <div class="player-modal-actions">
                <a href="<?php echo htmlspecialchars($closeFormUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-soft">إلغاء</a>
                <button type="button" class="btn btn-danger" id="closeRenewalModal">إغلاق</button>
            </div>
        </div>

        <form method="POST" class="login-form player-form" id="renewalForm" data-player-category="<?php echo htmlspecialchars($formData['player_category'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['subscription_renewal_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="player_id" value="<?php echo (int)$formData['player_id']; ?>">

            <div class="trainer-form-section">
                <div class="trainer-section-heading">
                    <h4 class="trainer-section-title">بيانات اللاعب</h4>
                </div>
                <div class="renewal-player-card">
                    <div>
                        <span>الاسم</span>
                        <strong><?php echo htmlspecialchars($formData['name'] !== '' ? $formData['name'] : '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div>
                        <span>الباركود</span>
                        <strong><?php echo htmlspecialchars($formData['barcode'] !== '' ? $formData['barcode'] : '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div>
                        <span>رقم الهاتف</span>
                        <strong><?php echo htmlspecialchars($formData['phone'] !== '' ? $formData['phone'] : '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div>
                        <span>التصنيف</span>
                        <strong><?php echo htmlspecialchars($formData['player_category'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>
            </div>

            <div class="trainer-form-section">
                <div class="trainer-section-heading">
                    <h4 class="trainer-section-title">بيانات التجديد</h4>
                </div>
                <div class="player-form-grid">
                    <div class="form-group player-field-full">
                        <label for="group_id">المجموعة</label>
                        <div class="select-shell">
                            <select name="group_id" id="group_id" <?php echo $hasGroups ? 'required' : 'disabled'; ?>>
                                <option value="">اختر المجموعة</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo (int)$group['id']; ?>" <?php echo (string)(int)$group['id'] === $formData['group_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name'] . ' - ' . $group['group_level'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
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
                        <label for="receipt_number">رقم الإيصال</label>
                        <input type="text" name="receipt_number" id="receipt_number" value="<?php echo htmlspecialchars($formData['receipt_number'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="subscriber_number">رقم المشترك</label>
                        <input type="text" name="subscriber_number" id="subscriber_number" value="<?php echo htmlspecialchars($formData['subscriber_number'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="group_level_display">مستوى المجموعة</label>
                        <input type="text" id="group_level_display" value="<?php echo htmlspecialchars($formData['group_level'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="training_days_per_week_display">عدد أيام التمرين خلال الأسبوع</label>
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
                        <label for="training_time_display">ميعاد التمرين</label>
                        <input type="text" id="training_time_display" value="<?php echo htmlspecialchars($formData['training_time'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
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
                    <p class="trainer-section-subtitle" id="trainingDaysHelper">اختر المجموعة أولًا لعرض الأيام والميعاد المحددين لها.</p>
                </div>
                <div class="trainer-days-grid player-days-grid" id="trainingDaysContainer"></div>
            </div>

            <div class="renewal-form-actions">
                <a href="<?php echo htmlspecialchars($closeFormUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-soft">إلغاء</a>
                <button type="submit" class="btn btn-primary" <?php echo $hasGroups && $formData['player_id'] > 0 ? '' : 'disabled'; ?>>حفظ التجديد</button>
            </div>
        </form>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script id="playerGroupsData" type="application/json"><?php echo json_encode($groupsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script id="playerDayLabels" type="application/json"><?php echo json_encode(PLAYER_DAY_OPTIONS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script id="playerInitialDays" type="application/json"><?php echo json_encode(array_values($formData['training_day_keys']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script src="assets/js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalOverlay = document.getElementById('renewalModalOverlay');
    const closeModalButton = document.getElementById('closeRenewalModal');
    const closeUrl = <?php echo json_encode($closeFormUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const renewalForm = document.getElementById('renewalForm');
    const groupSelect = document.getElementById('group_id');
    const paidAmountInput = document.getElementById('paid_amount');
    const trainingDaysContainer = document.getElementById('trainingDaysContainer');
    const trainingDaysHelper = document.getElementById('trainingDaysHelper');
    const groupLevelDisplay = document.getElementById('group_level_display');
    const trainingDaysPerWeekDisplay = document.getElementById('training_days_per_week_display');
    const totalTrainingDaysDisplay = document.getElementById('total_training_days_display');
    const totalTrainingsDisplay = document.getElementById('total_trainings_display');
    const trainerNameDisplay = document.getElementById('trainer_name_display');
    const trainingTimeDisplay = document.getElementById('training_time_display');
    const subscriptionPriceDisplay = document.getElementById('subscription_price_display');
    const academyPercentageDisplay = document.getElementById('academy_percentage_display');
    const academyAmountDisplay = document.getElementById('academy_amount_display');
    const groupsData = JSON.parse(document.getElementById('playerGroupsData').textContent || '{}');
    const dayLabels = JSON.parse(document.getElementById('playerDayLabels').textContent || '{}');
    let selectedDays = JSON.parse(document.getElementById('playerInitialDays').textContent || '[]');
    const playerCategory = renewalForm ? (renewalForm.dataset.playerCategory || '') : '';

    const getSelectedGroup = function () {
        return groupSelect ? (groupsData[groupSelect.value] || null) : null;
    };

    const getSubscriptionPriceForCategory = function (group) {
        if (!group) {
            return 0;
        }
        if (playerCategory === 'مشاة') {
            return Number(group.walkers_price || 0);
        }
        if (playerCategory === 'اسلحة اخري' || playerCategory === 'أسلحة أخرى') {
            return Number(group.other_weapons_price || 0);
        }
        return Number(group.civilian_price || 0);
    };

    const updateTrainingDaysHelper = function (requiredCount) {
        if (!trainingDaysHelper) {
            return;
        }
        if (!requiredCount) {
            trainingDaysHelper.textContent = 'اختر المجموعة أولًا لعرض الأيام والميعاد المحددين لها.';
            return;
        }
        if (!selectedDays.length) {
            trainingDaysHelper.textContent = 'لا توجد أيام تمرين محددة في هذه المجموعة حتى الآن.';
            return;
        }
        trainingDaysHelper.textContent = 'أيام اللاعب يتم تحديدها تلقائيًا من المجموعة المختارة: ' + selectedDays.length + ' يوم.';
    };

    const renderTrainingDays = function (requiredCount) {
        if (!trainingDaysContainer) {
            return;
        }
        selectedDays = selectedDays.filter(function (dayKey) {
            return !!dayLabels[dayKey];
        });
        trainingDaysContainer.innerHTML = '';
        if (!selectedDays.length) {
            updateTrainingDaysHelper(0);
            return;
        }
        selectedDays.forEach(function (dayKey) {
            const wrapper = document.createElement('label');
            wrapper.className = 'trainer-day-chip is-selected';
            const span = document.createElement('span');
            span.textContent = dayLabels[dayKey];
            wrapper.appendChild(span);
            trainingDaysContainer.appendChild(wrapper);
        });
        updateTrainingDaysHelper(requiredCount);
    };

    const updateDerivedFields = function () {
        const group = getSelectedGroup();
        if (!group) {
            groupLevelDisplay.value = '';
            trainingDaysPerWeekDisplay.value = '';
            totalTrainingDaysDisplay.value = '';
            totalTrainingsDisplay.value = '';
            trainerNameDisplay.value = '';
            if (trainingTimeDisplay) {
                trainingTimeDisplay.value = '';
            }
            subscriptionPriceDisplay.value = '0.00';
            if (academyPercentageDisplay) {
                academyPercentageDisplay.value = '0.00';
            }
            if (academyAmountDisplay) {
                academyAmountDisplay.value = '0.00';
            }
            selectedDays = [];
            renderTrainingDays(0);
            return;
        }

        const subscriptionPrice = getSubscriptionPriceForCategory(group);
        const academyPercentage = Number(group.academy_percentage || 0);
        const paidAmount = Number(paidAmountInput ? (paidAmountInput.value || 0) : 0);
        const academyAmount = (paidAmount * academyPercentage) / 100;
        const requiredTrainingDays = Math.max(0, Number(group.training_days_count || 0));

        groupLevelDisplay.value = group.group_level || '';
        trainingDaysPerWeekDisplay.value = group.training_days_count || '';
        totalTrainingDaysDisplay.value = group.trainings_count || '';
        totalTrainingsDisplay.value = group.exercises_count || '';
        trainerNameDisplay.value = group.trainer_name || '';
        if (trainingTimeDisplay) {
            trainingTimeDisplay.value = group.training_time || '';
        }
        subscriptionPriceDisplay.value = subscriptionPrice.toFixed(2);
        if (academyPercentageDisplay) {
            academyPercentageDisplay.value = academyPercentage.toFixed(2);
        }
        if (academyAmountDisplay) {
            academyAmountDisplay.value = academyAmount.toFixed(2);
        }
        selectedDays = Array.isArray(group.training_day_keys) ? group.training_day_keys.slice() : [];
        renderTrainingDays(requiredTrainingDays);
    };

    if (groupSelect) {
        groupSelect.addEventListener('change', function () {
            selectedDays = [];
            updateDerivedFields();
        });
    }

    if (paidAmountInput) {
        paidAmountInput.addEventListener('input', updateDerivedFields);
    }

    if (closeModalButton) {
        closeModalButton.addEventListener('click', function () {
            window.location.href = closeUrl;
        });
    }

    if (modalOverlay) {
        modalOverlay.addEventListener('click', function (event) {
            if (event.target === modalOverlay) {
                window.location.href = closeUrl;
            }
        });
    }

    updateDerivedFields();
});
</script>
</body>
</html>
