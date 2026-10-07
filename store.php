<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$slug = trim($_GET['store'] ?? '');

if ($slug === '') {
    http_response_code(404);
    die('Store not found.');
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
        s.description,
        s.logo,
        s.banner_image,
        s.email,
        s.phone,
        s.whatsapp,
        s.address,
        s.primary_color,
        s.store_status,
        s.delivery_information

    FROM companies c

    LEFT JOIN company_storefronts s
        ON s.company_id = c.id

    WHERE c.storefront_slug = ?

    LIMIT 1
");

$stmt->execute([$slug]);

$store = $stmt->fetch();

if (!$store) {
    http_response_code(404);
    die('Store not found.');
}

$companyId = (int)$store['company_id'];


/*
|--------------------------------------------------------------------------
| ACCESS
|--------------------------------------------------------------------------
*/

$role = $_SESSION['role'] ?? '';

$previewMode = false;


/*
 * Customers can browse every active marketplace store.
 */
if ($role === 'customer') {

    if ($store['company_status'] !== 'active') {
        http_response_code(403);
        die('This store is currently unavailable.');
    }
}


/*
 * Company admin/staff may preview their own storefront.
 */ elseif (
    in_array(
        $role,
        ['admin', 'staff'],
        true
    )
) {

    if (
        (int)($_SESSION['company_id'] ?? 0)
        !== $companyId
    ) {

        redirectByRole();
    }

    $previewMode = true;
} else {

    redirectByRole();
}


/*
|--------------------------------------------------------------------------
| SUBSCRIPTION CHECK
|--------------------------------------------------------------------------
*/

if (!$previewMode) {

    $now = new DateTime();

    $subscriptionValid = false;

    if (
        $store['subscription_plan']
        === 'trial'
    ) {

        if (
            !empty($store['trial_ends_at'])
            && new DateTime(
                $store['trial_ends_at']
            ) >= $now
        ) {

            $subscriptionValid = true;
        }
    } elseif (
        in_array(
            $store['subscription_plan'],
            [
                'monthly',
                'quarterly',
                'yearly'
            ],
            true
        )
    ) {

        if (
            !empty($store['subscription_ends_at'])
            && new DateTime(
                $store['subscription_ends_at']
            ) >= $now
        ) {

            $subscriptionValid = true;
        }
    }


    if (!$subscriptionValid) {

        http_response_code(403);

        die('This store is currently unavailable.');
    }
}


/*
|--------------------------------------------------------------------------
| DISPLAY VALUES
|--------------------------------------------------------------------------
*/

$storeName =
    trim((string)($store['display_name'] ?? '')) !== ''
    ? $store['display_name']
    : $store['company_name'];

$primaryColor =
    preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        (string)($store['primary_color'] ?? '')
    )
    ? $store['primary_color']
    : '#198754';

$isOpen =
    ($store['store_status'] ?? 'open')
    === 'open';


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search =
    trim($_GET['q'] ?? '');

$categoryId =
    (int)($_GET['category'] ?? 0);


/*
|--------------------------------------------------------------------------
| CATEGORIES
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        c.id,
        c.name,

        COUNT(p.id) AS product_count

    FROM categories c

    LEFT JOIN products p
        ON p.category_id = c.id
        AND p.company_id = c.company_id
        AND p.status = 'active'

    WHERE c.company_id = ?
      AND c.status = 'active'

    GROUP BY
        c.id,
        c.name

    ORDER BY
        c.name ASC
");

$stmt->execute([$companyId]);

$categories =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| VALIDATE CATEGORY
|--------------------------------------------------------------------------
*/

if ($categoryId > 0) {

    $stmt = $conn->prepare("
        SELECT id
        FROM categories
        WHERE id = ?
          AND company_id = ?
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        $categoryId,
        $companyId
    ]);

    if (!$stmt->fetch()) {
        $categoryId = 0;
    }
}


/*
|--------------------------------------------------------------------------
| PRODUCTS
|--------------------------------------------------------------------------
*/

$sql = "
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

        c.name AS category_name,

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

    LEFT JOIN categories c
        ON c.id = p.category_id
        AND c.company_id = p.company_id

    WHERE p.company_id = ?
      AND p.status = 'active'
";

$params = [$companyId];


if ($categoryId > 0) {

    $sql .= "
        AND p.category_id = ?
    ";

    $params[] = $categoryId;
}


if ($search !== '') {

    $sql .= "
        AND (
            p.name LIKE ?
            OR p.description LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
}


$sql .= "
    ORDER BY
        p.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$products = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| CART COUNT
|--------------------------------------------------------------------------
*/

$cartCount = 0;

if ($role === 'customer') {

    $stmt = $conn->prepare("
        SELECT
            COALESCE(
                SUM(quantity),
                0
            )
        FROM cart
        WHERE user_id = ?
    ");

    $stmt->execute([
        currentUserId()
    ]);

    $cartCount =
        (int)$stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

function storefrontProductImage(
    array $product
): string {

    $image =
        $product['primary_image']
        ?? $product['image']
        ?? null;

    if (!$image) {
        return 'assets/images/product-placeholder.svg';
    }

    $image = str_replace(
        '\\',
        '/',
        trim($image)
    );

    $image = ltrim(
        $image,
        '/'
    );

    if (
        str_starts_with(
            $image,
            'http://'
        )
        || str_starts_with(
            $image,
            'https://'
        )
    ) {

        return $image;
    }

    if (
        str_starts_with(
            $image,
            'uploads/'
        )
        || str_starts_with(
            $image,
            'assets/'
        )
    ) {

        return $image;
    }

    if (
        str_starts_with(
            $image,
            'products/'
        )
    ) {

        return
            'uploads/'
            . $image;
    }

    return
        'uploads/products/'
        . $image;
}


/*
|--------------------------------------------------------------------------
| WHATSAPP
|--------------------------------------------------------------------------
*/

$whatsappUrl = '';

if (!empty($store['whatsapp'])) {

    $number = preg_replace(
        '/[^0-9]/',
        '',
        $store['whatsapp']
    );

    if ($number !== '') {

        $whatsappUrl =
            'https://wa.me/'
            . $number;
    }
}

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        <?= htmlspecialchars($storeName) ?>
        | Grocery Delivery
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
                <?= htmlspecialchars(
                    $primaryColor
                ) ?>;

            --market-dark: #111827;
        }

        body {
            background: #f7f8fa;
            color: #17202a;
        }


        /* NAV */

        .store-nav {
            background:
                rgba(255, 255, 255, .97);

            border-bottom:
                1px solid #e8eaed;

            position: sticky;

            top: 0;

            z-index: 1000;

            backdrop-filter:
                blur(10px);
        }


        .store-logo-small {
            width: 44px;
            height: 44px;

            border-radius: 12px;

            object-fit: cover;

            border:
                1px solid #eee;
        }


        .logo-placeholder-small {

            width: 44px;
            height: 44px;

            border-radius: 12px;

            display: flex;
            justify-content: center;
            align-items: center;

            color: #fff;

            background:
                var(--store-primary);
        }


        .cart-button {

            width: 44px;
            height: 44px;

            border-radius: 12px;

            display: flex;
            align-items: center;
            justify-content: center;
        }


        /* HERO */

        .store-hero {

            min-height: 340px;

            position: relative;

            background:
                linear-gradient(135deg,
                    var(--store-primary),
                    #111827);

            background-size: cover;

            background-position: center;

            display: flex;

            align-items: center;

            overflow: hidden;
        }


        .store-hero-overlay {

            position: absolute;

            inset: 0;

            background:
                linear-gradient(90deg,
                    rgba(0, 0, 0, .78),
                    rgba(0, 0, 0, .28));
        }


        .hero-content {

            position: relative;

            z-index: 2;

            color: white;

            max-width: 720px;
        }


        .hero-logo {

            width: 90px;
            height: 90px;

            border-radius: 22px;

            background: #fff;

            object-fit: cover;

            border:
                4px solid white;
        }


        /* CATEGORY */

        .category-scroll {

            display: flex;

            gap: 10px;

            overflow-x: auto;

            padding-bottom: 8px;

            scrollbar-width: thin;
        }


        .category-pill {

            white-space: nowrap;

            text-decoration: none;

            border:
                1px solid #dee2e6;

            color: #343a40;

            background: white;

            border-radius: 999px;

            padding:
                9px 16px;
        }


        .category-pill:hover,
        .category-pill.active {

            color: #fff;

            background:
                var(--store-primary);

            border-color:
                var(--store-primary);
        }


        /* PRODUCT */

        .product-card {

            border: 0;

            border-radius: 20px;

            background: #fff;

            height: 100%;

            overflow: hidden;

            box-shadow:
                0 7px 24px rgba(0, 0, 0, .06);

            transition:
                .2s ease;
        }


        .product-card:hover {

            transform:
                translateY(-4px);

            box-shadow:
                0 14px 35px rgba(0, 0, 0, .1);
        }


        .product-image {

            width: 100%;

            height: 220px;

            object-fit: cover;

            background: #f1f3f5;
        }


        .product-price {

            font-weight: 800;

            font-size: 1.12rem;

            color:
                var(--store-primary);
        }


        .btn-store {

            background:
                var(--store-primary);

            border-color:
                var(--store-primary);

            color: #fff;
        }


        .btn-store:hover {

            background:
                var(--store-primary);

            border-color:
                var(--store-primary);

            color: #fff;

            opacity: .9;
        }


        .store-info {

            background: #fff;

            border:
                1px solid #e9ecef;

            border-radius: 18px;

            height: 100%;
        }


        .preview-banner {

            background: #fff3cd;

            border-bottom:
                1px solid #ffe69c;

            padding: 10px;

            text-align: center;

            color: #664d03;

            font-size: .9rem;
        }


        footer {

            background: #111827;

            color:
                rgba(255, 255, 255, .75);
        }


        @media(max-width:767px) {

            .store-hero {

                min-height: 300px;
            }

            .product-image {

                height: 180px;
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

        include
            __DIR__
            . '/includes/loader.php';
    }

    ?>


    <?php if ($previewMode): ?>

        <div class="preview-banner">

            <i
                class="bi bi-eye me-1"></i>

            Storefront Preview Mode

        </div>

    <?php endif; ?>


    <!-- =========================================================
     NAV
========================================================= -->

    <nav class="store-nav">

        <div class="container py-3">

            <div
                class="d-flex align-items-center justify-content-between gap-3">


                <div
                    class="d-flex align-items-center gap-3">


                    <?php if ($role === 'customer'): ?>

                        <a
                            href="/somame_ent/marketplace.php"
                            class="btn btn-light border">

                            <i
                                class="bi bi-arrow-left"></i>

                        </a>

                    <?php else: ?>

                        <a
                            href="/somame_ent/admin/storefront.php"
                            class="btn btn-light border">

                            <i
                                class="bi bi-arrow-left"></i>

                        </a>

                    <?php endif; ?>


                    <a
                        href="/somame_ent/store.php?store=<?= urlencode($slug) ?>"
                        class="text-decoration-none text-dark">

                        <div
                            class="d-flex align-items-center gap-2">


                            <?php if (
                                !empty($store['logo'])
                            ): ?>

                                <img
                                    src="<?= htmlspecialchars(
                                                $store['logo']
                                            ) ?>"
                                    class="store-logo-small"
                                    alt="<?= htmlspecialchars(
                                                $storeName
                                            ) ?>">

                            <?php else: ?>

                                <div
                                    class="logo-placeholder-small">

                                    <i
                                        class="bi bi-shop"></i>

                                </div>

                            <?php endif; ?>


                            <div>

                                <strong
                                    class="d-block lh-sm">

                                    <?= htmlspecialchars(
                                        $storeName
                                    ) ?>

                                </strong>

                                <small
                                    class="text-muted">

                                    Storefront

                                </small>

                            </div>

                        </div>

                    </a>

                </div>


                <?php if ($role === 'customer'): ?>

                    <div
                        class="d-flex align-items-center gap-2">

                        <a
                            href="/somame_ent/cart.php"
                            class="btn btn-light border cart-button position-relative">

                            <i
                                class="bi bi-cart3"></i>


                            <?php if (
                                $cartCount > 0
                            ): ?>

                                <span
                                    class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">

                                    <?= $cartCount ?>

                                </span>

                            <?php endif; ?>

                        </a>


                        <a
                            href="/somame_ent/customer/dashboard.php"
                            class="btn btn-store d-none d-md-inline-block">

                            My Account

                        </a>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </nav>


    <!-- =========================================================
     HERO
========================================================= -->

    <section
        class="store-hero"

        <?php if (
            !empty($store['banner_image'])
        ): ?>

        style="
            background-image:
            url('<?= htmlspecialchars(
                        $store['banner_image']
                    ) ?>');
        "

        <?php endif; ?>>

        <div
            class="store-hero-overlay"></div>


        <div class="container">

            <div
                class="hero-content py-5">


                <?php if (
                    !empty($store['logo'])
                ): ?>

                    <img
                        src="<?= htmlspecialchars(
                                    $store['logo']
                                ) ?>"
                        class="hero-logo mb-3"
                        alt="<?= htmlspecialchars(
                                    $storeName
                                ) ?>">

                <?php endif; ?>


                <?php if ($isOpen): ?>

                    <span
                        class="badge rounded-pill text-bg-success mb-3">

                        <i
                            class="bi bi-circle-fill me-1"
                            style="font-size:.5rem;"></i>

                        Open for orders

                    </span>

                <?php else: ?>

                    <span
                        class="badge rounded-pill text-bg-secondary mb-3">

                        <i
                            class="bi bi-clock me-1"></i>

                        Currently closed

                    </span>

                <?php endif; ?>


                <h1
                    class="display-5 fw-bold">

                    <?= htmlspecialchars(
                        $storeName
                    ) ?>

                </h1>


                <?php if (
                    !empty($store['description'])
                ): ?>

                    <p class="lead mt-3 mb-0">

                        <?= nl2br(
                            htmlspecialchars(
                                $store['description']
                            )
                        ) ?>

                    </p>

                <?php endif; ?>


            </div>

        </div>

    </section>


    <!-- =========================================================
     STORE INFO
========================================================= -->

    <section class="py-4">

        <div class="container">

            <div class="row g-3">


                <?php if (
                    !empty($store['address'])
                ): ?>

                    <div class="col-md-4">

                        <div
                            class="store-info p-3">

                            <i
                                class="bi bi-geo-alt text-success me-2"></i>

                            <?= htmlspecialchars(
                                $store['address']
                            ) ?>

                        </div>

                    </div>

                <?php endif; ?>


                <?php if (
                    !empty($store['phone'])
                ): ?>

                    <div class="col-md-4">

                        <div
                            class="store-info p-3">

                            <i
                                class="bi bi-telephone text-success me-2"></i>

                            <?= htmlspecialchars(
                                $store['phone']
                            ) ?>

                        </div>

                    </div>

                <?php endif; ?>


                <?php if (
                    $whatsappUrl !== ''
                ): ?>

                    <div class="col-md-4">

                        <a
                            href="<?= htmlspecialchars(
                                        $whatsappUrl
                                    ) ?>"
                            target="_blank"
                            class="store-info p-3 d-block text-decoration-none text-dark">

                            <i
                                class="bi bi-whatsapp text-success me-2"></i>

                            Chat on WhatsApp

                        </a>

                    </div>

                <?php endif; ?>


            </div>

        </div>

    </section>


    <!-- =========================================================
     PRODUCTS
========================================================= -->

    <section
        class="py-4 pb-5"
        id="products">

        <div class="container">


            <div
                class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

                <div>

                    <small
                        class="text-success fw-bold text-uppercase">
                        Shop
                    </small>

                    <h2 class="fw-bold mb-0">
                        Products
                    </h2>

                </div>


                <form
                    method="GET"
                    class="d-flex gap-2">

                    <input
                        type="hidden"
                        name="store"
                        value="<?= htmlspecialchars(
                                    $slug
                                ) ?>">


                    <?php if (
                        $categoryId > 0
                    ): ?>

                        <input
                            type="hidden"
                            name="category"
                            value="<?= $categoryId ?>">

                    <?php endif; ?>


                    <input
                        type="search"
                        name="q"
                        class="form-control"
                        placeholder="Search products..."
                        value="<?= htmlspecialchars(
                                    $search
                                ) ?>">


                    <button
                        class="btn btn-store"
                        type="submit">

                        <i
                            class="bi bi-search"></i>

                    </button>

                </form>

            </div>


            <!-- CATEGORY FILTER -->

            <div
                class="category-scroll mb-4">

                <a
                    href="/somame_ent/store.php?store=<?= urlencode($slug) ?>#products"
                    class="category-pill <?= $categoryId === 0
                                                ? 'active'
                                                : '' ?>">
                    All
                </a>


                <?php foreach (
                    $categories as $category
                ): ?>

                    <a
                        href="/somame_ent/store.php?store=<?= urlencode($slug) ?>&category=<?= (int)$category['id'] ?>#products"
                        class="category-pill <?= $categoryId === (int)$category['id']
                                                    ? 'active'
                                                    : '' ?>">

                        <?= htmlspecialchars(
                            $category['name']
                        ) ?>

                        <span class="ms-1">

                            <?= (int)
                            $category['product_count'] ?>

                        </span>

                    </a>

                <?php endforeach; ?>

            </div>


            <?php if (
                !$isOpen
                && !$previewMode
            ): ?>

                <div
                    class="alert alert-warning">

                    <i
                        class="bi bi-clock me-2"></i>

                    This store is currently closed.
                    You can browse products, but ordering
                    is temporarily unavailable.

                </div>

            <?php endif; ?>


            <div class="row g-4">


                <?php foreach (
                    $products as $product
                ): ?>

                    <?php

                    $image =
                        storefrontProductImage(
                            $product
                        );

                    $inStock =
                        (float)
                        $product['stock']
                        > 0;

                    ?>


                    <div
                        class="col-6 col-lg-4 col-xl-3">

                        <article
                            class="product-card">


                            <div class="position-relative">

                                <img
                                    src="<?= htmlspecialchars(
                                                $image
                                            ) ?>"
                                    class="product-image"
                                    alt="<?= htmlspecialchars(
                                                $product['name']
                                            ) ?>"
                                    onerror="
                                    this.onerror=null;
                                    this.src='assets/images/product-placeholder.svg';
                                ">


                                <span
                                    class="position-absolute top-0 end-0 m-2 badge <?= $inStock
                                                                                        ? 'text-bg-success'
                                                                                        : 'text-bg-danger' ?>">

                                    <?= $inStock
                                        ? 'In Stock'
                                        : 'Out of Stock' ?>

                                </span>

                            </div>


                            <div class="p-3">


                                <?php if (
                                    !empty($product['category_name'])
                                ): ?>

                                    <small
                                        class="text-muted">

                                        <?= htmlspecialchars(
                                            $product['category_name']
                                        ) ?>

                                    </small>

                                <?php endif; ?>


                                <h5
                                    class="fw-bold mt-1">

                                    <?= htmlspecialchars(
                                        $product['name']
                                    ) ?>

                                </h5>


                                <?php if (
                                    !empty($product['description'])
                                ): ?>

                                    <p
                                        class="small text-muted">

                                        <?= htmlspecialchars(
                                            mb_strimwidth(
                                                $product['description'],
                                                0,
                                                80,
                                                '...'
                                            )
                                        ) ?>

                                    </p>

                                <?php endif; ?>


                                <div
                                    class="product-price">

                                    GH₵
                                    <?= number_format(
                                        (float)
                                        $product['price'],
                                        2
                                    ) ?>

                                </div>


                                <small
                                    class="text-muted">

                                    per
                                    <?= htmlspecialchars(
                                        $product['unit']
                                    ) ?>

                                </small>


                                <div class="mt-3">

                                    <?php if (
                                        $role === 'customer'
                                    ): ?>

                                        <a
                                            href="/somame_ent/product.php?id=<?= (int)$product['id'] ?>&store=<?= urlencode($slug) ?>"
                                            class="btn btn-store w-100 <?= (!$inStock || !$isOpen)
                                                                            ? 'disabled'
                                                                            : '' ?>">

                                            View Product

                                        </a>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary w-100"
                                            disabled>

                                            Preview Only

                                        </button>

                                    <?php endif; ?>

                                </div>


                            </div>

                        </article>

                    </div>

                <?php endforeach; ?>


                <?php if (!$products): ?>

                    <div class="col-12">

                        <div
                            class="text-center bg-white rounded-4 p-5">

                            <i
                                class="bi bi-bag-x display-4 text-muted"></i>

                            <h4 class="mt-3">
                                No products found
                            </h4>

                            <p
                                class="text-muted">

                                There are no products
                                matching this selection.

                            </p>

                        </div>

                    </div>

                <?php endif; ?>


            </div>

        </div>

    </section>


    <!-- =========================================================
     DELIVERY INFORMATION
========================================================= -->

    <?php if (
        !empty($store['delivery_information'])
    ): ?>

        <section class="pb-5">

            <div class="container">

                <div
                    class="store-info p-4">

                    <div class="row g-3 align-items-center">

                        <div
                            class="col-md-2 text-center">

                            <i
                                class="bi bi-truck display-4"
                                style="
                                color:
                                var(--store-primary);
                            "></i>

                        </div>


                        <div class="col-md-10">

                            <h4 class="fw-bold">
                                Delivery Information
                            </h4>

                            <p
                                class="text-muted mb-0">

                                <?= nl2br(
                                    htmlspecialchars(
                                        $store['delivery_information']
                                    )
                                ) ?>

                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </section>

    <?php endif; ?>


    <footer class="py-5">

        <div class="container">

            <div
                class="d-flex flex-wrap justify-content-between gap-3">

                <div>

                    <h5
                        class="text-white fw-bold">

                        <?= htmlspecialchars(
                            $storeName
                        ) ?>

                    </h5>

                    <small>
                        Available through Grocery Delivery Marketplace.
                    </small>

                </div>


                <?php if (
                    $role === 'customer'
                ): ?>

                    <a
                        href="/somame_ent/marketplace.php"
                        class="text-light text-decoration-none">

                        <i
                            class="bi bi-arrow-left me-1"></i>

                        Back to Marketplace

                    </a>

                <?php endif; ?>

            </div>

        </div>

    </footer>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>