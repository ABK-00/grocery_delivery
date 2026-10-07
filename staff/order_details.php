<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('staff');
requireCompanyAccess();
$companyId = currentCompanyId();

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    redirect("orders.php");
}

$error = "";
$success = "";

/*
|--------------------------------------------------------------------------
| HANDLE ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | UPDATE ORDER STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_status') {

        $newStatus = $_POST['status'] ?? '';

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

            $error = "Invalid order status.";

        } else {

            try {

                $conn->beginTransaction();

                $stmt = $conn->prepare("
                    SELECT *
                    FROM orders
                    WHERE id = ? AND company_id = ?
                    FOR UPDATE
                ");

                $stmt->execute([$orderId, $companyId]);

                $orderCheck = $stmt->fetch();

                if (!$orderCheck) {
                    throw new Exception("Order not found.");
                }

                /*
                |--------------------------------------------------------------------------
                | Sync delivery record when necessary
                |--------------------------------------------------------------------------
                */

                if ($newStatus === 'out_for_delivery') {

                    $stmt = $conn->prepare("
                        SELECT id
                        FROM deliveries
                        WHERE order_id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([$orderId, $companyId]);

                    $delivery = $stmt->fetch();

                    if (!$delivery) {
                        throw new Exception(
                            "This order has not been assigned to a delivery partner yet."
                        );
                    }

                    $stmt = $conn->prepare("
                        UPDATE deliveries
                        SET status = 'out_for_delivery'
                        WHERE id = ?
                    ");

                    $stmt->execute([$delivery['id']]);
                }

                if ($newStatus === 'delivered') {

                    $stmt = $conn->prepare("
                        SELECT id, delivery_partner_id
                        FROM deliveries
                        WHERE order_id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([$orderId]);

                    $delivery = $stmt->fetch();

                    if ($delivery) {

                        $stmt = $conn->prepare("
                            UPDATE deliveries
                            SET
                                status = 'delivered',
                                delivered_at = NOW()
                            WHERE id = ?
                        ");

                        $stmt->execute([$delivery['id']]);

                        if (!empty($delivery['delivery_partner_id'])) {

                            $stmt = $conn->prepare("
                                UPDATE delivery_partners
                                SET status = 'available'
                                WHERE id = ?
                            ");

                            $stmt->execute([
                                $delivery['delivery_partner_id']
                            ]);
                        }
                    }
                }

                if ($newStatus === 'cancelled') {

                    $stmt = $conn->prepare("
                        SELECT id, delivery_partner_id
                        FROM deliveries
                        WHERE order_id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([$orderId]);

                    $delivery = $stmt->fetch();

                    if ($delivery) {

                        $stmt = $conn->prepare("
                            UPDATE deliveries
                            SET status = 'cancelled'
                            WHERE id = ?
                        ");

                        $stmt->execute([$delivery['id']]);

                        if (!empty($delivery['delivery_partner_id'])) {

                            $stmt = $conn->prepare("
                                UPDATE delivery_partners
                                SET status = 'available'
                                WHERE id = ?
                            ");

                            $stmt->execute([
                                $delivery['delivery_partner_id']
                            ]);
                        }
                    }
                }

                $stmt = $conn->prepare("
                    UPDATE orders
                    SET status = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $newStatus,
                    $orderId
                ]);

                $conn->commit();

                $success = "Order status updated successfully.";

            } catch (Exception $e) {

                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ASSIGN DELIVERY PARTNER
    |--------------------------------------------------------------------------
    */

    if ($action === 'assign_delivery') {

        $partnerId = (int)($_POST['delivery_partner_id'] ?? 0);

        if ($partnerId <= 0) {

            $error = "Please select a delivery partner.";

        } else {

            try {

                $conn->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Lock order
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT *
                    FROM orders
                    WHERE id = ? AND company_id = ?
                    FOR UPDATE
                ");

                $stmt->execute([$orderId]);

                $order = $stmt->fetch();

                if (!$order) {
                    throw new Exception("Order not found.");
                }

                /*
                |--------------------------------------------------------------------------
                | Only ready orders should be assigned
                |--------------------------------------------------------------------------
                */

                if ($order['status'] !== 'ready') {

                    throw new Exception(
                        "The order must be marked as READY before assigning a delivery partner."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Check delivery partner
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT
                        dp.id,
                        dp.user_id,
                        dp.vehicle_type,
                        dp.vehicle_registration,
                        dp.status AS partner_status,
                        u.name,
                        u.phone,
                        u.status AS account_status
                    FROM delivery_partners dp
                    INNER JOIN users u
                        ON u.id = dp.user_id
                    WHERE dp.id = ?
                      AND u.company_id = ?
                      AND u.role = 'delivery_partner'
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([$partnerId, $companyId]);

                $partner = $stmt->fetch();

                if (!$partner) {

                    throw new Exception(
                        "Selected delivery partner does not exist."
                    );
                }

                if ($partner['account_status'] !== 'active') {

                    throw new Exception(
                        "The selected delivery partner account is not active."
                    );
                }

                if ($partner['partner_status'] !== 'available') {

                    throw new Exception(
                        "The selected delivery partner is not available."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Check whether another active delivery already exists
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT id
                    FROM deliveries
                    WHERE delivery_partner_id = ?
                      AND status IN (
                          'assigned',
                          'picked_up',
                          'out_for_delivery'
                      )
                    LIMIT 1
                ");

                $stmt->execute([$partnerId]);

                if ($stmt->fetch()) {

                    throw new Exception(
                        "This delivery partner already has an active delivery."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Check existing delivery record
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT *
                    FROM deliveries
                    WHERE order_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([$orderId]);

                $existingDelivery = $stmt->fetch();

                if ($existingDelivery) {

                    /*
                    | Reassign existing delivery
                    */

                    $stmt = $conn->prepare("
                        UPDATE deliveries
                        SET
                            delivery_partner_id = ?,
                            status = 'assigned',
                            assigned_at = NOW(),
                            picked_up_at = NULL,
                            delivered_at = NULL
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $partnerId,
                        $existingDelivery['id']
                    ]);

                } else {

                    /*
                    | Create new delivery
                    */

                    $stmt = $conn->prepare("
                        INSERT INTO deliveries (
                            order_id,
                            delivery_partner_id,
                            status,
                            assigned_at
                        )
                        VALUES (
                            ?,
                            ?,
                            'assigned',
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        $orderId,
                        $partnerId
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Mark delivery partner as busy
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    UPDATE delivery_partners
                    SET status = 'busy'
                    WHERE id = ?
                ");

                $stmt->execute([$partnerId]);

                $conn->commit();

                $success = "Delivery partner assigned successfully.";

            } catch (Exception $e) {

                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| GET ORDER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        o.*,
        u.name AS customer_name,
        u.email AS customer_email,
        u.phone AS customer_phone,
        u.whatsapp AS customer_whatsapp
    FROM orders o
    INNER JOIN users u
        ON u.id = o.user_id
    WHERE o.id = ? AND o.company_id = ?
    LIMIT 1
");

$stmt->execute([$orderId, $companyId]);

$order = $stmt->fetch();

if (!$order) {
    redirect("orders.php");
}


/*
|--------------------------------------------------------------------------
| GET ORDER ITEMS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        oi.*,
        p.image
    FROM order_items oi
    LEFT JOIN products p
        ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");

$stmt->execute([$orderId]);

$items = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| GET DELIVERY INFORMATION
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        d.*,
        dp.vehicle_type,
        dp.vehicle_registration,
        dp.status AS partner_status,
        u.name AS partner_name,
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

$stmt->execute([$orderId]);

$delivery = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| GET AVAILABLE DELIVERY PARTNERS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        dp.id,
        dp.vehicle_type,
        dp.vehicle_registration,
        dp.status,
        u.name,
        u.phone
    FROM delivery_partners dp

    INNER JOIN users u
        ON u.id = dp.user_id

    WHERE u.role = 'delivery_partner'
      AND u.company_id = ?
      AND u.status = 'active'
      AND dp.status = 'available'

    ORDER BY u.name ASC
");

$stmt->execute([$companyId]);
$availablePartners = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| STATUS LABELS
|--------------------------------------------------------------------------
*/

$statusLabels = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready',
    'out_for_delivery' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled'
];

$statusClasses = [
    'pending' => 'bg-secondary',
    'confirmed' => 'bg-primary',
    'preparing' => 'bg-warning text-dark',
    'ready' => 'bg-info text-dark',
    'out_for_delivery' => 'bg-success',
    'delivered' => 'bg-dark',
    'cancelled' => 'bg-danger'
];

$currentStatus = $order['status'];

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        Order <?= e($order['order_number']) ?> |
        Staff
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
            background: #f4f7f6;
            margin: 0;
        }

        .main-content {
            margin-left: 250px;
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
            font-weight: 700;
            color: #12231f;
        }

        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(0,0,0,.06);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #edf0ef;
            font-weight: 700;
            padding: 18px 20px;
        }

        .summary-card {
            padding: 22px;
        }

        .summary-label {
            color: #7a8783;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .summary-value {
            font-size: 17px;
            font-weight: 700;
            color: #172a25;
        }

        .product-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 10px;
            background: #eef2f1;
        }

        .assignment-box {
            border: 1px solid #dfe8e4;
            border-radius: 14px;
            padding: 20px;
            background: #fbfdfc;
        }

        .driver-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #e9f5ef;
            color: #147a4b;
            font-size: 24px;
        }

        .table > :not(caption) > * > * {
            padding: 14px;
            vertical-align: middle;
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

    </style>

</head>

<body>

<?php include "../includes/staff_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>

<div class="main-content">

    <div class="page-header">

        <div>

            <a
                href="orders.php"
                class="btn btn-sm btn-outline-secondary mb-3"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Orders
            </a>

            <h2 class="page-title mb-1">
                Order <?= e($order['order_number']) ?>
            </h2>

            <div class="text-muted">
                Placed <?= date('M d, Y h:i A', strtotime($order['created_at'])) ?>
            </div>

        </div>

        <div>

            <span class="badge <?= $statusClasses[$currentStatus] ?? 'bg-secondary' ?> fs-6 px-3 py-2">

                <?= e($statusLabels[$currentStatus] ?? ucfirst($currentStatus)) ?>

            </span>

        </div>

    </div>


    <?php if ($success): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle-fill me-2"></i>

            <?= e($success) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="bi bi-exclamation-triangle-fill me-2"></i>

            <?= e($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- SUMMARY -->

    <div class="row g-4 mb-4">

        <div class="col-md-3">

            <div class="card summary-card">

                <div class="summary-label">
                    Customer
                </div>

                <div class="summary-value">
                    <?= e($order['customer_name']) ?>
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card summary-card">

                <div class="summary-label">
                    Order Total
                </div>

                <div class="summary-value">
                    GHS <?= number_format($order['total_amount'], 2) ?>
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card summary-card">

                <div class="summary-label">
                    Payment
                </div>

                <div class="summary-value">

                    <?php if ($order['payment_status'] === 'paid'): ?>

                        <span class="text-success">
                            Paid
                        </span>

                    <?php elseif ($order['payment_status'] === 'failed'): ?>

                        <span class="text-danger">
                            Failed
                        </span>

                    <?php else: ?>

                        <span class="text-warning">
                            Pending
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card summary-card">

                <div class="summary-label">
                    Delivery
                </div>

                <div class="summary-value">

                    <?php if ($delivery): ?>

                        <span class="text-success">
                            Assigned
                        </span>

                    <?php else: ?>

                        <span class="text-warning">
                            Not Assigned
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <div class="row g-4">


        <!-- LEFT -->

        <div class="col-lg-8">


            <!-- CUSTOMER -->

            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-person-circle me-2"></i>
                    Customer Information

                </div>

                <div class="card-body">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <div class="summary-label">
                                Name
                            </div>

                            <strong>
                                <?= e($order['customer_name']) ?>
                            </strong>

                        </div>

                        <div class="col-md-6">

                            <div class="summary-label">
                                Email
                            </div>

                            <strong>
                                <?= e($order['customer_email']) ?>
                            </strong>

                        </div>

                        <div class="col-md-6">

                            <div class="summary-label">
                                Phone
                            </div>

                            <strong>
                                <?= e($order['customer_phone']) ?>
                            </strong>

                        </div>

                        <div class="col-md-6">

                            <div class="summary-label">
                                WhatsApp
                            </div>

                            <strong>
                                <?= e($order['customer_whatsapp'] ?: 'Not provided') ?>
                            </strong>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ITEMS -->

            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-basket2-fill me-2"></i>
                    Order Items

                </div>

                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table class="table mb-0">

                            <thead>

                                <tr>

                                    <th>Product</th>

                                    <th>Quantity</th>

                                    <th>Unit Price</th>

                                    <th>Subtotal</th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($items as $item): ?>

                                <tr>

                                    <td>

                                        <div class="d-flex align-items-center gap-3">

                                            <?php if (!empty($item['image'])): ?>

                                                <img
                                                    src="../assets/images/products/<?= e($item['image']) ?>"
                                                    class="product-img"
                                                    alt=""
                                                >

                                            <?php else: ?>

                                                <div class="product-img d-flex align-items-center justify-content-center">

                                                    <i class="bi bi-image text-muted"></i>

                                                </div>

                                            <?php endif; ?>

                                            <div>

                                                <strong>
                                                    <?= e($item['product_name']) ?>
                                                </strong>

                                            </div>

                                        </div>

                                    </td>

                                    <td>
                                        <?= number_format($item['quantity'], 2) ?>
                                    </td>

                                    <td>
                                        GHS <?= number_format($item['unit_price'], 2) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            GHS <?= number_format($item['subtotal'], 2) ?>
                                        </strong>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>


            <!-- DELIVERY ASSIGNMENT -->

            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-truck me-2"></i>
                    Delivery Assignment

                </div>

                <div class="card-body">


                    <?php if ($delivery): ?>

                        <div class="assignment-box">

                            <div class="d-flex align-items-center gap-3 mb-3">

                                <div class="driver-icon">

                                    <i class="bi bi-person-vcard"></i>

                                </div>

                                <div>

                                    <h5 class="mb-1">
                                        <?= e($delivery['partner_name']) ?>
                                    </h5>

                                    <div class="text-muted">
                                        <?= e($delivery['vehicle_type']) ?>
                                        •
                                        <?= e($delivery['vehicle_registration']) ?>
                                    </div>

                                </div>

                            </div>


                            <div class="row g-3">

                                <div class="col-md-6">

                                    <small class="text-muted d-block">
                                        Phone
                                    </small>

                                    <strong>
                                        <?= e($delivery['partner_phone']) ?>
                                    </strong>

                                </div>


                                <div class="col-md-6">

                                    <small class="text-muted d-block">
                                        Delivery Status
                                    </small>

                                    <span class="badge bg-primary">

                                        <?= e(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $delivery['status']
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </div>


                                <div class="col-md-6">

                                    <small class="text-muted d-block">
                                        Partner Availability
                                    </small>

                                    <span class="badge bg-dark">

                                        <?= e(
                                            ucfirst(
                                                $delivery['partner_status']
                                            )
                                        ) ?>

                                    </span>

                                </div>


                                <div class="col-md-6">

                                    <small class="text-muted d-block">
                                        Assigned
                                    </small>

                                    <strong>

                                        <?= !empty($delivery['assigned_at'])
                                            ? date(
                                                'M d, Y h:i A',
                                                strtotime($delivery['assigned_at'])
                                            )
                                            : '—'
                                        ?>

                                    </strong>

                                </div>

                            </div>


                            <?php if (
                                in_array(
                                    $delivery['status'],
                                    [
                                        'assigned',
                                        'picked_up'
                                    ],
                                    true
                                )
                            ): ?>

                                <hr>

                                <div class="d-flex justify-content-between align-items-center">

                                    <div>

                                        <strong>
                                            Need a different driver?
                                        </strong>

                                        <div class="small text-muted">
                                            You can reassign this delivery.
                                        </div>

                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-outline-success"
                                        data-bs-toggle="modal"
                                        data-bs-target="#assignDeliveryModal"
                                    >
                                        <i class="bi bi-arrow-repeat me-1"></i>
                                        Reassign
                                    </button>

                                </div>

                            <?php endif; ?>

                        </div>


                    <?php elseif ($currentStatus === 'ready'): ?>


                        <div class="alert alert-info">

                            <i class="bi bi-info-circle-fill me-2"></i>

                            This order is ready for delivery.
                            Assign an available delivery partner to continue.

                        </div>


                        <?php if (!empty($availablePartners)): ?>

                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="assign_delivery"
                                >

                                <div class="mb-3">

                                    <label class="form-label fw-semibold">
                                        Select Delivery Partner
                                    </label>

                                    <select
                                        name="delivery_partner_id"
                                        class="form-select"
                                        required
                                    >

                                        <option value="">
                                            -- Select available partner --
                                        </option>

                                        <?php foreach ($availablePartners as $partner): ?>

                                            <option value="<?= (int)$partner['id'] ?>">

                                                <?= e($partner['name']) ?>

                                                —
                                                <?= e($partner['vehicle_type']) ?>

                                                —
                                                <?= e($partner['vehicle_registration']) ?>

                                                —
                                                <?= e($partner['phone']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <button
                                    type="submit"
                                    class="btn btn-success"
                                >

                                    <i class="bi bi-person-check-fill me-1"></i>

                                    Assign Delivery Partner

                                </button>

                            </form>


                        <?php else: ?>

                            <div class="alert alert-warning mb-0">

                                <i class="bi bi-exclamation-circle me-2"></i>

                                There are currently no available delivery
                                partners.

                            </div>

                        <?php endif; ?>


                    <?php else: ?>

                        <div class="text-muted">

                            <i class="bi bi-clock-history me-2"></i>

                            Delivery assignment becomes available when the
                            order is marked <strong>Ready</strong>.

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- DELIVERY ADDRESS -->

            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-geo-alt-fill me-2"></i>
                    Delivery Information

                </div>

                <div class="card-body">

                    <div class="mb-3">

                        <div class="summary-label">
                            Delivery Address
                        </div>

                        <div>
                            <?= nl2br(e($order['delivery_address'])) ?>
                        </div>

                    </div>


                    <div class="row g-3">

                        <div class="col-md-6">

                            <div class="summary-label">
                                Delivery Phone
                            </div>

                            <strong>
                                <?= e($order['delivery_phone']) ?>
                            </strong>

                        </div>


                        <div class="col-md-6">

                            <div class="summary-label">
                                Delivery Fee
                            </div>

                            <strong>
                                GHS <?= number_format($order['delivery_fee'], 2) ?>
                            </strong>

                        </div>

                    </div>


                    <?php if (!empty($order['notes'])): ?>

                        <hr>

                        <div>

                            <div class="summary-label">
                                Customer Notes
                            </div>

                            <?= nl2br(e($order['notes'])) ?>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- RIGHT -->

        <div class="col-lg-4">


            <!-- STATUS -->

            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-arrow-repeat me-2"></i>
                    Update Order Status

                </div>

                <div class="card-body">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="update_status"
                        >

                        <label class="form-label fw-semibold">
                            Order Status
                        </label>

                        <select
                            name="status"
                            class="form-select mb-3"
                            required
                        >

                            <?php foreach ($statusLabels as $value => $label): ?>

                                <option
                                    value="<?= e($value) ?>"
                                    <?= $currentStatus === $value ? 'selected' : '' ?>
                                >

                                    <?= e($label) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>


                        <button
                            type="submit"
                            class="btn btn-dark w-100"
                        >

                            <i class="bi bi-check2-circle me-1"></i>

                            Update Status

                        </button>

                    </form>

                </div>

            </div>


            <!-- TOTAL -->

            <div class="card">

                <div class="card-header">
                    Order Summary
                </div>

                <div class="card-body">

                    <div class="d-flex justify-content-between mb-2">

                        <span class="text-muted">
                            Subtotal
                        </span>

                        <span>
                            GHS <?= number_format($order['subtotal'], 2) ?>
                        </span>

                    </div>


                    <div class="d-flex justify-content-between mb-3">

                        <span class="text-muted">
                            Delivery Fee
                        </span>

                        <span>
                            GHS <?= number_format($order['delivery_fee'], 2) ?>
                        </span>

                    </div>


                    <hr>


                    <div class="d-flex justify-content-between">

                        <strong>
                            Total
                        </strong>

                        <strong class="text-success fs-5">
                            GHS <?= number_format($order['total_amount'], 2) ?>
                        </strong>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- REASSIGN MODAL -->

<?php if ($delivery): ?>

<div
    class="modal fade"
    id="assignDeliveryModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="assign_delivery"
                >

                <div class="modal-header">

                    <h5 class="modal-title">
                        Reassign Delivery
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>


                <div class="modal-body">

                    <p class="text-muted">

                        Select another available delivery partner for this
                        order.

                    </p>


                    <label class="form-label fw-semibold">
                        Delivery Partner
                    </label>

                    <select
                        name="delivery_partner_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Select partner --
                        </option>

                        <?php foreach ($availablePartners as $partner): ?>

                            <option value="<?= (int)$partner['id'] ?>">

                                <?= e($partner['name']) ?>

                                —
                                <?= e($partner['vehicle_registration']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-success"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Assign Partner

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>