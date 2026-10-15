<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/communication.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$pass = 0;
$fail = 0;

function commTest(
    bool $ok,
    string $label,
    string $detail = ''
): void {
    global $pass, $fail;

    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }

    echo str_pad(
        $ok ? '[PASS]' : '[FAIL]',
        7
    )
    . ' '
    . $label
    . (
        $detail !== ''
            ? ' - ' . $detail
            : ''
    )
    . PHP_EOL;
}

echo "Somame communications audit\n";
echo str_repeat('=', 36) . "\n";

$tables = [
    'communication_threads',
    'communication_messages',
    'communication_calls',
    'communication_call_signals'
];

$tableStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = ?
");

foreach ($tables as $table) {
    $tableStmt->execute([$table]);

    commTest(
        (int) $tableStmt->fetchColumn() === 1,
        'Table ' . $table
    );
}

$columns = [
    'communication_threads' => [
        'user_one_id',
        'user_two_id',
        'company_id',
        'created_by',
        'last_message_at'
    ],
    'communication_messages' => [
        'thread_id',
        'sender_id',
        'message',
        'read_at'
    ],
    'communication_calls' => [
        'thread_id',
        'caller_id',
        'callee_id',
        'status',
        'started_at',
        'answered_at',
        'ended_at'
    ],
    'communication_call_signals' => [
        'call_id',
        'sender_id',
        'recipient_id',
        'signal_type',
        'payload'
    ]
];

$columnStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = ?
      AND column_name = ?
");

foreach ($columns as $table => $names) {
    foreach ($names as $column) {
        $columnStmt->execute([
            $table,
            $column
        ]);

        commTest(
            (int) $columnStmt->fetchColumn() === 1,
            'Column '
                . $table
                . '.'
                . $column
        );
    }
}

$root = dirname(__DIR__);

foreach ([
    'communications.php',
    'api/communications.php',
    'includes/communication.php',
    'includes/communication_alert.php',
    'assets/js/communications.js',
    'assets/css/communications.css'
] as $path) {
    commTest(
        is_file(
            $root
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $path
            )
        ),
        'File ' . $path
    );
}

$sampleUser = $conn->query("
    SELECT
        id,
        company_id,
        role
    FROM users
    WHERE status = 'active'
    ORDER BY id ASC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($sampleUser) {
    commTest(
        !communicationCanContact(
            $conn,
            (int) $sampleUser['id'],
            (string) $sampleUser['role'],
            $sampleUser['company_id'] !== null
                ? (int) $sampleUser['company_id']
                : null,
            (int) $sampleUser['id']
        ),
        'Self-contact is blocked'
    );
} else {
    commTest(
        true,
        'Self-contact is blocked',
        'no active users available for runtime sample'
    );
}

echo "\n";
echo "Pass={$pass}, Fail={$fail}\n";

exit($fail > 0 ? 1 : 0);
