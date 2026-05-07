<?php
require_once 'session.php';
startSecureSession();
require_once 'config.php';
require_once 'navigation.php';
require_once 'audit.php';

requireAuthenticatedUser();
requireMenuAccess('store-orders');

$currentGameId = (int)($_SESSION['selected_game_id'] ?? 0);
$currentGameName = (string)($_SESSION['selected_game_name'] ?? '');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$settingsStmt = $pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1');
$settings = $settingsStmt->fetch() ?: [];
$sidebarName = $settings['academy_name'] ?? ($_SESSION['site_name'] ?? 'أكاديمية رياضية');
$sidebarLogo = $settings['academy_logo'] ?? ($_SESSION['site_logo'] ?? 'assets/images/logo.png');
$activeMenu = 'store-orders';

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS store_orders (
            id INT(11) NOT NULL AUTO_INCREMENT,
            game_id INT(11) NOT NULL,
            player_id INT(11) NOT NULL,
            product_id INT(11) NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity INT(11) NOT NULL DEFAULT 1,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            customer_name VARCHAR(150) NOT NULL DEFAULT '',
            customer_phone VARCHAR(50) NOT NULL DEFAULT '',
            delivery_address TEXT NOT NULL,
            notes TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_response TEXT NULL,
            responded_by_user_id INT(11) NULL DEFAULT NULL,
            responded_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_orders_game (game_id),
            KEY idx_store_orders_player (player_id),
            KEY idx_store_orders_status (game_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
} catch (PDOException $ignored) {}

try {
    $tpiCheck = $pdo->query("SHOW COLUMNS FROM player_notifications LIKE 'target_player_id'");
    if (!$tpiCheck->fetch()) {
        $pdo->exec("ALTER TABLE player_notifications ADD COLUMN target_player_id INT(11) NULL DEFAULT NULL AFTER target_group_level");
    }
} catch (PDOException $ignored) {}

if (!isset($_SESSION['store_orders_csrf'])) {
    $_SESSION['store_orders_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['store_orders_csrf'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'الطلب غير صالح.';
    } else {
        $action = $_POST['action'] ?? '';
        $orderId = (int)($_POST['order_id'] ?? 0);
        $response = trim((string)($_POST['admin_response'] ?? ''));

        if (!in_array($action, ['accept', 'reject', 'delete'], true)) {
            $error = 'إجراء غير صالح.';
        } elseif ($orderId <= 0) {
            $error = 'الطلب غير صالح.';
        } else {
            try {
                $os = $pdo->prepare("SELECT * FROM store_orders WHERE id = ? AND game_id = ? LIMIT 1");
                $os->execute([$orderId, $currentGameId]);
                $order = $os->fetch();
                if (!$order) {
                    $error = 'الطلب غير موجود.';
                } else {
                    if ($action === 'delete') {
                        $del = $pdo->prepare("DELETE FROM store_orders WHERE id = ? AND game_id = ?");
                        $del->execute([$orderId, $currentGameId]);
                        auditLogActivity($pdo, "delete", "store_orders", $orderId, "طلبات المتجر", "حذف طلب #" . $orderId);
                        $success = 'تم حذف الطلب.';
                    } else {
                        $newStatus = $action === 'accept' ? 'accepted' : 'rejected';
                        $up = $pdo->prepare(
                            "UPDATE store_orders
                             SET status = ?, admin_response = ?, responded_by_user_id = ?, responded_at = NOW()
                             WHERE id = ? AND game_id = ?"
                        );
                        $up->execute([$newStatus, $response !== '' ? $response : null, $currentUserId > 0 ? $currentUserId : null, $orderId, $currentGameId]);

                        $title = $newStatus === 'accepted' ? '✅ تم قبول طلبك من المتجر' : '❌ تم رفض طلبك من المتجر';
                        $msgLines = [];
                        $msgLines[] = "المنتج: " . (string)$order['product_name'];
                        $msgLines[] = "الكمية: " . (int)$order['quantity'];
                        $msgLines[] = "الإجمالي: " . number_format((float)$order['total_price'], 2) . " ج.م";
                        if ($response !== '') {
                            $msgLines[] = "ملاحظات الإدارة: " . $response;
                        }
                        $message = implode("\n", $msgLines);

                        try {
                            $insN = $pdo->prepare(
                                "INSERT INTO player_notifications
                                 (game_id, title, message, notification_type, priority_level, visibility_status,
                                  target_scope, target_group_id, target_group_name, target_group_level, target_player_id,
                                  display_date, created_by_user_id, updated_by_user_id)
                                 VALUES (?,?,?,?,?,?, 'player', NULL, NULL, NULL, ?, CURDATE(), ?, ?)"
                            );
                            $insN->execute([
                                $currentGameId,
                                $title,
                                $message,
                                'general',
                                $newStatus === 'accepted' ? 'important' : 'urgent',
                                'visible',
                                (int)$order['player_id'],
                                $currentUserId > 0 ? $currentUserId : null,
                                $currentUserId > 0 ? $currentUserId : null,
                            ]);
                        } catch (PDOException $ignoredNotif) {}

                        auditLogActivity($pdo, "update", "store_orders", $orderId, "طلبات المتجر",
                            ($newStatus === 'accepted' ? 'قبول' : 'رفض') . " طلب #" . $orderId);
                        $success = $newStatus === 'accepted' ? 'تم قبول الطلب وإرسال إشعار للاعب.' : 'تم رفض الطلب وإرسال إشعار للاعب.';
                    }
                }
            } catch (PDOException $ex) {
                $error = 'تعذر تنفيذ الإجراء.';
            }
        }
    }
}

$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'pending', 'accepted', 'rejected'], true)) {
    $statusFilter = 'all';
}

$ordersSql = "SELECT o.*, p.name AS player_name, p.barcode AS player_barcode
              FROM store_orders o
              LEFT JOIN players p ON p.id = o.player_id
              WHERE o.game_id = ?";
$ordersParams = [$currentGameId];
if ($statusFilter !== 'all') {
    $ordersSql .= " AND o.status = ?";
    $ordersParams[] = $statusFilter;
}
$ordersSql .= " ORDER BY o.created_at DESC, o.id DESC";

$orders = [];
try {
    $oStmt = $pdo->prepare($ordersSql);
    $oStmt->execute($ordersParams);
    $orders = $oStmt->fetchAll();
} catch (PDOException $ignored) {}

$summaryRow = ['t' => 0, 'p' => 0, 'a' => 0, 'r' => 0];
try {
    $sumStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS t,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS p,
            SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) AS a,
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS r
         FROM store_orders WHERE game_id = ?"
    );
    $sumStmt->execute([$currentGameId]);
    $summaryRow = $sumStmt->fetch() ?: $summaryRow;
} catch (PDOException $ignored) {}

function soFmtDateTime($d) {
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00 00:00:00') return '—';
    try {
        $dt = new DateTimeImmutable($d, new DateTimeZone('Africa/Cairo'));
        return $dt->format('Y/m/d - H:i');
    } catch (Exception $e) { return '—'; }
}
function soStatusLabel($s) {
    $m = ['pending' => ['قيد المراجعة','status-warning'], 'accepted' => ['مقبول','status-success'], 'rejected' => ['مرفوض','status-danger']];
    return $m[(string)$s] ?? [(string)$s,'status-neutral'];
}
function soEsc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>طلبات المتجر | <?php echo soEsc($sidebarName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
.so-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:18px; }
.so-stat { background:var(--bg-secondary); border:1px solid var(--border); border-radius:14px; padding:18px 14px; text-align:center; }
.so-stat-val { font-size:1.4rem; font-weight:800; color:var(--text); }
.so-stat-lbl { font-size:.82rem; color:var(--text-soft); font-weight:700; margin-top:4px; }
.so-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.so-filter {
    padding:8px 16px; background:var(--bg-secondary); border:1px solid var(--border);
    border-radius:10px; color:var(--text); font-weight:700; text-decoration:none; font-size:.88rem;
}
.so-filter.active { background:linear-gradient(135deg,var(--primary),var(--purple)); color:#fff; border-color:transparent; }
.so-card {
    background:var(--bg-secondary); border:1px solid var(--border);
    border-radius:16px; padding:18px; margin-bottom:14px;
}
.so-card-h { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
.so-card-id { font-size:.9rem; color:var(--text-soft); font-weight:700; }
.so-card-title { font-size:1.05rem; font-weight:800; color:var(--text); margin-top:2px; }
.so-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:12px; }
.so-info { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:10px 12px; }
.so-info-l { font-size:.74rem; color:var(--text-soft); font-weight:700; margin-bottom:2px; }
.so-info-v { font-size:.92rem; color:var(--text); font-weight:800; word-break:break-word; }
.so-actions { display:flex; gap:8px; flex-wrap:wrap; padding-top:12px; border-top:1px solid var(--border); margin-top:8px; }
.so-btn { padding:9px 18px; border:none; border-radius:10px; font-weight:800; cursor:pointer; font-size:.85rem; }
.so-btn-accept { background:rgba(22,163,74,.12); color:var(--success); border:1px solid var(--success); }
.so-btn-reject { background:rgba(220,38,38,.1); color:var(--danger); border:1px solid var(--danger); }
.so-btn-delete { background:transparent; color:var(--text-soft); border:1px solid var(--border); }
.so-btn:hover { opacity:.9; }
.so-response-input {
    width:100%; padding:9px 12px; border:1.5px solid var(--border); border-radius:10px;
    background:var(--input-bg); color:var(--text); font-family:inherit; font-size:.9rem; margin-bottom:10px;
}
.so-empty { text-align:center; padding:60px 20px; color:var(--text-soft); font-weight:700; }
.so-empty span { display:block; font-size:3rem; margin-bottom:10px; }
.so-msg { padding:12px 16px; border-radius:10px; font-weight:800; margin-bottom:14px; text-align:center; }
.so-msg-ok { background:rgba(22,163,74,.1); border:1px solid var(--success); color:var(--success); }
.so-msg-er { background:rgba(220,38,38,.1); border:1px solid var(--danger); color:var(--danger); }
</style>
</head>
<body class="dashboard-body">

<?php include 'sidebar_menu.php'; ?>

<main class="main-content" id="mainContent">
    <header class="dashboard-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle" type="button">📚</button>
            <div>
                <h1>📦 طلبات المتجر</h1>
                <p>إدارة طلبات اللاعبين من المتجر — لعبة: <?php echo soEsc($currentGameName); ?></p>
            </div>
        </div>
    </header>

    <section class="dashboard-section">
        <?php if ($success !== ''): ?><div class="so-msg so-msg-ok">✅ <?php echo soEsc($success); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="so-msg so-msg-er">⚠️ <?php echo soEsc($error); ?></div><?php endif; ?>

        <div class="so-stats">
            <div class="so-stat"><div class="so-stat-val"><?php echo (int)$summaryRow['t']; ?></div><div class="so-stat-lbl">📋 إجمالي الطلبات</div></div>
            <div class="so-stat"><div class="so-stat-val"><?php echo (int)$summaryRow['p']; ?></div><div class="so-stat-lbl">⏳ قيد المراجعة</div></div>
            <div class="so-stat"><div class="so-stat-val"><?php echo (int)$summaryRow['a']; ?></div><div class="so-stat-lbl">✅ مقبولة</div></div>
            <div class="so-stat"><div class="so-stat-val"><?php echo (int)$summaryRow['r']; ?></div><div class="so-stat-lbl">❌ مرفوضة</div></div>
        </div>

        <div class="so-filters">
            <a href="?status=all" class="so-filter <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">الكل</a>
            <a href="?status=pending" class="so-filter <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">⏳ قيد المراجعة</a>
            <a href="?status=accepted" class="so-filter <?php echo $statusFilter === 'accepted' ? 'active' : ''; ?>">✅ مقبولة</a>
            <a href="?status=rejected" class="so-filter <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">❌ مرفوضة</a>
        </div>

        <?php if (count($orders) === 0): ?>
            <div class="so-empty"><span>📦</span>لا توجد طلبات.</div>
        <?php else: ?>
            <?php foreach ($orders as $o): ?>
                <?php $st = soStatusLabel($o['status']); ?>
                <div class="so-card">
                    <div class="so-card-h">
                        <div>
                            <div class="so-card-id">طلب رقم #<?php echo (int)$o['id']; ?></div>
                            <div class="so-card-title"><?php echo soEsc($o['product_name']); ?></div>
                        </div>
                        <span class="<?php echo $st[1]; ?>" style="padding:6px 14px;border-radius:999px;font-weight:800;font-size:.85rem;"><?php echo soEsc($st[0]); ?></span>
                    </div>

                    <div class="so-grid">
                        <div class="so-info"><div class="so-info-l">اللاعب</div><div class="so-info-v"><?php echo soEsc($o['player_name'] ?? '—'); ?></div></div>
                        <div class="so-info"><div class="so-info-l">باركود اللاعب</div><div class="so-info-v" dir="ltr"><?php echo soEsc($o['player_barcode'] ?? '—'); ?></div></div>
                        <div class="so-info"><div class="so-info-l">الكمية</div><div class="so-info-v"><?php echo (int)$o['quantity']; ?></div></div>
                        <div class="so-info"><div class="so-info-l">سعر الوحدة</div><div class="so-info-v"><?php echo number_format((float)$o['unit_price'], 2); ?> ج.م</div></div>
                        <div class="so-info"><div class="so-info-l">الإجمالي</div><div class="so-info-v" style="color:var(--success)"><?php echo number_format((float)$o['total_price'], 2); ?> ج.م</div></div>
                        <div class="so-info"><div class="so-info-l">اسم المستلم</div><div class="so-info-v"><?php echo soEsc($o['customer_name']); ?></div></div>
                        <div class="so-info"><div class="so-info-l">رقم الهاتف</div><div class="so-info-v" dir="ltr"><?php echo soEsc($o['customer_phone']); ?></div></div>
                        <div class="so-info" style="grid-column:1/-1;"><div class="so-info-l">عنوان التوصيل</div><div class="so-info-v"><?php echo nl2br(soEsc($o['delivery_address'])); ?></div></div>
                        <?php if (!empty($o['notes'])): ?>
                            <div class="so-info" style="grid-column:1/-1;"><div class="so-info-l">ملاحظات اللاعب</div><div class="so-info-v"><?php echo nl2br(soEsc($o['notes'])); ?></div></div>
                        <?php endif; ?>
                        <div class="so-info"><div class="so-info-l">تاريخ الطلب</div><div class="so-info-v"><?php echo soFmtDateTime($o['created_at']); ?></div></div>
                        <?php if (!empty($o['responded_at'])): ?>
                            <div class="so-info"><div class="so-info-l">تاريخ الرد</div><div class="so-info-v"><?php echo soFmtDateTime($o['responded_at']); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($o['admin_response'])): ?>
                            <div class="so-info" style="grid-column:1/-1;"><div class="so-info-l">رد الإدارة</div><div class="so-info-v"><?php echo nl2br(soEsc($o['admin_response'])); ?></div></div>
                        <?php endif; ?>
                    </div>

                    <?php if ((string)$o['status'] === 'pending'): ?>
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo soEsc($csrf); ?>">
                            <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                            <textarea class="so-response-input" name="admin_response" placeholder="ملاحظة الإدارة للاعب (اختياري)"></textarea>
                            <div class="so-actions">
                                <button type="submit" name="action" value="accept" class="so-btn so-btn-accept">✅ قبول الطلب</button>
                                <button type="submit" name="action" value="reject" class="so-btn so-btn-reject">❌ رفض الطلب</button>
                                <button type="submit" name="action" value="delete" class="so-btn so-btn-delete" onclick="return confirm('هل أنت متأكد من حذف الطلب؟')">🗑️ حذف</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="POST" autocomplete="off" style="margin-top:8px;">
                            <input type="hidden" name="csrf_token" value="<?php echo soEsc($csrf); ?>">
                            <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                            <div class="so-actions">
                                <button type="submit" name="action" value="delete" class="so-btn so-btn-delete" onclick="return confirm('هل أنت متأكد من حذف الطلب؟')">🗑️ حذف الطلب</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<script src="assets/js/script.js"></script>
</body>
</html>
