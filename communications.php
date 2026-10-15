<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/communication.php';

$role = requirePortalContext();
$userId = (int) currentUserId();
$companyId = currentCompanyId();
$portal = portalKeyForRole($role);

$orderId =
    filter_input(
        INPUT_GET,
        'order',
        FILTER_VALIDATE_INT
    ) ?: 0;

$deliveryContext =
    $orderId > 0
        ? communicationDeliveryContext(
            $conn,
            $orderId
        )
        : false;

if (
    !$deliveryContext
    || !communicationCanAccessOrder(
        $conn,
        $orderId,
        $userId,
        $role,
        $companyId
    )
) {
    http_response_code(403);

    $fallback =
        roleDashboardUrl($role);

    header(
        'Location: '
        . $fallback
    );
    exit;
}

$contacts =
    communicationContactsForOrder(
        $conn,
        $orderId,
        $userId,
        $role,
        $companyId
    );

$selectedTargetId =
    filter_input(
        INPUT_GET,
        'with',
        FILTER_VALIDATE_INT
    ) ?: 0;

$selectedCallId =
    filter_input(
        INPUT_GET,
        'call',
        FILTER_VALIDATE_INT
    ) ?: 0;

$selectedThreadId = 0;
$selectedContact = null;

if ($selectedTargetId > 0) {
    foreach ($contacts as $contact) {
        if (
            (int) $contact['id']
            === $selectedTargetId
        ) {
            $selectedContact =
                $contact;
            break;
        }
    }

    if ($selectedContact) {
        $thread =
            communicationThread(
                $conn,
                $orderId,
                $userId,
                $role,
                $companyId,
                $selectedTargetId,
                true
            );

        if ($thread) {
            $selectedThreadId =
                (int) $thread['id'];
        }
    }
}

$csrf = csrfToken(
    'communications'
);

$dashboardUrl =
    roleDashboardUrl(
        $role
    );

$roleLabel =
    'Delivery Communication';

function communicationRoleLabel(
    string $role
): string {
    return match ($role) {
        'super_admin' =>
            'Super Admin',
        'admin' =>
            'Company Admin',
        'staff' =>
            'Staff',
        'delivery_partner' =>
            'Delivery Partner',
        default =>
            'Customer',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Communications | Somame
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="<?= BASE_URL ?>/assets/css/admin.css"
        rel="stylesheet"
    >

    <link
        href="<?= BASE_URL ?>/assets/css/communications.css"
        rel="stylesheet"
    >
</head>

<body>

<?php
$sidebar = match ($role) {
    'super_admin' =>
        __DIR__ . '/includes/super_admin_sidebar.php',
    'admin' =>
        __DIR__ . '/includes/admin_sidebar.php',
    'staff' =>
        __DIR__ . '/includes/staff_sidebar.php',
    'delivery_partner' =>
        __DIR__ . '/includes/delivery_sidebar.php',
    default =>
        __DIR__ . '/includes/customer_sidebar.php',
};

include $sidebar;
?>

<div
    class="communications-app"
    id="communicationsApp"
    data-base-url="<?= e(BASE_URL) ?>"
    data-portal="<?= e($portal) ?>"
    data-user-id="<?= $userId ?>"
    data-order-id="<?= $orderId ?>"
    data-csrf="<?= e($csrf) ?>"
    data-thread-id="<?= $selectedThreadId ?>"
    data-target-id="<?= $selectedTargetId ?>"
    data-call-id="<?= $selectedCallId ?>"
>

    <header class="communications-topbar">

        <div>
            <div class="communication-kicker">
                Somame Connect
            </div>

            <h1>
                <?= e($roleLabel) ?>
            </h1>

            <p>
                Order <?= e($deliveryContext['order_number']) ?> · available only while this delivery is in progress.
            </p>
        </div>

        <a
            href="<?= e($dashboardUrl) ?>"
            class="btn btn-outline-dark"
        >
            <i class="bi bi-arrow-left"></i>
            Dashboard
        </a>

    </header>

    <section class="communications-grid">

        <aside class="communications-contacts">

            <div class="contacts-header">

                <div>
                    <strong>Conversations</strong>
                    <small>
                        <?= count($contacts) ?>
                        delivery contact<?= count($contacts) === 1 ? '' : 's' ?>
                    </small>
                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-light"
                    id="refreshThreads"
                    title="Refresh conversations"
                >
                    <i class="bi bi-arrow-clockwise"></i>
                </button>

            </div>

            <div class="contact-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    id="contactSearch"
                    placeholder="Search people..."
                    autocomplete="off"
                >
            </div>

            <div
                class="thread-list"
                id="threadList"
            ></div>

            <div class="contacts-divider">
                Delivery contacts
            </div>

            <div
                class="contact-list"
                id="contactList"
            >
                <?php foreach ($contacts as $contact): ?>

                    <button
                        type="button"
                        class="contact-row <?= $selectedTargetId === (int) $contact['id'] ? 'active' : '' ?>"
                        data-contact-id="<?= (int) $contact['id'] ?>"
                        data-contact-name="<?= e($contact['name']) ?>"
                        data-contact-role="<?= e($contact['role']) ?>"
                    >
                        <span class="contact-avatar">
                            <?= e(
                                strtoupper(
                                    mb_substr(
                                        (string) $contact['name'],
                                        0,
                                        1
                                    )
                                )
                            ) ?>
                        </span>

                        <span class="contact-copy">
                            <strong>
                                <?= e($contact['name']) ?>
                            </strong>

                            <small>
                                <?= e(
                                    communicationRoleLabel(
                                        (string) $contact['role']
                                    )
                                ) ?>
                            </small>
                        </span>

                        <i class="bi bi-chevron-right"></i>
                    </button>

                <?php endforeach; ?>

                <?php if (!$contacts): ?>
                    <div class="empty-contacts">
                        <i class="bi bi-people"></i>
                        <strong>
                            No contacts available
                        </strong>
                        <span>
                            No delivery contacts are available for this order.
                        </span>
                    </div>
                <?php endif; ?>
            </div>

        </aside>

        <main class="communications-chat">

            <div class="chat-empty <?= $selectedContact ? 'd-none' : '' ?>" id="chatEmpty">
                <div class="chat-empty-icon">
                    <i class="bi bi-chat-heart"></i>
                </div>

                <h3>
                    Start a conversation
                </h3>

                <p>
                    Choose a delivery contact to send an update or start an audio call.
                </p>
            </div>

            <div class="chat-active <?= $selectedContact ? '' : 'd-none' ?>" id="chatActive">

                <header class="chat-person">

                    <div class="chat-person-main">

                        <span
                            class="contact-avatar large"
                            id="chatAvatar"
                        >
                            <?= $selectedContact
                                ? e(
                                    strtoupper(
                                        mb_substr(
                                            (string) $selectedContact['name'],
                                            0,
                                            1
                                        )
                                    )
                                )
                                : '?' ?>
                        </span>

                        <div>
                            <strong id="chatName">
                                <?= $selectedContact
                                    ? e($selectedContact['name'])
                                    : 'Conversation' ?>
                            </strong>

                            <small id="chatRole">
                                <?= $selectedContact
                                    ? e(
                                        communicationRoleLabel(
                                            (string) $selectedContact['role']
                                        )
                                    )
                                    : '' ?>
                            </small>
                        </div>

                    </div>

                    <div class="chat-actions">

                        <button
                            type="button"
                            class="btn btn-success"
                            id="startCall"
                            <?= $selectedContact ? '' : 'disabled' ?>
                        >
                            <i class="bi bi-telephone-fill"></i>
                            <span>Call</span>
                        </button>

                    </div>

                </header>

                <div
                    class="messages-pane"
                    id="messagesPane"
                    aria-live="polite"
                ></div>

                <form
                    class="message-composer"
                    id="messageForm"
                >

                    <textarea
                        id="messageInput"
                        maxlength="4000"
                        rows="1"
                        placeholder="Write a message..."
                        <?= $selectedContact ? '' : 'disabled' ?>
                    ></textarea>

                    <button
                        type="submit"
                        id="sendMessage"
                        <?= $selectedContact ? '' : 'disabled' ?>
                    >
                        <i class="bi bi-send-fill"></i>
                    </button>

                </form>

            </div>

        </main>

    </section>

</div>


<div
    class="call-overlay"
    id="callOverlay"
    aria-hidden="true"
>

    <div class="call-card">

        <div class="call-avatar" id="callAvatar">
            <i class="bi bi-person-fill"></i>
        </div>

        <div class="call-status" id="callStatus">
            Incoming call
        </div>

        <h3 id="callName">
            Somame user
        </h3>

        <div class="call-timer" id="callTimer">
            00:00
        </div>

        <div class="call-controls">

            <button
                type="button"
                class="call-control secondary d-none"
                id="muteCall"
                title="Mute microphone"
            >
                <i class="bi bi-mic-fill"></i>
            </button>

            <button
                type="button"
                class="call-control accept d-none"
                id="acceptCall"
                title="Answer call"
            >
                <i class="bi bi-telephone-fill"></i>
            </button>

            <button
                type="button"
                class="call-control danger"
                id="endCall"
                title="End or reject call"
            >
                <i class="bi bi-telephone-x-fill"></i>
            </button>

        </div>

        <div class="call-note">
            Audio calls stay inside Somame and use your browser microphone.
        </div>

    </div>

</div>

<audio
    id="remoteAudio"
    autoplay
></audio>

<script
    src="<?= BASE_URL ?>/assets/js/communications.js"
    defer
></script>

</body>
</html>
