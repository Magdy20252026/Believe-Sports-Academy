<?php
require_once "session.php";
startSecureSession();
require_once "config.php";
require_once "navigation.php";
require_once "branches_support.php";
require_once "audit.php";

requireAuthenticatedUser();
requireMenuAccess("branches");

try {
    $bootstrapAdminId = (int)($_SESSION["user_id"] ?? 0);
    if ($bootstrapAdminId > 0) {
        $pdo->prepare(
            "INSERT IGNORE INTO user_branches (user_id, branch_id)
             SELECT ?, b.id FROM branches b WHERE b.status = 1"
        )->execute([$bootstrapAdminId]);
    }
} catch (Throwable $bootstrapErr) {
    error_log("branches.php manager auto-link failed: " . $bootstrapErr->getMessage());
}

function ensureBranchAuditColumns(PDO $pdo)
{
    $columns = [
        "created_by_user_id" => "ALTER TABLE branches ADD COLUMN created_by_user_id INT(11) NULL DEFAULT NULL",
        "updated_by_user_id" => "ALTER TABLE branches ADD COLUMN updated_by_user_id INT(11) NULL DEFAULT NULL",
    ];

    $checkStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'branches'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );

    foreach ($columns as $columnName => $alter) {
        try {
            $checkStmt->execute([$columnName]);
            if (!$checkStmt->fetchColumn()) {
                $pdo->exec($alter);
            }
        } catch (Throwable $throwable) {
            error_log("ensureBranchAuditColumns {$columnName} failed: " . $throwable->getMessage());
        }
    }
}

function fetchBranchById(PDO $pdo, $branchId)
{
    $stmt = $pdo->prepare(
        "SELECT id, name, status, created_at, created_by_user_id, updated_by_user_id
         FROM branches
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$branchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function branchNameTaken(PDO $pdo, $name, $excludeId = 0)
{
    if ((int)$excludeId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE name = ? AND id <> ? LIMIT 1");
        $stmt->execute([(string)$name, (int)$excludeId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE name = ? LIMIT 1");
        $stmt->execute([(string)$name]);
    }
    return (bool)$stmt->fetchColumn();
}

function branchDependentCounts(PDO $pdo, $branchId)
{
    $counts = ["games" => 0, "user_branches" => 0];
    try {
        $g = $pdo->prepare("SELECT COUNT(*) FROM games WHERE branch_id = ?");
        $g->execute([(int)$branchId]);
        $counts["games"] = (int)$g->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $u = $pdo->prepare("SELECT COUNT(*) FROM user_branches WHERE branch_id = ?");
        $u->execute([(int)$branchId]);
        $counts["user_branches"] = (int)$u->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    return $counts;
}

if (!isset($_SESSION["branches_csrf_token"])) {
    $_SESSION["branches_csrf_token"] = bin2hex(random_bytes(32));
}

ensureBranchSchema($pdo);
ensureBranchAuditColumns($pdo);

$success = "";
$error = "";
$currentGameId = (int)($_SESSION["selected_game_id"] ?? 0);
$currentGameName = (string)($_SESSION["selected_game_name"] ?? "");
$currentBranchId = (int)($_SESSION["selected_branch_id"] ?? 0);
$currentBranchName = (string)($_SESSION["selected_branch_name"] ?? "");

$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch();
$sidebarName = $settings["academy_name"] ?? ($_SESSION["site_name"] ?? "أكاديمية رياضية");
$sidebarLogo = $settings["academy_logo"] ?? ($_SESSION["site_logo"] ?? "assets/images/logo.png");
$activeMenu = "branches";

$flashSuccess = $_SESSION["branches_success"] ?? "";
unset($_SESSION["branches_success"]);
if ($flashSuccess !== "") {
    $success = $flashSuccess;
}

$formData = [
    "id" => 0,
    "name" => "",
    "status" => 1,
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["branches_csrf_token"], $csrfToken)) {
        $error = "الطلب غير صالح.";
    } else {
        $action = (string)($_POST["action"] ?? "");

        if ($action === "save") {
            $formData = [
                "id" => (int)($_POST["branch_id"] ?? 0),
                "name" => trim((string)($_POST["name"] ?? "")),
                "status" => ((int)($_POST["status"] ?? 1) === 0) ? 0 : 1,
            ];

            if ($formData["name"] === "") {
                $error = "اسم الفرع مطلوب.";
            } elseif (mb_strlen($formData["name"]) > 150) {
                $error = "اسم الفرع يجب ألا يتجاوز 150 حرفًا.";
            } elseif ($formData["id"] > 0 && !fetchBranchById($pdo, $formData["id"])) {
                $error = "الفرع غير متاح.";
            } elseif (branchNameTaken($pdo, $formData["name"], $formData["id"])) {
                $error = "هذا الاسم مستخدم بالفعل لفرع آخر.";
            } else {
                try {
                    if ($formData["id"] > 0) {
                        $stmt = $pdo->prepare(
                            "UPDATE branches SET name = ?, status = ? WHERE id = ?"
                        );
                        $stmt->execute([
                            $formData["name"],
                            $formData["status"],
                            $formData["id"],
                        ]);
                        auditTrack(
                            $pdo,
                            "update",
                            "branches",
                            $formData["id"],
                            "الفروع",
                            "تعديل فرع: " . $formData["name"]
                        );
                        if ($currentBranchId === $formData["id"]) {
                            $_SESSION["selected_branch_name"] = $formData["name"];
                        }
                        $_SESSION["branches_success"] = "تم تحديث بيانات الفرع ✅";
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO branches (name, status) VALUES (?, ?)"
                        );
                        $stmt->execute([$formData["name"], $formData["status"]]);
                        $newBranchId = (int)$pdo->lastInsertId();
                        $creatorId = (int)($_SESSION["user_id"] ?? 0);
                        if ($creatorId > 0 && $newBranchId > 0) {
                            try {
                                $grantStmt = $pdo->prepare(
                                    "INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (?, ?)"
                                );
                                $grantStmt->execute([$creatorId, $newBranchId]);
                            } catch (Throwable $grantErr) {
                                error_log("auto-grant new branch to creator failed: " . $grantErr->getMessage());
                            }
                        }
                        auditTrack(
                            $pdo,
                            "create",
                            "branches",
                            $newBranchId,
                            "الفروع",
                            "إضافة فرع: " . $formData["name"]
                        );
                        $_SESSION["branches_success"] = "تم إضافة الفرع بنجاح ✅";
                    }
                    header("Location: branches.php");
                    exit;
                } catch (Throwable $throwable) {
                    error_log("branches save failed: " . $throwable->getMessage());
                    $error = "تعذر حفظ بيانات الفرع.";
                }
            }
        } elseif ($action === "toggle_status") {
            $branchId = (int)($_POST["branch_id"] ?? 0);
            $branch = $branchId > 0 ? fetchBranchById($pdo, $branchId) : null;
            if (!$branch) {
                $error = "الفرع غير متاح.";
            } else {
                try {
                    $newStatus = ((int)$branch["status"] === 1) ? 0 : 1;
                    $stmt = $pdo->prepare("UPDATE branches SET status = ? WHERE id = ?");
                    $stmt->execute([$newStatus, $branchId]);
                    auditTrack(
                        $pdo,
                        "update",
                        "branches",
                        $branchId,
                        "الفروع",
                        ($newStatus === 1 ? "تفعيل" : "تعطيل") . " فرع: " . $branch["name"]
                    );
                    $_SESSION["branches_success"] = $newStatus === 1
                        ? "تم تفعيل الفرع ✅"
                        : "تم تعطيل الفرع 🚫";
                    header("Location: branches.php");
                    exit;
                } catch (Throwable $throwable) {
                    error_log("branches toggle_status failed: " . $throwable->getMessage());
                    $error = "تعذر تحديث حالة الفرع.";
                }
            }
        } elseif ($action === "delete") {
            $branchId = (int)($_POST["branch_id"] ?? 0);
            $branch = $branchId > 0 ? fetchBranchById($pdo, $branchId) : null;
            if (!$branch) {
                $error = "الفرع غير متاح.";
            } elseif ($branchId === $currentBranchId) {
                $error = "لا يمكن حذف الفرع المُحدّد حاليًا في جلستك. سجّل دخولًا لفرع آخر أولًا.";
            } else {
                $deps = branchDependentCounts($pdo, $branchId);
                if ($deps["games"] > 0) {
                    $error = "لا يمكن حذف الفرع لوجود (" . $deps["games"] . ") لعبة مرتبطة به. احذف أو انقل ألعاب الفرع أولًا.";
                } else {
                    try {
                        $pdo->beginTransaction();
                        $delLinks = $pdo->prepare("DELETE FROM user_branches WHERE branch_id = ?");
                        $delLinks->execute([$branchId]);
                        $delBranch = $pdo->prepare("DELETE FROM branches WHERE id = ?");
                        $delBranch->execute([$branchId]);
                        $pdo->commit();
                        auditLogActivity(
                            $pdo,
                            "delete",
                            "branches",
                            $branchId,
                            "الفروع",
                            "حذف فرع: " . $branch["name"]
                        );
                        $_SESSION["branches_success"] = "تم حذف الفرع 🗑️";
                        header("Location: branches.php");
                        exit;
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("branches delete failed: " . $throwable->getMessage());
                        $error = "تعذر حذف الفرع.";
                    }
                }
            }
        }
    }
}

$editId = (int)($_GET["edit"] ?? 0);
if ($editId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editBranch = fetchBranchById($pdo, $editId);
    if ($editBranch) {
        $formData = [
            "id" => (int)$editBranch["id"],
            "name" => (string)$editBranch["name"],
            "status" => (int)$editBranch["status"],
        ];
    }
}

$branchesStmt = $pdo->query(
    "SELECT b.id, b.name, b.status, b.created_at, b.created_by_user_id, b.updated_by_user_id,
            (SELECT COUNT(*) FROM games g WHERE g.branch_id = b.id) AS games_count,
            (SELECT COUNT(*) FROM user_branches ub WHERE ub.branch_id = b.id) AS users_count
     FROM branches b
     ORDER BY b.id ASC"
);
$branches = $branchesStmt->fetchAll();

$totalBranches = count($branches);
$activeBranches = 0;
foreach ($branches as $b) {
    if ((int)$b["status"] === 1) {
        $activeBranches++;
    }
}
$inactiveBranches = $totalBranches - $activeBranches;

$submitButtonLabel = $formData["id"] > 0 ? "تحديث الفرع" : "إضافة فرع جديد";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الفروع</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .branches-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .branches-stat-card {
            background: var(--card-bg, #fff);
            border-radius: 14px;
            padding: 16px 18px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .branches-stat-card span { color: var(--text-soft, #6b7280); font-size: 13px; }
        .branches-stat-card strong { font-size: 26px; }
        .branches-grid {
            display: grid;
            grid-template-columns: minmax(280px, 360px) 1fr;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .branches-grid { grid-template-columns: 1fr; }
        }
        .branches-form-card .login-form { display: flex; flex-direction: column; gap: 14px; }
        .branches-status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .branches-status-pill.is-active { background: rgba(16, 185, 129, 0.15); color: #047857; }
        .branches-status-pill.is-inactive { background: rgba(148, 163, 184, 0.2); color: #475569; }
        .branches-current-pill {
            display: inline-block;
            margin-inline-start: 6px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(47, 91, 234, 0.12);
            color: #2f5bea;
            font-size: 11px;
            font-weight: 700;
        }
        .branches-info-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.18);
            font-size: 12px;
            font-weight: 700;
        }
        body.dark-mode .branches-stat-card {
            background: #162133;
            border-color: #334155;
        }
        body.dark-mode .branches-status-pill.is-inactive {
            background: rgba(148, 163, 184, 0.25);
            color: #e2e8f0;
        }
    </style>
</head>
<body class="dashboard-page">
<div class="dashboard-layout">
    <?php require "sidebar_menu.php"; ?>

    <main class="main-content branches-page">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-sidebar-btn" id="mobileSidebarBtn" type="button">📋</button>
                <div>
                    <h1>الفروع</h1>
                </div>
            </div>

            <div class="topbar-left users-topbar-left">
                <?php if ($currentBranchName !== ""): ?>
                    <span class="context-badge">🏢 <?php echo htmlspecialchars($currentBranchName, ENT_QUOTES, "UTF-8"); ?></span>
                <?php endif; ?>
                <?php if ($currentGameName !== ""): ?>
                    <span class="context-badge">🎯 <?php echo htmlspecialchars($currentGameName, ENT_QUOTES, "UTF-8"); ?></span>
                <?php endif; ?>
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

        <section class="branches-stat-grid">
            <div class="branches-stat-card">
                <span>إجمالي الفروع</span>
                <strong><?php echo (int)$totalBranches; ?></strong>
            </div>
            <div class="branches-stat-card">
                <span>الفروع المُفعّلة</span>
                <strong><?php echo (int)$activeBranches; ?></strong>
            </div>
            <div class="branches-stat-card">
                <span>الفروع المُعطّلة</span>
                <strong><?php echo (int)$inactiveBranches; ?></strong>
            </div>
        </section>

        <section class="branches-grid">
            <div class="card branches-form-card">
                <div class="card-head">
                    <div>
                        <h3><?php echo $formData["id"] > 0 ? "تعديل بيانات الفرع" : "إضافة فرع جديد"; ?></h3>
                    </div>
                    <?php if ($formData["id"] > 0): ?>
                        <a href="branches.php" class="btn btn-soft" aria-label="إلغاء التعديل">إلغاء</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["branches_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="branch_id" value="<?php echo (int)$formData["id"]; ?>">

                    <div class="form-group">
                        <label for="name">اسم الفرع</label>
                        <input type="text" name="name" id="name" maxlength="150" value="<?php echo htmlspecialchars($formData["name"], ENT_QUOTES, "UTF-8"); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="status">حالة الفرع</label>
                        <select name="status" id="status">
                            <option value="1" <?php echo $formData["status"] === 1 ? "selected" : ""; ?>>مُفعّل</option>
                            <option value="0" <?php echo $formData["status"] === 0 ? "selected" : ""; ?>>مُعطّل</option>
                        </select>
                        <small style="color:var(--text-soft,#6b7280);">الفروع المُعطّلة لا تظهر في صفحة تسجيل الدخول.</small>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($submitButtonLabel, ENT_QUOTES, "UTF-8"); ?></button>

                    <?php if ($formData["id"] === 0): ?>
                        <small style="color:var(--text-soft,#6b7280);">
                            بعد إضافة فرع جديد، أضف ألعابه من صفحة <a href="games.php">الألعاب</a>،
                            ثم امنح المستخدمين صلاحيات الوصول للفرع من صفحة <a href="users.php">المستخدمين</a>.
                        </small>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card branches-table-card">
                <div class="card-head table-card-head">
                    <div>
                        <h3>الفروع المُسجّلة</h3>
                    </div>
                    <span class="table-counter">الإجمالي: <?php echo (int)$totalBranches; ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الفرع</th>
                                <th>الحالة</th>
                                <th>عدد الألعاب</th>
                                <th>المستخدمين المرتبطين</th>
                                <th>أُنشئ في</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($totalBranches === 0): ?>
                                <tr><td colspan="6" class="empty-cell">لا توجد فروع مُسجّلة بعد.</td></tr>
                            <?php else: ?>
                                <?php foreach ($branches as $branch): $branchId = (int)$branch["id"]; ?>
                                    <tr>
                                        <td data-label="الفرع">
                                            <strong><?php echo htmlspecialchars($branch["name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            <?php if ($branchId === $currentBranchId): ?>
                                                <span class="branches-current-pill">الحالي</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="الحالة">
                                            <?php if ((int)$branch["status"] === 1): ?>
                                                <span class="branches-status-pill is-active">مُفعّل</span>
                                            <?php else: ?>
                                                <span class="branches-status-pill is-inactive">مُعطّل</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="عدد الألعاب">
                                            <span class="branches-info-pill"><?php echo (int)$branch["games_count"]; ?></span>
                                        </td>
                                        <td data-label="المستخدمين المرتبطين">
                                            <span class="branches-info-pill"><?php echo (int)$branch["users_count"]; ?></span>
                                        </td>
                                        <td data-label="أُنشئ في">
                                            <?php echo htmlspecialchars((string)($branch["created_at"] ?? ""), ENT_QUOTES, "UTF-8"); ?>
                                        </td>
                                        <td data-label="الإجراءات">
                                            <div class="inline-actions">
                                                <a href="branches.php?edit=<?php echo $branchId; ?>" class="btn btn-warning" aria-label="تعديل الفرع">✏️</a>

                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["branches_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="branch_id" value="<?php echo $branchId; ?>">
                                                    <button type="submit" class="btn btn-soft" aria-label="تبديل حالة الفرع">
                                                        <?php echo (int)$branch["status"] === 1 ? "🚫 تعطيل" : "✅ تفعيل"; ?>
                                                    </button>
                                                </form>

                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["branches_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="branch_id" value="<?php echo $branchId; ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-danger"
                                                        aria-label="حذف الفرع"
                                                        onclick="return confirm('هل أنت متأكد من حذف الفرع؟ لا يمكن التراجع.');"
                                                        <?php echo $branchId === $currentBranchId ? "disabled title='لا يمكن حذف الفرع المُحدّد حاليًا'" : ""; ?>
                                                    >🗑️</button>
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
