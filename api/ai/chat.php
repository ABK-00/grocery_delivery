<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_service.php';

if (
    !isLoggedIn()
    || !syncAuthenticatedSession()
) {
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

    $userId = (int) currentUserId();
    $role = (string) ($_SESSION['role'] ?? '');
    $companyId = currentCompanyId();

    $enabled = (string) platformSetting(
        $conn,
        'ai_enabled',
        platformSetting(
            $conn,
            'ai.enabled',
            '0'
        )
    );

    if ($enabled !== '1') {
        http_response_code(503);

        echo json_encode([
            'success' => false,
            'message' => 'The AI assistant is currently disabled.'
        ]);
        exit;
    }

    if (!OPENAI_AI_ENABLED) {
        http_response_code(503);

        echo json_encode([
            'success' => false,
            'message' => 'The AI assistant has not been configured with an API key yet.'
        ]);
        exit;
    }

    $windowStart =
        (int) ($_SESSION['ai_rate_window'] ?? 0);

    $requestCount =
        (int) ($_SESSION['ai_rate_count'] ?? 0);

    $now = time();

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
            'message' => 'Your daily AI request limit has been reached.'
        ]);
        exit;
    }

    $scope = aiScopeForRole(
        $role,
        $role === 'customer'
            && ($payload['mode'] ?? '') === 'personalization'
                ? 'customer_personalization'
                : null
    );

    $conversationId =
        !empty($payload['conversation_id'])
            ? (int) $payload['conversation_id']
            : null;

    $conn->beginTransaction();

    if ($conversationId) {
        $stmt = $conn->prepare("
            SELECT
                id,
                user_id,
                company_id,
                scope
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

        $conversation =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conversation) {
            throw new RuntimeException(
                'Conversation not found.'
            );
        }

        if (
            (string) $conversation['scope']
            !== $scope
        ) {
            throw new RuntimeException(
                'This conversation belongs to a different assistant context.'
            );
        }

        if (
            in_array(
                $role,
                ['admin', 'staff', 'delivery_partner'],
                true
            )
            && (int) ($conversation['company_id'] ?? 0)
                !== (int) $companyId
        ) {
            throw new RuntimeException(
                'Conversation company access denied.'
            );
        }
    } else {
        $title = mb_substr(
            preg_replace(
                '/\s+/',
                ' ',
                $message
            ),
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

    $insertUser = $conn->prepare("
        INSERT INTO ai_messages (
            conversation_id,
            role,
            content
        )
        VALUES (?, 'user', ?)
    ");

    $insertUser->execute([
        $conversationId,
        $message
    ]);

    $conn->commit();

    $historyStmt = $conn->prepare("
        SELECT
            role,
            content
        FROM ai_messages
        WHERE conversation_id = ?
        ORDER BY id DESC
        LIMIT 16
    ");

    $historyStmt->execute([
        $conversationId
    ]);

    $history = array_reverse(
        $historyStmt->fetchAll(
            PDO::FETCH_ASSOC
        )
    );

    $context = buildAiBusinessContext(
        $conn,
        $userId,
        $role,
        $companyId
    );

    $instructions =
        aiInstructionsForRole(
            $role,
            $scope
        )
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
        $userId,
        $role,
        $companyId
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
            'response_id' =>
                $result['response_id'] ?? null,
            'model' =>
                $result['model'] ?? OPENAI_MODEL,
            'usage' =>
                $result['usage'] ?? null
        ])
    ]);

    $conn->prepare("
        UPDATE ai_conversations
        SET updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_id = ?
    ")->execute([
        $conversationId,
        $userId
    ]);

    writeAuditLog(
        $conn,
        $userId,
        $companyId,
        'ai.response_generated',
        'ai_conversation',
        (string) $conversationId,
        [
            'scope' => $scope,
            'model' =>
                $result['model'] ?? OPENAI_MODEL,
            'usage' =>
                $result['usage'] ?? null
        ]
    );

    $conn->commit();

    echo json_encode([
        'success' => true,
        'conversation_id' =>
            $conversationId,
        'message' =>
            $result['text'],
        'model' =>
            $result['model'] ?? OPENAI_MODEL,
        'usage' =>
            $result['usage'] ?? null
    ]);

} catch (InvalidArgumentException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

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
