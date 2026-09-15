```php
<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Content-Type: application/json');

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

if (!$orderId) {
    echo json_encode([
        'status' => false,
        'message' => 'Invalid order.'
    ]);
    exit;
}

try {

    // Get order belonging to current customer
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

    $stmt->execute([$orderId]);

    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode([
            'status' => false,
            'message' => 'Order not found.'
        ]);
        exit;
    }

    if ($order['payment_status'] === 'paid') {
        echo json_encode([
            'status' => false,
            'message' => 'This order has already been paid.'
        ]);
        exit;
    }

    $amount = (float)$order['total_amount'];

    if ($amount <= 0) {
        echo json_encode([
            'status' => false,
            'message' => 'Invalid payment amount.'
        ]);
        exit;
    }

    /*
     * Paystack requires amount in the currency subunit.
     * GHS 20.00 becomes 2000 pesewas.
     */
    $amountInPesewas = (int)round($amount * 100);

    /*
     * Generate unique reference.
     */
    $reference = 'GROC-' .
        date('YmdHis') . '-' .
        strtoupper(bin2hex(random_bytes(4)));

    /*
     * Store payment record before sending
     * customer to Paystack.
     */
    $insert = $conn->prepare("
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
        VALUES (?, ?, ?, ?, 'GHS', 'pending', 'paystack')
    ");

    $insert->execute([
        $order['id'],
        $userId,
        $reference,
        $amount
    ]);

    /*
     * Prepare Paystack request.
     */
    $payload = [
        'email' => $order['email'],
        'amount' => $amountInPesewas,
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
            'order_id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_id' => (int)$userId,
            'customer_name' => $order['name']
        ]
    ];

    $ch = curl_init(
        PAYSTACK_BASE_URL . '/transaction/initialize'
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

        $curlError = curl_error($ch);

        curl_close($ch);

        echo json_encode([
            'status' => false,
            'message' => 'Could not connect to Paystack: ' . $curlError
        ]);

        exit;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $result = json_decode($response, true);

    if (
        $httpCode >= 200 &&
        $httpCode < 300 &&
        isset($result['status']) &&
        $result['status'] === true
    ) {

        /*
         * Save Paystack reference on order.
         */
        $update = $conn->prepare("
            UPDATE orders
            SET payment_reference = ?
            WHERE id = ?
            AND user_id = ?
        ");

        $update->execute([
            $reference,
            $order['id'],
            $userId
        ]);

        /*
         * Return checkout URL.
         */
        echo json_encode([
            'status' => true,
            'message' => 'Payment initialized successfully.',
            'authorization_url' =>
                $result['data']['authorization_url'],
            'reference' =>
                $result['data']['reference']
        ]);

        exit;
    }

    echo json_encode([
        'status' => false,
        'message' =>
            $result['message'] ??
            'Unable to initialize payment.'
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'status' => false,
        'message' => 'Payment error: ' . $e->getMessage()
    ]);
}
```
