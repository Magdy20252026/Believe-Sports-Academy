<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

requireAuthenticatedUser();
requireMenuAccess("store");

function ensureStoreProductsTable(PDO $pdo)
{
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "price" => "ALTER TABLE store_products ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER product_name",
        "image_path" => "ALTER TABLE store_products ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL AFTER price",
        "is_available" => "ALTER TABLE store_products ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1 AFTER image_path",
        "created_by_user_id" => "ALTER TABLE store_products ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER is_available",
        "updated_by_user_id" => "ALTER TABLE store_products ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        "updated_at" => "ALTER TABLE store_products ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
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
        $existingColumnsStmt->execute(["store_products", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log("Failed to ensure store_products.{$columnName}: " . $throwable->getMessage());
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
           AND TABLE_NAME = 'store_products'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    $foreignKeys = [
        "fk_store_products_game" => "ALTER TABLE store_products ADD CONSTRAINT fk_store_products_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE",
        "fk_store_products_created_user" => "ALTER TABLE store_products ADD CONSTRAINT fk_store_products_created_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
        "fk_store_products_updated_user" => "ALTER TABLE store_products ADD CONSTRAINT fk_store_products_updated_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
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

function normalizeStoreNumericInput($value)
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

function normalizeStorePriceValue($value)
{
    $value = normalizeStoreNumericInput($value);
    if ($value === "" || !is_numeric($value)) {
        return "";
    }

    $floatValue = round((float)$value, 2);
    if ($floatValue < 0) {
        return "";
    }

    return number_format($floatValue, 2, ".", "");
}

function formatStoreCurrency($value)
{
    return number_format((float)$value, 2) . " ج.م";
}

function getStoreProductsUploadDirectory()
{
    $directory = __DIR__ . "/uploads/store_products";
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    return $directory;
}

function storeProductImageUploadResult($uploadedFile)
{
    if (!isset($uploadedFile["error"]) || (int)$uploadedFile["error"] !== UPLOAD_ERR_OK) {
        return ["success" => false, "error" => "فشل رفع الصورة."];
    }

    if (!isset($uploadedFile["tmp_name"]) || !is_uploaded_file($uploadedFile["tmp_name"])) {
        return ["success" => false, "error" => "ملف الصورة غير صالح."];
    }

    $maxSize = 5 * 1024 * 1024;
    if ((int)($uploadedFile["size"] ?? 0) <= 0 || (int)$uploadedFile["size"] > $maxSize) {
        return ["success" => false, "error" => "حجم الصورة يجب ألا يتجاوز 5 ميجابايت."];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($uploadedFile["tmp_name"]);
    $allowedTypes = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/gif" => "gif",
        "image/webp" => "webp",
    ];

    if (!isset($allowedTypes[$mimeType])) {
        return ["success" => false, "error" => "نوع الصورة غير مدعوم."];
    }

    $directory = getStoreProductsUploadDirectory();
    $fileName = "product_" . bin2hex(random_bytes(16)) . "." . $allowedTypes[$mimeType];
    $targetPath = $directory . "/" . $fileName;

    if (!move_uploaded_file($uploadedFile["tmp_name"], $targetPath)) {
        return ["success" => false, "error" => "تعذر حفظ الصورة."];
    }

    return [
        "success" => true,
        "path" => "uploads/store_products/" . $fileName,
    ];
}

function deleteStoreProductImage($relativePath)
{
    $relativePath = ltrim((string)$relativePath, "/");
    if ($relativePath === "" || strpos($relativePath, "uploads/store_products/") !== 0 || strpos($relativePath, "..") !== false) {
        return;
    }

    $baseDirectory = realpath(__DIR__ . "/uploads/store_products");
    if ($baseDirectory === false) {
        return;
    }

    $fullPath = __DIR__ . "/" . $relativePath;
    $realPath = realpath($fullPath);
    if ($realPath === false || strpos($realPath, $baseDirectory . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
        return;
    }

    @unlink($realPath);
}

function fetchStoreProduct(PDO $pdo, $gameId, $productId)
{
    $stmt = $pdo->prepare(
        "SELECT id, product_name, price, image_path, is_available, created_at, updated_at
         FROM store_products
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$productId, (int)$gameId]);

    return $stmt->fetch();
}

function storeProductDuplicateExists(PDO $pdo, $gameId, $productName, $productId = 0)
{
    $sql = (int)$productId > 0
        ? "SELECT id FROM store_products WHERE game_id = ? AND product_name = ? AND id <> ? LIMIT 1"
        : "SELECT id FROM store_products WHERE game_id = ? AND product_name = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = (int)$productId > 0
        ? [(int)$gameId, (string)$productName, (int)$productId]
        : [(int)$gameId, (string)$productName];
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

if (!isset($_SESSION["store_csrf_token"])) {
    $_SESSION["store_csrf_token"] = bin2hex(random_bytes(32));
}

ensureStoreProductsTable($pdo);

$success = "";
$error = "";
$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "store";

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
    "product_name" => "",
    "price" => "",
    "image_path" => "",
    "is_available" => 1,
];

$flashSuccess = $_SESSION["store_success"] ?? "";
unset($_SESSION["store_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["store_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["product_id"] ?? 0),
                "product_name" => trim((string)($_POST["product_name"] ?? "")),
                "price" => normalizeStorePriceValue($_POST["price"] ?? ""),
                "image_path" => "",
                "is_available" => isset($_POST["is_available"]) ? 1 : 0,
            ];

            $existingProduct = null;
            if ($formData["id"] > 0) {
                $existingProduct = fetchStoreProduct($pdo, $currentGameId, $formData["id"]);
                if (!$existingProduct) {
                    $error = "الصنف غير متاح.";
                }
            }

            if ($error === "" && $formData["product_name"] === "") {
                $error = "اسم الصنف مطلوب.";
            } elseif ($error === "" && $formData["price"] === "") {
                $error = "السعر غير صحيح.";
            } elseif ($error === "" && storeProductDuplicateExists($pdo, $currentGameId, $formData["product_name"], $formData["id"])) {
                $error = "اسم الصنف مستخدم بالفعل.";
            }

            $uploadedImage = $_FILES["product_image"] ?? null;
            $hasNewImage = is_array($uploadedImage)
                && isset($uploadedImage["error"])
                && (int)$uploadedImage["error"] !== UPLOAD_ERR_NO_FILE;

            if ($error === "" && $hasNewImage && (int)$uploadedImage["error"] !== UPLOAD_ERR_OK) {
                $error = "فشل رفع الصورة.";
            }

            if ($error === "" && !$hasNewImage && !$existingProduct) {
                $error = "صورة الصنف مطلوبة.";
            }

            $newImagePath = "";
            if ($error === "" && $hasNewImage) {
                $uploadResult = storeProductImageUploadResult($uploadedImage);
                if (!$uploadResult["success"]) {
                    $error = $uploadResult["error"];
                } else {
                    $newImagePath = (string)$uploadResult["path"];
                }
            }

            if ($error === "") {
                $currentImagePath = $existingProduct["image_path"] ?? "";
                $imagePathToSave = $newImagePath !== "" ? $newImagePath : (string)$currentImagePath;

                try {
                    $pdo->beginTransaction();

                    if ($existingProduct) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE store_products
                             SET product_name = ?, price = ?, image_path = ?, is_available = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["product_name"],
                            $formData["price"],
                            $imagePathToSave,
                            $formData["is_available"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "store_products", $formData["id"], "المتجر", "تعديل صنف: " . $formData["product_name"]);
                        $_SESSION["store_success"] = "تم تحديث الصنف.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO store_products (game_id, product_name, price, image_path, is_available, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["product_name"],
                            $formData["price"],
                            $imagePathToSave,
                            $formData["is_available"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newProductId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "store_products", $newProductId, "المتجر", "إضافة صنف: " . $formData["product_name"]);
                        $_SESSION["store_success"] = "تم حفظ الصنف.";
                    }

                    $pdo->commit();

                    if ($newImagePath !== "" && $existingProduct && (string)$existingProduct["image_path"] !== $newImagePath) {
                        deleteStoreProductImage((string)$existingProduct["image_path"]);
                    }

                    $_SESSION["store_csrf_token"] = bin2hex(random_bytes(32));
                    header("Location: store.php");
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($newImagePath !== "") {
                        deleteStoreProductImage($newImagePath);
                    }
                    $error = "تعذر حفظ الصنف.";
                    error_log("Store save error: " . $throwable->getMessage());
                }
            }

            $formData["image_path"] = $existingProduct["image_path"] ?? "";
        }

        if ($action === "delete") {
            $productId = (int)($_POST["product_id"] ?? 0);
            if ($productId <= 0) {
                $error = "الصنف غير صالح.";
            } else {
                $product = fetchStoreProduct($pdo, $currentGameId, $productId);
                if (!$product) {
                    $error = "الصنف غير متاح.";
                } else {
                    try {
                        $deleteStmt = $pdo->prepare("DELETE FROM store_products WHERE id = ? AND game_id = ?");
                        $deleteStmt->execute([$productId, $currentGameId]);
                        if ($deleteStmt->rowCount() === 0) {
                            throw new RuntimeException("الصنف غير متاح.");
                        }

                        deleteStoreProductImage((string)$product["image_path"]);
                        auditLogActivity($pdo, "delete", "store_products", $productId, "المتجر", "حذف صنف: " . (string)($product["product_name"] ?? ""));
                        $_SESSION["store_success"] = "تم حذف الصنف.";
                        $_SESSION["store_csrf_token"] = bin2hex(random_bytes(32));
                        header("Location: store.php");
                        exit;
                    } catch (Throwable $throwable) {
                        $error = $throwable instanceof RuntimeException ? $throwable->getMessage() : "تعذر حذف الصنف.";
                        error_log("Store delete error: " . $throwable->getMessage());
                    }
                }
            }
        }

        if ($action === "toggle_availability") {
            $productId = (int)($_POST["product_id"] ?? 0);
            if ($productId <= 0) {
                $error = "الصنف غير صالح.";
            } else {
                $product = fetchStoreProduct($pdo, $currentGameId, $productId);
                if (!$product) {
                    $error = "الصنف غير متاح.";
                } else {
                    try {
                        $updateStmt = $pdo->prepare(
                            "UPDATE store_products
                             SET is_available = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            (int)$product["is_available"] === 1 ? 0 : 1,
                            $currentUserId > 0 ? $currentUserId : null,
                            $productId,
                            $currentGameId,
                        ]);

                        $_SESSION["store_success"] = (int)$product["is_available"] === 1
                            ? "تم جعل الصنف غير متاح."
                            : "تم إتاحة الصنف.";
                        $_SESSION["store_csrf_token"] = bin2hex(random_bytes(32));
                        header("Location: store.php");
                        exit;
                    } catch (Throwable $throwable) {
                        $error = "تعذر تحديث حالة الصنف.";
                        error_log("Store toggle error: " . $throwable->getMessage());
                    }
                }
            }
        }
    }
}

$editProductId = (int)($_GET["edit"] ?? 0);
if ($editProductId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editProduct = fetchStoreProduct($pdo, $currentGameId, $editProductId);
    if ($editProduct) {
        $formData = [
            "id" => (int)$editProduct["id"],
            "product_name" => (string)$editProduct["product_name"],
            "price" => number_format((float)$editProduct["price"], 2, ".", ""),
            "image_path" => (string)($editProduct["image_path"] ?? ""),
            "is_available" => (int)$editProduct["is_available"],
        ];
    }
}

$productsStmt = $pdo->prepare(
    "SELECT id, product_name, price, image_path, is_available, created_at, updated_at, created_by_user_id, updated_by_user_id
     FROM store_products
     WHERE game_id = ?
     ORDER BY is_available DESC, updated_at DESC, product_name ASC"
);
$productsStmt->execute([$currentGameId]);
$products = $productsStmt->fetchAll();

$availableProductsCount = 0;
$unavailableProductsCount = 0;
foreach ($products as $product) {
    if ((int)$product["is_available"] === 1) {
        $availableProductsCount++;
    } else {
        $unavailableProductsCount++;
    }
}

$submitButtonLabel = $formData["id"] > 0 ? "حفظ التعديل" : "حفظ الصنف";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المتجر</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content store-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>المتجر</h1>
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

        <section class="store-stat-grid">
            <div class="store-stat-card">
                <span>إجمالي الأصناف</span>
                <strong><?php echo count($products); ?></strong>
            </div>
            <div class="store-stat-card">
                <span>الأصناف المتاحة</span>
                <strong><?php echo $availableProductsCount; ?></strong>
            </div>
            <div class="store-stat-card">
                <span>الأصناف غير المتاحة</span>
                <strong><?php echo $unavailableProductsCount; ?></strong>
            </div>
        </section>

        <section class="store-grid">
            <div class="card store-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل الصنف" : "إضافة صنف"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="store.php" class="btn btn-soft" aria-label="إلغاء التعديل">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["store_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="product_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="store-form-grid">
                        <div class="form-group form-group-full">
                            <label for="product_name">اسم الصنف</label>
                            <input type="text" name="product_name" id="product_name" value="<?php echo htmlspecialchars($formData["product_name"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="price">السعر بالجنيه المصري</label>
                            <input type="text" inputmode="decimal" name="price" id="price" value="<?php echo htmlspecialchars($formData["price"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="product_image">صورة الصنف</label>
                            <input type="file" name="product_image" id="product_image" accept="image/jpeg,image/png,image/gif,image/webp" <?php echo $formData["id"] === 0 ? "required" : ""; ?>>
                        </div>

                        <div class="form-group form-group-full">
                            <label class="store-availability-toggle">
                                <input type="checkbox" name="is_available" value="1" <?php echo (int)$formData["is_available"] === 1 ? "checked" : ""; ?>>
                                <span>الصنف متاح للبيع</span>
                            </label>
                        </div>
                    </div>

                    <div class="store-product-preview">
                        <?php if ($formData["image_path"] !== ""): ?>
                            <img src="<?php echo htmlspecialchars($formData["image_path"], ENT_QUOTES, "UTF-8"); ?>" alt="صورة الصنف" class="store-product-preview-image">
                        <?php else: ?>
                            <div class="store-image-placeholder">🛍️</div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, "UTF-8"); ?></button>
                </form>
            </div>

            <div class="card store-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>الأصناف المسجلة</h3>
                    </div>
                    <span class="table-counter">الإجمالي: <?php echo count($products); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الصورة</th>
                                <th>الصنف</th>
                                <th>السعر</th>
                                <th>الحالة</th>
                                <th>آخر تحديث</th>
                                <th>أضيف بواسطة</th>
                                <th>عدّل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($products) === 0): ?>
                                <tr>
                                    <td colspan="8" class="empty-cell">لا توجد أصناف مسجلة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td data-label="الصورة">
                                            <?php if ((string)$product["image_path"] !== ""): ?>
                                                <img src="<?php echo htmlspecialchars((string)$product["image_path"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars((string)$product["product_name"], ENT_QUOTES, "UTF-8"); ?>" class="store-table-thumb">
                                            <?php else: ?>
                                                <div class="store-table-thumb store-table-thumb-placeholder">🛍️</div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="الصنف">
                                            <div class="product-name-stack">
                                                <strong><?php echo htmlspecialchars((string)$product["product_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="السعر">
                                            <span class="price-pill"><?php echo htmlspecialchars(formatStoreCurrency($product["price"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="الحالة">
                                            <span class="availability-pill<?php echo (int)$product["is_available"] === 1 ? "" : " is-unavailable"; ?>">
                                                <?php echo (int)$product["is_available"] === 1 ? "متاح" : "غير متاح"; ?>
                                            </span>
                                        </td>
                                        <td data-label="آخر تحديث"><?php echo htmlspecialchars((string)$product["updated_at"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $product["created_by_user_id"] ?? null), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="عدّل بواسطة"><?php echo htmlspecialchars(auditDisplayUserName($pdo, $product["updated_by_user_id"] ?? null), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions store-actions-wrap">
                                                <a href="store.php?edit=<?php echo (int)$product["id"]; ?>" class="btn btn-warning" aria-label="تعديل الصنف">✏️</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["store_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="toggle_availability">
                                                    <input type="hidden" name="product_id" value="<?php echo (int)$product["id"]; ?>">
                                                    <button type="submit" class="btn btn-soft" aria-label="تغيير حالة الصنف">
                                                        <?php echo (int)$product["is_available"] === 1 ? "🚫" : "✅"; ?>
                                                    </button>
                                                </form>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["store_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="product_id" value="<?php echo (int)$product["id"]; ?>">
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
</body>
</html>
