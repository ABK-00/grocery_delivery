<?php

require_once "../config/db.php";
require_once "../includes/auth.php";

requireRole('delivery_partner');

header('Content-Type: application/json');

$userId = $_SESSION['user_id'];

$deliveryId = isset($_POST['delivery_id'])
    ? (int) $_POST['delivery_id']
    : 0;

$latitude = isset($_POST['latitude'])
    ? (float) $_POST['latitude']
    : null;

$longitude = isset($_POST['longitude'])
    ? (float) $_POST['longitude']
    : null;

$accuracy = isset($_POST['accuracy'])
    ? (float) $_POST['accuracy']
    : null;

$speed = isset($_POST['speed'])
    ? (float) $_POST['speed']
    : null;

$heading = isset($_POST['heading'])
    ? (float) $_POST['heading']
    : null;


if ($deliveryId <= 0) {

    echo json_encode([
        'success' => false,
        'message' => 'Invalid delivery.'
    ]);

    exit;
}


if (
    $latitude === null ||
    $longitude === null ||
    !is_finite($latitude) ||
    !is_finite($longitude)
) {

    echo json_encode([
        'success' => false,
        'message' => 'Invalid GPS coordinates.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Basic coordinate validation
|--------------------------------------------------------------------------
*/

if (
    $latitude < -90 ||
    $latitude > 90 ||
    $longitude < -180 ||
    $longitude > 180
) {

    echo json_encode([
        'success' => false,
        'message' => 'GPS coordinates are outside valid ranges.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Verify delivery belongs to this driver
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        d.id,
        d.status,
        dp.id AS partner_id

    FROM deliveries d

    INNER JOIN delivery_partners dp
        ON dp.id = d.delivery_partner_id

    WHERE d.id = ?
    AND dp.user_id = ?

    LIMIT 1
");

$stmt->execute([
    $deliveryId,
    $userId
]);

$delivery = $stmt->fetch();


if (!$delivery) {

    echo json_encode([
        'success' => false,
        'message' => 'Delivery not found.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Only active deliveries can send GPS
|--------------------------------------------------------------------------
*/

if ($delivery['status'] !== 'out_for_delivery') {

    echo json_encode([
        'success' => false,
        'message' => 'GPS tracking is not active for this delivery.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Save GPS location
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    INSERT INTO delivery_locations (
        delivery_id,
        latitude,
        longitude,
        accuracy,
        speed,
        heading,
        recorded_at
    )
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");

$stmt->execute([
    $deliveryId,
    $latitude,
    $longitude,
    $accuracy,
    $speed,
    $heading
]);


/*
|--------------------------------------------------------------------------
| Update partner heartbeat
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    UPDATE delivery_partners
    SET status = 'busy'
    WHERE id = ?
");

$stmt->execute([
    $delivery['partner_id']
]);


echo json_encode([
    'success' => true,
    'message' => 'Location updated.',
    'latitude' => $latitude,
    'longitude' => $longitude
]);