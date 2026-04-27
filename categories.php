<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("categories");

function ensureStoreCategoriesTable(PDO $pdo)
{
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "pricing_type" => "ALTER TABLE store_categories ADD COLUMN pricing_type VARCHAR(30) NOT NULL DEFAULT 'price_only' AFTER category_name",
        "quantity" => "ALTER TABLE store_categories ADD COLUMN quantity INT(11) NULL DEFAULT NULL AFTER pricing_type",
        "price" => "ALTER TABLE store_categories ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantity",
        "updated_at" => "ALTER TABLE store_categories ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
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
        $existingColumnsStmt->execute(["store_categories", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log(
                    "Failed to ensure store_categories.{$columnName} exists using SQL [{$sql}]: "
                    . $throwable->getMessage()
                );
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
           AND TABLE_NAME = 'store_categories'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    if (!in_array("fk_store_categories_game", $existingConstraints, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE store_categories
                 ADD CONSTRAINT fk_store_categories_game
                 FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
            );
        } catch (Throwable $throwable) {
            error_log("Failed to add fk_store_categories_game: " . $throwable->getMessage());
        }
    }
}

function normalizeCategoryNumericInput($value)
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

    return str_replace([" ", "\u{00A0}"], "", $value);
}

function normalizeCategoryQuantityValue($value)
{
    $value = normalizeCategoryNumericInput($value);
    if ($value === "" || preg_match('/^\d+$/', $value) !== 1) {
        return "";
    }

    $intValue = (int)$value;
    if ($intValue <= 0) {
        return "";
    }

    return (string)$intValue;
}

function normalizeCategoryPriceValue($value)
{
    $value = normalizeCategoryNumericInput($value);
    if ($value === "" || !is_numeric($value)) {
        return "";
    }

    $floatValue = (float)$value;
    if ($floatValue < 0) {
        return "";
    }

    return number_format($floatValue, 2, ".", "");
}

function formatEgyptianCurrency($value)
{
    return number_format((float)$value, 2) . " ج.م";
}

function storeCategoryExists(PDO $pdo, $gameId, $categoryId)
{
    $stmt = $pdo->prepare("SELECT id FROM store_categories WHERE id = ? AND game_id = ? LIMIT 1");
    $stmt->execute([(int)$categoryId, (int)$gameId]);
    return (bool)$stmt->fetch();
}

function storeCategoryDuplicateExists(PDO $pdo, $gameId, $categoryName, $categoryId = 0)
{
    $sql = (int)$categoryId > 0
        ? "SELECT id FROM store_categories WHERE game_id = ? AND category_name = ? AND id <> ? LIMIT 1"
        : "SELECT id FROM store_categories WHERE game_id = ? AND category_name = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = (int)$categoryId > 0
        ? [(int)$gameId, (string)$categoryName, (int)$categoryId]
        : [(int)$gameId, (string)$categoryName];
    $stmt->execute($params);

    return (bool)$stmt->fetch();
}

function storeCategoryUsedInSalesInvoices(PDO $pdo, $gameId, $categoryId)
{
    $tableExistsStmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'sales_invoice_items'
         LIMIT 1"
    );
    $tableExistsStmt->execute();
    if (!$tableExistsStmt->fetchColumn()) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM sales_invoice_items invoice_items
         INNER JOIN sales_invoices invoices ON invoices.id = invoice_items.invoice_id
         WHERE invoice_items.category_id = ?
           AND invoices.game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$categoryId, (int)$gameId]);

    return (bool)$stmt->fetchColumn();
}

if (!isset($_SESSION["categories_csrf_token"])) {
    $_SESSION["categories_csrf_token"] = bin2hex(random_bytes(32));
}

ensureStoreCategoriesTable($pdo);

$success = "";
$error = "";
$pricingTypes = [
    "price_only" => "سعر فقط",
    "price_with_quantity" => "سعر وعدد",
];
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "categories";

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
    "category_name" => "",
    "pricing_type" => "price_only",
    "quantity" => "",
    "price" => "",
];

$flashSuccess = $_SESSION["categories_success"] ?? "";
unset($_SESSION["categories_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["categories_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["category_id"] ?? 0),
                "category_name" => trim((string)($_POST["category_name"] ?? "")),
                "pricing_type" => (string)($_POST["pricing_type"] ?? "price_only"),
                "quantity" => normalizeCategoryQuantityValue($_POST["quantity"] ?? ""),
                "price" => normalizeCategoryPriceValue($_POST["price"] ?? ""),
            ];

            if (!isset($pricingTypes[$formData["pricing_type"]])) {
                $error = "نوع التسعير غير صالح.";
            } elseif ($formData["category_name"] === "") {
                $error = "اسم الصنف مطلوب.";
            } elseif (mb_strlen($formData["category_name"]) > 150) {
                $error = "اسم الصنف يجب ألا يتجاوز 150 حرفاً.";
            } elseif ($formData["price"] === "") {
                $error = "السعر يجب أن يكون رقماً موجباً أو صفراً.";
            } elseif (
                $formData["pricing_type"] === "price_with_quantity"
                && $formData["quantity"] === ""
            ) {
                $error = "العدد يجب أن يكون رقماً صحيحاً أكبر من صفر.";
            } elseif (
                $formData["id"] > 0
                && !storeCategoryExists($pdo, $currentGameId, $formData["id"])
            ) {
                $error = "الصنف غير متاح.";
            } elseif (
                storeCategoryDuplicateExists($pdo, $currentGameId, $formData["category_name"], $formData["id"])
            ) {
                $error = "هذا الصنف مسجل بالفعل.";
            } else {
                $quantityValue = $formData["pricing_type"] === "price_with_quantity"
                    ? (int)$formData["quantity"]
                    : null;

                try {
                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE store_categories
                             SET category_name = ?, pricing_type = ?, quantity = ?, price = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["category_name"],
                            $formData["pricing_type"],
                            $quantityValue,
                            $formData["price"],
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditTrack($pdo, "update", "store_categories", $formData["id"], "أصناف المخزون", "تعديل صنف: " . $formData["category_name"]);
                        $_SESSION["categories_success"] = "تم تعديل الصنف ✅";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO store_categories (game_id, category_name, pricing_type, quantity, price)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["category_name"],
                            $formData["pricing_type"],
                            $quantityValue,
                            $formData["price"],
                        ]);
                        $newCategoryId = (int)$pdo->lastInsertId();
                        auditTrack($pdo, "create", "store_categories", $newCategoryId, "أصناف المخزون", "إضافة صنف: " . $formData["category_name"]);
                        $_SESSION["categories_success"] = "تم حفظ الصنف ✅";
                    }

                    header("Location: categories.php");
                    exit;
                } catch (Throwable $throwable) {
                    $error = "تعذر حفظ بيانات الصنف.";
                }
            }
        }

        if ($action === "delete") {
            $deleteCategoryId = (int)($_POST["category_id"] ?? 0);

            if ($deleteCategoryId <= 0) {
                $error = "الصنف غير صالح.";
            } elseif (!storeCategoryExists($pdo, $currentGameId, $deleteCategoryId)) {
                $error = "الصنف غير متاح.";
            } elseif (storeCategoryUsedInSalesInvoices($pdo, $currentGameId, $deleteCategoryId)) {
                $error = "لا يمكن حذف الصنف لوجود فواتير مرتبطة به.";
            } else {
                try {
                    $catNameStmt = $pdo->prepare("SELECT category_name FROM store_categories WHERE id = ? AND game_id = ? LIMIT 1");
                    $catNameStmt->execute([$deleteCategoryId, $currentGameId]);
                    $deletedCategoryName = (string)($catNameStmt->fetchColumn() ?: "");
                    $deleteStmt = $pdo->prepare("DELETE FROM store_categories WHERE id = ? AND game_id = ?");
                    $deleteStmt->execute([$deleteCategoryId, $currentGameId]);
                    auditLogActivity($pdo, "delete", "store_categories", $deleteCategoryId, "أصناف المخزون", "حذف صنف: " . $deletedCategoryName);
                    $_SESSION["categories_success"] = "تم حذف الصنف 🗑️";
                    header("Location: categories.php");
                    exit;
                } catch (Throwable $throwable) {
                    $error = "تعذر حذف الصنف.";
                }
            }
        }
    }
}

$editCategoryId = (int)($_GET["edit"] ?? 0);
if ($editCategoryId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editStmt = $pdo->prepare(
        "SELECT id, category_name, pricing_type, quantity, price
         FROM store_categories
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $editStmt->execute([$editCategoryId, $currentGameId]);
    $editCategory = $editStmt->fetch();

    if ($editCategory) {
        $storedPricingType = (string)($editCategory["pricing_type"] ?? "");
        if (!isset($pricingTypes[$storedPricingType])) {
            error_log("Invalid pricing_type found for store category ID " . (int)$editCategory["id"]);
            $storedPricingType = "price_only";
        }

        $formData = [
            "id" => (int)$editCategory["id"],
            "category_name" => (string)$editCategory["category_name"],
            "pricing_type" => $storedPricingType,
            "quantity" => $editCategory["quantity"] === null ? "" : (string)$editCategory["quantity"],
            "price" => number_format((float)$editCategory["price"], 2, ".", ""),
        ];
    }
}

$categoriesStmt = $pdo->prepare(
    "SELECT id, category_name, pricing_type, quantity, price, updated_at, created_by_user_id, updated_by_user_id
     FROM store_categories
     WHERE game_id = ?
     ORDER BY category_name ASC"
);
$categoriesStmt->execute([$currentGameId]);
$categories = $categoriesStmt->fetchAll();

$priceOnlyCount = 0;
$priceWithQuantityCount = 0;
foreach ($categories as $category) {
    if (($category["pricing_type"] ?? "") === "price_with_quantity") {
        $priceWithQuantityCount++;
    } else {
        $priceOnlyCount++;
    }
}

$submitButtonLabel = $formData["id"] > 0 ? "تحديث الصنف" : "حفظ الصنف";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الأصناف</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content categories-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>الأصناف</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
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

        <section class="category-stat-grid">
            <div class="category-stat-card">
                <span>إجمالي الأصناف</span>
                <strong><?php echo count($categories); ?></strong>
            </div>
            <div class="category-stat-card">
                <span>سعر فقط</span>
                <strong><?php echo $priceOnlyCount; ?></strong>
            </div>
            <div class="category-stat-card">
                <span>سعر وعدد</span>
                <strong><?php echo $priceWithQuantityCount; ?></strong>
            </div>
        </section>

        <section class="categories-grid">
            <div class="card categories-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل الصنف" : "إضافة صنف"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="categories.php" class="btn btn-soft" aria-label="إلغاء التعديل">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["categories_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="category_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="categories-form-grid">
                        <div class="form-group category-field-full">
                            <label for="category_name">اسم الصنف</label>
                            <input type="text" name="category_name" id="category_name" value="<?php echo htmlspecialchars($formData["category_name"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>

                        <div class="form-group category-field-full">
                            <label>نوع التسعير</label>
                            <div class="pricing-option-grid">
                                <?php foreach ($pricingTypes as $pricingTypeValue => $pricingTypeLabel): ?>
                                    <label class="pricing-option">
                                        <input
                                            type="radio"
                                            name="pricing_type"
                                            value="<?php echo htmlspecialchars($pricingTypeValue, ENT_QUOTES, "UTF-8"); ?>"
                                            <?php echo $formData["pricing_type"] === $pricingTypeValue ? "checked" : ""; ?>
                                        >
                                        <span class="pricing-option-body"><?php echo htmlspecialchars($pricingTypeLabel, ENT_QUOTES, "UTF-8"); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group" id="quantityField" <?php echo $formData["pricing_type"] === "price_with_quantity" ? "" : "hidden"; ?>>
                            <label for="quantity">العدد</label>
                            <input type="text" inputmode="numeric" name="quantity" id="quantity" value="<?php echo htmlspecialchars($formData["quantity"], ENT_QUOTES, "UTF-8"); ?>" <?php echo $formData["pricing_type"] === "price_with_quantity" ? "required" : ""; ?>>
                        </div>

                        <div class="form-group">
                            <label for="price">السعر بالجنيه المصري</label>
                            <input type="text" inputmode="decimal" name="price" id="price" value="<?php echo htmlspecialchars($formData["price"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, "UTF-8"); ?></button>
                </form>
            </div>

            <div class="card categories-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>الأصناف المضافة</h3>
                    </div>
                    <span class="table-counter">الإجمالي: <?php echo count($categories); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الصنف</th>
                                <th>النوع</th>
                                <th>العدد</th>
                                <th>السعر</th>
                                <th>أضيف بواسطة</th>
                                <th>عدّل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($categories) === 0): ?>
                                <tr>
                                    <td colspan="7" class="empty-cell">لا توجد أصناف مضافة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td data-label="الصنف">
                                            <div class="category-name-cell">
                                                <strong><?php echo htmlspecialchars($category["category_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="النوع">
                                            <span class="category-type-pill <?php echo ($category["pricing_type"] ?? "") === "price_with_quantity" ? "type-quantity" : "type-price"; ?>">
                                                <?php echo htmlspecialchars($pricingTypes[$category["pricing_type"]] ?? $pricingTypes["price_only"], ENT_QUOTES, "UTF-8"); ?>
                                            </span>
                                        </td>
                                        <td data-label="العدد">
                                            <span class="info-pill">
                                                <?php echo ($category["pricing_type"] ?? "") === "price_with_quantity" ? (int)$category["quantity"] : "—"; ?>
                                            </span>
                                        </td>
                                        <td data-label="السعر">
                                            <span class="price-pill"><?php echo htmlspecialchars(formatEgyptianCurrency($category["price"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $category["created_by_user_id"] ?? null), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $category["updated_by_user_id"] ?? null), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="categories.php?edit=<?php echo (int)$category["id"]; ?>" class="btn btn-warning" aria-label="تعديل الصنف">✏️</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["categories_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="category_id" value="<?php echo (int)$category["id"]; ?>">
                                                    <button type="submit" class="btn btn-danger" aria-label="حذف الصنف" onclick="return confirm('هل أنت متأكد من حذف الصنف؟')">🗑️</button>
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
<script>
document.addEventListener("DOMContentLoaded", function () {
    const pricingTypeInputs = document.querySelectorAll('input[name="pricing_type"]');
    const quantityField = document.getElementById("quantityField");
    const quantityInput = document.getElementById("quantity");

    if (pricingTypeInputs.length === 0 || !quantityField || !quantityInput) {
        return;
    }

    const updateQuantityVisibility = function () {
        const selectedPricingType = document.querySelector('input[name="pricing_type"]:checked');
        const shouldShowQuantity = selectedPricingType && selectedPricingType.value === "price_with_quantity";

        quantityField.hidden = !shouldShowQuantity;
        quantityInput.required = shouldShowQuantity;

        if (!shouldShowQuantity) {
            quantityInput.value = "";
        }
    };

    pricingTypeInputs.forEach(function (pricingTypeInput) {
        pricingTypeInput.addEventListener("change", updateQuantityVisibility);
    });

    updateQuantityVisibility();
});
</script>
</body>
</html>
