<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');
requireCompanyAccess();

$companyId = currentCompanyId();

$search = trim((string) ($_GET['search'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));

$allowedRoles = [
    'admin',
    'staff',
    'delivery_partner'
];

$sql = "
    SELECT
        id,
        name,
        email,
        phone,
        whatsapp,
        role,
        status,
        created_at
    FROM users
    WHERE company_id = ?
      AND role IN (
          'admin',
          'staff',
          'delivery_partner'
      )
";

$params = [$companyId];

if ($search !== '') {
    $sql .= "
        AND (
            name LIKE ?
            OR email LIKE ?
            OR phone LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    array_push(
        $params,
        $like,
        $like,
        $like
    );
}

if (
    $roleFilter !== ''
    && in_array($roleFilter, $allowedRoles, true)
) {
    $sql .= " AND role = ? ";
    $params[] = $roleFilter;
}

$sql .= " ORDER BY created_at DESC ";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(role = 'admin') AS admin_count,
        SUM(role = 'staff') AS staff_count,
        SUM(role = 'delivery_partner') AS partner_count
    FROM users
    WHERE company_id = ?
      AND role IN (
          'admin',
          'staff',
          'delivery_partner'
      )
");

$statsStmt->execute([$companyId]);
$stats = $statsStmt->fetch() ?: [];

function userRoleBadge(string $role): string
{
    return match ($role) {
        'admin' => 'bg-danger',
        'staff' => 'bg-primary',
        'delivery_partner' => 'bg-info text-dark',
        default => 'bg-secondary'
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Company Users | GroceryDelivery</title>
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
                <h1 class="topbar-title">Company Users</h1>
                <p class="topbar-subtitle">
                    Company-side accounts only. Marketplace customers are platform accounts and are never owned by a vendor.
                </p>
            </div>
        </div>

        <div class="d-flex gap-2">
            <a href="staff.php" class="btn btn-outline-primary">
                <i class="bi bi-people me-1"></i> Manage Staff
            </a>
            <a href="delivery_partners.php" class="btn btn-success">
                <i class="bi bi-truck me-1"></i> Delivery Partners
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Company Users', (int)($stats['total_count'] ?? 0)],
            ['Admins', (int)($stats['admin_count'] ?? 0)],
            ['Staff', (int)($stats['staff_count'] ?? 0)],
            ['Delivery Partners', (int)($stats['partner_count'] ?? 0)]
        ] as [$label, $value]): ?>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($value) ?></div>
                    <div class="stat-label"><?= e($label) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="content-card mb-4 p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-lg-8">
                <label class="form-label small fw-semibold">Search</label>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Name, email or phone"
                >
            </div>
            <div class="col-lg-2">
                <label class="form-label small fw-semibold">Role</label>
                <select name="role" class="form-select">
                    <option value="">All roles</option>
                    <?php foreach ($allowedRoles as $role): ?>
                        <option
                            value="<?= e($role) ?>"
                            <?= $roleFilter === $role ? 'selected' : '' ?>
                        >
                            <?= e(ucwords(str_replace('_', ' ', $role))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-dark" type="submit">
                    Filter
                </button>
            </div>
        </form>
    </div>

    <div class="content-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="px-4">User</th>
                        <th>Contact</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            No company users found.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="px-4">
                            <strong><?= e($user['name']) ?></strong>
                            <small class="d-block text-muted">
                                <?= e($user['email']) ?>
                            </small>
                        </td>
                        <td>
                            <?= e($user['phone'] ?: '—') ?>
                            <?php if ($user['whatsapp']): ?>
                                <small class="d-block text-success">
                                    <i class="bi bi-whatsapp"></i>
                                    <?= e($user['whatsapp']) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= userRoleBadge($user['role']) ?>">
                                <?= e(ucwords(str_replace('_', ' ', $user['role']))) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $user['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= e(ucfirst($user['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?= e(date('d M Y', strtotime($user['created_at']))) ?>
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
