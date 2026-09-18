<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
requireCompanyAccess();
header('Content-Type: application/json');
$userId = (int)$_SESSION['user_id'];
$companyId = currentCompanyId();
$productId = (int)($_POST['product_id'] ?? 0);
$qty = (float)($_POST['quantity'] ?? 1);
if ($productId <= 0 || $qty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or quantity.']);
    exit;
}
$st = $conn->prepare("SELECT id,stock,status FROM products WHERE id=? AND company_id=? LIMIT 1");
$st->execute([$productId, $companyId]);
$p = $st->fetch();
if (!$p || $p['status'] !== 'active') {
    echo json_encode(['success' => false, 'message' => 'Product is unavailable.']);
    exit;
}
$st = $conn->prepare("SELECT quantity FROM cart WHERE user_id=? AND product_id=?");
$st->execute([$userId, $productId]);
$existing = (float)($st->fetchColumn() ?: 0);
$new = $existing + $qty;
if ($new > (float)$p['stock']) {
    echo json_encode(['success' => false, 'message' => 'Requested quantity exceeds available stock.']);
    exit;
}
$st = $conn->prepare("INSERT INTO cart(user_id,product_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),updated_at=CURRENT_TIMESTAMP");
$st->execute([$userId, $productId, $new]);
echo json_encode(['success' => true, 'message' => 'Added to cart.']);
