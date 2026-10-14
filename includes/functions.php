<?php
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect($url){ header("Location: $url"); exit; }

/**
 * Resolve a product image stored as a filename, products/... or uploads/...
 * $depth is the number of directories below the project root.
 */
function productImage($image = null, $depth = 0)
{
    $prefix = $depth > 0 ? str_repeat('../', $depth) : '';
    $placeholder = $prefix . 'assets/images/product-placeholder.svg';
    if ($image === null || trim((string)$image) === '') return $placeholder;
    $image = trim(str_replace('\\', '/', (string)$image));
    if (preg_match('#^https?://#i', $image)) return $image;
    $image = ltrim($image, '/');
    if (str_starts_with($image, 'uploads/')) return $prefix . $image;
    if (str_starts_with($image, 'products/')) return $prefix . 'uploads/' . $image;
    return $prefix . 'uploads/products/' . $image;
}

function getProductImage(array $product, $depth = 0)
{
    $image = $product['primary_image'] ?? $product['image'] ?? null;
    return productImage($image, $depth);
}

function money($amount){ return 'GH₵ ' . number_format((float)$amount, 2); }


/**
 * Restore stock reserved by an unpaid order exactly once.
 * Call inside an active DB transaction.
 */
function releaseOrderStock(PDO $conn, int $orderId): bool
{
    $orderStmt = $conn->prepare("
        SELECT id, company_id, payment_status, stock_released_at
        FROM orders
        WHERE id = ?
        FOR UPDATE
    ");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        throw new RuntimeException('Order not found while restoring stock.');
    }

    if (
        $order['payment_status'] === 'paid'
        || $order['stock_released_at'] !== null
    ) {
        return false;
    }

    $itemsStmt = $conn->prepare("
        SELECT product_id, quantity
        FROM order_items
        WHERE order_id = ?
    ");
    $itemsStmt->execute([$orderId]);

    $restoreStmt = $conn->prepare("
        UPDATE products
        SET stock = stock + ?
        WHERE id = ?
          AND company_id = ?
    ");

    foreach ($itemsStmt->fetchAll() as $item) {
        $restoreStmt->execute([
            $item['quantity'],
            $item['product_id'],
            $order['company_id']
        ]);

        if ($restoreStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to restore stock for product ' . $item['product_id'] . '.'
            );
        }
    }

    $markStmt = $conn->prepare("
        UPDATE orders
        SET stock_released_at = NOW()
        WHERE id = ?
          AND stock_released_at IS NULL
          AND payment_status <> 'paid'
    ");
    $markStmt->execute([$orderId]);

    return $markStmt->rowCount() === 1;
}


/**
 * Validate vendor-side order status transitions.
 *
 * Paid orders move forward through fulfillment. Unpaid orders may only be
 * cancelled. Paid-order cancellation is blocked until a refund workflow exists.
 */
function validateOrderStatusTransition(
    string $currentStatus,
    string $newStatus,
    string $paymentStatus
): void {
    $validStatuses = [
        'pending',
        'confirmed',
        'preparing',
        'ready',
        'out_for_delivery',
        'delivered',
        'cancelled'
    ];

    if (!in_array($newStatus, $validStatuses, true)) {
        throw new RuntimeException('Invalid order status.');
    }

    if ($newStatus === $currentStatus) {
        return;
    }

    if (in_array($currentStatus, ['delivered', 'cancelled'], true)) {
        throw new RuntimeException(
            'A completed or cancelled order cannot be changed.'
        );
    }

    if ($paymentStatus !== 'paid') {
        if ($newStatus !== 'cancelled') {
            throw new RuntimeException(
                'This order cannot enter fulfillment until payment is confirmed.'
            );
        }

        return;
    }

    if ($newStatus === 'cancelled') {
        throw new RuntimeException(
            'Paid orders cannot be cancelled until the refund workflow is available.'
        );
    }

    $nextStatus = [
        'pending' => 'confirmed',
        'confirmed' => 'preparing',
        'preparing' => 'ready',
        'ready' => 'out_for_delivery',
        'out_for_delivery' => 'delivered'
    ];

    if (($nextStatus[$currentStatus] ?? null) !== $newStatus) {
        throw new RuntimeException(
            'Invalid order status transition. Move the order through the fulfillment stages in sequence.'
        );
    }
}


function createNotification(
    PDO $conn,
    int $userId,
    string $title,
    string $message,
    ?string $link = null,
    string $type = 'general'
): void {
    $stmt = $conn->prepare("
        INSERT INTO notifications (
            user_id,
            type,
            title,
            message,
            link
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $type,
        $title,
        $message,
        $link
    ]);
}

function writeAuditLog(
    PDO $conn,
    ?int $userId,
    ?int $companyId,
    string $action,
    ?string $entityType = null,
    ?string $entityId = null,
    array $details = []
): void {
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (
            user_id,
            company_id,
            action,
            entity_type,
            entity_id,
            details,
            ip_address
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $companyId,
        $action,
        $entityType,
        $entityId,
        $details
            ? json_encode(
                $details,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            )
            : null,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}


/**
 * Read a platform setting with a fallback.
 */
function platformSetting(
    PDO $conn,
    string $key,
    mixed $default = null
): mixed {
    $stmt = $conn->prepare("
        SELECT setting_value
        FROM platform_settings
        WHERE setting_key = ?
        LIMIT 1
    ");
    $stmt->execute([$key]);

    $value = $stmt->fetchColumn();

    return $value === false || $value === null || $value === ''
        ? $default
        : $value;
}


/**
 * Notify active users in selected company roles.
 */
function notifyCompanyRoles(
    PDO $conn,
    int $companyId,
    array $roles,
    string $type,
    string $title,
    string $message,
    ?string $link = null
): void {
    $allowed = [
        'admin',
        'staff',
        'delivery_partner'
    ];

    $roles = array_values(
        array_intersect(
            array_unique($roles),
            $allowed
        )
    );

    if (!$roles) {
        return;
    }

    $placeholders =
        implode(',', array_fill(0, count($roles), '?'));

    $stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE company_id = ?
          AND status = 'active'
          AND role IN ({$placeholders})
    ");

    $stmt->execute(
        array_merge(
            [$companyId],
            $roles
        )
    );

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $targetUserId) {
        createNotification(
            $conn,
            (int) $targetUserId,
            $type,
            $title,
            $message,
            $link
        );
    }
}
