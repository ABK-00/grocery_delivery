<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userId = currentUserId();

if (empty($_SESSION['notification_csrf'])) {
    $_SESSION['notification_csrf'] =
        bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            $_SESSION['notification_csrf'],
            $csrf
        )
    ) {
        $_SESSION['notification_error'] =
            'Your session expired. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'mark_all_read') {
            $stmt = $conn->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = ?
                  AND is_read = 0
            ");
            $stmt->execute([$userId]);
        } elseif ($action === 'mark_read') {
            $notificationId =
                (int) ($_POST['notification_id'] ?? 0);

            $stmt = $conn->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE id = ?
                  AND user_id = ?
            ");
            $stmt->execute([
                $notificationId,
                $userId
            ]);
        }
    }

    header(
        'Location: '
        . BASE_URL
        . '/notifications.php'
    );
    exit;
}

$stmt = $conn->prepare("
    SELECT
        id,
        type,
        title,
        message,
        link,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 100
");

$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$error =
    $_SESSION['notification_error']
    ?? null;

unset(
    $_SESSION['notification_error']
);

$dashboardUrl = match (
    $_SESSION['role'] ?? ''
) {
    'super_admin' =>
        BASE_URL . '/super_admin/dashboard.php',
    'admin' =>
        BASE_URL . '/admin/dashboard.php',
    'staff' =>
        BASE_URL . '/staff/dashboard.php',
    'delivery_partner' =>
        BASE_URL . '/delivery/dashboard.php',
    default =>
        BASE_URL . '/customer/dashboard.php',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Notifications | GroceryDelivery</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >
    <style>
        body {
            background: #f6f8fb;
        }
        .wrap {
            max-width: 960px;
            margin: auto;
            padding: 28px 18px;
        }
        .notification-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 15px;
            padding: 18px;
            margin-bottom: 12px;
        }
        .notification-card.unread {
            border-left: 4px solid #16a34a;
        }
    </style>
</head>
<body>

<div class="wrap">

    <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="text-success small fw-bold text-uppercase">
                Account
            </div>
            <h2 class="fw-bold mb-1">
                Notifications
            </h2>
        </div>

        <div class="d-flex gap-2">
            <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['notification_csrf']) ?>"
                >
                <input
                    type="hidden"
                    name="action"
                    value="mark_all_read"
                >
                <button
                    class="btn btn-outline-success"
                    type="submit"
                >
                    Mark all read
                </button>
            </form>

            <a
                href="<?= e($dashboardUrl) ?>"
                class="btn btn-outline-dark"
            >
                Dashboard
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$notifications): ?>
        <div class="notification-card text-center py-5">
            <i class="bi bi-bell-slash fs-1 text-muted"></i>
            <h5 class="mt-3">
                No notifications yet
            </h5>
            <p class="text-muted mb-0">
                Updates about orders, deliveries, payments, and your account will appear here.
            </p>
        </div>
    <?php endif; ?>

    <?php foreach ($notifications as $item): ?>
        <div class="notification-card <?= !$item['is_read'] ? 'unread' : '' ?>">
            <div class="d-flex justify-content-between gap-3">
                <div>
                    <div class="fw-bold">
                        <?= e($item['title']) ?>
                    </div>
                    <div class="text-muted mt-1">
                        <?= nl2br(e($item['message'])) ?>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <?= date(
                            'd M Y, h:i A',
                            strtotime($item['created_at'])
                        ) ?>
                    </small>
                </div>

                <div class="d-flex flex-column gap-2 align-items-end">
                    <?php if (!empty($item['link'])): ?>
                        <a
                            href="<?= e($item['link']) ?>"
                            class="btn btn-sm btn-outline-success"
                        >
                            Open
                        </a>
                    <?php endif; ?>

                    <?php if (!$item['is_read']): ?>
                        <form method="POST">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($_SESSION['notification_csrf']) ?>"
                            >
                            <input
                                type="hidden"
                                name="action"
                                value="mark_read"
                            >
                            <input
                                type="hidden"
                                name="notification_id"
                                value="<?= (int) $item['id'] ?>"
                            >
                            <button
                                type="submit"
                                class="btn btn-sm btn-light"
                            >
                                Mark read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

</div>

</body>
</html>
