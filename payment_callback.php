<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/paystack.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('customer');

$userId = currentUserId();

$reference = trim(
    (string) ($_GET['reference'] ?? '')
);

if (!$userId || $reference === '') {
    http_response_code(400);
    exit('Payment reference is missing.');
}

try {

    $url =
        PAYSTACK_BASE_URL
        . '/transaction/verify/'
        . rawurlencode($reference);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException(
            'Payment verification failed: ' . $error
        );
    }

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    $result = json_decode(
        $response,
        true
    );

    if (
        $httpCode < 200
        || $httpCode >= 300
        || empty($result['status'])
        || empty($result['data'])
    ) {
        throw new RuntimeException(
            $result['message']
                ?? 'Unable to verify payment.'
        );
    }

    $transaction = $result['data'];

    if (
        ($transaction['reference'] ?? '') !== $reference
    ) {
        throw new RuntimeException(
            'Payment reference verification failed.'
        );
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT
            p.id AS payment_id,
            p.order_id,
            p.amount,
            p.currency,
            p.status AS payment_record_status,
            p.created_at AS payment_created_at,
            o.order_number,
            o.company_id,
            o.total_amount,
            o.payment_status,
            o.stock_released_at
        FROM payments p
        INNER JOIN orders o
            ON o.id = p.order_id
        WHERE p.reference = ?
          AND p.user_id = ?
          AND o.user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $reference,
        $userId,
        $userId
    ]);

    $payment = $stmt->fetch();

    if (!$payment) {
        $conn->rollBack();
        http_response_code(404);
        exit('Payment record not found.');
    }

    /*
     * Idempotent callback: if this payment/order was already finalized as
     * paid, repeated browser redirects do not perform any side effects.
     */
    if (
        $payment['payment_record_status'] === 'paid'
        && $payment['payment_status'] === 'paid'
    ) {
        $conn->commit();

        header(
            'Location: '
            . BASE_URL
            . '/order_success.php?order='
            . urlencode($payment['order_number'])
            . '&paid=1'
        );
        exit;
    }

    $expectedAmount =
        (int) round(
            (float) $payment['amount'] * 100
        );

    $paidAmount =
        (int) ($transaction['amount'] ?? 0);

    $gatewayCurrency =
        strtoupper(
            (string) ($transaction['currency'] ?? '')
        );

    if (
        $expectedAmount !== $paidAmount
        || $gatewayCurrency !== 'GHS'
    ) {
        releaseOrderStock(
            $conn,
            (int) $payment['order_id']
        );

        $update = $conn->prepare("
            UPDATE payments
            SET
                status = 'failed',
                gateway_response = ?
            WHERE id = ?
              AND status <> 'paid'
        ");

        $update->execute([
            json_encode($transaction),
            $payment['payment_id']
        ]);

        $updateOrder = $conn->prepare("
            UPDATE orders
            SET
                payment_status = 'failed',
                status = CASE
                    WHEN status = 'pending'
                    THEN 'cancelled'
                    ELSE status
                END
            WHERE id = ?
              AND user_id = ?
              AND payment_status <> 'paid'
        ");

        $updateOrder->execute([
            $payment['order_id'],
            $userId
        ]);

        $conn->commit();

        throw new RuntimeException(
            'Payment amount or currency verification failed.'
        );
    }

    if (
        ($transaction['status'] ?? '') === 'success'
    ) {

        /*
         * A terminal failed payment releases stock. We do not silently
         * convert such an order back to paid from a late callback because
         * that could oversell inventory after it has been returned.
         */
        if ($payment['stock_released_at'] !== null) {
            $conn->rollBack();

            throw new RuntimeException(
                'This order was already closed after a failed payment. Please contact support with reference: '
                . $reference
            );
        }

        $paidAt = !empty($transaction['paid_at'])
                ? date(
                    'Y-m-d H:i:s',
                    strtotime($transaction['paid_at'])
                )
                : date('Y-m-d H:i:s');

            $updatePayment = $conn->prepare("
                UPDATE payments
                SET
                    status = 'paid',
                    payment_method = ?,
                    gateway_response = ?,
                    paid_at = ?
                WHERE id = ?
            ");

            $updatePayment->execute([
                $transaction['channel'] ?? null,
                json_encode($transaction),
                $paidAt,
                $payment['payment_id']
            ]);

            $updateOrder = $conn->prepare("
                UPDATE orders
                SET
                    payment_status = 'paid',
                    status = CASE
                        WHEN status = 'pending'
                        THEN 'confirmed'
                        ELSE status
                    END
                WHERE id = ?
                  AND user_id = ?
            ");

            $updateOrder->execute([
                $payment['order_id'],
                $userId
            ]);

        createNotification(
            $conn,
            (int) $userId,
            'payment',
            'Payment confirmed',
            'Payment for order #' . $payment['order_number'] . ' was confirmed successfully.',
            BASE_URL . '/order_details.php?id=' . (int) $payment['order_id']
        );

        writeAuditLog(
            $conn,
            (int) $userId,
            (int) $payment['company_id'],
            'payment.confirmed',
            'order',
            (int) $payment['order_id'],
            [
                'reference' => $reference,
                'amount' => (float) $payment['amount'],
                'gateway' => 'paystack'
            ]
        );

        $conn->commit();

        header(
            'Location: '
            . BASE_URL
            . '/order_success.php?order='
            . urlencode($payment['order_number'])
            . '&paid=1'
        );

        exit;
    }

    $gatewayStatus =
        (string) ($transaction['status'] ?? 'failed');

    $paymentCreatedAt =
        strtotime((string) $payment['payment_created_at']);

    $paymentExpired =
        $paymentCreatedAt !== false
        && (time() - $paymentCreatedAt) >= PAYMENT_SESSION_TIMEOUT_SECONDS;

    $paymentStatus = in_array(
        $gatewayStatus,
        ['pending', 'ongoing', 'processing'],
        true
    )
        ? 'pending'
        : 'failed';

    if ($paymentExpired && $paymentStatus !== 'paid') {
        $paymentStatus = 'failed';
    }

    if ($paymentStatus === 'failed') {
        releaseOrderStock(
            $conn,
            (int) $payment['order_id']
        );
    }

    $update = $conn->prepare("
        UPDATE payments
        SET
            status = ?,
            gateway_response = ?
        WHERE id = ?
          AND status <> 'paid'
    ");

    $update->execute([
        $paymentStatus,
        json_encode($transaction),
        $payment['payment_id']
    ]);

    if ($paymentStatus === 'failed') {
        $updateOrder = $conn->prepare("
            UPDATE orders
            SET payment_status = 'failed'
            WHERE id = ?
              AND user_id = ?
              AND payment_status <> 'paid'
        ");

        $updateOrder->execute([
            $payment['order_id'],
            $userId
        ]);

        createNotification(
            $conn,
            (int) $userId,
            'payment',
            'Payment unsuccessful',
            'Payment for order #' . $payment['order_number'] . ' was not completed. Reserved stock has been released.',
            BASE_URL . '/order_details.php?id=' . (int) $payment['order_id']
        );

        writeAuditLog(
            $conn,
            (int) $userId,
            (int) $payment['company_id'],
            'payment.failed',
            'order',
            (int) $payment['order_id'],
            [
                'reference' => $reference,
                'gateway_status' => $gatewayStatus
            ]
        );
    }

    $conn->commit();

    header(
        'Location: '
        . BASE_URL
        . '/order_details.php?id='
        . urlencode($payment['order_id'])
        . '&payment='
        . urlencode($paymentStatus)
    );

    exit;

} catch (Throwable $e) {

    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);

    exit(
        'Payment verification error: '
        . htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}
