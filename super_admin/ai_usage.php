<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$days = filter_input(
    INPUT_GET,
    'days',
    FILTER_VALIDATE_INT
);

$days = $days
    ? max(1, min(90, $days))
    : 30;

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS assistant_messages,
        COUNT(DISTINCT ac.id) AS conversations,
        COUNT(DISTINCT ac.user_id) AS active_users,
        COALESCE(
            SUM(
                CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            am.metadata,
                            '$.usage.input_tokens'
                        )
                    ) AS UNSIGNED
                )
            ),
            0
        ) AS input_tokens,
        COALESCE(
            SUM(
                CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            am.metadata,
                            '$.usage.output_tokens'
                        )
                    ) AS UNSIGNED
                )
            ),
            0
        ) AS output_tokens,
        COALESCE(
            SUM(
                CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            am.metadata,
                            '$.usage.total_tokens'
                        )
                    ) AS UNSIGNED
                )
            ),
            0
        ) AS total_tokens
    FROM ai_messages am
    INNER JOIN ai_conversations ac
        ON ac.id = am.conversation_id
    WHERE am.role = 'assistant'
      AND am.created_at >= DATE_SUB(
            NOW(),
            INTERVAL ? DAY
      )
");
$summaryStmt->execute([$days]);
$summary = $summaryStmt->fetch();

$roleStmt = $conn->prepare("
    SELECT
        u.role,
        COUNT(*) AS assistant_messages,
        COUNT(DISTINCT ac.id) AS conversations,
        COUNT(DISTINCT ac.user_id) AS users,
        COALESCE(
            SUM(
                CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            am.metadata,
                            '$.usage.total_tokens'
                        )
                    ) AS UNSIGNED
                )
            ),
            0
        ) AS total_tokens
    FROM ai_messages am
    INNER JOIN ai_conversations ac
        ON ac.id = am.conversation_id
    INNER JOIN users u
        ON u.id = ac.user_id
    WHERE am.role = 'assistant'
      AND am.created_at >= DATE_SUB(
            NOW(),
            INTERVAL ? DAY
      )
    GROUP BY u.role
    ORDER BY total_tokens DESC
");
$roleStmt->execute([$days]);
$byRole = $roleStmt->fetchAll();

$modelStmt = $conn->prepare("
    SELECT
        COALESCE(
            NULLIF(
                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        am.metadata,
                        '$.model'
                    )
                ),
                ''
            ),
            'unknown'
        ) AS model,
        COUNT(*) AS responses,
        COALESCE(
            SUM(
                CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            am.metadata,
                            '$.usage.total_tokens'
                        )
                    ) AS UNSIGNED
                )
            ),
            0
        ) AS total_tokens
    FROM ai_messages am
    WHERE am.role = 'assistant'
      AND am.created_at >= DATE_SUB(
            NOW(),
            INTERVAL ? DAY
      )
    GROUP BY model
    ORDER BY total_tokens DESC
");
$modelStmt->execute([$days]);
$byModel = $modelStmt->fetchAll();

$recentStmt = $conn->prepare("
    SELECT
        ac.id,
        ac.title,
        ac.scope,
        ac.updated_at,
        u.name,
        u.role,
        c.company_name,
        (
            SELECT COUNT(*)
            FROM ai_messages m
            WHERE m.conversation_id = ac.id
        ) AS message_count
    FROM ai_conversations ac
    INNER JOIN users u
        ON u.id = ac.user_id
    LEFT JOIN companies c
        ON c.id = ac.company_id
    WHERE ac.updated_at >= DATE_SUB(
        NOW(),
        INTERVAL ? DAY
    )
    ORDER BY ac.updated_at DESC
    LIMIT 50
");
$recentStmt->execute([$days]);
$recent = $recentStmt->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >
    <title>AI Usage | GroceryDelivery</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >
    <link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>

<?php include __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
<?php include __DIR__ . '/../includes/loader.php'; ?>

<main class="main-content">

    <div class="topbar">
        <div class="topbar-left">
            <button
                class="sidebar-toggle"
                id="sidebarToggle"
                type="button"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="topbar-title">
                    AI Usage
                </h1>
                <p class="topbar-subtitle">
                    Monitor assistant adoption and token usage.
                </p>
            </div>
        </div>

        <form method="GET">
            <select
                name="days"
                class="form-select"
                onchange="this.form.submit()"
            >
                <?php foreach ([
                    7 => 'Last 7 days',
                    30 => 'Last 30 days',
                    60 => 'Last 60 days',
                    90 => 'Last 90 days'
                ] as $value => $label): ?>
                    <option
                        value="<?= (int) $value ?>"
                        <?= $days === $value ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            'Responses' => (int) ($summary['assistant_messages'] ?? 0),
            'Conversations' => (int) ($summary['conversations'] ?? 0),
            'Active Users' => (int) ($summary['active_users'] ?? 0),
            'Total Tokens' => (int) ($summary['total_tokens'] ?? 0)
        ] as $label => $value): ?>
            <div class="col-md-3">
                <div class="content-card p-4 h-100">
                    <small class="text-muted">
                        <?= e($label) ?>
                    </small>
                    <div class="fs-3 fw-bold mt-1">
                        <?= number_format($value) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">

        <div class="col-lg-6">
            <div class="content-card">
                <div class="p-4 border-bottom">
                    <h5 class="fw-bold mb-0">
                        Usage by Role
                    </h5>
                </div>

                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th class="px-4">Role</th>
                                <th>Users</th>
                                <th>Conversations</th>
                                <th>Responses</th>
                                <th>Tokens</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$byRole): ?>
                            <tr>
                                <td
                                    colspan="5"
                                    class="text-center text-muted py-5"
                                >
                                    No AI usage yet.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($byRole as $row): ?>
                            <tr>
                                <td class="px-4">
                                    <?= e(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $row['role']
                                            )
                                        )
                                    ) ?>
                                </td>
                                <td>
                                    <?= number_format((int) $row['users']) ?>
                                </td>
                                <td>
                                    <?= number_format((int) $row['conversations']) ?>
                                </td>
                                <td>
                                    <?= number_format((int) $row['assistant_messages']) ?>
                                </td>
                                <td>
                                    <?= number_format((int) $row['total_tokens']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="content-card">
                <div class="p-4 border-bottom">
                    <h5 class="fw-bold mb-0">
                        Usage by Model
                    </h5>
                </div>

                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th class="px-4">Model</th>
                                <th>Responses</th>
                                <th>Tokens</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$byModel): ?>
                            <tr>
                                <td
                                    colspan="3"
                                    class="text-center text-muted py-5"
                                >
                                    No model usage yet.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($byModel as $row): ?>
                            <tr>
                                <td class="px-4">
                                    <code><?= e($row['model']) ?></code>
                                </td>
                                <td>
                                    <?= number_format((int) $row['responses']) ?>
                                </td>
                                <td>
                                    <?= number_format((int) $row['total_tokens']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div class="content-card">
        <div class="p-4 border-bottom">
            <h5 class="fw-bold mb-0">
                Recent AI Conversations
            </h5>
        </div>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th class="px-4">User</th>
                        <th>Role</th>
                        <th>Scope</th>
                        <th>Company</th>
                        <th>Messages</th>
                        <th>Last Active</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recent): ?>
                    <tr>
                        <td
                            colspan="6"
                            class="text-center text-muted py-5"
                        >
                            No conversations in this period.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td class="px-4">
                            <div class="fw-semibold">
                                <?= e($row['name']) ?>
                            </div>
                            <small class="text-muted">
                                <?= e($row['title'] ?: 'Conversation') ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $row['role']
                                    )
                                )
                            ) ?>
                        </td>
                        <td>
                            <?= e(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $row['scope']
                                    )
                                )
                            ) ?>
                        </td>
                        <td>
                            <?= e($row['company_name'] ?: 'Platform') ?>
                        </td>
                        <td>
                            <?= number_format((int) $row['message_count']) ?>
                        </td>
                        <td>
                            <?= e(
                                date(
                                    'd M Y, h:i A',
                                    strtotime($row['updated_at'])
                                )
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

</body>
</html>
