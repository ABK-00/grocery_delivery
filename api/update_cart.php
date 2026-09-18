<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
requireCompanyAccess();
header('Content-Type: application/json');
$userId = (int)$_SESSION['user_id'];
$companyId = currentCompanyId();
$productId = (int)($_POST['product_id'] ?? 0);
$qty = (float)($_POST['quantity'] ?? 0);
if ($productId <= 0 || $qty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid quantity.']);
    exit;
}
$st = $conn->prepare("SELECT p.stock FROM products p INNER JOIN cart c ON c.product_id=p.id WHERE p.id=? AND p.company_id=? AND p.status='active' AND c.user_id=?");
$st->execute([$productId, $companyId, $userId]);
$stock = $st->fetchColumn();
if ($stock === false || $qty > (float)$stock) {
    echo json_encode(['success' => false, 'message' => 'Quantity is unavailable.']);
    exit;
}
$st = $conn->prepare("UPDATE cart SET quantity=? WHERE user_id=? AND product_id=?");
$st->execute([$qty, $userId, $productId]);
echo json_encode(['success' => true]);
