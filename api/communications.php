<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/communication.php';

$role = requirePortalContext();
$userId = (int) currentUserId();
$companyId = currentCompanyId();
$portal = portalKeyForRole($role);
$action = trim((string) ($_GET['action'] ?? ''));

$payload = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType =
        strtolower(
            (string) (
                $_SERVER['CONTENT_TYPE']
                ?? ''
            )
        );

    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (is_array($decoded)) {
            $payload = $decoded;
        }
    } else {
        $payload = $_POST;
    }

    $csrf =
        (string) (
            $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $payload['csrf_token']
            ?? ''
        );

    if (!validateCsrfToken('communications', $csrf)) {
        http_response_code(419);
        echo json_encode([
            'success' => false,
            'message' =>
                'Your communications session expired. Refresh and try again.'
        ]);
        exit;
    }
}

function communicationJson(
    array $data,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function communicationCallRow(
    PDO $conn,
    int $callId,
    int $userId
): array|false {
    $stmt = $conn->prepare("
        SELECT
            cc.*,
            ct.order_id,
            caller.name AS caller_name,
            caller.role AS caller_role,
            callee.name AS callee_name,
            callee.role AS callee_role
        FROM communication_calls cc
        INNER JOIN communication_threads ct
            ON ct.id = cc.thread_id
        INNER JOIN users caller
            ON caller.id = cc.caller_id
        INNER JOIN users callee
            ON callee.id = cc.callee_id
        WHERE cc.id = ?
          AND (
                cc.caller_id = ?
                OR cc.callee_id = ?
          )
        LIMIT 1
    ");

    $stmt->execute([
        $callId,
        $userId,
        $userId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function communicationAssertCallAccess(
    PDO $conn,
    array $call,
    int $userId,
    string $role,
    ?int $companyId
): array {
    return communicationAssertThreadAccess(
        $conn,
        (int) $call['thread_id'],
        $userId,
        $role,
        $companyId
    );
}

try {
    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && $action === 'contacts'
    ) {
        $orderId =
            (int) ($_GET['order_id'] ?? 0);

        $contacts =
            communicationContactsForOrder(
                $conn,
                $orderId,
                $userId,
                $role,
                $companyId
            );

        communicationJson([
            'success' => true,
            'contacts' => $contacts
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && $action === 'threads'
    ) {
        $orderId =
            (int) ($_GET['order_id'] ?? 0);

        if (
            !communicationCanAccessOrder(
                $conn,
                $orderId,
                $userId,
                $role,
                $companyId
            )
        ) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Delivery communication is not available for this order.'
            ], 403);
        }

        $stmt = $conn->prepare("
            SELECT
                ct.id,
                ct.order_id,
                ct.last_message_at,
                ct.updated_at,

                CASE
                    WHEN ct.user_one_id = ?
                    THEN ct.user_two_id
                    ELSE ct.user_one_id
                END AS other_user_id,

                u.name AS other_name,
                u.role AS other_role,

                (
                    SELECT cm.message
                    FROM communication_messages cm
                    WHERE cm.thread_id = ct.id
                    ORDER BY cm.id DESC
                    LIMIT 1
                ) AS last_message,

                (
                    SELECT COUNT(*)
                    FROM communication_messages cm
                    WHERE cm.thread_id = ct.id
                      AND cm.sender_id <> ?
                      AND cm.read_at IS NULL
                ) AS unread_count

            FROM communication_threads ct

            INNER JOIN users u
                ON u.id = CASE
                    WHEN ct.user_one_id = ?
                    THEN ct.user_two_id
                    ELSE ct.user_one_id
                END

            WHERE ct.order_id = ?
              AND (
                    ct.user_one_id = ?
                    OR ct.user_two_id = ?
              )

            ORDER BY
                COALESCE(
                    ct.last_message_at,
                    ct.updated_at
                ) DESC,
                ct.id DESC
        ");

        $stmt->execute([
            $userId,
            $userId,
            $userId,
            $orderId,
            $userId,
            $userId
        ]);

        $threads = [];

        foreach (
            $stmt->fetchAll(PDO::FETCH_ASSOC)
            as $thread
        ) {
            if (
                communicationCanContactForOrder(
                    $conn,
                    $orderId,
                    $userId,
                    $role,
                    $companyId,
                    (int) $thread['other_user_id']
                )
            ) {
                $threads[] = $thread;
            }
        }

        communicationJson([
            'success' => true,
            'threads' => $threads
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && $action === 'thread'
    ) {
        $orderId =
            (int) ($payload['order_id'] ?? 0);

        $targetId =
            (int) ($payload['target_id'] ?? 0);

        $thread =
            communicationThread(
                $conn,
                $orderId,
                $userId,
                $role,
                $companyId,
                $targetId,
                true
            );

        if (!$thread) {
            communicationJson([
                'success' => false,
                'message' =>
                    'This delivery communication channel is not available.'
            ], 403);
        }

        communicationJson([
            'success' => true,
            'thread_id' =>
                (int) $thread['id']
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && $action === 'messages'
    ) {
        $threadId =
            (int) ($_GET['thread_id'] ?? 0);

        $afterId = max(
            0,
            (int) ($_GET['after_id'] ?? 0)
        );

        communicationAssertThreadAccess(
            $conn,
            $threadId,
            $userId,
            $role,
            $companyId
        );

        $stmt = $conn->prepare("
            SELECT
                cm.id,
                cm.sender_id,
                cm.message,
                cm.read_at,
                cm.created_at,
                u.name AS sender_name
            FROM communication_messages cm
            INNER JOIN users u
                ON u.id = cm.sender_id
            WHERE cm.thread_id = ?
              AND cm.id > ?
            ORDER BY cm.id ASC
            LIMIT 200
        ");

        $stmt->execute([
            $threadId,
            $afterId
        ]);

        $messages =
            $stmt->fetchAll(PDO::FETCH_ASSOC);

        $conn->prepare("
            UPDATE communication_messages
            SET read_at = COALESCE(
                read_at,
                NOW()
            )
            WHERE thread_id = ?
              AND sender_id <> ?
              AND read_at IS NULL
        ")->execute([
            $threadId,
            $userId
        ]);

        communicationJson([
            'success' => true,
            'messages' => $messages
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && $action === 'send'
    ) {
        $threadId =
            (int) ($payload['thread_id'] ?? 0);

        $message = trim(
            (string) ($payload['message'] ?? '')
        );

        if (
            $message === ''
            || mb_strlen($message) > 4000
        ) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Enter a message up to 4000 characters.'
            ], 422);
        }

        $thread =
            communicationAssertThreadAccess(
                $conn,
                $threadId,
                $userId,
                $role,
                $companyId
            );

        $otherUserId =
            communicationOtherUserId(
                $thread,
                $userId
            );

        $conn->beginTransaction();

        $stmt = $conn->prepare("
            INSERT INTO communication_messages (
                thread_id,
                sender_id,
                message
            )
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $threadId,
            $userId,
            $message
        ]);

        $messageId =
            (int) $conn->lastInsertId();

        $conn->prepare("
            UPDATE communication_threads
            SET
                last_message_at = NOW(),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            $threadId
        ]);

        $sender =
            communicationUser(
                $conn,
                $userId
            );

        $recipient =
            communicationUser(
                $conn,
                $otherUserId
            );

        $recipientPortal =
            portalKeyForRole(
                (string) $recipient['role']
            );

        createNotification(
            $conn,
            $otherUserId,
            'message',
            'Delivery update from '
                . $sender['name'],
            mb_strlen($message) > 120
                ? mb_substr($message, 0, 117)
                    . '...'
                : $message,
            BASE_URL
                . '/communications.php?portal='
                . rawurlencode($recipientPortal)
                . '&order='
                . (int) $thread['order_id']
                . '&with='
                . $userId
        );

        writeAuditLog(
            $conn,
            $userId,
            $companyId,
            'communication.delivery_message_sent',
            'order',
            (string) $thread['order_id'],
            [
                'thread_id' =>
                    $threadId,
                'recipient_id' =>
                    $otherUserId,
                'message_id' =>
                    $messageId
            ]
        );

        $conn->commit();

        communicationJson([
            'success' => true,
            'message_id' => $messageId
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && $action === 'start_call'
    ) {
        $threadId =
            (int) ($payload['thread_id'] ?? 0);

        $thread =
            communicationAssertThreadAccess(
                $conn,
                $threadId,
                $userId,
                $role,
                $companyId
            );

        $calleeId =
            communicationOtherUserId(
                $thread,
                $userId
            );

        $active = $conn->prepare("
            SELECT id
            FROM communication_calls
            WHERE status IN (
                'ringing',
                'accepted'
            )
              AND (
                    caller_id IN (?, ?)
                    OR callee_id IN (?, ?)
              )
            LIMIT 1
        ");

        $active->execute([
            $userId,
            $calleeId,
            $userId,
            $calleeId
        ]);

        if ($active->fetchColumn()) {
            communicationJson([
                'success' => false,
                'message' =>
                    'One of you is already in an active call.'
            ], 409);
        }

        $stmt = $conn->prepare("
            INSERT INTO communication_calls (
                thread_id,
                caller_id,
                callee_id,
                status,
                started_at
            )
            VALUES (
                ?,
                ?,
                ?,
                'ringing',
                NOW()
            )
        ");

        $stmt->execute([
            $threadId,
            $userId,
            $calleeId
        ]);

        $callId =
            (int) $conn->lastInsertId();

        $caller =
            communicationUser(
                $conn,
                $userId
            );

        $callee =
            communicationUser(
                $conn,
                $calleeId
            );

        createNotification(
            $conn,
            $calleeId,
            'call',
            'Delivery call from '
                . $caller['name'],
            'Open the active delivery communication channel to answer.',
            BASE_URL
                . '/communications.php?portal='
                . rawurlencode(
                    portalKeyForRole(
                        (string) $callee['role']
                    )
                )
                . '&order='
                . (int) $thread['order_id']
                . '&with='
                . $userId
                . '&call='
                . $callId
        );

        communicationJson([
            'success' => true,
            'call_id' => $callId,
            'callee_id' => $calleeId
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && $action === 'incoming_call'
    ) {
        $conn->prepare("
            UPDATE communication_calls cc
            INNER JOIN communication_threads ct
                ON ct.id = cc.thread_id
            LEFT JOIN orders o
                ON o.id = ct.order_id
            LEFT JOIN deliveries d
                ON d.order_id = o.id
            SET
                cc.status = 'missed',
                cc.ended_at = NOW()
            WHERE cc.callee_id = ?
              AND cc.status = 'ringing'
              AND (
                    cc.started_at
                        < DATE_SUB(
                            NOW(),
                            INTERVAL 60 SECOND
                        )
                    OR o.status <> 'out_for_delivery'
                    OR d.status <> 'out_for_delivery'
              )
        ")->execute([
            $userId
        ]);

        $stmt = $conn->prepare("
            SELECT
                cc.id,
                cc.thread_id,
                ct.order_id,
                cc.caller_id,
                cc.started_at,
                u.name AS caller_name,
                u.role AS caller_role
            FROM communication_calls cc
            INNER JOIN communication_threads ct
                ON ct.id = cc.thread_id
            INNER JOIN orders o
                ON o.id = ct.order_id
            INNER JOIN deliveries d
                ON d.order_id = o.id
            INNER JOIN users u
                ON u.id = cc.caller_id
            WHERE cc.callee_id = ?
              AND cc.status = 'ringing'
              AND o.status = 'out_for_delivery'
              AND d.status = 'out_for_delivery'
            ORDER BY cc.id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $userId
        ]);

        $call =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if ($call) {
            try {
                communicationAssertThreadAccess(
                    $conn,
                    (int) $call['thread_id'],
                    $userId,
                    $role,
                    $companyId
                );
            } catch (Throwable $e) {
                $call = false;
            }
        }

        communicationJson([
            'success' => true,
            'call' => $call ?: null
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && $action === 'call_action'
    ) {
        $callId =
            (int) ($payload['call_id'] ?? 0);

        $status = trim(
            (string) ($payload['status'] ?? '')
        );

        $call =
            communicationCallRow(
                $conn,
                $callId,
                $userId
            );

        if (!$call) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Call not found.'
            ], 404);
        }

        communicationAssertCallAccess(
            $conn,
            $call,
            $userId,
            $role,
            $companyId
        );

        $allowed = [
            'accepted',
            'rejected',
            'ended',
            'cancelled'
        ];

        if (!in_array($status, $allowed, true)) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Invalid call action.'
            ], 422);
        }

        if (
            $status === 'accepted'
            && (
                (int) $call['callee_id']
                    !== $userId
                || $call['status'] !== 'ringing'
            )
        ) {
            communicationJson([
                'success' => false,
                'message' =>
                    'This call cannot be answered.'
            ], 409);
        }

        if (
            $status === 'rejected'
            && (int) $call['callee_id']
                !== $userId
        ) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Only the recipient can reject this call.'
            ], 403);
        }

        if (
            $status === 'cancelled'
            && (int) $call['caller_id']
                !== $userId
        ) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Only the caller can cancel this call.'
            ], 403);
        }

        if ($status === 'accepted') {
            $conn->prepare("
                UPDATE communication_calls
                SET
                    status = 'accepted',
                    answered_at = NOW()
                WHERE id = ?
            ")->execute([$callId]);
        } else {
            $conn->prepare("
                UPDATE communication_calls
                SET
                    status = ?,
                    ended_at = NOW()
                WHERE id = ?
            ")->execute([
                $status,
                $callId
            ]);
        }

        if (
            in_array(
                $status,
                [
                    'rejected',
                    'cancelled',
                    'ended'
                ],
                true
            )
        ) {
            $otherId =
                (int) $call['caller_id']
                    === $userId
                        ? (int) $call['callee_id']
                        : (int) $call['caller_id'];

            $conn->prepare("
                INSERT INTO communication_call_signals (
                    call_id,
                    sender_id,
                    recipient_id,
                    signal_type,
                    payload
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    'hangup',
                    NULL
                )
            ")->execute([
                $callId,
                $userId,
                $otherId
            ]);
        }

        communicationJson([
            'success' => true,
            'status' => $status
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && $action === 'signal'
    ) {
        $callId =
            (int) ($payload['call_id'] ?? 0);

        $signalType = trim(
            (string) ($payload['signal_type'] ?? '')
        );

        $call =
            communicationCallRow(
                $conn,
                $callId,
                $userId
            );

        if (!$call) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Call not found.'
            ], 404);
        }

        communicationAssertCallAccess(
            $conn,
            $call,
            $userId,
            $role,
            $companyId
        );

        if (
            !in_array(
                $call['status'],
                ['ringing', 'accepted'],
                true
            )
        ) {
            communicationJson([
                'success' => false,
                'message' =>
                    'This call is no longer active.'
            ], 409);
        }

        if (
            !in_array(
                $signalType,
                ['offer', 'answer', 'ice'],
                true
            )
        ) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Invalid call signal.'
            ], 422);
        }

        $recipientId =
            (int) $call['caller_id'] === $userId
                ? (int) $call['callee_id']
                : (int) $call['caller_id'];

        $conn->prepare("
            INSERT INTO communication_call_signals (
                call_id,
                sender_id,
                recipient_id,
                signal_type,
                payload
            )
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $callId,
            $userId,
            $recipientId,
            $signalType,
            isset($payload['payload'])
                ? json_encode(
                    $payload['payload'],
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                )
                : null
        ]);

        communicationJson([
            'success' => true
        ]);
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && $action === 'signals'
    ) {
        $callId =
            (int) ($_GET['call_id'] ?? 0);

        $afterId = max(
            0,
            (int) ($_GET['after_id'] ?? 0)
        );

        $call =
            communicationCallRow(
                $conn,
                $callId,
                $userId
            );

        if (!$call) {
            communicationJson([
                'success' => false,
                'message' =>
                    'Call not found.'
            ], 404);
        }

        communicationAssertCallAccess(
            $conn,
            $call,
            $userId,
            $role,
            $companyId
        );

        $stmt = $conn->prepare("
            SELECT
                id,
                sender_id,
                signal_type,
                payload,
                created_at
            FROM communication_call_signals
            WHERE call_id = ?
              AND recipient_id = ?
              AND id > ?
            ORDER BY id ASC
            LIMIT 100
        ");

        $stmt->execute([
            $callId,
            $userId,
            $afterId
        ]);

        $signals =
            $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($signals as &$signal) {
            $signal['payload'] =
                $signal['payload'] !== null
                    ? json_decode(
                        (string) $signal['payload'],
                        true
                    )
                    : null;
        }
        unset($signal);

        communicationJson([
            'success' => true,
            'call_status' =>
                $call['status'],
            'signals' => $signals
        ]);
    }

    communicationJson([
        'success' => false,
        'message' =>
            'Unknown communications action.'
    ], 404);

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log(
        'Communications API error: '
        . $e->getMessage()
    );

    communicationJson([
        'success' => false,
        'message' =>
            'The delivery communication channel is unavailable.'
    ], 500);
}
