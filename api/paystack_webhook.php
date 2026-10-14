<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../includes/functions.php';

$rawBody = file_get_contents('php://input') ?: '';
$signature = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');

$expected = hash_hmac(
    'sha512',
    $rawBody,
    PAYSTACK_SECRET_KEY
);

if (
    $signature === ''
    || !hash_equals($expected, $signature)
) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($rawBody, true);

if (!is_array($event)) {
    http_response_code(400);
    exit('Invalid payload');
}

$eventName = (string) ($event['event'] ?? '');
$data = is_array($event['data'] ?? null)
    ? $event['data']
    : [];

http_response_code(200);

try {
    if ($eventName === 'charge.success') {
        $reference = trim((string) ($data['reference'] ?? ''));

        if ($reference === '') {
            exit('ok');
        }

        if (str_starts_with($reference, 'SUB-')) {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT *
                FROM subscription_payments
                WHERE reference = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$reference]);
            $payment = $stmt->fetch();

            if (!$payment || $payment['status'] === 'paid') {
                $conn->commit();
                exit('ok');
            }

            $expectedAmount =
                (int) round((float) $payment['amount'] * 100);

            if (
                (int) ($data['amount'] ?? 0) !== $expectedAmount
                || strtoupper((string) ($data['currency'] ?? '')) !== 'GHS'
            ) {
                $stmt = $conn->prepare("
                    UPDATE subscription_payments
                    SET
                        status = 'failed',
                        gateway_response = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    json_encode($data),
                    $payment['id']
                ]);

                writeAuditLog(
                    $conn,
                    (int) $payment['user_id'],
                    (int) $payment['company_id'],
                    'subscription.webhook_amount_mismatch',
                    'subscription_payment',
                    (string) $payment['id'],
                    ['reference' => $reference]
                );

                $conn->commit();
                exit('ok');
            }

            $months = match ($payment['plan']) {
                'monthly' => 1,
                'quarterly' => 3,
                'yearly' => 12,
                default => 0
            };

            if ($months <= 0) {
                throw new RuntimeException(
                    'Invalid subscription plan.'
                );
            }

            $companyStmt = $conn->prepare("
                SELECT subscription_ends_at
                FROM companies
                WHERE id = ?
                FOR UPDATE
            ");
            $companyStmt->execute([
                $payment['company_id']
            ]);
            $currentEnd = $companyStmt->fetchColumn();

            $base = new DateTimeImmutable('now');

            if (
                $currentEnd
                && strtotime((string) $currentEnd) > time()
            ) {
                $base = new DateTimeImmutable(
                    (string) $currentEnd
                );
            }

            $newEnd =
                $base->modify('+' . $months . ' months');

            $paidAt = !empty($data['paid_at'])
                ? date(
                    'Y-m-d H:i:s',
                    strtotime((string) $data['paid_at'])
                )
                : date('Y-m-d H:i:s');

            $conn->prepare("
                UPDATE subscription_payments
                SET
                    status = 'paid',
                    gateway_response = ?,
                    paid_at = ?
                WHERE id = ?
            ")->execute([
                json_encode($data),
                $paidAt,
                $payment['id']
            ]);

            $conn->prepare("
                UPDATE companies
                SET
                    subscription_plan = ?,
                    subscription_starts_at =
                        COALESCE(subscription_starts_at, NOW()),
                    subscription_ends_at = ?,
                    status = CASE
                        WHEN status = 'suspended'
                        THEN 'active'
                        ELSE status
                    END
                WHERE id = ?
            ")->execute([
                $payment['plan'],
                $newEnd->format('Y-m-d H:i:s'),
                $payment['company_id']
            ]);

            createNotification(
                $conn,
                (int) $payment['user_id'],
                'subscription',
                'Subscription activated',
                ucfirst($payment['plan'])
                    . ' subscription is active until '
                    . $newEnd->format('d M Y')
                    . '.',
                BASE_URL . '/admin/subscription.php'
            );

            writeAuditLog(
                $conn,
                (int) $payment['user_id'],
                (int) $payment['company_id'],
                'subscription.webhook_confirmed',
                'subscription_payment',
                (string) $payment['id'],
                ['reference' => $reference]
            );

            $conn->commit();
            exit('ok');
        }

        $conn->beginTransaction();

        $stmt = $conn->prepare("
            SELECT
                p.id AS payment_id,
                p.order_id,
                p.user_id,
                p.amount,
                p.currency,
                p.status AS payment_status,
                o.company_id,
                o.order_number,
                o.status AS order_status,
                o.payment_status AS order_payment_status,
                o.stock_released_at
            FROM payments p
            INNER JOIN orders o
                ON o.id = p.order_id
            WHERE p.reference = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([$reference]);
        $payment = $stmt->fetch();

        if (!$payment) {
            $conn->commit();
            exit('ok');
        }

        if (
            $payment['payment_status'] === 'paid'
            && $payment['order_payment_status'] === 'paid'
        ) {
            $conn->commit();
            exit('ok');
        }

        $expectedAmount =
            (int) round((float) $payment['amount'] * 100);

        if (
            (int) ($data['amount'] ?? 0) !== $expectedAmount
            || strtoupper((string) ($data['currency'] ?? '')) !== 'GHS'
        ) {
            $conn->prepare("
                UPDATE payments
                SET
                    status = 'failed',
                    gateway_response = ?
                WHERE id = ?
                  AND status <> 'paid'
            ")->execute([
                json_encode($data),
                $payment['payment_id']
            ]);

            writeAuditLog(
                $conn,
                null,
                (int) $payment['company_id'],
                'payment.webhook_amount_mismatch',
                'order',
                (string) $payment['order_id'],
                ['reference' => $reference]
            );

            $conn->commit();
            exit('ok');
        }

        $canFulfil = true;

        if ($payment['stock_released_at'] !== null) {
            $itemsStmt = $conn->prepare("
                SELECT
                    oi.product_id,
                    oi.quantity,
                    p.stock
                FROM order_items oi
                INNER JOIN products p
                    ON p.id = oi.product_id
                WHERE oi.order_id = ?
                  AND p.company_id = ?
                FOR UPDATE
            ");
            $itemsStmt->execute([
                $payment['order_id'],
                $payment['company_id']
            ]);
            $items = $itemsStmt->fetchAll();

            foreach ($items as $item) {
                if (
                    (float) $item['stock']
                    < (float) $item['quantity']
                ) {
                    $canFulfil = false;
                    break;
                }
            }

            if ($canFulfil) {
                $reserve = $conn->prepare("
                    UPDATE products
                    SET stock = stock - ?
                    WHERE id = ?
                      AND company_id = ?
                      AND stock >= ?
                ");

                foreach ($items as $item) {
                    $reserve->execute([
                        $item['quantity'],
                        $item['product_id'],
                        $payment['company_id'],
                        $item['quantity']
                    ]);

                    if ($reserve->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Unable to re-reserve stock.'
                        );
                    }
                }

                $conn->prepare("
                    UPDATE orders
                    SET stock_released_at = NULL
                    WHERE id = ?
                ")->execute([
                    $payment['order_id']
                ]);
            }
        }

        $paidAt = !empty($data['paid_at'])
            ? date(
                'Y-m-d H:i:s',
                strtotime((string) $data['paid_at'])
            )
            : date('Y-m-d H:i:s');

        $conn->prepare("
            UPDATE payments
            SET
                status = 'paid',
                payment_method = ?,
                gateway_response = ?,
                paid_at = ?
            WHERE id = ?
        ")->execute([
            $data['channel'] ?? null,
            json_encode($data),
            $paidAt,
            $payment['payment_id']
        ]);

        $conn->prepare("
            UPDATE orders
            SET
                payment_status = 'paid',
                status = ?
            WHERE id = ?
        ")->execute([
            $canFulfil
                ? 'confirmed'
                : 'cancelled',
            $payment['order_id']
        ]);

        if ($canFulfil) {
            createNotification(
                $conn,
                (int) $payment['user_id'],
                'payment',
                'Payment confirmed',
                'Payment for order #'
                    . $payment['order_number']
                    . ' was confirmed.',
                BASE_URL
                    . '/order_details.php?id='
                    . (int) $payment['order_id']
            );

            notifyCompanyRoles(
                $conn,
                (int) $payment['company_id'],
                ['admin', 'staff'],
                'order',
                'New paid order',
                'Order #'
                    . $payment['order_number']
                    . ' is ready for processing.',
                BASE_URL
                    . '/admin/order_details.php?id='
                    . (int) $payment['order_id']
            );
        } else {
            createNotification(
                $conn,
                (int) $payment['user_id'],
                'payment',
                'Payment received after order expiry',
                'We received payment for order #'
                    . $payment['order_number']
                    . ', but stock is no longer available. Support will arrange a refund.',
                BASE_URL
                    . '/order_details.php?id='
                    . (int) $payment['order_id']
            );

            notifyCompanyRoles(
                $conn,
                (int) $payment['company_id'],
                ['admin'],
                'payment',
                'Refund required',
                'Order #'
                    . $payment['order_number']
                    . ' was paid after expiry and cannot be fulfilled. Start a refund.',
                BASE_URL
                    . '/admin/order_details.php?id='
                    . (int) $payment['order_id']
            );
        }

        writeAuditLog(
            $conn,
            null,
            (int) $payment['company_id'],
            'payment.webhook_confirmed',
            'order',
            (string) $payment['order_id'],
            [
                'reference' => $reference,
                'can_fulfil' => $canFulfil
            ]
        );

        $conn->commit();
        exit('ok');
    }

    if (
        in_array(
            $eventName,
            [
                'refund.pending',
                'refund.processing',
                'refund.needs-attention',
                'refund.processed',
                'refund.failed'
            ],
            true
        )
    ) {
        $transactionReference = trim(
            (string) (
                $data['transaction_reference']
                ?? $data['transaction']['reference']
                ?? ''
            )
        );

        if ($transactionReference === '') {
            exit('ok');
        }

        $statusMap = [
            'refund.pending' => 'pending',
            'refund.processing' => 'processing',
            'refund.needs-attention' => 'needs_attention',
            'refund.processed' => 'processed',
            'refund.failed' => 'failed'
        ];

        $newStatus = $statusMap[$eventName];

        $conn->beginTransaction();

        $stmt = $conn->prepare("
            SELECT
                r.id AS refund_id,
                r.status AS refund_status,
                r.order_id,
                r.payment_id,
                p.user_id,
                o.company_id,
                o.order_number,
                o.status AS order_status,
                o.payment_status
            FROM refunds r
            INNER JOIN payments p
                ON p.id = r.payment_id
            INNER JOIN orders o
                ON o.id = r.order_id
            WHERE p.reference = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            $transactionReference
        ]);
        $refund = $stmt->fetch();

        if (!$refund) {
            $conn->commit();
            exit('ok');
        }

        if (
            $refund['refund_status'] === 'processed'
            && $newStatus === 'processed'
        ) {
            $conn->commit();
            exit('ok');
        }

        $refundReference =
            (string) ($data['refund_reference'] ?? '');

        $gatewayRefundId =
            isset($data['id'])
                ? (string) $data['id']
                : null;

        $conn->prepare("
            UPDATE refunds
            SET
                status = ?,
                gateway_refund_id =
                    COALESCE(?, gateway_refund_id),
                refund_reference =
                    COALESCE(NULLIF(?, ''), refund_reference),
                gateway_response = ?,
                processed_at = CASE
                    WHEN ? = 'processed'
                    THEN COALESCE(processed_at, NOW())
                    ELSE processed_at
                END
            WHERE id = ?
        ")->execute([
            $newStatus,
            $gatewayRefundId,
            $refundReference,
            json_encode($data),
            $newStatus,
            $refund['refund_id']
        ]);

        if ($newStatus === 'processed') {
            $conn->prepare("
                UPDATE payments
                SET status = 'refunded'
                WHERE id = ?
            ")->execute([
                $refund['payment_id']
            ]);

            $conn->prepare("
                UPDATE orders
                SET
                    payment_status = 'refunded',
                    status = 'cancelled'
                WHERE id = ?
            ")->execute([
                $refund['order_id']
            ]);

            releaseOrderStock(
                $conn,
                (int) $refund['order_id']
            );

            createNotification(
                $conn,
                (int) $refund['user_id'],
                'refund',
                'Refund completed',
                'The refund for order #'
                    . $refund['order_number']
                    . ' has been processed.',
                BASE_URL
                    . '/order_details.php?id='
                    . (int) $refund['order_id']
            );
        } elseif ($newStatus === 'failed') {
            $conn->prepare("
                UPDATE orders
                SET status = CASE
                    WHEN status = 'cancelled'
                    THEN 'confirmed'
                    ELSE status
                END
                WHERE id = ?
                  AND payment_status = 'paid'
            ")->execute([
                $refund['order_id']
            ]);

            createNotification(
                $conn,
                (int) $refund['user_id'],
                'refund',
                'Refund needs attention',
                'The refund for order #'
                    . $refund['order_number']
                    . ' could not be completed. Support has been notified.',
                BASE_URL
                    . '/support.php'
            );

            notifyCompanyRoles(
                $conn,
                (int) $refund['company_id'],
                ['admin'],
                'refund',
                'Refund failed',
                'Refund for order #'
                    . $refund['order_number']
                    . ' failed and needs attention.',
                BASE_URL
                    . '/admin/order_details.php?id='
                    . (int) $refund['order_id']
            );
        }

        writeAuditLog(
            $conn,
            null,
            (int) $refund['company_id'],
            'refund.webhook_' . $newStatus,
            'refund',
            (string) $refund['refund_id'],
            ['transaction_reference' => $transactionReference]
        );

        $conn->commit();
        exit('ok');
    }

    exit('ok');

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log(
        'Paystack webhook processing error: '
        . $e->getMessage()
    );

    exit('ok');
}
