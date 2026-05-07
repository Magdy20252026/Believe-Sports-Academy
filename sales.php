<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("sales");

function ensureStoreCategoriesTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS store_categories (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            category_name VARCHAR(150) NOT NULL,
            pricing_type VARCHAR(30) NOT NULL DEFAULT 'price_only',
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
                error_log("Failed to ensure store_categories.{$columnName}: " . $throwable->getMessage());
            }
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS store_category_sizes (
            id INT(11) NOT NULL AUTO_INCREMENT,
            category_id INT(11) NOT NULL,
            size_name VARCHAR(100) NOT NULL,
            quantity INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_store_category_size_name (category_id, size_name),
            KEY idx_store_category_sizes_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $sizeRequiredColumns = [
        "size_name" => "ALTER TABLE store_category_sizes ADD COLUMN size_name VARCHAR(100) NOT NULL AFTER category_id",
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
                error_log("Failed to ensure store_category_sizes.{$columnName}: " . $throwable->getMessage());
            }
        }
    }
}

function ensureSalesInvoicesTables(PDO $pdo)
{
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $invoiceColumns = [
        "customer_name" => "ALTER TABLE sales_invoices ADD COLUMN customer_name VARCHAR(255) NOT NULL DEFAULT '' AFTER game_id",
        "invoice_type" => "ALTER TABLE sales_invoices ADD COLUMN invoice_type VARCHAR(20) NOT NULL DEFAULT 'purchase' AFTER customer_name",
        "invoice_date" => "ALTER TABLE sales_invoices ADD COLUMN invoice_date DATE NOT NULL AFTER invoice_type",
        "total_amount" => "ALTER TABLE sales_invoices ADD COLUMN total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER invoice_date",
        "paid_amount" => "ALTER TABLE sales_invoices ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount",
        "created_by_user_id" => "ALTER TABLE sales_invoices ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER paid_amount",
        "updated_by_user_id" => "ALTER TABLE sales_invoices ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        "updated_at" => "ALTER TABLE sales_invoices ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sales_invoice_items (
            id INT(11) NOT NULL AUTO_INCREMENT,
            invoice_id INT(11) NOT NULL,
            category_id INT(11) NOT NULL,
            category_name VARCHAR(150) NOT NULL,
            size_name VARCHAR(100) NOT NULL DEFAULT '',
            quantity INT(11) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sales_invoice_items_invoice (invoice_id),
            KEY idx_sales_invoice_items_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $itemColumns = [
        "category_id" => "ALTER TABLE sales_invoice_items ADD COLUMN category_id INT(11) NOT NULL AFTER invoice_id",
        "category_name" => "ALTER TABLE sales_invoice_items ADD COLUMN category_name VARCHAR(150) NOT NULL AFTER category_id",
        "size_name" => "ALTER TABLE sales_invoice_items ADD COLUMN size_name VARCHAR(100) NOT NULL DEFAULT '' AFTER category_name",
        "quantity" => "ALTER TABLE sales_invoice_items ADD COLUMN quantity INT(11) NOT NULL AFTER size_name",
        "unit_price" => "ALTER TABLE sales_invoice_items ADD COLUMN unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity",
        "line_total" => "ALTER TABLE sales_invoice_items ADD COLUMN line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price",
    ];

    $existingColumnsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );

    foreach ($invoiceColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(["sales_invoices", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log("Failed to ensure sales_invoices.{$columnName}: " . $throwable->getMessage());
            }
        }
    }

    foreach ($itemColumns as $columnName => $sql) {
        $existingColumnsStmt->execute(["sales_invoice_items", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log("Failed to ensure sales_invoice_items.{$columnName}: " . $throwable->getMessage());
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
           AND TABLE_NAME = ?
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );

    $invoiceConstraints = [];
    $constraintsStmt->execute([$databaseName, "sales_invoices"]);
    $invoiceConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    $itemConstraints = [];
    $constraintsStmt->execute([$databaseName, "sales_invoice_items"]);
    $itemConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    if (!in_array("fk_sales_invoices_game", $invoiceConstraints, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE sales_invoices
                 ADD CONSTRAINT fk_sales_invoices_game
                 FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE"
            );
        } catch (Throwable $throwable) {
            error_log("Failed to add fk_sales_invoices_game: " . $throwable->getMessage());
        }
    }

    if (!in_array("fk_sales_invoices_created_user", $invoiceConstraints, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE sales_invoices
                 ADD CONSTRAINT fk_sales_invoices_created_user
                 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL"
            );
        } catch (Throwable $throwable) {
            error_log("Failed to add fk_sales_invoices_created_user: " . $throwable->getMessage());
        }
    }

    if (!in_array("fk_sales_invoices_updated_user", $invoiceConstraints, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE sales_invoices
                 ADD CONSTRAINT fk_sales_invoices_updated_user
                 FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL"
            );
        } catch (Throwable $throwable) {
            error_log("Failed to add fk_sales_invoices_updated_user: " . $throwable->getMessage());
        }
    }

    if (!in_array("fk_sales_invoice_items_invoice", $itemConstraints, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE sales_invoice_items
                 ADD CONSTRAINT fk_sales_invoice_items_invoice
                 FOREIGN KEY (invoice_id) REFERENCES sales_invoices (id) ON DELETE CASCADE"
            );
        } catch (Throwable $throwable) {
            error_log("Failed to add fk_sales_invoice_items_invoice: " . $throwable->getMessage());
        }
    }

    $constraintsStmt->execute([$databaseName, "store_category_sizes"]);
    $sizeConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");
    if (!in_array("fk_store_category_sizes_category", $sizeConstraints, true)) {
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

function normalizeSalesNumericInput($value)
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

function normalizeSalesQuantityValue($value)
{
    $value = normalizeSalesNumericInput($value);
    if ($value === "" || preg_match('/^\d+$/', $value) !== 1) {
        return "";
    }

    $intValue = (int)$value;
    if ($intValue <= 0) {
        return "";
    }

    return (string)$intValue;
}

function normalizeSalesAmountValue($value)
{
    $value = normalizeSalesNumericInput($value);
    if ($value === "" || !is_numeric($value)) {
        return "";
    }

    $floatValue = round((float)$value, 2);
    if ($floatValue < 0) {
        return "";
    }

    return number_format($floatValue, 2, ".", "");
}

function isValidSalesDate($value)
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("!Y-m-d", $value, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $value;
}

function formatSalesCurrency($value)
{
    return number_format((float)$value, 2) . " ج.م";
}

function formatSalesDateTime($value)
{
    $value = trim((string)$value);
    if ($value === "") {
        return "—";
    }

    try {
        $dateTime = new DateTimeImmutable($value, new DateTimeZone("Africa/Cairo"));
    } catch (Throwable $throwable) {
        return "—";
    }

    return $dateTime->format("Y-m-d • H:i");
}

function fetchSalesCategories(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, category_name, pricing_type, has_sizes, quantity, price
         FROM store_categories
         WHERE game_id = ?
         ORDER BY category_name ASC"
    );
    $stmt->execute([(int)$gameId]);

    $sizesStmt = $pdo->prepare(
        "SELECT scs.category_id, scs.size_name, scs.quantity
         FROM store_category_sizes scs
         INNER JOIN store_categories sc ON sc.id = scs.category_id
         WHERE sc.game_id = ?
         ORDER BY scs.size_name ASC, scs.id ASC"
    );
    $sizesStmt->execute([(int)$gameId]);
    $sizesByCategoryId = [];
    foreach ($sizesStmt->fetchAll() as $sizeRow) {
        $categoryId = (int)$sizeRow["category_id"];
        if (!isset($sizesByCategoryId[$categoryId])) {
            $sizesByCategoryId[$categoryId] = [];
        }
        $sizesByCategoryId[$categoryId][] = [
            "size_name" => (string)$sizeRow["size_name"],
            "quantity" => (int)$sizeRow["quantity"],
        ];
    }

    $categories = [];
    foreach ($stmt->fetchAll() as $category) {
        $categoryId = (int)$category["id"];
        $sizes = $sizesByCategoryId[$categoryId] ?? [];
        $categories[$categoryId] = [
            "id" => $categoryId,
            "category_name" => (string)$category["category_name"],
            "pricing_type" => (string)($category["pricing_type"] ?? "price_only"),
            "has_sizes" => (int)($category["has_sizes"] ?? 0) === 1,
            "quantity" => $category["quantity"] === null ? null : (int)$category["quantity"],
            "price" => number_format((float)$category["price"], 2, ".", ""),
            "sizes" => $sizes,
        ];
    }

    return $categories;
}

function collectSalesInvoiceItems(array $categoryIds, array $sizeNames, array $quantities, array $categoriesMap)
{
    $items = [];
    $usedItemKeys = [];
    $rowCount = max(count($categoryIds), count($sizeNames), count($quantities));

    for ($index = 0; $index < $rowCount; $index++) {
        $rawCategoryId = trim((string)($categoryIds[$index] ?? ""));
        $rawSizeName = trim((string)($sizeNames[$index] ?? ""));
        $rawQuantity = trim((string)($quantities[$index] ?? ""));

        if ($rawCategoryId === "" && $rawSizeName === "" && $rawQuantity === "") {
            continue;
        }

        $categoryId = (int)$rawCategoryId;
        if ($categoryId <= 0 || !isset($categoriesMap[$categoryId])) {
            return ["items" => [], "error" => "اختر صنفًا صالحًا في كل سطر."];
        }

        $quantityValue = normalizeSalesQuantityValue($rawQuantity);
        if ($quantityValue === "") {
            return ["items" => [], "error" => "الكمية يجب أن تكون رقمًا صحيحًا أكبر من صفر."];
        }

        $category = $categoriesMap[$categoryId];
        $invoiceItemSizeName = "";
        if (($category["has_sizes"] ?? false) === true) {
            if ($rawSizeName === "") {
                return ["items" => [], "error" => "اختر المقاس المطلوب لكل صنف له مقاسات."];
            }

            $matchingSizeNames = array_column($category["sizes"] ?? [], "size_name");
            if (!in_array($rawSizeName, $matchingSizeNames, true)) {
                return ["items" => [], "error" => "المقاس المحدد غير متاح للصنف المختار."];
            }

            $invoiceItemSizeName = $rawSizeName;
        }

        $itemUniqueKey = $categoryId . "|" . $invoiceItemSizeName;
        if (in_array($itemUniqueKey, $usedItemKeys, true)) {
            return ["items" => [], "error" => "لا يمكن تكرار نفس الصنف والمقاس داخل الفاتورة الواحدة."];
        }

        $usedItemKeys[] = $itemUniqueKey;
        $quantity = (int)$quantityValue;
        $unitPrice = (float)$category["price"];
        $lineTotal = round($quantity * $unitPrice, 2);

        $items[] = [
            "category_id" => $categoryId,
            "category_name" => (string)$category["category_name"],
            "size_name" => $invoiceItemSizeName,
            "pricing_type" => (string)$category["pricing_type"],
            "has_sizes" => ($category["has_sizes"] ?? false) === true,
            "quantity" => $quantity,
            "unit_price" => number_format($unitPrice, 2, ".", ""),
            "line_total" => number_format($lineTotal, 2, ".", ""),
        ];
    }

    if (count($items) === 0) {
        return ["items" => [], "error" => "أضف صنفًا واحدًا على الأقل داخل الفاتورة."];
    }

    return ["items" => $items, "error" => ""];
}

function getSalesInvoiceTotals(array $items)
{
    $totalAmount = 0.0;
    foreach ($items as $item) {
        $totalAmount += (float)$item["line_total"];
    }

    return number_format(round($totalAmount, 2), 2, ".", "");
}

function fetchSalesInvoiceWithItems(PDO $pdo, $invoiceId, $gameId)
{
    $invoiceStmt = $pdo->prepare(
        "SELECT id, game_id, customer_name, invoice_type, invoice_date, total_amount, paid_amount, created_at, updated_at
         FROM sales_invoices
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $invoiceStmt->execute([(int)$invoiceId, (int)$gameId]);
    $invoice = $invoiceStmt->fetch();
    if (!$invoice) {
        return null;
    }

    $itemsStmt = $pdo->prepare(
        "SELECT id, invoice_id, category_id, category_name, size_name, quantity, unit_price, line_total
         FROM sales_invoice_items
         WHERE invoice_id = ?
         ORDER BY id ASC"
    );
    $itemsStmt->execute([(int)$invoiceId]);
    $invoice["items"] = $itemsStmt->fetchAll();

    return $invoice;
}

function calculateSalesSizeTotalQuantity(array $sizeRows)
{
    $total = 0;
    foreach ($sizeRows as $sizeRow) {
        $total += (int)($sizeRow["quantity"] ?? 0);
    }

    return $total;
}

function buildSalesLockPlaceholders(array $values)
{
    return implode(", ", array_fill(0, count($values), "?"));
}

function lockSalesCategories(PDO $pdo, $gameId, array $categoryIds)
{
    $categoryIds = array_values(array_unique(array_map("intval", $categoryIds)));
    if (count($categoryIds) === 0) {
        return [];
    }

    $sql =
        "SELECT id, category_name, pricing_type, has_sizes, quantity, price
         FROM store_categories
         WHERE game_id = ? AND id IN (" . buildSalesLockPlaceholders($categoryIds) . ")
         FOR UPDATE";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([(int)$gameId], $categoryIds));

    $lockedCategories = [];
    foreach ($stmt->fetchAll() as $category) {
        $lockedCategories[(int)$category["id"]] = [
            "id" => (int)$category["id"],
            "category_name" => (string)$category["category_name"],
            "pricing_type" => (string)($category["pricing_type"] ?? "price_only"),
            "has_sizes" => (int)($category["has_sizes"] ?? 0) === 1,
            "quantity" => $category["quantity"] === null ? null : (int)$category["quantity"],
            "sizes" => [],
        ];
    }

    if (count($lockedCategories) === 0) {
        return $lockedCategories;
    }

    $sizesStmt = $pdo->prepare(
        "SELECT scs.category_id, scs.size_name, scs.quantity
         FROM store_category_sizes scs
         WHERE scs.category_id IN (" . buildSalesLockPlaceholders(array_keys($lockedCategories)) . ")
         FOR UPDATE"
    );
    $sizesStmt->execute(array_map("intval", array_keys($lockedCategories)));
    foreach ($sizesStmt->fetchAll() as $sizeRow) {
        $categoryId = (int)$sizeRow["category_id"];
        $sizeName = (string)$sizeRow["size_name"];
        if (!isset($lockedCategories[$categoryId])) {
            continue;
        }

        $lockedCategories[$categoryId]["sizes"][$sizeName] = [
            "size_name" => $sizeName,
            "quantity" => (int)$sizeRow["quantity"],
        ];
    }

    return $lockedCategories;
}

function getSalesUnavailableCategoryMessage(array $requestedCategoryIds, array $lockedCategories)
{
    $requestedCategoryIds = array_values(array_unique(array_map("intval", $requestedCategoryIds)));
    $missingCategoryIds = array_values(array_diff($requestedCategoryIds, array_keys($lockedCategories)));
    if (count($missingCategoryIds) === 0) {
        return "";
    }

    return "الأصناف التالية غير متاحة الآن: #" . implode(", #", $missingCategoryIds) . ".";
}

function applySalesInventoryImpact(array &$lockedCategories, $invoiceType, array $items, $reverse = false)
{
    foreach ($items as $item) {
        $categoryId = (int)$item["category_id"];
        if (!isset($lockedCategories[$categoryId])) {
            return "أحد الأصناف المرتبطة بالفاتورة غير متاح الآن.";
        }

        if (($lockedCategories[$categoryId]["pricing_type"] ?? "") !== "price_with_quantity") {
            continue;
        }

        $itemQuantity = (int)$item["quantity"];
        $delta = 0;

        if ($invoiceType === "purchase") {
            $delta = $reverse ? $itemQuantity : -$itemQuantity;
        } else {
            $delta = $reverse ? -$itemQuantity : $itemQuantity;
        }

        if (($lockedCategories[$categoryId]["has_sizes"] ?? false) === true) {
            $sizeName = trim((string)($item["size_name"] ?? ""));
            if ($sizeName === "" || !isset($lockedCategories[$categoryId]["sizes"][$sizeName])) {
                return "أحد المقاسات المرتبطة بالصنف \"" . $lockedCategories[$categoryId]["category_name"] . "\" غير متاح الآن.";
            }

            $currentQuantity = (int)$lockedCategories[$categoryId]["sizes"][$sizeName]["quantity"];
            if (($currentQuantity + $delta) < 0) {
                return "الكمية المتاحة لا تسمح بحفظ التعديل للمقاس \"" . $sizeName . "\" في الصنف \"" . $lockedCategories[$categoryId]["category_name"] . "\".";
            }

            $lockedCategories[$categoryId]["sizes"][$sizeName]["quantity"] = $currentQuantity + $delta;
            $updatedCategoryQuantity = calculateSalesSizeTotalQuantity($lockedCategories[$categoryId]["sizes"]);
            $lockedCategories[$categoryId]["quantity"] = $updatedCategoryQuantity;
            continue;
        }

        $currentQuantity = $lockedCategories[$categoryId]["quantity"] === null ? 0 : (int)$lockedCategories[$categoryId]["quantity"];
        if (($currentQuantity + $delta) < 0) {
            return "الكمية المتاحة لا تسمح بحفظ التعديل للصنف \"" . $lockedCategories[$categoryId]["category_name"] . "\".";
        }

        $lockedCategories[$categoryId]["quantity"] = $currentQuantity + $delta;
    }

    return "";
}

function persistSalesCategoryQuantities(PDO $pdo, $gameId, array $lockedCategories, array $originalCategories)
{
    $updateStmt = $pdo->prepare(
        "UPDATE store_categories
         SET quantity = ?
         WHERE id = ? AND game_id = ?"
    );
    $updateSizeStmt = $pdo->prepare(
        "UPDATE store_category_sizes
         SET quantity = ?
         WHERE category_id = ? AND size_name = ?"
    );

    foreach ($lockedCategories as $categoryId => $category) {
        if (($category["pricing_type"] ?? "") !== "price_with_quantity") {
            continue;
        }

        if (($category["has_sizes"] ?? false) === true) {
            foreach (($category["sizes"] ?? []) as $sizeName => $sizeRow) {
                $originalSizeQuantity = $originalCategories[$categoryId]["sizes"][$sizeName]["quantity"] ?? null;
                $newSizeQuantity = (int)$sizeRow["quantity"];
                if ($originalSizeQuantity !== $newSizeQuantity) {
                    $updateSizeStmt->execute([$newSizeQuantity, (int)$categoryId, (string)$sizeName]);
                }
            }
        }

        $originalQuantity = $originalCategories[$categoryId]["quantity"] ?? null;
        $newQuantity = $category["quantity"];
        if ($originalQuantity === $newQuantity) {
            continue;
        }

        $updateStmt->execute([$newQuantity, (int)$categoryId, (int)$gameId]);
    }
}

function fetchSalesInvoicesForDate(PDO $pdo, $gameId, $selectedDate, $filterType)
{
    $params = [(int)$gameId, (string)$selectedDate];
    $sql =
        "SELECT i.id, i.invoice_type, i.invoice_date, i.customer_name, i.total_amount, i.paid_amount, i.created_at,
                creator.username AS created_by_name,
                updater.username AS updated_by_name
         FROM sales_invoices i
         LEFT JOIN users creator ON creator.id = i.created_by_user_id
         LEFT JOIN users updater ON updater.id = i.updated_by_user_id
         WHERE i.game_id = ?
           AND i.invoice_date = ?";

    if ($filterType !== "all") {
        $sql .= " AND i.invoice_type = ?";
        $params[] = $filterType;
    }

    $sql .= " ORDER BY i.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    if (count($invoices) === 0) {
        return [];
    }

    $invoiceIds = array_map("intval", array_column($invoices, "id"));
    $itemsStmt = $pdo->prepare(
        "SELECT invoice_id, category_name, size_name, quantity, unit_price, line_total
         FROM sales_invoice_items
         WHERE invoice_id IN (" . buildSalesLockPlaceholders($invoiceIds) . ")
         ORDER BY id ASC"
    );
    $itemsStmt->execute($invoiceIds);

    $itemsByInvoice = [];
    foreach ($itemsStmt->fetchAll() as $item) {
        $invoiceId = (int)$item["invoice_id"];
        if (!isset($itemsByInvoice[$invoiceId])) {
            $itemsByInvoice[$invoiceId] = [];
        }
        $itemsByInvoice[$invoiceId][] = $item;
    }

    foreach ($invoices as &$invoice) {
        $invoice["items"] = $itemsByInvoice[(int)$invoice["id"]] ?? [];
        $invoice["remaining_amount"] = number_format(
            round((float)$invoice["total_amount"] - (float)$invoice["paid_amount"], 2),
            2,
            ".",
            ""
        );
    }
    unset($invoice);

    return $invoices;
}

function formatSalesItemName(array $item)
{
    $categoryName = trim((string)($item["category_name"] ?? ""));
    $sizeName = trim((string)($item["size_name"] ?? ""));
    if ($sizeName === "") {
        return $categoryName;
    }

    return $categoryName . " - " . $sizeName;
}

function summarizeSalesDay(PDO $pdo, $gameId, $selectedDate)
{
    $stmt = $pdo->prepare(
        "SELECT invoice_type,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total_amount), 0) AS total_amount,
                COALESCE(SUM(paid_amount), 0) AS paid_amount
         FROM sales_invoices
         WHERE game_id = ?
           AND invoice_date = ?
         GROUP BY invoice_type"
    );
    $stmt->execute([(int)$gameId, (string)$selectedDate]);

    $summary = [
        "invoice_count" => 0,
        "purchase_total" => 0.0,
        "return_total" => 0.0,
        "net_total" => 0.0,
        "net_paid" => 0.0,
        "remaining_total" => 0.0,
    ];

    foreach ($stmt->fetchAll() as $row) {
        $invoiceType = (string)($row["invoice_type"] ?? "purchase");
        $count = (int)($row["invoice_count"] ?? 0);
        $totalAmount = (float)($row["total_amount"] ?? 0);
        $paidAmount = (float)($row["paid_amount"] ?? 0);

        $summary["invoice_count"] += $count;
        if ($invoiceType === "return") {
            $summary["return_total"] += $totalAmount;
            $summary["net_total"] -= $totalAmount;
            $summary["net_paid"] -= $paidAmount;
        } else {
            $summary["purchase_total"] += $totalAmount;
            $summary["net_total"] += $totalAmount;
            $summary["net_paid"] += $paidAmount;
        }
    }

    $summary["remaining_total"] = round($summary["net_total"] - $summary["net_paid"], 2);

    return $summary;
}

function salesInvoiceTypeLabel($invoiceType)
{
    return $invoiceType === "return" ? "مرتجع" : "شراء";
}

function salesInvoiceTypeClass($invoiceType)
{
    return $invoiceType === "return" ? "sales-type-return" : "sales-type-purchase";
}

if (!isset($_SESSION["sales_csrf_token"])) {
    $_SESSION["sales_csrf_token"] = bin2hex(random_bytes(32));
}

ensureStoreCategoriesTable($pdo);
ensureSalesInvoicesTables($pdo);

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
$activeMenu = "sales";

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
if (!isValidSalesDate($selectedDate)) {
    $selectedDate = $todayDate;
}

$selectedFilterType = trim((string)($_GET["filter_type"] ?? "all"));
if (!in_array($selectedFilterType, ["all", "purchase", "return"], true)) {
    $selectedFilterType = "all";
}

$categoriesMap = fetchSalesCategories($pdo, $currentGameId);
$categories = array_values($categoriesMap);
$formData = [
    "id" => 0,
    "invoice_type" => "purchase",
    "invoice_date" => $selectedDate,
    "customer_name" => "",
    "paid_amount" => "0.00",
    "items" => [
        [
            "category_id" => 0,
            "size_name" => "",
            "quantity" => "1",
        ],
    ],
];

$flashSuccess = $_SESSION["sales_success"] ?? "";
unset($_SESSION["sales_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["sales_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["invoice_id"] ?? 0),
                "invoice_type" => trim((string)($_POST["invoice_type"] ?? "purchase")),
                "invoice_date" => trim((string)($_POST["invoice_date"] ?? $selectedDate)),
                "customer_name" => trim((string)($_POST["customer_name"] ?? "")),
                "paid_amount" => trim((string)($_POST["paid_amount"] ?? "0")),
                "items" => [],
            ];

            $postedCategoryIds = $_POST["item_category_id"] ?? [];
            $postedSizeNames = $_POST["item_size_name"] ?? [];
            $postedQuantities = $_POST["item_quantity"] ?? [];
            if (!is_array($postedCategoryIds)) {
                $postedCategoryIds = [];
            }
            if (!is_array($postedSizeNames)) {
                $postedSizeNames = [];
            }
            if (!is_array($postedQuantities)) {
                $postedQuantities = [];
            }

            $rowCount = max(count($postedCategoryIds), count($postedSizeNames), count($postedQuantities));
            for ($index = 0; $index < $rowCount; $index++) {
                $formData["items"][] = [
                    "category_id" => (int)($postedCategoryIds[$index] ?? 0),
                    "size_name" => trim((string)($postedSizeNames[$index] ?? "")),
                    "quantity" => trim((string)($postedQuantities[$index] ?? "")),
                ];
            }
            if (count($formData["items"]) === 0) {
                $formData["items"][] = ["category_id" => 0, "size_name" => "", "quantity" => "1"];
            }

            if ($formData["id"] > 0 && !$isManager) {
                $error = "تعديل الفواتير متاح للمدير فقط.";
            } elseif (!in_array($formData["invoice_type"], ["purchase", "return"], true)) {
                $error = "نوع الفاتورة غير صالح.";
            } elseif (!isValidSalesDate($formData["invoice_date"])) {
                $error = "تاريخ الفاتورة غير صحيح.";
            }

            $collectedItems = ["items" => [], "error" => ""];
            if ($error === "") {
                $collectedItems = collectSalesInvoiceItems($postedCategoryIds, $postedSizeNames, $postedQuantities, $categoriesMap);
                $error = $collectedItems["error"];
            }

            $paidAmountValue = normalizeSalesAmountValue($formData["paid_amount"]);
            if ($error === "" && $paidAmountValue === "") {
                $error = "قيمة المدفوع غير صحيحة.";
            }

            $invoiceItems = $collectedItems["items"];
            $totalAmountValue = $error === "" ? getSalesInvoiceTotals($invoiceItems) : "0.00";
            if ($error === "" && (float)$paidAmountValue > (float)$totalAmountValue) {
                $error = "المدفوع لا يمكن أن يتجاوز إجمالي الفاتورة.";
            }

            if ($error === "") {
                try {
                    $pdo->beginTransaction();

                    $existingInvoice = null;
                    if ($formData["id"] > 0) {
                        $existingInvoice = fetchSalesInvoiceWithItems($pdo, $formData["id"], $currentGameId);
                        if (!$existingInvoice) {
                            throw new RuntimeException("الفاتورة غير متاحة.");
                        }
                    }

                    $categoryIdsToLock = array_map("intval", array_column($invoiceItems, "category_id"));
                    if ($existingInvoice) {
                        $categoryIdsToLock = array_values(array_unique(array_merge(
                            $categoryIdsToLock,
                            array_map("intval", array_column($existingInvoice["items"], "category_id"))
                        )));
                    }

                    $lockedCategories = lockSalesCategories($pdo, $currentGameId, $categoryIdsToLock);
                    if (count($lockedCategories) !== count($categoryIdsToLock)) {
                        throw new RuntimeException(
                            getSalesUnavailableCategoryMessage($categoryIdsToLock, $lockedCategories) ?: "يوجد صنف غير متاح داخل الفاتورة."
                        );
                    }
                    $originalCategories = $lockedCategories;

                    if ($existingInvoice) {
                        $reverseError = applySalesInventoryImpact(
                            $lockedCategories,
                            (string)$existingInvoice["invoice_type"],
                            $existingInvoice["items"],
                            true
                        );
                        if ($reverseError !== "") {
                            throw new RuntimeException($reverseError);
                        }
                    }

                    $applyError = applySalesInventoryImpact(
                        $lockedCategories,
                        $formData["invoice_type"],
                        $invoiceItems,
                        false
                    );
                    if ($applyError !== "") {
                        throw new RuntimeException($applyError);
                    }

                    persistSalesCategoryQuantities($pdo, $currentGameId, $lockedCategories, $originalCategories);

                    if ($existingInvoice) {
                        $updateInvoiceStmt = $pdo->prepare(
                            "UPDATE sales_invoices
                             SET invoice_type = ?, invoice_date = ?, total_amount = ?, paid_amount = ?, customer_name = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateInvoiceStmt->execute([
                            $formData["invoice_type"],
                            $formData["invoice_date"],
                            $totalAmountValue,
                            $paidAmountValue,
                            $formData["customer_name"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        $deleteItemsStmt = $pdo->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?");
                        $deleteItemsStmt->execute([$formData["id"]]);
                        $invoiceId = (int)$formData["id"];
                        auditLogActivity($pdo, "update", "sales_invoices", $invoiceId, "المبيعات", "تعديل فاتورة #" . $invoiceId . " - العميل: " . (string)$formData["customer_name"] . " - الإجمالي: " . $totalAmountValue);
                        $_SESSION["sales_success"] = "تم تحديث الفاتورة.";
                    } else {
                        $insertInvoiceStmt = $pdo->prepare(
                            "INSERT INTO sales_invoices (game_id, invoice_type, invoice_date, total_amount, paid_amount, customer_name, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertInvoiceStmt->execute([
                            $currentGameId,
                            $formData["invoice_type"],
                            $formData["invoice_date"],
                            $totalAmountValue,
                            $paidAmountValue,
                            $formData["customer_name"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $invoiceId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "sales_invoices", $invoiceId, "المبيعات", "إضافة فاتورة #" . $invoiceId . " - العميل: " . (string)$formData["customer_name"] . " - الإجمالي: " . $totalAmountValue);
                        $_SESSION["sales_success"] = "تم حفظ الفاتورة.";
                    }

                    $insertItemStmt = $pdo->prepare(
                        "INSERT INTO sales_invoice_items (invoice_id, category_id, category_name, size_name, quantity, unit_price, line_total)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    foreach ($invoiceItems as $item) {
                        $insertItemStmt->execute([
                            $invoiceId,
                            (int)$item["category_id"],
                            (string)$item["category_name"],
                            (string)$item["size_name"],
                            (int)$item["quantity"],
                            (string)$item["unit_price"],
                            (string)$item["line_total"],
                        ]);
                    }

                    $pdo->commit();
                    $_SESSION["sales_csrf_token"] = bin2hex(random_bytes(32));
                    header("Location: sales.php?" . http_build_query([
                        "date" => $formData["invoice_date"],
                        "filter_type" => $selectedFilterType,
                    ]));
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $throwable instanceof RuntimeException
                        ? $throwable->getMessage()
                        : "تعذر حفظ الفاتورة.";
                    error_log("Sales save error: " . $throwable->getMessage());
                }
            }
        }

        if ($action === "delete") {
            $invoiceId = (int)($_POST["invoice_id"] ?? 0);
            $redirectDate = trim((string)($_POST["redirect_date"] ?? $selectedDate));
            if (!isValidSalesDate($redirectDate)) {
                $redirectDate = $todayDate;
            }
            $redirectFilterType = trim((string)($_POST["redirect_filter_type"] ?? $selectedFilterType));
            if (!in_array($redirectFilterType, ["all", "purchase", "return"], true)) {
                $redirectFilterType = "all";
            }

            if (!$isManager) {
                $error = "حذف الفواتير متاح للمدير فقط.";
            } elseif ($invoiceId <= 0) {
                $error = "الفاتورة غير صالحة.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $existingInvoice = fetchSalesInvoiceWithItems($pdo, $invoiceId, $currentGameId);
                    if (!$existingInvoice) {
                        throw new RuntimeException("الفاتورة غير متاحة.");
                    }

                    $categoryIdsToLock = array_map("intval", array_column($existingInvoice["items"], "category_id"));
                    $lockedCategories = lockSalesCategories($pdo, $currentGameId, $categoryIdsToLock);
                    if (count($lockedCategories) !== count($categoryIdsToLock)) {
                        throw new RuntimeException(
                            getSalesUnavailableCategoryMessage($categoryIdsToLock, $lockedCategories) ?: "يوجد صنف غير متاح داخل الفاتورة."
                        );
                    }
                    $originalCategories = $lockedCategories;

                    $reverseError = applySalesInventoryImpact(
                        $lockedCategories,
                        (string)$existingInvoice["invoice_type"],
                        $existingInvoice["items"],
                        true
                    );
                    if ($reverseError !== "") {
                        throw new RuntimeException($reverseError);
                    }

                    persistSalesCategoryQuantities($pdo, $currentGameId, $lockedCategories, $originalCategories);

                    $deleteInvoiceStmt = $pdo->prepare("DELETE FROM sales_invoices WHERE id = ? AND game_id = ?");
                    $deleteInvoiceStmt->execute([$invoiceId, $currentGameId]);
                    if ($deleteInvoiceStmt->rowCount() === 0) {
                        throw new RuntimeException("الفاتورة غير متاحة.");
                    }

                    auditLogActivity($pdo, "delete", "sales_invoices", $invoiceId, "المبيعات", "حذف فاتورة #" . $invoiceId . " - العميل: " . (string)($existingInvoice["customer_name"] ?? "") . " - الإجمالي: " . (string)($existingInvoice["total_amount"] ?? ""));
                    $pdo->commit();
                    $_SESSION["sales_success"] = "تم حذف الفاتورة.";
                    $_SESSION["sales_csrf_token"] = bin2hex(random_bytes(32));
                    header("Location: sales.php?" . http_build_query([
                        "date" => $redirectDate,
                        "filter_type" => $redirectFilterType,
                    ]));
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $throwable instanceof RuntimeException
                        ? $throwable->getMessage()
                        : "تعذر حذف الفاتورة.";
                    error_log("Sales delete error: " . $throwable->getMessage());
                }
            }
        }
    }
}

$editInvoiceId = (int)($_GET["edit"] ?? 0);
if ($isManager && $editInvoiceId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editInvoice = fetchSalesInvoiceWithItems($pdo, $editInvoiceId, $currentGameId);
    if ($editInvoice) {
        $missingCategory = false;
        $formItems = [];
        foreach ($editInvoice["items"] as $item) {
            $categoryId = (int)$item["category_id"];
            if (!isset($categoriesMap[$categoryId])) {
                $missingCategory = true;
                break;
            }

            $formItems[] = [
                "category_id" => $categoryId,
                "size_name" => (string)($item["size_name"] ?? ""),
                "quantity" => (string)(int)$item["quantity"],
            ];
        }

        if ($missingCategory) {
            $error = "لا يمكن تعديل هذه الفاتورة قبل إعادة إضافة الأصناف المحذوفة.";
        } else {
            $formData = [
                "id" => (int)$editInvoice["id"],
                "invoice_type" => (string)$editInvoice["invoice_type"],
                "invoice_date" => (string)$editInvoice["invoice_date"],
                "customer_name" => (string)($editInvoice["customer_name"] ?? ""),
                "paid_amount" => number_format((float)$editInvoice["paid_amount"], 2, ".", ""),
                "items" => count($formItems) > 0 ? $formItems : [["category_id" => 0, "size_name" => "", "quantity" => "1"]],
            ];
            $selectedDate = (string)$editInvoice["invoice_date"];
        }
    }
}

$summary = summarizeSalesDay($pdo, $currentGameId, $selectedDate);
$invoices = fetchSalesInvoicesForDate($pdo, $currentGameId, $selectedDate, $selectedFilterType);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المبيعات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page sales-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content sales-page-main">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>المبيعات</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <span class="context-badge"><?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?></span>
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

        <section class="sales-summary-grid">
            <div class="sales-summary-card sales-summary-primary">
                <span>فواتير اليوم</span>
                <strong><?php echo (int)$summary["invoice_count"]; ?></strong>
            </div>
            <div class="sales-summary-card">
                <span>مبيعات اليوم</span>
                <strong><?php echo htmlspecialchars(formatSalesCurrency($summary["purchase_total"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
            <div class="sales-summary-card">
                <span>مرتجعات اليوم</span>
                <strong><?php echo htmlspecialchars(formatSalesCurrency($summary["return_total"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
            <div class="sales-summary-card">
                <span>صافي اليوم</span>
                <strong><?php echo htmlspecialchars(formatSalesCurrency($summary["net_total"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
            <div class="sales-summary-card">
                <span>المدفوع اليوم</span>
                <strong><?php echo htmlspecialchars(formatSalesCurrency($summary["net_paid"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
            <div class="sales-summary-card">
                <span>المتبقي</span>
                <strong><?php echo htmlspecialchars(formatSalesCurrency($summary["remaining_total"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
        </section>

        <section class="sales-layout">
            <div class="sales-form-stack">
                <div class="card sales-filter-card">
                    <div class="card-head">
                        <div>
                            <h3>فلترة الفواتير</h3>
                        </div>
                    </div>
                    <form method="GET" class="sales-filter-form">
                        <div class="sales-filter-grid">
                            <div class="form-group">
                                <label for="salesFilterDate">التاريخ</label>
                                <input type="date" name="date" id="salesFilterDate" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="salesFilterType">نوع الفاتورة</label>
                                <select name="filter_type" id="salesFilterType">
                                    <option value="all" <?php echo $selectedFilterType === "all" ? "selected" : ""; ?>>الكل</option>
                                    <option value="purchase" <?php echo $selectedFilterType === "purchase" ? "selected" : ""; ?>>شراء</option>
                                    <option value="return" <?php echo $selectedFilterType === "return" ? "selected" : ""; ?>>مرتجع</option>
                                </select>
                            </div>
                        </div>
                        <div class="sales-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض الفواتير</button>
                            <a href="sales.php" class="btn btn-soft">اليوم الحالي</a>
                        </div>
                    </form>
                </div>

                <div class="card sales-invoice-card">
                    <div class="card-head">
                        <div>
                            <h3><?php echo $formData["id"] > 0 ? "تعديل فاتورة" : "إنشاء فاتورة"; ?></h3>
                        </div>
                        <?php if ($formData["id"] > 0): ?>
                            <a href="sales.php?<?php echo htmlspecialchars(http_build_query(["date" => $selectedDate, "filter_type" => $selectedFilterType]), ENT_QUOTES, "UTF-8"); ?>" class="btn btn-soft">إلغاء</a>
                        <?php endif; ?>
                    </div>

                    <?php if (count($categories) === 0): ?>
                        <div class="empty-state">ابدأ بإضافة الأصناف أولاً حتى تتمكن من إنشاء الفواتير.</div>
                    <?php else: ?>
                        <form method="POST" class="sales-invoice-form" id="salesInvoiceForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["sales_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="invoice_id" value="<?php echo (int)$formData["id"]; ?>">
                            <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">

                            <div class="sales-form-grid">
                                <div class="form-group">
                                    <label>نوع الفاتورة</label>
                                    <div class="sales-type-grid">
                                        <label class="sales-type-option">
                                            <input type="radio" name="invoice_type" value="purchase" <?php echo $formData["invoice_type"] === "purchase" ? "checked" : ""; ?>>
                                            <span class="sales-type-body">فاتورة شراء</span>
                                        </label>
                                        <label class="sales-type-option">
                                            <input type="radio" name="invoice_type" value="return" <?php echo $formData["invoice_type"] === "return" ? "checked" : ""; ?>>
                                            <span class="sales-type-body">فاتورة مرتجع</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="invoiceDate">تاريخ الفاتورة</label>
                                    <input type="date" name="invoice_date" id="invoiceDate" value="<?php echo htmlspecialchars($formData["invoice_date"], ENT_QUOTES, "UTF-8"); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="customerName">اسم العميل (اختياري)</label>
                                    <input type="text" name="customer_name" id="customerName" value="<?php echo htmlspecialchars($formData["customer_name"], ENT_QUOTES, "UTF-8"); ?>" placeholder="مثال: أحمد محمد">
                                </div>
                                <div class="form-group">
                                    <label for="paidAmount">المدفوع</label>
                                    <input type="number" min="0" step="0.01" inputmode="decimal" name="paid_amount" id="paidAmount" value="<?php echo htmlspecialchars((string)$formData["paid_amount"], ENT_QUOTES, "UTF-8"); ?>" required>
                                </div>
                            </div>

                            <div class="sales-items-section">
                                <div class="sales-items-head">
                                    <h4>أصناف الفاتورة</h4>
                                    <button type="button" class="btn btn-primary sales-add-row-btn" id="addSalesItemRow">إضافة صنف</button>
                                </div>
                                <div class="table-wrap sales-items-wrap">
                                    <table class="data-table sales-items-table">
                                        <thead>
                                            <tr>
                                                <th>الصنف</th>
                                                <th>المقاس</th>
                                                <th>الرصيد</th>
                                                <th>الكمية</th>
                                                <th>سعر الوحدة</th>
                                                <th>الإجمالي</th>
                                                <th>الإجراء</th>
                                            </tr>
                                        </thead>
                                        <tbody id="salesItemsBody">
                                            <?php foreach ($formData["items"] as $itemIndex => $itemRow): ?>
                                                <tr class="sales-item-row">
                                                    <td data-label="الصنف">
                                                        <select name="item_category_id[]" class="sales-item-select" required>
                                                            <option value="">اختر الصنف</option>
                                                            <?php foreach ($categories as $category): ?>
                                                                <option
                                                                    value="<?php echo (int)$category["id"]; ?>"
                                                                    data-price="<?php echo htmlspecialchars((string)$category["price"], ENT_QUOTES, "UTF-8"); ?>"
                                                                    data-stock-managed="<?php echo ($category["pricing_type"] ?? "") === "price_with_quantity" ? "1" : "0"; ?>"
                                                                    data-stock="<?php echo $category["quantity"] === null ? "" : (int)$category["quantity"]; ?>"
                                                                    data-has-sizes="<?php echo ($category["has_sizes"] ?? false) ? "1" : "0"; ?>"
                                                                    data-sizes="<?php echo htmlspecialchars(json_encode($category["sizes"] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>"
                                                                    <?php echo (int)$itemRow["category_id"] === (int)$category["id"] ? "selected" : ""; ?>
                                                                >
                                                                    <?php echo htmlspecialchars((string)$category["category_name"], ENT_QUOTES, "UTF-8"); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td data-label="المقاس">
                                                        <select name="item_size_name[]" class="sales-item-size-select">
                                                            <option value="">بدون مقاس</option>
                                                        </select>
                                                        <input type="hidden" class="sales-item-size-value" value="<?php echo htmlspecialchars((string)($itemRow["size_name"] ?? ""), ENT_QUOTES, "UTF-8"); ?>">
                                                    </td>
                                                    <td data-label="الرصيد">
                                                        <span class="sales-stock-pill">—</span>
                                                    </td>
                                                    <td data-label="الكمية">
                                                        <input type="number" min="1" step="1" inputmode="numeric" name="item_quantity[]" class="sales-item-quantity" value="<?php echo htmlspecialchars((string)$itemRow["quantity"], ENT_QUOTES, "UTF-8"); ?>" required>
                                                    </td>
                                                    <td data-label="سعر الوحدة">
                                                        <span class="sales-price-pill sales-unit-price">0.00 ج.م</span>
                                                    </td>
                                                    <td data-label="الإجمالي">
                                                        <span class="sales-price-pill sales-line-total">0.00 ج.م</span>
                                                    </td>
                                                    <td data-label="الإجراء">
                                                        <button type="button" class="btn btn-danger sales-remove-row">حذف</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="sales-total-grid">
                                <div class="sales-total-card">
                                    <span>إجمالي الفاتورة</span>
                                    <strong id="invoiceTotalDisplay">0.00 ج.م</strong>
                                </div>
                                <div class="sales-total-card">
                                    <span>المتبقي</span>
                                    <strong id="invoiceRemainingDisplay">0.00 ج.م</strong>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary sales-submit-btn"><?php echo $formData["id"] > 0 ? "تحديث الفاتورة" : "حفظ الفاتورة"; ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card sales-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>فواتير اليوم</h3>
                    </div>
                    <span class="table-counter"><?php echo count($invoices); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الفاتورة</th>
                                <th>النوع</th>
                                <th>اسم العميل</th>
                                <th>الأصناف</th>
                                <th>الإجمالي</th>
                                <th>المدفوع</th>
                                <th>المتبقي</th>
                                <th>أنشئ بواسطة</th>
                                <th>آخر تعديل</th>
                                <th>وقت التسجيل</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($invoices) === 0): ?>
                                <tr>
                                    <td colspan="11" class="empty-cell">لا توجد فواتير مطابقة لليوم المحدد.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td data-label="الفاتورة">
                                            <div class="sales-invoice-code">#<?php echo str_pad((string)(int)$invoice["id"], 4, "0", STR_PAD_LEFT); ?></div>
                                        </td>
                                        <td data-label="النوع">
                                            <span class="sales-type-pill <?php echo htmlspecialchars(salesInvoiceTypeClass((string)$invoice["invoice_type"]), ENT_QUOTES, "UTF-8"); ?>">
                                                <?php echo htmlspecialchars(salesInvoiceTypeLabel((string)$invoice["invoice_type"]), ENT_QUOTES, "UTF-8"); ?>
                                            </span>
                                        </td>
                                        <td data-label="اسم العميل">
                                            <?php echo htmlspecialchars($invoice["customer_name"] ?? "", ENT_QUOTES, "UTF-8"); ?>
                                        </td>
                                        <td data-label="الأصناف">
                                                <div class="sales-items-preview">
                                                    <?php foreach ($invoice["items"] as $item): ?>
                                                        <div class="sales-preview-item">
                                                            <span><?php echo htmlspecialchars(formatSalesItemName($item), ENT_QUOTES, "UTF-8"); ?></span>
                                                            <strong><?php echo (int)$item["quantity"]; ?> × <?php echo htmlspecialchars(formatSalesCurrency($item["unit_price"]), ENT_QUOTES, "UTF-8"); ?></strong>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                        </td>
                                        <td data-label="الإجمالي">
                                            <span class="sales-price-pill"><?php echo htmlspecialchars(formatSalesCurrency($invoice["total_amount"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="المدفوع">
                                            <span class="sales-price-pill"><?php echo htmlspecialchars(formatSalesCurrency($invoice["paid_amount"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="المتبقي">
                                            <span class="sales-price-pill"><?php echo htmlspecialchars(formatSalesCurrency($invoice["remaining_amount"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="أنشئ بواسطة">
                                            <?php echo htmlspecialchars($invoice["created_by_name"] ?? "—", ENT_QUOTES, "UTF-8"); ?>
                                        </td>
                                        <td data-label="آخر تعديل">
                                            <?php echo htmlspecialchars($invoice["updated_by_name"] ?? "—", ENT_QUOTES, "UTF-8"); ?>
                                        </td>
                                        <td data-label="وقت التسجيل"><?php echo htmlspecialchars(formatSalesDateTime((string)$invoice["created_at"]), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <?php if ($isManager): ?>
                                                <div class="inline-actions">
                                                    <a href="sales.php?<?php echo htmlspecialchars(http_build_query(["date" => $selectedDate, "filter_type" => $selectedFilterType, "edit" => (int)$invoice["id"]]), ENT_QUOTES, "UTF-8"); ?>" class="btn btn-warning">تعديل</a>
                                                    <form method="POST" class="inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["sales_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice["id"]; ?>">
                                                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                                                        <input type="hidden" name="redirect_filter_type" value="<?php echo htmlspecialchars($selectedFilterType, ENT_QUOTES, "UTF-8"); ?>">
                                                        <button type="submit" class="btn btn-danger" onclick="return confirm('هل تريد حذف هذه الفاتورة؟')">حذف</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge trainer-empty-badge">للمدير فقط</span>
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

<?php if (count($categories) > 0): ?>
<template id="salesItemRowTemplate">
    <tr class="sales-item-row">
        <td data-label="الصنف">
            <select name="item_category_id[]" class="sales-item-select" required>
                <option value="">اختر الصنف</option>
                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?php echo (int)$category["id"]; ?>"
                        data-price="<?php echo htmlspecialchars((string)$category["price"], ENT_QUOTES, "UTF-8"); ?>"
                        data-stock-managed="<?php echo ($category["pricing_type"] ?? "") === "price_with_quantity" ? "1" : "0"; ?>"
                        data-stock="<?php echo $category["quantity"] === null ? "" : (int)$category["quantity"]; ?>"
                        data-has-sizes="<?php echo ($category["has_sizes"] ?? false) ? "1" : "0"; ?>"
                        data-sizes="<?php echo htmlspecialchars(json_encode($category["sizes"] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>"
                    >
                        <?php echo htmlspecialchars((string)$category["category_name"], ENT_QUOTES, "UTF-8"); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td data-label="المقاس">
            <select name="item_size_name[]" class="sales-item-size-select">
                <option value="">بدون مقاس</option>
            </select>
            <input type="hidden" class="sales-item-size-value" value="">
        </td>
        <td data-label="الرصيد">
            <span class="sales-stock-pill">—</span>
        </td>
        <td data-label="الكمية">
            <input type="number" min="1" step="1" inputmode="numeric" name="item_quantity[]" class="sales-item-quantity" value="1" required>
        </td>
        <td data-label="سعر الوحدة">
            <span class="sales-price-pill sales-unit-price">0.00 ج.م</span>
        </td>
        <td data-label="الإجمالي">
            <span class="sales-price-pill sales-line-total">0.00 ج.م</span>
        </td>
        <td data-label="الإجراء">
            <button type="button" class="btn btn-danger sales-remove-row">حذف</button>
        </td>
    </tr>
</template>
<?php endif; ?>

<script src="assets/js/script.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const salesItemsBody = document.getElementById("salesItemsBody");
    const addRowButton = document.getElementById("addSalesItemRow");
    const rowTemplate = document.getElementById("salesItemRowTemplate");
    const paidAmountInput = document.getElementById("paidAmount");
    const invoiceTotalDisplay = document.getElementById("invoiceTotalDisplay");
    const invoiceRemainingDisplay = document.getElementById("invoiceRemainingDisplay");

    if (!salesItemsBody || !rowTemplate || !addRowButton || !paidAmountInput || !invoiceTotalDisplay || !invoiceRemainingDisplay) {
        return;
    }

    const formatCurrency = function (value) {
        const numericValue = Number.isFinite(value) ? value : 0;
        return numericValue.toLocaleString("en-US", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + " ج.م";
    };

    const getSelectedOption = function (selectElement) {
        return selectElement && selectElement.selectedIndex >= 0
            ? selectElement.options[selectElement.selectedIndex]
            : null;
    };

    const readSizes = function (selectedOption) {
        if (!selectedOption || !selectedOption.dataset || !selectedOption.dataset.sizes) {
            return [];
        }

        try {
            const parsedSizes = JSON.parse(selectedOption.dataset.sizes);
            return Array.isArray(parsedSizes) ? parsedSizes : [];
        } catch (error) {
            return [];
        }
    };

    const populateSizeSelect = function (rowElement) {
        const selectElement = rowElement.querySelector(".sales-item-select");
        const sizeSelectElement = rowElement.querySelector(".sales-item-size-select");
        const sizeValueInput = rowElement.querySelector(".sales-item-size-value");
        const selectedOption = getSelectedOption(selectElement);
        const selectedSizeName = sizeValueInput ? (sizeValueInput.value || "") : "";

        if (!sizeSelectElement) {
            return;
        }

        sizeSelectElement.innerHTML = "";

        if (!selectedOption || !selectedOption.value || selectedOption.dataset.hasSizes !== "1") {
            const emptyOption = document.createElement("option");
            emptyOption.value = "";
            emptyOption.textContent = "بدون مقاس";
            sizeSelectElement.appendChild(emptyOption);
            sizeSelectElement.required = false;
            sizeSelectElement.disabled = true;
            sizeSelectElement.value = "";
            if (sizeValueInput) {
                sizeValueInput.value = "";
            }
            return;
        }

        const defaultOption = document.createElement("option");
        defaultOption.value = "";
        defaultOption.textContent = "اختر المقاس";
        sizeSelectElement.appendChild(defaultOption);

        readSizes(selectedOption).forEach(function (sizeRow) {
            const sizeOption = document.createElement("option");
            sizeOption.value = sizeRow.size_name || "";
            const sizeQuantity = (sizeRow.quantity !== undefined && sizeRow.quantity !== null) ? sizeRow.quantity : 0;
            sizeOption.textContent = (sizeRow.size_name || "") + " — الرصيد: " + String(sizeQuantity);
            sizeOption.dataset.stock = String(sizeQuantity);
            sizeSelectElement.appendChild(sizeOption);
        });

        sizeSelectElement.required = true;
        sizeSelectElement.disabled = false;
        sizeSelectElement.value = selectedSizeName;
        if (sizeSelectElement.value !== selectedSizeName) {
            sizeSelectElement.value = "";
        }
        if (sizeValueInput) {
            sizeValueInput.value = sizeSelectElement.value || "";
        }
    };

    const updateRow = function (rowElement) {
        const selectElement = rowElement.querySelector(".sales-item-select");
        const sizeSelectElement = rowElement.querySelector(".sales-item-size-select");
        const quantityInput = rowElement.querySelector(".sales-item-quantity");
        const unitPriceElement = rowElement.querySelector(".sales-unit-price");
        const lineTotalElement = rowElement.querySelector(".sales-line-total");
        const stockElement = rowElement.querySelector(".sales-stock-pill");
        const selectedOption = getSelectedOption(selectElement);
        const unitPrice = selectedOption ? parseFloat(selectedOption.dataset.price || "0") : 0;
        const isStockManaged = selectedOption ? selectedOption.dataset.stockManaged === "1" : false;
        const hasSizes = selectedOption ? selectedOption.dataset.hasSizes === "1" : false;
        const stockValue = selectedOption ? selectedOption.dataset.stock || "" : "";
        const selectedSizeOption = getSelectedOption(sizeSelectElement);
        const sizeStockValue = selectedSizeOption ? selectedSizeOption.dataset.stock || "" : "";
        const quantity = parseInt(quantityInput.value || "0", 10) || 0;
        const lineTotal = unitPrice * quantity;

        unitPriceElement.textContent = formatCurrency(unitPrice);
        lineTotalElement.textContent = formatCurrency(lineTotal);

        if (!selectedOption || !selectedOption.value) {
            stockElement.textContent = "—";
            stockElement.classList.remove("is-stocked");
        } else if (hasSizes) {
            if (!selectedSizeOption || !selectedSizeOption.value) {
                stockElement.textContent = "اختر المقاس";
                stockElement.classList.remove("is-stocked");
            } else if (isStockManaged) {
                stockElement.textContent = "الرصيد: " + (sizeStockValue === "" ? "0" : sizeStockValue);
                stockElement.classList.add("is-stocked");
            } else {
                stockElement.textContent = "المقاس متاح";
                stockElement.classList.remove("is-stocked");
            }
        } else if (isStockManaged) {
            stockElement.textContent = "الرصيد: " + (stockValue === "" ? "0" : stockValue);
            stockElement.classList.add("is-stocked");
        } else {
            stockElement.textContent = "بدون عدد";
            stockElement.classList.remove("is-stocked");
        }
    };

    const updateRemoveButtonsState = function () {
        const rows = salesItemsBody.querySelectorAll(".sales-item-row");
        rows.forEach(function (rowElement) {
            const removeButton = rowElement.querySelector(".sales-remove-row");
            if (removeButton) {
                removeButton.disabled = rows.length === 1;
            }
        });
    };

    const updateInvoiceTotals = function () {
        let totalAmount = 0;
        salesItemsBody.querySelectorAll(".sales-item-row").forEach(function (rowElement) {
            const selectElement = rowElement.querySelector(".sales-item-select");
            const quantityInput = rowElement.querySelector(".sales-item-quantity");
            const selectedOption = getSelectedOption(selectElement);
            if (!selectedOption || !selectedOption.value) {
                return;
            }

            const unitPrice = parseFloat(selectedOption.dataset.price || "0") || 0;
            const quantity = parseInt(quantityInput.value || "0", 10) || 0;
            totalAmount += unitPrice * quantity;
        });

        const paidAmount = parseFloat(paidAmountInput.value || "0") || 0;
        const remainingAmount = totalAmount - paidAmount;

        invoiceTotalDisplay.textContent = formatCurrency(totalAmount);
        invoiceRemainingDisplay.textContent = formatCurrency(remainingAmount);
    };

    const bindRow = function (rowElement) {
        const selectElement = rowElement.querySelector(".sales-item-select");
        const sizeSelectElement = rowElement.querySelector(".sales-item-size-select");
        const quantityInput = rowElement.querySelector(".sales-item-quantity");
        const removeButton = rowElement.querySelector(".sales-remove-row");

        if (selectElement) {
            populateSizeSelect(rowElement);
            selectElement.addEventListener("change", function () {
                const sizeValueInput = rowElement.querySelector(".sales-item-size-value");
                if (sizeValueInput) {
                    sizeValueInput.value = "";
                }
                populateSizeSelect(rowElement);
                updateRow(rowElement);
                updateInvoiceTotals();
            });
        }

        if (sizeSelectElement) {
            sizeSelectElement.addEventListener("change", function () {
                const sizeValueInput = rowElement.querySelector(".sales-item-size-value");
                if (sizeValueInput) {
                    sizeValueInput.value = sizeSelectElement.value || "";
                }
                updateRow(rowElement);
                updateInvoiceTotals();
            });
        }

        if (quantityInput) {
            quantityInput.addEventListener("input", function () {
                updateRow(rowElement);
                updateInvoiceTotals();
            });
        }

        if (removeButton) {
            removeButton.addEventListener("click", function () {
                const rows = salesItemsBody.querySelectorAll(".sales-item-row");
                if (rows.length <= 1) {
                    return;
                }

                rowElement.remove();
                updateRemoveButtonsState();
                updateInvoiceTotals();
            });
        }

        populateSizeSelect(rowElement);
        updateRow(rowElement);
    };

    salesItemsBody.querySelectorAll(".sales-item-row").forEach(function (rowElement) {
        bindRow(rowElement);
    });

    addRowButton.addEventListener("click", function () {
        const rowFragment = rowTemplate.content.cloneNode(true);
        salesItemsBody.appendChild(rowFragment);
        const nextRow = salesItemsBody.lastElementChild;
        if (nextRow) {
            bindRow(nextRow);
        }
        updateRemoveButtonsState();
        updateInvoiceTotals();
    });

    paidAmountInput.addEventListener("input", updateInvoiceTotals);

    updateRemoveButtonsState();
    updateInvoiceTotals();
});
</script>
</body>
</html>
