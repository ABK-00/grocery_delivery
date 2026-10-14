<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$companies = $conn->query("
    SELECT
        id,
        company_name,
        subscription_plan,
        trial_ends_at,
        subscription_ends_at
    FROM companies
    WHERE status = 'active'
")->fetchAll();

$reminders = 0;
$expiredNotices = 0;

foreach ($companies as $company) {
    $endsAt =
        $company['subscription_plan'] === 'trial'
            ? $company['trial_ends_at']
            : $company['subscription_ends_at'];

    if (!$endsAt) {
        continue;
    }

    $endTimestamp = strtotime((string) $endsAt);

    if ($endTimestamp === false) {
        continue;
    }

    $secondsRemaining = $endTimestamp - time();
    $daysRemaining =
        (int) ceil($secondsRemaining / 86400);

    if (
        $daysRemaining > 7
        || $daysRemaining < 0
    ) {
        if ($secondsRemaining >= 0) {
            continue;
        }
    }

    $admins = $conn->prepare("
        SELECT id
        FROM users
        WHERE company_id = ?
          AND role = 'admin'
          AND status = 'active'
    ");
    $admins->execute([
        $company['id']
    ]);

    foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        $adminId = (int) $adminId;

        if ($secondsRemaining < 0) {
            $title = 'Subscription expired';
            $message =
                $company['company_name']
                . ' no longer has active platform access. Renew the subscription to restore operations.';
            $type = 'subscription_expired';
        } else {
            $title = 'Subscription expires soon';
            $message =
                $company['company_name']
                . ' access expires in '
                . max(0, $daysRemaining)
                . ' day'
                . (max(0, $daysRemaining) === 1 ? '' : 's')
                . '.';
            $type = 'subscription_reminder';
        }

        $dedupe = $conn->prepare("
            SELECT id
            FROM notifications
            WHERE user_id = ?
              AND type = ?
              AND DATE(created_at) = CURDATE()
            LIMIT 1
        ");
        $dedupe->execute([
            $adminId,
            $type
        ]);

        if ($dedupe->fetchColumn()) {
            continue;
        }

        createNotification(
            $conn,
            $adminId,
            $type,
            $title,
            $message,
            BASE_URL . '/admin/subscription.php'
        );

        if ($secondsRemaining < 0) {
            $expiredNotices++;
        } else {
            $reminders++;
        }
    }
}

echo "Done. Reminders={$reminders}, ExpiredNotices={$expiredNotices}\n";
