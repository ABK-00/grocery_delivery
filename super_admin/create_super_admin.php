<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$countStmt = $conn->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'super_admin'
");

$superAdminCount = (int) $countStmt->fetchColumn();

if ($superAdminCount > 0) {
    header(
        'Location: '
        . BASE_URL
        . '/super_admin/login.php'
    );
    exit;
}

if (empty($_SESSION['create_super_admin_csrf'])) {
    $_SESSION['create_super_admin_csrf'] =
        bin2hex(random_bytes(32));
}

$message = '';
$type = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf =
        (string) ($_POST['csrf_token'] ?? '');

    $name =
        trim((string) ($_POST['name'] ?? ''));

    $email =
        strtolower(
            trim((string) ($_POST['email'] ?? ''))
        );

    $password =
        (string) ($_POST['password'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            $_SESSION['create_super_admin_csrf'],
            $csrf
        )
    ) {
        $message =
            'Your session expired. Refresh the page and try again.';
    } elseif (
        $name === ''
        || !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
        || strlen($password) < 8
    ) {
        $message =
            'Enter a name, valid email and password of at least 8 characters.';
    } else {

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $check->execute([
            $email
        ]);

        if ($check->fetch()) {
            $message =
                'That email already exists.';
        } else {

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    company_id,
                    name,
                    email,
                    password,
                    role,
                    status
                )
                VALUES
                (
                    NULL,
                    ?,
                    ?,
                    ?,
                    'super_admin',
                    'active'
                )
            ");

            $stmt->execute([
                $name,
                $email,
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                )
            ]);

            unset(
                $_SESSION['create_super_admin_csrf']
            );

            header(
                'Location: '
                . BASE_URL
                . '/super_admin/login.php?created=1'
            );

            exit;
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >
    <title>Create Super Admin</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-light">
    <main class="container py-5">
        <div
            class="card shadow-sm border-0 mx-auto"
            style="max-width:520px"
        >
            <div class="card-body p-4">
                <h2>Platform Super Admin</h2>

                <p class="text-muted">
                    One-time platform setup.
                </p>

                <?php if ($message): ?>
                    <div
                        class="alert alert-<?= htmlspecialchars($type) ?>"
                    >
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <form method="post">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $_SESSION['create_super_admin_csrf']
                        ) ?>"
                    >

                    <div class="mb-3">
                        <label class="form-label">
                            Name
                        </label>

                        <input
                            name="name"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Email
                        </label>

                        <input
                            name="email"
                            type="email"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Password
                        </label>

                        <input
                            name="password"
                            type="password"
                            minlength="8"
                            class="form-control"
                            required
                        >
                    </div>

                    <button
                        class="btn btn-success w-100"
                    >
                        Create Super Admin
                    </button>

                </form>
            </div>
        </div>
    </main>
</body>
</html>
