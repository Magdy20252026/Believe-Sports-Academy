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
            variant_type VARCHAR(30) NOT NULL DEFAULT 'none',
            has_sizes TINYINT(1) NOT NULL DEFAULT 0,
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
        "variant_type" => "ALTER TABLE store_categories ADD COLUMN variant_type VARCHAR(30) NOT NULL DEFAULT 'none' AFTER pricing_type",
        "has_sizes" => "ALTER TABLE store_categories ADD COLUMN has_sizes TINYINT(1) NOT NULL DEFAULT 0 AFTER pricing_type",
        "quantity" => "ALTER TABLE store_categories ADD COLUMN quantity INT(11) NULL DEFAULT NULL AFTER has_sizes",
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS store_category_sizes (
            id INT(11) NOT NULL AUTO_INCREMENT,
            category_id INT(11) NOT NULL,
            size_name VARCHAR(100) NOT NULL,
            color_name VARCHAR(100) NOT NULL DEFAULT '',
            quantity INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_store_category_variant_name (category_id, size_name, color_name),
            KEY idx_store_category_sizes_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $sizeRequiredColumns = [
        "size_name" => "ALTER TABLE store_category_sizes ADD COLUMN size_name VARCHAR(100) NOT NULL AFTER category_id",
        "color_name" => "ALTER TABLE store_category_sizes ADD COLUMN color_name VARCHAR(100) NOT NULL DEFAULT '' AFTER size_name",
        "quantity" => "ALTER TABLE store_category_sizes ADD COLUMN quantity INT(11) NOT NULL DEFAULT 0 AFTER size_name",
        "created_at" => "ALTER TABLE store_category_sizes ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER quantity",
        "updated_at" => "ALTER TABLE store_category_sizes ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($sizeRequiredColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(["store_category_sizes", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log(
                    "Failed to ensure store_category_sizes.{$columnName} exists using SQL [{$sql}]: "
                    . $throwable->getMessage()
                );
            }
        }
    }

    $sizeIndexesStmt = $pdo->prepare(
        "SELECT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'store_category_sizes'"
    );
    $sizeIndexesStmt->execute();
    $existingIndexNames = array_column($sizeIndexesStmt->fetchAll(), "INDEX_NAME");
    if (in_array("unique_store_category_size_name", $existingIndexNames, true)) {
        try {
            $pdo->exec("ALTER TABLE store_category_sizes DROP INDEX unique_store_category_size_name");
        } catch (Throwable $throwable) {
            error_log("Failed to drop unique_store_category_size_name: " . $throwable->getMessage());
        }
    }
    if (!in_array("unique_store_category_variant_name", $existingIndexNames, true)) {
        try {
            $pdo->exec("ALTER TABLE store_category_sizes ADD UNIQUE KEY unique_store_category_variant_name (category_id, size_name, color_name)");
        } catch (Throwable $throwable) {
            error_log("Failed to add unique_store_category_variant_name: " . $throwable->getMessage());
        }
    }

    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");
    if (!in_array("fk_store_category_sizes_category", $existingConstraints, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE store_category_sizes
                 ADD CONSTRAINT fk_store_category_sizes_category
                 FOREIGN KEY (category_id) REFERENCES store_categories (id) ON DELETE CASCADE"
            );
        } catch (Throwable $throwable) {
            error_log("Failed to add fk_store_category_sizes_category: " . $throwable->getMessage());
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

function normalizeCategorySizeRows(array $sizeNames, array $sizeColors, array $sizeQuantities)
{
    $rows = [];
    $rowCount = max(count($sizeNames), count($sizeColors), count($sizeQuantities));

    for ($index = 0; $index < $rowCount; $index++) {
        $sizeName = trim((string)($sizeNames[$index] ?? ""));
        $colorName = trim((string)($sizeColors[$index] ?? ""));
        $quantity = normalizeCategoryQuantityValue($sizeQuantities[$index] ?? "");

        if ($sizeName === "" && $colorName === "" && $quantity === "") {
            continue;
        }

        $rows[] = [
            "size_name" => $sizeName,
            "color_name" => $colorName,
            "quantity" => $quantity,
        ];
    }

    return $rows;
}

function getCategorySizesTotalQuantity(array $sizeRows)
{
    $total = 0;
    foreach ($sizeRows as $sizeRow) {
        $total += (int)($sizeRow["quantity"] ?? 0);
    }

    return $total;
}

function normalizeCategorySizeKey($value)
{
    $value = trim((string)$value);
    if ($value === "") {
        return "";
    }

    return function_exists("mb_strtolower")
        ? mb_strtolower($value, "UTF-8")
        : strtolower($value);
}

function hasDuplicateCategorySizeNames(array $sizeRows)
{
    $normalizedNames = array_map(function ($sizeRow) {
        return normalizeCategorySizeKey($sizeRow["size_name"] ?? "") . "|" . normalizeCategorySizeKey($sizeRow["color_name"] ?? "");
    }, $sizeRows);

    return count(array_unique($normalizedNames)) !== count($sizeRows);
}

function normalizeCategoryVariantRows(array $sizeRows, $variantType)
{
    foreach ($sizeRows as &$sizeRow) {
        if ($variantType === "size") {
            $sizeRow["color_name"] = "";
        } elseif ($variantType === "color") {
            $sizeRow["size_name"] = "";
        }
    }
    unset($sizeRow);

    return $sizeRows;
}

function getCategorySizeRowsValidationError(array $sizeRows, $variantType)
{
    if (count($sizeRows) === 0) {
        if ($variantType === "size_color") {
            return "أضف مقاسًا ولونًا واحدًا على الأقل لهذا الصنف.";
        }
        if ($variantType === "color") {
            return "أضف لونًا واحدًا على الأقل لهذا الصنف.";
        }

        return "أضف مقاسًا واحدًا على الأقل لهذا الصنف.";
    }

    if (hasDuplicateCategorySizeNames($sizeRows)) {
        if ($variantType === "size_color") {
            return "لا يمكن تكرار نفس المقاس واللون داخل الصنف.";
        }
        if ($variantType === "color") {
            return "لا يمكن تكرار نفس اللون داخل الصنف.";
        }

        return "لا يمكن تكرار نفس المقاس داخل الصنف.";
    }

    foreach ($sizeRows as $sizeRow) {
        $sizeName = trim((string)($sizeRow["size_name"] ?? ""));
        $colorName = trim((string)($sizeRow["color_name"] ?? ""));
        if (
            $variantType !== "color"
            && ($sizeName === "" || mb_strlen($sizeName) > 100 || ($sizeRow["quantity"] ?? "") === "")
        ) {
            return "كل مقاس يجب أن يحتوي على اسم لا يتجاوز 100 حرف وعدد صحيح أكبر من صفر.";
        }
        if (
            ($variantType === "color" || $variantType === "size_color")
            && ($colorName === "" || mb_strlen($colorName) > 100 || ($sizeRow["quantity"] ?? "") === "")
        ) {
            return $variantType === "size_color"
                ? "كل صف يجب أن يحتوي على مقاس ولون لا يتجاوز 100 حرف وعدد صحيح أكبر من صفر."
                : "كل لون يجب أن يحتوي على اسم لا يتجاوز 100 حرف وعدد صحيح أكبر من صفر.";
        }
    }

    if (getCategorySizesTotalQuantity($sizeRows) <= 0) {
        return "إجمالي كميات الخيارات يجب أن يكون أكبر من صفر.";
    }

    return "";
}

function fetchCategorySizesByCategoryIds(PDO $pdo, array $categoryIds)
{
    $categoryIds = array_values(array_unique(array_map("intval", $categoryIds)));
    if (count($categoryIds) === 0) {
        return [];
    }

    $placeholders = implode(", ", array_fill(0, count($categoryIds), "?"));
    $stmt = $pdo->prepare(
        "SELECT category_id, size_name, color_name, quantity
         FROM store_category_sizes
         WHERE category_id IN (" . $placeholders . ")
         ORDER BY size_name ASC, id ASC"
    );
    $stmt->execute($categoryIds);

    $sizesByCategoryId = [];
    foreach ($stmt->fetchAll() as $row) {
        $categoryId = (int)$row["category_id"];
        if (!isset($sizesByCategoryId[$categoryId])) {
            $sizesByCategoryId[$categoryId] = [];
        }

        $sizesByCategoryId[$categoryId][] = [
            "size_name" => (string)$row["size_name"],
            "color_name" => (string)($row["color_name"] ?? ""),
            "quantity" => (int)$row["quantity"],
        ];
    }

    return $sizesByCategoryId;
}

function formatCategoryVariantLabel($sizeName, $colorName)
{
    $sizeName = trim((string)$sizeName);
    $colorName = trim((string)$colorName);
    if ($sizeName !== "" && $colorName !== "") {
        return $sizeName . " - " . $colorName;
    }
    if ($sizeName !== "") {
        return $sizeName;
    }

    return $colorName;
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
    "variant_type" => "none",
    "has_sizes" => false,
    "quantity" => "",
    "price" => "",
    "sizes" => [
        ["size_name" => "", "color_name" => "", "quantity" => ""],
    ],
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
                "variant_type" => (string)($_POST["variant_type"] ?? "none"),
                "has_sizes" => false,
                "quantity" => normalizeCategoryQuantityValue($_POST["quantity"] ?? ""),
                "price" => normalizeCategoryPriceValue($_POST["price"] ?? ""),
                "sizes" => normalizeCategorySizeRows(
                    is_array($_POST["size_name"] ?? null) ? $_POST["size_name"] : [],
                    is_array($_POST["size_color"] ?? null) ? $_POST["size_color"] : [],
                    is_array($_POST["size_quantity"] ?? null) ? $_POST["size_quantity"] : []
                ),
            ];
            $formData["has_sizes"] = $formData["variant_type"] !== "none";

            if (!isset($pricingTypes[$formData["pricing_type"]])) {
                $error = "نوع التسعير غير صالح.";
            } elseif (!in_array($formData["variant_type"], ["none", "size", "color", "size_color"], true)) {
                $error = "نوع تفاصيل الصنف غير صالح.";
            } elseif ($formData["category_name"] === "") {
                $error = "اسم الصنف مطلوب.";
            } elseif (mb_strlen($formData["category_name"]) > 150) {
                $error = "اسم الصنف يجب ألا يتجاوز 150 حرفاً.";
            } elseif ($formData["price"] === "") {
                $error = "السعر يجب أن يكون رقماً موجباً أو صفراً.";
            } elseif ($formData["has_sizes"]) {
                $formData["sizes"] = normalizeCategoryVariantRows($formData["sizes"], $formData["variant_type"]);
                $error = getCategorySizeRowsValidationError($formData["sizes"], $formData["variant_type"]);
            } elseif (
                $formData["pricing_type"] === "price_with_quantity"
                && !$formData["has_sizes"]
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
                $quantityValue = $formData["has_sizes"]
                    ? getCategorySizesTotalQuantity($formData["sizes"])
                    : ($formData["pricing_type"] === "price_with_quantity"
                        ? (int)$formData["quantity"]
                        : null);
            }

            if ($error === "") {
                try {
                    $pdo->beginTransaction();

                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE store_categories
                             SET category_name = ?, pricing_type = ?, variant_type = ?, has_sizes = ?, quantity = ?, price = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["category_name"],
                            $formData["pricing_type"],
                            $formData["variant_type"],
                            $formData["has_sizes"] ? 1 : 0,
                            $quantityValue,
                            $formData["price"],
                            $formData["id"],
                            $currentGameId,
                        ]);
                        $categoryIdForSizes = (int)$formData["id"];
                        auditTrack($pdo, "update", "store_categories", $formData["id"], "أصناف المخزون", "تعديل صنف: " . $formData["category_name"]);
                        $_SESSION["categories_success"] = "تم تعديل الصنف ✅";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO store_categories (game_id, category_name, pricing_type, variant_type, has_sizes, quantity, price)
                             VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["category_name"],
                            $formData["pricing_type"],
                            $formData["variant_type"],
                            $formData["has_sizes"] ? 1 : 0,
                            $quantityValue,
                            $formData["price"],
                        ]);
                        $categoryIdForSizes = (int)$pdo->lastInsertId();
                        auditTrack($pdo, "create", "store_categories", $categoryIdForSizes, "أصناف المخزون", "إضافة صنف: " . $formData["category_name"]);
                        $_SESSION["categories_success"] = "تم حفظ الصنف ✅";
                    }

                    $deleteSizesStmt = $pdo->prepare("DELETE FROM store_category_sizes WHERE category_id = ?");
                    $deleteSizesStmt->execute([$categoryIdForSizes]);

                    if ($formData["has_sizes"] && count($formData["sizes"]) > 0) {
                        $insertSizeStmt = $pdo->prepare(
                            "INSERT INTO store_category_sizes (category_id, size_name, color_name, quantity)
                             VALUES (?, ?, ?, ?)"
                        );
                        foreach ($formData["sizes"] as $sizeRow) {
                            $insertSizeStmt->execute([
                                $categoryIdForSizes,
                                $sizeRow["size_name"],
                                $sizeRow["color_name"],
                                (int)$sizeRow["quantity"],
                            ]);
                        }
                    }

                    $pdo->commit();

                    header("Location: categories.php");
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
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
        "SELECT id, category_name, pricing_type, variant_type, has_sizes, quantity, price
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
            "variant_type" => (string)($editCategory["variant_type"] ?? ((int)($editCategory["has_sizes"] ?? 0) === 1 ? "size" : "none")),
            "has_sizes" => (int)($editCategory["has_sizes"] ?? 0) === 1,
            "quantity" => $editCategory["quantity"] === null ? "" : (string)$editCategory["quantity"],
            "price" => number_format((float)$editCategory["price"], 2, ".", ""),
            "sizes" => [
                ["size_name" => "", "color_name" => "", "quantity" => ""],
            ],
        ];

        if ($formData["has_sizes"]) {
            $sizeStmt = $pdo->prepare(
                "SELECT size_name, color_name, quantity
                 FROM store_category_sizes
                 WHERE category_id = ?
                 ORDER BY size_name ASC, id ASC"
            );
            $sizeStmt->execute([(int)$editCategory["id"]]);
            $loadedSizes = [];
            foreach ($sizeStmt->fetchAll() as $sizeRow) {
                $loadedSizes[] = [
                    "size_name" => (string)$sizeRow["size_name"],
                    "color_name" => (string)($sizeRow["color_name"] ?? ""),
                    "quantity" => (string)(int)$sizeRow["quantity"],
                ];
            }
            if (count($loadedSizes) > 0) {
                $formData["sizes"] = $loadedSizes;
            }
        }
    }
}

$categoriesStmt = $pdo->prepare(
    "SELECT id, category_name, pricing_type, has_sizes, quantity, price, updated_at, created_by_user_id, updated_by_user_id
     FROM store_categories
     WHERE game_id = ?
     ORDER BY category_name ASC"
);
$categoriesStmt->execute([$currentGameId]);
$categories = $categoriesStmt->fetchAll();
$categorySizesMap = fetchCategorySizesByCategoryIds($pdo, array_column($categories, "id"));

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

                        <div class="form-group category-field-full">
                            <label>تفاصيل الصنف</label>
                            <div class="pricing-option-grid">
                                <label class="pricing-option">
                                    <input type="radio" name="variant_type" value="none" <?php echo $formData["variant_type"] === "none" ? "checked" : ""; ?>>
                                    <span class="pricing-option-body">بدون مقاس أو لون</span>
                                </label>
                                <label class="pricing-option">
                                    <input type="radio" name="variant_type" value="size" <?php echo $formData["variant_type"] === "size" ? "checked" : ""; ?>>
                                    <span class="pricing-option-body">مقاس فقط</span>
                                </label>
                                <label class="pricing-option">
                                    <input type="radio" name="variant_type" value="color" <?php echo $formData["variant_type"] === "color" ? "checked" : ""; ?>>
                                    <span class="pricing-option-body">لون فقط</span>
                                </label>
                                <label class="pricing-option">
                                    <input type="radio" name="variant_type" value="size_color" <?php echo $formData["variant_type"] === "size_color" ? "checked" : ""; ?>>
                                    <span class="pricing-option-body">مقاس ولون</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group" id="quantityField" <?php echo $formData["pricing_type"] === "price_with_quantity" && !$formData["has_sizes"] ? "" : "hidden"; ?>>
                            <label for="quantity">العدد</label>
                            <input type="text" inputmode="numeric" name="quantity" id="quantity" value="<?php echo htmlspecialchars($formData["quantity"], ENT_QUOTES, "UTF-8"); ?>" <?php echo $formData["pricing_type"] === "price_with_quantity" && !$formData["has_sizes"] ? "required" : ""; ?>>
                        </div>

                        <div class="form-group">
                            <label for="price">السعر بالجنيه المصري</label>
                            <input type="text" inputmode="decimal" name="price" id="price" value="<?php echo htmlspecialchars($formData["price"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>

                        <div class="form-group category-field-full" id="sizesField" <?php echo $formData["has_sizes"] ? "" : "hidden"; ?>>
                            <div class="card-head" style="padding:0; margin-bottom:0.75rem;">
                                <div>
                                    <h3 style="font-size:1rem;">الخيارات المتاحة</h3>
                                </div>
                                <button type="button" class="btn btn-soft" id="addSizeRowButton">إضافة خيار</button>
                            </div>
                                <div id="sizesRows">
                                    <?php foreach ($formData["sizes"] as $sizeRow): ?>
                                        <div class="categories-form-grid size-row">
                                        <div class="form-group size-name-field" <?php echo $formData["variant_type"] === "color" ? "hidden" : ""; ?>>
                                            <label>المقاس</label>
                                            <input type="text" name="size_name[]" value="<?php echo htmlspecialchars((string)$sizeRow["size_name"], ENT_QUOTES, "UTF-8"); ?>">
                                        </div>
                                        <div class="form-group size-color-field" <?php echo in_array($formData["variant_type"], ["color", "size_color"], true) ? "" : "hidden"; ?>>
                                            <label>اللون</label>
                                            <input type="text" name="size_color[]" value="<?php echo htmlspecialchars((string)($sizeRow["color_name"] ?? ""), ENT_QUOTES, "UTF-8"); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>العدد</label>
                                            <input type="text" inputmode="numeric" name="size_quantity[]" value="<?php echo htmlspecialchars((string)$sizeRow["quantity"], ENT_QUOTES, "UTF-8"); ?>">
                                        </div>
                                        <div class="form-group" style="display:flex; align-items:flex-end;">
                                            <button type="button" class="btn btn-danger remove-size-row">حذف</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
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
                                <th>الخيارات</th>
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
                                    <td colspan="8" class="empty-cell">لا توجد أصناف مضافة.</td>
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
                                        <td data-label="الخيارات">
                                            <?php $categorySizes = $categorySizesMap[(int)$category["id"]] ?? []; ?>
                                            <?php if ((int)($category["has_sizes"] ?? 0) === 1 && count($categorySizes) > 0): ?>
                                                <div class="table-badges">
                                                    <?php foreach ($categorySizes as $categorySize): ?>
                                                        <span class="badge">
                                                            <?php echo htmlspecialchars(formatCategoryVariantLabel($categorySize["size_name"] ?? "", $categorySize["color_name"] ?? "") . " (" . (int)$categorySize["quantity"] . ")", ENT_QUOTES, "UTF-8"); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="info-pill">بدون خيارات</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="العدد">
                                            <span class="info-pill">
                                                <?php echo (($category["pricing_type"] ?? "") === "price_with_quantity" || (int)($category["has_sizes"] ?? 0) === 1) ? (int)$category["quantity"] : "—"; ?>
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
    const variantTypeInputs = document.querySelectorAll('input[name="variant_type"]');
    const quantityField = document.getElementById("quantityField");
    const quantityInput = document.getElementById("quantity");
    const sizesField = document.getElementById("sizesField");
    const sizesRows = document.getElementById("sizesRows");
    const addSizeRowButton = document.getElementById("addSizeRowButton");

    if (pricingTypeInputs.length === 0 || variantTypeInputs.length === 0 || !quantityField || !quantityInput || !sizesField || !sizesRows || !addSizeRowButton) {
        return;
    }

    const updateSizeRowButtons = function () {
        const removeButtons = sizesRows.querySelectorAll(".remove-size-row");
        removeButtons.forEach(function (button) {
            button.disabled = sizesRows.querySelectorAll(".size-row").length <= 1;
        });
    };

    const attachSizeRowEvents = function (rowElement) {
        const removeButton = rowElement.querySelector(".remove-size-row");
        if (!removeButton) {
            return;
        }

        removeButton.addEventListener("click", function () {
            const rows = sizesRows.querySelectorAll(".size-row");
            if (rows.length <= 1) {
                const inputs = rowElement.querySelectorAll('input[name="size_name[]"], input[name="size_color[]"], input[name="size_quantity[]"]');
                inputs.forEach(function (input) {
                    input.value = "";
                });
                return;
            }

            rowElement.remove();
            updateSizeRowButtons();
        });
    };

    const updateQuantityVisibility = function () {
        const selectedPricingType = document.querySelector('input[name="pricing_type"]:checked');
        const selectedVariantType = document.querySelector('input[name="variant_type"]:checked');
        const hasVariants = selectedVariantType && selectedVariantType.value !== "none";
        const showSizeNames = selectedVariantType && selectedVariantType.value !== "none" && selectedVariantType.value !== "color";
        const showColors = selectedVariantType && (selectedVariantType.value === "color" || selectedVariantType.value === "size_color");
        const shouldShowQuantity = selectedPricingType && selectedPricingType.value === "price_with_quantity" && !hasVariants;
        const sizeNameInputs = sizesRows.querySelectorAll('input[name="size_name[]"]');
        const sizeColorInputs = sizesRows.querySelectorAll('input[name="size_color[]"]');
        const sizeQuantityInputs = sizesRows.querySelectorAll('input[name="size_quantity[]"]');

        quantityField.hidden = !shouldShowQuantity;
        quantityInput.required = shouldShowQuantity;
        sizesField.hidden = !hasVariants;

        if (!shouldShowQuantity) {
            quantityInput.value = "";
        }

        sizeNameInputs.forEach(function (input) {
            input.required = !!showSizeNames;
            if (!showSizeNames) {
                input.value = "";
            }
        });
        sizeColorInputs.forEach(function (input) {
            input.required = !!showColors;
            if (!showColors) {
                input.value = "";
            }
        });
        sizeQuantityInputs.forEach(function (input) {
            input.required = hasVariants;
        });

        sizesRows.querySelectorAll(".size-name-field").forEach(function (field) {
            field.hidden = !showSizeNames;
        });
        sizesRows.querySelectorAll(".size-color-field").forEach(function (field) {
            field.hidden = !showColors;
        });
    };

    pricingTypeInputs.forEach(function (pricingTypeInput) {
        pricingTypeInput.addEventListener("change", updateQuantityVisibility);
    });

    variantTypeInputs.forEach(function (variantTypeInput) {
        variantTypeInput.addEventListener("change", updateQuantityVisibility);
    });

    addSizeRowButton.addEventListener("click", function () {
        const rowElement = document.createElement("div");
        rowElement.className = "categories-form-grid size-row";
        rowElement.innerHTML = `
            <div class="form-group size-name-field">
                <label>المقاس</label>
                <input type="text" name="size_name[]">
            </div>
            <div class="form-group size-color-field" hidden>
                <label>اللون</label>
                <input type="text" name="size_color[]">
            </div>
            <div class="form-group">
                <label>العدد</label>
                <input type="text" inputmode="numeric" name="size_quantity[]">
            </div>
            <div class="form-group" style="display:flex; align-items:flex-end;">
                <button type="button" class="btn btn-danger remove-size-row">حذف</button>
            </div>
        `;
        sizesRows.appendChild(rowElement);
        attachSizeRowEvents(rowElement);
        updateSizeRowButtons();
        updateQuantityVisibility();
    });

    sizesRows.querySelectorAll(".size-row").forEach(function (rowElement) {
        attachSizeRowEvents(rowElement);
    });

    updateSizeRowButtons();
    updateQuantityVisibility();
});
</script>
</body>
</html>
