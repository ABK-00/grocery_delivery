<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AI/AIService.php';

$role = requirePortalContext();

$userId = (int) currentUserId();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

try {
    $payload = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($payload)) {
        throw new InvalidArgumentException(
            'Invalid request body.'
        );
    }

    $message = trim(
        (string) ($payload['message'] ?? '')
    );

    if ($message === '') {
        throw new InvalidArgumentException(
            'Please enter a message.'
        );
    }

    if (mb_strlen($message) > 4000) {
        throw new InvalidArgumentException(
            'Message is too long.'
        );
    }

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
            'message' =>
                'Too many AI requests. Please try again shortly.'
        ]);
        exit;
    }

    $_SESSION['ai_rate_window'] =
        $windowStart;

    $_SESSION['ai_rate_count'] =
        $requestCount + 1;

    $dailyLimit = max(
        1,
        (int) platformSetting(
            $conn,
            'ai_daily_request_limit',
            100
        )
    );

    $usageStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM ai_messages am
        INNER JOIN ai_conversations ac
            ON ac.id = am.conversation_id
        WHERE ac.user_id = ?
          AND am.sender_type = 'user'
          AND am.created_at >= CURDATE()
          AND am.created_at < DATE_ADD(
                CURDATE(),
                INTERVAL 1 DAY
          )
    ");

    $usageStmt->execute([
        $userId
    ]);

    if (
        (int) $usageStmt->fetchColumn()
        >= $dailyLimit
    ) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' =>
                'Your daily AI request limit has been reached.'
        ]);
        exit;
    }

    $conversationId =
        !empty($payload['conversation_id'])
            ? (int) $payload['conversation_id']
            : null;

    $ai = new AIService($conn);

    $result = $ai->chat(
        $userId,
        $role,
        $message,
        $companyId,
        $conversationId
    );

    echo json_encode($result);

} catch (InvalidArgumentException $e) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log(
        'AI chat error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'The AI assistant is temporarily unavailable.'
    ]);
}
