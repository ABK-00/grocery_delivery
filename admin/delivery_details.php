<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('admin');
requireCompanyAccess();
$companyId = currentCompanyId();

$deliveryId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($deliveryId <= 0) {
    header("Location: deliveries.php");
    exit;
}

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Badge helpers
|--------------------------------------------------------------------------
*/
function deliveryStatusBadge($status)
{
    $map = [
        'assigned'          => 'warning',
        'picked_up'         => 'info',
        'out_for_delivery'  => 'dark',
        'delivered'         => 'success',
        'cancelled'         => 'danger'
    ];

    $class = $map[$status] ?? 'secondary';

    return '<span class="badge bg-' . $class . '">' .
        htmlspecialchars(ucwords(str_replace('_', ' ', $status))) .
        '</span>';
}

function orderStatusBadge($status)
{
    $map = [
        'pending'            => 'warning',
        'confirmed'          => 'info',
        'preparing'          => 'primary',
        'ready'              => 'success',
        'out_for_delivery'  => 'dark',
        'delivered'          => 'success',
        'cancelled'          => 'danger'
    ];

    $class = $map[$status] ?? 'secondary';

    return '<span class="badge bg-' . $class . '">' .
        htmlspecialchars(ucwords(str_replace('_', ' ', $status))) .
        '</span>';
}

/*
|--------------------------------------------------------------------------
| Load delivery
|--------------------------------------------------------------------------
*/
function getDelivery(PDO $conn, int $deliveryId, int $companyId)
{
    $stmt = $conn->prepare("
        SELECT
            d.*,

            o.order_number,
            o.user_id,
            o.subtotal,
            o.delivery_fee,
            o.total_amount,
            o.delivery_address,
            o.delivery_phone,
            o.notes,
            o.status AS order_status,
            o.payment_status,
            o.payment_reference,
            o.created_at AS order_created_at,

            u.name AS customer_name,
            u.email AS customer_email,
            u.phone AS customer_phone,
            u.whatsapp AS customer_whatsapp,

            dp.vehicle_type,
            dp.vehicle_registration,
            dp.status AS partner_status,

            partner.id AS partner_user_id,
            partner.name AS partner_name,
            partner.email AS partner_email,
            partner.phone AS partner_phone,
            partner.whatsapp AS partner_whatsapp

        FROM deliveries d

        INNER JOIN orders o
            ON o.id = d.order_id

        INNER JOIN users u
            ON u.id = o.user_id

        LEFT JOIN delivery_partners dp
            ON dp.id = d.delivery_partner_id

        LEFT JOIN users partner
            ON partner.id = dp.user_id

        WHERE d.id = ?
          AND o.company_id = ?

        LIMIT 1
    ");

    $stmt->execute([$deliveryId, $companyId]);

    return $stmt->fetch();
}

$delivery = getDelivery($conn, $deliveryId, $companyId);

if (!$delivery) {
    header("Location: deliveries.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle POST actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Assign / reassign delivery partner
    |--------------------------------------------------------------------------
    */
    if ($action === 'assign_partner') {

        $partnerId = isset($_POST['delivery_partner_id'])
            ? (int) $_POST['delivery_partner_id']
            : 0;

        if ($partnerId <= 0) {

            $error = "Please select a delivery partner.";

        } elseif (
            in_array(
                $delivery['status'],
                ['delivered', 'cancelled'],
                true
            )
        ) {

            $error = "A completed or cancelled delivery cannot be reassigned.";

        } else {

            try {

                $conn->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Get selected partner
                |--------------------------------------------------------------------------
                */
                $partnerStmt = $conn->prepare("
                    SELECT
                        dp.id,
                        dp.user_id,
                        dp.status,
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
                ");

                $partnerStmt->execute([$partnerId, $companyId]);
                $partner = $partnerStmt->fetch();

                if (!$partner) {
                    throw new Exception(
                        "The selected delivery partner was not found."
                    );
                }

                if ($partner['account_status'] !== 'active') {
                    throw new Exception(
                        "The selected delivery partner account is not active."
                    );
                }

                if ($partner['status'] !== 'available') {
                    throw new Exception(
                        "The selected delivery partner is not available."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Check if partner already has another active delivery
                |--------------------------------------------------------------------------
                */
                $busyStmt = $conn->prepare("
                    SELECT id
                    FROM deliveries
                    WHERE delivery_partner_id = ?
                      AND id <> ?
                      AND status IN (
                          'assigned',
                          'picked_up',
                          'out_for_delivery'
                      )
                    LIMIT 1
                ");

                $busyStmt->execute([
                    $partnerId,
                    $deliveryId
                ]);

                if ($busyStmt->fetch()) {
                    throw new Exception(
                        "This delivery partner already has another active delivery."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Previous partner
                |--------------------------------------------------------------------------
                */
                $oldPartnerId = !empty($delivery['delivery_partner_id'])
                    ? (int) $delivery['delivery_partner_id']
                    : null;

                /*
                |--------------------------------------------------------------------------
                | Update delivery assignment
                |--------------------------------------------------------------------------
                */
                $update = $conn->prepare("
                    UPDATE deliveries
                    SET
                        delivery_partner_id = ?,
                        status = 'assigned',
                        assigned_at = NOW()
                    WHERE id = ?
                ");

                $update->execute([
                    $partnerId,
                    $deliveryId
                ]);

                /*
                |--------------------------------------------------------------------------
                | Release old partner
                |--------------------------------------------------------------------------
                */
                if ($oldPartnerId && $oldPartnerId !== $partnerId) {

                    $oldPartnerUpdate = $conn->prepare("
                        UPDATE delivery_partners
                        SET status = 'available'
                        WHERE id = ?
                    ");

                    $oldPartnerUpdate->execute([
                        $oldPartnerId
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Mark new partner busy
                |--------------------------------------------------------------------------
                */
                $newPartnerUpdate = $conn->prepare("
                    UPDATE delivery_partners
                    SET status = 'busy'
                    WHERE id = ?
                ");

                $newPartnerUpdate->execute([
                    $partnerId
                ]);

                $conn->commit();

                $message = "Delivery partner assigned successfully.";

            } catch (Throwable $e) {

                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update delivery status
    |--------------------------------------------------------------------------
    */
    elseif ($action === 'update_status') {

        $newStatus = $_POST['status'] ?? '';

        $allowedStatuses = [
            'assigned',
            'picked_up',
            'out_for_delivery',
            'delivered',
            'cancelled'
        ];

        if (!in_array($newStatus, $allowedStatuses, true)) {

            $error = "Invalid delivery status.";

        } else {

            try {

                $conn->beginTransaction();

                $currentStatus = $delivery['status'];
                $partnerId = !empty($delivery['delivery_partner_id'])
                    ? (int) $delivery['delivery_partner_id']
                    : null;

                /*
                |--------------------------------------------------------------------------
                | Delivery requires partner
                |--------------------------------------------------------------------------
                */
                if (
                    in_array(
                        $newStatus,
                        [
                            'picked_up',
                            'out_for_delivery',
                            'delivered'
                        ],
                        true
                    )
                    && !$partnerId
                ) {

                    throw new Exception(
                        "A delivery partner must be assigned first."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Prevent moving delivered/cancelled deliveries
                |--------------------------------------------------------------------------
                */
                if (
                    in_array(
                        $currentStatus,
                        ['delivered', 'cancelled'],
                        true
                    )
                    && $newStatus !== $currentStatus
                ) {

                    throw new Exception(
                        "A delivered or cancelled delivery cannot be changed."
                    );
                }

                /*
                |--------------------------------------------------------------------------

                | Validate transition sequence
                |--------------------------------------------------------------------------
                */
                $validTransition = false;

                if ($currentStatus === $newStatus) {
                    $validTransition = true;
                }

                if (
                    $currentStatus === 'assigned'
                    && in_array(
                        $newStatus,
                        ['picked_up', 'cancelled'],
                        true
                    )
                ) {
                    $validTransition = true;
                }

                if (
                    $currentStatus === 'picked_up'
                    && in_array(
                        $newStatus,
                        ['out_for_delivery', 'cancelled'],
                        true
                    )
                ) {
                    $validTransition = true;
                }

                if (
                    $currentStatus === 'out_for_delivery'
                    && in_array(
                        $newStatus,
                        ['delivered', 'cancelled'],
                        true
                    )
                ) {
                    $validTransition = true;
                }

                if (!$validTransition) {
                    throw new Exception(
                        "Invalid delivery status transition."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Update delivery
                |--------------------------------------------------------------------------
                */
                if ($newStatus === 'picked_up') {

                    $stmt = $conn->prepare("
                        UPDATE deliveries
                        SET
                            status = 'picked_up',
                            picked_up_at = COALESCE(picked_up_at, NOW())
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $deliveryId
                    ]);

                } elseif ($newStatus === 'out_for_delivery') {

                    $stmt = $conn->prepare("
                        UPDATE deliveries
                        SET
                            status = 'out_for_delivery',
                            picked_up_at = COALESCE(picked_up_at, NOW())
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $deliveryId
                    ]);

                } elseif ($newStatus === 'delivered') {

                    $stmt = $conn->prepare("
                        UPDATE deliveries
                        SET
                            status = 'delivered',
                            delivered_at = COALESCE(delivered_at, NOW())
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $deliveryId
                    ]);

                } elseif ($newStatus === 'cancelled') {

                    $stmt = $conn->prepare("
                        UPDATE deliveries
                        SET
                            status = 'cancelled'
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $deliveryId
                    ]);

                } else {

                    $stmt = $conn->prepare("
                        UPDATE deliveries
                        SET status = 'assigned'
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $deliveryId
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Synchronize order status
                |--------------------------------------------------------------------------
                */
                $orderStatus = null;

                switch ($newStatus) {

                    case 'assigned':
                        $orderStatus = 'ready';
                        break;

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

                    $orderUpdate = $conn->prepare("
                        UPDATE orders
                        SET status = ?
                        WHERE id = ?
                    ");

                    $orderUpdate->execute([
                        $orderStatus,
                        $delivery['order_id']
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Update partner availability
                |--------------------------------------------------------------------------
                */
                if ($partnerId) {

                    if (
                        in_array(
                            $newStatus,
                            [
                                'assigned',
                                'picked_up',
                                'out_for_delivery'
                            ],
                            true
                        )
                    ) {

                        $partnerUpdate = $conn->prepare("
                            UPDATE delivery_partners
                            SET status = 'busy'
                            WHERE id = ?
                        ");

                        $partnerUpdate->execute([
                            $partnerId
                        ]);

                    } elseif (
                        in_array(
                            $newStatus,
                            ['delivered', 'cancelled'],
                            true
                        )
                    ) {

                        $partnerUpdate = $conn->prepare("
                            UPDATE delivery_partners
                            SET status = 'available'
                            WHERE id = ?
                        ");

                        $partnerUpdate->execute([
                            $partnerId
                        ]);
                    }
                }

                $conn->commit();

                $message = "Delivery status updated successfully.";

            } catch (Throwable $e) {

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
| Reload delivery
|--------------------------------------------------------------------------
*/
$delivery = getDelivery($conn, $deliveryId, $companyId);

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
    INNER JOIN users u
        ON u.id = dp.user_id
    WHERE u.role = 'delivery_partner'
      AND u.status = 'active'
      AND u.company_id = ?
      AND dp.status = 'available'
    ORDER BY u.name ASC
");

$partnersStmt->execute([$companyId]);
$availablePartners = $partnersStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Recent GPS locations
|--------------------------------------------------------------------------
*/
$locationStmt = $conn->prepare("
    SELECT
        latitude,
        longitude,
        accuracy,
        speed,
        heading,
        recorded_at
    FROM delivery_locations
    WHERE delivery_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 20
");

$locationStmt->execute([
    $deliveryId
]);

$locations = $locationStmt->fetchAll();

$latestLocation = $locations[0] ?? null;

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Delivery Details | <?= htmlspecialchars($delivery['order_number']) ?>
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

        :root {
            --navy: #071a2b;
            --navy-light: #0d2940;
            --green: #198754;
            --bg: #f4f7f9;
            --text: #17212b;
            --muted: #6c757d;
            --border: #e5e9ed;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        .main-content {
            margin-left: 250px;
            padding: 30px;
            min-height: 100vh;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: var(--muted);
            margin-top: 6px;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 18px 20px;
            font-weight: 700;
        }

        .card-body {
            padding: 20px;
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

        .summary-card {
            background: linear-gradient(
                135deg,
                var(--navy),
                var(--navy-light)
            );
            color: #fff;
            border: 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,.1);
        }

        .summary-row:last-child {
            border-bottom: 0;
        }

        .summary-total {
            font-size: 22px;
            font-weight: 800;
        }

        .gps-card {
            border-left: 4px solid var(--green);
        }

        .gps-value {
            font-family: monospace;
            font-size: 14px;
        }

        .table th {
            white-space: nowrap;
            font-size: 13px;
        }

        .table td {
            vertical-align: middle;
        }

        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: "";
            position: absolute;
            left: 9px;
            top: 5px;
            bottom: 5px;
            width: 2px;
            background: #dee2e6;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 24px;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -27px;
            top: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--green);
            border: 3px solid #dff3e8;
        }

        .empty-state {
            padding: 35px 15px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 40px;
            display: block;
            margin-bottom: 10px;
        }

        @media (max-width: 991px) {

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
            }

        }

        @media (max-width: 575px) {

            .main-content {
                padding: 15px;
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


<main class="main-content">

    <!-- PAGE HEADER -->
    <div class="page-header">

        <div>

            <div class="mb-2">

                <a
                    href="deliveries.php"
                    class="btn btn-sm btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Deliveries
                </a>

            </div>

            <h1 class="page-title">
                Delivery Details
            </h1>

            <div class="page-subtitle">

                Order:
                <strong>
                    <?= htmlspecialchars($delivery['order_number']) ?>
                </strong>

                &nbsp;·&nbsp;

                Delivery #<?= (int)$delivery['id'] ?>

            </div>

        </div>

        <div>

            <?= deliveryStatusBadge($delivery['status']) ?>

        </div>

    </div>

    <?php if ($message): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle me-2"></i>

            <?= htmlspecialchars($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="bi bi-exclamation-triangle me-2"></i>

            <?= htmlspecialchars($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>

    <div class="row g-4">

        <!-- LEFT -->
        <div class="col-lg-8">

            <!-- CUSTOMER -->
            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-person me-2"></i>

                    Customer

                </div>

                <div class="card-body">

                    <div class="row g-4">

                        <div class="col-md-6">

                            <div class="info-label">
                                Name
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars($delivery['customer_name']) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="info-label">
                                Email
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars($delivery['customer_email']) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="info-label">
                                Phone
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars(
                                    $delivery['customer_phone'] ?: 'Not provided'
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="info-label">
                                WhatsApp
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars(
                                    $delivery['customer_whatsapp'] ?: 'Not provided'
                                ) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- DELIVERY ADDRESS -->
            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-geo-alt me-2"></i>

                    Delivery Address

                </div>

                <div class="card-body">

                    <div class="mb-3">

                        <div class="info-label">
                            Address
                        </div>

                        <div class="info-value">
                            <?= nl2br(
                                htmlspecialchars(
                                    $delivery['delivery_address']
                                )
                            ) ?>
                        </div>

                    </div>

                    <div class="row g-4">

                        <div class="col-md-6">

                            <div class="info-label">
                                Delivery Phone
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars(
                                    $delivery['delivery_phone']
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="info-label">
                                Order Status
                            </div>

                            <div>
                                <?= orderStatusBadge(
                                    $delivery['order_status']
                                ) ?>
                            </div>

                        </div>

                    </div>

                    <?php if (!empty($delivery['notes'])): ?>

                        <hr>

                        <div>

                            <div class="info-label">
                                Customer Notes
                            </div>

                            <div class="p-3 bg-light rounded">
                                <?= nl2br(
                                    htmlspecialchars(
                                        $delivery['notes']
                                    )
                                ) ?>
                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- DELIVERY PARTNER -->
            <div class="card mb-4">

                <div class="card-header d-flex justify-content-between align-items-center">

                    <span>

                        <i class="bi bi-bicycle me-2"></i>

                        Delivery Partner

                    </span>

                    <?php if ($delivery['partner_name']): ?>

                        <span class="badge bg-success">
                            Assigned
                        </span>

                    <?php else: ?>

                        <span class="badge bg-secondary">
                            Unassigned
                        </span>

                    <?php endif; ?>

                </div>

                <div class="card-body">

                    <?php if ($delivery['partner_name']): ?>

                        <div class="row g-4">

                            <div class="col-md-6">

                                <div class="info-label">
                                    Name
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars(
                                        $delivery['partner_name']
                                    ) ?>
                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-label">
                                    Phone
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars(
                                        $delivery['partner_phone'] ?: 'Not provided'
                                    ) ?>
                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-label">
                                    Vehicle Type
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars(
                                        $delivery['vehicle_type']
                                    ) ?>
                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-label">
                                    Registration
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars(
                                        $delivery['vehicle_registration']
                                    ) ?>
                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-label">
                                    Current Availability
                                </div>

                                <div class="info-value">

                                    <?php

                                    $partnerStatus = $delivery['partner_status'] ?? 'offline';

                                    $partnerClass = [
                                        'available' => 'success',
                                        'busy'       => 'warning',
                                        'offline'    => 'secondary'
                                    ][$partnerStatus] ?? 'secondary';

                                    ?>

                                    <span class="badge bg-<?= $partnerClass ?>">
                                        <?= htmlspecialchars(
                                            ucfirst($partnerStatus)
                                        ) ?>
                                    </span>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-label">
                                    Assigned At
                                </div>

                                <div class="info-value">

                                    <?= $delivery['assigned_at']
                                        ? date(
                                            'd M Y, h:i A',
                                            strtotime($delivery['assigned_at'])
                                        )
                                        : '—'
                                    ?>

                                </div>

                            </div>

                        </div>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-person-x"></i>

                            <div class="fw-semibold">
                                No delivery partner assigned
                            </div>

                            <div class="small">
                                Assign an available delivery partner below.
                            </div>

                        </div>

                    <?php endif; ?>

                    <?php if (
                        !in_array(
                            $delivery['status'],
                            ['delivered', 'cancelled'],
                            true
                        )
                    ): ?>

                        <hr>

                        <button
                            type="button"
                            class="btn btn-outline-success"
                            data-bs-toggle="modal"
                            data-bs-target="#assignModal"
                        >

                            <i class="bi bi-person-plus me-1"></i>

                            <?= $delivery['partner_name']
                                ? 'Reassign Partner'
                                : 'Assign Partner'
                            ?>

                        </button>

                    <?php endif; ?>

                </div>

            </div>

            <!-- GPS -->
            <div class="card gps-card mb-4">

                <div class="card-header">

                    <i class="bi bi-crosshair me-2"></i>

                    GPS Tracking

                </div>

                <div class="card-body">

                    <?php if ($latestLocation): ?>

                        <div class="row g-4">

                            <div class="col-md-4">

                                <div class="info-label">
                                    Latitude
                                </div>

                                <div class="gps-value">
                                    <?= htmlspecialchars(
                                        $latestLocation['latitude']
                                    ) ?>
                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="info-label">
                                    Longitude
                                </div>

                                <div class="gps-value">
                                    <?= htmlspecialchars(
                                        $latestLocation['longitude']
                                    ) ?>
                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="info-label">
                                    Accuracy
                                </div>

                                <div class="info-value">

                                    <?= $latestLocation['accuracy'] !== null
                                        ? number_format(
                                            (float)$latestLocation['accuracy'],
                                            1
                                        ) . ' m'
                                        : '—'
                                    ?>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="info-label">
                                    Speed
                                </div>

                                <div class="info-value">

                                    <?= $latestLocation['speed'] !== null
                                        ? number_format(
                                            (float)$latestLocation['speed'],
                                            2
                                        ) . ' m/s'
                                        : '—'
                                    ?>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="info-label">
                                    Heading
                                </div>

                                <div class="info-value">

                                    <?= $latestLocation['heading'] !== null
                                        ? number_format(
                                            (float)$latestLocation['heading'],
                                            1
                                        ) . '°'
                                        : '—'
                                    ?>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="info-label">
                                    Last Update
                                </div>

                                <div class="info-value">

                                    <?= date(
                                        'd M Y, h:i:s A',
                                        strtotime(
                                            $latestLocation['recorded_at']
                                        )
                                    ) ?>

                                </div>

                            </div>

                        </div>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-geo-alt"></i>

                            <div class="fw-semibold">
                                No GPS location received yet
                            </div>

                            <div class="small">
                                GPS information will appear here when the
                                delivery partner starts sharing their location.
                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- LOCATION HISTORY -->
            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-pin-map me-2"></i>

                    Recent Location History

                </div>

                <div class="card-body p-0">

                    <?php if ($locations): ?>

                        <div class="table-responsive">

                            <table class="table table-hover mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th class="ps-4">
                                            Time
                                        </th>

                                        <th>
                                            Latitude
                                        </th>

                                        <th>
                                            Longitude
                                        </th>

                                        <th>
                                            Accuracy
                                        </th>

                                        <th>
                                            Speed
                                        </th>

                                        <th>
                                            Heading
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($locations as $location): ?>

                                    <tr>

                                        <td class="ps-4">

                                            <?= date(
                                                'd M Y, h:i:s A',
                                                strtotime(
                                                    $location['recorded_at']
                                                )
                                            ) ?>

                                        </td>

                                        <td class="font-monospace small">

                                            <?= htmlspecialchars(
                                                $location['latitude']
                                            ) ?>

                                        </td>

                                        <td class="font-monospace small">

                                            <?= htmlspecialchars(
                                                $location['longitude']
                                            ) ?>

                                        </td>

                                        <td>

                                            <?= $location['accuracy'] !== null
                                                ? number_format(
                                                    (float)$location['accuracy'],
                                                    1
                                                ) . ' m'
                                                : '—'
                                            ?>

                                        </td>

                                        <td>

                                            <?= $location['speed'] !== null
                                                ? number_format(
                                                    (float)$location['speed'],
                                                    2
                                                ) . ' m/s'
                                                : '—'
                                            ?>

                                        </td>

                                        <td>

                                            <?= $location['heading'] !== null
                                                ? number_format(
                                                    (float)$location['heading'],
                                                    1
                                                ) . '°'
                                                : '—'
                                            ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-map"></i>

                            No location history available.

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <!-- RIGHT -->
        <div class="col-lg-4">

            <!-- ORDER SUMMARY -->
            <div class="card summary-card mb-4">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        Order Summary
                    </h5>

                    <div class="summary-row">

                        <span>Order</span>

                        <strong>
                            <?= htmlspecialchars(
                                $delivery['order_number']
                            ) ?>
                        </strong>

                    </div>

                    <div class="summary-row">

                        <span>Subtotal</span>

                        <strong>
                            GHS <?= number_format(
                                (float)$delivery['subtotal'],
                                2
                            ) ?>
                        </strong>

                    </div>

                    <div class="summary-row">

                        <span>Delivery Fee</span>

                        <strong>
                            GHS <?= number_format(
                                (float)$delivery['delivery_fee'],
                                2
                            ) ?>
                        </strong>

                    </div>

                    <div class="summary-row mt-2 pt-3">

                        <span>Total</span>

                        <strong class="summary-total">

                            GHS <?= number_format(
                                (float)$delivery['total_amount'],
                                2
                            ) ?>

                        </strong>

                    </div>

                </div>

            </div>

            <!-- STATUS -->
            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-arrow-repeat me-2"></i>

                    Delivery Status

                </div>

                <div class="card-body">

                    <div class="mb-3">

                        <div class="info-label">
                            Current Status
                        </div>

                        <?= deliveryStatusBadge(
                            $delivery['status']
                        ) ?>

                    </div>

                    <?php if (
                        !in_array(
                            $delivery['status'],
                            ['delivered', 'cancelled'],
                            true
                        )
                    ): ?>

                        <form method="POST">

                            <input
                                type="hidden"
                                name="action"
                                value="update_status"
                            >

                            <label class="form-label fw-semibold">
                                Change Status
                            </label>

                            <select
                                name="status"
                                class="form-select mb-3"
                                required
                            >

                                <?php

                                $statuses = [
                                    'assigned' => 'Assigned',
                                    'picked_up' => 'Picked Up',
                                    'out_for_delivery' => 'Out for Delivery',
                                    'delivered' => 'Delivered',
                                    'cancelled' => 'Cancelled'
                                ];

                                foreach ($statuses as $value => $label):

                                ?>

                                    <option
                                        value="<?= $value ?>"
                                        <?= $delivery['status'] === $value
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= $label ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <button
                                type="submit"
                                class="btn btn-success w-100"
                            >

                                <i class="bi bi-check-lg me-1"></i>

                                Update Delivery

                            </button>

                        </form>

                    <?php else: ?>

                        <div class="alert alert-light mb-0">

                            <i class="bi bi-lock me-1"></i>

                            This delivery is
                            <strong>
                                <?= htmlspecialchars(
                                    $delivery['status']
                                ) ?>
                            </strong>
                            and can no longer be changed.

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- TIMELINE -->
            <div class="card mb-4">

                <div class="card-header">

                    <i class="bi bi-clock-history me-2"></i>

                    Delivery Timeline

                </div>

                <div class="card-body">

                    <div class="timeline">

                        <div class="timeline-item">

                            <div class="timeline-dot"></div>

                            <div class="fw-semibold">
                                Delivery Created
                            </div>

                            <small class="text-muted">

                                <?= $delivery['created_at']
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $delivery['created_at']
                                        )
                                    )
                                    : '—'
                                ?>

                            </small>

                        </div>

                        <?php if ($delivery['assigned_at']): ?>

                            <div class="timeline-item">

                                <div class="timeline-dot"></div>

                                <div class="fw-semibold">
                                    Partner Assigned
                                </div>

                                <small class="text-muted">

                                    <?= date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $delivery['assigned_at']
                                        )
                                    ) ?>

                                </small>

                            </div>

                        <?php endif; ?>

                        <?php if ($delivery['picked_up_at']): ?>

                            <div class="timeline-item">

                                <div class="timeline-dot"></div>

                                <div class="fw-semibold">
                                    Order Picked Up
                                </div>

                                <small class="text-muted">

                                    <?= date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $delivery['picked_up_at']
                                        )
                                    ) ?>

                                </small>

                            </div>

                        <?php endif; ?>

                        <?php if (
                            $delivery['status'] === 'out_for_delivery'
                            || $delivery['status'] === 'delivered'
                        ): ?>

                            <div class="timeline-item">

                                <div class="timeline-dot"></div>

                                <div class="fw-semibold">
                                    Out for Delivery
                                </div>

                            </div>

                        <?php endif; ?>

                        <?php if ($delivery['delivered_at']): ?>

                            <div class="timeline-item">

                                <div class="timeline-dot"></div>

                                <div class="fw-semibold">
                                    Delivered
                                </div>

                                <small class="text-muted">

                                    <?= date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $delivery['delivered_at']
                                        )
                                    ) ?>

                                </small>

                            </div>

                        <?php endif; ?>

                        <?php if ($delivery['status'] === 'cancelled'): ?>

                            <div class="timeline-item">

                                <div
                                    class="timeline-dot"
                                    style="background:#dc3545;border-color:#f8d7da;"
                                ></div>

                                <div class="fw-semibold text-danger">
                                    Cancelled
                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <!-- ORDER LINK -->
            <div class="card">

                <div class="card-body">

                    <h6 class="fw-bold mb-2">
                        Related Order
                    </h6>

                    <p class="text-muted small mb-3">
                        View the complete order, products, customer
                        information and payment details.
                    </p>

                    <a
                        href="order_details.php?id=<?= (int)$delivery['order_id'] ?>"
                        class="btn btn-outline-primary w-100"
                    >

                        <i class="bi bi-box-arrow-up-right me-1"></i>

                        View Order Details

                    </a>

                </div>

            </div>

        </div>

    </div>

</main>

<!-- ASSIGN PARTNER MODAL -->
<div
    class="modal fade"
    id="assignModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-bicycle me-2"></i>

                    Assign Delivery Partner

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <form method="POST">

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="action"
                        value="assign_partner"
                    >

                    <label class="form-label fw-semibold">
                        Available Delivery Partner
                    </label>

                    <select
                        name="delivery_partner_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select delivery partner
                        </option>

                        <?php foreach ($availablePartners as $partner): ?>

                            <option
                                value="<?= (int)$partner['id'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $partner['name']
                                ) ?>

                                —
                                <?= htmlspecialchars(
                                    $partner['vehicle_type']
                                ) ?>

                                —
                                <?= htmlspecialchars(
                                    $partner['vehicle_registration']
                                ) ?>

                                <?php if (!empty($partner['phone'])): ?>

                                    —
                                    <?= htmlspecialchars(
                                        $partner['phone']
                                    ) ?>

                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <?php if (!$availablePartners): ?>

                        <div class="alert alert-warning mt-3 mb-0">

                            <i class="bi bi-exclamation-circle me-1"></i>

                            There are currently no available delivery
                            partners.

                        </div>

                    <?php endif; ?>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-success"
                        <?= !$availablePartners
                            ? 'disabled'
                            : ''
                        ?>
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Assign Partner

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>