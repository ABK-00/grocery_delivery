<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('customer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/orders.php');
}

$userId = currentUserId();
$orderId = filter_input(
    INPUT_POST,
    'order_id',
    FILTER_VALIDATE_INT
);

$action = trim(
    (string) ($_POST['action'] ?? '')
);

$reason = trim(
    (string) ($_POST['reason'] ?? '')
);

$csrf = (string) ($_POST['csrf_token'] ?? '');

if (
    !$orderId
    || empty($_SESSION['customer_order_action_csrf'])
    || $csrf === ''
    || !hash_equals(
        (string) $_SESSION['customer_order_action_csrf'],
        $csrf
    )
) {
    $_SESSION['customer_order_action_error'] =
        'Your session expired. Refresh the page and try again.';

    redirect(
        BASE_URL
        . '/order_details.php?id='
        . (int) $orderId
    );
}

if (mb_strlen($reason) > 255) {
    $reason = mb_substr(
        $reason,
        0,
        255
    );
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT
            o.id,
            o.company_id,
            o.user_id,
            o.order_number,
            o.status,
            o.payment_status,
            o.stock_released_at,
            p.id AS payment_id,
            p.reference,
            p.amount,
            p.currency,
            p.status AS payment_record_status
        FROM orders o
        LEFT JOIN payments p
            ON p.id = (
                SELECT p2.id
                FROM payments p2
                WHERE p2.order_id = o.id
                ORDER BY p2.id DESC
                LIMIT 1
            )
        WHERE o.id = ?
          AND o.user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $orderId,
        $userId
    ]);

    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new RuntimeException(
            'Order not found.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CANCEL UNPAID ORDER
    |--------------------------------------------------------------------------
    */
    if ($action === 'cancel') {
        if ($order['payment_status'] === 'paid') {
            throw new RuntimeException(
                'Paid orders must use the refund request option.'
            );
        }

        if ($order['status'] !== 'pending') {
            throw new RuntimeException(
                'This order can no longer be cancelled.'
            );
        }

        releaseOrderStock(
            $conn,
            (int) $order['id']
        );

        $conn->prepare("
            UPDATE orders
            SET
                status = 'cancelled',
                payment_status = CASE
                    WHEN payment_status = 'pending'
                    THEN 'failed'
                    ELSE payment_status
                END
            WHERE id = ?
              AND user_id = ?
              AND status = 'pending'
        ")->execute([
            $orderId,
            $userId
        ]);

        $conn->prepare("
            UPDATE payments
            SET status = 'failed'
            WHERE order_id = ?
              AND status = 'pending'
        ")->execute([
            $orderId
        ]);

        notifyCompanyRoles(
            $conn,
            (int) $order['company_id'],
            ['admin', 'staff'],
            'order',
            'Order cancelled by customer',
            'Customer cancelled order #'
                . $order['order_number']
                . '.',
            BASE_URL
                . '/admin/order_details.php?id='
                . $orderId
        );

        writeAuditLog(
            $conn,
            $userId,
            (int) $order['company_id'],
            'order.cancelled.customer',
            'order',
            (string) $orderId,
            [
                'reason' =>
                    $reason !== ''
                        ? $reason
                        : null
            ]
        );

        $conn->commit();

        $_SESSION['customer_order_action_success'] =
            'Order cancelled successfully.';

        redirect(
            BASE_URL
            . '/order_details.php?id='
            . $orderId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REQUEST REFUND FOR PAID ORDER
    |--------------------------------------------------------------------------
    */
    if ($action === 'request_refund') {
        if (
            $order['payment_status'] !== 'paid'
            || $order['payment_record_status'] !== 'paid'
        ) {
            throw new RuntimeException(
                'Only paid orders can request a refund.'
            );
        }

        if (
            !in_array(
                $order['status'],
                [
                    'pending',
                    'confirmed',
                    'preparing'
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'A refund can no longer be requested once the order is ready for pickup or has entered delivery handling.'
            );
        }

        if (!$order['payment_id']) {
            throw new RuntimeException(
                'Payment record not found.'
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
            (int) $order['payment_id']
        ]);

        $existing =
            $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $conn->commit();

            $_SESSION['customer_order_action_error'] =
                'A refund request already exists for this order with status: '
                . str_replace(
                    '_',
                    ' ',
                    (string) $existing['status']
                )
                . '.';

            redirect(
                BASE_URL
                . '/order_details.php?id='
                . $orderId
            );
        }

        if ($reason === '') {
            throw new RuntimeException(
                'Please provide a reason for the refund request.'
            );
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
            (int) $order['payment_id'],
            $userId,
            $order['amount'],
            $order['currency'],
            $reason
        ]);

        $refundId =
            (int) $conn->lastInsertId();

        notifyCompanyRoles(
            $conn,
            (int) $order['company_id'],
            ['admin'],
            'refund',
            'Customer refund request',
            'Customer requested a refund for order #'
                . $order['order_number']
                . '.',
            BASE_URL
                . '/admin/order_details.php?id='
                . $orderId
        );

        writeAuditLog(
            $conn,
            $userId,
            (int) $order['company_id'],
            'refund.requested.customer',
            'refund',
            (string) $refundId,
            [
                'order_id' => $orderId,
                'reason' => $reason
            ]
        );

        $conn->commit();

        $_SESSION['customer_order_action_success'] =
            'Refund request submitted. The vendor will review it.';

        redirect(
            BASE_URL
            . '/order_details.php?id='
            . $orderId
        );
    }

    throw new RuntimeException(
        'Invalid order action.'
    );

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $_SESSION['customer_order_action_error'] =
        $e->getMessage();

    redirect(
        BASE_URL
        . '/order_details.php?id='
        . (int) $orderId
    );
}
