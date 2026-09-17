<?php

$currentPage = basename($_SERVER['PHP_SELF']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customerName = $_SESSION['user_name'] ?? 'Customer';

require_once __DIR__ . "/loader.php";

?>

<!-- =====================================================
     CUSTOMER SIDEBAR (Matching Admin Style & Collapse)
     ===================================================== -->

<aside class="sidebar" id="customerSidebar">

    <!-- BRAND -->
    <div class="sidebar-brand">
        <a href="dashboard.php">
            <div class="brand-icon">
                <i class="bi bi-cart3"></i>
            </div>
            <div class="brand-text">
                Grocery<span>Delivery</span>
            </div>
        </a>
    </div>

    <!-- PROFILE SUMMARY -->
    <div class="p-3 border-bottom border-secondary border-opacity-10 d-flex align-items-center gap-3">
        <div class="brand-icon" style="background: #22c55e; color: #111827; font-weight: 700; width: 38px; height: 38px; min-width: 38px;">
            <?= strtoupper(substr($customerName, 0, 1)) ?>
        </div>
        <div class="text-truncate">
            <div class="fw-bold text-white small text-truncate"><?= htmlspecialchars($customerName) ?></div>
            <div class="text-secondary" style="font-size: 11px;">Customer Portal</div>
        </div>
    </div>

    <!-- NAVIGATION -->
    <div class="sidebar-nav">

        <div class="sidebar-section-title">
            Main Menu
        </div>

        <a href="dashboard.php" class="nav-link <?= ($currentPage === 'dashboard.php' || $currentPage === 'index.php') ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="../products.php" class="nav-link <?= in_array($currentPage, ['products.php', 'product.php']) ? 'active' : '' ?>">
            <i class="bi bi-shop"></i>
            <span>Shop Products</span>
        </a>

        <a href="../cart.php" class="nav-link <?= ($currentPage === 'cart.php') ? 'active' : '' ?>">
            <i class="bi bi-cart3"></i>
            <span>My Cart</span>
        </a>

        <a href="../orders.php" class="nav-link <?= in_array($currentPage, ['orders.php', 'order_details.php']) ? 'active' : '' ?>">
            <i class="bi bi-bag-check-fill"></i>
            <span>My Orders</span>
        </a>

        <a href="track_order.php" class="nav-link <?= ($currentPage === 'track_order.php') ? 'active' : '' ?>">
            <i class="bi bi-geo-alt-fill"></i>
            <span>Track Delivery</span>
        </a>

        <div class="sidebar-section-title mt-3">
            Account Settings
        </div>

        <a href="profile.php" class="nav-link <?= ($currentPage === 'profile.php') ? 'active' : '' ?>">
            <i class="bi bi-person-circle"></i>
            <span>My Profile</span>
        </a>

    </div>

    <!-- LOGOUT -->
    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link text-danger-subtle">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>

<!-- =====================================================
     MOBILE OVERLAY
     ===================================================== -->

<div class="sidebar-overlay" id="customerOverlay"></div>

<!-- =====================================================
     SIDEBAR JAVASCRIPT
     ===================================================== -->

<script>
document.addEventListener("DOMContentLoaded", function () {
    const sidebar = document.getElementById("customerSidebar") || document.getElementById("adminSidebar");
    const toggleBtns = [
        document.getElementById("sidebarToggle"),
        document.getElementById("customerMenuBtn")
    ];
    const overlay = document.getElementById("customerOverlay") || document.getElementById("sidebarOverlay");

    toggleBtns.forEach(function(btn) {
        if (btn && sidebar) {
            btn.addEventListener("click", function () {
                sidebar.classList.toggle("show");
                if (overlay) overlay.classList.toggle("show");
            });
        }
    });

    if (overlay && sidebar) {
        overlay.addEventListener("click", function () {
            sidebar.classList.remove("show");
            overlay.classList.remove("show");
        });
    }

    document.querySelectorAll(".sidebar .nav-link").forEach(function(link) {
        link.addEventListener("click", function() {
            if (window.innerWidth <= 900 && sidebar && overlay) {
                sidebar.classList.remove("show");
                overlay.classList.remove("show");
            }
        });
    });
});
</script>
