<?php

$currentPage = basename($_SERVER['PHP_SELF']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customerName = $_SESSION['user_name'] ?? 'Customer';

?>

<style>
    .customer-sidebar {
        width: 250px;
        height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        background: #101827;
        color: #fff;
        z-index: 1000;
        overflow-y: auto;
        transition: all .3s ease;
    }

    .customer-brand {
        height: 75px;
        display: flex;
        align-items: center;
        padding: 0 22px;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .customer-brand-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: #198754;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 11px;
        font-size: 19px;
    }

    .customer-brand h5 {
        margin: 0;
        font-weight: 700;
        font-size: 17px;
    }

    .customer-brand small {
        color: #94a3b8;
        font-size: 11px;
    }

    .customer-profile {
        padding: 20px;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .customer-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: #198754;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
        flex-shrink: 0;
    }

    .customer-profile-name {
        font-weight: 600;
        font-size: 14px;
    }

    .customer-profile-role {
        font-size: 11px;
        color: #94a3b8;
    }

    .customer-nav {
        padding: 18px 12px;
    }

    .customer-nav-title {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #64748b;
        padding: 0 12px 8px;
        font-weight: 700;
    }

    .customer-nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #cbd5e1;
        text-decoration: none;
        padding: 11px 13px;
        border-radius: 9px;
        margin-bottom: 4px;
        font-size: 14px;
        transition: .2s;
    }

    .customer-nav a:hover {
        background: rgba(255,255,255,.06);
        color: #fff;
    }

    .customer-nav a.active {
        background: #198754;
        color: #fff;
        font-weight: 600;
    }

    .customer-nav a i {
        width: 20px;
        text-align: center;
        font-size: 16px;
    }

    .customer-logout {
        margin-top: 18px;
        padding-top: 15px;
        border-top: 1px solid rgba(255,255,255,.08);
    }

    .customer-menu-btn {
        display: none;
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        width: 42px;
        height: 42px;
        border: 0;
        border-radius: 10px;
        background: #198754;
        color: white;
        font-size: 20px;
    }

    @media(max-width: 768px) {

        .customer-sidebar {
            transform: translateX(-100%);
        }

        .customer-sidebar.show {
            transform: translateX(0);
        }

        .customer-menu-btn {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }
</style>

<button class="customer-menu-btn" id="customerMenuBtn">
    <i class="bi bi-list"></i>
</button>

<aside class="customer-sidebar" id="customerSidebar">

    <div class="customer-brand">

        <div class="customer-brand-icon">
            <i class="bi bi-basket2-fill"></i>
        </div>

        <div>
            <h5>GroceryDelivery</h5>
            <small>Customer Portal</small>
        </div>

    </div>

    <div class="customer-profile d-flex align-items-center">

        <div class="customer-avatar">
            <?= strtoupper(substr($customerName, 0, 1)) ?>
        </div>

        <div class="ms-3">
            <div class="customer-profile-name">
                <?= htmlspecialchars($customerName) ?>
            </div>

            <div class="customer-profile-role">
                Shopper / Customer
            </div>
        </div>

    </div>

    <nav class="customer-nav">

        <div class="customer-nav-title">
            Main Menu
        </div>

        <a
            href="dashboard.php"
            class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            Dashboard
        </a>

        <a
            href="../products.php"
            class="<?= in_array($currentPage, ['products.php', 'product.php']) ? 'active' : '' ?>"
        >
            <i class="bi bi-shop"></i>
            Shop Products
        </a>

        <a
            href="../cart.php"
        >
            <i class="bi bi-cart3"></i>
            My Cart
        </a>

        <a
            href="../orders.php"
            class="<?= in_array($currentPage, ['orders.php', 'order_details.php']) ? 'active' : '' ?>"
        >
            <i class="bi bi-bag-check"></i>
            My Orders
        </a>

        <a
            href="track_order.php"
            class="<?= $currentPage === 'track_order.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-geo-alt-fill"></i>
            Track Delivery
        </a>

        <div class="customer-nav-title mt-4">
            Account
        </div>

        <a
            href="profile.php"
            class="<?= $currentPage === 'profile.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-person-circle"></i>
            My Profile
        </a>

        <div class="customer-logout">

            <a href="../logout.php">
                <i class="bi bi-box-arrow-right"></i>
                Logout
            </a>

        </div>

    </nav>

</aside>

<script>
    const customerMenuBtn =
        document.getElementById('customerMenuBtn');

    const customerSidebar =
        document.getElementById('customerSidebar');

    if (customerMenuBtn) {
        customerMenuBtn.addEventListener('click', () => {
            customerSidebar.classList.toggle('show');
        });
    }
</script>
