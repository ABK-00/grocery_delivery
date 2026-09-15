<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('customer');

$userId = $_SESSION['user_id'];

$orderId = filter_input(
    INPUT_GET,
    'order',
    FILTER_VALIDATE_INT
);

if (!$orderId) {
    header("Location: ../orders.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Order
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        o.*,

        d.id AS delivery_id,
        d.status AS delivery_status,
        d.assigned_at,
        d.picked_up_at,
        d.delivered_at,

        dp.vehicle_type,
        dp.vehicle_registration,

        u.name AS driver_name,
        u.phone AS driver_phone

    FROM orders o

    LEFT JOIN deliveries d
        ON d.order_id = o.id

    LEFT JOIN delivery_partners dp
        ON dp.id = d.delivery_partner_id

    LEFT JOIN users u
        ON u.id = dp.user_id

    WHERE o.id = ?
      AND o.user_id = ?

    LIMIT 1
");

$stmt->execute([
    $orderId,
    $userId
]);

$order = $stmt->fetch();

if (!$order) {
    header("Location: ../orders.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Latest Location
|--------------------------------------------------------------------------
*/
$location = null;

if (!empty($order['delivery_id'])) {

    $stmt = $conn->prepare("
        SELECT
            latitude,
            longitude,
            accuracy,
            speed,
            heading,
            recorded_at

        FROM delivery_locations

        WHERE delivery_id = ?

        ORDER BY recorded_at DESC

        LIMIT 1
    ");

    $stmt->execute([
        $order['delivery_id']
    ]);

    $location = $stmt->fetch();
}


/*
|--------------------------------------------------------------------------
| Timeline
|--------------------------------------------------------------------------
*/
$status = $order['status'];

$statusSteps = [
    'pending' => [
        'label' => 'Order Placed',
        'icon' => 'bi-receipt'
    ],

    'confirmed' => [
        'label' => 'Order Confirmed',
        'icon' => 'bi-check-circle'
    ],

    'preparing' => [
        'label' => 'Preparing Order',
        'icon' => 'bi-basket'
    ],

    'ready' => [
        'label' => 'Ready for Delivery',
        'icon' => 'bi-box-seam'
    ],

    'out_for_delivery' => [
        'label' => 'Out for Delivery',
        'icon' => 'bi-bicycle'
    ],

    'delivered' => [
        'label' => 'Delivered',
        'icon' => 'bi-house-check'
    ]
];

$orderFlow = [
    'pending',
    'confirmed',
    'preparing',
    'ready',
    'out_for_delivery',
    'delivered'
];

$currentIndex = array_search(
    $status,
    $orderFlow
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
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Track Order <?= e($order['order_number']) ?> |
        GroceryDelivery
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- Leaflet -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <style>

        :root {
            --navy: #071a2b;
            --green: #19b56b;
            --green-dark: #119456;
            --light: #f4f7f9;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--light);
            font-family: Arial, sans-serif;
        }

        .main {
            margin-left: 250px;
            min-height: 100vh;
        }

        .topbar {
            background: white;
            padding: 18px 30px;
            border-bottom: 1px solid #e5e7eb;
        }

        .content {
            padding: 30px;
        }

        .page-title {
            color: var(--navy);
            font-weight: 700;
        }

        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(7,26,43,.06);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #edf0f2;
            padding: 20px;
            border-radius: 16px 16px 0 0 !important;
        }

        #map {
            height: 430px;
            width: 100%;
            border-radius: 14px;
        }

        .status-badge {
            padding: 8px 13px;
            border-radius: 50px;
            background: rgba(25,181,107,.1);
            color: var(--green-dark);
            font-weight: 600;
        }

        .timeline {
            position: relative;
            padding-left: 38px;
        }

        .timeline::before {
            content: "";
            position: absolute;
            left: 14px;
            top: 10px;
            bottom: 10px;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 25px;
        }

        .timeline-icon {
            position: absolute;
            left: -38px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #64748b;
            z-index: 1;
        }

        .timeline-item.active .timeline-icon {
            background: var(--green);
            color: white;
        }

        .timeline-item.active strong {
            color: var(--green-dark);
        }

        .driver-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 18px;
        }

        .driver-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--navy);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 20px;
        }

        .mobile-toggle {
            display: none;
        }

        @media (max-width: 991px) {

            .main {
                margin-left: 0;
            }

            .mobile-toggle {
                display: inline-block;
            }

            .content {
                padding: 20px;
            }
        }

    </style>

</head>

<body>

<?php include "../includes/customer_sidebar.php"; ?>

<div class="main">

    <div class="topbar d-flex justify-content-between align-items-center">

        <div>

            <button
                class="btn btn-outline-dark mobile-toggle"
                onclick="toggleSidebar()"
            >
                <i class="bi bi-list"></i>
            </button>

            <span class="ms-2 fw-semibold">
                Track Delivery
            </span>

        </div>

        <a
            href="../orders.php"
            class="btn btn-sm btn-outline-secondary"
        >
            <i class="bi bi-arrow-left me-1"></i>
            My Orders
        </a>

    </div>


    <div class="content">

        <div class="mb-4">

            <h2 class="page-title mb-1">
                Track Your Order
            </h2>

            <p class="text-muted mb-0">

                <?= e($order['order_number']) ?>

                <span class="mx-2">•</span>

                <?= date(
                    'd M Y, h:i A',
                    strtotime($order['created_at'])
                ) ?>

            </p>

        </div>


        <div class="row g-4">

            <!-- Map -->
            <div class="col-lg-8">

                <div class="card">

                    <div class="card-header
                                d-flex
                                justify-content-between
                                align-items-center">

                        <div>

                            <h5 class="mb-1">
                                <i class="bi bi-geo-alt me-2 text-success"></i>
                                Live Delivery Location
                            </h5>

                            <small
                                class="text-muted"
                                id="locationStatus"
                            >
                                Checking delivery location...
                            </small>

                        </div>

                        <span
                            class="status-badge"
                            id="orderStatus"
                        >
                            <?= e(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $order['status']
                                    )
                                )
                            ) ?>
                        </span>

                    </div>

                    <div class="card-body">

                        <div id="map"></div>

                    </div>

                </div>


                <!-- Driver -->
                <?php if (!empty($order['driver_name'])): ?>

                    <div class="card mt-4">

                        <div class="card-body">

                            <div class="driver-card">

                                <div class="d-flex
                                            align-items-center
                                            justify-content-between">

                                    <div class="d-flex
                                                align-items-center">

                                        <div class="driver-avatar">

                                            <i class="bi bi-person"></i>

                                        </div>

                                        <div class="ms-3">

                                            <small class="text-muted">
                                                Delivery Partner
                                            </small>

                                            <h6 class="mb-1">
                                                <?= e($order['driver_name']) ?>
                                            </h6>

                                            <small>
                                                <?= e(
                                                    $order['vehicle_type']
                                                ) ?>

                                                <?php if (
                                                    !empty(
                                                        $order[
                                                            'vehicle_registration'
                                                        ]
                                                    )
                                                ): ?>

                                                    •
                                                    <?= e(
                                                        $order[
                                                            'vehicle_registration'
                                                        ]
                                                    ) ?>

                                                <?php endif; ?>

                                            </small>

                                        </div>

                                    </div>

                                    <?php if (!empty($order['driver_phone'])): ?>

                                        <a
                                            href="tel:<?= e(
                                                $order['driver_phone']
                                            ) ?>"
                                            class="btn btn-success"
                                        >
                                            <i class="bi bi-telephone me-1"></i>
                                            Call
                                        </a>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>

            </div>


            <!-- Right -->
            <div class="col-lg-4">

                <!-- Timeline -->
                <div class="card mb-4">

                    <div class="card-header">

                        <h5 class="mb-0">
                            <i class="bi bi-clock-history me-2 text-success"></i>
                            Order Progress
                        </h5>

                    </div>

                    <div class="card-body">

                        <div class="timeline">

                            <?php foreach (
                                $orderFlow as $index => $flowStatus
                            ): ?>

                                <?php
                                $step = $statusSteps[$flowStatus];

                                $isActive = $index <= $currentIndex;
                                ?>

                                <div class="
                                    timeline-item
                                    <?= $isActive ? 'active' : '' ?>
                                ">

                                    <div class="timeline-icon">

                                        <i class="
                                            bi
                                            <?= e($step['icon']) ?>
                                        "></i>

                                    </div>

                                    <strong>
                                        <?= e($step['label']) ?>
                                    </strong>

                                    <?php if (
                                        $index === $currentIndex
                                    ): ?>

                                        <div class="small text-muted mt-1">
                                            Current status
                                        </div>

                                    <?php endif; ?>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>


                <!-- Delivery Information -->
                <div class="card">

                    <div class="card-header">

                        <h5 class="mb-0">
                            <i class="bi bi-box-seam me-2 text-success"></i>
                            Delivery Information
                        </h5>

                    </div>

                    <div class="card-body">

                        <div class="mb-3">

                            <small class="text-muted">
                                Delivery Address
                            </small>

                            <div class="fw-semibold">
                                <?= nl2br(
                                    e($order['delivery_address'])
                                ) ?>
                            </div>

                        </div>

                        <div class="mb-3">

                            <small class="text-muted">
                                Delivery Phone
                            </small>

                            <div class="fw-semibold">
                                <?= e($order['delivery_phone']) ?>
                            </div>

                        </div>

                        <div>

                            <small class="text-muted">
                                Order Total
                            </small>

                            <div class="fw-bold fs-5 text-success">
                                GHS
                                <?= number_format(
                                    $order['total_amount'],
                                    2
                                ) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

const deliveryId = <?= json_encode(
    $order['delivery_id'] ?? null
) ?>;

const orderId = <?= (int)$orderId ?>;

let map = L.map('map').setView(
    [5.6037, -0.1870],
    13
);

L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }
).addTo(map);

let deliveryMarker = null;

let firstLocation = true;


/*
|--------------------------------------------------------------------------
| Update Marker
|--------------------------------------------------------------------------
*/
function updateMap(latitude, longitude) {

    const position = [
        parseFloat(latitude),
        parseFloat(longitude)
    ];

    const driverIcon = L.divIcon({
        className: '',
        html: `
            <div style="
                width:42px;
                height:42px;
                border-radius:50%;
                background:#19b56b;
                color:white;
                display:flex;
                align-items:center;
                justify-content:center;
                border:4px solid white;
                box-shadow:0 3px 10px rgba(0,0,0,.25);
                font-size:18px;
            ">
                <i class="bi bi-bicycle"></i>
            </div>
        `,
        iconSize: [42, 42],
        iconAnchor: [21, 21]
    });

    if (!deliveryMarker) {

        deliveryMarker = L.marker(
            position,
            {
                icon: driverIcon
            }
        ).addTo(map);

        deliveryMarker.bindPopup(
            "<strong>Delivery Partner</strong>"
        );

    } else {

        deliveryMarker.setLatLng(position);

    }

    if (firstLocation) {

        map.setView(
            position,
            16
        );

        firstLocation = false;
    }
}


/*
|--------------------------------------------------------------------------
| Get Latest Location
|--------------------------------------------------------------------------
*/
async function loadLocation() {

    if (!deliveryId) {

        document.getElementById(
            'locationStatus'
        ).innerHTML =
            'A delivery partner has not been assigned yet.';

        return;
    }

    try {

        const response = await fetch(
            '../api/get_delivery_location.php?delivery_id='
            + encodeURIComponent(deliveryId),
            {
                cache: 'no-store'
            }
        );

        const data = await response.json();

        if (!data.success) {

            document.getElementById(
                'locationStatus'
            ).innerHTML =
                data.message ||
                'Waiting for delivery location...';

            return;
        }

        if (!data.location) {

            document.getElementById(
                'locationStatus'
            ).innerHTML =
                'Waiting for delivery partner GPS location...';

            return;
        }

        updateMap(
            data.location.latitude,
            data.location.longitude
        );

        document.getElementById(
            'locationStatus'
        ).innerHTML =
            'Last updated: ' +
            data.location.recorded_at;

    } catch (error) {

        console.error(error);

        document.getElementById(
            'locationStatus'
        ).innerHTML =
            'Unable to retrieve live location.';
    }
}


/*
|--------------------------------------------------------------------------
| Poll Every 5 Seconds
|--------------------------------------------------------------------------
*/
loadLocation();

setInterval(
    loadLocation,
    5000
);


/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/
function toggleSidebar() {

    const sidebar =
        document.querySelector('.sidebar');

    if (sidebar) {
        sidebar.classList.toggle('show');
    }
}

</script>

</body>
</html>