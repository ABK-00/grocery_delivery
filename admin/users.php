<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole("admin");

$message = "";
$messageType = "success";

/* =========================================================
   ADD USER
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_user"])) {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $whatsapp = trim($_POST["whatsapp"] ?? "");
    $role = trim($_POST["role"] ?? "customer");
    $password = $_POST["password"] ?? "";

    $allowedRoles = ["admin", "staff", "delivery_partner", "customer"];

    if ($name === "" || $email === "" || $password === "") {
        $message = "Name, email, and password are required.";
        $messageType = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = "danger";
    } elseif (!in_array($role, $allowedRoles, true)) {
        $message = "Invalid user role selected.";
        $messageType = "danger";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$email]);

        if ($check->fetch()) {
            $message = "An account with this email already exists.";
            $messageType = "danger";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                INSERT INTO users (name, email, phone, whatsapp, password, role, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");

            $stmt->execute([
                $name,
                $email,
                $phone !== "" ? $phone : null,
                $whatsapp !== "" ? $whatsapp : null,
                $hashedPassword,
                $role
            ]);

            $message = "User account created successfully.";
        }
    }
}

/* =========================================================
   TOGGLE STATUS
   ========================================================= */
if (isset($_GET["toggle"]) && is_numeric($_GET["toggle"])) {
    $userId = (int)$_GET["toggle"];
    $stmt = $conn->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user) {
        $newStatus = ($user["status"] === "active") ? "inactive" : "active";
        $conn->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
        $message = "User status updated.";
    }
}

/* =========================================================
   FETCH USERS & STATS
   ========================================================= */
$search = trim($_GET["search"] ?? "");
$roleFilter = trim($_GET["role"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($search !== "") {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($roleFilter !== "") {
    $sql .= " AND role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter !== "") {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Stats
$totalCount = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$customerCount = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$staffCount = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetchColumn();
$partnerCount = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'delivery_partner'")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | Grocery Delivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
    <style>
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dcfce7;
            color: #15803d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>

<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1 class="topbar-title">Users Management</h1>
                <p class="topbar-subtitle">Manage customer, staff, and system user accounts.</p>
            </div>
        </div>
        <div>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal" type="button">
                <i class="bi bi-person-plus me-1"></i> Add User
            </button>
        </div>
    </div>

    <!-- MESSAGE -->
    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show mb-4">
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Users</div>
                        <div class="stat-number"><?= $totalCount ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-people"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Customers</div>
                        <div class="stat-number"><?= $customerCount ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-person"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Staff</div>
                        <div class="stat-number"><?= $staffCount ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-person-badge"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Delivery Partners</div>
                        <div class="stat-number"><?= $partnerCount ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-bicycle"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- USERS TABLE -->
    <div class="content-card">
        <div class="p-3 border-bottom">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search name, email..." value="<?= e($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <option value="customer" <?= $roleFilter === 'customer' ? 'selected' : '' ?>>Customer</option>
                        <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option>
                        <option value="delivery_partner" <?= $roleFilter === 'delivery_partner' ? 'selected' : '' ?>>Delivery Partner</option>
                        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100"><i class="bi bi-filter"></i> Filter</button>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Contact</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No users found matching criteria.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="user-avatar">
                                            <?= strtoupper(substr($user["name"] ?? "U", 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= e($user["name"]) ?></div>
                                            <div class="text-muted small"><?= e($user["email"]) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><?= e($user["phone"] ?? "N/A") ?></div>
                                    <?php if (!empty($user["whatsapp"])): ?>
                                        <small class="text-success"><i class="bi bi-whatsapp"></i> <?= e($user["whatsapp"]) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $roleBadge = "bg-secondary";
                                    if ($user["role"] === "admin") $roleBadge = "bg-danger";
                                    elseif ($user["role"] === "staff") $roleBadge = "bg-primary";
                                    elseif ($user["role"] === "delivery_partner") $roleBadge = "bg-info text-dark";
                                    elseif ($user["role"] === "customer") $roleBadge = "bg-success";
                                    ?>
                                    <span class="badge <?= $roleBadge ?>"><?= ucfirst(e($user["role"])) ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= ($user["status"] ?? "active") === "active" ? "badge-delivered" : "badge-cancelled" ?>">
                                        <?= ucfirst(e($user["status"] ?? "active")) ?>
                                    </span>
                                </td>
                                <td><?= date("M d, Y", strtotime($user["created_at"] ?? "now")) ?></td>
                                <td class="text-end">
                                    <a href="users.php?toggle=<?= $user["id"] ?>" class="btn btn-sm btn-outline-secondary" title="Toggle Status">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Create User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address *</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label">WhatsApp</label>
                            <input type="text" name="whatsapp" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-select" required>
                            <option value="customer">Customer</option>
                            <option value="staff">Staff</option>
                            <option value="delivery_partner">Delivery Partner</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-success">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
