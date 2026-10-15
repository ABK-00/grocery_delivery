<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userId = currentUserId();
$role = (string) ($_SESSION['role'] ?? '');
$companyId = currentCompanyId();

if (empty($_SESSION['support_csrf'])) {
    $_SESSION['support_csrf'] =
        bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            $_SESSION['support_csrf'],
            $csrf
        )
    ) {
        $error =
            'Your session expired. Refresh and try again.';
    } else {
        $category = trim(
            (string) ($_POST['category'] ?? '')
        );

        $subject = trim(
            (string) ($_POST['subject'] ?? '')
        );

        $message = trim(
            (string) ($_POST['message'] ?? '')
        );

        $allowedCategories = [
            'Orders',
            'Payments',
            'Delivery',
            'Account',
            'Company',
            'Subscription',
            'Marketplace',
            'Technical',
            'Other'
        ];

        if (!in_array($category, $allowedCategories, true)) {
            $error = 'Choose a valid support category.';
        } elseif ($subject === '' || mb_strlen($subject) > 180) {
            $error = 'Enter a valid subject.';
        } elseif ($message === '') {
            $error = 'Describe the issue.';
        } else {
            try {
                $conn->beginTransaction();

                do {
                    $ticketNumber =
                        'SUP-'
                        . date('Ymd')
                        . '-'
                        . strtoupper(
                            bin2hex(random_bytes(3))
                        );

                    $check = $conn->prepare("
                        SELECT id
                        FROM support_tickets
                        WHERE ticket_number = ?
                        LIMIT 1
                    ");

                    $check->execute([
                        $ticketNumber
                    ]);
                } while ($check->fetch());

                $stmt = $conn->prepare("
                    INSERT INTO support_tickets (
                        ticket_number,
                        user_id,
                        company_id,
                        category,
                        subject
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $ticketNumber,
                    $userId,
                    $companyId,
                    $category,
                    $subject
                ]);

                $ticketId =
                    (int) $conn->lastInsertId();

                $stmt = $conn->prepare("
                    INSERT INTO support_messages (
                        ticket_id,
                        user_id,
                        message
                    )
                    VALUES (?, ?, ?)
                ");

                $stmt->execute([
                    $ticketId,
                    $userId,
                    $message
                ]);

                $conn->commit();

                header(
                    'Location: '
                    . BASE_URL
                    . '/support_ticket.php?id='
                    . $ticketId
                    . '&created=1'
                );
                exit;

            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }
}

if ($role === 'super_admin') {
    $stmt = $conn->query("
        SELECT
            st.*,
            u.name AS requester_name,
            c.company_name
        FROM support_tickets st
        INNER JOIN users u
            ON u.id = st.user_id
        LEFT JOIN companies c
            ON c.id = st.company_id
        ORDER BY
            FIELD(st.status, 'open', 'in_progress', 'resolved', 'closed'),
            st.updated_at DESC
        LIMIT 100
    ");
} elseif (
    in_array($role, ['admin', 'staff'], true)
    && $companyId
) {
    $stmt = $conn->prepare("
        SELECT
            st.*,
            u.name AS requester_name,
            c.company_name
        FROM support_tickets st
        INNER JOIN users u
            ON u.id = st.user_id
        LEFT JOIN companies c
            ON c.id = st.company_id
        WHERE st.company_id = ?
        ORDER BY
            FIELD(st.status, 'open', 'in_progress', 'resolved', 'closed'),
            st.updated_at DESC
        LIMIT 100
    ");

    $stmt->execute([
        $companyId
    ]);
} else {
    $stmt = $conn->prepare("
        SELECT
            st.*,
            u.name AS requester_name,
            c.company_name
        FROM support_tickets st
        INNER JOIN users u
            ON u.id = st.user_id
        LEFT JOIN companies c
            ON c.id = st.company_id
        WHERE st.user_id = ?
        ORDER BY st.updated_at DESC
        LIMIT 100
    ");

    $stmt->execute([
        $userId
    ]);
}

$tickets = $stmt->fetchAll();

$dashboardUrl = match ($role) {
    'super_admin' => BASE_URL . '/super_admin/dashboard.php',
    'admin' => BASE_URL . '/admin/dashboard.php',
    'staff' => BASE_URL . '/staff/dashboard.php',
    'delivery_partner' => BASE_URL . '/delivery/dashboard.php',
    default => BASE_URL . '/customer/dashboard.php',
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
    <title>Support Center | GroceryDelivery</title>
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
            max-width: 1180px;
            margin: auto;
            padding: 28px 18px;
        }

        .panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 22px;
        }

        .ticket-row {
            display: block;
            color: inherit;
            text-decoration: none;
            padding: 15px;
            border: 1px solid #edf0f4;
            border-radius: 13px;
            margin-bottom: 10px;
        }

        .ticket-row:hover {
            border-color: #86efac;
            background: #f7fcf8;
        }
    </style>
</head>
<body>

<div class="wrap">

    <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="text-success fw-bold small text-uppercase">
                Help & Support
            </div>
            <h2 class="fw-bold mb-1">
                Support Center
            </h2>
            <div class="text-muted">
                Create a ticket when the AI assistant cannot resolve an issue.
            </div>
        </div>

        <div class="d-flex gap-2">
            <a
                href="<?= BASE_URL ?>/assistant.php"
                class="btn btn-success"
            >
                <i class="bi bi-stars"></i>
                Ask AI
            </a>

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

    <div class="row g-4">

        <div class="col-lg-5">

            <div class="panel">
                <h5 class="fw-bold mb-3">
                    Create Support Ticket
                </h5>

                <form method="POST">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['support_csrf']) ?>"
                    >

                    <div class="mb-3">
                        <label class="form-label">
                            Category
                        </label>

                        <select
                            name="category"
                            class="form-select"
                            required
                        >
                            <option value="">
                                Select category
                            </option>
                            <?php foreach ([
                                'Orders',
                                'Payments',
                                'Delivery',
                                'Account',
                                'Company',
                                'Subscription',
                                'Marketplace',
                                'Technical',
                                'Other'
                            ] as $category): ?>
                                <option value="<?= e($category) ?>">
                                    <?= e($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Subject
                        </label>

                        <input
                            type="text"
                            name="subject"
                            class="form-control"
                            maxlength="180"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Message
                        </label>

                        <textarea
                            name="message"
                            class="form-control"
                            rows="7"
                            required
                        ></textarea>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-dark w-100"
                    >
                        Submit Ticket
                    </button>
                </form>
            </div>

        </div>

        <div class="col-lg-7">

            <div class="panel">
                <h5 class="fw-bold mb-3">
                    <?= $role === 'super_admin'
                        ? 'Platform Tickets'
                        : 'My Tickets' ?>
                </h5>

                <?php if (!$tickets): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1"></i>
                        <div class="mt-2">
                            No support tickets yet.
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($tickets as $ticket): ?>
                    <a
                        class="ticket-row"
                        href="<?= BASE_URL ?>/support_ticket.php?id=<?= (int) $ticket['id'] ?>"
                    >
                        <div class="d-flex justify-content-between gap-3">
                            <div>
                                <div class="fw-bold">
                                    <?= e($ticket['subject']) ?>
                                </div>
                                <small class="text-muted">
                                    <?= e($ticket['ticket_number']) ?>
                                    ·
                                    <?= e($ticket['category']) ?>
                                    <?php if ($role === 'super_admin'): ?>
                                        ·
                                        <?= e($ticket['requester_name']) ?>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="text-end">
                                <span class="badge bg-secondary">
                                    <?= e(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $ticket['status']
                                            )
                                        )
                                    ) ?>
                                </span>
                                <small class="text-muted d-block mt-1">
                                    <?= date(
                                        'd M Y',
                                        strtotime($ticket['updated_at'])
                                    ) ?>
                                </small>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>

            </div>

        </div>

    </div>

</div>

</body>
</html>
