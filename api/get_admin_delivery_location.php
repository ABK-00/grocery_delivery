<?php

require_once "../config/db.php";
require_once "../includes/auth.php";

requireRole('admin');
requireCompanyAccess();

header('Content-Type: application/json');

$companyId = currentCompanyId();

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

$stmt = $conn->prepare("
    SELECT
        d.id,
        d.status
    FROM deliveries d
    INNER JOIN orders o
        ON o.id = d.order_id
    WHERE d.id = ?
      AND o.company_id = ?
    LIMIT 1
");

$stmt->execute([
    $deliveryId,
    $companyId
]);

$delivery = $stmt->fetch();

if (!$delivery) {
    echo json_encode([
        'success' => false,
        'message' => 'Delivery not found.'
    ]);
    exit;
}

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

$location = $stmt->fetch();

echo json_encode([
    'success' => true,
    'delivery_status' => $delivery['status'],
    'location' => $location ?: null
]);
