<?php
$currentPage = basename($_SERVER['PHP_SELF']);

$sidebarUserName =
    $_SESSION['user_name']
    ?? $_SESSION['name']
    ?? 'Administrator';

$sidebarUserEmail = '';

if (
    isset($conn)
    && $conn instanceof PDO
    && !empty($_SESSION['user_id'])
) {
    try {
        $sidebarUserStmt = $conn->prepare("
            SELECT name, email
            FROM users
            WHERE id = ?
              AND role = 'admin'
            LIMIT 1
        ");

        $sidebarUserStmt->execute([
            (int) $_SESSION['user_id']
        ]);

        $sidebarUser = $sidebarUserStmt->fetch();

        if ($sidebarUser) {
            $sidebarUserName =
                $sidebarUser['name']
                ?: $sidebarUserName;

            $sidebarUserEmail =
                $sidebarUser['email']
                ?? '';
        }
    } catch (Throwable $e) {
        // Sidebar keeps working even if profile lookup fails.
    }
}

$sidebarInitials = '';

foreach (
    preg_split('/\s+/', trim($sidebarUserName))
    as $part
) {
    if ($part === '') {
        continue;
    }

    $sidebarInitials .= strtoupper(
        function_exists('mb_substr')
            ? mb_substr($part, 0, 1)
            : substr($part, 0, 1)
    );

    if (strlen($sidebarInitials) >= 2) {
        break;
    }
}

if ($sidebarInitials === '') {
    $sidebarInitials = 'AD';
}
?>

<aside
    class="sidebar"
    id="adminSidebar"
    aria-label="Admin navigation"
>
    <div class="sidebar-brand">

        <a href="<?= BASE_URL ?>/admin/dashboard.php">

            <div class="brand-icon">
                <i class="bi bi-cart3"></i>
            </div>

            <div class="brand-text">
                Grocery<span>Delivery</span>
            </div>

        </a>

    </div>


    <div class="sidebar-nav">

        <div class="sidebar-section-title">
            Main Menu
        </div>

        <a
            href="<?= BASE_URL ?>/admin/dashboard.php"
            class="nav-link <?= in_array(
                $currentPage,
                ['dashboard.php', 'index.php'],
                true
            ) ? 'active' : '' ?>"
            title="Dashboard"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/users.php"
            class="nav-link <?= $currentPage === 'users.php'
                ? 'active'
                : '' ?>"
            title="Users"
        >
            <i class="bi bi-people-fill"></i>
            <span>Users</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/staff.php"
            class="nav-link <?= $currentPage === 'staff.php'
                ? 'active'
                : '' ?>"
            title="Staff"
        >
            <i class="bi bi-person-badge-fill"></i>
            <span>Staff</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/delivery_partners.php"
            class="nav-link <?= $currentPage === 'delivery_partners.php'
                ? 'active'
                : '' ?>"
            title="Delivery Partners"
        >
            <i class="bi bi-bicycle"></i>
            <span>Delivery Partners</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/customers.php"
            class="nav-link <?= $currentPage === 'customers.php'
                ? 'active'
                : '' ?>"
            title="Customers"
        >
            <i class="bi bi-people"></i>
            <span>Customers</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/products.php"
            class="nav-link <?= $currentPage === 'products.php'
                ? 'active'
                : '' ?>"
            title="Products"
        >
            <i class="bi bi-box-seam-fill"></i>
            <span>Products</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/storefront.php"
            class="nav-link <?= $currentPage === 'storefront.php'
                ? 'active'
                : '' ?>"
            title="Storefront"
        >
            <i class="bi bi-shop-window"></i>
            <span>Storefront</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/categories.php"
            class="nav-link <?= $currentPage === 'categories.php'
                ? 'active'
                : '' ?>"
            title="Categories"
        >
            <i class="bi bi-tags-fill"></i>
            <span>Categories</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/orders.php"
            class="nav-link <?= in_array(
                $currentPage,
                ['orders.php', 'order_details.php'],
                true
            ) ? 'active' : '' ?>"
            title="Orders"
        >
            <i class="bi bi-cart-check-fill"></i>
            <span>Orders</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/payments.php"
            class="nav-link <?= $currentPage === 'payments.php'
                ? 'active'
                : '' ?>"
            title="Payments"
        >
            <i class="bi bi-credit-card-fill"></i>
            <span>Payments</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/deliveries.php"
            class="nav-link <?= in_array(
                $currentPage,
                ['deliveries.php', 'delivery_details.php'],
                true
            ) ? 'active' : '' ?>"
            title="Deliveries"
        >
            <i class="bi bi-truck"></i>
            <span>Deliveries</span>
        </a>

        <a
            href="<?= BASE_URL ?>/admin/reports.php"
            class="nav-link <?= $currentPage === 'reports.php'
                ? 'active'
                : '' ?>"
            title="Reports"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

    </div>


    <div class="sidebar-footer">

        <div class="dropdown sidebar-accent-picker">

            <button
                type="button"
                class="nav-link"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                title="Accent theme"
            >
                <i class="bi bi-palette2"></i>
                <span>Accent</span>
                <small
                    class="ms-auto sidebar-meta"
                    data-accent-label
                >
                    Sapphire
                </small>
            </button>

            <div class="dropdown-menu sidebar-accent-menu fv-theme-menu">

                <div class="fv-theme-title">
                    System accent
                </div>

                <button
                    type="button"
                    class="fv-theme-option"
                    data-accent-option="sapphire"
                >
                    <span class="fv-theme-swatch swatch-sapphire"></span>
                    <span>Sapphire Blue</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>

                <button
                    type="button"
                    class="fv-theme-option"
                    data-accent-option="teal"
                >
                    <span class="fv-theme-swatch swatch-teal"></span>
                    <span>Teal</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>

                <button
                    type="button"
                    class="fv-theme-option"
                    data-accent-option="coral"
                >
                    <span class="fv-theme-swatch swatch-coral"></span>
                    <span>Coral</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>

                <button
                    type="button"
                    class="fv-theme-option"
                    data-accent-option="slate"
                >
                    <span class="fv-theme-swatch swatch-slate"></span>
                    <span>Slate Grey</span>
                    <i class="bi bi-check2 fv-theme-check"></i>
                </button>

            </div>

        </div>


        <button
            type="button"
            class="nav-link theme-toggle"
            data-theme-toggle
            title="Toggle light and dark mode"
        >
            <i class="bi bi-moon-stars-fill theme-icon"></i>
            <span class="theme-label">Dark Theme</span>
            <small class="theme-state ms-auto sidebar-meta">
                Off
            </small>
        </button>


        <a
            href="<?= BASE_URL ?>/admin/profile.php"
            class="nav-link <?= $currentPage === 'profile.php'
                ? 'active'
                : '' ?>"
            title="My Profile"
        >
            <i class="bi bi-person-circle"></i>
            <span>My Profile</span>
        </a>


        <a
            href="<?= BASE_URL ?>/logout.php"
            class="nav-link"
            title="Logout"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>


        <div class="sidebar-profile">

            <a
                href="<?= BASE_URL ?>/admin/profile.php"
                class="sidebar-profile-link"
                title="Open profile"
            >
                <div class="sidebar-profile-avatar">
                    <?= htmlspecialchars($sidebarInitials) ?>
                </div>

                <div class="sidebar-profile-copy">
                    <strong>
                        <?= htmlspecialchars($sidebarUserName) ?>
                    </strong>

                    <?php if ($sidebarUserEmail !== ''): ?>
                        <small>
                            <?= htmlspecialchars($sidebarUserEmail) ?>
                        </small>
                    <?php endif; ?>
                </div>
            </a>

            <button
                type="button"
                class="sidebar-collapse-button"
                id="sidebarCollapseButton"
                aria-label="Collapse sidebar"
                aria-expanded="true"
                title="Collapse sidebar"
            >
                <i class="bi bi-chevron-double-left"></i>
            </button>

        </div>

    </div>

</aside>


<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {

        const sidebar =
            document.getElementById('adminSidebar');

        const mobileToggle =
            document.getElementById('sidebarToggle');

        const overlay =
            document.getElementById('sidebarOverlay');

        const collapseButton =
            document.getElementById(
                'sidebarCollapseButton'
            );

        const storageKey =
            'gd-admin-sidebar-collapsed';

        function setCollapsed(collapsed) {

            if (!sidebar) {
                return;
            }

            sidebar.classList.toggle(
                'is-collapsed',
                collapsed
            );

            document.body.classList.toggle(
                'admin-sidebar-collapsed',
                collapsed
            );

            if (collapseButton) {

                collapseButton.setAttribute(
                    'aria-expanded',
                    collapsed
                        ? 'false'
                        : 'true'
                );

                collapseButton.setAttribute(
                    'aria-label',
                    collapsed
                        ? 'Expand sidebar'
                        : 'Collapse sidebar'
                );

                collapseButton.setAttribute(
                    'title',
                    collapsed
                        ? 'Expand sidebar'
                        : 'Collapse sidebar'
                );

                const icon =
                    collapseButton.querySelector('i');

                if (icon) {
                    icon.className =
                        collapsed
                        ? 'bi bi-chevron-double-right'
                        : 'bi bi-chevron-double-left';
                }
            }

            try {
                localStorage.setItem(
                    storageKey,
                    collapsed
                        ? '1'
                        : '0'
                );
            } catch (error) {
                // Collapse still works for the current page.
            }
        }

        let initiallyCollapsed = false;

        try {
            initiallyCollapsed =
                localStorage.getItem(storageKey)
                === '1';
        } catch (error) {
            initiallyCollapsed = false;
        }

        if (window.innerWidth > 900) {
            setCollapsed(initiallyCollapsed);
        }

        if (collapseButton) {

            collapseButton.addEventListener(
                'click',
                function () {

                    if (window.innerWidth <= 900) {
                        return;
                    }

                    setCollapsed(
                        !sidebar.classList.contains(
                            'is-collapsed'
                        )
                    );
                }
            );
        }

        if (
            mobileToggle
            && sidebar
            && overlay
        ) {

            mobileToggle.addEventListener(
                'click',
                function () {
                    sidebar.classList.toggle('show');
                    overlay.classList.toggle('show');
                }
            );

            overlay.addEventListener(
                'click',
                function () {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                }
            );

            document
                .querySelectorAll(
                    '.sidebar .nav-link'
                )
                .forEach(function (link) {

                    link.addEventListener(
                        'click',
                        function () {

                            if (window.innerWidth <= 900) {
                                sidebar.classList.remove('show');
                                overlay.classList.remove('show');
                            }
                        }
                    );
                });
        }

        window.addEventListener(
            'resize',
            function () {

                if (window.innerWidth <= 900) {
                    document.body.classList.remove(
                        'admin-sidebar-collapsed'
                    );

                    sidebar.classList.remove(
                        'is-collapsed'
                    );
                } else {
                    let collapsed = false;

                    try {
                        collapsed =
                            localStorage.getItem(
                                storageKey
                            ) === '1';
                    } catch (error) {
                        collapsed = false;
                    }

                    setCollapsed(collapsed);
                }
            }
        );
    }
);
</script>

<script src="<?= BASE_URL ?>/assets/js/theme.js?v=20261009-2"></script>
