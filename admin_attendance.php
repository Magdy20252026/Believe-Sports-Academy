<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

date_default_timezone_set("Africa/Cairo");

const ADMIN_ATTENDANCE_DAY_OPTIONS = [
    "saturday" => "السبت",
    "sunday" => "الأحد",
    "monday" => "الإثنين",
    "tuesday" => "الثلاثاء",
    "wednesday" => "الأربعاء",
    "thursday" => "الخميس",
    "friday" => "الجمعة",
];
const ADMIN_ATTENDANCE_DAY_ORDER_SQL = "'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'";
const ADMIN_ATTENDANCE_DAY_SEPARATOR = "|";
const ADMIN_ATTENDANCE_EMPTY_VALUE = "—";
const ADMIN_ATTENDANCE_GRACE_MINUTES = 10;
const ADMIN_ATTENDANCE_ABSENCE_MINUTES = 60;

requireAuthenticatedUser();
requireMenuAccess("admins-attendance");

function getAdminAttendanceDayOrderSql()
{
    return ADMIN_ATTENDANCE_DAY_ORDER_SQL;
}

function formatAdminAttendanceTimeWithSeconds($time)
{
    return substr((string)$time, 0, 5) . ":00";
}

function convertAdminAttendance24HourTimeToParts($time)
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

function formatAdminAttendanceTimeForDisplay($time)
{
    $time = substr(trim((string)$time), 0, 5);
    if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
        return ADMIN_ATTENDANCE_EMPTY_VALUE;
    }

    return $time;
}

function formatAdminAttendanceDateTimeLabel(DateTimeInterface $dateTime)
{
    return $dateTime->format("Y/m/d - H:i");
}

function formatAdminAttendanceActualTime($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return ADMIN_ATTENDANCE_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return ADMIN_ATTENDANCE_EMPTY_VALUE;
    }

    return $dateTime->format("H:i");
}

function normalizeAdminAttendanceDayKeys($dayKeysValue)
{
    $keys = [];
    foreach (explode(ADMIN_ATTENDANCE_DAY_SEPARATOR, (string)$dayKeysValue) as $dayKey) {
        $dayKey = trim((string)$dayKey);
        if ($dayKey !== "" && isset(ADMIN_ATTENDANCE_DAY_OPTIONS[$dayKey])) {
            $keys[] = $dayKey;
        }
    }

    return array_values(array_unique($keys));
}

function getAdminAttendanceDayKeyFromDate(DateTimeInterface $date)
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

function isValidAdminAttendanceDate($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("Y-m-d", $date, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $date;
}

function createAdminAttendanceDate($date)
{
    return new DateTimeImmutable((string)$date . " 00:00:00", new DateTimeZone("Africa/Cairo"));
}

function formatAdminAttendanceStatusBadgeClass($status)
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

function ensureAdminAttendanceTables(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admins (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            barcode VARCHAR(100) NOT NULL DEFAULT '',
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_attendance (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            admin_id INT(11) NOT NULL,
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
            UNIQUE KEY unique_admin_attendance_day (admin_id, attendance_date),
            KEY idx_admin_attendance_game_date (game_id, attendance_date),
            KEY idx_admin_attendance_admin (admin_id)
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
           AND TABLE_NAME IN ('admins', 'admin_days_off', 'admin_weekly_schedule', 'admin_attendance')
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

    $attendanceConstraints = $existingConstraints["admin_attendance"] ?? [];
    if (!in_array("fk_admin_attendance_game", $attendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE admin_attendance
             ADD CONSTRAINT fk_admin_attendance_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }

    if (!in_array("fk_admin_attendance_admin", $attendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE admin_attendance
             ADD CONSTRAINT fk_admin_attendance_admin
             FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE"
        );
    }
}

function fetchAdminAttendanceAdmins(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT
            a.id,
            a.name,
            a.barcode,
            a.attendance_time,
            a.departure_time,
            a.created_at,
            GROUP_CONCAT(
                DISTINCT ad.day_key
                ORDER BY FIELD(ad.day_key, " . getAdminAttendanceDayOrderSql() . ")
                SEPARATOR '" . ADMIN_ATTENDANCE_DAY_SEPARATOR . "'
            ) AS days_off
         FROM admins a
         LEFT JOIN admin_days_off ad ON ad.admin_id = a.id
         WHERE a.game_id = ?
         GROUP BY a.id, a.name, a.barcode, a.attendance_time, a.departure_time, a.created_at
         ORDER BY a.name ASC, a.id ASC"
    );
    $stmt->execute([(int)$gameId]);
    $rows = $stmt->fetchAll();

    $scheduleMap = fetchAdminAttendanceWeeklySchedulesByAdminIds(
        $pdo,
        array_column($rows, "id")
    );

    $admins = [];
    foreach ($rows as $row) {
        $row["id"] = (int)$row["id"];
        $row["day_keys"] = normalizeAdminAttendanceDayKeys($row["days_off"] ?? "");
        $row["barcode"] = trim((string)($row["barcode"] ?? ""));
        $row["schedule_map"] = resolveAdminAttendanceWeeklySchedule(
            $scheduleMap[$row["id"]] ?? [],
            $row["day_keys"],
            $row["attendance_time"] ?? "",
            $row["departure_time"] ?? ""
        );
        $admins[] = $row;
    }

    return $admins;
}

function fetchAdminAttendanceWeeklySchedulesByAdminIds(PDO $pdo, array $adminIds)
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
         ORDER BY admin_id ASC, FIELD(day_key, " . getAdminAttendanceDayOrderSql() . ")"
    );
    $stmt->execute($adminIds);

    $schedules = [];
    foreach ($stmt->fetchAll() as $row) {
        $adminId = (int)$row["admin_id"];
        $dayKey = trim((string)$row["day_key"]);
        if (!isset(ADMIN_ATTENDANCE_DAY_OPTIONS[$dayKey])) {
            continue;
        }

        $schedules[$adminId][$dayKey] = [
            "attendance_time" => substr((string)$row["attendance_time"], 0, 8),
            "departure_time" => substr((string)$row["departure_time"], 0, 8),
        ];
    }

    return $schedules;
}

function resolveAdminAttendanceWeeklySchedule(array $storedSchedules, array $daysOff, $defaultAttendanceTime = "", $defaultDepartureTime = "")
{
    $resolvedSchedule = [];
    $hasDefaultTimes = preg_match('/^\d{2}:\d{2}$/', substr((string)$defaultAttendanceTime, 0, 5)) === 1
        && preg_match('/^\d{2}:\d{2}$/', substr((string)$defaultDepartureTime, 0, 5)) === 1;

    foreach (ADMIN_ATTENDANCE_DAY_OPTIONS as $dayKey => $_dayLabel) {
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

function getAdminAttendanceScheduleForDay(array $admin, $dayKey)
{
    $scheduleMap = $admin["schedule_map"] ?? [];
    return is_array($scheduleMap) && isset($scheduleMap[$dayKey]) ? $scheduleMap[$dayKey] : null;
}

function calculateAdminAttendanceMinutesDifference(DateTimeImmutable $now, $scheduledTime)
{
    $normalizedNow = new DateTimeImmutable($now->format("Y-m-d H:i:00"), new DateTimeZone("Africa/Cairo"));
    $scheduledDateTime = new DateTimeImmutable($now->format("Y-m-d") . " " . substr((string)$scheduledTime, 0, 8), new DateTimeZone("Africa/Cairo"));
    return (int)floor(($normalizedNow->getTimestamp() - $scheduledDateTime->getTimestamp()) / 60);
}

function calculateAdminArrivalStatus(DateTimeImmutable $now, $scheduledTime)
{
    $lateMinutes = calculateAdminAttendanceMinutesDifference($now, $scheduledTime);
    if ($lateMinutes >= ADMIN_ATTENDANCE_ABSENCE_MINUTES) {
        return [
            "status" => "غياب",
            "minutes_late" => $lateMinutes,
            "is_absent" => true,
        ];
    }

    if ($lateMinutes <= ADMIN_ATTENDANCE_GRACE_MINUTES) {
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

function calculateAdminDepartureStatus(DateTimeImmutable $now, $scheduledTime)
{
    $minutesDifference = calculateAdminAttendanceMinutesDifference($now, $scheduledTime);

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

function syncAdminAbsenceRows(PDO $pdo, $gameId, array $admins, DateTimeImmutable $today)
{
    if (count($admins) === 0) {
        return;
    }

    $yesterday = $today->modify("-1 day");
    $earliestCreatedDate = null;
    foreach ($admins as $admin) {
        $createdDate = createAdminAttendanceDate(substr((string)$admin["created_at"], 0, 10));
        if ($earliestCreatedDate === null || $createdDate < $earliestCreatedDate) {
            $earliestCreatedDate = $createdDate;
        }
    }

    if (!$earliestCreatedDate instanceof DateTimeImmutable || $earliestCreatedDate > $yesterday) {
        return;
    }

    $existingStmt = $pdo->prepare(
        "SELECT admin_id, attendance_date
         FROM admin_attendance
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
        $adminId = (int)$row["admin_id"];
        if (!isset($existingRows[$adminId])) {
            $existingRows[$adminId] = [];
        }
        $existingRows[$adminId][(string)$row["attendance_date"]] = true;
    }

    $insertRows = [];
    foreach ($admins as $admin) {
        $adminId = (int)$admin["id"];
        $createdDate = createAdminAttendanceDate(substr((string)$admin["created_at"], 0, 10));
        if ($createdDate > $yesterday) {
            continue;
        }

        $dayKeys = $admin["day_keys"] ?? [];
        $cursor = $createdDate;
        while ($cursor <= $yesterday) {
            $attendanceDate = $cursor->format("Y-m-d");
            $dayKey = getAdminAttendanceDayKeyFromDate($cursor);
            $daySchedule = getAdminAttendanceScheduleForDay($admin, $dayKey);
            if (!in_array($dayKey, $dayKeys, true) && is_array($daySchedule) && empty($existingRows[$adminId][$attendanceDate])) {
                $insertRows[] = [
                    (int)$gameId,
                    $adminId,
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
        "INSERT IGNORE INTO admin_attendance (
            game_id,
            admin_id,
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

function fetchAdminAttendanceRecords(PDO $pdo, $gameId, $attendanceDate, $adminId, $searchTerm)
{
    $sql = "SELECT
                aa.id,
                aa.attendance_date,
                aa.scheduled_attendance_time,
                aa.scheduled_departure_time,
                aa.attendance_at,
                aa.attendance_status,
                aa.attendance_minutes_late,
                aa.departure_at,
                aa.departure_status,
                aa.departure_minutes_early,
                aa.overtime_minutes,
                aa.day_status,
                a.name AS admin_name,
                a.barcode AS admin_barcode
            FROM admin_attendance aa
            INNER JOIN admins a ON a.id = aa.admin_id
            WHERE aa.game_id = ?
              AND aa.attendance_date = ?";
    $params = [(int)$gameId, (string)$attendanceDate];

    if ((int)$adminId > 0) {
        $sql .= " AND aa.admin_id = ?";
        $params[] = (int)$adminId;
    }

    if ((string)$searchTerm !== "") {
        $sql .= " AND (a.name LIKE ? OR a.barcode LIKE ?)";
        $searchLike = "%" . $searchTerm . "%";
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    $sql .= " ORDER BY aa.attendance_date DESC, aa.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function summarizeAdminAttendanceRows(array $rows)
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

function escapeAdminAttendanceXml($value)
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, "UTF-8");
}

function buildAdminAttendanceWorksheetCell($cellReference, $value, $styleId)
{
    return '<c r="' . $cellReference . '" s="' . $styleId . '" t="inlineStr"><is><t xml:space="preserve">' . escapeAdminAttendanceXml($value) . '</t></is></c>';
}

function getAdminAttendanceExcelColumnName($columnNumber)
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

function buildAdminAttendanceWorksheetXml(array $headers, array $rows)
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
        $xml .= buildAdminAttendanceWorksheetCell(getAdminAttendanceExcelColumnName($index + 1) . $headerRowNumber, $header, 1);
    }
    $xml .= '</row>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $cellIndex => $cellValue) {
            $xml .= buildAdminAttendanceWorksheetCell(getAdminAttendanceExcelColumnName($cellIndex + 1) . $excelRow, $cellValue, 2);
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

function buildAdminAttendanceStylesXml()
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

function exportAdminAttendanceXlsx(array $records, $gameName, $attendanceDate)
{
    if (!class_exists("ZipArchive")) {
        throw new RuntimeException("امتداد ZipArchive غير متاح.");
    }

    $headers = [
        "التاريخ",
        "اسم الإداري",
        "اللعبة",
        "باركود الإداري",
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
            (string)$record["admin_name"],
            (string)$gameName,
            trim((string)($record["admin_barcode"] ?? "")) !== "" ? (string)$record["admin_barcode"] : ADMIN_ATTENDANCE_EMPTY_VALUE,
            formatAdminAttendanceTimeForDisplay($record["scheduled_attendance_time"]),
            formatAdminAttendanceActualTime($record["attendance_at"] ?? ""),
            (string)($record["attendance_status"] ?: ADMIN_ATTENDANCE_EMPTY_VALUE),
            (int)($record["attendance_minutes_late"] ?? 0) > 0 ? (string)((int)$record["attendance_minutes_late"]) : "0",
            formatAdminAttendanceTimeForDisplay($record["scheduled_departure_time"]),
            formatAdminAttendanceActualTime($record["departure_at"] ?? ""),
            (string)($record["departure_status"] ?: ADMIN_ATTENDANCE_EMPTY_VALUE),
            (int)($record["departure_minutes_early"] ?? 0) > 0 ? (string)((int)$record["departure_minutes_early"]) : "0",
            (int)($record["overtime_minutes"] ?? 0) > 0 ? (string)((int)$record["overtime_minutes"]) : "0",
        ];
    }

    $sheetXml = buildAdminAttendanceWorksheetXml($headers, $rows);
    $stylesXml = buildAdminAttendanceStylesXml();
    $timestamp = gmdate("Y-m-d\\TH:i:s\\Z");
    $tempFile = sys_get_temp_dir() . "/admin-attendance-" . uniqid("", true) . ".xlsx";

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
    <dc:title>حضور الإداريين</dc:title>
    <dc:creator>Believe Sports Academy</dc:creator>
    <cp:lastModifiedBy>Believe Sports Academy</cp:lastModifiedBy>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>
</cp:coreProperties>');
    $zip->addFromString("xl/workbook.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="حضور الإداريين" sheetId="1" r:id="rId1"/>
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

    $fileName = "admin-attendance-" . $attendanceDate . ".xlsx";

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

function handleAdminAttendanceScan(PDO $pdo, array $admin, DateTimeImmutable $now, $gameId)
{
    $todayDate = $now->format("Y-m-d");
    $dayKey = getAdminAttendanceDayKeyFromDate($now);
    $adminDayKeys = $admin["day_keys"] ?? [];
    $daySchedule = getAdminAttendanceScheduleForDay($admin, $dayKey);
    $isDayOff = in_array($dayKey, $adminDayKeys, true);
    $dayOffNotice = $isDayOff ? " تنبيه: اليوم إجازة هذا الإداري وتم تسجيل العملية بناءً على طلب المستخدم." : "";

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
                "message" => "لا يوجد ميعاد عمل مسجل لهذا الإداري اليوم.",
            ];
        }
    }

    $pdo->beginTransaction();
    try {
        $recordStmt = $pdo->prepare(
            "SELECT *
             FROM admin_attendance
             WHERE admin_id = ? AND attendance_date = ?
             LIMIT 1
             FOR UPDATE"
        );
        $recordStmt->execute([(int)$admin["id"], $todayDate]);
        $existingRecord = $recordStmt->fetch();

        if (!$existingRecord) {
            $arrival = $isDayOff
                ? [
                    "status" => "حضور في يوم الإجازة",
                    "minutes_late" => 0,
                    "is_absent" => false,
                ]
                : calculateAdminArrivalStatus($now, $daySchedule["attendance_time"]);
            $insertStmt = $pdo->prepare(
                "INSERT INTO admin_attendance (
                    game_id,
                    admin_id,
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
                (int)$admin["id"],
                $todayDate,
                $daySchedule["attendance_time"],
                $daySchedule["departure_time"],
                !empty($arrival["is_absent"]) ? null : $now->format("Y-m-d H:i:s"),
                $arrival["status"],
                (int)$arrival["minutes_late"],
                !empty($arrival["is_absent"]) ? "غياب" : "قيد الدوام",
            ]);
            auditTrack($pdo, "create", "admin_attendance", (int)$pdo->lastInsertId(), "حضور الإداريين", "تسجيل حضور الإداري: " . (string)$admin["name"] . " (" . $arrival["status"] . ")");
            $pdo->commit();

            if (!empty($arrival["is_absent"])) {
                return [
                    "success" => true,
                    "message" => "تم احتساب " . $admin["name"] . " غياباً بسبب تأخير " . (int)$arrival["minutes_late"] . " دقيقة" . $dayOffNotice,
                ];
            }

            $message = $arrival["status"];
            if ((int)$arrival["minutes_late"] > 0) {
                $message .= " - التأخير " . (int)$arrival["minutes_late"] . " دقيقة";
            }

            return [
                "success" => true,
                "message" => "تم تسجيل حضور " . $admin["name"] . " - " . $message . $dayOffNotice,
            ];
        }

        if (!empty($existingRecord["departure_at"])) {
            $pdo->rollBack();
            return [
                "success" => false,
                "message" => "تم تسجيل حضور وانصراف هذا الإداري اليوم بالفعل.",
            ];
        }

        if (($existingRecord["attendance_status"] ?? "") === "غياب" || ($existingRecord["day_status"] ?? "") === "غياب") {
            $pdo->rollBack();
            return [
                "success" => false,
                "message" => "تم احتساب هذا الإداري غياباً اليوم بسبب التأخير.",
            ];
        }

        $departure = $isDayOff
            ? [
                "status" => "انصراف في يوم الإجازة",
                "minutes_early" => 0,
                "overtime_minutes" => 0,
            ]
            : calculateAdminDepartureStatus($now, $daySchedule["departure_time"]);
        $updateStmt = $pdo->prepare(
            "UPDATE admin_attendance
             SET departure_at = ?,
                 departure_status = ?,
                 departure_minutes_early = ?,
                 overtime_minutes = ?,
                 day_status = ?
             WHERE id = ?"
        );
        $updateStmt->execute([
            $now->format("Y-m-d H:i:s"),
            $departure["status"],
            (int)$departure["minutes_early"],
            (int)$departure["overtime_minutes"],
            "مكتمل",
            (int)$existingRecord["id"],
        ]);
        auditTrack($pdo, "update", "admin_attendance", (int)$existingRecord["id"], "حضور الإداريين", "تسجيل انصراف الإداري: " . (string)$admin["name"] . " (" . $departure["status"] . ")");
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
            "message" => "تم تسجيل انصراف " . $admin["name"] . " - " . $message . $dayOffNotice,
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Admin attendance page error: " . $throwable->getMessage());
        return [
            "success" => false,
            "message" => "تعذر تسجيل العملية حاليًا.",
        ];
    }
}

ensureAdminAttendanceTables($pdo);

if (!isset($_SESSION["admin_attendance_csrf_token"])) {
    $_SESSION["admin_attendance_csrf_token"] = bin2hex(random_bytes(32));
}

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "admins-attendance";

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
$egyptDateTimeLabel = formatAdminAttendanceDateTimeLabel($now);
$defaultAttendanceDate = $now->format("Y-m-d");

$admins = fetchAdminAttendanceAdmins($pdo, $currentGameId);
$adminBarcodeMap = [];
foreach ($admins as $admin) {
    if ($admin["barcode"] !== "") {
        $adminBarcodeMap[$admin["barcode"]] = $admin;
    }
}

try {
    syncAdminAbsenceRows($pdo, $currentGameId, $admins, $now);
} catch (Throwable $throwable) {
    error_log("Admin attendance sync error: " . $throwable->getMessage());
    $error = "تعذر تحديث حالات الغياب.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["admin_attendance_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $barcode = trim((string)($_POST["barcode"] ?? ""));
        if ($barcode === "") {
            $error = "الباركود مطلوب.";
        } elseif (!isset($adminBarcodeMap[$barcode])) {
            $error = "لا يوجد إداري بهذا الباركود.";
        } else {
            $result = handleAdminAttendanceScan($pdo, $adminBarcodeMap[$barcode], $now, $currentGameId);
            if ($result["success"]) {
                $_SESSION["admin_attendance_csrf_token"] = bin2hex(random_bytes(32));
                $_SESSION["admin_attendance_success"] = $result["message"];
                header("Location: admin_attendance.php");
                exit;
            }
            $error = $result["message"];
        }
    }
}

$flashSuccess = $_SESSION["admin_attendance_success"] ?? "";
unset($_SESSION["admin_attendance_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

$attendanceDate = trim((string)($_GET["attendance_date"] ?? $defaultAttendanceDate));
if (!isValidAdminAttendanceDate($attendanceDate)) {
    $attendanceDate = $defaultAttendanceDate;
}

$selectedAdminId = (int)($_GET["admin_id"] ?? 0);
$searchTerm = strip_tags(trim((string)($_GET["search"] ?? "")));
if (function_exists("mb_substr")) {
    $searchTerm = mb_substr($searchTerm, 0, 100);
} else {
    $searchTerm = substr($searchTerm, 0, 100);
}

$records = fetchAdminAttendanceRecords($pdo, $currentGameId, $attendanceDate, $selectedAdminId, $searchTerm);
$summary = summarizeAdminAttendanceRows($records);

if (($_GET["export"] ?? "") === "xlsx") {
    try {
        exportAdminAttendanceXlsx($records, $currentGameName, $attendanceDate);
    } catch (Throwable $throwable) {
        error_log("Admin attendance export error: " . $throwable->getMessage());
        $error = "تعذر إنشاء ملف Excel.";
    }
}

$exportQuery = http_build_query([
    "attendance_date" => $attendanceDate,
    "admin_id" => $selectedAdminId,
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
    <title>حضور الإداريين</title>
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
                    <h1>حضور الإداريين</h1>
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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admin_attendance_csrf_token"], ENT_QUOTES, 'UTF-8'); ?>">
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
                                <label for="adminFilter">الإداري</label>
                                <select name="admin_id" id="adminFilter">
                                    <option value="0">الكل</option>
                                    <?php foreach ($admins as $admin): ?>
                                        <option value="<?php echo (int)$admin["id"]; ?>" <?php echo $selectedAdminId === (int)$admin["id"] ? "selected" : ""; ?>><?php echo htmlspecialchars($admin["name"], ENT_QUOTES, 'UTF-8'); ?></option>
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
                            <a href="admin_attendance.php?<?php echo htmlspecialchars($exportQuery, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">تصدير Excel</a>
                            <a href="admin_attendance.php" class="btn btn-soft">إعادة ضبط</a>
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
                                <th>اسم الإداري</th>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) === 0): ?>
                                <tr>
                                    <td colspan="13" class="empty-cell">لا توجد سجلات.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td data-label="التاريخ"><?php echo htmlspecialchars((string)$record["attendance_date"], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="اسم الإداري"><?php echo htmlspecialchars((string)$record["admin_name"], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الباركود"><?php echo htmlspecialchars(trim((string)($record["admin_barcode"] ?? "")) !== "" ? (string)$record["admin_barcode"] : ADMIN_ATTENDANCE_EMPTY_VALUE, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="ميعاد الحضور"><?php echo htmlspecialchars(formatAdminAttendanceTimeForDisplay($record["scheduled_attendance_time"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الحضور الفعلي"><?php echo htmlspecialchars(formatAdminAttendanceActualTime($record["attendance_at"] ?? ""), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="حالة الحضور">
                                            <span class="status-chip <?php echo htmlspecialchars(formatAdminAttendanceStatusBadgeClass($record["attendance_status"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($record["attendance_status"] ?: ADMIN_ATTENDANCE_EMPTY_VALUE), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td data-label="دقائق التأخير"><?php echo (int)($record["attendance_minutes_late"] ?? 0); ?></td>
                                        <td data-label="ميعاد الانصراف"><?php echo htmlspecialchars(formatAdminAttendanceTimeForDisplay($record["scheduled_departure_time"]), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الانصراف الفعلي"><?php echo htmlspecialchars(formatAdminAttendanceActualTime($record["departure_at"] ?? ""), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="حالة الانصراف">
                                            <span class="status-chip <?php echo htmlspecialchars(formatAdminAttendanceStatusBadgeClass($record["departure_status"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($record["departure_status"] ?: ADMIN_ATTENDANCE_EMPTY_VALUE), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td data-label="الانصراف المبكر"><?php echo (int)($record["departure_minutes_early"] ?? 0); ?></td>
                                        <td data-label="الإضافي"><?php echo (int)($record["overtime_minutes"] ?? 0); ?></td>
                                        <td data-label="الحالة اليومية">
                                            <span class="status-chip <?php echo htmlspecialchars(formatAdminAttendanceStatusBadgeClass($record["day_status"] ?? ""), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($record["day_status"] ?: ADMIN_ATTENDANCE_EMPTY_VALUE), ENT_QUOTES, 'UTF-8'); ?></span>
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
