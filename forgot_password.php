<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['forgot_password_csrf'])) {
    $_SESSION['forgot_password_csrf'] =
        bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$developmentResetUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $email = strtolower(
        trim((string) ($_POST['email'] ?? ''))
    );

    if (
        $csrf === ''
        || !hash_equals(
            $_SESSION['forgot_password_csrf'],
            $csrf
        )
    ) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = $conn->prepare("
            SELECT id, name, email
            FROM users
            WHERE email = ?
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date(
                'Y-m-d H:i:s',
                time() + 3600
            );

            $conn->beginTransaction();

            try {
                $conn->prepare("
                    UPDATE password_reset_tokens
                    SET used_at = NOW()
                    WHERE user_id = ?
                      AND used_at IS NULL
                ")->execute([
                    $user['id']
                ]);

                $conn->prepare("
                    INSERT INTO password_reset_tokens (
                        user_id,
                        token_hash,
                        expires_at
                    )
                    VALUES (?, ?, ?)
                ")->execute([
                    $user['id'],
                    $tokenHash,
                    $expiresAt
                ]);

                writeAuditLog(
                    $conn,
                    (int) $user['id'],
                    null,
                    'auth.password_reset_requested',
                    'user',
                    (string) $user['id']
                );

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                throw $e;
            }

            $https =
                !empty($_SERVER['HTTPS'])
                && strtolower((string) $_SERVER['HTTPS']) !== 'off';

            $scheme = $https ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

            $resetUrl =
                $scheme
                . '://'
                . $host
                . BASE_URL
                . '/reset_password.php?token='
                . rawurlencode($rawToken);

            $isLocal =
                in_array(
                    strtolower(
                        preg_replace(
                            '/:\d+$/',
                            '',
                            $host
                        )
                    ),
                    ['localhost', '127.0.0.1'],
                    true
                );

            if ($isLocal) {
                $developmentResetUrl = $resetUrl;
            } else {
                $subject =
                    'Reset your GroceryDelivery password';

                $body =
                    "Hello "
                    . $user['name']
                    . ",\n\nUse this link to reset your password:\n"
                    . $resetUrl
                    . "\n\nThis link expires in 1 hour.\n";

                @mail(
                    $user['email'],
                    $subject,
                    $body,
                    "Content-Type: text/plain; charset=UTF-8\r\n"
                );
            }
        }

        $success =
            'If an active account matches that email, a password reset link has been prepared.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forgot Password | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body{min-height:100vh;display:grid;place-items:center;background:#f6f8fb;padding:24px}
        .auth-card{width:min(100%,480px);background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08)}
    </style>
</head>
<body>
<div class="auth-card">
    <a href="<?= BASE_URL ?>/access.php" class="text-decoration-none text-success small">
        <i class="bi bi-arrow-left"></i> Back to sign in
    </a>
    <h2 class="fw-bold mt-3">Reset password</h2>
    <p class="text-muted">Enter the email attached to your account.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($developmentResetUrl): ?>
        <div class="alert alert-warning">
            <strong>Local development link</strong>
            <div class="small mt-1">
                Email delivery is not required on localhost.
            </div>
            <a href="<?= e($developmentResetUrl) ?>" class="btn btn-sm btn-dark mt-2">
                Open reset link
            </a>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['forgot_password_csrf']) ?>">
        <label class="form-label fw-semibold">Email address</label>
        <input type="email" name="email" class="form-control mb-3" autocomplete="email" required>
        <button class="btn btn-success w-100" type="submit">
            Create reset link
        </button>
    </form>
</div>
</body>
</html>
