<?php

declare(strict_types=1);

/**
 * Internal Somame communications authorization and thread helpers.
 */

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


function communicationCanContact(
    PDO $conn,
    int $actorId,
    string $actorRole,
    ?int $actorCompanyId,
    int $targetId
): bool {
    if (
        $actorId <= 0
        || $targetId <= 0
        || $actorId === $targetId
    ) {
        return false;
    }

    $target = communicationUser(
        $conn,
        $targetId
    );

    if (
        !$target
        || $target['status'] !== 'active'
    ) {
        return false;
    }

    $targetRole =
        (string) $target['role'];

    $targetCompanyId =
        $target['company_id'] !== null
            ? (int) $target['company_id']
            : null;

    if (
        $actorRole === 'super_admin'
        || $targetRole === 'super_admin'
    ) {
        return true;
    }

    $companyRoles = [
        'admin',
        'staff',
        'delivery_partner'
    ];

    if (
        in_array(
            $actorRole,
            $companyRoles,
            true
        )
        && in_array(
            $targetRole,
            $companyRoles,
            true
        )
    ) {
        return $actorCompanyId !== null
            && $targetCompanyId !== null
            && $actorCompanyId === $targetCompanyId;
    }

    if (
        $actorRole === 'customer'
        && in_array(
            $targetRole,
            ['admin', 'staff'],
            true
        )
        && $targetCompanyId
    ) {
        $stmt = $conn->prepare("
            SELECT 1
            FROM orders
            WHERE user_id = ?
              AND company_id = ?
            LIMIT 1
        ");
        $stmt->execute([
            $actorId,
            $targetCompanyId
        ]);

        return (bool) $stmt->fetchColumn();
    }

    if (
        in_array(
            $actorRole,
            ['admin', 'staff'],
            true
        )
        && $targetRole === 'customer'
        && $actorCompanyId
    ) {
        $stmt = $conn->prepare("
            SELECT 1
            FROM orders
            WHERE user_id = ?
              AND company_id = ?
            LIMIT 1
        ");
        $stmt->execute([
            $targetId,
            $actorCompanyId
        ]);

        return (bool) $stmt->fetchColumn();
    }

    if (
        $actorRole === 'customer'
        && $targetRole === 'delivery_partner'
    ) {
        $stmt = $conn->prepare("
            SELECT 1
            FROM deliveries d
            INNER JOIN delivery_partners dp
                ON dp.id = d.delivery_partner_id
            INNER JOIN orders o
                ON o.id = d.order_id
            WHERE o.user_id = ?
              AND dp.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([
            $actorId,
            $targetId
        ]);

        return (bool) $stmt->fetchColumn();
    }

    if (
        $actorRole === 'delivery_partner'
        && $targetRole === 'customer'
    ) {
        $stmt = $conn->prepare("
            SELECT 1
            FROM deliveries d
            INNER JOIN delivery_partners dp
                ON dp.id = d.delivery_partner_id
            INNER JOIN orders o
                ON o.id = d.order_id
            WHERE dp.user_id = ?
              AND o.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([
            $actorId,
            $targetId
        ]);

        return (bool) $stmt->fetchColumn();
    }

    return false;
}


function communicationContacts(
    PDO $conn,
    int $actorId,
    string $actorRole,
    ?int $actorCompanyId
): array {
    $stmt = $conn->prepare("
        SELECT
            id,
            company_id,
            name,
            email,
            role,
            status
        FROM users
        WHERE id <> ?
          AND status = 'active'
        ORDER BY name ASC
    ");
    $stmt->execute([$actorId]);

    $contacts = [];

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $user
    ) {
        if (
            communicationCanContact(
                $conn,
                $actorId,
                $actorRole,
                $actorCompanyId,
                (int) $user['id']
            )
        ) {
            $contacts[] = $user;
        }
    }

    return $contacts;
}


function communicationThread(
    PDO $conn,
    int $actorId,
    string $actorRole,
    ?int $actorCompanyId,
    int $targetId,
    bool $create = true
): array|false {
    if (
        !communicationCanContact(
            $conn,
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
        WHERE user_one_id = ?
          AND user_two_id = ?
        LIMIT 1
    ");
    $stmt->execute([
        $one,
        $two
    ]);

    $thread = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (
        $thread
        || !$create
    ) {
        return $thread;
    }

    $target =
        communicationUser(
            $conn,
            $targetId
        );

    $companyId = null;

    if (
        $actorCompanyId !== null
        && !empty($target['company_id'])
        && (int) $target['company_id']
            === $actorCompanyId
    ) {
        $companyId = $actorCompanyId;
    } elseif (
        $actorRole === 'customer'
        && !empty($target['company_id'])
    ) {
        $companyId =
            (int) $target['company_id'];
    }

    $stmt = $conn->prepare("
        INSERT INTO communication_threads (
            user_one_id,
            user_two_id,
            company_id,
            created_by
        )
        VALUES (?, ?, ?, ?)
    ");

    try {
        $stmt->execute([
            $one,
            $two,
            $companyId,
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
        WHERE user_one_id = ?
          AND user_two_id = ?
        LIMIT 1
    ");
    $stmt->execute([
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
    int $userId
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

    $thread = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$thread) {
        throw new RuntimeException(
            'Conversation not found.'
        );
    }

    return $thread;
}


function communicationOtherUserId(
    array $thread,
    int $userId
): int {
    return (int) (
        (int) $thread['user_one_id'] === $userId
            ? $thread['user_two_id']
            : $thread['user_one_id']
    );
}
