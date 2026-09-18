<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('delivery_partner');
requireCompanyAccess();
$companyId = currentCompanyId();

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id, vehicle_type, vehicle_registration
    FROM delivery_partners dp
    INNER JOIN users ux ON ux.id=dp.user_id
    WHERE dp.user_id = ? AND ux.company_id = ?
    LIMIT 1
");
$stmt->execute([$userId,$companyId]);

$partner = $stmt->fetch();

if (!$partner) {
    die("Delivery partner profile not found.");
}

$partnerId = $partner['id'];

$deliveryId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($deliveryId <= 0) {
    redirect("orders.php");
}

/*
|--------------------------------------------------------------------------
| Handle delivery status update
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $newStatus = $_POST['status'] ?? '';

    $allowedStatuses = [
        'assigned',
        'picked_up',
        'out_for_delivery',
        'delivered',
        'cancelled'
    ];

    if (!in_array($newStatus, $allowedStatuses, true)) {
        $_SESSION['delivery_error'] = "Invalid delivery status.";
        redirect("order_details.php?id=" . $deliveryId);
    }

    try {

        $conn->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Lock delivery
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT *
            FROM deliveries
            WHERE id = ?
            AND delivery_partner_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $deliveryId,
            $partnerId
        ]);

        $delivery = $stmt->fetch();

        if (!$delivery) {
            throw new Exception("Delivery not found.");
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent invalid transitions
        |--------------------------------------------------------------------------
        */

        $currentStatus = $delivery['status'];

        $transitions = [

            'assigned' => [
                'picked_up',
                'cancelled'
            ],

            'picked_up' => [
                'out_for_delivery',
                'cancelled'
            ],

            'out_for_delivery' => [
                'delivered',
                'cancelled'
            ],

            'delivered' => [],

            'cancelled' => []

        ];

        if (!in_array(
            $newStatus,
            $transitions[$currentStatus] ?? [],
            true
        )) {

            throw new Exception(
                "This delivery cannot move from " .
                str_replace('_', ' ', $currentStatus) .
                " to " .
                str_replace('_', ' ', $newStatus) .
                "."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update delivery timestamps
        |--------------------------------------------------------------------------
        */

        $assignedAt = $delivery['assigned_at'];
        $pickedUpAt = $delivery['picked_up_at'];
        $deliveredAt = $delivery['delivered_at'];

        if ($newStatus === 'assigned' && !$assignedAt) {
            $assignedAt = date('Y-m-d H:i:s');
        }

        if ($newStatus === 'picked_up') {
            $pickedUpAt = date('Y-m-d H:i:s');
        }

        if ($newStatus === 'delivered') {
            $deliveredAt = date('Y-m-d H:i:s');
        }

        $stmt = $conn->prepare("
            UPDATE deliveries
            SET
                status = ?,
                assigned_at = ?,
                picked_up_at = ?,
                delivered_at = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $newStatus,
            $assignedAt,
            $pickedUpAt,
            $deliveredAt,
            $deliveryId
        ]);

        /*
        |--------------------------------------------------------------------------
        | Get associated order
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT order_id
            FROM deliveries
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$deliveryId]);

        $orderId = $stmt->fetchColumn();

        if (!$orderId) {
            throw new Exception("Associated order not found.");
        }

        /*
        |--------------------------------------------------------------------------
        | Sync delivery status with main order
        |--------------------------------------------------------------------------
        */

        $orderStatus = null;

        switch ($newStatus) {

            case 'picked_up':
                $orderStatus = 'ready';
                break;

            case 'out_for_delivery':
                $orderStatus = 'out_for_delivery';
                break;

            case 'delivered':
                $orderStatus = 'delivered';
                break;

            case 'cancelled':
                $orderStatus = 'cancelled';
                break;
        }

        if ($orderStatus) {

            $stmt = $conn->prepare("
                UPDATE orders
                SET status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $orderStatus,
                $orderId
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Update delivery partner availability
        |--------------------------------------------------------------------------
        */

        if (
            $newStatus === 'picked_up' ||
            $newStatus === 'out_for_delivery'
        ) {

            $stmt = $conn->prepare("
                UPDATE delivery_partners
                SET status = 'busy'
                WHERE id = ?
            ");

            $stmt->execute([$partnerId]);
        }

        if (
            $newStatus === 'delivered' ||
            $newStatus === 'cancelled'
        ) {

            $stmt = $conn->prepare("
                UPDATE delivery_partners
                SET status = 'available'
                WHERE id = ?
            ");

            $stmt->execute([$partnerId]);
        }

        $conn->commit();

        $_SESSION['delivery_success'] =
            "Delivery status updated successfully.";

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $_SESSION['delivery_error'] = $e->getMessage();
    }

    redirect("order_details.php?id=" . $deliveryId);
}

/*
|--------------------------------------------------------------------------
| Load delivery
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        d.*,

        o.id AS order_id,
        o.order_number,
        o.subtotal,
        o.delivery_fee,
        o.total_amount,
        o.delivery_address,
        o.delivery_phone,
        o.notes,
        o.status AS order_status,
        o.payment_status,

        u.name AS customer_name,
        u.email AS customer_email,
        u.phone AS customer_phone,
        u.whatsapp AS customer_whatsapp

    FROM deliveries d

    INNER JOIN orders o
        ON o.id = d.order_id

    INNER JOIN users u
        ON u.id = o.user_id

    WHERE d.id = ?
    AND d.delivery_partner_id = ?
    AND o.company_id = ?

    LIMIT 1
");

$stmt->execute([
    $deliveryId,
    $partnerId,
    $companyId
]);

$delivery = $stmt->fetch();

if (!$delivery) {
    die("Delivery not found or you are not authorized to view it.");
}

/*
|--------------------------------------------------------------------------
| Order items
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        oi.product_name,
        oi.quantity,
        oi.unit_price,
        oi.subtotal,
        p.unit,
        p.image

    FROM order_items oi

    LEFT JOIN products p
        ON p.id = oi.product_id

    WHERE oi.order_id = ?

    ORDER BY oi.id ASC
");

$stmt->execute([
    $delivery['order_id']
]);

$items = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
    <?= e($delivery['order_number']) ?> | Delivery
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

.status-box {
    border-radius: 16px;
    padding: 20px;
    background: #f8f9fa;
}

.product-img {
    width: 55px;
    height: 55px;
    object-fit: cover;
    border-radius: 10px;
    background: #eee;
}

.address-box {
    background: #f8f9fa;
    border-radius: 15px;
    padding: 20px;
}

.action-btn {
    min-height: 52px;
    border-radius: 12px;
    font-weight: 600;
}

@media(max-width: 991px) {

    .main-content {
        margin-left: 0;
        padding: 20px;
    }

}

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
                <a href="orders.php" class="text-decoration-none text-muted small">
                    <i class="bi bi-arrow-left"></i> Back to Deliveries
                </a>
                <h1 class="topbar-title mt-1">
                    Order <?= e($delivery['order_number']) ?>
                </h1>
            </div>
        </div>
    </div>

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

        <span class="badge <?= $badge ?> px-3 py-2">

            <?= ucwords(
                str_replace(
                    '_',
                    ' ',
                    $delivery['status']
                )
            ) ?>

        </span>

    </div>


    <?php if (!empty($_SESSION['delivery_success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle me-2"></i>

            <?= e($_SESSION['delivery_success']) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

        <?php unset($_SESSION['delivery_success']); ?>

    <?php endif; ?>


    <?php if (!empty($_SESSION['delivery_error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="bi bi-exclamation-triangle me-2"></i>

            <?= e($_SESSION['delivery_error']) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

        <?php unset($_SESSION['delivery_error']); ?>

    <?php endif; ?>


    <div class="row g-4">


        <!-- LEFT -->

        <div class="col-lg-8">


            <!-- Customer -->

            <div class="card mb-4">

                <div class="card-body">

                    <h5 class="fw-bold mb-4">

                        <i class="bi bi-person-circle me-2 text-success"></i>

                        Customer

                    </h5>

                    <div class="row g-3">

                        <div class="col-md-6">

                            <small class="text-muted">
                                Name
                            </small>

                            <div class="fw-semibold">
                                <?= e($delivery['customer_name']) ?>
                            </div>

                        </div>


                        <div class="col-md-6">

                            <small class="text-muted">
                                Phone
                            </small>

                            <div class="fw-semibold">

                                <a
                                    href="tel:<?= e($delivery['delivery_phone']) ?>"
                                    class="text-decoration-none"
                                >
                                    <?= e($delivery['delivery_phone']) ?>
                                </a>

                            </div>

                        </div>


                        <div class="col-md-6">

                            <small class="text-muted">
                                Email
                            </small>

                            <div>
                                <?= e($delivery['customer_email']) ?>
                            </div>

                        </div>


                        <?php if (!empty($delivery['customer_whatsapp'])): ?>

                        <div class="col-md-6">

                            <small class="text-muted">
                                WhatsApp
                            </small>

                            <div>

                                <?= e($delivery['customer_whatsapp']) ?>

                            </div>

                        </div>

                        <?php endif; ?>

                    </div>

                    <div class="d-flex gap-2 mt-4">

                        <a
                            href="tel:<?= e($delivery['delivery_phone']) ?>"
                            class="btn btn-success action-btn px-4"
                        >

                            <i class="bi bi-telephone-fill me-2"></i>

                            Call Customer

                        </a>

                    </div>

                </div>

            </div>


            <!-- Delivery address -->

            <div class="card mb-4">

                <div class="card-body">

                    <h5 class="fw-bold mb-4">

                        <i class="bi bi-geo-alt-fill me-2 text-danger"></i>

                        Delivery Address

                    </h5>

                    <div class="address-box">

                        <div class="fw-semibold mb-2">

                            <?= nl2br(
                                e($delivery['delivery_address'])
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Items -->

            <div class="card">

                <div class="card-body">

                    <h5 class="fw-bold mb-4">

                        <i class="bi bi-basket2 me-2 text-success"></i>

                        Order Items

                    </h5>


                    <?php foreach ($items as $item): ?>

                        <div class="d-flex align-items-center border-bottom py-3">

                            <?php

                            $image = !empty($item['image'])
                                ? "../assets/images/products/" . $item['image']
                                : "../assets/images/no-image.png";

                            ?>

                            <img
                                src="<?= e($image) ?>"
                                class="product-img me-3"
                                onerror="this.src='../assets/images/no-image.png'"
                            >

                            <div class="flex-grow-1">

                                <div class="fw-semibold">

                                    <?= e($item['product_name']) ?>

                                </div>

                                <small class="text-muted">

                                    <?= e($item['quantity']) ?>

                                    <?= e($item['unit'] ?? 'piece') ?>

                                    × GHS
                                    <?= number_format(
                                        $item['unit_price'],
                                        2
                                    ) ?>

                                </small>

                            </div>

                            <div class="fw-bold">

                                GHS
                                <?= number_format(
                                    $item['subtotal'],
                                    2
                                ) ?>

                            </div>

                        </div>

                    <?php endforeach; ?>


                    <div class="mt-4">

                        <div class="d-flex justify-content-between mb-2">

                            <span>
                                Subtotal
                            </span>

                            <span>
                                GHS <?= number_format(
                                    $delivery['subtotal'],
                                    2
                                ) ?>
                            </span>

                        </div>


                        <div class="d-flex justify-content-between mb-2">

                            <span>
                                Delivery Fee
                            </span>

                            <span>
                                GHS <?= number_format(
                                    $delivery['delivery_fee'],
                                    2
                                ) ?>
                            </span>

                        </div>


                        <hr>


                        <div class="d-flex justify-content-between">

                            <strong>
                                Total
                            </strong>

                            <strong class="text-success fs-5">

                                GHS
                                <?= number_format(
                                    $delivery['total_amount'],
                                    2
                                ) ?>

                            </strong>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- RIGHT -->

        <div class="col-lg-4">


            <!-- Status -->

            <div class="card mb-4">

                <div class="card-body">

                    <h5 class="fw-bold mb-4">
                        Delivery Status
                    </h5>


                    <?php if ($delivery['status'] === 'assigned'): ?>

                        <form method="POST">

                            <input
                                type="hidden"
                                name="status"
                                value="picked_up"
                            >

                            <button
                                class="btn btn-primary w-100 action-btn"
                                type="submit"
                            >

                                <i class="bi bi-box-seam me-2"></i>

                                Confirm Pickup

                            </button>

                        </form>


                    <?php elseif ($delivery['status'] === 'picked_up'): ?>

                        <div class="status-box mb-3">

                            <div class="fw-bold">
                                Order Picked Up
                            </div>

                            <small class="text-muted">
                                Take the order to the customer.
                            </small>

                        </div>


                        <form method="POST">

                            <input
                                type="hidden"
                                name="status"
                                value="out_for_delivery"
                            >

                            <button
                                class="btn btn-success w-100 action-btn"
                                type="submit"
                            >

                                <i class="bi bi-geo-alt-fill me-2"></i>

                                Start Delivery

                            </button>

                        </form>


                    <?php elseif ($delivery['status'] === 'out_for_delivery'): ?>

                        <div class="status-box mb-3">

                            <div class="fw-bold text-success">

                                <i class="bi bi-broadcast me-2"></i>

                                Delivery In Progress

                            </div>

                            <small class="text-muted">

                                Your location can now be shared
                                with the customer.

                            </small>

                        </div>


                        <a
                            href="track_delivery.php?id=<?= $deliveryId ?>"
                            class="btn btn-outline-success w-100 action-btn mb-3"
                        >

                            <i class="bi bi-geo-alt me-2"></i>

                            Open GPS Tracking

                        </a>


                        <form method="POST">

                            <input
                                type="hidden"
                                name="status"
                                value="delivered"
                            >

                            <button
                                class="btn btn-dark w-100 action-btn"
                                type="submit"
                            >

                                <i class="bi bi-check-circle me-2"></i>

                                Mark as Delivered

                            </button>

                        </form>


                    <?php elseif ($delivery['status'] === 'delivered'): ?>

                        <div class="alert alert-success mb-0">

                            <i class="bi bi-check-circle-fill me-2"></i>

                            Delivery completed successfully.

                        </div>


                    <?php elseif ($delivery['status'] === 'cancelled'): ?>

                        <div class="alert alert-danger mb-0">

                            This delivery has been cancelled.

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- Payment -->

            <div class="card mb-4">

                <div class="card-body">

                    <h6 class="fw-bold mb-3">
                        Payment
                    </h6>

                    <div class="d-flex justify-content-between">

                        <span>
                            Payment Status
                        </span>

                        <?php if ($delivery['payment_status'] === 'paid'): ?>

                            <span class="badge bg-success">
                                Paid
                            </span>

                        <?php else: ?>

                            <span class="badge bg-warning text-dark">
                                <?= ucfirst(
                                    $delivery['payment_status']
                                ) ?>
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- Notes -->

            <?php if (!empty($delivery['notes'])): ?>

            <div class="card">

                <div class="card-body">

                    <h6 class="fw-bold">
                        Customer Notes
                    </h6>

                    <p class="text-muted mb-0">

                        <?= nl2br(
                            e($delivery['notes'])
                        ) ?>

                    </p>

                </div>

            </div>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>
</html>