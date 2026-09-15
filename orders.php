<?php

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/functions.php";

requireLogin();

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        id,
        order_number,
        total_amount,
        status,
        created_at
    FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC
");

$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

function statusBadge($status)
{
    $classes = [
        'pending' => 'bg-warning text-dark',
        'confirmed' => 'bg-primary',
        'preparing' => 'bg-info text-dark',
        'ready' => 'bg-secondary',
        'out_for_delivery' => 'bg-dark',
        'delivered' => 'bg-success',
        'cancelled' => 'bg-danger'
    ];

    $labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'ready' => 'Ready',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled'
    ];

    $class = $classes[$status] ?? 'bg-secondary';
    $label = $labels[$status] ?? ucfirst($status);

    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
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

    <title>My Orders | GroceryDelivery</title>

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

        .page-header {
            background: #0f172a;
            color: white;
            padding: 30px 0;
        }

        .order-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            transition: .2s;
        }

        .order-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,.07);
        }

        .order-number {
            font-weight: 700;
            color: #0f172a;
        }

        .empty-state {
            background: white;
            border-radius: 18px;
            padding: 60px 25px;
            text-align: center;
        }

        .empty-icon {
            font-size: 55px;
            color: #16a34a;
        }

    </style>

</head>

<body>

<header class="page-header">

    <div class="container">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <h2 class="fw-bold mb-1">
                    <i class="bi bi-receipt"></i>
                    My Orders
                </h2>

                <div class="text-white-50">
                    View and track your grocery orders
                </div>

            </div>

            <a
                href="products.php"
                class="btn btn-success"
            >
                <i class="bi bi-cart"></i>
                Shop Now
            </a>

        </div>

    </div>

</header>


<div class="container py-4">

    <?php if (!$orders): ?>

        <div class="empty-state">

            <div class="empty-icon mb-3">
                <i class="bi bi-bag-x"></i>
            </div>

            <h3 class="fw-bold">
                No Orders Yet
            </h3>

            <p class="text-muted">
                You haven't placed any orders yet.
            </p>

            <a
                href="products.php"
                class="btn btn-success"
            >
                Start Shopping
            </a>

        </div>

    <?php else: ?>

        <?php foreach ($orders as $order): ?>

            <div class="order-card">

                <div class="row align-items-center g-3">

                    <div class="col-md-3">

                        <small class="text-muted">
                            Order Number
                        </small>

                        <div class="order-number">
                            #<?= e($order['order_number']) ?>
                        </div>

                    </div>


                    <div class="col-md-2">

                        <small class="text-muted">
                            Date
                        </small>

                        <div>
                            <?= date(
                                'd M Y',
                                strtotime($order['created_at'])
                            ) ?>
                        </div>

                    </div>


                    <div class="col-md-2">

                        <small class="text-muted">
                            Total
                        </small>

                        <div class="fw-bold">
                            GH₵<?= number_format(
                                $order['total_amount'],
                                2
                            ) ?>
                        </div>

                    </div>


                    <div class="col-md-2">

                        <small class="text-muted d-block mb-1">
                            Status
                        </small>

                        <?= statusBadge($order['status']) ?>

                    </div>


                    <div class="col-md-3 text-md-end">

                        <a
                            href="order_details.php?id=<?= (int)$order['id'] ?>"
                            class="btn btn-outline-success"
                        >
                            <i class="bi bi-eye"></i>
                            View Order
                        </a>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>