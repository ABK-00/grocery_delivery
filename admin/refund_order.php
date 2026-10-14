<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');
requireCompanyAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/orders.php');
    exit;
}

$userId = currentUserId();
$companyId = currentCompanyId();
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$reason = trim((string) ($_POST['reason'] ?? 'Vendor cancellation'));
$csrf = (string) ($_POST['csrf_token'] ?? '');

if (
    !$orderId
    || empty($_SESSION['order_action_csrf'])
    || $csrf === ''
    || !hash_equals($_SESSION['order_action_csrf'], $csrf)
) {
    $_SESSION['order_action_error'] =
        'Your session expired. Refresh the page and try again.';

    header('Location: ' . BASE_URL . '/admin/orders.php');
    exit;
}

if (mb_strlen($reason) > 255) {
    $reason = mb_substr($reason, 0, 255);
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT
            o.id,
            o.order_number,
            o.user_id,
            o.status,
            o.payment_status,
            p.id AS payment_id,
            p.reference,
            p.amount,
            p.currency,
            p.status AS payment_record_status
        FROM orders o
        INNER JOIN payments p
            ON p.order_id = o.id
        WHERE o.id = ?
          AND o.company_id = ?
        ORDER BY p.id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $orderId,
        $companyId
    ]);

    $order = $stmt->fetch();

    if (!$order) {
        throw new RuntimeException('Order or payment record not found.');
    }

    if (
        $order['payment_status'] !== 'paid'
        || $order['payment_record_status'] !== 'paid'
    ) {
        throw new RuntimeException(
            'Only successfully paid orders can be refunded.'
        );
    }

    if (
        !in_array(
            $order['status'],
            ['pending', 'confirmed', 'preparing', 'ready'],
            true
        )
    ) {
        throw new RuntimeException(
            'This order can no longer be automatically refunded because delivery has already started or the order is complete.'
        );
    }

    $existingStmt = $conn->prepare("
        SELECT id, status
        FROM refunds
        WHERE payment_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $existingStmt->execute([
        $order['payment_id']
    ]);

    $existing = $existingStmt->fetch();

    if ($existing) {
        $conn->commit();

        $_SESSION['order_action_error'] =
            'A refund already exists for this payment with status: '
            . $existing['status']
            . '.';

        header(
            'Location: '
            . BASE_URL
            . '/admin/order_details.php?id='
            . (int) $orderId
        );
        exit;
    }

    $insert = $conn->prepare("
        INSERT INTO refunds (
            order_id,
            payment_id,
            requested_by,
            amount,
            currency,
            status,
            reason
        )
        VALUES (?, ?, ?, ?, ?, 'pending', ?)
    ");

    $insert->execute([
        $orderId,
        $order['payment_id'],
        $userId,
        $order['amount'],
        $order['currency'],
        $reason
    ]);

    $refundId = (int) $conn->lastInsertId();

    writeAuditLog(
        $conn,
        $userId,
        $companyId,
        'refund.requested',
        'order',
        (string) $orderId,
        [
            'refund_id' => $refundId,
            'reference' => $order['reference'],
            'amount' => (float) $order['amount'],
            'reason' => $reason
        ]
    );

    $conn->commit();

    $payload = [
        'transaction' => $order['reference'],
        'customer_note' => $reason,
        'merchant_note' =>
            'Refund for order #'
            . $order['order_number']
            . ' requested by vendor administrator'
    ];

    $ch = curl_init(PAYSTACK_BASE_URL . '/refund');

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
            'Refund request connection failed: ' . $error
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
        $message = is_array($result)
            ? ($result['message'] ?? 'Unable to initiate refund.')
            : 'Unable to initiate refund.';

        $fail = $conn->prepare("
            UPDATE refunds
            SET
                status = 'failed',
                gateway_response = ?
            WHERE id = ?
              AND status = 'pending'
        ");
        $fail->execute([
            $response ?: null,
            $refundId
        ]);

        throw new RuntimeException($message);
    }

    $data = $result['data'];

    $gatewayStatus =
        strtolower((string) ($data['status'] ?? 'pending'));

    $localStatus = in_array(
        $gatewayStatus,
        ['pending', 'processing', 'needs-attention', 'processed', 'failed'],
        true
    )
        ? str_replace('-', '_', $gatewayStatus)
        : 'pending';

    $gatewayRefundId =
        isset($data['id'])
            ? (string) $data['id']
            : null;

    $refundReference =
        (string) (
            $data['refund_reference']
            ?? $data['reference']
            ?? ''
        );

    $conn->beginTransaction();

    $update = $conn->prepare("
        UPDATE refunds
        SET
            gateway_refund_id = ?,
            refund_reference = ?,
            status = ?,
            gateway_response = ?
        WHERE id = ?
    ");

    $update->execute([
        $gatewayRefundId,
        $refundReference !== ''
            ? $refundReference
            : null,
        $localStatus,
        json_encode($data),
        $refundId
    ]);

    $cancelOrder = $conn->prepare("
        UPDATE orders
        SET status = 'cancelled'
        WHERE id = ?
          AND company_id = ?
          AND status IN (
              'pending',
              'confirmed',
              'preparing',
              'ready'
          )
    ");

    $cancelOrder->execute([
        $orderId,
        $companyId
    ]);

    $deliveryStmt = $conn->prepare("
        SELECT id, delivery_partner_id
        FROM deliveries
        WHERE order_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $deliveryStmt->execute([$orderId]);
    $delivery = $deliveryStmt->fetch();

    if ($delivery) {
        $conn->prepare("
            UPDATE deliveries
            SET status = 'cancelled'
            WHERE id = ?
        ")->execute([
            $delivery['id']
        ]);

        if (!empty($delivery['delivery_partner_id'])) {
            $conn->prepare("
                UPDATE delivery_partners
                SET status = 'available'
                WHERE id = ?
            ")->execute([
                $delivery['delivery_partner_id']
            ]);
        }
    }

    createNotification(
        $conn,
        (int) $order['user_id'],
        'refund',
        'Refund started',
        'A refund has been initiated for order #'
            . $order['order_number']
            . '. You will be notified when it is completed.',
        BASE_URL . '/order_details.php?id=' . (int) $orderId
    );

    writeAuditLog(
        $conn,
        $userId,
        $companyId,
        'refund.gateway_accepted',
        'refund',
        (string) $refundId,
        [
            'status' => $localStatus,
            'gateway_refund_id' => $gatewayRefundId
        ]
    );

    $conn->commit();

    $_SESSION['order_action_success'] =
        'Refund initiated successfully.';

    header(
        'Location: '
        . BASE_URL
        . '/admin/order_details.php?id='
        . (int) $orderId
    );
    exit;

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $_SESSION['order_action_error'] =
        $e->getMessage();

    header(
        'Location: '
        . BASE_URL
        . '/admin/order_details.php?id='
        . (int) $orderId
    );
    exit;
}
