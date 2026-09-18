<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireRole('super_admin');
$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['company_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$adminName || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) $err = 'Complete all required fields correctly. Password must be at least 8 characters.';
    else try {
        $conn->beginTransaction();
        do {
            $code = 'GD-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $s = $conn->prepare('SELECT id FROM companies WHERE company_code=?');
            $s->execute([$code]);
        } while ($s->fetch());
        $s = $conn->prepare("INSERT INTO companies(company_code,company_name,email,phone,status,subscription_plan,trial_ends_at) VALUES(?,?,?,?, 'active','trial',DATE_ADD(NOW(),INTERVAL 30 DAY))");
        $s->execute([$code, $name, $email, $phone ?: null]);
        $cid = (int)$conn->lastInsertId();
        $s = $conn->prepare("INSERT INTO users(company_id,name,email,phone,password,role,status) VALUES(?,?,?,?,?,'admin','active')");
        $s->execute([$cid, $adminName, $adminEmail, $phone ?: null, password_hash($password, PASSWORD_DEFAULT)]);
        $conn->commit();
        $msg = "Company created. Code: $code";
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $err = 'Could not create company. The admin/company email may already exist.';
    }
}
$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$sql = 'SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.company_id=c.id) user_count FROM companies c WHERE 1=1';
$p = [];
if ($q !== '') {
    $sql .= ' AND (c.company_name LIKE ? OR c.company_code LIKE ? OR c.email LIKE ?)';
    $like = "%$q%";
    $p = [$like, $like, $like];
}
if (in_array($status, ['active', 'suspended', 'blocked'])) {
    $sql .= ' AND c.status=?';
    $p[] = $status;
}
$sql .= ' ORDER BY c.created_at DESC';
$s = $conn->prepare($sql);
$s->execute($p);
$companies = $s->fetchAll();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Companies | Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/super_admin.css" rel="stylesheet">
</head>

<body><?php include '../includes/loader.php';
        include '../includes/super_admin_sidebar.php'; ?><main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">Companies</h2>
                <p class="text-muted mb-0">Create and control businesses using the platform.</p>
            </div><button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCompany"><i class="bi bi-plus-lg"></i> Add Company</button>
        </div><?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?><?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?><div class="panel p-3 mb-4">
            <form class="row g-2">
                <div class="col-md-7"><input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search company, code or email"></div>
                <div class="col-md-3"><select class="form-select" name="status">
                        <option value="">All statuses</option><?php foreach (['active', 'suspended', 'blocked'] as $v): ?><option <?= $status === $v ? 'selected' : '' ?> value="<?= $v ?>"><?= ucfirst($v) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><button class="btn btn-dark w-100">Filter</button></div>
            </form>
        </div>
        <div class="panel p-3">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Code</th>
                            <th>Plan</th>
                            <th>Users</th>
                            <th>Access Until</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($companies as $c): $until = $c['subscription_plan'] === 'trial' ? $c['trial_ends_at'] : $c['subscription_ends_at']; ?><tr>
                                <td><strong><?= htmlspecialchars($c['company_name']) ?></strong><small class="d-block text-muted"><?= htmlspecialchars($c['email']) ?></small></td>
                                <td><?= htmlspecialchars($c['company_code']) ?></td>
                                <td><?= ucfirst($c['subscription_plan']) ?></td>
                                <td><?= (int)$c['user_count'] ?></td>
                                <td><?= $until ? date('M j, Y', strtotime($until)) : '—' ?></td>
                                <td><span class="badge text-bg-<?= $c['status'] === 'active' ? 'success' : ($c['status'] === 'suspended' ? 'warning' : 'danger') ?>"><?= ucfirst($c['status']) ?></span></td>
                                <td><a class="btn btn-sm btn-outline-success" href="company_details.php?id=<?= $c['id'] ?>">Manage</a></td>
                            </tr><?php endforeach; ?><?php if (!$companies): ?><tr>
                                <td colspan="7" class="text-center text-muted py-5">No companies found.</td>
                            </tr><?php endif; ?></tbody>
                </table>
            </div>
        </div>
    </main>
    <div class="modal fade" id="addCompany">
        <div class="modal-dialog modal-lg">
            <form method="post" class="modal-content"><input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Register Company</h5><button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Company Name *</label><input name="company_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Company Email *</label><input type="email" name="email" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Phone</label><input name="phone" class="form-control"></div>
                        <div class="col-12">
                            <hr>
                            <h6>First Company Administrator</h6>
                        </div>
                        <div class="col-md-6"><label class="form-label">Admin Name *</label><input name="admin_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Admin Email *</label><input type="email" name="admin_email" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Temporary Password *</label><input type="password" minlength="8" name="password" class="form-control" required></div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">New companies receive a 30-day trial automatically.</div>
                </div>
                <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Create Company</button></div>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>