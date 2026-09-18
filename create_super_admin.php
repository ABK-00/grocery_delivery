<?php
require_once __DIR__ . '/config/db.php';
$message = '';
$type = 'danger';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $message = 'Enter a name, valid email and password of at least 8 characters.';
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$email]);
        if ($check->fetch()) {
            $message = 'That email already exists.';
        } else {
            $stmt = $conn->prepare("INSERT INTO users (company_id,name,email,password,role,status) VALUES (NULL,?,?,?,'super_admin','active')");
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $message = 'Super Admin created. Delete create_super_admin.php now, then log in.';
            $type = 'success';
        }
    }
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow-sm border-0 mx-auto" style="max-width:520px">
            <div class="card-body p-4">
                <h2>Platform Super Admin</h2>
                <p class="text-muted">One-time setup. Run the multi-company migration first.</p><?php if ($message): ?><div class="alert alert-<?= $type ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?><form method="post">
                    <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Email</label><input name="email" type="email" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Password</label><input name="password" type="password" minlength="8" class="form-control" required></div><button class="btn btn-success w-100">Create Super Admin</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>