<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <a href="<?= BASE_URL ?>/admin/dashboard.php">
            <div class="brand-icon"><i class="bi bi-cart3"></i></div>
            <div class="brand-text">Grocery<span>Delivery</span></div>
        </a>
    </div>

    <div class="sidebar-nav">
        <div class="sidebar-section-title">Main Menu</div>

        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="nav-link <?= in_array($currentPage, ['dashboard.php', 'index.php']) ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i><span>Users</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/staff.php" class="nav-link <?= $currentPage === 'staff.php' ? 'active' : '' ?>">
            <i class="bi bi-person-badge-fill"></i><span>Staff</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/delivery_partners.php" class="nav-link <?= $currentPage === 'delivery_partners.php' ? 'active' : '' ?>">
            <i class="bi bi-bicycle"></i><span>Delivery Partners</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/customers.php"
            class="nav-link <?= $currentPage === 'customers.php' ? 'active' : '' ?>">
            <i class="bi bi-people"></i>
            <span>Customers</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/products.php" class="nav-link <?= $currentPage === 'products.php' ? 'active' : '' ?>">
            <i class="bi bi-box-seam-fill"></i><span>Products</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/storefront.php" class="nav-link <?= $currentPage === 'storefront.php' ? 'active' : '' ?>">
            <i class="bi bi-shop-window"></i>
            <span>Storefront</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/categories.php" class="nav-link <?= $currentPage === 'categories.php' ? 'active' : '' ?>">
            <i class="bi bi-tags-fill"></i><span>Categories</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/orders.php" class="nav-link <?= in_array($currentPage, ['orders.php', 'order_details.php']) ? 'active' : '' ?>">
            <i class="bi bi-cart-check-fill"></i><span>Orders</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/payments.php" class="nav-link <?= $currentPage === 'payments.php' ? 'active' : '' ?>">
            <i class="bi bi-credit-card-fill"></i><span>Payments</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/deliveries.php" class="nav-link <?= in_array($currentPage, ['deliveries.php', 'delivery_details.php']) ? 'active' : '' ?>">
            <i class="bi bi-truck"></i><span>Deliveries</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/reports.php" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-fill"></i><span>Reports</span>
        </a>
    </div>

    <div class="sidebar-footer">

        <div class="dropdown sidebar-accent-picker">
            <button
                type="button"
                class="nav-link"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-palette2"></i>
                <span>Accent</span>
                <small class="ms-auto" data-accent-label>Sapphire</small>
            </button>

            <div class="dropdown-menu sidebar-accent-menu fv-theme-menu">
                <div class="fv-theme-title">System accent</div>

                <button type="button" class="fv-theme-option" data-accent-option="sapphire">
                    <span class="fv-theme-swatch swatch-sapphire"></span>
                    <span>Sapphire Blue</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>

                <button type="button" class="fv-theme-option" data-accent-option="teal">
                    <span class="fv-theme-swatch swatch-teal"></span>
                    <span>Teal</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>

                <button type="button" class="fv-theme-option" data-accent-option="coral">
                    <span class="fv-theme-swatch swatch-coral"></span>
                    <span>Coral</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>

                <button type="button" class="fv-theme-option" data-accent-option="slate">
                    <span class="fv-theme-swatch swatch-slate"></span>
                    <span>Slate Grey</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>
            </div>
        </div>

        <button type="button" class="nav-link theme-toggle" data-theme-toggle>
            <i class="bi bi-moon-stars-fill theme-icon"></i>
            <span class="theme-label">Dark Theme</span>
            <small class="theme-state ms-auto">Off</small>
        </button>
        <a href="<?= BASE_URL ?>/admin/profile.php" class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>">
            <i class="bi bi-person-circle"></i><span>My Profile</span>
        </a>
        <a href="<?= BASE_URL ?>/logout.php" class="nav-link">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('adminSidebar');
        const toggle = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('sidebarOverlay');
        if (toggle && sidebar && overlay) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            });
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            });
            document.querySelectorAll('.sidebar .nav-link').forEach(link => link.addEventListener('click', () => {
                if (window.innerWidth <= 900) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                }
            }));
        }
    });
</script>
<script src="../assets/js/theme.js"></script>
