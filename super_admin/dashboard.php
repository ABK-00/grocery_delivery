<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('super_admin');
$companies = (int)$conn->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$active = (int)$conn->query("SELECT COUNT(*) FROM companies WHERE status='active'")->fetchColumn();
$suspended = (int)$conn->query("SELECT COUNT(*) FROM companies WHERE status IN ('suspended','blocked')")->fetchColumn();
$expiring = (int)$conn->query("SELECT COUNT(*) FROM companies WHERE status='active' AND ((subscription_plan='trial' AND trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)) OR (subscription_plan<>'trial' AND subscription_ends_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)))")->fetchColumn();
$recent = $conn->query("SELECT * FROM companies ORDER BY created_at DESC LIMIT 8")->fetchAll();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Super Admin | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/super_admin.css" rel="stylesheet">
</head>

<body><?php include __DIR__ . '/../includes/loader.php';
        include __DIR__ . '/../includes/super_admin_sidebar.php'; ?><main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">Platform Overview</h2>
                <p class="text-muted mb-0">Monitor companies, subscriptions and platform access.</p>
            </div>
        </div>
        <div class="row g-3 mb-4"><?php foreach ([['Companies', $companies, 'building'], ['Active', $active, 'check-circle'], ['Restricted', $suspended, 'shield-x'], ['Expiring in 7 days', $expiring, 'clock-history']] as $m): ?><div class="col-md-6 col-xl-3">
                    <div class="card metric shadow-sm">
                        <div class="card-body"><i class="bi bi-<?= $m[2] ?> fs-3 text-success"></i>
                            <div class="text-muted mt-3"><?= $m[0] ?></div>
                            <div class="display-6 fw-bold"><?= $m[1] ?></div>
                        </div>
                    </div>
                </div><?php endforeach; ?></div>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Recent Companies</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Code</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($recent as $c): ?><tr>
                                    <td><?= htmlspecialchars($c['company_name']) ?></td>
                                    <td><?= htmlspecialchars($c['company_code']) ?></td>
                                    <td><?= ucfirst($c['subscription_plan']) ?></td>
                                    <td><span class="badge text-bg-<?= $c['status'] === 'active' ? 'success' : 'danger' ?>"><?= ucfirst($c['status']) ?></span></td>
                                    <td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                                </tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>

</html>