<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../config/ai.php';

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
        'stock_released_at'
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

echo "\n";
echo "Failures={$failures}, Warnings={$warnings}\n";

exit($failures > 0 ? 1 : 0);
