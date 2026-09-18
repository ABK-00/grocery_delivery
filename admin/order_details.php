<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole("admin");
requireCompanyAccess();
$companyId = currentCompanyId();

$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($orderId <= 0) {
    header("Location: orders.php");
    exit;
}

$message = "";
$messageType = "success";

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/
function statusBadgeClass($status)
{
    switch ($status) {
        case 'pending':
            return 'bg-warning text-dark';

        case 'confirmed':
            return 'bg-info text-dark';

        case 'preparing':
            return 'bg-primary';

        case 'ready':
            return 'bg-success';

        case 'out_for_delivery':
            return 'bg-dark';

        case 'delivered':
            return 'bg-success';

        case 'cancelled':
            return 'bg-danger';

        case 'paid':
            return 'bg-success';

        case 'failed':
            return 'bg-danger';

        case 'refunded':
            return 'bg-secondary';

        case 'assigned':
            return 'bg-info text-dark';

        case 'picked_up':
            return 'bg-primary';

        default:
            return 'bg-secondary';
    }
}

/*
|--------------------------------------------------------------------------
| Fetch order
|--------------------------------------------------------------------------
*/
$orderStmt = $conn->prepare("
    SELECT
        o.*,
        u.name AS customer_name,
        u.email AS customer_email,
        u.phone AS customer_phone,
        u.whatsapp AS customer_whatsapp
    FROM orders o
    INNER JOIN users u ON u.id = o.user_id
    WHERE o.id = ?
      AND o.company_id = ?
    LIMIT 1
");

$orderStmt->execute([$orderId, $companyId]);
$order = $orderStmt->fetch();

if (!$order) {
    header("Location: orders.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle order status update
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {

    $newStatus = trim($_POST['order_status'] ?? '');

    $allowedStatuses = [
        'pending',
        'confirmed',
        'preparing',
        'ready',
        'out_for_delivery',
        'delivered',
        'cancelled'
    ];

    if (!in_array($newStatus, $allowedStatuses, true)) {

        $message = "Invalid order status selected.";
        $messageType = "danger";

    } else {

        try {

            $conn->beginTransaction();

            /*
             * If trying to move to out_for_delivery,
             * there must be an assigned delivery.
             */
            if ($newStatus === 'out_for_delivery') {

                $deliveryCheck = $conn->prepare("
                    SELECT id, status, delivery_partner_id
                    FROM deliveries
                    WHERE order_id = ?
                    LIMIT 1
                ");

                $deliveryCheck->execute([$orderId]);
                $delivery = $deliveryCheck->fetch();

                if (!$delivery || !$delivery['delivery_partner_id']) {
                    throw new Exception(
                        "This order cannot be moved to 'Out for Delivery' until a delivery partner is assigned."
                    );
                }
            }

            /*
             * Update order.
             */
            $updateOrder = $conn->prepare("
                UPDATE orders
                SET status = ?
                WHERE id = ?
            ");

            $updateOrder->execute([
                $newStatus,
                $orderId
            ]);

            /*
             * Synchronize delivery record where appropriate.
             */
            $deliveryStmt = $conn->prepare("
                SELECT id, delivery_partner_id
                FROM deliveries
                WHERE order_id = ?
                LIMIT 1
            ");

            $deliveryStmt->execute([$orderId]);
            $delivery = $deliveryStmt->fetch();

            if ($delivery) {

                $deliveryId = (int) $delivery['id'];
                $partnerId = !empty($delivery['delivery_partner_id'])
                    ? (int) $delivery['delivery_partner_id']
                    : null;

                if ($newStatus === 'out_for_delivery') {

                    $updateDelivery = $conn->prepare("
                        UPDATE deliveries
                        SET
                            status = 'out_for_delivery',
                            picked_up_at = COALESCE(picked_up_at, NOW())
                        WHERE id = ?
                    ");

                    $updateDelivery->execute([$deliveryId]);

                    if ($partnerId) {

                        $partnerUpdate = $conn->prepare("
                            UPDATE delivery_partners
                            SET status = 'busy'
                            WHERE id = ?
                        ");

                        $partnerUpdate->execute([$partnerId]);
                    }

                } elseif ($newStatus === 'delivered') {

                    $updateDelivery = $conn->prepare("
                        UPDATE deliveries
                        SET
                            status = 'delivered',
                            delivered_at = COALESCE(delivered_at, NOW())
                        WHERE id = ?
                    ");

                    $updateDelivery->execute([$deliveryId]);

                    if ($partnerId) {

                        $partnerUpdate = $conn->prepare("
                            UPDATE delivery_partners
                            SET status = 'available'
                            WHERE id = ?
                        ");

                        $partnerUpdate->execute([$partnerId]);
                    }

                } elseif ($newStatus === 'cancelled') {

                    $updateDelivery = $conn->prepare("
                        UPDATE deliveries
                        SET status = 'cancelled'
                        WHERE id = ?
                    ");

                    $updateDelivery->execute([$deliveryId]);

                    if ($partnerId) {

                        $partnerUpdate = $conn->prepare("
                            UPDATE delivery_partners
                            SET status = 'available'
                            WHERE id = ?
                        ");

                        $partnerUpdate->execute([$partnerId]);
                    }

                } elseif ($newStatus === 'ready') {

                    /*
                     * Keep an existing assigned delivery assigned.
                     * If it was previously active, do not destroy it.
                     */
                    $deliveryStatusStmt = $conn->prepare("
                        SELECT status
                        FROM deliveries
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $deliveryStatusStmt->execute([$deliveryId]);
                    $currentDeliveryStatus = $deliveryStatusStmt->fetchColumn();

                    if (
                        $currentDeliveryStatus === 'cancelled' ||
                        $currentDeliveryStatus === false
                    ) {
                        // No action.
                    }

                } elseif (
                    $newStatus === 'pending' ||
                    $newStatus === 'confirmed' ||
                    $newStatus === 'preparing'
                ) {

                    /*
                     * If an order is moved backwards before delivery,
                     * leave assignment intact but reset the delivery
                     * state only if it has not been completed.
                     */
                    $deliveryStatusStmt = $conn->prepare("
                        SELECT status
                        FROM deliveries
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $deliveryStatusStmt->execute([$deliveryId]);
                    $currentDeliveryStatus = $deliveryStatusStmt->fetchColumn();

                    if (
                        in_array(
                            $currentDeliveryStatus,
                            ['assigned', 'picked_up', 'out_for_delivery'],
                            true
                        )
                    ) {

                        if ($partnerId) {

                            $partnerUpdate = $conn->prepare("
                                UPDATE delivery_partners
                                SET status = 'available'
                                WHERE id = ?
                            ");

                            $partnerUpdate->execute([$partnerId]);
                        }

                        $cancelDelivery = $conn->prepare("
                            UPDATE deliveries
                            SET status = 'cancelled'
                            WHERE id = ?
                        ");

                        $cancelDelivery->execute([$deliveryId]);
                    }
                }
            }

            $conn->commit();

            $message = "Order status updated successfully.";

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = $e->getMessage();
            $messageType = "danger";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Handle delivery assignment
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_delivery'])) {

    $partnerId = (int) ($_POST['delivery_partner_id'] ?? 0);

    if ($partnerId <= 0) {

        $message = "Please select a delivery partner.";
        $messageType = "danger";

    } elseif ($order['status'] !== 'ready') {

        $message = "A delivery partner can only be assigned when the order is ready.";
        $messageType = "danger";

    } else {

        try {

            $conn->beginTransaction();

            /*
             * Check partner.
             */
            $partnerStmt = $conn->prepare("
                SELECT
                    dp.id,
                    dp.status,
                    u.name,
                    u.status AS account_status
                FROM delivery_partners dp
                INNER JOIN users u ON u.id = dp.user_id
                WHERE dp.id = ?
                  AND u.company_id = ?
                  AND u.role = 'delivery_partner'
                LIMIT 1
            ");

            $partnerStmt->execute([$partnerId, $companyId]);
            $partner = $partnerStmt->fetch();

            if (!$partner) {
                throw new Exception("Delivery partner not found.");
            }

            if ($partner['account_status'] !== 'active') {
                throw new Exception("The selected delivery partner account is not active.");
            }

            if ($partner['status'] !== 'available') {
                throw new Exception("The selected delivery partner is not currently available.");
            }

            /*
             * Make sure partner isn't already handling another delivery.
             */
            $activeDeliveryStmt = $conn->prepare("
                SELECT d.id
                FROM deliveries d
                WHERE d.delivery_partner_id = ?
                AND d.status IN ('assigned', 'picked_up', 'out_for_delivery')
                LIMIT 1
            ");

            $activeDeliveryStmt->execute([$partnerId]);

            if ($activeDeliveryStmt->fetch()) {
                throw new Exception(
                    "This delivery partner already has an active delivery."
                );
            }

            /*
             * Check whether this order already has a delivery.
             */
            $existingDeliveryStmt = $conn->prepare("
                SELECT id, delivery_partner_id, status
                FROM deliveries
                WHERE order_id = ?
                LIMIT 1
            ");

            $existingDeliveryStmt->execute([$orderId]);
            $existingDelivery = $existingDeliveryStmt->fetch();

            /*
             * Release previous partner if reassigning.
             */
            if (
                $existingDelivery &&
                !empty($existingDelivery['delivery_partner_id']) &&
                (int) $existingDelivery['delivery_partner_id'] !== $partnerId
            ) {

                $oldPartnerStmt = $conn->prepare("
                    UPDATE delivery_partners
                    SET status = 'available'
                    WHERE id = ?
                ");

                $oldPartnerStmt->execute([
                    (int) $existingDelivery['delivery_partner_id']
                ]);
            }

            if ($existingDelivery) {

                $deliveryUpdate = $conn->prepare("
                    UPDATE deliveries
                    SET
                        delivery_partner_id = ?,
                        status = 'assigned',
                        assigned_at = NOW(),
                        picked_up_at = NULL,
                        delivered_at = NULL
                    WHERE id = ?
                ");

                $deliveryUpdate->execute([
                    $partnerId,
                    (int) $existingDelivery['id']
                ]);

            } else {

                $deliveryInsert = $conn->prepare("
                    INSERT INTO deliveries (
                        order_id,
                        delivery_partner_id,
                        status,
                        assigned_at
                    )
                    VALUES (?, ?, 'assigned', NOW())
                ");

                $deliveryInsert->execute([
                    $orderId,
                    $partnerId
                ]);
            }

            /*
             * Mark partner busy.
             */
            $partnerBusy = $conn->prepare("
                UPDATE delivery_partners
                SET status = 'busy'
                WHERE id = ?
            ");

            $partnerBusy->execute([$partnerId]);

            $conn->commit();

            $message = "Delivery partner assigned successfully.";

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = $e->getMessage();
            $messageType = "danger";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Refresh order after actions
|--------------------------------------------------------------------------
*/
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

/*
|--------------------------------------------------------------------------
| Fetch order items
|--------------------------------------------------------------------------
*/
$itemsStmt = $conn->prepare("
    SELECT
        oi.*,
        p.image
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");

$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Fetch payment
|--------------------------------------------------------------------------
*/
$paymentStmt = $conn->prepare("
    SELECT *
    FROM payments
    WHERE order_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$paymentStmt->execute([$orderId]);
$payment = $paymentStmt->fetch();

/*
|--------------------------------------------------------------------------
| Fetch delivery
|--------------------------------------------------------------------------
*/
$deliveryStmt = $conn->prepare("
    SELECT
        d.*,
        dp.vehicle_type,
        dp.vehicle_registration,
        dp.status AS partner_status,
        u.name AS partner_name,
        u.email AS partner_email,
        u.phone AS partner_phone,
        u.whatsapp AS partner_whatsapp
    FROM deliveries d
    LEFT JOIN delivery_partners dp
        ON dp.id = d.delivery_partner_id
    LEFT JOIN users u
        ON u.id = dp.user_id
    WHERE d.order_id = ?
    LIMIT 1
");

$deliveryStmt->execute([$orderId]);
$delivery = $deliveryStmt->fetch();

/*
|--------------------------------------------------------------------------
| Available delivery partners
|--------------------------------------------------------------------------
*/
$partnersStmt = $conn->prepare("
    SELECT
        dp.id,
        dp.vehicle_type,
        dp.vehicle_registration,
        dp.status,
        u.name,
        u.phone
    FROM delivery_partners dp
    INNER JOIN users u ON u.id = dp.user_id
    WHERE dp.status = 'available'
      AND u.status = 'active'
      AND u.company_id = ?
    ORDER BY u.name ASC
");
$partnersStmt->execute([$companyId]);
$availablePartners = $partnersStmt->fetchAll();

$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        Order <?= e($order['order_number']) ?> - Admin
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <style>

        :root {
            --navy: #071827;
            --navy-light: #0d2639;
            --green: #16a34a;
            --green-dark: #12813c;
            --bg: #f4f7f8;
            --text: #17202a;
            --muted: #6b7280;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            padding: 30px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-title {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }

        .page-subtitle {
            color: var(--muted);
            margin-top: 5px;
        }

        .card-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 5px 20px rgba(0,0,0,.04);
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .info-label {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 600;
            word-break: break-word;
        }

        .order-number {
            color: var(--green-dark);
            font-weight: 700;
        }

        .summary-box {
            background: #f8fafb;
            border-radius: 12px;
            padding: 18px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 8px 0;
        }

        .summary-row.total {
            border-top: 1px solid var(--border);
            margin-top: 8px;
            padding-top: 15px;
            font-size: 19px;
            font-weight: 700;
        }

        .product-image {
            width: 58px;
            height: 58px;
            border-radius: 10px;
            object-fit: cover;
            background: #eef2f3;
        }

        .product-placeholder {
            width: 58px;
            height: 58px;
            border-radius: 10px;
            background: #eef2f3;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 22px;
        }

        .status-badge {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .timeline {
            position: relative;
            padding-left: 28px;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }

        .timeline-item::before {
            content: "";
            position: absolute;
            left: -22px;
            top: 5px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #d1d5db;
        }

        .timeline-item::after {
            content: "";
            position: absolute;
            left: -18px;
            top: 16px;
            width: 2px;
            height: calc(100% - 8px);
            background: #e5e7eb;
        }

        .timeline-item:last-child::after {
            display: none;
        }

        .timeline-item.active::before {
            background: var(--green);
        }

        .timeline-title {
            font-weight: 700;
            font-size: 14px;
        }

        .timeline-text {
            color: var(--muted);
            font-size: 13px;
        }

        .driver-card {
            background: linear-gradient(
                135deg,
                var(--navy),
                var(--navy-light)
            );
            color: white;
            border-radius: 14px;
            padding: 20px;
        }

        .driver-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: rgba(255,255,255,.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }

        .btn-green {
            background: var(--green);
            color: white;
            border: none;
        }

        .btn-green:hover {
            background: var(--green-dark);
            color: white;
        }

        .action-btn {
            min-height: 42px;
        }

        @media (max-width: 991px) {

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 575px) {

            .main-content {
                padding: 15px;
            }

            .card-box {
                padding: 18px;
            }

            .page-title {
                font-size: 23px;
            }
        }

    </style>

</head>

<body>

<?php include "../includes/admin_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>

<div class="main-content">

    <!-- HEADER -->
    <div class="page-header">

        <div>

            <div class="d-flex align-items-center gap-2 mb-2">

                <a href="orders.php"
                   class="btn btn-sm btn-outline-secondary">

                    <i class="bi bi-arrow-left"></i>
                    Back to Orders

                </a>

            </div>

            <h1 class="page-title">
                Order Details
            </h1>

            <div class="page-subtitle">
                Manage order
                <span class="order-number">
                    <?= e($order['order_number']) ?>
                </span>
            </div>

        </div>

        <div>

            <span class="status-badge <?= statusBadgeClass($order['status']) ?>">
                <?= e(str_replace('_', ' ', $order['status'])) ?>
            </span>

        </div>

    </div>

    <!-- ALERT -->
    <?php if ($message): ?>

        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show">

            <i class="bi bi-info-circle me-2"></i>

            <?= e($message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>


    <div class="row">

        <!-- LEFT -->
        <div class="col-lg-8">

            <!-- CUSTOMER -->
            <div class="card-box">

                <div class="section-title">
                    <i class="bi bi-person-circle me-2"></i>
                    Customer Information
                </div>

                <div class="row g-4">

                    <div class="col-md-6">

                        <div class="info-label">
                            Customer
                        </div>

                        <div class="info-value">
                            <?= e($order['customer_name']) ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="info-label">
                            Email
                        </div>

                        <div class="info-value">
                            <?= e($order['customer_email']) ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="info-label">
                            Phone
                        </div>

                        <div class="info-value">

                            <?php if (!empty($order['customer_phone'])): ?>

                                <a href="tel:<?= e($order['customer_phone']) ?>"
                                   class="text-decoration-none">

                                    <?= e($order['customer_phone']) ?>

                                </a>

                            <?php else: ?>

                                <span class="text-muted">
                                    Not provided
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="info-label">
                            WhatsApp
                        </div>

                        <div class="info-value">

                            <?php if (!empty($order['customer_whatsapp'])): ?>

                                <a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $order['customer_whatsapp'])) ?>"
                                   target="_blank"
                                   class="text-success text-decoration-none">

                                    <i class="bi bi-whatsapp me-1"></i>
                                    <?= e($order['customer_whatsapp']) ?>

                                </a>

                            <?php else: ?>

                                <span class="text-muted">
                                    Not provided
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ORDER ITEMS -->
            <div class="card-box">

                <div class="section-title">
                    <i class="bi bi-basket2 me-2"></i>
                    Order Items
                </div>

                <div class="table-responsive">

                    <table class="table align-middle">

                        <thead>

                            <tr>

                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th class="text-end">Subtotal</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php if (empty($items)): ?>

                            <tr>

                                <td colspan="4"
                                    class="text-center text-muted py-4">

                                    No order items found.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($items as $item): ?>

                                <tr>

                                    <td>

                                        <div class="d-flex align-items-center gap-3">

                                            <?php
                                            $image = $item['image'] ?? '';

                                            if (!empty($image)):

                                                $imagePath = "../assets/images/products/" . $image;
                                            ?>

                                                <img src="<?= e($imagePath) ?>"
                                                     class="product-image"
                                                     alt="<?= e($item['product_name']) ?>">

                                            <?php else: ?>

                                                <div class="product-placeholder">
                                                    <i class="bi bi-image"></i>
                                                </div>

                                            <?php endif; ?>

                                            <div>

                                                <div class="fw-semibold">
                                                    <?= e($item['product_name']) ?>
                                                </div>

                                            </div>

                                        </div>

                                    </td>

                                    <td>
                                        <?= e($item['quantity']) ?>
                                    </td>

                                    <td>
                                        GHS <?= number_format((float) $item['unit_price'], 2) ?>
                                    </td>

                                    <td class="text-end fw-semibold">
                                        GHS <?= number_format((float) $item['subtotal'], 2) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>


            <!-- DELIVERY ADDRESS -->
            <div class="card-box">

                <div class="section-title">
                    <i class="bi bi-geo-alt me-2"></i>
                    Delivery Information
                </div>

                <div class="row g-4">

                    <div class="col-md-7">

                        <div class="info-label">
                            Delivery Address
                        </div>

                        <div class="info-value">
                            <?= nl2br(e($order['delivery_address'])) ?>
                        </div>

                    </div>

                    <div class="col-md-5">

                        <div class="info-label">
                            Delivery Phone
                        </div>

                        <div class="info-value">
                            <?= e($order['delivery_phone']) ?>
                        </div>

                    </div>

                    <?php if (!empty($order['notes'])): ?>

                        <div class="col-12">

                            <div class="info-label">
                                Customer Notes
                            </div>

                            <div class="summary-box">
                                <?= nl2br(e($order['notes'])) ?>
                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- DELIVERY PARTNER -->
            <div class="card-box">

                <div class="section-title d-flex justify-content-between align-items-center">

                    <span>
                        <i class="bi bi-bicycle me-2"></i>
                        Delivery Partner
                    </span>

                    <?php if ($delivery): ?>

                        <span class="status-badge <?= statusBadgeClass($delivery['status']) ?>">
                            <?= e(str_replace('_', ' ', $delivery['status'])) ?>
                        </span>

                    <?php endif; ?>

                </div>


                <?php if ($delivery && !empty($delivery['partner_name'])): ?>

                    <div class="driver-card mb-3">

                        <div class="d-flex align-items-center gap-3">

                            <div class="driver-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>

                            <div>

                                <div class="fw-bold fs-5">
                                    <?= e($delivery['partner_name']) ?>
                                </div>

                                <div class="opacity-75">
                                    <?= e($delivery['vehicle_type']) ?>
                                    —
                                    <?= e($delivery['vehicle_registration']) ?>
                                </div>

                            </div>

                        </div>

                        <hr class="border-light opacity-25">

                        <div class="row g-3">

                            <div class="col-md-6">

                                <small class="opacity-75">
                                    Phone
                                </small>

                                <div>
                                    <?= e($delivery['partner_phone'] ?? 'Not provided') ?>
                                </div>

                            </div>

                            <div class="col-md-6">

                                <small class="opacity-75">
                                    Partner Availability
                                </small>

                                <div class="text-capitalize">
                                    <?= e($delivery['partner_status'] ?? 'Unknown') ?>
                                </div>

                            </div>

                        </div>

                    </div>


                    <?php if ($order['status'] === 'out_for_delivery'): ?>

                        <a href="../delivery/track_delivery.php?id=<?= (int) $delivery['id'] ?>"
                           class="btn btn-outline-success">

                            <i class="bi bi-geo-alt me-1"></i>
                            View Live Tracking

                        </a>

                    <?php endif; ?>


                    <?php if (
                        $order['status'] === 'ready' &&
                        $delivery['status'] === 'assigned'
                    ): ?>

                        <button class="btn btn-outline-primary ms-2"
                                data-bs-toggle="modal"
                                data-bs-target="#assignModal">

                            <i class="bi bi-arrow-repeat me-1"></i>
                            Reassign

                        </button>

                    <?php endif; ?>


                <?php else: ?>

                    <div class="text-center py-4">

                        <div class="text-muted mb-3">

                            <i class="bi bi-person-x display-6"></i>

                        </div>

                        <h6>
                            No delivery partner assigned
                        </h6>

                        <p class="text-muted small mb-3">
                            This order is currently waiting for a delivery partner.
                        </p>


                        <?php if ($order['status'] === 'ready'): ?>

                            <button class="btn btn-green"
                                    data-bs-toggle="modal"
                                    data-bs-target="#assignModal">

                                <i class="bi bi-person-plus me-1"></i>
                                Assign Delivery Partner

                            </button>

                        <?php else: ?>

                            <small class="text-muted">
                                The order must be marked as
                                <strong>Ready</strong>
                                before a delivery partner can be assigned.
                            </small>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- RIGHT -->
        <div class="col-lg-4">

            <!-- ORDER SUMMARY -->
            <div class="card-box">

                <div class="section-title">
                    <i class="bi bi-receipt me-2"></i>
                    Order Summary
                </div>

                <div class="summary-box">

                    <div class="summary-row">

                        <span>
                            Subtotal
                        </span>

                        <strong>
                            GHS <?= number_format((float) $order['subtotal'], 2) ?>
                        </strong>

                    </div>

                    <div class="summary-row">

                        <span>
                            Delivery Fee
                        </span>

                        <strong>
                            GHS <?= number_format((float) $order['delivery_fee'], 2) ?>
                        </strong>

                    </div>

                    <div class="summary-row total">

                        <span>
                            Total
                        </span>

                        <span>
                            GHS <?= number_format((float) $order['total_amount'], 2) ?>
                        </span>

                    </div>

                </div>

            </div>


            <!-- PAYMENT -->
            <div class="card-box">

                <div class="section-title">
                    <i class="bi bi-credit-card me-2"></i>
                    Payment
                </div>

                <?php if ($payment): ?>

                    <div class="mb-3">

                        <div class="info-label">
                            Payment Status
                        </div>

                        <span class="status-badge <?= statusBadgeClass($payment['status']) ?>">
                            <?= e($payment['status']) ?>
                        </span>

                    </div>

                    <div class="mb-3">

                        <div class="info-label">
                            Amount
                        </div>

                        <div class="info-value">
                            <?= e($payment['currency']) ?>
                            <?= number_format((float) $payment['amount'], 2) ?>
                        </div>

                    </div>

                    <div class="mb-3">

                        <div class="info-label">
                            Reference
                        </div>

                        <div class="small text-break">
                            <?= e($payment['reference']) ?>
                        </div>

                    </div>

                    <div class="mb-3">

                        <div class="info-label">
                            Payment Method
                        </div>

                        <div class="info-value text-capitalize">
                            <?= e($payment['payment_method'] ?: 'Not specified') ?>
                        </div>

                    </div>

                    <div>

                        <div class="info-label">
                            Gateway
                        </div>

                        <div class="info-value text-capitalize">
                            <?= e($payment['gateway']) ?>
                        </div>

                    </div>

                <?php else: ?>

                    <div class="text-center text-muted py-3">

                        <i class="bi bi-credit-card-2-front display-6"></i>

                        <p class="mt-2 mb-0">
                            No payment record found.
                        </p>

                    </div>

                <?php endif; ?>

            </div>


            <!-- ORDER STATUS -->
            <div class="card-box">

                <div class="section-title">
                    <i class="bi bi-arrow-repeat me-2"></i>
                    Update Order Status
                </div>

                <form method="POST">

                    <div class="mb-3">

                        <label class="form-label fw-semibold">
                            Order Status
                        </label>

                        <select name="order_status"
                                class="form-select"
                                required>

                            <?php
                            $statuses = [
                                'pending' => 'Pending',
                                'confirmed' => 'Confirmed',
                                'preparing' => 'Preparing',
                                'ready' => 'Ready',
                                'out_for_delivery' => 'Out for Delivery',
                                'delivered' => 'Delivered',
                                'cancelled' => 'Cancelled'
                            ];
                            ?>

                            <?php foreach ($statuses as $value => $label): ?>

                                <option value="<?= e($value) ?>"
                                    <?= $order['status'] === $value ? 'selected' : '' ?>>

                                    <?= e($label) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <button type="submit"
                            name="update_order_status"
                            class="btn btn-green w-100 action-btn">

                        <i class="bi bi-check-circle me-1"></i>
                        Update Status

                    </button>

                </form>

            </div>


            <!-- DATES -->
            <div class="card-box">

                <div class="section-title">
                    <i class="bi bi-clock-history me-2"></i>
                    Order Timeline
                </div>

                <div class="timeline">

                    <div class="timeline-item active">

                        <div class="timeline-title">
                            Order Created
                        </div>

                        <div class="timeline-text">
                            <?= date(
                                'd M Y, h:i A',
                                strtotime($order['created_at'])
                            ) ?>
                        </div>

                    </div>


                    <div class="timeline-item
                        <?= in_array(
                            $order['status'],
                            [
                                'confirmed',
                                'preparing',
                                'ready',
                                'out_for_delivery',
                                'delivered'
                            ],
                            true
                        ) ? 'active' : '' ?>">

                        <div class="timeline-title">
                            Confirmed
                        </div>

                        <div class="timeline-text">
                            Order accepted for processing
                        </div>

                    </div>


                    <div class="timeline-item
                        <?= in_array(
                            $order['status'],
                            [
                                'preparing',
                                'ready',
                                'out_for_delivery',
                                'delivered'
                            ],
                            true
                        ) ? 'active' : '' ?>">

                        <div class="timeline-title">
                            Preparing
                        </div>

                        <div class="timeline-text">
                            Order being prepared
                        </div>

                    </div>


                    <div class="timeline-item
                        <?= in_array(
                            $order['status'],
                            [
                                'ready',
                                'out_for_delivery',
                                'delivered'
                            ],
                            true
                        ) ? 'active' : '' ?>">

                        <div class="timeline-title">
                            Ready
                        </div>

                        <div class="timeline-text">
                            Ready for delivery
                        </div>

                    </div>


                    <div class="timeline-item
                        <?= in_array(
                            $order['status'],
                            [
                                'out_for_delivery',
                                'delivered'
                            ],
                            true
                        ) ? 'active' : '' ?>">

                        <div class="timeline-title">
                            Out for Delivery
                        </div>

                        <div class="timeline-text">
                            Delivery partner is on the way
                        </div>

                    </div>


                    <div class="timeline-item
                        <?= $order['status'] === 'delivered'
                            ? 'active'
                            : '' ?>">

                        <div class="timeline-title">
                            Delivered
                        </div>

                        <div class="timeline-text">
                            Order completed
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- ASSIGN DELIVERY MODAL -->
<div class="modal fade"
     id="assignModal"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        <i class="bi bi-bicycle me-2"></i>
                        Assign Delivery Partner
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <p class="text-muted small">
                        Select an available delivery partner for
                        <strong><?= e($order['order_number']) ?></strong>.
                    </p>

                    <?php if (empty($availablePartners)): ?>

                        <div class="alert alert-warning">

                            <i class="bi bi-exclamation-triangle me-2"></i>

                            There are currently no available delivery partners.

                        </div>

                    <?php else: ?>

                        <div class="mb-3">

                            <label class="form-label fw-semibold">
                                Delivery Partner
                            </label>

                            <select name="delivery_partner_id"
                                    class="form-select"
                                    required>

                                <option value="">
                                    Select delivery partner
                                </option>

                                <?php foreach ($availablePartners as $partner): ?>

                                    <option value="<?= (int) $partner['id'] ?>">

                                        <?= e($partner['name']) ?>

                                        —
                                        <?= e($partner['vehicle_type']) ?>

                                        (<?= e($partner['vehicle_registration']) ?>)

                                        <?php if (!empty($partner['phone'])): ?>

                                            —
                                            <?= e($partner['phone']) ?>

                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    <?php endif; ?>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <?php if (!empty($availablePartners)): ?>

                        <button type="submit"
                                name="assign_delivery"
                                class="btn btn-green">

                            <i class="bi bi-check-circle me-1"></i>
                            Assign Partner

                        </button>

                    <?php endif; ?>

                </div>

            </form>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

</body>
</html>