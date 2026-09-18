<?php

require_once "../config/db.php";
require_once "../includes/auth.php";

requireRole('customer');
requireCompanyAccess();
$companyId = currentCompanyId();

header('Content-Type: application/json');

$userId = $_SESSION['user_id'];

$deliveryId = filter_input(
    INPUT_GET,
    'delivery_id',
    FILTER_VALIDATE_INT
);

if (!$deliveryId) {

    echo json_encode([
        'success' => false,
        'message' => 'Invalid delivery.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Verify Delivery Belongs To Customer
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT d.id

    FROM deliveries d

    INNER JOIN orders o
        ON o.id = d.order_id

    WHERE d.id = ?
      AND o.user_id = ?
      AND o.company_id = ?

    LIMIT 1
");

$stmt->execute([
    $deliveryId,
    $userId,
    $companyId
]);

if (!$stmt->fetch()) {

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized delivery.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Latest GPS Location
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

    ORDER BY recorded_at DESC

    LIMIT 1
");

$stmt->execute([
    $deliveryId
]);

$location = $stmt->fetch();

echo json_encode([
    'success' => true,
    'location' => $location ?: null
]);