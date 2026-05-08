<?php

const GAME_LEVEL_MAX_LENGTH = 150;
const GAME_LEVEL_DETAILS_MAX_LENGTH = 1000;
const GAME_LEVEL_INPUT_DELIMITER = '|';

function ensureGameLevelsTable(PDO $pdo)
{
    static $alreadyEnsured = false;
    if ($alreadyEnsured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS game_levels (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            level_name VARCHAR(150) NOT NULL,
            level_details TEXT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_game_level_name (game_id, level_name),
            KEY idx_game_levels_game_order (game_id, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === '') {
        return;
    }

    $columnStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'game_levels'
           AND COLUMN_NAME = 'level_details'
         LIMIT 1"
    );
    $columnStmt->execute([$databaseName]);

    if (!$columnStmt->fetchColumn()) {
        try {
            $pdo->exec(
                "ALTER TABLE game_levels
                 ADD COLUMN level_details TEXT NULL AFTER level_name"
            );
        } catch (Throwable $throwable) {
            error_log('Failed to ensure game_levels.level_details column: ' . $throwable->getMessage());
        }
    }

    $constraintStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'game_levels'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'
           AND CONSTRAINT_NAME = 'fk_game_levels_game'
         LIMIT 1"
    );
    $constraintStmt->execute([$databaseName]);

    if (!$constraintStmt->fetchColumn()) {
        try {
            $pdo->exec(
                "ALTER TABLE game_levels
                 ADD CONSTRAINT fk_game_levels_game
                 FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
            );
        } catch (Throwable $throwable) {
            error_log('Failed to ensure game_levels foreign key: ' . $throwable->getMessage());
        }
    }

    $alreadyEnsured = true;
}

function ensureGameGroupLevelsTable(PDO $pdo)
{
    static $alreadyEnsured = false;
    if ($alreadyEnsured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS game_group_levels (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            level_name VARCHAR(150) NOT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_game_group_level_name (game_id, level_name),
            KEY idx_game_group_levels_game_order (game_id, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === '') {
        return;
    }

    $constraintStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'game_group_levels'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'
           AND CONSTRAINT_NAME = 'fk_game_group_levels_game'
         LIMIT 1"
    );
    $constraintStmt->execute([$databaseName]);

    if (!$constraintStmt->fetchColumn()) {
        try {
            $pdo->exec(
                "ALTER TABLE game_group_levels
                 ADD CONSTRAINT fk_game_group_levels_game
                 FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
            );
        } catch (Throwable $throwable) {
            error_log('Failed to ensure game_group_levels foreign key: ' . $throwable->getMessage());
        }
    }

    $alreadyEnsured = true;
}

function normalizeGameLevelsInput($value)
{
    $rawValue = str_replace(["\r\n", "\r"], "\n", (string)$value);
    $lines = explode("\n", $rawValue);
    $levels = [];
    $seen = [];

    foreach ($lines as $line) {
        [$levelName] = parseGameLevelInputLine($line);
        if ($levelName === '' || mb_strlen($levelName) > GAME_LEVEL_MAX_LENGTH) {
            continue;
        }

        if (isset($seen[$levelName])) {
            continue;
        }

        $seen[$levelName] = true;
        $levels[] = $levelName;
    }

    return $levels;
}

function parseGameLevelInputLine($line)
{
    $parts = explode(GAME_LEVEL_INPUT_DELIMITER, (string)$line, 2);
    return [
        trim((string)($parts[0] ?? '')),
        trim((string)($parts[1] ?? '')),
    ];
}

function normalizeGameLevelRecordsInput($value)
{
    $rawValue = str_replace(["\r\n", "\r"], "\n", (string)$value);
    $lines = explode("\n", $rawValue);
    $levels = [];
    $seen = [];

    foreach ($lines as $line) {
        [$levelName, $levelDetails] = parseGameLevelInputLine($line);

        if ($levelName === '' || mb_strlen($levelName) > GAME_LEVEL_MAX_LENGTH) {
            continue;
        }

        if ($levelDetails !== '' && mb_strlen($levelDetails) > GAME_LEVEL_DETAILS_MAX_LENGTH) {
            continue;
        }

        if (isset($seen[$levelName])) {
            continue;
        }

        $seen[$levelName] = true;
        $levels[] = [
            'level_name' => $levelName,
            'level_details' => $levelDetails,
        ];
    }

    return $levels;
}

function formatGameLevelRecordsForTextarea(array $levels)
{
    $lines = [];

    foreach ($levels as $level) {
        $levelName = trim((string)($level['level_name'] ?? ''));
        if ($levelName === '') {
            continue;
        }

        $levelDetails = trim((string)($level['level_details'] ?? ''));
        $lines[] = $levelDetails !== ''
            ? $levelName . ' ' . GAME_LEVEL_INPUT_DELIMITER . ' ' . $levelDetails
            : $levelName;
    }

    return implode(PHP_EOL, $lines);
}

function fetchGameLevels(PDO $pdo, $gameId)
{
    ensureGameLevelsTable($pdo);

    $stmt = $pdo->prepare(
        "SELECT level_name
         FROM game_levels
         WHERE game_id = ?
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([(int)$gameId]);

    return array_values(array_filter(array_map(function ($levelName) {
        return trim((string)$levelName);
    }, $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function fetchGameLevelRecords(PDO $pdo, $gameId)
{
    ensureGameLevelsTable($pdo);

    $stmt = $pdo->prepare(
        "SELECT level_name, level_details
         FROM game_levels
         WHERE game_id = ?
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([(int)$gameId]);

    $levels = [];
    foreach ($stmt->fetchAll() as $row) {
        $levelName = trim((string)($row['level_name'] ?? ''));
        if ($levelName === '') {
            continue;
        }

        $levels[] = [
            'level_name' => $levelName,
            'level_details' => trim((string)($row['level_details'] ?? '')),
        ];
    }

    return $levels;
}

function fetchGameLevelsGrouped(PDO $pdo)
{
    $groupedRecords = fetchGameLevelRecordsGrouped($pdo);
    $levelsByGame = [];

    foreach ($groupedRecords as $gameId => $levelRecords) {
        $levelsByGame[$gameId] = array_map(function ($levelRecord) {
            return (string)($levelRecord['level_name'] ?? '');
        }, $levelRecords);
    }

    return $levelsByGame;
}

function fetchGameLevelRecordsGrouped(PDO $pdo)
{
    ensureGameLevelsTable($pdo);

    $stmt = $pdo->query(
        "SELECT game_id, level_name, level_details
         FROM game_levels
         ORDER BY game_id ASC, sort_order ASC, id ASC"
    );

    $levelsByGame = [];
    foreach ($stmt->fetchAll() as $row) {
        $gameId = (int)($row['game_id'] ?? 0);
        $levelName = trim((string)($row['level_name'] ?? ''));
        $levelDetails = trim((string)($row['level_details'] ?? ''));
        if ($gameId <= 0 || $levelName === '') {
            continue;
        }

        if (!isset($levelsByGame[$gameId])) {
            $levelsByGame[$gameId] = [];
        }
        $levelsByGame[$gameId][] = [
            'level_name' => $levelName,
            'level_details' => $levelDetails,
        ];
    }

    return $levelsByGame;
}

function fetchGameGroupLevels(PDO $pdo, $gameId)
{
    ensureGameGroupLevelsTable($pdo);

    $stmt = $pdo->prepare(
        "SELECT level_name
         FROM game_group_levels
         WHERE game_id = ?
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([(int)$gameId]);

    return array_values(array_filter(array_map(function ($levelName) {
        return trim((string)$levelName);
    }, $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function fetchGameGroupLevelsGrouped(PDO $pdo)
{
    ensureGameGroupLevelsTable($pdo);

    $stmt = $pdo->query(
        "SELECT game_id, level_name
         FROM game_group_levels
         ORDER BY game_id ASC, sort_order ASC, id ASC"
    );

    $levelsByGame = [];
    foreach ($stmt->fetchAll() as $row) {
        $gameId = (int)($row['game_id'] ?? 0);
        $levelName = trim((string)($row['level_name'] ?? ''));
        if ($gameId <= 0 || $levelName === '') {
            continue;
        }

        if (!isset($levelsByGame[$gameId])) {
            $levelsByGame[$gameId] = [];
        }
        $levelsByGame[$gameId][] = $levelName;
    }

    return $levelsByGame;
}

function saveGameLevels(PDO $pdo, $gameId, array $levels)
{
    ensureGameLevelsTable($pdo);

    $deleteStmt = $pdo->prepare("DELETE FROM game_levels WHERE game_id = ?");
    $deleteStmt->execute([(int)$gameId]);

    if (count($levels) === 0) {
        return;
    }

    $insertStmt = $pdo->prepare(
        "INSERT INTO game_levels (game_id, level_name, level_details, sort_order)
         VALUES (?, ?, ?, ?)"
    );

    foreach (array_values($levels) as $index => $level) {
        $levelName = trim((string)($level['level_name'] ?? ''));
        $levelDetails = trim((string)($level['level_details'] ?? ''));
        if ($levelName === '') {
            continue;
        }

        $insertStmt->execute([(int)$gameId, $levelName, $levelDetails, $index + 1]);
    }
}

function saveGameGroupLevels(PDO $pdo, $gameId, array $levels)
{
    ensureGameGroupLevelsTable($pdo);

    $deleteStmt = $pdo->prepare("DELETE FROM game_group_levels WHERE game_id = ?");
    $deleteStmt->execute([(int)$gameId]);

    if (count($levels) === 0) {
        return;
    }

    $insertStmt = $pdo->prepare(
        "INSERT INTO game_group_levels (game_id, level_name, sort_order)
         VALUES (?, ?, ?)"
    );

    foreach (array_values($levels) as $index => $levelName) {
        $insertStmt->execute([(int)$gameId, (string)$levelName, $index + 1]);
    }
}
