<?php

function bootstrapCoreApplicationDatabase(PDO $pdo)
{
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    try {
        $existingTablesStmt = $pdo->prepare(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('settings', 'users', 'games', 'user_games')"
        );
        $existingTablesStmt->execute();
        $existingTables = array_column($existingTablesStmt->fetchAll(), "TABLE_NAME");
        if (count($existingTables) === 4) {
            return;
        }
    } catch (Throwable $throwable) {
        error_log("bootstrapCoreApplicationDatabase: failed to inspect existing tables: " . $throwable->getMessage());
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS settings (
            id INT(11) NOT NULL AUTO_INCREMENT,
            academy_name VARCHAR(255) NOT NULL DEFAULT 'أكاديمية رياضية',
            academy_logo VARCHAR(255) NOT NULL DEFAULT 'assets/images/logo.png',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS users (
            id INT(11) NOT NULL AUTO_INCREMENT,
            username VARCHAR(150) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'مشرف',
            can_access_all_games TINYINT(1) NOT NULL DEFAULT 0,
            can_access_all_branches TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS games (
            id INT(11) NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            branch_id INT(11) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_games_branch (branch_id),
            UNIQUE KEY uniq_games_branch_name (branch_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS user_games (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_game (user_id, game_id),
            KEY idx_user_games_user (user_id),
            KEY idx_user_games_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function bootstrapFeatureApplicationDatabase(PDO $pdo)
{
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    try {
        $existingTablesStmt = $pdo->prepare(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('activity_log', 'sports_groups', 'players', 'admins', 'trainers', 'store_products', 'admin_salary_payments', 'trainer_salary_payments')"
        );
        $existingTablesStmt->execute();
        $existingTables = array_column($existingTablesStmt->fetchAll(), "TABLE_NAME");
        if (count($existingTables) === 8) {
            return;
        }
    } catch (Throwable $throwable) {
        error_log("bootstrapFeatureApplicationDatabase: failed to inspect existing tables: " . $throwable->getMessage());
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS user_game_permissions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_game_permission (user_id, game_id, permission_key),
            KEY idx_user_game (user_id, game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS dashboard_notification_reads (
            user_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            alert_key VARCHAR(191) NOT NULL,
            read_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, game_id, alert_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS sports_groups (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            group_name VARCHAR(150) NOT NULL,
            group_level VARCHAR(150) NOT NULL,
            training_days_count INT(11) NOT NULL DEFAULT 1,
            training_day_keys VARCHAR(255) NOT NULL DEFAULT '',
            training_time TIME NULL DEFAULT NULL,
            trainings_count INT(11) NOT NULL DEFAULT 1,
            exercises_count INT(11) NOT NULL DEFAULT 1,
            max_players INT(11) NOT NULL DEFAULT 1,
            trainer_name VARCHAR(150) NOT NULL,
            academy_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            walkers_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            other_weapons_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            civilian_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sports_groups_game (game_id),
            KEY idx_sports_groups_game_level (game_id, group_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
            password VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_players_game (game_id),
            KEY idx_players_group (group_id),
            KEY idx_players_barcode (barcode),
            KEY idx_players_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS player_attendance (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            player_id INT(11) NOT NULL,
            attendance_date DATE NOT NULL,
            attendance_day_key VARCHAR(20) NOT NULL,
            attendance_status VARCHAR(20) NOT NULL DEFAULT 'حضور',
            attendance_minutes_late INT(11) NOT NULL DEFAULT 0,
            attendance_at DATETIME NULL DEFAULT NULL,
            pentathlon_sub_game VARCHAR(100) NOT NULL DEFAULT '',
            attendance_source VARCHAR(50) NOT NULL DEFAULT '',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_player_attendance_day (player_id, attendance_date),
            KEY idx_player_attendance_game_date (game_id, attendance_date),
            KEY idx_player_attendance_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
            KEY idx_player_notifications_group (game_id, target_group_id),
            KEY idx_player_notifications_player (game_id, target_player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS player_notification_reads (
            notification_id INT(11) NOT NULL,
            player_id INT(11) NOT NULL,
            read_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notification_id, player_id),
            KEY idx_player_notification_reads_player (player_id, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS player_subscription_alerts (
            id INT(11) NOT NULL AUTO_INCREMENT,
            player_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            alert_key VARCHAR(50) NOT NULL,
            subscription_start_date DATE NOT NULL,
            subscription_end_date DATE NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_player_subscription_alert (player_id, alert_key, subscription_start_date, subscription_end_date),
            KEY idx_player_subscription_alerts_game (game_id),
            KEY idx_player_subscription_alerts_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS admins (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            barcode VARCHAR(100) NOT NULL DEFAULT '',
            attendance_time TIME NOT NULL,
            departure_time TIME NOT NULL,
            salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            password VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admins_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS admin_days_off (
            id INT(11) NOT NULL AUTO_INCREMENT,
            admin_id INT(11) NOT NULL,
            day_key VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_admin_day_off (admin_id, day_key),
            KEY idx_admin_days_off_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS admin_loans (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            admin_id INT(11) NOT NULL,
            admin_name VARCHAR(150) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            loan_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_loans_game_date (game_id, loan_date),
            KEY idx_admin_loans_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS admin_deductions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            admin_id INT(11) NOT NULL,
            admin_name VARCHAR(150) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reason TEXT NOT NULL,
            deduction_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_deductions_game_date (game_id, deduction_date),
            KEY idx_admin_deductions_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority_level VARCHAR(20) NOT NULL DEFAULT 'normal',
            visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible',
            display_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_notifications_game_date (game_id, display_date),
            KEY idx_admin_notifications_status (game_id, visibility_status),
            KEY idx_admin_notifications_priority (game_id, priority_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS admin_salary_payments (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            admin_id INT(11) NOT NULL,
            admin_name VARCHAR(150) NOT NULL DEFAULT '',
            salary_month DATE NOT NULL,
            base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            loans_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            deductions_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            net_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            actual_paid_amount DECIMAL(10,2) DEFAULT NULL,
            attendance_days INT(11) NOT NULL DEFAULT 0,
            absent_days INT(11) NOT NULL DEFAULT 0,
            late_days INT(11) NOT NULL DEFAULT 0,
            early_departure_days INT(11) NOT NULL DEFAULT 0,
            overtime_days INT(11) NOT NULL DEFAULT 0,
            paid_by_user_id INT(11) DEFAULT NULL,
            paid_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_admin_salary_payment_month (game_id, admin_id, salary_month),
            KEY idx_admin_salary_payments_game_month (game_id, salary_month),
            KEY idx_admin_salary_payments_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS trainers (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            barcode VARCHAR(100) NOT NULL DEFAULT '',
            attendance_time TIME NOT NULL,
            departure_time TIME NOT NULL,
            salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trainers_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS trainer_days_off (
            id INT(11) NOT NULL AUTO_INCREMENT,
            trainer_id INT(11) NOT NULL,
            day_key VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_trainer_day_off (trainer_id, day_key),
            KEY idx_trainer_days_off_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
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
            actual_work_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_trainer_attendance_day (trainer_id, attendance_date),
            KEY idx_trainer_attendance_game_date (game_id, attendance_date),
            KEY idx_trainer_attendance_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS trainer_loans (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            trainer_id INT(11) NOT NULL,
            trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            loan_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trainer_loans_game_date (game_id, loan_date),
            KEY idx_trainer_loans_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS trainer_deductions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            trainer_id INT(11) NOT NULL,
            trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reason TEXT NOT NULL,
            deduction_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trainer_deductions_game_date (game_id, deduction_date),
            KEY idx_trainer_deductions_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS trainer_notifications (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority_level VARCHAR(20) NOT NULL DEFAULT 'normal',
            visibility_status VARCHAR(20) NOT NULL DEFAULT 'visible',
            display_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trainer_notifications_game_date (game_id, display_date),
            KEY idx_trainer_notifications_status (game_id, visibility_status),
            KEY idx_trainer_notifications_priority (game_id, priority_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS trainer_salary_payments (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            trainer_id INT(11) NOT NULL,
            trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            salary_month DATE NOT NULL,
            base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            loans_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            deductions_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            net_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            actual_paid_amount DECIMAL(10,2) DEFAULT NULL,
            attendance_days INT(11) NOT NULL DEFAULT 0,
            absent_days INT(11) NOT NULL DEFAULT 0,
            late_days INT(11) NOT NULL DEFAULT 0,
            early_departure_days INT(11) NOT NULL DEFAULT 0,
            overtime_days INT(11) NOT NULL DEFAULT 0,
            paid_by_user_id INT(11) DEFAULT NULL,
            paid_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_trainer_salary_payment_month (game_id, trainer_id, salary_month),
            KEY idx_trainer_salary_payments_game_month (game_id, salary_month),
            KEY idx_trainer_salary_payments_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS store_categories (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            category_name VARCHAR(150) NOT NULL,
            pricing_type VARCHAR(30) NOT NULL DEFAULT 'price_only',
            quantity INT(11) NULL DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_store_category_name_per_game (game_id, category_name),
            KEY idx_store_categories_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS store_products (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            image_path VARCHAR(255) NULL DEFAULT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_store_product_name_per_game (game_id, product_name),
            KEY idx_store_products_game (game_id),
            KEY idx_store_products_availability (game_id, is_available)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS store_orders (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            player_id INT(11) NOT NULL,
            product_id INT(11) NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity INT(11) NOT NULL DEFAULT 1,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            customer_name VARCHAR(150) NOT NULL DEFAULT '',
            customer_phone VARCHAR(50) NOT NULL DEFAULT '',
            delivery_address TEXT NOT NULL,
            notes TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_response TEXT NULL,
            responded_by_user_id INT(11) NULL DEFAULT NULL,
            responded_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_orders_game (game_id),
            KEY idx_store_orders_player (player_id),
            KEY idx_store_orders_status (game_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS store_expenses (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            expense_date DATE NOT NULL,
            statement_text VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_expenses_game_date (game_id, expense_date),
            KEY idx_store_expenses_created_by (created_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS sales_invoices (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            customer_name VARCHAR(255) NOT NULL DEFAULT '',
            invoice_type VARCHAR(20) NOT NULL DEFAULT 'purchase',
            invoice_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sales_invoices_game_date (game_id, invoice_date),
            KEY idx_sales_invoices_type (invoice_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS sales_invoice_items (
            id INT(11) NOT NULL AUTO_INCREMENT,
            invoice_id INT(11) NOT NULL,
            category_id INT(11) NOT NULL,
            category_name VARCHAR(150) NOT NULL,
            quantity INT(11) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sales_invoice_items_invoice (invoice_id),
            KEY idx_sales_invoice_items_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS offers (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            title VARCHAR(180) NOT NULL,
            details TEXT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_offers_game (game_id),
            KEY idx_offers_updated (game_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS potential_customers (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            player_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            notes TEXT NULL DEFAULT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_potential_customers_game (game_id),
            KEY idx_potential_customers_created_by (created_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function seedDefaultApplicationData(PDO $pdo)
{
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    $settingsCount = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($settingsCount === 0) {
        $insertSettingsStmt = $pdo->prepare(
            "INSERT INTO settings (academy_name, academy_logo) VALUES (?, ?)"
        );
        $insertSettingsStmt->execute(["أكاديمية رياضية", "assets/images/logo.png"]);
    }

    $branches = [];
    try {
        $branches = $pdo->query("SELECT id, name FROM branches WHERE status = 1 ORDER BY id ASC")->fetchAll();
    } catch (Throwable $throwable) {
        error_log("seedDefaultApplicationData: failed to fetch active branches: " . $throwable->getMessage());
    }

    $gamesCount = (int)$pdo->query("SELECT COUNT(*) FROM games WHERE status = 1")->fetchColumn();
    if ($gamesCount === 0 && count($branches) > 0) {
        $insertGameStmt = $pdo->prepare(
            "INSERT INTO games (name, branch_id, status) VALUES (?, ?, 1)"
        );
        foreach ($branches as $branch) {
            $insertGameStmt->execute(["اللعبة الرئيسية", (int)$branch["id"]]);
        }
    }

    $adminStmt = $pdo->prepare("SELECT id, password, role FROM users WHERE username = ? LIMIT 1");
    $adminStmt->execute(["admin"]);
    $adminUser = $adminStmt->fetch();

    $passwordHash = password_hash("123456", PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        error_log("seedDefaultApplicationData: failed to hash default admin password.");
        return;
    }

    if ($adminUser) {
        $storedPassword = (string)($adminUser["password"] ?? "");
        $passwordInfo = password_get_info($storedPassword);
        $passwordMatches = !empty($passwordInfo["algo"]) && password_verify("123456", $storedPassword);

        if (!$passwordMatches || (string)($adminUser["role"] ?? "") !== "مدير") {
            $updateAdminStmt = $pdo->prepare(
                "UPDATE users
                 SET password = ?, role = 'مدير', can_access_all_games = 1, can_access_all_branches = 1, status = 1
                 WHERE id = ?"
            );
            $updateAdminStmt->execute([$passwordHash, (int)$adminUser["id"]]);
        } else {
            $updateFlagsStmt = $pdo->prepare(
                "UPDATE users
                 SET role = 'مدير', can_access_all_games = 1, can_access_all_branches = 1, status = 1
                 WHERE id = ?"
            );
            $updateFlagsStmt->execute([(int)$adminUser["id"]]);
        }

        $adminUserId = (int)$adminUser["id"];
    } else {
        $insertAdminStmt = $pdo->prepare(
            "INSERT INTO users (username, password, role, can_access_all_games, can_access_all_branches, status)
             VALUES (?, ?, 'مدير', 1, 1, 1)"
        );
        $insertAdminStmt->execute(["admin", $passwordHash]);
        $adminUserId = (int)$pdo->lastInsertId();
    }

    if ($adminUserId <= 0) {
        return;
    }

    try {
        $pdo->prepare(
            "INSERT IGNORE INTO user_branches (user_id, branch_id)
             SELECT ?, b.id FROM branches b WHERE b.status = 1"
        )->execute([$adminUserId]);
    } catch (Throwable $throwable) {
        error_log("seedDefaultApplicationData: failed to grant admin branch access: " . $throwable->getMessage());
    }

    try {
        $pdo->prepare(
            "INSERT IGNORE INTO user_games (user_id, game_id)
             SELECT ?, g.id FROM games g WHERE g.status = 1"
        )->execute([$adminUserId]);
    } catch (Throwable $throwable) {
        error_log("seedDefaultApplicationData: failed to grant admin game access: " . $throwable->getMessage());
    }
}
