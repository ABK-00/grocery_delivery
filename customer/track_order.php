<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole("customer");

$orderId = isset($_GET['order']) ? (int) $_GET['order'] : 0;

if ($orderId <= 0) {
    die("Invalid order.");
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Get order + delivery + driver
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        o.id,
        o.order_number,
        o.total_amount,
        o.delivery_address,
        o.delivery_phone,
        o.status AS order_status,
        o.payment_status,
        o.created_at,

        d.id AS delivery_id,
        d.status AS delivery_status,

        dp.id AS partner_id,
        dp.vehicle_type,
        dp.vehicle_registration,

        driver.id AS driver_user_id,
        driver.name AS driver_name,
        driver.phone AS driver_phone

    FROM orders o

    LEFT JOIN deliveries d
        ON d.order_id = o.id

    LEFT JOIN delivery_partners dp
        ON dp.id = d.delivery_partner_id

    LEFT JOIN users driver
        ON driver.id = dp.user_id

    WHERE o.id = ?
      AND o.user_id = ?

    LIMIT 1
");

$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    die("Order not found.");
}


/*
|--------------------------------------------------------------------------
| Get latest delivery location
|--------------------------------------------------------------------------
*/
$latestLocation = null;

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
        ORDER BY recorded_at DESC, id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $order['delivery_id']
    ]);

    $latestLocation = $stmt->fetch();
}


/*
|--------------------------------------------------------------------------
| Tracking availability
|--------------------------------------------------------------------------
*/
$trackingActive =
    !empty($order['delivery_id']) &&
    $order['delivery_status'] === 'out_for_delivery';


/*
|--------------------------------------------------------------------------
| Map starting position
|--------------------------------------------------------------------------
|
| If driver has already sent GPS coordinates, use them.
| Otherwise use Accra as a neutral starting position.
|
*/
$mapLat = $latestLocation
    ? (float)$latestLocation['latitude']
    : 5.6037;

$mapLng = $latestLocation
    ? (float)$latestLocation['longitude']
    : -0.1870;

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
        Track Order <?= e($order['order_number']) ?> | GroceryDelivery
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >


    <style>

        body {
            background: #f4f7f6;
            color: #17202a;
            font-family: Arial, sans-serif;
        }


        .main-content {
            margin-left: 250px;
            padding: 30px;
            min-height: 100vh;
        }


        .page-title {
            font-weight: 700;
            margin-bottom: 5px;
        }


        .page-subtitle {
            color: #6c757d;
            margin-bottom: 25px;
        }


        .tracking-card {
            background: #ffffff;
            border: none;
            border-radius: 18px;
            box-shadow: 0 5px 20px rgba(0,0,0,.06);
        }


        #map {
            width: 100%;
            height: 520px;
            border-radius: 16px;
            overflow: hidden;
        }


        .driver-card {
            background: #f8faf9;
            border: 1px solid #e5ebe7;
            border-radius: 15px;
            padding: 18px;
        }


        .driver-avatar {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: #198754;
            color: white;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 23px;
        }


        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding: 7px 13px;

            border-radius: 50px;

            font-size: 13px;
            font-weight: 600;
        }


        .status-live {
            background: #dff5e8;
            color: #137a45;
        }


        .status-waiting {
            background: #fff3cd;
            color: #856404;
        }


        .status-complete {
            background: #e9ecef;
            color: #495057;
        }


        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
        }


        .dot-live {
            background: #198754;
            box-shadow: 0 0 0 4px rgba(25,135,84,.12);
        }


        .dot-waiting {
            background: #ffc107;
        }


        .dot-complete {
            background: #6c757d;
        }


        .info-box {
            background: #f8faf9;
            border: 1px solid #e5ebe7;
            border-radius: 14px;
            padding: 15px;
        }


        .info-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }


        .info-value {
            font-weight: 600;
        }


        .gps-panel {
            background: #17202a;
            color: white;
            border-radius: 15px;
            padding: 18px;
        }


        .gps-value {
            font-family: monospace;
            font-size: 13px;
        }


        .refresh-indicator {
            font-size: 12px;
            color: #6c757d;
        }


        @media (max-width: 991px) {

            .main-content {
                margin-left: 0;
                padding: 20px;
                padding-top: 80px;
            }

            #map {
                height: 430px;
            }
        }


        @media (max-width: 575px) {

            .main-content {
                padding: 15px;
                padding-top: 75px;
            }

            #map {
                height: 360px;
            }
        }

    </style>

</head>


<body>


<?php include "../includes/customer_sidebar.php"; ?>


<div class="main-content">


    <!-- HEADER -->

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

        <div>

            <h2 class="page-title">

                <i class="bi bi-geo-alt-fill text-success me-2"></i>

                Track Your Order

            </h2>

            <div class="page-subtitle">

                Order <?= e($order['order_number']) ?>

            </div>

        </div>


        <a
            href="orders.php"
            class="btn btn-outline-secondary"
        >

            <i class="bi bi-arrow-left me-1"></i>

            My Orders

        </a>

    </div>


    <div class="row g-4">


        <!-- MAP -->

        <div class="col-lg-8">

            <div class="tracking-card p-3 p-md-4">

                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">

                    <div>

                        <h5 class="fw-bold mb-1">
                            Delivery Location
                        </h5>

                        <div class="refresh-indicator">

                            <i class="bi bi-arrow-repeat me-1"></i>

                            <span id="refreshText">
                                Waiting for location...
                            </span>

                        </div>

                    </div>


                    <div id="trackingStatus">

                        <?php if ($trackingActive): ?>

                            <span class="status-pill status-live">

                                <span class="status-dot dot-live"></span>

                                Driver is on the way

                            </span>

                        <?php elseif ($order['delivery_status'] === 'delivered'): ?>

                            <span class="status-pill status-complete">

                                <span class="status-dot dot-complete"></span>

                                Delivered

                            </span>

                        <?php else: ?>

                            <span class="status-pill status-waiting">

                                <span class="status-dot dot-waiting"></span>

                                Preparing Delivery

                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <div id="map"></div>


                <?php if (!$trackingActive): ?>

                    <div class="alert alert-info mt-3 mb-0">

                        <i class="bi bi-info-circle-fill me-2"></i>

                        Live driver tracking will become available when
                        your order is <strong>Out for Delivery</strong>.

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- SIDE PANEL -->

        <div class="col-lg-4">


            <!-- DRIVER -->

            <div class="tracking-card p-3 p-md-4 mb-4">

                <h5 class="fw-bold mb-3">

                    <i class="bi bi-person-badge me-2 text-success"></i>

                    Delivery Partner

                </h5>


                <?php if (!empty($order['driver_name'])): ?>

                    <div class="driver-card">

                        <div class="d-flex align-items-center">

                            <div class="driver-avatar me-3">

                                <i class="bi bi-person-fill"></i>

                            </div>


                            <div>

                                <div class="fw-bold">

                                    <?= e($order['driver_name']) ?>

                                </div>

                                <small class="text-muted">

                                    <?= e($order['vehicle_type'] ?? 'Delivery Vehicle') ?>

                                </small>

                            </div>

                        </div>


                        <hr>


                        <div class="mb-3">

                            <small class="text-muted d-block">
                                Vehicle Registration
                            </small>

                            <strong>

                                <?= e($order['vehicle_registration']) ?>

                            </strong>

                        </div>


                        <?php if (!empty($order['driver_phone'])): ?>

                            <a
                                href="tel:<?= e($order['driver_phone']) ?>"
                                class="btn btn-success w-100"
                            >

                                <i class="bi bi-telephone-fill me-2"></i>

                                Call Driver

                            </a>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <div class="text-center py-3">

                        <i class="bi bi-truck fs-1 text-muted"></i>

                        <p class="text-muted mt-2 mb-0">

                            A delivery partner has not been assigned yet.

                        </p>

                    </div>

                <?php endif; ?>

            </div>


            <!-- ORDER INFO -->

            <div class="tracking-card p-3 p-md-4 mb-4">

                <h5 class="fw-bold mb-3">

                    Order Information

                </h5>


                <div class="row g-3">


                    <div class="col-6">

                        <div class="info-box">

                            <div class="info-label">
                                Order
                            </div>

                            <div class="info-value">
                                <?= e($order['order_number']) ?>
                            </div>

                        </div>

                    </div>


                    <div class="col-6">

                        <div class="info-box">

                            <div class="info-label">
                                Total
                            </div>

                            <div class="info-value text-success">
                                GHS <?= number_format((float)$order['total_amount'], 2) ?>
                            </div>

                        </div>

                    </div>


                    <div class="col-12">

                        <div class="info-box">

                            <div class="info-label">
                                Delivery Address
                            </div>

                            <div class="info-value">

                                <?= nl2br(e($order['delivery_address'])) ?>

                            </div>

                        </div>

                    </div>


                    <div class="col-12">

                        <div class="info-box">

                            <div class="info-label">
                                Delivery Phone
                            </div>

                            <div class="info-value">

                                <?= e($order['delivery_phone']) ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- GPS INFO -->

            <div class="gps-panel">

                <h6 class="fw-bold mb-3">

                    <i class="bi bi-broadcast me-2"></i>

                    Live GPS

                </h6>


                <div class="mb-2">

                    <small class="text-secondary">
                        Latitude
                    </small>

                    <div
                        id="latitude"
                        class="gps-value"
                    >
                        —
                    </div>

                </div>


                <div class="mb-2">

                    <small class="text-secondary">
                        Longitude
                    </small>

                    <div
                        id="longitude"
                        class="gps-value"
                    >
                        —
                    </div>

                </div>


                <div class="mb-2">

                    <small class="text-secondary">
                        Accuracy
                    </small>

                    <div
                        id="accuracy"
                        class="gps-value"
                    >
                        —
                    </div>

                </div>


                <div>

                    <small class="text-secondary">
                        Last Update
                    </small>

                    <div
                        id="lastUpdate"
                        class="gps-value"
                    >
                        —
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>


<script>


/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

const deliveryId = <?= $order['delivery_id'] ? (int)$order['delivery_id'] : 0 ?>;

const trackingActive =
    <?= $trackingActive ? 'true' : 'false' ?>;


/*
|--------------------------------------------------------------------------
| Initial coordinates
|--------------------------------------------------------------------------
*/

const initialLat = <?= $mapLat ?>;
const initialLng = <?= $mapLng ?>;


/*
|--------------------------------------------------------------------------
| Map
|--------------------------------------------------------------------------
*/

const map = L.map('map').setView(
    [initialLat, initialLng],
    <?= $latestLocation ? 16 : 12 ?>
);


L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }
).addTo(map);


/*
|--------------------------------------------------------------------------
| Driver marker
|--------------------------------------------------------------------------
*/

let driverMarker = null;

let accuracyCircle = null;


<?php if ($latestLocation): ?>

driverMarker = L.marker([
    <?= (float)$latestLocation['latitude'] ?>,
    <?= (float)$latestLocation['longitude'] ?>
]).addTo(map);


driverMarker.bindPopup(
    '<strong>Delivery Partner</strong><br>Current location'
);


accuracyCircle = L.circle([
    <?= (float)$latestLocation['latitude'] ?>,
    <?= (float)$latestLocation['longitude'] ?>
], {
    radius: <?= $latestLocation['accuracy'] !== null
        ? (float)$latestLocation['accuracy']
        : 30 ?>,
    color: '#198754',
    fillOpacity: 0.10
}).addTo(map);


document.getElementById('latitude').textContent =
    <?= json_encode(number_format((float)$latestLocation['latitude'], 7)) ?>;


document.getElementById('longitude').textContent =
    <?= json_encode(number_format((float)$latestLocation['longitude'], 7)) ?>;


document.getElementById('accuracy').textContent =
    <?= $latestLocation['accuracy'] !== null
        ? json_encode(number_format((float)$latestLocation['accuracy'], 1) . ' m')
        : json_encode('—') ?>;


document.getElementById('lastUpdate').textContent =
    <?= json_encode($latestLocation['recorded_at']) ?>;


document.getElementById('refreshText').textContent =
    'Last location received: <?= e($latestLocation['recorded_at']) ?>';


<?php endif; ?>


/*
|--------------------------------------------------------------------------
| Update marker
|--------------------------------------------------------------------------
*/

function updateDriverLocation(data) {

    const lat = parseFloat(data.latitude);
    const lng = parseFloat(data.longitude);

    const accuracy =
        data.accuracy !== null
            ? parseFloat(data.accuracy)
            : 30;


    if (
        Number.isNaN(lat) ||
        Number.isNaN(lng)
    ) {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Create marker
    |--------------------------------------------------------------------------
    */

    if (!driverMarker) {

        driverMarker = L.marker([
            lat,
            lng
        ]).addTo(map);

        driverMarker.bindPopup(
            '<strong>Delivery Partner</strong><br>Current location'
        );

    } else {

        /*
        | Smoothly move marker
        */
        driverMarker.setLatLng([
            lat,
            lng
        ]);

    }


    /*
    |--------------------------------------------------------------------------
    | Accuracy circle
    |--------------------------------------------------------------------------
    */

    if (!accuracyCircle) {

        accuracyCircle = L.circle([
            lat,
            lng
        ], {
            radius: accuracy,
            color: '#198754',
            fillOpacity: 0.10
        }).addTo(map);

    } else {

        accuracyCircle.setLatLng([
            lat,
            lng
        ]);

        accuracyCircle.setRadius(
            accuracy
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Update map position
    |--------------------------------------------------------------------------
    */

    map.panTo([
        lat,
        lng
    ], {
        animate: true,
        duration: 0.8
    });


    /*
    |--------------------------------------------------------------------------
    | Update GPS information
    |--------------------------------------------------------------------------
    */

    document.getElementById('latitude').textContent =
        lat.toFixed(7);


    document.getElementById('longitude').textContent =
        lng.toFixed(7);


    document.getElementById('accuracy').textContent =
        accuracy.toFixed(1) + ' m';


    const recordedAt =
        data.recorded_at || new Date().toLocaleTimeString();


    document.getElementById('lastUpdate').textContent =
        recordedAt;


    document.getElementById('refreshText').textContent =
        'Location updated ' + recordedAt;
}


/*
|--------------------------------------------------------------------------
| Fetch latest driver location
|--------------------------------------------------------------------------
*/

async function fetchDriverLocation() {

    if (!deliveryId) {

        return;
    }


    try {

        const response = await fetch(
            '../api/get_delivery_location.php?delivery_id='
            + encodeURIComponent(deliveryId)
            + '&_='
            + Date.now(),
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );


        if (!response.ok) {

            throw new Error(
                'Unable to retrieve location.'
            );
        }


        const data =
            await response.json();


        if (
            data.success &&
            data.location
        ) {

            updateDriverLocation(
                data.location
            );

        } else {

            document.getElementById('refreshText').textContent =
                'Waiting for the driver to send GPS location...';
        }


    } catch (error) {

        console.error(
            'Tracking error:',
            error
        );


        document.getElementById('refreshText').textContent =
            'Unable to refresh driver location.';
    }

}


/*
|--------------------------------------------------------------------------
| Poll every 5 seconds
|--------------------------------------------------------------------------
*/

if (trackingActive) {

    fetchDriverLocation();

    setInterval(
        fetchDriverLocation,
        5000
    );

}


/*
|--------------------------------------------------------------------------
| Refresh map size
|--------------------------------------------------------------------------
*/

setTimeout(
    function () {
        map.invalidateSize();
    },
    500
);

</script>


</body>

</html>