<?php
/**
 * Real-time sync helper.
 * - Maintains a per-page list of "watched" tables (which changes are relevant).
 * - Builds the small JS snippet that is auto-injected before </body>.
 * - The actual change feed comes from the existing `activity_log` table
 *   that every write goes through via auditTrack().
 */

if (!function_exists("realtimeSyncGetWatchMap")) {
    function realtimeSyncGetWatchMap()
    {
        return [
            "dashboard.php"             => "*",
            "activity_log.php"          => "*",

            "players.php"               => ["players", "sports_groups", "player_attendance", "player_subscription_history", "player_files"],
            "players_attendance.php"    => ["player_attendance", "players", "sports_groups"],
            "players_notifications.php" => ["players"],
            "subscription_renewal.php"  => ["players", "player_subscription_history"],
            "single_training.php"       => ["single_training_attendance", "single_training_prices", "players"],
            "player_seating.php"        => ["players", "sports_groups"],
            "players_support.php"       => ["players", "sports_groups"],

            "admins.php"                => ["admins"],
            "admin_attendance.php"      => ["admin_attendance", "admin_days_off", "admin_weekly_schedule", "admins"],
            "admin_loans.php"           => ["admin_loans", "admins"],
            "admin_deductions.php"      => ["admin_deductions", "admins"],
            "admin_collections.php"     => ["admin_collections", "admins"],
            "admin_notifications.php"   => ["admins"],

            "trainers.php"              => ["trainers"],
            "trainer_attendance.php"    => ["trainer_attendance", "trainer_days_off", "trainer_weekly_schedule", "trainer_salary_payments", "trainers"],
            "trainer_loans.php"         => ["trainer_loans", "trainers"],
            "trainer_deductions.php"    => ["trainer_deductions", "trainers"],
            "trainer_collections.php"   => ["trainer_collections", "trainers"],
            "trainer_notifications.php" => ["trainers"],

            "groups.php"                => ["sports_groups", "players"],
            "sales.php"                 => ["sales_invoices", "sales_invoice_items", "store_categories"],
            "store.php"                 => ["store_categories"],
            "expenses.php"              => ["expenses"],
            "categories.php"            => ["store_categories"],
            "offers.php"                => ["offers", "players"],

            "users.php"                 => ["users"],
            "user_permissions.php"      => ["user_game_permissions", "users", "games"],
            "settings.php"              => ["settings"],

            "admin_portal_login.php"    => ["admins", "settings"],
            "trainer_portal_login.php"  => ["trainers", "settings"],
            "player_portal_login.php"   => ["offers", "store_products", "settings", "games"],
            "admin_portal.php"          => ["admins", "admin_attendance", "admin_loans", "admin_deductions", "admin_salary_payments", "admin_notifications", "settings", "games"],
            "trainer_portal.php"        => ["trainers", "trainer_attendance", "trainer_loans", "trainer_deductions", "trainer_salary_payments", "trainer_notifications", "settings", "games"],
            "player_portal.php"         => ["players", "player_attendance", "sports_groups", "player_notifications", "store_orders", "store_products", "offers", "settings", "games"],
        ];
    }
}

if (!function_exists("realtimeSyncResolveWatchedTables")) {
    function realtimeSyncResolveWatchedTables()
    {
        $script = isset($_SERVER["SCRIPT_NAME"]) ? basename((string)$_SERVER["SCRIPT_NAME"]) : "";
        if ($script === "") {
            return "*";
        }
        $map = realtimeSyncGetWatchMap();
        if (!array_key_exists($script, $map)) {
            return "*";
        }
        return $map[$script];
    }
}

if (!function_exists("realtimeSyncShouldInject")) {
    function realtimeSyncShouldInject()
    {
        if (defined("REALTIME_SYNC_DISABLED") && REALTIME_SYNC_DISABLED) {
            return false;
        }
        if (php_sapi_name() === "cli") {
            return false;
        }
        $script = isset($_SERVER["SCRIPT_NAME"]) ? basename((string)$_SERVER["SCRIPT_NAME"]) : "";
        $skipPages = ["index.php", "logout.php", "realtime_check.php"];
        if (in_array($script, $skipPages, true)) {
            return false;
        }
        return true;
    }
}

if (!function_exists("realtimeSyncResolveRootSelector")) {
    function realtimeSyncResolveRootSelector()
    {
        $script = isset($_SERVER["SCRIPT_NAME"]) ? basename((string)$_SERVER["SCRIPT_NAME"]) : "";
        if ($script === "admin_portal_login.php" || $script === "trainer_portal_login.php") {
            return ".portal-login-wrap";
        }
        if ($script === "admin_portal.php" || $script === "trainer_portal.php") {
            return ".portal-root";
        }
        if ($script === "player_portal.php" || $script === "player_portal_login.php") {
            return ".pp-shell";
        }
        return ".dashboard-layout";
    }
}

if (!function_exists("realtimeSyncBuildSnippet")) {
    function realtimeSyncBuildSnippet()
    {
        $watch = realtimeSyncResolveWatchedTables();
        $intervalMs = 5000;
        $endpoint = "realtime_check.php";
        $rootSelector = realtimeSyncResolveRootSelector();

        $config = json_encode([
            "endpoint"     => $endpoint,
            "intervalMs"   => $intervalMs,
            "watch"        => $watch === "*" ? "*" : array_values((array)$watch),
            "rootSelector" => $rootSelector,
        ], JSON_UNESCAPED_UNICODE);
        if ($config === false) {
            return "";
        }

        $tag  = "\n<script data-realtime-sync=\"config\">window.__REALTIME_SYNC_CONFIG__ = " . $config . ";</script>\n";
        $tag .= '<script src="assets/js/realtime_sync.js" defer data-realtime-sync="client"></script>' . "\n";
        return $tag;
    }
}

if (!function_exists("realtimeSyncOutputBufferCallback")) {
    function realtimeSyncOutputBufferCallback($buffer)
    {
        if (!is_string($buffer) || $buffer === "") {
            return $buffer;
        }
        $pos = stripos($buffer, "</body>");
        if ($pos === false) {
            return $buffer;
        }
        $snippet = realtimeSyncBuildSnippet();
        if ($snippet === "") {
            return $buffer;
        }
        return substr($buffer, 0, $pos) . $snippet . substr($buffer, $pos);
    }
}

if (!function_exists("realtimeSyncStart")) {
    function realtimeSyncStart()
    {
        if (!realtimeSyncShouldInject()) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        ob_start("realtimeSyncOutputBufferCallback");
    }
}
?>
