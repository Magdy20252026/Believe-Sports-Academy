<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

date_default_timezone_set("Africa/Cairo");

const ADMIN_LOAN_EMPTY_VALUE = "—";

requireAuthenticatedUser();
requireMenuAccess("admins-loans");

function isValidAdminLoanDate($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("Y-m-d", $date, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $date;
}

function formatAdminLoanEgyptDateTimeLabel(DateTimeInterface $dateTime)
{
    $hour = (int)$dateTime->format("G");
    $minute = $dateTime->format("i");
    $period = $hour >= 12 ? "م" : "ص";
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return $dateTime->format("Y/m/d") . " - " . str_pad((string)$displayHour, 2, "0", STR_PAD_LEFT) . ":" . $minute . " " . $period;
}

function formatAdminLoanDateTimeValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return ADMIN_LOAN_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return ADMIN_LOAN_EMPTY_VALUE;
    }

    return formatAdminLoanEgyptDateTimeLabel($dateTime);
}

function formatAdminLoanAmountWithCurrency($amount)
{
    return number_format((float)$amount, 2) . " ج.م";
}

function normalizeAdminLoanAmount($amount)
{
    return number_format((float)$amount, 2, ".", "");
}

function ensureAdminLoansTable(PDO $pdo)
{
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "admin_name" => "ALTER TABLE admin_loans ADD COLUMN admin_name VARCHAR(150) NOT NULL DEFAULT '' AFTER admin_id",
        "updated_by_user_id" => "ALTER TABLE admin_loans ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        "created_by_user_id" => "ALTER TABLE admin_loans ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER loan_date",
        "loan_date" => "ALTER TABLE admin_loans ADD COLUMN loan_date DATE NOT NULL AFTER amount",
    ];

    foreach ($requiredColumns as $columnName => $sql) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM admin_loans LIKE " . $pdo->quote($columnName));
        if (!$columnStmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

function fetchAdminLoanAdmins(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, name
         FROM admins
         WHERE game_id = ?
         ORDER BY name ASC, id DESC"
    );
    $stmt->execute([(int)$gameId]);
    return $stmt->fetchAll();
}

function fetchAdminLoanRecord(PDO $pdo, $loanId, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, admin_id, admin_name, amount, loan_date
         FROM admin_loans
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$loanId, (int)$gameId]);
    return $stmt->fetch();
}

function fetchAdminLoanRows(PDO $pdo, $gameId, $selectedDate, $adminId = 0)
{
    $sql = "SELECT
            l.id,
            l.admin_id,
            l.admin_name,
            l.amount,
            l.loan_date,
            l.created_at,
            l.updated_at,
            COALESCE(a.name, l.admin_name) AS display_admin_name,
            COALESCE(created_user.username, " . $pdo->quote(ADMIN_LOAN_EMPTY_VALUE) . ") AS created_by_name,
            COALESCE(updated_user.username, " . $pdo->quote(ADMIN_LOAN_EMPTY_VALUE) . ") AS updated_by_name
        FROM admin_loans l
        LEFT JOIN admins a
            ON a.id = l.admin_id
           AND a.game_id = l.game_id
        LEFT JOIN users created_user
            ON created_user.id = l.created_by_user_id
        LEFT JOIN users updated_user
            ON updated_user.id = l.updated_by_user_id
        WHERE l.game_id = ?
          AND l.loan_date = ?";

    $params = [(int)$gameId, (string)$selectedDate];
    if ((int)$adminId > 0) {
        $sql .= " AND l.admin_id = ?";
        $params[] = (int)$adminId;
    }

    $sql .= " ORDER BY l.created_at DESC, l.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function summarizeAdminLoanRows(array $rows)
{
    $summary = [
        "count" => count($rows),
        "total_amount" => 0.0,
    ];

    foreach ($rows as $row) {
        $summary["total_amount"] += (float)($row["amount"] ?? 0);
    }

    return $summary;
}

function escapeAdminLoanXml($value)
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, "UTF-8");
}

function buildAdminLoanWorksheetCell($cellReference, $value, $styleId)
{
    return '<c r="' . $cellReference . '" s="' . $styleId . '" t="inlineStr"><is><t xml:space="preserve">' . escapeAdminLoanXml($value) . '</t></is></c>';
}

function getAdminLoanExcelColumnName($columnNumber)
{
    $columnNumber = (int)$columnNumber;
    if ($columnNumber <= 0) {
        return "A";
    }

    $columnName = "";
    while ($columnNumber > 0) {
        $columnNumber--;
        $columnName = chr(65 + ($columnNumber % 26)) . $columnName;
        $columnNumber = (int)floor($columnNumber / 26);
    }

    return $columnName;
}

function buildAdminLoanWorksheetXml(array $headers, array $rows)
{
    $columnWidths = [10, 20, 24, 18, 24, 20, 20];
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>';
    $xml .= '<cols>';
    foreach ($columnWidths as $index => $width) {
        $columnIndex = $index + 1;
        $xml .= '<col min="' . $columnIndex . '" max="' . $columnIndex . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols>';
    $xml .= '<sheetData>';
    $xml .= '<row r="1" ht="26" customHeight="1">';
    foreach ($headers as $index => $header) {
        $xml .= buildAdminLoanWorksheetCell(getAdminLoanExcelColumnName($index + 1) . "1", $header, 1);
    }
    $xml .= '</row>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $cellIndex => $cellValue) {
            $xml .= buildAdminLoanWorksheetCell(getAdminLoanExcelColumnName($cellIndex + 1) . $excelRow, $cellValue, 2);
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    if (count($rows) > 0) {
        $xml .= '<autoFilter ref="A1:' . getAdminLoanExcelColumnName(count($headers)) . (count($rows) + 1) . '"/>';
    }
    $xml .= '</worksheet>';
    return $xml;
}

function buildAdminLoanStylesXml()
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2">
        <font><sz val="11"/><name val="Arial"/></font>
        <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    </fonts>
    <fills count="3">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF7C3AED"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFD1D5DB"/></left>
            <right style="thin"><color rgb="FFD1D5DB"/></right>
            <top style="thin"><color rgb="FFD1D5DB"/></top>
            <bottom style="thin"><color rgb="FFD1D5DB"/></bottom>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellStyleXfs>
    <cellXfs count="3">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    </cellXfs>
    <cellStyles count="1">
        <cellStyle name="Normal" xfId="0" builtinId="0"/>
    </cellStyles>
</styleSheet>';
}

function exportAdminLoansXlsx(array $records, $selectedDate, $currentGameName)
{
    if (!class_exists("ZipArchive")) {
        error_log("ZipArchive extension not available for admin loans export.");
        throw new RuntimeException("امتداد ZipArchive غير متاح.");
    }

    $headers = [
        "م",
        "التاريخ",
        "اسم الإداري",
        "المبلغ بالجنيه المصري",
        "وقت التسجيل",
        "أضيف بواسطة",
        "آخر تعديل بواسطة",
    ];

    $rows = [];
    foreach ($records as $index => $record) {
        $rows[] = [
            (string)($index + 1),
            (string)$selectedDate,
            (string)($record["display_admin_name"] ?? $record["admin_name"] ?? ""),
            normalizeAdminLoanAmount($record["amount"] ?? 0),
            formatAdminLoanDateTimeValue($record["created_at"] ?? ""),
            (string)($record["created_by_name"] ?? ADMIN_LOAN_EMPTY_VALUE),
            (string)($record["updated_by_name"] ?? ADMIN_LOAN_EMPTY_VALUE),
        ];
    }

    $sheetXml = buildAdminLoanWorksheetXml($headers, $rows);
    $stylesXml = buildAdminLoanStylesXml();
    $timestamp = (new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo")))->format("Y-m-d\\TH:i:sP");
    $tempFile = sys_get_temp_dir() . "/admin-loans-" . uniqid("", true) . ".xlsx";
    $tempFileDeleted = false;
    $cleanupTempFile = static function () use ($tempFile, &$tempFileDeleted) {
        if ($tempFileDeleted) {
            return;
        }

        $tempFileDeleted = true;
        if (file_exists($tempFile) && !unlink($tempFile)) {
            error_log("Failed to delete temporary admin loans XLSX file: " . $tempFile);
        }
    };
    register_shutdown_function($cleanupTempFile);

    $zip = new ZipArchive();
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("تعذر إنشاء ملف Excel.");
    }

    $zip->addFromString("[Content_Types].xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>');
    $zip->addFromString("_rels/.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>');
    $zip->addFromString("docProps/app.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>Believe Sports Academy</Application>
</Properties>');
    $zip->addFromString("docProps/core.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>سلف الإداريين - ' . escapeAdminLoanXml($currentGameName) . '</dc:title>
    <dc:creator>Believe Sports Academy</dc:creator>
    <cp:lastModifiedBy>Believe Sports Academy</cp:lastModifiedBy>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>
</cp:coreProperties>');
    $zip->addFromString("xl/workbook.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="سلف الإداريين" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');
    $zip->addFromString("xl/_rels/workbook.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString("xl/styles.xml", $stylesXml);
    $zip->addFromString("xl/worksheets/sheet1.xml", $sheetXml);
    $zip->close();

    $fileName = "admin-loans-" . $selectedDate . ".xlsx";

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header("Content-Length: " . filesize($tempFile));
    header("Cache-Control: max-age=0");
    readfile($tempFile);
    $cleanupTempFile();
    exit;
}

function logAdminLoanException(Throwable $throwable)
{
    error_log("Admin loans page error: " . $throwable->getMessage());
}

if (!isset($_SESSION["admin_loans_csrf_token"])) {
    $_SESSION["admin_loans_csrf_token"] = bin2hex(random_bytes(32));
}

ensureAdminLoansTable($pdo);

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
$activeMenu = "admins-loans";

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
$egyptDateTimeLabel = formatAdminLoanEgyptDateTimeLabel($egyptNow);

$selectedDate = trim((string)($_GET["date"] ?? $todayDate));
if (!isValidAdminLoanDate($selectedDate)) {
    $selectedDate = $todayDate;
}

$admins = fetchAdminLoanAdmins($pdo, $currentGameId);
$adminMap = [];
foreach ($admins as $admin) {
    $adminMap[(int)$admin["id"]] = $admin;
}

$selectedAdminId = (int)($_GET["admin_id"] ?? 0);
if ($selectedAdminId > 0 && !isset($adminMap[$selectedAdminId])) {
    $selectedAdminId = 0;
}

$formData = [
    "id" => 0,
    "admin_id" => $selectedAdminId > 0 ? $selectedAdminId : 0,
    "amount" => "",
    "loan_date" => $selectedDate,
];

$flashSuccess = $_SESSION["admin_loans_success"] ?? "";
unset($_SESSION["admin_loans_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["admin_loans_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";
        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["loan_id"] ?? 0),
                "admin_id" => (int)($_POST["admin_id"] ?? 0),
                "amount" => trim((string)($_POST["amount"] ?? "")),
                "loan_date" => trim((string)($_POST["loan_date"] ?? $todayDate)),
            ];

            if ($formData["id"] > 0 && !$isManager) {
                $error = "تعديل السلف متاح للمدير فقط.";
            } elseif ($formData["admin_id"] <= 0 || !isset($adminMap[$formData["admin_id"]])) {
                $error = "اختر الإداري.";
            } elseif ($formData["amount"] === "") {
                $error = "المبلغ مطلوب.";
            } elseif (!is_numeric($formData["amount"]) || (float)$formData["amount"] <= 0) {
                $error = "المبلغ غير صحيح.";
            } elseif (!isValidAdminLoanDate($formData["loan_date"])) {
                $error = "تاريخ السلفة غير صحيح.";
            }

            if ($error === "") {
                $existingLoan = $formData["id"] > 0 ? fetchAdminLoanRecord($pdo, $formData["id"], $currentGameId) : null;
                if ($formData["id"] > 0 && !$existingLoan) {
                    $error = "السلفة غير متاحة.";
                }
            }

            if ($error === "") {
                $adminName = (string)$adminMap[$formData["admin_id"]]["name"];
                $amountValue = normalizeAdminLoanAmount($formData["amount"]);

                try {
                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE admin_loans
                             SET admin_id = ?, admin_name = ?, amount = ?, loan_date = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["admin_id"],
                            $adminName,
                            $amountValue,
                            $formData["loan_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "admin_loans", $formData["id"], "سلف الإداريين", "تعديل سلفة لـ: " . $adminName . " بمبلغ " . $amountValue);
                        $_SESSION["admin_loans_success"] = "تم تحديث السلفة.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO admin_loans (game_id, admin_id, admin_name, amount, loan_date, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["admin_id"],
                            $adminName,
                            $amountValue,
                            $formData["loan_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newLoanId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "admin_loans", $newLoanId, "سلف الإداريين", "إضافة سلفة لـ: " . $adminName . " بمبلغ " . $amountValue);
                        $_SESSION["admin_loans_success"] = "تم تسجيل السلفة.";
                    }

                    header("Location: admin_loans.php?date=" . urlencode($formData["loan_date"]));
                    exit;
                } catch (Throwable $throwable) {
                    logAdminLoanException($throwable);
                    $error = "تعذر حفظ السلفة.";
                }
            }
        }

        if ($action === "delete") {
            $loanId = (int)($_POST["loan_id"] ?? 0);
            $redirectDate = trim((string)($_POST["redirect_date"] ?? $selectedDate));
            if (!isValidAdminLoanDate($redirectDate)) {
                $redirectDate = $todayDate;
            }
            if (!$isManager) {
                $error = "حذف السلف متاح للمدير فقط.";
            } elseif ($loanId <= 0) {
                $error = "السلفة غير صالحة.";
            } else {
                $loanRecordToDelete = fetchAdminLoanRecord($pdo, $loanId, $currentGameId);
                $deleteStmt = $pdo->prepare("DELETE FROM admin_loans WHERE id = ? AND game_id = ?");

                try {
                    $deleteStmt->execute([$loanId, $currentGameId]);
                    if ($deleteStmt->rowCount() === 0) {
                        $error = "السلفة غير متاحة.";
                    } else {
                        $deletedLoanLabel = $loanRecordToDelete ? ((string)($loanRecordToDelete["admin_name"] ?? "") . " بمبلغ " . (string)($loanRecordToDelete["amount"] ?? "")) : "";
                        auditLogActivity($pdo, "delete", "admin_loans", $loanId, "سلف الإداريين", "حذف سلفة: " . $deletedLoanLabel);
                        $_SESSION["admin_loans_success"] = "تم حذف السلفة.";
                        header("Location: admin_loans.php?date=" . urlencode($redirectDate));
                        exit;
                    }
                } catch (Throwable $throwable) {
                    logAdminLoanException($throwable);
                    $error = "تعذر حذف السلفة.";
                }
            }
        }
    }
}

$editLoanId = (int)($_GET["edit"] ?? 0);
if ($isManager && $editLoanId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editLoan = fetchAdminLoanRecord($pdo, $editLoanId, $currentGameId);
    if ($editLoan) {
        $formData = [
            "id" => (int)$editLoan["id"],
            "admin_id" => isset($adminMap[(int)$editLoan["admin_id"]]) ? (int)$editLoan["admin_id"] : 0,
            "amount" => number_format((float)$editLoan["amount"], 2, ".", ""),
            "loan_date" => (string)$editLoan["loan_date"],
        ];
        $selectedDate = (string)$editLoan["loan_date"];
    }
}

$records = fetchAdminLoanRows($pdo, $currentGameId, $selectedDate, $selectedAdminId);
$summary = summarizeAdminLoanRows($records);

if (($_GET["export"] ?? "") === "xlsx") {
    try {
        exportAdminLoansXlsx($records, $selectedDate, $currentGameName);
    } catch (Throwable $throwable) {
        logAdminLoanException($throwable);
        $error = "تعذر إنشاء ملف Excel.";
    }
}

$exportQuery = http_build_query([
    "date" => $selectedDate,
    "admin_id" => $selectedAdminId,
    "export" => "xlsx",
]);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سلف الإداريين</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">القائمة</button>
                <div>
                    <h1>سلف الإداريين</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <span class="context-badge"><?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <span class="context-badge egypt-datetime-badge" id="egyptDateTime"><?php echo htmlspecialchars($egyptDateTimeLabel, ENT_QUOTES, "UTF-8"); ?></span>
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

        <section class="trainers-summary-grid">
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">تاريخ الكشف</span>
                <strong class="trainer-stat-value"><?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">عدد السلف</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["count"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">إجمالي سلف اليوم</span>
                <strong class="trainer-stat-value"><?php echo htmlspecialchars(formatAdminLoanAmountWithCurrency($summary["total_amount"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
        </section>

        <section class="attendance-layout-grid">
            <div class="attendance-stack">
                <div class="card attendance-filter-card">
                     <div class="card-head">
                         <div>
                             <h3><?php echo $formData["id"] > 0 ? "تعديل سلفة" : "تسجيل سلفة جديدة"; ?></h3>
                         </div>
                        <?php if ($formData["id"] > 0): ?>
                            <a href="admin_loans.php?date=<?php echo urlencode($selectedDate); ?>" class="btn btn-soft">إلغاء</a>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="attendance-filter-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admin_loans_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="loan_id" value="<?php echo (int)$formData["id"]; ?>">
                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                        <div class="attendance-filter-grid">
                            <div class="form-group">
                                <label for="loanAdmin">الإداري</label>
                                <select name="admin_id" id="loanAdmin" required>
                                    <option value="">اختر الإداري</option>
                                    <?php foreach ($admins as $admin): ?>
                                        <option value="<?php echo (int)$admin["id"]; ?>" <?php echo (int)$formData["admin_id"] === (int)$admin["id"] ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars((string)$admin["name"], ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="loanAmount">المبلغ بالجنيه المصري</label>
                                <input type="number" name="amount" id="loanAmount" min="0.01" step="0.01" inputmode="decimal" value="<?php echo htmlspecialchars((string)$formData["amount"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="loanDate">تاريخ السلفة</label>
                                <input type="date" name="loan_date" id="loanDate" value="<?php echo htmlspecialchars((string)$formData["loan_date"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary"><?php echo $formData["id"] > 0 ? "تحديث السلفة" : "حفظ السلفة"; ?></button>
                        </div>
                    </form>
                </div>

                <div class="card attendance-filter-card">
                    <div class="card-head">
                        <div>
                            <h3>فلترة كشف اليوم</h3>
                        </div>
                    </div>

                    <form method="GET" class="attendance-filter-form">
                        <div class="attendance-filter-grid">
                            <div class="form-group">
                                <label for="filterDate">التاريخ</label>
                                <input type="date" name="date" id="filterDate" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="filterAdminId">الإداري</label>
                                <select name="admin_id" id="filterAdminId">
                                    <option value="0">كل الإداريين</option>
                                    <?php foreach ($admins as $admin): ?>
                                        <option value="<?php echo (int)$admin["id"]; ?>" <?php echo $selectedAdminId === (int)$admin["id"] ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars((string)$admin["name"], ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض الكشف</button>
                            <a href="admin_loans.php?<?php echo htmlspecialchars($exportQuery, ENT_QUOTES, "UTF-8"); ?>" class="btn btn-warning">تصدير Excel</a>
                            <a href="admin_loans.php" class="btn btn-soft">اليوم الحالي</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card attendance-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>كشف السلف اليومية</h3>
                    </div>
                    <span class="table-counter"><?php echo count($records); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table attendance-table">
                        <thead>
                            <tr>
                                <th>م</th>
                                <th>اسم الإداري</th>
                                <th>المبلغ</th>
                                <th>تاريخ السلفة</th>
                                <th>وقت التسجيل</th>
                                <th>أضيف بواسطة</th>
                                <th>آخر تعديل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) === 0): ?>
                                <tr>
                                    <td colspan="8" class="empty-cell">لا توجد سلف مسجلة في هذا اليوم.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $index => $record): ?>
                                    <tr>
                                        <td data-label="م" class="trainer-row-number"><?php echo (int)($index + 1); ?></td>
                                        <td data-label="اسم الإداري">
                                            <div class="trainer-name-cell">
                                                <strong><?php echo htmlspecialchars((string)$record["display_admin_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="المبلغ">
                                            <span class="loan-amount-pill"><?php echo htmlspecialchars(formatAdminLoanAmountWithCurrency($record["amount"] ?? 0), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="تاريخ السلفة"><?php echo htmlspecialchars((string)$record["loan_date"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="وقت التسجيل"><?php echo htmlspecialchars(formatAdminLoanDateTimeValue($record["created_at"] ?? ""), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars((string)($record["created_by_name"] ?? ADMIN_LOAN_EMPTY_VALUE), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="آخر تعديل بواسطة"><?php echo htmlspecialchars((string)($record["updated_by_name"] ?? ADMIN_LOAN_EMPTY_VALUE), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <?php if ($isManager): ?>
                                                <div class="inline-actions">
                                                    <a href="admin_loans.php?date=<?php echo urlencode((string)$record["loan_date"]); ?>&admin_id=<?php echo (int)$selectedAdminId; ?>&edit=<?php echo (int)$record["id"]; ?>" class="btn btn-warning">تعديل</a>
                                                    <form method="POST" class="inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admin_loans_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="loan_id" value="<?php echo (int)$record["id"]; ?>">
                                                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                                                        <button type="submit" class="btn btn-danger" onclick="return confirm('هل تريد حذف هذه السلفة؟')">حذف</button>
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
<script src="assets/js/script.js"></script>
</body>
</html>
