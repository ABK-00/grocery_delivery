<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('customer');

$userId = currentUserId();


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function money($amount): string
{
    return 'GH₵ ' . number_format(
        (float)$amount,
        2
    );
}


function marketplaceProductImage(array $product): string
{
    $placeholder =
        '/somame_ent/assets/images/product-placeholder.svg';

    $image = '';

    if (!empty($product['primary_image'])) {

        $image =
            $product['primary_image'];
    } elseif (!empty($product['image'])) {

        $image =
            $product['image'];
    }


    $image =
        trim((string)$image);


    if ($image === '') {
        return $placeholder;
    }


    if (
        str_starts_with($image, 'http://')
        || str_starts_with($image, 'https://')
    ) {
        return $image;
    }


    $image =
        str_replace(
            '\\',
            '/',
            $image
        );

    $image =
        ltrim(
            $image,
            '/'
        );


    if (
        str_starts_with(
            $image,
            'somame_ent/'
        )
    ) {

        return '/' . $image;
    }


    if (
        str_starts_with(
            $image,
            'assets/images/products/'
        )
    ) {

        return
            '/somame_ent/'
            . $image;
    }


    $filename =
        basename($image);


    $physicalFile =
        __DIR__
        . '/assets/images/products/'
        . $filename;


    if (
        is_file(
            $physicalFile
        )
    ) {

        return
            '/somame_ent/assets/images/products/'
            . rawurlencode($filename);
    }


    return $placeholder;
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search =
    trim(
        $_GET['q'] ?? ''
    );


$category =
    trim(
        $_GET['category'] ?? ''
    );


$store =
    trim(
        $_GET['store'] ?? ''
    );


/*
|--------------------------------------------------------------------------
| LOAD MARKETPLACE CATEGORIES
|--------------------------------------------------------------------------
|
| Categories are filtered by NAME rather than ID because every vendor
| owns its own category records.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT DISTINCT
        cat.name

    FROM categories cat

    INNER JOIN companies c
        ON c.id = cat.company_id

    WHERE cat.status = 'active'

      AND c.status = 'active'

      AND (
            (
                c.subscription_plan = 'trial'
                AND c.trial_ends_at IS NOT NULL
                AND c.trial_ends_at >= NOW()
            )

            OR

            (
                c.subscription_plan IN (
                    'monthly',
                    'quarterly',
                    'yearly'
                )
                AND c.subscription_ends_at IS NOT NULL
                AND c.subscription_ends_at >= NOW()
            )
      )

    ORDER BY cat.name ASC
");

$stmt->execute();

$categories =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| LOAD STORES FOR FILTER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        c.id,
        c.company_name,
        c.storefront_slug,

        COALESCE(
            NULLIF(cs.display_name, ''),
            c.company_name
        ) AS store_name

    FROM companies c

    LEFT JOIN company_storefronts cs
        ON cs.company_id = c.id

    WHERE c.status = 'active'

      AND (
            (
                c.subscription_plan = 'trial'
                AND c.trial_ends_at IS NOT NULL
                AND c.trial_ends_at >= NOW()
            )

            OR

            (
                c.subscription_plan IN (
                    'monthly',
                    'quarterly',
                    'yearly'
                )
                AND c.subscription_ends_at IS NOT NULL
                AND c.subscription_ends_at >= NOW()
            )
      )

    ORDER BY store_name ASC
");

$stmt->execute();

$stores =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| LOAD PRODUCTS ACROSS ALL ACTIVE VENDORS
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

        cat.name AS category_name,

        c.company_name,
        c.storefront_slug,

        COALESCE(
            NULLIF(cs.display_name, ''),
            c.company_name
        ) AS store_name,

        COALESCE(
            cs.store_status,
            'open'
        ) AS store_status,

        cs.address AS store_address,

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

    WHERE p.status = 'active'

      AND c.status = 'active'

      AND (
            (
                c.subscription_plan = 'trial'
                AND c.trial_ends_at IS NOT NULL
                AND c.trial_ends_at >= NOW()
            )

            OR

            (
                c.subscription_plan IN (
                    'monthly',
                    'quarterly',
                    'yearly'
                )
                AND c.subscription_ends_at IS NOT NULL
                AND c.subscription_ends_at >= NOW()
            )
      )
";


$params = [];


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            p.name LIKE ?
            OR p.description LIKE ?
            OR cat.name LIKE ?
            OR c.company_name LIKE ?
            OR cs.display_name LIKE ?
        )
    ";


    $like =
        '%' . $search . '%';


    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}


/*
|--------------------------------------------------------------------------
| CATEGORY FILTER
|--------------------------------------------------------------------------
*/

if ($category !== '') {

    $sql .= "
        AND cat.name = ?
    ";

    $params[] =
        $category;
}


/*
|--------------------------------------------------------------------------
| STORE FILTER
|--------------------------------------------------------------------------
*/

if ($store !== '') {

    $sql .= "
        AND c.storefront_slug = ?
    ";

    $params[] =
        $store;
}


$sql .= "
    ORDER BY
        CASE
            WHEN COALESCE(
                cs.store_status,
                'open'
            ) = 'open'
            THEN 0
            ELSE 1
        END ASC,

        p.created_at DESC
";


$stmt =
    $conn->prepare($sql);

$stmt->execute($params);

$products =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| CART COUNT
|--------------------------------------------------------------------------
*/

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
    $userId
]);

$cartCount =
    (int)$stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| CUSTOMER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        name

    FROM users

    WHERE id = ?
      AND role = 'customer'

    LIMIT 1
");

$stmt->execute([
    $userId
]);

$customer =
    $stmt->fetch();


$customerName =
    $customer['name']
    ?? 'Customer';

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        Browse Products | Grocery Delivery
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">


    <style>
        :root {

            --green: #198754;
            --green-dark: #146c43;
            --page-bg: #f7f8fa;
            --border: #e9ecef;
            --text: #182230;
            --muted: #667085;

        }


        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            background:
                var(--page-bg);

            color:
                var(--text);

            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

        }


        a {
            text-decoration: none;
        }


        /*
        |--------------------------------------------------------------------------
        | NAVBAR
        |--------------------------------------------------------------------------
        */

        .market-nav {

            position: sticky;

            top: 0;

            z-index: 1000;

            background:
                rgba(255,
                    255,
                    255,
                    .97);

            backdrop-filter:
                blur(12px);

            border-bottom:
                1px solid var(--border);

        }


        .brand-icon {

            width: 42px;
            height: 42px;

            border-radius: 13px;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                var(--green);

            color: #fff;

            font-size: 1.15rem;

        }


        .nav-link-custom {

            color:
                var(--muted);

            font-weight:
                500;

        }


        .nav-link-custom:hover,
        .nav-link-custom.active {

            color:
                var(--green);

        }


        /*
        |--------------------------------------------------------------------------
        | HERO
        |--------------------------------------------------------------------------
        */

        .page-hero {

            margin-top: 25px;

            padding:
                35px;

            border-radius:
                25px;

            color:
                #fff;

            background:
                linear-gradient(135deg,
                    #101828,
                    #19392b);

            overflow:
                hidden;

            position:
                relative;

        }


        .page-hero::after {

            content: "";

            position:
                absolute;

            width:
                280px;

            height:
                280px;

            right:
                -80px;

            top:
                -110px;

            border-radius:
                50%;

            background:
                rgba(25,
                    135,
                    84,
                    .25);

        }


        .hero-content {

            position:
                relative;

            z-index:
                2;

        }


        /*
        |--------------------------------------------------------------------------
        | FILTERS
        |--------------------------------------------------------------------------
        */

        .filter-card {

            background:
                #fff;

            border:
                1px solid var(--border);

            border-radius:
                20px;

            padding:
                18px;

        }


        .form-control,
        .form-select {

            min-height:
                48px;

            border-radius:
                12px;

        }


        .form-control:focus,
        .form-select:focus {

            border-color:
                var(--green);

            box-shadow:
                0 0 0 .2rem rgba(25,
                    135,
                    84,
                    .10);

        }


        .btn-search {

            min-height:
                48px;

            border-radius:
                12px;

            background:
                var(--green);

            color:
                #fff;

            border-color:
                var(--green);

        }


        .btn-search:hover {

            background:
                var(--green-dark);

            border-color:
                var(--green-dark);

            color:
                #fff;

        }


        .category-strip {

            display:
                flex;

            gap:
                9px;

            overflow-x:
                auto;

            padding-bottom:
                4px;

            scrollbar-width:
                thin;

        }


        .category-pill {

            white-space:
                nowrap;

            border:
                1px solid var(--border);

            background:
                #fff;

            color:
                #475467;

            padding:
                8px 14px;

            border-radius:
                999px;

            font-size:
                .86rem;

        }


        .category-pill:hover,
        .category-pill.active {

            color:
                #fff;

            background:
                var(--green);

            border-color:
                var(--green);

        }


        /*
        |--------------------------------------------------------------------------
        | PRODUCT CARDS
        |--------------------------------------------------------------------------
        */

        .product-card {

            height:
                100%;

            background:
                #fff;

            border:
                1px solid var(--border);

            border-radius:
                20px;

            overflow:
                hidden;

            transition:
                .2s ease;

        }


        .product-card:hover {

            transform:
                translateY(-4px);

            box-shadow:
                0 16px 35px rgba(16,
                    24,
                    40,
                    .08);

        }


        .product-image-wrap {

            position:
                relative;

            overflow:
                hidden;

            background:
                #f2f4f7;

        }


        .product-image {

            width:
                100%;

            height:
                220px;

            object-fit:
                cover;

            display:
                block;

            transition:
                transform .3s ease;

        }


        .product-card:hover .product-image {

            transform:
                scale(1.04);

        }


        .store-status {

            position:
                absolute;

            top:
                12px;

            right:
                12px;

            padding:
                6px 9px;

            border-radius:
                999px;

            font-size:
                .72rem;

            font-weight:
                700;

            background:
                rgba(255,
                    255,
                    255,
                    .94);

            box-shadow:
                0 4px 15px rgba(0,
                    0,
                    0,
                    .08);

        }


        .product-body {

            padding:
                18px;

        }


        .store-name {

            color:
                var(--green);

            font-weight:
                650;

            font-size:
                .82rem;

        }


        .product-name {
            font-weight: 750;
            font-size: 1rem;
            color: var(--text);

            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;

            min-height: 48px;
        }

        .description {
            color: var(--muted);
            font-size: .84rem;

            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;

            min-height: 40px;
        }


        .price {

            color:
                var(--green);

            font-size:
                1.1rem;

            font-weight:
                800;

        }


        .unit {

            color:
                var(--muted);

            font-size:
                .78rem;

        }


        .view-btn {

            border-radius:
                10px;

            background:
                var(--green);

            border-color:
                var(--green);

            color:
                #fff;

        }


        .view-btn:hover {

            background:
                var(--green-dark);

            border-color:
                var(--green-dark);

            color:
                #fff;

        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY
        |--------------------------------------------------------------------------
        */

        .empty-state {

            padding:
                70px 25px;

            text-align:
                center;

            background:
                #fff;

            border:
                1px solid var(--border);

            border-radius:
                22px;

        }


        .empty-icon {

            width:
                75px;

            height:
                75px;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            margin:
                0 auto 18px;

            border-radius:
                22px;

            background:
                #f2f4f7;

            font-size:
                1.8rem;

            color:
                #667085;

        }


        @media(max-width:767px) {

            .page-hero {

                padding:
                    27px 22px;

            }


            .product-image {

                height:
                    180px;

            }


            .desktop-name {

                display:
                    none;

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


    <!-- =========================================================
     NAVIGATION
========================================================= -->

    <nav class="market-nav">

        <div class="container">

            <div
                class="d-flex justify-content-between align-items-center py-3 gap-3">


                <a
                    href="/somame_ent/marketplace.php"
                    class="d-flex align-items-center gap-2 text-dark">

                    <div class="brand-icon">

                        <i
                            class="bi bi-basket2-fill"></i>

                    </div>


                    <div>

                        <strong
                            class="d-block lh-1">
                            Grocery Delivery
                        </strong>

                        <small
                            class="text-muted">
                            Marketplace
                        </small>

                    </div>

                </a>


                <div
                    class="d-none d-md-flex align-items-center gap-4">

                    <a
                        href="/somame_ent/marketplace.php"
                        class="nav-link-custom">
                        Stores
                    </a>

                    <a
                        href="/somame_ent/products.php"
                        class="nav-link-custom active">
                        Products
                    </a>

                    <a
                        href="/somame_ent/orders.php"
                        class="nav-link-custom">
                        Orders
                    </a>

                </div>


                <div
                    class="d-flex align-items-center gap-2">


                    <span
                        class="desktop-name text-muted small">

                        Hi,
                        <?= h(
                            $customerName
                        ) ?>

                    </span>


                    <a
                        href="/somame_ent/cart.php"
                        class="btn btn-light border position-relative"
                        title="Cart">

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
                        href="/somame_ent/customer/profile.php"
                        class="btn btn-light border"
                        title="Profile">

                        <i
                            class="bi bi-person"></i>

                    </a>


                </div>

            </div>

        </div>

    </nav>


    <main class="container pb-5">


        <!-- =====================================================
         HERO
    ====================================================== -->

        <section class="page-hero">

            <div class="hero-content">

                <span
                    class="badge rounded-pill text-bg-success mb-3">
                    MULTI-VENDOR MARKETPLACE
                </span>


                <h1
                    class="fw-bold mb-2">

                    Find what you need.

                </h1>


                <p
                    class="mb-0"
                    style="
                    color:
                    rgba(
                        255,
                        255,
                        255,
                        .7
                    );
                    max-width:600px;
                ">

                    Search products from all active
                    stores in one place.

                </p>

            </div>

        </section>


        <!-- =====================================================
         FILTERS
    ====================================================== -->

        <section
            class="filter-card my-4">

            <form
                method="GET"
                action="/somame_ent/products.php">

                <div class="row g-2">


                    <div class="col-lg-6">

                        <div class="input-group">

                            <span
                                class="input-group-text bg-white border-end-0">

                                <i
                                    class="bi bi-search text-muted"></i>

                            </span>


                            <input
                                type="search"
                                name="q"
                                class="form-control border-start-0"
                                placeholder="Search rice, drinks, snacks..."
                                value="<?= h(
                                            $search
                                        ) ?>">

                        </div>

                    </div>


                    <div class="col-lg-3">

                        <select
                            name="store"
                            class="form-select">

                            <option value="">
                                All Stores
                            </option>


                            <?php foreach (
                                $stores
                                as $vendor
                            ): ?>

                                <option
                                    value="<?= h(
                                                $vendor['storefront_slug']
                                            ) ?>"
                                    <?= $store === $vendor['storefront_slug']
                                        ? 'selected'
                                        : '' ?>>

                                    <?= h(
                                        $vendor['store_name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-lg-3">

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-search flex-grow-1">

                                Search

                            </button>


                            <?php if (
                                $search !== ''
                                || $category !== ''
                                || $store !== ''
                            ): ?>

                                <a
                                    href="/somame_ent/products.php"
                                    class="btn btn-light border d-flex align-items-center"
                                    title="Clear filters">

                                    <i
                                        class="bi bi-x-lg"></i>

                                </a>

                            <?php endif; ?>

                        </div>

                    </div>


                </div>


                <?php if (
                    $category !== ''
                ): ?>

                    <input
                        type="hidden"
                        name="category"
                        value="<?= h(
                                    $category
                                ) ?>">

                <?php endif; ?>

            </form>


            <hr>


            <div class="category-strip">


                <a
                    href="/somame_ent/products.php?<?= http_build_query(
                                                        array_filter([
                                                            'q' => $search,
                                                            'store' => $store
                                                        ])
                                                    ) ?>"
                    class="category-pill <?= $category === ''
                                                ? 'active'
                                                : '' ?>">

                    All Products

                </a>


                <?php foreach (
                    $categories
                    as $cat
                ): ?>


                    <?php

                    $categoryName =
                        $cat['name'];

                    $query =
                        http_build_query(
                            array_filter([
                                'q' => $search,
                                'store' => $store,
                                'category' =>
                                $categoryName
                            ])
                        );

                    ?>


                    <a
                        href="/somame_ent/products.php?<?= h(
                                                            $query
                                                        ) ?>"
                        class="category-pill <?= $category === $categoryName
                                                    ? 'active'
                                                    : '' ?>">

                        <?= h(
                            $categoryName
                        ) ?>

                    </a>


                <?php endforeach; ?>


            </div>

        </section>


        <!-- =====================================================
         RESULT HEADER
    ====================================================== -->

        <div
            class="d-flex justify-content-between align-items-center mb-3">

            <div>

                <h4
                    class="fw-bold mb-1">
                    Products
                </h4>

                <small
                    class="text-muted">

                    <?= count(
                        $products
                    ) ?>

                    result<?= count($products) === 1
                                ? ''
                                : 's' ?>

                </small>

            </div>


            <a
                href="/somame_ent/marketplace.php"
                class="btn btn-outline-secondary btn-sm">

                <i
                    class="bi bi-shop me-1"></i>

                Browse Stores

            </a>

        </div>


        <!-- =====================================================
         PRODUCTS
    ====================================================== -->

        <?php if (
            $products
        ): ?>

            <div
                class="row g-3 g-lg-4">


                <?php foreach (
                    $products
                    as $product
                ): ?>


                    <?php

                    $image =
                        marketplaceProductImage(
                            $product
                        );

                    $storeOpen =
                        $product['store_status'] === 'open';

                    $inStock =
                        (float)$product['stock']
                        > 0;

                    ?>


                    <div
                        class="col-6 col-md-4 col-xl-3">

                        <article
                            class="product-card">


                            <div
                                class="product-image-wrap">

                                <a
                                    href="/somame_ent/product.php?id=<?= (int)$product['id'] ?>&store=<?= urlencode(
                                                                                                            $product['storefront_slug']
                                                                                                        ) ?>">

                                    <img
                                        src="<?= h(
                                                    $image
                                                ) ?>"
                                        alt="<?= h(
                                                    $product['name']
                                                ) ?>"
                                        class="product-image"
                                        loading="lazy"
                                        onerror="
                                        this.onerror=null;
                                        this.src='/somame_ent/assets/images/product-placeholder.svg';
                                    ">

                                </a>


                                <span
                                    class="store-status <?= $storeOpen
                                                            ? 'text-success'
                                                            : 'text-danger' ?>">

                                    <i
                                        class="bi <?= $storeOpen
                                                        ? 'bi-check-circle'
                                                        : 'bi-clock' ?> me-1"></i>

                                    <?= $storeOpen
                                        ? 'Open'
                                        : 'Closed' ?>

                                </span>

                            </div>


                            <div
                                class="product-body">


                                <a
                                    href="/somame_ent/store.php?store=<?= urlencode(
                                                                            $product['storefront_slug']
                                                                        ) ?>"
                                    class="store-name d-block mb-1">

                                    <i
                                        class="bi bi-shop me-1"></i>

                                    <?= h(
                                        $product['store_name']
                                    ) ?>

                                </a>


                                <a
                                    href="/somame_ent/product.php?id=<?= (int)$product['id'] ?>&store=<?= urlencode(
                                                                                                            $product['storefront_slug']
                                                                                                        ) ?>"
                                    class="product-name d-block mb-2">

                                    <?= h(
                                        $product['name']
                                    ) ?>

                                </a>


                                <?php if (
                                    !empty($product['category_name'])
                                ): ?>

                                    <div
                                        class="small text-muted mb-2">

                                        <?= h(
                                            $product['category_name']
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <div
                                    class="description mb-3">

                                    <?php if (
                                        !empty($product['description'])
                                    ): ?>

                                        <?= h(
                                            $product['description']
                                        ) ?>

                                    <?php else: ?>

                                        Available from
                                        <?= h(
                                            $product['store_name']
                                        ) ?>.

                                    <?php endif; ?>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-end gap-2">

                                    <div>

                                        <div
                                            class="price">

                                            <?= money(
                                                $product['price']
                                            ) ?>

                                        </div>


                                        <?php if (
                                            !empty($product['unit'])
                                        ): ?>

                                            <div
                                                class="unit">

                                                per
                                                <?= h(
                                                    $product['unit']
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>


                                    <a
                                        href="/somame_ent/product.php?id=<?= (int)$product['id'] ?>&store=<?= urlencode(
                                                                                                                $product['storefront_slug']
                                                                                                            ) ?>"
                                        class="btn view-btn btn-sm"
                                        title="View Product">

                                        <?php if (
                                            $storeOpen
                                            && $inStock
                                        ): ?>

                                            <i
                                                class="bi bi-cart-plus"></i>

                                        <?php else: ?>

                                            <i
                                                class="bi bi-eye"></i>

                                        <?php endif; ?>

                                    </a>


                                </div>


                                <?php if (
                                    !$inStock
                                ): ?>

                                    <small
                                        class="text-danger d-block mt-2">
                                        Out of stock
                                    </small>

                                <?php endif; ?>


                            </div>

                        </article>

                    </div>


                <?php endforeach; ?>


            </div>


        <?php else: ?>


            <div class="empty-state">

                <div class="empty-icon">

                    <i
                        class="bi bi-search"></i>

                </div>


                <h4 class="fw-bold">
                    No products found
                </h4>


                <p
                    class="text-muted mb-4">

                    Try another search, category
                    or store.

                </p>


                <a
                    href="/somame_ent/products.php"
                    class="btn btn-success">

                    View All Products

                </a>

            </div>


        <?php endif; ?>


    </main>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


</body>

</html>