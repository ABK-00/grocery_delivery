<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['reset_password_csrf'])) {
    $_SESSION['reset_password_csrf'] =
        bin2hex(random_bytes(32));
}

$token = trim(
    (string) ($_GET['token'] ?? $_POST['token'] ?? '')
);

$error = '';
$success = '';

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'This password reset link is invalid.';
}

$tokenRow = null;

if ($error === '') {
    $stmt = $conn->prepare("
        SELECT
            prt.id,
            prt.user_id,
            prt.expires_at,
            prt.used_at,
            u.email,
            u.status
        FROM password_reset_tokens prt
        INNER JOIN users u
            ON u.id = prt.user_id
        WHERE prt.token_hash = ?
        LIMIT 1
    ");

    $stmt->execute([
        hash('sha256', $token)
    ]);

    $tokenRow = $stmt->fetch();

    if (
        !$tokenRow
        || $tokenRow['used_at'] !== null
        || strtotime($tokenRow['expires_at']) < time()
        || $tokenRow['status'] !== 'active'
    ) {
        $error =
            'This password reset link has expired or has already been used.';
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $error === ''
) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmation =
        (string) ($_POST['password_confirmation'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            $_SESSION['reset_password_csrf'],
            $csrf
        )
    ) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif (strlen($password) < 8) {
        $error =
            'Use a password with at least 8 characters.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $conn->beginTransaction();

            $lock = $conn->prepare("
                SELECT id, user_id, expires_at, used_at
                FROM password_reset_tokens
                WHERE id = ?
                FOR UPDATE
            ");
            $lock->execute([
                $tokenRow['id']
            ]);
            $locked = $lock->fetch();

            if (
                !$locked
                || $locked['used_at'] !== null
                || strtotime($locked['expires_at']) < time()
            ) {
                throw new RuntimeException(
                    'This password reset link is no longer valid.'
                );
            }

            $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
                  AND status = 'active'
            ")->execute([
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                $locked['user_id']
            ]);

            $conn->prepare("
                UPDATE password_reset_tokens
                SET used_at = NOW()
                WHERE user_id = ?
                  AND used_at IS NULL
            ")->execute([
                $locked['user_id']
            ]);

            writeAuditLog(
                $conn,
                (int) $locked['user_id'],
                null,
                'auth.password_reset_completed',
                'user',
                (string) $locked['user_id']
            );

            $conn->commit();

            $success =
                'Password updated successfully. You can now sign in.';
            $tokenRow = null;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Choose New Password | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body{min-height:100vh;display:grid;place-items:center;background:#f6f8fb;padding:24px}
        .auth-card{width:min(100%,480px);background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08)}
    </style>
</head>
<body>
<div class="auth-card">
    <h2 class="fw-bold">Choose a new password</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger mt-3"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success mt-3"><?= e($success) ?></div>
        <a href="<?= BASE_URL ?>/access.php" class="btn btn-success w-100">
            Return to sign in
        </a>
    <?php elseif ($tokenRow): ?>
        <p class="text-muted">
            Resetting password for <?= e($tokenRow['email']) ?>.
        </p>

        <form method="POST">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['reset_password_csrf']) ?>">

            <label class="form-label fw-semibold">New password</label>
            <input type="password" name="password" class="form-control mb-3" minlength="8" autocomplete="new-password" required>

            <label class="form-label fw-semibold">Confirm password</label>
            <input type="password" name="password_confirmation" class="form-control mb-3" minlength="8" autocomplete="new-password" required>

            <button class="btn btn-success w-100" type="submit">
                Update password
            </button>
        </form>
    <?php else: ?>
        <a href="<?= BASE_URL ?>/forgot_password.php" class="btn btn-outline-dark w-100">
            Request another reset link
        </a>
    <?php endif; ?>
</div>
</body>
</html>
