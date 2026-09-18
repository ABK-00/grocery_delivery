<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <a href="dashboard.php">
            <div class="brand-icon"><i class="bi bi-cart3"></i></div>
            <div class="brand-text">Grocery<span>Delivery</span></div>
        </a>
    </div>

    <div class="sidebar-nav">
        <div class="sidebar-section-title">Main Menu</div>

        <a href="dashboard.php" class="nav-link <?= in_array($currentPage, ['dashboard.php','index.php']) ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
        </a>
        <a href="users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i><span>Users</span>
        </a>
        <a href="staff.php" class="nav-link <?= $currentPage === 'staff.php' ? 'active' : '' ?>">
            <i class="bi bi-person-badge-fill"></i><span>Staff</span>
        </a>
        <a href="delivery_partners.php" class="nav-link <?= $currentPage === 'delivery_partners.php' ? 'active' : '' ?>">
            <i class="bi bi-bicycle"></i><span>Delivery Partners</span>
        </a>
        <a href="products.php" class="nav-link <?= $currentPage === 'products.php' ? 'active' : '' ?>">
            <i class="bi bi-box-seam-fill"></i><span>Products</span>
        </a>
        <a href="categories.php" class="nav-link <?= $currentPage === 'categories.php' ? 'active' : '' ?>">
            <i class="bi bi-tags-fill"></i><span>Categories</span>
        </a>
        <a href="orders.php" class="nav-link <?= in_array($currentPage, ['orders.php','order_details.php']) ? 'active' : '' ?>">
            <i class="bi bi-cart-check-fill"></i><span>Orders</span>
        </a>
        <a href="payments.php" class="nav-link <?= $currentPage === 'payments.php' ? 'active' : '' ?>">
            <i class="bi bi-credit-card-fill"></i><span>Payments</span>
        </a>
        <a href="deliveries.php" class="nav-link <?= in_array($currentPage, ['deliveries.php','delivery_details.php']) ? 'active' : '' ?>">
            <i class="bi bi-truck"></i><span>Deliveries</span>
        </a>
        <a href="reports.php" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-fill"></i><span>Reports</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <button type="button" class="nav-link theme-toggle" data-theme-toggle>
            <i class="bi bi-moon-stars-fill theme-icon"></i>
            <span class="theme-label">Dark Theme</span>
            <small class="theme-state ms-auto">Off</small>
        </button>
        <a href="profile.php" class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>">
            <i class="bi bi-person-circle"></i><span>My Profile</span>
        </a>
        <a href="../logout.php" class="nav-link">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('adminSidebar');
    const toggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');
    if (toggle && sidebar && overlay) {
        toggle.addEventListener('click', () => { sidebar.classList.toggle('show'); overlay.classList.toggle('show'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('show'); overlay.classList.remove('show'); });
        document.querySelectorAll('.sidebar .nav-link').forEach(link => link.addEventListener('click', () => {
            if (window.innerWidth <= 900) { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
        }));
    }
});
</script>
