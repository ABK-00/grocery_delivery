<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole('staff');

$totalOrders = (int)$conn->query("
    SELECT COUNT(*) FROM orders
")->fetchColumn();

$pendingOrders = (int)$conn->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'pending'
")->fetchColumn();

$preparingOrders = (int)$conn->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status IN ('confirmed', 'preparing')
")->fetchColumn();

$deliveryOrders = (int)$conn->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'out_for_delivery'
")->fetchColumn();

$todayOrders = (int)$conn->query("
    SELECT COUNT(*)
    FROM orders
    WHERE DATE(created_at) = CURDATE()
")->fetchColumn();

$todayRevenue = (float)$conn->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE DATE(created_at) = CURDATE()
      AND status != 'cancelled'
")->fetchColumn();

$stmt = $conn->query("
    SELECT
        o.id,
        o.order_number,
        o.total_amount,
        o.status,
        o.created_at,
        u.name AS customer_name
    FROM orders o
    INNER JOIN users u
        ON u.id = o.user_id
    ORDER BY o.created_at DESC
    LIMIT 8
");

$recentOrders = $stmt->fetchAll();

function dashboardStatus($status)
{
    $map = [
        'pending' => ['Pending', 'bg-warning text-dark'],
        'confirmed' => ['Confirmed', 'bg-primary'],
        'preparing' => ['Preparing', 'bg-info text-dark'],
        'ready' => ['Ready', 'bg-secondary'],
        'out_for_delivery' => ['Out for Delivery', 'bg-dark'],
        'delivered' => ['Delivered', 'bg-success'],
        'cancelled' => ['Cancelled', 'bg-danger']
    ];

    $item = $map[$status] ?? [ucfirst($status), 'bg-secondary'];

    return '<span class="badge ' . $item[1] . '">' .
        e($item[0]) .
        '</span>';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1"
>

<title>Staff Dashboard | GroceryDelivery</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
rel="stylesheet"
>

<style>

body {
    background: #f5f7fa;
    font-family: Arial, sans-serif;
}

.main {
    padding: 30px;
    margin-left: 250px;
}

.welcome {
    background: linear-gradient(135deg, #0f172a, #166534);
    color: white;
    border-radius: 18px;
    padding: 28px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 22px;
    height: 100%;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #dcfce7;
    color: #15803d;
    font-size: 22px;
}

.stat-number {
    font-size: 28px;
    font-weight: 700;
}

.dashboard-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 22px;
}

.table th {
    font-size: 13px;
    color: #64748b;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

.quick-action {
    text-decoration: none;
    color: inherit;
    display: block;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 18px;
    transition: .2s;
}

.quick-action:hover {
    border-color: #16a34a;
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0,0,0,.06);
}

.quick-icon {
    font-size: 25px;
    color: #16a34a;
}

@media(max-width: 768px) {

    .main {
        padding: 15px;
        margin-left: 0px;
    }

}

</style>

</head>

<body>

<?php require_once __DIR__ . "/../includes/staff_sidebar.php"; ?>

<main class="main">

<div class="welcome">

    <div class="d-flex justify-content-between align-items-center">

        <div>

            <div class="text-white-50 mb-1">
                Staff Portal
            </div>

            <h2 class="fw-bold mb-2">
                Welcome back, <?= e($_SESSION['user_name'] ?? 'Staff') ?> 👋
            </h2>

            <p class="mb-0 text-white-50">
                Manage customer orders and delivery operations.
            </p>

        </div>

        <i class="bi bi-shop fs-1 d-none d-md-block"></i>

    </div>

</div>


<!-- STATISTICS -->

<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">

        <div class="stat-card">

            <div class="d-flex justify-content-between">

                <div>

                    <div class="text-muted small">
                        Total Orders
                    </div>

                    <div class="stat-number">
                        <?= $totalOrders ?>
                    </div>

                </div>

                <div class="stat-icon">
                    <i class="bi bi-cart-check"></i>
                </div>

            </div>

        </div>

    </div>


    <div class="col-xl-3 col-md-6">

        <div class="stat-card">

            <div class="d-flex justify-content-between">

                <div>

                    <div class="text-muted small">
                        Pending Orders
                    </div>

                    <div class="stat-number">
                        <?= $pendingOrders ?>
                    </div>

                </div>

                <div class="stat-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>

            </div>

        </div>

    </div>


    <div class="col-xl-3 col-md-6">

        <div class="stat-card">

            <div class="d-flex justify-content-between">

                <div>

                    <div class="text-muted small">
                        Preparing
                    </div>

                    <div class="stat-number">
                        <?= $preparingOrders ?>
                    </div>

                </div>

                <div class="stat-icon">
                    <i class="bi bi-box-seam"></i>
                </div>

            </div>

        </div>

    </div>


    <div class="col-xl-3 col-md-6">

        <div class="stat-card">

            <div class="d-flex justify-content-between">

                <div>

                    <div class="text-muted small">
                        Out for Delivery
                    </div>

                    <div class="stat-number">
                        <?= $deliveryOrders ?>
                    </div>

                </div>

                <div class="stat-icon">
                    <i class="bi bi-truck"></i>
                </div>

            </div>

        </div>

    </div>

</div>


<!-- TODAY -->

<div class="row g-4 mb-4">

    <div class="col-lg-8">

        <div class="dashboard-card h-100">

            <div class="d-flex justify-content-between align-items-center mb-3">

                <div>

                    <h5 class="fw-bold mb-1">
                        Today's Activity
                    </h5>

                    <small class="text-muted">
                        Orders received today
                    </small>

                </div>

                <i class="bi bi-calendar-day text-success fs-4"></i>

            </div>


            <div class="row">

                <div class="col-sm-6">

                    <div class="border rounded-3 p-3 mb-3">

                        <small class="text-muted">
                            Today's Orders
                        </small>

                        <h3 class="fw-bold mb-0">
                            <?= $todayOrders ?>
                        </h3>

                    </div>

                </div>


                <div class="col-sm-6">

                    <div class="border rounded-3 p-3 mb-3">

                        <small class="text-muted">
                            Today's Revenue
                        </small>

                        <h3 class="fw-bold mb-0">
                            GH₵<?= number_format($todayRevenue, 2) ?>
                        </h3>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <div class="col-lg-4">

        <div class="dashboard-card h-100">

            <h5 class="fw-bold mb-3">
                Quick Actions
            </h5>

            <a href="orders.php" class="quick-action mb-2">

                <i class="bi bi-cart-check quick-icon"></i>

                <strong class="ms-2">
                    Manage Orders
                </strong>

            </a>

            <a href="../products.php" class="quick-action">

                <i class="bi bi-box-seam quick-icon"></i>

                <strong class="ms-2">
                    View Products
                </strong>

            </a>

        </div>

    </div>

</div>


<!-- RECENT ORDERS -->

<div class="dashboard-card">

<div class="d-flex justify-content-between align-items-center mb-3">

    <div>

        <h5 class="fw-bold mb-1">
            Recent Orders
        </h5>

        <small class="text-muted">
            Latest customer orders
        </small>

    </div>

    <a
        href="orders.php"
        class="btn btn-sm btn-outline-success"
    >
        View All
    </a>

</div>


<div class="table-responsive">

<table class="table table-hover">

<thead>

<tr>

<th>Order</th>
<th>Customer</th>
<th>Total</th>
<th>Status</th>
<th>Date</th>
<th></th>

</tr>

</thead>

<tbody>

<?php if (!$recentOrders): ?>

<tr>

<td colspan="6" class="text-center py-4 text-muted">

    No orders yet.

</td>

</tr>

<?php endif; ?>


<?php foreach ($recentOrders as $order): ?>

<tr>

<td class="fw-bold">

    #<?= e($order['order_number']) ?>

</td>

<td>

    <?= e($order['customer_name']) ?>

</td>

<td class="fw-bold">

    GH₵<?= number_format(
        $order['total_amount'],
        2
    ) ?>

</td>

<td>

    <?= dashboardStatus($order['status']) ?>

</td>

<td>

    <?= date(
        'd M Y, h:i A',
        strtotime($order['created_at'])
    ) ?>

</td>

<td class="text-end">

    <a
        href="order_details.php?id=<?= (int)$order['id'] ?>"
        class="btn btn-sm btn-outline-primary"
    >
        View
    </a>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

</main>

<script
src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>