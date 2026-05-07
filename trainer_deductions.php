<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";

date_default_timezone_set("Africa/Cairo");

const TRAINER_DEDUCTION_EMPTY_VALUE = "—";
const TRAINER_DEDUCTION_REASON_MAX_LENGTH = 500;

requireAuthenticatedUser();
requireMenuAccess("trainers-deductions");

function isValidTrainerDeductionDate($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }

    $dateTime = DateTimeImmutable::createFromFormat("Y-m-d", $date, new DateTimeZone("Africa/Cairo"));
    return $dateTime instanceof DateTimeImmutable && $dateTime->format("Y-m-d") === $date;
}

function formatTrainerDeductionEgyptDateTimeLabel(DateTimeInterface $dateTime)
{
    return $dateTime->format("Y/m/d - H:i");
}

function formatTrainerDeductionDateTimeValue($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return TRAINER_DEDUCTION_EMPTY_VALUE;
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return TRAINER_DEDUCTION_EMPTY_VALUE;
    }

    return formatTrainerDeductionEgyptDateTimeLabel($dateTime);
}

function formatTrainerDeductionAmountWithCurrency($amount)
{
    return number_format((float)$amount, 2) . " ج.م";
}

function normalizeTrainerDeductionAmount($amount)
{
    return number_format((float)$amount, 2, ".", "");
}

function normalizeTrainerDeductionReason($reason)
{
    $reason = trim((string)$reason);
    $reason = preg_replace('/\r\n?|\n/u', "\n", $reason);
    $reason = preg_replace('/[ \t]+/u', ' ', $reason);
    $reason = preg_replace('/\n{3,}/u', "\n\n", $reason);

    if (strlen($reason) <= TRAINER_DEDUCTION_REASON_MAX_LENGTH) {
        return $reason;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($reason, 0, TRAINER_DEDUCTION_REASON_MAX_LENGTH, 'UTF-8');
    }

    if (preg_match_all('/./us', $reason, $characters) !== false) {
        return implode('', array_slice($characters[0], 0, TRAINER_DEDUCTION_REASON_MAX_LENGTH));
    }

    return '';
}

function formatTrainerDeductionReasonForExcel($reason)
{
    return str_replace("\n", " / ", normalizeTrainerDeductionReason($reason));
}

function renderTrainerDeductionReasonHtml($reason)
{
    return nl2br(htmlspecialchars(normalizeTrainerDeductionReason($reason), ENT_QUOTES, "UTF-8"));
}

function ensureTrainerDeductionsTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS trainer_deductions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            trainer_id INT(11) NOT NULL,
            trainer_name VARCHAR(150) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reason TEXT NOT NULL,
            deduction_date DATE NOT NULL,
            created_by_user_id INT(11) NULL DEFAULT NULL,
            updated_by_user_id INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trainer_deductions_game_date (game_id, deduction_date),
            KEY idx_trainer_deductions_trainer (trainer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $requiredColumns = [
        "trainer_name" => "ALTER TABLE trainer_deductions ADD COLUMN trainer_name VARCHAR(150) NOT NULL DEFAULT '' AFTER trainer_id",
        "reason" => "ALTER TABLE trainer_deductions ADD COLUMN reason TEXT NOT NULL AFTER amount",
        "updated_by_user_id" => "ALTER TABLE trainer_deductions ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL AFTER created_by_user_id",
        "created_by_user_id" => "ALTER TABLE trainer_deductions ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL AFTER deduction_date",
        "deduction_date" => "ALTER TABLE trainer_deductions ADD COLUMN deduction_date DATE NOT NULL AFTER reason",
    ];

    foreach ($requiredColumns as $columnName => $sql) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM trainer_deductions LIKE " . $pdo->quote($columnName));
        if (!$columnStmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

function fetchTrainerDeductionTrainers(PDO $pdo, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, name
         FROM trainers
         WHERE game_id = ?
         ORDER BY name ASC, id DESC"
    );
    $stmt->execute([(int)$gameId]);
    return $stmt->fetchAll();
}

function fetchTrainerDeductionRecord(PDO $pdo, $deductionId, $gameId)
{
    $stmt = $pdo->prepare(
        "SELECT id, trainer_id, trainer_name, amount, reason, deduction_date
         FROM trainer_deductions
         WHERE id = ? AND game_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$deductionId, (int)$gameId]);
    return $stmt->fetch();
}

function fetchTrainerDeductionRows(PDO $pdo, $gameId, $selectedDate, $trainerId = 0)
{
    $sql = "SELECT
            d.id,
            d.trainer_id,
            d.trainer_name,
            d.amount,
            d.reason,
            d.deduction_date,
            d.created_at,
            d.updated_at,
            COALESCE(t.name, d.trainer_name) AS display_trainer_name,
            COALESCE(created_user.username, " . $pdo->quote(TRAINER_DEDUCTION_EMPTY_VALUE) . ") AS created_by_name,
            COALESCE(updated_user.username, " . $pdo->quote(TRAINER_DEDUCTION_EMPTY_VALUE) . ") AS updated_by_name
        FROM trainer_deductions d
        LEFT JOIN trainers t
            ON t.id = d.trainer_id
           AND t.game_id = d.game_id
        LEFT JOIN users created_user
            ON created_user.id = d.created_by_user_id
        LEFT JOIN users updated_user
            ON updated_user.id = d.updated_by_user_id
        WHERE d.game_id = ?
          AND d.deduction_date = ?";

    $params = [(int)$gameId, (string)$selectedDate];
    if ((int)$trainerId > 0) {
        $sql .= " AND d.trainer_id = ?";
        $params[] = (int)$trainerId;
    }

    $sql .= " ORDER BY d.created_at DESC, d.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function summarizeTrainerDeductionRows(array $rows)
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

function escapeTrainerDeductionXml($value)
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, "UTF-8");
}

function buildTrainerDeductionWorksheetCell($cellReference, $value, $styleId)
{
    return '<c r="' . $cellReference . '" s="' . $styleId . '" t="inlineStr"><is><t xml:space="preserve">' . escapeTrainerDeductionXml($value) . '</t></is></c>';
}

function getTrainerDeductionExcelColumnName($columnNumber)
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

function buildTrainerDeductionWorksheetXml(array $headers, array $rows)
{
    $columnWidths = [10, 18, 24, 22, 34, 24, 20, 20];
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
        $xml .= buildTrainerDeductionWorksheetCell(getTrainerDeductionExcelColumnName($index + 1) . "1", $header, 1);
    }
    $xml .= '</row>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $cellIndex => $cellValue) {
            $xml .= buildTrainerDeductionWorksheetCell(getTrainerDeductionExcelColumnName($cellIndex + 1) . $excelRow, $cellValue, 2);
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    if (count($rows) > 0) {
        $xml .= '<autoFilter ref="A1:' . getTrainerDeductionExcelColumnName(count($headers)) . (count($rows) + 1) . '"/>';
    }
    $xml .= '</worksheet>';
    return $xml;
}

function buildTrainerDeductionStylesXml()
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

function exportTrainerDeductionsXlsx(array $records, $selectedDate, $currentGameName)
{
    if (!class_exists("ZipArchive")) {
        error_log("ZipArchive extension not available for trainer deductions export.");
        throw new RuntimeException("امتداد ZipArchive غير متاح.");
    }

    $headers = [
        "م",
        "التاريخ",
        "اسم المدرب",
        "مبلغ الخصم بالجنيه المصري",
        "سبب الخصم",
        "وقت التسجيل",
        "أضيف بواسطة",
        "آخر تعديل بواسطة",
    ];

    $rows = [];
    foreach ($records as $index => $record) {
        $rows[] = [
            (string)($index + 1),
            (string)$selectedDate,
            (string)($record["display_trainer_name"] ?? $record["trainer_name"] ?? ""),
            normalizeTrainerDeductionAmount($record["amount"] ?? 0),
            formatTrainerDeductionReasonForExcel($record["reason"] ?? ""),
            formatTrainerDeductionDateTimeValue($record["created_at"] ?? ""),
            (string)($record["created_by_name"] ?? TRAINER_DEDUCTION_EMPTY_VALUE),
            (string)($record["updated_by_name"] ?? TRAINER_DEDUCTION_EMPTY_VALUE),
        ];
    }

    $sheetXml = buildTrainerDeductionWorksheetXml($headers, $rows);
    $stylesXml = buildTrainerDeductionStylesXml();
    $timestamp = (new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo")))->format("Y-m-d\\TH:i:sP");
    $tempFile = sys_get_temp_dir() . "/trainer-deductions-" . uniqid("", true) . ".xlsx";
    $tempFileDeleted = false;
    $cleanupTempFile = static function () use ($tempFile, &$tempFileDeleted) {
        if ($tempFileDeleted) {
            return;
        }

        $tempFileDeleted = true;
        if (file_exists($tempFile) && !unlink($tempFile)) {
            error_log("Failed to delete temporary trainer deductions XLSX file: " . basename($tempFile));
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
    <dc:title>خصومات المدربين - ' . escapeTrainerDeductionXml($currentGameName) . '</dc:title>
    <dc:creator>Believe Sports Academy</dc:creator>
    <cp:lastModifiedBy>Believe Sports Academy</cp:lastModifiedBy>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>
</cp:coreProperties>');
    $zip->addFromString("xl/workbook.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="خصومات المدربين" sheetId="1" r:id="rId1"/>
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

    $fileName = "trainer-deductions-" . $selectedDate . ".xlsx";

    for ($bufferLevel = ob_get_level(); $bufferLevel > 0; $bufferLevel--) {
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

function logTrainerDeductionException(Throwable $throwable)
{
    error_log("Trainer deductions page error: " . $throwable->getMessage());
}

if (!isset($_SESSION["trainer_deductions_csrf_token"])) {
    $_SESSION["trainer_deductions_csrf_token"] = bin2hex(random_bytes(32));
}

ensureTrainerDeductionsTable($pdo);

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
$activeMenu = "trainers-deductions";

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
$egyptDateTimeLabel = formatTrainerDeductionEgyptDateTimeLabel($egyptNow);

$selectedDate = trim((string)($_GET["date"] ?? $todayDate));
if (!isValidTrainerDeductionDate($selectedDate)) {
    $selectedDate = $todayDate;
}

$trainers = fetchTrainerDeductionTrainers($pdo, $currentGameId);
$trainerMap = [];
foreach ($trainers as $trainer) {
    $trainerMap[(int)$trainer["id"]] = $trainer;
}

$selectedTrainerId = (int)($_GET["trainer_id"] ?? 0);
if ($selectedTrainerId > 0 && !isset($trainerMap[$selectedTrainerId])) {
    $selectedTrainerId = 0;
}

$formData = [
    "id" => 0,
    "trainer_id" => $selectedTrainerId > 0 ? $selectedTrainerId : 0,
    "amount" => "",
    "reason" => "",
    "deduction_date" => $selectedDate,
];

$flashSuccess = $_SESSION["trainer_deductions_success"] ?? "";
unset($_SESSION["trainer_deductions_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["trainer_deductions_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = $_POST["action"] ?? "";
        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["deduction_id"] ?? 0),
                "trainer_id" => (int)($_POST["trainer_id"] ?? 0),
                "amount" => trim((string)($_POST["amount"] ?? "")),
                "reason" => normalizeTrainerDeductionReason($_POST["reason"] ?? ""),
                "deduction_date" => trim((string)($_POST["deduction_date"] ?? $todayDate)),
            ];

            if ($formData["id"] > 0 && !$isManager) {
                $error = "تعديل الخصم متاح للمدير فقط.";
            } elseif ($formData["trainer_id"] <= 0 || !isset($trainerMap[$formData["trainer_id"]])) {
                $error = "اختر المدرب.";
            } elseif ($formData["amount"] === "") {
                $error = "المبلغ مطلوب.";
            } elseif (!is_numeric($formData["amount"]) || (float)$formData["amount"] <= 0) {
                $error = "المبلغ غير صحيح.";
            } elseif ($formData["reason"] === "") {
                $error = "سبب الخصم مطلوب.";
            } elseif (!isValidTrainerDeductionDate($formData["deduction_date"])) {
                $error = "تاريخ الخصم غير صحيح.";
            }

            if ($error === "") {
                $existingDeduction = $formData["id"] > 0 ? fetchTrainerDeductionRecord($pdo, $formData["id"], $currentGameId) : null;
                if ($formData["id"] > 0 && !$existingDeduction) {
                    $error = "الخصم غير متاح.";
                }
            }

            if ($error === "") {
                $trainerName = (string)$trainerMap[$formData["trainer_id"]]["name"];
                $amountValue = normalizeTrainerDeductionAmount($formData["amount"]);

                try {
                    if ($formData["id"] > 0) {
                        $updateStmt = $pdo->prepare(
                            "UPDATE trainer_deductions
                             SET trainer_id = ?, trainer_name = ?, amount = ?, reason = ?, deduction_date = ?, updated_by_user_id = ?
                             WHERE id = ? AND game_id = ?"
                        );
                        $updateStmt->execute([
                            $formData["trainer_id"],
                            $trainerName,
                            $amountValue,
                            $formData["reason"],
                            $formData["deduction_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $formData["id"],
                            $currentGameId,
                        ]);
                        auditLogActivity($pdo, "update", "trainer_deductions", $formData["id"], "خصومات المدربين", "تعديل خصم لـ: " . $trainerName . " بمبلغ " . $amountValue);
                        $_SESSION["trainer_deductions_success"] = "تم تحديث الخصم.";
                    } else {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO trainer_deductions (game_id, trainer_id, trainer_name, amount, reason, deduction_date, created_by_user_id, updated_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            $currentGameId,
                            $formData["trainer_id"],
                            $trainerName,
                            $amountValue,
                            $formData["reason"],
                            $formData["deduction_date"],
                            $currentUserId > 0 ? $currentUserId : null,
                            $currentUserId > 0 ? $currentUserId : null,
                        ]);
                        $newDeductionId = (int)$pdo->lastInsertId();
                        auditLogActivity($pdo, "create", "trainer_deductions", $newDeductionId, "خصومات المدربين", "إضافة خصم لـ: " . $trainerName . " بمبلغ " . $amountValue);
                        $_SESSION["trainer_deductions_success"] = "تم تسجيل الخصم.";
                    }

                    header("Location: trainer_deductions.php?date=" . urlencode($formData["deduction_date"]));
                    exit;
                } catch (Throwable $throwable) {
                    logTrainerDeductionException($throwable);
                    $error = "تعذر حفظ الخصم.";
                }
            }
        }

        if ($action === "delete") {
            $deductionId = (int)($_POST["deduction_id"] ?? 0);
            $redirectDate = trim((string)($_POST["redirect_date"] ?? $selectedDate));
            if (!isValidTrainerDeductionDate($redirectDate)) {
                $redirectDate = $todayDate;
            }
            if (!$isManager) {
                $error = "حذف الخصم متاح للمدير فقط.";
            } elseif ($deductionId <= 0) {
                $error = "الخصم غير صالح.";
            } else {
                $deductionRecordToDelete = fetchTrainerDeductionRecord($pdo, $deductionId, $currentGameId);
                $deleteStmt = $pdo->prepare("DELETE FROM trainer_deductions WHERE id = ? AND game_id = ?");

                try {
                    $deleteStmt->execute([$deductionId, $currentGameId]);
                    if ($deleteStmt->rowCount() === 0) {
                        $error = "الخصم غير متاح.";
                    } else {
                        $deletedLabel = $deductionRecordToDelete ? ((string)($deductionRecordToDelete["trainer_name"] ?? "") . " بمبلغ " . (string)($deductionRecordToDelete["amount"] ?? "")) : "";
                        auditLogActivity($pdo, "delete", "trainer_deductions", $deductionId, "خصومات المدربين", "حذف خصم: " . $deletedLabel);
                        $_SESSION["trainer_deductions_success"] = "تم حذف الخصم.";
                        header("Location: trainer_deductions.php?date=" . urlencode($redirectDate));
                        exit;
                    }
                } catch (Throwable $throwable) {
                    logTrainerDeductionException($throwable);
                    $error = "تعذر حذف الخصم.";
                }
            }
        }
    }
}

$editDeductionId = (int)($_GET["edit"] ?? 0);
if ($isManager && $editDeductionId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editDeduction = fetchTrainerDeductionRecord($pdo, $editDeductionId, $currentGameId);
    if ($editDeduction) {
        $formData = [
            "id" => (int)$editDeduction["id"],
            "trainer_id" => isset($trainerMap[(int)$editDeduction["trainer_id"]]) ? (int)$editDeduction["trainer_id"] : 0,
            "amount" => number_format((float)$editDeduction["amount"], 2, ".", ""),
            "reason" => (string)($editDeduction["reason"] ?? ""),
            "deduction_date" => (string)$editDeduction["deduction_date"],
        ];
        $selectedDate = (string)$editDeduction["deduction_date"];
    }
}

$records = fetchTrainerDeductionRows($pdo, $currentGameId, $selectedDate, $selectedTrainerId);
$summary = summarizeTrainerDeductionRows($records);

if (($_GET["export"] ?? "") === "xlsx") {
    try {
        exportTrainerDeductionsXlsx($records, $selectedDate, $currentGameName);
    } catch (Throwable $throwable) {
        logTrainerDeductionException($throwable);
        $error = "تعذر إنشاء ملف Excel.";
    }
}

$exportQuery = http_build_query([
    "date" => $selectedDate,
    "trainer_id" => $selectedTrainerId,
    "export" => "xlsx",
]);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خصومات المدربين</title>
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
                    <h1>خصومات المدربين</h1>
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
                <span class="trainer-stat-label">عدد الخصومات</span>
                <strong class="trainer-stat-value"><?php echo (int)$summary["count"]; ?></strong>
            </div>
            <div class="card trainer-stat-card">
                <span class="trainer-stat-label">إجمالي خصومات اليوم</span>
                <strong class="trainer-stat-value"><?php echo htmlspecialchars(formatTrainerDeductionAmountWithCurrency($summary["total_amount"]), ENT_QUOTES, "UTF-8"); ?></strong>
            </div>
        </section>

        <section class="attendance-layout-grid">
            <div class="attendance-stack">
                <div class="card attendance-filter-card">
                    <div class="card-head">
                        <div>
                            <h3><?php echo $formData["id"] > 0 ? "تعديل خصم" : "تسجيل خصم جديد"; ?></h3>
                        </div>
                        <?php if ($formData["id"] > 0): ?>
                            <a href="trainer_deductions.php?date=<?php echo urlencode($selectedDate); ?>" class="btn btn-soft">إلغاء</a>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="attendance-filter-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["trainer_deductions_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="deduction_id" value="<?php echo (int)$formData["id"]; ?>">
                        <div class="attendance-filter-grid">
                            <div class="form-group">
                                <label for="deductionTrainer">المدرب</label>
                                <select name="trainer_id" id="deductionTrainer" required>
                                    <option value="">اختر المدرب</option>
                                    <?php foreach ($trainers as $trainer): ?>
                                        <option value="<?php echo (int)$trainer["id"]; ?>" <?php echo (int)$formData["trainer_id"] === (int)$trainer["id"] ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars((string)$trainer["name"], ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="deductionAmount">مبلغ الخصم بالجنيه المصري</label>
                                <input type="number" name="amount" id="deductionAmount" min="0.01" step="0.01" inputmode="decimal" value="<?php echo htmlspecialchars((string)$formData["amount"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                            <div class="form-group form-group-full">
                                <label for="deductionReason">سبب الخصم</label>
                                <textarea name="reason" id="deductionReason" required><?php echo htmlspecialchars((string)$formData["reason"], ENT_QUOTES, "UTF-8"); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="deductionDate">تاريخ الخصم</label>
                                <input type="date" name="deduction_date" id="deductionDate" value="<?php echo htmlspecialchars((string)$formData["deduction_date"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary"><?php echo $formData["id"] > 0 ? "تحديث الخصم" : "حفظ الخصم"; ?></button>
                        </div>
                    </form>
                </div>

                <div class="card attendance-filter-card">
                    <div class="card-head">
                        <div>
                            <h3>فلترة الكشف</h3>
                        </div>
                    </div>

                    <form method="GET" class="attendance-filter-form">
                        <div class="attendance-filter-grid">
                            <div class="form-group">
                                <label for="filterDate">التاريخ</label>
                                <input type="date" name="date" id="filterDate" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-group">
                                <label for="filterTrainerId">المدرب</label>
                                <select name="trainer_id" id="filterTrainerId">
                                    <option value="0">كل المدربين</option>
                                    <?php foreach ($trainers as $trainer): ?>
                                        <option value="<?php echo (int)$trainer["id"]; ?>" <?php echo $selectedTrainerId === (int)$trainer["id"] ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars((string)$trainer["name"], ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="attendance-filter-actions">
                            <button type="submit" class="btn btn-primary">عرض الكشف</button>
                            <a href="trainer_deductions.php?<?php echo htmlspecialchars($exportQuery, ENT_QUOTES, "UTF-8"); ?>" class="btn btn-warning">تصدير Excel</a>
                            <a href="trainer_deductions.php" class="btn btn-soft">اليوم الحالي</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card attendance-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>جدول الخصومات اليومية</h3>
                    </div>
                    <span class="table-counter"><?php echo count($records); ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table attendance-table">
                        <thead>
                            <tr>
                                <th>م</th>
                                <th>اسم المدرب</th>
                                <th>المبلغ</th>
                                <th>سبب الخصم</th>
                                <th>تاريخ الخصم</th>
                                <th>وقت التسجيل</th>
                                <th>أضيف بواسطة</th>
                                <th>آخر تعديل بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) === 0): ?>
                                <tr>
                                    <td colspan="9" class="empty-cell">لا توجد خصومات مسجلة في هذا اليوم.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $index => $record): ?>
                                    <tr>
                                        <td data-label="م" class="trainer-row-number"><?php echo (int)($index + 1); ?></td>
                                        <td data-label="اسم المدرب">
                                            <div class="trainer-name-cell">
                                                <strong><?php echo htmlspecialchars((string)$record["display_trainer_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="المبلغ">
                                            <span class="loan-amount-pill"><?php echo htmlspecialchars(formatTrainerDeductionAmountWithCurrency($record["amount"] ?? 0), ENT_QUOTES, "UTF-8"); ?></span>
                                        </td>
                                        <td data-label="سبب الخصم">
                                            <div class="deduction-reason-cell"><?php echo renderTrainerDeductionReasonHtml($record["reason"] ?? ""); ?></div>
                                        </td>
                                        <td data-label="تاريخ الخصم"><?php echo htmlspecialchars((string)$record["deduction_date"], ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="وقت التسجيل"><?php echo htmlspecialchars(formatTrainerDeductionDateTimeValue($record["created_at"] ?? ""), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="أضيف بواسطة"><?php echo htmlspecialchars((string)($record["created_by_name"] ?? TRAINER_DEDUCTION_EMPTY_VALUE), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="آخر تعديل بواسطة"><?php echo htmlspecialchars((string)($record["updated_by_name"] ?? TRAINER_DEDUCTION_EMPTY_VALUE), ENT_QUOTES, "UTF-8"); ?></td>
                                        <td data-label="الإجراءات">
                                            <?php if ($isManager): ?>
                                                <div class="inline-actions">
                                                    <a href="trainer_deductions.php?date=<?php echo urlencode((string)$record["deduction_date"]); ?>&trainer_id=<?php echo (int)$selectedTrainerId; ?>&edit=<?php echo (int)$record["id"]; ?>" class="btn btn-warning">تعديل</a>
                                                    <form method="POST" class="inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["trainer_deductions_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="deduction_id" value="<?php echo (int)$record["id"]; ?>">
                                                        <input type="hidden" name="redirect_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, "UTF-8"); ?>">
                                                        <button type="submit" class="btn btn-danger" onclick="return confirm('هل تريد حذف هذا الخصم؟')">حذف</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge trainer-manager-only-badge">مدير</span>
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
