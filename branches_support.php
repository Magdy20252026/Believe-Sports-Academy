<?php

const BRANCH_DEFAULT_NAMES = ["دار المشاه", "المعادي"];

function ensureBranchSchema(PDO $pdo)
{
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    $databaseName = "";
    try {
        $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    } catch (PDOException $e) {
        error_log("ensureBranchSchema DATABASE() failed: " . $e->getMessage());
    }
    if ($databaseName === "") {
        return;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS branches (
                id INT(11) NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_branch_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (PDOException $e) {
        error_log("ensureBranchSchema branches table failed: " . $e->getMessage());
    }

    try {
        $insertBranchStmt = $pdo->prepare("INSERT IGNORE INTO branches (name, status) VALUES (?, 1)");
        foreach (BRANCH_DEFAULT_NAMES as $branchName) {
            $insertBranchStmt->execute([$branchName]);
        }
    } catch (PDOException $e) {
        error_log("ensureBranchSchema seed branches failed: " . $e->getMessage());
    }

    $branchesById = [];
    $branchesByName = [];
    try {
        foreach ($pdo->query("SELECT id, name FROM branches ORDER BY id ASC")->fetchAll() as $branchRow) {
            $branchesById[(int)$branchRow["id"]] = $branchRow["name"];
            $branchesByName[$branchRow["name"]] = (int)$branchRow["id"];
        }
    } catch (PDOException $e) {
        error_log("ensureBranchSchema fetch branches failed: " . $e->getMessage());
    }

    $primaryBranchId = (int)($branchesByName[BRANCH_DEFAULT_NAMES[0]] ?? 0);
    $secondaryBranchId = (int)($branchesByName[BRANCH_DEFAULT_NAMES[1]] ?? 0);
    if ($primaryBranchId <= 0 && count($branchesById) > 0) {
        $primaryBranchId = (int)(array_key_first($branchesById));
    }

    try {
        $hasGamesBranchColStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'games' AND COLUMN_NAME = 'branch_id'"
        );
        $hasGamesBranchColStmt->execute([$databaseName]);
        if ((int)$hasGamesBranchColStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE games ADD COLUMN branch_id INT(11) DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log("ensureBranchSchema add games.branch_id failed: " . $e->getMessage());
    }

    try {
        $hasGamesBranchIdxStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'games' AND INDEX_NAME = 'idx_games_branch'"
        );
        $hasGamesBranchIdxStmt->execute([$databaseName]);
        if ((int)$hasGamesBranchIdxStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE games ADD KEY idx_games_branch (branch_id)");
        }
    } catch (PDOException $e) {
        error_log("ensureBranchSchema add games index failed: " . $e->getMessage());
    }

    if ($primaryBranchId > 0) {
        try {
            $migrateGamesStmt = $pdo->prepare("UPDATE games SET branch_id = ? WHERE branch_id IS NULL OR branch_id = 0");
            $migrateGamesStmt->execute([$primaryBranchId]);
        } catch (PDOException $e) {
            error_log("ensureBranchSchema migrate games to primary failed: " . $e->getMessage());
        }
    }

    try {
        $uniqueOnNameStmt = $pdo->prepare(
            "SELECT DISTINCT s.INDEX_NAME
             FROM information_schema.STATISTICS s
             WHERE s.TABLE_SCHEMA = ?
               AND s.TABLE_NAME = 'games'
               AND s.NON_UNIQUE = 0
               AND s.INDEX_NAME <> 'PRIMARY'
               AND s.INDEX_NAME IN (
                   SELECT s2.INDEX_NAME
                   FROM information_schema.STATISTICS s2
                   WHERE s2.TABLE_SCHEMA = ?
                     AND s2.TABLE_NAME = 'games'
                     AND s2.COLUMN_NAME = 'name'
               )
               AND s.INDEX_NAME NOT IN (
                   SELECT s3.INDEX_NAME
                   FROM information_schema.STATISTICS s3
                   WHERE s3.TABLE_SCHEMA = ?
                     AND s3.TABLE_NAME = 'games'
                     AND s3.COLUMN_NAME = 'branch_id'
               )"
        );
        $uniqueOnNameStmt->execute([$databaseName, $databaseName, $databaseName]);
        foreach ($uniqueOnNameStmt->fetchAll(PDO::FETCH_COLUMN) as $idxName) {
            try {
                $pdo->exec("ALTER TABLE games DROP INDEX `" . str_replace("`", "", $idxName) . "`");
            } catch (PDOException $e) {
                error_log("ensureBranchSchema drop games unique-name index failed (" . $idxName . "): " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("ensureBranchSchema scan games unique indexes failed: " . $e->getMessage());
    }

    try {
        $hasComboIdxStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'games' AND INDEX_NAME = 'uniq_games_branch_name'"
        );
        $hasComboIdxStmt->execute([$databaseName]);
        if ((int)$hasComboIdxStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE games ADD UNIQUE KEY uniq_games_branch_name (branch_id, name)");
        }
    } catch (PDOException $e) {
        error_log("ensureBranchSchema add games composite unique failed: " . $e->getMessage());
    }

    if ($primaryBranchId > 0 && $secondaryBranchId > 0) {
        try {
            $existingSecondaryStmt = $pdo->prepare("SELECT name FROM games WHERE branch_id = ?");
            $existingSecondaryStmt->execute([$secondaryBranchId]);
            $existingSecondaryNames = array_column($existingSecondaryStmt->fetchAll(), "name");

            $primaryGamesStmt = $pdo->prepare("SELECT name, status FROM games WHERE branch_id = ? ORDER BY id ASC");
            $primaryGamesStmt->execute([$primaryBranchId]);
            $insertGameStmt = $pdo->prepare("INSERT INTO games (name, status, branch_id) VALUES (?, ?, ?)");
            foreach ($primaryGamesStmt->fetchAll() as $primaryGame) {
                $name = (string)$primaryGame["name"];
                if ($name !== "" && !in_array($name, $existingSecondaryNames, true)) {
                    $insertGameStmt->execute([$name, (int)$primaryGame["status"], $secondaryBranchId]);
                    $existingSecondaryNames[] = $name;
                }
            }
        } catch (PDOException $e) {
            error_log("ensureBranchSchema duplicate games failed: " . $e->getMessage());
        }
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_branches (
                id INT(11) NOT NULL AUTO_INCREMENT,
                user_id INT(11) NOT NULL,
                branch_id INT(11) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_branch (user_id, branch_id),
                KEY idx_user_branches_user (user_id),
                KEY idx_user_branches_branch (branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (PDOException $e) {
        error_log("ensureBranchSchema user_branches table failed: " . $e->getMessage());
    }

    try {
        $hasUsersBranchAccessColStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_access_all_branches'"
        );
        $hasUsersBranchAccessColStmt->execute([$databaseName]);
        if ((int)$hasUsersBranchAccessColStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN can_access_all_branches TINYINT(1) NOT NULL DEFAULT 0");
            try {
                $pdo->exec("UPDATE users SET can_access_all_branches = can_access_all_games");
            } catch (PDOException $e) {
                error_log("ensureBranchSchema backfill can_access_all_branches failed: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("ensureBranchSchema add users.can_access_all_branches failed: " . $e->getMessage());
    }

    try {
        $pdo->exec(
            "INSERT IGNORE INTO user_branches (user_id, branch_id)
             SELECT DISTINCT ug.user_id, g.branch_id
             FROM user_games ug
             INNER JOIN games g ON g.id = ug.game_id
             WHERE g.branch_id IS NOT NULL"
        );
    } catch (PDOException $e) {
        error_log("ensureBranchSchema backfill user_branches failed: " . $e->getMessage());
    }
}

function getAllActiveBranches(PDO $pdo)
{
    try {
        $stmt = $pdo->query("SELECT id, name FROM branches WHERE status = 1 ORDER BY id ASC");
        return $stmt->fetchAll();
    } catch (PDOException $exception) {
        return [];
    }
}

function getBranchesForUser(PDO $pdo, array $user)
{
    if ((int)($user["can_access_all_branches"] ?? 0) === 1) {
        return getAllActiveBranches($pdo);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT b.id, b.name
             FROM branches b
             INNER JOIN user_branches ub ON ub.branch_id = b.id
             WHERE ub.user_id = ? AND b.status = 1
             ORDER BY b.id ASC"
        );
        $stmt->execute([(int)($user["id"] ?? 0)]);
        return $stmt->fetchAll();
    } catch (PDOException $exception) {
        return [];
    }
}

function getGamesForBranch(PDO $pdo, $branchId)
{
    try {
        $stmt = $pdo->prepare(
            "SELECT id, name FROM games WHERE branch_id = ? AND status = 1 ORDER BY id ASC"
        );
        $stmt->execute([(int)$branchId]);
        return $stmt->fetchAll();
    } catch (PDOException $exception) {
        return [];
    }
}

function getGamesForUserInBranch(PDO $pdo, array $user, $branchId)
{
    if ((int)($user["can_access_all_games"] ?? 0) === 1) {
        return getGamesForBranch($pdo, $branchId);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT g.id, g.name
             FROM games g
             INNER JOIN user_games ug ON ug.game_id = g.id
             WHERE ug.user_id = ? AND g.branch_id = ? AND g.status = 1
             ORDER BY g.id ASC"
        );
        $stmt->execute([(int)($user["id"] ?? 0), (int)$branchId]);
        return $stmt->fetchAll();
    } catch (PDOException $exception) {
        return [];
    }
}

function getAllGamesGroupedByBranch(PDO $pdo)
{
    try {
        $stmt = $pdo->query(
            "SELECT g.id, g.name, g.branch_id, b.name AS branch_name
             FROM games g
             LEFT JOIN branches b ON b.id = g.branch_id
             WHERE g.status = 1
             ORDER BY g.branch_id ASC, g.id ASC"
        );
        $rows = $stmt->fetchAll();
    } catch (PDOException $exception) {
        return [];
    }

    $grouped = [];
    foreach ($rows as $row) {
        $branchId = (int)($row["branch_id"] ?? 0);
        if (!isset($grouped[$branchId])) {
            $grouped[$branchId] = [
                "branch_id" => $branchId,
                "branch_name" => (string)($row["branch_name"] ?? ""),
                "games" => [],
            ];
        }
        $grouped[$branchId]["games"][] = [
            "id" => (int)$row["id"],
            "name" => (string)$row["name"],
        ];
    }

    return array_values($grouped);
}
?>
