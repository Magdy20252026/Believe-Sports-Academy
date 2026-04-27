<?php
/**
 * نظام التتبع (Audit) لتسجيل كل عمليات الإضافة/التعديل/الحذف
 * ----------------------------------------------------------
 * - جدول activity_log المركزي يسجّل كل العمليات (بما فيها الحذف).
 * - لكل جدول رئيسي عمودان created_by_user_id و updated_by_user_id.
 * - دالة تركيب تلقائية تضيف الأعمدة الناقصة وتُنشئ الجدول مرّة واحدة.
 */

if (!function_exists("auditGetCurrentUserId")) {
    function auditGetCurrentUserId()
    {
        $userId = $_SESSION["user_id"] ?? null;
        return $userId !== null ? (int)$userId : null;
    }
}

if (!function_exists("auditGetCurrentUsername")) {
    function auditGetCurrentUsername()
    {
        $username = $_SESSION["username"] ?? "";
        return $username !== "" ? (string)$username : "غير معروف";
    }
}

if (!function_exists("auditGetCurrentGameId")) {
    function auditGetCurrentGameId()
    {
        $gameId = $_SESSION["selected_game_id"] ?? null;
        return $gameId !== null ? (int)$gameId : null;
    }
}

if (!function_exists("auditGetActionLabels")) {
    function auditGetActionLabels()
    {
        return [
            "create" => "إضافة",
            "update" => "تعديل",
            "delete" => "حذف",
        ];
    }
}

if (!function_exists("auditFormatActionLabel")) {
    function auditFormatActionLabel($action)
    {
        $labels = auditGetActionLabels();
        $action = (string)$action;
        return $labels[$action] ?? $action;
    }
}

if (!function_exists("auditPreloadUserNames")) {
    /**
     * يحمّل أسماء عدة مستخدمين دفعة واحدة إلى الذاكرة المؤقتة العامة.
     */
    function auditPreloadUserNames(PDO $pdo, array $userIds)
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ($id === null || $id === "") {
                continue;
            }
            $idInt = (int)$id;
            if ($idInt > 0) {
                $ids[$idInt] = true;
            }
        }
        if (count($ids) === 0) {
            return;
        }

        if (!isset($GLOBALS["__auditUserNameCache"]) || !is_array($GLOBALS["__auditUserNameCache"])) {
            $GLOBALS["__auditUserNameCache"] = [];
        }

        $missing = array_values(array_diff(array_keys($ids), array_keys($GLOBALS["__auditUserNameCache"])));
        if (count($missing) === 0) {
            return;
        }

        try {
            $placeholders = implode(",", array_fill(0, count($missing), "?"));
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id IN ($placeholders)");
            $stmt->execute($missing);
            foreach ($stmt->fetchAll() as $row) {
                $GLOBALS["__auditUserNameCache"][(int)$row["id"]] = (string)$row["username"];
            }
        } catch (PDOException $e) {
            // تجاهل الخطأ
        }
    }
}

if (!function_exists("auditDisplayUserName")) {
    /**
     * يعيد اسم المستخدم اعتمادًا على ID؛ "غير معروف" إن لم يوجد.
     */
    function auditDisplayUserName(?PDO $pdo, $userId)
    {
        if ($userId === null || $userId === "" || (int)$userId <= 0) {
            return "غير معروف";
        }
        $userId = (int)$userId;

        if (!isset($GLOBALS["__auditUserNameCache"]) || !is_array($GLOBALS["__auditUserNameCache"])) {
            $GLOBALS["__auditUserNameCache"] = [];
        }
        if (array_key_exists($userId, $GLOBALS["__auditUserNameCache"])) {
            return $GLOBALS["__auditUserNameCache"][$userId] !== "" ? $GLOBALS["__auditUserNameCache"][$userId] : "غير معروف";
        }

        if ($pdo === null) {
            return "غير معروف";
        }

        $username = "";
        try {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $username = (string)($stmt->fetchColumn() ?: "");
        } catch (PDOException $e) {
            $username = "";
        }

        $GLOBALS["__auditUserNameCache"][$userId] = $username;
        return $username !== "" ? $username : "غير معروف";
    }
}

if (!function_exists("auditEnsureSchema")) {
    /**
     * تنشئ جدول activity_log وتضيف أعمدة created_by_user_id/updated_by_user_id لكل الجداول الرئيسية
     * بشكل آمن (idempotent) بحيث يمكن استدعاؤها على كل صفحة دون مشاكل أداء جسيمة.
     * يعتمد على معلومات INFORMATION_SCHEMA.
     */
    function auditEnsureSchema(PDO $pdo)
    {
        static $alreadyRan = false;
        if ($alreadyRan) {
            return;
        }
        $alreadyRan = true;

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS activity_log (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    user_id INT(11) DEFAULT NULL,
                    username_snapshot VARCHAR(150) DEFAULT NULL,
                    action_type VARCHAR(20) NOT NULL,
                    table_name VARCHAR(100) NOT NULL,
                    record_id VARCHAR(100) DEFAULT NULL,
                    page_label VARCHAR(150) DEFAULT NULL,
                    description TEXT DEFAULT NULL,
                    game_id INT(11) DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_activity_user (user_id),
                    KEY idx_activity_table (table_name),
                    KEY idx_activity_action (action_type),
                    KEY idx_activity_created (created_at),
                    KEY idx_activity_game (game_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        } catch (PDOException $e) {
            error_log("auditEnsureSchema: failed to create activity_log table: " . $e->getMessage());
            return;
        }

        $tablesNeedingAuditColumns = [
            "admins",
            "admin_attendance",
            "admin_days_off",
            "admin_weekly_schedule",
            "trainers",
            "trainer_attendance",
            "trainer_days_off",
            "trainer_weekly_schedule",
            "trainer_salary_payments",
            "players",
            "player_attendance",
            "player_files",
            "player_subscription_history",
            "sports_groups",
            "store_categories",
            "sales_invoice_items",
            "single_training_attendance",
            "single_training_prices",
            "settings",
            "user_games",
            "user_game_permissions",
            "games",
            "users",
        ];

        try {
            $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($databaseName === "") {
                return;
            }

            $colsStmt = $pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND COLUMN_NAME IN ('created_by_user_id','updated_by_user_id')"
            );
            $colsStmt->execute([$databaseName]);
            $existing = [];
            foreach ($colsStmt->fetchAll() as $row) {
                $existing[$row["TABLE_NAME"]][$row["COLUMN_NAME"]] = true;
            }

            $tablesStmt = $pdo->prepare(
                "SELECT TABLE_NAME
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
            );
            $tablesStmt->execute([$databaseName]);
            $allTables = array_column($tablesStmt->fetchAll(), "TABLE_NAME");

            foreach ($tablesNeedingAuditColumns as $table) {
                if (!in_array($table, $allTables, true)) {
                    continue;
                }
                $hasCreated = isset($existing[$table]["created_by_user_id"]);
                $hasUpdated = isset($existing[$table]["updated_by_user_id"]);
                if (!$hasCreated) {
                    try {
                        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `created_by_user_id` INT(11) DEFAULT NULL");
                    } catch (PDOException $e) {
                        error_log("auditEnsureSchema: failed adding created_by_user_id to $table: " . $e->getMessage());
                    }
                }
                if (!$hasUpdated) {
                    try {
                        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `updated_by_user_id` INT(11) DEFAULT NULL");
                    } catch (PDOException $e) {
                        error_log("auditEnsureSchema: failed adding updated_by_user_id to $table: " . $e->getMessage());
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("auditEnsureSchema: schema introspection failed: " . $e->getMessage());
        }
    }
}

if (!function_exists("auditLogActivity")) {
    /**
     * يسجّل عملية في activity_log.
     * @param string $action    create | update | delete
     * @param string $tableName اسم الجدول
     * @param mixed  $recordId  المعرّف
     * @param string $pageLabel اسم الصفحة بالعربية مثل "اللاعبين"
     * @param string $description وصف مختصر
     */
    function auditLogActivity(PDO $pdo, $action, $tableName, $recordId, $pageLabel = "", $description = "")
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO activity_log
                    (user_id, username_snapshot, action_type, table_name, record_id, page_label, description, game_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                auditGetCurrentUserId(),
                auditGetCurrentUsername(),
                (string)$action,
                (string)$tableName,
                $recordId !== null ? (string)$recordId : null,
                (string)$pageLabel,
                (string)$description,
                auditGetCurrentGameId(),
            ]);
        } catch (PDOException $e) {
            error_log("auditLogActivity failed: " . $e->getMessage());
        }
    }
}

if (!function_exists("auditStampOwnership")) {
    /**
     * يُعدّل سجلاً لإسناد created_by_user_id / updated_by_user_id إن كانت الأعمدة موجودة.
     * يُستدعى مباشرة بعد INSERT أو UPDATE.
     */
    function auditStampOwnership(PDO $pdo, $tableName, $recordId, $isCreate = false, $idColumn = "id")
    {
        if ($recordId === null || $recordId === "") {
            return;
        }
        $userId = auditGetCurrentUserId();
        if ($userId === null) {
            return;
        }

        $tableName = (string)$tableName;
        $idColumn = (string)$idColumn;
        if ($tableName === "" || $idColumn === "") {
            return;
        }

        try {
            if ($isCreate) {
                $sql = "UPDATE `$tableName`
                        SET created_by_user_id = COALESCE(created_by_user_id, ?),
                            updated_by_user_id = COALESCE(updated_by_user_id, ?)
                        WHERE `$idColumn` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $userId, $recordId]);
            } else {
                $sql = "UPDATE `$tableName`
                        SET updated_by_user_id = ?
                        WHERE `$idColumn` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $recordId]);
            }
        } catch (PDOException $e) {
            // غالبًا الأعمدة غير موجودة في الجدول؛ تجاهل بصمت
        }
    }
}

if (!function_exists("auditTrack")) {
    /**
     * مساعد موحّد: يضع created_by/updated_by + يسجّل في activity_log في خطوة واحدة.
     */
    function auditTrack(
        PDO $pdo,
        $action,
        $tableName,
        $recordId,
        $pageLabel = "",
        $description = "",
        $idColumn = "id"
    ) {
        if ($action === "create") {
            auditStampOwnership($pdo, $tableName, $recordId, true, $idColumn);
        } elseif ($action === "update") {
            auditStampOwnership($pdo, $tableName, $recordId, false, $idColumn);
        }
        auditLogActivity($pdo, $action, $tableName, $recordId, $pageLabel, $description);
    }
}
?>
