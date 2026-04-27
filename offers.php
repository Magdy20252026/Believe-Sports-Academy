<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

const OFFERS_EMPTY_VALUE = "—";
const OFFERS_TIMEZONE = "Africa/Cairo";
const OFFERS_IMAGE_MAX_SIZE = 5242880;
const OFFERS_UPLOAD_DIRECTORY_MODE = 0755;
const OFFERS_UPLOAD_MAX_RETRIES = 5;
const ACTIVE_GAME_STATUS = 1;
const OFFERS_DEFAULT_ACADEMY_NAME = "أكاديمية رياضية";
const OFFERS_DEFAULT_LOGO_PATH = "assets/images/logo.png";

date_default_timezone_set(OFFERS_TIMEZONE);

requireAuthenticatedUser();
requireMenuAccess("offers");

function limitOfferText($value, $maxLength)
{
    $value = (string)$value;
    $maxLength = (int)$maxLength;
    if ($maxLength <= 0) {
        return "";
    }

    if (function_exists("mb_substr")) {
        return mb_substr($value, 0, $maxLength, "UTF-8");
    }

    return substr($value, 0, $maxLength);
}

function normalizeOfferTitle($title)
{
    $title = preg_replace('/\s+/u', ' ', trim((string)$title));
    return limitOfferText($title, 180);
}

function normalizeOfferDetails($details)
{
    $details = str_replace(["\r\n", "\r"], "\n", (string)$details);
    $details = preg_replace("/\n{3,}/", "\n\n", $details);
    $details = trim($details);
    return limitOfferText($details, 5000);
}

function formatOfferDateTimeValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return OFFERS_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone(OFFERS_TIMEZONE));
    } catch (Exception $exception) {
        return OFFERS_EMPTY_VALUE;
    }

    $hour = (int)$dateTime->format("G");
    $minute = $dateTime->format("i");
    $period = $hour >= 12 ? "م" : "ص";
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return $dateTime->format("Y/m/d") . " - " . str_pad((string)$displayHour, 2, "0", STR_PAD_LEFT) . ":" . $minute . " " . $period;
}

function getOfferEgyptDateValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return "";
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone(OFFERS_TIMEZONE));
    } catch (Exception $exception) {
        return "";
    }

    return $dateTime->format("Y-m-d");
}

function ensureOffersTable(PDO $pdo)
{
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "details" => "ALTER TABLE offers ADD COLUMN details TEXT NOT NULL AFTER title",
        "image_path" => "ALTER TABLE offers ADD COLUMN image_path VARCHAR(255) NOT NULL AFTER details",
        "created_by_user_id" => "ALTER TABLE offers ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER image_path",
        "updated_by_user_id" => "ALTER TABLE offers ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        "updated_at" => "ALTER TABLE offers ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
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
        $existingColumnsStmt->execute(["offers", $columnName]);
        if (!$existingColumnsStmt->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $throwable) {
                error_log("Failed to ensure offers.{$columnName}: " . $throwable->getMessage());
            }
        }
    }

    try {
        $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    } catch (Throwable $throwable) {
        error_log("Failed to determine current database for offers table: " . $throwable->getMessage());
        return;
    }

    if ($databaseName === "") {
        return;
    }

    $constraintsStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'offers'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintsStmt->execute([$databaseName]);
    $existingConstraints = array_column($constraintsStmt->fetchAll(), "CONSTRAINT_NAME");

    $foreignKeys = [
        "fk_offers_game" => "ALTER TABLE offers ADD CONSTRAINT fk_offers_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE",
        "fk_offers_created_user" => "ALTER TABLE offers ADD CONSTRAINT fk_offers_created_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
        "fk_offers_updated_user" => "ALTER TABLE offers ADD CONSTRAINT fk_offers_updated_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL",
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

function getOffersUploadDirectory()
{
    $directory = __DIR__ . "/uploads/offers";
    if (!is_dir($directory)) {
        mkdir($directory, OFFERS_UPLOAD_DIRECTORY_MODE, true);
    }

    return $directory;
}

function uploadOfferImage($uploadedFile)
{
    if (!isset($uploadedFile["error"]) || (int)$uploadedFile["error"] !== UPLOAD_ERR_OK) {
        return ["success" => false, "error" => "فشل رفع صورة العرض."];
    }

    if (!isset($uploadedFile["tmp_name"]) || !is_uploaded_file($uploadedFile["tmp_name"])) {
        return ["success" => false, "error" => "ملف الصورة غير صالح."];
    }

    if ((int)($uploadedFile["size"] ?? 0) <= 0 || (int)$uploadedFile["size"] > OFFERS_IMAGE_MAX_SIZE) {
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

    $directory = getOffersUploadDirectory();
    $extension = (string)$allowedTypes[$mimeType];
    $targetPath = "";

    for ($attempt = 0; $attempt < OFFERS_UPLOAD_MAX_RETRIES; $attempt++) {
        $fileName = "offer_" . bin2hex(random_bytes(16)) . "." . $extension;
        $candidatePath = $directory . "/" . $fileName;
        if (file_exists($candidatePath)) {
            continue;
        }

        if (!move_uploaded_file($uploadedFile["tmp_name"], $candidatePath)) {
            return ["success" => false, "error" => "تعذر حفظ الصورة."];
        }

        $targetPath = $candidatePath;
        break;
    }

    if ($targetPath === "") {
        return ["success" => false, "error" => "تعذر إنشاء اسم صالح للصورة."];
    }

    return [
        "success" => true,
        "path" => "uploads/offers/" . basename($targetPath),
    ];
}

function deleteOfferImage($relativePath)
{
    $relativePath = ltrim((string)$relativePath, "/");
    $decodedRelativePath = rawurldecode($relativePath);
    if ($decodedRelativePath === "" || strpos($decodedRelativePath, "uploads/offers/") !== 0 || strpos($decodedRelativePath, "..") !== false) {
        return;
    }

    $baseDirectory = realpath(__DIR__ . "/uploads/offers");
    if ($baseDirectory === false) {
        return;
    }

    $fullPath = __DIR__ . "/" . $decodedRelativePath;
    $realPath = realpath($fullPath);
    if ($realPath === false || strpos($realPath, $baseDirectory . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
        return;
    }

    @unlink($realPath);
}

function fetchOffer(PDO $pdo, $gameId, $offerId)
{
    $stmt = $pdo->prepare(
        "SELECT id, title, details, image_path, created_at, updated_at
         FROM offers
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$offerId, (int)$gameId]);
    return $stmt->fetch();
}

if (!isset($_SESSION["offers_csrf_token"])) {
    $_SESSION["offers_csrf_token"] = bin2hex(random_bytes(32));
}

ensureOffersTable($pdo);

$success = "";
$error = "";
$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");

$settingsStmt = $pdo->prepare("SELECT academy_name, academy_logo FROM settings ORDER BY id DESC LIMIT 1");
$settingsStmt->execute();
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? OFFERS_DEFAULT_ACADEMY_NAME);
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? OFFERS_DEFAULT_LOGO_PATH);
$activeMenu = "offers";

$gamesStmt = $pdo->prepare("SELECT id, name FROM games WHERE status = ? ORDER BY id ASC");
$gamesStmt->execute([ACTIVE_GAME_STATUS]);
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
    "title" => "",
    "details" => "",
    "image_path" => "",
];

$flashSuccess = $_SESSION["offers_success"] ?? "";
unset($_SESSION["offers_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["offers_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["offer_id"] ?? 0),
                "title" => normalizeOfferTitle($_POST["title"] ?? ""),
                "details" => normalizeOfferDetails($_POST["details"] ?? ""),
                "image_path" => "",
            ];

            $existingOffer = null;
            if ($formData["id"] > 0) {
                $existingOffer = fetchOffer($pdo, $currentGameId, $formData["id"]);
                if (!$existingOffer) {
                    $error = "العرض غير متاح.";
                }
            }

            if ($error === "" && $formData["title"] === "") {
                $error = "عنوان العرض مطلوب.";
            } elseif ($error === "" && $formData["details"] === "") {
                $error = "تفاصيل العرض مطلوبة.";
            }

            $uploadedImage = $_FILES["offer_image"] ?? null;
            $hasNewImage = is_array($uploadedImage)
                && isset($uploadedImage["error"])
                && (int)$uploadedImage["error"] !== UPLOAD_ERR_NO_FILE;

            if ($error === "" && $hasNewImage && (int)$uploadedImage["error"] !== UPLOAD_ERR_OK) {
                $error = "فشل رفع صورة العرض.";
            }

            if ($error === "" && !$hasNewImage && !$existingOffer) {
                $error = "صورة العرض مطلوبة.";
            }

            $newImagePath = "";
            if ($error === "" && $hasNewImage) {
                $uploadResult = uploadOfferImage($uploadedImage);
                if (!$uploadResult["success"]) {
                    $error = $uploadResult["error"];
                } else {
                    $newImagePath = (string)$uploadResult["path"];
                }
            }

            if ($error === "") {
                $currentImagePath = $existingOffer["image_path"] ?? "";
                $imagePathToSave = $newImagePath !== "" ? $newImagePath : (string)$currentImagePath;

                try {
                    $pdo->beginTransaction();

                    if ($existingOffer) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE offers
                             SET title = ?, details = ?, image_path = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["title"],
                            $formData["details"],
                            $imagePathToSave,
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "offers", $formData["id"], "العروض", "تعديل عرض: " . $formData["title"]);
                        $_SESSION["offers_success"] = "تم تحديث العرض.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO offers (game_id, title, details, image_path, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["title"],
                            $formData["details"],
                            $imagePathToSave,
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newOfferId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "offers", $newOfferId, "العروض", "إضافة عرض: " . $formData["title"]);
                        $_SESSION["offers_success"] = "تم حفظ العرض.";
                    }

                    $pdo->commit();

                    if ($newImagePath !== "" && $existingOffer && (string)$existingOffer["image_path"] !== $newImagePath) {
                        deleteOfferImage((string)$existingOffer["image_path"]);
                    }

                    $_SESSION["offers_csrf_token"] = bin2hex(random_bytes(32));
                    header("Location: offers.php");
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($newImagePath !== "") {
                        deleteOfferImage($newImagePath);
                    }
                    $error = "تعذر حفظ العرض.";
                    error_log("Offers save error: " . $throwable->getMessage());
                }
            }

            $formData["image_path"] = $existingOffer["image_path"] ?? "";
        }

        if ($action === "delete") {
            $offerId = (int)($_POST["offer_id"] ?? 0);
            if ($offerId <= 0) {
                $error = "العرض غير صالح.";
            } else {
                $offer = fetchOffer($pdo, $currentGameId, $offerId);
                if (!$offer) {
                    $error = "العرض غير متاح.";
                } else {
                    try {
                        $deleteStmt = $pdo->prepare("DELETE FROM offers WHERE id = ? AND game_id = ?");
                        $deleteStmt->execute([$offerId, $currentGameId]);
                        if ($deleteStmt->rowCount() === 0) {
                            throw new RuntimeException("العرض غير متاح.");
                        }

                        deleteOfferImage((string)$offer["image_path"]);
                        auditLogActivity($pdo, "delete", "offers", $offerId, "العروض", "حذف عرض: " . (string)($offer["title"] ?? ""));
                        $_SESSION["offers_success"] = "تم حذف العرض.";
                        $_SESSION["offers_csrf_token"] = bin2hex(random_bytes(32));
                        header("Location: offers.php");
                        exit;
                    } catch (Throwable $throwable) {
                        $error = $throwable instanceof RuntimeException ? $throwable->getMessage() : "تعذر حذف العرض.";
                        error_log("Offers delete error: " . $throwable->getMessage());
                    }
                }
            }
        }
    }
}

$editOfferId = (int)($_GET["edit"] ?? 0);
if ($editOfferId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editOffer = fetchOffer($pdo, $currentGameId, $editOfferId);
    if ($editOffer) {
        $formData = [
            "id" => (int)$editOffer["id"],
            "title" => (string)$editOffer["title"],
            "details" => (string)$editOffer["details"],
            "image_path" => (string)($editOffer["image_path"] ?? ""),
        ];
    }
}

$offersStmt = $pdo->prepare(
    "SELECT id, title, details, image_path, created_at, updated_at, created_by_user_id, updated_by_user_id
     FROM offers
     WHERE game_id = ?
     ORDER BY updated_at DESC, id DESC"
);
$offersStmt->execute([$currentGameId]);
$offers = $offersStmt->fetchAll();

$currentEgyptDate = (new DateTimeImmutable("now", new DateTimeZone(OFFERS_TIMEZONE)))->format("Y-m-d");
$offersCreatedToday = 0;
foreach ($offers as $offer) {
    if (getOfferEgyptDateValue((string)$offer["created_at"]) === $currentEgyptDate) {
        $offersCreatedToday++;
    }
}

$latestOfferUpdate = count($offers) > 0
    ? formatOfferDateTimeValue((string)$offers[0]["updated_at"])
    : OFFERS_EMPTY_VALUE;

$submitButtonLabel = $formData["id"] > 0 ? "حفظ التعديل" : "حفظ العرض";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>العروض</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content offers-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button" aria-label="فتح القائمة الجانبية">📋</button>
                <div>
                    <h1>العروض</h1>
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

        <section class="offers-stat-grid">
            <div class="offers-stat-card">
                <span>إجمالي العروض</span>
                <strong><?php echo count($offers); ?></strong>
            </div>
            <div class="offers-stat-card">
                <span>عروض أضيفت اليوم</span>
                <strong><?php echo $offersCreatedToday; ?></strong>
            </div>
            <div class="offers-stat-card">
                <span>آخر تحديث</span>
                <strong><?php echo htmlspecialchars($latestOfferUpdate, ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
        </section>

        <section class="offers-grid">
            <div class="card offers-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل العرض" : "إضافة عرض"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="offers.php" class="btn btn-soft" aria-label="إلغاء التعديل">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["offers_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="offer_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="offers-form-grid">
                        <div class="form-group form-group-full">
                            <label for="title">عنوان العرض</label>
                            <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($formData["title"], ENT_QUOTES, "UTF-8"); ?>" required>
                        </div>

                        <div class="form-group form-group-full">
                            <label for="details">تفاصيل العرض</label>
                            <textarea name="details" id="details" rows="7" required><?php echo htmlspecialchars($formData["details"], ENT_QUOTES, "UTF-8"); ?></textarea>
                        </div>

                        <div class="form-group form-group-full">
                            <label for="offer_image">صورة العرض</label>
                            <input type="file" name="offer_image" id="offer_image" accept="image/jpeg,image/png,image/gif,image/webp" <?php echo $formData["id"] === 0 ? "required" : ""; ?>>
                        </div>
                    </div>

                    <div class="offer-image-preview">
                        <?php if ($formData["image_path"] !== ""): ?>
                            <img src="<?php echo htmlspecialchars($formData["image_path"], ENT_QUOTES, "UTF-8"); ?>" alt="صورة العرض" class="offer-image-preview-image">
                        <?php else: ?>
                            <div class="offer-image-placeholder">🎁</div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, "UTF-8"); ?></button>
                </form>
            </div>

            <div class="card offers-list-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>العروض المسجلة</h3>
                    </div>
                    <span class="table-counter">الإجمالي: <?php echo count($offers); ?></span>
                </div>

                <?php if (count($offers) === 0): ?>
                    <div class="empty-state">لا توجد عروض مسجلة.</div>
                <?php else: ?>
                    <div class="offers-list-grid">
                        <?php foreach ($offers as $offer): ?>
                            <article class="card offer-card">
                                <div class="offer-card-media">
                                    <img src="<?php echo htmlspecialchars((string)$offer["image_path"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars((string)$offer["title"], ENT_QUOTES, "UTF-8"); ?>" class="offer-card-image">
                                </div>
                                <div class="offer-card-body">
                                    <div class="offer-meta">
                                        <span class="offer-meta-item">تم الإنشاء: <?php echo htmlspecialchars(formatOfferDateTimeValue((string)$offer["created_at"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        <span class="offer-meta-item">آخر تحديث: <?php echo htmlspecialchars(formatOfferDateTimeValue((string)$offer["updated_at"]), ENT_QUOTES, "UTF-8"); ?></span>
                                        <span class="offer-meta-item">أضيف بواسطة: <?php echo htmlspecialchars(auditDisplayUserName($pdo, $offer["created_by_user_id"] ?? null), ENT_QUOTES, "UTF-8"); ?></span>
                                        <span class="offer-meta-item">عدّل بواسطة: <?php echo htmlspecialchars(auditDisplayUserName($pdo, $offer["updated_by_user_id"] ?? null), ENT_QUOTES, "UTF-8"); ?></span>
                                    </div>
                                    <h3 class="offer-card-title"><?php echo htmlspecialchars((string)$offer["title"], ENT_QUOTES, "UTF-8"); ?></h3>
                                    <p class="offer-card-details"><?php echo htmlspecialchars((string)$offer["details"], ENT_QUOTES, "UTF-8"); ?></p>
                                    <div class="offer-actions">
                                        <a href="offers.php?edit=<?php echo (int)$offer["id"]; ?>" class="btn btn-warning" aria-label="تعديل العرض">تعديل</a>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["offers_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="offer_id" value="<?php echo (int)$offer["id"]; ?>">
                                            <button type="submit" class="btn btn-danger" aria-label="حذف العرض" onclick="return confirm('هل أنت متأكد من حذف العرض؟')">حذف</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/script.js"></script>
</body>
</html>
