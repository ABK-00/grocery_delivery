<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('customer');

$userId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        c.id AS cart_id,
        c.quantity,
        p.id AS product_id,
        p.company_id,
        p.name,
        p.price,
        p.stock,
        p.unit,
        p.image,
        co.company_name,
        co.storefront_slug,
        cs.display_name,
        (
            SELECT pi.image
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
            LIMIT 1
        ) AS primary_image
    FROM cart c
    INNER JOIN products p
        ON p.id = c.product_id
    INNER JOIN companies co
        ON co.id = p.company_id
    LEFT JOIN company_storefronts cs
        ON cs.company_id = co.id
    WHERE c.user_id = ?
    ORDER BY c.updated_at DESC
");

$stmt->execute([$userId]);
$items = $stmt->fetchAll();

$companyIds = array_values(array_unique(array_map(
    static fn(array $item): int => (int) $item['company_id'],
    $items
)));

$cartConflict = count($companyIds) > 1;
$cartStoreName = '';

if ($items) {
    $cartStoreName = $items[0]['display_name']
        ?: $items[0]['company_name'];
}

$subtotal = 0.0;

foreach ($items as $item) {
    $subtotal +=
        (float) $item['price']
        * (float) $item['quantity'];
}

$deliveryFee = 20.00;
$total = $subtotal + ($items ? $deliveryFee : 0);

function cartProductImage(array $product): string
{
    $image = $product['primary_image']
        ?: ($product['image'] ?? '');

    if ($image === '') {
        return '/somame_ent/assets/images/product-placeholder.svg';
    }

    if (
        str_starts_with($image, 'http://')
        || str_starts_with($image, 'https://')
    ) {
        return $image;
    }

    $filename = basename(str_replace('\\', '/', $image));

    return '/somame_ent/assets/images/products/'
        . rawurlencode($filename);
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>My Cart | GroceryDelivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="assets/css/admin.css"
        rel="stylesheet"
    >

    <link
        href="assets/css/customer.css"
        rel="stylesheet"
    >

    <style>
        .cart-img {
            width: 80px;
            height: 70px;
            object-fit: cover;
            border-radius: 10px;
        }

        .cart-row {
            background: var(--gd-surface);
            border: 1px solid var(--gd-border);
            border-radius: 15px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .summary {
            background: var(--gd-surface);
            border: 1px solid var(--gd-border);
            border-radius: 16px;
            padding: 22px;
            position: sticky;
            top: 20px;
        }
    </style>
</head>

<body>

<?php
include __DIR__ . '/includes/loader.php';
include __DIR__ . '/includes/customer_sidebar.php';
?>

<main class="main-content customer-main">

    <div class="topbar">

        <div class="topbar-left">

            <button
                class="sidebar-toggle"
                id="sidebarToggle"
                type="button"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="topbar-title">
                    My Cart
                </h1>

                <p class="topbar-subtitle">
                    <?= $cartStoreName
                        ? 'Shopping from ' . e($cartStoreName)
                        : 'Review your groceries before checkout.' ?>
                </p>
            </div>

        </div>

        <a
            href="products.php"
            class="btn btn-outline-success"
        >
            <i class="bi bi-arrow-left"></i>
            Continue Shopping
        </a>

    </div>

    <?php if ($cartConflict): ?>

        <div class="alert alert-danger">
            <strong>Cart conflict:</strong>
            your cart contains products from more than one store.
            Remove items until only one store remains before checkout.
        </div>

    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-8">

            <?php if (!$items): ?>

                <div class="section-card p-5 text-center">

                    <i class="bi bi-cart-x fs-1 text-muted"></i>

                    <h4 class="mt-3">
                        Your cart is empty
                    </h4>

                    <a
                        href="products.php"
                        class="btn btn-success mt-2"
                    >
                        Start Shopping
                    </a>

                </div>

            <?php endif; ?>

            <?php foreach ($items as $item): ?>

                <div class="cart-row">

                    <div class="row align-items-center g-3">

                        <div class="col-auto">
                            <img
                                class="cart-img"
                                src="<?= e(cartProductImage($item)) ?>"
                                alt="<?= e($item['name']) ?>"
                                onerror="this.onerror=null;this.src='/somame_ent/assets/images/product-placeholder.svg';"
                            >
                        </div>

                        <div class="col">

                            <div class="fw-bold">
                                <?= e($item['name']) ?>
                            </div>

                            <small class="text-muted">
                                GH₵<?= number_format((float)$item['price'], 2) ?>
                                /
                                <?= e($item['unit']) ?>
                            </small>

                        </div>

                        <div class="col-sm-3">

                            <input
                                class="form-control qty"
                                type="number"
                                min="1"
                                step="1"
                                max="<?= e($item['stock']) ?>"
                                value="<?= e($item['quantity']) ?>"
                                data-product="<?= (int)$item['product_id'] ?>"
                            >

                        </div>

                        <div class="col-sm-2 fw-bold">
                            GH₵<?= number_format(
                                (float)$item['price']
                                * (float)$item['quantity'],
                                2
                            ) ?>
                        </div>

                        <div class="col-auto">

                            <button
                                class="btn btn-outline-danger remove"
                                type="button"
                                data-product="<?= (int)$item['product_id'] ?>"
                            >
                                <i class="bi bi-trash"></i>
                            </button>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

        <div class="col-lg-4">

            <div class="summary">

                <h5 class="fw-bold mb-4">
                    Order Summary
                </h5>

                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal</span>
                    <strong>
                        GH₵<?= number_format($subtotal, 2) ?>
                    </strong>
                </div>

                <div class="d-flex justify-content-between mb-3">
                    <span>Delivery</span>
                    <strong>
                        GH₵<?= number_format($items ? $deliveryFee : 0, 2) ?>
                    </strong>
                </div>

                <hr>

                <div class="d-flex justify-content-between fs-5 mb-4">
                    <span>Total</span>
                    <strong>
                        GH₵<?= number_format($total, 2) ?>
                    </strong>
                </div>

                <a
                    href="checkout.php"
                    class="btn btn-success w-100 <?= (!$items || $cartConflict) ? 'disabled' : '' ?>"
                >
                    <i class="bi bi-lock me-1"></i>
                    Proceed to Checkout
                </a>

            </div>

        </div>

    </div>

</main>

<script>

async function cartPost(url, productId, quantity) {

    const formData =
        new FormData();

    formData.append(
        'product_id',
        productId
    );

    if (quantity !== undefined) {
        formData.append(
            'quantity',
            quantity
        );
    }

    const response =
        await fetch(
            url,
            {
                method: 'POST',
                body: formData
            }
        );

    return response.json();
}


document
    .querySelectorAll('.qty')
    .forEach(input => {

        input.addEventListener(
            'change',
            async () => {

                try {

                    const result =
                        await cartPost(
                            'api/update_cart.php',
                            input.dataset.product,
                            input.value
                        );

                    if (result.success) {

                        location.reload();

                    } else {

                        alert(
                            result.message
                            || 'Unable to update cart.'
                        );
                    }

                } catch (error) {

                    alert(
                        'Unable to update cart.'
                    );
                }
            }
        );
    });


document
    .querySelectorAll('.remove')
    .forEach(button => {

        button.addEventListener(
            'click',
            async () => {

                if (
                    !confirm(
                        'Remove this item from your cart?'
                    )
                ) {
                    return;
                }

                try {

                    const result =
                        await cartPost(
                            'api/remove_from_cart.php',
                            button.dataset.product
                        );

                    if (result.success) {

                        location.reload();

                    } else {

                        alert(
                            result.message
                            || 'Unable to remove item.'
                        );
                    }

                } catch (error) {

                    alert(
                        'Unable to remove item.'
                    );
                }
            }
        );
    });

</script>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>
