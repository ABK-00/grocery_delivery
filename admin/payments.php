<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');
requireCompanyAccess();

$companyId = currentCompanyId();

$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$allowedStatuses = [
    'pending',
    'paid',
    'failed',
    'refunded'
];

$where = ['o.company_id = ?'];
$params = [$companyId];

if (
    $status !== ''
    && in_array($status, $allowedStatuses, true)
) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = '(
        p.reference LIKE ?
        OR o.order_number LIKE ?
        OR u.name LIKE ?
        OR u.email LIKE ?
    )';

    $like = '%' . $search . '%';

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like
    );
}

$whereSql = implode(' AND ', $where);

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.reference,
        p.amount,
        p.currency,
        p.payment_method,
        p.status,
        p.gateway,
        p.paid_at,
        p.created_at,
        o.id AS order_id,
        o.order_number,
        o.status AS order_status,
        u.name AS customer_name,
        u.email AS customer_email,
        r.id AS refund_id,
        r.status AS refund_status,
        r.amount AS refund_amount
    FROM payments p
    INNER JOIN orders o
        ON o.id = p.order_id
    INNER JOIN users u
        ON u.id = p.user_id
    LEFT JOIN refunds r
        ON r.payment_id = p.id
    WHERE {$whereSql}
    ORDER BY p.id DESC
    LIMIT 250
");

$stmt->execute($params);
$payments = $stmt->fetchAll();

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(p.status = 'paid') AS paid_count,
        SUM(p.status = 'failed') AS failed_count,
        SUM(p.status = 'refunded') AS refunded_count,
        COALESCE(
            SUM(
                CASE
                    WHEN p.status = 'paid'
                    THEN p.amount
                    ELSE 0
                END
            ),
            0
        ) AS paid_volume
    FROM payments p
    INNER JOIN orders o
        ON o.id = p.order_id
    WHERE o.company_id = ?
");

$statsStmt->execute([$companyId]);
$stats = $statsStmt->fetch() ?: [];

function paymentBadge(string $status): string
{
    return match ($status) {
        'paid' => 'bg-success',
        'failed' => 'bg-danger',
        'refunded' => 'bg-info text-dark',
        default => 'bg-warning text-dark'
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Payments | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
    <link href="../assets/css/admin-farvist.css" rel="stylesheet">
</head>
<body class="admin-farvist">
<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>

<main class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1 class="topbar-title">Payments</h1>
                <p class="topbar-subtitle">
                    Gateway-verified order payments and refunds.
                </p>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-shield-check me-1"></i>
        Payment status is gateway-controlled and cannot be edited manually.
        Refunds are initiated from an eligible paid order.
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Transactions', (int)($stats['total_count'] ?? 0)],
            ['Paid', (int)($stats['paid_count'] ?? 0)],
            ['Failed', (int)($stats['failed_count'] ?? 0)],
            ['Refunded', (int)($stats['refunded_count'] ?? 0)],
            ['Paid Volume', 'GH₵' . number_format((float)($stats['paid_volume'] ?? 0), 2)]
        ] as [$label, $value]): ?>
            <div class="col-md">
                <div class="stat-card">
                    <div class="stat-number"><?= e($value) ?></div>
                    <div class="stat-label"><?= e($label) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="content-card mb-4 p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-lg-7">
                <label class="form-label small fw-semibold">Search</label>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Reference, order number, customer name or email"
                >
            </div>
            <div class="col-lg-3">
                <label class="form-label small fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach ($allowedStatuses as $value): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $status === $value ? 'selected' : '' ?>
                        >
                            <?= e(ucfirst($value)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-dark" type="submit">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <div class="content-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="px-4">Reference</th>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Payment</th>
                        <th>Refund</th>
                        <th>Date</th>
                        <th class="text-end px-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            No payments found.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td class="px-4">
                            <code><?= e($payment['reference']) ?></code>
                        </td>
                        <td>
                            <strong><?= e($payment['order_number']) ?></strong>
                            <small class="d-block text-muted">
                                <?= e(ucwords(str_replace('_', ' ', $payment['order_status']))) ?>
                            </small>
                        </td>
                        <td>
                            <?= e($payment['customer_name']) ?>
                            <small class="d-block text-muted">
                                <?= e($payment['customer_email']) ?>
                            </small>
                        </td>
                        <td>
                            <?= e($payment['currency']) ?>
                            <?= number_format((float)$payment['amount'], 2) ?>
                        </td>
                        <td class="text-capitalize">
                            <?= e($payment['payment_method'] ?: '—') ?>
                        </td>
                        <td>
                            <span class="badge <?= paymentBadge($payment['status']) ?>">
                                <?= e(ucfirst($payment['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($payment['refund_id']): ?>
                                <span class="badge bg-secondary">
                                    <?= e(ucwords(str_replace('_', ' ', (string)$payment['refund_status']))) ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e(date(
                                'd M Y, h:i A',
                                strtotime($payment['paid_at'] ?: $payment['created_at'])
                            )) ?>
                        </td>
                        <td class="text-end px-4">
                            <a
                                href="order_details.php?id=<?= (int)$payment['order_id'] ?>"
                                class="btn btn-sm btn-outline-success"
                            >
                                View Order
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
