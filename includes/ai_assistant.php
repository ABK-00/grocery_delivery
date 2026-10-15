<?php
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/app.php';
}

$aiEsc = static fn(mixed $value): string =>
    htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

$aiRole = (string) ($_SESSION['role'] ?? 'customer');

$aiTitle = match ($aiRole) {
    'super_admin' => 'Somame Platform Assistant',
    'admin' => 'Somame Admin Assistant',
    'staff' => 'Somame Staff Assistant',
    'delivery_partner' => 'Somame Delivery Assistant',
    default => 'Somame Assistant'
};

$aiPlaceholder = match ($aiRole) {
    'super_admin' => 'Ask about companies, subscriptions or platform activity…',
    'admin' => 'Ask about orders, products or store performance…',
    'staff' => 'Ask about orders, products or operations…',
    'delivery_partner' => 'Ask about your assigned deliveries…',
    default => 'Ask about products, orders or deliveries…'
};
?>

<link
    rel="stylesheet"
    href="<?= $aiEsc(BASE_URL) ?>/assets/css/ai-assistant.css"
>

<div
    class="somame-ai"
    id="somameAi"
    data-endpoint="<?= $aiEsc(BASE_URL) ?>/api/ai/chat.php"
    data-role="<?= $aiEsc($aiRole) ?>"
>
    <button
        type="button"
        class="somame-ai-launcher"
        id="somameAiLauncher"
        aria-label="Open <?= $aiEsc($aiTitle) ?>"
        title="<?= $aiEsc($aiTitle) ?>"
    >
        <i class="bi bi-stars"></i>
        <span class="somame-ai-launcher-text">Ask Somame AI</span>
    </button>

    <section
        class="somame-ai-panel"
        id="somameAiPanel"
        aria-hidden="true"
    >
        <header class="somame-ai-header">
            <div class="somame-ai-brand">
                <span class="somame-ai-avatar">
                    <i class="bi bi-stars"></i>
                </span>

                <div>
                    <div class="somame-ai-title">
                        <?= $aiEsc($aiTitle) ?>
                    </div>
                    <div class="somame-ai-status">
                        <span class="somame-ai-status-dot"></span>
                        Role-aware assistant
                    </div>
                </div>
            </div>

            <div class="somame-ai-header-actions">
                <button
                    type="button"
                    class="somame-ai-icon-btn"
                    id="somameAiNewChat"
                    title="Start new conversation"
                    aria-label="Start new conversation"
                >
                    <i class="bi bi-plus-lg"></i>
                </button>

                <button
                    type="button"
                    class="somame-ai-icon-btn"
                    id="somameAiClose"
                    title="Close"
                    aria-label="Close assistant"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </header>

        <div
            class="somame-ai-messages"
            id="somameAiMessages"
            aria-live="polite"
        >
            <div class="somame-ai-message assistant">
                <div class="somame-ai-bubble">
                    Hi! I’m Somame AI. I only use information and actions allowed for your account. How can I help?
                </div>
            </div>
        </div>

        <form
            class="somame-ai-compose"
            id="somameAiForm"
        >
            <textarea
                id="somameAiInput"
                class="somame-ai-input"
                rows="1"
                maxlength="2000"
                placeholder="<?= $aiEsc($aiPlaceholder) ?>"
                required
            ></textarea>

            <button
                type="submit"
                class="somame-ai-send"
                id="somameAiSend"
                aria-label="Send message"
            >
                <i class="bi bi-arrow-up"></i>
            </button>
        </form>

        <div class="somame-ai-footnote">
            AI can make mistakes. Verify important order or payment decisions.
        </div>
    </section>
</div>

<script
    src="<?= $aiEsc(BASE_URL) ?>/assets/js/ai-assistant.js"
    defer
></script>
