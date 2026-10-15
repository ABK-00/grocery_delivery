<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userId = currentUserId();
$role = (string) ($_SESSION['role'] ?? '');
$companyId = currentCompanyId();

$ticketId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$ticketId) {
    header(
        'Location: '
        . BASE_URL
        . '/support.php'
    );
    exit;
}

if (empty($_SESSION['support_csrf'])) {
    $_SESSION['support_csrf'] =
        bin2hex(random_bytes(32));
}

function fetchSupportTicket(
    PDO $conn,
    int $ticketId,
    int $userId,
    string $role,
    ?int $companyId
): array|false {
    if ($role === 'super_admin') {
        $stmt = $conn->prepare("
            SELECT
                st.*,
                u.name AS requester_name,
                u.email AS requester_email,
                c.company_name
            FROM support_tickets st
            INNER JOIN users u
                ON u.id = st.user_id
            LEFT JOIN companies c
                ON c.id = st.company_id
            WHERE st.id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $ticketId
        ]);

        return $stmt->fetch();
    }

    if (
        in_array($role, ['admin', 'staff'], true)
        && $companyId
    ) {
        $stmt = $conn->prepare("
            SELECT
                st.*,
                u.name AS requester_name,
                u.email AS requester_email,
                c.company_name
            FROM support_tickets st
            INNER JOIN users u
                ON u.id = st.user_id
            LEFT JOIN companies c
                ON c.id = st.company_id
            WHERE st.id = ?
              AND st.company_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $ticketId,
            $companyId
        ]);

        return $stmt->fetch();
    }

    $stmt = $conn->prepare("
        SELECT
            st.*,
            u.name AS requester_name,
            u.email AS requester_email,
            c.company_name
        FROM support_tickets st
        INNER JOIN users u
            ON u.id = st.user_id
        LEFT JOIN companies c
            ON c.id = st.company_id
        WHERE st.id = ?
          AND st.user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $ticketId,
        $userId
    ]);

    return $stmt->fetch();
}

$ticket = fetchSupportTicket(
    $conn,
    $ticketId,
    $userId,
    $role,
    $companyId
);

if (!$ticket) {
    header(
        'Location: '
        . BASE_URL
        . '/support.php'
    );
    exit;
}

$error = '';

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
        $action =
            (string) ($_POST['action'] ?? '');

        if ($action === 'reply') {
            $message = trim(
                (string) ($_POST['message'] ?? '')
            );

            if ($message === '') {
                $error = 'Enter a reply.';
            } elseif ($ticket['status'] === 'closed') {
                $error = 'This ticket is closed.';
            } else {
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

                if (
                    in_array(
                        $role,
                        ['super_admin', 'admin', 'staff'],
                        true
                    )
                    && (int) $ticket['user_id'] !== (int) $userId
                ) {
                    createNotification(
                        $conn,
                        (int) $ticket['user_id'],
                        'support',
                        'Support replied',
                        'Support replied to ticket '
                            . $ticket['ticket_number']
                            . '.',
                        BASE_URL
                            . '/support_ticket.php?id='
                            . $ticketId
                    );

                    $stmt = $conn->prepare("
                        UPDATE support_tickets
                        SET
                            status = CASE
                                WHEN status = 'open'
                                THEN 'in_progress'
                                ELSE status
                            END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $ticketId
                    ]);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE support_tickets
                        SET updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                          AND user_id = ?
                    ");
                    $stmt->execute([
                        $ticketId,
                        $userId
                    ]);
                }

                writeAuditLog(
                    $conn,
                    $userId,
                    $ticket['company_id']
                        ? (int) $ticket['company_id']
                        : null,
                    'support.reply_added',
                    'support_ticket',
                    (string) $ticketId,
                    [
                        'ticket_number' =>
                            $ticket['ticket_number'],
                        'actor_role' => $role
                    ]
                );

                header(
                    'Location: '
                    . BASE_URL
                    . '/support_ticket.php?id='
                    . $ticketId
                );
                exit;
            }
        }

        if (
            $action === 'status'
            && in_array(
                $role,
                ['super_admin', 'admin'],
                true
            )
        ) {
            $status =
                (string) ($_POST['status'] ?? '');

            if (!in_array(
                $status,
                [
                    'open',
                    'in_progress',
                    'resolved',
                    'closed'
                ],
                true
            )) {
                $error = 'Invalid ticket status.';
            } else {
                $stmt = $conn->prepare("
                    UPDATE support_tickets
                    SET status = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $status,
                    $ticketId
                ]);

                createNotification(
                    $conn,
                    (int) $ticket['user_id'],
                    'support',
                    'Support ticket updated',
                    'Ticket '
                        . $ticket['ticket_number']
                        . ' is now '
                        . ucwords(str_replace('_', ' ', $status))
                        . '.',
                    BASE_URL
                        . '/support_ticket.php?id='
                        . $ticketId
                );

                writeAuditLog(
                    $conn,
                    $userId,
                    $ticket['company_id']
                        ? (int) $ticket['company_id']
                        : null,
                    'support.status_changed',
                    'support_ticket',
                    (string) $ticketId,
                    [
                        'from' => $ticket['status'],
                        'to' => $status
                    ]
                );

                header(
                    'Location: '
                    . BASE_URL
                    . '/support_ticket.php?id='
                    . $ticketId
                );
                exit;
            }
        }
    }
}

$ticket = fetchSupportTicket(
    $conn,
    $ticketId,
    $userId,
    $role
);

$stmt = $conn->prepare("
    SELECT
        sm.id,
        sm.message,
        sm.created_at,
        sm.user_id,
        u.name,
        u.role
    FROM support_messages sm
    INNER JOIN users u
        ON u.id = sm.user_id
    WHERE sm.ticket_id = ?
    ORDER BY sm.id ASC
");

$stmt->execute([
    $ticketId
]);

$messages = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title><?= e($ticket['ticket_number']) ?> | Support</title>
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
            max-width: 900px;
            margin: auto;
            padding: 28px 18px;
        }
        .panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 22px;
        }
        .support-message {
            padding: 15px;
            border-radius: 13px;
            background: #f8fafc;
            margin-bottom: 12px;
        }
        .support-message.mine {
            background: #eef8f1;
        }
    </style>
</head>
<body>

<div class="wrap">

    <div class="mb-3">
        <a
            href="<?= BASE_URL ?>/support.php"
            class="btn btn-sm btn-outline-dark"
        >
            <i class="bi bi-arrow-left"></i>
            Support Center
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="panel mb-4">

        <div class="d-flex justify-content-between gap-3 align-items-start">
            <div>
                <small class="text-success fw-bold">
                    <?= e($ticket['ticket_number']) ?>
                </small>
                <h3 class="fw-bold mt-1">
                    <?= e($ticket['subject']) ?>
                </h3>
                <div class="text-muted">
                    <?= e($ticket['category']) ?>
                    ·
                    <?= e($ticket['requester_name']) ?>
                    <?php if (!empty($ticket['company_name'])): ?>
                        ·
                        <?= e($ticket['company_name']) ?>
                    <?php endif; ?>
                </div>
            </div>

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
        </div>

        <?php if ($role === 'super_admin'): ?>
            <form
                method="POST"
                class="d-flex gap-2 mt-4"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['support_csrf']) ?>"
                >
                <input
                    type="hidden"
                    name="action"
                    value="status"
                >

                <select
                    name="status"
                    class="form-select"
                >
                    <?php foreach ([
                        'open',
                        'in_progress',
                        'resolved',
                        'closed'
                    ] as $status): ?>
                        <option
                            value="<?= e($status) ?>"
                            <?= $ticket['status'] === $status ? 'selected' : '' ?>
                        >
                            <?= e(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $status
                                    )
                                )
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="btn btn-dark">
                    Update
                </button>
            </form>
        <?php endif; ?>

    </div>

    <div class="panel mb-4">

        <?php foreach ($messages as $item): ?>
            <div class="support-message <?= (int) $item['user_id'] === (int) $userId ? 'mine' : '' ?>">
                <div class="d-flex justify-content-between gap-3 mb-1">
                    <strong>
                        <?= e($item['name']) ?>
                        <?php if ($item['role'] === 'super_admin'): ?>
                            <span class="badge bg-dark ms-1">
                                Support
                            </span>
                        <?php endif; ?>
                    </strong>

                    <small class="text-muted">
                        <?= date(
                            'd M Y, h:i A',
                            strtotime($item['created_at'])
                        ) ?>
                    </small>
                </div>

                <div>
                    <?= nl2br(e($item['message'])) ?>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <?php if ($ticket['status'] !== 'closed'): ?>
        <div class="panel">
            <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['support_csrf']) ?>"
                >
                <input
                    type="hidden"
                    name="action"
                    value="reply"
                >

                <label class="form-label fw-semibold">
                    Reply
                </label>

                <textarea
                    name="message"
                    class="form-control"
                    rows="5"
                    required
                ></textarea>

                <button
                    class="btn btn-success mt-3"
                    type="submit"
                >
                    Send Reply
                </button>
            </form>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
