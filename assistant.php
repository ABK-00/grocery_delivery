<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_service.php';

requireLogin();

$userId = currentUserId();
$role = (string) ($_SESSION['role'] ?? '');
$companyId = currentCompanyId();

$requestedMode =
    $role === 'customer'
        ? (string) ($_GET['mode'] ?? 'support')
        : null;

$assistantScope = aiScopeForRole(
    $role,
    $requestedMode === 'personalization'
        ? 'customer_personalization'
        : null
);

if (empty($_SESSION['ai_chat_csrf'])) {
    $_SESSION['ai_chat_csrf'] =
        bin2hex(random_bytes(32));
}

$conversationId = filter_input(
    INPUT_GET,
    'conversation_id',
    FILTER_VALIDATE_INT
);

$conversation = null;
$messages = [];

if ($conversationId) {
    $stmt = $conn->prepare("
        SELECT id, title, scope, updated_at
        FROM ai_conversations
        WHERE id = ?
          AND user_id = ?
          AND scope = ?
        LIMIT 1
    ");

    $stmt->execute([
        $conversationId,
        $userId,
        $assistantScope
    ]);

    $conversation = $stmt->fetch();

    if ($conversation) {
        $stmt = $conn->prepare("
            SELECT role, content, created_at
            FROM ai_messages
            WHERE conversation_id = ?
            ORDER BY id ASC
        ");

        $stmt->execute([
            $conversationId
        ]);

        $messages = $stmt->fetchAll();
    } else {
        $conversationId = null;
    }
}

$stmt = $conn->prepare("
    SELECT id, title, scope, updated_at
    FROM ai_conversations
    WHERE user_id = ?
      AND scope = ?
    ORDER BY updated_at DESC
    LIMIT 20
");

$stmt->execute([
    $userId,
    $assistantScope
]);

$conversations = $stmt->fetchAll();

$roleTitle = match ($role) {
    'super_admin' => 'Platform AI Assistant',
    'admin' => 'Business AI Assistant',
    'staff' => 'Operations AI Assistant',
    'delivery_partner' => 'Delivery AI Assistant',
    default => $assistantScope === 'customer_personalization'
        ? 'Personal Shopping Assistant'
        : 'Customer Support Assistant',
};

$roleIntro = match ($role) {
    'super_admin' =>
        'Ask about platform performance, subscriptions, companies, support load, risks, and operational priorities.',
    'admin' =>
        'Ask about your store, orders, inventory, customers, support queue, deliveries, and business priorities.',
    'staff' =>
        'Get help processing orders and handling day-to-day store operations.',
    'delivery_partner' =>
        'Get help with your assigned deliveries and delivery workflow.',
    default => $assistantScope === 'customer_personalization'
        ? 'Discover products and get recommendations using your enabled shopping preferences and purchase patterns.'
        : 'Get help with orders, payments, deliveries, product discovery, and support.',
};

$dashboardUrl = match ($role) {
    'super_admin' => BASE_URL . '/super_admin/dashboard.php',
    'admin' => BASE_URL . '/admin/dashboard.php',
    'staff' => BASE_URL . '/staff/dashboard.php',
    'delivery_partner' => BASE_URL . '/delivery/dashboard.php',
    default => BASE_URL . '/customer/dashboard.php',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title><?= e($roleTitle) ?> | GroceryDelivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f6f8fb;
            color: #172033;
        }

        .ai-shell {
            max-width: 1320px;
            margin: 0 auto;
            padding: 24px;
        }

        .ai-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .ai-layout {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            gap: 20px;
        }

        .panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, .05);
        }

        .history {
            padding: 16px;
            min-height: 680px;
        }

        .conversation-link {
            display: block;
            padding: 11px 12px;
            border-radius: 12px;
            text-decoration: none;
            color: #334155;
            margin-bottom: 7px;
        }

        .conversation-link:hover,
        .conversation-link.active {
            background: #eef8f1;
            color: #166534;
        }

        .chat-panel {
            min-height: 680px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f4;
        }

        .messages {
            flex: 1;
            padding: 22px;
            overflow-y: auto;
            max-height: 580px;
        }

        .message {
            max-width: 82%;
            padding: 12px 15px;
            border-radius: 15px;
            margin-bottom: 12px;
            white-space: pre-wrap;
            line-height: 1.5;
        }

        .message.user {
            background: #172033;
            color: #fff;
            margin-left: auto;
            border-bottom-right-radius: 5px;
        }

        .message.assistant {
            background: #eef8f1;
            color: #16351f;
            margin-right: auto;
            border-bottom-left-radius: 5px;
        }

        .empty-chat {
            min-height: 420px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
        }

        .chat-form {
            padding: 16px;
            border-top: 1px solid #edf0f4;
            background: #fff;
        }

        .composer {
            display: flex;
            gap: 10px;
        }

        .composer textarea {
            resize: none;
            min-height: 52px;
            max-height: 160px;
        }

        .ai-note {
            font-size: 12px;
            color: #64748b;
            margin-top: 8px;
        }

        @media(max-width: 850px) {
            .ai-layout {
                grid-template-columns: 1fr;
            }

            .history {
                min-height: auto;
            }

            .message {
                max-width: 94%;
            }
        }
    </style>
</head>
<body>

<div class="ai-shell">

    <div class="ai-topbar">
        <div>
            <div class="text-success fw-semibold small text-uppercase">
                AI Workspace
            </div>
            <h2 class="fw-bold mb-1">
                <?= e($roleTitle) ?>
            </h2>
            <div class="text-muted">
                <?= e($roleIntro) ?>
            </div>
        </div>

        <a
            href="<?= e($dashboardUrl) ?>"
            class="btn btn-outline-dark"
        >
            <i class="bi bi-arrow-left"></i>
            Dashboard
        </a>
    </div>

    <?php if (!OPENAI_AI_ENABLED): ?>
        <div class="alert alert-warning">
            <strong>AI is not configured yet.</strong>
            Add your API key in
            <code>config/ai.local.php</code>
            before using this assistant.
        </div>
    <?php endif; ?>

    <?php if ($role === 'customer'): ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a
                href="<?= BASE_URL ?>/assistant.php?mode=support"
                class="btn <?= $assistantScope === 'customer_support' ? 'btn-dark' : 'btn-outline-dark' ?>"
            >
                <i class="bi bi-headset"></i>
                Support
            </a>
            <a
                href="<?= BASE_URL ?>/assistant.php?mode=personalization"
                class="btn <?= $assistantScope === 'customer_personalization' ? 'btn-success' : 'btn-outline-success' ?>"
            >
                <i class="bi bi-stars"></i>
                Personal Shopping
            </a>
            <a
                href="<?= BASE_URL ?>/customer/preferences.php"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-sliders"></i>
                Preferences
            </a>
        </div>
    <?php endif; ?>

    <div class="ai-layout">

        <aside class="panel history">

            <a
                href="<?= BASE_URL ?>/assistant.php<?= $role === 'customer' ? '?mode=' . ($assistantScope === 'customer_personalization' ? 'personalization' : 'support') : '' ?>"
                class="btn btn-success w-100 mb-3"
            >
                <i class="bi bi-plus-lg"></i>
                New conversation
            </a>

            <div class="small fw-bold text-uppercase text-muted mb-2">
                Recent conversations
            </div>

            <?php if (!$conversations): ?>
                <div class="text-muted small">
                    No conversations yet.
                </div>
            <?php endif; ?>

            <?php foreach ($conversations as $item): ?>
                <a
                    href="<?= BASE_URL ?>/assistant.php?<?= $role === 'customer' ? 'mode=' . ($assistantScope === 'customer_personalization' ? 'personalization' : 'support') . '&' : '' ?>conversation_id=<?= (int) $item['id'] ?>"
                    class="conversation-link <?= (int) $conversationId === (int) $item['id'] ? 'active' : '' ?>"
                >
                    <div class="fw-semibold text-truncate">
                        <?= e($item['title'] ?: 'Conversation') ?>
                    </div>
                    <small class="text-muted">
                        <?= date(
                            'd M, H:i',
                            strtotime($item['updated_at'])
                        ) ?>
                    </small>
                </a>
            <?php endforeach; ?>

        </aside>

        <section class="panel chat-panel">

            <div class="chat-header">
                <div class="d-flex align-items-center gap-3">
                    <div
                        class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center"
                        style="width:44px;height:44px"
                    >
                        <i class="bi bi-stars"></i>
                    </div>
                    <div>
                        <div class="fw-bold">
                            <?= e($roleTitle) ?>
                        </div>
                        <small class="text-muted">
                            Uses your authorized application context
                        </small>
                    </div>
                </div>
            </div>

            <div class="messages" id="messages">

                <?php if (!$messages): ?>
                    <div class="empty-chat" id="emptyChat">
                        <i class="bi bi-chat-square-heart fs-1 text-success"></i>
                        <h4 class="mt-3 fw-bold">
                            What can I help you with?
                        </h4>
                        <p class="mb-0">
                            <?= e($roleIntro) ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php foreach ($messages as $item): ?>
                    <div class="message <?= $item['role'] === 'assistant' ? 'assistant' : 'user' ?>">
                        <?= e($item['content']) ?>
                    </div>
                <?php endforeach; ?>

            </div>

            <form class="chat-form" id="chatForm">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['ai_chat_csrf']) ?>"
                >
                <input
                    type="hidden"
                    name="assistant_scope"
                    value="<?= e($assistantScope) ?>"
                >
                <input
                    type="hidden"
                    name="conversation_id"
                    id="conversationId"
                    value="<?= $conversationId ? (int) $conversationId : '' ?>"
                >

                <div class="composer">
                    <textarea
                        name="message"
                        id="messageInput"
                        class="form-control"
                        placeholder="Ask your assistant..."
                        maxlength="4000"
                        required
                    ></textarea>

                    <button
                        type="submit"
                        class="btn btn-success px-4"
                        id="sendButton"
                    >
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>

                <div class="ai-note">
                    AI can make mistakes. Verify important operational or payment decisions before acting.
                </div>
            </form>

        </section>

    </div>

</div>

<script>
const form = document.getElementById('chatForm');
const messages = document.getElementById('messages');
const input = document.getElementById('messageInput');
const sendButton = document.getElementById('sendButton');
const conversationIdInput =
    document.getElementById('conversationId');

function appendMessage(role, text) {
    const empty = document.getElementById('emptyChat');

    if (empty) {
        empty.remove();
    }

    const node = document.createElement('div');
    node.className = 'message ' + role;
    node.textContent = text;
    messages.appendChild(node);
    messages.scrollTop = messages.scrollHeight;
    return node;
}

form.addEventListener('submit', async function (event) {
    event.preventDefault();

    const text = input.value.trim();

    if (!text) {
        return;
    }

    appendMessage('user', text);

    input.value = '';
    input.disabled = true;
    sendButton.disabled = true;

    const waiting = appendMessage(
        'assistant',
        'Thinking…'
    );

    try {
        const body = new FormData(form);
        body.set('message', text);

        const response = await fetch(
            '<?= BASE_URL ?>/api/ai_chat.php',
            {
                method: 'POST',
                body
            }
        );

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || 'Unable to contact the assistant.'
            );
        }

        waiting.textContent = result.reply;

        if (result.conversation_id) {
            conversationIdInput.value =
                result.conversation_id;
        }

    } catch (error) {
        waiting.textContent =
            error.message || 'Unable to contact the assistant.';
    } finally {
        input.disabled = false;
        sendButton.disabled = false;
        input.focus();
        messages.scrollTop = messages.scrollHeight;
    }
});

input.addEventListener('keydown', function (event) {
    if (
        event.key === 'Enter'
        && !event.shiftKey
    ) {
        event.preventDefault();
        form.requestSubmit();
    }
});

messages.scrollTop = messages.scrollHeight;
</script>

</body>
</html>
