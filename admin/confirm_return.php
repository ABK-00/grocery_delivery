<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');
requireCompanyAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

$companyId = currentCompanyId();
$userId = currentUserId();
$orderId = filter_input(
    INPUT_POST,
    'order_id',
    FILTER_VALIDATE_INT
);

$csrf = (string) ($_POST['csrf_token'] ?? '');

if (
    !$orderId
    || empty($_SESSION['order_action_csrf'])
    || $csrf === ''
    || !hash_equals(
        (string) $_SESSION['order_action_csrf'],
        $csrf
    )
) {
    $_SESSION['order_action_error'] =
        'Your session expired. Refresh the page and try again.';

    redirect(
        'order_details.php?id=' . (int) $orderId
    );
}

$received = $_POST['quantity_received'] ?? [];
$conditions = $_POST['item_condition'] ?? [];
$notes = trim(
    (string) ($_POST['return_notes'] ?? '')
);

$allowedConditions = [
    'sellable',
    'damaged',
    'opened',
    'perishable',
    'missing'
];

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT
            d.id AS delivery_id,
            d.delivery_partner_id,
            d.status AS delivery_status,
            o.id AS order_id,
            o.order_number,
            o.user_id,
            o.status AS order_status
        FROM deliveries d
        INNER JOIN orders o
            ON o.id = d.order_id
        WHERE o.id = ?
          AND o.company_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $orderId,
        $companyId
    ]);

    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$delivery) {
        throw new RuntimeException(
            'Order or delivery was not found.'
        );
    }

    if (
        $delivery['delivery_status']
        !== 'return_pending_confirmation'
        || $delivery['order_status']
        !== 'return_pending_confirmation'
    ) {
        throw new RuntimeException(
            'This return is not awaiting vendor confirmation.'
        );
    }

    $itemsStmt = $conn->prepare("
        SELECT
            id,
            product_id,
            product_name,
            quantity
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
        FOR UPDATE
    ");

    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new RuntimeException(
            'No order items were found.'
        );
    }

    $upsert = $conn->prepare("
        INSERT INTO delivery_return_items (
            delivery_id,
            order_item_id,
            quantity_expected,
            quantity_received,
            quantity_restocked,
            item_condition,
            notes
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            quantity_received = VALUES(quantity_received),
            quantity_restocked = VALUES(quantity_restocked),
            item_condition = VALUES(item_condition),
            notes = VALUES(notes)
    ");

    $restock = $conn->prepare("
        UPDATE products
        SET stock = stock + ?
        WHERE id = ?
          AND company_id = ?
    ");

    $summary = [];

    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $expected = (float) $item['quantity'];

        $qtyReceived = isset($received[$itemId])
            ? (float) $received[$itemId]
            : 0.0;

        if (
            $qtyReceived < 0
            || $qtyReceived > $expected
        ) {
            throw new RuntimeException(
                'Invalid returned quantity for '
                . $item['product_name']
                . '.'
            );
        }

        $condition = (string) (
            $conditions[$itemId]
            ?? 'missing'
        );

        if (
            !in_array(
                $condition,
                $allowedConditions,
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid item condition.'
            );
        }

        $restockQty =
            $condition === 'sellable'
                ? $qtyReceived
                : 0.0;

        if (
            $restockQty > 0
            && !empty($item['product_id'])
        ) {
            $restock->execute([
                $restockQty,
                (int) $item['product_id'],
                $companyId
            ]);

            if ($restock->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to restore inventory for '
                    . $item['product_name']
                    . '.'
                );
            }
        }

        $upsert->execute([
            (int) $delivery['delivery_id'],
            $itemId,
            $expected,
            $qtyReceived,
            $restockQty,
            $condition,
            null
        ]);

        $summary[] = [
            'order_item_id' => $itemId,
            'product_name' =>
                $item['product_name'],
            'quantity_expected' => $expected,
            'quantity_received' => $qtyReceived,
            'quantity_restocked' => $restockQty,
            'condition' => $condition
        ];
    }

    $stmt = $conn->prepare("
        UPDATE deliveries
        SET
            status = 'returned',
            returned_at = NOW(),
            return_confirmed_by = ?,
            return_notes = ?
        WHERE id = ?
          AND status = 'return_pending_confirmation'
    ");

    $stmt->execute([
        $userId,
        $notes !== '' ? $notes : null,
        (int) $delivery['delivery_id']
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'The return status changed before it could be confirmed.'
        );
    }

    $stmt = $conn->prepare("
        UPDATE orders
        SET status = 'returned'
        WHERE id = ?
          AND company_id = ?
          AND status = 'return_pending_confirmation'
    ");

    $stmt->execute([
        $orderId,
        $companyId
    ]);

    if (!empty($delivery['delivery_partner_id'])) {
        $conn->prepare("
            UPDATE delivery_partners
            SET status = 'available'
            WHERE id = ?
        ")->execute([
            (int) $delivery['delivery_partner_id']
        ]);
    }

    createNotification(
        $conn,
        (int) $delivery['user_id'],
        'delivery_return',
        'Returned goods confirmed',
        'The store has confirmed receipt of the returned goods for order #'
            . $delivery['order_number']
            . '.',
        BASE_URL
            . '/order_details.php?id='
            . $orderId
    );

    emitRealtimeEvent(
        $conn,
        'vendor:orders',
        'ORDER_RETURN_CONFIRMED',
        [
            'type' => 'ORDER_RETURN_CONFIRMED',
            'order_id' => (int) $orderId,
            'order_number' =>
                (string) $delivery['order_number'],
            'company_id' => (int) $companyId,
            'status' => 'returned',
            'items' => $summary,
            'confirmed_at' =>
                (new DateTimeImmutable())
                    ->format(DATE_ATOM)
        ],
        (int) $companyId,
        (int) $userId,
        'order-return-confirmed:'
            . (int) $orderId
    );

    writeAuditLog(
        $conn,
        $userId,
        $companyId,
        'delivery.return_confirmed',
        'order',
        (string) $orderId,
        [
            'delivery_id' =>
                (int) $delivery['delivery_id'],
            'items' => $summary,
            'return_notes' =>
                $notes !== '' ? $notes : null
        ]
    );

    $conn->commit();

    $_SESSION['order_action_success'] =
        'Returned goods confirmed. Only sellable quantities were restored to stock.';

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $_SESSION['order_action_error'] =
        $e->getMessage();
}

redirect(
    'order_details.php?id=' . (int) $orderId
);
