<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('customer');

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/orders.php');
    exit;
}

$orderId = filter_input(
    INPUT_POST,
    'order_id',
    FILTER_VALIDATE_INT
);

$csrf = (string) ($_POST['csrf_token'] ?? '');

if (
    !$orderId
    || empty($_SESSION['reorder_csrf'])
    || $csrf === ''
    || !hash_equals($_SESSION['reorder_csrf'], $csrf)
) {
    $_SESSION['cart_error'] =
        'Your reorder request is invalid or has expired. Please try again.';

    header('Location: ' . BASE_URL . '/orders.php');
    exit;
}

try {
    $conn->beginTransaction();

    $orderStmt = $conn->prepare("
        SELECT
            o.id,
            o.company_id,
            o.status,
            o.payment_status,
            c.status AS company_status,
            c.subscription_plan,
            c.trial_ends_at,
            c.subscription_ends_at,
            COALESCE(cs.store_status, 'open') AS store_status
        FROM orders o
        INNER JOIN companies c
            ON c.id = o.company_id
        LEFT JOIN company_storefronts cs
            ON cs.company_id = c.id
        WHERE o.id = ?
          AND o.user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $orderStmt->execute([
        $orderId,
        $userId
    ]);

    $order = $orderStmt->fetch();

    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    if (
        $order['status'] !== 'cancelled'
        && $order['payment_status'] !== 'failed'
    ) {
        throw new RuntimeException(
            'Only cancelled or failed-payment orders can be rebuilt right now.'
        );
    }

    if ($order['company_status'] !== 'active') {
        throw new RuntimeException(
            'This store is currently unavailable.'
        );
    }

    if ($order['store_status'] !== 'open') {
        throw new RuntimeException(
            'This store is currently closed.'
        );
    }

    $subscriptionValid = false;

    if ($order['subscription_plan'] === 'trial') {
        $subscriptionValid =
            !empty($order['trial_ends_at'])
            && strtotime($order['trial_ends_at']) >= time();
    } elseif (
        in_array(
            $order['subscription_plan'],
            ['monthly', 'quarterly', 'yearly'],
            true
        )
    ) {
        $subscriptionValid =
            !empty($order['subscription_ends_at'])
            && strtotime($order['subscription_ends_at']) >= time();
    }

    if (!$subscriptionValid) {
        throw new RuntimeException(
            'This store is temporarily unavailable.'
        );
    }

    /*
     * Never destroy or merge an existing cart implicitly.
     * The empty-cart requirement also makes repeated reorder submissions
     * idempotent when combined with the upsert below.
     */
    $cartStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM cart
        WHERE user_id = ?
        FOR UPDATE
    ");
    $cartStmt->execute([$userId]);

    if ((int) $cartStmt->fetchColumn() > 0) {
        throw new RuntimeException(
            'Your cart already has items. Complete or clear it before rebuilding this order.'
        );
    }

    $itemsStmt = $conn->prepare("
        SELECT
            oi.product_id,
            oi.product_name,
            oi.quantity,
            p.company_id,
            p.stock,
            p.status
        FROM order_items oi
        INNER JOIN products p
            ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
        FOR UPDATE
    ");

    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll();

    if (!$items) {
        throw new RuntimeException(
            'This order has no products that can be reordered.'
        );
    }

    foreach ($items as $item) {
        if (
            (int) $item['company_id']
                !== (int) $order['company_id']
            || $item['status'] !== 'active'
        ) {
            throw new RuntimeException(
                $item['product_name']
                . ' is no longer available.'
            );
        }

        if ((float) $item['quantity'] > (float) $item['stock']) {
            throw new RuntimeException(
                'There is not enough stock to reorder '
                . $item['product_name']
                . '.'
            );
        }
    }

    $insert = $conn->prepare("
        INSERT INTO cart (
            user_id,
            product_id,
            quantity
        )
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($items as $item) {
        $insert->execute([
            $userId,
            $item['product_id'],
            $item['quantity']
        ]);
    }

    $conn->commit();

    unset($_SESSION['reorder_csrf']);
    $_SESSION['cart_success'] =
        'Order items were added back to your cart. Review current prices and stock before checkout.';

    header('Location: ' . BASE_URL . '/cart.php');
    exit;

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $_SESSION['cart_error'] = $e->getMessage();

    header(
        'Location: '
        . BASE_URL
        . '/order_details.php?id='
        . urlencode((string) $orderId)
    );
    exit;
}
