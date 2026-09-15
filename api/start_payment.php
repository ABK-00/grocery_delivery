<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId = $_SESSION['user_id'];

$orderId = filter_input(
    INPUT_GET,
    'order_id',
    FILTER_VALIDATE_INT
);

if (!$orderId) {
    die('Invalid order.');
}

try {

    /*
     * Get order.
     */
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
        die('Order not found.');
    }

    if ($order['payment_status'] === 'paid') {
        header(
            "Location: ../order_success.php?order=" .
            urlencode($order['order_number']) .
            "&paid=1"
        );
        exit;
    }

    /*
     * Generate Paystack reference.
     */
    $reference =
        'GROC-' .
        date('YmdHis') .
        '-' .
        strtoupper(bin2hex(random_bytes(4)));

    /*
     * Amount must be sent in pesewas.
     */
    $amount =
        (int)round(
            (float)$order['total_amount'] * 100
        );

    /*
     * Initialize transaction.
     */
    $payload = [

        'email' => $order['email'],

        'amount' => $amount,

        'currency' => 'GHS',

        'reference' => $reference,

        'callback_url' =>
            PAYSTACK_CALLBACK_URL,

        'channels' => [
            'card',
            'mobile_money',
            'bank_transfer',
            'ussd'
        ],

        'metadata' => json_encode([
            'order_id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_id' => (int)$userId
        ])
    ];

    $ch = curl_init(
        PAYSTACK_BASE_URL .
        '/transaction/initialize'
    );

    curl_setopt_array($ch, [

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS =>
            json_encode($payload),

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' .
            PAYSTACK_SECRET_KEY,

            'Content-Type: application/json'
        ],

        CURLOPT_TIMEOUT => 30,

        CURLOPT_SSL_VERIFYPEER => true

    ]);

    $response = curl_exec($ch);

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        die(
            'Paystack connection failed: ' .
            htmlspecialchars($error)
        );
    }

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    $result =
        json_decode(
            $response,
            true
        );

    if (
        $httpCode < 200 ||
        $httpCode >= 300 ||
        empty($result['status'])
    ) {

        die(
            'Paystack error: ' .
            htmlspecialchars(
                $result['message'] ??
                'Unable to initialize payment.'
            )
        );
    }

    /*
     * Get the temporary payment record.
     */
    $paymentStmt = $conn->prepare("
        SELECT id
        FROM payments
        WHERE order_id = ?
        AND user_id = ?
        AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
    ");

    $paymentStmt->execute([
        $orderId,
        $userId
    ]);

    $payment = $paymentStmt->fetch();

    /*
     * Update payment reference.
     */
    if ($payment) {

        $updatePayment = $conn->prepare("
            UPDATE payments
            SET reference = ?
            WHERE id = ?
        ");

        $updatePayment->execute([
            $reference,
            $payment['id']
        ]);
    }

    /*
     * Update order reference.
     */
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

    /*
     * Redirect customer to Paystack.
     */
    header(
        "Location: " .
        $result['data']['authorization_url']
    );

    exit;

} catch (Throwable $e) {

    die(
        'Payment initialization error: ' .
        htmlspecialchars(
            $e->getMessage()
        )
    );
}
