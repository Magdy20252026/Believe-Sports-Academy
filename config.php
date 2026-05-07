<?php
$host = "sql100.infinityfree.com";
$dbname = "if0_41709916_believesportsacademy_012";
$username = "if0_41709916";
$password = "vxP7d5Owzm";

date_default_timezone_set("Africa/Cairo");

const EGYPT_MERIDIEM_SEPARATOR = "\u{00A0}";

function getEgyptDisplayTimeZone()
{
    static $timeZone = null;
    if (!$timeZone instanceof DateTimeZone) {
        $timeZone = new DateTimeZone("Africa/Cairo");
    }

    return $timeZone;
}

function createEgyptDisplayDateTimeFromFormats($value, array $formats)
{
    $timeZone = getEgyptDisplayTimeZone();
    foreach ($formats as $format) {
        $dateTime = DateTimeImmutable::createFromFormat($format, (string)$value, $timeZone);
        if (!$dateTime instanceof DateTimeImmutable) {
            continue;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors === false || ((int)($errors["warning_count"] ?? 0) === 0 && (int)($errors["error_count"] ?? 0) === 0)) {
            return $dateTime->setTimezone($timeZone);
        }
    }

    return null;
}

function normalizeEgyptDisplayDateTime($value, array $formats = [])
{
    $timeZone = getEgyptDisplayTimeZone();

    if ($value instanceof DateTimeInterface) {
        $dateTime = $value instanceof DateTimeImmutable ? $value : DateTimeImmutable::createFromInterface($value);
        return $dateTime->setTimezone($timeZone);
    }

    $value = trim((string)$value);
    if ($value === "") {
        return null;
    }

    $dateTime = createEgyptDisplayDateTimeFromFormats($value, $formats);
    if ($dateTime instanceof DateTimeImmutable) {
        return $dateTime;
    }

    try {
        return (new DateTimeImmutable($value, $timeZone))->setTimezone($timeZone);
    } catch (Exception $exception) {
        return null;
    }
}

function formatEgyptMeridiemLabel(DateTimeInterface $dateTime)
{
    return (int)$dateTime->format("G") < 12 ? "ص" : "م";
}

function formatEgyptTimeForDisplay($value, $emptyValue = "—")
{
    $dateTime = normalizeEgyptDisplayDateTime($value, ["!H:i:s", "!H:i"]);
    if (!$dateTime instanceof DateTimeImmutable) {
        return (string)$emptyValue;
    }

    return $dateTime->format("h:i") . EGYPT_MERIDIEM_SEPARATOR . formatEgyptMeridiemLabel($dateTime);
}

function formatEgyptDateTimeForDisplay($value, $emptyValue = "—", $dateFormat = "Y/m/d", $separator = " - ")
{
    $dateTime = normalizeEgyptDisplayDateTime($value, [
        "Y-m-d H:i:s",
        "Y-m-d H:i",
        "Y-m-d\\TH:i:sP",
        "Y-m-d\\TH:i:s",
        "Y-m-d\\TH:i",
    ]);
    if (!$dateTime instanceof DateTimeImmutable) {
        return (string)$emptyValue;
    }

    return $dateTime->format($dateFormat) . $separator . formatEgyptTimeForDisplay($dateTime, "");
}

function getEgyptMySqlTimeZoneOffset()
{
    $egyptTimeZone = getEgyptDisplayTimeZone();
    $egyptNow = new DateTimeImmutable("now", $egyptTimeZone);
    $offsetInSeconds = $egyptTimeZone->getOffset($egyptNow);
    $sign = $offsetInSeconds < 0 ? "-" : "+";
    $offsetInSeconds = abs($offsetInSeconds);
    $hours = str_pad((string)intdiv($offsetInSeconds, 3600), 2, "0", STR_PAD_LEFT);
    $minutes = str_pad((string)intdiv($offsetInSeconds % 3600, 60), 2, "0", STR_PAD_LEFT);

    return $sign . $hours . ":" . $minutes;
}

function configureEgyptMySqlSessionTimeZone(PDO $pdo)
{
    try {
        $pdo->exec("SET time_zone = 'Africa/Cairo'");
        return;
    } catch (PDOException $exception) {
        error_log("MySQL named timezone Africa/Cairo is unavailable, falling back to Egypt UTC offset: " . $exception->getMessage());
    }

    $timeZoneOffset = getEgyptMySqlTimeZoneOffset();
    if (preg_match('/^\+0[23]:[0-5]\d$/', $timeZoneOffset) !== 1) {
        error_log("Generated Egypt timezone offset is invalid: " . $timeZoneOffset);
        return;
    }

    try {
        $statement = $pdo->prepare("SET time_zone = ?");
        $statement->execute([$timeZoneOffset]);
    } catch (PDOException $exception) {
        error_log("Failed to set MySQL session timezone to Egypt time: " . $exception->getMessage());
    }
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    configureEgyptMySqlSessionTimeZone($pdo);
} catch (PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}

require_once __DIR__ . "/database_bootstrap.php";
bootstrapCoreApplicationDatabase($pdo);

require_once __DIR__ . "/branches_support.php";
ensureBranchSchema($pdo);

bootstrapFeatureApplicationDatabase($pdo);
seedDefaultApplicationData($pdo);

require_once __DIR__ . "/audit.php";
auditEnsureSchema($pdo);
?>
