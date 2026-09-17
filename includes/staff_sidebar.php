<?php

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<style>

.staff-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100vh;
    background: #0f172a;
    color: white;
    z-index: 1000;
    display: flex;
    flex-direction: column;
}

.staff-brand {
    padding: 24px 20px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.staff-brand a {
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 12px;
}

.brand-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    background: #16a34a;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.brand-name {
    font-size: 18px;
    font-weight: 700;
}

.brand-name span {
    color: #4ade80;
}

.staff-nav {
    padding: 20px 12px;
    flex: 1;
}

.staff-section {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .08em;
    padding: 0 12px 10px;
}

.staff-nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #cbd5e1;
    text-decoration: none;
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 5px;
    transition: .2s;
}

.staff-nav-link i {
    font-size: 18px;
}

.staff-nav-link:hover {
    background: rgba(255,255,255,.06);
    color: white;
}

.staff-nav-link.active {
    background: #166534;
    color: white;
}

.staff-footer {
    padding: 12px;
    border-top: 1px solid rgba(255,255,255,.08);
}

.staff-logout {
    color: #fca5a5;
}

.staff-logout:hover {
    color: #fecaca;
    background: rgba(239,68,68,.1);
}

.staff-main {
    margin-left: 250px;
}

@media(max-width: 768px) {

    .staff-sidebar {
        transform: translateX(-100%);
        transition: .25s;
    }

    .staff-sidebar.show {
        transform: translateX(0);
    }

    .staff-main {
        margin-left: 0;
    }

    .staff-menu-btn {
        display: flex !important;
    }

}

.staff-menu-btn {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 1100;
    width: 42px;
    height: 42px;
    border: none;
    border-radius: 10px;
    background: #0f172a;
    color: white;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.staff-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 999;
}

.staff-overlay.show {
    display: block;
}

</style>


<button
    class="staff-menu-btn"
    id="staffMenuBtn"
    type="button"
>
    <i class="bi bi-list"></i>
</button>


<aside
    class="staff-sidebar"
    id="staffSidebar"
>

    <div class="staff-brand">

        <a href="dashboard.php">

            <div class="brand-icon">
                <i class="bi bi-cart3"></i>
            </div>

            <div class="brand-name">
                Grocery<span>Delivery</span>
            </div>

        </a>

    </div>


    <div class="staff-nav">

        <div class="staff-section">
            Staff Portal
        </div>


        <a
            href="dashboard.php"
            class="staff-nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-grid-1x2-fill"></i>

            <span>Dashboard</span>

        </a>


        <a
            href="orders.php"
            class="staff-nav-link <?= in_array($currentPage, ['orders.php', 'order_details.php']) ? 'active' : '' ?>"
        >

            <i class="bi bi-cart-check-fill"></i>

            <span>Orders</span>

        </a>


        <a
            href="../products.php"
            class="staff-nav-link"
        >

            <i class="bi bi-box-seam-fill"></i>

            <span>Products</span>

        </a>


        <a
            href="../categories.php"
            class="staff-nav-link"
        >

            <i class="bi bi-tags-fill"></i>

            <span>Categories</span>

        </a>

    </div>


    <div class="staff-footer">

        <a
            href="../logout.php"
            class="staff-nav-link staff-logout"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>Logout</span>

        </a>

    </div>

</aside>


<div
    class="staff-overlay"
    id="staffOverlay"
></div>


<script>
document.addEventListener("DOMContentLoaded", function () {
    const sidebar = document.getElementById("staffSidebar");
    const toggleBtns = [
        document.getElementById("sidebarToggle"),
        document.getElementById("staffMenuBtn")
    ];
    const overlay = document.getElementById("staffOverlay") || document.getElementById("sidebarOverlay");

    toggleBtns.forEach(function(btn) {
        if (btn && sidebar && overlay) {
            btn.addEventListener("click", function () {
                sidebar.classList.toggle("show");
                overlay.classList.toggle("show");
            });
        }
    });

    if (overlay && sidebar) {
        overlay.addEventListener("click", function () {
            sidebar.classList.remove("show");
            overlay.classList.remove("show");
        });
    }
});
</script>