<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$path =
    __DIR__
    . '/../config/migrate_communications.sql';

if (!is_file($path)) {
    fwrite(
        STDERR,
        "Migration file not found.\n"
    );
    exit(1);
}

$sql =
    (string) file_get_contents($path);

$sql = preg_replace(
    '/^\s*--.*$/m',
    '',
    $sql
);

$statements = preg_split(
    '/;\s*(?:\r?\n|$)/',
    $sql
) ?: [];

echo "Somame communications migration\n";
echo str_repeat('=', 36) . "\n";

try {
    foreach ($statements as $statement) {
        $statement = trim($statement);

        if ($statement === '') {
            continue;
        }

        if (
            preg_match(
                '/^USE\s+/i',
                $statement
            )
        ) {
            continue;
        }

        $conn->exec($statement);
    }

    echo "[PASS] Communications tables are ready.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "[FAIL] "
        . $e->getMessage()
        . "\n"
    );

    exit(1);
}
