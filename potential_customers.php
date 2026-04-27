<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("potential-customers");

function ensurePotentialCustomersTable(PDO $pdo)
{
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "notes" => "ALTER TABLE potential_customers ADD COLUMN notes TEXT NULL DEFAULT NULL AFTER phone",
        "created_by_user_id" => "ALTER TABLE potential_customers ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER notes",
        "updated_by_user_id" => "ALTER TABLE potential_customers ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        "updated_at" => "ALTER TABLE potential_customers ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
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
        $existingColumnsStmt->execute(["potential_customers", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $t) {
                error_log("Failed to ensure potential_customers.{$columnName}: " . $t->getMessage());
            }
        }
    }

    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($databaseName === "") {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'potential_customers'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    $foreignKeys = [
        "fk_potential_customers_game" => "ALTER TABLE potential_customers ADD CONSTRAINT fk_potential_customers_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE",
        "fk_potential_customers_created_user" => "ALTER TABLE potential_customers ADD CONSTRAINT fk_potential_customers_created_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
        "fk_potential_customers_updated_user" => "ALTER TABLE potential_customers ADD CONSTRAINT fk_potential_customers_updated_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
    ];

    foreach ($foreignKeys as $constraintName => $sql) {
        if (!in_array($constraintName, $existingConstraints, true)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $t) {
                error_log("Failed to ensure {$constraintName}: " . $t->getMessage());
            }
        }
    }
}

function fetchPotentialCustomer(PDO $pdo, $gameId, $customerId)
{
    $stmt = $pdo->prepare(
        "SELECT id, player_name, phone, notes, created_at, updated_at
         FROM potential_customers
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$customerId, (int)$gameId]);
    return $stmt->fetch();
}

if (!isset($_SESSION["potential_customers_csrf_token"])) {
    $_SESSION["potential_customers_csrf_token"] = bin2hex(random_bytes(32));
}

ensurePotentialCustomersTable($pdo);

$success = "";
$error = "";
$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "potential-customers";

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

$formData = [
    "id" => 0,
    "player_name" => "",
    "phone" => "",
    "notes" => "",
];

$flashSuccess = $_SESSION["potential_customers_success"] ?? "";
unset($_SESSION["potential_customers_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["potential_customers_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["customer_id"] ?? 0),
                "player_name" => trim((string)($_POST["player_name"] ?? "")),
                "phone" => trim((string)($_POST["phone"] ?? "")),
                "notes" => trim((string)($_POST["notes"] ?? "")),
            ];

            if ($formData["player_name"] === "") {
                $error = "اسم اللاعب مطلوب.";
            } elseif (mb_strlen($formData["player_name"]) > 255) {
                $error = "اسم اللاعب طويل جدًا.";
            } elseif ($formData["phone"] === "") {
                $error = "رقم الهاتف مطلوب.";
            } elseif (mb_strlen($formData["phone"]) > 50) {
                $error = "رقم الهاتف طويل جدًا.";
            }

            if ($error === "") {
                $existingCustomer = null;
                if ($formData["id"] > 0) {
                    $existingCustomer = fetchPotentialCustomer($pdo, $currentGameId, $formData["id"]);
                    if (!$existingCustomer) {
                        $error = "السجل غير متاح.";
                    }
                }
            }

            if ($error === "") {
                try {
                    if (!empty($existingCustomer)) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE potential_customers
                             SET player_name = ?, phone = ?, notes = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["player_name"],
                            $formData["phone"],
                            $formData["notes"] !== "" ? $formData["notes"] : null,
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "potential_customers", $formData["id"], "العملاء المحتملين", "تعديل عميل محتمل: " . $formData["player_name"]);
                        $_SESSION["potential_customers_success"] = "تم تحديث البيانات.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO potential_customers (game_id, player_name, phone, notes, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["player_name"],
                            $formData["phone"],
                            $formData["notes"] !== "" ? $formData["notes"] : null,
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "potential_customers", $newId, "العملاء المحتملين", "إضافة عميل محتمل: " . $formData["player_name"]);
                        $_SESSION["potential_customers_success"] = "تم حفظ العميل المحتمل.";
                    }

                    $_SESSION["potential_customers_csrf_token"] = bin2hex(random_bytes(32));
                    header("Location: potential_customers.php");
                    exit;
                } catch (Throwable $t) {
                    $error = "تعذر حفظ البيانات.";
                    error_log("Potential customers save error: " . $t->getMessage());
                }
            }
        }

        if ($action === "delete") {
            $customerId = (int)($_POST["customer_id"] ?? 0);

            if ($customerId <= 0) {
                $error = "السجل غير صالح.";
            } else {
                $existingCustomer = fetchPotentialCustomer($pdo, $currentGameId, $customerId);
                if (!$existingCustomer) {
                    $error = "السجل غير متاح.";
                } else {
                    try {
                        $deleteStmt = $pdo->prepare("DELETE FROM potential_customers WHERE id = ? AND game_id = ?");
                        $deleteStmt->execute([$customerId, $currentGameId]);
                        if ($deleteStmt->rowCount() === 0) {
                            throw new RuntimeException("السجل غير متاح.");
                        }

                        auditLogActivity($pdo, "delete", "potential_customers", $customerId, "العملاء المحتملين", "حذف عميل محتمل: " . (string)($existingCustomer["player_name"] ?? ""));
                        $_SESSION["potential_customers_success"] = "تم الحذف.";
                        $_SESSION["potential_customers_csrf_token"] = bin2hex(random_bytes(32));
                        header("Location: potential_customers.php");
                        exit;
                    } catch (Throwable $t) {
                        $error = $t instanceof RuntimeException ? $t->getMessage() : "تعذر الحذف.";
                        error_log("Potential customers delete error: " . $t->getMessage());
                    }
                }
            }
        }
    }
}

$editCustomerId = (int)($_GET["edit"] ?? 0);
if ($editCustomerId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editCustomer = fetchPotentialCustomer($pdo, $currentGameId, $editCustomerId);
    if ($editCustomer) {
        $formData = [
            "id" => (int)$editCustomer["id"],
            "player_name" => (string)$editCustomer["player_name"],
            "phone" => (string)$editCustomer["phone"],
            "notes" => (string)($editCustomer["notes"] ?? ""),
        ];
    }
}

$searchQuery = trim((string)($_GET["q"] ?? ""));

if ($searchQuery !== "") {
    $customersStmt = $pdo->prepare(
        "SELECT pc.id, pc.player_name, pc.phone, pc.notes, pc.created_at, pc.updated_at,
                creator.username AS created_by_username
         FROM potential_customers pc
         LEFT JOIN users creator ON creator.id = pc.created_by_user_id
         WHERE pc.game_id = ?
           AND (pc.player_name LIKE ? OR pc.phone LIKE ?)
         ORDER BY pc.created_at DESC, pc.id DESC"
    );
    $likeQuery = "%" . $searchQuery . "%";
    $customersStmt->execute([$currentGameId, $likeQuery, $likeQuery]);
} else {
    $customersStmt = $pdo->prepare(
        "SELECT pc.id, pc.player_name, pc.phone, pc.notes, pc.created_at, pc.updated_at,
                creator.username AS created_by_username
         FROM potential_customers pc
         LEFT JOIN users creator ON creator.id = pc.created_by_user_id
         WHERE pc.game_id = ?
         ORDER BY pc.created_at DESC, pc.id DESC"
    );
    $customersStmt->execute([$currentGameId]);
}

$customers = $customersStmt->fetchAll();

$totalCountStmt = $pdo->prepare("SELECT COUNT(*) FROM potential_customers WHERE game_id = ?");
$totalCountStmt->execute([$currentGameId]);
$totalCount = (int)$totalCountStmt->fetchColumn();

$submitButtonLabel = $formData["id"] > 0 ? "حفظ التعديل" : "إضافة عميل";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>العملاء المحتملين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .prospects-layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .prospects-layout {
                grid-template-columns: 1fr;
            }
        }

        .prospects-form-card .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .prospects-form-card .card-head h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            margin: 0;
        }

        .prospects-form-card .form-group {
            margin-bottom: 16px;
        }

        .prospects-form-card .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .prospects-form-card .form-group input,
        .prospects-form-card .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border, #e2e8f0);
            border-radius: 10px;
            font-family: "Cairo", sans-serif;
            font-size: 0.92rem;
            background: var(--bg);
            color: var(--text);
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .prospects-form-card .form-group input:focus,
        .prospects-form-card .form-group textarea:focus {
            border-color: var(--login-accent, #2563eb);
        }

        .prospects-form-card .form-group textarea {
            resize: vertical;
            min-height: 90px;
        }

        .prospects-save-btn {
            width: 100%;
            margin-top: 4px;
        }

        .prospects-search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }

        .prospects-search-bar input {
            flex: 1;
            padding: 10px 14px;
            border: 1.5px solid var(--border, #e2e8f0);
            border-radius: 10px;
            font-family: "Cairo", sans-serif;
            font-size: 0.92rem;
            background: var(--bg);
            color: var(--text);
            outline: none;
            box-sizing: border-box;
        }

        .prospects-search-bar input::placeholder {
            color: var(--text-soft);
        }

        .prospects-table-card .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .prospects-table-card .card-head h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            margin: 0;
        }

        .prospects-stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .prospects-stat-card {
            flex: 1;
            min-width: 140px;
            background: var(--bg-secondary);
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .prospects-stat-card span {
            font-size: 0.82rem;
            color: var(--text-soft);
            font-weight: 600;
        }

        .prospects-stat-card strong {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text);
        }

        body.dark-mode .prospects-stat-card {
            background: var(--bg-secondary);
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }

        body.dark-mode .prospects-form-card .form-group input,
        body.dark-mode .prospects-form-card .form-group textarea,
        body.dark-mode .prospects-search-bar input {
            background: var(--bg-secondary);
            color: var(--text);
            border-color: rgba(255,255,255,0.12);
        }

        body.dark-mode .prospects-form-card .form-group label {
            color: var(--text);
        }

        body.dark-mode .prospects-form-card .card-head h3,
        body.dark-mode .prospects-table-card .card-head h3 {
            color: var(--text);
        }

        body.dark-mode .prospects-stat-card span {
            color: var(--text-soft);
        }

        body.dark-mode .prospects-stat-card strong {
            color: var(--text);
        }

        .prospect-notes-cell {
            font-size: 0.85rem;
            color: var(--text-soft);
            max-width: 200px;
            word-break: break-word;
        }

        body.dark-mode .prospect-notes-cell {
            color: var(--text-soft);
        }

        .prospect-name-cell strong {
            color: var(--text);
            font-weight: 700;
        }

        body.dark-mode .prospect-name-cell strong {
            color: var(--text);
        }

        .prospect-phone-pill {
            display: inline-block;
            background: rgba(37,99,235,0.1);
            color: #2563eb;
            border-radius: 8px;
            padding: 3px 10px;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: monospace;
            direction: ltr;
        }

        body.dark-mode .prospect-phone-pill {
            background: rgba(96,165,250,0.15);
            color: #93c5fd;
        }

        .prospects-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        @media (max-width: 600px) {
            .prospects-stats-row {
                gap: 10px;
            }

            .prospects-stat-card {
                min-width: 100px;
                padding: 12px 14px;
            }

            .data-table th,
            .data-table td {
                font-size: 0.83rem;
            }
        }
    </style>
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>🌟 العملاء المحتملين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <?php if ($currentGameName !== ""): ?>
                    <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <?php endif; ?>
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

        <div class="prospects-stats-row">
            <div class="prospects-stat-card">
                <span>إجمالي العملاء المحتملين</span>
                <strong><?php echo $totalCount; ?></strong>
            </div>
            <div class="prospects-stat-card">
                <span>نتائج البحث الحالية</span>
                <strong><?php echo count($customers); ?></strong>
            </div>
        </div>

        <section class="prospects-layout">
            <div class="card prospects-form-card">
                <div class="card-head">
                    <h3><?php echo $formData["id"] > 0 ? "✏️ تعديل البيانات" : "➕ إضافة عميل محتمل"; ?></h3>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="potential_customers.php" class="btn btn-soft">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["potential_customers_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="customer_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="form-group">
                        <label for="player_name">اسم اللاعب</label>
                        <input
                            type="text"
                            name="player_name"
                            id="player_name"
                            value="<?php echo htmlspecialchars($formData["player_name"], ENT_QUOTES, "UTF-8"); ?>"
                            placeholder="أدخل اسم اللاعب"
                            required
                            autocomplete="off"
                        >
                    </div>

                    <div class="form-group">
                        <label for="phone">رقم الهاتف</label>
                        <input
                            type="tel"
                            name="phone"
                            id="phone"
                            value="<?php echo htmlspecialchars($formData["phone"], ENT_QUOTES, "UTF-8"); ?>"
                            placeholder="أدخل رقم الهاتف"
                            required
                            autocomplete="off"
                            inputmode="tel"
                        >
                    </div>

                    <div class="form-group">
                        <label for="notes">ملاحظة</label>
                        <textarea
                            name="notes"
                            id="notes"
                            placeholder="أضف ملاحظة اختيارية..."
                        ><?php echo htmlspecialchars($formData["notes"], ENT_QUOTES, "UTF-8"); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary prospects-save-btn">
                        <?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, "UTF-8"); ?>
                    </button>
                </form>
            </div>

            <div class="card prospects-table-card">
                <div class="card-head">
                    <h3>قائمة العملاء المحتملين</h3>
                    <span class="table-counter"><?php echo count($customers); ?> سجل</span>
                </div>

                <form method="GET" class="prospects-search-bar">
                    <input
                        type="text"
                        name="q"
                        value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, "UTF-8"); ?>"
                        placeholder="🔍 بحث بالاسم أو رقم الهاتف..."
                        autocomplete="off"
                    >
                    <button type="submit" class="btn btn-primary">بحث</button>
                    <?php if ($searchQuery !== ""): ?>
                        <a href="potential_customers.php" class="btn btn-soft">مسح</a>
                    <?php endif; ?>
                </form>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم اللاعب</th>
                                <th>رقم الهاتف</th>
                                <th>الملاحظة</th>
                                <th>أضيف بواسطة</th>
                                <th>تاريخ الإضافة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($customers) === 0): ?>
                                <tr>
                                    <td colspan="7" class="empty-cell">
                                        <?php echo $searchQuery !== "" ? "لا توجد نتائج مطابقة." : "لا يوجد عملاء محتملين حتى الآن."; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $index => $customer): ?>
                                    <tr>
                                        <td data-label="#"><?php echo $index + 1; ?></td>
                                        <td data-label="اسم اللاعب">
                                            <div class="prospect-name-cell">
                                                <strong><?php echo htmlspecialchars((string)$customer["player_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="رقم الهاتف">
                                            <span class="prospect-phone-pill"><?php echo htmlspecialchars((string)$customer["phone"], ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="الملاحظة">
                                            <div class="prospect-notes-cell">
                                                <?php echo $customer["notes"] !== null && $customer["notes"] !== "" ? htmlspecialchars((string)$customer["notes"], ENT_QUOTES, "UTF-8") : "—"; ?>
                                            </div>
                                        </td>
                                        <td data-label="أضيف بواسطة">
                                            <?php echo htmlspecialchars((string)($customer["created_by_username"] ?? "—"), ENT_QUOTES, "UTF-8"); ?>
                                        </td>
                                        <td data-label="تاريخ الإضافة">
                                            <?php
                                            $createdAt = $customer["created_at"] ?? "";
                                            if ($createdAt !== "") {
                                                try {
                                                    $dt = new DateTimeImmutable((string)$createdAt, new DateTimeZone("Africa/Cairo"));
                                                    echo htmlspecialchars($dt->format("Y-m-d"), ENT_QUOTES, "UTF-8");
                                                } catch (Throwable $t) {
                                                    echo htmlspecialchars((string)$createdAt, ENT_QUOTES, "UTF-8");
                                                }
                                            } else {
                                                echo "—";
                                            }
                                            ?>
                                        </td>
                                        <td data-label="الإجراءات">
                                            <div class="prospects-actions">
                                                <a
                                                    href="potential_customers.php?edit=<?php echo (int)$customer["id"]; ?><?php echo $searchQuery !== "" ? "&q=" . urlencode($searchQuery) : ""; ?>"
                                                    class="btn btn-soft btn-sm"
                                                >تعديل</a>

                                                <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا السجل؟');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["potential_customers_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="customer_id" value="<?php echo (int)$customer["id"]; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                                                </form>
                                            </div>
                                        </td>
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
<script src="assets/js/script.js"></script>
</body>
</html>
