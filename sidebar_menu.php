<?php
require_once "navigation.php";

$safeSidebarName = $sidebarName ?? "لوحة التحكم";
$safeSidebarLogo = "assets/images/logo.png";
$sidebarItems = getVisibleNavigationItemsForCurrentSession();

function isAllowedSidebarLogoPath($path)
{
    $relativePath = ltrim((string)$path, "/");
    $fullPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . $relativePath);
    if ($fullPath === false) {
        return false;
    }

    $allowedDirectories = [
        realpath(__DIR__ . "/assets"),
        realpath(__DIR__ . "/uploads"),
    ];

    foreach ($allowedDirectories as $directory) {
        if (
            $directory !== false
            && ($fullPath === $directory || strpos($fullPath, $directory . DIRECTORY_SEPARATOR) === 0)
        ) {
            return true;
        }
    }

    return false;
}

function renderSidebarItem(array $item, $activeMenu = "")
{
    $isActive = $activeMenu === $item["key"];
    $itemClasses = implode(" ", array_filter([
        "menu-item",
        $item["tone"] ?? "",
        $isActive ? "active" : "",
    ]));
    ?>
    <a
        href="<?php echo htmlspecialchars($item["href"], ENT_QUOTES, 'UTF-8'); ?>"
        class="<?php echo htmlspecialchars($itemClasses, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <?php echo htmlspecialchars($item["label"] ?? "", ENT_QUOTES, 'UTF-8'); ?>
    </a>
    <?php
}

if (
    isset($sidebarLogo)
    && strpos($sidebarLogo, "..") === false
    && preg_match('/^(?:assets|uploads)\/(?:[A-Za-z0-9._-]+\/)*[A-Za-z0-9._-]+$/', $sidebarLogo) === 1
    && isAllowedSidebarLogoPath($sidebarLogo)
) {
    $safeSidebarLogo = $sidebarLogo;
}
?>
<script>
(function () {
    window.addEventListener('pageshow', function (event) {
        var navEntries = (window.performance && window.performance.getEntriesByType)
            ? window.performance.getEntriesByType('navigation')
            : [];
        var navType = navEntries.length > 0 ? navEntries[0].type : (
            window.performance && window.performance.navigation
                ? (window.performance.navigation.type === 2 ? 'back_forward' : 'navigate')
                : 'navigate'
        );
        if (event.persisted || navType === 'back_forward') {
            window.location.reload();
        }
    });
})();
</script>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <button class="sidebar-toggle" id="sidebarToggle" type="button">📚</button>
        <div class="sidebar-brand">
            <img src="<?php echo htmlspecialchars($safeSidebarLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="الشعار" class="sidebar-logo">
            <div class="sidebar-brand-text">
                <h2><?php echo htmlspecialchars($safeSidebarName, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>لوحة التحكم</p>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <?php foreach ($sidebarItems as $item): ?>
            <?php renderSidebarItem($item, $activeMenu ?? ""); ?>
        <?php endforeach; ?>
        <a href="logout.php" class="menu-item tone-red logout-item">⏻ تسجيل الخروج</a>
    </nav>
</aside>
