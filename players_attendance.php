<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "players_support.php";

date_default_timezone_set("Africa/Cairo");

requireAuthenticatedUser();
requireMenuAccess("players-attendance");
ensurePlayersTables($pdo);

const PLAYER_ATTENDANCE_CANDIDATE_BATCH_SIZE = 200;
const PLAYER_ATTENDANCE_SINGLE_TRAINING_LABEL = "تمرينة واحدة";
const PLAYER_ATTENDANCE_LATE_LIMIT_MINUTES = 180;
const PLAYER_ATTENDANCE_DEFAULT_MINUTES_LATE = 0;

function formatPlayerAttendanceStatusBadgeClass($status)
{
    $status = trim((string)$status);
    if ($status === PLAYER_ATTENDANCE_STATUS_PRESENT) {
        return "status-success";
    }
    if ($status === PLAYER_ATTENDANCE_STATUS_ABSENT) {
        return "status-danger";
    }

    return "status-neutral";
}

function formatPlayerAttendanceStatusLabel(array $record)
{
    $status = trim((string)($record["attendance_status"] ?? ""));
    if ($status === "") {
        $status = PLAYER_ATTENDANCE_EMPTY_VALUE;
    }

    if (($record["attendance_source"] ?? "") === "single_training") {
        return $status . " - " . PLAYER_ATTENDANCE_SINGLE_TRAINING_LABEL;
    }

    return $status;
}

function buildPlayerAttendanceDaySignature(array $dayKeys)
{
    $normalized = [];
    foreach (array_keys(PLAYER_DAY_OPTIONS) as $dayKey) {
        if (in_array($dayKey, $dayKeys, true)) {
            $normalized[] = $dayKey;
        }
    }

    return implode(PLAYER_DAY_SEPARATOR, $normalized);
}

function fetchPlayerAttendanceSnapshots(PDO $pdo, $gameId)
{
    $presentStatus = PLAYER_ATTENDANCE_STATUS_PRESENT;
    $stmt = $pdo->prepare(
        "SELECT
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
            p.training_days_per_week,
            p.total_training_days,
            p.total_trainings,
            p.trainer_name,
            p.subscription_price,
            p.paid_amount,
            p.training_day_keys,
            p.training_time,
            p.created_at,
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
         WHERE p.game_id = ?
         GROUP BY
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
            p.training_days_per_week,
            p.total_training_days,
            p.total_trainings,
            p.trainer_name,
            p.subscription_price,
            p.paid_amount,
            p.training_day_keys,
            p.training_time,
            p.created_at
         ORDER BY p.name ASC, p.id ASC"
    );
    $stmt->execute([$presentStatus, $presentStatus, (int)$gameId]);

    return $stmt->fetchAll();
}

function buildPlayerAttendanceSnapshot(array $player, DateTimeImmutable $today)
{
    $isStandaloneSingleTraining = (($player["attendance_source"] ?? "") === "single_training")
        && (int)($player["player_id"] ?? 0) === 0;
    $attendanceCount = $isStandaloneSingleTraining ? 1 : (int)($player["attendance_count"] ?? 0);
    $consumedSessionsCount = $isStandaloneSingleTraining ? 1 : (int)($player["consumed_sessions_count"] ?? 0);
    $daysRemaining = $isStandaloneSingleTraining
        ? 0
        : calculatePlayerDaysRemaining($player["subscription_end_date"] ?? "", $today);
    $remainingTrainings = $isStandaloneSingleTraining
        ? 0
        : calculatePlayerRemainingTrainings($player["total_trainings"] ?? 0, $consumedSessionsCount);
    $dayKeys = $isStandaloneSingleTraining
        ? []
        : getPlayerTrainingDayKeys($player["training_day_keys"] ?? "");

    $player["attendance_count"] = $attendanceCount;
    $player["consumed_sessions_count"] = $consumedSessionsCount;
    $player["days_remaining"] = $daysRemaining;
    $player["remaining_trainings"] = $remainingTrainings;
    $player["status"] = $isStandaloneSingleTraining ? PLAYER_ATTENDANCE_SINGLE_TRAINING_LABEL : getPlayerSubscriptionStatus($daysRemaining, $remainingTrainings);
    $player["training_day_keys_list"] = $dayKeys;
    $player["training_day_labels"] = $isStandaloneSingleTraining ? [PLAYER_ATTENDANCE_SINGLE_TRAINING_LABEL] : formatPlayerTrainingDaysLabel($dayKeys);
    $player["training_day_signature"] = buildPlayerAttendanceDaySignature($dayKeys);

    return $player;
}

function isPlayerAttendanceScheduledForDay(array $player, $dayKey)
{
    return in_array($dayKey, $player["training_day_keys_list"] ?? [], true);
}

function buildPlayerAttendanceOffScheduleNotice(array $player, $dayKey)
{
    $trainingDayLabels = $player["training_day_labels"] ?? [];
    $scheduledDaysLabel = count($trainingDayLabels) > 0
        ? implode(" - ", $trainingDayLabels)
        : "غير محددة";
    $scheduledTimeLabel = formatTrainingTimeDisplay($player["training_time"] ?? "");
    $scheduledTimeMessage = $scheduledTimeLabel !== ""
        ? " وميعاده المسجل " . $scheduledTimeLabel
        : "";

    return "تنبيه: هذا ليس ميعاد تمرين اللاعب. أيام التمرين المسجلة له هي (" . $scheduledDaysLabel . ")" . $scheduledTimeMessage . ".";
}

function buildPlayerAttendanceWrongTimeNotice(array $player, DateTimeImmutable $now)
{
    $scheduledTimeValue = formatTrainingTimeLabel($player["training_time"] ?? "");
    if ($scheduledTimeValue === "") {
        return "";
    }

    $currentTimeValue = $now->format("H:i");
    if ($currentTimeValue === $scheduledTimeValue) {
        return "";
    }

    $scheduledTimeLabel = formatEgyptTimeForDisplay($scheduledTimeValue, $scheduledTimeValue);
    $currentTimeLabel = formatEgyptTimeForDisplay($currentTimeValue, $currentTimeValue);

    return "تنبيه: هذا ليس ميعاد تمرين اللاعب. الميعاد المسجل له هو " . $scheduledTimeLabel . " وتم تسجيل الحضور الآن في " . $currentTimeLabel . ".";
}

function getPlayerAttendanceLateCutoffDateTime(array $player, DateTimeImmutable $today)
{
    $trainingTime = normalizeTrainingTimeValue($player["training_time"] ?? "");
    if ($trainingTime === "") {
        return null;
    }

    $scheduledDateTime = DateTimeImmutable::createFromFormat(
        "Y-m-d H:i:s",
        $today->format("Y-m-d") . " " . $trainingTime,
        new DateTimeZone("Africa/Cairo")
    );
    if (!($scheduledDateTime instanceof DateTimeImmutable)) {
        return null;
    }

    return $scheduledDateTime->modify("+" . PLAYER_ATTENDANCE_LATE_LIMIT_MINUTES . " minutes");
}

function buildPlayerAttendanceLateBlockMessage(array $player, DateTimeImmutable $lateCutoff)
{
    $playerName = trim((string)($player["name"] ?? ""));
    $trainingTimeLabel = formatTrainingTimeDisplay($player["training_time"] ?? "");
    $lateCutoffLabel = formatTrainingTimeDisplay($lateCutoff->format("H:i:s"));
    $playerNameLabel = $playerName !== "" ? " للاعب " . $playerName : "";

    if ($trainingTimeLabel !== "" && $lateCutoffLabel !== "") {
        return "تم احتساب غياب اليوم" . $playerNameLabel . " لأنه تأخر أكثر من " . PLAYER_ATTENDANCE_LATE_LIMIT_MINUTES . " دقيقة عن موعد التمرين (" . $trainingTimeLabel . "). لا يمكن تسجيل الحضور بعد " . $lateCutoffLabel . ".";
    }

    return "تم احتساب غياب اليوم" . $playerNameLabel . " لأنه تأخر أكثر من " . PLAYER_ATTENDANCE_LATE_LIMIT_MINUTES . " دقيقة عن موعد التمرين. لا يمكن تسجيل الحضور.";
}

function calculatePlayerAttendanceMinutesLate(array $player, DateTimeImmutable $now, DateTimeImmutable $today)
{
    $trainingTime = normalizeTrainingTimeValue($player["training_time"] ?? "");
    if ($trainingTime === "") {
        return 0;
    }

    try {
        $scheduledDateTime = new DateTimeImmutable(
            $today->format("Y-m-d") . " " . $trainingTime,
            new DateTimeZone("Africa/Cairo")
        );
    } catch (Exception $exception) {
        return 0;
    }

    $secondsLate = $now->getTimestamp() - $scheduledDateTime->getTimestamp();
    if ($secondsLate <= 0) {
        return 0;
    }

    return (int)floor($secondsLate / 60);
}

function buildPlayerAttendanceLateNotificationMessage(array $player, DateTimeImmutable $attendanceDate, int $minutesLate)
{
    $minutesLate = max(0, (int)$minutesLate);
    $messageLines = [
        "تم تسجيل تأخيرك بتاريخ " . $attendanceDate->format("Y/m/d") . ".",
        "عدد دقائق التأخير: " . $minutesLate . " دقيقة.",
    ];

    $trainingTimeLabel = formatTrainingTimeDisplay($player["training_time"] ?? "");
    if ($trainingTimeLabel !== "") {
        $messageLines[] = "موعد التمرين: " . $trainingTimeLabel . ".";
    }

    return implode("\n", $messageLines);
}

function canPlayerReceiveAttendanceMark(array $player, DateTimeImmutable $today, $dayKey, $allowExistingTodayRecord = false)
{
    $startDate = trim((string)($player["subscription_start_date"] ?? ""));
    if (!isValidPlayerDate($startDate) || createPlayerDate($startDate) > $today) {
        return false;
    }

    if (!isPlayerAttendanceScheduledForDay($player, $dayKey)) {
        return false;
    }

    if ((int)($player["days_remaining"] ?? 0) <= 0) {
        return false;
    }

    if ((int)($player["remaining_trainings"] ?? 0) <= 0 && !$allowExistingTodayRecord) {
        return false;
    }

    return true;
}

function getPlayerAttendanceBlockMessage(array $player, DateTimeImmutable $today, $dayKey, $existingRecord = null, $allowOffSchedule = false)
{
    $startDate = trim((string)($player["subscription_start_date"] ?? ""));
    if (!isValidPlayerDate($startDate) || createPlayerDate($startDate) > $today) {
        return "اشتراك اللاعب لم يبدأ بعد.";
    }

    if (!$allowOffSchedule && !isPlayerAttendanceScheduledForDay($player, $dayKey)) {
        return "اليوم ليس من أيام تمرين اللاعب.";
    }

    if ((int)($player["days_remaining"] ?? 0) <= 0) {
        return "لا يمكن تسجيل حضور لاعب عدد الأيام المتبقية له 0.";
    }

    $allowExistingTodayAbsence = is_array($existingRecord)
        && ($existingRecord["attendance_status"] ?? "") === PLAYER_ATTENDANCE_STATUS_ABSENT;

    if ((int)($player["remaining_trainings"] ?? 0) <= 0 && !$allowExistingTodayAbsence) {
        return "لا يمكن تسجيل حضور لاعب عدد التمرينات المتبقية له 0.";
    }

    return "";
}

function syncPlayerAbsenceRows(PDO $pdo, $gameId, array $playersById, DateTimeImmutable $today)
{
    if (count($playersById) === 0) {
        return false;
    }

    $yesterday = $today->modify("-1 day");
    $earliestStartDate = null;
    foreach ($playersById as $player) {
        $startDateValue = trim((string)($player["subscription_start_date"] ?? ""));
        if (!isValidPlayerDate($startDateValue)) {
            continue;
        }

        $startDate = createPlayerDate($startDateValue);
        if ($earliestStartDate === null || $startDate < $earliestStartDate) {
            $earliestStartDate = $startDate;
        }
    }

    if (!$earliestStartDate instanceof DateTimeImmutable || $earliestStartDate > $yesterday) {
        return false;
    }

    $existingStmt = $pdo->prepare(
        "SELECT player_id, attendance_date
         FROM player_attendance
         WHERE game_id = ?
           AND attendance_date BETWEEN ? AND ?"
    );
    $existingStmt->execute([
        (int)$gameId,
        $earliestStartDate->format("Y-m-d"),
        $yesterday->format("Y-m-d"),
    ]);

    $existingRows = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $playerId = (int)($row["player_id"] ?? 0);
        if (!isset($existingRows[$playerId])) {
            $existingRows[$playerId] = [];
        }
        $existingRows[$playerId][(string)($row["attendance_date"] ?? "")] = true;
    }

    $rowsToInsert = [];
    foreach ($playersById as $player) {
        $playerId = (int)($player["id"] ?? 0);
        $remainingTrainings = (int)($player["remaining_trainings"] ?? 0);
        $trainingDayKeys = $player["training_day_keys_list"] ?? [];
        $startDateValue = trim((string)($player["subscription_start_date"] ?? ""));
        $endDateValue = trim((string)($player["subscription_end_date"] ?? ""));

        if (
            $playerId <= 0
            || $remainingTrainings <= 0
            || count($trainingDayKeys) === 0
            || !isValidPlayerDate($startDateValue)
            || !isValidPlayerDate($endDateValue)
        ) {
            continue;
        }

        $cursor = createPlayerDate($startDateValue);
        $endDate = createPlayerDate($endDateValue);
        $lastDate = $endDate < $yesterday ? $endDate : $yesterday;
        if ($cursor > $lastDate) {
            continue;
        }

        while ($cursor <= $lastDate && $remainingTrainings > 0) {
            $attendanceDate = $cursor->format("Y-m-d");
            $dayKey = getPlayerAttendanceDayKeyFromDate($cursor);
            if (
                in_array($dayKey, $trainingDayKeys, true)
                && empty($existingRows[$playerId][$attendanceDate])
            ) {
                $rowsToInsert[] = [
                    (int)$gameId,
                    $playerId,
                    $attendanceDate,
                    $dayKey,
                    PLAYER_ATTENDANCE_STATUS_ABSENT,
                    PLAYER_ATTENDANCE_DEFAULT_MINUTES_LATE,
                ];
                $remainingTrainings--;
            }

            $cursor = $cursor->modify("+1 day");
        }
    }

    if (count($rowsToInsert) === 0) {
        return false;
    }

    $insertStmt = $pdo->prepare(
        "INSERT IGNORE INTO player_attendance (
            game_id,
            player_id,
            attendance_date,
            attendance_day_key,
            attendance_status,
            attendance_minutes_late,
            attendance_at
        ) VALUES (?, ?, ?, ?, ?, ?, NULL)"
    );

    $pdo->beginTransaction();
    try {
        foreach ($rowsToInsert as $row) {
            $insertStmt->execute($row);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}

function normalizeAttendanceHourFilterValue($value)
{
    $value = trim((string)$value);
    if ($value === "") {
        return "";
    }

    if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
        return $value . ":00";
    }

    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
        return $value;
    }

    return "";
}

function normalizePlayerAttendanceDayFilters($dayFilters)
{
    if (!is_array($dayFilters)) {
        return [];
    }

    $sanitized = [];
    foreach ($dayFilters as $dayKey) {
        $dayKey = trim((string)$dayKey);
        if ($dayKey !== "" && isset(PLAYER_DAY_OPTIONS[$dayKey])) {
            $sanitized[] = $dayKey;
        }
    }

    return array_values(array_unique($sanitized));
}

function normalizePlayerAttendanceGroupStatusFilter($value)
{
    $value = trim((string)$value);
    return in_array($value, ["complete", "incomplete"], true) ? $value : "";
}

function matchesPlayerAttendanceGroupFilters(array $record, array $filters, array $groupStatusMap = [])
{
    $groupLevel = trim((string)($record["group_level"] ?? ""));
    $trainingDaysCount = (int)($record["training_days_per_week"] ?? 0);
    $trainingTime = normalizeAttendanceHourFilterValue($record["training_time"] ?? "");
    $trainerName = trim((string)($record["trainer_name"] ?? ""));
    $trainingDayKeys = getPlayerTrainingDayKeys($record["training_day_keys"] ?? "");
    $groupId = (int)($record["group_id"] ?? 0);

    if (($filters["level"] ?? "") !== "" && $groupLevel !== (string)$filters["level"]) {
        return false;
    }

    if (($filters["days_count"] ?? null) !== null && $trainingDaysCount !== (int)$filters["days_count"]) {
        return false;
    }

    if (($filters["time"] ?? "") !== "" && $trainingTime !== (string)$filters["time"]) {
        return false;
    }

    if (($filters["trainer"] ?? "") !== "" && $trainerName !== (string)$filters["trainer"]) {
        return false;
    }

    foreach (($filters["days"] ?? []) as $dayKey) {
        if (!in_array($dayKey, $trainingDayKeys, true)) {
            return false;
        }
    }

    $groupStatusFilter = (string)($filters["group_status"] ?? "");
    if ($groupStatusFilter !== "") {
        if ($groupId <= 0) {
            return false;
        }
        $groupCanAddPlayers = $groupId > 0 ? (bool)($groupStatusMap[$groupId] ?? false) : false;
        if ($groupStatusFilter === "complete" && $groupCanAddPlayers) {
            return false;
        }
        if ($groupStatusFilter === "incomplete" && !$groupCanAddPlayers) {
            return false;
        }
    }

    return true;
}

function fetchPlayerAttendanceRecords(PDO $pdo, $gameId, $dateFrom, $dateTo, $groupId, $statusFilter, $searchTerm, $timeFrom = "", $timeTo = "")
{
    $presentStatus = PLAYER_ATTENDANCE_STATUS_PRESENT;
    $sql = "SELECT
                pa.id,
                pa.player_id,
                pa.attendance_date,
                pa.attendance_at,
                COALESCE(NULLIF(pa.attendance_status, ''), ?) AS attendance_status,
                p.barcode,
                p.name,
                p.phone,
                p.player_category,
                p.subscription_start_date,
                p.subscription_end_date,
                p.group_id,
                p.group_name,
                p.group_level,
                p.training_days_per_week,
                p.total_training_days,
                p.total_trainings,
                p.trainer_name,
                p.training_time,
                p.subscription_price,
                p.paid_amount,
                p.training_day_keys,
                COALESCE(stats.consumed_sessions_count, 0) AS consumed_sessions_count,
                COALESCE(stats.attendance_count, 0) AS attendance_count,
                COALESCE(pa.pentathlon_sub_game, '') AS pentathlon_sub_game
            FROM player_attendance pa
            INNER JOIN players p ON p.id = pa.player_id
            LEFT JOIN (
                SELECT
                    player_id,
                    COUNT(*) AS consumed_sessions_count,
                    SUM(
                        CASE
                            WHEN COALESCE(NULLIF(attendance_status, ''), ?) = ?
                            THEN 1
                            ELSE 0
                        END
                    ) AS attendance_count
                FROM player_attendance
                INNER JOIN players current_players
                    ON current_players.id = player_attendance.player_id
                    AND player_attendance.attendance_date BETWEEN current_players.subscription_start_date AND current_players.subscription_end_date
                GROUP BY player_attendance.player_id
            ) stats ON stats.player_id = p.id
            WHERE pa.game_id = ?
              AND pa.attendance_date BETWEEN ? AND ?";
    $params = [$presentStatus, $presentStatus, $presentStatus, (int)$gameId, (string)$dateFrom, (string)$dateTo];

    if ((int)$groupId > 0) {
        $sql .= " AND p.group_id = ?";
        $params[] = (int)$groupId;
    }

    if ((string)$statusFilter !== "") {
        $sql .= " AND COALESCE(NULLIF(pa.attendance_status, ''), ?) = ?";
        $params[] = $presentStatus;
        $params[] = (string)$statusFilter;
    }

    if ((string)$searchTerm !== "") {
        $searchLike = "%" . $searchTerm . "%";
        $sql .= " AND (p.barcode LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)";
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    if ((string)$timeFrom !== "") {
        $sql .= " AND TIME(COALESCE(pa.attendance_at, pa.created_at)) >= ?";
        $params[] = (string)$timeFrom;
    }

    if ((string)$timeTo !== "") {
        $sql .= " AND TIME(COALESCE(pa.attendance_at, pa.created_at)) <= ?";
        $params[] = (string)$timeTo;
    }

    $sql .= " ORDER BY pa.attendance_date DESC, COALESCE(pa.attendance_at, pa.created_at) DESC, pa.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetchSingleTrainingAttendanceLogRecords(PDO $pdo, $gameId, $dateFrom, $dateTo, $groupId, $statusFilter, $searchTerm, $timeFrom = "", $timeTo = "")
{
    if ((string)$statusFilter === PLAYER_ATTENDANCE_STATUS_ABSENT) {
        return [];
    }

    $presentStatus = PLAYER_ATTENDANCE_STATUS_PRESENT;
    $sql = "SELECT
                sta.id,
                COALESCE(p.id, 0) AS player_id,
                sta.attendance_date,
                sta.attended_at AS attendance_at,
                ? AS attendance_status,
                COALESCE(p.barcode, '') AS barcode,
                COALESCE(NULLIF(p.name, ''), sta.player_name) AS name,
                COALESCE(NULLIF(p.phone, ''), sta.player_phone) AS phone,
                COALESCE(NULLIF(p.player_category, ''), ?) AS player_category,
                p.subscription_start_date,
                p.subscription_end_date,
                COALESCE(p.group_id, 0) AS group_id,
                COALESCE(NULLIF(p.group_name, ''), sta.training_name) AS group_name,
                COALESCE(NULLIF(p.group_level, ''), ?) AS group_level,
                COALESCE(p.training_days_per_week, 0) AS training_days_per_week,
                COALESCE(p.total_training_days, 0) AS total_training_days,
                COALESCE(p.total_trainings, 0) AS total_trainings,
                COALESCE(p.trainer_name, '') AS trainer_name,
                COALESCE(p.training_time, '') AS training_time,
                COALESCE(sta.training_price, 0) AS subscription_price,
                sta.paid_amount,
                COALESCE(p.training_day_keys, '') AS training_day_keys,
                COALESCE(stats.consumed_sessions_count, 0) AS consumed_sessions_count,
                COALESCE(stats.attendance_count, 0) AS attendance_count,
                'single_training' AS attendance_source
            FROM single_training_attendance sta
            LEFT JOIN players p
                ON p.game_id = sta.game_id
               AND p.phone = sta.player_phone
            LEFT JOIN (
                SELECT
                    player_attendance.player_id,
                    COUNT(*) AS consumed_sessions_count,
                    SUM(
                        CASE
                            WHEN COALESCE(NULLIF(player_attendance.attendance_status, ''), ?) = ?
                            THEN 1
                            ELSE 0
                        END
                    ) AS attendance_count
                FROM player_attendance
                INNER JOIN players current_players
                    ON current_players.id = player_attendance.player_id
                   AND player_attendance.attendance_date BETWEEN current_players.subscription_start_date AND current_players.subscription_end_date
                GROUP BY player_attendance.player_id
            ) stats ON stats.player_id = p.id
            WHERE sta.game_id = ?
              AND sta.attendance_date BETWEEN ? AND ?";
    $params = [
        $presentStatus,
        PLAYER_ATTENDANCE_SINGLE_TRAINING_LABEL,
        PLAYER_ATTENDANCE_SINGLE_TRAINING_LABEL,
        $presentStatus,
        $presentStatus,
        (int)$gameId,
        (string)$dateFrom,
        (string)$dateTo,
    ];

    if ((int)$groupId > 0) {
        $sql .= " AND p.group_id = ?";
        $params[] = (int)$groupId;
    }

    if ((string)$searchTerm !== "") {
        $searchLike = "%" . $searchTerm . "%";
        $sql .= " AND (sta.player_name LIKE ? OR sta.player_phone LIKE ? OR sta.training_name LIKE ? OR COALESCE(p.barcode, '') LIKE ?)";
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    if ((string)$timeFrom !== "") {
        $sql .= " AND TIME(sta.attended_at) >= ?";
        $params[] = (string)$timeFrom;
    }

    if ((string)$timeTo !== "") {
        $sql .= " AND TIME(sta.attended_at) <= ?";
        $params[] = (string)$timeTo;
    }

    $sql .= " ORDER BY sta.attendance_date DESC, sta.attended_at DESC, sta.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function summarizePlayerAttendanceRecords(array $records)
{
    $summary = [
        "total" => count($records),
        "present" => 0,
        "absent" => 0,
        "players" => 0,
    ];

    $playerIds = [];
    foreach ($records as $record) {
        $status = (string)($record["attendance_status"] ?? "");
        if ($status === PLAYER_ATTENDANCE_STATUS_PRESENT) {
            $summary["present"]++;
        } elseif ($status === PLAYER_ATTENDANCE_STATUS_ABSENT) {
            $summary["absent"]++;
        }

        $playerId = (int)($record["player_id"] ?? 0);
        if ($playerId > 0) {
            $playerIds[$playerId] = true;
        }
    }

    $summary["players"] = count($playerIds);
    return $summary;
}

function attachPlayerAttendanceSortTimestamp(array $record)
{
    $sortDateTime = (string)($record["attendance_at"] ?? $record["attendance_date"] ?? "");
    $sortTimestamp = $sortDateTime === "" ? false : strtotime($sortDateTime);
    $record["_sort_timestamp"] = $sortTimestamp === false ? 0 : $sortTimestamp;

    return $record;
}

function fetchTodayPlayerAttendanceStatuses(PDO $pdo, $gameId, $todayDate)
{
    $presentStatus = PLAYER_ATTENDANCE_STATUS_PRESENT;
    $stmt = $pdo->prepare(
        "SELECT
            player_id,
            COALESCE(NULLIF(attendance_status, ''), :empty_status_fallback) AS attendance_status
         FROM player_attendance
         WHERE game_id = :game_id
            AND attendance_date = :today_date"
    );
    $stmt->execute([
        ":empty_status_fallback" => $presentStatus,
        ":game_id" => (int)$gameId,
        ":today_date" => (string)$todayDate,
    ]);

    $statuses = [];
    foreach ($stmt->fetchAll() as $row) {
        $statuses[(int)($row["player_id"] ?? 0)] = (string)($row["attendance_status"] ?? "");
    }

    return $statuses;
}

function fetchTodayPlayerAttendanceSummary(PDO $pdo, $gameId, DateTimeImmutable $today, array $playersById)
{
    $dayKey = getPlayerAttendanceDayKeyFromDate($today);
    $todayStatuses = fetchTodayPlayerAttendanceStatuses($pdo, $gameId, $today->format("Y-m-d"));
    $allowExistingTodayRecord = true;

    $summary = [
        "present" => 0,
        "absent" => 0,
        "scheduled" => 0,
    ];

    foreach ($todayStatuses as $playerId => $status) {
        if ($status === PLAYER_ATTENDANCE_STATUS_PRESENT) {
            $summary["present"]++;
        }
    }

    foreach ($playersById as $player) {
        if (!canPlayerReceiveAttendanceMark($player, $today, $dayKey, $allowExistingTodayRecord)) {
            continue;
        }

        $playerId = (int)($player["id"] ?? 0);
        $summary["scheduled"]++;
        if (($todayStatuses[$playerId] ?? "") !== PLAYER_ATTENDANCE_STATUS_PRESENT) {
            $summary["absent"]++;
        }
    }

    return [
        "present" => (int)$summary["present"],
        "absent" => (int)$summary["absent"],
        "scheduled" => (int)$summary["scheduled"],
    ];
}

function computePlayerImpliedAbsencesInRange(
    PDO $pdo,
    $gameId,
    array $playersById,
    $dateFrom,
    $dateTo,
    DateTimeImmutable $today,
    $selectedGroupId = 0,
    $searchTerm = ""
) {
    $result = [
        "total_absences" => 0,
        "unique_players" => 0,
        "entries"        => [],
        "date_from"      => (string)$dateFrom,
        "date_to"        => (string)$dateTo,
        "effective_to"   => (string)$dateFrom,
    ];

    if (!isValidPlayerDate($dateFrom) || !isValidPlayerDate($dateTo)) {
        return $result;
    }

    if (count($playersById) === 0) {
        return $result;
    }

    $todayKey = $today->format("Y-m-d");
    // Only count days that have already ENDED (strictly before today).
    $yesterday = $today->modify("-1 day")->format("Y-m-d");
    $effectiveTo = ($dateTo >= $todayKey) ? $yesterday : $dateTo;
    $result["effective_to"] = $effectiveTo;

    if ($effectiveTo < $dateFrom) {
        return $result;
    }

    $needle = "";
    if ((string)$searchTerm !== "") {
        $needle = function_exists("mb_strtolower")
            ? mb_strtolower((string)$searchTerm, "UTF-8")
            : strtolower((string)$searchTerm);
    }

    $applicablePlayers = [];
    foreach ($playersById as $player) {
        if ($selectedGroupId > 0 && (int)($player["group_id"] ?? 0) !== (int)$selectedGroupId) {
            continue;
        }
        if ($needle !== "") {
            $hayParts = [
                (string)($player["barcode"] ?? ""),
                (string)($player["name"] ?? ""),
                (string)($player["phone"] ?? ""),
            ];
            $hay = implode(" ", $hayParts);
            $hayLower = function_exists("mb_strtolower") ? mb_strtolower($hay, "UTF-8") : strtolower($hay);
            $found = function_exists("mb_strpos") ? (mb_strpos($hayLower, $needle, 0, "UTF-8") !== false) : (strpos($hayLower, $needle) !== false);
            if (!$found) {
                continue;
            }
        }
        $dayKeys = $player["training_day_keys_list"] ?? [];
        if (count($dayKeys) === 0) {
            continue;
        }
        $startDate = trim((string)($player["subscription_start_date"] ?? ""));
        if (!isValidPlayerDate($startDate)) {
            continue;
        }
        $applicablePlayers[(int)$player["id"]] = $player;
    }

    if (count($applicablePlayers) === 0) {
        return $result;
    }

    $playerIds = array_keys($applicablePlayers);
    $placeholders = implode(",", array_fill(0, count($playerIds), "?"));
    $sql = "SELECT player_id, attendance_date
            FROM player_attendance
            WHERE game_id = ?
              AND player_id IN ($placeholders)
              AND attendance_date BETWEEN ? AND ?";
    $params = array_merge([(int)$gameId], $playerIds, [(string)$dateFrom, (string)$effectiveTo]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $attendanceByPlayer = [];
    foreach ($stmt->fetchAll() as $row) {
        $pid = (int)$row["player_id"];
        $d   = (string)$row["attendance_date"];
        $attendanceByPlayer[$pid][$d] = true;
    }

    $startDt = createPlayerDate($dateFrom);
    $endDt   = createPlayerDate($effectiveTo);
    if (!$startDt || !$endDt) {
        return $result;
    }

    $period = new DatePeriod($startDt, new DateInterval("P1D"), $endDt->modify("+1 day"));
    $iterations = 0;
    foreach ($period as $dt) {
        $iterations++;
        if ($iterations > 400) {
            break;
        }
        $dateStr = $dt->format("Y-m-d");
        $dayKey  = getPlayerAttendanceDayKeyFromDate($dt);

        foreach ($applicablePlayers as $pid => $p) {
            $pStart = trim((string)($p["subscription_start_date"] ?? ""));
            $pEnd   = trim((string)($p["subscription_end_date"] ?? ""));
            if ($dateStr < $pStart) {
                continue;
            }
            if ($pEnd !== "" && $dateStr > $pEnd) {
                continue;
            }
            if (!in_array($dayKey, $p["training_day_keys_list"] ?? [], true)) {
                continue;
            }
            if (isset($attendanceByPlayer[$pid][$dateStr])) {
                continue;
            }

            if (!isset($result["entries"][$pid])) {
                $result["entries"][$pid] = [
                    "id"                  => $pid,
                    "name"                => (string)($p["name"] ?? ""),
                    "barcode"             => (string)($p["barcode"] ?? ""),
                    "phone"               => (string)($p["phone"] ?? ""),
                    "group_name"          => (string)($p["group_name"] ?? ""),
                    "training_time"       => (string)($p["training_time"] ?? ""),
                    "training_day_labels" => $p["training_day_labels"] ?? [],
                    "dates"               => [],
                ];
            }
            $result["entries"][$pid]["dates"][] = $dateStr;
            $result["total_absences"]++;
        }
    }

    foreach ($result["entries"] as $pid => $entry) {
        $dates = $entry["dates"];
        usort($dates, function ($a, $b) {
            return strcmp((string)$b, (string)$a);
        });
        $entry["dates"] = $dates;
        $result["entries"][$pid] = $entry;
    }
    uasort($result["entries"], function ($a, $b) {
        return strcmp((string)$a["name"], (string)$b["name"]);
    });
    $result["unique_players"] = count($result["entries"]);

    return $result;
}

function exportPlayerAttendanceXlsx(array $records, $dateFrom, $dateTo)
{
    $headers = [
        "رقم الصف",
        "باركود اللاعب",
        "اسم اللاعب",
        "رقم الهاتف",
        "تصنيف اللاعب",
        "المجموعة",
        "مستوى المجموعة",
        "أيام التمرين",
        "عدد أيام التمرين بالأسبوع",
        "عدد الحضور",
        "عدد التمرينات المتبقية",
        "المدرب",
        "تاريخ بداية الاشتراك",
        "تاريخ نهاية الاشتراك",
        "عدد الأيام المتبقية",
        "تاريخ السجل",
        "وقت التسجيل",
        "حالة السجل",
        "سعر الاشتراك",
        "المدفوع",
    ];

    $rows = [];
    foreach ($records as $index => $record) {
        $rows[] = [
            (string)($index + 1),
            (string)($record["barcode"] ?? ""),
            (string)($record["name"] ?? ""),
            (string)($record["phone"] ?? ""),
            (string)($record["player_category"] ?? ""),
            (string)($record["group_name"] ?? ""),
            (string)($record["group_level"] ?? ""),
            implode(" - ", $record["training_day_labels"] ?? []),
            (string)(int)($record["training_days_per_week"] ?? 0),
            (string)(int)($record["attendance_count"] ?? 0),
            (string)(int)($record["remaining_trainings"] ?? 0),
            (string)($record["trainer_name"] ?? ""),
            (string)($record["subscription_start_date"] ?? ""),
            (string)($record["subscription_end_date"] ?? ""),
            (string)(int)($record["days_remaining"] ?? 0),
            (string)($record["attendance_date"] ?? ""),
            formatPlayerAttendanceActualTime($record["attendance_at"] ?? ""),
            formatPlayerAttendanceStatusLabel($record),
            formatPlayerCurrencyLabel($record["subscription_price"] ?? 0),
            formatPlayerCurrencyLabel($record["paid_amount"] ?? 0),
        ];
    }

    outputPlayersXlsxDownload("players-attendance-" . $dateFrom . "-to-" . $dateTo . ".xlsx", $headers, $rows);
}

function handlePlayerAttendanceScan(PDO $pdo, array $player, array $playersById, DateTimeImmutable $now, $gameId, string $pentathlonSubGame = "")
{
    $today = new DateTimeImmutable($now->format("Y-m-d") . " 00:00:00", new DateTimeZone("Africa/Cairo"));
    $todayDate = $today->format("Y-m-d");
    $dayKey = getPlayerAttendanceDayKeyFromDate($today);
    $isScheduledDay = isPlayerAttendanceScheduledForDay($player, $dayKey);
    $attendanceMinutesLate = $isScheduledDay ? calculatePlayerAttendanceMinutesLate($player, $now, $today) : 0;

    $pdo->beginTransaction();
    try {
        $recordStmt = $pdo->prepare(
            "SELECT *
             FROM player_attendance
             WHERE player_id = ? AND attendance_date = ?
             LIMIT 1
             FOR UPDATE"
        );
        $recordStmt->execute([(int)$player["id"], $todayDate]);
        $existingRecord = $recordStmt->fetch();

        $blockMessage = getPlayerAttendanceBlockMessage($player, $today, $dayKey, $existingRecord, true);
        if ($blockMessage !== "") {
            $pdo->rollBack();
            return [
                "success" => false,
                "message" => $blockMessage,
            ];
        }

        if ($existingRecord && (($existingRecord["attendance_status"] ?? "") === PLAYER_ATTENDANCE_STATUS_PRESENT)) {
            $pdo->rollBack();
            return [
                "success" => false,
                "message" => "تم تسجيل حضور هذا اللاعب اليوم بالفعل.",
            ];
        }

        if ($existingRecord) {
            $updateStmt = $pdo->prepare(
                "UPDATE player_attendance
                 SET attendance_status = ?, attendance_minutes_late = ?, attendance_at = ?, pentathlon_sub_game = ?
                 WHERE id = ?"
            );
            $updateStmt->execute([
                PLAYER_ATTENDANCE_STATUS_PRESENT,
                $attendanceMinutesLate,
                $now->format("Y-m-d H:i:s"),
                $pentathlonSubGame,
                (int)$existingRecord["id"],
            ]);
            auditTrack($pdo, "update", "player_attendance", (int)$existingRecord["id"], "حضور اللاعبين", "تسجيل حضور اللاعب: " . (string)$player["name"]);
        } else {
            $insertPresentStmt = $pdo->prepare(
                "INSERT INTO player_attendance (
                    game_id,
                    player_id,
                    attendance_date,
                    attendance_day_key,
                    attendance_status,
                    attendance_minutes_late,
                    attendance_at,
                    pentathlon_sub_game
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insertPresentStmt->execute([
                (int)$gameId,
                (int)$player["id"],
                $todayDate,
                $dayKey,
                PLAYER_ATTENDANCE_STATUS_PRESENT,
                $attendanceMinutesLate,
                $now->format("Y-m-d H:i:s"),
                $pentathlonSubGame,
            ]);
            auditTrack($pdo, "create", "player_attendance", (int)$pdo->lastInsertId(), "حضور اللاعبين", "تسجيل حضور اللاعب: " . (string)$player["name"]);
        }

        if ($attendanceMinutesLate > 0) {
            createPlayerNotification(
                $pdo,
                $gameId,
                (int)$player["id"],
                '⏰ تم تسجيل تأخيرك اليوم',
                buildPlayerAttendanceLateNotificationMessage($player, $today, $attendanceMinutesLate),
                'alert',
                'important',
                $todayDate
            );
        }

        $pdo->commit();

        $subGameLabel = $pentathlonSubGame !== "" ? " في لعبة " . $pentathlonSubGame : "";
        $message = $existingRecord
            ? "تم تحويل غياب اللاعب " . $player["name"] . " إلى حضور" . $subGameLabel . "."
            : "تم تسجيل حضور اللاعب " . $player["name"] . $subGameLabel . ".";
        if (!$isScheduledDay) {
            $message .= " " . buildPlayerAttendanceOffScheduleNotice($player, $dayKey);
        } else {
            $wrongTimeNotice = buildPlayerAttendanceWrongTimeNotice($player, $now);
            if ($wrongTimeNotice !== "") {
                $message .= " " . $wrongTimeNotice;
            }
        }

        return [
            "success" => true,
            "message" => $message,
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Player attendance page error: " . $throwable->getMessage());
        return [
            "success" => false,
            "message" => "تعذر تسجيل الحضور حاليًا.",
        ];
    }
}

if (!isset($_SESSION["players_attendance_csrf_token"])) {
    $_SESSION["players_attendance_csrf_token"] = bin2hex(random_bytes(32));
}

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$isPentathlon = mb_stripos($currentGameName, "خماسي") !== false;
$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch() ?: [];
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "players-attendance";

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
$today = new DateTimeImmutable("today", new DateTimeZone("Africa/Cairo"));
$egyptDateTimeLabel = formatPlayerAttendanceDateTimeLabel($now);

$groupsStmt = $pdo->prepare(
    "SELECT id, group_name, group_level, max_players, trainer_name, training_days_count, training_day_keys, training_time
     FROM sports_groups
     WHERE game_id = ?
     ORDER BY group_level ASC, group_name ASC, id ASC"
);
$groupsStmt->execute([$currentGameId]);
$groups = $groupsStmt->fetchAll();
$groupPlayerCounts = fetchGroupPlayerCounts($pdo, $currentGameId);
$availableLevels = [];
$availableDaysCounts = [];
$availableTimes = [];
$availableTrainers = [];
$groupStatusMap = [];
foreach ($groups as &$group) {
    $groupId = (int)($group["id"] ?? 0);
    $group["current_players_count"] = $groupPlayerCounts[$groupId] ?? 0;
    $group["can_add_players"] = playerGroupHasAvailableSlot($group);
    $groupStatusMap[$groupId] = (bool)$group["can_add_players"];

    $groupLevel = trim((string)($group["group_level"] ?? ""));
    if ($groupLevel !== "") {
        $availableLevels[$groupLevel] = $groupLevel;
    }

    $daysCount = (int)($group["training_days_count"] ?? 0);
    if ($daysCount > 0) {
        $availableDaysCounts[$daysCount] = $daysCount;
    }

    $trainingTime = normalizeAttendanceHourFilterValue($group["training_time"] ?? "");
    if ($trainingTime !== "") {
        $availableTimes[$trainingTime] = $trainingTime;
    }

    $trainerName = trim((string)($group["trainer_name"] ?? ""));
    if ($trainerName !== "") {
        $availableTrainers[$trainerName] = $trainerName;
    }
}
unset($group);
ksort($availableLevels, SORT_NATURAL);
ksort($availableDaysCounts, SORT_NUMERIC);
ksort($availableTimes, SORT_NATURAL);
ksort($availableTrainers, SORT_NATURAL);
$availableLevels = array_values($availableLevels);
$availableDaysCounts = array_values($availableDaysCounts);
$availableTimes = array_values($availableTimes);
$availableTrainers = array_values($availableTrainers);
$singleTrainingDefinitions = fetchSingleTrainingDefinitions($pdo, $currentGameId);
$singleTrainingAttendanceFormData = [
    "player_name" => "",
    "player_phone" => "",
    "single_training_id" => "",
    "paid_amount" => "",
];
$shouldOpenSingleTrainingModal = false;

$players = [];
$playersById = [];
$playersByBarcode = [];
foreach (fetchPlayerAttendanceSnapshots($pdo, $currentGameId) as $playerRow) {
    $snapshot = buildPlayerAttendanceSnapshot($playerRow, $today);
    $players[] = $snapshot;
    $playersById[(int)$snapshot["id"]] = $snapshot;
    if (trim((string)$snapshot["barcode"]) !== "") {
        $playersByBarcode[(string)$snapshot["barcode"]] = $snapshot;
    }
}

if (syncPlayerAbsenceRows($pdo, $currentGameId, $playersById, $today)) {
    $players = [];
    $playersById = [];
    $playersByBarcode = [];
    foreach (fetchPlayerAttendanceSnapshots($pdo, $currentGameId) as $playerRow) {
        $snapshot = buildPlayerAttendanceSnapshot($playerRow, $today);
        $players[] = $snapshot;
        $playersById[(int)$snapshot["id"]] = $snapshot;
        if (trim((string)$snapshot["barcode"]) !== "") {
            $playersByBarcode[(string)$snapshot["barcode"]] = $snapshot;
        }
    }
}

if ($isPentathlon && count($players) > 0) {
    $pentathlonPlayerIds = array_map(fn($p) => (int)$p["id"], $players);
    $pentathlonPlaceholders = implode(",", array_fill(0, count($pentathlonPlayerIds), "?"));
    $lastSubGameStmt = $pdo->prepare(
        "SELECT pa.player_id, pa.pentathlon_sub_game
         FROM player_attendance pa
         INNER JOIN (
             SELECT player_id, MAX(id) AS max_id
             FROM player_attendance
             WHERE player_id IN ($pentathlonPlaceholders)
               AND pentathlon_sub_game != ''
               AND attendance_status = ?
             GROUP BY player_id
         ) latest ON latest.player_id = pa.player_id AND latest.max_id = pa.id"
    );
    $lastSubGameStmt->execute(array_merge($pentathlonPlayerIds, [PLAYER_ATTENDANCE_STATUS_PRESENT]));
    $lastSubGameByPlayer = [];
    foreach ($lastSubGameStmt->fetchAll() as $row) {
        $lastSubGameByPlayer[(int)$row["player_id"]] = (string)$row["pentathlon_sub_game"];
    }
    foreach ($players as &$playerRef) {
        $pid = (int)$playerRef["id"];
        $playerRef["last_pentathlon_sub_game"] = $lastSubGameByPlayer[$pid] ?? "";
    }
    unset($playerRef);
    foreach ($players as $playerRefItem) {
        $pid = (int)$playerRefItem["id"];
        $playersById[$pid] = $playerRefItem;
        $bc = trim((string)$playerRefItem["barcode"]);
        if ($bc !== "") {
            $playersByBarcode[$bc] = $playerRefItem;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["players_attendance_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = trim((string)($_POST["action"] ?? "scan_attendance"));
        if ($action === "add_single_training_attendance") {
            $shouldOpenSingleTrainingModal = true;
            $singleTrainingAttendanceFormData = [
                "player_name" => limitSingleTrainingText($_POST["player_name"] ?? ""),
                "player_phone" => limitSingleTrainingText($_POST["player_phone"] ?? "", 30),
                "single_training_id" => trim((string)($_POST["single_training_id"] ?? "")),
                "paid_amount" => normalizePlayerMoneyValue($_POST["paid_amount"] ?? ""),
            ];

            $selectedSingleTrainingId = $singleTrainingAttendanceFormData["single_training_id"] === ""
                ? 0
                : (int)$singleTrainingAttendanceFormData["single_training_id"];
            $selectedSingleTraining = $selectedSingleTrainingId > 0
                ? fetchSingleTrainingDefinitionById($pdo, $currentGameId, $selectedSingleTrainingId)
                : null;

            if ($singleTrainingAttendanceFormData["player_name"] === "") {
                $error = "اسم اللاعب مطلوب.";
            } elseif ($singleTrainingAttendanceFormData["player_phone"] === "") {
                $error = "رقم هاتف اللاعب مطلوب.";
            } elseif (!$selectedSingleTraining) {
                $error = "التمرين المحدد غير متاح.";
            } elseif ($singleTrainingAttendanceFormData["paid_amount"] === "") {
                $error = "المدفوع غير صحيح.";
            } else {
                $trainingPrice = formatPlayerCurrency($selectedSingleTraining["price"] ?? 0);
                $paidAmount = formatPlayerCurrency($singleTrainingAttendanceFormData["paid_amount"]);

                if ($paidAmount !== $trainingPrice) {
                    $error = "المدفوع يجب أن يساوي سعر التمرينة المختارة.";
                } else {
                    $saveAttendanceStmt = $pdo->prepare(
                        "INSERT INTO single_training_attendance
                         (game_id, single_training_id, player_name, player_phone, training_name, training_price, paid_amount, attendance_date, attended_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    try {
                        $saveAttendanceStmt->execute([
                            $currentGameId,
                            (int)$selectedSingleTraining["id"],
                            $singleTrainingAttendanceFormData["player_name"],
                            $singleTrainingAttendanceFormData["player_phone"],
                            $selectedSingleTraining["training_name"],
                            $trainingPrice,
                            $paidAmount,
                            $today->format("Y-m-d"),
                            $now->format("Y-m-d H:i:s"),
                        ]);

                        $_SESSION["players_attendance_csrf_token"] = bin2hex(random_bytes(32));
                        $_SESSION["players_attendance_success"] = "تم تسجيل حضور لاعب تمرينة واحدة بنجاح.";
                        header("Location: players_attendance.php");
                        exit;
                    } catch (Throwable $throwable) {
                        $error = "تعذر تسجيل حضور لاعب تمرينة واحدة.";
                    }
                }
            }
        } else {
            $barcode = trim((string)($_POST["barcode"] ?? ""));
            $selectedPentathlonSubGame = trim((string)($_POST["pentathlon_sub_game"] ?? ""));
            if (!in_array($selectedPentathlonSubGame, PENTATHLON_SUB_GAMES, true)) {
                $selectedPentathlonSubGame = "";
            }

            if ($barcode === "") {
                $error = "الباركود مطلوب.";
            } elseif ($isPentathlon && $selectedPentathlonSubGame === "") {
                $error = "يرجى تحديد اللعبة قبل تسجيل الحضور.";
            } elseif (!isset($playersByBarcode[$barcode])) {
                $error = "لا يوجد لاعب بهذا الباركود.";
            } else {
                $result = handlePlayerAttendanceScan($pdo, $playersByBarcode[$barcode], $playersById, $now, $currentGameId, $isPentathlon ? $selectedPentathlonSubGame : "");
                if ($result["success"]) {
                    $_SESSION["players_attendance_csrf_token"] = bin2hex(random_bytes(32));
                    $_SESSION["players_attendance_success"] = $result["message"];
                    header("Location: players_attendance.php");
                    exit;
                }
                $error = $result["message"];
            }
        }
    }
}

$flashSuccess = $_SESSION["players_attendance_success"] ?? "";
unset($_SESSION["players_attendance_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

$dateFrom = trim((string)($_GET["date_from"] ?? $today->format("Y-m-d")));
$dateTo = trim((string)($_GET["date_to"] ?? $today->format("Y-m-d")));
if (!isValidPlayerDate($dateFrom)) {
    $dateFrom = $today->format("Y-m-d");
}
if (!isValidPlayerDate($dateTo)) {
    $dateTo = $today->format("Y-m-d");
}
if ($dateFrom > $dateTo) {
    $temporaryDate = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $temporaryDate;
}

$selectedGroupId = (int)($_GET["group_id"] ?? 0);
$statusFilter = trim((string)($_GET["status"] ?? ""));
if ($statusFilter !== PLAYER_ATTENDANCE_STATUS_PRESENT && $statusFilter !== PLAYER_ATTENDANCE_STATUS_ABSENT) {
    $statusFilter = "";
}
$timeFrom = normalizeAttendanceHourFilterValue($_GET["time_from"] ?? "");
$timeTo = normalizeAttendanceHourFilterValue($_GET["time_to"] ?? "");
if ($timeFrom !== "" && $timeTo !== "" && $timeFrom > $timeTo) {
    $swapTime = $timeFrom;
    $timeFrom = $timeTo;
    $timeTo = $swapTime;
}

$searchTerm = strip_tags(trim((string)($_GET["search"] ?? "")));
if (function_exists("mb_substr")) {
    $searchTerm = mb_substr($searchTerm, 0, 100);
} else {
    $searchTerm = substr($searchTerm, 0, 100);
}
$filterLevel = trim((string)($_GET["filter_level"] ?? ""));
$filterDaysCount = isset($_GET["filter_days_count"]) && $_GET["filter_days_count"] !== "" ? (int)$_GET["filter_days_count"] : null;
$filterDays = normalizePlayerAttendanceDayFilters($_GET["filter_days"] ?? []);
$filterTime = normalizeAttendanceHourFilterValue($_GET["filter_time"] ?? "");
$filterTrainer = trim((string)($_GET["filter_trainer"] ?? ""));
$filterGroupStatus = normalizePlayerAttendanceGroupStatusFilter($_GET["filter_group_status"] ?? "");
$attendanceGroupFilters = [
    "level" => $filterLevel,
    "days_count" => $filterDaysCount,
    "days" => $filterDays,
    "time" => $filterTime,
    "trainer" => $filterTrainer,
    "group_status" => $filterGroupStatus,
];

$records = [];
foreach (fetchPlayerAttendanceRecords($pdo, $currentGameId, $dateFrom, $dateTo, $selectedGroupId, $statusFilter, $searchTerm, $timeFrom, $timeTo) as $recordRow) {
    $record = buildPlayerAttendanceSnapshot($recordRow, $today);
    if (!matchesPlayerAttendanceGroupFilters($record, $attendanceGroupFilters, $groupStatusMap)) {
        continue;
    }
    $records[] = attachPlayerAttendanceSortTimestamp($record);
}
foreach (fetchSingleTrainingAttendanceLogRecords($pdo, $currentGameId, $dateFrom, $dateTo, $selectedGroupId, $statusFilter, $searchTerm, $timeFrom, $timeTo) as $recordRow) {
    $record = buildPlayerAttendanceSnapshot($recordRow, $today);
    if (!matchesPlayerAttendanceGroupFilters($record, $attendanceGroupFilters, $groupStatusMap)) {
        continue;
    }
    $records[] = attachPlayerAttendanceSortTimestamp($record);
}
usort($records, function (array $firstRecord, array $secondRecord) {
    $firstTimestamp = (int)($firstRecord["_sort_timestamp"] ?? 0);
    $secondTimestamp = (int)($secondRecord["_sort_timestamp"] ?? 0);
    if ($firstTimestamp === $secondTimestamp) {
        return ((int)($secondRecord["id"] ?? 0)) <=> ((int)($firstRecord["id"] ?? 0));
    }

    return $secondTimestamp <=> $firstTimestamp;
});

$todaySummary = fetchTodayPlayerAttendanceSummary($pdo, $currentGameId, $today, $playersById);

$absencePlayersById = [];
foreach ($playersById as $playerId => $player) {
    if (matchesPlayerAttendanceGroupFilters($player, $attendanceGroupFilters, $groupStatusMap)) {
        $absencePlayersById[$playerId] = $player;
    }
}
$absencesInRange = computePlayerImpliedAbsencesInRange(
    $pdo,
    $currentGameId,
    $absencePlayersById,
    $dateFrom,
    $dateTo,
    $today,
    $selectedGroupId,
    $searchTerm
);
$isAbsenceRangeSingleDay = ((string)$dateFrom === (string)$dateTo);
$absenceRangeLabel = $isAbsenceRangeSingleDay
    ? $dateFrom
    : ($dateFrom . " ← " . $dateTo);
$hasEndedDaysInRange = ((string)$absencesInRange["effective_to"] >= (string)$dateFrom);

if (($_GET["export"] ?? "") === "xlsx") {
    try {
        exportPlayerAttendanceXlsx($records, $dateFrom, $dateTo);
    } catch (Throwable $throwable) {
        error_log("Player attendance export error: " . $throwable->getMessage());
        $error = "تعذر إنشاء ملف Excel.";
    }
}

$exportQuery = http_build_query([
    "date_from" => $dateFrom,
    "date_to" => $dateTo,
    "group_id" => $selectedGroupId,
    "status" => $statusFilter,
    "time_from" => $timeFrom === "" ? "" : substr($timeFrom, 0, 5),
    "time_to" => $timeTo === "" ? "" : substr($timeTo, 0, 5),
    "search" => $searchTerm,
    "filter_level" => $filterLevel,
    "filter_days_count" => $filterDaysCount,
    "filter_days" => $filterDays,
    "filter_time" => $filterTime === "" ? "" : substr($filterTime, 0, 5),
    "filter_trainer" => $filterTrainer,
    "filter_group_status" => $filterGroupStatus,
    "export" => "xlsx",
]);
$selectedSingleTrainingId = $singleTrainingAttendanceFormData["single_training_id"] === ""
    ? 0
    : (int)$singleTrainingAttendanceFormData["single_training_id"];
$selectedSingleTraining = $selectedSingleTrainingId > 0
    ? fetchSingleTrainingDefinitionById($pdo, $currentGameId, $selectedSingleTrainingId)
    : null;
$selectedSingleTrainingPrice = $selectedSingleTraining ? formatPlayerCurrency($selectedSingleTraining["price"]) : "";
$selectedSingleTrainingPriceLabel = $selectedSingleTraining ? formatPlayerCurrencyLabel($selectedSingleTraining["price"]) : "";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حضور اللاعبين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .single-training-attendance-modal-card {
            width: min(560px, 100%);
        }
        .single-training-attendance-form-grid {
            display: grid;
            gap: 16px;
        }
        .single-training-attendance-price-preview {
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
        .single-training-attendance-price-preview.is-empty {
            color: var(--text-soft);
        }
        body.dark-mode .single-training-attendance-price-preview {
            background: #162133;
            border-color: #334155;
        }

        .players-absence-stat-card {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .players-absence-stat-trigger {
            background: none;
            border: 0;
            padding: 0;
            margin: 0;
            color: inherit;
            font: inherit;
            cursor: pointer;
            text-align: inherit;
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 100%;
        }
        .players-absence-stat-trigger:disabled {
            cursor: default;
            opacity: 0.85;
        }
        .players-absence-stat-trigger:not(:disabled):hover .trainer-stat-value,
        .players-absence-stat-trigger:not(:disabled):focus-visible .trainer-stat-value {
            color: var(--danger, #dc2626);
            text-decoration: underline;
        }
        .players-absence-stat-cta {
            font-size: 12px;
            color: var(--text-soft, #6b7280);
        }
        .players-absence-stat-meta {
            font-size: 12px;
            color: var(--text-soft, #6b7280);
        }
        .players-absence-modal-card {
            width: min(960px, 100%);
            max-height: 88vh;
            display: flex;
            flex-direction: column;
        }
        .players-absence-modal-body {
            margin-top: 14px;
            overflow: auto;
            max-height: 70vh;
        }
        .players-absence-modal-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
            font-size: 13px;
            color: var(--text-soft, #6b7280);
            margin-bottom: 12px;
        }
        .players-absence-modal-meta b {
            color: var(--text, #111827);
        }
        .players-absence-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .players-absence-table th,
        .players-absence-table td {
            border: 1px solid rgba(148, 163, 184, 0.25);
            padding: 8px 10px;
            text-align: right;
            vertical-align: top;
        }
        .players-absence-table thead th {
            background: rgba(47, 91, 234, 0.08);
            font-weight: 700;
        }
        .players-absence-table tbody tr:nth-child(even) td {
            background: rgba(148, 163, 184, 0.06);
        }
        .players-absence-date-chip {
            display: inline-block;
            margin: 2px 4px 2px 0;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(220, 38, 38, 0.1);
            color: #b91c1c;
            font-size: 12px;
            white-space: nowrap;
        }
        body.dark-mode .players-absence-table thead th {
            background: #1e293b;
        }
        body.dark-mode .players-absence-table tbody tr:nth-child(even) td {
            background: #111827;
        }
        body.dark-mode .players-absence-date-chip {
            background: rgba(248, 113, 113, 0.18);
            color: #fecaca;
        }
        .players-absence-empty {
            padding: 24px 12px;
            text-align: center;
            color: var(--text-soft, #6b7280);
        }
        .players-attendance-days-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 10px;
        }
        .players-attendance-days-filter label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
        }
        @media (max-width: 768px) {
            .players-attendance-days-filter {
                gap: 10px;
            }
            .players-attendance-days-filter label {
                width: calc(50% - 5px);
            }
        }
    </style>
</head>
<body class="dashboard-page players-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>حضور اللاعبين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <span class="context-badge egypt-datetime-badge"><?php echo htmlspecialchars($egyptDateTimeLabel, ENT_QUOTES, "UTF-8"); ?></span>
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
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <section class="players-summary-grid">
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">الحضور المسجل اليوم</span>
                <strong class="trainer-stat-value"><?php echo (int)$todaySummary["present"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">غياب اليوم</span>
                <strong class="trainer-stat-value"><?php echo (int)$todaySummary["absent"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">المحدد لهم تمرين اليوم</span>
                <strong class="trainer-stat-value"><?php echo (int)$todaySummary["scheduled"]; ?></strong>
            </div>
            <div class="card trainer-stat-card players-absence-stat-card">
                <button
                    type="button"
                    class="players-absence-stat-trigger"
                    id="openAbsenceListModal"
                    aria-haspopup="dialog"
                    aria-controls="absenceListModalOverlay"
                    <?php echo ((int)$absencesInRange["total_absences"] === 0) ? "disabled" : ""; ?>
                >
                    <span class="trainer-stat-label">عدد الغائبين في الفترة المحددة</span>
                    <strong class="trainer-stat-value"><?php echo (int)$absencesInRange["total_absences"]; ?></strong>
                    <?php if (!$hasEndedDaysInRange): ?>
                        <span class="players-absence-stat-meta">لم تنتهِ أي أيام من الفترة بعد</span>
                    <?php else: ?>
                        <span class="players-absence-stat-meta">
                            (<?php echo (int)$absencesInRange["unique_players"]; ?> لاعب)
                            • <?php echo htmlspecialchars($absenceRangeLabel, ENT_QUOTES, "UTF-8"); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ((int)$absencesInRange["total_absences"] > 0): ?>
                        <span class="players-absence-stat-cta">اضغط لعرض الغائبين</span>
                    <?php endif; ?>
                </button>
            </div>
        </section>

        <section class="players-attendance-grid">
            <div class="attendance-stack">
                <div class="card attendance-scan-card players-attendance-card">
                        <div class="card-head">
                            <div>
                                <h3>تسجيل الحضور</h3>
                            </div>
                            <button type="button" class="btn btn-soft" id="openSingleTrainingAttendanceModal" <?php echo count($singleTrainingDefinitions) > 0 ? "" : "disabled"; ?>>تسجيل حضور لاعب تمرينة واحدة</button>
                        </div>

                    <form method="POST" class="attendance-scan-form players-barcode-form" id="attendanceScanForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["players_attendance_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="scan_attendance">
                        <?php if ($isPentathlon): ?>
                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label for="pentathlonSubGameSelect">اللعبة <span style="color:var(--danger,red)">*</span></label>
                            <select name="pentathlon_sub_game" id="pentathlonSubGameSelect" required>
                                <option value="">-- اختر اللعبة --</option>
                                <?php foreach (PENTATHLON_SUB_GAMES as $subGame): ?>
                                    <option value="<?php echo htmlspecialchars($subGame, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($subGame, ENT_QUOTES, "UTF-8"); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="scan-input-row">
                            <div class="form-group scan-field-group">
                                <label for="barcodeInput">باركود اللاعب</label>
                                <input type="text" name="barcode" id="barcodeInput" autocomplete="off" autocapitalize="off" spellcheck="false" autofocus required>
                            </div>
                            <button type="submit" class="btn btn-primary">تسجيل</button>
                            <button type="button" class="btn btn-soft camera-scan-mobile-only" id="openCameraScanner" hidden aria-hidden="true">قراءة الباركود</button>
                        </div>
                    </form>
                </div>

                <div class="card attendance-filter-card players-attendance-card">
                    <div class="card-head">
                        <div>
                            <h3>فلاتر السجل</h3>
                        </div>
                    </div>

                    <form method="GET" class="attendance-filter-form">
                        <div class="attendance-filter-grid">
                            <div class="form-group">
                                <label for="dateFrom">من تاريخ</label>
                                <input type="date" name="date_from" id="dateFrom" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="dateTo">إلى تاريخ</label>
                                <input type="date" name="date_to" id="dateTo" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="groupFilter">المجموعة</label>
                                <select name="group_id" id="groupFilter">
                                    <option value="0">كل المجموعات</option>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo (int)$group["id"]; ?>" <?php echo $selectedGroupId === (int)$group["id"] ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($group["group_name"] . " — " . $group["group_level"], ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="statusFilter">الحالة</label>
                                <select name="status" id="statusFilter">
                                    <option value="">الكل</option>
                                    <option value="<?php echo htmlspecialchars(PLAYER_ATTENDANCE_STATUS_PRESENT, ENT_QUOTES, "UTF-8"); ?>" <?php echo $statusFilter === PLAYER_ATTENDANCE_STATUS_PRESENT ? "selected" : ""; ?>>حضور</option>
                                    <option value="<?php echo htmlspecialchars(PLAYER_ATTENDANCE_STATUS_ABSENT, ENT_QUOTES, "UTF-8"); ?>" <?php echo $statusFilter === PLAYER_ATTENDANCE_STATUS_ABSENT ? "selected" : ""; ?>>غياب</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filterLevel">📊 مستوى المجموعة</label>
                                <select name="filter_level" id="filterLevel">
                                    <option value="">الكل</option>
                                    <?php foreach ($availableLevels as $level): ?>
                                        <option value="<?php echo htmlspecialchars($level, ENT_QUOTES, "UTF-8"); ?>" <?php echo $filterLevel === $level ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($level, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filterDaysCount">📅 عدد أيام التمرين أسبوعياً</label>
                                <select name="filter_days_count" id="filterDaysCount">
                                    <option value="">الكل</option>
                                    <?php foreach ($availableDaysCounts as $daysCount): ?>
                                        <option value="<?php echo (int)$daysCount; ?>" <?php echo ($filterDaysCount !== null && $filterDaysCount === (int)$daysCount) ? "selected" : ""; ?>>
                                            <?php echo (int)$daysCount; ?> أيام
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filterTrainingTime">⏰ ميعاد التمرين</label>
                                <select name="filter_time" id="filterTrainingTime">
                                    <option value="">الكل</option>
                                    <?php foreach ($availableTimes as $trainingTimeOption): ?>
                                        <option value="<?php echo htmlspecialchars(substr($trainingTimeOption, 0, 5), ENT_QUOTES, "UTF-8"); ?>" <?php echo $filterTime === $trainingTimeOption ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars(formatTrainingTimeDisplay($trainingTimeOption), ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filterTrainer">🧑‍🏫 المدرب</label>
                                <select name="filter_trainer" id="filterTrainer">
                                    <option value="">الكل</option>
                                    <?php foreach ($availableTrainers as $trainerName): ?>
                                        <option value="<?php echo htmlspecialchars($trainerName, ENT_QUOTES, "UTF-8"); ?>" <?php echo $filterTrainer === $trainerName ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($trainerName, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filterGroupStatus">📌 حالة المجموعة</label>
                                <select name="filter_group_status" id="filterGroupStatus">
                                    <option value="">الكل</option>
                                    <option value="complete" <?php echo $filterGroupStatus === "complete" ? "selected" : ""; ?>>مكتملة</option>
                                    <option value="incomplete" <?php echo $filterGroupStatus === "incomplete" ? "selected" : ""; ?>>غير مكتملة</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="timeFrom">من الساعة</label>
                                <input type="time" name="time_from" id="timeFrom" value="<?php echo htmlspecialchars($timeFrom === "" ? "" : substr($timeFrom, 0, 5), ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="timeTo">إلى الساعة</label>
                                <input type="time" name="time_to" id="timeTo" value="<?php echo htmlspecialchars($timeTo === "" ? "" : substr($timeTo, 0, 5), ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="searchAttendance">بحث</label>
                                <input type="search" name="search" id="searchAttendance" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, "UTF-8"); ?>" placeholder="الاسم أو الباركود أو الهاتف">
                            </div>
                            <div class="form-group form-group-full">
                                <label>🗓️ أيام محددة</label>
                                <div class="players-attendance-days-filter">
                                    <?php foreach (PLAYER_DAY_OPTIONS as $dayKey => $dayLabel): ?>
                                        <label>
                                            <input type="checkbox" name="filter_days[]" value="<?php echo htmlspecialchars($dayKey, ENT_QUOTES, "UTF-8"); ?>" <?php echo in_array($dayKey, $filterDays, true) ? "checked" : ""; ?>>
                                            <?php echo htmlspecialchars($dayLabel, ENT_QUOTES, "UTF-8"); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض</button>
                            <a href="players_attendance.php?<?php echo htmlspecialchars($exportQuery, ENT_QUOTES, "UTF-8"); ?>" class="btn btn-warning">تصدير Excel</a>
                            <a href="players_attendance.php" class="btn btn-soft">إعادة ضبط</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card attendance-table-card players-attendance-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>جدول الحضور</h3>
                    </div>
                    <span class="table-counter"><?php echo count($records); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table attendance-table">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>وقت التسجيل</th>
                                <th>الحالة</th>
                                <?php if ($isPentathlon): ?>
                                    <th>اللعبة</th>
                                <?php endif; ?>
                                <th>باركود اللاعب</th>
                                <th>اسم اللاعب</th>
                                <th>رقم الهاتف</th>
                                <th>التصنيف</th>
                                <th>المجموعة</th>
                                <th>أيام التمرين</th>
                                <th>المدرب</th>
                                <th>تاريخ البداية</th>
                                <th>تاريخ النهاية</th>
                                <th>الأيام المتبقية</th>
                                <th>التمرينات المتبقية</th>
                                <th>سعر الاشتراك</th>
                                <th>المدفوع</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) === 0): ?>
                                <tr>
                                    <td colspan="<?php echo 16 + ($isPentathlon ? 1 : 0); ?>" class="empty-cell">لا توجد سجلات حضور مطابقة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td data-label="التاريخ"><?php echo htmlspecialchars((string)$record["attendance_date"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="وقت التسجيل"><?php echo htmlspecialchars(formatPlayerAttendanceActualTime($record["attendance_at"] ?? ""), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الحالة">
                                            <span class="status-chip <?php echo htmlspecialchars(formatPlayerAttendanceStatusBadgeClass($record["attendance_status"] ?? ""), ENT_QUOTES, "UTF-8"); ?>">
                                                <?php echo htmlspecialchars(formatPlayerAttendanceStatusLabel($record), ENT_QUOTES, "UTF-8"); ?>
                                            </span>
                                        </td>
                                        <?php if ($isPentathlon): ?>
                                            <td data-label="اللعبة">
                                                <?php
                                                $recSubGame = trim((string)($record["pentathlon_sub_game"] ?? ""));
                                                echo $recSubGame !== "" ? htmlspecialchars($recSubGame, ENT_QUOTES, "UTF-8") : PLAYER_ATTENDANCE_EMPTY_VALUE;
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                        <td data-label="باركود اللاعب"><?php echo htmlspecialchars(trim((string)($record["barcode"] ?? "")) !== "" ? (string)$record["barcode"] : PLAYER_ATTENDANCE_EMPTY_VALUE, ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="اسم اللاعب"><strong><?php echo htmlspecialchars((string)$record["name"], ENT_QUOTES, "UTF-8"); ?></strong></td>
                                        <td data-label="رقم الهاتف"><?php echo htmlspecialchars((string)$record["phone"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="التصنيف"><?php echo htmlspecialchars((string)$record["player_category"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="المجموعة">
                                            <div class="players-table-stack">
                                                <strong><?php echo htmlspecialchars((string)$record["group_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                                <span><?php echo htmlspecialchars((string)$record["group_level"], ENT_QUOTES, "UTF-8"); ?></span>
                                            </div>
                                        </td>
                                        <td data-label="أيام التمرين">
                                            <div class="table-badges">
                                                <?php foreach (($record["training_day_labels"] ?? []) as $dayLabel): ?>
                                                    <span class="badge"><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, "UTF-8"); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td data-label="المدرب"><?php echo htmlspecialchars((string)$record["trainer_name"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="تاريخ البداية"><?php echo htmlspecialchars((string)$record["subscription_start_date"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="تاريخ النهاية"><?php echo htmlspecialchars((string)$record["subscription_end_date"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الأيام المتبقية"><?php echo (int)$record["days_remaining"]; ?></td>
                                        <td data-label="التمرينات المتبقية">
                                            <div class="players-table-stack">
                                                <strong><?php echo (int)$record["remaining_trainings"]; ?></strong>
                                                <span>حضور: <?php echo (int)$record["attendance_count"]; ?></span>
                                            </div>
                                        </td>
                                        <td data-label="سعر الاشتراك"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($record["subscription_price"] ?? 0), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="المدفوع"><?php echo htmlspecialchars(formatPlayerCurrencyLabel($record["paid_amount"] ?? 0), ENT_QUOTES, "UTF-8"); ?></td>
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
<div class="player-modal-overlay" id="absenceListModalOverlay" role="dialog" aria-modal="true" aria-labelledby="absenceListModalTitle">
    <div class="card player-modal-card players-absence-modal-card">
        <div class="card-head player-modal-head">
            <div>
                <h3 id="absenceListModalTitle">قائمة الغائبين في الفترة المحددة</h3>
            </div>
            <div class="player-modal-actions">
                <button type="button" class="btn btn-danger" id="closeAbsenceListModal">إغلاق</button>
            </div>
        </div>

        <div class="players-absence-modal-meta">
            <span>الفترة: <b><?php echo htmlspecialchars($absenceRangeLabel, ENT_QUOTES, "UTF-8"); ?></b></span>
            <span>إجمالي أيام الغياب: <b><?php echo (int)$absencesInRange["total_absences"]; ?></b></span>
            <span>عدد اللاعبين: <b><?php echo (int)$absencesInRange["unique_players"]; ?></b></span>
            <?php if ($hasEndedDaysInRange && (string)$absencesInRange["effective_to"] !== (string)$dateTo): ?>
                <span>الأيام المحتسبة حتى: <b><?php echo htmlspecialchars($absencesInRange["effective_to"], ENT_QUOTES, "UTF-8"); ?></b></span>
            <?php endif; ?>
        </div>

        <div class="players-absence-modal-body">
            <?php if (count($absencesInRange["entries"]) === 0): ?>
                <div class="players-absence-empty">
                    <?php if (!$hasEndedDaysInRange): ?>
                        لم تنتهِ أي أيام من الفترة المحددة بعد، لذلك لا يوجد غياب لعرضه.
                    <?php else: ?>
                        لا يوجد غياب في الفترة المحددة. ✅
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table class="players-absence-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم اللاعب</th>
                            <th>الباركود</th>
                            <th>الهاتف</th>
                            <th>المجموعة</th>
                            <th>أيام التمرين</th>
                            <th>عدد أيام الغياب</th>
                            <th>تواريخ الغياب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $absenceRowIndex = 0; foreach ($absencesInRange["entries"] as $absentEntry): $absenceRowIndex++; ?>
                            <tr>
                                <td><?php echo $absenceRowIndex; ?></td>
                                <td><?php echo htmlspecialchars((string)$absentEntry["name"], ENT_QUOTES, "UTF-8"); ?></td>
                                <td><?php echo htmlspecialchars((string)$absentEntry["barcode"], ENT_QUOTES, "UTF-8"); ?></td>
                                <td><?php echo htmlspecialchars((string)$absentEntry["phone"], ENT_QUOTES, "UTF-8"); ?></td>
                                <td><?php echo htmlspecialchars((string)$absentEntry["group_name"], ENT_QUOTES, "UTF-8"); ?></td>
                                <td><?php echo htmlspecialchars(implode(" - ", (array)($absentEntry["training_day_labels"] ?? [])), ENT_QUOTES, "UTF-8"); ?></td>
                                <td><strong><?php echo (int)count($absentEntry["dates"]); ?></strong></td>
                                <td>
                                    <?php foreach ($absentEntry["dates"] as $absentDate): ?>
                                        <span class="players-absence-date-chip"><?php echo htmlspecialchars((string)$absentDate, ENT_QUOTES, "UTF-8"); ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="player-modal-overlay<?php echo $shouldOpenSingleTrainingModal ? " is-visible" : ""; ?>" id="singleTrainingAttendanceModalOverlay">
    <div class="card player-modal-card single-training-attendance-modal-card">
        <div class="card-head player-modal-head">
            <div>
                <h3>تسجيل حضور لاعب تمرينة واحدة</h3>
            </div>
            <div class="player-modal-actions">
                <a href="single_training.php" class="btn btn-soft">إدارة الأسعار</a>
                <button type="button" class="btn btn-danger" id="closeSingleTrainingAttendanceModal">إغلاق</button>
            </div>
        </div>

        <form method="POST" class="login-form" id="singleTrainingAttendanceForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["players_attendance_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
            <input type="hidden" name="action" value="add_single_training_attendance">

            <div class="single-training-attendance-form-grid">
                <div class="form-group">
                    <label for="single_training_player_name">اسم اللاعب</label>
                    <input type="text" name="player_name" id="single_training_player_name" value="<?php echo htmlspecialchars($singleTrainingAttendanceFormData["player_name"], ENT_QUOTES, "UTF-8"); ?>" maxlength="150" required>
                </div>
                <div class="form-group">
                    <label for="single_training_player_phone">رقم هاتف اللاعب</label>
                    <input type="text" name="player_phone" id="single_training_player_phone" value="<?php echo htmlspecialchars($singleTrainingAttendanceFormData["player_phone"], ENT_QUOTES, "UTF-8"); ?>" maxlength="30" inputmode="tel" required>
                </div>
                <div class="form-group">
                    <label for="singleTrainingId">التمرين</label>
                    <select name="single_training_id" id="singleTrainingId" <?php echo count($singleTrainingDefinitions) > 0 ? "required" : "disabled"; ?>>
                        <option value="">اختر التمرين</option>
                        <?php foreach ($singleTrainingDefinitions as $training): ?>
                            <option
                                value="<?php echo (int)$training["id"]; ?>"
                                data-price-raw="<?php echo htmlspecialchars(formatPlayerCurrency($training["price"]), ENT_QUOTES, "UTF-8"); ?>"
                                data-price-label="<?php echo htmlspecialchars(formatPlayerCurrencyLabel($training["price"]), ENT_QUOTES, "UTF-8"); ?>"
                                <?php echo $selectedSingleTrainingId === (int)$training["id"] ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars($training["training_name"], ENT_QUOTES, "UTF-8"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>سعر التمرينة المختارة</label>
                    <div class="single-training-attendance-price-preview<?php echo $selectedSingleTrainingPriceLabel === "" ? " is-empty" : ""; ?>" id="singleTrainingPricePreview"><?php echo htmlspecialchars($selectedSingleTrainingPriceLabel !== "" ? $selectedSingleTrainingPriceLabel : "اختر التمرين", ENT_QUOTES, "UTF-8"); ?></div>
                </div>
                <div class="form-group">
                    <label for="singleTrainingPaidAmount">المدفوع</label>
                    <input type="number" name="paid_amount" id="singleTrainingPaidAmount" value="<?php echo htmlspecialchars($singleTrainingAttendanceFormData["paid_amount"] !== "" ? $singleTrainingAttendanceFormData["paid_amount"] : $selectedSingleTrainingPrice, ENT_QUOTES, "UTF-8"); ?>" min="0.01" step="0.01" inputmode="decimal" required>
                </div>
            </div>

            <div class="player-modal-actions" style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary" <?php echo count($singleTrainingDefinitions) > 0 ? "" : "disabled"; ?>>حفظ الحضور</button>
            </div>
        </form>
    </div>
</div>
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
    // Keep the JS mobile-scanner breakpoint aligned with the CSS mobile/tablet breakpoint.
    const MAX_PHONE_SCREEN_WIDTH = 768;
    const MOBILE_SCANNER_MEDIA_QUERY = "(max-width: " + MAX_PHONE_SCREEN_WIDTH + "px) and (pointer: coarse) and (hover: none)";
    const PHONE_USER_AGENT_PATTERN = /Android|webOS|iPhone|iPod|BlackBerry|Windows Phone|IEMobile|Opera Mini/i;
    const TABLET_USER_AGENT_PATTERN = /iPad|Tablet|PlayBook|Silk/i;
    const RESIZE_VISIBILITY_DEBOUNCE_MS = 150;
    const BARCODE_SCAN_INTERVAL_MS = 500;
    const BARCODE_SCANNER_FORMATS = ["code_128", "ean_13", "ean_8", "qr_code", "upc_a", "upc_e"];
    const barcodeInput = document.getElementById("barcodeInput");
    const scanForm = document.getElementById("attendanceScanForm");
    const openCameraScannerButton = document.getElementById("openCameraScanner");
    const closeCameraScannerButton = document.getElementById("closeCameraScanner");
    const singleTrainingModalOverlay = document.getElementById("singleTrainingAttendanceModalOverlay");
    const openSingleTrainingModalButton = document.getElementById("openSingleTrainingAttendanceModal");
    const closeSingleTrainingModalButton = document.getElementById("closeSingleTrainingAttendanceModal");
    const singleTrainingAttendanceForm = document.getElementById("singleTrainingAttendanceForm");
    const singleTrainingSelect = document.getElementById("singleTrainingId");
    const singleTrainingPricePreview = document.getElementById("singleTrainingPricePreview");
    const singleTrainingPaidAmountInput = document.getElementById("singleTrainingPaidAmount");
    const singleTrainingPlayerNameInput = document.getElementById("single_training_player_name");
    const cameraModal = document.getElementById("cameraModal");
    const cameraModalBackdrop = document.getElementById("cameraModalBackdrop");
    const cameraVideo = document.getElementById("cameraVideo");
    let cameraStream = null;
    let detectorInterval = null;
    let resizeVisibilityTimeout = null;
    let hasBarcodeDetectionError = false;
    let isSubmittingScanForm = false;
    let barcodeDetector = null;

    if (barcodeInput) {
        barcodeInput.focus();
    }

    const openSingleTrainingModal = function () {
        if (!singleTrainingModalOverlay) {
            return;
        }
        singleTrainingModalOverlay.classList.add("is-visible");
        if (singleTrainingPlayerNameInput) {
            window.setTimeout(function () {
                singleTrainingPlayerNameInput.focus();
            }, 50);
        }
    };

    const closeSingleTrainingModal = function () {
        if (!singleTrainingModalOverlay) {
            return;
        }
        singleTrainingModalOverlay.classList.remove("is-visible");
        if (barcodeInput) {
            barcodeInput.focus();
        }
    };

    const updateSingleTrainingPrice = function () {
        if (!singleTrainingSelect || !singleTrainingPricePreview || !singleTrainingPaidAmountInput) {
            return;
        }

        const selectedOption = singleTrainingSelect.options[singleTrainingSelect.selectedIndex];
        const selectedPriceLabel = selectedOption ? selectedOption.getAttribute("data-price-label") || "" : "";
        const selectedPriceRaw = selectedOption ? selectedOption.getAttribute("data-price-raw") || "" : "";

        if (selectedPriceLabel === "") {
            singleTrainingPricePreview.textContent = "اختر التمرين";
            singleTrainingPricePreview.classList.add("is-empty");
        } else {
            singleTrainingPricePreview.textContent = selectedPriceLabel;
            singleTrainingPricePreview.classList.remove("is-empty");
        }

        if (selectedPriceRaw !== "" && (singleTrainingPaidAmountInput.value === "" || singleTrainingPaidAmountInput.value === "<?php echo htmlspecialchars($selectedSingleTrainingPrice, ENT_QUOTES, "UTF-8"); ?>")) {
            singleTrainingPaidAmountInput.value = selectedPriceRaw;
        }
    };

    if (openSingleTrainingModalButton) {
        openSingleTrainingModalButton.addEventListener("click", openSingleTrainingModal);
    }

    if (closeSingleTrainingModalButton) {
        closeSingleTrainingModalButton.addEventListener("click", closeSingleTrainingModal);
    }

    if (singleTrainingModalOverlay) {
        singleTrainingModalOverlay.addEventListener("click", function (event) {
            if (event.target === singleTrainingModalOverlay) {
                closeSingleTrainingModal();
            }
        });
    }

    if (singleTrainingSelect) {
        singleTrainingSelect.addEventListener("change", updateSingleTrainingPrice);
        updateSingleTrainingPrice();
    }

    if (singleTrainingAttendanceForm && singleTrainingSelect && singleTrainingPaidAmountInput) {
        singleTrainingAttendanceForm.addEventListener("submit", function (event) {
            const selectedOption = singleTrainingSelect.options[singleTrainingSelect.selectedIndex];
            const selectedPriceRaw = selectedOption ? selectedOption.getAttribute("data-price-raw") || "" : "";
            const normalizedPaidAmount = singleTrainingPaidAmountInput.value === "" ? "" : Number(singleTrainingPaidAmountInput.value).toFixed(2);

            if (selectedPriceRaw !== "" && normalizedPaidAmount !== selectedPriceRaw) {
                event.preventDefault();
                singleTrainingPaidAmountInput.setCustomValidity("المدفوع يجب أن يساوي سعر التمرينة المختارة.");
                singleTrainingPaidAmountInput.reportValidity();
                return;
            }

            singleTrainingPaidAmountInput.setCustomValidity("");
        });

        singleTrainingPaidAmountInput.addEventListener("input", function () {
            singleTrainingPaidAmountInput.setCustomValidity("");
        });
    }

    <?php if ($shouldOpenSingleTrainingModal): ?>
    openSingleTrainingModal();
    <?php endif; ?>

    const absenceListModalOverlay = document.getElementById("absenceListModalOverlay");
    const openAbsenceListModalButton = document.getElementById("openAbsenceListModal");
    const closeAbsenceListModalButton = document.getElementById("closeAbsenceListModal");

    const openAbsenceListModal = function () {
        if (!absenceListModalOverlay) {
            return;
        }
        absenceListModalOverlay.classList.add("is-visible");
    };

    const closeAbsenceListModal = function () {
        if (!absenceListModalOverlay) {
            return;
        }
        absenceListModalOverlay.classList.remove("is-visible");
    };

    if (openAbsenceListModalButton && absenceListModalOverlay) {
        openAbsenceListModalButton.addEventListener("click", openAbsenceListModal);
    }

    if (closeAbsenceListModalButton) {
        closeAbsenceListModalButton.addEventListener("click", closeAbsenceListModal);
    }

    if (absenceListModalOverlay) {
        absenceListModalOverlay.addEventListener("click", function (event) {
            if (event.target === absenceListModalOverlay) {
                closeAbsenceListModal();
            }
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && absenceListModalOverlay.classList.contains("is-visible")) {
                closeAbsenceListModal();
            }
        });
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
            if (window.matchMedia && window.matchMedia(MOBILE_SCANNER_MEDIA_QUERY).matches) {
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

        const shouldHide = !isMobileScannerDevice() || !supportsCameraScanner();
        openCameraScannerButton.hidden = shouldHide;
        openCameraScannerButton.setAttribute("aria-hidden", shouldHide ? "true" : "false");
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
            if (!barcodeDetector) {
                barcodeDetector = new BarcodeDetector({ formats: BARCODE_SCANNER_FORMATS });
            }
        } catch (error) {
            window.alert("المتصفح لا يدعم تنسيقات الباركود المطلوبة.");
            return;
        }

        try {
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
                    const barcodes = await barcodeDetector.detect(cameraVideo);
                    if (barcodes && barcodes.length > 0 && barcodes[0].rawValue) {
                        fillBarcodeAndSubmit(barcodes[0].rawValue);
                    }
                } catch (error) {
                    if (!hasBarcodeDetectionError) {
                        console.warn("Barcode detection is temporarily unavailable:", error);
                        hasBarcodeDetectionError = true;
                    }
                }
            }, BARCODE_SCAN_INTERVAL_MS);
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
