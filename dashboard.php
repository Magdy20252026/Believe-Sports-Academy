<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
requireAuthenticatedUser();
require_once "navigation.php";
require_once "dashboard_notifications_support.php";
require_once "players_support.php";

function getDashboardEgyptDayKey(DateTimeInterface $dateTime)
{
    $dayMap = [
        6 => "saturday",
        0 => "sunday",
        1 => "monday",
        2 => "tuesday",
        3 => "wednesday",
        4 => "thursday",
        5 => "friday",
    ];

    return $dayMap[(int)$dateTime->format("w")] ?? "";
}

function parseDashboardDateValue($date)
{
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat("!Y-m-d", $date, new DateTimeZone("Africa/Cairo"));
    if (!$dateTime instanceof DateTimeImmutable || $dateTime->format("Y-m-d") !== $date) {
        return null;
    }

    return $dateTime;
}

function getDashboardDayLabel(DateTimeInterface $dateTime)
{
    $dayKey = getDashboardEgyptDayKey($dateTime);
    if (isset(PLAYER_DAY_OPTIONS[$dayKey])) {
        return PLAYER_DAY_OPTIONS[$dayKey];
    }

    return "اليوم الموافق " . $dateTime->format("Y/m/d");
}

function getDashboardAttendanceSectionSubtitle($isViewingToday)
{
    if ($isViewingToday) {
    }

}

function formatDashboardEgyptTime(DateTimeInterface $dateTime)
{
    $hour = (int)$dateTime->format("G");
    $minute = $dateTime->format("i");
    $period = $hour >= 12 ? "م" : "ص";
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return str_pad((string)$displayHour, 2, "0", STR_PAD_LEFT) . ":" . $minute . " " . $period;
}

function formatDashboardAttendanceTime($dateTimeString)
{
    $dateTimeString = trim((string)$dateTimeString);
    if ($dateTimeString === "") {
        return "—";
    }

    try {
        $dateTime = new DateTimeImmutable($dateTimeString, new DateTimeZone("Africa/Cairo"));
    } catch (Exception $exception) {
        return "—";
    }

    return formatDashboardEgyptTime($dateTime);
}

function getDashboardPlayerStatusMeta($status)
{
    $status = trim((string)$status);
    if ($status === PLAYER_ATTENDANCE_STATUS_PRESENT) {
        return ["label" => PLAYER_ATTENDANCE_STATUS_PRESENT, "class" => "status-success"];
    }
    if ($status === PLAYER_ATTENDANCE_STATUS_ABSENT) {
        return ["label" => PLAYER_ATTENDANCE_STATUS_ABSENT, "class" => "status-danger"];
    }

    return ["label" => "لم يسجل بعد", "class" => "status-neutral"];
}

function fetchDashboardTodayAttendanceGroups(PDO $pdo, $gameId, $attendanceDate, $dayKey)
{
    $allowedDayKeys = ["saturday", "sunday", "monday", "tuesday", "wednesday", "thursday", "friday"];
    $dayKey = trim((string)$dayKey);
    if ((int)$gameId <= 0 || $dayKey === "" || !in_array($dayKey, $allowedDayKeys, true)) {
        return [];
    }

    $daySearchPattern = "%|" . $dayKey . "|%";
    $stmt = $pdo->prepare(
        "SELECT
            p.id,
            p.group_id,
            p.barcode,
            p.name,
            p.phone,
            p.group_name,
            p.group_level,
            p.trainer_name,
            COALESCE(pa.attendance_status, '') AS attendance_status,
            pa.attendance_at
         FROM players p
         LEFT JOIN player_attendance pa
             ON pa.player_id = p.id
            AND pa.attendance_date = ?
         WHERE p.game_id = ?
           AND p.subscription_start_date <= ?
           AND p.subscription_end_date >= ?
           AND CONCAT('|', COALESCE(p.training_day_keys, ''), '|') LIKE ?
         ORDER BY p.group_level ASC, p.group_name ASC, p.name ASC, p.id ASC"
    );
    $stmt->execute([
        (string)$attendanceDate,
        (int)$gameId,
        (string)$attendanceDate,
        (string)$attendanceDate,
        $daySearchPattern,
    ]);

    $groups = [];
    foreach ($stmt->fetchAll() as $player) {
        $groupId = (int)($player["group_id"] ?? 0);
        $groupName = trim((string)($player["group_name"] ?? ""));
        $groupLevel = trim((string)($player["group_level"] ?? ""));
        $groupKey = $groupId > 0
            ? "group-" . $groupId
            : "group-fallback-" . strlen($groupName) . ":" . $groupName . "||" . strlen($groupLevel) . ":" . $groupLevel;

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                "group_name" => $groupName !== "" ? $groupName : "بدون اسم مجموعة",
                "group_level" => $groupLevel !== "" ? $groupLevel : "بدون مستوى",
                "trainer_name" => trim((string)($player["trainer_name"] ?? "")),
                "players_count" => 0,
                "present_count" => 0,
                "absent_count" => 0,
                "pending_count" => 0,
                "players" => [],
            ];
        }

        $statusMeta = getDashboardPlayerStatusMeta($player["attendance_status"] ?? "");
        $groups[$groupKey]["players_count"]++;
        if ($statusMeta["label"] === PLAYER_ATTENDANCE_STATUS_PRESENT) {
            $groups[$groupKey]["present_count"]++;
        } elseif ($statusMeta["label"] === PLAYER_ATTENDANCE_STATUS_ABSENT) {
            $groups[$groupKey]["absent_count"]++;
        } else {
            $groups[$groupKey]["pending_count"]++;
        }

        $groups[$groupKey]["players"][] = [
            "barcode" => trim((string)($player["barcode"] ?? "")),
            "name" => trim((string)($player["name"] ?? "")),
            "phone" => trim((string)($player["phone"] ?? "")),
            "attendance_time" => formatDashboardAttendanceTime($player["attendance_at"] ?? ""),
            "status_label" => $statusMeta["label"],
            "status_class" => $statusMeta["class"],
        ];
    }

    return array_values($groups);
}

$siteName = $_SESSION["site_name"] ?? "أكاديمية رياضية";
$siteLogo = $_SESSION["site_logo"] ?? "assets/images/logo.png";
$username = $_SESSION["username"] ?? "مستخدم";
$role     = $_SESSION["role"] ?? "مشرف";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = $_SESSION["selected_game_name"] ?? "";
$currentBranchName = (string)($_SESSION["selected_branch_name"] ?? "");
$dashboardPageUrl = "dashboard.php";
$sidebarName = $siteName;
$sidebarLogo = $siteLogo;
$activeMenu  = "home";
$egyptNow = new DateTimeImmutable("now", new DateTimeZone("Africa/Cairo"));
$currentEgyptTimeLabel = formatDashboardEgyptTime($egyptNow);
$currentEgyptDateLabel = $egyptNow->format("Y/m/d");
$currentEgyptDayLabel = getDashboardDayLabel($egyptNow);
$dashboardSummaryTitle = "لوحة التحكم";
$dashboardSummarySubtitle = "عرض معلومات اللعبة الحالية.";

function fetchDashboardConsecutiveAbsenceAlerts(PDO $pdo, $gameId)
{
    if ((int)$gameId <= 0) { return []; }
    $sql = "SELECT p.id, p.barcode, p.name, p.phone,
                   COALESCE(p.phone2, '') AS phone2,
                   p.group_name, p.group_level, p.trainer_name
            FROM players p
            WHERE p.game_id = ?
            ORDER BY p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$gameId]);
    $players = $stmt->fetchAll();
    if (!$players) { return []; }

    $alerts = [];
    $absentStatus = PLAYER_ATTENDANCE_STATUS_ABSENT;
    $attStmt = $pdo->prepare(
        "SELECT attendance_status, attendance_date
         FROM player_attendance
         WHERE player_id = ?
         ORDER BY attendance_date DESC
         LIMIT 2"
    );
    foreach ($players as $p) {
        $attStmt->execute([(int)$p["id"]]);
        $rows = $attStmt->fetchAll();
        if (count($rows) < 2) { continue; }
        if ((string)$rows[0]["attendance_status"] === $absentStatus
            && (string)$rows[1]["attendance_status"] === $absentStatus) {
            $alerts[] = [
                "alert_key" => buildDashboardNotificationKey("absence", [
                    (int)($p["id"] ?? 0),
                    (string)($rows[1]["attendance_date"] ?? ""),
                    (string)($rows[0]["attendance_date"] ?? ""),
                ]),
                "type" => "absence",
                "barcode" => (string)$p["barcode"],
                "name" => (string)$p["name"],
                "phone" => (string)$p["phone"],
                "phone2" => (string)$p["phone2"],
                "group_name" => (string)$p["group_name"],
                "group_level" => (string)$p["group_level"],
                "trainer_name" => (string)$p["trainer_name"],
                "detail" => "غياب يومين متتاليين (" . $rows[1]["attendance_date"] . " و " . $rows[0]["attendance_date"] . ")",
                "alert_date" => (string)($rows[0]["attendance_date"] ?? ""),
                "alert_time" => "—",
            ];
        }
    }
    return $alerts;
}

function fetchDashboardExpiredSubscriptionAlerts(PDO $pdo, $gameId, DateTimeImmutable $today)
{
    if ((int)$gameId <= 0) { return []; }
    $presentStatus = PLAYER_ATTENDANCE_STATUS_PRESENT;
    $todayDate = $today->format("Y-m-d");
    $sql = "SELECT p.id, p.barcode, p.name, p.phone,
                   COALESCE(p.phone2, '') AS phone2,
                   p.group_name, p.group_level, p.trainer_name,
                   p.subscription_end_date, p.total_trainings,
                   SUM(
                       CASE
                           WHEN COALESCE(NULLIF(pa.attendance_status, ''), ?) = ?
                           THEN 1
                           ELSE 0
                       END
                   ) AS attendance_count
            FROM players p
            LEFT JOIN player_attendance pa
                ON pa.player_id = p.id
               AND pa.attendance_date BETWEEN p.subscription_start_date AND p.subscription_end_date
            WHERE p.game_id = ?
            GROUP BY p.id
            HAVING (
                CASE
                    WHEN p.subscription_end_date <= ? THEN 0
                    ELSE DATEDIFF(p.subscription_end_date, ?)
                END
            ) = 0
               OR GREATEST(0, COALESCE(p.total_trainings, 0) - attendance_count) = 0
            ORDER BY p.subscription_end_date ASC, p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$presentStatus, $presentStatus, (int)$gameId, $todayDate, $todayDate]);
    $alerts = [];
    foreach ($stmt->fetchAll() as $p) {
        $daysRemaining = calculatePlayerDaysRemaining($p["subscription_end_date"] ?? "", $today);
        $remainingTrainings = calculatePlayerRemainingTrainings($p["total_trainings"] ?? 0, $p["attendance_count"] ?? 0);
        if (getPlayerSubscriptionStatus($daysRemaining, $remainingTrainings) !== "منتهي") {
            continue;
        }

        $alerts[] = [
            "alert_key" => buildDashboardNotificationKey("expiry", [
                (int)($p["id"] ?? 0),
                (string)($p["subscription_end_date"] ?? ""),
                $remainingTrainings,
            ]),
            "type" => "expiry",
            "barcode" => (string)$p["barcode"],
            "name" => (string)$p["name"],
            "phone" => (string)$p["phone"],
            "phone2" => (string)$p["phone2"],
            "group_name" => (string)$p["group_name"],
            "group_level" => (string)$p["group_level"],
            "trainer_name" => (string)$p["trainer_name"],
            "detail" => "انتهى الاشتراك في " . (string)$p["subscription_end_date"],
            "alert_date" => (string)($p["subscription_end_date"] ?? ""),
            "alert_time" => "—",
        ];
    }
    return $alerts;
}

function fetchDashboardStaffAttendanceAlerts(PDO $pdo, $gameId, DateTimeImmutable $today)
{
    if ((int)$gameId <= 0) {
        return [];
    }

    $attendanceDate = $today->format("Y-m-d");
    $alerts = [];

    $trainerStmt = $pdo->prepare(
        "SELECT 'trainer' AS staff_type,
                t.id AS staff_id,
                t.barcode,
                t.name,
                t.phone,
                ta.attendance_date,
                ta.attendance_status,
                ta.attendance_minutes_late,
                ta.attendance_at,
                ta.day_status
         FROM trainer_attendance ta
         INNER JOIN trainers t ON t.id = ta.trainer_id
         WHERE ta.game_id = ?
           AND ta.attendance_date = ?
           AND (
                ta.day_status = 'غياب'
                OR ta.attendance_status = 'غياب'
                OR ta.attendance_minutes_late > 0
           )
         ORDER BY ta.id DESC"
    );
    $trainerStmt->execute([(int)$gameId, $attendanceDate]);

    foreach ($trainerStmt->fetchAll() as $row) {
        $isAbsent = (string)($row["day_status"] ?? "") === "غياب" || (string)($row["attendance_status"] ?? "") === "غياب";
        $alerts[] = [
            "alert_key" => buildDashboardNotificationKey("trainer_attendance", [
                (int)($row["staff_id"] ?? 0),
                (string)($row["attendance_date"] ?? ""),
                $isAbsent ? "absent" : "late",
                (int)($row["attendance_minutes_late"] ?? 0),
            ]),
            "staff_label" => "مدرب",
            "barcode" => (string)($row["barcode"] ?? ""),
            "name" => (string)($row["name"] ?? ""),
            "phone" => (string)($row["phone"] ?? ""),
            "detail" => $isAbsent
                ? "تاريخ الغياب: " . (string)($row["attendance_date"] ?? $attendanceDate)
                : "دقائق التأخير: " . (int)($row["attendance_minutes_late"] ?? 0),
            "status_badge" => $isAbsent ? "غياب" : "تأخير",
            "status_class" => $isAbsent ? "danger" : "warning",
            "alert_date" => (string)($row["attendance_date"] ?? $attendanceDate),
            "alert_time" => $isAbsent ? "—" : formatDashboardAttendanceTime($row["attendance_at"] ?? ""),
        ];
    }

    $adminStmt = $pdo->prepare(
        "SELECT 'admin' AS staff_type,
                a.id AS staff_id,
                a.barcode,
                a.name,
                a.phone,
                aa.attendance_date,
                aa.attendance_status,
                aa.attendance_minutes_late,
                aa.attendance_at,
                aa.day_status
         FROM admin_attendance aa
         INNER JOIN admins a ON a.id = aa.admin_id
         WHERE aa.game_id = ?
           AND aa.attendance_date = ?
           AND (
                aa.day_status = 'غياب'
                OR aa.attendance_status = 'غياب'
                OR aa.attendance_minutes_late > 0
           )
         ORDER BY aa.id DESC"
    );
    $adminStmt->execute([(int)$gameId, $attendanceDate]);

    foreach ($adminStmt->fetchAll() as $row) {
        $isAbsent = (string)($row["day_status"] ?? "") === "غياب" || (string)($row["attendance_status"] ?? "") === "غياب";
        $alerts[] = [
            "alert_key" => buildDashboardNotificationKey("admin_attendance", [
                (int)($row["staff_id"] ?? 0),
                (string)($row["attendance_date"] ?? ""),
                $isAbsent ? "absent" : "late",
                (int)($row["attendance_minutes_late"] ?? 0),
            ]),
            "staff_label" => "إداري",
            "barcode" => (string)($row["barcode"] ?? ""),
            "name" => (string)($row["name"] ?? ""),
            "phone" => (string)($row["phone"] ?? ""),
            "detail" => $isAbsent
                ? "تاريخ الغياب: " . (string)($row["attendance_date"] ?? $attendanceDate)
                : "دقائق التأخير: " . (int)($row["attendance_minutes_late"] ?? 0),
            "status_badge" => $isAbsent ? "غياب" : "تأخير",
            "status_class" => $isAbsent ? "danger" : "warning",
            "alert_date" => (string)($row["attendance_date"] ?? $attendanceDate),
            "alert_time" => $isAbsent ? "—" : formatDashboardAttendanceTime($row["attendance_at"] ?? ""),
        ];
    }

    return $alerts;
}

$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$absenceAlerts = fetchDashboardConsecutiveAbsenceAlerts($pdo, $currentGameId);
$expiredSubscriptionAlerts = fetchDashboardExpiredSubscriptionAlerts($pdo, $currentGameId, $egyptNow);
$staffAttendanceAlerts = fetchDashboardStaffAttendanceAlerts($pdo, $currentGameId, $egyptNow);
$allDashboardAlertKeys = [];
foreach ([$absenceAlerts, $expiredSubscriptionAlerts, $staffAttendanceAlerts] as $alertGroup) {
    foreach ($alertGroup as $alertRow) {
        $alertKey = trim((string)($alertRow["alert_key"] ?? ""));
        if ($alertKey !== "") {
            $allDashboardAlertKeys[] = $alertKey;
        }
    }
}
$readDashboardAlertLookup = array_fill_keys(
    fetchDashboardReadAlertKeys($pdo, $currentUserId, $currentGameId, $allDashboardAlertKeys),
    true
);
$unreadAlertKeys = [];
foreach (["absenceAlerts", "expiredSubscriptionAlerts", "staffAttendanceAlerts"] as $alertGroupName) {
    foreach (${$alertGroupName} as $index => $alertRow) {
        $alertKey = trim((string)($alertRow["alert_key"] ?? ""));
        $isRead = $alertKey !== "" && isset($readDashboardAlertLookup[$alertKey]);
        ${$alertGroupName}[$index]["is_read"] = $isRead ? 1 : 0;
        if (!$isRead && $alertKey !== "") {
            $unreadAlertKeys[] = $alertKey;
        }
    }
}
$visibleNotificationsCount = count($absenceAlerts) + count($expiredSubscriptionAlerts) + count($staffAttendanceAlerts);
$unreadNotificationsCount = count($unreadAlertKeys);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم | <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
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
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>👋 مرحبًا، <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>
                        الصلاحية: <strong><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </p>
                </div>
            </div>

            <div class="topbar-left">
                <?php if ($currentBranchName !== ""): ?>
                    <span class="context-badge">🏢 <?php echo htmlspecialchars($currentBranchName, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if ($currentGameName !== ""): ?>
                    <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <span class="context-badge egypt-datetime-badge">🇪🇬 <?php echo htmlspecialchars($currentEgyptDayLabel . " - " . $currentEgyptDateLabel . " - " . $currentEgyptTimeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                <div class="notif-wrap" id="notifWrap" style="position:relative;">
                    <button type="button" id="notifBellBtn" class="notif-bell" aria-label="الإشعارات" style="position:relative; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:8px 12px; cursor:pointer; font-size:18px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                        🔔
                        <?php if ($unreadNotificationsCount > 0): ?>
                            <span class="notif-badge" style="position:absolute; top:-6px; left:-6px; background:#dc2626; color:#fff; border-radius:999px; min-width:22px; height:22px; line-height:22px; padding:0 6px; font-size:12px; font-weight:800;"><?php echo (int)$unreadNotificationsCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notifDropdown" class="notif-dropdown" style="display:none; position:absolute; top:calc(100% + 8px); left:0; min-width:380px; max-width:440px; max-height:520px; overflow-y:auto; background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,0.15); z-index:9999; direction:rtl; text-align:right;">
                        <div style="padding:12px 14px; border-bottom:1px solid #f1f5f9; font-weight:800; color:#0f172a; background:#f8fafc; border-radius:14px 14px 0 0;">
                            <div>🔔 الإشعارات</div>
                            <div id="notifUnreadSummary" style="margin-top:4px; font-size:12px; font-weight:700; color:#475569;">
                                غير المقروء: <span id="notifUnreadCountValue"><?php echo (int)$unreadNotificationsCount; ?></span> | الكل: <?php echo (int)$visibleNotificationsCount; ?>
                            </div>
                        </div>
                        <?php if ($visibleNotificationsCount === 0): ?>
                            <div style="padding:18px; text-align:center; color:#64748b;">لا توجد إشعارات حالياً</div>
                        <?php else: ?>
                            <?php if (count($absenceAlerts) > 0): ?>
                                <div style="padding:10px 14px; background:#fef2f2; color:#b91c1c; font-weight:700; border-bottom:1px solid #fee2e2;">
                                    ⛔ غياب يومين متتاليين (<?php echo count($absenceAlerts); ?>)
                                </div>
                                <?php foreach ($absenceAlerts as $a): ?>
                                    <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9;">
                                        <div style="font-weight:800; color:#0f172a; margin-bottom:4px;"><?php echo htmlspecialchars($a["name"], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div style="font-size:13px; color:#475569; margin-bottom:2px;">📊 الباركود: <strong><?php echo htmlspecialchars($a["barcode"] !== "" ? $a["barcode"] : "—", ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div style="font-size:13px; color:#475569;">📞 <?php echo htmlspecialchars($a["phone"] !== "" ? $a["phone"] : "—", ENT_QUOTES, 'UTF-8'); ?><?php if ($a["phone2"] !== ""): ?> | 📱 <?php echo htmlspecialchars($a["phone2"], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                                        <div style="font-size:12px; color:#b91c1c; margin-top:4px;"><?php echo htmlspecialchars($a["detail"], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div style="font-size:12px; color:#64748b; margin-top:4px;">🗓️ التاريخ: <?php echo htmlspecialchars($a["alert_date"] !== "" ? $a["alert_date"] : "—", ENT_QUOTES, 'UTF-8'); ?> | ⏰ الوقت: <?php echo htmlspecialchars($a["alert_time"] !== "" ? $a["alert_time"] : "—", ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (count($expiredSubscriptionAlerts) > 0): ?>
                                <div aria-label="تنبيه اشتراكات منتهية" style="padding:10px 14px; background:#fffbeb; color:#92400e; font-weight:700; border-bottom:1px solid #fef3c7;">
                                    <span aria-hidden="true">⛔</span> اشتراكات منتهية (<?php echo count($expiredSubscriptionAlerts); ?>)
                                </div>
                                <?php foreach ($expiredSubscriptionAlerts as $a): ?>
                                    <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9;">
                                        <div style="font-weight:800; color:#0f172a; margin-bottom:4px;"><?php echo htmlspecialchars($a["name"], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div style="font-size:13px; color:#475569; margin-bottom:2px;">📊 الباركود: <strong><?php echo htmlspecialchars($a["barcode"] !== "" ? $a["barcode"] : "—", ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div style="font-size:13px; color:#475569;">📞 <?php echo htmlspecialchars($a["phone"] !== "" ? $a["phone"] : "—", ENT_QUOTES, 'UTF-8'); ?><?php if ($a["phone2"] !== ""): ?> | 📱 <?php echo htmlspecialchars($a["phone2"], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                                        <div style="font-size:12px; color:#92400e; margin-top:4px;"><?php echo htmlspecialchars($a["detail"], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div style="font-size:12px; color:#64748b; margin-top:4px;">🗓️ التاريخ: <?php echo htmlspecialchars($a["alert_date"] !== "" ? $a["alert_date"] : "—", ENT_QUOTES, 'UTF-8'); ?> | ⏰ الوقت: <?php echo htmlspecialchars($a["alert_time"] !== "" ? $a["alert_time"] : "—", ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (count($staffAttendanceAlerts) > 0): ?>
                                <div aria-label="تنبيه تأخير وغياب المدربين والإداريين" style="padding:10px 14px; background:#eff6ff; color:#1d4ed8; font-weight:700; border-bottom:1px solid #dbeafe;">
                                    <span aria-hidden="true">👥</span> تأخير وغياب المدربين والإداريين (<?php echo count($staffAttendanceAlerts); ?>)
                                </div>
                                <?php foreach ($staffAttendanceAlerts as $a): ?>
                                    <?php
                                        $statusBadgeBg = $a["status_class"] === "danger" ? "#fef2f2" : "#fffbeb";
                                        $statusBadgeColor = $a["status_class"] === "danger" ? "#b91c1c" : "#92400e";
                                    ?>
                                    <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9;">
                                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:4px;">
                                            <div style="font-weight:800; color:#0f172a;"><?php echo htmlspecialchars($a["staff_label"] . " / " . $a["name"], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <span style="display:inline-flex; align-items:center; justify-content:center; min-width:64px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:800; background:<?php echo $statusBadgeBg; ?>; color:<?php echo $statusBadgeColor; ?>;">
                                                <?php echo htmlspecialchars($a["status_badge"], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                        <div style="font-size:13px; color:#475569; margin-bottom:2px;">📊 الباركود: <strong><?php echo htmlspecialchars($a["barcode"] !== "" ? $a["barcode"] : "—", ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div style="font-size:13px; color:#475569;">📞 <?php echo htmlspecialchars($a["phone"] !== "" ? $a["phone"] : "—", ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div style="font-size:12px; color:#1d4ed8; margin-top:4px;"><?php echo htmlspecialchars($a["detail"], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div style="font-size:12px; color:#64748b; margin-top:4px;">🗓️ التاريخ: <?php echo htmlspecialchars($a["alert_date"] !== "" ? $a["alert_date"] : "—", ENT_QUOTES, 'UTF-8'); ?> | ⏰ الوقت: <?php echo htmlspecialchars($a["alert_time"] !== "" ? $a["alert_time"] : "—", ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle">
                    <span class="theme-slider">
                        <span class="theme-icon sun">☀️</span>
                        <span class="theme-icon moon">🌙</span>
                    </span>
                </label>
            </div>
        </header>

        <section class="dashboard-panels">
            <div class="card dashboard-summary-card">
                <div class="dashboard-summary-head">
                    <div>
                        <h3><?php echo htmlspecialchars($dashboardSummaryTitle, ENT_QUOTES, "UTF-8"); ?></h3>
                        <p class="card-subtitle"><?php echo htmlspecialchars($dashboardSummarySubtitle, ENT_QUOTES, "UTF-8"); ?></p>
                    </div>
                </div>
                <div class="dashboard-meta">
                    <span class="dashboard-meta-item">📅 التاريخ: <?php echo htmlspecialchars($currentEgyptDateLabel, ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="dashboard-meta-item">🗓️ اليوم: <?php echo htmlspecialchars($currentEgyptDayLabel, ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="dashboard-meta-item">⏰ الوقت الحالي: <?php echo htmlspecialchars($currentEgyptTimeLabel, ENT_QUOTES, "UTF-8"); ?></span>
                    <?php if ($currentBranchName !== ""): ?>
                        <span class="dashboard-meta-item">🏢 الفرع: <?php echo htmlspecialchars($currentBranchName, ENT_QUOTES, "UTF-8"); ?></span>
                    <?php endif; ?>
                    <?php if ($currentGameName !== ""): ?>
                        <span class="dashboard-meta-item">🎯 اللعبة: <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="assets/js/script.js"></script>
<script>
(function () {
    var btn = document.getElementById('notifBellBtn');
    var dd = document.getElementById('notifDropdown');
    var wrap = document.getElementById('notifWrap');
    var unreadCountValue = document.getElementById('notifUnreadCountValue');
    var unreadAlertKeys = <?php echo json_encode(array_values($unreadAlertKeys), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var isMarkingRead = false;
    if (!btn || !dd || !wrap) return;

    function setUnreadCount(count) {
        count = Math.max(0, parseInt(count, 10) || 0);
        var badge = btn.querySelector('.notif-badge');
        if (unreadCountValue) {
            unreadCountValue.textContent = String(count);
        }
        if (count === 0) {
            if (badge) {
                badge.remove();
            }
            return;
        }
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'notif-badge';
            badge.style.position = 'absolute';
            badge.style.top = '-6px';
            badge.style.left = '-6px';
            badge.style.background = '#dc2626';
            badge.style.color = '#fff';
            badge.style.borderRadius = '999px';
            badge.style.minWidth = '22px';
            badge.style.height = '22px';
            badge.style.lineHeight = '22px';
            badge.style.padding = '0 6px';
            badge.style.fontSize = '12px';
            badge.style.fontWeight = '800';
            btn.appendChild(badge);
        }
        badge.textContent = String(count);
    }

    function markNotificationsAsRead() {
        if (isMarkingRead || !Array.isArray(unreadAlertKeys) || unreadAlertKeys.length === 0) {
            return;
        }
        isMarkingRead = true;
        fetch('dashboard_notifications_read.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                notification_keys: unreadAlertKeys
            })
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.ok !== true) {
                    throw new Error('invalid_payload');
                }
                unreadAlertKeys = [];
                setUnreadCount(0);
                isMarkingRead = false;
            })
            .catch(function () {
                isMarkingRead = false;
            });
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var shouldOpen = dd.style.display === 'none';
        dd.style.display = shouldOpen ? 'block' : 'none';
        if (shouldOpen) {
            markNotificationsAsRead();
        }
    });
    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) { dd.style.display = 'none'; }
    });
})();
</script>
</body>
</html>
