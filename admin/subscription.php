<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');
requireCompanyAccess();

$companyId = currentCompanyId();

$stmt = $conn->prepare("
    SELECT
        company_name,
        subscription_plan,
        trial_ends_at,
        subscription_starts_at,
        subscription_ends_at,
        status
    FROM companies
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

if (!$company) {
    exit('Company not found.');
}

$settings = [];
$stmt = $conn->query("
    SELECT setting_key, setting_value
    FROM platform_settings
    WHERE setting_key IN (
        'subscription_monthly_price',
        'subscription_quarterly_price',
        'subscription_yearly_price'
    )
");
foreach ($stmt->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$plans = [
    'monthly' => [
        'label' => 'Monthly',
        'months' => 1,
        'price' => $settings['subscription_monthly_price'] ?? null
    ],
    'quarterly' => [
        'label' => 'Quarterly',
        'months' => 3,
        'price' => $settings['subscription_quarterly_price'] ?? null
    ],
    'yearly' => [
        'label' => 'Yearly',
        'months' => 12,
        'price' => $settings['subscription_yearly_price'] ?? null
    ]
];

$historyStmt = $conn->prepare("
    SELECT
        plan,
        reference,
        amount,
        status,
        paid_at,
        created_at
    FROM subscription_payments
    WHERE company_id = ?
    ORDER BY id DESC
    LIMIT 20
");
$historyStmt->execute([$companyId]);
$history = $historyStmt->fetchAll();

$success = isset($_GET['paid']) && $_GET['paid'] === '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Subscription | GroceryDelivery</title>
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
            <button class="sidebar-toggle" id="sidebarToggle" type="button"><i class="bi bi-list"></i></button>
            <div>
                <h1 class="topbar-title">Subscription & Billing</h1>
                <p class="topbar-subtitle">Manage access for <?= e($company['company_name']) ?>.</p>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill me-1"></i>
            Subscription payment confirmed.
        </div>
    <?php endif; ?>

    <div class="content-card p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <small class="text-muted">Current Plan</small>
                <div class="fw-bold fs-5"><?= e(ucfirst($company['subscription_plan'])) ?></div>
            </div>
            <div class="col-md-3">
                <small class="text-muted">Company Status</small>
                <div class="fw-bold fs-5"><?= e(ucfirst($company['status'])) ?></div>
            </div>
            <div class="col-md-3">
                <small class="text-muted">Trial Ends</small>
                <div class="fw-semibold"><?= $company['trial_ends_at'] ? e(date('d M Y', strtotime($company['trial_ends_at']))) : '—' ?></div>
            </div>
            <div class="col-md-3">
                <small class="text-muted">Paid Access Ends</small>
                <div class="fw-semibold"><?= $company['subscription_ends_at'] ? e(date('d M Y', strtotime($company['subscription_ends_at']))) : '—' ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <?php foreach ($plans as $key => $plan): ?>
            <div class="col-lg-4">
                <div class="content-card p-4 h-100">
                    <h4 class="fw-bold"><?= e($plan['label']) ?></h4>
                    <p class="text-muted"><?= (int)$plan['months'] ?> month<?= $plan['months'] > 1 ? 's' : '' ?> of platform access.</p>

                    <?php if ($plan['price'] !== null && $plan['price'] !== '' && (float)$plan['price'] > 0): ?>
                        <div class="display-6 fw-bold mb-3">
                            GH₵<?= number_format((float)$plan['price'], 2) ?>
                        </div>
                        <a
                            href="<?= BASE_URL ?>/api/start_subscription_payment.php?plan=<?= e($key) ?>"
                            class="btn btn-success w-100"
                        >
                            <i class="bi bi-credit-card me-1"></i>
                            Pay with Paystack
                        </a>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">
                            Pricing for this plan has not been configured by the platform administrator.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="content-card">
        <div class="p-4 border-bottom">
            <h5 class="fw-bold mb-0">Billing History</h5>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th class="px-4">Plan</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$history): ?>
                    <tr><td colspan="5" class="text-center text-muted py-5">No subscription payments yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($history as $item): ?>
                    <tr>
                        <td class="px-4"><?= e(ucfirst($item['plan'])) ?></td>
                        <td><code><?= e($item['reference']) ?></code></td>
                        <td>GH₵<?= number_format((float)$item['amount'], 2) ?></td>
                        <td>
                            <span class="badge <?= $item['status'] === 'paid' ? 'bg-success' : ($item['status'] === 'failed' ? 'bg-danger' : 'bg-warning text-dark') ?>">
                                <?= e(ucfirst($item['status'])) ?>
                            </span>
                        </td>
                        <td><?= e(date('d M Y, h:i A', strtotime($item['paid_at'] ?: $item['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>
