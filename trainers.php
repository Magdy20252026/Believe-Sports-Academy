<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

date_default_timezone_set("Africa/Cairo");

const TRAINER_DAY_OPTIONS = [
    "saturday" => "السبت",
    "sunday" => "الأحد",
    "monday" => "الإثنين",
    "tuesday" => "الثلاثاء",
    "wednesday" => "الأربعاء",
    "thursday" => "الخميس",
    "friday" => "الجمعة",
];

const TRAINER_DAY_SEPARATOR = " | ";
const TRAINER_DAY_ORDER_SQL = "'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'";
const TRAINER_TIME_REGEX = '/^\d{2}:\d{2}$/';
const TRAINER_BARCODE_MAX_LENGTH = 100;
const TRAINER_EMPTY_FIELD_DISPLAY = "—";
const TRAINER_TIME_PERIODS = [
    "AM" => "ص",
    "PM" => "م",
];

requireAuthenticatedUser();
requireMenuAccess("trainers");

function ensureTrainersTables(PDO $pdo)
{
    $trainerBarcodeColumnDefinition = getTrainerBarcodeColumnDefinition();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS trainers (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            barcode " . $trainerBarcodeColumnDefinition . " NOT NULL DEFAULT '',
            attendance_time TIME NOT NULL,
            departure_time TIME NOT NULL,
            salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trainers_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS trainer_days_off (
            id INT(11) NOT NULL AUTO_INCREMENT,
            trainer_id INT(11) NOT NULL,
            day_key VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_trainer_day_off (trainer_id, day_key),
            KEY idx_trainer_days_off_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS trainer_weekly_schedule (
            id INT(11) NOT NULL AUTO_INCREMENT,
            trainer_id INT(11) NOT NULL,
            day_key VARCHAR(20) NOT NULL,
            attendance_time TIME NOT NULL,
            departure_time TIME NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_trainer_weekly_schedule_day (trainer_id, day_key),
            KEY idx_trainer_weekly_schedule_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === "") {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT TABLE_NAME, CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME IN ('trainers', 'trainer_days_off', 'trainer_weekly_schedule')
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = [];
    foreach ($constraintsStmt->fetchAll() as $constraint) {
        $existingConstraints[(string)$constraint["TABLE_NAME"]][] = (string)$constraint["CONSTRAINT_NAME"];
    }

    $hourlyRateColStmt = $pdo->query("SHOW COLUMNS FROM trainers LIKE 'hourly_rate'");
    if (!$hourlyRateColStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE trainers
             ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER salary"
        );
    }

    $trainerConstraints = $existingConstraints["trainers"] ?? [];
    if (!in_array("fk_trainers_game", $trainerConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE trainers
             ADD CONSTRAINT fk_trainers_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }

    $daysOffConstraints = $existingConstraints["trainer_days_off"] ?? [];
    if (!in_array("fk_trainer_days_off_trainer", $daysOffConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE trainer_days_off
             ADD CONSTRAINT fk_trainer_days_off_trainer
             FOREIGN KEY (trainer_id) REFERENCES trainers (id) ON DELETE CASCADE"
        );
    }

    $weeklyScheduleConstraints = $existingConstraints["trainer_weekly_schedule"] ?? [];
    if (!in_array("fk_trainer_weekly_schedule_trainer", $weeklyScheduleConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE trainer_weekly_schedule
             ADD CONSTRAINT fk_trainer_weekly_schedule_trainer
             FOREIGN KEY (trainer_id) REFERENCES trainers (id) ON DELETE CASCADE"
        );
    }

    $barcodeColumnStmt = $pdo->query("SHOW COLUMNS FROM trainers LIKE 'barcode'");
    if (!$barcodeColumnStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE trainers
             ADD COLUMN barcode " . $trainerBarcodeColumnDefinition . " NOT NULL DEFAULT '' AFTER phone"
        );
    }

    $passwordColStmt = $pdo->query("SHOW COLUMNS FROM trainers LIKE 'password'");
    if (!$passwordColStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE trainers
             ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT '' AFTER salary"
        );
    }
}

function sanitizeTrainerDayKeys(array $dayKeys)
{
    $sanitized = [];
    foreach ($dayKeys as $dayKey) {
        $dayKey = trim((string)$dayKey);
        if ($dayKey !== "" && isset(TRAINER_DAY_OPTIONS[$dayKey])) {
            $sanitized[] = $dayKey;
        }
    }

    return array_values(array_unique($sanitized));
}

function getTrainerDayOrderSql()
{
    return TRAINER_DAY_ORDER_SQL;
}

function isValidTrainerTime($time)
{
    return preg_match(TRAINER_TIME_REGEX, (string)$time) === 1;
}

function normalizeTrainerSalaryValue($salary)
{
    return number_format((float)$salary, 2, ".", "");
}

function formatTrainerTimeWithSeconds($time)
{
    return (string)$time . ":00";
}

function convertTrainer24HourTimeToParts($time)
{
    $time = substr((string)$time, 0, 5);
    if (!isValidTrainerTime($time)) {
        return [
            "hour" => "",
            "minute" => "",
            "period" => "AM",
        ];
    }

    [$hour, $minute] = array_map("intval", explode(":", $time));
    $period = $hour >= 12 ? "PM" : "AM";
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return [
        "hour" => str_pad((string)$displayHour, 2, "0", STR_PAD_LEFT),
        "minute" => str_pad((string)$minute, 2, "0", STR_PAD_LEFT),
        "period" => $period,
    ];
}

function convertTrainer12HourPartsTo24Hour($hour, $minute, $period)
{
    $hour = (int)$hour;
    $minute = (int)$minute;
    $period = strtoupper(trim((string)$period));

    if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 || !isset(TRAINER_TIME_PERIODS[$period])) {
        return "";
    }

    if ($period === "AM") {
        $hour = $hour === 12 ? 0 : $hour;
    } else {
        $hour = $hour === 12 ? 12 : $hour + 12;
    }

    return str_pad((string)$hour, 2, "0", STR_PAD_LEFT) . ":" . str_pad((string)$minute, 2, "0", STR_PAD_LEFT);
}

function formatTrainerTimeForDisplay($time)
{
    $parts = convertTrainer24HourTimeToParts($time);
    if ($parts["hour"] === "" || $parts["minute"] === "") {
        return "";
    }

    return $parts["hour"] . ":" . $parts["minute"] . " " . TRAINER_TIME_PERIODS[$parts["period"]];
}

function formatEgyptDateTimeLabel(DateTimeInterface $dateTime)
{
    $parts = convertTrainer24HourTimeToParts($dateTime->format("H:i"));
    return $dateTime->format("Y/m/d") . " - " . $parts["hour"] . ":" . $parts["minute"] . " " . TRAINER_TIME_PERIODS[$parts["period"]];
}

function fetchTrainerDayKeys(PDO $pdo, $trainerId)
{
    $stmt = $pdo->prepare(
        "SELECT day_key
         FROM trainer_days_off
         WHERE trainer_id = ?
         ORDER BY FIELD(day_key, " . getTrainerDayOrderSql() . ")"
    );
    $stmt->execute([(int)$trainerId]);

    return sanitizeTrainerDayKeys(array_column($stmt->fetchAll(), "day_key"));
}

function buildEmptyTrainerWeeklyScheduleFormData()
{
    $schedule = [];
    foreach (TRAINER_DAY_OPTIONS as $dayKey => $_dayLabel) {
        $schedule[$dayKey] = [
            "attendance_hour" => "",
            "attendance_minute" => "",
            "attendance_period" => "AM",
            "departure_hour" => "",
            "departure_minute" => "",
            "departure_period" => "AM",
        ];
    }

    return $schedule;
}

function fetchTrainerWeeklySchedulesByTrainerIds(PDO $pdo, array $trainerIds)
{
    $trainerIds = array_values(array_filter(array_map("intval", $trainerIds), function ($trainerId) {
        return $trainerId > 0;
    }));
    if (count($trainerIds) === 0) {
        return [];
    }

    $placeholders = implode(", ", array_fill(0, count($trainerIds), "?"));
    $stmt = $pdo->prepare(
        "SELECT trainer_id, day_key, attendance_time, departure_time
         FROM trainer_weekly_schedule
         WHERE trainer_id IN (" . $placeholders . ")
         ORDER BY trainer_id ASC, FIELD(day_key, " . getTrainerDayOrderSql() . ")"
    );
    $stmt->execute($trainerIds);

    $schedules = [];
    foreach ($stmt->fetchAll() as $row) {
        $trainerId = (int)$row["trainer_id"];
        $dayKey = trim((string)$row["day_key"]);
        if (!isset(TRAINER_DAY_OPTIONS[$dayKey])) {
            continue;
        }

        $schedules[$trainerId][$dayKey] = [
            "attendance_time" => substr((string)$row["attendance_time"], 0, 8),
            "departure_time" => substr((string)$row["departure_time"], 0, 8),
        ];
    }

    return $schedules;
}

function buildTrainerWeeklyScheduleFormData(array $storedSchedules, $defaultAttendanceTime = "", $defaultDepartureTime = "")
{
    $formSchedule = buildEmptyTrainerWeeklyScheduleFormData();
    $hasDefaultTimes = isValidTrainerTime(substr((string)$defaultAttendanceTime, 0, 5))
        && isValidTrainerTime(substr((string)$defaultDepartureTime, 0, 5));

    foreach (TRAINER_DAY_OPTIONS as $dayKey => $_dayLabel) {
        $daySchedule = $storedSchedules[$dayKey] ?? null;
        if (!is_array($daySchedule) && $hasDefaultTimes) {
            $daySchedule = [
                "attendance_time" => substr((string)$defaultAttendanceTime, 0, 8),
                "departure_time" => substr((string)$defaultDepartureTime, 0, 8),
            ];
        }

        if (!is_array($daySchedule)) {
            continue;
        }

        $attendanceParts = convertTrainer24HourTimeToParts($daySchedule["attendance_time"] ?? "");
        $departureParts = convertTrainer24HourTimeToParts($daySchedule["departure_time"] ?? "");
        $formSchedule[$dayKey] = [
            "attendance_hour" => $attendanceParts["hour"],
            "attendance_minute" => $attendanceParts["minute"],
            "attendance_period" => $attendanceParts["period"],
            "departure_hour" => $departureParts["hour"],
            "departure_minute" => $departureParts["minute"],
            "departure_period" => $departureParts["period"],
        ];
    }

    return $formSchedule;
}

function normalizeTrainerWeeklyScheduleInput($scheduleInput)
{
    $normalizedSchedule = buildEmptyTrainerWeeklyScheduleFormData();
    if (!is_array($scheduleInput)) {
        return $normalizedSchedule;
    }

    foreach (TRAINER_DAY_OPTIONS as $dayKey => $_dayLabel) {
        $dayInput = $scheduleInput[$dayKey] ?? null;
        if (!is_array($dayInput)) {
            continue;
        }

        $normalizedSchedule[$dayKey] = [
            "attendance_hour" => trim((string)($dayInput["attendance_hour"] ?? "")),
            "attendance_minute" => trim((string)($dayInput["attendance_minute"] ?? "")),
            "attendance_period" => strtoupper(trim((string)($dayInput["attendance_period"] ?? "AM"))),
            "departure_hour" => trim((string)($dayInput["departure_hour"] ?? "")),
            "departure_minute" => trim((string)($dayInput["departure_minute"] ?? "")),
            "departure_period" => strtoupper(trim((string)($dayInput["departure_period"] ?? "AM"))),
        ];
    }

    return $normalizedSchedule;
}

function resolveTrainerWeeklySchedule(array $storedSchedules, array $daysOff, $defaultAttendanceTime = "", $defaultDepartureTime = "")
{
    $resolvedSchedule = [];
    $hasDefaultTimes = isValidTrainerTime(substr((string)$defaultAttendanceTime, 0, 5))
        && isValidTrainerTime(substr((string)$defaultDepartureTime, 0, 5));

    foreach (TRAINER_DAY_OPTIONS as $dayKey => $_dayLabel) {
        if (in_array($dayKey, $daysOff, true)) {
            continue;
        }

        $daySchedule = $storedSchedules[$dayKey] ?? null;
        if (!is_array($daySchedule) && $hasDefaultTimes) {
            $daySchedule = [
                "attendance_time" => substr((string)$defaultAttendanceTime, 0, 8),
                "departure_time" => substr((string)$defaultDepartureTime, 0, 8),
            ];
        }

        if (!is_array($daySchedule)) {
            continue;
        }

        $resolvedSchedule[$dayKey] = [
            "attendance_time" => substr((string)($daySchedule["attendance_time"] ?? ""), 0, 8),
            "departure_time" => substr((string)($daySchedule["departure_time"] ?? ""), 0, 8),
        ];
    }

    return $resolvedSchedule;
}

function getFirstTrainerWorkingSchedule(array $resolvedSchedule)
{
    foreach (TRAINER_DAY_OPTIONS as $dayKey => $_dayLabel) {
        if (isset($resolvedSchedule[$dayKey])) {
            return $resolvedSchedule[$dayKey];
        }
    }

    return null;
}

function trainerPhoneExists(PDO $pdo, $gameId, $phone, $trainerId = 0)
{
    $sql = (int)$trainerId > 0
        ? "SELECT id FROM trainers WHERE game_id = ? AND phone = ? AND id <> ? LIMIT 1"
        : "SELECT id FROM trainers WHERE game_id = ? AND phone = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = (int)$trainerId > 0
        ? [(int)$gameId, (string)$phone, (int)$trainerId]
        : [(int)$gameId, (string)$phone];
    $stmt->execute($params);

    return (bool)$stmt->fetch();
}

function trainerBarcodeExists(PDO $pdo, $gameId, $barcode, $trainerId = 0)
{
    if ((string)$barcode === "") {
        return false;
    }

    $sql = (int)$trainerId > 0
        ? "SELECT id FROM trainers WHERE game_id = ? AND barcode = ? AND id <> ? LIMIT 1"
        : "SELECT id FROM trainers WHERE game_id = ? AND barcode = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = (int)$trainerId > 0
        ? [(int)$gameId, (string)$barcode, (int)$trainerId]
        : [(int)$gameId, (string)$barcode];
    $stmt->execute($params);

    return (bool)$stmt->fetch();
}

function isTrainerBarcodeTooLong($barcode)
{
    return function_exists("mb_strlen")
        ? mb_strlen((string)$barcode) > TRAINER_BARCODE_MAX_LENGTH
        : strlen((string)$barcode) > TRAINER_BARCODE_MAX_LENGTH;
}

function getTrainerBarcodeColumnDefinition()
{
    return "VARCHAR(" . max(1, (int)TRAINER_BARCODE_MAX_LENGTH) . ")";
}

function logTrainerException(Throwable $throwable)
{
    error_log("Trainers page error: " . $throwable->getMessage());
}

function updateTrainerPortalPassword(PDO $pdo, int $trainerId, int $gameId, string $plainPassword)
{
    if ($plainPassword === "") {
        return;
    }

    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        throw new RuntimeException("تعذر إنشاء كلمة مرور المدرب.");
    }

    $pwStmt = $pdo->prepare("UPDATE trainers SET password = ? WHERE id = ? AND game_id = ?");
    $pwStmt->execute([$hashedPassword, $trainerId, $gameId]);
}

if (!isset($_SESSION["trainers_csrf_token"])) {
    $_SESSION["trainers_csrf_token"] = bin2hex(random_bytes(32));
}

ensureTrainersTables($pdo);

$success = "";
$error = "";
$isManager = (string)($_SESSION["role"] ?? "") === "مدير";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$isSwimming = mb_stripos($currentGameName, "سباح") !== false;

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "trainers";

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

$formData = [
    "id" => 0,
    "name" => "",
    "phone" => "",
    "barcode" => "",
    "salary" => "",
    "hourly_rate" => "",
    "password" => "",
    "days_off" => [],
    "weekly_schedule" => buildEmptyTrainerWeeklyScheduleFormData(),
];

$egyptNow = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$egyptDateTimeLabel = formatEgyptDateTimeLabel($egyptNow);

$flashSuccess = $_SESSION["trainers_success"] ?? "";
unset($_SESSION["trainers_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["trainers_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["trainer_id"] ?? 0),
                "name" => trim($_POST["name"] ?? ""),
                "phone" => trim($_POST["phone"] ?? ""),
                "barcode" => trim($_POST["barcode"] ?? ""),
                "salary" => $isManager ? trim($_POST["salary"] ?? "") : "",
                "hourly_rate" => ($isManager && $isSwimming) ? trim($_POST["hourly_rate"] ?? "") : "",
                "password" => trim($_POST["trainer_password"] ?? ""),
                "days_off" => sanitizeTrainerDayKeys($_POST["days_off"] ?? []),
                "weekly_schedule" => normalizeTrainerWeeklyScheduleInput($_POST["weekly_schedule"] ?? []),
            ];
            $resolvedWeeklySchedule = [];

            if ($formData["name"] === "") {
                $error = "اسم المدرب مطلوب.";
            } elseif ($formData["phone"] === "") {
                $error = "رقم الهاتف مطلوب.";
            } elseif (!preg_match('/^[0-9+\-\s()]{6,20}$/', $formData["phone"])) {
                $error = "رقم الهاتف غير صحيح.";
            } elseif (isTrainerBarcodeTooLong($formData["barcode"])) {
                $error = "باركود المدرب طويل جدًا.";
            } elseif ($isManager && $formData["salary"] === "") {
                $error = "الراتب مطلوب.";
            } elseif ($isManager && (!is_numeric($formData["salary"]) || (float)$formData["salary"] < 0)) {
                $error = "الراتب غير صحيح.";
            } elseif ($isManager && $isSwimming && $formData["hourly_rate"] !== "" && (!is_numeric($formData["hourly_rate"]) || (float)$formData["hourly_rate"] < 0)) {
                $error = "سعر الساعة غير صحيح.";
            }

            if ($error === "") {
                foreach (TRAINER_DAY_OPTIONS as $dayKey => $dayLabel) {
                    if (in_array($dayKey, $formData["days_off"], true)) {
                        continue;
                    }

                    $daySchedule = $formData["weekly_schedule"][$dayKey] ?? [];
                    $attendanceTime = convertTrainer12HourPartsTo24Hour(
                        $daySchedule["attendance_hour"] ?? "",
                        $daySchedule["attendance_minute"] ?? "",
                        $daySchedule["attendance_period"] ?? "AM"
                    );
                    $departureTime = convertTrainer12HourPartsTo24Hour(
                        $daySchedule["departure_hour"] ?? "",
                        $daySchedule["departure_minute"] ?? "",
                        $daySchedule["departure_period"] ?? "AM"
                    );

                    if ($attendanceTime === "" || $departureTime === "") {
                        $error = "حدد ميعاد الحضور والانصراف ليوم " . $dayLabel . ".";
                        break;
                    }

                    if ($attendanceTime >= $departureTime) {
                        $error = "يجب أن يكون ميعاد الانصراف بعد ميعاد الحضور ليوم " . $dayLabel . ".";
                        break;
                    }

                    $resolvedWeeklySchedule[$dayKey] = [
                        "attendance_time" => formatTrainerTimeWithSeconds($attendanceTime),
                        "departure_time" => formatTrainerTimeWithSeconds($departureTime),
                    ];
                }
            }

            if ($error === "" && count($resolvedWeeklySchedule) === 0) {
                $error = "يجب تحديد يوم عمل واحد على الأقل.";
            }

            if ($error === "" && $formData["id"] > 0) {
                $trainerExistsStmt = $pdo->prepare(
                    "SELECT id, salary
                     FROM trainers
                     WHERE id = ? AND game_id = ?
                     LIMIT 1"
                );
                $trainerExistsStmt->execute([$formData["id"], $currentGameId]);
                $existingTrainer = $trainerExistsStmt->fetch();
                if (!$existingTrainer) {
                    $error = "المدرب غير متاح.";
                }
            } else {
                $existingTrainer = null;
            }

            if ($error === "") {
                if (trainerPhoneExists($pdo, $currentGameId, $formData["phone"], $formData["id"])) {
                    $error = "رقم الهاتف مستخدم بالفعل.";
                } elseif ($formData["barcode"] !== "" && trainerBarcodeExists($pdo, $currentGameId, $formData["barcode"], $formData["id"])) {
                    $error = "باركود المدرب مستخدم بالفعل.";
                }
            }

            if ($error === "") {
                $salaryValue = $isManager
                    ? normalizeTrainerSalaryValue($formData["salary"])
                    : normalizeTrainerSalaryValue($existingTrainer["salary"] ?? 0);

                if ($isManager && $isSwimming && $formData["hourly_rate"] !== "") {
                    $hourlyRateValue = number_format((float)$formData["hourly_rate"], 2, ".", "");
                } else {
                    $hourlyRateValue = $isSwimming ? normalizeTrainerSalaryValue($existingTrainer["hourly_rate"] ?? 0) : "0.00";
                }

                try {
                    $pdo->beginTransaction();
                    $defaultSchedule = getFirstTrainerWorkingSchedule($resolvedWeeklySchedule);

                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE trainers
                             SET name = ?, phone = ?, barcode = ?, attendance_time = ?, departure_time = ?, salary = ?, hourly_rate = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["name"],
                            $formData["phone"],
                            $formData["barcode"],
                            $defaultSchedule["attendance_time"],
                            $defaultSchedule["departure_time"],
                            $salaryValue,
                            $hourlyRateValue,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        $trainerId = $formData["id"];
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO trainers (game_id, name, phone, barcode, attendance_time, departure_time, salary, hourly_rate)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["name"],
                            $formData["phone"],
                            $formData["barcode"],
                            $defaultSchedule["attendance_time"],
                            $defaultSchedule["departure_time"],
                            $salaryValue,
                            $hourlyRateValue,
                        ]);
                        $trainerId = (int)$pdo->lastInsertId();
                    }

                    updateTrainerPortalPassword($pdo, $trainerId, $currentGameId, $formData["password"]);

                    $deleteDaysStmt = $pdo->prepare("DELETE FROM trainer_days_off WHERE trainer_id = ?");
                    $deleteDaysStmt->execute([$trainerId]);

                    $deleteScheduleStmt = $pdo->prepare("DELETE FROM trainer_weekly_schedule WHERE trainer_id = ?");
                    $deleteScheduleStmt->execute([$trainerId]);

                    $insertDayStmt = $pdo->prepare(
                        "INSERT INTO trainer_days_off (trainer_id, day_key)
                         VALUES (?, ?)"
                    );
                    foreach ($formData["days_off"] as $dayKey) {
                        $insertDayStmt->execute([$trainerId, $dayKey]);
                    }

                    $insertScheduleStmt = $pdo->prepare(
                        "INSERT INTO trainer_weekly_schedule (trainer_id, day_key, attendance_time, departure_time)
                         VALUES (?, ?, ?, ?)"
                    );
                    foreach ($resolvedWeeklySchedule as $dayKey => $daySchedule) {
                        $insertScheduleStmt->execute([
                            $trainerId,
                            $dayKey,
                            $daySchedule["attendance_time"],
                            $daySchedule["departure_time"],
                        ]);
                    }

                    auditTrack($pdo, $formData["id"] > 0 ? "update" : "create", "trainers", $trainerId, "المدربين", ($formData["id"] > 0 ? "تعديل بيانات مدرب: " : "إضافة مدرب: ") . (string)$formData["name"]);
                    $pdo->commit();
                    $_SESSION["trainers_success"] = $formData["id"] > 0 ? "تم تحديث المدرب." : "تم حفظ المدرب.";
                    header("Location: trainers.php");
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    logTrainerException($throwable);
                    $error = "تعذر حفظ بيانات المدرب.";
                }
            }
        }

        if ($action === "delete") {
            $deleteTrainerId = (int)($_POST["trainer_id"] ?? 0);

            if ($deleteTrainerId <= 0) {
                $error = "المدرب غير صالح.";
            } else {
                $deletedNameStmt = $pdo->prepare("SELECT name FROM trainers WHERE id = ? AND game_id = ? LIMIT 1");
                $deletedNameStmt->execute([$deleteTrainerId, $currentGameId]);
                $deletedTrainerName = (string)($deletedNameStmt->fetchColumn() ?: "");
                $deleteStmt = $pdo->prepare("DELETE FROM trainers WHERE id = ? AND game_id = ?");

                try {
                    $deleteStmt->execute([$deleteTrainerId, $currentGameId]);
                    if ($deleteStmt->rowCount() === 0) {
                        $error = "المدرب غير متاح.";
                    } else {
                        auditLogActivity($pdo, "delete", "trainers", $deleteTrainerId, "المدربين", "حذف مدرب: " . $deletedTrainerName);
                        $_SESSION["trainers_success"] = "تم حذف المدرب.";
                        header("Location: trainers.php");
                        exit;
                    }
                } catch (Throwable $throwable) {
                    logTrainerException($throwable);
                    $error = "تعذر حذف المدرب.";
                }
            }
        }
    }
}

$editTrainerId = (int)($_GET["edit"] ?? 0);
if ($editTrainerId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editStmt = $pdo->prepare(
        "SELECT id, name, phone, barcode, attendance_time, departure_time, salary, hourly_rate
         FROM trainers
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $editStmt->execute([$editTrainerId, $currentGameId]);
    $editTrainer = $editStmt->fetch();

    if ($editTrainer) {
        $weeklySchedules = fetchTrainerWeeklySchedulesByTrainerIds($pdo, [$editTrainerId]);
        $formData = [
            "id" => (int)$editTrainer["id"],
            "name" => (string)$editTrainer["name"],
            "phone" => (string)$editTrainer["phone"],
            "barcode" => (string)($editTrainer["barcode"] ?? ""),
            "salary" => $isManager ? number_format((float)$editTrainer["salary"], 2, ".", "") : "",
            "hourly_rate" => ($isManager && $isSwimming) ? number_format((float)($editTrainer["hourly_rate"] ?? 0), 2, ".", "") : "",
            "password" => "",
            "days_off" => fetchTrainerDayKeys($pdo, $editTrainerId),
            "weekly_schedule" => buildTrainerWeeklyScheduleFormData(
                $weeklySchedules[$editTrainerId] ?? [],
                $editTrainer["attendance_time"] ?? "",
                $editTrainer["departure_time"] ?? ""
            ),
        ];
    }
}

$rawSearchTerm = strip_tags(trim((string)($_GET["search"] ?? "")));
$searchTerm = function_exists("mb_substr")
    ? mb_substr($rawSearchTerm, 0, 100)
    : substr($rawSearchTerm, 0, 100);

$statsStmt = $pdo->prepare("SELECT COUNT(*) AS trainer_count, COALESCE(SUM(salary), 0) AS total_salary FROM trainers WHERE game_id = ?");
$statsStmt->execute([$currentGameId]);
$statsRow = $statsStmt->fetch() ?: ["trainer_count" => 0, "total_salary" => 0];

$listSql = "SELECT
        t.id,
        t.name,
        t.phone,
        t.barcode,
        t.attendance_time,
        t.departure_time,
        t.salary,
        t.hourly_rate,
        t.created_by_user_id,
        t.updated_by_user_id,
        GROUP_CONCAT(
            DISTINCT td.day_key
            ORDER BY FIELD(td.day_key, " . getTrainerDayOrderSql() . ")
            SEPARATOR '" . TRAINER_DAY_SEPARATOR . "'
        ) AS days_off
     FROM trainers t
     LEFT JOIN trainer_days_off td ON td.trainer_id = t.id
     WHERE t.game_id = ?";
$listParams = [$currentGameId];
if ($searchTerm !== "") {
    $listSql .= " AND (t.name LIKE ? OR t.phone LIKE ? OR t.barcode LIKE ?)";
    $searchLike = "%" . $searchTerm . "%";
    $listParams[] = $searchLike;
    $listParams[] = $searchLike;
    $listParams[] = $searchLike;
}
$listSql .= "
     GROUP BY t.id, t.name, t.phone, t.barcode, t.attendance_time, t.departure_time, t.salary, t.created_by_user_id, t.updated_by_user_id
     ORDER BY t.id DESC";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($listParams);
$trainers = $listStmt->fetchAll();
$trainerSchedulesById = fetchTrainerWeeklySchedulesByTrainerIds($pdo, array_column($trainers, "id"));
foreach ($trainers as &$trainer) {
    $dayKeys = sanitizeTrainerDayKeys(array_filter(explode(TRAINER_DAY_SEPARATOR, (string)($trainer["days_off"] ?? ""))));
    $trainer["resolved_schedule"] = resolveTrainerWeeklySchedule(
        $trainerSchedulesById[(int)$trainer["id"]] ?? [],
        $dayKeys,
        $trainer["attendance_time"] ?? "",
        $trainer["departure_time"] ?? ""
    );
}
unset($trainer);

$trainerCount = (int)($statsRow["trainer_count"] ?? 0);
$displayedTrainerCount = count($trainers);
$totalSalaryAmount = (float)($statsRow["total_salary"] ?? 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المدربين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">القائمة</button>
                <div>
                    <h1>المدربين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge"><?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="context-badge egypt-datetime-badge" id="egyptDateTime"><?php echo htmlspecialchars($egyptDateTimeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
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
                <span class="trainer-stat-label">إجمالي المدربين</span>
                <strong class="trainer-stat-value"><?php echo $trainerCount; ?></strong>
            </div>
            <?php if ($isManager): ?>
                <div class="card trainer-stat-card">
                    <span class="trainer-stat-label">إجمالي الرواتب</span>
                    <strong class="trainer-stat-value"><?php echo htmlspecialchars(number_format($totalSalaryAmount, 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            <?php endif; ?>
        </section>

        <section class="trainers-grid">
            <div class="card trainer-form-card" id="trainerFormCard">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل مدرب" : "إضافة مدرب"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="trainers.php" class="btn btn-soft" aria-label="إلغاء">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["trainers_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="trainer_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="trainer-form-section">
                        <div class="trainer-section-heading">
                            <h4 class="trainer-section-title">البيانات الأساسية</h4>
                        </div>
                        <div class="trainer-basic-grid">
                            <div class="form-group">
                                <label for="name">اسم المدرب</label>
                                <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($formData["name"], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone">رقم الهاتف</label>
                                <input type="text" name="phone" id="phone" inputmode="tel" value="<?php echo htmlspecialchars($formData["phone"], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="barcode">باركود المدرب</label>
                                <input type="text" name="barcode" id="barcode" value="<?php echo htmlspecialchars($formData["barcode"], ENT_QUOTES, 'UTF-8'); ?>" maxlength="<?php echo TRAINER_BARCODE_MAX_LENGTH; ?>" placeholder="أدخل باركود المدرب">
                            </div>

                            <div class="form-group">
                                <label for="trainer_password">كلمة المرور <span style="font-size:0.85em;color:#888;">(اختياري<?php echo $formData["id"] > 0 ? " — اتركها فارغة لعدم التغيير" : ""; ?>)</span></label>
                                <input type="password" name="trainer_password" id="trainer_password" value="" autocomplete="new-password" placeholder="أدخل كلمة مرور جديدة">
                            </div>

                            <?php if ($isManager): ?>
                                <div class="form-group">
                                    <label for="salary">الراتب</label>
                                    <input type="number" name="salary" id="salary" min="0" step="0.01" value="<?php echo htmlspecialchars($formData["salary"], ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            <?php endif; ?>
                            <?php if ($isManager && $isSwimming): ?>
                                <div class="form-group">
                                    <label for="hourly_rate">سعر الساعة</label>
                                    <input type="number" name="hourly_rate" id="hourly_rate" min="0" step="0.01" value="<?php echo htmlspecialchars($formData["hourly_rate"], ENT_QUOTES, 'UTF-8'); ?>" placeholder="0.00">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="trainer-form-section">
                        <div class="trainer-section-heading">
                            <h4 class="trainer-section-title">أيام الإجازة</h4>
                            <p class="trainer-section-subtitle">اختياري، ويمكن تركها بدون تحديد إذا كان المدرب يعمل طوال الأسبوع.</p>
                        </div>
                        <div class="form-group">
                            <div class="trainer-days-grid">
                                <?php foreach (TRAINER_DAY_OPTIONS as $dayKey => $dayLabel): ?>
                                    <label class="trainer-day-chip">
                                        <input type="checkbox" name="days_off[]" value="<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($dayKey, $formData["days_off"], true) ? "checked" : ""; ?>>
                                        <span><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="trainer-form-section">
                        <div class="trainer-section-heading">
                            <h4 class="trainer-section-title">الجدول الأسبوعي</h4>
                            <p class="trainer-section-subtitle">حدد ميعاد الحضور والانصراف لكل يوم عمل، وسيتم تجاهل اليوم المحدد كإجازة.</p>
                        </div>
                        <div class="trainer-weekly-schedule-grid">
                            <?php foreach (TRAINER_DAY_OPTIONS as $dayKey => $dayLabel): ?>
                                <?php
                                $daySchedule = $formData["weekly_schedule"][$dayKey] ?? buildEmptyTrainerWeeklyScheduleFormData()[$dayKey];
                                $isDayOff = in_array($dayKey, $formData["days_off"], true);
                                ?>
                                <div class="trainer-weekly-schedule-card<?php echo $isDayOff ? " is-day-off" : ""; ?>" data-day-key="<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="trainer-weekly-schedule-head">
                                        <strong><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span class="badge trainer-schedule-status" data-schedule-status><?php echo $isDayOff ? "يوم إجازة" : "يوم عمل"; ?></span>
                                    </div>
                                    <div class="trainer-weekly-schedule-time-block">
                                        <label>الحضور</label>
                                        <div class="trainer-time-selects">
                                            <select name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][attendance_hour]" aria-label="ساعة حضور <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                                <option value="">الساعة</option>
                                                <?php for ($hour = 1; $hour <= 12; $hour++): ?>
                                                    <?php $hourValue = str_pad((string)$hour, 2, "0", STR_PAD_LEFT); ?>
                                                    <option value="<?php echo $hourValue; ?>" <?php echo ($daySchedule["attendance_hour"] ?? "") === $hourValue ? "selected" : ""; ?>><?php echo $hourValue; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <select name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][attendance_minute]" aria-label="دقيقة حضور <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                                <option value="">الدقيقة</option>
                                                <?php for ($minute = 0; $minute <= 59; $minute++): ?>
                                                    <?php $minuteValue = str_pad((string)$minute, 2, "0", STR_PAD_LEFT); ?>
                                                    <option value="<?php echo $minuteValue; ?>" <?php echo ($daySchedule["attendance_minute"] ?? "") === $minuteValue ? "selected" : ""; ?>><?php echo $minuteValue; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <select name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][attendance_period]" aria-label="فترة حضور <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                                <?php foreach (TRAINER_TIME_PERIODS as $periodValue => $periodLabel): ?>
                                                    <option value="<?php echo $periodValue; ?>" <?php echo ($daySchedule["attendance_period"] ?? "AM") === $periodValue ? "selected" : ""; ?>><?php echo $periodLabel; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="trainer-weekly-schedule-time-block">
                                        <label>الانصراف</label>
                                        <div class="trainer-time-selects">
                                            <select name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][departure_hour]" aria-label="ساعة انصراف <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                                <option value="">الساعة</option>
                                                <?php for ($hour = 1; $hour <= 12; $hour++): ?>
                                                    <?php $hourValue = str_pad((string)$hour, 2, "0", STR_PAD_LEFT); ?>
                                                    <option value="<?php echo $hourValue; ?>" <?php echo ($daySchedule["departure_hour"] ?? "") === $hourValue ? "selected" : ""; ?>><?php echo $hourValue; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <select name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][departure_minute]" aria-label="دقيقة انصراف <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                                <option value="">الدقيقة</option>
                                                <?php for ($minute = 0; $minute <= 59; $minute++): ?>
                                                    <?php $minuteValue = str_pad((string)$minute, 2, "0", STR_PAD_LEFT); ?>
                                                    <option value="<?php echo $minuteValue; ?>" <?php echo ($daySchedule["departure_minute"] ?? "") === $minuteValue ? "selected" : ""; ?>><?php echo $minuteValue; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <select name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][departure_period]" aria-label="فترة انصراف <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                                <?php foreach (TRAINER_TIME_PERIODS as $periodValue => $periodLabel): ?>
                                                    <option value="<?php echo $periodValue; ?>" <?php echo ($daySchedule["departure_period"] ?? "AM") === $periodValue ? "selected" : ""; ?>><?php echo $periodLabel; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="trainer-form-actions">
                        <button type="submit" class="btn btn-primary"><?php echo $formData["id"] > 0 ? "تحديث المدرب" : "حفظ المدرب"; ?></button>
                    </div>
                </form>
            </div>

            <div class="card trainer-table-card" id="trainersTableCard">
                <div class="card-head table-card-head">
                    <div>
                        <h3>جدول المدربين</h3>
                    </div>
                    <span class="table-counter"><?php echo $displayedTrainerCount; ?> / <?php echo $trainerCount; ?></span>
                </div>

                <div class="trainer-table-toolbar">
                    <form method="GET" class="trainer-search-form">
                        <div class="trainer-search-field">
                                <span aria-hidden="true">🔎</span>
                                <input
                                    type="search"
                                    name="search"
                                    placeholder="ابحث باسم المدرب أو رقم الهاتف أو الباركود"
                                    value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>"
                                    aria-label="ابحث باسم المدرب أو رقم الهاتف أو الباركود"
                                >
                            </div>
                        <button type="submit" class="btn btn-soft">بحث</button>
                        <?php if ($searchTerm !== ""): ?>
                            <a href="trainers.php" class="btn btn-warning">إلغاء البحث</a>
                        <?php endif; ?>
                    </form>

                    <div class="trainer-table-meta">
                        <span class="badge">نتائج البحث: <?php echo $displayedTrainerCount; ?></span>
                        <?php if ($searchTerm !== ""): ?>
                            <span class="badge">الكلمة: <?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>رقم الصف</th>
                                <th>اسم المدرب</th>
                                <th>رقم الهاتف</th>
                                <th>الباركود</th>
                                <th>الجدول الأسبوعي</th>
                                <th>أيام الإجازة</th>
                                <?php if ($isManager): ?>
                                    <th>الراتب</th>
                                <?php endif; ?>
                                <?php if ($isManager && $isSwimming): ?>
                                    <th>سعر الساعة</th>
                                <?php endif; ?>
                                <th>أضيف بواسطة</th>
                                <th>عدّل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($displayedTrainerCount === 0): ?>
                                <tr>
                                    <td colspan="<?php echo 9 + ($isManager ? 1 : 0) + ($isManager && $isSwimming ? 1 : 0); ?>" class="empty-cell">
                                        <?php echo $searchTerm !== "" ? "لا توجد نتائج مطابقة للبحث." : "لا يوجد مدربون."; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($trainers as $index => $trainer): ?>
                                    <?php
                                    $dayLabels = [];
                                     foreach (array_filter(explode(TRAINER_DAY_SEPARATOR, (string)$trainer["days_off"])) as $dayKey) {
                                         if (isset(TRAINER_DAY_OPTIONS[$dayKey])) {
                                             $dayLabels[] = TRAINER_DAY_OPTIONS[$dayKey];
                                         }
                                     }
                                     $resolvedSchedule = $trainer["resolved_schedule"] ?? [];
                                     $trainerBarcode = trim((string)($trainer["barcode"] ?? ""));
                                     ?>
                                    <tr>
                                        <td data-label="#" class="trainer-row-number"><?php echo $index + 1; ?></td>
                                        <td data-label="اسم المدرب">
                                            <div class="trainer-name-cell">
                                                <strong><?php echo htmlspecialchars($trainer["name"], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="رقم الهاتف"><?php echo htmlspecialchars($trainer["phone"], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الباركود">
                                            <?php echo htmlspecialchars($trainerBarcode !== "" ? $trainerBarcode : TRAINER_EMPTY_FIELD_DISPLAY, ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="الجدول الأسبوعي">
                                            <div class="table-badges trainer-weekly-summary">
                                                <?php if (count($resolvedSchedule) === 0): ?>
                                                    <span class="badge trainer-empty-badge">لا يوجد</span>
                                                <?php else: ?>
                                                    <?php foreach ($resolvedSchedule as $dayKey => $daySchedule): ?>
                                                        <span class="badge">
                                                            <?php echo htmlspecialchars(TRAINER_DAY_OPTIONS[$dayKey] . ": " . formatTrainerTimeForDisplay($daySchedule["attendance_time"]) . " - " . formatTrainerTimeForDisplay($daySchedule["departure_time"]), ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="أيام الإجازة">
                                            <div class="table-badges">
                                                <?php if (count($dayLabels) === 0): ?>
                                                    <span class="badge trainer-empty-badge">لا يوجد</span>
                                                <?php else: ?>
                                                    <?php foreach ($dayLabels as $dayLabel): ?>
                                                        <span class="badge"><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php if ($isManager): ?>
                                            <td data-label="الراتب"><span class="trainer-salary-pill"><?php echo htmlspecialchars(number_format((float)$trainer["salary"], 2), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <?php endif; ?>
                                        <?php if ($isManager && $isSwimming): ?>
                                            <td data-label="سعر الساعة"><span class="trainer-salary-pill"><?php echo htmlspecialchars(number_format((float)($trainer["hourly_rate"] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <?php endif; ?>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $trainer["created_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $trainer["updated_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="trainers.php?edit=<?php echo (int)$trainer["id"]; ?>" class="btn btn-warning" aria-label="تعديل">تعديل</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["trainers_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="trainer_id" value="<?php echo (int)$trainer["id"]; ?>">
                                                    <button type="submit" class="btn btn-danger" aria-label="حذف" onclick="return confirm('هل تريد حذف هذا المدرب؟')">حذف</button>
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
<script src="assets/js/script.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const dayOffInputs = document.querySelectorAll('input[name="days_off[]"]');
    const scheduleCards = document.querySelectorAll(".trainer-weekly-schedule-card");

    if (dayOffInputs.length === 0 || scheduleCards.length === 0) {
        return;
    }

    const syncWeeklyScheduleState = function () {
        const dayOffSet = new Set(Array.from(dayOffInputs).filter(function (input) {
            return input.checked;
        }).map(function (input) {
            return input.value;
        }));

        scheduleCards.forEach(function (card) {
            const dayKey = card.getAttribute("data-day-key") || "";
            const isDayOff = dayOffSet.has(dayKey);
            card.classList.toggle("is-day-off", isDayOff);

            card.querySelectorAll("select").forEach(function (select) {
                select.disabled = isDayOff;
            });

            const statusLabel = card.querySelector("[data-schedule-status]");
            if (statusLabel) {
                statusLabel.textContent = isDayOff ? "يوم إجازة" : "يوم عمل";
            }
        });
    };

    dayOffInputs.forEach(function (input) {
        input.addEventListener("change", syncWeeklyScheduleState);
    });

    syncWeeklyScheduleState();
});
</script>
</body>
</html>
