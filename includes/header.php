<?php
// header.php — Top navigation bar shown on every admin page.
// Displays the page title, dark mode toggle, notification badge, user info, and logout.
$initials    = strtoupper(substr($current_user_name ?? 'U', 0, 1));
$notif_count = 0;

if (isset($conn)) {
    $nr = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
    if ($nr) {
        $nr->bind_param('i', $current_user_id);
        $nr->execute();
        $notif_count = (int) $nr->get_result()->fetch_assoc()['c'];
        $nr->close();
    }
}
?>
<div id="topbar">
    <div class="topbar-left">
        <button id="sidebar-toggle" title="Toggle Sidebar">
            <i class="bi bi-layout-sidebar"></i>
        </button>
        <div class="topbar-title-block">
            <span class="page-title"><?php echo e($page_title ?? APP_NAME); ?></span>
        </div>
    </div>
    <div class="topbar-right">
        <button id="theme-toggle" class="notif-btn" title="Toggle dark mode" onclick="toggleTheme()">
            <i class="bi bi-moon-fill" id="theme-icon"></i>
        </button>
        <div class="notif-btn" title="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($notif_count > 0): ?>
                <span class="notif-badge"><?php echo $notif_count; ?></span>
            <?php endif; ?>
        </div>
        <div class="topbar-user">
            <div class="mini-avatar"><?php echo $initials; ?></div>
            <span><?php echo e($current_user_name); ?></span>
            <span class="badge bg-<?php echo $current_user_role === 'admin' ? 'primary' : 'secondary'; ?> ms-1">
                <?php echo ucfirst($current_user_role); ?>
            </span>
        </div>
        <a href="<?php echo BASE_URL; ?>logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>
</div>

<script>
// Sidebar collapse — runs right away so there's no layout flash on page load
(function () {
    var sidebar   = document.getElementById('sidebar');
    var main      = document.querySelector('.main-content');
    var toggle    = document.getElementById('sidebar-toggle');
    var collapsed = localStorage.getItem('sidebar_collapsed') === 'true';

    // Desktop: restore collapsed state from localStorage
    if (window.innerWidth > 768 && collapsed) {
        sidebar.classList.add('collapsed');
        if (main) main.classList.add('expanded');
    }

    toggle && toggle.addEventListener('click', function () {
        if (window.innerWidth <= 768) {
            // Mobile: toggle the slide-in drawer
            sidebar.classList.toggle('mobile-open');
        } else {
            // Desktop: toggle the icon-only collapsed state
            sidebar.classList.toggle('collapsed');
            if (main) main.classList.toggle('expanded');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        }
    });
})();

// Dark mode toggle — smooth transition
function toggleTheme() {
    var html   = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    var next   = isDark ? 'light' : 'dark';

    // Add transition class so all elements fade gracefully
    html.classList.add('theme-transitioning');
    html.setAttribute('data-theme',    next);
    html.setAttribute('data-bs-theme', next);
    localStorage.setItem('theme', next);
    updateThemeIcon();

    // Remove transition class after animation completes
    setTimeout(function () {
        html.classList.remove('theme-transitioning');
    }, 400);
}

function updateThemeIcon() {
    var icon = document.getElementById('theme-icon');
    if (icon) icon.className = document.documentElement.getAttribute('data-theme') === 'dark'
        ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
}

document.addEventListener('DOMContentLoaded', updateThemeIcon);
</script>
