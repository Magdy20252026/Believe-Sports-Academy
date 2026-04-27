<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("expenses");

function ensureStoreExpensesTable(PDO $pdo)
{
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "expense_date" => "ALTER TABLE store_expenses ADD COLUMN expense_date DATE NOT NULL AFTER game_id",
        "statement_text" => "ALTER TABLE store_expenses ADD COLUMN statement_text VARCHAR(255) NOT NULL AFTER expense_date",
        "amount" => "ALTER TABLE store_expenses ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER statement_text",
        "created_by_user_id" => "ALTER TABLE store_expenses ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER amount",
        "updated_by_user_id" => "ALTER TABLE store_expenses ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        "updated_at" => "ALTER TABLE store_expenses ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
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
        $existingColumnsStmt->execute(["store_expenses", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log("Failed to ensure store_expenses.{$columnName}: " . $throwable->getMessage());
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
           AND TABLE_NAME = 'store_expenses'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    $foreignKeys = [
        "fk_store_expenses_game" => "ALTER TABLE store_expenses ADD CONSTRAINT fk_store_expenses_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE",
        "fk_store_expenses_created_user" => "ALTER TABLE store_expenses ADD CONSTRAINT fk_store_expenses_created_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
        "fk_store_expenses_updated_user" => "ALTER TABLE store_expenses ADD CONSTRAINT fk_store_expenses_updated_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
    ];

    foreach ($foreignKeys as $constraintName => $sql) {
        if (!in_array($constraintName, $existingConstraints, true)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log("Failed to ensure {$constraintName}: " . $throwable->getMessage());
            }
        }
    }
}

function normalizeExpensesNumericInput($value)
{
    $value = trim((string)$value);
    if ($value === "") {
        return "";
    }

    $value = strtr($value, [
        "٠" => "0",
        "١" => "1",
        "٢" => "2",
        "٣" => "3",
        "٤" => "4",
        "٥" => "5",
        "٦" => "6",
        "٧" => "7",
        "٨" => "8",
        "٩" => "9",
        "٫" => ".",
        "٬" => "",
        "،" => ".",
    ]);

    return (string)preg_replace('/\s+/u', '', $value);
}

function normalizeExpensesAmountValue($value)
{
    $value = normalizeExpensesNumericInput($value);
    if ($value === "" || !is_numeric($value)) {
        return "";
    }

    $floatValue = round((float)$value, 2);
    if ($floatValue < 0) {
        return "";
    }

    return number_format($floatValue, 2, ".", "");
}

function isValidExpenseDate($value)
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("!Y-m-d", $value, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $value;
}

function formatExpensesCurrency($value)
{
    return number_format((float)$value, 2) . " ج.م";
}

function fetchStoreExpense(PDO $pdo, $gameId, $expenseId)
{
    $stmt = $pdo->prepare(
        "SELECT id, expense_date, statement_text, amount, created_at, updated_at
         FROM store_expenses
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$expenseId, (int)$gameId]);

    return $stmt->fetch();
}

if (!isset($_SESSION["expenses_csrf_token"])) {
    $_SESSION["expenses_csrf_token"] = bin2hex(random_bytes(32));
}

ensureStoreExpensesTable($pdo);

$success = "";
$error = "";
$isManager = (string)($_SESSION["role"] ?? "") === "مدير";
$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "expenses";

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

$egyptNow = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$todayDate = $egyptNow->format("Y-m-d");
$selectedDate = trim((string)($_GET["date"] ?? $_POST["redirect_date"] ?? $todayDate));
if (!isValidExpenseDate($selectedDate)) {
    $selectedDate = $todayDate;
}

$formData = [
    "id" => 0,
    "expense_date" => $selectedDate,
    "statement_text" => "",
    "amount" => "",
];

$flashSuccess = $_SESSION["expenses_success"] ?? "";
unset($_SESSION["expenses_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["expenses_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["expense_id"] ?? 0),
                "expense_date" => trim((string)($_POST["expense_date"] ?? $selectedDate)),
                "statement_text" => trim((string)($_POST["statement_text"] ?? "")),
                "amount" => normalizeExpensesAmountValue($_POST["amount"] ?? ""),
            ];

            if ($formData["id"] > 0 && !$isManager) {
                $error = "تعديل المصروفات متاح للمدير فقط.";
            } elseif (!isValidExpenseDate($formData["expense_date"])) {
                $error = "تاريخ المصروف غير صحيح.";
            } elseif ($formData["statement_text"] === "") {
                $error = "البيان مطلوب.";
            } elseif ((function_exists("mb_strlen") ? mb_strlen($formData["statement_text"]) : strlen($formData["statement_text"])) > 255) {
                $error = "البيان طويل جدًا.";
            } elseif ($formData["amount"] === "") {
                $error = "المبلغ غير صحيح.";
            }

            if ($error === "") {
                $existingExpense = null;
                if ($formData["id"] > 0) {
                    $existingExpense = fetchStoreExpense($pdo, $currentGameId, $formData["id"]);
                    if (!$existingExpense) {
                        $error = "المصروف غير متاح.";
                    }
                }
            }

            if ($error === "") {
                try {
                    if (!empty($existingExpense)) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE store_expenses
                             SET expense_date = ?, statement_text = ?, amount = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["expense_date"],
                            $formData["statement_text"],
                            $formData["amount"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "store_expenses", $formData["id"], "المصروفات", "تعديل مصروف: " . $formData["statement_text"]);
                        $_SESSION["expenses_success"] = "تم تحديث المصروف.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO store_expenses (game_id, expense_date, statement_text, amount, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["expense_date"],
                            $formData["statement_text"],
                            $formData["amount"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newExpenseId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "store_expenses", $newExpenseId, "المصروفات", "إضافة مصروف: " . $formData["statement_text"]);
                        $_SESSION["expenses_success"] = "تم حفظ المصروف.";
                    }

                    $_SESSION["expenses_csrf_token"] = bin2hex(random_bytes(32));
                    header("Location: expenses.php?" . http_build_query(["date" => $formData["expense_date"]]));
                    exit;
                } catch (Throwable $throwable) {
                    $error = "تعذر حفظ المصروف.";
                    error_log("Expenses save error: " . $throwable->getMessage());
                }
            }
        }

        if ($action === "delete") {
            $expenseId = (int)($_POST["expense_id"] ?? 0);
            $redirectDate = trim((string)($_POST["redirect_date"] ?? $selectedDate));
            if (!isValidExpenseDate($redirectDate)) {
                $redirectDate = $todayDate;
            }

            if (!$isManager) {
                $error = "حذف المصروفات متاح للمدير فقط.";
            } elseif ($expenseId <= 0) {
                $error = "المصروف غير صالح.";
            } else {
                $existingExpense = fetchStoreExpense($pdo, $currentGameId, $expenseId);
                if (!$existingExpense) {
                    $error = "المصروف غير متاح.";
                } else {
                    try {
                        $deleteStmt = $pdo->prepare("DELETE FROM store_expenses WHERE id = ? AND game_id = ?");
                        $deleteStmt->execute([$expenseId, $currentGameId]);
                        if ($deleteStmt->rowCount() === 0) {
                            throw new RuntimeException("المصروف غير متاح.");
                        }

                        auditLogActivity($pdo, "delete", "store_expenses", $expenseId, "المصروفات", "حذف مصروف: " . (string)($existingExpense["statement_text"] ?? ""));
                        $_SESSION["expenses_success"] = "تم حذف المصروف.";
                        $_SESSION["expenses_csrf_token"] = bin2hex(random_bytes(32));
                        header("Location: expenses.php?" . http_build_query(["date" => $redirectDate]));
                        exit;
                    } catch (Throwable $throwable) {
                        $error = $throwable instanceof RuntimeException ? $throwable->getMessage() : "تعذر حذف المصروف.";
                        error_log("Expenses delete error: " . $throwable->getMessage());
                    }
                }
            }
        }
    }
}

$editExpenseId = (int)($_GET["edit"] ?? 0);
if ($isManager && $editExpenseId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editExpense = fetchStoreExpense($pdo, $currentGameId, $editExpenseId);
    if ($editExpense) {
        $formData = [
            "id" => (int)$editExpense["id"],
            "expense_date" => (string)$editExpense["expense_date"],
            "statement_text" => (string)$editExpense["statement_text"],
            "amount" => number_format((float)$editExpense["amount"], 2, ".", ""),
        ];
    }
}

$expensesStmt = $pdo->prepare(
    "SELECT expenses.id, expenses.expense_date, expenses.statement_text, expenses.amount, expenses.created_at, expenses.updated_at,
            creator.username AS created_by_username, updater.username AS updated_by_username
     FROM store_expenses expenses
     LEFT JOIN users creator ON creator.id = expenses.created_by_user_id
     LEFT JOIN users updater ON updater.id = expenses.updated_by_user_id
     WHERE expenses.game_id = ? AND expenses.expense_date = ?
     ORDER BY expenses.created_at DESC, expenses.id DESC"
);
$expensesStmt->execute([$currentGameId, $selectedDate]);
$expenses = $expensesStmt->fetchAll();

$selectedDateTotalsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS expenses_count, COALESCE(SUM(amount), 0) AS total_amount
     FROM store_expenses
     WHERE game_id = ? AND expense_date = ?"
);
$selectedDateTotalsStmt->execute([$currentGameId, $selectedDate]);
$selectedDateTotals = $selectedDateTotalsStmt->fetch() ?: ["expenses_count" => 0, "total_amount" => 0];

$grandTotalStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_amount
     FROM store_expenses
     WHERE game_id = ?"
);
$grandTotalStmt->execute([$currentGameId]);
$grandTotalAmount = (float)$grandTotalStmt->fetchColumn();

$isViewingToday = $selectedDate === $todayDate;
$submitButtonLabel = $formData["id"] > 0 ? "حفظ التعديل" : "حفظ المصروف";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المصروفات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content expenses-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>المصروفات</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">��</span>
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

        <section class="card expenses-filter-card">
            <div class="card-head expenses-filter-head">
                <div>
                    <h3>عرض مصروفات يوم محدد</h3>
                </div>
                <?php if (!$isViewingToday): ?>
                    <a href="expenses.php" class="btn btn-soft">اليوم</a>
                <?php endif; ?>
            </div>
            <form method="GET" class="expenses-filter-form">
                <div class="expenses-filter-grid">
                    <div class="form-group">
                        <label for="expense_filter_date">التاريخ</label>
                        <input type="date" name="date" id="expense_filter_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">عرض البيانات</button>
                </div>
            </form>
        </section>

        <section class="expenses-stat-grid">
            <div class="expenses-stat-card">
                <span>عدد مصروفات اليوم</span>
                <strong><?php echo (int)$selectedDateTotals["expenses_count"]; ?></strong>
            </div>
            <div class="expenses-stat-card">
                <span>إجمالي اليوم المحدد</span>
                <strong><?php echo htmlspecialchars(formatExpensesCurrency($selectedDateTotals["total_amount"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
            <div class="expenses-stat-card">
                <span>إجمالي كل المصروفات</span>
                <strong><?php echo htmlspecialchars(formatExpensesCurrency($grandTotalAmount), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
        </section>

        <section class="expenses-grid">
            <div class="card expenses-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل المصروف" : "إضافة مصروف"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="expenses.php?date=<?php echo urlencode($selectedDate); ?>" class="btn btn-soft" aria-label="إلغاء التعديل">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["expenses_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="expense_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="expenses-form-grid">
                        <div class="form-group form-group-full">
                            <label for="statement_text">البيان</label>
                            <input type="text" name="statement_text" id="statement_text" value="<?php echo htmlspecialchars($formData["statement_text"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="amount">المبلغ</label>
                            <input type="text" inputmode="decimal" name="amount" id="amount" value="<?php echo htmlspecialchars($formData["amount"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="expense_date">التاريخ</label>
                            <input type="date" name="expense_date" id="expense_date" value="<?php echo htmlspecialchars($formData["expense_date"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, "UTF-8"); ?></button>
                </form>
            </div>

            <div class="card expenses-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>المصروفات اليومية</h3>
                    </div>
                    <span class="table-counter"><?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>البيان</th>
                                <th>المبلغ</th>
                                <th>أضيف بواسطة</th>
                                <th>وقت الإضافة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($expenses) === 0): ?>
                                <tr>
                                    <td colspan="5" class="empty-cell">لا توجد مصروفات في هذا التاريخ.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $expense): ?>
                                    <tr>
                                        <td data-label="البيان">
                                            <div class="product-name-stack">
                                                <strong><?php echo htmlspecialchars((string)$expense["statement_text"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="المبلغ">
                                            <span class="expenses-amount-pill"><?php echo htmlspecialchars(formatExpensesCurrency($expense["amount"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars((string)($expense["created_by_username"] ?: "—"), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="وقت الإضافة"><?php echo htmlspecialchars((string)$expense["created_at"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <?php if ($isManager): ?>
                                                <div class="inline-actions">
                                                    <a href="expenses.php?date=<?php echo urlencode($selectedDate); ?>&edit=<?php echo (int)$expense["id"]; ?>" class="btn btn-warning" aria-label="تعديل المصروف">✏️</a>
                                                    <form method="POST" class="inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["expenses_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="expense_id" value="<?php echo (int)$expense["id"]; ?>">
                                                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                                                        <button type="submit" class="btn btn-danger" aria-label="حذف المصروف" onclick="return confirm('هل أنت متأكد من حذف المصروف؟')">🗑️</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge trainer-manager-only-badge">للمدير فقط</span>
                                            <?php endif; ?>
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
