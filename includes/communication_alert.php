<?php

if (
    basename($_SERVER['PHP_SELF'] ?? '')
    === 'communications.php'
) {
    return;
}

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/app.php';
}

if (!function_exists('portalKeyForRole')) {
    require_once __DIR__ . '/auth.php';
}

if (!function_exists('csrfToken')) {
    require_once __DIR__ . '/functions.php';
}

$communicationAlertRole =
    (string) ($_SESSION['role'] ?? '');

$communicationAlertPortal =
    portalKeyForRole(
        $communicationAlertRole
    );

if ($communicationAlertPortal === '') {
    return;
}

$communicationAlertCsrf =
    csrfToken('communications');
?>

<div
    id="somameIncomingCall"
    style="
        display:none;
        position:fixed;
        right:20px;
        bottom:20px;
        z-index:999999;
        width:min(360px,calc(100vw - 40px));
        background:#0f172a;
        color:#fff;
        border-radius:18px;
        padding:16px;
        box-shadow:0 25px 70px rgba(2,6,23,.35);
    "
>
    <div
        style="
            display:flex;
            align-items:center;
            gap:12px;
        "
    >
        <div
            style="
                width:48px;
                height:48px;
                border-radius:50%;
                background:#22c55e;
                color:#07120c;
                display:flex;
                align-items:center;
                justify-content:center;
                font-size:20px;
            "
        >
            <i class="bi bi-telephone-fill"></i>
        </div>

        <div style="flex:1;min-width:0">
            <div
                style="
                    color:#94a3b8;
                    font-size:12px;
                    text-transform:uppercase;
                    letter-spacing:.06em;
                "
            >
                Incoming Somame call
            </div>

            <div
                id="somameIncomingCaller"
                style="
                    font-weight:800;
                    margin-top:2px;
                    overflow:hidden;
                    text-overflow:ellipsis;
                    white-space:nowrap;
                "
            >
                Somame user
            </div>
        </div>

        <a
            id="somameIncomingOpen"
            href="#"
            style="
                background:#22c55e;
                color:#07120c;
                text-decoration:none;
                font-weight:700;
                border-radius:12px;
                padding:9px 12px;
            "
        >
            Open
        </a>
    </div>
</div>

<script>
(() => {
    const card =
        document.getElementById(
            'somameIncomingCall'
        );

    const caller =
        document.getElementById(
            'somameIncomingCaller'
        );

    const open =
        document.getElementById(
            'somameIncomingOpen'
        );

    if (!card || !caller || !open) {
        return;
    }

    const base =
        <?= json_encode(BASE_URL) ?>;

    const portal =
        <?= json_encode($communicationAlertPortal) ?>;

    let visibleCallId = 0;

    async function checkCall() {
        try {
            const url =
                base
                + '/api/communications.php'
                + '?portal='
                + encodeURIComponent(portal)
                + '&action=incoming_call';

            const response =
                await fetch(
                    url,
                    {
                        credentials:
                            'same-origin',
                        cache:
                            'no-store'
                    }
                );

            if (!response.ok) {
                return;
            }

            const data =
                await response.json();

            if (
                !data.success
                || !data.call
            ) {
                card.style.display =
                    'none';

                visibleCallId = 0;
                return;
            }

            if (
                visibleCallId
                !== Number(data.call.id)
            ) {
                visibleCallId =
                    Number(data.call.id);

                caller.textContent =
                    data.call.caller_name
                    || 'Somame user';

                open.href =
                    base
                    + '/communications.php'
                    + '?portal='
                    + encodeURIComponent(portal)
                    + '&order='
                    + encodeURIComponent(
                        data.call.order_id
                    )
                    + '&with='
                    + encodeURIComponent(
                        data.call.caller_id
                    )
                    + '&call='
                    + encodeURIComponent(
                        data.call.id
                    );
            }

            card.style.display =
                'block';
        } catch (error) {
            console.error(
                'Incoming call check failed',
                error
            );
        }
    }

    checkCall();

    setInterval(
        checkCall,
        2500
    );
})();
</script>
