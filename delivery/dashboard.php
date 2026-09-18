<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('delivery_partner');

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        dp.id,
        dp.vehicle_type,
        dp.vehicle_registration,
        dp.status
    FROM delivery_partners dp
    WHERE dp.user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);
$partner = $stmt->fetch();

if (!$partner) {
    die("Delivery partner profile not found.");
}

$partnerId = $partner['id'];

/* Statistics */

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM deliveries
    WHERE delivery_partner_id = ?
");
$stmt->execute([$partnerId]);
$totalDeliveries = $stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM deliveries
    WHERE delivery_partner_id = ?
    AND status IN ('assigned', 'picked_up', 'out_for_delivery')
");
$stmt->execute([$partnerId]);
$activeDeliveries = $stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM deliveries
    WHERE delivery_partner_id = ?
    AND status = 'delivered'
");
$stmt->execute([$partnerId]);
$completedDeliveries = $stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM deliveries
    WHERE delivery_partner_id = ?
    AND DATE(delivered_at) = CURDATE()
");
$stmt->execute([$partnerId]);
$todayDeliveries = $stmt->fetchColumn();

/* Current deliveries */

$stmt = $conn->prepare("
    SELECT
        d.id,
        d.status,
        d.assigned_at,
        o.order_number,
        o.delivery_address,
        o.delivery_phone,
        o.total_amount,
        u.name AS customer_name
    FROM deliveries d
    INNER JOIN orders o ON o.id = d.order_id
    INNER JOIN users u ON u.id = o.user_id
    WHERE d.delivery_partner_id = ?
    AND d.status IN ('assigned', 'picked_up', 'out_for_delivery')
    ORDER BY d.created_at DESC
");

$stmt->execute([$partnerId]);
$deliveries = $stmt->fetchAll();

$currentPage = "dashboard";
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Delivery Dashboard | GroceryDelivery</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>

body {
    background: #f5f7fb;
    font-family: Arial, sans-serif;
}

.main-content {
    margin-left: 250px;
    padding: 30px;
}

.stat-card {
    border: none;
    border-radius: 18px;
    padding: 25px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(0,0,0,.05);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e8f7ef;
    color: #198754;
    font-size: 22px;
}

.delivery-card {
    border: none;
    border-radius: 18px;
    box-shadow: 0 5px 20px rgba(0,0,0,.05);
}

@media(max-width: 991px) {

    .main-content {
        margin-left: 0;
        padding: 20px;
    }

}

</style>
<link href="../assets/css/admin.css" rel="stylesheet">
</head>

<body>

<?php include "../includes/delivery_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>


<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1 class="topbar-title">Delivery Dashboard</h1>
                <p class="topbar-subtitle">Welcome back, <?= e($_SESSION['user_name']) ?> 👋</p>
            </div>
        </div>
    </div>

        <div>

            <?php if ($partner['status'] === 'available'): ?>

                <span class="badge bg-success px-3 py-2">
                    <i class="bi bi-circle-fill me-1"></i>
                    Online
                </span>

            <?php elseif ($partner['status'] === 'busy'): ?>

                <span class="badge bg-warning text-dark px-3 py-2">
                    <i class="bi bi-circle-fill me-1"></i>
                    Busy
                </span>

            <?php else: ?>

                <span class="badge bg-secondary px-3 py-2">
                    <i class="bi bi-circle-fill me-1"></i>
                    Offline
                </span>

            <?php endif; ?>

        </div>

    </div>


    <!-- Statistics -->

    <div class="row g-4 mb-4">

        <div class="col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>
                        <small class="text-muted">
                            Total Deliveries
                        </small>

                        <h2 class="fw-bold mt-2">
                            <?= $totalDeliveries ?>
                        </h2>
                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>

                        <small class="text-muted">
                            Active Deliveries
                        </small>

                        <h2 class="fw-bold mt-2">
                            <?= $activeDeliveries ?>
                        </h2>

                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-truck"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>

                        <small class="text-muted">
                            Completed
                        </small>

                        <h2 class="fw-bold mt-2">
                            <?= $completedDeliveries ?>
                        </h2>

                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>

                        <small class="text-muted">
                            Today
                        </small>

                        <h2 class="fw-bold mt-2">
                            <?= $todayDeliveries ?>
                        </h2>

                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Vehicle -->

    <div class="card delivery-card mb-4">

        <div class="card-body">

            <div class="d-flex align-items-center">

                <div class="stat-icon me-3">
                    <i class="bi bi-car-front"></i>
                </div>

                <div>

                    <h6 class="fw-bold mb-1">
                        Assigned Vehicle
                    </h6>

                    <div class="text-muted">

                        <?= e($partner['vehicle_type']) ?>

                        •

                        <?= e($partner['vehicle_registration']) ?>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Active deliveries -->

    <div class="card delivery-card">

        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center mb-4">

                <h5 class="fw-bold mb-0">
                    Active Deliveries
                </h5>

                <a href="orders.php" class="btn btn-outline-success btn-sm">
                    View All
                </a>

            </div>


            <?php if (!$deliveries): ?>

                <div class="text-center py-5">

                    <i class="bi bi-truck fs-1 text-muted"></i>

                    <h6 class="mt-3">
                        No active deliveries
                    </h6>

                    <p class="text-muted">
                        Assigned deliveries will appear here.
                    </p>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table align-middle">

                        <thead>

                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th></th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($deliveries as $delivery): ?>

                            <tr>

                                <td class="fw-bold">
                                    <?= e($delivery['order_number']) ?>
                                </td>

                                <td>
                                    <?= e($delivery['customer_name']) ?>
                                </td>

                                <td>
                                    <?= e($delivery['delivery_address']) ?>
                                </td>

                                <td>

                                    <?php

                                    $badge = match ($delivery['status']) {

                                        'assigned' => 'bg-primary',

                                        'picked_up' => 'bg-warning text-dark',

                                        'out_for_delivery' => 'bg-success',

                                        default => 'bg-secondary'

                                    };

                                    ?>

                                    <span class="badge <?= $badge ?>">

                                        <?= ucwords(str_replace('_', ' ', $delivery['status'])) ?>

                                    </span>

                                </td>

                                <td>

                                    <a
                                        href="order_details.php?id=<?= $delivery['id'] ?>"
                                        class="btn btn-sm btn-dark"
                                    >
                                        Manage
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>
</html>