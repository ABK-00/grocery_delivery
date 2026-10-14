<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$orderVolume = (float)$conn->query("
    SELECT COALESCE(SUM(amount),0)
    FROM payments
    WHERE status = 'paid'
")->fetchColumn();

$subscriptionVolume = (float)$conn->query("
    SELECT COALESCE(SUM(amount),0)
    FROM subscription_payments
    WHERE status = 'paid'
")->fetchColumn();

$failedCount = (int)$conn->query("
    SELECT
        (SELECT COUNT(*) FROM payments WHERE status='failed')
        +
        (SELECT COUNT(*) FROM subscription_payments WHERE status='failed')
")->fetchColumn();

$orderPayments = $conn->query("
    SELECT
        p.id,
        p.reference,
        p.amount,
        p.status,
        p.gateway,
        p.paid_at,
        p.created_at,
        o.order_number,
        c.company_name,
        u.email AS customer_email
    FROM payments p
    INNER JOIN orders o ON o.id = p.order_id
    INNER JOIN companies c ON c.id = o.company_id
    INNER JOIN users u ON u.id = p.user_id
    ORDER BY p.id DESC
    LIMIT 100
")->fetchAll();

$refunds = $conn->query("
    SELECT
        r.id,
        r.refund_reference,
        r.amount,
        r.currency,
        r.status,
        r.reason,
        r.processed_at,
        r.created_at,
        o.order_number,
        c.company_name,
        p.reference AS payment_reference
    FROM refunds r
    INNER JOIN orders o ON o.id = r.order_id
    INNER JOIN companies c ON c.id = o.company_id
    INNER JOIN payments p ON p.id = r.payment_id
    ORDER BY r.id DESC
    LIMIT 100
")->fetchAll();

$subscriptionPayments = $conn->query("
    SELECT
        sp.id,
        sp.reference,
        sp.plan,
        sp.amount,
        sp.status,
        sp.paid_at,
        sp.created_at,
        c.company_name,
        u.email AS admin_email
    FROM subscription_payments sp
    INNER JOIN companies c ON c.id = sp.company_id
    INNER JOIN users u ON u.id = sp.user_id
    ORDER BY sp.id DESC
    LIMIT 100
")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Platform Payments | GroceryDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>
<main class="main-content">
<div class="topbar"><div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div><h1 class="topbar-title">Platform Payments</h1><p class="topbar-subtitle">Order and subscription payment activity.</p></div></div></div>

<div class="row g-3 mb-4">
<div class="col-md-4"><div class="stat-card"><div class="stat-number">GH₵<?= number_format($orderVolume,2) ?></div><div class="stat-label">Paid Order Volume</div></div></div>
<div class="col-md-4"><div class="stat-card"><div class="stat-number">GH₵<?= number_format($subscriptionVolume,2) ?></div><div class="stat-label">Subscription Revenue</div></div></div>
<div class="col-md-4"><div class="stat-card"><div class="stat-number"><?= number_format($failedCount) ?></div><div class="stat-label">Failed Payments</div></div></div>
</div>

<div class="content-card mb-4">
<div class="p-4 border-bottom"><h5 class="mb-0 fw-bold">Order Payments</h5></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th class="px-4">Reference</th><th>Order</th><th>Company</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach($orderPayments as $p): ?><tr>
<td class="px-4"><code><?= e($p['reference']) ?></code></td>
<td><?= e($p['order_number']) ?></td>
<td><?= e($p['company_name']) ?></td>
<td><?= e($p['customer_email']) ?></td>
<td>GH₵<?= number_format((float)$p['amount'],2) ?></td>
<td><span class="badge <?= $p['status']==='paid'?'bg-success':($p['status']==='failed'?'bg-danger':'bg-warning text-dark') ?>"><?= e(ucfirst($p['status'])) ?></span></td>
<td><?= e(date('d M Y H:i',strtotime($p['paid_at'] ?: $p['created_at']))) ?></td>
</tr><?php endforeach; ?>
<?php if(!$orderPayments): ?><tr><td colspan="7" class="text-center text-muted py-5">No order payments.</td></tr><?php endif; ?>
</tbody></table></div>
</div>

<div class="content-card mb-4">
<div class="p-4 border-bottom"><h5 class="mb-0 fw-bold">Refunds</h5></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th class="px-4">Refund</th><th>Order</th><th>Company</th><th>Payment</th><th>Amount</th><th>Status</th><th>Reason</th><th>Date</th></tr></thead><tbody>
<?php foreach($refunds as $r): ?><tr>
<td class="px-4"><code><?= e($r['refund_reference'] ?: 'Pending gateway reference') ?></code></td>
<td><?= e($r['order_number']) ?></td>
<td><?= e($r['company_name']) ?></td>
<td><code><?= e($r['payment_reference']) ?></code></td>
<td><?= e($r['currency']) ?> <?= number_format((float)$r['amount'],2) ?></td>
<td><span class="badge <?= $r['status']==='processed'?'bg-success':($r['status']==='failed'?'bg-danger':'bg-warning text-dark') ?>"><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></span></td>
<td><?= e($r['reason'] ?: '—') ?></td>
<td><?= e(date('d M Y H:i',strtotime($r['processed_at'] ?: $r['created_at']))) ?></td>
</tr><?php endforeach; ?>
<?php if(!$refunds): ?><tr><td colspan="8" class="text-center text-muted py-5">No refunds.</td></tr><?php endif; ?>
</tbody></table></div>
</div>

<div class="content-card">
<div class="p-4 border-bottom"><h5 class="mb-0 fw-bold">Subscription Payments</h5></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th class="px-4">Reference</th><th>Company</th><th>Plan</th><th>Admin</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach($subscriptionPayments as $p): ?><tr>
<td class="px-4"><code><?= e($p['reference']) ?></code></td>
<td><?= e($p['company_name']) ?></td>
<td><?= e(ucfirst($p['plan'])) ?></td>
<td><?= e($p['admin_email']) ?></td>
<td>GH₵<?= number_format((float)$p['amount'],2) ?></td>
<td><span class="badge <?= $p['status']==='paid'?'bg-success':($p['status']==='failed'?'bg-danger':'bg-warning text-dark') ?>"><?= e(ucfirst($p['status'])) ?></span></td>
<td><?= e(date('d M Y H:i',strtotime($p['paid_at'] ?: $p['created_at']))) ?></td>
</tr><?php endforeach; ?>
<?php if(!$subscriptionPayments): ?><tr><td colspan="7" class="text-center text-muted py-5">No subscription payments.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
</main>
</body>
</html>
