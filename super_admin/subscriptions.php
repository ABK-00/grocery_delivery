<?php require_once '../config/db.php';
require_once '../includes/auth.php';
requireRole('super_admin');
$rows = $conn->query("SELECT *,CASE WHEN subscription_plan='trial' THEN trial_ends_at ELSE subscription_ends_at END access_until FROM companies ORDER BY access_until ASC,company_name")->fetchAll(); ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Subscriptions | Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/super_admin.css" rel="stylesheet">
</head>

<body><?php include '../includes/loader.php';
        include '../includes/super_admin_sidebar.php'; ?><main class="main-content">
        <h2 class="fw-bold">Subscriptions</h2>
        <p class="text-muted mb-4">Track trials, paid plans and expiry dates.</p>
        <div class="panel p-3">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Plan</th>
                            <th>Access Until</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($rows as $r): $expired = $r['access_until'] && strtotime($r['access_until']) < time(); ?><tr>
                                <td><strong><?= htmlspecialchars($r['company_name']) ?></strong><small class="d-block text-muted"><?= htmlspecialchars($r['company_code']) ?></small></td>
                                <td><?= ucfirst($r['subscription_plan']) ?></td>
                                <td><?= $r['access_until'] ? date('M j, Y', strtotime($r['access_until'])) : '—' ?></td>
                                <td><span class="badge text-bg-<?= $expired ? 'danger' : 'success' ?>"><?= $expired ? 'Expired' : 'Valid' ?></span></td>
                                <td><a href="company_details.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-success">Manage</a></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </div>
    </main>
</body>

</html>