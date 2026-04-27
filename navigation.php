<?php
function getApplicationNavigationItems()
{
    return [
                ["key" => "home", "label" => "🏠 الصفحة الرئيسية", "href" => "dashboard.php", "tone" => "tone-blue", "assignable" => false, "manager_only" => false, "show_on_dashboard" => false],
                ["key" => "users", "label" => "👥 المستخدمين", "href" => "users.php", "tone" => "tone-cyan", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "user-permissions", "label" => "🛡️ صلاحيات المستخدمين", "href" => "user_permissions.php", "tone" => "tone-violet", "assignable" => false, "manager_only" => true, "show_on_dashboard" => true],
                ["key" => "groups", "label" => "🏘️ المجموعات", "href" => "groups.php", "tone" => "tone-teal", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "player-seating", "label" => "🪑 تسكين لاعبين", "href" => "player_seating.php", "tone" => "tone-teal", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "players", "label" => "⚽ اللاعبين", "href" => "players.php", "tone" => "tone-indigo", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "players-attendance", "label" => "✅ حضور اللاعبين", "href" => "players_attendance.php", "tone" => "tone-green", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "subscription-renewal", "label" => "🔄 تجديد الاشتراك", "href" => "subscription_renewal.php", "tone" => "tone-purple", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "single-training", "label" => "🏷️ سعر تمرينة واحدة", "href" => "single_training.php", "tone" => "tone-orange", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "players-notifications", "label" => "📣 إشعارات اللاعبين", "href" => "players_notifications.php", "tone" => "tone-pink", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "admins", "label" => "👑 الإداريين", "href" => "admins.php", "tone" => "tone-violet", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "admins-attendance", "label" => "🗓️ حضور الإداريين", "href" => "admin_attendance.php", "tone" => "tone-orange", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "admins-loans", "label" => "💰 سلف الإداريين", "href" => "admin_loans.php", "tone" => "tone-gold", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "admins-deductions", "label" => "📉 خصومات الإداريين", "href" => "admin_deductions.php", "tone" => "tone-rose", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "admins-collections", "label" => "💵 قبض الإداريين", "href" => "admin_collections.php", "tone" => "tone-emerald", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "admins-notifications", "label" => "📣 إشعارات الإداريين", "href" => "admin_notifications.php", "tone" => "tone-pink", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "trainers", "label" => "🏋️ المدربين", "href" => "trainers.php", "tone" => "tone-green", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "trainers-attendance", "label" => "🗓️ حضور المدربين", "href" => "trainer_attendance.php", "tone" => "tone-orange", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "trainers-loans", "label" => "💰 سلف المدربين", "href" => "trainer_loans.php", "tone" => "tone-gold", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "trainers-deductions", "label" => "📉 خصومات المدربين", "href" => "trainer_deductions.php", "tone" => "tone-rose", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "trainers-collections", "label" => "💵 قبض المدربين", "href" => "trainer_collections.php", "tone" => "tone-emerald", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "trainers-notifications", "label" => "🔔 إشعارات المدربين", "href" => "trainer_notifications.php", "tone" => "tone-red", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "offers", "label" => "🎁 العروض", "href" => "offers.php", "tone" => "tone-gold", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "categories", "label" => "🧾 الأصناف", "href" => "categories.php", "tone" => "tone-cyan", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "sales", "label" => "🛒 المبيعات", "href" => "sales.php", "tone" => "tone-orange", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "store", "label" => "🏪 المتجر", "href" => "store.php", "tone" => "tone-indigo", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "store-orders", "label" => "📦 طلبات المتجر", "href" => "player_store_orders.php", "tone" => "tone-orange", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "expenses", "label" => "💸 المصروفات", "href" => "expenses.php", "tone" => "tone-red", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "statistics", "label" => "📊 الاحصائيات", "href" => "statistics.php", "tone" => "tone-violet", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "activity-log", "label" => "📜 سجل النشاطات", "href" => "activity_log.php", "tone" => "tone-violet", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "potential-customers", "label" => "🌟 العملاء المحتملين", "href" => "potential_customers.php", "tone" => "tone-teal", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "branches", "label" => "🏢 الفروع", "href" => "branches.php", "tone" => "tone-blue", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "games", "label" => "🎮 الألعاب", "href" => "games.php", "tone" => "tone-green", "assignable" => true, "manager_only" => false, "show_on_dashboard" => true],
                ["key" => "settings", "label" => "⚙️ إعدادات الموقع", "href" => "settings.php", "tone" => "tone-gold", "assignable" => false, "manager_only" => true, "show_on_dashboard" => true],

    ];
}

function getPermissionManagedNavigationItems()
{
    return array_values(array_filter(getApplicationNavigationItems(), function ($item) {
        return !empty($item["assignable"]);
    }));
}

function getAllNavigationKeys()
{
    return array_values(array_map(function ($item) {
        return $item["key"];
    }, getApplicationNavigationItems()));
}

function getDefaultMenuPermissionKeys($role = "")
{
    if ($role === "مدير") {
        return getAllNavigationKeys();
    }

    $keys = ["home"];
    foreach (getPermissionManagedNavigationItems() as $item) {
        $keys[] = $item["key"];
    }

    return array_values(array_unique($keys));
}

function sanitizeMenuPermissionKeys(array $keys)
{
    $allowedKeys = array_values(array_map(function ($item) {
        return $item["key"];
    }, getPermissionManagedNavigationItems()));

    $sanitized = [];
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key !== "" && in_array($key, $allowedKeys, true)) {
            $sanitized[] = $key;
        }
    }

    return array_values(array_unique($sanitized));
}

function ensureUserGamePermissionsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_game_permissions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            game_id INT(11) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_game_permission (user_id, game_id, permission_key),
            KEY idx_user_game (user_id, game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === "") {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'user_game_permissions'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    if (!in_array("fk_user_game_permissions_user", $existingConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE user_game_permissions
             ADD CONSTRAINT fk_user_game_permissions_user
             FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE"
        );
    }

    if (!in_array("fk_user_game_permissions_game", $existingConstraints, true)) {
        $pdo->exec(
            "ALTER TABLE user_game_permissions
             ADD CONSTRAINT fk_user_game_permissions_game
             FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
        );
    }
}

function fetchStoredMenuPermissionKeys(PDO $pdo, $userId, $gameId)
{
    ensureUserGamePermissionsTable($pdo);

    $stmt = $pdo->prepare(
        "SELECT permission_key
         FROM user_game_permissions
         WHERE user_id = ? AND game_id = ?
         ORDER BY permission_key ASC"
    );
    $stmt->execute([(int)$userId, (int)$gameId]);

    return sanitizeMenuPermissionKeys(array_column($stmt->fetchAll(), "permission_key"));
}

function getAllowedMenuPermissionKeys(PDO $pdo, array $user, $gameId)
{
    $role = (string)($user["role"] ?? "");
    if ($role === "مدير") {
        return getDefaultMenuPermissionKeys($role);
    }

    $storedKeys = fetchStoredMenuPermissionKeys($pdo, (int)($user["id"] ?? 0), (int)$gameId);
    if (count($storedKeys) === 0) {
        return getDefaultMenuPermissionKeys($role);
    }

    return array_values(array_unique(array_merge(["home"], $storedKeys)));
}

function getSessionAllowedMenuKeys()
{
    $role = (string)($_SESSION["role"] ?? "");
    $storedKeys = $_SESSION["menu_permissions"] ?? [];
    if (!is_array($storedKeys) || count($storedKeys) === 0) {
        return getDefaultMenuPermissionKeys($role);
    }

    if ($role === "مدير") {
        return getDefaultMenuPermissionKeys($role);
    }

    return array_values(array_unique(array_merge(["home"], sanitizeMenuPermissionKeys($storedKeys))));
}

function userCanAccessMenuKey($menuKey)
{
    $role = (string)($_SESSION["role"] ?? "");
    if ($role === "مدير") {
        return in_array($menuKey, getAllNavigationKeys(), true);
    }

    return in_array($menuKey, getSessionAllowedMenuKeys(), true);
}

function requireMenuAccess($menuKey, $location = "dashboard.php")
{
    if (!userCanAccessMenuKey($menuKey)) {
        header("Location: " . $location);
        exit;
    }
}

function getVisibleNavigationItemsForCurrentSession()
{
    $allowedKeys = getSessionAllowedMenuKeys();
    $role = (string)($_SESSION["role"] ?? "");
    $visibleItems = [];

    foreach (getApplicationNavigationItems() as $item) {
        $key = $item["key"];
        if (!empty($item["manager_only"]) && $role !== "مدير") {
            continue;
        }

        if ($role !== "مدير" && !in_array($key, $allowedKeys, true)) {
            continue;
        }

        $visibleItems[] = $item;
    }

    return $visibleItems;
}

function getDashboardShortcutItemsForCurrentSession()
{
    return array_values(array_filter(getVisibleNavigationItemsForCurrentSession(), function ($item) {
        return !empty($item["show_on_dashboard"]) && $item["key"] !== "home";
    }));
}
?>
