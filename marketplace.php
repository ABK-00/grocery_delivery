<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('customer');

$userId = currentUserId();

if (!$userId) {
    header(
        'Location: /somame_ent/login.php?customer=1'
    );
    exit;
}
/*
|--------------------------------------------------------------------------
| CURRENT CUSTOMER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        status
    FROM users
    WHERE id = ?
      AND role = 'customer'
    LIMIT 1
");

$stmt->execute([$userId]);

$customer = $stmt->fetch();

if (!$customer) {

    session_unset();
    session_destroy();

    header('Location: /somame_ent/login.php?customer=1');
    exit;
}

if ($customer['status'] !== 'active') {
    die('Your account is currently unavailable.');
}


/*
|--------------------------------------------------------------------------
| SEARCH / CATEGORY
|--------------------------------------------------------------------------
*/

$search = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');


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

$cartCount = (float)$stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| MARKETPLACE CATEGORIES
|--------------------------------------------------------------------------
|
| Categories are still vendor-specific in the database.
| Here we group matching names together for marketplace browsing.
|--------------------------------------------------------------------------
*/

$categorySql = "
    SELECT
        cat.name,
        COUNT(DISTINCT cat.company_id) AS vendor_count,
        COUNT(DISTINCT p.id) AS product_count

    FROM categories cat

    INNER JOIN companies c
        ON c.id = cat.company_id

    INNER JOIN products p
        ON p.category_id = cat.id
        AND p.company_id = cat.company_id
        AND p.status = 'active'

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

    GROUP BY cat.name

    ORDER BY
        product_count DESC,
        cat.name ASC

    LIMIT 8
";

$categories = $conn
    ->query($categorySql)
    ->fetchAll();


/*
|--------------------------------------------------------------------------
| ACTIVE SUBSCRIBED VENDORS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.company_name,
        c.storefront_slug,

        s.display_name,
        s.description,
        s.logo,
        s.banner_image,
        s.address,
        s.primary_color,
        s.store_status,
        s.delivery_information,

        (
            SELECT COUNT(*)
            FROM products p2
            WHERE p2.company_id = c.id
              AND p2.status = 'active'
        ) AS product_count,

        (
            SELECT MIN(p3.price)
            FROM products p3
            WHERE p3.company_id = c.id
              AND p3.status = 'active'
        ) AS starting_price

    FROM companies c

    LEFT JOIN company_storefronts s
        ON s.company_id = c.id

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
            c.company_name LIKE ?
            OR s.display_name LIKE ?
            OR s.description LIKE ?
            OR s.address LIKE ?

            OR EXISTS (
                SELECT 1
                FROM products sp
                WHERE sp.company_id = c.id
                  AND sp.status = 'active'
                  AND (
                        sp.name LIKE ?
                        OR sp.description LIKE ?
                  )
            )
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
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
        AND EXISTS (
            SELECT 1

            FROM categories fc

            INNER JOIN products fp
                ON fp.category_id = fc.id
                AND fp.company_id = c.id
                AND fp.status = 'active'

            WHERE fc.company_id = c.id
              AND fc.status = 'active'
              AND fc.name = ?
        )
    ";

    $params[] = $category;
}


$sql .= "
    ORDER BY
        CASE
            WHEN COALESCE(s.store_status, 'open') = 'open'
            THEN 0
            ELSE 1
        END ASC,

        product_count DESC,

        COALESCE(
            NULLIF(s.display_name, ''),
            c.company_name
        ) ASC
";


$stmt = $conn->prepare($sql);
$stmt->execute($params);

$stores = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function marketplaceStoreName(array $store): string
{
    if (
        isset($store['display_name'])
        && trim((string)$store['display_name']) !== ''
    ) {
        return $store['display_name'];
    }

    return $store['company_name'];
}


function marketplaceInitials(string $name): string
{
    $words = preg_split(
        '/\s+/',
        trim($name)
    );

    $initials = '';

    foreach (array_slice($words, 0, 2) as $word) {

        if ($word !== '') {

            $initials .= strtoupper(
                mb_substr($word, 0, 1)
            );
        }
    }

    return $initials ?: 'S';
}


function validStoreColor(?string $color): string
{
    if (
        $color
        && preg_match(
            '/^#[0-9A-Fa-f]{6}$/',
            $color
        )
    ) {
        return $color;
    }

    return '#198754';
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
        Marketplace | Grocery Delivery
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <style>
        :root {
            --market-green: #198754;
            --market-dark: #111827;
            --market-bg: #f7f8fa;
        }

        body {
            background: var(--market-bg);
            color: #17202a;
        }


        /* =====================================================
           NAVBAR
        ====================================================== */

        .market-navbar {
            background: rgba(255, 255, 255, .96);
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .platform-logo {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            background: var(--market-green);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .nav-search {
            max-width: 520px;
        }

        .nav-search .form-control {
            min-height: 46px;
            border-radius: 14px 0 0 14px;
        }

        .nav-search .btn {
            border-radius: 0 14px 14px 0;
        }

        .nav-icon {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            display: flex;
            justify-content: center;
            align-items: center;
        }


        /* =====================================================
           HERO
        ====================================================== */

        .market-hero {
            background:
                linear-gradient(135deg,
                    #eaf7ef,
                    #ffffff);

            border: 1px solid #e5eee8;
            border-radius: 28px;
            overflow: hidden;
        }

        .hero-badge {
            background: #dff5e7;
            color: #146c43;
            padding: 7px 12px;
            border-radius: 999px;
            font-weight: 700;
            font-size: .8rem;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .hero-title {
            font-size: clamp(2rem,
                    5vw,
                    3.5rem);

            font-weight: 800;
            line-height: 1.05;
        }

        .hero-visual {
            min-height: 280px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-shopping-icon {
            width: 180px;
            height: 180px;
            border-radius: 45px;
            background: var(--market-green);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 5rem;
            transform: rotate(-5deg);
            box-shadow:
                0 25px 50px rgba(25, 135, 84, .25);
        }


        /* =====================================================
           CATEGORIES
        ====================================================== */

        .category-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 8px;
            scrollbar-width: thin;
        }

        .category-card {
            min-width: 150px;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #fff;
            padding: 18px;
            text-decoration: none;
            color: inherit;
            transition: .2s ease;
        }

        .category-card:hover {
            border-color: var(--market-green);
            transform: translateY(-2px);
        }

        .category-card.active {
            border-color: var(--market-green);
            background: #effaf3;
        }

        .category-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #eaf7ef;
            color: var(--market-green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }


        /* =====================================================
           STORES
        ====================================================== */

        .store-card {
            background: #fff;
            border-radius: 22px;
            border: 1px solid #ebedf0;
            overflow: hidden;
            height: 100%;
            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .store-card:hover {
            transform: translateY(-4px);
            box-shadow:
                0 16px 40px rgba(0, 0, 0, .09);
        }

        .store-banner {
            height: 170px;
            position: relative;
            background:
                linear-gradient(135deg,
                    #edf8f1,
                    #e9ecef);

            background-size: cover;
            background-position: center;
        }

        .store-banner-overlay {
            position: absolute;
            inset: 0;

            background:
                linear-gradient(180deg,
                    rgba(0, 0, 0, .02),
                    rgba(0, 0, 0, .25));
        }

        .store-logo {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            background: #fff;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow:
                0 6px 20px rgba(0, 0, 0, .13);
        }

        .store-logo-placeholder {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 800;
            border: 4px solid #fff;
            box-shadow:
                0 6px 20px rgba(0, 0, 0, .13);
        }

        .store-status {
            position: absolute;
            top: 13px;
            right: 13px;
            z-index: 2;
        }

        .store-body {
            padding: 0 18px 18px;
        }

        .store-logo-wrap {
            position: relative;
            margin-top: -38px;
            margin-bottom: 12px;
            z-index: 3;
        }

        .store-description {
            min-height: 42px;
        }

        .store-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            color: #6c757d;
            font-size: .83rem;
        }

        .btn-store {
            background: var(--market-green);
            border-color: var(--market-green);
            color: #fff;
        }

        .btn-store:hover {
            background: #157347;
            border-color: #157347;
            color: white;
        }


        /* =====================================================
           EMPTY STATE
        ====================================================== */

        .empty-state {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 22px;
            padding: 60px 20px;
        }


        /* =====================================================
           FOOTER
        ====================================================== */

        footer {
            background: #111827;
            color: rgba(255, 255, 255, .75);
        }


        @media(max-width: 767px) {

            .desktop-search {
                display: none !important;
            }

            .hero-visual {
                min-height: 190px;
            }

            .hero-shopping-icon {
                width: 125px;
                height: 125px;
                font-size: 3.5rem;
                border-radius: 32px;
            }

            .store-banner {
                height: 145px;
            }

        }
    </style>

</head>

<body>


    <?php

    if (file_exists(__DIR__ . '/includes/loader.php')) {
        include __DIR__ . '/includes/loader.php';
    }

    ?>


    <!-- =========================================================
     NAVBAR
========================================================= -->

    <nav class="market-navbar">

        <div class="container py-3">

            <div
                class="d-flex align-items-center justify-content-between gap-3">

                <a
                    href="marketplace.php"
                    class="text-decoration-none text-dark">

                    <div
                        class="d-flex align-items-center gap-2">

                        <div class="platform-logo">

                            <i class="bi bi-basket2-fill"></i>

                        </div>


                        <div>

                            <strong class="d-block lh-sm">
                                Grocery Delivery
                            </strong>

                            <small
                                class="text-muted">
                                Marketplace
                            </small>

                        </div>

                    </div>

                </a>


                <!-- DESKTOP SEARCH -->

                <form
                    method="GET"
                    class="input-group nav-search desktop-search flex-grow-1">

                    <?php if ($category !== ''): ?>

                        <input
                            type="hidden"
                            name="category"
                            value="<?= htmlspecialchars(
                                        $category
                                    ) ?>">

                    <?php endif; ?>


                    <input
                        type="search"
                        name="q"
                        class="form-control"
                        value="<?= htmlspecialchars(
                                    $search
                                ) ?>"
                        placeholder="Search stores or products...">


                    <button
                        class="btn btn-store px-4"
                        type="submit">

                        <i class="bi bi-search"></i>

                    </button>

                </form>


                <div
                    class="d-flex align-items-center gap-2">

                    <a
                        href="cart.php"
                        class="btn btn-light border nav-icon position-relative"
                        title="Cart">

                        <i class="bi bi-cart3"></i>


                        <?php if ($cartCount > 0): ?>

                            <span
                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">

                                <?= (int)$cartCount ?>

                            </span>

                        <?php endif; ?>

                    </a>


                    <div class="dropdown">

                        <button
                            class="btn btn-light border dropdown-toggle"
                            data-bs-toggle="dropdown">

                            <i
                                class="bi bi-person-circle me-1"></i>

                            <span
                                class="d-none d-md-inline">

                                <?= htmlspecialchars(
                                    explode(
                                        ' ',
                                        $customer['name']
                                    )[0]
                                ) ?>

                            </span>

                        </button>


                        <ul
                            class="dropdown-menu dropdown-menu-end">

                            <li>

                                <a
                                    href="customer/dashboard.php"
                                    class="dropdown-item">

                                    <i
                                        class="bi bi-grid me-2"></i>

                                    Dashboard

                                </a>

                            </li>


                            <li>

                                <a
                                    href="orders.php"
                                    class="dropdown-item">

                                    <i
                                        class="bi bi-bag-check me-2"></i>

                                    My Orders

                                </a>

                            </li>


                            <li>

                                <a
                                    href="customer/profile.php"
                                    class="dropdown-item">

                                    <i
                                        class="bi bi-person me-2"></i>

                                    Profile

                                </a>

                            </li>


                            <li>
                                <hr class="dropdown-divider">
                            </li>


                            <li>

                                <a
                                    href="logout.php"
                                    class="dropdown-item text-danger">

                                    <i
                                        class="bi bi-box-arrow-right me-2"></i>

                                    Logout

                                </a>

                            </li>

                        </ul>

                    </div>

                </div>

            </div>


            <!-- MOBILE SEARCH -->

            <form
                method="GET"
                class="input-group mt-3 d-md-none">

                <?php if ($category !== ''): ?>

                    <input
                        type="hidden"
                        name="category"
                        value="<?= htmlspecialchars(
                                    $category
                                ) ?>">

                <?php endif; ?>


                <input
                    type="search"
                    name="q"
                    class="form-control"
                    value="<?= htmlspecialchars(
                                $search
                            ) ?>"
                    placeholder="Search stores or products...">


                <button
                    type="submit"
                    class="btn btn-store">

                    <i class="bi bi-search"></i>

                </button>

            </form>

        </div>

    </nav>


    <!-- =========================================================
     PAGE
========================================================= -->

    <main class="container py-4 py-md-5">


        <!-- HERO -->

        <section class="market-hero">

            <div class="row g-0 align-items-center">

                <div class="col-lg-7">

                    <div class="p-4 p-md-5">

                        <div class="hero-badge">

                            <i class="bi bi-stars"></i>

                            Your marketplace

                        </div>


                        <h1
                            class="hero-title mt-3 mb-3">

                            What are you shopping for today?

                        </h1>


                        <p
                            class="lead text-muted mb-4">

                            Browse products from stores,
                            supermarkets, food vendors and
                            other businesses in one place.

                        </p>


                        <a
                            href="#stores"
                            class="btn btn-store btn-lg px-4">

                            Browse Stores

                            <i
                                class="bi bi-arrow-down ms-1"></i>

                        </a>

                    </div>

                </div>


                <div class="col-lg-5">

                    <div class="hero-visual">

                        <div class="hero-shopping-icon">

                            <i
                                class="bi bi-bag-heart-fill"></i>

                        </div>

                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
         CATEGORIES
    ====================================================== -->

        <?php if ($categories): ?>

            <section class="mt-5">

                <div
                    class="d-flex justify-content-between align-items-end mb-3">

                    <div>

                        <small
                            class="text-success fw-bold text-uppercase">
                            Explore
                        </small>

                        <h3 class="fw-bold mb-0">
                            Browse Categories
                        </h3>

                    </div>


                    <?php if ($category !== ''): ?>

                        <a
                            href="marketplace.php"
                            class="text-decoration-none text-success">

                            Clear filter

                        </a>

                    <?php endif; ?>

                </div>


                <div class="category-scroll">

                    <?php foreach (
                        $categories as $cat
                    ): ?>

                        <a
                            href="marketplace.php?category=<?= urlencode(
                                                                $cat['name']
                                                            ) ?>"
                            class="category-card <?= $category === $cat['name']
                                                        ? 'active'
                                                        : '' ?>">

                            <div class="category-icon">

                                <i class="bi bi-grid"></i>

                            </div>


                            <div class="fw-bold mt-3">

                                <?= htmlspecialchars(
                                    $cat['name']
                                ) ?>

                            </div>


                            <small
                                class="text-muted">

                                <?= (int)$cat['product_count'] ?>
                                products

                            </small>

                        </a>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php endif; ?>


        <!-- =====================================================
         STORES
    ====================================================== -->

        <section
            class="mt-5"
            id="stores">

            <div
                class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

                <div>

                    <small
                        class="text-success fw-bold text-uppercase">
                        Marketplace
                    </small>


                    <h2 class="fw-bold mb-1">

                        <?php if ($category !== ''): ?>

                            <?= htmlspecialchars(
                                $category
                            ) ?> Stores

                        <?php elseif ($search !== ''): ?>

                            Search Results

                        <?php else: ?>

                            Available Stores

                        <?php endif; ?>

                    </h2>


                    <p class="text-muted mb-0">

                        <?= count($stores) ?>

                        <?= count($stores) === 1
                            ? 'store'
                            : 'stores' ?>

                        available

                    </p>

                </div>


                <?php if (
                    $search !== ''
                    || $category !== ''
                ): ?>

                    <a
                        href="marketplace.php"
                        class="btn btn-outline-secondary">

                        <i
                            class="bi bi-x-lg me-1"></i>

                        Clear

                    </a>

                <?php endif; ?>

            </div>


            <div class="row g-4">


                <?php foreach (
                    $stores as $store
                ): ?>

                    <?php

                    $storeName =
                        marketplaceStoreName($store);

                    $brandColor =
                        validStoreColor(
                            $store['primary_color']
                                ?? null
                        );

                    $isOpen =
                        ($store['store_status'] ?? 'open')
                        === 'open';

                    ?>


                    <div
                        class="col-sm-6 col-xl-4">

                        <article class="store-card">


                            <!-- BANNER -->

                            <div
                                class="store-banner"
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
                                    class="store-banner-overlay"></div>


                                <div class="store-status">

                                    <?php if ($isOpen): ?>

                                        <span
                                            class="badge rounded-pill text-bg-success">

                                            <i
                                                class="bi bi-circle-fill me-1"
                                                style="font-size:.55rem;"></i>

                                            Open

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="badge rounded-pill text-bg-secondary">

                                            <i
                                                class="bi bi-clock me-1"></i>

                                            Closed

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- CONTENT -->

                            <div class="store-body">


                                <div
                                    class="store-logo-wrap">

                                    <?php if (
                                        !empty($store['logo'])
                                    ): ?>

                                        <img
                                            src="<?= htmlspecialchars(
                                                        $store['logo']
                                                    ) ?>"
                                            class="store-logo"
                                            alt="<?= htmlspecialchars(
                                                        $storeName
                                                    ) ?>">

                                    <?php else: ?>

                                        <div
                                            class="store-logo-placeholder"
                                            style="
                                            background:
                                            <?= htmlspecialchars(
                                                $brandColor
                                            ) ?>;
                                        ">

                                            <?= htmlspecialchars(
                                                marketplaceInitials(
                                                    $storeName
                                                )
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </div>


                                <h4 class="fw-bold mb-1">

                                    <?= htmlspecialchars(
                                        $storeName
                                    ) ?>

                                </h4>


                                <p
                                    class="text-muted small store-description mb-3">

                                    <?= htmlspecialchars(
                                        mb_strimwidth(
                                            $store['description']
                                                ?: 'Browse products available from this store.',
                                            0,
                                            95,
                                            '...'
                                        )
                                    ) ?>

                                </p>


                                <div
                                    class="store-meta mb-3">

                                    <span>

                                        <i
                                            class="bi bi-bag me-1"></i>

                                        <?= (int)
                                        $store['product_count'] ?>

                                        products

                                    </span>


                                    <?php if (
                                        !empty($store['address'])
                                    ): ?>

                                        <span>

                                            <i
                                                class="bi bi-geo-alt me-1"></i>

                                            <?= htmlspecialchars(
                                                mb_strimwidth(
                                                    $store['address'],
                                                    0,
                                                    25,
                                                    '...'
                                                )
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </div>


                                <?php if (
                                    $store['starting_price']
                                    !== null
                                ): ?>

                                    <div
                                        class="small text-muted mb-3">

                                        Products from

                                        <strong
                                            class="text-dark">

                                            GH₵
                                            <?= number_format(
                                                (float)
                                                $store['starting_price'],
                                                2
                                            ) ?>

                                        </strong>

                                    </div>

                                <?php endif; ?>


                                <a
                                    href="store.php?store=<?= urlencode(
                                                                $store['storefront_slug']
                                                            ) ?>"
                                    class="btn btn-store w-100">

                                    View Store

                                    <i
                                        class="bi bi-arrow-right ms-1"></i>

                                </a>

                            </div>

                        </article>

                    </div>

                <?php endforeach; ?>


                <?php if (!$stores): ?>

                    <div class="col-12">

                        <div
                            class="empty-state text-center">

                            <i
                                class="bi bi-shop-window display-3 text-muted"></i>


                            <h4 class="fw-bold mt-3">
                                No stores found
                            </h4>


                            <p class="text-muted">

                                We couldn't find any active stores
                                matching your search.

                            </p>


                            <a
                                href="marketplace.php"
                                class="btn btn-store">

                                View All Stores

                            </a>

                        </div>

                    </div>

                <?php endif; ?>


            </div>

        </section>

    </main>


    <!-- =========================================================
     FOOTER
========================================================= -->

    <footer class="mt-5 py-5">

        <div class="container">

            <div class="row g-4">

                <div class="col-md-6">

                    <h5 class="text-white fw-bold">
                        Grocery Delivery
                    </h5>

                    <p class="mb-0">

                        Shop from multiple businesses
                        through one marketplace account.

                    </p>

                </div>


                <div class="col-md-3">

                    <h6 class="text-white">
                        Customer
                    </h6>

                    <a
                        href="orders.php"
                        class="d-block text-decoration-none text-light opacity-75 mb-2">
                        My Orders
                    </a>

                    <a
                        href="customer/profile.php"
                        class="d-block text-decoration-none text-light opacity-75">
                        My Profile
                    </a>

                </div>


                <div class="col-md-3">

                    <h6 class="text-white">
                        Account
                    </h6>

                    <a
                        href="logout.php"
                        class="text-decoration-none text-light opacity-75">
                        Logout
                    </a>

                </div>

            </div>


            <hr
                class="border-secondary my-4">


            <div class="text-center small">

                &copy;
                <?= date('Y') ?>

                Grocery Delivery Marketplace.

            </div>

        </div>

    </footer>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>