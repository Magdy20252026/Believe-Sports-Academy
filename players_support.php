<?php
const PLAYER_DEFAULT_CATEGORY = 'مدني';
const PLAYER_CATEGORY_OPTIONS = [
     'مشاة' => 'مشاة',
    'اسلحة اخري' => 'اسلحة اخري',
    'مدني' => 'مدني',


];
const PLAYER_DAY_OPTIONS = [
    'saturday' => 'السبت',
    'sunday' => 'الأحد',
    'monday' => 'الإثنين',
    'tuesday' => 'الثلاثاء',
    'wednesday' => 'الأربعاء',
    'thursday' => 'الخميس',
    'friday' => 'الجمعة',
];
const PLAYER_DAY_ORDER_SQL = "'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'";
const PLAYER_DAY_SEPARATOR = '|';
const PLAYER_BARCODE_MAX_LENGTH = 100;
const PLAYER_LEVEL_MAX_LENGTH = 150;
const PLAYER_NOTIFICATION_TITLE_MAX_LENGTH = 160;
const PLAYER_NOTIFICATION_MESSAGE_MAX_LENGTH = 3000;
const PLAYER_ATTENDANCE_EMPTY_VALUE = '—';
const PLAYER_ATTENDANCE_STATUS_PRESENT = 'حضور';
const PLAYER_ATTENDANCE_STATUS_ABSENT = 'غياب';

const PENTATHLON_SUB_GAMES = [
    'أوبستكلس',
    'شيش',
    'جري',
    'سباحة',
    'رماية',
];

function ensurePlayersTables(PDO $pdo)
{
    ensurePlayerNotificationsTableForPortal($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS players (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            group_id INT(11) DEFAULT NULL,
            barcode VARCHAR(100) NOT NULL DEFAULT '',
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            phone2 VARCHAR(30) NOT NULL DEFAULT '',
            whatsapp_group_joined TINYINT(1) NOT NULL DEFAULT 0,
            player_category VARCHAR(50) NOT NULL,
            subscription_start_date DATE NOT NULL,
            subscription_end_date DATE NOT NULL,
            group_name VARCHAR(150) NOT NULL,
            group_level VARCHAR(150) NOT NULL,
            player_level VARCHAR(150) NOT NULL DEFAULT '',
            receipt_number VARCHAR(100) NOT NULL DEFAULT '',
            subscriber_number VARCHAR(100) NOT NULL DEFAULT '',
            subscription_number VARCHAR(100) NOT NULL DEFAULT '',
            issue_date DATE NULL DEFAULT NULL,
            birth_date DATE NULL DEFAULT NULL,
            player_age INT(11) NOT NULL DEFAULT 0,
            training_days_per_week INT(11) NOT NULL DEFAULT 1,
            total_training_days INT(11) NOT NULL DEFAULT 1,
            total_trainings INT(11) NOT NULL DEFAULT 1,
            trainer_name VARCHAR(150) NOT NULL,
            subscription_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            academy_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            training_day_keys VARCHAR(255) NOT NULL DEFAULT '',
            training_time TIME NULL DEFAULT NULL,
            password VARCHAR(255) NOT NULL DEFAULT '123456',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_players_game (game_id),
            KEY idx_players_group (group_id),
            KEY idx_players_barcode (barcode),
            KEY idx_players_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_attendance (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            player_id INT(11) NOT NULL,
            attendance_date DATE NOT NULL,
            attendance_day_key VARCHAR(20) NOT NULL,
            attendance_status VARCHAR(20) NOT NULL DEFAULT 'حضور',
            attendance_minutes_late INT(11) NOT NULL DEFAULT 0,
            attendance_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_player_attendance_day (player_id, attendance_date),
            KEY idx_player_attendance_game_date (game_id, attendance_date),
            KEY idx_player_attendance_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_subscription_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            player_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            group_id INT(11) DEFAULT NULL,
            barcode VARCHAR(100) NOT NULL DEFAULT '',
            player_name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL DEFAULT '',
            phone2 VARCHAR(30) NOT NULL DEFAULT '',
            whatsapp_group_joined TINYINT(1) NOT NULL DEFAULT 0,
            player_category VARCHAR(50) NOT NULL DEFAULT '',
            subscription_start_date DATE NOT NULL,
            subscription_end_date DATE NOT NULL,
            group_name VARCHAR(150) NOT NULL DEFAULT '',
            group_level VARCHAR(150) NOT NULL DEFAULT '',
            player_level VARCHAR(150) NOT NULL DEFAULT '',
            receipt_number VARCHAR(100) NOT NULL DEFAULT '',
            subscriber_number VARCHAR(100) NOT NULL DEFAULT '',
            subscription_number VARCHAR(100) NOT NULL DEFAULT '',
            issue_date DATE NULL DEFAULT NULL,
            birth_date DATE NULL DEFAULT NULL,
            player_age INT(11) NOT NULL DEFAULT 0,
            training_days_per_week INT(11) NOT NULL DEFAULT 1,
            total_training_days INT(11) NOT NULL DEFAULT 1,
            total_trainings INT(11) NOT NULL DEFAULT 1,
            trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            subscription_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            academy_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            training_day_keys VARCHAR(255) NOT NULL DEFAULT '',
            training_time TIME NULL DEFAULT NULL,
            source_action VARCHAR(30) NOT NULL DEFAULT 'save',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player_subscription_history_player (player_id),
            KEY idx_player_subscription_history_game (game_id),
            KEY idx_player_subscription_history_dates (subscription_start_date, subscription_end_date),
            KEY idx_player_subscription_history_latest (player_id, game_id, subscription_start_date, id),
            CONSTRAINT fk_player_subscription_history_player FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
            CONSTRAINT fk_player_subscription_history_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS single_training_prices (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            training_name VARCHAR(150) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_single_training_game_name (game_id, training_name),
            KEY idx_single_training_prices_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS single_training_attendance (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            single_training_id INT(11) NOT NULL,
            player_name VARCHAR(150) NOT NULL,
            player_phone VARCHAR(30) NOT NULL,
            training_name VARCHAR(150) NOT NULL DEFAULT '',
            training_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            attendance_date DATE NOT NULL,
            attended_at DATETIME NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_single_training_attendance_game_date (game_id, attendance_date),
            KEY idx_single_training_attendance_training (single_training_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // جدول ملفات اللاعبين
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_files (
            id INT(11) NOT NULL AUTO_INCREMENT,
            player_id INT(11) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            file_type VARCHAR(100) NOT NULL DEFAULT 'image',
            file_size INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player_files_player (player_id),
            CONSTRAINT fk_player_files_player FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredPlayerColumns = [
        'group_id' => "ALTER TABLE players ADD COLUMN group_id INT(11) DEFAULT NULL AFTER game_id",
        'barcode' => "ALTER TABLE players ADD COLUMN barcode VARCHAR(100) NOT NULL DEFAULT '' AFTER group_id",
        'phone2' => "ALTER TABLE players ADD COLUMN phone2 VARCHAR(30) NOT NULL DEFAULT '' AFTER phone",
        'whatsapp_group_joined' => "ALTER TABLE players ADD COLUMN whatsapp_group_joined TINYINT(1) NOT NULL DEFAULT 0 AFTER phone2",
        'player_category' => "ALTER TABLE players ADD COLUMN player_category VARCHAR(50) NOT NULL DEFAULT '" . PLAYER_DEFAULT_CATEGORY . "' AFTER whatsapp_group_joined",
        'subscription_start_date' => "ALTER TABLE players ADD COLUMN subscription_start_date DATE NOT NULL AFTER player_category",
        'subscription_end_date' => "ALTER TABLE players ADD COLUMN subscription_end_date DATE NOT NULL AFTER subscription_start_date",
        'group_name' => "ALTER TABLE players ADD COLUMN group_name VARCHAR(150) NOT NULL DEFAULT '' AFTER subscription_end_date",
        'group_level' => "ALTER TABLE players ADD COLUMN group_level VARCHAR(150) NOT NULL DEFAULT '' AFTER group_name",
        'player_level' => "ALTER TABLE players ADD COLUMN player_level VARCHAR(150) NOT NULL DEFAULT '' AFTER group_level",
        'receipt_number' => "ALTER TABLE players ADD COLUMN receipt_number VARCHAR(100) NOT NULL DEFAULT '' AFTER player_level",
        'subscriber_number' => "ALTER TABLE players ADD COLUMN subscriber_number VARCHAR(100) NOT NULL DEFAULT '' AFTER receipt_number",
        'subscription_number' => "ALTER TABLE players ADD COLUMN subscription_number VARCHAR(100) NOT NULL DEFAULT '' AFTER subscriber_number",
        'issue_date' => "ALTER TABLE players ADD COLUMN issue_date DATE NULL DEFAULT NULL AFTER subscription_number",
        'birth_date' => "ALTER TABLE players ADD COLUMN birth_date DATE NULL DEFAULT NULL AFTER issue_date",
        'player_age' => "ALTER TABLE players ADD COLUMN player_age INT(11) NOT NULL DEFAULT 0 AFTER birth_date",
        'training_days_per_week' => "ALTER TABLE players ADD COLUMN training_days_per_week INT(11) NOT NULL DEFAULT 1 AFTER player_age",
        'total_training_days' => "ALTER TABLE players ADD COLUMN total_training_days INT(11) NOT NULL DEFAULT 1 AFTER training_days_per_week",
        'total_trainings' => "ALTER TABLE players ADD COLUMN total_trainings INT(11) NOT NULL DEFAULT 1 AFTER total_training_days",
        'trainer_name' => "ALTER TABLE players ADD COLUMN trainer_name VARCHAR(150) NOT NULL DEFAULT '' AFTER total_trainings",
        'subscription_price' => "ALTER TABLE players ADD COLUMN subscription_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER trainer_name",
        'paid_amount' => "ALTER TABLE players ADD COLUMN paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subscription_price",
        'academy_percentage' => "ALTER TABLE players ADD COLUMN academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER paid_amount",
        'academy_amount' => "ALTER TABLE players ADD COLUMN academy_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER academy_percentage",
        'training_day_keys' => "ALTER TABLE players ADD COLUMN training_day_keys VARCHAR(255) NOT NULL DEFAULT '' AFTER academy_amount",
        'training_time' => "ALTER TABLE players ADD COLUMN training_time TIME NULL DEFAULT NULL AFTER training_day_keys",
        'password' => "ALTER TABLE players ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT '123456' AFTER training_time",
    ];
    $requiredSubscriptionHistoryColumns = [
        'player_level' => "ALTER TABLE player_subscription_history ADD COLUMN player_level VARCHAR(150) NOT NULL DEFAULT '' AFTER group_level",
        'phone2' => "ALTER TABLE player_subscription_history ADD COLUMN phone2 VARCHAR(30) NOT NULL DEFAULT '' AFTER phone",
        'whatsapp_group_joined' => "ALTER TABLE player_subscription_history ADD COLUMN whatsapp_group_joined TINYINT(1) NOT NULL DEFAULT 0 AFTER phone2",
        'receipt_number' => "ALTER TABLE player_subscription_history ADD COLUMN receipt_number VARCHAR(100) NOT NULL DEFAULT '' AFTER player_level",
        'subscriber_number' => "ALTER TABLE player_subscription_history ADD COLUMN subscriber_number VARCHAR(100) NOT NULL DEFAULT '' AFTER receipt_number",
        'subscription_number' => "ALTER TABLE player_subscription_history ADD COLUMN subscription_number VARCHAR(100) NOT NULL DEFAULT '' AFTER subscriber_number",
        'issue_date' => "ALTER TABLE player_subscription_history ADD COLUMN issue_date DATE NULL DEFAULT NULL AFTER subscription_number",
        'birth_date' => "ALTER TABLE player_subscription_history ADD COLUMN birth_date DATE NULL DEFAULT NULL AFTER issue_date",
        'player_age' => "ALTER TABLE player_subscription_history ADD COLUMN player_age INT(11) NOT NULL DEFAULT 0 AFTER birth_date",
        'training_time' => "ALTER TABLE player_subscription_history ADD COLUMN training_time TIME NULL DEFAULT NULL AFTER training_day_keys",
    ];

    $requiredAttendanceColumns = [
        'attendance_day_key' => "ALTER TABLE player_attendance ADD COLUMN attendance_day_key VARCHAR(20) NOT NULL DEFAULT '' AFTER attendance_date",
        'attendance_status' => "ALTER TABLE player_attendance ADD COLUMN attendance_status VARCHAR(20) NOT NULL DEFAULT 'حضور' AFTER attendance_day_key",
        'attendance_minutes_late' => "ALTER TABLE player_attendance ADD COLUMN attendance_minutes_late INT(11) NOT NULL DEFAULT 0 AFTER attendance_status",
        'attendance_at' => "ALTER TABLE player_attendance ADD COLUMN attendance_at DATETIME NULL DEFAULT NULL AFTER attendance_minutes_late",
        'pentathlon_sub_game' => "ALTER TABLE player_attendance ADD COLUMN pentathlon_sub_game VARCHAR(100) NOT NULL DEFAULT '' AFTER attendance_at",
        'attendance_source' => "ALTER TABLE player_attendance ADD COLUMN attendance_source VARCHAR(50) NOT NULL DEFAULT '' AFTER pentathlon_sub_game",
    ];

    $requiredSingleTrainingPriceColumns = [
        'created_at' => "ALTER TABLE single_training_prices ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER price",
        'updated_at' => "ALTER TABLE single_training_prices ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    $requiredSingleTrainingAttendanceColumns = [
        'training_name' => "ALTER TABLE single_training_attendance ADD COLUMN training_name VARCHAR(150) NOT NULL DEFAULT '' AFTER player_phone",
        'training_price' => "ALTER TABLE single_training_attendance ADD COLUMN training_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER training_name",
        'paid_amount' => "ALTER TABLE single_training_attendance ADD COLUMN paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER training_price",
        'attendance_date' => "ALTER TABLE single_training_attendance ADD COLUMN attendance_date DATE NULL DEFAULT NULL AFTER paid_amount",
        'attended_at' => "ALTER TABLE single_training_attendance ADD COLUMN attended_at DATETIME NULL DEFAULT NULL AFTER attendance_date",
        'created_at' => "ALTER TABLE single_training_attendance ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER attended_at",
    ];

    $existingColumnsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );

    foreach ($requiredPlayerColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(['players', $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure players.' . $columnName . ' exists: ' . $throwable->getMessage());
            }
        }
    }

    foreach ($requiredAttendanceColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(['player_attendance', $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure player_attendance.' . $columnName . ' exists: ' . $throwable->getMessage());
            }
        }
    }

    foreach ($requiredSubscriptionHistoryColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(['player_subscription_history', $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure player_subscription_history.' . $columnName . ' exists: ' . $throwable->getMessage());
            }
        }
    }

    foreach ($requiredSingleTrainingPriceColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(['single_training_prices', $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure single_training_prices.' . $columnName . ' exists: ' . $throwable->getMessage());
            }
        }
    }

    foreach ($requiredSingleTrainingAttendanceColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(['single_training_attendance', $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure single_training_attendance.' . $columnName . ' exists: ' . $throwable->getMessage());
            }
        }
    }

    $existingIndexesStmt = $pdo->prepare(
        "SELECT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1"
    );
    $existingIndexesStmt->execute(['player_notifications', 'idx_player_notifications_player']);
    if (!$existingIndexesStmt->fetchColumn()) {
        try {
            $pdo->exec(
                "ALTER TABLE player_notifications
                 ADD INDEX idx_player_notifications_player (game_id, target_player_id)"
            );
        } catch (Throwable $throwable) {
            error_log('Failed to ensure idx_player_notifications_player exists: ' . $throwable->getMessage());
        }
    }

    $existingIndexesStmt->execute(['player_subscription_history', 'idx_player_subscription_history_latest']);
    if (!$existingIndexesStmt->fetchColumn()) {
        try {
            $pdo->exec(
                "ALTER TABLE player_subscription_history
                 ADD INDEX idx_player_subscription_history_latest (player_id, game_id, subscription_start_date, id)"
            );
        } catch (Throwable $throwable) {
            error_log('Failed to ensure idx_player_subscription_history_latest exists: ' . $throwable->getMessage());
        }
    }

    // جدول تمرينات اللاعبين في ألعاب الخماسي
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pentathlon_player_sub_game_sessions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            player_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            sub_game VARCHAR(100) NOT NULL,
            total_sessions INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_pentathlon_player_sub_game (player_id, sub_game),
            KEY idx_pentathlon_player_sub_game_player (player_id),
            KEY idx_pentathlon_player_sub_game_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // التحقق من وجود عمود label في player_files (للترقية)
    $existingColumnsStmt->execute(['player_files', 'label']);
    if (!$existingColumnsStmt->fetchColumn()) {
        try {
            $pdo->exec("ALTER TABLE player_files ADD COLUMN label VARCHAR(255) NOT NULL DEFAULT '' AFTER original_name");
        } catch (Throwable $t) {}
    }

    $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($databaseName === '') {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT TABLE_NAME, CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME IN ('players', 'player_attendance', 'player_files', 'single_training_prices', 'single_training_attendance')
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = [];
    foreach ($constraintsStmt->fetchAll() as $constraint) {
        $tableName = (string)$constraint['TABLE_NAME'];
        if (!isset($existingConstraints[$tableName])) {
            $existingConstraints[$tableName] = [];
        }
        $existingConstraints[$tableName][] = (string)$constraint['CONSTRAINT_NAME'];
    }

    $playerConstraints = $existingConstraints['players'] ?? [];
    if (!in_array('fk_players_game', $playerConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE players
             ADD CONSTRAINT fk_players_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }
    if (!in_array('fk_players_group', $playerConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE players
             ADD CONSTRAINT fk_players_group
             FOREIGN KEY (group_id) REFERENCES sports_groups (id) ON DELETE SET NULL"
        );
    }

    $attendanceConstraints = $existingConstraints['player_attendance'] ?? [];
    if (!in_array('fk_player_attendance_game', $attendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE player_attendance
             ADD CONSTRAINT fk_player_attendance_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }
    if (!in_array('fk_player_attendance_player', $attendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE player_attendance
             ADD CONSTRAINT fk_player_attendance_player
             FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE"
        );
    }

    $singleTrainingPriceConstraints = $existingConstraints['single_training_prices'] ?? [];
    if (!in_array('fk_single_training_prices_game', $singleTrainingPriceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE single_training_prices
             ADD CONSTRAINT fk_single_training_prices_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }

    $singleTrainingAttendanceConstraints = $existingConstraints['single_training_attendance'] ?? [];
    if (!in_array('fk_single_training_attendance_game', $singleTrainingAttendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE single_training_attendance
             ADD CONSTRAINT fk_single_training_attendance_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }
    if (!in_array('fk_single_training_attendance_training', $singleTrainingAttendanceConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE single_training_attendance
             ADD CONSTRAINT fk_single_training_attendance_training
             FOREIGN KEY (single_training_id) REFERENCES single_training_prices (id) ON DELETE RESTRICT"
        );
    }
}

function ensurePlayerNotificationsTableForPortal(PDO $pdo)
{
    static $alreadyEnsured = false;
    if ($alreadyEnsured) {
        return;
    }
    $alreadyEnsured = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_notifications (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority_level VARCHAR(20) NOT NULL DEFAULT 'normal',
            visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible',
            target_scope VARCHAR(20) NOT NULL DEFAULT 'all',
            target_group_id INT(11) NULL DEFAULT NULL,
            target_group_name VARCHAR(150) NULL DEFAULT NULL,
            target_group_level VARCHAR(150) NULL DEFAULT NULL,
            target_player_id INT(11) NULL DEFAULT NULL,
            display_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player_notifications_game_date (game_id, display_date),
            KEY idx_player_notifications_status (game_id, visibility_status),
            KEY idx_player_notifications_scope (game_id, target_scope),
            KEY idx_player_notifications_group (game_id, target_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredNotificationColumns = [
        'title' => "ALTER TABLE player_notifications ADD COLUMN title VARCHAR(160) NOT NULL AFTER game_id",
        'message' => "ALTER TABLE player_notifications ADD COLUMN message TEXT NOT NULL AFTER title",
        'notification_type' => "ALTER TABLE player_notifications ADD COLUMN notification_type VARCHAR(50) NOT NULL DEFAULT 'general' AFTER message",
        'priority_level' => "ALTER TABLE player_notifications ADD COLUMN priority_level VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER notification_type",
        'visibility_status' => "ALTER TABLE player_notifications ADD COLUMN visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible' AFTER priority_level",
        'target_scope' => "ALTER TABLE player_notifications ADD COLUMN target_scope VARCHAR(20) NOT NULL DEFAULT 'all' AFTER visibility_status",
        'target_group_id' => "ALTER TABLE player_notifications ADD COLUMN target_group_id INT(11) NULL DEFAULT NULL AFTER target_scope",
        'target_group_name' => "ALTER TABLE player_notifications ADD COLUMN target_group_name VARCHAR(150) NULL DEFAULT NULL AFTER target_group_id",
        'target_group_level' => "ALTER TABLE player_notifications ADD COLUMN target_group_level VARCHAR(150) NULL DEFAULT NULL AFTER target_group_name",
        'target_player_id' => "ALTER TABLE player_notifications ADD COLUMN target_player_id INT(11) NULL DEFAULT NULL AFTER target_group_level",
        'display_date' => "ALTER TABLE player_notifications ADD COLUMN display_date DATE NOT NULL AFTER target_player_id",
        'created_by_user_id' => "ALTER TABLE player_notifications ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER display_date",
        'updated_by_user_id' => "ALTER TABLE player_notifications ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
    ];

    $existingColumnsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'player_notifications'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );

    foreach ($requiredNotificationColumns as $columnName => $sql) {
        $existingColumnsStmt->execute([$columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log('Failed to ensure player_notifications.' . $columnName . ' exists: ' . $throwable->getMessage());
            }
        }
    }
}

function ensurePlayerNotificationReadsTable(PDO $pdo)
{
    static $alreadyEnsured = false;
    if ($alreadyEnsured) {
        return;
    }
    $alreadyEnsured = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_notification_reads (
            notification_id INT(11) NOT NULL,
            player_id INT(11) NOT NULL,
            read_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notification_id, player_id),
            KEY idx_player_notification_reads_player (player_id, read_at)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function ensurePlayerSubscriptionAlertsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_subscription_alerts (
            id INT(11) NOT NULL AUTO_INCREMENT,
            player_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            alert_key VARCHAR(50) NOT NULL,
            subscription_start_date DATE NOT NULL,
            subscription_end_date DATE NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_player_subscription_alert (
                player_id,
                alert_key,
                subscription_start_date,
                subscription_end_date
            ),
            KEY idx_player_subscription_alerts_game (game_id),
            KEY idx_player_subscription_alerts_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function fetchPlayerPortalNotifications(PDO $pdo, $gameId, $playerId, $groupId, $groupLevel)
{
    ensurePlayerNotificationsTableForPortal($pdo);
    ensurePlayerNotificationReadsTable($pdo);

    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.message, n.notification_type, n.priority_level, n.target_scope,
                n.display_date, n.created_at,
                CASE WHEN r.notification_id IS NULL THEN 0 ELSE 1 END AS is_read
         FROM player_notifications n
         LEFT JOIN player_notification_reads r
           ON r.notification_id = n.id AND r.player_id = ?
         WHERE n.game_id = ? AND n.visibility_status = 'visible'
           AND (
                n.target_scope = 'all'
                OR (n.target_scope = 'level' AND n.target_group_level = ?)
                OR (n.target_scope = 'group' AND n.target_group_id = ?)
                OR (n.target_scope = 'player' AND n.target_player_id = ?)
           )
         ORDER BY n.display_date DESC, n.id DESC
         LIMIT 50"
    );
    $stmt->execute([
        (int)$playerId,
        (int)$gameId,
        trim((string)$groupLevel),
        (int)$groupId,
        (int)$playerId,
    ]);

    return $stmt->fetchAll();
}

function markPlayerPortalNotificationsAsRead(PDO $pdo, $playerId, array $notificationIds)
{
    ensurePlayerNotificationReadsTable($pdo);

    $playerId = (int)$playerId;
    if ($playerId <= 0) {
        return;
    }

    $notificationIds = array_map("intval", $notificationIds);
    $notificationIds = array_filter($notificationIds, static function ($notificationId) {
        return $notificationId > 0;
    });
    $notificationIds = array_values(array_unique($notificationIds));
    if (count($notificationIds) === 0) {
        return;
    }

    $placeholders = [];
    $params = [];
    foreach ($notificationIds as $notificationId) {
        $placeholders[] = "(?, ?)";
        $params[] = $notificationId;
        $params[] = $playerId;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO player_notification_reads (notification_id, player_id)
         VALUES " . implode(", ", $placeholders) . "
         ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute($params);
}

function createPlayerNotification(PDO $pdo, $gameId, $playerId, $title, $message, $notificationType = 'alert', $priorityLevel = 'important', $displayDate = null, $userId = null)
{
    ensurePlayerNotificationsTableForPortal($pdo);

    $gameId = (int)$gameId;
    $playerId = (int)$playerId;
    $title = trim((string)$title);
    $message = trim((string)$message);
    $notificationType = trim((string)$notificationType);
    $priorityLevel = trim((string)$priorityLevel);
    $userId = $userId !== null ? (int)$userId : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

    if ($gameId <= 0 || $playerId <= 0 || $title === '' || $message === '') {
        return false;
    }

    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, PLAYER_NOTIFICATION_TITLE_MAX_LENGTH, 'UTF-8');
        $message = mb_substr($message, 0, PLAYER_NOTIFICATION_MESSAGE_MAX_LENGTH, 'UTF-8');
    } else {
        $title = substr($title, 0, PLAYER_NOTIFICATION_TITLE_MAX_LENGTH);
        $message = substr($message, 0, PLAYER_NOTIFICATION_MESSAGE_MAX_LENGTH);
    }

    $displayDate = trim((string)$displayDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $displayDate)) {
        $displayDate = (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d');
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO player_notifications (
                game_id, title, message, notification_type, priority_level, visibility_status, target_scope,
                target_group_id, target_group_name, target_group_level, target_player_id,
                display_date, created_by_user_id, updated_by_user_id
             ) VALUES (?, ?, ?, ?, ?, 'visible', 'player', NULL, NULL, NULL, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $gameId,
            $title,
            $message,
            $notificationType !== '' ? $notificationType : 'alert',
            $priorityLevel !== '' ? $priorityLevel : 'important',
            $playerId,
            $displayDate,
            $userId,
            $userId,
        ]);
        $notificationId = (int)$pdo->lastInsertId();
        if ($notificationId > 0 && function_exists('auditTrack')) {
            auditTrack(
                $pdo,
                'create',
                'player_notifications',
                $notificationId,
                'إشعارات اللاعبين',
                'إرسال إشعار للاعب رقم ' . $playerId . ' بعنوان: ' . $title
            );
        }
        return true;
    } catch (Throwable $throwable) {
        error_log('Failed to create direct player notification: ' . $throwable->getMessage());
    }

    return false;
}

function fetchPlayerConsumedSessionsCount(PDO $pdo, $playerId, $subscriptionStartDate, $subscriptionEndDate)
{
    if ((int)$playerId <= 0 || !isValidPlayerDate($subscriptionStartDate) || !isValidPlayerDate($subscriptionEndDate)) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM player_attendance
         WHERE player_id = ?
           AND attendance_date BETWEEN ? AND ?"
    );
    $stmt->execute([
        (int)$playerId,
        (string)$subscriptionStartDate,
        (string)$subscriptionEndDate,
    ]);

    return (int)$stmt->fetchColumn();
}

function registerPlayerSubscriptionAlert(PDO $pdo, array $player, $alertKey, $title, $message, $displayDate = null, $userId = null)
{
    ensurePlayerSubscriptionAlertsTable($pdo);

    $playerId = (int)($player['id'] ?? 0);
    $gameId = (int)($player['game_id'] ?? 0);
    $subscriptionStartDate = trim((string)($player['subscription_start_date'] ?? ''));
    $subscriptionEndDate = trim((string)($player['subscription_end_date'] ?? ''));
    $alertKey = trim((string)$alertKey);

    if (
        $playerId <= 0
        || $gameId <= 0
        || $alertKey === ''
        || !isValidPlayerDate($subscriptionStartDate)
        || !isValidPlayerDate($subscriptionEndDate)
    ) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO player_subscription_alerts (
                player_id,
                game_id,
                alert_key,
                subscription_start_date,
                subscription_end_date
             ) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $playerId,
            $gameId,
            $alertKey,
            $subscriptionStartDate,
            $subscriptionEndDate,
        ]);

        if ($stmt->rowCount() < 1) {
            return false;
        }

        if (createPlayerNotification($pdo, $gameId, $playerId, $title, $message, 'alert', 'important', $displayDate, $userId)) {
            return true;
        }

        $deleteStmt = $pdo->prepare(
            "DELETE FROM player_subscription_alerts
             WHERE player_id = ?
               AND alert_key = ?
               AND subscription_start_date = ?
               AND subscription_end_date = ?"
        );
        $deleteStmt->execute([
            $playerId,
            $alertKey,
            $subscriptionStartDate,
            $subscriptionEndDate,
        ]);
    } catch (Throwable $throwable) {
        error_log('Failed to register player subscription alert: ' . $throwable->getMessage());
    }

    return false;
}

function ensurePlayerSubscriptionStatusNotifications(PDO $pdo, array $player, ?DateTimeImmutable $today = null, $userId = null)
{
    $playerId = (int)($player['id'] ?? 0);
    $gameId = (int)($player['game_id'] ?? 0);
    $subscriptionStartDate = trim((string)($player['subscription_start_date'] ?? ''));
    $subscriptionEndDate = trim((string)($player['subscription_end_date'] ?? ''));

    if (
        $playerId <= 0
        || $gameId <= 0
        || !isValidPlayerDate($subscriptionStartDate)
        || !isValidPlayerDate($subscriptionEndDate)
    ) {
        return;
    }

    $today = $today ?: new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
    $subscriptionStart = createPlayerDate($subscriptionStartDate);
    $subscriptionEnd = createPlayerDate($subscriptionEndDate);
    if ($subscriptionStart > $today) {
        return;
    }

    $consumedSessionsCount = fetchPlayerConsumedSessionsCount($pdo, $playerId, $subscriptionStartDate, $subscriptionEndDate);
    $daysRemaining = calculatePlayerDaysRemaining($subscriptionEndDate, $today);
    $remainingTrainings = calculatePlayerRemainingTrainings($player['total_trainings'] ?? 0, $consumedSessionsCount);
    $displayDate = $today->format('Y-m-d');

    if ($daysRemaining === 1 && $remainingTrainings > 0) {
        registerPlayerSubscriptionAlert(
            $pdo,
            $player,
            'subscription_expires_tomorrow',
            '⏳ اشتراكك ينتهي غدًا',
            "تنبيه: يتبقى يوم واحد فقط على نهاية اشتراكك.\nيرجى مراجعة الإدارة لتجديد الاشتراك قبل تاريخ " . $subscriptionEnd->format('Y/m/d') . ".",
            $displayDate,
            $userId
        );
    }

    if ($remainingTrainings === 1 && $daysRemaining > 0) {
        registerPlayerSubscriptionAlert(
            $pdo,
            $player,
            'one_training_left',
            '🏃 متبقي لك تمرينة واحدة',
            "تنبيه: متبقي لك تمرينة واحدة فقط في اشتراكك الحالي.\nيرجى التواصل مع الإدارة لتجديد الاشتراك في الوقت المناسب.",
            $displayDate,
            $userId
        );
    }

    if ($daysRemaining === 0 || $remainingTrainings === 0) {
        $messageLines = ['انتهى اشتراكك الحالي.'];
        if ($daysRemaining === 0) {
            $messageLines[] = 'سبب الانتهاء: تم الوصول إلى تاريخ نهاية الاشتراك.';
        }
        if ($remainingTrainings === 0) {
            $messageLines[] = 'سبب الانتهاء: تم استهلاك جميع التمرينات المتاحة.';
        }
        $messageLines[] = 'يرجى مراجعة الإدارة لتجديد الاشتراك.';

        registerPlayerSubscriptionAlert(
            $pdo,
            $player,
            'subscription_ended',
            '📛 انتهى اشتراكك',
            implode("\n", $messageLines),
            $displayDate,
            $userId
        );
    }
}

function notifyPlayerLevelChanged(PDO $pdo, $gameId, $playerId, $previousPlayerLevel, $newPlayerLevel, $userId = null)
{
    $previousPlayerLevel = trim((string)$previousPlayerLevel);
    $newPlayerLevel = trim((string)$newPlayerLevel);

    if ($previousPlayerLevel === $newPlayerLevel) {
        return false;
    }

    $levelMessage = sprintf(
        "تم تحديث مستوى اللاعب من الإدارة.\nالمستوى السابق: %s\nالمستوى الجديد: %s",
        $previousPlayerLevel !== '' ? $previousPlayerLevel : 'غير محدد',
        $newPlayerLevel !== '' ? $newPlayerLevel : 'غير محدد'
    );

    return createPlayerNotification(
        $pdo,
        $gameId,
        $playerId,
        '🏆 تم تحديث المستوى',
        $levelMessage,
        'administrative',
        'important',
        null,
        $userId
    );
}

function buildPlayerAbsenceNotificationMessage($attendanceDateLabel, $reasonLine = '')
{
    $messageLines = [
        "تم تسجيل غيابك بتاريخ " . trim((string)$attendanceDateLabel) . ".",
    ];

    $reasonLine = trim((string)$reasonLine);
    if ($reasonLine !== '') {
        $messageLines[] = $reasonLine;
    }

    return implode("\n", $messageLines);
}

function fetchPentathlonPlayerSubGameSessions(PDO $pdo, $playerId)
{
    $stmt = $pdo->prepare(
        "SELECT sub_game, total_sessions
         FROM pentathlon_player_sub_game_sessions
         WHERE player_id = ?"
    );
    $stmt->execute([(int)$playerId]);
    $rows = $stmt->fetchAll();
    $result = [];
    foreach (PENTATHLON_SUB_GAMES as $subGame) {
        $result[$subGame] = 0;
    }
    foreach ($rows as $row) {
        $key = (string)$row['sub_game'];
        if (isset($result[$key])) {
            $result[$key] = (int)$row['total_sessions'];
        }
    }

    return $result;
}

function savePentathlonPlayerSubGameSessions(PDO $pdo, $playerId, $gameId, array $sessions)
{
    $stmt = $pdo->prepare(
        "INSERT INTO pentathlon_player_sub_game_sessions (player_id, game_id, sub_game, total_sessions)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE total_sessions = VALUES(total_sessions), game_id = VALUES(game_id)"
    );
    foreach (PENTATHLON_SUB_GAMES as $subGame) {
        $count = max(0, (int)($sessions[$subGame] ?? 0));
        $stmt->execute([(int)$playerId, (int)$gameId, $subGame, $count]);
    }
}

function fetchPentathlonPlayerConsumedSessionsBySubGame(PDO $pdo, $playerId, $subscriptionStartDate, $subscriptionEndDate)
{
    $stmt = $pdo->prepare(
        "SELECT pentathlon_sub_game, COUNT(*) AS consumed
         FROM player_attendance
         WHERE player_id = ?
           AND attendance_status = ?
           AND attendance_date BETWEEN ? AND ?
           AND pentathlon_sub_game != ''
         GROUP BY pentathlon_sub_game"
    );
    $stmt->execute([(int)$playerId, PLAYER_ATTENDANCE_STATUS_PRESENT, (string)$subscriptionStartDate, (string)$subscriptionEndDate]);
    $result = [];
    foreach (PENTATHLON_SUB_GAMES as $subGame) {
        $result[$subGame] = 0;
    }
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['pentathlon_sub_game'];
        if (isset($result[$key])) {
            $result[$key] = (int)$row['consumed'];
        }
    }

    return $result;
}

function limitSingleTrainingText($value, $maxLength = 150)
{
    $value = strip_tags(trim((string)$value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function fetchSingleTrainingDefinitions(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        'SELECT id, training_name, price, created_at, updated_at, created_by_user_id, updated_by_user_id
         FROM single_training_prices
         WHERE game_id = ?
         ORDER BY training_name ASC, id DESC'
    );
    $stmt->execute([(int)$gameId]);

    return $stmt->fetchAll();
}

function fetchSingleTrainingDefinitionById(PDO $pdo, $gameId, $trainingId)
{
    $stmt = $pdo->prepare(
        'SELECT id, game_id, training_name, price
         FROM single_training_prices
         WHERE id = ? AND game_id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$trainingId, (int)$gameId]);

    return $stmt->fetch() ?: null;
}

function normalizePlayerNumericInput($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = strtr($value, [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '٫' => '.',
        '٬' => '',
        '،' => '.',
    ]);

    return str_replace([' ', "\xC2\xA0"], '', $value);
}

function normalizePlayerMoneyValue($value)
{
    $value = normalizePlayerNumericInput($value);
    if ($value === '' || !is_numeric($value)) {
        return '';
    }

    $floatValue = (float)$value;
    if ($floatValue < 0) {
        return '';
    }

    return number_format($floatValue, 2, '.', '');
}

function playerCategoryExists($category)
{
    return isset(PLAYER_CATEGORY_OPTIONS[(string)$category]);
}

function isValidPlayerDate($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone('Africa/Cairo'));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d') === $date;
}

function createPlayerDate($date)
{
    return new DateTimeImmutable((string)$date . ' 00:00:00', new DateTimeZone('Africa/Cairo'));
}

function calculatePlayerAgeFromBirthDate($birthDate, ?DateTimeImmutable $referenceDate = null)
{
    $birthDate = trim((string)$birthDate);
    if ($birthDate === '' || !isValidPlayerDate($birthDate)) {
        return 0;
    }

    $birthDateObject = createPlayerDate($birthDate);
    $referenceDate = $referenceDate ?: new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
    if ($birthDateObject > $referenceDate) {
        return 0;
    }

    return (int)$birthDateObject->diff($referenceDate)->y;
}

function getPlayerGroupPriceByCategory(array $group, $category)
{
    $category = (string)$category;
    if ($category === 'مشاة') {
        return (float)($group['walkers_price'] ?? 0);
    }
    if ($category === 'اسلحة اخري') {
        return (float)($group['other_weapons_price'] ?? 0);
    }

    return (float)($group['civilian_price'] ?? 0);
}

function calculatePlayerAcademyAmount($paidAmount, $academyPercentage)
{
    $paidAmount = (float)$paidAmount;
    $academyPercentage = (float)$academyPercentage;
    return round(($paidAmount * $academyPercentage) / 100, 2);
}

function sanitizePlayerTrainingDayKeys(array $dayKeys)
{
    $sanitized = [];
    foreach ($dayKeys as $dayKey) {
        $dayKey = trim((string)$dayKey);
        if ($dayKey !== '' && isset(PLAYER_DAY_OPTIONS[$dayKey])) {
            $sanitized[] = $dayKey;
        }
    }

    return array_values(array_unique($sanitized));
}

function getPlayerTrainingDayKeys($storedValue)
{
    return sanitizePlayerTrainingDayKeys(explode(PLAYER_DAY_SEPARATOR, (string)$storedValue));
}

function formatPlayerTrainingDaysLabel(array $dayKeys)
{
    $labels = [];
    foreach ($dayKeys as $dayKey) {
        if (isset(PLAYER_DAY_OPTIONS[$dayKey])) {
            $labels[] = PLAYER_DAY_OPTIONS[$dayKey];
        }
    }

    return $labels;
}

function normalizeTrainingTimeValue($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $formats = ['H:i:s', 'H:i'];
    foreach ($formats as $format) {
        $dateTime = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Africa/Cairo'));
        if ($dateTime instanceof DateTimeImmutable && $dateTime->format($format) === $value) {
            return $dateTime->format('H:i:s');
        }
    }

    return '';
}

function formatTrainingTimeLabel($value)
{
    $normalizedTime = normalizeTrainingTimeValue($value);
    if ($normalizedTime === '') {
        return '';
    }

    return substr($normalizedTime, 0, 5);
}

function formatTrainingTimeDisplay($value)
{
    $label = formatTrainingTimeLabel($value);
    if ($label === '') {
        return '';
    }

    if (preg_match('/^(\d{2}):(\d{2})$/', $label, $matches) !== 1) {
        return $label;
    }

    $hour = (int)$matches[1];
    $minute = $matches[2];
    $period = $hour >= 12 ? 'م' : 'ص';
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return str_pad((string)$displayHour, 2, '0', STR_PAD_LEFT) . ':' . $minute . ' ' . $period;
}

function countPlayersInGroup(PDO $pdo, $gameId, $groupId, $excludePlayerId = 0)
{
    if ((int)$groupId <= 0) {
        return 0;
    }

    $sql = 'SELECT COUNT(*) FROM players WHERE game_id = ? AND group_id = ?';
    $params = [(int)$gameId, (int)$groupId];

    if ((int)$excludePlayerId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = (int)$excludePlayerId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function fetchGroupPlayerCounts(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        'SELECT group_id, COUNT(*) AS players_count
         FROM players
         WHERE game_id = ? AND group_id IS NOT NULL
         GROUP BY group_id'
    );
    $stmt->execute([(int)$gameId]);

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int)($row['group_id'] ?? 0)] = (int)($row['players_count'] ?? 0);
    }

    return $counts;
}

function getPlayerAttendanceDayKeyFromDate(DateTimeInterface $date)
{
    $dayMap = [
        '0' => 'sunday',
        '1' => 'monday',
        '2' => 'tuesday',
        '3' => 'wednesday',
        '4' => 'thursday',
        '5' => 'friday',
        '6' => 'saturday',
    ];

    return $dayMap[$date->format('w')] ?? '';
}

function calculatePlayerDaysRemaining($subscriptionEndDate, ?DateTimeImmutable $today = null)
{
    if (!isValidPlayerDate($subscriptionEndDate)) {
        return 0;
    }

    $today = $today ?: new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
    $endDate = createPlayerDate($subscriptionEndDate);
    if ($endDate <= $today) {
        return 0;
    }

    return (int)$today->diff($endDate)->days;
}

function calculatePlayerRemainingTrainings($totalTrainings, $attendanceCount)
{
    return max(0, (int)$totalTrainings - (int)$attendanceCount);
}

function getPlayerSubscriptionStatus($daysRemaining, $remainingTrainings)
{
    return (int)$daysRemaining === 0 || (int)$remainingTrainings === 0 ? 'منتهي' : 'مستمر';
}

function formatPlayerCurrency($value)
{
    return number_format((float)$value, 2, '.', '');
}

function formatPlayerAttendanceDateTimeLabel(DateTimeInterface $dateTime)
{
    $hour = (int)$dateTime->format('G');
    $minute = $dateTime->format('i');
    $period = $hour >= 12 ? 'م' : 'ص';
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return $dateTime->format('Y/m/d') . ' - ' . str_pad((string)$displayHour, 2, '0', STR_PAD_LEFT) . ':' . $minute . ' ' . $period;
}

function formatPlayerAttendanceActualTime($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === '') {
        return PLAYER_ATTENDANCE_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone('Africa/Cairo'));
    } catch (Exception $exception) {
        return PLAYER_ATTENDANCE_EMPTY_VALUE;
    }

    $hour = (int)$dateTime->format('G');
    $minute = $dateTime->format('i');
    $period = $hour >= 12 ? 'م' : 'ص';
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return str_pad((string)$displayHour, 2, '0', STR_PAD_LEFT) . ':' . $minute . ' ' . $period;
}

function formatPlayerCurrencyLabel($value)
{
    return number_format((float)$value, 2) . ' ج.م';
}

function formatPlayerPercentageLabel($value)
{
    return number_format((float)$value, 2) . '%';
}

function playerFieldExists(PDO $pdo, $gameId, $fieldName, $fieldValue, $playerId = 0)
{
    if ($fieldName === 'phone') {
        $sql = 'SELECT id FROM players WHERE game_id = ? AND phone = ?';
    } elseif ($fieldName === 'barcode') {
        $sql = 'SELECT id FROM players WHERE game_id = ? AND barcode = ?';
    } else {
        return false;
    }

    $params = [(int)$gameId, (string)$fieldValue];
    if ((int)$playerId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = (int)$playerId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch();
}

function fetchPlayerRowById(PDO $pdo, $playerId, $gameId)
{
    $stmt = $pdo->prepare(
        'SELECT id, game_id, group_id, barcode, name, phone, phone2, whatsapp_group_joined, player_category,
                subscription_start_date, subscription_end_date, group_name, group_level, player_level,
                receipt_number, subscriber_number, subscription_number, issue_date, birth_date, player_age,
                training_days_per_week, total_training_days, total_trainings, trainer_name,
                subscription_price, paid_amount, academy_percentage, academy_amount, training_day_keys, training_time
         FROM players
         WHERE id = ? AND game_id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$playerId, (int)$gameId]);
    return $stmt->fetch() ?: null;
}

function normalizePlayerSubscriptionHistorySourceAction($sourceAction)
{
    $allowedActions = ['save', 'renewal', 'pre_save', 'pre_renewal'];
    $sourceAction = trim((string)$sourceAction);
    return in_array($sourceAction, $allowedActions, true) ? $sourceAction : 'save';
}

function buildPlayerSubscriptionHistoryPayload(array $player, $sourceAction = 'save')
{
    return [
        'player_id' => (int)($player['id'] ?? 0),
        'game_id' => (int)($player['game_id'] ?? 0),
        'group_id' => isset($player['group_id']) && $player['group_id'] !== null ? (int)$player['group_id'] : null,
        'barcode' => (string)($player['barcode'] ?? ''),
        'player_name' => (string)($player['name'] ?? ''),
        'phone' => (string)($player['phone'] ?? ''),
        'phone2' => (string)($player['phone2'] ?? ''),
        'whatsapp_group_joined' => (int)($player['whatsapp_group_joined'] ?? 0),
        'player_category' => (string)($player['player_category'] ?? ''),
        'subscription_start_date' => (string)($player['subscription_start_date'] ?? ''),
        'subscription_end_date' => (string)($player['subscription_end_date'] ?? ''),
        'group_name' => (string)($player['group_name'] ?? ''),
        'group_level' => (string)($player['group_level'] ?? ''),
        'player_level' => (string)($player['player_level'] ?? ''),
        'receipt_number' => (string)($player['receipt_number'] ?? ''),
        'subscriber_number' => (string)($player['subscriber_number'] ?? ''),
        'subscription_number' => (string)($player['subscription_number'] ?? ''),
        'issue_date' => (string)($player['issue_date'] ?? ''),
        'birth_date' => (string)($player['birth_date'] ?? ''),
        'player_age' => (int)($player['player_age'] ?? 0),
        'training_days_per_week' => (int)($player['training_days_per_week'] ?? 1),
        'total_training_days' => (int)($player['total_training_days'] ?? 1),
        'total_trainings' => (int)($player['total_trainings'] ?? 1),
        'trainer_name' => (string)($player['trainer_name'] ?? ''),
        'subscription_price' => formatPlayerCurrency($player['subscription_price'] ?? 0),
        'paid_amount' => formatPlayerCurrency($player['paid_amount'] ?? 0),
        'academy_percentage' => formatPlayerCurrency($player['academy_percentage'] ?? 0),
        'academy_amount' => formatPlayerCurrency($player['academy_amount'] ?? 0),
        'training_day_keys' => (string)($player['training_day_keys'] ?? ''),
        'training_time' => normalizeTrainingTimeValue($player['training_time'] ?? ''),
        'source_action' => normalizePlayerSubscriptionHistorySourceAction($sourceAction),
    ];
}

function playerSubscriptionHistoryMatchesCycle(array $historyRow, array $payload)
{
    return (string)($historyRow['subscription_start_date'] ?? '') === (string)$payload['subscription_start_date']
        && (string)($historyRow['subscription_end_date'] ?? '') === (string)$payload['subscription_end_date'];
}

function playerSubscriptionHistoryRowsAreIdentical(array $historyRow, array $payload)
{
    $nullableIntegerFields = ['group_id'];
    foreach ($nullableIntegerFields as $field) {
        $historyValue = $historyRow[$field] ?? null;
        $payloadValue = $payload[$field] ?? null;
        if ($historyValue === null && $payloadValue === null) {
            continue;
        }
        if ((int)$historyValue !== (int)$payloadValue) {
            return false;
        }
    }

    $integerFields = ['player_id', 'game_id', 'player_age', 'training_days_per_week', 'total_training_days', 'total_trainings', 'whatsapp_group_joined'];
    foreach ($integerFields as $field) {
        if ((int)($historyRow[$field] ?? 0) !== (int)($payload[$field] ?? 0)) {
            return false;
        }
    }

    $decimalFields = ['subscription_price', 'paid_amount', 'academy_percentage', 'academy_amount'];
    foreach ($decimalFields as $field) {
        if (formatPlayerCurrency($historyRow[$field] ?? 0) !== formatPlayerCurrency($payload[$field] ?? 0)) {
            return false;
        }
    }

    $stringFields = [
        'barcode',
        'player_name',
        'phone',
        'phone2',
        'player_category',
        'subscription_start_date',
        'subscription_end_date',
        'group_name',
        'group_level',
        'player_level',
        'receipt_number',
        'subscriber_number',
        'subscription_number',
        'issue_date',
        'birth_date',
        'trainer_name',
        'training_day_keys',
        'training_time',
    ];
    foreach ($stringFields as $field) {
        if ((string)($historyRow[$field] ?? '') !== (string)($payload[$field] ?? '')) {
            return false;
        }
    }

    return true;
}

function syncPlayerSubscriptionHistory(PDO $pdo, array $player, $sourceAction = 'save')
{
    if ((int)($player['id'] ?? 0) <= 0 || (int)($player['game_id'] ?? 0) <= 0) {
        return;
    }

    $payload = buildPlayerSubscriptionHistoryPayload($player, $sourceAction);
    if (!isValidPlayerDate($payload['subscription_start_date']) || !isValidPlayerDate($payload['subscription_end_date'])) {
        return;
    }

    $latestStmt = $pdo->prepare(
        'SELECT *
         FROM player_subscription_history
         WHERE player_id = ? AND game_id = ?
         ORDER BY subscription_start_date DESC, id DESC
         LIMIT 1'
    );
    $latestStmt->execute([$payload['player_id'], $payload['game_id']]);
    $latestHistory = $latestStmt->fetch() ?: null;

    if ($latestHistory && playerSubscriptionHistoryRowsAreIdentical($latestHistory, $payload)) {
        return;
    }

    if ($latestHistory && playerSubscriptionHistoryMatchesCycle($latestHistory, $payload)) {
        $updateStmt = $pdo->prepare(
            'UPDATE player_subscription_history
             SET group_id = ?, barcode = ?, player_name = ?, phone = ?, phone2 = ?, whatsapp_group_joined = ?, player_category = ?,
                 group_name = ?, group_level = ?, player_level = ?, receipt_number = ?, subscriber_number = ?,
                 subscription_number = ?, issue_date = ?, birth_date = ?, player_age = ?, training_days_per_week = ?, total_training_days = ?,
                 total_trainings = ?, trainer_name = ?, subscription_price = ?, paid_amount = ?,
                 academy_percentage = ?, academy_amount = ?, training_day_keys = ?, training_time = ?, source_action = ?
             WHERE id = ?'
        );
        $updateStmt->execute([
            $payload['group_id'],
            $payload['barcode'],
            $payload['player_name'],
            $payload['phone'],
            $payload['phone2'],
            $payload['whatsapp_group_joined'],
            $payload['player_category'],
            $payload['group_name'],
            $payload['group_level'],
            $payload['player_level'],
            $payload['receipt_number'],
            $payload['subscriber_number'],
            $payload['subscription_number'],
            $payload['issue_date'] !== '' ? $payload['issue_date'] : null,
            $payload['birth_date'] !== '' ? $payload['birth_date'] : null,
            $payload['player_age'],
            $payload['training_days_per_week'],
            $payload['total_training_days'],
            $payload['total_trainings'],
            $payload['trainer_name'],
            $payload['subscription_price'],
            $payload['paid_amount'],
            $payload['academy_percentage'],
            $payload['academy_amount'],
            $payload['training_day_keys'],
            $payload['training_time'],
            $payload['source_action'],
            (int)$latestHistory['id'],
        ]);
        return;
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO player_subscription_history (
            player_id, game_id, group_id, barcode, player_name, phone, phone2, whatsapp_group_joined, player_category,
            subscription_start_date, subscription_end_date, group_name, group_level, player_level,
            receipt_number, subscriber_number, subscription_number, issue_date, birth_date, player_age,
            training_days_per_week, total_training_days, total_trainings, trainer_name,
            subscription_price, paid_amount, academy_percentage, academy_amount, training_day_keys, training_time, source_action
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $payload['player_id'],
        $payload['game_id'],
        $payload['group_id'],
        $payload['barcode'],
        $payload['player_name'],
        $payload['phone'],
        $payload['phone2'],
        $payload['whatsapp_group_joined'],
        $payload['player_category'],
        $payload['subscription_start_date'],
        $payload['subscription_end_date'],
        $payload['group_name'],
        $payload['group_level'],
        $payload['player_level'],
        $payload['receipt_number'],
        $payload['subscriber_number'],
        $payload['subscription_number'],
        $payload['issue_date'] !== '' ? $payload['issue_date'] : null,
        $payload['birth_date'] !== '' ? $payload['birth_date'] : null,
        $payload['player_age'],
        $payload['training_days_per_week'],
        $payload['total_training_days'],
        $payload['total_trainings'],
        $payload['trainer_name'],
        $payload['subscription_price'],
        $payload['paid_amount'],
        $payload['academy_percentage'],
        $payload['academy_amount'],
        $payload['training_day_keys'],
        $payload['training_time'],
        $payload['source_action'],
    ]);
}

function syncPlayerSubscriptionHistoryFromPlayerId(PDO $pdo, $playerId, $gameId, $sourceAction = 'save')
{
    $player = fetchPlayerRowById($pdo, $playerId, $gameId);
    if ($player) {
        syncPlayerSubscriptionHistory($pdo, $player, $sourceAction);
    }
}

function fetchPlayerSubscriptionHistory(PDO $pdo, $playerId, $gameId)
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM player_subscription_history
         WHERE player_id = ? AND game_id = ?
         ORDER BY subscription_start_date DESC, subscription_end_date DESC, id DESC'
    );
    $stmt->execute([(int)$playerId, (int)$gameId]);
    return $stmt->fetchAll();
}

/**
 * إزالة المحارف التحكمية غير الصالحة من النص قبل إضافته إلى ملف Excel.
 *
 * @param mixed $value
 * @return string
 */
function sanitizePlayersExcelTextValue($value)
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$value);
}

/**
 * تجهيز النص لملفات Excel XML.
 */
function escapePlayersExcelXml($value)
{
    return htmlspecialchars(sanitizePlayersExcelTextValue($value), ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/**
 * تحويل رقم العمود إلى حرف Excel (1->A, 2->B, ...)
 */
function getPlayersExcelColumnName($columnNumber)
{
    $columnNumber = (int)$columnNumber;
    if ($columnNumber <= 0) {
        return 'A';
    }

    $columnName = '';
    while ($columnNumber > 0) {
        $columnNumber--;
        $columnName = chr(65 + ($columnNumber % 26)) . $columnName;
        $columnNumber = (int)floor($columnNumber / 26);
    }

    return $columnName;
}

function buildPlayersXlsxWorksheetCell($cellReference, $value, $styleId)
{
    return '<c r="' . $cellReference . '" s="' . $styleId . '" t="inlineStr"><is><t xml:space="preserve">' . escapePlayersExcelXml($value) . '</t></is></c>';
}

function buildPlayersXlsxWorksheetXml(array $headers, array $rows)
{
    $columnWidths = [];
    $columnCount = count($headers);
    for ($index = 0; $index < $columnCount; $index++) {
        $width = 12;
        if ($index === 0) $width = 8;
        if ($index === 1) $width = 15;
        if ($index === 2) $width = 20;
        if ($index === 3) $width = 18;
        if ($index === 4) $width = 12;
        if ($index === 5) $width = 18;
        if ($index === 6) $width = 18;
        if ($index === 7) $width = 25;
        if ($index === 8 || $index === 9 || $index === 10) $width = 10;
        if ($index === 11) $width = 15;
        if ($index === 12 || $index === 13) $width = 14;
        if ($index === 14 || $index === 15 || $index === 16) $width = 12;
        if ($index === 17 || $index === 18) $width = 14;
        if ($index >= 19 && $index <= 20) $width = 14;
        if ($index === $columnCount - 1) $width = 12;
        $columnWidths[] = $width;
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>';
    $xml .= '<cols>';
    foreach ($columnWidths as $index => $width) {
        $columnIndex = $index + 1;
        $xml .= '<col min="' . $columnIndex . '" max="' . $columnIndex . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    $xml .= '<row r="1" ht="20" customHeight="1">';
    foreach ($headers as $columnIndex => $header) {
        $xml .= buildPlayersXlsxWorksheetCell(getPlayersExcelColumnName($columnIndex + 1) . '1', $header, 1);
    }
    $xml .= '</row>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $columnIndex => $cellValue) {
            $value = sanitizePlayersExcelTextValue($cellValue);
            $isPhoneColumn = $columnIndex === 3;
            if ($isPhoneColumn && preg_match('/^\d+$/', $value) === 1) {
                $value = "'" . $value;
            }

            $xml .= buildPlayersXlsxWorksheetCell(
                getPlayersExcelColumnName($columnIndex + 1) . $excelRow,
                $value,
                2
            );
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    if (count($rows) > 0) {
        $xml .= '<autoFilter ref="A1:' . getPlayersExcelColumnName($columnCount) . (count($rows) + 1) . '"/>';
    }
    $xml .= '</worksheet>';

    return $xml;
}

function buildPlayersXlsxStylesXml()
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
        <fill><patternFill patternType="solid"><fgColor rgb="FF2F5BEA"/><bgColor indexed="64"/></patternFill></fill>
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
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>
    </cellXfs>
    <cellStyles count="1">
        <cellStyle name="Normal" xfId="0" builtinId="0"/>
    </cellStyles>
</styleSheet>';
}

/**
 * تصدير البيانات إلى ملف XLSX حقيقي مع تنسيق محسّن ودعم الأصفار في أرقام الهواتف
 */
function outputPlayersXlsxDownload($fileName, array $headers, array $rows)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد ZipArchive غير متاح.');
    }

    $safeFileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($fileName));
    if ($safeFileName === '' || $safeFileName === '.' || $safeFileName === '..') {
        $safeFileName = 'players.xlsx';
    }
    if (strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION)) !== 'xlsx') {
        $safeFileName .= '.xlsx';
    }

    $sheetXml = buildPlayersXlsxWorksheetXml($headers, $rows);
    $stylesXml = buildPlayersXlsxStylesXml();
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d\\TH:i:sP');
    $zipPath = sys_get_temp_dir() . '/players-export-' . uniqid('', true) . '.xlsx';
    $tempFileDeleted = false;
    $cleanupTempFile = static function () use ($zipPath, &$tempFileDeleted) {
        if ($tempFileDeleted) {
            return;
        }

        $tempFileDeleted = true;
        if (file_exists($zipPath) && !unlink($zipPath)) {
            error_log('Failed to delete temporary player XLSX file: ' . basename($zipPath));
        }
    };
    register_shutdown_function($cleanupTempFile);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('تعذر تجهيز ملف الإكسل.');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>');

    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>Believe Sports Academy</Application>
</Properties>');

    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>Players Export</dc:title>
    <dc:creator>Believe Sports Academy</dc:creator>
    <cp:lastModifiedBy>Believe Sports Academy</cp:lastModifiedBy>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>
</cp:coreProperties>');

    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="اللاعبين" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

    $zip->close();

    // تنظيف أي مخرجات مخزنة مؤقتًا قبل إرسال الملف حتى لا يتلف ملف Excel.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeFileName . '"; filename*=UTF-8\'\'' . rawurlencode($safeFileName));
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: max-age=0');
    readfile($zipPath);
    $cleanupTempFile();
    exit;
}

// ========== دوال إدارة ملفات اللاعبين ==========

/**
 * الحصول على مسار تخزين ملفات اللاعبين
 */
function getPlayerFilesUploadPath()
{
    $baseDir = __DIR__ . '/uploads/player_files/';
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }
    return $baseDir;
}

/**
 * تنظيف اسم الملف للاستخدام الآمن على السيرفر
 */
function sanitizeFileName($name)
{
    $name = preg_replace('/[^a-zA-Z0-9_\-.()]/u', '_', $name);
    $name = str_replace(' ', '_', $name);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $basename = pathinfo($name, PATHINFO_FILENAME);
    $basename = substr($basename, 0, 60);
    return $basename . '.' . $ext;
}

/**
 * الحصول على جميع ملفات لاعب معين
 */
function getPlayerFiles(PDO $pdo, $playerId)
{
    $stmt = $pdo->prepare('SELECT * FROM player_files WHERE player_id = ? ORDER BY id DESC');
    $stmt->execute([$playerId]);
    return $stmt->fetchAll();
}

/**
 * رفع ملف للاعب
 */
function uploadPlayerFile(PDO $pdo, $playerId, $uploadedFile, $label)
{
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'فشل رفع الملف.'];
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($uploadedFile['type'], $allowedTypes)) {
        return ['success' => false, 'error' => 'نوع الملف غير مسموح. الصور فقط (JPEG, PNG, GIF, WEBP).'];
    }
    
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($uploadedFile['size'] > $maxSize) {
        return ['success' => false, 'error' => 'حجم الملف يتجاوز 5 ميجابايت.'];
    }
    
    $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    $originalName = $uploadedFile['name'];
    $safeFileName = uniqid('player_', true) . '.' . $ext;
    $targetPath = getPlayerFilesUploadPath() . $safeFileName;
    
    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'تعذر نقل الملف إلى المجلد المخصص.'];
    }
    
    $label = trim(strip_tags($label));
    if ($label === '') {
        $label = pathinfo($originalName, PATHINFO_FILENAME);
    }
    
    $stmt = $pdo->prepare(
        'INSERT INTO player_files (player_id, file_name, original_name, label, file_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $playerId,
        $safeFileName,
        $originalName,
        $label,
        $uploadedFile['type'],
        $uploadedFile['size']
    ]);

    $newFileId = (int)$pdo->lastInsertId();
    auditTrack($pdo, "create", "player_files", $newFileId, "ملفات اللاعبين", "رفع ملف للاعب رقم " . (int)$playerId . ": " . $label);
    return ['success' => true, 'file_id' => $newFileId];
}

/**
 * حذف ملف لاعب
 */
function deletePlayerFile(PDO $pdo, $fileId, $playerId)
{
    $stmt = $pdo->prepare('SELECT file_name FROM player_files WHERE id = ? AND player_id = ?');
    $stmt->execute([$fileId, $playerId]);
    $file = $stmt->fetch();
    if (!$file) {
        return ['success' => false, 'error' => 'الملف غير موجود.'];
    }
    
    $filePath = getPlayerFilesUploadPath() . $file['file_name'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    
    $stmt = $pdo->prepare('DELETE FROM player_files WHERE id = ? AND player_id = ?');
    $stmt->execute([$fileId, $playerId]);

    auditLogActivity($pdo, "delete", "player_files", (int)$fileId, "ملفات اللاعبين", "حذف ملف للاعب رقم " . (int)$playerId);
    return ['success' => true];
}

/**
 * تحديث تسمية ملف لاعب
 */
function updatePlayerFileLabel(PDO $pdo, $fileId, $playerId, $newLabel)
{
    $newLabel = trim(strip_tags($newLabel));
    if ($newLabel === '') {
        return ['success' => false, 'error' => 'التسمية لا يمكن أن تكون فارغة.'];
    }
    
    $stmt = $pdo->prepare('UPDATE player_files SET label = ? WHERE id = ? AND player_id = ?');
    $stmt->execute([$newLabel, $fileId, $playerId]);
    
    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'error' => 'الملف غير موجود أو لم يتغير.'];
    }
    auditTrack($pdo, "update", "player_files", (int)$fileId, "ملفات اللاعبين", "تعديل تسمية ملف للاعب رقم " . (int)$playerId . " إلى: " . $newLabel);
    return ['success' => true];
}
?>
