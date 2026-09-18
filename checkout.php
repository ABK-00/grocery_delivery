<?php

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/functions.php";

requireLogin();
requireCompanyAccess();
$companyId = currentCompanyId();

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        c.id AS cart_id,
        c.product_id,
        c.quantity,
        p.name,
        p.price,
        p.stock,
        p.unit,
        COALESCE(
            (
                SELECT pi.image
                FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                LIMIT 1
            ),
            p.image
        ) AS image
    FROM cart c
    INNER JOIN products p ON p.id = c.product_id
    WHERE c.user_id = ?
      AND p.company_id = ?
      AND p.status = 'active'
    ORDER BY c.created_at DESC
");

$stmt->execute([$userId, $companyId]);
$cartItems = $stmt->fetchAll();

if (!$cartItems) {
    redirect("cart.php");
}

$subtotal = 0;

foreach ($cartItems as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

/*
|--------------------------------------------------------------------------
| Delivery fee
|--------------------------------------------------------------------------
| For now we use a simple fixed delivery fee.
| We can make this location-based later.
*/
$deliveryFee = 20.00;
$total = $subtotal + $deliveryFee;

$userStmt = $conn->prepare("
    SELECT name, email, phone, whatsapp
    FROM users
    WHERE id = ?
    LIMIT 1
");

$userStmt->execute([$userId]);
$user = $userStmt->fetch();

$error = $_SESSION['checkout_error'] ?? null;
unset($_SESSION['checkout_error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Checkout | GroceryDelivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f5f7fa;
            font-family: Arial, sans-serif;
        }

        .checkout-header {
            background: #0f172a;
            color: white;
            padding: 25px 0;
        }

        .checkout-title {
            font-weight: 700;
        }

        .checkout-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            padding: 25px;
            margin-bottom: 20px;
        }

        .product-row {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .product-row:last-child {
            border-bottom: none;
        }

        .product-image {
            width: 75px;
            height: 75px;
            object-fit: cover;
            border-radius: 10px;
            background: #f1f5f9;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .total-row {
            border-top: 1px solid #ddd;
            padding-top: 18px;
            margin-top: 15px;
            font-size: 20px;
            font-weight: 700;
        }

        .btn-place-order {
            background: #16a34a;
            border: none;
            color: white;
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
        }

        .btn-place-order:hover {
            background: #15803d;
        }

        .required {
            color: #dc2626;
        }

    </style>

    <link href="assets/css/admin.css" rel="stylesheet">
    <link href="assets/css/customer.css" rel="stylesheet">
</head>

<body>
<?php include __DIR__ . '/includes/loader.php'; ?>
<?php include __DIR__ . '/includes/customer_sidebar.php'; ?>
<main class="customer-main">
<header class="checkout-header">

    <div class="container">

        <div class="d-flex justify-content-between align-items-center">

            <div>
                <h2 class="checkout-title mb-1">
                    <i class="bi bi-bag-check"></i>
                    Checkout
                </h2>

                <div class="text-white-50">
                    Complete your order
                </div>
            </div>

            <a href="cart.php" class="btn btn-outline-light">
                <i class="bi bi-cart"></i>
                Back to Cart
            </a>

        </div>

    </div>

</header>


<div class="container py-4">

    <?php if ($error): ?>

        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <form method="POST" action="api/place_order.php">

        <div class="row">

            <!-- CUSTOMER INFORMATION -->

            <div class="col-lg-7">

                <div class="checkout-card">

                    <h5 class="fw-bold mb-4">
                        <i class="bi bi-person"></i>
                        Delivery Information
                    </h5>

                    <div class="mb-3">

                        <label class="form-label">
                            Full Name
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= e($user['name']) ?>"
                            readonly
                        >

                    </div>


                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Email
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                value="<?= e($user['email']) ?>"
                                readonly
                            >

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Phone <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                name="delivery_phone"
                                class="form-control"
                                value="<?= e($user['phone'] ?? '') ?>"
                                required
                            >

                        </div>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Delivery Address <span class="required">*</span>
                        </label>

                        <textarea
                            name="delivery_address"
                            class="form-control"
                            rows="4"
                            placeholder="Enter your complete delivery address"
                            required
                        ></textarea>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Order Notes
                        </label>

                        <textarea
                            name="notes"
                            class="form-control"
                            rows="3"
                            placeholder="Optional instructions for your order"
                        ></textarea>

                    </div>

                </div>

            </div>


            <!-- ORDER SUMMARY -->

            <div class="col-lg-5">

                <div class="checkout-card">

                    <h5 class="fw-bold mb-3">
                        <i class="bi bi-receipt"></i>
                        Your Order
                    </h5>


                    <?php foreach ($cartItems as $item): ?>

                        <?php
                        $itemSubtotal = $item['price'] * $item['quantity'];

                        $image = $item['image']
                            ? "assets/images/products/" . $item['image']
                            : "assets/images/products/default.jpg";
                        ?>

                        <div class="product-row">

                            <img
                                src="<?= e($image) ?>"
                                class="product-image"
                                alt="<?= e($item['name']) ?>"
                                onerror="this.src='assets/images/products/default.jpg';"
                            >

                            <div class="flex-grow-1">

                                <div class="fw-semibold">
                                    <?= e($item['name']) ?>
                                </div>

                                <small class="text-muted">
                                    <?= e($item['quantity']) ?>
                                    <?= e($item['unit']) ?>
                                    × GH₵<?= number_format($item['price'], 2) ?>
                                </small>

                            </div>

                            <div class="fw-semibold">

                                GH₵<?= number_format($itemSubtotal, 2) ?>

                            </div>

                        </div>

                    <?php endforeach; ?>


                    <div class="mt-4">

                        <div class="summary-row">

                            <span>Subtotal</span>

                            <strong>
                                GH₵<?= number_format($subtotal, 2) ?>
                            </strong>

                        </div>


                        <div class="summary-row">

                            <span>Delivery Fee</span>

                            <strong>
                                GH₵<?= number_format($deliveryFee, 2) ?>
                            </strong>

                        </div>


                        <div class="summary-row total-row">

                            <span>Total</span>

                            <span>
                                GH₵<?= number_format($total, 2) ?>
                            </span>

                        </div>

                    </div>


                    <button
                        type="submit"
                        class="btn-place-order mt-3"
                    >
                        <i class="bi bi-check-circle"></i>
                        Place Order
                    </button>


                    <div class="text-center text-muted small mt-3">

                        <i class="bi bi-shield-check"></i>
                        Your order information is secure.

                    </div>

                </div>

            </div>

        </div>

    </form>

</div>

</body>
</html>