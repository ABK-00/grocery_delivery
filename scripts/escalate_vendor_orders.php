<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';

$slaMinutes = max(
    1,
    (int) (
        getenv('VENDOR_ORDER_SLA_MINUTES')
        ?: 10
    )
);

$limit = 100;
$now = new DateTimeImmutable('now');

$stmt = $conn->query("
    SELECT
        o.id,
        o.company_id,
        o.order_number,
        o.status,
        o.payment_status,
        o.vendor_sla_expires_at,
        o.vendor_escalated_at,
        COALESCE(
            MAX(p.paid_at),
            o.updated_at,
            o.created_at
        ) AS pending_since
    FROM orders o
    LEFT JOIN payments p
        ON p.order_id = o.id
       AND p.status = 'paid'
    WHERE o.status = 'pending'
      AND o.payment_status = 'paid'
      AND o.vendor_escalated_at IS NULL
    GROUP BY
        o.id,
        o.company_id,
        o.order_number,
        o.status,
        o.payment_status,
        o.vendor_sla_expires_at,
        o.vendor_escalated_at,
        o.updated_at,
        o.created_at
    ORDER BY pending_since ASC
    LIMIT {$limit}
");

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$escalated = 0;
$skipped = 0;

foreach ($orders as $candidate) {
    $pendingSince = new DateTimeImmutable(
        (string) $candidate['pending_since']
    );

    $expiresAt = !empty($candidate['vendor_sla_expires_at'])
        ? new DateTimeImmutable(
            (string) $candidate['vendor_sla_expires_at']
        )
        : $pendingSince->modify(
            '+' . $slaMinutes . ' minutes'
        );

    if ($expiresAt > $now) {
        $skipped++;
        continue;
    }

    try {
        $conn->beginTransaction();

        $lock = $conn->prepare("
            SELECT
                id,
                company_id,
                order_number,
                status,
                payment_status,
                vendor_sla_expires_at,
                vendor_escalated_at
            FROM orders
            WHERE id = ?
            FOR UPDATE
        ");
        $lock->execute([
            (int) $candidate['id']
        ]);

        $order = $lock->fetch(PDO::FETCH_ASSOC);

        if (
            !$order
            || $order['status'] !== 'pending'
            || $order['payment_status'] !== 'paid'
            || $order['vendor_escalated_at'] !== null
        ) {
            $conn->rollBack();
            $skipped++;
            continue;
        }

        $expiresSql = $expiresAt->format(
            'Y-m-d H:i:s'
        );

        $mark = $conn->prepare("
            UPDATE orders
            SET
                vendor_sla_expires_at = COALESCE(
                    vendor_sla_expires_at,
                    ?
                ),
                vendor_escalated_at = NOW()
            WHERE id = ?
              AND status = 'pending'
              AND payment_status = 'paid'
              AND vendor_escalated_at IS NULL
        ");

        $mark->execute([
            $expiresSql,
            (int) $order['id']
        ]);

        if ($mark->rowCount() !== 1) {
            $conn->rollBack();
            $skipped++;
            continue;
        }

        $escalatedAt = (new DateTimeImmutable())
            ->format(DATE_ATOM);

        $payload = [
            'type' => 'ORDER_SLA_ESCALATED',
            'order_id' => (int) $order['id'],
            'order_number' =>
                (string) $order['order_number'],
            'company_id' =>
                (int) $order['company_id'],
            'status' => 'pending',
            'payment_status' => 'paid',
            'sla_minutes' => $slaMinutes,
            'sla_expires_at' =>
                $expiresAt->format(DATE_ATOM),
            'escalated_at' => $escalatedAt
        ];

        emitRealtimeEvent(
            $conn,
            'vendor:orders',
            'ORDER_SLA_ESCALATED',
            $payload,
            (int) $order['company_id'],
            null,
            'vendor-order-sla:'
                . (int) $order['id']
        );

        notifyCompanyRoles(
            $conn,
            (int) $order['company_id'],
            ['admin', 'staff'],
            'order_sla',
            'Order needs attention',
            'Paid order #'
                . $order['order_number']
                . ' is still pending after '
                . $slaMinutes
                . ' minutes.',
            BASE_URL
                . '/admin/order_details.php?id='
                . (int) $order['id']
        );

        writeAuditLog(
            $conn,
            null,
            (int) $order['company_id'],
            'order.sla_escalated',
            'order',
            (string) $order['id'],
            $payload
        );

        $conn->commit();

        $escalated++;

        echo 'Escalated order #'
            . (int) $order['id']
            . PHP_EOL;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        fwrite(
            STDERR,
            'Failed order #'
                . (int) $candidate['id']
                . ': '
                . $e->getMessage()
                . PHP_EOL
        );
    }
}

echo 'Escalated='
    . $escalated
    . ', Skipped='
    . $skipped
    . PHP_EOL;
