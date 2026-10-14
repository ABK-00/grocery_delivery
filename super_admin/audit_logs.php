<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$search = trim((string)($_GET['search'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));

$where = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR c.company_name LIKE ? OR a.entity_id LIKE ?) ";
    $needle = "%{$search}%";
    array_push($params, $needle, $needle, $needle, $needle);
}

if ($action !== '') {
    $where .= " AND a.action LIKE ? ";
    $params[] = "%{$action}%";
}

$stmt = $conn->prepare("
    SELECT
        a.*,
        u.name AS user_name,
        u.email AS user_email,
        c.company_name
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN companies c ON c.id = a.company_id
    {$where}
    ORDER BY a.id DESC
    LIMIT 300
");
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Audit Logs | GroceryDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>
<main class="main-content">
<div class="topbar"><div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div><h1 class="topbar-title">Audit Logs</h1><p class="topbar-subtitle">Security and operational activity across the platform.</p></div></div></div>

<div class="content-card p-3 mb-4">
<form method="GET" class="row g-2">
<div class="col-md-6"><input class="form-control" name="search" value="<?= e($search) ?>" placeholder="User, email, company or entity ID"></div>
<div class="col-md-4"><input class="form-control" name="action" value="<?= e($action) ?>" placeholder="Action contains..."></div>
<div class="col-md-2"><button class="btn btn-dark w-100">Filter</button></div>
</form>
</div>

<div class="content-card"><div class="table-responsive"><table class="table mb-0">
<thead><tr><th class="px-4">Time</th><th>User</th><th>Company</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead>
<tbody>
<?php foreach($logs as $log): ?><tr>
<td class="px-4"><?= e(date('d M Y H:i:s',strtotime($log['created_at']))) ?></td>
<td><?= e($log['user_name'] ?: 'System') ?><br><small class="text-muted"><?= e($log['user_email'] ?: '') ?></small></td>
<td><?= e($log['company_name'] ?: '—') ?></td>
<td><code><?= e($log['action']) ?></code></td>
<td><?= e(($log['entity_type'] ?: '—') . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')) ?></td>
<td><?= e($log['ip_address'] ?: '—') ?></td>
<td><small class="text-muted"><?= e(mb_strimwidth((string)$log['details'],0,180,'…')) ?></small></td>
</tr><?php endforeach; ?>
<?php if(!$logs): ?><tr><td colspan="7" class="text-center text-muted py-5">No audit entries found.</td></tr><?php endif; ?>
</tbody></table></div></div>
</main>
</body>
</html>
