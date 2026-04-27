<?php
$host = "sql100.infinityfree.com";
$dbname = "if0_41709916_believesportsacademy_012";
$username = "if0_41709916";
$password = "vxP7d5Owzm";

date_default_timezone_set("Africa/Cairo");

function getEgyptMySqlTimeZoneOffset()
{
    $egyptTimeZone = new DateTimeZone("Africa/Cairo");
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

require_once __DIR__ . "/audit.php";
auditEnsureSchema($pdo);

require_once __DIR__ . "/branches_support.php";
ensureBranchSchema($pdo);
?>
