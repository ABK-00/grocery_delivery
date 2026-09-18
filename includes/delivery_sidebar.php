<?php

$currentPage = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['user_name'])) {
    $_SESSION['user_name'] = 'Delivery Partner';
}

?>

<style>
    .delivery-sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 250px;
        height: 100vh;
        background: #101827;
        color: white;
        z-index: 1000;
        overflow-y: auto;
    }

    .delivery-brand {
        padding: 25px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, .08);
    }

    .delivery-brand h4 {
        margin: 0;
        font-weight: 700;
    }

    .delivery-profile {
        padding: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, .08);
    }

    .delivery-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: #198754;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }

    .delivery-nav {
        padding: 20px 12px;
    }

    .delivery-nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 15px;
        color: #adb5bd;
        text-decoration: none;
        border-radius: 10px;
        margin-bottom: 5px;
        transition: .2s;
    }

    .delivery-nav a:hover,
    .delivery-nav a.active {
        background: #198754;
        color: white;
    }

    .delivery-nav i {
        font-size: 18px;
    }

    .delivery-logout {
        margin-top: 30px;
    }

    @media(max-width: 991px) {

        .delivery-sidebar {
            transform: translateX(-100%);
            transition: .3s;
        }

        .delivery-sidebar.show {
            transform: translateX(0);
        }

    }
</style>

<div class="delivery-sidebar" id="deliverySidebar">

    <div class="delivery-brand">

        <h4>
            <i class="bi bi-basket2-fill me-2"></i>
            GroceryDelivery
        </h4>

        <small class="text-secondary">
            Delivery Partner
        </small>

    </div>


    <div class="delivery-profile">

        <div class="d-flex align-items-center gap-3">

            <div class="delivery-avatar">

                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>

            </div>

            <div>

                <div class="fw-bold">
                    <?= e($_SESSION['user_name']) ?>
                </div>

                <small class="text-secondary">
                    Delivery Partner
                </small>

            </div>

        </div>

    </div>


    <div class="delivery-nav">

        <a
            href="dashboard.php"
            class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-grid"></i>
            Dashboard
        </a>


        <a
            href="orders.php"
            class="<?= in_array($currentPage, ['orders.php', 'order_details.php']) ? 'active' : '' ?>">
            <i class="bi bi-box-seam"></i>
            My Deliveries
        </a>


        <a href="availability.php">

            <i class="bi bi-toggle-on"></i>
            Availability

        </a>


        <a href="profile.php">

            <i class="bi bi-person"></i>
            My Profile

        </a>


        <a href="../customer/track_order.php">

            <i class="bi bi-geo-alt"></i>
            Tracking

        </a>


        <div class="delivery-logout">

            <a href="../logout.php">

                <i class="bi bi-box-arrow-right"></i>
                Logout

            </a>

        </div>

    </div>

</div>

<!-- MOBILE OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const sidebar = document.getElementById("deliverySidebar") || document.getElementById("adminSidebar");
        const toggleBtns = [
            document.getElementById("sidebarToggle"),
            document.getElementById("deliveryMenuBtn")
        ];
        const overlay = document.getElementById("sidebarOverlay");

        toggleBtns.forEach(function(btn) {
            if (btn && sidebar && overlay) {
                btn.addEventListener("click", function() {
                    sidebar.classList.toggle("show");
                    overlay.classList.toggle("show");
                });
            }
        });

        if (overlay && sidebar) {
            overlay.addEventListener("click", function() {
                sidebar.classList.remove("show");
                overlay.classList.remove("show");
            });
        }

        document.querySelectorAll(".delivery-sidebar a").forEach(function(link) {
            link.addEventListener("click", function() {
                if (window.innerWidth <= 991 && sidebar && overlay) {
                    sidebar.classList.remove("show");
                    overlay.classList.remove("show");
                }
            });
        });
    });
</script>