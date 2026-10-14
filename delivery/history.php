<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('delivery_partner');
requireCompanyAccess();

$userId = currentUserId();
$companyId = currentCompanyId();

$stmt = $conn->prepare("
    SELECT dp.id
    FROM delivery_partners dp
    INNER JOIN users u ON u.id = dp.user_id
    WHERE dp.user_id = ?
      AND u.company_id = ?
    LIMIT 1
");
$stmt->execute([$userId, $companyId]);
$partnerId = (int)($stmt->fetchColumn() ?: 0);

if ($partnerId <= 0) {
    exit('Delivery partner profile not found.');
}

$stmt = $conn->prepare("
    SELECT
        d.id,
        d.status,
        d.assigned_at,
        d.picked_up_at,
        d.delivered_at,
        o.id AS order_id,
        o.order_number,
        o.delivery_address,
        o.total_amount,
        o.created_at,
        u.name AS customer_name
    FROM deliveries d
    INNER JOIN orders o ON o.id = d.order_id
    INNER JOIN users u ON u.id = o.user_id
    WHERE d.delivery_partner_id = ?
      AND d.status IN ('delivered','cancelled')
    ORDER BY COALESCE(d.delivered_at, d.updated_at) DESC
    LIMIT 200
");
$stmt->execute([$partnerId]);
$history = $stmt->fetchAll();

$deliveredCount = 0;
foreach ($history as $row) {
    if ($row['status'] === 'delivered') $deliveredCount++;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delivery History | GroceryDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/delivery_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>
<main class="main-content p-4">
<div class="d-flex justify-content-between align-items-center mb-4">
<div><h2 class="fw-bold mb-1">Delivery History</h2><p class="text-muted mb-0">Completed and cancelled assignments.</p></div>
<div class="badge bg-success fs-6"><?= number_format($deliveredCount) ?> delivered</div>
</div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table mb-0">
<thead><tr><th class="px-4">Order</th><th>Customer</th><th>Address</th><th>Status</th><th>Assigned</th><th>Completed</th><th class="text-end px-4">Action</th></tr></thead>
<tbody>
<?php foreach($history as $row): ?><tr>
<td class="px-4"><strong><?= e($row['order_number']) ?></strong></td>
<td><?= e($row['customer_name']) ?></td>
<td><?= e(mb_strimwidth($row['delivery_address'],0,60,'…')) ?></td>
<td><span class="badge <?= $row['status']==='delivered'?'bg-success':'bg-danger' ?>"><?= e(ucfirst($row['status'])) ?></span></td>
<td><?= $row['assigned_at']?e(date('d M Y H:i',strtotime($row['assigned_at']))):'—' ?></td>
<td><?= $row['delivered_at']?e(date('d M Y H:i',strtotime($row['delivered_at']))):'—' ?></td>
<td class="text-end px-4"><a class="btn btn-sm btn-outline-dark" href="order_details.php?id=<?= (int)$row['id'] ?>">View</a></td>
</tr><?php endforeach; ?>
<?php if(!$history): ?><tr><td colspan="7" class="text-center text-muted py-5">No completed deliveries yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
</main>
</body>
</html>
