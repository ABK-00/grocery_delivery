<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- =====================================================
     SIDEBAR
     ===================================================== -->

<aside class="sidebar" id="adminSidebar">

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

    <!-- NAVIGATION -->
    <div class="sidebar-nav">

        <div class="sidebar-section-title">
            Main Menu
        </div>

        <a href="dashboard.php" class="nav-link <?= ($currentPage === 'dashboard.php' || $currentPage === 'index.php') ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="users.php" class="nav-link <?= ($currentPage === 'users.php') ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i>
            <span>Users</span>
        </a>

        <a href="staff.php" class="nav-link <?= ($currentPage === 'staff.php') ? 'active' : '' ?>">
            <i class="bi bi-person-badge-fill"></i>
            <span>Staff</span>
        </a>

        <a href="delivery_partners.php" class="nav-link <?= ($currentPage === 'delivery_partners.php') ? 'active' : '' ?>">
            <i class="bi bi-bicycle"></i>
            <span>Delivery Partners</span>
        </a>

        <a href="products.php" class="nav-link <?= ($currentPage === 'products.php') ? 'active' : '' ?>">
            <i class="bi bi-box-seam-fill"></i>
            <span>Products</span>
        </a>

        <a href="categories.php" class="nav-link <?= ($currentPage === 'categories.php') ? 'active' : '' ?>">
            <i class="bi bi-tags-fill"></i>
            <span>Categories</span>
        </a>

        <a href="orders.php" class="nav-link <?= ($currentPage === 'orders.php') ? 'active' : '' ?>">
            <i class="bi bi-cart-check-fill"></i>
            <span>Orders</span>
        </a>

        <a href="payments.php" class="nav-link <?= ($currentPage === 'payments.php') ? 'active' : '' ?>">
            <i class="bi bi-credit-card-fill"></i>
            <span>Payments</span>
        </a>

        <a href="deliveries.php" class="nav-link <?= ($currentPage === 'deliveries.php') ? 'active' : '' ?>">
            <i class="bi bi-truck"></i>
            <span>Deliveries</span>
        </a>

        <a href="reports.php" class="nav-link <?= ($currentPage === 'reports.php') ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

    </div>

    <!-- LOGOUT -->
    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>

<!-- =====================================================
     MOBILE OVERLAY
     ===================================================== -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- =====================================================
     SIDEBAR JAVASCRIPT
     ===================================================== -->

<script>
document.addEventListener("DOMContentLoaded", function () {
    const sidebar = document.getElementById("adminSidebar");
    const sidebarToggle = document.getElementById("sidebarToggle");
    const sidebarOverlay = document.getElementById("sidebarOverlay");

    if (sidebarToggle && sidebar && sidebarOverlay) {
        sidebarToggle.addEventListener("click", function () {
            sidebar.classList.toggle("show");
            sidebarOverlay.classList.toggle("show");
        });

        sidebarOverlay.addEventListener("click", function () {
            sidebar.classList.remove("show");
            sidebarOverlay.classList.remove("show");
        });

        document.querySelectorAll(".sidebar .nav-link").forEach(function(link) {
            link.addEventListener("click", function() {
                if (window.innerWidth <= 900) {
                    sidebar.classList.remove("show");
                    sidebarOverlay.classList.remove("show");
                }
            });
        });
    }
});
</script>
