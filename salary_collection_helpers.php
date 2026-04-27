<?php

date_default_timezone_set("Africa/Cairo");

const PAYROLL_EMPTY_VALUE = "—";
const PAYROLL_MONTH_NAMES = [
    1 => "يناير",
    2 => "فبراير",
    3 => "مارس",
    4 => "أبريل",
    5 => "مايو",
    6 => "يونيو",
    7 => "يوليو",
    8 => "أغسطس",
    9 => "سبتمبر",
    10 => "أكتوبر",
    11 => "نوفمبر",
    12 => "ديسمبر",
];

function payrollGetEgyptDateTimeLabel(DateTimeInterface $dateTime)
{
    $hour = (int)$dateTime->format("G");
    $minute = $dateTime->format("i");
    $period = $hour >= 12 ? "م" : "ص";
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return $dateTime->format("Y/m/d") . " - " . str_pad((string)$displayHour, 2, "0", STR_PAD_LEFT) . ":" . $minute . " " . $period;
}

function payrollFormatDateTimeValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return PAYROLL_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return PAYROLL_EMPTY_VALUE;
    }

    return payrollGetEgyptDateTimeLabel($dateTime);
}

function payrollFormatTimeValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return PAYROLL_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return PAYROLL_EMPTY_VALUE;
    }

    $hour = (int)$dateTime->format("G");
    $minute = $dateTime->format("i");
    $period = $hour >= 12 ? "م" : "ص";
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return str_pad((string)$displayHour, 2, "0", STR_PAD_LEFT) . ":" . $minute . " " . $period;
}

function payrollIsAbsentAttendanceRow(array $row)
{
    $dayStatus = trim((string)($row["day_status"] ?? ""));
    $attendanceStatus = trim((string)($row["attendance_status"] ?? ""));

    return in_array($dayStatus, ["غياب", "absent"], true)
        || in_array($attendanceStatus, ["غياب", "absent"], true);
}

function payrollIsLateAttendanceRow(array $row)
{
    $attendanceStatus = trim((string)($row["attendance_status"] ?? ""));
    $dayStatus = trim((string)($row["day_status"] ?? ""));

    return in_array($attendanceStatus, ["حضور متأخر", "late"], true)
        || in_array($dayStatus, ["late"], true);
}

function payrollCalculateActualWorkHours($attendanceAt, $departureAt, $storedHours = 0.0)
{
    $storedHours = (float)$storedHours;
    if ($storedHours > 0) {
        return (float)number_format($storedHours, 2, ".", "");
    }

    $attendanceAt = trim((string)$attendanceAt);
    $departureAt = trim((string)$departureAt);
    if ($attendanceAt === "" || $departureAt === "") {
        return 0.0;
    }

    try {
        $attendanceDateTime = new DateTimeImmutable($attendanceAt, new DateTimeZone("Africa/Cairo"));
        $departureDateTime = new DateTimeImmutable($departureAt, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return 0.0;
    }

    $workedSeconds = $departureDateTime->getTimestamp() - $attendanceDateTime->getTimestamp();
    if ($workedSeconds <= 0) {
        error_log(
            "Payroll actual work hours error: departure time is earlier than or equal to attendance time. "
            . "attendance_at={$attendanceAt}, departure_at={$departureAt}"
        );
        return 0.0;
    }

    return (float)number_format($workedSeconds / 3600, 2, ".", "");
}

function payrollFormatCurrency($amount)
{
    return number_format((float)$amount, 2) . " ج.م";
}

function payrollNormalizeAmount($amount)
{
    return number_format((float)$amount, 2, ".", "");
}

function payrollIsValidMonthValue($value)
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-01$/', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat("Y-m-d", $value, new DateTimeZone("Africa/Cairo"));
    return $date instanceof DateTimeImmutable && $date->format("Y-m-d") === $value;
}

function payrollFormatMonthLabel($value)
{
    if (!payrollIsValidMonthValue($value)) {
        return $value;
    }

    $date = new DateTimeImmutable($value, new DateTimeZone("Africa/Cairo"));
    $monthNumber = (int)$date->format("n");
    return (PAYROLL_MONTH_NAMES[$monthNumber] ?? $date->format("m")) . " " . $date->format("Y");
}

function payrollGetMonthOptions(DateTimeImmutable $now, $monthsCount = 24)
{
    $options = [];
    for ($index = 0; $index < (int)$monthsCount; $index++) {
        $monthDate = $now->modify("first day of -" . $index . " month");
        $value = $monthDate->format("Y-m-01");
        $options[] = [
            "value" => $value,
            "label" => payrollFormatMonthLabel($value),
        ];
    }

    return $options;
}

function payrollGetMonthDateRange($salaryMonth)
{
    $startDate = new DateTimeImmutable((string)$salaryMonth, new DateTimeZone("Africa/Cairo"));
    return [
        "start" => $startDate->format("Y-m-d"),
        "end" => $startDate->modify("last day of this month")->format("Y-m-d"),
    ];
}

function payrollEnsureTrainerAttendanceHoursColumn(PDO $pdo)
{
    $columnStmt = $pdo->query("SHOW COLUMNS FROM trainer_attendance LIKE 'actual_work_hours'");
    if (!$columnStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE trainer_attendance
             ADD COLUMN actual_work_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER day_status"
        );
    }
}

function payrollEnsureSalaryPaymentsTable(PDO $pdo, array $config)
{
    $table = $config["salary_table"];
    $entityIdColumn = $config["entity_id_column"];
    $entityNameColumn = $config["entity_name_column"];
    $uniqueKeyName = $config["salary_unique_key"];
    $gameMonthKeyName = $config["salary_game_month_key"];
    $entityKeyName = $config["salary_entity_key"];

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `game_id` INT(11) NOT NULL,
            `{$entityIdColumn}` INT(11) NOT NULL,
            `{$entityNameColumn}` VARCHAR(150) NOT NULL DEFAULT '',
            `salary_month` DATE NOT NULL,
            `base_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `loans_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `deductions_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `net_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `actual_paid_amount` DECIMAL(10,2) DEFAULT NULL,
            `attendance_days` INT(11) NOT NULL DEFAULT 0,
            `absent_days` INT(11) NOT NULL DEFAULT 0,
            `late_days` INT(11) NOT NULL DEFAULT 0,
            `early_departure_days` INT(11) NOT NULL DEFAULT 0,
            `overtime_days` INT(11) NOT NULL DEFAULT 0,
            `paid_by_user_id` INT(11) DEFAULT NULL,
            `paid_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "base_salary" => "ALTER TABLE `{$table}` ADD COLUMN `base_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `salary_month`",
        "loans_total" => "ALTER TABLE `{$table}` ADD COLUMN `loans_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `base_salary`",
        "deductions_total" => "ALTER TABLE `{$table}` ADD COLUMN `deductions_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `loans_total`",
        "net_salary" => "ALTER TABLE `{$table}` ADD COLUMN `net_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `deductions_total`",
        "actual_paid_amount" => "ALTER TABLE `{$table}` ADD COLUMN `actual_paid_amount` DECIMAL(10,2) DEFAULT NULL AFTER `net_salary`",
        "attendance_days" => "ALTER TABLE `{$table}` ADD COLUMN `attendance_days` INT(11) NOT NULL DEFAULT 0 AFTER `actual_paid_amount`",
        "absent_days" => "ALTER TABLE `{$table}` ADD COLUMN `absent_days` INT(11) NOT NULL DEFAULT 0 AFTER `attendance_days`",
        "late_days" => "ALTER TABLE `{$table}` ADD COLUMN `late_days` INT(11) NOT NULL DEFAULT 0 AFTER `absent_days`",
        "early_departure_days" => "ALTER TABLE `{$table}` ADD COLUMN `early_departure_days` INT(11) NOT NULL DEFAULT 0 AFTER `late_days`",
        "overtime_days" => "ALTER TABLE `{$table}` ADD COLUMN `overtime_days` INT(11) NOT NULL DEFAULT 0 AFTER `early_departure_days`",
        "paid_by_user_id" => "ALTER TABLE `{$table}` ADD COLUMN `paid_by_user_id` INT(11) DEFAULT NULL AFTER `overtime_days`",
        "paid_at" => "ALTER TABLE `{$table}` ADD COLUMN `paid_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `paid_by_user_id`",
    ];

    foreach ($requiredColumns as $columnName => $sql) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($columnName));
        if (!$columnStmt->fetch()) {
            $pdo->exec($sql);
        }
    }

    $indexStmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($uniqueKeyName));
    if (!$indexStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE `{$table}`
             ADD UNIQUE KEY `{$uniqueKeyName}` (`game_id`, `{$entityIdColumn}`, `salary_month`)"
        );
    }

    $indexStmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($gameMonthKeyName));
    if (!$indexStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE `{$table}`
             ADD KEY `{$gameMonthKeyName}` (`game_id`, `salary_month`)"
        );
    }

    $indexStmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($entityKeyName));
    if (!$indexStmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE `{$table}`
             ADD KEY `{$entityKeyName}` (`{$entityIdColumn}`)"
        );
    }
}

function payrollFetchAllGamesMap(PDO $pdo)
{
    $stmt = $pdo->query("SELECT id, name FROM games WHERE status = 1 ORDER BY id ASC");
    $games = $stmt->fetchAll();
    $gameMap = [];
    foreach ($games as $game) {
        $gameMap[(int)$game["id"]] = $game;
    }

    return $gameMap;
}

function payrollFetchEmployees(PDO $pdo, array $config, $gameId)
{
    $hourlyRateSelect = !empty($config["has_hourly_rate"]) ? ", COALESCE(e.hourly_rate, 0) AS hourly_rate" : ", 0 AS hourly_rate";
    $stmt = $pdo->prepare(
        "SELECT e.id, e.name, e.salary{$hourlyRateSelect}
         FROM `{$config["entity_table"]}` e
         WHERE e.game_id = ?
         ORDER BY e.name ASC, e.id DESC"
    );
    $stmt->execute([(int)$gameId]);
    return $stmt->fetchAll();
}

function payrollFetchEmployee(PDO $pdo, array $config, $gameId, $entityId)
{
    $hourlyRateSelect = !empty($config["has_hourly_rate"]) ? ", COALESCE(e.hourly_rate, 0) AS hourly_rate" : ", 0 AS hourly_rate";
    $stmt = $pdo->prepare(
        "SELECT e.id, e.name, e.salary{$hourlyRateSelect}
         FROM `{$config["entity_table"]}` e
         WHERE e.game_id = ? AND e.id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$gameId, (int)$entityId]);
    return $stmt->fetch();
}

function payrollFetchUnpaidEmployees(PDO $pdo, array $config, $gameId, $salaryMonth)
{
    $hourlyRateSelect = !empty($config["has_hourly_rate"]) ? ", COALESCE(e.hourly_rate, 0) AS hourly_rate" : ", 0 AS hourly_rate";
    $stmt = $pdo->prepare(
        "SELECT e.id, e.name, e.salary{$hourlyRateSelect}
         FROM `{$config["entity_table"]}` e
         LEFT JOIN `{$config["salary_table"]}` sp
             ON sp.game_id = e.game_id
            AND sp.{$config["entity_id_column"]} = e.id
            AND sp.salary_month = ?
         WHERE e.game_id = ?
           AND sp.id IS NULL
         ORDER BY e.name ASC, e.id DESC"
    );
    $stmt->execute([(string)$salaryMonth, (int)$gameId]);
    return $stmt->fetchAll();
}

function payrollFetchAttendanceRows(PDO $pdo, array $config, $gameId, $entityId, $startDate, $endDate)
{
    $actualWorkHoursSelect = !empty($config["attendance_has_actual_hours"]) ? ", a.actual_work_hours" : ", 0 AS actual_work_hours";
    $stmt = $pdo->prepare(
        "SELECT
            a.id,
            a.attendance_date,
            a.scheduled_attendance_time,
            a.scheduled_departure_time,
            a.attendance_at,
            a.attendance_status,
            a.attendance_minutes_late,
            a.departure_at,
            a.departure_status,
            a.departure_minutes_early,
            a.overtime_minutes,
            a.day_status{$actualWorkHoursSelect}
         FROM `{$config["attendance_table"]}` a
         WHERE a.game_id = ?
           AND a.{$config["attendance_entity_id_column"]} = ?
           AND a.attendance_date BETWEEN ? AND ?
         ORDER BY a.attendance_date DESC, a.id DESC"
    );
    $stmt->execute([(int)$gameId, (int)$entityId, (string)$startDate, (string)$endDate]);
    $rows = $stmt->fetchAll();

    // Enable this only for attendance tables, مثل حضور المدربين في السباحة, where payroll is based on worked hours.
    if (!empty($config["attendance_has_actual_hours"])) {
        foreach ($rows as &$row) {
            $row["actual_work_hours"] = payrollCalculateActualWorkHours(
                $row["attendance_at"] ?? "",
                $row["departure_at"] ?? "",
                $row["actual_work_hours"] ?? 0
            );
        }
        unset($row);
    }

    return $rows;
}

function payrollFetchLoanRows(PDO $pdo, array $config, $gameId, $entityId, $startDate, $endDate)
{
    $stmt = $pdo->prepare(
        "SELECT id, amount, {$config["loan_name_column"]} AS entity_name, loan_date, created_at
         FROM `{$config["loan_table"]}`
         WHERE game_id = ?
           AND {$config["loan_entity_id_column"]} = ?
           AND loan_date BETWEEN ? AND ?
         ORDER BY loan_date DESC, id DESC"
    );
    $stmt->execute([(int)$gameId, (int)$entityId, (string)$startDate, (string)$endDate]);
    return $stmt->fetchAll();
}

function payrollFetchDeductionRows(PDO $pdo, array $config, $gameId, $entityId, $startDate, $endDate)
{
    $stmt = $pdo->prepare(
        "SELECT id, amount, {$config["deduction_name_column"]} AS entity_name, reason, deduction_date, created_at
         FROM `{$config["deduction_table"]}`
         WHERE game_id = ?
           AND {$config["deduction_entity_id_column"]} = ?
           AND deduction_date BETWEEN ? AND ?
         ORDER BY deduction_date DESC, id DESC"
    );
    $stmt->execute([(int)$gameId, (int)$entityId, (string)$startDate, (string)$endDate]);
    return $stmt->fetchAll();
}

function payrollSummarizeAttendanceRows(array $rows)
{
    $summary = [
        "attendance_days" => 0,
        "absent_days" => 0,
        "late_days" => 0,
        "early_departure_days" => 0,
        "overtime_days" => 0,
        "actual_work_hours" => 0.0,
    ];

    foreach ($rows as $row) {
        if (payrollIsAbsentAttendanceRow($row)) {
            $summary["absent_days"]++;
        } else {
            $summary["attendance_days"]++;
        }

        if (payrollIsLateAttendanceRow($row)) {
            $summary["late_days"]++;
        }
        if (($row["departure_status"] ?? "") === "انصراف مبكر") {
            $summary["early_departure_days"]++;
        }
        if (($row["departure_status"] ?? "") === "انصراف مع إضافي") {
            $summary["overtime_days"]++;
        }
        $summary["actual_work_hours"] += (float)($row["actual_work_hours"] ?? 0);
    }

    $summary["actual_work_hours"] = (float)number_format($summary["actual_work_hours"], 2, ".", "");
    return $summary;
}

function payrollSumAmountRows(array $rows, $columnName = "amount")
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row[$columnName] ?? 0);
    }

    return (float)number_format($total, 2, ".", "");
}

function payrollBuildEmployeeMonthDetails(PDO $pdo, array $config, $gameId, array $employee, $salaryMonth, $useHourlyRate)
{
    $dateRange = payrollGetMonthDateRange($salaryMonth);
    $attendanceRows = payrollFetchAttendanceRows($pdo, $config, $gameId, $employee["id"], $dateRange["start"], $dateRange["end"]);
    $loanRows = payrollFetchLoanRows($pdo, $config, $gameId, $employee["id"], $dateRange["start"], $dateRange["end"]);
    $deductionRows = payrollFetchDeductionRows($pdo, $config, $gameId, $employee["id"], $dateRange["start"], $dateRange["end"]);
    $attendanceSummary = payrollSummarizeAttendanceRows($attendanceRows);
    $loansTotal = payrollSumAmountRows($loanRows);
    $deductionsTotal = payrollSumAmountRows($deductionRows);
    $baseSalary = $useHourlyRate
        ? (float)number_format($attendanceSummary["actual_work_hours"] * (float)($employee["hourly_rate"] ?? 0), 2, ".", "")
        : (float)number_format((float)($employee["salary"] ?? 0), 2, ".", "");
    $netSalary = (float)number_format($baseSalary - $loansTotal - $deductionsTotal, 2, ".", "");

    return [
        "attendance_rows" => $attendanceRows,
        "loan_rows" => $loanRows,
        "deduction_rows" => $deductionRows,
        "attendance_summary" => $attendanceSummary,
        "base_salary" => $baseSalary,
        "loans_total" => $loansTotal,
        "deductions_total" => $deductionsTotal,
        "net_salary" => $netSalary,
    ];
}

function payrollFetchPaidRows(PDO $pdo, array $config, $gameId, $salaryMonth)
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            {$config["entity_id_column"]} AS entity_id,
            {$config["entity_name_column"]} AS entity_name,
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
            paid_at
         FROM `{$config["salary_table"]}`
         WHERE game_id = ?
           AND salary_month = ?
         ORDER BY paid_at DESC, id DESC"
    );
    $stmt->execute([(int)$gameId, (string)$salaryMonth]);
    return $stmt->fetchAll();
}

function payrollSumPaidRows(array $rows)
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row["actual_paid_amount"] ?? 0);
    }

    return (float)number_format($total, 2, ".", "");
}
