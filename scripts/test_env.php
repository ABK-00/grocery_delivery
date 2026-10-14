<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

echo "Environment test\n";
echo "================\n";

echo 'OPENAI_MODEL: '
    . (getenv('OPENAI_MODEL') ?: 'NOT SET')
    . PHP_EOL;

echo 'OPENAI_BASE_URL: '
    . (getenv('OPENAI_BASE_URL') ?: 'NOT SET')
    . PHP_EOL;

echo 'OPENAI_API_KEY: '
    . (
        getenv('OPENAI_API_KEY')
            ? 'CONFIGURED'
            : 'NOT CONFIGURED'
    )
    . PHP_EOL;