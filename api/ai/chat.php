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

    $role = strtolower(
        (string)(
            $_SESSION['role']
            ?? $_SESSION['user_role']
            ?? 'customer'
        )
    );

    $companyId = !empty($_SESSION['company_id'])
        ? (int) $_SESSION['company_id']
        : null;

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

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}