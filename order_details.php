<?php

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/functions.php";

requireLogin();
requireCompanyAccess();
$companyId = currentCompanyId();

$userId = $_SESSION['user_id'];
$orderId = (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    redirect("orders.php");
}


/*
|--------------------------------------------------------------------------
| Get order
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        order_number,
        subtotal,
        delivery_fee,
        total_amount,
        delivery_address,
        delivery_phone,
        notes,
        status,
        created_at
    FROM orders
    WHERE id = ?
      AND user_id = ?
      AND company_id = ?
    LIMIT 1
");

$stmt->execute([
    $orderId,
    $userId
]);

$order = $stmt->fetch();

if (!$order) {
    redirect("orders.php");
}


/*
|--------------------------------------------------------------------------
| Get items
|--------------------------------------------------------------------------
*/

$itemStmt = $conn->prepare("
    SELECT
        oi.product_id,
        oi.product_name,
        oi.quantity,
        oi.unit_price,
        oi.subtotal,
        COALESCE(
            (
                SELECT pi.image
                FROM product_images pi
                WHERE pi.product_id = oi.product_id
                ORDER BY pi.is_primary DESC,
                         pi.sort_order ASC,
                         pi.id ASC
                LIMIT 1
            ),
            p.image
        ) AS image,
        p.unit
    FROM order_items oi
    LEFT JOIN products p
        ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");

$itemStmt->execute([$orderId]);

$items = $itemStmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Status progress
|--------------------------------------------------------------------------
*/

$statuses = [
    'pending' => [
        'label' => 'Order Placed',
        'icon' => 'bi-receipt'
    ],
    'confirmed' => [
        'label' => 'Order Confirmed',
        'icon' => 'bi-check-circle'
    ],
    'preparing' => [
        'label' => 'Being Prepared',
        'icon' => 'bi-box-seam'
    ],
    'ready' => [
        'label' => 'Ready for Delivery',
        'icon' => 'bi-bag-check'
    ],
    'out_for_delivery' => [
        'label' => 'Out for Delivery',
        'icon' => 'bi-truck'
    ],
    'delivered' => [
        'label' => 'Delivered',
        'icon' => 'bi-house-check'
    ]
];

$statusOrder = array_keys($statuses);

$currentIndex = array_search(
    $order['status'],
    $statusOrder
);

if ($currentIndex === false) {
    $currentIndex = 0;
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

    <title>
        Order #<?= e($order['order_number']) ?> | GroceryDelivery
    </title>

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
            padding: 25px 0;
        }

        .card-box {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .product-row {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .product-row:last-child {
            border-bottom: none;
        }

        .product-image {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 10px;
            background: #f1f5f9;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .total-row {
            border-top: 1px solid #ddd;
            padding-top: 15px;
            font-size: 20px;
            font-weight: 700;
        }

        .timeline {
            position: relative;
        }

        .timeline-item {
            display: flex;
            gap: 15px;
            position: relative;
            padding-bottom: 25px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e5e7eb;
            color: #64748b;
            flex-shrink: 0;
            z-index: 2;
        }

        .timeline-item.completed .timeline-icon,
        .timeline-item.current .timeline-icon {
            background: #16a34a;
            color: white;
        }

        .timeline-line {
            position: absolute;
            left: 20px;
            top: 42px;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }

        .timeline-item.completed .timeline-line {
            background: #16a34a;
        }

    </style>

    <link href="assets/css/admin.css" rel="stylesheet">
    <link href="assets/css/customer.css" rel="stylesheet">
</head>

<body>
<?php include __DIR__ . '/includes/loader.php'; ?>
<?php include __DIR__ . '/includes/customer_sidebar.php'; ?>
<main class="customer-main">
<header class="page-header">

    <div class="container">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <div class="text-white-50 small">
                    Order Details
                </div>

                <h2 class="fw-bold mb-0">
                    #<?= e($order['order_number']) ?>
                </h2>

            </div>

            <a
                href="orders.php"
                class="btn btn-outline-light"
            >
                <i class="bi bi-arrow-left"></i>
                My Orders
            </a>

        </div>

    </div>

</header>


<div class="container py-4">

    <div class="row">

        <div class="col-lg-8">

            <!-- ORDER STATUS -->

            <div class="card-box">

                <h5 class="fw-bold mb-4">
                    <i class="bi bi-activity"></i>
                    Order Status
                </h5>


                <div class="timeline">

                    <?php foreach ($statuses as $status => $info): ?>

                        <?php

                        $index = array_search(
                            $status,
                            $statusOrder
                        );

                        $completed =
                            $index < $currentIndex;

                        $current =
                            $index === $currentIndex;

                        ?>

                        <div class="
                            timeline-item
                            <?= $completed ? 'completed' : '' ?>
                            <?= $current ? 'current' : '' ?>
                        ">

                            <div class="timeline-icon">

                                <i class="bi <?= $info['icon'] ?>"></i>

                            </div>


                            <div>

                                <div class="fw-bold">

                                    <?= e($info['label']) ?>

                                </div>

                                <?php if ($current): ?>

                                    <small class="text-muted">

                                        Current order status

                                    </small>

                                <?php endif; ?>

                            </div>


                            <?php if ($status !== 'delivered'): ?>

                                <div class="timeline-line"></div>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>


            <!-- ITEMS -->

            <div class="card-box">

                <h5 class="fw-bold mb-3">
                    <i class="bi bi-basket"></i>
                    Items Ordered
                </h5>


                <?php foreach ($items as $item): ?>

                    <?php

                    $image = $item['image']
                        ? "assets/images/products/" . $item['image']
                        : "assets/images/products/default.jpg";

                    ?>

                    <div class="product-row">

                        <img
                            src="<?= e($image) ?>"
                            class="product-image"
                            alt="<?= e($item['product_name']) ?>"
                            onerror="this.src='assets/images/products/default.jpg';"
                        >


                        <div class="flex-grow-1">

                            <div class="fw-bold">
                                <?= e($item['product_name']) ?>
                            </div>

                            <small class="text-muted">

                                <?= e($item['quantity']) ?>
                                <?= e($item['unit'] ?? 'piece') ?>

                                ×

                                GH₵<?= number_format(
                                    $item['unit_price'],
                                    2
                                ) ?>

                            </small>

                        </div>


                        <div class="fw-bold">

                            GH₵<?= number_format(
                                $item['subtotal'],
                                2
                            ) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>


            <!-- DELIVERY -->

            <div class="card-box">

                <h5 class="fw-bold mb-3">

                    <i class="bi bi-geo-alt"></i>

                    Delivery Information

                </h5>


                <div class="mb-3">

                    <small class="text-muted">
                        Delivery Address
                    </small>

                    <div>
                        <?= nl2br(e($order['delivery_address'])) ?>
                    </div>

                </div>


                <div class="mb-3">

                    <small class="text-muted">
                        Phone
                    </small>

                    <div>
                        <?= e($order['delivery_phone']) ?>
                    </div>

                </div>


                <?php if (!empty($order['notes'])): ?>

                    <div>

                        <small class="text-muted">
                            Order Notes
                        </small>

                        <div>
                            <?= nl2br(e($order['notes'])) ?>
                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- RIGHT COLUMN -->

        <div class="col-lg-4">

            <div class="card-box">

                <h5 class="fw-bold mb-4">
                    Order Summary
                </h5>


                <div class="summary-row">

                    <span>Subtotal</span>

                    <strong>
                        GH₵<?= number_format(
                            $order['subtotal'],
                            2
                        ) ?>
                    </strong>

                </div>


                <div class="summary-row">

                    <span>Delivery</span>

                    <strong>
                        GH₵<?= number_format(
                            $order['delivery_fee'],
                            2
                        ) ?>
                    </strong>

                </div>


                <div class="summary-row total-row">

                    <span>Total</span>

                    <span>
                        GH₵<?= number_format(
                            $order['total_amount'],
                            2
                        ) ?>
                    </span>

                </div>

            </div>


            <div class="card-box">

                <h6 class="fw-bold">
                    Order Information
                </h6>

                <div class="text-muted small">

                    Placed on

                    <div class="text-dark mt-1">

                        <?= date(
                            'd M Y, h:i A',
                            strtotime($order['created_at'])
                        ) ?>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

</body>
</html>