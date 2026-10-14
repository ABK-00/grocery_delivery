<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../includes/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$cutoff = date(
    'Y-m-d H:i:s',
    time() - PAYMENT_SESSION_TIMEOUT_SECONDS
);

$stmt = $conn->prepare("
    SELECT id
    FROM orders
    WHERE payment_status = 'pending'
      AND status = 'pending'
      AND stock_released_at IS NULL
      AND created_at <= ?
    ORDER BY id ASC
");

$stmt->execute([$cutoff]);
$orderIds = array_map(
    'intval',
    $stmt->fetchAll(PDO::FETCH_COLUMN)
);

$expired = 0;
$skipped = 0;
$failed = 0;

foreach ($orderIds as $orderId) {
    try {
        $conn->beginTransaction();

        $lock = $conn->prepare("
            SELECT id, payment_status, status, stock_released_at
            FROM orders
            WHERE id = ?
            FOR UPDATE
        ");
        $lock->execute([$orderId]);
        $order = $lock->fetch();

        if (
            !$order
            || $order['payment_status'] !== 'pending'
            || $order['status'] !== 'pending'
            || $order['stock_released_at'] !== null
        ) {
            $conn->rollBack();
            $skipped++;
            continue;
        }

        releaseOrderStock($conn, $orderId);

        $updatePayments = $conn->prepare("
            UPDATE payments
            SET status = 'failed'
            WHERE order_id = ?
              AND status = 'pending'
        ");
        $updatePayments->execute([$orderId]);

        $updateOrder = $conn->prepare("
            UPDATE orders
            SET
                payment_status = 'failed',
                status = 'cancelled'
            WHERE id = ?
              AND payment_status = 'pending'
              AND status = 'pending'
        ");
        $updateOrder->execute([$orderId]);

        $conn->commit();
        $expired++;

        echo "Expired order #{$orderId}\n";
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $failed++;
        fwrite(
            STDERR,
            "Failed order #{$orderId}: {$e->getMessage()}\n"
        );
    }
}

echo "Done. Expired={$expired}, Skipped={$skipped}, Failed={$failed}\n";

exit($failed > 0 ? 1 : 0);
