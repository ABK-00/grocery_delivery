<?php

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/functions.php";

requireLogin();

$orderNumber = trim($_GET['order'] ?? '');

if ($orderNumber === '') {
    redirect("customer/dashboard.php");
}

$stmt = $conn->prepare("
    SELECT
        id,
        order_number,
        total_amount,
        status,
        created_at
    FROM orders
    WHERE order_number = ?
      AND user_id = ?
    LIMIT 1
");

$stmt->execute([
    $orderNumber,
    $_SESSION['user_id']
]);

$order = $stmt->fetch();

if (!$order) {
    redirect("customer/dashboard.php");
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

    <title>Order Successful | GroceryDelivery</title>

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
        }

        .success-wrapper {
            min-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .success-card {
            background: white;
            border-radius: 20px;
            padding: 50px;
            max-width: 600px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 35px rgba(0,0,0,.08);
        }

        .success-icon {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #dcfce7;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 45px;
        }

        .order-number {
            background: #f8fafc;
            border-radius: 10px;
            padding: 15px;
            font-size: 20px;
            font-weight: 700;
            margin: 25px 0;
        }

    </style>

    <link href="assets/css/admin.css" rel="stylesheet">
    <link href="assets/css/customer.css" rel="stylesheet">
</head>

<body>
    <?php include __DIR__ . '/includes/loader.php'; ?>
<?php include __DIR__ . '/includes/customer_sidebar.php'; ?>
<main class="customer-main">
<div class="container">

    <div class="success-wrapper">

        <div class="success-card">

            <div class="success-icon">

                <i class="bi bi-check-lg"></i>

            </div>

            <h1 class="fw-bold">
                Order Placed!
            </h1>

            <p class="text-muted">
                Your grocery order has been successfully placed.
            </p>


            <div class="order-number">

                Order #<?= e($order['order_number']) ?>

            </div>


            <div class="mb-4">

                <div class="text-muted">
                    Total Amount
                </div>

                <h3 class="fw-bold">
                    GH₵<?= number_format($order['total_amount'], 2) ?>
                </h3>

            </div>


            <div class="alert alert-info">

                <i class="bi bi-info-circle"></i>

                Your order is currently being processed.

            </div>


            <div class="d-flex gap-2 justify-content-center mt-4">

                <a
                    href="orders.php"
                    class="btn btn-success"
                >
                    <i class="bi bi-receipt"></i>
                    My Orders
                </a>

                <a
                    href="products.php"
                    class="btn btn-outline-secondary"
                >
                    Continue Shopping
                </a>

            </div>

        </div>

    </div>

</div>

</body>
</html>