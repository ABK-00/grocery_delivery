<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('customer');

header('Content-Type: application/json');

$userId = (int) $_SESSION['user_id'];
$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = (float) ($_POST['quantity'] ?? 1);

if ($productId <= 0 || $quantity <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid product or quantity.'
    ]);
    exit;
}

$productStmt = $conn->prepare("
    SELECT
        p.id,
        p.company_id,
        p.stock,
        p.status,
        c.company_name,
        cs.display_name
    FROM products p
    INNER JOIN companies c
        ON c.id = p.company_id
    LEFT JOIN company_storefronts cs
        ON cs.company_id = c.id
    WHERE p.id = ?
      AND p.status = 'active'
      AND c.status = 'active'
    LIMIT 1
");

$productStmt->execute([$productId]);
$product = $productStmt->fetch();

if (!$product) {
    echo json_encode([
        'success' => false,
        'message' => 'Product is unavailable.'
    ]);
    exit;
}

$cartVendorStmt = $conn->prepare("
    SELECT
        p.company_id,
        c.company_name,
        cs.display_name
    FROM cart ca
    INNER JOIN products p
        ON p.id = ca.product_id
    INNER JOIN companies c
        ON c.id = p.company_id
    LEFT JOIN company_storefronts cs
        ON cs.company_id = c.id
    WHERE ca.user_id = ?
    LIMIT 1
");

$cartVendorStmt->execute([$userId]);
$cartVendor = $cartVendorStmt->fetch();

if (
    $cartVendor
    && (int)$cartVendor['company_id']
        !== (int)$product['company_id']
) {
    echo json_encode([
        'success' => false,
        'code' => 'different_vendor',
        'message' =>
            'Your cart already contains products from '
            . ($cartVendor['display_name'] ?: $cartVendor['company_name'])
            . '. Complete or clear that cart before shopping from another store.'
    ]);
    exit;
}

$existingStmt = $conn->prepare("
    SELECT quantity
    FROM cart
    WHERE user_id = ?
      AND product_id = ?
    LIMIT 1
");

$existingStmt->execute([
    $userId,
    $productId
]);

$existingQuantity =
    (float) ($existingStmt->fetchColumn() ?: 0);

$newQuantity =
    $existingQuantity + $quantity;

if ($newQuantity > (float)$product['stock']) {
    echo json_encode([
        'success' => false,
        'message' => 'Requested quantity exceeds available stock.'
    ]);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO cart (
        user_id,
        product_id,
        quantity
    )
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
        quantity = VALUES(quantity),
        updated_at = CURRENT_TIMESTAMP
");

$stmt->execute([
    $userId,
    $productId,
    $newQuantity
]);

echo json_encode([
    'success' => true,
    'message' => 'Added to cart.'
]);
