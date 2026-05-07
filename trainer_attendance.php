<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "salary_collection_helpers.php";

date_default_timezone_set("Africa/Cairo");

const TRAINER_ATTENDANCE_DAY_OPTIONS = [
    "saturday" => "السبت",
    "sunday" => "الأحد",
    "monday" => "الإثنين",
    "tuesday" => "الثلاثاء",
    "wednesday" => "الأربعاء",
    "thursday" => "الخميس",
    "friday" => "الجمعة",
];
const TRAINER_ATTENDANCE_DAY_ORDER_SQL = "'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'";
const TRAINER_ATTENDANCE_DAY_SEPARATOR = "|";
const TRAINER_ATTENDANCE_EMPTY_VALUE = "—";
const TRAINER_ATTENDANCE_GRACE_MINUTES = 10;
const TRAINER_ATTENDANCE_ABSENCE_MINUTES = 60;

requireAuthenticatedUser();
requireMenuAccess("trainers-attendance");

function getTrainerAttendanceDayOrderSql()
{
    return TRAINER_ATTENDANCE_DAY_ORDER_SQL;
}

function getTrainerAttendanceBarcodeColumnDefinition()
{
    return "VARCHAR(100)";
}

function formatTrainerAttendanceTimeWithSeconds($time)
{
    return substr((string)$time, 0, 5) . ":00";
}

function convertTrainerAttendance24HourTimeToParts($time)
{
    $time = substr((string)$time, 0, 5);
    if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
        return [
            "hour" => "",
            "minute" => "",
            "period" => "AM",
        ];
    }

    list($hour, $minute) = array_map("intval", explode(":", $time));
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

function formatTrainerAttendanceTimeForDisplay($time)
{
    $parts = convertTrainerAttendance24HourTimeToParts($time);
    if ($parts["hour"] === "" || $parts["minute"] === "") {
        return TRAINER_ATTENDANCE_EMPTY_VALUE;
    }

    return $parts["hour"] . ":" . $parts["minute"];
}

function formatTrainerAttendanceDateTimeLabel(DateTimeInterface $dateTime)
{
    return $dateTime->format("Y/m/d") . " - " . $dateTime->format("H:i");
}

function formatTrainerAttendanceActualTime($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return TRAINER_ATTENDANCE_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return TRAINER_ATTENDANCE_EMPTY_VALUE;
    }

    return $dateTime->format("H:i");
}

function normalizeTrainerAttendanceDayKeys($dayKeysValue)
{
    $keys = [];
    foreach (explode(TRAINER_ATTENDANCE_DAY_SEPARATOR, (string)$dayKeysValue) as $dayKey) {
        $dayKey = trim((string)$dayKey);
        if ($dayKey !== "" && isset(TRAINER_ATTENDANCE_DAY_OPTIONS[$dayKey])) {
            $keys[] = $dayKey;
        }
    }

    return array_values(array_unique($keys));
}

function getTrainerAttendanceDayKeyFromDate(DateTimeInterface $date)
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

function isValidTrainerAttendanceDate($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("Y-m-d", $date, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $date;
}

function createTrainerAttendanceDate($date)
{
    return new DateTimeImmutable((string)$date . " 00:00:00", new DateTimeZone("Africa/Cairo"));
}

function formatTrainerAttendanceStatusBadgeClass($status)
{
    $status = trim((string)$status);
    if ($status === "حضور في الميعاد" || $status === "انصراف في الميعاد" || $status === "مكتمل") {
        return "status-success";
    }
    if ($status === "حضور متأخر" || $status === "انصراف مبكر") {
        return "status-warning";
    }
    if ($status === "انصراف مع إضافي") {
        return "status-info";
    }
    if ($status === "غياب") {
        return "status-danger";
    }

    return "status-neutral";
}

function ensureTrainerAttendanceTables(PDO $pdo)
{
    $trainerBarcodeColumnDefinition = getTrainerAttendanceBarcodeColumnDefinition();

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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS trainer_attendance (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            trainer_id INT(11) NOT NULL,
            attendance_date DATE NOT NULL,
            scheduled_attendance_time TIME NOT NULL,
            scheduled_departure_time TIME NOT NULL,
            attendance_at DATETIME NULL DEFAULT NULL,
            attendance_status VARCHAR(50) NOT NULL DEFAULT '',
            attendance_minutes_late INT(11) NOT NULL DEFAULT 0,
            departure_at DATETIME NULL DEFAULT NULL,
            departure_status VARCHAR(50) NOT NULL DEFAULT '',
            departure_minutes_early INT(11) NOT NULL DEFAULT 0,
            overtime_minutes INT(11) NOT NULL DEFAULT 0,
            day_status VARCHAR(50) NOT NULL DEFAULT '',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_trainer_attendance_day (trainer_id, attendance_date),
            KEY idx_trainer_attendance_game_date (game_id, attendance_date),
            KEY idx_trainer_attendance_trainer (trainer_id)
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
           AND TABLE_NAME IN ('trainers', 'trainer_days_off', 'trainer_weekly_schedule', 'trainer_attendance')
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = [];
    foreach ($constraintsStmt->fetchAll() as $constraint) {
        $tableName = (string)$constraint["TABLE_NAME"];
        if (!isset($existingConstraints[$tableName])) {
            $existingConstraints[$tableName] = [];
        }
        $existingConstraints[$tableName][] = (string)$constraint["CONSTRAINT_NAME"];
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

    $attendanceConstraints = $existingConstraints["trainer_attendance"] ?? [];
    if (!in_array("fk_trainer_attendance_game", $attendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE trainer_attendance
             ADD CONSTRAINT fk_trainer_attendance_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }

    if (!in_array("fk_trainer_attendance_trainer", $attendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE trainer_attendance
             ADD CONSTRAINT fk_trainer_attendance_trainer
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

    $hourlyRateColStmt = $pdo->query("SHOW COLUMNS FROM trainers LIKE 'hourly_rate'");
    if (!$hourlyRateColStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE trainers
             ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER salary"
        );
    }

    $actualWorkHoursColStmt = $pdo->query("SHOW COLUMNS FROM trainer_attendance LIKE 'actual_work_hours'");
    if (!$actualWorkHoursColStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE trainer_attendance
             ADD COLUMN actual_work_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER day_status"
        );
    }
}

function fetchTrainerAttendanceTrainers(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT
            t.id,
            t.name,
            t.barcode,
            t.attendance_time,
            t.departure_time,
            t.created_at,
            GROUP_CONCAT(
                DISTINCT td.day_key
                ORDER BY FIELD(td.day_key, " . getTrainerAttendanceDayOrderSql() . ")
                SEPARATOR '" . TRAINER_ATTENDANCE_DAY_SEPARATOR . "'
            ) AS days_off
         FROM trainers t
         LEFT JOIN trainer_days_off td ON td.trainer_id = t.id
         WHERE t.game_id = ?
         GROUP BY t.id, t.name, t.barcode, t.attendance_time, t.departure_time, t.created_at
         ORDER BY t.name ASC, t.id ASC"
    );
    $stmt->execute([(int)$gameId]);
    $rows = $stmt->fetchAll();

    $scheduleMap = fetchTrainerAttendanceWeeklySchedulesByTrainerIds(
        $pdo,
        array_column($rows, "id")
    );

    $trainers = [];
    foreach ($rows as $row) {
        $row["id"] = (int)$row["id"];
        $row["day_keys"] = normalizeTrainerAttendanceDayKeys($row["days_off"] ?? "");
        $row["barcode"] = trim((string)($row["barcode"] ?? ""));
        $row["schedule_map"] = resolveTrainerAttendanceWeeklySchedule(
            $scheduleMap[$row["id"]] ?? [],
            $row["day_keys"],
            $row["attendance_time"] ?? "",
            $row["departure_time"] ?? ""
        );
        $trainers[] = $row;
    }

    return $trainers;
}

function fetchTrainerAttendanceWeeklySchedulesByTrainerIds(PDO $pdo, array $trainerIds)
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
         ORDER BY trainer_id ASC, FIELD(day_key, " . getTrainerAttendanceDayOrderSql() . ")"
    );
    $stmt->execute($trainerIds);

    $schedules = [];
    foreach ($stmt->fetchAll() as $row) {
        $trainerId = (int)$row["trainer_id"];
        $dayKey = trim((string)$row["day_key"]);
        if (!isset(TRAINER_ATTENDANCE_DAY_OPTIONS[$dayKey])) {
            continue;
        }

        $schedules[$trainerId][$dayKey] = [
            "attendance_time" => substr((string)$row["attendance_time"], 0, 8),
            "departure_time" => substr((string)$row["departure_time"], 0, 8),
        ];
    }

    return $schedules;
}

function resolveTrainerAttendanceWeeklySchedule(array $storedSchedules, array $daysOff, $defaultAttendanceTime = "", $defaultDepartureTime = "")
{
    $resolvedSchedule = [];
    $hasDefaultTimes = preg_match('/^\d{2}:\d{2}$/', substr((string)$defaultAttendanceTime, 0, 5)) === 1
        && preg_match('/^\d{2}:\d{2}$/', substr((string)$defaultDepartureTime, 0, 5)) === 1;

    foreach (TRAINER_ATTENDANCE_DAY_OPTIONS as $dayKey => $_dayLabel) {
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

function getTrainerAttendanceScheduleForDay(array $trainer, $dayKey)
{
    $scheduleMap = $trainer["schedule_map"] ?? [];
    return is_array($scheduleMap) && isset($scheduleMap[$dayKey]) ? $scheduleMap[$dayKey] : null;
}

function calculateTrainerAttendanceMinutesDifference(DateTimeImmutable $now, $scheduledTime)
{
    $normalizedNow = new DateTimeImmutable($now->format("Y-m-d H:i:00"), new DateTimeZone("Africa/Cairo"));
    $scheduledDateTime = new DateTimeImmutable($now->format("Y-m-d") . " " . substr((string)$scheduledTime, 0, 8), new DateTimeZone("Africa/Cairo"));
    return (int)floor(($normalizedNow->getTimestamp() - $scheduledDateTime->getTimestamp()) / 60);
}

function calculateTrainerArrivalStatus(DateTimeImmutable $now, $scheduledTime)
{
    $lateMinutes = calculateTrainerAttendanceMinutesDifference($now, $scheduledTime);
    if ($lateMinutes >= TRAINER_ATTENDANCE_ABSENCE_MINUTES) {
        return [
            "status" => "غياب",
            "minutes_late" => $lateMinutes,
            "is_absent" => true,
        ];
    }

    if ($lateMinutes <= TRAINER_ATTENDANCE_GRACE_MINUTES) {
        return [
            "status" => "حضور في الميعاد",
            "minutes_late" => 0,
            "is_absent" => false,
        ];
    }

    return [
        "status" => "حضور متأخر",
        "minutes_late" => $lateMinutes,
        "is_absent" => false,
    ];
}

function calculateTrainerDepartureStatus(DateTimeImmutable $now, $scheduledTime)
{
    $minutesDifference = calculateTrainerAttendanceMinutesDifference($now, $scheduledTime);

    if ($minutesDifference < 0) {
        return [
            "status" => "انصراف مبكر",
            "minutes_early" => abs($minutesDifference),
            "overtime_minutes" => 0,
        ];
    }

    if ($minutesDifference === 0) {
        return [
            "status" => "انصراف في الميعاد",
            "minutes_early" => 0,
            "overtime_minutes" => 0,
        ];
    }

    return [
        "status" => "انصراف مع إضافي",
        "minutes_early" => 0,
        "overtime_minutes" => $minutesDifference,
    ];
}

function syncTrainerAbsenceRows(PDO $pdo, $gameId, array $trainers, DateTimeImmutable $today)
{
    if (count($trainers) === 0) {
        return;
    }

    $yesterday = $today->modify("-1 day");
    $earliestCreatedDate = null;
    foreach ($trainers as $trainer) {
        $createdDate = createTrainerAttendanceDate(substr((string)$trainer["created_at"], 0, 10));
        if ($earliestCreatedDate === null || $createdDate < $earliestCreatedDate) {
            $earliestCreatedDate = $createdDate;
        }
    }

    if (!$earliestCreatedDate instanceof DateTimeImmutable || $earliestCreatedDate > $yesterday) {
        return;
    }

    $existingStmt = $pdo->prepare(
        "SELECT trainer_id, attendance_date
         FROM trainer_attendance
         WHERE game_id = ?
           AND attendance_date BETWEEN ? AND ?"
    );
    $existingStmt->execute([
        (int)$gameId,
        $earliestCreatedDate->format("Y-m-d"),
        $yesterday->format("Y-m-d"),
    ]);
    $existingRows = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $trainerId = (int)$row["trainer_id"];
        if (!isset($existingRows[$trainerId])) {
            $existingRows[$trainerId] = [];
        }
        $existingRows[$trainerId][(string)$row["attendance_date"]] = true;
    }

    $insertRows = [];
    foreach ($trainers as $trainer) {
        $trainerId = (int)$trainer["id"];
        $createdDate = createTrainerAttendanceDate(substr((string)$trainer["created_at"], 0, 10));
        if ($createdDate > $yesterday) {
            continue;
        }

        $dayKeys = $trainer["day_keys"] ?? [];
        $cursor = $createdDate;
        while ($cursor <= $yesterday) {
            $attendanceDate = $cursor->format("Y-m-d");
            $dayKey = getTrainerAttendanceDayKeyFromDate($cursor);
            $daySchedule = getTrainerAttendanceScheduleForDay($trainer, $dayKey);
            if (!in_array($dayKey, $dayKeys, true) && is_array($daySchedule) && empty($existingRows[$trainerId][$attendanceDate])) {
                $insertRows[] = [
                    (int)$gameId,
                    $trainerId,
                    $attendanceDate,
                    $daySchedule["attendance_time"],
                    $daySchedule["departure_time"],
                    "غياب",
                    "غياب",
                    "غياب",
                ];
            }
            $cursor = $cursor->modify("+1 day");
        }
    }

    if (count($insertRows) === 0) {
        return;
    }

    $insertStmt = $pdo->prepare(
        "INSERT IGNORE INTO trainer_attendance (
            game_id,
            trainer_id,
            attendance_date,
            scheduled_attendance_time,
            scheduled_departure_time,
            attendance_status,
            departure_status,
            day_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $pdo->beginTransaction();
    try {
        foreach ($insertRows as $row) {
            $insertStmt->execute($row);
        }
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}

function fetchTrainerAttendanceRecords(PDO $pdo, $gameId, $attendanceDate, $trainerId, $searchTerm)
{
    $sql = "SELECT
                ta.id,
                ta.attendance_date,
                ta.scheduled_attendance_time,
                ta.scheduled_departure_time,
                ta.attendance_at,
                ta.attendance_status,
                ta.attendance_minutes_late,
                ta.departure_at,
                ta.departure_status,
                ta.departure_minutes_early,
                ta.overtime_minutes,
                ta.day_status,
                ta.actual_work_hours,
                t.name AS trainer_name,
                t.barcode AS trainer_barcode,
                t.hourly_rate AS trainer_hourly_rate
            FROM trainer_attendance ta
            INNER JOIN trainers t ON t.id = ta.trainer_id
            WHERE ta.game_id = ?
              AND ta.attendance_date = ?";
    $params = [(int)$gameId, (string)$attendanceDate];

    if ((int)$trainerId > 0) {
        $sql .= " AND ta.trainer_id = ?";
        $params[] = (int)$trainerId;
    }

    if ((string)$searchTerm !== "") {
        $sql .= " AND (t.name LIKE ? OR t.barcode LIKE ?)";
        $searchLike = "%" . $searchTerm . "%";
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    $sql .= " ORDER BY ta.attendance_date DESC, ta.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function summarizeTrainerAttendanceRows(array $rows)
{
    $summary = [
        "total" => count($rows),
        "on_time" => 0,
        "late" => 0,
        "early_departure" => 0,
        "overtime" => 0,
        "absent" => 0,
    ];

    foreach ($rows as $row) {
        if (($row["attendance_status"] ?? "") === "حضور في الميعاد") {
            $summary["on_time"]++;
        }
        if (($row["attendance_status"] ?? "") === "حضور متأخر") {
            $summary["late"]++;
        }
        if (($row["departure_status"] ?? "") === "انصراف مبكر") {
            $summary["early_departure"]++;
        }
        if (($row["departure_status"] ?? "") === "انصراف مع إضافي") {
            $summary["overtime"]++;
        }
        if (($row["day_status"] ?? "") === "غياب") {
            $summary["absent"]++;
        }
    }

    return $summary;
}

function escapeTrainerAttendanceXml($value)
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, "UTF-8");
}

function buildTrainerAttendanceWorksheetCell($cellReference, $value, $styleId)
{
    return '<c r="' . $cellReference . '" s="' . $styleId . '" t="inlineStr"><is><t xml:space="preserve">' . escapeTrainerAttendanceXml($value) . '</t></is></c>';
}

function getTrainerAttendanceExcelColumnName($columnNumber)
{
    $columnNumber = (int)$columnNumber;
    if ($columnNumber <= 0) {
        return "A";
    }

    $columnName = "";
    while ($columnNumber > 0) {
        $columnNumber--;
        $columnName = chr(65 + ($columnNumber % 26)) . $columnName;
        $columnNumber = (int)floor($columnNumber / 26);
    }

    return $columnName;
}

function buildTrainerAttendanceWorksheetXml(array $headers, array $rows)
{
    $columnWidths = [14, 22, 18, 18, 18, 18, 14, 18, 18, 14, 18, 16, 16];
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>';
    $xml .= '<cols>';
    foreach ($columnWidths as $index => $width) {
        $columnIndex = $index + 1;
        $xml .= '<col min="' . $columnIndex . '" max="' . $columnIndex . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols>';
    $xml .= '<sheetData>';

    $headerRowNumber = 1;
    $xml .= '<row r="1" ht="26" customHeight="1">';
    foreach ($headers as $index => $header) {
        $xml .= buildTrainerAttendanceWorksheetCell(getTrainerAttendanceExcelColumnName($index + 1) . $headerRowNumber, $header, 1);
    }
    $xml .= '</row>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $cellIndex => $cellValue) {
            $xml .= buildTrainerAttendanceWorksheetCell(getTrainerAttendanceExcelColumnName($cellIndex + 1) . $excelRow, $cellValue, 2);
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    if (count($rows) > 0) {
        $xml .= '<autoFilter ref="A1:M' . (count($rows) + 1) . '"/>';
    }
    $xml .= '</worksheet>';
    return $xml;
}

function buildTrainerAttendanceStylesXml()
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2">
        <font><sz val="11"/><name val="Arial"/></font>
        <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    </fonts>
    <fills count="3">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF2448BD"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFD1D5DB"/></left>
            <right style="thin"><color rgb="FFD1D5DB"/></right>
            <top style="thin"><color rgb="FFD1D5DB"/></top>
            <bottom style="thin"><color rgb="FFD1D5DB"/></bottom>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellStyleXfs>
    <cellXfs count="3">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    </cellXfs>
    <cellStyles count="1">
        <cellStyle name="Normal" xfId="0" builtinId="0"/>
    </cellStyles>
</styleSheet>';
}

function exportTrainerAttendanceXlsx(array $records, $gameName, $attendanceDate)
{
    if (!class_exists("ZipArchive")) {
        throw new RuntimeException("امتداد ZipArchive غير متاح.");
    }

    $headers = [
        "التاريخ",
        "اسم المدرب",
        "اللعبة",
        "باركود المدرب",
        "ميعاد الحضور",
        "الحضور الفعلي",
        "حالة الحضور",
        "دقائق التأخير",
        "ميعاد الانصراف",
        "الانصراف الفعلي",
        "حالة الانصراف",
        "دقائق الانصراف المبكر",
        "دقائق الإضافي",
    ];

    $rows = [];
    foreach ($records as $record) {
        $rows[] = [
            (string)$record["attendance_date"],
            (string)$record["trainer_name"],
            (string)$gameName,
            trim((string)($record["trainer_barcode"] ?? "")) !== "" ? (string)$record["trainer_barcode"] : TRAINER_ATTENDANCE_EMPTY_VALUE,
            formatTrainerAttendanceTimeForDisplay($record["scheduled_attendance_time"]),
            formatTrainerAttendanceActualTime($record["attendance_at"] ?? ""),
            (string)($record["attendance_status"] ?: TRAINER_ATTENDANCE_EMPTY_VALUE),
            (int)($record["attendance_minutes_late"] ?? 0) > 0 ? (string)((int)$record["attendance_minutes_late"]) : "0",
            formatTrainerAttendanceTimeForDisplay($record["scheduled_departure_time"]),
            formatTrainerAttendanceActualTime($record["departure_at"] ?? ""),
            (string)($record["departure_status"] ?: TRAINER_ATTENDANCE_EMPTY_VALUE),
            (int)($record["departure_minutes_early"] ?? 0) > 0 ? (string)((int)$record["departure_minutes_early"]) : "0",
            (int)($record["overtime_minutes"] ?? 0) > 0 ? (string)((int)$record["overtime_minutes"]) : "0",
        ];
    }

    $sheetXml = buildTrainerAttendanceWorksheetXml($headers, $rows);
    $stylesXml = buildTrainerAttendanceStylesXml();
    $timestamp = gmdate("Y-m-d\\TH:i:s\\Z");
    $tempFile = sys_get_temp_dir() . "/trainer-attendance-" . uniqid("", true) . ".xlsx";

    $zip = new ZipArchive();
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("تعذر إنشاء ملف Excel.");
    }

    $zip->addFromString("[Content_Types].xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>');
    $zip->addFromString("_rels/.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>');
    $zip->addFromString("docProps/app.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>Believe Sports Academy</Application>
</Properties>');
    $zip->addFromString("docProps/core.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>حضور المدربين</dc:title>
    <dc:creator>Believe Sports Academy</dc:creator>
    <cp:lastModifiedBy>Believe Sports Academy</cp:lastModifiedBy>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>
</cp:coreProperties>');
    $zip->addFromString("xl/workbook.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="حضور المدربين" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');
    $zip->addFromString("xl/_rels/workbook.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString("xl/styles.xml", $stylesXml);
    $zip->addFromString("xl/worksheets/sheet1.xml", $sheetXml);
    $zip->close();

    $fileName = "trainer-attendance-" . $attendanceDate . ".xlsx";

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header("Content-Length: " . filesize($tempFile));
    header("Cache-Control: max-age=0");
    readfile($tempFile);
    if (file_exists($tempFile) && !unlink($tempFile)) {
        error_log("Failed to delete temporary XLSX file.");
    }
    exit;
}

function handleTrainerAttendanceScan(PDO $pdo, array $trainer, DateTimeImmutable $now, $gameId)
{
    $todayDate = $now->format("Y-m-d");
    $dayKey = getTrainerAttendanceDayKeyFromDate($now);
    $trainerDayKeys = $trainer["day_keys"] ?? [];
    $daySchedule = getTrainerAttendanceScheduleForDay($trainer, $dayKey);
    $isDayOff = in_array($dayKey, $trainerDayKeys, true);
    $dayOffNotice = $isDayOff ? " تنبيه: اليوم إجازة هذا المدرب وتم تسجيل العملية بناءً على طلب المستخدم." : "";

    if (!is_array($daySchedule)) {
        if ($isDayOff) {
            $fallbackTime = $now->format("H:i:s");
            $daySchedule = [
                "attendance_time" => $fallbackTime,
                "departure_time" => $fallbackTime,
            ];
        } else {
            return [
                "success" => false,
                "message" => "لا يوجد ميعاد عمل مسجل لهذا المدرب اليوم.",
            ];
        }
    }

    $pdo->beginTransaction();
    try {
        $recordStmt = $pdo->prepare(
            "SELECT *
             FROM trainer_attendance
             WHERE trainer_id = ? AND attendance_date = ?
             LIMIT 1
             FOR UPDATE"
        );
        $recordStmt->execute([(int)$trainer["id"], $todayDate]);
        $existingRecord = $recordStmt->fetch();

        if (!$existingRecord) {
            $arrival = $isDayOff
                ? [
                    "status" => "حضور في يوم الإجازة",
                    "minutes_late" => 0,
                    "is_absent" => false,
                ]
                : calculateTrainerArrivalStatus($now, $daySchedule["attendance_time"]);
            $insertStmt = $pdo->prepare(
                "INSERT INTO trainer_attendance (
                    game_id,
                    trainer_id,
                    attendance_date,
                    scheduled_attendance_time,
                    scheduled_departure_time,
                    attendance_at,
                    attendance_status,
                    attendance_minutes_late,
                    day_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->execute([
                (int)$gameId,
                (int)$trainer["id"],
                $todayDate,
                $daySchedule["attendance_time"],
                $daySchedule["departure_time"],
                !empty($arrival["is_absent"]) ? null : $now->format("Y-m-d H:i:s"),
                $arrival["status"],
                (int)$arrival["minutes_late"],
                !empty($arrival["is_absent"]) ? "غياب" : "قيد الدوام",
            ]);
            auditTrack($pdo, "create", "trainer_attendance", (int)$pdo->lastInsertId(), "حضور المدربين", "تسجيل حضور المدرب: " . (string)$trainer["name"] . " (" . $arrival["status"] . ")");
            $pdo->commit();

            if (!empty($arrival["is_absent"])) {
                return [
                    "success" => true,
                    "message" => "تم احتساب " . $trainer["name"] . " غياباً بسبب تأخير " . (int)$arrival["minutes_late"] . " دقيقة" . $dayOffNotice,
                ];
            }

            $message = $arrival["status"];
            if ((int)$arrival["minutes_late"] > 0) {
                $message .= " - التأخير " . (int)$arrival["minutes_late"] . " دقيقة";
            }

            return [
                "success" => true,
                "message" => "تم تسجيل حضور " . $trainer["name"] . " - " . $message . $dayOffNotice,
            ];
        }

        if (!empty($existingRecord["departure_at"])) {
            $pdo->rollBack();
            return [
                "success" => false,
                "message" => "تم تسجيل حضور وانصراف هذا المدرب اليوم بالفعل.",
            ];
        }

        if (($existingRecord["attendance_status"] ?? "") === "غياب" || ($existingRecord["day_status"] ?? "") === "غياب") {
            $pdo->rollBack();
            return [
                "success" => false,
                "message" => "تم احتساب هذا المدرب غياباً اليوم بسبب التأخير.",
            ];
        }

        $departure = $isDayOff
            ? [
                "status" => "انصراف في يوم الإجازة",
                "minutes_early" => 0,
                "overtime_minutes" => 0,
            ]
            : calculateTrainerDepartureStatus($now, $daySchedule["departure_time"]);
        $departureAt = $now->format("Y-m-d H:i:s");
        $actualWorkHours = payrollCalculateActualWorkHours($existingRecord["attendance_at"] ?? "", $departureAt);
        $updateStmt = $pdo->prepare(
            "UPDATE trainer_attendance
             SET departure_at = ?,
                 departure_status = ?,
                 departure_minutes_early = ?,
                 overtime_minutes = ?,
                 actual_work_hours = ?,
                 day_status = ?
              WHERE id = ?"
        );
        $updateStmt->execute([
            $departureAt,
            $departure["status"],
            (int)$departure["minutes_early"],
            (int)$departure["overtime_minutes"],
            $actualWorkHours,
            "مكتمل",
            (int)$existingRecord["id"],
        ]);
        auditTrack($pdo, "update", "trainer_attendance", (int)$existingRecord["id"], "حضور المدربين", "تسجيل انصراف المدرب: " . (string)$trainer["name"] . " (" . $departure["status"] . ")");
        $pdo->commit();

        $message = $departure["status"];
        if ((int)$departure["minutes_early"] > 0) {
            $message .= " - الانصراف المبكر " . (int)$departure["minutes_early"] . " دقيقة";
        }
        if ((int)$departure["overtime_minutes"] > 0) {
            $message .= " - الإضافي " . (int)$departure["overtime_minutes"] . " دقيقة";
        }

        return [
            "success" => true,
            "message" => "تم تسجيل انصراف " . $trainer["name"] . " - " . $message . $dayOffNotice,
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Trainer attendance page error: " . $throwable->getMessage());
        return [
            "success" => false,
            "message" => "تعذر تسجيل العملية حاليًا.",
        ];
    }
}

ensureTrainerAttendanceTables($pdo);

if (!isset($_SESSION["trainer_attendance_csrf_token"])) {
    $_SESSION["trainer_attendance_csrf_token"] = bin2hex(random_bytes(32));
}

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$isSwimming = mb_stripos($currentGameName, "سباح") !== false;
$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "trainers-attendance";

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

$now = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$egyptDateTimeLabel = formatTrainerAttendanceDateTimeLabel($now);
$defaultAttendanceDate = $now->format("Y-m-d");

$trainers = fetchTrainerAttendanceTrainers($pdo, $currentGameId);
$trainerBarcodeMap = [];
foreach ($trainers as $trainer) {
    if ($trainer["barcode"] !== "") {
        $trainerBarcodeMap[$trainer["barcode"]] = $trainer;
    }
}

try {
    syncTrainerAbsenceRows($pdo, $currentGameId, $trainers, $now);
} catch (Throwable $throwable) {
    error_log("Trainer attendance sync error: " . $throwable->getMessage());
    $error = "تعذر تحديث حالات الغياب.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["trainer_attendance_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save_hours") {
            $recordId = (int)($_POST["record_id"] ?? 0);
            $actualWorkHours = trim((string)($_POST["actual_work_hours"] ?? ""));
            if ($recordId <= 0) {
                $error = "السجل غير صالح.";
            } elseif ($actualWorkHours === "" || !is_numeric($actualWorkHours) || (float)$actualWorkHours < 0) {
                $error = "عدد ساعات العمل الفعلية غير صحيح.";
            } else {
                try {
                    $updateHoursStmt = $pdo->prepare(
                        "UPDATE trainer_attendance SET actual_work_hours = ? WHERE id = ? AND game_id = ?"
                    );
                    $updateHoursStmt->execute([
                        number_format((float)$actualWorkHours, 2, ".", ""),
                        $recordId,
                        $currentGameId,
                    ]);
                    $_SESSION["trainer_attendance_csrf_token"] = bin2hex(random_bytes(32));
                    $_SESSION["trainer_attendance_success"] = "تم حفظ ساعات العمل الفعلية.";
                    $redirectParams = http_build_query([
                        "attendance_date" => trim((string)($_GET["attendance_date"] ?? "")),
                        "trainer_id" => (int)($_GET["trainer_id"] ?? 0),
                        "search" => trim((string)($_GET["search"] ?? "")),
                    ]);
                    header("Location: trainer_attendance.php?" . $redirectParams);
                    exit;
                } catch (Throwable $throwable) {
                    error_log("Trainer attendance save hours error: " . $throwable->getMessage());
                    $error = "تعذر حفظ ساعات العمل.";
                }
            }
        } else {
            $barcode = trim((string)($_POST["barcode"] ?? ""));
            if ($barcode === "") {
                $error = "الباركود مطلوب.";
            } elseif (!isset($trainerBarcodeMap[$barcode])) {
                $error = "لا يوجد مدرب بهذا الباركود.";
            } else {
                $result = handleTrainerAttendanceScan($pdo, $trainerBarcodeMap[$barcode], $now, $currentGameId);
                if ($result["success"]) {
                    $_SESSION["trainer_attendance_csrf_token"] = bin2hex(random_bytes(32));
                    $_SESSION["trainer_attendance_success"] = $result["message"];
                    header("Location: trainer_attendance.php");
                    exit;
                }
                $error = $result["message"];
            }
        }
    }
}

$flashSuccess = $_SESSION["trainer_attendance_success"] ?? "";
unset($_SESSION["trainer_attendance_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

$attendanceDate = trim((string)($_GET["attendance_date"] ?? $defaultAttendanceDate));
if (!isValidTrainerAttendanceDate($attendanceDate)) {
    $attendanceDate = $defaultAttendanceDate;
}

$selectedTrainerId = (int)($_GET["trainer_id"] ?? 0);
$searchTerm = strip_tags(trim((string)($_GET["search"] ?? "")));
if (function_exists("mb_substr")) {
    $searchTerm = mb_substr($searchTerm, 0, 100);
} else {
    $searchTerm = substr($searchTerm, 0, 100);
}

$records = fetchTrainerAttendanceRecords($pdo, $currentGameId, $attendanceDate, $selectedTrainerId, $searchTerm);
$summary = summarizeTrainerAttendanceRows($records);

if (($_GET["export"] ?? "") === "xlsx") {
    try {
        exportTrainerAttendanceXlsx($records, $currentGameName, $attendanceDate);
    } catch (Throwable $throwable) {
        error_log("Trainer attendance export error: " . $throwable->getMessage());
        $error = "تعذر إنشاء ملف Excel.";
    }
}

$exportQuery = http_build_query([
    "attendance_date" => $attendanceDate,
    "trainer_id" => $selectedTrainerId,
    "search" => $searchTerm,
    "export" => "xlsx",
]);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حضور المدربين</title>
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
                    <h1>حضور المدربين</h1>
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
                <span class="trainer-stat-label">سجلات اليوم</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["total"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">حضور في الميعاد</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["on_time"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">حضور متأخر</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["late"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">انصراف مبكر</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["early_departure"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">إضافي</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["overtime"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">غياب</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["absent"]; ?></strong>
            </div>
        </section>

        <section class="attendance-layout-grid">
            <div class="attendance-stack">
                <div class="card attendance-scan-card">
                    <div class="card-head">
                        <div>
                            <h3>تسجيل الحضور والانصراف</h3>
                        </div>
                    </div>

                    <form method="POST" class="attendance-scan-form" id="attendanceScanForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["trainer_attendance_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="scan-input-row">
                            <div class="form-group scan-field-group">
                                <label for="barcodeInput">الباركود</label>
                                <input type="text" name="barcode" id="barcodeInput" autocomplete="off" autocapitalize="off" spellcheck="false" autofocus required>
                            </div>
                            <button type="submit" class="btn btn-primary">تسجيل</button>
                            <button type="button" class="btn btn-soft camera-scan-mobile-only" id="openCameraScanner" hidden>قراءة الباركود</button>
                        </div>
                    </form>
                </div>

                <div class="card attendance-filter-card">
                    <div class="card-head">
                        <div>
                            <h3>الفلاتر</h3>
                        </div>
                    </div>

                    <form method="GET" class="attendance-filter-form">
                        <div class="attendance-filter-grid">
                            <div class="form-group">
                                <label for="attendanceDate">التاريخ</label>
                                <input type="date" name="attendance_date" id="attendanceDate" value="<?php echo htmlspecialchars($attendanceDate, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="trainerFilter">المدرب</label>
                                <select name="trainer_id" id="trainerFilter">
                                    <option value="0">الكل</option>
                                    <?php foreach ($trainers as $trainer): ?>
                                        <option value="<?php echo (int)$trainer["id"]; ?>" <?php echo $selectedTrainerId === (int)$trainer["id"] ? "selected" : ""; ?>><?php echo htmlspecialchars($trainer["name"], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="searchAttendance">بحث</label>
                                <input type="search" name="search" id="searchAttendance" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض</button>
                            <a href="trainer_attendance.php?<?php echo htmlspecialchars($exportQuery, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">تصدير Excel</a>
                            <a href="trainer_attendance.php" class="btn btn-soft">إعادة ضبط</a>
                        </div>
                    </form>
                </div>
            </div>

                <div class="card attendance-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>سجل حضور يوم <?php echo htmlspecialchars($attendanceDate, ENT_QUOTES, 'UTF-8'); ?></h3>
                    </div>
                    <span class="table-counter"><?php echo count($records); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table attendance-table">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>اسم المدرب</th>
                                <th>الباركود</th>
                                <th>ميعاد الحضور</th>
                                <th>الحضور الفعلي</th>
                                <th>حالة الحضور</th>
                                <th>دقائق التأخير</th>
                                <th>ميعاد الانصراف</th>
                                <th>الانصراف الفعلي</th>
                                <th>حالة الانصراف</th>
                                <th>الانصراف المبكر</th>
                                <th>الإضافي</th>
                                <th>الحالة اليومية</th>
                                <?php if ($isSwimming): ?>
                                    <th>سعر الساعة</th>
                                    <th>ساعات العمل الفعلية</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) === 0): ?>
                                <tr>
                                    <td colspan="<?php echo 13 + ($isSwimming ? 2 : 0); ?>" class="empty-cell">لا توجد سجلات.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td data-label="التاريخ"><?php echo htmlspecialchars((string)$record["attendance_date"], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="اسم المدرب"><?php echo htmlspecialchars((string)$record["trainer_name"], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الباركود"><?php echo htmlspecialchars(trim((string)($record["trainer_barcode"] ?? "")) !== "" ? (string)$record["trainer_barcode"] : TRAINER_ATTENDANCE_EMPTY_VALUE, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="ميعاد الحضور"><?php echo htmlspecialchars(formatTrainerAttendanceTimeForDisplay($record["scheduled_attendance_time"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الحضور الفعلي"><?php echo htmlspecialchars(formatTrainerAttendanceActualTime($record["attendance_at"] ?? ""), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="حالة الحضور">
                                            <span class="status-chip <?php echo htmlspecialchars(formatTrainerAttendanceStatusBadgeClass($record["attendance_status"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($record["attendance_status"] ?: TRAINER_ATTENDANCE_EMPTY_VALUE), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td data-label="دقائق التأخير"><?php echo (int)($record["attendance_minutes_late"] ?? 0); ?></td>
                                        <td data-label="ميعاد الانصراف"><?php echo htmlspecialchars(formatTrainerAttendanceTimeForDisplay($record["scheduled_departure_time"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الانصراف الفعلي"><?php echo htmlspecialchars(formatTrainerAttendanceActualTime($record["departure_at"] ?? ""), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="حالة الانصراف">
                                            <span class="status-chip <?php echo htmlspecialchars(formatTrainerAttendanceStatusBadgeClass($record["departure_status"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($record["departure_status"] ?: TRAINER_ATTENDANCE_EMPTY_VALUE), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td data-label="الانصراف المبكر"><?php echo (int)($record["departure_minutes_early"] ?? 0); ?></td>
                                        <td data-label="الإضافي"><?php echo (int)($record["overtime_minutes"] ?? 0); ?></td>
                                        <td data-label="الحالة اليومية">
                                            <span class="status-chip <?php echo htmlspecialchars(formatTrainerAttendanceStatusBadgeClass($record["day_status"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($record["day_status"] ?: TRAINER_ATTENDANCE_EMPTY_VALUE), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <?php if ($isSwimming): ?>
                                            <td data-label="سعر الساعة"><span class="trainer-salary-pill"><?php echo htmlspecialchars(number_format((float)($record["trainer_hourly_rate"] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td data-label="ساعات العمل الفعلية">
                                                <form method="POST" class="inline-form" style="display:flex;gap:4px;align-items:center;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["trainer_attendance_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="save_hours">
                                                    <input type="hidden" name="record_id" value="<?php echo (int)$record["id"]; ?>">
                                                    <input type="number" name="actual_work_hours" min="0" step="0.5" style="width:70px;" value="<?php echo htmlspecialchars(number_format((float)($record["actual_work_hours"] ?? 0), 2, ".", ""), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="btn btn-primary" style="padding:4px 10px;font-size:0.8rem;">حفظ</button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
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
<div class="camera-modal" id="cameraModal" hidden>
    <div class="camera-modal-backdrop" id="cameraModalBackdrop"></div>
    <div class="camera-modal-content">
        <div class="camera-modal-header">
            <h3>قراءة الباركود</h3>
            <button type="button" class="btn btn-danger" id="closeCameraScanner">إغلاق</button>
        </div>
        <div class="camera-video-wrap">
            <video id="cameraVideo" playsinline></video>
        </div>
    </div>
</div>

<script src="assets/js/script.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    // Keep the camera button limited to real mobile phones and avoid showing it on desktops/tablets.
    const MAX_PHONE_SCREEN_WIDTH = 768;
    const PHONE_USER_AGENT_PATTERN = /Android|webOS|iPhone|iPod|BlackBerry|Windows Phone|IEMobile|Opera Mini/i;
    const TABLET_USER_AGENT_PATTERN = /iPad|Tablet|PlayBook|Silk/i;
    const RESIZE_VISIBILITY_DEBOUNCE_MS = 150;
    const barcodeInput = document.getElementById("barcodeInput");
    const scanForm = document.getElementById("attendanceScanForm");
    const openCameraScannerButton = document.getElementById("openCameraScanner");
    const closeCameraScannerButton = document.getElementById("closeCameraScanner");
    const cameraModal = document.getElementById("cameraModal");
    const cameraModalBackdrop = document.getElementById("cameraModalBackdrop");
    const cameraVideo = document.getElementById("cameraVideo");
    let cameraStream = null;
    let detectorInterval = null;
    let resizeVisibilityTimeout = null;
    let hasBarcodeDetectionError = false;
    let isSubmittingScanForm = false;

    if (barcodeInput) {
        barcodeInput.focus();
    }

    const supportsCameraScanner = function () {
        return !!(
            navigator.mediaDevices &&
            typeof navigator.mediaDevices.getUserMedia === "function" &&
            typeof BarcodeDetector !== "undefined"
        );
    };

    const isMobileScannerDevice = function () {
        const viewportWidth = window.innerWidth || 0;
        const userAgent = String(navigator.userAgent || navigator.vendor || "");
        const hasPhoneSizedViewport = viewportWidth > 0 && viewportWidth <= MAX_PHONE_SCREEN_WIDTH;
        const hasPhoneUserAgent = PHONE_USER_AGENT_PATTERN.test(userAgent);
        const hasTabletUserAgent = TABLET_USER_AGENT_PATTERN.test(userAgent);
        if (!hasPhoneSizedViewport || !hasPhoneUserAgent || hasTabletUserAgent) {
            return false;
        }

        try {
            if (window.matchMedia && window.matchMedia("(max-width: 768px) and (pointer: coarse) and (hover: none)").matches) {
                return true;
            }
        } catch (error) {
            console.warn("Unable to evaluate coarse pointer media query.", error);
        }

        return (navigator.maxTouchPoints || 0) > 0;
    };

    const updateCameraScannerButtonVisibility = function () {
        if (!openCameraScannerButton) {
            return;
        }

        openCameraScannerButton.hidden = !isMobileScannerDevice() || !supportsCameraScanner();
    };

    const stopCameraScanner = function () {
        if (detectorInterval) {
            window.clearInterval(detectorInterval);
            detectorInterval = null;
        }
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (track) {
                track.stop();
            });
            cameraStream = null;
        }
        if (cameraVideo) {
            cameraVideo.pause();
            cameraVideo.srcObject = null;
        }
        if (cameraModal) {
            cameraModal.hidden = true;
        }
    };

    const submitScanForm = function () {
        if (!barcodeInput || !scanForm) {
            return;
        }
        const normalizedValue = barcodeInput.value.trim();
        if (normalizedValue === "" || isSubmittingScanForm) {
            return;
        }

        isSubmittingScanForm = true;
        barcodeInput.value = normalizedValue;
        stopCameraScanner();
        if (typeof scanForm.requestSubmit === "function") {
            // requestSubmit preserves submit handlers and native validation unlike submit().
            scanForm.requestSubmit();
            return;
        }
        scanForm.submit();
    };

    const fillBarcodeAndSubmit = function (value) {
        if (!barcodeInput) {
            return;
        }
        barcodeInput.value = value === null || typeof value === "undefined" ? "" : String(value).trim();
        if (barcodeInput.value === "") {
            return;
        }
        stopCameraScanner();
        submitScanForm();
    };

    const startCameraScanner = async function () {
        if (!cameraModal || !cameraVideo) {
            return;
        }

        stopCameraScanner();
        hasBarcodeDetectionError = false;

        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== "function") {
            window.alert("الكاميرا غير متاحة على هذا الجهاز.");
            return;
        }

        if (typeof BarcodeDetector === "undefined") {
            window.alert("المتصفح لا يدعم قراءة الباركود من الكاميرا.");
            return;
        }

        try {
            const detector = new BarcodeDetector({ formats: ["code_128", "ean_13", "ean_8", "qr_code", "upc_a", "upc_e"] });
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: "environment" }
                },
                audio: false
            });
            cameraVideo.srcObject = cameraStream;
            await cameraVideo.play();
            cameraModal.hidden = false;

            detectorInterval = window.setInterval(async function () {
                try {
                    const barcodes = await detector.detect(cameraVideo);
                    if (barcodes && barcodes.length > 0 && barcodes[0].rawValue) {
                        fillBarcodeAndSubmit(barcodes[0].rawValue);
                    }
                } catch (error) {
                    if (!hasBarcodeDetectionError) {
                        console.warn("Barcode detection is temporarily unavailable:", error);
                        hasBarcodeDetectionError = true;
                    }
                    return;
                }
            }, 300);
        } catch (error) {
            stopCameraScanner();
            window.alert("تعذر تشغيل الكاميرا.");
        }
    };

    if (openCameraScannerButton) {
        updateCameraScannerButtonVisibility();
        openCameraScannerButton.addEventListener("click", function () {
            startCameraScanner();
        });
    }

    if (barcodeInput) {
        barcodeInput.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                submitScanForm();
            }
        });
    }

    if (scanForm) {
        scanForm.addEventListener("submit", function () {
            isSubmittingScanForm = true;
        });
    }

    if (closeCameraScannerButton) {
        closeCameraScannerButton.addEventListener("click", stopCameraScanner);
    }

    if (cameraModalBackdrop) {
        cameraModalBackdrop.addEventListener("click", stopCameraScanner);
    }

    window.addEventListener("resize", function () {
        window.clearTimeout(resizeVisibilityTimeout);
        resizeVisibilityTimeout = window.setTimeout(updateCameraScannerButtonVisibility, RESIZE_VISIBILITY_DEBOUNCE_MS);
    });
    window.addEventListener("beforeunload", stopCameraScanner);
});
</script>
</body>
</html>
