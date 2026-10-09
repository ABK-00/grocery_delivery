<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

function singleProductImage(array $product): string
{
    $placeholder =
        '/somame_ent/assets/images/product-placeholder.svg';

    $image = '';

    if (!empty($product['primary_image'])) {
        $image = $product['primary_image'];
    } elseif (!empty($product['image'])) {
        $image = $product['image'];
    }

    $image = trim((string)$image);

    if ($image === '') {
        return $placeholder;
    }

    if (
        str_starts_with($image, 'http://') ||
        str_starts_with($image, 'https://')
    ) {
        return $image;
    }

    $image = str_replace('\\', '/', $image);
    $image = ltrim($image, '/');

    if (str_starts_with($image, 'somame_ent/')) {
        return '/' . $image;
    }

    if (
        str_starts_with(
            $image,
            'assets/images/products/'
        )
    ) {
        return '/somame_ent/' . $image;
    }

    $filename = basename($image);

    $physicalFile =
        __DIR__
        . '/assets/images/products/'
        . $filename;

    if (is_file($physicalFile)) {
        return
            '/somame_ent/assets/images/products/'
            . rawurlencode($filename);
    }

    return $placeholder;
}

requireRole('customer');

$userId = currentUserId();

$productId = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
$storeSlug = trim($_GET['store'] ?? $_POST['store'] ?? '');

$error = '';
$message = '';
$cartConflict = null;


/*
|--------------------------------------------------------------------------
| VALIDATE REQUEST
|--------------------------------------------------------------------------
*/

if ($productId <= 0 || $storeSlug === '') {
    http_response_code(404);
    die('Product not found.');
}


/*
|--------------------------------------------------------------------------
| LOAD STORE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        c.id AS company_id,
        c.company_name,
        c.storefront_slug,
        c.status AS company_status,
        c.subscription_plan,
        c.trial_ends_at,
        c.subscription_ends_at,

        s.display_name,
        s.logo,
        s.primary_color,
        s.store_status

    FROM companies c

    LEFT JOIN company_storefronts s
        ON s.company_id = c.id

    WHERE c.storefront_slug = ?

    LIMIT 1
");

$stmt->execute([$storeSlug]);

$store = $stmt->fetch();

if (!$store) {
    http_response_code(404);
    die('Store not found.');
}

$companyId = (int)$store['company_id'];


/*
|--------------------------------------------------------------------------
| COMPANY STATUS
|--------------------------------------------------------------------------
*/

if ($store['company_status'] !== 'active') {
    http_response_code(403);
    die('This store is currently unavailable.');
}


/*
|--------------------------------------------------------------------------
| SUBSCRIPTION
|--------------------------------------------------------------------------
*/

$now = new DateTime();
$subscriptionValid = false;

if ($store['subscription_plan'] === 'trial') {

    if (
        !empty($store['trial_ends_at'])
        && new DateTime($store['trial_ends_at']) >= $now
    ) {
        $subscriptionValid = true;
    }
} elseif (
    in_array(
        $store['subscription_plan'],
        ['monthly', 'quarterly', 'yearly'],
        true
    )
) {

    if (
        !empty($store['subscription_ends_at'])
        && new DateTime($store['subscription_ends_at']) >= $now
    ) {
        $subscriptionValid = true;
    }
}

if (!$subscriptionValid) {
    http_response_code(403);
    die('This store is currently unavailable.');
}


/*
|--------------------------------------------------------------------------
| STORE DETAILS
|--------------------------------------------------------------------------
*/

$storeName =
    !empty($store['display_name'])
    ? $store['display_name']
    : $store['company_name'];

$storeOpen =
    ($store['store_status'] ?? 'open') === 'open';

$primaryColor =
    preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        (string)($store['primary_color'] ?? '')
    )
    ? $store['primary_color']
    : '#198754';


/*
|--------------------------------------------------------------------------
| LOAD PRODUCT
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.company_id,
        p.category_id,
        p.name,
        p.description,
        p.price,
        p.stock,
        p.unit,
        p.image,
        p.status,

        cat.name AS category_name,

        c.company_name,
        c.storefront_slug,

        COALESCE(
            NULLIF(cs.display_name, ''),
            c.company_name
        ) AS store_name,

        cs.store_status,

        (
            SELECT pi.image
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY
                pi.is_primary DESC,
                pi.sort_order ASC,
                pi.id ASC
            LIMIT 1
        ) AS primary_image

    FROM products p

    INNER JOIN companies c
        ON c.id = p.company_id

    LEFT JOIN company_storefronts cs
        ON cs.company_id = c.id

    LEFT JOIN categories cat
        ON cat.id = p.category_id
        AND cat.company_id = p.company_id

    WHERE p.id = ?
      AND p.company_id = ?
      AND p.status = 'active'

    LIMIT 1
");

$stmt->execute([
    $productId,
    $companyId
]);

$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    die('Product not found.');
}


/*
|--------------------------------------------------------------------------
| PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

function productPageImage(array $product): string
{
    $image =
        $product['primary_image']
        ?? $product['image']
        ?? null;

    if (!$image) {
        return 'assets/images/product-placeholder.svg';
    }

    $image = str_replace('\\', '/', trim($image));
    $image = ltrim($image, '/');

    if (
        str_starts_with($image, 'http://')
        || str_starts_with($image, 'https://')
    ) {
        return $image;
    }

    if (
        str_starts_with($image, 'uploads/')
        || str_starts_with($image, 'assets/')
    ) {
        return $image;
    }

    if (str_starts_with($image, 'products/')) {
        return 'uploads/' . $image;
    }

    return 'uploads/products/' . $image;
}

$productImage =
    singleProductImage($product);


/*
|--------------------------------------------------------------------------
| ADD TO CART
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'add_to_cart'
) {

    $quantity = (float)($_POST['quantity'] ?? 1);

    if (!$storeOpen) {

        $error = 'This store is currently closed.';
    } elseif ((float)$product['stock'] <= 0) {

        $error = 'This product is out of stock.';
    } elseif ($quantity <= 0) {

        $error = 'Invalid quantity.';
    } elseif ($quantity > (float)$product['stock']) {

        $error = 'Requested quantity exceeds available stock.';
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | CHECK EXISTING CART VENDOR
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    p.company_id,
                    c.company_name,
                    c.storefront_slug,
                    s.display_name

                FROM cart ca

                INNER JOIN products p
                    ON p.id = ca.product_id

                INNER JOIN companies c
                    ON c.id = p.company_id

                LEFT JOIN company_storefronts s
                    ON s.company_id = c.id

                WHERE ca.user_id = ?

                LIMIT 1
            ");

            $stmt->execute([$userId]);

            $existingCart = $stmt->fetch();


            /*
            |--------------------------------------------------------------------------
            | DIFFERENT VENDOR
            |--------------------------------------------------------------------------
            */

            if (
                $existingCart
                && (int)$existingCart['company_id'] !== $companyId
                && ($_POST['replace_cart'] ?? '') !== '1'
            ) {

                $existingStoreName =
                    !empty($existingCart['display_name'])
                    ? $existingCart['display_name']
                    : $existingCart['company_name'];

                $cartConflict = [
                    'store_name' => $existingStoreName
                ];
            } else {

                $conn->beginTransaction();


                /*
                 * User confirmed starting a new order
                 * with another vendor.
                 */
                if (
                    $existingCart
                    && (int)$existingCart['company_id'] !== $companyId
                    && ($_POST['replace_cart'] ?? '') === '1'
                ) {

                    $stmt = $conn->prepare("
                        DELETE FROM cart
                        WHERE user_id = ?
                    ");

                    $stmt->execute([$userId]);
                }


                /*
                |--------------------------------------------------------------------------
                | EXISTING PRODUCT IN CART
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT
                        id,
                        quantity
                    FROM cart
                    WHERE user_id = ?
                      AND product_id = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $userId,
                    $productId
                ]);

                $cartItem = $stmt->fetch();


                if ($cartItem) {

                    $newQuantity =
                        (float)$cartItem['quantity']
                        + $quantity;

                    if (
                        $newQuantity
                        > (float)$product['stock']
                    ) {

                        throw new RuntimeException(
                            'The total cart quantity exceeds available stock.'
                        );
                    }


                    $stmt = $conn->prepare("
                        UPDATE cart
                        SET quantity = ?
                        WHERE id = ?
                          AND user_id = ?
                    ");

                    $stmt->execute([
                        $newQuantity,
                        $cartItem['id'],
                        $userId
                    ]);
                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | NEW CART ITEM
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $conn->prepare("
                        INSERT INTO cart (
                            user_id,
                            product_id,
                            quantity
                        )
                        VALUES (?, ?, ?)
                    ");

                    $stmt->execute([
                        $userId,
                        $productId,
                        $quantity
                    ]);
                }


                $conn->commit();


                header(
                    'Location: /somame_ent/cart.php?added=1'
                );

                exit;
            }
        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| CART COUNT
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(quantity), 0)
    FROM cart
    WHERE user_id = ?
");

$stmt->execute([$userId]);

$cartCount = (int)$stmt->fetchColumn();

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        <?= htmlspecialchars($product['name']) ?>
        | <?= htmlspecialchars($storeName) ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <style>
        :root {
            --store-primary:
                <?= htmlspecialchars($primaryColor) ?>;
        }

        body {
            background: #f7f8fa;
            color: #17202a;
        }

        .topbar {
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .product-image-card {
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid #eceff2;
        }

        .product-main-image {
            width: 100%;
            height: 520px;
            object-fit: cover;
            display: block;
            background: #f3f4f6;
        }

        .product-info-card {
            background: #fff;
            border-radius: 24px;
            border: 1px solid #eceff2;
            padding: 32px;
        }

        .price {
            color: var(--store-primary);
            font-size: 2rem;
            font-weight: 800;
        }

        .btn-store {
            background: var(--store-primary);
            border-color: var(--store-primary);
            color: #fff;
        }

        .btn-store:hover {
            background: var(--store-primary);
            border-color: var(--store-primary);
            color: #fff;
            opacity: .9;
        }

        .quantity-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quantity-box button {
            width: 44px;
            height: 44px;
            border-radius: 12px;
        }

        .quantity-box input {
            width: 80px;
            height: 44px;
            text-align: center;
            border-radius: 12px;
        }

        .store-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #f3f4f6;
            font-size: .85rem;
        }

        @media (max-width: 767px) {
            .product-main-image {
                height: 330px;
            }
        }
    </style>

</head>

<body>


    <?php

    if (
        file_exists(
            __DIR__
                . '/includes/loader.php'
        )
    ) {
        include __DIR__ . '/includes/loader.php';
    }

    ?>


    <!-- NAV -->

    <nav class="topbar">

        <div class="container py-3">

            <div
                class="d-flex align-items-center justify-content-between gap-3">

                <a
                    href="/somame_ent/store.php?store=<?= urlencode($storeSlug) ?>"
                    class="btn btn-light border">

                    <i class="bi bi-arrow-left me-1"></i>

                    <span class="d-none d-sm-inline">
                        Back to Store
                    </span>

                </a>


                <div class="fw-bold">

                    <?= htmlspecialchars($storeName) ?>

                </div>


                <a
                    href="/somame_ent/cart.php"
                    class="btn btn-light border position-relative">

                    <i class="bi bi-cart3"></i>


                    <?php if ($cartCount > 0): ?>

                        <span
                            class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $cartCount ?>
                        </span>

                    <?php endif; ?>

                </a>

            </div>

        </div>

    </nav>


    <main class="container py-4 py-md-5">


        <?php if ($error): ?>

            <div class="alert alert-danger">

                <i class="bi bi-exclamation-circle me-1"></i>

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>


        <div class="row g-4 align-items-start">


            <!-- IMAGE -->

            <div class="col-lg-6">

                <div class="product-image-card">

                    <img
                        src="<?= htmlspecialchars($productImage) ?>"
                        alt="<?= htmlspecialchars($product['name']) ?>"
                        class="product-main-image"
                        onerror="
        this.onerror=null;
        this.src='/somame_ent/assets/images/product-placeholder.svg';
    ">

                </div>

            </div>


            <!-- INFO -->

            <div class="col-lg-6">

                <div class="product-info-card">


                    <div class="store-chip mb-3">

                        <i class="bi bi-shop"></i>

                        <?= htmlspecialchars($storeName) ?>

                    </div>


                    <?php if (!empty($product['category_name'])): ?>

                        <div
                            class="text-success fw-semibold small text-uppercase mb-2">
                            <?= htmlspecialchars($product['category_name']) ?>
                        </div>

                    <?php endif; ?>


                    <h1 class="fw-bold mb-3">

                        <?= htmlspecialchars($product['name']) ?>

                    </h1>


                    <div class="price">

                        GH₵
                        <?= number_format(
                            (float)$product['price'],
                            2
                        ) ?>

                    </div>


                    <div class="text-muted mb-4">

                        per
                        <?= htmlspecialchars($product['unit']) ?>

                    </div>


                    <?php if (!empty($product['description'])): ?>

                        <p class="text-muted">

                            <?= nl2br(
                                htmlspecialchars(
                                    $product['description']
                                )
                            ) ?>

                        </p>

                    <?php endif; ?>


                    <hr class="my-4">


                    <!-- STOCK -->

                    <div class="mb-4">

                        <?php if ((float)$product['stock'] > 0): ?>

                            <span class="badge text-bg-success">

                                <i class="bi bi-check-circle me-1"></i>

                                In Stock

                            </span>

                            <small class="text-muted ms-2">

                                <?= number_format(
                                    (float)$product['stock'],
                                    2
                                ) ?>

                                <?= htmlspecialchars($product['unit']) ?>

                                available

                            </small>

                        <?php else: ?>

                            <span class="badge text-bg-danger">

                                Out of Stock

                            </span>

                        <?php endif; ?>

                    </div>


                    <?php if (!$storeOpen): ?>

                        <div class="alert alert-warning">

                            <i class="bi bi-clock me-2"></i>

                            This store is currently closed.

                        </div>

                    <?php endif; ?>


                    <!-- ADD TO CART -->

                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="add_to_cart">

                        <input
                            type="hidden"
                            name="product_id"
                            value="<?= (int)$product['id'] ?>">

                        <input
                            type="hidden"
                            name="store"
                            value="<?= htmlspecialchars($storeSlug) ?>">


                        <label class="form-label fw-semibold">
                            Quantity
                        </label>


                        <div class="quantity-box mb-4">

                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                id="decreaseQuantity">
                                <i class="bi bi-dash"></i>
                            </button>


                            <input
                                type="number"
                                name="quantity"
                                id="quantity"
                                class="form-control"
                                value="1"
                                min="1"
                                max="<?= htmlspecialchars(
                                            (string)$product['stock']
                                        ) ?>"
                                step="1"
                                required>


                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                id="increaseQuantity">
                                <i class="bi bi-plus"></i>
                            </button>

                        </div>


                        <button
                            type="submit"
                            class="btn btn-store btn-lg w-100"
                            <?= (
                                !$storeOpen
                                || (float)$product['stock'] <= 0
                            )
                                ? 'disabled'
                                : '' ?>>

                            <i class="bi bi-cart-plus me-2"></i>

                            Add to Cart

                        </button>

                    </form>


                    <div
                        class="border rounded-4 p-3 mt-4 bg-light">

                        <div class="d-flex gap-3">

                            <i
                                class="bi bi-truck fs-4 text-success"></i>

                            <div>

                                <strong class="d-block">
                                    Delivery
                                </strong>

                                <small class="text-muted">
                                    Delivery details and fees will
                                    be confirmed during checkout.
                                </small>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>


    <!-- =========================================================
     CART CONFLICT MODAL
========================================================= -->

    <?php if ($cartConflict): ?>

        <div
            class="modal fade"
            id="cartConflictModal"
            tabindex="-1"
            data-bs-backdrop="static">

            <div
                class="modal-dialog modal-dialog-centered">

                <div class="modal-content">

                    <div class="modal-header">

                        <h5 class="modal-title">

                            <i
                                class="bi bi-cart-x text-warning me-2"></i>

                            Start a new order?

                        </h5>

                    </div>


                    <div class="modal-body">

                        <p>

                            Your cart currently contains items from

                            <strong>
                                <?= htmlspecialchars(
                                    $cartConflict['store_name']
                                ) ?>
                            </strong>.

                        </p>


                        <p class="mb-0 text-muted">

                            You can only order from one store at a time.
                            Starting an order from

                            <strong>
                                <?= htmlspecialchars($storeName) ?>
                            </strong>

                            will clear your current cart.

                        </p>

                    </div>


                    <div class="modal-footer">

                        <a
                            href="/somame_ent/cart.php"
                            class="btn btn-outline-secondary">
                            Keep Current Cart
                        </a>


                        <form method="POST">

                            <input
                                type="hidden"
                                name="action"
                                value="add_to_cart">

                            <input
                                type="hidden"
                                name="replace_cart"
                                value="1">

                            <input
                                type="hidden"
                                name="product_id"
                                value="<?= (int)$product['id'] ?>">

                            <input
                                type="hidden"
                                name="store"
                                value="<?= htmlspecialchars($storeSlug) ?>">

                            <input
                                type="hidden"
                                name="quantity"
                                value="<?= htmlspecialchars(
                                            $_POST['quantity'] ?? '1'
                                        ) ?>">


                            <button
                                type="submit"
                                class="btn btn-danger">

                                Clear Cart & Continue

                            </button>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


    <script>
        /*
|--------------------------------------------------------------------------
| QUANTITY
|--------------------------------------------------------------------------
*/

        const quantity =
            document.getElementById('quantity');

        const decrease =
            document.getElementById(
                'decreaseQuantity'
            );

        const increase =
            document.getElementById(
                'increaseQuantity'
            );


        if (
            quantity &&
            decrease &&
            increase
        ) {

            decrease.addEventListener(
                'click',
                function() {

                    let value =
                        parseInt(
                            quantity.value || '1',
                            10
                        );

                    value =
                        Math.max(
                            1,
                            value - 1
                        );

                    quantity.value =
                        value;
                }
            );


            increase.addEventListener(
                'click',
                function() {

                    let value =
                        parseInt(
                            quantity.value || '1',
                            10
                        );

                    const max =
                        parseInt(
                            quantity.max || '999999',
                            10
                        );

                    value =
                        Math.min(
                            max,
                            value + 1
                        );

                    quantity.value =
                        value;
                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CART CONFLICT
        |--------------------------------------------------------------------------
        */

        <?php if ($cartConflict): ?>

            const conflictModal =
                new bootstrap.Modal(
                    document.getElementById(
                        'cartConflictModal'
                    )
                );

            conflictModal.show();

        <?php endif; ?>
    </script>


</body>

</html>