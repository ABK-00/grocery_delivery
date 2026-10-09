<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('customer');

header('Content-Type: application/json');

$userId = (int) $_SESSION['user_id'];
$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = (float) ($_POST['quantity'] ?? 0);

if ($productId <= 0 || $quantity <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid quantity.'
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT p.stock
    FROM products p
    INNER JOIN cart c
        ON c.product_id = p.id
    WHERE p.id = ?
      AND p.status = 'active'
      AND c.user_id = ?
    LIMIT 1
");

$stmt->execute([
    $productId,
    $userId
]);

$stock = $stmt->fetchColumn();

if (
    $stock === false
    || $quantity > (float)$stock
) {
    echo json_encode([
        'success' => false,
        'message' => 'Quantity is unavailable.'
    ]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE cart
    SET quantity = ?
    WHERE user_id = ?
      AND product_id = ?
");

$stmt->execute([
    $quantity,
    $userId,
    $productId
]);

echo json_encode([
    'success' => true
]);
