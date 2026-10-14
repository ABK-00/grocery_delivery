<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('customer');

header('Content-Type: application/json');

$csrf = (string) ($_POST['csrf_token'] ?? '');

if (
    empty($_SESSION['cart_action_csrf'])
    || $csrf === ''
    || !hash_equals(
        $_SESSION['cart_action_csrf'],
        $csrf
    )
) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => 'Your session expired. Refresh and try again.'
    ]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid product.'
    ]);
    exit;
}

$stmt = $conn->prepare("
    DELETE FROM cart
    WHERE user_id = ?
      AND product_id = ?
");

$stmt->execute([
    $userId,
    $productId
]);

echo json_encode([
    'success' => true
]);
