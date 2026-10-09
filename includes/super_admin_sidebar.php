<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
<button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation" aria-expanded="false"><i class="bi bi-list"></i></button>
<aside class="sidebar" id="superAdminSidebar">
    <div class="sidebar-brand"><a href="dashboard.php">
            <div class="brand-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div class="brand-text">Grocery<span>Delivery</span><small>Platform</small></div>
        </a></div>
    <div class="sidebar-nav">
        <div class="sidebar-section-title">Platform</div>
        <a href="dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
        <a href="companies.php" class="nav-link <?= in_array($currentPage, ['companies.php', 'company_details.php']) ? 'active' : '' ?>"><i class="bi bi-buildings-fill"></i><span>Companies</span></a>
        <a href="subscriptions.php" class="nav-link <?= $currentPage === 'subscriptions.php' ? 'active' : '' ?>"><i class="bi bi-credit-card-2-front-fill"></i><span>Subscriptions</span></a>
    </div>
    <div class="sidebar-footer"><button type="button" class="nav-link theme-toggle" data-theme-toggle><i class="bi bi-moon-stars-fill"></i><span data-theme-label>Dark Theme</span></button><a href="profile.php" class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>"><i class="bi bi-person-circle"></i><span>My Profile</span></a><a href="../logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/js/theme.js"></script>
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