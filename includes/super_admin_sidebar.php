<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
<button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation" aria-expanded="false"><i class="bi bi-list"></i></button>
<aside class="sidebar" id="superAdminSidebar">
    <div class="sidebar-brand"><a href="<?= BASE_URL ?>/super_admin/dashboard.php">
            <div class="brand-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div class="brand-text">Grocery<span>Delivery</span><small>Platform</small></div>
        </a></div>
    <div class="sidebar-nav">
        <div class="sidebar-section-title">Platform</div>
        <a href="<?= BASE_URL ?>/super_admin/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
        <a href="<?= BASE_URL ?>/super_admin/companies.php" class="nav-link <?= in_array($currentPage, ['companies.php', 'company_details.php']) ? 'active' : '' ?>"><i class="bi bi-buildings-fill"></i><span>Companies</span></a>
        <a href="<?= BASE_URL ?>/super_admin/subscriptions.php" class="nav-link <?= $currentPage === 'subscriptions.php' ? 'active' : '' ?>"><i class="bi bi-credit-card-2-front-fill"></i><span>Subscriptions</span></a>
        <a href="<?= BASE_URL ?>/super_admin/payments.php" class="nav-link <?= $currentPage === 'payments.php' ? 'active' : '' ?>"><i class="bi bi-cash-stack"></i><span>Payments</span></a>
        <a href="<?= BASE_URL ?>/super_admin/users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>"><i class="bi bi-people"></i><span>Users</span></a>
        <a href="<?= BASE_URL ?>/super_admin/reports.php" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>"><i class="bi bi-bar-chart"></i><span>Reports</span></a>
        <a href="<?= BASE_URL ?>/super_admin/audit_logs.php" class="nav-link <?= $currentPage === 'audit_logs.php' ? 'active' : '' ?>"><i class="bi bi-journal-text"></i><span>Audit Logs</span></a>
        <a href="<?= BASE_URL ?>/super_admin/platform_settings.php" class="nav-link <?= $currentPage === 'platform_settings.php' ? 'active' : '' ?>"><i class="bi bi-gear"></i><span>Settings</span></a>
        <a href="<?= BASE_URL ?>/assistant.php" class="nav-link"><i class="bi bi-stars"></i><span>AI Assistant</span></a>
        <a
            href="<?= BASE_URL ?>/super_admin/ai_usage.php"
            class="nav-link <?= $currentPage === 'ai_usage.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-cpu"></i>
            <span>AI Usage</span>
        </a>
        <a href="<?= BASE_URL ?>/notifications.php" class="nav-link"><i class="bi bi-bell"></i><span>Notifications</span></a>
        <a href="<?= BASE_URL ?>/support.php" class="nav-link"><i class="bi bi-life-preserver"></i><span>Support</span></a>
    </div>
    <div class="sidebar-footer"><button type="button" class="nav-link theme-toggle" data-theme-toggle><i class="bi bi-moon-stars-fill"></i><span data-theme-label>Dark Theme</span></button><a href="<?= BASE_URL ?>/super_admin/profile.php" class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>"><i class="bi bi-person-circle"></i><span>My Profile</span></a><a href="<?= BASE_URL ?>/logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="<?= BASE_URL ?>/assets/js/theme.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const sidebar = document.getElementById("superAdminSidebar"),
            toggle = document.getElementById("sidebarToggle"),
            overlay = document.getElementById("sidebarOverlay");
        if (!sidebar || !toggle || !overlay) return;
        const close = () => {
            sidebar.classList.remove("show");
            overlay.classList.remove("show");
            document.body.style.overflow = "";
            toggle.setAttribute("aria-expanded", "false");
            toggle.innerHTML = '<i class="bi bi-list"></i>';
        };
        const open = () => {
            sidebar.classList.add("show");
            overlay.classList.add("show");
            document.body.style.overflow = "hidden";
            toggle.setAttribute("aria-expanded", "true");
            toggle.innerHTML = '<i class="bi bi-x-lg"></i>';
        };
        toggle.addEventListener("click", () => sidebar.classList.contains("show") ? close() : open());
        overlay.addEventListener("click", close);
        sidebar.querySelectorAll("a.nav-link").forEach(a => a.addEventListener("click", () => {
            if (innerWidth <= 900) close();
        }));
        document.addEventListener("keydown", e => {
            if (e.key === "Escape") close();
        });
        window.addEventListener("resize", () => {
            if (innerWidth > 900) close();
        });
    });
</script>