<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/paystack.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$userId = $_SESSION['user_id'];

$reference = $_GET['reference'] ?? '';

if (!$reference) {
    die('Payment reference is missing.');
}

try {

    /*
     * Verify transaction directly with Paystack.
     */
    $url =
        PAYSTACK_BASE_URL .
        '/transaction/verify/' .
        rawurlencode($reference);

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

        die('Payment verification failed: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $result = json_decode($response, true);

    if (
        $httpCode < 200 ||
        $httpCode >= 300 ||
        empty($result['status'])
    ) {
        die('Unable to verify payment.');
    }

    $transaction = $result['data'];

    /*
     * Find payment record.
     */
    $stmt = $conn->prepare("
        SELECT
            p.*,
            o.order_number,
            o.total_amount,
            o.payment_status
        FROM payments p
        INNER JOIN orders o
            ON o.id = p.order_id
        WHERE p.reference = ?
        AND p.user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $reference,
        $userId
    ]);

    $payment = $stmt->fetch();

    if (!$payment) {
        die('Payment record not found.');
    }

    /*
     * Prevent amount manipulation.
     */
    $expectedAmount =
        (int)round((float)$payment['amount'] * 100);

    $paidAmount =
        (int)$transaction['amount'];

    if ($expectedAmount !== $paidAmount) {

        $update = $conn->prepare("
            UPDATE payments
            SET
                status = 'failed',
                gateway_response = ?
            WHERE reference = ?
        ");

        $update->execute([
            json_encode($transaction),
            $reference
        ]);

        die('Payment amount verification failed.');
    }

    /*
     * Successful payment.
     */
    if ($transaction['status'] === 'success') {

        $conn->beginTransaction();

        try {

            /*
             * Update payment.
             */
            $updatePayment = $conn->prepare("
                UPDATE payments
                SET
                    status = 'paid',
                    payment_method = ?,
                    gateway_response = ?,
                    paid_at = ?
                WHERE reference = ?
                AND status <> 'paid'
            ");

            $paidAt = !empty($transaction['paid_at'])
                ? date(
                    'Y-m-d H:i:s',
                    strtotime($transaction['paid_at'])
                )
                : date('Y-m-d H:i:s');

            $updatePayment->execute([
                $transaction['channel'] ?? null,
                json_encode($transaction),
                $paidAt,
                $reference
            ]);

            /*
             * Update order.
             */
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

            $conn->commit();

            header(
                'Location: order_success.php?order=' .
                urlencode($payment['order_number']) .
                '&paid=1'
            );

            exit;

        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            die(
                'Payment was successful, but the order update failed. ' .
                'Reference: ' .
                htmlspecialchars($reference)
            );
        }
    }

    /*
     * Payment failed / abandoned / pending.
     */
    $gatewayStatus =
        $transaction['status'] ?? 'failed';

    $paymentStatus = 'failed';

    if (
        in_array(
            $gatewayStatus,
            ['pending', 'ongoing', 'processing'],
            true
        )
    ) {
        $paymentStatus = 'pending';
    }

    $update = $conn->prepare("
        UPDATE payments
        SET
            status = ?,
            gateway_response = ?
        WHERE reference = ?
    ");

    $update->execute([
        $paymentStatus,
        json_encode($transaction),
        $reference
    ]);

    header(
        'Location: order_details.php?id=' .
        urlencode($payment['order_id']) .
        '&payment=failed'
    );

    exit;

} catch (Throwable $e) {

    die(
        'Payment verification error: ' .
        htmlspecialchars($e->getMessage())
    );
}

