<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/AI/AIService.php';

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

$csrf = (string) ($_POST['csrf_token'] ?? '');

if (
    empty($_SESSION['ai_chat_csrf'])
    || $csrf === ''
    || !hash_equals(
        (string) $_SESSION['ai_chat_csrf'],
        $csrf
    )
) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' =>
            'Your AI session expired. Refresh and try again.'
    ]);
    exit;
}

try {
    $message = trim(
        (string) ($_POST['message'] ?? '')
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

    $conversationId = filter_input(
        INPUT_POST,
        'conversation_id',
        FILTER_VALIDATE_INT
    );

    $ai = new AIService($conn);

    $result = $ai->chat(
        $userId,
        $role,
        $message,
        $companyId,
        $conversationId ?: null
    );

    echo json_encode([
        'success' =>
            (bool) ($result['success'] ?? false),
        'conversation_id' =>
            $result['conversation_id'] ?? null,
        'reply' =>
            $result['message'] ?? '',
        'message' =>
            $result['message'] ?? '',
        'model' =>
            $result['model'] ?? null,
        'usage' =>
            $result['usage'] ?? null
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log(
        'AI form chat error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'The AI assistant is temporarily unavailable.'
    ]);
}
