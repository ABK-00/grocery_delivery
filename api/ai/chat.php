<?php

declare(strict_types=1);

header('Content-Type: application/json');

session_start();

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../app/Services/AI/AIService.php';

try {

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.'
        ]);

        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.'
        ]);

        exit;
    }

    $payload = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($payload)) {
        throw new RuntimeException(
            'Invalid request body.'
        );
    }

    $message = trim(
        (string)($payload['message'] ?? '')
    );

    if ($message === '') {
        throw new RuntimeException(
            'Please enter a message.'
        );
    }

    $userId = (int) $_SESSION['user_id'];

    $identityStmt = $conn->prepare("
        SELECT
            id,
            company_id,
            role,
            status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $identityStmt->execute([$userId]);
    $identity = $identityStmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$identity
        || $identity['status'] !== 'active'
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Account access is unavailable.'
        ]);

        exit;
    }

    $role = strtolower(
        (string) $identity['role']
    );

    $companyId = $identity['company_id'] !== null
        ? (int) $identity['company_id']
        : null;

    $conversationId =
        !empty($payload['conversation_id'])
            ? (int) $payload['conversation_id']
            : null;

    $now = time();
    $windowStart =
        (int) ($_SESSION['ai_rate_window'] ?? 0);
    $requestCount =
        (int) ($_SESSION['ai_rate_count'] ?? 0);

    if (
        $windowStart === 0
        || ($now - $windowStart) >= 60
    ) {
        $windowStart = $now;
        $requestCount = 0;
    }

    if ($requestCount >= 20) {
        http_response_code(429);

        echo json_encode([
            'success' => false,
            'message' => 'Too many AI requests. Please try again shortly.'
        ]);

        exit;
    }

    $_SESSION['ai_rate_window'] =
        $windowStart;
    $_SESSION['ai_rate_count'] =
        $requestCount + 1;

    $ai = new AIService($conn);

    $result = $ai->chat(
        $userId,
        $role,
        $message,
        $companyId,
        $conversationId
    );

    echo json_encode($result);

} catch (Throwable $e) {

    http_response_code(500);

    error_log(
        'AI chat error: '
        . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'message' =>
            'The AI assistant is temporarily unavailable.'
    ]);
}