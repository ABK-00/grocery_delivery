<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requireCompanyAccess();
$companyId = currentCompanyId();

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../checkout.php");
    exit;
}

$deliveryAddress = trim($_POST['delivery_address'] ?? '');
$deliveryPhone    = trim($_POST['delivery_phone'] ?? '');
$notes            = trim($_POST['notes'] ?? '');

if ($deliveryAddress === '' || $deliveryPhone === '') {
    $_SESSION['checkout_error'] = 'Delivery address and phone number are required.';
    header("Location: ../checkout.php");
    exit;
}

$deliveryFee = 20.00;

try {

    $conn->beginTransaction();

    /*
     * Get customer's cart.
     */
    $stmt = $conn->prepare("
        SELECT
            c.product_id,
            c.quantity,
            p.name,
            p.price,
            p.stock,
            p.status
        FROM cart c
        INNER JOIN products p
            ON p.id = c.product_id
        WHERE c.user_id = ?
          AND p.company_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$userId, $companyId]);

    $cartItems = $stmt->fetchAll();

    if (!$cartItems) {
        throw new Exception('Your cart is empty.');
    }

    $subtotal = 0;

    foreach ($cartItems as $item) {

        if ($item['status'] !== 'active') {
            throw new Exception(
                $item['name'] . ' is no longer available.'
            );
        }

        if ((float)$item['quantity'] > (float)$item['stock']) {
            throw new Exception(
                'Insufficient stock for ' . $item['name'] . '.'
            );
        }

        $subtotal +=
            (float)$item['price'] *
            (float)$item['quantity'];
    }

    $totalAmount = $subtotal + $deliveryFee;

    /*
     * Generate unique order number.
     */
    do {

        $orderNumber =
            'ORD-' .
            date('Ymd') .
            '-' .
            strtoupper(bin2hex(random_bytes(4)));

        $check = $conn->prepare("
            SELECT id
            FROM orders
            WHERE order_number = ?
            LIMIT 1
        ");

        $check->execute([$orderNumber]);

    } while ($check->fetch());

    /*
     * Create order.
     *
     * Payment remains pending.
     * Order remains pending until payment succeeds.
     */
    $insertOrder = $conn->prepare("
        INSERT INTO orders
        (
            company_id,
            user_id,
            order_number,
            subtotal,
            delivery_fee,
            total_amount,
            delivery_address,
            delivery_phone,
            notes,
            status,
            payment_status
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')
    ");

    $insertOrder->execute([
        $companyId,
        $userId,
        $orderNumber,
        $subtotal,
        $deliveryFee,
        $totalAmount,
        $deliveryAddress,
        $deliveryPhone,
        $notes !== '' ? $notes : null
    ]);

    $orderId = $conn->lastInsertId();

    /*
     * Add order items.
     */
    $insertItem = $conn->prepare("
        INSERT INTO order_items
        (
            order_id,
            product_id,
            product_name,
            quantity,
            unit_price,
            subtotal
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($cartItems as $item) {

        $itemSubtotal =
            (float)$item['price'] *
            (float)$item['quantity'];

        $insertItem->execute([
            $orderId,
            $item['product_id'],
            $item['name'],
            $item['quantity'],
            $item['price'],
            $itemSubtotal
        ]);

        /*
         * Reserve/decrease stock.
         */
        $updateStock = $conn->prepare("
            UPDATE products
            SET stock = stock - ?
            WHERE id = ?
            AND company_id = ?
            AND stock >= ?
        ");

        $updateStock->execute([
            $item['quantity'],
            $item['product_id'],
            $companyId,
            $item['quantity']
        ]);

        if ($updateStock->rowCount() === 0) {
            throw new Exception(
                'Unable to update stock for ' .
                $item['name'] . '.'
            );
        }
    }

    /*
     * Create payment record.
     *
     * Reference will be assigned during
     * Paystack initialization.
     */
    $insertPayment = $conn->prepare("
        INSERT INTO payments
        (
            order_id,
            user_id,
            reference,
            amount,
            currency,
            status,
            gateway
        )
        VALUES (?, ?, ?, ?, 'GHS', 'pending', 'paystack')
    ");

    /*
     * Temporary internal reference.
     * It will be replaced by the real Paystack
     * reference immediately during initialization.
     */
    $temporaryReference =
        'PENDING-' .
        $orderId . '-' .
        bin2hex(random_bytes(4));

    $insertPayment->execute([
        $orderId,
        $userId,
        $temporaryReference,
        $totalAmount
    ]);

    /*
     * Empty the cart.
     */
    $deleteCart = $conn->prepare("
        DELETE FROM cart
        WHERE user_id = ?
    ");

    $deleteCart->execute([$userId]);

    $conn->commit();

    /*
     * Send customer to payment initialization.
     */
    header(
        "Location: ../api/start_payment.php?order_id=" .
        urlencode($orderId)
    );

    exit;

} catch (Throwable $e) {

    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $_SESSION['checkout_error'] =
        $e->getMessage();

    header("Location: ../checkout.php");
    exit;
}