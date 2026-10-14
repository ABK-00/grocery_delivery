<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$summary = [
    'companies' => (int)$conn->query("SELECT COUNT(*) FROM companies")->fetchColumn(),
    'customers' => (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
    'orders' => (int)$conn->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'paid_volume' => (float)$conn->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status='paid'")->fetchColumn(),
    'subscription_revenue' => (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid'")->fetchColumn(),
];

$companyRows = $conn->query("
    SELECT
        c.id,
        c.company_name,
        c.subscription_plan,
        c.status,
        COUNT(DISTINCT o.id) AS order_count,
        COALESCE(SUM(CASE WHEN o.payment_status='paid' THEN o.total_amount ELSE 0 END),0) AS paid_volume,
        COUNT(DISTINCT CASE WHEN o.status='delivered' THEN o.id END) AS delivered_orders,
        COUNT(DISTINCT p.id) AS product_count
    FROM companies c
    LEFT JOIN orders o ON o.company_id = c.id
    LEFT JOIN products p ON p.company_id = c.id
    GROUP BY c.id
    ORDER BY paid_volume DESC, order_count DESC
    LIMIT 100
")->fetchAll();

$monthly = $conn->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month_key,
        COUNT(*) AS orders,
        COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END),0) AS paid_volume
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month_key ASC
")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Platform Reports | GroceryDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>
<main class="main-content">
<div class="topbar"><div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div><h1 class="topbar-title">Platform Reports</h1><p class="topbar-subtitle">Marketplace performance across vendors.</p></div></div></div>

<div class="row g-3 mb-4">
<?php foreach([
['Companies',$summary['companies']],
['Customers',$summary['customers']],
['Orders',$summary['orders']],
['Paid Order Volume','GH₵'.number_format($summary['paid_volume'],2)],
['Subscription Revenue','GH₵'.number_format($summary['subscription_revenue'],2)]
] as [$label,$value]): ?>
<div class="col-md"><div class="stat-card"><div class="stat-number"><?= e($value) ?></div><div class="stat-label"><?= e($label) ?></div></div></div>
<?php endforeach; ?>
</div>

<div class="content-card mb-4">
<div class="p-4 border-bottom"><h5 class="fw-bold mb-0">Company Performance</h5></div>
<div class="table-responsive"><table class="table mb-0">
<thead><tr><th class="px-4">Company</th><th>Plan</th><th>Status</th><th>Products</th><th>Orders</th><th>Delivered</th><th>Paid Volume</th></tr></thead>
<tbody>
<?php foreach($companyRows as $row): ?><tr>
<td class="px-4"><a href="company_details.php?id=<?= (int)$row['id'] ?>" class="text-decoration-none fw-semibold"><?= e($row['company_name']) ?></a></td>
<td><?= e(ucfirst($row['subscription_plan'])) ?></td>
<td><?= e(ucfirst($row['status'])) ?></td>
<td><?= number_format((int)$row['product_count']) ?></td>
<td><?= number_format((int)$row['order_count']) ?></td>
<td><?= number_format((int)$row['delivered_orders']) ?></td>
<td>GH₵<?= number_format((float)$row['paid_volume'],2) ?></td>
</tr><?php endforeach; ?>
</tbody>
</table></div>
</div>

<div class="content-card">
<div class="p-4 border-bottom"><h5 class="fw-bold mb-0">Monthly Order Volume</h5></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th class="px-4">Month</th><th>Orders</th><th>Paid Volume</th></tr></thead><tbody>
<?php foreach($monthly as $row): ?><tr><td class="px-4"><?= e($row['month_key']) ?></td><td><?= number_format((int)$row['orders']) ?></td><td>GH₵<?= number_format((float)$row['paid_volume'],2) ?></td></tr><?php endforeach; ?>
<?php if(!$monthly): ?><tr><td colspan="3" class="text-center text-muted py-5">No report data yet.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
</main>
</body>
</html>
