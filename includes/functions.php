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
