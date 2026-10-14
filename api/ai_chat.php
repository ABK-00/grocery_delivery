<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_service.php';

requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$userId = currentUserId();
$role = (string) ($_SESSION['role'] ?? '');
$companyId = currentCompanyId();

$message = trim((string) ($_POST['message'] ?? ''));
$conversationId = filter_input(
    INPUT_POST,
    'conversation_id',
    FILTER_VALIDATE_INT
);

$csrf = (string) ($_POST['csrf_token'] ?? '');

if (
    empty($_SESSION['ai_chat_csrf'])
    || $csrf === ''
    || !hash_equals($_SESSION['ai_chat_csrf'], $csrf)
) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => 'Your AI session expired. Refresh the page and try again.'
    ]);
    exit;
}

if ($message === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a message.'
    ]);
    exit;
}

if (mb_strlen($message) > 4000) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Message is too long.'
    ]);
    exit;
}

$requestedScope =
    trim((string) ($_POST['assistant_scope'] ?? ''));

$scope = aiScopeForRole(
    $role,
    $requestedScope === 'customer_personalization'
        ? 'customer_personalization'
        : null
);

$aiEnabled =
    (string) platformSetting(
        $conn,
        'ai_enabled',
        '1'
    ) !== '0';

if (!$aiEnabled) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' =>
            'The AI assistant is temporarily disabled by the platform administrator.'
    ]);
    exit;
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
      AND am.role = 'user'
      AND am.created_at >= CURDATE()
      AND am.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
");

$usageStmt->execute([$userId]);

if ((int) $usageStmt->fetchColumn() >= $dailyLimit) {
    http_response_code(429);

    echo json_encode([
        'success' => false,
        'message' =>
            'Your daily AI request limit has been reached. Try again tomorrow.'
    ]);
    exit;
}

try {
    $conn->beginTransaction();

    if ($conversationId) {
        $stmt = $conn->prepare("
            SELECT id, scope
            FROM ai_conversations
            WHERE id = ?
              AND user_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            $conversationId,
            $userId
        ]);

        $conversation = $stmt->fetch();

        if (!$conversation) {
            throw new RuntimeException(
                'Conversation not found.'
            );
        }

        if ($conversation['scope'] !== $scope) {
            throw new RuntimeException(
                'This conversation belongs to a different assistant context.'
            );
        }
    } else {
        $title = mb_substr(
            preg_replace('/\s+/', ' ', $message),
            0,
            80
        );

        $stmt = $conn->prepare("
            INSERT INTO ai_conversations (
                user_id,
                company_id,
                scope,
                title
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $companyId,
            $scope,
            $title
        ]);

        $conversationId =
            (int) $conn->lastInsertId();
    }

    $insert = $conn->prepare("
        INSERT INTO ai_messages (
            conversation_id,
            role,
            content
        )
        VALUES (?, 'user', ?)
    ");

    $insert->execute([
        $conversationId,
        $message
    ]);

    $conn->commit();

    $historyStmt = $conn->prepare("
        SELECT role, content
        FROM ai_messages
        WHERE conversation_id = ?
        ORDER BY id DESC
        LIMIT 16
    ");

    $historyStmt->execute([
        $conversationId
    ]);

    $history = array_reverse(
        $historyStmt->fetchAll()
    );

    $context = buildAiBusinessContext(
        $conn,
        $userId,
        $role,
        $companyId
    );

    $instructions =
        aiInstructionsForRole($role)
        . "\n\nCurrent application context (JSON):\n"
        . json_encode(
            $context,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

    $result = callOpenAiResponsesWithTools(
        $conn,
        $history,
        $instructions,
        (int) $userId,
        $role,
        $companyId !== null
            ? (int) $companyId
            : null
    );

    $conn->beginTransaction();

    $insertAssistant = $conn->prepare("
        INSERT INTO ai_messages (
            conversation_id,
            role,
            content,
            metadata
        )
        VALUES (?, 'assistant', ?, ?)
    ");

    $insertAssistant->execute([
        $conversationId,
        $result['text'],
        json_encode([
            'response_id' => $result['response_id'],
            'model' => $result['model'],
            'usage' => $result['usage']
        ])
    ]);

    $touch = $conn->prepare("
        UPDATE ai_conversations
        SET updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_id = ?
    ");

    $touch->execute([
        $conversationId,
        $userId
    ]);

    writeAuditLog(
        $conn,
        (int) $userId,
        $companyId !== null
            ? (int) $companyId
            : null,
        'ai.response_generated',
        'ai_conversation',
        (string) $conversationId,
        [
            'scope' => $scope,
            'model' => $result['model'],
            'response_id' => $result['response_id'],
            'usage' => $result['usage']
        ]
    );

    $conn->commit();

    echo json_encode([
        'success' => true,
        'conversation_id' => $conversationId,
        'reply' => $result['text']
    ]);
    exit;

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
