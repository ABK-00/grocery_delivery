<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole("delivery_partner");
requireCompanyAccess();
$companyId = currentCompanyId();

$deliveryId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($deliveryId <= 0) {
    die("Invalid delivery.");
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Get delivery and verify ownership
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        d.id,
        d.order_id,
        d.status AS delivery_status,
        d.assigned_at,
        d.picked_up_at,
        d.delivered_at,

        o.order_number,
        o.total_amount,
        o.status AS order_status,
        o.delivery_address,
        o.delivery_phone,

        u.name AS customer_name,
        u.phone AS customer_phone

    FROM deliveries d

    INNER JOIN orders o
        ON o.id = d.order_id

    INNER JOIN users u
        ON u.id = o.user_id

    INNER JOIN delivery_partners dp
        ON dp.id = d.delivery_partner_id

    WHERE d.id = ?
      AND dp.user_id = ?
      AND o.company_id = ?
    LIMIT 1
");

$stmt->execute([$deliveryId, $userId, $companyId]);
$delivery = $stmt->fetch();

if (!$delivery) {
    die("Delivery not found or you are not authorized to track it.");
}

/*
|--------------------------------------------------------------------------
| Get latest saved location
|--------------------------------------------------------------------------
*/
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

$stmt->execute([$deliveryId]);
$latestLocation = $stmt->fetch();

$isTrackable = ($delivery['delivery_status'] === 'out_for_delivery');

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Live Tracking | GroceryDelivery</title>

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
            font-family: Arial, sans-serif;
            color: #17202a;
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
            background: #fff;
            border: none;
            border-radius: 18px;
            box-shadow: 0 5px 20px rgba(0,0,0,.06);
        }

        .status-card {
            border-radius: 15px;
            padding: 18px;
            background: #f8faf9;
            border: 1px solid #e7ece9;
        }

        .status-dot {
            width: 11px;
            height: 11px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 7px;
        }

        .status-live {
            background: #198754;
            box-shadow: 0 0 0 5px rgba(25,135,84,.12);
        }

        .status-offline {
            background: #dc3545;
        }

        .status-waiting {
            background: #ffc107;
        }

        #map {
            height: 480px;
            width: 100%;
            border-radius: 15px;
            overflow: hidden;
        }

        .metric-box {
            background: #f8faf9;
            border: 1px solid #e7ece9;
            border-radius: 14px;
            padding: 17px;
            height: 100%;
        }

        .metric-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #e9f5ef;
            color: #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 10px;
        }

        .metric-label {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .metric-value {
            font-size: 16px;
            font-weight: 700;
            word-break: break-word;
        }

        .tracking-active {
            background: #198754 !important;
            border-color: #198754 !important;
        }

        .tracking-inactive {
            background: #6c757d !important;
            border-color: #6c757d !important;
        }

        .coordinate-value {
            font-family: monospace;
            font-size: 14px;
        }

        @media (max-width: 991px) {

            .main-content {
                margin-left: 0;
                padding: 20px;
                padding-top: 80px;
            }

            #map {
                height: 400px;
            }
        }

        @media (max-width: 575px) {

            .main-content {
                padding: 15px;
                padding-top: 75px;
            }

            #map {
                height: 350px;
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
                <h1 class="topbar-title">Live Delivery Tracking</h1>
                <p class="topbar-subtitle">Track and share your current location with the customer.</p>
            </div>
        </div>
        <div>
            <a href="order_details.php?id=<?= (int)$deliveryId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Delivery
            </a>
        </div>
    </div>


    <?php if (!$isTrackable): ?>

        <div class="alert alert-warning tracking-card border-0">

            <div class="d-flex align-items-start">

                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>

                <div>

                    <strong>GPS tracking is currently unavailable.</strong>

                    <div class="mt-1">
                        This delivery must be marked
                        <strong>Out for Delivery</strong>
                        before GPS tracking can begin.
                    </div>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <div class="row g-4">

        <!-- LEFT -->
        <div class="col-lg-8">

            <div class="tracking-card p-3 p-md-4">

                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">

                    <div>

                        <h5 class="mb-1 fw-bold">
                            Order <?= e($delivery['order_number']) ?>
                        </h5>

                        <small class="text-muted">
                            Customer: <?= e($delivery['customer_name']) ?>
                        </small>

                    </div>


                    <div id="gpsStatusContainer">

                        <?php if ($isTrackable): ?>

                            <span class="badge bg-secondary px-3 py-2">
                                <span
                                    id="gpsDot"
                                    class="status-dot status-offline"
                                ></span>

                                <span id="gpsStatus">
                                    Tracking Stopped
                                </span>
                            </span>

                        <?php else: ?>

                            <span class="badge bg-warning text-dark px-3 py-2">
                                <span class="status-dot status-waiting"></span>
                                Waiting for Delivery
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <div id="map"></div>


                <div class="d-flex flex-wrap gap-2 mt-3">

                    <button
                        type="button"
                        id="startTrackingBtn"
                        class="btn btn-success"
                        <?= !$isTrackable ? 'disabled' : '' ?>
                    >
                        <i class="bi bi-play-fill me-1"></i>
                        Start Tracking
                    </button>


                    <button
                        type="button"
                        id="stopTrackingBtn"
                        class="btn btn-danger"
                        disabled
                    >
                        <i class="bi bi-stop-fill me-1"></i>
                        Stop Tracking
                    </button>

                </div>


                <div
                    id="trackingMessage"
                    class="small text-muted mt-3"
                >
                    <?php if (!$isTrackable): ?>

                        Tracking will become available when the delivery
                        status changes to Out for Delivery.

                    <?php else: ?>

                        Press "Start Tracking" to begin sending your GPS
                        location.

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- RIGHT -->
        <div class="col-lg-4">

            <div class="tracking-card p-3 p-md-4 mb-4">

                <h5 class="fw-bold mb-3">
                    Delivery Information
                </h5>


                <div class="status-card mb-3">

                    <div class="small text-muted mb-1">
                        Delivery Status
                    </div>

                    <div class="fw-bold text-uppercase">
                        <?= e(str_replace('_', ' ', $delivery['delivery_status'])) ?>
                    </div>

                </div>


                <div class="mb-3">

                    <small class="text-muted d-block">
                        Customer
                    </small>

                    <strong>
                        <?= e($delivery['customer_name']) ?>
                    </strong>

                </div>


                <div class="mb-3">

                    <small class="text-muted d-block">
                        Customer Phone
                    </small>

                    <a
                        href="tel:<?= e($delivery['customer_phone']) ?>"
                        class="text-decoration-none"
                    >
                        <?= e($delivery['customer_phone']) ?>
                    </a>

                </div>


                <div class="mb-3">

                    <small class="text-muted d-block">
                        Delivery Address
                    </small>

                    <div>
                        <?= nl2br(e($delivery['delivery_address'])) ?>
                    </div>

                </div>


                <div>

                    <small class="text-muted d-block">
                        Order Total
                    </small>

                    <strong class="text-success fs-5">
                        GHS <?= number_format((float)$delivery['total_amount'], 2) ?>
                    </strong>

                </div>

            </div>


            <!-- GPS Metrics -->

            <div class="tracking-card p-3 p-md-4">

                <h5 class="fw-bold mb-3">
                    GPS Information
                </h5>


                <div class="row g-3">

                    <div class="col-6">

                        <div class="metric-box">

                            <div class="metric-icon">
                                <i class="bi bi-crosshair"></i>
                            </div>

                            <div class="metric-label">
                                Accuracy
                            </div>

                            <div
                                class="metric-value"
                                id="accuracyValue"
                            >
                                —
                            </div>

                        </div>

                    </div>


                    <div class="col-6">

                        <div class="metric-box">

                            <div class="metric-icon">
                                <i class="bi bi-speedometer2"></i>
                            </div>

                            <div class="metric-label">
                                Speed
                            </div>

                            <div
                                class="metric-value"
                                id="speedValue"
                            >
                                —
                            </div>

                        </div>

                    </div>


                    <div class="col-12">

                        <div class="metric-box">

                            <div class="metric-icon">
                                <i class="bi bi-geo-alt"></i>
                            </div>

                            <div class="metric-label">
                                Latitude
                            </div>

                            <div
                                class="metric-value coordinate-value"
                                id="latitudeValue"
                            >
                                —
                            </div>

                        </div>

                    </div>


                    <div class="col-12">

                        <div class="metric-box">

                            <div class="metric-icon">
                                <i class="bi bi-compass"></i>
                            </div>

                            <div class="metric-label">
                                Longitude
                            </div>

                            <div
                                class="metric-value coordinate-value"
                                id="longitudeValue"
                            >
                                —
                            </div>

                        </div>

                    </div>


                    <div class="col-12">

                        <div class="metric-box">

                            <div class="metric-icon">
                                <i class="bi bi-clock"></i>
                            </div>

                            <div class="metric-label">
                                Last Location Sent
                            </div>

                            <div
                                class="metric-value"
                                id="lastSentValue"
                            >
                                —
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>


<script>

const deliveryId = <?= (int)$deliveryId ?>;
const trackingAllowed = <?= $isTrackable ? 'true' : 'false' ?>;

let watchId = null;
let isTracking = false;

let lastSentTime = 0;
let lastSentLat = null;
let lastSentLng = null;

let driverMarker = null;
let accuracyCircle = null;


/*
|--------------------------------------------------------------------------
| Initial map position
|--------------------------------------------------------------------------
*/

const initialLat = <?= $latestLocation ? (float)$latestLocation['latitude'] : 5.6037 ?>;
const initialLng = <?= $latestLocation ? (float)$latestLocation['longitude'] : -0.1870 ?>;

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

<?php if ($latestLocation): ?>

driverMarker = L.marker([
    <?= (float)$latestLocation['latitude'] ?>,
    <?= (float)$latestLocation['longitude'] ?>
]).addTo(map);

driverMarker.bindPopup(
    '<strong>Your latest location</strong>'
).openPopup();


<?php if ($latestLocation['accuracy'] !== null): ?>

accuracyCircle = L.circle([
    <?= (float)$latestLocation['latitude'] ?>,
    <?= (float)$latestLocation['longitude'] ?>
], {
    radius: <?= (float)$latestLocation['accuracy'] ?>,
    color: '#198754',
    fillOpacity: 0.12
}).addTo(map);

<?php endif; ?>


document.getElementById('latitudeValue').textContent =
    <?= json_encode(number_format((float)$latestLocation['latitude'], 7)) ?>;

document.getElementById('longitudeValue').textContent =
    <?= json_encode(number_format((float)$latestLocation['longitude'], 7)) ?>;

<?php if ($latestLocation['accuracy'] !== null): ?>

document.getElementById('accuracyValue').textContent =
    <?= json_encode(number_format((float)$latestLocation['accuracy'], 1) . ' m') ?>;

<?php endif; ?>


<?php if ($latestLocation['speed'] !== null): ?>

document.getElementById('speedValue').textContent =
    <?= json_encode(number_format(((float)$latestLocation['speed']) * 3.6, 1) . ' km/h') ?>;

<?php endif; ?>


document.getElementById('lastSentValue').textContent =
    <?= json_encode($latestLocation['recorded_at']) ?>;

<?php endif; ?>


/*
|--------------------------------------------------------------------------
| Elements
|--------------------------------------------------------------------------
*/

const startBtn = document.getElementById('startTrackingBtn');
const stopBtn = document.getElementById('stopTrackingBtn');

const gpsDot = document.getElementById('gpsDot');
const gpsStatus = document.getElementById('gpsStatus');

const trackingMessage = document.getElementById('trackingMessage');

const latitudeValue = document.getElementById('latitudeValue');
const longitudeValue = document.getElementById('longitudeValue');

const accuracyValue = document.getElementById('accuracyValue');
const speedValue = document.getElementById('speedValue');

const lastSentValue = document.getElementById('lastSentValue');


/*
|--------------------------------------------------------------------------
| Haversine distance
|--------------------------------------------------------------------------
*/

function distanceInMeters(lat1, lon1, lat2, lon2) {

    const R = 6371000;

    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;

    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) *
        Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLon / 2) *
        Math.sin(dLon / 2);

    const c = 2 * Math.atan2(
        Math.sqrt(a),
        Math.sqrt(1 - a)
    );

    return R * c;
}


/*
|--------------------------------------------------------------------------
| Update map
|--------------------------------------------------------------------------
*/

function updateMap(position) {

    const lat = position.coords.latitude;
    const lng = position.coords.longitude;

    const accuracy = position.coords.accuracy || 0;

    if (!driverMarker) {

        driverMarker = L.marker([lat, lng]).addTo(map);

        driverMarker.bindPopup(
            '<strong>Current Location</strong>'
        );

    } else {

        driverMarker.setLatLng([lat, lng]);

    }


    if (!accuracyCircle) {

        accuracyCircle = L.circle([lat, lng], {
            radius: accuracy,
            color: '#198754',
            fillOpacity: 0.12
        }).addTo(map);

    } else {

        accuracyCircle.setLatLng([lat, lng]);
        accuracyCircle.setRadius(accuracy);

    }


    map.setView(
        [lat, lng],
        Math.max(map.getZoom(), 16)
    );
}


/*
|--------------------------------------------------------------------------
| Update UI
|--------------------------------------------------------------------------
*/

function updateGpsUI(position) {

    const coords = position.coords;

    const lat = coords.latitude;
    const lng = coords.longitude;

    const accuracy = coords.accuracy;

    let speed = coords.speed;

    if (speed === null || typeof speed === 'undefined') {
        speed = 0;
    }


    latitudeValue.textContent =
        lat.toFixed(7);

    longitudeValue.textContent =
        lng.toFixed(7);

    accuracyValue.textContent =
        accuracy.toFixed(1) + ' m';

    speedValue.textContent =
        (speed * 3.6).toFixed(1) + ' km/h';
}


/*
|--------------------------------------------------------------------------
| Send location to server
|--------------------------------------------------------------------------
*/

async function sendLocation(position) {

    const now = Date.now();

    const lat = position.coords.latitude;
    const lng = position.coords.longitude;

    const accuracy = position.coords.accuracy || null;
    const speed = position.coords.speed;
    const heading = position.coords.heading;


    /*
    Prevent excessive database writes.

    Send if:
    - 5 seconds have passed, OR
    - vehicle moved at least 5 meters.
    */

    let movedEnough = true;

    if (
        lastSentLat !== null &&
        lastSentLng !== null
    ) {

        movedEnough =
            distanceInMeters(
                lastSentLat,
                lastSentLng,
                lat,
                lng
            ) >= 5;
    }


    if (
        now - lastSentTime < 5000 &&
        !movedEnough
    ) {
        return;
    }


    const formData = new FormData();

    formData.append(
        'delivery_id',
        deliveryId
    );

    formData.append(
        'latitude',
        lat
    );

    formData.append(
        'longitude',
        lng
    );

    formData.append(
        'accuracy',
        accuracy !== null ? accuracy : ''
    );

    formData.append(
        'speed',
        speed !== null ? speed : ''
    );

    formData.append(
        'heading',
        heading !== null ? heading : ''
    );


    try {

        const response = await fetch(
            '../api/update_delivery_location.php',
            {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }
        );


        const data = await response.json();


        if (!response.ok || !data.success) {

            throw new Error(
                data.message || 'Unable to update location.'
            );

        }


        lastSentTime = now;
        lastSentLat = lat;
        lastSentLng = lng;

        const currentTime = new Date();

        lastSentValue.textContent =
            currentTime.toLocaleTimeString();


        trackingMessage.textContent =
            'GPS location successfully sent to the server.';


    } catch (error) {

        console.error(error);

        trackingMessage.textContent =
            'GPS detected, but the location could not be sent to the server.';

    }

}


/*
|--------------------------------------------------------------------------
| GPS success
|--------------------------------------------------------------------------
*/

function handlePosition(position) {

    updateGpsUI(position);

    updateMap(position);

    sendLocation(position);
}


/*
|--------------------------------------------------------------------------
| GPS error
|--------------------------------------------------------------------------
*/

function handleGpsError(error) {

    let message = 'Unable to retrieve your location.';


    switch (error.code) {

        case error.PERMISSION_DENIED:

            message =
                'GPS permission was denied. Please allow location access in your browser.';

            break;


        case error.POSITION_UNAVAILABLE:

            message =
                'Your device could not determine its current location.';

            break;


        case error.TIMEOUT:

            message =
                'GPS request timed out. Trying again...';

            break;

    }


    trackingMessage.textContent = message;

    gpsStatus.textContent = 'GPS Error';

    gpsDot.className =
        'status-dot status-offline';


    console.error(
        'Geolocation error:',
        error
    );
}


/*
|--------------------------------------------------------------------------
| Start tracking
|--------------------------------------------------------------------------
*/

function startTracking() {

    if (!trackingAllowed) {
        return;
    }


    if (!navigator.geolocation) {

        trackingMessage.textContent =
            'Your browser does not support GPS location services.';

        return;
    }


    if (isTracking) {
        return;
    }


    isTracking = true;


    gpsStatus.textContent =
        'GPS Active';

    gpsDot.className =
        'status-dot status-live';


    startBtn.disabled = true;
    stopBtn.disabled = false;


    startBtn.classList.add(
        'tracking-active'
    );


    trackingMessage.textContent =
        'Requesting your GPS location...';


    watchId = navigator.geolocation.watchPosition(
        handlePosition,
        handleGpsError,
        {
            enableHighAccuracy: true,
            maximumAge: 5000,
            timeout: 15000
        }
    );
}


/*
|--------------------------------------------------------------------------
| Stop tracking
|--------------------------------------------------------------------------
*/

function stopTracking() {

    if (watchId !== null) {

        navigator.geolocation.clearWatch(
            watchId
        );

        watchId = null;
    }


    isTracking = false;


    gpsStatus.textContent =
        'Tracking Stopped';

    gpsDot.className =
        'status-dot status-offline';


    startBtn.disabled = !trackingAllowed;
    stopBtn.disabled = true;


    startBtn.classList.remove(
        'tracking-active'
    );


    trackingMessage.textContent =
        'GPS tracking has been stopped.';
}


/*
|--------------------------------------------------------------------------
| Buttons
|--------------------------------------------------------------------------
*/

startBtn.addEventListener(
    'click',
    startTracking
);

stopBtn.addEventListener(
    'click',
    stopTracking
);


/*
|--------------------------------------------------------------------------
| Stop GPS when leaving page
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'beforeunload',
    function () {

        if (watchId !== null) {

            navigator.geolocation.clearWatch(
                watchId
            );

        }

    }
);

</script>

</body>
</html>