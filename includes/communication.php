<?php

declare(strict_types=1);

function communicationUser(
    PDO $conn,
    int $userId
): array|false {
    $stmt = $conn->prepare("
        SELECT
            id,
            company_id,
            name,
            email,
            phone,
            role,
            status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function communicationDeliveryContext(
    PDO $conn,
    int $orderId
): array|false {
    $stmt = $conn->prepare("
        SELECT
            o.id AS order_id,
            o.order_number,
            o.user_id AS customer_id,
            o.company_id,
            o.status AS order_status,

            d.id AS delivery_id,
            d.status AS delivery_status,

            dp.id AS partner_id,
            dp.user_id AS rider_user_id,

            customer.name AS customer_name,
            rider.name AS rider_name

        FROM orders o

        INNER JOIN deliveries d
            ON d.order_id = o.id

        INNER JOIN delivery_partners dp
            ON dp.id = d.delivery_partner_id

        INNER JOIN users customer
            ON customer.id = o.user_id

        INNER JOIN users rider
            ON rider.id = dp.user_id

        WHERE o.id = ?
          AND o.status = 'out_for_delivery'
          AND d.status = 'out_for_delivery'
          AND customer.status = 'active'
          AND rider.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$orderId]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function communicationCanAccessOrder(
    PDO $conn,
    int $orderId,
    int $actorId,
    string $actorRole,
    ?int $actorCompanyId
): bool {
    $context =
        communicationDeliveryContext(
            $conn,
            $orderId
        );

    if (!$context) {
        return false;
    }

    if (
        $actorRole === 'customer'
    ) {
        return (int) $context['customer_id']
            === $actorId;
    }

    if (
        $actorRole === 'delivery_partner'
    ) {
        return (int) $context['rider_user_id']
            === $actorId;
    }

    if (
        in_array(
            $actorRole,
            ['admin', 'staff'],
            true
        )
    ) {
        return $actorCompanyId !== null
            && (int) $context['company_id']
                === $actorCompanyId;
    }

    return false;
}


function communicationCanContactForOrder(
    PDO $conn,
    int $orderId,
    int $actorId,
    string $actorRole,
    ?int $actorCompanyId,
    int $targetId
): bool {
    if (
        $actorId <= 0
        || $targetId <= 0
        || $actorId === $targetId
        || !communicationCanAccessOrder(
            $conn,
            $orderId,
            $actorId,
            $actorRole,
            $actorCompanyId
        )
    ) {
        return false;
    }

    $context =
        communicationDeliveryContext(
            $conn,
            $orderId
        );

    if (!$context) {
        return false;
    }

    $riderId =
        (int) $context['rider_user_id'];

    $customerId =
        (int) $context['customer_id'];

    if (
        $actorRole === 'customer'
    ) {
        return $targetId === $riderId;
    }

    if (
        in_array(
            $actorRole,
            ['admin', 'staff'],
            true
        )
    ) {
        return $targetId === $riderId;
    }

    if (
        $actorRole === 'delivery_partner'
    ) {
        if ($targetId === $customerId) {
            return true;
        }

        $target =
            communicationUser(
                $conn,
                $targetId
            );

        return $target
            && $target['status'] === 'active'
            && in_array(
                (string) $target['role'],
                ['admin', 'staff'],
                true
            )
            && (int) ($target['company_id'] ?? 0)
                === (int) $context['company_id'];
    }

    return false;
}


function communicationContactsForOrder(
    PDO $conn,
    int $orderId,
    int $actorId,
    string $actorRole,
    ?int $actorCompanyId
): array {
    $context =
        communicationDeliveryContext(
            $conn,
            $orderId
        );

    if (
        !$context
        || !communicationCanAccessOrder(
            $conn,
            $orderId,
            $actorId,
            $actorRole,
            $actorCompanyId
        )
    ) {
        return [];
    }

    if (
        $actorRole !== 'delivery_partner'
    ) {
        $rider =
            communicationUser(
                $conn,
                (int) $context['rider_user_id']
            );

        return $rider
            ? [$rider]
            : [];
    }

    $contacts = [];

    $customer =
        communicationUser(
            $conn,
            (int) $context['customer_id']
        );

    if ($customer) {
        $contacts[] = $customer;
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            company_id,
            name,
            email,
            phone,
            role,
            status
        FROM users
        WHERE company_id = ?
          AND status = 'active'
          AND role IN ('admin', 'staff')
        ORDER BY
            CASE role
                WHEN 'admin' THEN 0
                ELSE 1
            END,
            name ASC
    ");

    $stmt->execute([
        (int) $context['company_id']
    ]);

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $user
    ) {
        $contacts[] = $user;
    }

    return $contacts;
}


function communicationThread(
    PDO $conn,
    int $orderId,
    int $actorId,
    string $actorRole,
    ?int $actorCompanyId,
    int $targetId,
    bool $create = true
): array|false {
    if (
        !communicationCanContactForOrder(
            $conn,
            $orderId,
            $actorId,
            $actorRole,
            $actorCompanyId,
            $targetId
        )
    ) {
        return false;
    }

    $one = min(
        $actorId,
        $targetId
    );

    $two = max(
        $actorId,
        $targetId
    );

    $stmt = $conn->prepare("
        SELECT *
        FROM communication_threads
        WHERE order_id = ?
          AND user_one_id = ?
          AND user_two_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $orderId,
        $one,
        $two
    ]);

    $thread =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (
        $thread
        || !$create
    ) {
        return $thread;
    }

    $context =
        communicationDeliveryContext(
            $conn,
            $orderId
        );

    $stmt = $conn->prepare("
        INSERT INTO communication_threads (
            order_id,
            user_one_id,
            user_two_id,
            company_id,
            created_by
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    try {
        $stmt->execute([
            $orderId,
            $one,
            $two,
            (int) $context['company_id'],
            $actorId
        ]);
    } catch (PDOException $e) {
        if (
            (string) $e->getCode()
            !== '23000'
        ) {
            throw $e;
        }
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM communication_threads
        WHERE order_id = ?
          AND user_one_id = ?
          AND user_two_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $orderId,
        $one,
        $two
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}


function communicationAssertThreadAccess(
    PDO $conn,
    int $threadId,
    int $userId,
    string $role,
    ?int $companyId
): array {
    $stmt = $conn->prepare("
        SELECT *
        FROM communication_threads
        WHERE id = ?
          AND (
                user_one_id = ?
                OR user_two_id = ?
          )
        LIMIT 1
    ");

    $stmt->execute([
        $threadId,
        $userId,
        $userId
    ]);

    $thread =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$thread) {
        throw new RuntimeException(
            'Delivery conversation not found.'
        );
    }

    if (
        !communicationCanAccessOrder(
            $conn,
            (int) $thread['order_id'],
            $userId,
            $role,
            $companyId
        )
    ) {
        throw new RuntimeException(
            'This delivery communication channel is no longer active.'
        );
    }

    $otherId =
        communicationOtherUserId(
            $thread,
            $userId
        );

    if (
        !communicationCanContactForOrder(
            $conn,
            (int) $thread['order_id'],
            $userId,
            $role,
            $companyId,
            $otherId
        )
    ) {
        throw new RuntimeException(
            'You are not allowed to use this delivery conversation.'
        );
    }

    return $thread;
}


function communicationOtherUserId(
    array $thread,
    int $userId
): int {
    return (int) (
        (int) $thread['user_one_id']
            === $userId
                ? $thread['user_two_id']
                : $thread['user_one_id']
    );
}
