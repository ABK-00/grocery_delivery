<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
requireCompanyAccess();
header('Content-Type: application/json');
$userId = (int)$_SESSION['user_id'];
$companyId = currentCompanyId();
$productId = (int)($_POST['product_id'] ?? 0);
$st = $conn->prepare("DELETE c FROM cart c INNER JOIN products p ON p.id=c.product_id WHERE c.user_id=? AND c.product_id=? AND p.company_id=?");
$st->execute([$userId, $productId, $companyId]);
echo json_encode(['success' => true]);
