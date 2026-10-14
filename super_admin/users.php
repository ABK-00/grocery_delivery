<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$userId = currentUserId();

if (empty($_SESSION['platform_users_csrf'])) {
    $_SESSION['platform_users_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $targetId = (int)($_POST['user_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');

    if ($csrf === '' || !hash_equals($_SESSION['platform_users_csrf'], $csrf)) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif ($targetId <= 0 || !in_array($status, ['active','inactive','suspended'], true)) {
        $error = 'Invalid user action.';
    } elseif ($targetId === $userId) {
        $error = 'You cannot change your own account status here.';
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT id, role, company_id, status, email
                FROM users
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();

            if (!$target) {
                throw new RuntimeException('User not found.');
            }

            if ($target['role'] === 'super_admin') {
                throw new RuntimeException('Super administrator accounts must be managed separately.');
            }

            $update = $conn->prepare("
                UPDATE users
                SET status = ?
                WHERE id = ?
                  AND role <> 'super_admin'
            ");
            $update->execute([$status, $targetId]);

            writeAuditLog(
                $conn,
                $userId,
                $target['company_id'] !== null ? (int)$target['company_id'] : null,
                'user.status_changed',
                'user',
                $targetId,
                [
                    'email' => $target['email'],
                    'from' => $target['status'],
                    'to' => $status
                ]
            );

            $conn->commit();
            $success = 'User status updated.';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$role = trim((string)($_GET['role'] ?? ''));

$where = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR c.company_name LIKE ?) ";
    $needle = "%{$search}%";
    array_push($params, $needle, $needle, $needle);
}

$roles = ['super_admin','admin','staff','customer','delivery_partner'];

if ($role !== '' && in_array($role, $roles, true)) {
    $where .= " AND u.role = ? ";
    $params[] = $role;
}

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.role,
        u.status,
        u.created_at,
        c.company_name
    FROM users u
    LEFT JOIN companies c ON c.id = u.company_id
    {$where}
    ORDER BY u.created_at DESC
    LIMIT 250
");
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Platform Users | GroceryDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>
<main class="main-content">
<div class="topbar"><div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div><h1 class="topbar-title">Platform Users</h1><p class="topbar-subtitle">Customers, company users and delivery accounts.</p></div></div></div>

<?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<div class="content-card mb-4 p-3">
<form method="GET" class="row g-2">
<div class="col-md-6"><input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search name, email or company"></div>
<div class="col-md-3"><select class="form-select" name="role"><option value="">All roles</option><?php foreach($roles as $r): ?><option value="<?= e($r) ?>" <?= $role===$r?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$r))) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><button class="btn btn-dark w-100">Filter</button></div>
</form>
</div>

<div class="content-card"><div class="table-responsive"><table class="table mb-0">
<thead><tr><th class="px-4">User</th><th>Role</th><th>Company</th><th>Phone</th><th>Status</th><th>Joined</th><th class="text-end px-4">Action</th></tr></thead>
<tbody>
<?php foreach($users as $u): ?><tr>
<td class="px-4"><div class="fw-semibold"><?= e($u['name']) ?></div><small class="text-muted"><?= e($u['email']) ?></small></td>
<td><?= e(ucwords(str_replace('_',' ',$u['role']))) ?></td>
<td><?= e($u['company_name'] ?: 'Platform') ?></td>
<td><?= e($u['phone'] ?: '—') ?></td>
<td><span class="badge <?= $u['status']==='active'?'bg-success':($u['status']==='suspended'?'bg-danger':'bg-secondary') ?>"><?= e(ucfirst($u['status'])) ?></span></td>
<td><?= e(date('d M Y',strtotime($u['created_at']))) ?></td>
<td class="text-end px-4">
<?php if($u['role']!=='super_admin' && (int)$u['id']!==$userId): ?>
<form method="POST" class="d-inline-flex gap-2">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION['platform_users_csrf']) ?>">
<input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
<select name="status" class="form-select form-select-sm">
<?php foreach(['active','inactive','suspended'] as $st): ?><option value="<?= e($st) ?>" <?= $u['status']===$st?'selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach; ?>
</select>
<button class="btn btn-sm btn-outline-dark">Save</button>
</form>
<?php else: ?><span class="text-muted small">Protected</span><?php endif; ?>
</td>
</tr><?php endforeach; ?>
<?php if(!$users): ?><tr><td colspan="7" class="text-center text-muted py-5">No users found.</td></tr><?php endif; ?>
</tbody></table></div></div>
</main>
</body>
</html>
