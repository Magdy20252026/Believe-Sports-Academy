<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

date_default_timezone_set("Africa/Cairo");

const ADMIN_DAY_OPTIONS = [
    "saturday" => "السبت",
    "sunday" => "الأحد",
    "monday" => "الإثنين",
    "tuesday" => "الثلاثاء",
    "wednesday" => "الأربعاء",
    "thursday" => "الخميس",
    "friday" => "الجمعة",
];

const ADMIN_DAY_SEPARATOR = " | ";
const ADMIN_DAY_ORDER_SQL = "'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'";
const ADMIN_TIME_REGEX = '/^\d{2}:\d{2}$/';
const ADMIN_BARCODE_MAX_LENGTH = 100;
const ADMIN_EMPTY_FIELD_DISPLAY = "—";
const ADMIN_TIME_PERIODS = [
    "AM" => "ص",
    "PM" => "م",
];

requireAuthenticatedUser();
requireMenuAccess("admins");

function ensureAdminsTables(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admins (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            barcode VARCHAR(" . ADMIN_BARCODE_MAX_LENGTH . ") NOT NULL DEFAULT '',
            attendance_time TIME NOT NULL,
            departure_time TIME NOT NULL,
            salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admins_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_days_off (
            id INT(11) NOT NULL AUTO_INCREMENT,
            admin_id INT(11) NOT NULL,
            day_key VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_admin_day_off (admin_id, day_key),
            KEY idx_admin_days_off_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_weekly_schedule (
            id INT(11) NOT NULL AUTO_INCREMENT,
            admin_id INT(11) NOT NULL,
            day_key VARCHAR(20) NOT NULL,
            attendance_time TIME NOT NULL,
            departure_time TIME NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_admin_weekly_schedule_day (admin_id, day_key),
            KEY idx_admin_weekly_schedule_admin (admin_id)
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
           AND TABLE_NAME IN ('admins', 'admin_days_off', 'admin_weekly_schedule')
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = [];
    foreach ($constraintsStmt->fetchAll() as $constraint) {
        $existingConstraints[(string)$constraint["TABLE_NAME"]][] = (string)$constraint["CONSTRAINT_NAME"];
    }

    $adminConstraints = $existingConstraints["admins"] ?? [];
    if (!in_array("fk_admins_game", $adminConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE admins
             ADD CONSTRAINT fk_admins_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }

    $daysOffConstraints = $existingConstraints["admin_days_off"] ?? [];
    if (!in_array("fk_admin_days_off_admin", $daysOffConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE admin_days_off
             ADD CONSTRAINT fk_admin_days_off_admin
             FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE"
        );
    }

    $weeklyScheduleConstraints = $existingConstraints["admin_weekly_schedule"] ?? [];
    if (!in_array("fk_admin_weekly_schedule_admin", $weeklyScheduleConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE admin_weekly_schedule
             ADD CONSTRAINT fk_admin_weekly_schedule_admin
             FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE"
        );
    }

    $passwordColStmt = $pdo->query("SHOW COLUMNS FROM admins LIKE 'password'");
    if (!$passwordColStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE admins
             ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT '' AFTER salary"
        );
    }
}

function sanitizeAdminDayKeys(array $dayKeys)
{
    $sanitized = [];
    foreach ($dayKeys as $dayKey) {
        $dayKey = trim((string)$dayKey);
        if ($dayKey !== "" && isset(ADMIN_DAY_OPTIONS[$dayKey])) {
            $sanitized[] = $dayKey;
        }
    }

    return array_values(array_unique($sanitized));
}

function getAdminDayOrderSql()
{
    return ADMIN_DAY_ORDER_SQL;
}

function isValidAdminTime($time)
{
    return preg_match(ADMIN_TIME_REGEX, (string)$time) === 1;
}

function normalizeAdminTimeInputValue($time)
{
    $time = substr(trim((string)$time), 0, 5);
    return isValidAdminTime($time) ? $time : "";
}

function normalizeAdminSalaryValue($salary)
{
    return number_format((float)$salary, 2, ".", "");
}

function formatAdminTimeWithSeconds($time)
{
    return (string)$time . ":00";
}

function convertAdmin24HourTimeToParts($time)
{
    $time = substr((string)$time, 0, 5);
    if (!isValidAdminTime($time)) {
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

function convertAdmin12HourPartsTo24Hour($hour, $minute, $period)
{
    $hour = (int)$hour;
    $minute = (int)$minute;
    $period = strtoupper(trim((string)$period));

    if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 || !isset(ADMIN_TIME_PERIODS[$period])) {
        return "";
    }

    if ($period === "AM") {
        $hour = $hour === 12 ? 0 : $hour;
    } else {
        $hour = $hour === 12 ? 12 : $hour + 12;
    }

    return str_pad((string)$hour, 2, "0", STR_PAD_LEFT) . ":" . str_pad((string)$minute, 2, "0", STR_PAD_LEFT);
}

function formatAdminTimeForDisplay($time)
{
    $time = normalizeAdminTimeInputValue($time);
    if ($time === "") {
        return "";
    }

    return formatEgyptTimeForDisplay($time, "");
}

function formatAdminEgyptDateTimeLabel(DateTimeInterface $dateTime)
{
    return formatEgyptDateTimeForDisplay($dateTime, "");
}

function fetchAdminDayKeys(PDO $pdo, $adminId)
{
    $stmt = $pdo->prepare(
        "SELECT day_key
         FROM admin_days_off
         WHERE admin_id = ?
         ORDER BY FIELD(day_key, " . getAdminDayOrderSql() . ")"
    );
    $stmt->execute([(int)$adminId]);

    return sanitizeAdminDayKeys(array_column($stmt->fetchAll(), "day_key"));
}

function buildEmptyAdminWeeklyScheduleFormData()
{
    $schedule = [];
    foreach (ADMIN_DAY_OPTIONS as $dayKey => $_dayLabel) {
        $schedule[$dayKey] = [
            "attendance_time" => "",
            "departure_time" => "",
        ];
    }

    return $schedule;
}

function fetchAdminWeeklySchedulesByAdminIds(PDO $pdo, array $adminIds)
{
    $adminIds = array_values(array_filter(array_map("intval", $adminIds), function ($adminId) {
        return $adminId > 0;
    }));
    if (count($adminIds) === 0) {
        return [];
    }

    $placeholders = implode(", ", array_fill(0, count($adminIds), "?"));
    $stmt = $pdo->prepare(
        "SELECT admin_id, day_key, attendance_time, departure_time
         FROM admin_weekly_schedule
         WHERE admin_id IN (" . $placeholders . ")
         ORDER BY admin_id ASC, FIELD(day_key, " . getAdminDayOrderSql() . ")"
    );
    $stmt->execute($adminIds);

    $schedules = [];
    foreach ($stmt->fetchAll() as $row) {
        $adminId = (int)$row["admin_id"];
        $dayKey = trim((string)$row["day_key"]);
        if (!isset(ADMIN_DAY_OPTIONS[$dayKey])) {
            continue;
        }

        $schedules[$adminId][$dayKey] = [
            "attendance_time" => substr((string)$row["attendance_time"], 0, 8),
            "departure_time" => substr((string)$row["departure_time"], 0, 8),
        ];
    }

    return $schedules;
}

function buildAdminWeeklyScheduleFormData(array $storedSchedules, $defaultAttendanceTime = "", $defaultDepartureTime = "")
{
    $formSchedule = buildEmptyAdminWeeklyScheduleFormData();
    $hasDefaultTimes = isValidAdminTime(substr((string)$defaultAttendanceTime, 0, 5))
        && isValidAdminTime(substr((string)$defaultDepartureTime, 0, 5));

    foreach (ADMIN_DAY_OPTIONS as $dayKey => $_dayLabel) {
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

        $formSchedule[$dayKey] = [
            "attendance_time" => normalizeAdminTimeInputValue($daySchedule["attendance_time"] ?? ""),
            "departure_time" => normalizeAdminTimeInputValue($daySchedule["departure_time"] ?? ""),
        ];
    }

    return $formSchedule;
}

function normalizeAdminWeeklyScheduleInput($scheduleInput)
{
    $normalizedSchedule = buildEmptyAdminWeeklyScheduleFormData();
    if (!is_array($scheduleInput)) {
        return $normalizedSchedule;
    }

    foreach (ADMIN_DAY_OPTIONS as $dayKey => $_dayLabel) {
        $dayInput = $scheduleInput[$dayKey] ?? null;
        if (!is_array($dayInput)) {
            continue;
        }

        $normalizedSchedule[$dayKey] = [
            "attendance_time" => normalizeAdminTimeInputValue($dayInput["attendance_time"] ?? ""),
            "departure_time" => normalizeAdminTimeInputValue($dayInput["departure_time"] ?? ""),
        ];
    }

    return $normalizedSchedule;
}

function getAdminDefaultWorkingScheduleInput(array $scheduleInput, array $daysOff)
{
    foreach (ADMIN_DAY_OPTIONS as $dayKey => $_dayLabel) {
        if (in_array($dayKey, $daysOff, true)) {
            continue;
        }

        $daySchedule = $scheduleInput[$dayKey] ?? [];
        $attendanceTime = normalizeAdminTimeInputValue($daySchedule["attendance_time"] ?? "");
        $departureTime = normalizeAdminTimeInputValue($daySchedule["departure_time"] ?? "");

        if ($attendanceTime === "" || $departureTime === "") {
            continue;
        }

        return [
            "attendance_time" => $attendanceTime,
            "departure_time" => $departureTime,
        ];
    }

    return null;
}

function resolveAdminWeeklySchedule(array $storedSchedules, array $daysOff, $defaultAttendanceTime = "", $defaultDepartureTime = "")
{
    $resolvedSchedule = [];
    $hasDefaultTimes = isValidAdminTime(substr((string)$defaultAttendanceTime, 0, 5))
        && isValidAdminTime(substr((string)$defaultDepartureTime, 0, 5));

    foreach (ADMIN_DAY_OPTIONS as $dayKey => $_dayLabel) {
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

function getFirstAdminWorkingSchedule(array $resolvedSchedule)
{
    foreach (ADMIN_DAY_OPTIONS as $dayKey => $_dayLabel) {
        if (isset($resolvedSchedule[$dayKey])) {
            return $resolvedSchedule[$dayKey];
        }
    }

    return null;
}

function adminPhoneExists(PDO $pdo, $gameId, $phone, $adminId = 0)
{
    $sql = (int)$adminId > 0
        ? "SELECT id FROM admins WHERE game_id = ? AND phone = ? AND id <> ? LIMIT 1"
        : "SELECT id FROM admins WHERE game_id = ? AND phone = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = (int)$adminId > 0
        ? [(int)$gameId, (string)$phone, (int)$adminId]
        : [(int)$gameId, (string)$phone];
    $stmt->execute($params);

    return (bool)$stmt->fetch();
}

function adminBarcodeExists(PDO $pdo, $gameId, $barcode, $adminId = 0)
{
    if ((string)$barcode === "") {
        return false;
    }

    $sql = (int)$adminId > 0
        ? "SELECT id FROM admins WHERE game_id = ? AND barcode = ? AND id <> ? LIMIT 1"
        : "SELECT id FROM admins WHERE game_id = ? AND barcode = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = (int)$adminId > 0
        ? [(int)$gameId, (string)$barcode, (int)$adminId]
        : [(int)$gameId, (string)$barcode];
    $stmt->execute($params);

    return (bool)$stmt->fetch();
}

function isAdminBarcodeTooLong($barcode)
{
    return function_exists("mb_strlen")
        ? mb_strlen((string)$barcode) > ADMIN_BARCODE_MAX_LENGTH
        : strlen((string)$barcode) > ADMIN_BARCODE_MAX_LENGTH;
}

function logAdminException(Throwable $throwable)
{
    error_log("Admins page error: " . $throwable->getMessage());
}

function updateAdminPortalPassword(PDO $pdo, int $adminId, int $gameId, string $plainPassword)
{
    if ($plainPassword === "") {
        return;
    }

    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        throw new RuntimeException("تعذر إنشاء كلمة مرور الإداري.");
    }

    $pwStmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ? AND game_id = ?");
    $pwStmt->execute([$hashedPassword, $adminId, $gameId]);
    if ($pwStmt->rowCount() < 1) {
        $existsStmt = $pdo->prepare("SELECT id FROM admins WHERE id = ? AND game_id = ? LIMIT 1");
        $existsStmt->execute([$adminId, $gameId]);
        if (!$existsStmt->fetchColumn()) {
            throw new RuntimeException("تعذر حفظ كلمة مرور الإداري.");
        }

        error_log("Admins page warning: password update reported no changed rows for an existing admin record.");
    }
}

if (!isset($_SESSION["admins_csrf_token"])) {
    $_SESSION["admins_csrf_token"] = bin2hex(random_bytes(32));
}

ensureAdminsTables($pdo);

$success = "";
$error = "";
$isManager = (string)($_SESSION["role"] ?? "") === "مدير";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "admins";

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
    "password" => "",
    "days_off" => [],
    "weekly_schedule" => buildEmptyAdminWeeklyScheduleFormData(),
];

$egyptNow = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$egyptDateTimeLabel = formatAdminEgyptDateTimeLabel($egyptNow);

$flashSuccess = $_SESSION["admins_success"] ?? "";
unset($_SESSION["admins_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["admins_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["admin_id"] ?? 0),
                "name" => trim($_POST["name"] ?? ""),
                "phone" => trim($_POST["phone"] ?? ""),
                "barcode" => trim($_POST["barcode"] ?? ""),
                "salary" => $isManager ? trim($_POST["salary"] ?? "") : "",
                "password" => trim($_POST["admin_password"] ?? ""),
                "days_off" => sanitizeAdminDayKeys($_POST["days_off"] ?? []),
                "weekly_schedule" => normalizeAdminWeeklyScheduleInput($_POST["weekly_schedule"] ?? []),
            ];
            $resolvedWeeklySchedule = [];
            $defaultWorkingScheduleInput = getAdminDefaultWorkingScheduleInput($formData["weekly_schedule"], $formData["days_off"]);

            if ($formData["name"] === "") {
                $error = "اسم الإداري مطلوب.";
            } elseif ($formData["phone"] === "") {
                $error = "رقم الهاتف مطلوب.";
            } elseif (!preg_match('/^[0-9+\-\s()]{6,20}$/', $formData["phone"])) {
                $error = "رقم الهاتف غير صحيح.";
            } elseif (isAdminBarcodeTooLong($formData["barcode"])) {
                $error = "باركود الإداري طويل جدًا.";
            } elseif ($isManager && $formData["salary"] === "") {
                $error = "الراتب مطلوب.";
            } elseif ($isManager && (!is_numeric($formData["salary"]) || (float)$formData["salary"] < 0)) {
                $error = "الراتب غير صحيح.";
            }

            if ($error === "") {
                foreach (ADMIN_DAY_OPTIONS as $dayKey => $dayLabel) {
                    if (in_array($dayKey, $formData["days_off"], true)) {
                        continue;
                    }

                    $daySchedule = $formData["weekly_schedule"][$dayKey] ?? [];
                    $attendanceTime = normalizeAdminTimeInputValue($daySchedule["attendance_time"] ?? "");
                    $departureTime = normalizeAdminTimeInputValue($daySchedule["departure_time"] ?? "");

                    if ($attendanceTime === "" && $departureTime === "" && is_array($defaultWorkingScheduleInput)) {
                        $attendanceTime = $defaultWorkingScheduleInput["attendance_time"];
                        $departureTime = $defaultWorkingScheduleInput["departure_time"];
                    }

                    if ($attendanceTime === "" || $departureTime === "") {
                        $error = "حدد ميعاد الحضور والانصراف ليوم " . $dayLabel . ".";
                        break;
                    }

                    if ($attendanceTime >= $departureTime) {
                        $error = "يجب أن يكون ميعاد الانصراف بعد ميعاد الحضور ليوم " . $dayLabel . ".";
                        break;
                    }

                    $resolvedWeeklySchedule[$dayKey] = [
                        "attendance_time" => formatAdminTimeWithSeconds($attendanceTime),
                        "departure_time" => formatAdminTimeWithSeconds($departureTime),
                    ];
                }
            }

            if ($error === "" && count($resolvedWeeklySchedule) === 0) {
                $error = "يجب تحديد يوم عمل واحد على الأقل.";
            }

            if ($error === "" && $formData["id"] > 0) {
                $adminExistsStmt = $pdo->prepare(
                    "SELECT id, salary FROM admins WHERE id = ? AND game_id = ? LIMIT 1"
                );
                $adminExistsStmt->execute([$formData["id"], $currentGameId]);
                $existingAdmin = $adminExistsStmt->fetch();
                if (!$existingAdmin) {
                    $error = "الإداري غير متاح.";
                }
            } else {
                $existingAdmin = null;
            }

            if ($error === "") {
                if (adminPhoneExists($pdo, $currentGameId, $formData["phone"], $formData["id"])) {
                    $error = "رقم الهاتف مستخدم بالفعل.";
                } elseif ($formData["barcode"] !== "" && adminBarcodeExists($pdo, $currentGameId, $formData["barcode"], $formData["id"])) {
                    $error = "باركود الإداري مستخدم بالفعل.";
                }
            }

            if ($error === "") {
                $salaryValue = $isManager
                    ? normalizeAdminSalaryValue($formData["salary"])
                    : normalizeAdminSalaryValue($existingAdmin["salary"] ?? 0);

                try {
                    $pdo->beginTransaction();
                    $defaultSchedule = getFirstAdminWorkingSchedule($resolvedWeeklySchedule);

                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE admins
                             SET name = ?, phone = ?, barcode = ?, attendance_time = ?, departure_time = ?, salary = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["name"],
                            $formData["phone"],
                            $formData["barcode"],
                            $defaultSchedule["attendance_time"],
                            $defaultSchedule["departure_time"],
                            $salaryValue,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        $adminId = $formData["id"];
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO admins (game_id, name, phone, barcode, attendance_time, departure_time, salary)
                             VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["name"],
                            $formData["phone"],
                            $formData["barcode"],
                            $defaultSchedule["attendance_time"],
                            $defaultSchedule["departure_time"],
                            $salaryValue,
                        ]);
                        $adminId = (int)$pdo->lastInsertId();
                    }

                    updateAdminPortalPassword($pdo, $adminId, $currentGameId, $formData["password"]);

                    $deleteDaysStmt = $pdo->prepare("DELETE FROM admin_days_off WHERE admin_id = ?");
                    $deleteDaysStmt->execute([$adminId]);

                    $deleteScheduleStmt = $pdo->prepare("DELETE FROM admin_weekly_schedule WHERE admin_id = ?");
                    $deleteScheduleStmt->execute([$adminId]);

                    $insertDayStmt = $pdo->prepare(
                        "INSERT INTO admin_days_off (admin_id, day_key) VALUES (?, ?)"
                    );
                    foreach ($formData["days_off"] as $dayKey) {
                        $insertDayStmt->execute([$adminId, $dayKey]);
                    }

                    $insertScheduleStmt = $pdo->prepare(
                        "INSERT INTO admin_weekly_schedule (admin_id, day_key, attendance_time, departure_time)
                         VALUES (?, ?, ?, ?)"
                    );
                    foreach ($resolvedWeeklySchedule as $dayKey => $daySchedule) {
                        $insertScheduleStmt->execute([
                            $adminId,
                            $dayKey,
                            $daySchedule["attendance_time"],
                            $daySchedule["departure_time"],
                        ]);
                    }

                    auditTrack($pdo, $formData["id"] > 0 ? "update" : "create", "admins", $adminId, "الإداريين", ($formData["id"] > 0 ? "تعديل بيانات إداري: " : "إضافة إداري: ") . (string)$formData["name"]);
                    $pdo->commit();
                    $_SESSION["admins_success"] = $formData["id"] > 0 ? "تم تحديث الإداري." : "تم حفظ الإداري.";
                    header("Location: admins.php");
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    logAdminException($throwable);
                    $error = "تعذر حفظ بيانات الإداري.";
                }
            }
        }

        if ($action === "delete") {
            $deleteAdminId = (int)($_POST["admin_id"] ?? 0);

            if ($deleteAdminId <= 0) {
                $error = "الإداري غير صالح.";
            } else {
                $deletedNameStmt = $pdo->prepare("SELECT name FROM admins WHERE id = ? AND game_id = ? LIMIT 1");
                $deletedNameStmt->execute([$deleteAdminId, $currentGameId]);
                $deletedAdminName = (string)($deletedNameStmt->fetchColumn() ?: "");
                $deleteStmt = $pdo->prepare("DELETE FROM admins WHERE id = ? AND game_id = ?");

                try {
                    $deleteStmt->execute([$deleteAdminId, $currentGameId]);
                    if ($deleteStmt->rowCount() === 0) {
                        $error = "الإداري غير متاح.";
                    } else {
                        auditLogActivity($pdo, "delete", "admins", $deleteAdminId, "الإداريين", "حذف إداري: " . $deletedAdminName);
                        $_SESSION["admins_success"] = "تم حذف الإداري.";
                        header("Location: admins.php");
                        exit;
                    }
                } catch (Throwable $throwable) {
                    logAdminException($throwable);
                    $error = "تعذر حذف الإداري.";
                }
            }
        }
    }
}

$editAdminId = (int)($_GET["edit"] ?? 0);
if ($editAdminId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editStmt = $pdo->prepare(
        "SELECT id, name, phone, barcode, attendance_time, departure_time, salary
         FROM admins
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $editStmt->execute([$editAdminId, $currentGameId]);
    $editAdmin = $editStmt->fetch();

    if ($editAdmin) {
        $weeklySchedules = fetchAdminWeeklySchedulesByAdminIds($pdo, [$editAdminId]);
        $formData = [
            "id" => (int)$editAdmin["id"],
            "name" => (string)$editAdmin["name"],
            "phone" => (string)$editAdmin["phone"],
            "barcode" => (string)($editAdmin["barcode"] ?? ""),
            "salary" => $isManager ? number_format((float)$editAdmin["salary"], 2, ".", "") : "",
            "password" => "",
            "days_off" => fetchAdminDayKeys($pdo, $editAdminId),
            "weekly_schedule" => buildAdminWeeklyScheduleFormData(
                $weeklySchedules[$editAdminId] ?? [],
                $editAdmin["attendance_time"] ?? "",
                $editAdmin["departure_time"] ?? ""
            ),
        ];
    }
}

$rawSearchTerm = strip_tags(trim((string)($_GET["search"] ?? "")));
$searchTerm = function_exists("mb_substr")
    ? mb_substr($rawSearchTerm, 0, 100)
    : substr($rawSearchTerm, 0, 100);

$statsStmt = $pdo->prepare("SELECT COUNT(*) AS admin_count, COALESCE(SUM(salary), 0) AS total_salary FROM admins WHERE game_id = ?");
$statsStmt->execute([$currentGameId]);
$statsRow = $statsStmt->fetch() ?: ["admin_count" => 0, "total_salary" => 0];

$listSql = "SELECT
        a.id,
        a.name,
        a.phone,
        a.barcode,
        a.attendance_time,
        a.departure_time,
        a.salary,
        a.created_by_user_id,
        a.updated_by_user_id,
        GROUP_CONCAT(
            DISTINCT ad.day_key
            ORDER BY FIELD(ad.day_key, " . getAdminDayOrderSql() . ")
            SEPARATOR '" . ADMIN_DAY_SEPARATOR . "'
        ) AS days_off
     FROM admins a
     LEFT JOIN admin_days_off ad ON ad.admin_id = a.id
     WHERE a.game_id = ?";
$listParams = [$currentGameId];
if ($searchTerm !== "") {
    $listSql .= " AND (a.name LIKE ? OR a.phone LIKE ? OR a.barcode LIKE ?)";
    $searchLike = "%" . $searchTerm . "%";
    $listParams[] = $searchLike;
    $listParams[] = $searchLike;
    $listParams[] = $searchLike;
}
$listSql .= "
     GROUP BY a.id, a.name, a.phone, a.barcode, a.attendance_time, a.departure_time, a.salary, a.created_by_user_id, a.updated_by_user_id
     ORDER BY a.id DESC";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($listParams);
$adminsList = $listStmt->fetchAll();
$adminSchedulesById = fetchAdminWeeklySchedulesByAdminIds($pdo, array_column($adminsList, "id"));
foreach ($adminsList as &$admin) {
    $dayKeys = sanitizeAdminDayKeys(array_filter(explode(ADMIN_DAY_SEPARATOR, (string)($admin["days_off"] ?? ""))));
    $admin["resolved_schedule"] = resolveAdminWeeklySchedule(
        $adminSchedulesById[(int)$admin["id"]] ?? [],
        $dayKeys,
        $admin["attendance_time"] ?? "",
        $admin["departure_time"] ?? ""
    );
}
unset($admin);

$adminCount = (int)($statsRow["admin_count"] ?? 0);
$displayedAdminCount = count($adminsList);
$totalSalaryAmount = (float)($statsRow["total_salary"] ?? 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإداريين</title>
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
                    <h1>الإداريين</h1>
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
                <span class="trainer-stat-label">إجمالي الإداريين</span>
                <strong class="trainer-stat-value"><?php echo $adminCount; ?></strong>
            </div>
            <?php if ($isManager): ?>
                <div class="card trainer-stat-card">
                    <span class="trainer-stat-label">إجمالي الرواتب</span>
                    <strong class="trainer-stat-value"><?php echo htmlspecialchars(number_format($totalSalaryAmount, 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            <?php endif; ?>
        </section>

        <section class="trainers-grid">
            <div class="card trainer-form-card" id="adminFormCard">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل إداري" : "إضافة إداري"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="admins.php" class="btn btn-soft" aria-label="إلغاء">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admins_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="admin_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="trainer-form-section">
                        <div class="trainer-section-heading">
                            <h4 class="trainer-section-title">البيانات الأساسية</h4>
                        </div>
                        <div class="trainer-basic-grid">
                            <div class="form-group">
                                <label for="name">اسم الإداري</label>
                                <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($formData["name"], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone">رقم الهاتف</label>
                                <input type="text" name="phone" id="phone" inputmode="tel" value="<?php echo htmlspecialchars($formData["phone"], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="barcode">باركود الإداري</label>
                                <input type="text" name="barcode" id="barcode" value="<?php echo htmlspecialchars($formData["barcode"], ENT_QUOTES, 'UTF-8'); ?>" maxlength="<?php echo ADMIN_BARCODE_MAX_LENGTH; ?>" placeholder="أدخل باركود الإداري">
                            </div>

                            <div class="form-group">
                                <label for="admin_password">كلمة المرور <span style="font-size:0.85em;color:#888;">(اختياري<?php echo $formData["id"] > 0 ? " — اتركها فارغة لعدم التغيير" : ""; ?>)</span></label>
                                <input type="password" name="admin_password" id="admin_password" value="" autocomplete="new-password" placeholder="أدخل كلمة مرور جديدة">
                            </div>

                            <?php if ($isManager): ?>
                                <div class="form-group">
                                    <label for="salary">الراتب</label>
                                    <input type="number" name="salary" id="salary" min="0" step="0.01" value="<?php echo htmlspecialchars($formData["salary"], ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="trainer-form-section">
                        <div class="trainer-section-heading">
                            <h4 class="trainer-section-title">أيام الإجازة</h4>
                            <p class="trainer-section-subtitle">اختياري، ويمكن تركها بدون تحديد إذا كان الإداري يعمل طوال الأسبوع.</p>
                        </div>
                        <div class="form-group">
                            <div class="trainer-days-grid">
                                <?php foreach (ADMIN_DAY_OPTIONS as $dayKey => $dayLabel): ?>
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
                            <p class="trainer-section-subtitle">حدد ميعاد الحضور والانصراف لأيام العمل، ويمكن إدخال ميعاد يوم واحد فقط ليُستخدم تلقائيًا لباقي الأيام التي تُترك فارغة، وسيتم تجاهل اليوم المحدد كإجازة.</p>
                        </div>
                        <div class="trainer-weekly-schedule-grid">
                            <?php foreach (ADMIN_DAY_OPTIONS as $dayKey => $dayLabel): ?>
                                <?php
                                $daySchedule = $formData["weekly_schedule"][$dayKey] ?? buildEmptyAdminWeeklyScheduleFormData()[$dayKey];
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
                                            <input type="time" step="60" name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][attendance_time]" aria-label="ميعاد حضور <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)($daySchedule["attendance_time"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                        </div>
                                    </div>
                                    <div class="trainer-weekly-schedule-time-block">
                                        <label>الانصراف</label>
                                        <div class="trainer-time-selects">
                                            <input type="time" step="60" name="weekly_schedule[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>][departure_time]" aria-label="ميعاد انصراف <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)($daySchedule["departure_time"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isDayOff ? "disabled" : ""; ?>>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="trainer-form-actions">
                        <button type="submit" class="btn btn-primary"><?php echo $formData["id"] > 0 ? "تحديث الإداري" : "حفظ الإداري"; ?></button>
                    </div>
                </form>
            </div>

            <div class="card trainer-table-card" id="adminsTableCard">
                <div class="card-head table-card-head">
                    <div>
                        <h3>جدول الإداريين</h3>
                    </div>
                    <span class="table-counter"><?php echo $displayedAdminCount; ?> / <?php echo $adminCount; ?></span>
                </div>

                <div class="trainer-table-toolbar">
                    <form method="GET" class="trainer-search-form">
                        <div class="trainer-search-field">
                            <span aria-hidden="true">🔎</span>
                            <input
                                type="search"
                                name="search"
                                placeholder="ابحث باسم الإداري أو رقم الهاتف أو الباركود"
                                value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>"
                            >
                        </div>
                        <button type="submit" class="btn btn-soft">بحث</button>
                        <?php if ($searchTerm !== ""): ?>
                            <a href="admins.php" class="btn btn-warning">إلغاء البحث</a>
                        <?php endif; ?>
                    </form>

                    <div class="trainer-table-meta">
                        <span class="badge">نتائج البحث: <?php echo $displayedAdminCount; ?></span>
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
                                <th>اسم الإداري</th>
                                <th>رقم الهاتف</th>
                                <th>الباركود</th>
                                <th>الجدول الأسبوعي</th>
                                <th>أيام الإجازة</th>
                                <?php if ($isManager): ?>
                                    <th>الراتب</th>
                                <?php endif; ?>
                                <th>أضيف بواسطة</th>
                                <th>عدّل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($displayedAdminCount === 0): ?>
                                <tr>
                                    <td colspan="<?php echo $isManager ? 10 : 9; ?>" class="empty-cell">
                                        <?php echo $searchTerm !== "" ? "لا توجد نتائج مطابقة للبحث." : "لا يوجد إداريون."; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($adminsList as $index => $admin): ?>
                                    <?php
                                    $dayLabels = [];
                                    foreach (array_filter(explode(ADMIN_DAY_SEPARATOR, (string)$admin["days_off"])) as $dayKey) {
                                        if (isset(ADMIN_DAY_OPTIONS[$dayKey])) {
                                            $dayLabels[] = ADMIN_DAY_OPTIONS[$dayKey];
                                        }
                                    }
                                    $resolvedSchedule = $admin["resolved_schedule"] ?? [];
                                    $adminBarcode = trim((string)($admin["barcode"] ?? ""));
                                    ?>
                                    <tr>
                                        <td data-label="#" class="trainer-row-number"><?php echo $index + 1; ?></td>
                                        <td data-label="اسم الإداري">
                                            <div class="trainer-name-cell">
                                                <strong><?php echo htmlspecialchars($admin["name"], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="رقم الهاتف"><?php echo htmlspecialchars($admin["phone"], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الباركود">
                                            <?php echo htmlspecialchars($adminBarcode !== "" ? $adminBarcode : ADMIN_EMPTY_FIELD_DISPLAY, ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="الجدول الأسبوعي">
                                            <div class="table-badges trainer-weekly-summary">
                                                <?php if (count($resolvedSchedule) === 0): ?>
                                                    <span class="badge trainer-empty-badge">لا يوجد</span>
                                                <?php else: ?>
                                                    <?php foreach ($resolvedSchedule as $dayKey => $daySchedule): ?>
                                                        <span class="badge">
                                                            <?php echo htmlspecialchars(ADMIN_DAY_OPTIONS[$dayKey] . ": " . formatAdminTimeForDisplay($daySchedule["attendance_time"]) . " - " . formatAdminTimeForDisplay($daySchedule["departure_time"]), ENT_QUOTES, 'UTF-8'); ?>
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
                                            <td data-label="الراتب"><span class="trainer-salary-pill"><?php echo htmlspecialchars(number_format((float)$admin["salary"], 2), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <?php endif; ?>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $admin["created_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $admin["updated_by_user_id"] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="admins.php?edit=<?php echo (int)$admin["id"]; ?>" class="btn btn-warning" aria-label="تعديل">تعديل</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admins_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="admin_id" value="<?php echo (int)$admin["id"]; ?>">
                                                    <button type="submit" class="btn btn-danger" aria-label="حذف" onclick="return confirm('هل تريد حذف هذا الإداري؟')">حذف</button>
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

            card.querySelectorAll("input, select").forEach(function (field) {
                field.disabled = isDayOff;
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
