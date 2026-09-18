<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireRole('super_admin');
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: companies.php');
    exit;
}
$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'status') {
            $v = $_POST['status'] ?? '';
            if (!in_array($v, ['active', 'suspended', 'blocked'])) throw new Exception();
            $conn->prepare('UPDATE companies SET status=? WHERE id=?')->execute([$v, $id]);
            $msg = 'Company access updated.';
        } elseif ($action === 'subscription') {
            $plan = $_POST['plan'] ?? '';
            $months = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];
            if ($plan === 'trial') {
                $conn->prepare("UPDATE companies SET subscription_plan='trial',trial_ends_at=DATE_ADD(NOW(),INTERVAL 30 DAY),subscription_starts_at=NULL,subscription_ends_at=NULL,status='active' WHERE id=?")->execute([$id]);
            } elseif (isset($months[$plan])) {
                $end = (new DateTime())->modify('+' . $months[$plan] . ' months')->format('Y-m-d H:i:s');
                $conn->prepare("UPDATE companies SET subscription_plan=?,subscription_starts_at=NOW(),subscription_ends_at=?,status='active' WHERE id=?")->execute([$plan, $end, $id]);
            }
            $msg = 'Subscription updated.';
        }
    } catch (Throwable $e) {
        $err = 'Update failed.';
    }
}
$s = $conn->prepare('SELECT * FROM companies WHERE id=?');
$s->execute([$id]);
$c = $s->fetch();
if (!$c) {
    http_response_code(404);
    die('Company not found');
}
$s = $conn->prepare("SELECT id,name,email,phone,role,status,created_at FROM users WHERE company_id=? ORDER BY FIELD(role,'admin','staff','delivery_partner','customer'),created_at DESC");
$s->execute([$id]);
$users = $s->fetchAll();
$stats = [];
foreach (['users' => 'SELECT COUNT(*) FROM users WHERE company_id=?', 'products' => 'SELECT COUNT(*) FROM products WHERE company_id=?', 'orders' => 'SELECT COUNT(*) FROM orders WHERE company_id=?', 'revenue' => "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE company_id=? AND payment_status='paid'"] as $k => $sql) {
    $x = $conn->prepare($sql);
    $x->execute([$id]);
    $stats[$k] = $x->fetchColumn();
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($c['company_name']) ?> | Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/super_admin.css" rel="stylesheet">
</head>

<body><?php include '../includes/loader.php';
        include '../includes/super_admin_sidebar.php'; ?><main class="main-content"><a href="companies.php" class="text-decoration-none">← Companies</a>
        <div class="d-flex justify-content-between align-items-start mt-3 mb-4">
            <div>
                <h2 class="fw-bold mb-1"><?= htmlspecialchars($c['company_name']) ?></h2>
                <div class="text-muted"><?= htmlspecialchars($c['company_code']) ?> · <?= htmlspecialchars($c['email']) ?></div>
            </div><span class="badge fs-6 text-bg-<?= $c['status'] === 'active' ? 'success' : ($c['status'] === 'suspended' ? 'warning' : 'danger') ?>"><?= ucfirst($c['status']) ?></span>
        </div><?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?><?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?><div class="row g-3 mb-4"><?php foreach ([['Users', $stats['users']], ['Products', $stats['products']], ['Orders', $stats['orders']], ['Paid Revenue', 'GHS ' . number_format($stats['revenue'], 2)]] as $m): ?><div class="col-md-6 col-xl-3">
                    <div class="metric p-4">
                        <div class="text-muted"><?= $m[0] ?></div>
                        <div class="fs-3 fw-bold"><?= $m[1] ?></div>
                    </div>
                </div><?php endforeach; ?></div>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="panel p-4">
                    <h5 class="fw-bold">Company Users</h5>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($users as $u): ?><tr>
                                        <td><?= htmlspecialchars($u['name']) ?><small class="d-block text-muted"><?= htmlspecialchars($u['email']) ?></small></td>
                                        <td><?= ucwords(str_replace('_', ' ', $u['role'])) ?></td>
                                        <td><?= ucfirst($u['status']) ?></td>
                                    </tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel p-4 mb-4">
                    <h5 class="fw-bold">Platform Access</h5>
                    <form method="post"><input type="hidden" name="action" value="status"><select name="status" class="form-select mb-3"><?php foreach (['active', 'suspended', 'blocked'] as $v): ?><option <?= $c['status'] === $v ? 'selected' : '' ?> value="<?= $v ?>"><?= ucfirst($v) ?></option><?php endforeach; ?></select><button class="btn btn-dark w-100">Update Access</button></form>
                </div>
                <div class="panel p-4">
                    <h5 class="fw-bold">Subscription</h5>
                    <p class="text-muted">Current: <?= ucfirst($c['subscription_plan']) ?></p>
                    <form method="post"><input type="hidden" name="action" value="subscription"><select name="plan" class="form-select mb-3">
                            <option value="trial">New 30-day Trial</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select><button class="btn btn-success w-100">Apply Plan</button></form>
                </div>
            </div>
        </div>
    </main>
</body>

</html>