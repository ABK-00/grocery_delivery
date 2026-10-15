<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ai_service.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

echo "Somame AI connection test\n";
echo str_repeat('=', 34) . "\n";
echo "Model: " . OPENAI_MODEL . "\n";
echo "Endpoint: Responses API\n";

if (!OPENAI_AI_ENABLED) {
    echo "[FAIL] OPENAI_API_KEY is not configured.\n";
    exit(1);
}

try {
    $result = sendOpenAiResponsePayload([
        'model' => OPENAI_MODEL,
        'input' => 'Reply with exactly: SOMAME_AI_OK',
        'max_output_tokens' => 20
    ]);

    $text = extractAiResponseText($result);

    if (trim($text) !== 'SOMAME_AI_OK') {
        echo "[FAIL] API responded, but the expected test reply was not returned.\n";
        echo "Response: " . trim($text) . "\n";
        exit(1);
    }

    echo "[PASS] OpenAI API connection\n";
    echo "[PASS] Model access\n";
    echo "[PASS] Responses API\n";
    echo "AI transport is ready.\n";
    exit(0);

} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}
