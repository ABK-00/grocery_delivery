<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireRole('customer');
requireCompanyAccess();
$companyId = currentCompanyId();

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$productId || $productId <= 0) {
    http_response_code(404);
    die("Product not found.");
}

/*
|--------------------------------------------------------------------------
| Get Product
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        p.id,
        p.name,
        p.description,
        p.price,
        p.stock,
        p.unit,
        p.image,
        p.status,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.id
    WHERE p.id = ?
      AND p.company_id = ?
      AND p.status = 'active'
    LIMIT 1
");

$stmt->execute([$productId, $companyId]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    die("Product not found or unavailable.");
}

/*
|--------------------------------------------------------------------------
| Get Product Images
|--------------------------------------------------------------------------
*/
$imageStmt = $conn->prepare("
    SELECT id, image, is_primary, sort_order
    FROM product_images
    WHERE product_id = ?
    ORDER BY is_primary DESC, sort_order ASC, id ASC
");

$imageStmt->execute([$productId]);
$images = $imageStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Fallback to old products.image field
|--------------------------------------------------------------------------
*/
if (empty($images) && !empty($product['image'])) {
    $images[] = [
        'id' => 0,
        'image' => $product['image'],
        'is_primary' => 1,
        'sort_order' => 0
    ];
}

if (empty($images)) {
    $images[] = [
        'id' => 0,
        'image' => null,
        'is_primary' => 1,
        'sort_order' => 0
    ];
}

$mainImage = $images[0]['image'];

/*
|--------------------------------------------------------------------------
| Related Products
|--------------------------------------------------------------------------
*/
$relatedStmt = $conn->prepare("
    SELECT
        p.id,
        p.name,
        p.price,
        p.stock,
        p.unit,
        p.image
    FROM products p
    WHERE p.status = 'active'
      AND p.company_id = ?
      AND p.id != ?
      AND (
          p.category_id = (
              SELECT category_id
              FROM products
              WHERE id = ? AND company_id = ?
          )
          OR p.category_id IS NULL
      )
    ORDER BY p.created_at DESC
    LIMIT 4
");

$relatedStmt->execute([$companyId, $productId, $productId, $companyId]);
$relatedProducts = $relatedStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function productImage($filename)
{
    if (!$filename) {
        return 'assets/images/product-placeholder.png';
    }

    return 'assets/images/products/' . rawurlencode($filename);
}

$formattedPrice = number_format((float)$product['price'], 2);

$stock = (float)$product['stock'];

if ($stock <= 0) {
    $stockText = 'Out of stock';
    $stockClass = 'out';
} elseif ($stock <= 5) {
    $stockText = 'Only ' . rtrim(rtrim(number_format($stock, 2), '0'), '.') . ' left';
    $stockClass = 'low';
} else {
    $stockText = 'In stock';
    $stockClass = 'available';
}

$maxQuantity = max(1, (int)floor($stock));

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title><?= e($product['name']) ?> | GroceryDelivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <style>
        :root {
            --green: #16a34a;
            --dark-green: #15803d;
            --navy: #0f172a;
            --light-bg: #f8fafc;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--light-bg);
            color: #1e293b;
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        /* NAVBAR */

        .navbar {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
        }

        .navbar-brand {
            font-weight: 800;
            color: var(--navy) !important;
            font-size: 1.35rem;
        }

        .navbar-brand span {
            color: var(--green);
        }

        .nav-link {
            color: #475569 !important;
            font-weight: 600;
        }

        .nav-link:hover {
            color: var(--green) !important;
        }

        .cart-btn {
            position: relative;
        }

        .cart-badge {
            position: absolute;
            top: -7px;
            right: -8px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            min-width: 19px;
            height: 19px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* PAGE */

        .product-section {
            padding: 45px 0 70px;
        }

        .breadcrumb {
            font-size: 14px;
        }

        .breadcrumb a {
            color: var(--green);
            text-decoration: none;
            font-weight: 600;
        }

        /* PRODUCT CARD */

        .product-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid var(--border);
            padding: 28px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
        }

        /* IMAGE AREA */

        .main-image-box {
            width: 100%;
            height: 470px;
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .main-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 25px;
        }

        .placeholder-image {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 70px;
        }

        .thumbnail-container {
            display: flex;
            gap: 12px;
            margin-top: 15px;
            overflow-x: auto;
            padding-bottom: 5px;
        }

        .thumbnail {
            width: 78px;
            height: 78px;
            border: 2px solid transparent;
            border-radius: 12px;
            overflow: hidden;
            background: #f8fafc;
            flex-shrink: 0;
            padding: 0;
            cursor: pointer;
        }

        .thumbnail.active {
            border-color: var(--green);
        }

        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* PRODUCT INFO */

        .category-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 30px;
            background: #dcfce7;
            color: #15803d;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .product-title {
            font-size: 2.25rem;
            line-height: 1.15;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 15px;
        }

        .product-description {
            color: #64748b;
            line-height: 1.8;
            font-size: 15px;
        }

        .price {
            font-size: 2rem;
            font-weight: 800;
            color: var(--green);
        }

        .unit {
            color: #64748b;
            font-size: 14px;
        }

        .stock-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 10px;
            font-size: 14px;
            font-weight: 700;
        }

        .stock-status.available {
            color: #16a34a;
        }

        .stock-status.low {
            color: #d97706;
        }

        .stock-status.out {
            color: #dc2626;
        }

        /* QUANTITY */

        .quantity-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 25px;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .quantity-control button {
            width: 42px;
            height: 42px;
            border: 0;
            background: #f8fafc;
            font-size: 18px;
        }

        .quantity-control button:hover {
            background: #dcfce7;
        }

        .quantity-control input {
            width: 55px;
            height: 42px;
            border: 0;
            text-align: center;
            font-weight: 700;
            outline: none;
        }

        /* BUTTON */

        .add-cart-btn {
            margin-top: 22px;
            width: 100%;
            border: 0;
            background: var(--green);
            color: #fff;
            padding: 14px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 16px;
            transition: .2s ease;
        }

        .add-cart-btn:hover {
            background: var(--dark-green);
            transform: translateY(-1px);
        }

        .add-cart-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }

        /* FEATURES */

        .product-features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 25px;
        }

        .feature {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 13px 10px;
            text-align: center;
        }

        .feature i {
            display: block;
            color: var(--green);
            font-size: 21px;
            margin-bottom: 5px;
        }

        .feature span {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
        }

        /* RELATED */

        .related-section {
            margin-top: 45px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 20px;
        }

        .related-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 15px;
            overflow: hidden;
            height: 100%;
            transition: .2s ease;
        }

        .related-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(15, 23, 42, .08);
        }

        .related-image {
            height: 190px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .related-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .related-body {
            padding: 15px;
        }

        .related-name {
            font-weight: 700;
            color: var(--navy);
            text-decoration: none;
            display: block;
            margin-bottom: 8px;
        }

        .related-price {
            color: var(--green);
            font-size: 17px;
            font-weight: 800;
        }

        /* MOBILE */

        @media (max-width: 767px) {

            .product-section {
                padding-top: 25px;
            }

            .product-card {
                padding: 18px;
            }

            .main-image-box {
                height: 330px;
            }

            .product-title {
                font-size: 1.8rem;
                margin-top: 25px;
            }

            .product-features {
                grid-template-columns: 1fr;
            }

        }
    </style>

    <link href="assets/css/customer.css" rel="stylesheet">
</head>

<body>
    <?php include __DIR__ . '/includes/loader.php'; ?>

    <!-- NAVBAR -->

    <nav class="navbar navbar-expand-lg sticky-top">

        <div class="container">

            <a class="navbar-brand" href="index.php">
                Grocery<span>Delivery</span>
            </a>

            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#mainNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div
                class="collapse navbar-collapse"
                id="mainNavbar">

                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">

                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            Home
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="products.php">
                            Products
                        </a>
                    </li>

                    <li class="nav-item">

                        <a
                            class="nav-link cart-btn"
                            href="cart.php">

                            <i class="bi bi-cart3 fs-5"></i>

                            <span
                                class="cart-badge"
                                id="cartBadge">0</span>

                        </a>

                    </li>

                </ul>

            </div>

        </div>

    </nav>


    <!-- PRODUCT -->

    <main class="product-section">

        <div class="container">

            <nav aria-label="breadcrumb" class="mb-4">

                <ol class="breadcrumb">

                    <li class="breadcrumb-item">
                        <a href="index.php">Home</a>
                    </li>

                    <li class="breadcrumb-item">
                        <a href="products.php">Products</a>
                    </li>

                    <li class="breadcrumb-item active">
                        <?= e($product['name']) ?>
                    </li>

                </ol>

            </nav>


            <div class="product-card">

                <div class="row g-4">

                    <!-- IMAGES -->

                    <div class="col-lg-6">

                        <div class="main-image-box">

                            <?php if ($mainImage): ?>

                                <img
                                    src="<?= e(productImage($mainImage)) ?>"
                                    alt="<?= e($product['name']) ?>"
                                    class="main-image"
                                    id="mainProductImage">

                            <?php else: ?>

                                <div class="placeholder-image">
                                    <i class="bi bi-image"></i>
                                </div>

                            <?php endif; ?>

                        </div>


                        <?php if (count($images) > 1): ?>

                            <div class="thumbnail-container">

                                <?php foreach ($images as $index => $img): ?>

                                    <?php if (!empty($img['image'])): ?>

                                        <button
                                            type="button"
                                            class="thumbnail <?= $index === 0 ? 'active' : '' ?>"
                                            onclick="changeImage(
                                            this,
                                            '<?= e(productImage($img['image'])) ?>'
                                        )">

                                            <img
                                                src="<?= e(productImage($img['image'])) ?>"
                                                alt="<?= e($product['name']) ?>">

                                        </button>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- DETAILS -->

                    <div class="col-lg-6">

                        <?php if (!empty($product['category_name'])): ?>

                            <div class="category-label">

                                <i class="bi bi-tag-fill"></i>

                                <?= e($product['category_name']) ?>

                            </div>

                        <?php endif; ?>


                        <h1 class="product-title">
                            <?= e($product['name']) ?>
                        </h1>


                        <?php if (!empty($product['description'])): ?>

                            <p class="product-description">
                                <?= nl2br(e($product['description'])) ?>
                            </p>

                        <?php endif; ?>


                        <div class="mt-4">

                            <span class="price">
                                GH₵ <?= $formattedPrice ?>
                            </span>

                            <span class="unit">
                                / <?= e($product['unit']) ?>
                            </span>

                        </div>


                        <div class="stock-status <?= $stockClass ?>">

                            <?php if ($stockClass === 'available'): ?>

                                <i class="bi bi-check-circle-fill"></i>

                            <?php elseif ($stockClass === 'low'): ?>

                                <i class="bi bi-exclamation-circle-fill"></i>

                            <?php else: ?>

                                <i class="bi bi-x-circle-fill"></i>

                            <?php endif; ?>

                            <?= e($stockText) ?>

                        </div>


                        <?php if ($stock > 0): ?>

                            <div class="quantity-wrapper">

                                <strong>Quantity:</strong>

                                <div class="quantity-control">

                                    <button
                                        type="button"
                                        onclick="decreaseQuantity()">
                                        −
                                    </button>

                                    <input
                                        type="number"
                                        id="quantity"
                                        value="1"
                                        min="1"
                                        max="<?= $maxQuantity ?>"
                                        readonly>

                                    <button
                                        type="button"
                                        onclick="increaseQuantity()">
                                        +
                                    </button>

                                </div>

                            </div>


                            <button
                                type="button"
                                class="add-cart-btn"
                                onclick="addToCart()">

                                <i class="bi bi-cart-plus me-2"></i>

                                Add to Cart

                            </button>

                        <?php else: ?>

                            <button
                                type="button"
                                class="add-cart-btn"
                                disabled>

                                <i class="bi bi-x-circle me-2"></i>

                                Out of Stock

                            </button>

                        <?php endif; ?>


                        <div class="product-features">

                            <div class="feature">

                                <i class="bi bi-truck"></i>

                                <span>
                                    Fast Delivery
                                </span>

                            </div>

                            <div class="feature">

                                <i class="bi bi-shield-check"></i>

                                <span>
                                    Quality Products
                                </span>

                            </div>

                            <div class="feature">

                                <i class="bi bi-headset"></i>

                                <span>
                                    Customer Support
                                </span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- RELATED PRODUCTS -->

            <?php if (!empty($relatedProducts)): ?>

                <section class="related-section">

                    <h2 class="section-title">
                        You May Also Like
                    </h2>

                    <div class="row g-4">

                        <?php foreach ($relatedProducts as $related): ?>

                            <div class="col-6 col-md-4 col-lg-3">

                                <div class="related-card">

                                    <a
                                        href="product.php?id=<?= (int)$related['id'] ?>"
                                        class="text-decoration-none">

                                        <div class="related-image">

                                            <?php if (!empty($related['image'])): ?>

                                                <img
                                                    src="<?= e(productImage($related['image'])) ?>"
                                                    alt="<?= e($related['name']) ?>">

                                            <?php else: ?>

                                                <i
                                                    class="bi bi-image"
                                                    style="font-size:50px;color:#cbd5e1;"></i>

                                            <?php endif; ?>

                                        </div>

                                    </a>


                                    <div class="related-body">

                                        <a
                                            href="product.php?id=<?= (int)$related['id'] ?>"
                                            class="related-name">
                                            <?= e($related['name']) ?>
                                        </a>

                                        <div class="related-price">

                                            GH₵
                                            <?= number_format((float)$related['price'], 2) ?>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </section>

            <?php endif; ?>

        </div>

    </main>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


    <script>
        /*
|--------------------------------------------------------------------------
| Image Gallery
|--------------------------------------------------------------------------
*/

        function changeImage(button, imageUrl) {
            const mainImage = document.getElementById('mainProductImage');

            if (mainImage) {
                mainImage.src = imageUrl;
            }

            document
                .querySelectorAll('.thumbnail')
                .forEach(function(item) {
                    item.classList.remove('active');
                });

            button.classList.add('active');
        }


        /*
        |--------------------------------------------------------------------------
        | Quantity
        |--------------------------------------------------------------------------
        */

        function increaseQuantity() {
            const input = document.getElementById('quantity');

            if (!input) {
                return;
            }

            let quantity = parseInt(input.value) || 1;

            const max = parseInt(input.max) || 999999;

            if (quantity < max) {
                quantity++;
            }

            input.value = quantity;
        }


        function decreaseQuantity() {
            const input = document.getElementById('quantity');

            if (!input) {
                return;
            }

            let quantity = parseInt(input.value) || 1;

            if (quantity > 1) {
                quantity--;
            }

            input.value = quantity;
        }


        /*
        |--------------------------------------------------------------------------
        | Cart
        |--------------------------------------------------------------------------
        |
        | For now this stores the cart in localStorage.
        | We will replace this with the MySQL cart system when cart.php
        | and the cart API are connected.
        |
        */

        function addToCart() {
            const quantityInput = document.getElementById('quantity');

            if (!quantityInput) {
                return;
            }

            const quantity = parseInt(quantityInput.value) || 1;

            const product = {
                id: <?= (int)$product['id'] ?>,
                name: <?= json_encode($product['name']) ?>,
                price: <?= (float)$product['price'] ?>,
                unit: <?= json_encode($product['unit']) ?>,
                image: <?= json_encode($mainImage) ?>,
                quantity: quantity
            };

            let cart = JSON.parse(
                localStorage.getItem('grocery_cart') || '[]'
            );

            const existingIndex = cart.findIndex(function(item) {
                return parseInt(item.id) === product.id;
            });

            if (existingIndex !== -1) {

                cart[existingIndex].quantity += quantity;

            } else {

                cart.push(product);

            }

            localStorage.setItem(
                'grocery_cart',
                JSON.stringify(cart)
            );

            updateCartBadge();

            alert(
                product.name +
                ' has been added to your cart.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Cart Badge
        |--------------------------------------------------------------------------
        */

        function updateCartBadge() {
            const badge = document.getElementById('cartBadge');

            if (!badge) {
                return;
            }

            const cart = JSON.parse(
                localStorage.getItem('grocery_cart') || '[]'
            );

            let total = 0;

            cart.forEach(function(item) {
                total += parseInt(item.quantity) || 0;
            });

            badge.textContent = total;
        }


        document.addEventListener(
            'DOMContentLoaded',
            updateCartBadge
        );
    </script>

</body>

</html>