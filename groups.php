<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "players_support.php";
require_once "game_levels_support.php";

requireAuthenticatedUser();
requireMenuAccess("groups");

const GROUPS_PAGE_HREF = "groups.php";

function ensureSportsGroupsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sports_groups (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            group_name VARCHAR(150) NOT NULL,
            group_level VARCHAR(150) NOT NULL,
            training_days_count INT(11) NOT NULL DEFAULT 1,
            training_day_keys VARCHAR(255) NOT NULL DEFAULT '',
            training_time TIME NULL DEFAULT NULL,
            training_day_times LONGTEXT NULL DEFAULT NULL,
            trainings_count INT(11) NOT NULL DEFAULT 1,
            exercises_count INT(11) NOT NULL DEFAULT 1,
            max_players INT(11) NOT NULL DEFAULT 1,
            trainer_name VARCHAR(150) NOT NULL,
            assistant_trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            ballet_trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            walkers_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            other_weapons_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            civilian_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sports_groups_game (game_id),
            KEY idx_sports_groups_game_level (game_id, group_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "training_days_count" => "ALTER TABLE sports_groups ADD COLUMN training_days_count INT(11) NOT NULL DEFAULT 1 AFTER group_level",
        "training_day_keys" => "ALTER TABLE sports_groups ADD COLUMN training_day_keys VARCHAR(255) NOT NULL DEFAULT '' AFTER training_days_count",
        "training_time" => "ALTER TABLE sports_groups ADD COLUMN training_time TIME NULL DEFAULT NULL AFTER training_day_keys",
        "training_day_times" => "ALTER TABLE sports_groups ADD COLUMN training_day_times LONGTEXT NULL DEFAULT NULL AFTER training_time",
        "trainings_count" => "ALTER TABLE sports_groups ADD COLUMN trainings_count INT(11) NOT NULL DEFAULT 1 AFTER training_day_times",
        "exercises_count" => "ALTER TABLE sports_groups ADD COLUMN exercises_count INT(11) NOT NULL DEFAULT 1 AFTER trainings_count",
        "max_players" => "ALTER TABLE sports_groups ADD COLUMN max_players INT(11) NOT NULL DEFAULT 1 AFTER exercises_count",
        "assistant_trainer_name" => "ALTER TABLE sports_groups ADD COLUMN assistant_trainer_name VARCHAR(150) NOT NULL DEFAULT '' AFTER trainer_name",
        "ballet_trainer_name" => "ALTER TABLE sports_groups ADD COLUMN ballet_trainer_name VARCHAR(150) NOT NULL DEFAULT '' AFTER assistant_trainer_name",
        "academy_percentage" => "ALTER TABLE sports_groups ADD COLUMN academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER ballet_trainer_name",
    ];

    $existingColumnsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );

    foreach ($requiredColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(["sports_groups", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log(
                    "Failed to ensure sports_groups.{$columnName} exists using SQL [{$sql}]: "
                    . $throwable->getMessage()
                );
            }
        }
    }

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === "") {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'sports_groups'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    if (!in_array("fk_sports_groups_game", $existingConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE sports_groups
             ADD CONSTRAINT fk_sports_groups_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }
}

function normalizeArabicNumericInput($value)
{
    $value = trim((string)$value);
    if ($value === "") {
        return "";
    }

    $value = strtr($value, [
        "٠" => "0",
        "١" => "1",
        "٢" => "2",
        "٣" => "3",
        "٤" => "4",
        "٥" => "5",
        "٦" => "6",
        "٧" => "7",
        "٨" => "8",
        "٩" => "9",
        "٫" => ".",
        "٬" => "",
        "،" => ".",
    ]);

    return str_replace([" ", "\u{00A0}"], "", $value);
}

function normalizeGroupCountValue($value)
{
    $value = normalizeArabicNumericInput($value);
    if ($value === "" || preg_match('/^\d+$/', $value) !== 1) {
        return "";
    }

    $intValue = (int)$value;
    if ($intValue <= 0) {
        return "";
    }

    return (string)$intValue;
}

function normalizeGroupPriceValue($value)
{
    $value = normalizeArabicNumericInput($value);
    if ($value === "" || !is_numeric($value)) {
        return "";
    }

    $floatValue = (float)$value;
    if ($floatValue < 0) {
        return "";
    }

    return number_format($floatValue, 2, ".", "");
}

function formatCurrencyLabel($value)
{
    return number_format((float)$value, 2) . " ج.م";
}

function normalizeGroupPercentageValue($value)
{
    $value = normalizeArabicNumericInput($value);
    if ($value === "" || !is_numeric($value)) {
        return "";
    }

    $floatValue = (float)$value;
    if ($floatValue < 0 || $floatValue > 100) {
        return "";
    }

    return number_format($floatValue, 2, ".", "");
}

function formatPercentageLabel($value)
{
    return number_format((float)$value, 2) . "%";
}

function escapeSqlLikePattern($value)
{
    return strtr((string)$value, [
        "\\" => "\\\\",
        "%" => "\\%",
        "_" => "\\_",
    ]);
}

function groupRecordExists(PDO $pdo, $gameId, $groupId)
{
    $stmt = $pdo->prepare("SELECT id FROM sports_groups WHERE id = ? AND game_id = ? LIMIT 1");
    $stmt->execute([(int)$groupId, (int)$gameId]);
    return (bool)$stmt->fetch();
}

function getGroupTrainerName(PDO $pdo, $gameId, $groupId)
{
    $stmt = $pdo->prepare("SELECT trainer_name FROM sports_groups WHERE id = ? AND game_id = ? LIMIT 1");
    $stmt->execute([(int)$groupId, (int)$gameId]);
    $trainerName = $stmt->fetchColumn();
    return $trainerName === false ? "" : trim((string)$trainerName);
}

function getGroupTrainerAssignments(PDO $pdo, $gameId, $groupId)
{
    $stmt = $pdo->prepare(
        "SELECT trainer_name, assistant_trainer_name, ballet_trainer_name
         FROM sports_groups
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$groupId, (int)$gameId]);
    $group = $stmt->fetch();

    return [
        "trainer_name" => trim((string)($group["trainer_name"] ?? "")),
        "assistant_trainer_name" => trim((string)($group["assistant_trainer_name"] ?? "")),
        "ballet_trainer_name" => trim((string)($group["ballet_trainer_name"] ?? "")),
    ];
}

function isGymnasticsGame($gameName)
{
    $gameNameWithoutWhitespace = preg_replace('/\s+/u', '', trim((string)$gameName));
    return $gameNameWithoutWhitespace !== '' && preg_match('/جمباز/u', $gameNameWithoutWhitespace) === 1;
}

function convertGroup24HourTimeToParts($time)
{
    $normalizedTime = normalizeTrainingTimeValue($time);
    if ($normalizedTime === '') {
        return [
            "hour" => "",
            "minute" => "",
            "period" => "AM",
        ];
    }

    [$hour, $minute] = array_map('intval', explode(':', substr($normalizedTime, 0, 5)));
    $period = $hour >= 12 ? 'PM' : 'AM';
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return [
        "hour" => str_pad((string)$displayHour, 2, '0', STR_PAD_LEFT),
        "minute" => str_pad((string)$minute, 2, '0', STR_PAD_LEFT),
        "period" => $period,
    ];
}

function convertGroup12HourPartsTo24Hour($hour, $minute, $period)
{
    $hour = trim((string)$hour);
    $minute = trim((string)$minute);
    $period = strtoupper(trim((string)$period));

    if (
        $hour === ''
        || $minute === ''
        || preg_match('/^\d{1,2}$/', $hour) !== 1
        || preg_match('/^\d{1,2}$/', $minute) !== 1
        || !in_array($period, ['AM', 'PM'], true)
    ) {
        return '';
    }

    $hour = (int)$hour;
    $minute = (int)$minute;
    if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
        return '';
    }

    if ($period === 'AM') {
        $hour = $hour === 12 ? 0 : $hour;
    } else {
        $hour = $hour === 12 ? 12 : $hour + 12;
    }

    return str_pad((string)$hour, 2, '0', STR_PAD_LEFT)
        . ':'
        . str_pad((string)$minute, 2, '0', STR_PAD_LEFT)
        . ':00';
}

function decodeGroupTrainingDayTimes($storedValue, array $selectedDayKeys = [], $fallbackTime = '')
{
    $dayTimes = [];
    $decodedValue = json_decode((string)$storedValue, true);
    if (is_array($decodedValue)) {
        foreach ($decodedValue as $dayKey => $timeValue) {
            $dayKey = trim((string)$dayKey);
            $normalizedTime = normalizeTrainingTimeValue($timeValue);
            if ($dayKey !== '' && isset(PLAYER_DAY_OPTIONS[$dayKey]) && $normalizedTime !== '') {
                $dayTimes[$dayKey] = $normalizedTime;
            }
        }
    }

    $selectedDayKeys = sanitizePlayerTrainingDayKeys($selectedDayKeys);
    if (count($dayTimes) === 0) {
        $fallbackTime = normalizeTrainingTimeValue($fallbackTime);
        if ($fallbackTime !== '' && count($selectedDayKeys) > 0) {
            foreach ($selectedDayKeys as $dayKey) {
                $dayTimes[$dayKey] = $fallbackTime;
            }
        }
    }

    if (count($selectedDayKeys) > 0) {
        $filteredDayTimes = [];
        foreach ($selectedDayKeys as $dayKey) {
            if (isset($dayTimes[$dayKey])) {
                $filteredDayTimes[$dayKey] = $dayTimes[$dayKey];
            }
        }
        return $filteredDayTimes;
    }

    return $dayTimes;
}

function normalizeGroupTrainingDayTimes(array $submittedTimes, array $selectedDayKeys)
{
    $normalizedTimes = [];
    foreach ($selectedDayKeys as $dayKey) {
        if (!isset(PLAYER_DAY_OPTIONS[$dayKey])) {
            continue;
        }

        $dayPayload = isset($submittedTimes[$dayKey]) && is_array($submittedTimes[$dayKey])
            ? $submittedTimes[$dayKey]
            : [];
        $normalizedTime = normalizeTrainingTimeValue($dayPayload['time'] ?? ($submittedTimes[$dayKey] ?? ''));
        if ($normalizedTime !== '') {
            $normalizedTimes[$dayKey] = $normalizedTime;
        }
    }

    return $normalizedTimes;
}

function buildGroupTrainingDayTimesFormData(array $dayTimes)
{
    $formDayTimes = [];
    foreach ($dayTimes as $dayKey => $timeValue) {
        if (!isset(PLAYER_DAY_OPTIONS[$dayKey])) {
            continue;
        }
        $formDayTimes[$dayKey] = [
            "time" => formatTrainingTimeLabel($timeValue),
        ];
    }

    return $formDayTimes;
}

function encodeGroupTrainingDayTimes(array $dayTimes)
{
    if (count($dayTimes) === 0) {
        return null;
    }

    return json_encode($dayTimes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function getPrimaryGroupTrainingTime(array $selectedDayKeys, array $dayTimes)
{
    foreach ($selectedDayKeys as $dayKey) {
        if (isset($dayTimes[$dayKey])) {
            return $dayTimes[$dayKey];
        }
    }

    return '';
}

function formatGroupTrainingScheduleLabels(array $selectedDayKeys, array $dayTimes)
{
    $labels = [];
    foreach ($selectedDayKeys as $dayKey) {
        if (!isset(PLAYER_DAY_OPTIONS[$dayKey])) {
            continue;
        }

        $dayLabel = PLAYER_DAY_OPTIONS[$dayKey];
        $timeLabel = formatTrainingTimeDisplay($dayTimes[$dayKey] ?? '');
        if ($timeLabel === '') {
            $labels[] = $dayLabel;
            continue;
        }

        $labels[] = $dayLabel . ': ' . $timeLabel;
    }

    return $labels;
}

function getAssignedGroupTrainerRoles(array $formData, $isGymnasticsGame)
{
    $trainerRoles = [
        'المدرب الأساسي' => trim((string)($formData['trainer_name'] ?? '')),
        'المدرب المساعد' => trim((string)($formData['assistant_trainer_name'] ?? '')),
    ];

    if ($isGymnasticsGame) {
        $trainerRoles['مدرب البالية'] = trim((string)($formData['ballet_trainer_name'] ?? ''));
    }

    return array_filter($trainerRoles, function ($trainerName) {
        return $trainerName !== '';
    });
}

function findGroupTrainerDayOffConflict(array $trainingDayKeys, array $assignedTrainerRoles, array $trainerDaysOffByName)
{
    foreach ($assignedTrainerRoles as $trainerRoleLabel => $trainerName) {
        $trainerDaysOff = $trainerDaysOffByName[$trainerName] ?? [];
        if (count($trainerDaysOff) === 0) {
            continue;
        }

        $conflictingDayKeys = array_intersect($trainingDayKeys, $trainerDaysOff);
        if (count($conflictingDayKeys) === 0) {
            continue;
        }

        $conflictingDayLabels = implode('، ', formatPlayerTrainingDaysLabel($conflictingDayKeys));
        return $trainerRoleLabel . ' "' . $trainerName . '" لديه إجازة في: ' . $conflictingDayLabels . '.';
    }

    return '';
}

function fetchGroupTrainerAvailabilityByName(PDO $pdo, $gameId)
{
    $trainerStmt = $pdo->prepare(
        "SELECT id, name, attendance_time, departure_time
         FROM trainers
         WHERE game_id = ?
           AND name <> ''
         ORDER BY name ASC, id ASC"
    );
    $trainerStmt->execute([(int)$gameId]);
    $trainers = $trainerStmt->fetchAll();
    if (count($trainers) === 0) {
        return [];
    }

    $trainerIds = array_map(static function ($trainer) {
        return (int)($trainer["id"] ?? 0);
    }, $trainers);
    $placeholders = implode(", ", array_fill(0, count($trainerIds), "?"));

    $daysOffByTrainerId = [];
    try {
        $daysOffStmt = $pdo->prepare(
            "SELECT trainer_id, day_key
             FROM trainer_days_off
             WHERE trainer_id IN (" . $placeholders . ")"
        );
        $daysOffStmt->execute($trainerIds);
        foreach ($daysOffStmt->fetchAll() as $row) {
            $trainerId = (int)($row["trainer_id"] ?? 0);
            $dayKey = trim((string)($row["day_key"] ?? ""));
            if ($trainerId > 0 && isset(PLAYER_DAY_OPTIONS[$dayKey])) {
                $daysOffByTrainerId[$trainerId][] = $dayKey;
            }
        }
    } catch (Throwable $throwable) {
        $daysOffByTrainerId = [];
    }

    $scheduleRowsByTrainerId = [];
    try {
        $scheduleStmt = $pdo->prepare(
            "SELECT trainer_id, day_key, attendance_time, departure_time
             FROM trainer_weekly_schedule
             WHERE trainer_id IN (" . $placeholders . ")"
        );
        $scheduleStmt->execute($trainerIds);
        foreach ($scheduleStmt->fetchAll() as $row) {
            $trainerId = (int)($row["trainer_id"] ?? 0);
            $dayKey = trim((string)($row["day_key"] ?? ""));
            if ($trainerId > 0 && isset(PLAYER_DAY_OPTIONS[$dayKey])) {
                $scheduleRowsByTrainerId[$trainerId][$dayKey] = [
                    "attendance_time" => normalizeTrainingTimeValue($row["attendance_time"] ?? ""),
                    "departure_time" => normalizeTrainingTimeValue($row["departure_time"] ?? ""),
                ];
            }
        }
    } catch (Throwable $throwable) {
        $scheduleRowsByTrainerId = [];
    }

    $availabilityByName = [];
    foreach ($trainers as $trainer) {
        $trainerId = (int)($trainer["id"] ?? 0);
        $trainerName = trim((string)($trainer["name"] ?? ""));
        if ($trainerId <= 0 || $trainerName === "") {
            continue;
        }

        $defaultAttendanceTime = normalizeTrainingTimeValue($trainer["attendance_time"] ?? "");
        $defaultDepartureTime = normalizeTrainingTimeValue($trainer["departure_time"] ?? "");
        $daysOff = sanitizePlayerTrainingDayKeys($daysOffByTrainerId[$trainerId] ?? []);
        $scheduleMap = [];
        foreach (PLAYER_DAY_OPTIONS as $dayKey => $_dayLabel) {
            if (in_array($dayKey, $daysOff, true)) {
                continue;
            }

            $rowSchedule = $scheduleRowsByTrainerId[$trainerId][$dayKey] ?? null;
            $attendanceTime = normalizeTrainingTimeValue($rowSchedule["attendance_time"] ?? $defaultAttendanceTime);
            $departureTime = normalizeTrainingTimeValue($rowSchedule["departure_time"] ?? $defaultDepartureTime);
            if ($attendanceTime === "" || $departureTime === "") {
                continue;
            }

            $scheduleMap[$dayKey] = [
                "attendance_time" => $attendanceTime,
                "departure_time" => $departureTime,
            ];
        }

        $availabilityByName[$trainerName][] = [
            "trainer_id" => $trainerId,
            "schedule_map" => $scheduleMap,
        ];
    }

    return $availabilityByName;
}

function isGroupTrainerVariantAvailableForSchedule(array $trainerVariant, array $trainingDayKeys, array $dayTimes)
{
    $scheduleMap = $trainerVariant["schedule_map"] ?? [];
    if (!is_array($scheduleMap)) {
        return false;
    }

    foreach ($trainingDayKeys as $dayKey) {
        if (!isset($scheduleMap[$dayKey])) {
            return false;
        }

        $groupTime = normalizeTrainingTimeValue($dayTimes[$dayKey] ?? "");
        if ($groupTime === "") {
            continue;
        }

        $attendanceTime = normalizeTrainingTimeValue($scheduleMap[$dayKey]["attendance_time"] ?? "");
        $departureTime = normalizeTrainingTimeValue($scheduleMap[$dayKey]["departure_time"] ?? "");
        if ($attendanceTime === "" || $departureTime === "" || $groupTime < $attendanceTime || $groupTime > $departureTime) {
            return false;
        }
    }

    return true;
}

function isGroupTrainerAvailableForSchedule($trainerName, array $trainingDayKeys, array $dayTimes, array $trainerAvailabilityByName)
{
    $trainerName = trim((string)$trainerName);
    if ($trainerName === "") {
        return false;
    }

    $variants = $trainerAvailabilityByName[$trainerName] ?? [];
    foreach ($variants as $variant) {
        if (isGroupTrainerVariantAvailableForSchedule($variant, $trainingDayKeys, $dayTimes)) {
            return true;
        }
    }

    return false;
}

function groupDuplicateExists(PDO $pdo, $gameId, $groupName, $groupLevel, $groupId = 0)
{
    $sql = (int)$groupId > 0
        ? "SELECT id FROM sports_groups WHERE game_id = ? AND group_name = ? AND group_level = ? AND id <> ? LIMIT 1"
        : "SELECT id FROM sports_groups WHERE game_id = ? AND group_name = ? AND group_level = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = (int)$groupId > 0
        ? [(int)$gameId, (string)$groupName, (string)$groupLevel, (int)$groupId]
        : [(int)$gameId, (string)$groupName, (string)$groupLevel];
    $stmt->execute($params);

    return (bool)$stmt->fetch();
}

if (!isset($_SESSION["groups_csrf_token"])) {
    $_SESSION["groups_csrf_token"] = bin2hex(random_bytes(32));
}

ensureSportsGroupsTable($pdo);
ensureGameLevelsTable($pdo);
ensureGameGroupLevelsTable($pdo);

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$isGymnasticsGame = isGymnasticsGame($currentGameName);

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "groups";

$gamesStmt = $pdo->query("SELECT id, name FROM games WHERE status = 1 ORDER BY id ASC");
$allGames = $gamesStmt->fetchAll();
$allGameMap = [];
foreach ($allGames as $game) {
    $allGameMap[(int)$game["id"]] = $game;
}

if ($currentGameId <= 0 || !isset($allGameMap[$currentGameId])) {
    header("Location: dashboard.php");
    exit;
}

$levelOptions = fetchGameGroupLevels($pdo, $currentGameId);
$existingLevelOptionsStmt = $pdo->prepare(
    "SELECT DISTINCT group_level
     FROM sports_groups
     WHERE game_id = ? AND group_level <> ''
     ORDER BY group_level ASC"
);
$existingLevelOptionsStmt->execute([$currentGameId]);
foreach ($existingLevelOptionsStmt->fetchAll(PDO::FETCH_COLUMN) as $existingLevelOption) {
    $existingLevelOption = trim((string)$existingLevelOption);
    if ($existingLevelOption !== '' && !in_array($existingLevelOption, $levelOptions, true)) {
        $levelOptions[] = $existingLevelOption;
    }
}
$trainerSuggestions = [];
$trainerAvailabilityByName = [];

try {
    $trainerAvailabilityByName = fetchGroupTrainerAvailabilityByName($pdo, $currentGameId);
    $trainerSuggestions = array_keys($trainerAvailabilityByName);
} catch (Throwable $throwable) {
    $trainerSuggestions = [];
    $trainerAvailabilityByName = [];
}

$formData = [
    "id" => 0,
    "group_name" => "",
    "group_level" => "",
    "training_days_count" => "",
    "training_day_keys" => [],
    "training_time" => "",
    "training_day_times" => [],
    "trainings_count" => "",
    "exercises_count" => "",
    "max_players" => "",
    "trainer_name" => "",
    "assistant_trainer_name" => "",
    "ballet_trainer_name" => "",
    "academy_percentage" => "",
    "walkers_price" => "",
    "other_weapons_price" => "",
    "civilian_price" => "",
];

$flashSuccess = $_SESSION["groups_success"] ?? "";
unset($_SESSION["groups_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["groups_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["group_id"] ?? 0),
                "group_name" => trim((string)($_POST["group_name"] ?? "")),
                "group_level" => trim((string)($_POST["group_level"] ?? "")),
                "training_days_count" => normalizeGroupCountValue($_POST["training_days_count"] ?? ""),
                "training_day_keys" => sanitizePlayerTrainingDayKeys($_POST["training_day_keys"] ?? []),
                "training_day_times" => [],
                "training_time" => "",
                "trainings_count" => normalizeGroupCountValue($_POST["trainings_count"] ?? ""),
                "exercises_count" => normalizeGroupCountValue($_POST["exercises_count"] ?? ""),
                "max_players" => normalizeGroupCountValue($_POST["max_players"] ?? ""),
                "trainer_name" => trim((string)($_POST["trainer_name"] ?? "")),
                "assistant_trainer_name" => trim((string)($_POST["assistant_trainer_name"] ?? "")),
                "ballet_trainer_name" => trim((string)($_POST["ballet_trainer_name"] ?? "")),
                "academy_percentage" => normalizeGroupPercentageValue($_POST["academy_percentage"] ?? ""),
                "walkers_price" => normalizeGroupPriceValue($_POST["walkers_price"] ?? ""),
                "other_weapons_price" => normalizeGroupPriceValue($_POST["other_weapons_price"] ?? ""),
                "civilian_price" => normalizeGroupPriceValue($_POST["civilian_price"] ?? ""),
            ];
            $formData["training_day_times"] = normalizeGroupTrainingDayTimes(
                $_POST["training_day_times"] ?? [],
                $formData["training_day_keys"]
            );
            $formData["training_time"] = getPrimaryGroupTrainingTime(
                $formData["training_day_keys"],
                $formData["training_day_times"]
            );

            $trainerValidationOptions = $trainerSuggestions;
            if ($formData["id"] > 0) {
                $existingTrainerAssignments = getGroupTrainerAssignments($pdo, $currentGameId, $formData["id"]);
                foreach ($existingTrainerAssignments as $existingTrainerName) {
                    if ($existingTrainerName !== "" && !in_array($existingTrainerName, $trainerValidationOptions, true)) {
                        $trainerValidationOptions[] = $existingTrainerName;
                    }
                }
            }
            if ($formData["group_name"] === "") {
                $error = "اسم المجموعة مطلوب.";
            } elseif ($formData["group_level"] === "") {
                $error = "مستوى المجموعة مطلوب.";
            } elseif (count($levelOptions) > 0 && !in_array($formData["group_level"], $levelOptions, true)) {
                $error = "مستوى المجموعة غير متاح لهذه اللعبة.";
            } elseif ($formData["training_days_count"] === "") {
                $error = "عدد أيام التمرين خلال الأسبوع غير صحيح.";
            } elseif (count($formData["training_day_keys"]) !== (int)$formData["training_days_count"]) {
                $error = "يجب تحديد " . (int)$formData["training_days_count"] . " يوم تمرين للمجموعة.";
            } elseif (count($formData["training_day_times"]) !== count($formData["training_day_keys"])) {
                $error = "يجب إدخال الساعة والدقيقة والفترة (ص/م) بشكل صحيح لكل يوم من أيام التمرين المحددة.";
            } elseif ($formData["trainings_count"] === "") {
                $error = "إجمالي عدد أيام التمرين غير صحيح.";
            } elseif ($formData["exercises_count"] === "") {
                $error = "عدد التمرينات غير صحيح.";
            } elseif ($formData["max_players"] === "") {
                $error = "الحد الأقصى للاعبين غير صحيح.";
            } elseif ($formData["trainer_name"] === "") {
                $error = "اسم المدرب مطلوب.";
            } elseif (!in_array($formData["trainer_name"], $trainerValidationOptions, true)) {
                $error = "المدرب المحدد غير متاح.";
            } elseif (!isGroupTrainerAvailableForSchedule($formData["trainer_name"], $formData["training_day_keys"], $formData["training_day_times"], $trainerAvailabilityByName)) {
                $error = "المدرب الأساسي لا يعمل في الأيام أو المواعيد المختارة للمجموعة.";
            } elseif (
                $formData["assistant_trainer_name"] !== ""
                && !in_array($formData["assistant_trainer_name"], $trainerValidationOptions, true)
            ) {
                $error = "المدرب المساعد المحدد غير متاح.";
            } elseif (
                $formData["assistant_trainer_name"] !== ""
                && !isGroupTrainerAvailableForSchedule($formData["assistant_trainer_name"], $formData["training_day_keys"], $formData["training_day_times"], $trainerAvailabilityByName)
            ) {
                $error = "المدرب المساعد لا يعمل في الأيام أو المواعيد المختارة للمجموعة.";
            } elseif (!$isGymnasticsGame && $formData["ballet_trainer_name"] !== "") {
                $error = "مدرب البالية متاح لمجموعات الجمباز فقط.";
            } elseif (
                $formData["ballet_trainer_name"] !== ""
                && !in_array($formData["ballet_trainer_name"], $trainerValidationOptions, true)
            ) {
                $error = "مدرب البالية المحدد غير متاح.";
            } elseif (
                $formData["ballet_trainer_name"] !== ""
                && !isGroupTrainerAvailableForSchedule($formData["ballet_trainer_name"], $formData["training_day_keys"], $formData["training_day_times"], $trainerAvailabilityByName)
            ) {
                $error = "مدرب البالية لا يعمل في الأيام أو المواعيد المختارة للمجموعة.";
            } elseif ($formData["academy_percentage"] === "") {
                $error = "نسبة الأكاديمية يجب أن تكون من 0 إلى 100.";
            } elseif ($formData["walkers_price"] === "") {
                $error = "سعر المشاة غير صحيح.";
            } elseif ($formData["other_weapons_price"] === "") {
                $error = "سعر الأسلحة الأخرى غير صحيح.";
            } elseif ($formData["civilian_price"] === "") {
                $error = "سعر المدني غير صحيح.";
            } elseif ($formData["id"] > 0 && !groupRecordExists($pdo, $currentGameId, $formData["id"])) {
                $error = "المجموعة غير متاحة.";
            } elseif (groupDuplicateExists($pdo, $currentGameId, $formData["group_name"], $formData["group_level"], $formData["id"])) {
                $error = "هذه المجموعة مسجلة بالفعل لنفس المستوى.";
            } elseif ($formData["id"] > 0 && countPlayersInGroup($pdo, $currentGameId, $formData["id"], 0) > (int)$formData["max_players"]) {
                $error = "الحد الأقصى للاعبين لا يمكن أن يكون أقل من عدد اللاعبين الحاليين بالمجموعة.";
            }

            if ($error === "") {
                if (!$isGymnasticsGame) {
                    $formData["ballet_trainer_name"] = "";
                }
                $trainingDayValue = implode(PLAYER_DAY_SEPARATOR, $formData["training_day_keys"]);
                $trainingDayTimesValue = encodeGroupTrainingDayTimes($formData["training_day_times"]);
                try {
                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE sports_groups
                             SET group_name = ?, group_level = ?, training_days_count = ?, training_day_keys = ?, training_time = ?, training_day_times = ?, trainings_count = ?, exercises_count = ?, max_players = ?, trainer_name = ?,
                                  assistant_trainer_name = ?, ballet_trainer_name = ?, academy_percentage = ?, walkers_price = ?, other_weapons_price = ?, civilian_price = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["group_name"],
                            $formData["group_level"],
                            (int)$formData["training_days_count"],
                            $trainingDayValue,
                            $formData["training_time"],
                            $trainingDayTimesValue,
                            (int)$formData["trainings_count"],
                            (int)$formData["exercises_count"],
                            (int)$formData["max_players"],
                            $formData["trainer_name"],
                            $formData["assistant_trainer_name"],
                            $formData["ballet_trainer_name"],
                            $formData["academy_percentage"],
                            $formData["walkers_price"],
                            $formData["other_weapons_price"],
                            $formData["civilian_price"],
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditTrack($pdo, "update", "sports_groups", $formData["id"], "المجموعات", "تعديل مجموعة: " . $formData["group_name"]);
                        $_SESSION["groups_success"] = "تم تعديل المجموعة ✅";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO sports_groups (
                                game_id, group_name, group_level, training_days_count, training_day_keys, training_time, training_day_times, trainings_count,
                                exercises_count, max_players, trainer_name, assistant_trainer_name, ballet_trainer_name, academy_percentage, walkers_price, other_weapons_price, civilian_price
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["group_name"],
                            $formData["group_level"],
                            (int)$formData["training_days_count"],
                            $trainingDayValue,
                            $formData["training_time"],
                            $trainingDayTimesValue,
                            (int)$formData["trainings_count"],
                            (int)$formData["exercises_count"],
                            (int)$formData["max_players"],
                            $formData["trainer_name"],
                            $formData["assistant_trainer_name"],
                            $formData["ballet_trainer_name"],
                            $formData["academy_percentage"],
                            $formData["walkers_price"],
                            $formData["other_weapons_price"],
                            $formData["civilian_price"],
                        ]);
                        $newGroupId = (int)$pdo->lastInsertId();
                        auditTrack($pdo, "create", "sports_groups", $newGroupId, "المجموعات", "إضافة مجموعة: " . $formData["group_name"]);
                        $_SESSION["groups_success"] = "تم حفظ المجموعة ✅";
                    }

                    header("Location: groups.php");
                    exit;
                } catch (Throwable $throwable) {
                    $error = "تعذر حفظ بيانات المجموعة.";
                }
            }
        }

        if ($action === "delete") {
            $deleteGroupId = (int)($_POST["group_id"] ?? 0);

            if ($deleteGroupId <= 0) {
                $error = "المجموعة غير صالحة.";
            } elseif (!groupRecordExists($pdo, $currentGameId, $deleteGroupId)) {
                $error = "المجموعة غير متاحة.";
            } else {
                try {
                    $grpNameStmt = $pdo->prepare("SELECT group_name FROM sports_groups WHERE id = ? AND game_id = ? LIMIT 1");
                    $grpNameStmt->execute([$deleteGroupId, $currentGameId]);
                    $deletedGroupName = (string)($grpNameStmt->fetchColumn() ?: "");
                    $deleteStmt = $pdo->prepare("DELETE FROM sports_groups WHERE id = ? AND game_id = ?");
                    $deleteStmt->execute([$deleteGroupId, $currentGameId]);
                    auditLogActivity($pdo, "delete", "sports_groups", $deleteGroupId, "المجموعات", "حذف مجموعة: " . $deletedGroupName);
                    $_SESSION["groups_success"] = "تم حذف المجموعة 🗑️";
                    header("Location: groups.php");
                    exit;
                } catch (Throwable $throwable) {
                    $error = "تعذر حذف المجموعة.";
                }
            }
        }
    }
}

$editGroupId = (int)($_GET["edit"] ?? 0);
if ($editGroupId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editStmt = $pdo->prepare(
        "SELECT id, group_name, group_level, training_days_count, training_day_keys, training_time, training_day_times, trainings_count, exercises_count, max_players, trainer_name,
                assistant_trainer_name, ballet_trainer_name, academy_percentage, walkers_price, other_weapons_price, civilian_price
         FROM sports_groups
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $editStmt->execute([$editGroupId, $currentGameId]);
    $editGroup = $editStmt->fetch();

    if ($editGroup) {
        $formData = [
            "id" => (int)$editGroup["id"],
            "group_name" => (string)$editGroup["group_name"],
            "group_level" => (string)$editGroup["group_level"],
            "training_days_count" => (string)(int)$editGroup["training_days_count"],
            "training_day_keys" => getPlayerTrainingDayKeys($editGroup["training_day_keys"] ?? ""),
            "training_day_times" => decodeGroupTrainingDayTimes(
                $editGroup["training_day_times"] ?? "",
                getPlayerTrainingDayKeys($editGroup["training_day_keys"] ?? ""),
                $editGroup["training_time"] ?? ""
            ),
            "training_time" => formatTrainingTimeLabel($editGroup["training_time"] ?? ""),
            "trainings_count" => (string)(int)$editGroup["trainings_count"],
            "exercises_count" => (string)(int)$editGroup["exercises_count"],
            "max_players" => (string)(int)$editGroup["max_players"],
            "trainer_name" => (string)$editGroup["trainer_name"],
            "assistant_trainer_name" => (string)($editGroup["assistant_trainer_name"] ?? ""),
            "ballet_trainer_name" => (string)($editGroup["ballet_trainer_name"] ?? ""),
            "academy_percentage" => number_format((float)$editGroup["academy_percentage"], 2, ".", ""),
            "walkers_price" => number_format((float)$editGroup["walkers_price"], 2, ".", ""),
            "other_weapons_price" => number_format((float)$editGroup["other_weapons_price"], 2, ".", ""),
            "civilian_price" => number_format((float)$editGroup["civilian_price"], 2, ".", ""),
        ];
        $formData["training_time"] = formatTrainingTimeLabel(
            getPrimaryGroupTrainingTime($formData["training_day_keys"], $formData["training_day_times"])
        );
    }
}

$trainerSelectOptions = $trainerSuggestions;
$trainerFieldNames = ["trainer_name", "assistant_trainer_name"];
if ($isGymnasticsGame) {
    $trainerFieldNames[] = "ballet_trainer_name";
}
foreach ($trainerFieldNames as $trainerFieldName) {
    if (
        $formData[$trainerFieldName] !== ""
        && !in_array($formData[$trainerFieldName], $trainerSelectOptions, true)
    ) {
        $trainerSelectOptions[] = $formData[$trainerFieldName];
    }
}
sort($trainerSelectOptions, SORT_NATURAL);
$hasTrainerOptions = count($trainerSelectOptions) > 0;
$initialTrainingDayTimeParts = buildGroupTrainingDayTimesFormData($formData["training_day_times"]);

$groupSearch = isset($_GET["group_search"]) ? trim((string)$_GET["group_search"]) : "";
$filterLevel = isset($_GET["filter_level"]) ? trim((string)$_GET["filter_level"]) : "";
$filterDaysCount = isset($_GET["filter_days_count"]) && $_GET["filter_days_count"] !== "" ? (int)$_GET["filter_days_count"] : null;
$filterDays = isset($_GET["filter_days"]) && is_array($_GET["filter_days"]) ? array_map("trim", $_GET["filter_days"]) : [];
$filterTime = isset($_GET["filter_time"]) ? trim((string)$_GET["filter_time"]) : "";
$filterTrainer = isset($_GET["filter_trainer"]) ? trim((string)$_GET["filter_trainer"]) : "";
$filterGroupStatus = isset($_GET["filter_group_status"]) ? trim((string)$_GET["filter_group_status"]) : "";

$filterDays = array_values(array_unique(array_filter($filterDays, function ($dayKey) {
    return isset(PLAYER_DAY_OPTIONS[$dayKey]);
})));

if (!in_array($filterGroupStatus, ["complete", "incomplete"], true)) {
    $filterGroupStatus = "";
}

$availableLevelsStmt = $pdo->prepare(
    "SELECT DISTINCT group_level
     FROM sports_groups
     WHERE game_id = ? AND group_level <> ''
     ORDER BY group_level ASC"
);
$availableLevelsStmt->execute([$currentGameId]);
$availableLevels = $availableLevelsStmt->fetchAll(PDO::FETCH_COLUMN);
if ($filterLevel !== "" && !in_array($filterLevel, $availableLevels, true)) {
    $filterLevel = "";
}

$availableDaysCountsStmt = $pdo->prepare(
    "SELECT DISTINCT training_days_count
     FROM sports_groups
     WHERE game_id = ?
     ORDER BY training_days_count ASC"
);
$availableDaysCountsStmt->execute([$currentGameId]);
$availableDaysCounts = array_map("intval", $availableDaysCountsStmt->fetchAll(PDO::FETCH_COLUMN));
if ($filterDaysCount !== null && !in_array($filterDaysCount, $availableDaysCounts, true)) {
    $filterDaysCount = null;
}

$availableTimesStmt = $pdo->prepare(
    "SELECT DISTINCT training_time
     FROM sports_groups
     WHERE game_id = ?
       AND training_time IS NOT NULL
       AND training_time <> ''
     ORDER BY training_time ASC"
);
$availableTimesStmt->execute([$currentGameId]);
$availableTimes = array_values(array_filter($availableTimesStmt->fetchAll(PDO::FETCH_COLUMN), function ($value) {
    return $value !== null && $value !== "";
}));
if ($filterTime !== "" && !in_array($filterTime, $availableTimes, true)) {
    $filterTime = "";
}

$availableTrainersStmt = $pdo->prepare(
    "SELECT DISTINCT trainer_name
     FROM sports_groups
     WHERE game_id = ?
       AND trainer_name <> ''
     ORDER BY trainer_name ASC"
);
$availableTrainersStmt->execute([$currentGameId]);
$availableTrainers = $availableTrainersStmt->fetchAll(PDO::FETCH_COLUMN);
if ($filterTrainer !== "" && !in_array($filterTrainer, $availableTrainers, true)) {
    $filterTrainer = "";
}

$groupPlayerCounts = fetchGroupPlayerCounts($pdo, $currentGameId);
$groupsSql = "SELECT id, group_name, group_level, training_days_count, training_day_keys, training_time, training_day_times, trainings_count, exercises_count, max_players, trainer_name,
                     assistant_trainer_name, ballet_trainer_name, academy_percentage, walkers_price, other_weapons_price, civilian_price, created_at, updated_at, created_by_user_id, updated_by_user_id
              FROM sports_groups
              WHERE game_id = ?";
$groupsParams = [$currentGameId];

if ($groupSearch !== "") {
    $groupsSql .= " AND group_name LIKE ? ESCAPE '\\\\'";
    $groupsParams[] = "%" . escapeSqlLikePattern($groupSearch) . "%";
}
if ($filterLevel !== "") {
    $groupsSql .= " AND group_level = ?";
    $groupsParams[] = $filterLevel;
}
if ($filterDaysCount !== null) {
    $groupsSql .= " AND training_days_count = ?";
    $groupsParams[] = $filterDaysCount;
}
if ($filterTime !== "") {
    $groupsSql .= " AND training_time = ?";
    $groupsParams[] = $filterTime;
}
if ($filterTrainer !== "") {
    $groupsSql .= " AND trainer_name = ?";
    $groupsParams[] = $filterTrainer;
}

$groupsSql .= " ORDER BY group_level ASC, group_name ASC, id DESC";
$groupsStmt = $pdo->prepare($groupsSql);
$groupsStmt->execute($groupsParams);
$groups = $groupsStmt->fetchAll();

if (count($filterDays) > 0) {
    $groups = array_values(array_filter($groups, function (array $group) use ($filterDays) {
        $groupDayKeys = getPlayerTrainingDayKeys($group["training_day_keys"] ?? "");
        foreach ($filterDays as $dayKey) {
            if (!in_array($dayKey, $groupDayKeys, true)) {
                return false;
            }
        }

        return true;
    }));
}

foreach ($groups as &$group) {
    $group["current_players_count"] = $groupPlayerCounts[(int)$group["id"]] ?? 0;
    $group["can_add_players"] = playerGroupHasAvailableSlot($group);
    $group["training_day_labels"] = formatPlayerTrainingDaysLabel(getPlayerTrainingDayKeys($group["training_day_keys"] ?? ""));
    $group["training_schedule_labels"] = formatGroupTrainingScheduleLabels(
        getPlayerTrainingDayKeys($group["training_day_keys"] ?? ""),
        decodeGroupTrainingDayTimes(
            $group["training_day_times"] ?? "",
            getPlayerTrainingDayKeys($group["training_day_keys"] ?? ""),
            $group["training_time"] ?? ""
        )
    );
    $group["training_time_label"] = formatTrainingTimeDisplay($group["training_time"] ?? "");
}
unset($group);

if ($filterGroupStatus !== "") {
    $groups = array_values(array_filter($groups, function (array $group) use ($filterGroupStatus) {
        if ($filterGroupStatus === "complete") {
            return !$group["can_add_players"];
        }

        return $group["can_add_players"];
    }));
}

$hasActiveGroupFilters = $groupSearch !== ""
    || $filterLevel !== ""
    || $filterDaysCount !== null
    || count($filterDays) > 0
    || $filterTime !== ""
    || $filterTrainer !== ""
    || $filterGroupStatus !== "";

$trainerSelectAriaLabel = $hasTrainerOptions ? "المدرب" : "لا يوجد مدرب متاح";
$submitButtonLabel = $formData["id"] > 0 ? "تحديث المجموعة" : "حفظ المجموعة";
$submitButtonAriaLabel = $hasTrainerOptions ? $submitButtonLabel : "لا يمكن الحفظ: لا يوجد مدرب متاح";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المجموعات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .filter-bar {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 160px;
        }
        .filter-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text-muted);
        }
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-secondary);
            font-family: inherit;
        }
        .days-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }
        .days-checkboxes label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: normal;
            font-size: 14px;
            cursor: pointer;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .group-training-time-input {
            min-height: 52px;
            font-size: 1rem;
            padding: 12px 14px;
        }
        .group-trainer-warning {
            margin-top: 8px;
            color: #b45309;
            font-size: 0.9rem;
            font-weight: 700;
        }
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; align-items: stretch; }
            .filter-actions { flex-direction: row; justify-content: space-between; }
        }
    </style>
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content groups-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>المجموعات</h1>
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

        <?php if ($success !== ""): ?>
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label for="group_search">اسم المجموعة</label>
                    <input
                        type="text"
                        name="group_search"
                        id="group_search"
                        value="<?php echo htmlspecialchars($groupSearch, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="ابحث باسم المجموعة"
                    >
                </div>

                <div class="filter-group">
                    <label for="filter_level">مستوى المجموعة</label>
                    <select name="filter_level" id="filter_level">
                        <option value="">الكل</option>
                        <?php foreach ($availableLevels as $level): ?>
                            <option value="<?php echo htmlspecialchars((string)$level, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterLevel === (string)$level ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars((string)$level, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_days_count">عدد أيام التمرين أسبوعياً</label>
                    <select name="filter_days_count" id="filter_days_count">
                        <option value="">الكل</option>
                        <?php foreach ($availableDaysCounts as $daysCount): ?>
                            <option value="<?php echo (int)$daysCount; ?>" <?php echo $filterDaysCount !== null && $filterDaysCount === (int)$daysCount ? "selected" : ""; ?>>
                                <?php echo (int)$daysCount; ?> أيام
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_time">ميعاد التمرين</label>
                    <select name="filter_time" id="filter_time">
                        <option value="">الكل</option>
                        <?php foreach ($availableTimes as $timeValue): ?>
                            <option value="<?php echo htmlspecialchars((string)$timeValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterTime === (string)$timeValue ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars(formatTrainingTimeDisplay((string)$timeValue), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_trainer">المدرب</label>
                    <select name="filter_trainer" id="filter_trainer">
                        <option value="">الكل</option>
                        <?php foreach ($availableTrainers as $trainerName): ?>
                            <option value="<?php echo htmlspecialchars((string)$trainerName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterTrainer === (string)$trainerName ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars((string)$trainerName, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_group_status">حالة المجموعة</label>
                    <select name="filter_group_status" id="filter_group_status">
                        <option value="">الكل</option>
                        <option value="complete" <?php echo $filterGroupStatus === "complete" ? "selected" : ""; ?>>مكتملة</option>
                        <option value="incomplete" <?php echo $filterGroupStatus === "incomplete" ? "selected" : ""; ?>>غير مكتملة</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>أيام محددة (اختر الأيام التي تريد أن تتضمنها المجموعة)</label>
                    <div class="days-checkboxes">
                        <?php foreach (PLAYER_DAY_OPTIONS as $dayKey => $dayLabel): ?>
                            <label>
                                <input type="checkbox" name="filter_days[]" value="<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($dayKey, $filterDays, true) ? "checked" : ""; ?>>
                                <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">تصفية</button>
                    <a href="<?php echo GROUPS_PAGE_HREF; ?>" class="btn btn-soft">إلغاء الفلاتر</a>
                </div>
            </form>
        </div>

        <section class="groups-grid">
            <div class="card groups-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل المجموعة" : "إضافة مجموعة"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="<?php echo GROUPS_PAGE_HREF; ?>" class="btn btn-soft" aria-label="إلغاء التعديل">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["groups_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="group_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="groups-form-grid">
                        <div class="form-group group-field-full">
                            <label for="group_name">اسم المجموعة</label>
                            <input type="text" name="group_name" id="group_name" value="<?php echo htmlspecialchars($formData["group_name"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group group-field-full">
                            <label for="group_level">مستوى المجموعة</label>
                            <?php if (count($levelOptions) > 0): ?>
                                <div class="select-shell">
                                    <select name="group_level" id="group_level" required>
                                        <option value="">اختر المستوى</option>
                                        <?php foreach ($levelOptions as $levelOption): ?>
                                            <option value="<?php echo htmlspecialchars($levelOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData["group_level"] === $levelOption ? "selected" : ""; ?>>
                                                <?php echo htmlspecialchars($levelOption, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <small style="color:var(--text-soft,#6b7280);">هذه القائمة تُسحب من مستويات المجموعات المسجلة في صفحة الألعاب.</small>
                            <?php else: ?>
                                <input type="text" name="group_level" id="group_level" value="<?php echo htmlspecialchars($formData["group_level"], ENT_QUOTES, 'UTF-8'); ?>" required>
                                <small style="color:var(--text-soft,#6b7280);">سجّل مستويات المجموعات أولًا من صفحة الألعاب لتظهر هنا كقائمة منسدلة.</small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="training_days_count">عدد أيام التمرين خلال الأسبوع</label>
                            <input type="text" inputmode="numeric" name="training_days_count" id="training_days_count" value="<?php echo htmlspecialchars($formData["training_days_count"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group group-field-full">
                            <label>أيام التمرين للمجموعة</label>
                            <p class="form-helper-text" id="groupTrainingDaysHelper">حدد عدد الأيام المطلوب أولًا، وسيتم حفظ الميعاد بتوقيت جمهورية مصر العربية.</p>
                            <div class="trainer-days-grid player-days-grid" id="groupTrainingDaysContainer"></div>
                        </div>

                        <div class="form-group group-field-full">
                            <label>مواعيد التمرين لكل يوم</label>
                            <p class="form-helper-text" id="groupTrainingTimesHelper">سجّل الميعاد بنظام 24 ساعة لكل يوم تدريب محدد.</p>
                            <div class="groups-form-grid" id="groupTrainingTimesContainer"></div>
                        </div>

                        <div class="form-group">
                            <label for="trainings_count">إجمالي عدد أيام التمرين</label>
                            <input type="text" inputmode="numeric" name="trainings_count" id="trainings_count" value="<?php echo htmlspecialchars($formData["trainings_count"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="exercises_count">عدد التمرينات</label>
                            <input type="text" inputmode="numeric" name="exercises_count" id="exercises_count" value="<?php echo htmlspecialchars($formData["exercises_count"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="max_players">الحد الأقصى للاعبين</label>
                            <input type="text" inputmode="numeric" name="max_players" id="max_players" value="<?php echo htmlspecialchars($formData["max_players"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group group-field-full">
                            <label for="trainer_name">المدرب الأساسي</label>
                            <div class="select-shell trainer-select-shell">
                                <select name="trainer_name" id="trainer_name" class="group-trainer-select" aria-label="<?php echo htmlspecialchars($trainerSelectAriaLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $hasTrainerOptions ? "required" : "disabled"; ?>>
                                    <option value=""><?php echo $hasTrainerOptions ? "اختر المدرب" : "لا يوجد مدرب متاح"; ?></option>
                                    <?php foreach ($trainerSelectOptions as $trainerOption): ?>
                                        <option value="<?php echo htmlspecialchars($trainerOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData["trainer_name"] === $trainerOption ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($trainerOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group group-field-full">
                            <label for="assistant_trainer_name">المدرب المساعد</label>
                            <div class="select-shell trainer-select-shell">
                                <select name="assistant_trainer_name" id="assistant_trainer_name" class="group-trainer-select" <?php echo !$hasTrainerOptions ? "disabled" : ""; ?>>
                                    <option value="">اختياري</option>
                                    <?php foreach ($trainerSelectOptions as $trainerOption): ?>
                                        <option value="<?php echo htmlspecialchars($trainerOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData["assistant_trainer_name"] === $trainerOption ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($trainerOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($isGymnasticsGame): ?>
                            <div class="form-group group-field-full">
                                <label for="ballet_trainer_name">مدرب البالية</label>
                                <div class="select-shell trainer-select-shell">
                                    <select name="ballet_trainer_name" id="ballet_trainer_name" class="group-trainer-select" <?php echo !$hasTrainerOptions ? "disabled" : ""; ?>>
                                        <option value="">اختياري</option>
                                        <?php foreach ($trainerSelectOptions as $trainerOption): ?>
                                            <option value="<?php echo htmlspecialchars($trainerOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData["ballet_trainer_name"] === $trainerOption ? "selected" : ""; ?>>
                                                <?php echo htmlspecialchars($trainerOption, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group group-field-full">
                            <div id="groupTrainerAvailabilityWarning" class="group-trainer-warning" style="display:none;"></div>
                        </div>

                        <div class="form-group">
                            <label for="academy_percentage">نسبة الأكاديمية (%)</label>
                            <input type="text" inputmode="decimal" name="academy_percentage" id="academy_percentage" value="<?php echo htmlspecialchars($formData["academy_percentage"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="walkers_price">سعر المشاة</label>
                            <input type="text" inputmode="decimal" name="walkers_price" id="walkers_price" value="<?php echo htmlspecialchars($formData["walkers_price"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="other_weapons_price">سعر الأسلحة الأخرى</label>
                            <input type="text" inputmode="decimal" name="other_weapons_price" id="other_weapons_price" value="<?php echo htmlspecialchars($formData["other_weapons_price"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group group-field-full">
                            <label for="civilian_price">سعر المدني</label>
                            <input type="text" inputmode="decimal" name="civilian_price" id="civilian_price" value="<?php echo htmlspecialchars($formData["civilian_price"], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" aria-label="<?php echo htmlspecialchars($submitButtonAriaLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$hasTrainerOptions ? "disabled" : ""; ?>><?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
            </div>

            <div class="card groups-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>مجموعات <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></h3>
                    </div>
                    <span class="table-counter">الإجمالي: <?php echo count($groups); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>المجموعة</th>
                                <th>المستوى</th>
                                <th>المدرب</th>
                                <th>أيام التمرين أسبوعياً</th>
                                <th>جدول التمرين</th>
                                <th>إجمالي أيام التمرين</th>
                                <th>عدد التمرينات</th>
                                <th>السعة</th>
                                <th>الأسعار</th>
                                <th>أضيف بواسطة</th>
                                <th>عدّل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($groups) === 0): ?>
                                <tr>
                                    <td colspan="12" class="empty-cell"><?php echo $hasActiveGroupFilters ? "لا توجد مجموعات متاحة تطابق الفلاتر المختارة." : "لا توجد مجموعات مسجلة."; ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($groups as $group): ?>
                                    <tr>
                                        <td data-label="المجموعة">
                                            <div class="group-name-cell">
                                                <strong><?php echo htmlspecialchars($group["group_name"], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="المستوى">
                                            <span class="group-level-pill"><?php echo htmlspecialchars($group["group_level"], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td data-label="المدرب">
                                            <div class="price-stack">
                                                <span class="info-pill">الأساسي: <?php echo htmlspecialchars($group["trainer_name"], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if (trim((string)($group["assistant_trainer_name"] ?? "")) !== ""): ?>
                                                    <span class="price-pill">المساعد: <?php echo htmlspecialchars($group["assistant_trainer_name"], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                                <?php if (trim((string)($group["ballet_trainer_name"] ?? "")) !== ""): ?>
                                                    <span class="price-pill">البالية: <?php echo htmlspecialchars($group["ballet_trainer_name"], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="أيام التمرين أسبوعياً"><?php echo (int)$group["training_days_count"]; ?></td>
                                        <td data-label="جدول التمرين">
                                            <div class="price-stack">
                                                <?php if (count($group["training_schedule_labels"]) > 0): ?>
                                                    <?php foreach ($group["training_schedule_labels"] as $scheduleLabel): ?>
                                                        <span class="price-pill"><?php echo htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8"); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="info-pill">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="إجمالي أيام التمرين"><?php echo (int)$group["trainings_count"]; ?></td>
                                        <td data-label="عدد التمرينات"><?php echo (int)$group["exercises_count"]; ?></td>
                                        <td data-label="السعة"><?php echo (int)$group["current_players_count"]; ?> / <?php echo (int)$group["max_players"]; ?></td>
                                        <td data-label="الأسعار">
                                            <div class="price-stack">
                                                <span class="info-pill">نسبة الأكاديمية: <?php echo htmlspecialchars(formatPercentageLabel($group["academy_percentage"]), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="price-pill">المشاة: <?php echo htmlspecialchars(formatCurrencyLabel($group["walkers_price"]), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="price-pill">الأسلحة الأخرى: <?php echo htmlspecialchars(formatCurrencyLabel($group["other_weapons_price"]), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="price-pill">المدني: <?php echo htmlspecialchars(formatCurrencyLabel($group["civilian_price"]), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $group["created_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $group["updated_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="groups.php?edit=<?php echo (int)$group["id"]; ?>" class="btn btn-warning" aria-label="تعديل المجموعة">✏️</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["groups_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="group_id" value="<?php echo (int)$group["id"]; ?>">
                                                    <button type="submit" class="btn btn-danger" aria-label="حذف المجموعة" onclick="return confirm('هل أنت متأكد من حذف المجموعة؟')">🗑️</button>
                                                </form>
                                            </div>
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
<script id="groupDayLabels" type="application/json"><?php echo json_encode(PLAYER_DAY_OPTIONS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script id="groupInitialDays" type="application/json"><?php echo json_encode(array_values($formData["training_day_keys"]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script id="groupInitialDayTimes" type="application/json"><?php echo json_encode($initialTrainingDayTimeParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script id="groupTrainerAvailability" type="application/json"><?php echo json_encode($trainerAvailabilityByName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script src="assets/js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const trainingDaysCountInput = document.getElementById('training_days_count');
    const trainingDaysContainer = document.getElementById('groupTrainingDaysContainer');
    const trainingDaysHelper = document.getElementById('groupTrainingDaysHelper');
    const trainingTimesContainer = document.getElementById('groupTrainingTimesContainer');
    const trainingTimesHelper = document.getElementById('groupTrainingTimesHelper');
    const trainerAvailabilityWarning = document.getElementById('groupTrainerAvailabilityWarning');
    const dayLabels = JSON.parse(document.getElementById('groupDayLabels').textContent || '{}');
    let selectedDays = JSON.parse(document.getElementById('groupInitialDays').textContent || '[]');
    let selectedDayTimes = JSON.parse(document.getElementById('groupInitialDayTimes').textContent || '{}');
    const trainerAvailabilityByName = JSON.parse(document.getElementById('groupTrainerAvailability').textContent || '{}');
    const trainerSelects = Array.from(document.querySelectorAll('.group-trainer-select'));
    const allTrainerOptions = Array.from(new Set(trainerSelects.flatMap(function (select) {
        return Array.from(select.querySelectorAll('option')).map(function (option) {
            return option.value;
        }).filter(function (value) {
            return value !== '';
        });
    }))).sort(function (firstValue, secondValue) {
        return firstValue.localeCompare(secondValue, 'ar');
    });

    const createTimeField = function (fieldName, selectedValue) {
        const input = document.createElement('input');
        input.type = 'time';
        input.step = '60';
        input.name = fieldName;
        input.value = selectedValue || '';
        input.required = true;
        input.className = 'group-training-time-input';
        if (typeof window.__APP_UI_ENHANCE_TIME_INPUT__ === 'function') {
            window.__APP_UI_ENHANCE_TIME_INPUT__(input);
        } else {
            input.setAttribute('lang', 'en-GB');
            input.setAttribute('inputmode', 'numeric');
            input.setAttribute('dir', 'ltr');
        }
        return input;
    };

    const updateHelper = function (requiredCount) {
        if (!trainingDaysHelper) {
            return;
        }
        if (!requiredCount) {
            trainingDaysHelper.textContent = 'حدد عدد الأيام المطلوب أولًا.';
            return;
        }
        trainingDaysHelper.textContent = 'اختر ' + requiredCount + ' يوم تمرين للمجموعة. المختار الآن: ' + selectedDays.length;
    };

    const updateTimesHelper = function () {
        if (!trainingTimesHelper) {
            return;
        }

        if (selectedDays.length === 0) {
            trainingTimesHelper.textContent = 'حدّد يوم تدريب واحد على الأقل لإظهار حقول المواعيد.';
            return;
        }

        trainingTimesHelper.textContent = 'سجّل الوقت بنظام 24 ساعة لكل يوم من الأيام المحددة.';
    };

    const isTrainerAvailable = function (trainerName) {
        const variants = Array.isArray(trainerAvailabilityByName[trainerName]) ? trainerAvailabilityByName[trainerName] : [];
        if (variants.length === 0) {
            return false;
        }

        return variants.some(function (variant) {
            const scheduleMap = variant && typeof variant === 'object' ? (variant.schedule_map || {}) : {};
            return selectedDays.every(function (dayKey) {
                const daySchedule = scheduleMap[dayKey];
                if (!daySchedule) {
                    return false;
                }

                const selectedTime = (selectedDayTimes[dayKey] && selectedDayTimes[dayKey].time) ? selectedDayTimes[dayKey].time : '';
                if (!selectedTime) {
                    return true;
                }

                const attendanceTime = String(daySchedule.attendance_time || '').slice(0, 5);
                const departureTime = String(daySchedule.departure_time || '').slice(0, 5);
                return attendanceTime !== '' && departureTime !== '' && selectedTime >= attendanceTime && selectedTime <= departureTime;
            });
        });
    };

    const syncTrainerSelectOptions = function () {
        const availableOptions = allTrainerOptions.filter(isTrainerAvailable);
        const unavailableSelected = [];

        trainerSelects.forEach(function (select) {
            const previousValue = select.value;
            const placeholderText = select.id === 'trainer_name'
                ? (availableOptions.length > 0 ? 'اختر المدرب' : 'لا يوجد مدرب متاح')
                : 'اختياري';

            select.innerHTML = '';
            const placeholderOption = document.createElement('option');
            placeholderOption.value = '';
            placeholderOption.textContent = placeholderText;
            select.appendChild(placeholderOption);

            availableOptions.forEach(function (trainerName) {
                const optionElement = document.createElement('option');
                optionElement.value = trainerName;
                optionElement.textContent = trainerName;
                if (trainerName === previousValue) {
                    optionElement.selected = true;
                }
                select.appendChild(optionElement);
            });

            if (previousValue !== '' && availableOptions.indexOf(previousValue) === -1 && allTrainerOptions.indexOf(previousValue) !== -1) {
                const unavailableOption = document.createElement('option');
                unavailableOption.value = previousValue;
                unavailableOption.textContent = previousValue + ' (خارج الأيام أو المواعيد المختارة)';
                unavailableOption.selected = true;
                select.appendChild(unavailableOption);
                unavailableSelected.push(previousValue);
            }

            if (select.id === 'trainer_name') {
                select.disabled = availableOptions.length === 0 && previousValue === '';
                select.required = !select.disabled;
            } else {
                select.disabled = allTrainerOptions.length === 0;
            }
        });

        if (!trainerAvailabilityWarning) {
            return;
        }

        if (unavailableSelected.length > 0) {
            trainerAvailabilityWarning.style.display = 'block';
            trainerAvailabilityWarning.textContent = 'تنبيه: بعض المدربين المختارين لا يعملون في الأيام أو المواعيد الحالية للمجموعة.';
            return;
        }

        if (selectedDays.length > 0 && availableOptions.length === 0) {
            trainerAvailabilityWarning.style.display = 'block';
            trainerAvailabilityWarning.textContent = 'لا يوجد مدرب متاح في الأيام أو المواعيد المختارة حاليًا.';
            return;
        }

        trainerAvailabilityWarning.style.display = 'none';
        trainerAvailabilityWarning.textContent = '';
    };

    const renderTrainingTimes = function () {
        if (!trainingTimesContainer) {
            return;
        }

        trainingTimesContainer.innerHTML = '';

        if (selectedDays.length === 0) {
            updateTimesHelper();
            return;
        }

        selectedDays.forEach(function (dayKey) {
            const dayTime = selectedDayTimes[dayKey] || { time: '' };
            const group = document.createElement('div');
            group.className = 'form-group';

            const label = document.createElement('label');
            label.textContent = dayLabels[dayKey] || dayKey;
            group.appendChild(label);

            const fieldsRow = document.createElement('div');
            const timeField = createTimeField('training_day_times[' + dayKey + '][time]', dayTime.time || '');
            timeField.addEventListener('input', function () {
                selectedDayTimes[dayKey] = {
                    time: timeField.value
                };
                syncTrainerSelectOptions();
            });
            fieldsRow.appendChild(timeField);

            group.appendChild(fieldsRow);
            trainingTimesContainer.appendChild(group);
        });

        updateTimesHelper();
        syncTrainerSelectOptions();
    };

    const renderTrainingDays = function () {
        if (!trainingDaysContainer || !trainingDaysCountInput) {
            return;
        }

        const requiredCount = Math.max(0, Number(trainingDaysCountInput.value || 0));
        selectedDays = selectedDays.filter(function (dayKey) {
            return !!dayLabels[dayKey];
        }).slice(0, requiredCount);

        trainingDaysContainer.innerHTML = '';
        Object.keys(dayLabels).forEach(function (dayKey) {
            const wrapper = document.createElement('label');
            wrapper.className = 'trainer-day-chip';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'training_day_keys[]';
            input.value = dayKey;
            input.checked = selectedDays.indexOf(dayKey) !== -1;
            input.disabled = requiredCount === 0;
            input.addEventListener('change', function () {
                const checkedInputs = Array.from(trainingDaysContainer.querySelectorAll('input:checked')).map(function (element) {
                    return element.value;
                });
                if (checkedInputs.length > requiredCount) {
                    input.checked = false;
                    return;
                }
                selectedDays = checkedInputs;
                updateHelper(requiredCount);
                renderTrainingTimes();
            });

            const text = document.createElement('span');
            text.textContent = dayLabels[dayKey];
            wrapper.appendChild(input);
            wrapper.appendChild(text);
            if (requiredCount === 0) {
                wrapper.classList.add('is-disabled');
            }
            trainingDaysContainer.appendChild(wrapper);
        });

        updateHelper(requiredCount);
        renderTrainingTimes();
    };

    if (trainingDaysCountInput) {
        trainingDaysCountInput.addEventListener('input', renderTrainingDays);
    }

    renderTrainingDays();
    syncTrainerSelectOptions();
});
</script>
</body>
</html>
