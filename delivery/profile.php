<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('delivery_partner');
requireCompanyAccess();

$userId = currentUserId();

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($csrf === '' || !hash_equals($_SESSION['profile_csrf'], $csrf)) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif ($name === '') {
        $error = 'Name is required.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'New password must contain at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $conn->beginTransaction();

            if ($password !== '') {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        phone = ?,
                        whatsapp = ?,
                        password = ?
                    WHERE id = ?
                      AND role = 'delivery_partner'
                ");
                $stmt->execute([
                    $name,
                    $phone ?: null,
                    $whatsapp ?: ($phone ?: null),
                    password_hash($password, PASSWORD_DEFAULT),
                    $userId
                ]);
            } else {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        phone = ?,
                        whatsapp = ?
                    WHERE id = ?
                      AND role = 'delivery_partner'
                ");
                $stmt->execute([
                    $name,
                    $phone ?: null,
                    $whatsapp ?: ($phone ?: null),
                    $userId
                ]);
            }

            writeAuditLog(
                $conn,
                $userId,
                currentCompanyId(),
                'profile.updated',
                'user',
                $userId
            );

            $conn->commit();

            $_SESSION['name'] = $name;
            $_SESSION['user_name'] = $name;
            $success = 'Profile updated.';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

$stmt = $conn->prepare("
    SELECT name, email, phone, whatsapp, created_at
    FROM users
    WHERE id = ?
      AND role = 'delivery_partner'
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) exit('Profile not found.');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delivery Partner Profile | GroceryDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/delivery_sidebar.php'; ?>
<main class="main-content p-4">
<h2 class="fw-bold mb-1">My Profile</h2>
<p class="text-muted mb-4">Manage your delivery partner account details.</p>
<?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<div class="card border-0 shadow-sm"><div class="card-body p-4">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION['profile_csrf']) ?>">
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" required value="<?= e($user['name']) ?>"></div>
<div class="col-md-6"><label class="form-label">Email</label><input class="form-control" value="<?= e($user['email']) ?>" readonly></div>
<div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($user['phone'] ?? '') ?>"></div>
<div class="col-md-6"><label class="form-label">WhatsApp</label><input class="form-control" name="whatsapp" value="<?= e($user['whatsapp'] ?? '') ?>"></div>
<div class="col-md-6"><label class="form-label">New Password</label><input type="password" class="form-control" name="password" autocomplete="new-password"></div>
<div class="col-md-6"><label class="form-label">Confirm Password</label><input type="password" class="form-control" name="confirm_password" autocomplete="new-password"></div>
</div>
<button class="btn btn-success mt-4"><i class="bi bi-save me-1"></i>Save Changes</button>
</form>
</div></div>
</main>
</body>
</html>
