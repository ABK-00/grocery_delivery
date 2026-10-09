<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');
requireCompanyAccess();

$companyId = currentCompanyId();
$userName = $_SESSION['name'] ?? 'Admin';

function dashboardCount(
    PDO $conn,
    string $sql,
    array $params = []
): int {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function dashboardAmount(
    PDO $conn,
    string $sql,
    array $params = []
): float {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return (float) $stmt->fetchColumn();
}

$totalStaff = dashboardCount(
    $conn,
    "
        SELECT COUNT(*)
        FROM users
        WHERE company_id = ?
          AND role = 'staff'
    ",
    [$companyId]
);

$totalDeliveryPartners = dashboardCount(
    $conn,
    "
        SELECT COUNT(*)
        FROM users
        WHERE company_id = ?
          AND role = 'delivery_partner'
    ",
    [$companyId]
);

$totalProducts = dashboardCount(
    $conn,
    "
        SELECT COUNT(*)
        FROM products
        WHERE company_id = ?
    ",
    [$companyId]
);

$totalOrders = dashboardCount(
    $conn,
    "
        SELECT COUNT(*)
        FROM orders
        WHERE company_id = ?
    ",
    [$companyId]
);

$totalCustomers = dashboardCount(
    $conn,
    "
        SELECT COUNT(DISTINCT user_id)
        FROM orders
        WHERE company_id = ?
    ",
    [$companyId]
);

$activeOrders = dashboardCount(
    $conn,
    "
        SELECT COUNT(*)
        FROM orders
        WHERE company_id = ?
          AND status NOT IN ('delivered', 'cancelled')
    ",
    [$companyId]
);

$totalRevenue = dashboardAmount(
    $conn,
    "
        SELECT COALESCE(SUM(total_amount), 0)
        FROM orders
        WHERE company_id = ?
          AND status = 'delivered'
    ",
    [$companyId]
);

$todayRevenue = dashboardAmount(
    $conn,
    "
        SELECT COALESCE(SUM(total_amount), 0)
        FROM orders
        WHERE company_id = ?
          AND status = 'delivered'
          AND DATE(created_at) = CURDATE()
    ",
    [$companyId]
);

$recentStmt = $conn->prepare("
    SELECT
        o.id,
        o.order_number,
        o.total_amount,
        o.status,
        o.payment_status,
        o.created_at,
        u.name AS customer_name
    FROM orders o
    INNER JOIN users u
        ON u.id = o.user_id
    WHERE o.company_id = ?
    ORDER BY o.created_at DESC
    LIMIT 6
");

$recentStmt->execute([$companyId]);
$recentOrders = $recentStmt->fetchAll();

$companyStmt = $conn->prepare("
    SELECT
        c.company_name,
        c.company_code,
        c.storefront_slug,
        c.subscription_plan,
        c.trial_ends_at,
        c.subscription_ends_at,
        COALESCE(cs.display_name, c.company_name) AS display_name,
        COALESCE(cs.store_status, 'closed') AS store_status
    FROM companies c
    LEFT JOIN company_storefronts cs
        ON cs.company_id = c.id
    WHERE c.id = ?
    LIMIT 1
");

$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch() ?: [];

$storeName =
    $company['display_name']
    ?? $company['company_name']
    ?? 'Your Store';

$storeSlug =
    $company['storefront_slug']
    ?? '';

$storeOpen =
    ($company['store_status'] ?? 'closed')
    === 'open';

$subscriptionPlan =
    $company['subscription_plan']
    ?? 'trial';

$subscriptionEnd =
    $subscriptionPlan === 'trial'
    ? ($company['trial_ends_at'] ?? null)
    : ($company['subscription_ends_at'] ?? null);

$adminInitials = '';

foreach (
    preg_split('/\s+/', trim($userName))
    as $part
) {
    if ($part !== '') {
        $adminInitials .= strtoupper(
            mb_substr($part, 0, 1)
        );
    }

    if (mb_strlen($adminInitials) >= 2) {
        break;
    }
}

if ($adminInitials === '') {
    $adminInitials = 'AD';
}

function dashboardStatusClass(string $status): string
{
    $allowed = [
        'pending',
        'confirmed',
        'preparing',
        'ready',
        'out_for_delivery',
        'delivered',
        'cancelled'
    ];

    return in_array($status, $allowed, true)
        ? $status
        : 'pending';
}

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Admin Dashboard | Grocery Delivery
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="../assets/css/admin.css"
        rel="stylesheet"
    >

    <link
        href="../assets/css/admin-farvist.css"
        rel="stylesheet"
    >

</head>

<body class="admin-farvist">

<?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>

<main class="main-content">

    <div class="fv-topbar">

        <div class="fv-eyebrow mb-0">
            <i class="bi bi-stars"></i>
            Company Portal
        </div>

        <div class="fv-topbar-spacer"></div>

        <a
            href="profile.php"
            class="fv-icon-btn"
            title="Profile"
        >
            <i class="bi bi-person"></i>
        </a>

        <div class="dropdown fv-theme-picker">

            <button
                type="button"
                class="fv-icon-btn"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                title="Choose system theme"
            >
                <i class="bi bi-palette2"></i>
            </button>

            <div class="dropdown-menu dropdown-menu-end fv-theme-menu">

                <div class="fv-theme-title">
                    Accent theme
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
            class="fv-icon-btn"
            data-theme-toggle
            title="Toggle theme"
        >
            <i class="bi bi-moon-stars-fill theme-icon"></i>
        </button>

        <a
            href="profile.php"
            class="fv-avatar"
            title="<?= e($userName) ?>"
        >
            <?= e($adminInitials) ?>
        </a>

    </div>


    <div class="fv-heading-row">

        <div>

            <div class="fv-eyebrow">
                <i class="bi bi-grid-1x2-fill"></i>
                Overview
            </div>

            <h1 class="fv-page-title">
                Welcome back, <?= e($userName) ?>.
            </h1>

            <p class="fv-page-subtitle">
                Here's a live snapshot of <?= e($storeName) ?>.
            </p>

        </div>

        <div class="d-flex flex-wrap gap-2">

            <?php if ($storeSlug !== ''): ?>

                <a
                    href="/somame_ent/store.php?store=<?= urlencode($storeSlug) ?>"
                    target="_blank"
                    class="fv-glass-btn"
                >
                    <i class="bi bi-box-arrow-up-right"></i>
                    View Store
                </a>

            <?php endif; ?>

            <a
                href="products.php"
                class="fv-primary-btn"
            >
                <i class="bi bi-plus-lg"></i>
                Add Product
            </a>

        </div>

    </div>


    <div class="row g-4 mb-4">

        <div class="col-sm-6 col-xl-3">

            <div
                class="fv-card fv-card-glow fv-hover-lift"
                style="--tile-glow:rgba(245,158,11,.18)"
            >

                <div class="fv-card-body">

                    <div class="fv-stat-head">

                        <span class="fv-icon-tile tile-amber">
                            <i class="bi bi-bag-check-fill"></i>
                        </span>

                        <span class="fv-chip chip-amber">
                            <?= $activeOrders ?> active
                        </span>

                    </div>

                    <div class="fv-stat-value">
                        <?= number_format($totalOrders) ?>
                    </div>

                    <div class="fv-stat-label">
                        Total orders
                    </div>

                </div>

            </div>

        </div>


        <div class="col-sm-6 col-xl-3">

            <div
                class="fv-card fv-card-glow fv-hover-lift"
                style="--tile-glow:rgba(52,211,153,.15)"
            >

                <div class="fv-card-body">

                    <div class="fv-stat-head">

                        <span class="fv-icon-tile tile-emerald">
                            <i class="bi bi-cash-stack"></i>
                        </span>

                        <span class="fv-chip chip-success">
                            GH₵<?= number_format($todayRevenue, 2) ?> today
                        </span>

                    </div>

                    <div class="fv-stat-value">
                        GH₵<?= number_format($totalRevenue, 2) ?>
                    </div>

                    <div class="fv-stat-label">
                        Delivered revenue
                    </div>

                </div>

            </div>

        </div>


        <div class="col-sm-6 col-xl-3">

            <div
                class="fv-card fv-card-glow fv-hover-lift"
                style="--tile-glow:rgba(56,189,248,.15)"
            >

                <div class="fv-card-body">

                    <div class="fv-stat-head">

                        <span class="fv-icon-tile tile-sky">
                            <i class="bi bi-box-seam-fill"></i>
                        </span>

                        <span class="fv-chip chip-sky">
                            Inventory
                        </span>

                    </div>

                    <div class="fv-stat-value">
                        <?= number_format($totalProducts) ?>
                    </div>

                    <div class="fv-stat-label">
                        Products
                    </div>

                </div>

            </div>

        </div>


        <div class="col-sm-6 col-xl-3">

            <div
                class="fv-card fv-card-glow fv-hover-lift"
                style="--tile-glow:rgba(251,113,133,.14)"
            >

                <div class="fv-card-body">

                    <div class="fv-stat-head">

                        <span class="fv-icon-tile tile-rose">
                            <i class="bi bi-people-fill"></i>
                        </span>

                        <span class="fv-chip chip-rose">
                            Customers
                        </span>

                    </div>

                    <div class="fv-stat-value">
                        <?= number_format($totalCustomers) ?>
                    </div>

                    <div class="fv-stat-label">
                        Customers served
                    </div>

                </div>

            </div>

        </div>

    </div>


    <div class="row g-4 mb-4">

        <div class="col-xl-8">

            <section class="fv-card h-100">

                <div class="fv-card-header">

                    <div>

                        <h2 class="fv-section-title">
                            Recent orders
                        </h2>

                        <p class="fv-section-subtitle">
                            Latest customer activity for your store.
                        </p>

                    </div>

                    <a
                        href="orders.php"
                        class="fv-glass-btn"
                    >
                        View all
                        <i class="bi bi-arrow-right"></i>
                    </a>

                </div>

                <div class="fv-table-wrap">

                    <?php if ($recentOrders): ?>

                        <table class="fv-table">

                            <thead>
                            <tr>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th class="text-end">Amount</th>
                            </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($recentOrders as $order): ?>

                                <tr>

                                    <td>

                                        <a
                                            href="order_details.php?id=<?= (int)$order['id'] ?>"
                                            class="text-decoration-none"
                                        >

                                            <div class="fv-order-number">
                                                <?= e($order['order_number']) ?>
                                            </div>

                                            <div class="fv-customer">
                                                <?= e($order['customer_name']) ?>
                                            </div>

                                        </a>

                                    </td>

                                    <td>

                                        <span
                                            class="fv-status status-<?= e(
                                                dashboardStatusClass(
                                                    $order['status']
                                                )
                                            ) ?>"
                                        >
                                            <?= e(
                                                ucwords(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        $order['status']
                                                    )
                                                )
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <?php
                                        $paymentPaid =
                                            ($order['payment_status'] ?? '')
                                            === 'paid';
                                        ?>

                                        <span
                                            class="fv-chip <?= $paymentPaid
                                                ? 'chip-success'
                                                : 'chip-amber' ?>"
                                        >
                                            <?= $paymentPaid
                                                ? 'Paid'
                                                : 'Pending' ?>
                                        </span>

                                    </td>

                                    <td class="text-end fw-bold">
                                        GH₵<?= number_format(
                                            (float)$order['total_amount'],
                                            2
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    <?php else: ?>

                        <div class="text-center py-5">

                            <i
                                class="bi bi-receipt fs-1"
                                style="color:#6d6e78"
                            ></i>

                            <div class="mt-3 fw-bold">
                                No orders yet
                            </div>

                            <div class="text-muted small mt-1">
                                New customer orders will appear here.
                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </div>


        <div class="col-xl-4">

            <section class="fv-card fv-store-panel h-100">

                <div class="fv-card-body">

                    <div class="d-flex align-items-start justify-content-between gap-3 mb-4">

                        <div>

                            <div class="fv-eyebrow">
                                <i class="bi bi-shop-window"></i>
                                Storefront
                            </div>

                            <div class="fv-store-name">
                                <?= e($storeName) ?>
                            </div>

                        </div>

                        <span class="fv-chip <?= $storeOpen
                            ? 'chip-success'
                            : 'chip-rose' ?>"
                        >
                            <span
                                class="fv-store-status <?= $storeOpen
                                    ? ''
                                    : 'closed' ?>"
                            ></span>

                            <?= $storeOpen
                                ? 'Open'
                                : 'Closed' ?>
                        </span>

                    </div>

                    <div class="mb-4">

                        <div class="text-muted small mb-1">
                            Subscription
                        </div>

                        <div class="fv-plan">
                            <?= e(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $subscriptionPlan
                                    )
                                )
                            ) ?>
                        </div>

                        <?php if ($subscriptionEnd): ?>

                            <div class="text-muted small mt-1">
                                Access until
                                <?= e(
                                    date(
                                        'M j, Y',
                                        strtotime($subscriptionEnd)
                                    )
                                ) ?>
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="row g-3 mb-4">

                        <div class="col-6">

                            <div
                                class="p-3 rounded-4"
                                style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07)"
                            >

                                <div class="text-muted small">
                                    Staff
                                </div>

                                <div class="fs-4 fw-bold mt-1">
                                    <?= number_format($totalStaff) ?>
                                </div>

                            </div>

                        </div>

                        <div class="col-6">

                            <div
                                class="p-3 rounded-4"
                                style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07)"
                            >

                                <div class="text-muted small">
                                    Delivery
                                </div>

                                <div class="fs-4 fw-bold mt-1">
                                    <?= number_format($totalDeliveryPartners) ?>
                                </div>

                            </div>

                        </div>

                    </div>

                    <a
                        href="storefront.php"
                        class="fv-primary-btn w-100"
                    >
                        <i class="bi bi-sliders"></i>
                        Manage Storefront
                    </a>

                </div>

            </section>

        </div>

    </div>


    <div class="row g-4">

        <div class="col-12">

            <section class="fv-card">

                <div class="fv-card-header">

                    <div>

                        <h2 class="fv-section-title">
                            Quick actions
                        </h2>

                        <p class="fv-section-subtitle">
                            Common tasks for running your store.
                        </p>

                    </div>

                </div>

                <div class="fv-card-body pt-3">

                    <div class="row g-3">

                        <div class="col-md-6 col-xl-3">

                            <a
                                href="products.php"
                                class="fv-action h-100"
                            >

                                <span class="fv-action-icon">
                                    <i class="bi bi-box-seam"></i>
                                </span>

                                <span>
                                    <span class="fv-action-title d-block">
                                        Products
                                    </span>

                                    <span class="fv-action-copy d-block">
                                        Add or update inventory.
                                    </span>
                                </span>

                            </a>

                        </div>


                        <div class="col-md-6 col-xl-3">

                            <a
                                href="staff.php"
                                class="fv-action h-100"
                            >

                                <span class="fv-action-icon">
                                    <i class="bi bi-person-plus"></i>
                                </span>

                                <span>
                                    <span class="fv-action-title d-block">
                                        Staff
                                    </span>

                                    <span class="fv-action-copy d-block">
                                        Manage staff accounts.
                                    </span>
                                </span>

                            </a>

                        </div>


                        <div class="col-md-6 col-xl-3">

                            <a
                                href="delivery_partners.php"
                                class="fv-action h-100"
                            >

                                <span class="fv-action-icon">
                                    <i class="bi bi-bicycle"></i>
                                </span>

                                <span>
                                    <span class="fv-action-title d-block">
                                        Delivery team
                                    </span>

                                    <span class="fv-action-copy d-block">
                                        Manage delivery partners.
                                    </span>
                                </span>

                            </a>

                        </div>


                        <div class="col-md-6 col-xl-3">

                            <a
                                href="reports.php"
                                class="fv-action h-100"
                            >

                                <span class="fv-action-icon">
                                    <i class="bi bi-bar-chart"></i>
                                </span>

                                <span>
                                    <span class="fv-action-title d-block">
                                        Reports
                                    </span>

                                    <span class="fv-action-copy d-block">
                                        Review store performance.
                                    </span>
                                </span>

                            </a>

                        </div>

                    </div>

                </div>

            </section>

        </div>

    </div>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>
