<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('delivery_partner');

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id
    FROM delivery_partners
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$partner = $stmt->fetch();

if (!$partner) {
    die("Delivery partner profile not found.");
}

$partnerId = $partner['id'];

$stmt = $conn->prepare("
    SELECT
        d.id,
        d.status,
        d.assigned_at,
        d.picked_up_at,
        d.delivered_at,

        o.order_number,
        o.delivery_address,
        o.delivery_phone,
        o.total_amount,

        u.name AS customer_name

    FROM deliveries d

    INNER JOIN orders o
        ON o.id = d.order_id

    INNER JOIN users u
        ON u.id = o.user_id

    WHERE d.delivery_partner_id = ?

    ORDER BY d.created_at DESC
");

$stmt->execute([$partnerId]);

$deliveries = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>My Deliveries | GroceryDelivery</title>

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
    background: #f5f7fb;
}

.main-content {
    margin-left: 250px;
    padding: 30px;
}

.card {
    border: none;
    border-radius: 18px;
    box-shadow: 0 5px 20px rgba(0,0,0,.05);
}

@media(max-width:991px) {

    .main-content {
        margin-left: 0;
        padding: 20px;
    }

}

<link href="../assets/css/admin.css" rel="stylesheet">
</head>

<body>

<?php include "../includes/delivery_sidebar.php"; ?>

<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1 class="topbar-title">My Deliveries</h1>
                <p class="topbar-subtitle">Manage your assigned delivery orders.</p>
            </div>
        </div>
    </div>


    <div class="card">

        <div class="card-body">

            <div class="table-responsive">

                <table class="table align-middle">

                    <thead>

                        <tr>

                            <th>Order</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$deliveries): ?>

                        <tr>

                            <td colspan="7" class="text-center py-5">

                                <i class="bi bi-truck fs-1 text-muted"></i>

                                <div class="mt-3">
                                    No deliveries assigned yet.
                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($deliveries as $delivery): ?>

                            <tr>

                                <td class="fw-bold">
                                    <?= e($delivery['order_number']) ?>
                                </td>

                                <td>
                                    <?= e($delivery['customer_name']) ?>
                                </td>

                                <td>
                                    <?= e($delivery['delivery_phone']) ?>
                                </td>

                                <td style="max-width:250px">
                                    <?= e($delivery['delivery_address']) ?>
                                </td>

                                <td class="fw-bold">
                                    GHS <?= number_format($delivery['total_amount'], 2) ?>
                                </td>

                                <td>

                                    <?php

                                    $badge = match ($delivery['status']) {

                                        'assigned' => 'bg-primary',

                                        'picked_up' => 'bg-warning text-dark',

                                        'out_for_delivery' => 'bg-success',

                                        'delivered' => 'bg-dark',

                                        'cancelled' => 'bg-danger',

                                        default => 'bg-secondary'

                                    };

                                    ?>

                                    <span class="badge <?= $badge ?>">

                                        <?= ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $delivery['status']
                                            )
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <a
                                        href="order_details.php?id=<?= $delivery['id'] ?>"
                                        class="btn btn-sm btn-dark"
                                    >
                                        View
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

</body>
</html>