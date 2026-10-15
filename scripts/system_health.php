<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../config/ai.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$failures = 0;
$warnings = 0;

function healthLine(
    string $label,
    bool $ok,
    string $detail = '',
    bool $warningOnly = false
): void {
    global $failures, $warnings;

    $status = $ok
        ? '[OK]'
        : ($warningOnly ? '[WARN]' : '[FAIL]');

    echo str_pad($status, 7)
        . ' '
        . $label
        . ($detail !== '' ? ' - ' . $detail : '')
        . PHP_EOL;

    if (!$ok) {
        if ($warningOnly) {
            $warnings++;
        } else {
            $failures++;
        }
    }
}

echo "GroceryDelivery system health check\n";
echo str_repeat('=', 38) . "\n";

healthLine(
    'PHP version',
    version_compare(PHP_VERSION, '8.3.0', '>='),
    PHP_VERSION
);

foreach ([
    'pdo_mysql',
    'curl',
    'json',
    'mbstring'
] as $extension) {
    healthLine(
        'PHP extension ' . $extension,
        extension_loaded($extension)
    );
}

try {
    $conn->query('SELECT 1');
    healthLine('Database connection', true);
} catch (Throwable $e) {
    healthLine(
        'Database connection',
        false,
        $e->getMessage()
    );
}

$requiredTables = [
    'companies',
    'users',
    'company_storefronts',
    'categories',
    'products',
    'product_images',
    'cart',
    'orders',
    'order_items',
    'payments',
    'refunds',
    'subscription_payments',
    'deliveries',
    'delivery_partners',
    'delivery_locations',
    'delivery_return_items',
    'realtime_events',
    'customer_preferences',
    'customer_addresses',
    'ai_conversations',
    'ai_messages',
    'notifications',
    'support_tickets',
    'support_messages',
    'platform_settings',
    'audit_logs'
];

$tableStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = ?
");

foreach ($requiredTables as $table) {
    $tableStmt->execute([$table]);
    healthLine(
        'Table ' . $table,
        (int) $tableStmt->fetchColumn() === 1
    );
}

$requiredColumns = [
    'orders' => [
        'company_id',
        'idempotency_key',
        'payment_status',
        'payment_reference',
        'stock_released_at',
        'vendor_sla_expires_at',
        'vendor_escalated_at'
    ],
    'deliveries' => [
        'out_for_delivery_at',
        'failure_reason',
        'failed_at',
        'return_started_at',
        'return_pending_at',
        'returned_at',
        'return_confirmed_by',
        'return_notes'
    ],
    'order_items' => [
        'unit'
    ],
    'payments' => [
        'reference',
        'authorization_url',
        'status'
    ],
    'companies' => [
        'subscription_plan',
        'trial_ends_at',
        'subscription_ends_at'
    ],
    'customer_preferences' => [
        'preferred_categories',
        'preferred_stores',
        'dietary_preferences',
        'disliked_products',
        'preferred_payment_method',
        'allow_personalization',
        'allow_marketing',
        'allow_location_personalization',
        'preferred_language',
        'ai_profile_summary'
    ],
    'customer_addresses' => [
        'address_line1',
        'address_line2',
        'city',
        'region',
        'country',
        'delivery_instructions',
        'is_default',
        'is_active'
    ],
    'ai_conversations' => [
        'user_id',
        'company_id',
        'conversation_uuid',
        'assistant_type',
        'channel',
        'status',
        'started_at',
        'last_message_at'
    ],
    'ai_messages' => [
        'conversation_id',
        'sender_type',
        'sender_id',
        'message',
        'message_type',
        'model',
        'metadata',
        'tool_name',
        'tool_payload'
    ]
];

$columnStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = ?
      AND column_name = ?
");

foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $columnStmt->execute([
            $table,
            $column
        ]);

        healthLine(
            'Column ' . $table . '.' . $column,
            (int) $columnStmt->fetchColumn() === 1
        );
    }
}

healthLine(
    'Paystack configuration',
    defined('PAYSTACK_SECRET_KEY')
        && trim((string) PAYSTACK_SECRET_KEY) !== ''
);

healthLine(
    'OpenAI configuration',
    OPENAI_AI_ENABLED,
    OPENAI_AI_ENABLED
        ? 'model=' . OPENAI_MODEL
        : 'AI remains disabled until OPENAI_API_KEY is configured.',
    true
);

$aiPlatformEnabled =
    (string) platformSetting(
        $conn,
        'ai_enabled',
        platformSetting(
            $conn,
            'ai.enabled',
            '0'
        )
    ) === '1';

healthLine(
    'AI platform switch',
    !$aiPlatformEnabled || OPENAI_AI_ENABLED,
    $aiPlatformEnabled
        ? (
            OPENAI_AI_ENABLED
                ? 'enabled'
                : 'enabled but API key is missing'
        )
        : 'disabled',
    true
);

try {
    $pending = (int) $conn->query("
        SELECT COUNT(*)
        FROM orders
        WHERE payment_status = 'pending'
          AND status = 'pending'
          AND created_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ")->fetchColumn();

    healthLine(
        'Expired pending orders',
        $pending === 0,
        $pending === 0
            ? 'none'
            : $pending . ' waiting for cleanup',
        true
    );
} catch (Throwable $e) {
    healthLine(
        'Expired pending orders',
        false,
        $e->getMessage(),
        true
    );
}



try {
    $slaMinutes = max(
        1,
        (int) (
            getenv('VENDOR_ORDER_SLA_MINUTES')
            ?: 10
        )
    );

    $cutoff = date(
        'Y-m-d H:i:s',
        time() - ($slaMinutes * 60)
    );

    $slaStmt = $conn->prepare("
        SELECT COUNT(DISTINCT o.id)
        FROM orders o
        LEFT JOIN payments p
            ON p.order_id = o.id
           AND p.status = 'paid'
        WHERE o.status = 'pending'
          AND o.payment_status = 'paid'
          AND o.vendor_escalated_at IS NULL
          AND COALESCE(
                p.paid_at,
                o.updated_at,
                o.created_at
              ) <= ?
    ");

    $slaStmt->execute([$cutoff]);
    $overdue = (int) $slaStmt->fetchColumn();

    healthLine(
        'Vendor SLA escalations pending',
        $overdue === 0,
        $overdue === 0
            ? 'none'
            : $overdue . ' order(s) need escalation',
        true
    );
} catch (Throwable $e) {
    healthLine(
        'Vendor SLA escalations pending',
        false,
        $e->getMessage(),
        true
    );
}

echo "\n";
echo "Failures={$failures}, Warnings={$warnings}\n";

exit($failures > 0 ? 1 : 0);
