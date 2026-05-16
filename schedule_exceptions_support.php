<?php
require_once __DIR__ . "/players_support.php";

const PLAYER_ATTENDANCE_STATUS_EMERGENCY_LEAVE = 'إجازة طارئة';
const TRAINER_ATTENDANCE_STATUS_EMERGENCY_LEAVE = 'إجازة طارئة';

function ensureScheduleExceptionsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS group_schedule_exceptions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            group_id INT(11) NOT NULL,
            trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            original_date DATE NOT NULL,
            original_start_time TIME NULL DEFAULT NULL,
            original_end_time TIME NULL DEFAULT NULL,
            replacement_date DATE NULL DEFAULT NULL,
            replacement_start_time TIME NULL DEFAULT NULL,
            replacement_end_time TIME NULL DEFAULT NULL,
            apply_trainer_leave TINYINT(1) NOT NULL DEFAULT 1,
            apply_players_leave TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_group_schedule_exception_original (group_id, original_date),
            KEY idx_group_schedule_exceptions_game_dates (game_id, original_date, replacement_date),
            KEY idx_group_schedule_exceptions_group (group_id),
            KEY idx_group_schedule_exceptions_trainer (trainer_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        'trainer_name' => "ALTER TABLE group_schedule_exceptions ADD COLUMN trainer_name VARCHAR(150) NOT NULL DEFAULT '' AFTER group_id",
        'original_start_time' => "ALTER TABLE group_schedule_exceptions ADD COLUMN original_start_time TIME NULL DEFAULT NULL AFTER original_date",
        'original_end_time' => "ALTER TABLE group_schedule_exceptions ADD COLUMN original_end_time TIME NULL DEFAULT NULL AFTER original_start_time",
        'replacement_date' => "ALTER TABLE group_schedule_exceptions ADD COLUMN replacement_date DATE NULL DEFAULT NULL AFTER original_end_time",
        'replacement_start_time' => "ALTER TABLE group_schedule_exceptions ADD COLUMN replacement_start_time TIME NULL DEFAULT NULL AFTER replacement_date",
        'replacement_end_time' => "ALTER TABLE group_schedule_exceptions ADD COLUMN replacement_end_time TIME NULL DEFAULT NULL AFTER replacement_start_time",
        'apply_trainer_leave' => "ALTER TABLE group_schedule_exceptions ADD COLUMN apply_trainer_leave TINYINT(1) NOT NULL DEFAULT 1 AFTER replacement_end_time",
        'apply_players_leave' => "ALTER TABLE group_schedule_exceptions ADD COLUMN apply_players_leave TINYINT(1) NOT NULL DEFAULT 1 AFTER apply_trainer_leave",
        'created_by_user_id' => "ALTER TABLE group_schedule_exceptions ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER apply_players_leave",
        'updated_by_user_id' => "ALTER TABLE group_schedule_exceptions ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        'updated_at' => "ALTER TABLE group_schedule_exceptions ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
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
        $existingColumnsStmt->execute(['group_schedule_exceptions', $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure group_schedule_exceptions.' . $columnName . ': ' . $throwable->getMessage());
            }
        }
    }

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === '') {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'group_schedule_exceptions'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), 'CONSTRAINT_NAME');

    $foreignKeys = [
        'fk_group_schedule_exceptions_game' => "ALTER TABLE group_schedule_exceptions ADD CONSTRAINT fk_group_schedule_exceptions_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE",
        'fk_group_schedule_exceptions_group' => "ALTER TABLE group_schedule_exceptions ADD CONSTRAINT fk_group_schedule_exceptions_group FOREIGN KEY (group_id) REFERENCES sports_groups (id) ON DELETE CASCADE",
        'fk_group_schedule_exceptions_created_user' => "ALTER TABLE group_schedule_exceptions ADD CONSTRAINT fk_group_schedule_exceptions_created_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
        'fk_group_schedule_exceptions_updated_user' => "ALTER TABLE group_schedule_exceptions ADD CONSTRAINT fk_group_schedule_exceptions_updated_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
    ];

    foreach ($foreignKeys as $constraintName => $sql) {
        if (!in_array($constraintName, $existingConstraints, true)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure ' . $constraintName . ': ' . $throwable->getMessage());
            }
        }
    }
}

function fetchScheduleExceptionRows(PDO $pdo, $gameId)
{
    ensureScheduleExceptionsTable($pdo);

    $stmt = $pdo->prepare(
        "SELECT gse.id, gse.game_id, gse.group_id, gse.trainer_name, gse.original_date, gse.original_start_time,
                gse.original_end_time, gse.replacement_date, gse.replacement_start_time, gse.replacement_end_time,
                gse.apply_trainer_leave, gse.apply_players_leave, gse.created_at, gse.updated_at,
                sg.group_name, sg.group_level
         FROM group_schedule_exceptions gse
         INNER JOIN sports_groups sg ON sg.id = gse.group_id
         WHERE gse.game_id = ?
         ORDER BY gse.original_date DESC, gse.id DESC"
    );
    $stmt->execute([(int)$gameId]);
    return $stmt->fetchAll();
}

function buildGroupScheduleExceptionMap(array $rows)
{
    $map = [];
    foreach ($rows as $row) {
        $groupId = (int)($row['group_id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }

        if (!isset($map[$groupId])) {
            $map[$groupId] = [
                'original_dates' => [],
                'replacement_dates' => [],
                'rows' => [],
            ];
        }

        $originalDate = trim((string)($row['original_date'] ?? ''));
        if ($originalDate !== '') {
            $map[$groupId]['original_dates'][$originalDate] = $row;
        }

        $replacementDate = trim((string)($row['replacement_date'] ?? ''));
        if ($replacementDate !== '') {
            $map[$groupId]['replacement_dates'][$replacementDate] = $row;
        }

        $map[$groupId]['rows'][] = $row;
    }

    return $map;
}

function buildTrainerScheduleExceptionMap(array $rows)
{
    $map = [];
    foreach ($rows as $row) {
        $trainerName = trim((string)($row['trainer_name'] ?? ''));
        if ($trainerName === '') {
            continue;
        }

        if (!isset($map[$trainerName])) {
            $map[$trainerName] = [
                'original_dates' => [],
                'replacement_dates' => [],
                'rows' => [],
            ];
        }

        $originalDate = trim((string)($row['original_date'] ?? ''));
        if ($originalDate !== '') {
            $map[$trainerName]['original_dates'][$originalDate] = $row;
        }

        $replacementDate = trim((string)($row['replacement_date'] ?? ''));
        if ($replacementDate !== '') {
            $map[$trainerName]['replacement_dates'][$replacementDate] = $row;
        }

        $map[$trainerName]['rows'][] = $row;
    }

    return $map;
}

function getGroupScheduleExceptionForDate(array $groupScheduleData, DateTimeImmutable $date)
{
    $dateKey = $date->format('Y-m-d');
    $exceptionMap = $groupScheduleData['exception_dates'] ?? [];
    if (!is_array($exceptionMap)) {
        return ['type' => '', 'row' => null];
    }

    if (isset($exceptionMap['replacement_dates'][$dateKey])) {
        return ['type' => 'replacement', 'row' => $exceptionMap['replacement_dates'][$dateKey]];
    }

    if (isset($exceptionMap['original_dates'][$dateKey])) {
        return ['type' => 'original', 'row' => $exceptionMap['original_dates'][$dateKey]];
    }

    return ['type' => '', 'row' => null];
}

function resolveGroupScheduleForDate(array $groupScheduleData, DateTimeImmutable $date)
{
    $dayKey = strtolower($date->format('l'));
    $trainingDayKeys = $groupScheduleData['training_day_keys_list'] ?? [];
    $trainingDayTimes = $groupScheduleData['training_day_times_map'] ?? [];

    $resolved = [
        'is_scheduled' => in_array($dayKey, $trainingDayKeys, true),
        'time' => normalizeTrainingTimeValue($trainingDayTimes[$dayKey] ?? ''),
        'type' => 'default',
        'row' => null,
    ];

    $exception = getGroupScheduleExceptionForDate($groupScheduleData, $date);
    if ($exception['type'] === 'replacement' && is_array($exception['row'])) {
        $resolved['is_scheduled'] = true;
        $resolved['time'] = normalizeTrainingTimeValue($exception['row']['replacement_start_time'] ?? '');
        $resolved['type'] = 'replacement';
        $resolved['row'] = $exception['row'];
        return $resolved;
    }

    if ($exception['type'] === 'original' && is_array($exception['row']) && (int)($exception['row']['apply_players_leave'] ?? 0) === 1) {
        $resolved['is_scheduled'] = false;
        $resolved['time'] = '';
        $resolved['type'] = 'leave';
        $resolved['row'] = $exception['row'];
        return $resolved;
    }

    if ($resolved['time'] === '') {
        $resolved['time'] = normalizeTrainingTimeValue($groupScheduleData['training_time'] ?? '');
    }

    return $resolved;
}

function resolveTrainerEmergencyScheduleForDate(array $trainer, DateTimeImmutable $date)
{
    $dateKey = $date->format('Y-m-d');
    $exceptionMap = $trainer['exception_dates'] ?? [];
    if (is_array($exceptionMap) && isset($exceptionMap['replacement_dates'][$dateKey])) {
        $row = $exceptionMap['replacement_dates'][$dateKey];
        return [
            'type' => 'replacement',
            'is_leave' => false,
            'row' => $row,
            'schedule' => [
                'attendance_time' => normalizeTrainingTimeValue($row['replacement_start_time'] ?? ''),
                'departure_time' => normalizeTrainingTimeValue($row['replacement_end_time'] ?? ''),
            ],
        ];
    }

    if (is_array($exceptionMap) && isset($exceptionMap['original_dates'][$dateKey])) {
        $row = $exceptionMap['original_dates'][$dateKey];
        if ((int)($row['apply_trainer_leave'] ?? 0) === 1) {
            return [
                'type' => 'leave',
                'is_leave' => true,
                'row' => $row,
                'schedule' => [
                    'attendance_time' => normalizeTrainingTimeValue($row['original_start_time'] ?? ''),
                    'departure_time' => normalizeTrainingTimeValue($row['original_end_time'] ?? ''),
                ],
            ];
        }
    }

    return [
        'type' => 'default',
        'is_leave' => false,
        'row' => null,
        'schedule' => null,
    ];
}

function formatScheduleExceptionDateLabel($value)
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') {
        return '—';
    }

    try {
        $dateTime = new DateTimeImmutable($value, new DateTimeZone('Africa/Cairo'));
        return $dateTime->format('Y/m/d');
    } catch (Throwable $throwable) {
        return '—';
    }
}

function formatScheduleExceptionTimeLabel($value)
{
    return formatEgyptTimeForDisplay($value, '—');
}
