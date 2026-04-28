<?php

const GAME_LEVEL_MAX_LENGTH = 150;

function ensureGameLevelsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS game_levels (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            level_name VARCHAR(150) NOT NULL,
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
}

function normalizeGameLevelsInput($value)
{
    $rawValue = str_replace(["\r\n", "\r"], "\n", (string)$value);
    $lines = explode("\n", $rawValue);
    $levels = [];
    $seen = [];

    foreach ($lines as $line) {
        $levelName = trim($line);
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

function fetchGameLevelsGrouped(PDO $pdo)
{
    ensureGameLevelsTable($pdo);

    $stmt = $pdo->query(
        "SELECT game_id, level_name
         FROM game_levels
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
        "INSERT INTO game_levels (game_id, level_name, sort_order)
         VALUES (?, ?, ?)"
    );

    foreach (array_values($levels) as $index => $levelName) {
        $insertStmt->execute([(int)$gameId, (string)$levelName, $index + 1]);
    }
}
