<?php

$currentPage = basename($_SERVER['PHP_SELF']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customerName = $_SESSION['user_name'] ?? 'Customer';

?>

<!-- =====================================================
     CUSTOMER SIDEBAR
     Uses the same .sidebar / .nav-link classes as admin
     so admin.css handles collapse, overlay, responsive.
     ===================================================== -->

<aside class="sidebar" id="customerSidebar">

    <!-- BRAND -->
    <div class="sidebar-brand">
        <a href="dashboard.php">
            <div class="brand-icon">
                <i class="bi bi-basket2-fill"></i>
            </div>
            <div class="brand-text">
                Grocery<span>Delivery</span>
            </div>
        </a>
    </div>

    <!-- PROFILE CHIP -->
    <div style="
        padding: 14px 16px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        display: flex;
        align-items: center;
        gap: 11px;
    ">
        <div style="
            width: 38px; height: 38px; min-width: 38px;
            border-radius: 50%;
            background: #22c55e;
            color: #111827;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; font-weight: 700;
        ">
            <?= strtoupper(substr($customerName, 0, 1)) ?>
        </div>
        <div style="min-width:0; overflow:hidden;">
            <div style="font-weight:600; font-size:13px; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                <?= htmlspecialchars($customerName) ?>
            </div>
            <div style="font-size:11px; color:#6b7280;">
                Customer Portal
            </div>
        </div>
    </div>

    <!-- NAVIGATION -->
    <div class="sidebar-nav">

        <div class="sidebar-section-title">Main Menu</div>

        <a href="dashboard.php"
           class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="../products.php"
           class="nav-link <?= in_array($currentPage, ['products.php', 'product.php']) ? 'active' : '' ?>">
            <i class="bi bi-shop"></i>
            <span>Shop Products</span>
        </a>

        <a href="../cart.php"
           class="nav-link <?= $currentPage === 'cart.php' ? 'active' : '' ?>">
            <i class="bi bi-cart3"></i>
            <span>My Cart</span>
        </a>

        <a href="../orders.php"
           class="nav-link <?= in_array($currentPage, ['orders.php', 'order_details.php']) ? 'active' : '' ?>">
            <i class="bi bi-bag-check"></i>
            <span>My Orders</span>
        </a>

        <a href="track_order.php"
           class="nav-link <?= $currentPage === 'track_order.php' ? 'active' : '' ?>">
            <i class="bi bi-geo-alt-fill"></i>
            <span>Track Delivery</span>
        </a>

        <div class="sidebar-section-title" style="margin-top:12px;">Account</div>

        <a href="profile.php"
           class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>">
            <i class="bi bi-person-circle"></i>
            <span>My Profile</span>
        </a>

    </div>

    <!-- FOOTER / LOGOUT -->
    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>

<!-- Mobile overlay — same id/class used by admin.css -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    var sidebar  = document.getElementById("customerSidebar");
    var overlay  = document.getElementById("sidebarOverlay");

    /* Support both the in-topbar toggle (#sidebarToggle) and any
       legacy mobile button that may still exist on the page */
    var toggleIds = ["sidebarToggle", "customerMenuBtn"];

    toggleIds.forEach(function (id) {
        var btn = document.getElementById(id);
        if (btn && sidebar) {
            btn.addEventListener("click", function () {
                sidebar.classList.toggle("show");
                if (overlay) { overlay.classList.toggle("show"); }
            });
        }
    });

    if (overlay && sidebar) {
        overlay.addEventListener("click", function () {
            sidebar.classList.remove("show");
            overlay.classList.remove("show");
        });
    }

    /* Auto-close sidebar on nav-link click for small screens */
    sidebar && sidebar.querySelectorAll(".nav-link").forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 900) {
                sidebar.classList.remove("show");
                if (overlay) { overlay.classList.remove("show"); }
            }
        });
    });
});
</script>
