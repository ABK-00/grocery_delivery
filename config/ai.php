<?php

require_once __DIR__ . '/app.php';

$apiKey = trim((string) getenv('OPENAI_API_KEY'));
$model = trim((string) getenv('OPENAI_MODEL'));

$localConfigFile = __DIR__ . '/ai.local.php';

if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;

    if (is_array($localConfig)) {
        if ($apiKey === '') {
            $apiKey = trim((string) ($localConfig['api_key'] ?? ''));
        }

        if ($model === '') {
            $model = trim((string) ($localConfig['model'] ?? ''));
        }
    }
}

if ($model === '') {
    $model = 'gpt-6-luna';
}

define('OPENAI_API_KEY', $apiKey);
define('OPENAI_MODEL', $model);
define('OPENAI_RESPONSES_URL', 'https://api.openai.com/v1/responses');
define('OPENAI_AI_ENABLED', OPENAI_API_KEY !== '');
