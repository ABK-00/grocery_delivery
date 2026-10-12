<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('customer');

$userId = currentUserId();

$orderId = filter_input(
    INPUT_GET,
    'order_id',
    FILTER_VALIDATE_INT
);

if (!$userId || !$orderId) {
    http_response_code(400);
    exit('Invalid order.');
}

try {

    $stmt = $conn->prepare("
        SELECT
            o.id,
            o.order_number,
            o.total_amount,
            o.payment_status,
            u.email,
            u.name
        FROM orders o
        INNER JOIN users u
            ON u.id = o.user_id
        WHERE o.id = ?
          AND o.user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $orderId,
        $userId
    ]);

    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        exit('Order not found.');
    }

    if ($order['payment_status'] === 'paid') {
        header(
            'Location: '
            . BASE_URL
            . '/order_success.php?order='
            . urlencode($order['order_number'])
            . '&paid=1'
        );
        exit;
    }

    $amount =
        (int) round(
            (float) $order['total_amount'] * 100
        );

    if ($amount <= 0) {
        throw new RuntimeException('Invalid payment amount.');
    }

    $reference =
        'GROC-'
        . date('YmdHis')
        . '-'
        . strtoupper(bin2hex(random_bytes(4)));

    $conn->beginTransaction();

    $paymentStmt = $conn->prepare("
        SELECT id
        FROM payments
        WHERE order_id = ?
          AND user_id = ?
          AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $paymentStmt->execute([
        $orderId,
        $userId
    ]);

    $payment = $paymentStmt->fetch();

    if ($payment) {

        $updatePayment = $conn->prepare("
            UPDATE payments
            SET
                reference = ?,
                amount = ?,
                currency = 'GHS',
                gateway = 'paystack'
            WHERE id = ?
        ");

        $updatePayment->execute([
            $reference,
            $order['total_amount'],
            $payment['id']
        ]);

    } else {

        $insertPayment = $conn->prepare("
            INSERT INTO payments
            (
                order_id,
                user_id,
                reference,
                amount,
                currency,
                status,
                gateway
            )
            VALUES
            (?, ?, ?, ?, 'GHS', 'pending', 'paystack')
        ");

        $insertPayment->execute([
            $orderId,
            $userId,
            $reference,
            $order['total_amount']
        ]);
    }

    $updateOrder = $conn->prepare("
        UPDATE orders
        SET payment_reference = ?
        WHERE id = ?
          AND user_id = ?
    ");

    $updateOrder->execute([
        $reference,
        $orderId,
        $userId
    ]);

    $conn->commit();

    $payload = [
        'email' => $order['email'],
        'amount' => $amount,
        'currency' => 'GHS',
        'reference' => $reference,
        'callback_url' => PAYSTACK_CALLBACK_URL,
        'channels' => [
            'card',
            'mobile_money',
            'bank_transfer',
            'ussd'
        ],
        'metadata' => [
            'order_id' => (int) $order['id'],
            'order_number' => $order['order_number'],
            'customer_id' => (int) $userId,
            'customer_name' => $order['name']
        ]
    ];

    $ch = curl_init(
        PAYSTACK_BASE_URL
        . '/transaction/initialize'
    );

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
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
            'Paystack connection failed: ' . $error
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
        || empty($result['data']['authorization_url'])
    ) {
        throw new RuntimeException(
            $result['message']
                ?? 'Unable to initialize payment.'
        );
    }

    header(
        'Location: '
        . $result['data']['authorization_url']
    );

    exit;

} catch (Throwable $e) {

    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);

    exit(
        'Payment initialization error: '
        . htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}
