<?php

session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';


/*
|--------------------------------------------------------------------------
| CUSTOMER / COMPANY
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$companyId =
    isset($_SESSION['company_id'])
        ? (int)$_SESSION['company_id']
        : 0;


/*
|--------------------------------------------------------------------------
| RECOVER COMPANY ID IF SESSION DOES NOT HAVE IT
|--------------------------------------------------------------------------
*/

if ($companyId <= 0) {

    $stmt = $conn->prepare("
        SELECT company_id
        FROM users
        WHERE id = ?
          AND role = 'customer'
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $customer = $stmt->fetch();

    if (!$customer || empty($customer['company_id'])) {
        die(
            'Your customer account is not associated '
            . 'with a company storefront.'
        );
    }

    $companyId = (int)$customer['company_id'];

    $_SESSION['company_id'] = $companyId;
}


/*
|--------------------------------------------------------------------------
| COMPANY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        company_name,
        company_code,
        logo,
        status,
        subscription_plan,
        trial_ends_at,
        subscription_ends_at
    FROM companies
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$companyId]);

$company = $stmt->fetch();

if (!$company) {
    die('Storefront not found.');
}

if ($company['status'] !== 'active') {
    die('This storefront is currently unavailable.');
}


/*
|--------------------------------------------------------------------------
| SEARCH / FILTERS
|--------------------------------------------------------------------------
*/

$search =
    trim($_GET['search'] ?? '');

$categoryId =
    isset($_GET['category'])
        ? (int)$_GET['category']
        : 0;


/*
|--------------------------------------------------------------------------
| CATEGORIES
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        name
    FROM categories
    WHERE company_id = ?
      AND status = 'active'
    ORDER BY name ASC
");

$stmt->execute([$companyId]);

$categories = $stmt->fetchAll();


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
        p.status,
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


if ($search !== '') {

    $sql .= "
        AND (
            p.name LIKE ?
            OR p.description LIKE ?
            OR c.name LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}


if ($categoryId > 0) {

    $sql .= "
        AND p.category_id = ?
    ";

    $params[] = $categoryId;
}


$sql .= "
    ORDER BY
        p.created_at DESC,
        p.name ASC
";


$stmt = $conn->prepare($sql);

$stmt->execute($params);

$products = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| CART COUNT
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(c.quantity), 0)
    FROM cart c

    INNER JOIN products p
        ON p.id = c.product_id

    WHERE c.user_id = ?
      AND p.company_id = ?
");

$stmt->execute([
    $userId,
    $companyId
]);

$cartCount =
    (float)$stmt->fetchColumn();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Shop |
        <?= e($company['company_name']) ?>
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <style>

        :root {
            --navy: #071827;
            --green: #198754;
            --background: #f5f7f9;
            --card: #ffffff;
            --text: #17212b;
            --muted: #6c757d;
            --border: #e5e9ed;
        }

        body {
            background: var(--background);
            color: var(--text);
        }

        .store-navbar {
            background: var(--navy);
        }

        .store-brand {
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 20px;
        }

        .store-brand span {
            color: #20c997;
        }

        .hero {
            background:
                linear-gradient(
                    135deg,
                    #071827,
                    #0b2d3c
                );

            color: #fff;

            border-radius: 22px;

            padding: 35px;

            margin-top: 25px;
            margin-bottom: 28px;
        }

        .hero p {
            color: rgba(255,255,255,.7);
            margin-bottom: 0;
        }

        .search-box {
            background: #fff;
            border-radius: 16px;
            padding: 18px;
            box-shadow:
                0 5px 20px rgba(0,0,0,.04);
        }

        .product-card {
            height: 100%;

            background: var(--card);

            border:
                1px solid var(--border);

            border-radius: 18px;

            overflow: hidden;

            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .product-card:hover {
            transform: translateY(-4px);

            box-shadow:
                0 12px 30px rgba(0,0,0,.08);
        }

        .product-image-wrap {
            position: relative;

            height: 220px;

            background: #eef1f3;

            overflow: hidden;
        }

        .product-image {
            width: 100%;
            height: 100%;

            object-fit: cover;

            display: block;
        }

        .stock-badge {
            position: absolute;

            top: 12px;
            right: 12px;

            padding: 7px 10px;

            border-radius: 30px;

            font-size: 11px;
            font-weight: 700;
        }

        .product-body {
            padding: 18px;
        }

        .product-category {
            color: var(--green);

            font-size: 12px;
            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .4px;
        }

        .product-name {
            margin-top: 5px;
            margin-bottom: 5px;

            font-size: 18px;
            font-weight: 700;
        }

        .product-description {
            color: var(--muted);

            font-size: 13px;

            min-height: 40px;
        }

        .product-price {
            color: var(--navy);

            font-size: 21px;
            font-weight: 800;
        }

        .product-unit {
            color: var(--muted);

            font-size: 12px;
        }

        .btn-green {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }

        .btn-green:hover {
            background: #146c43;
            border-color: #146c43;
            color: #fff;
        }

        .empty-state {
            padding: 70px 20px;

            text-align: center;

            color: var(--muted);
        }

        .empty-state i {
            display: block;

            margin-bottom: 15px;

            font-size: 50px;

            color: #adb5bd;
        }

        @media (max-width: 576px) {

            .hero {
                padding: 25px 20px;
            }

            .product-image-wrap {
                height: 190px;
            }

        }

    </style>

</head>


<body>


<!-- =========================================================
     NAVIGATION
========================================================= -->

<nav class="navbar store-navbar">

    <div class="container">

        <a
            href="products.php"
            class="store-brand"
        >

            <i class="bi bi-basket2-fill me-2"></i>

            <?= e($company['company_name']) ?>

        </a>


        <div class="d-flex gap-2">

            <a
                href="customer/dashboard.php"
                class="btn btn-outline-light btn-sm"
            >
                <i class="bi bi-grid me-1"></i>

                Dashboard
            </a>


            <a
                href="cart.php"
                class="btn btn-success btn-sm"
            >

                <i class="bi bi-cart3 me-1"></i>

                Cart

                <?php if ($cartCount > 0): ?>

                    <span
                        class="badge bg-light text-dark ms-1"
                    >
                        <?= e($cartCount) ?>
                    </span>

                <?php endif; ?>

            </a>

        </div>

    </div>

</nav>


<main class="container pb-5">


    <!-- =====================================================
         HERO
    ====================================================== -->

    <section class="hero">

        <div class="row align-items-center">

            <div class="col-lg-8">

                <span
                    class="badge bg-success mb-3"
                >
                    Online Store
                </span>

                <h1 class="fw-bold mb-2">

                    Shop
                    <?= e($company['company_name']) ?>

                </h1>

                <p>

                    Browse available groceries,
                    add items to your cart and
                    have them delivered to you.

                </p>

            </div>

        </div>

    </section>


    <!-- =====================================================
         FILTERS
    ====================================================== -->

    <section class="search-box mb-4">

        <form
            method="GET"
            action="products.php"
            class="row g-3"
        >

            <div class="col-md-7">

                <div class="input-group">

                    <span class="input-group-text bg-white">

                        <i class="bi bi-search"></i>

                    </span>

                    <input
                        type="search"
                        name="search"
                        class="form-control"
                        placeholder="Search groceries..."
                        value="<?= e($search) ?>"
                    >

                </div>

            </div>


            <div class="col-md-3">

                <select
                    name="category"
                    class="form-select"
                >

                    <option value="0">
                        All Categories
                    </option>


                    <?php foreach ($categories as $category): ?>

                        <option
                            value="<?= (int)$category['id'] ?>"
                            <?= (
                                $categoryId
                                === (int)$category['id']
                            ) ? 'selected' : '' ?>
                        >

                            <?= e($category['name']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-2">

                <button
                    class="btn btn-green w-100"
                    type="submit"
                >

                    Filter

                </button>

            </div>

        </form>

    </section>


    <!-- =====================================================
         PRODUCTS
    ====================================================== -->

    <div class="row g-4">


        <?php if ($products): ?>


            <?php foreach ($products as $product): ?>


                <?php

                $imageUrl =
                    getProductImage(
                        $product,
                        0
                    );

                $inStock =
                    (float)$product['stock'] > 0;

                ?>


                <div
                    class="col-12 col-sm-6 col-lg-4 col-xl-3"
                >

                    <article class="product-card">


                        <!-- IMAGE -->

                        <a
                            href="product.php?id=<?= (int)$product['id'] ?>"
                            class="text-decoration-none"
                        >

                            <div class="product-image-wrap">

                                <img
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e($product['name']) ?>"
                                    class="product-image"
                                    loading="lazy"
                                >


                                <?php if ($inStock): ?>

                                    <span
                                        class="stock-badge bg-success text-white"
                                    >
                                        In Stock
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="stock-badge bg-danger text-white"
                                    >
                                        Out of Stock
                                    </span>

                                <?php endif; ?>

                            </div>

                        </a>


                        <!-- BODY -->

                        <div class="product-body">


                            <div class="product-category">

                                <?= e(
                                    $product['category_name']
                                    ?? 'Grocery'
                                ) ?>

                            </div>


                            <h2 class="product-name">

                                <?= e($product['name']) ?>

                            </h2>


                            <p class="product-description">

                                <?php

                                $description =
                                    trim(
                                        (string)(
                                            $product['description']
                                            ?? ''
                                        )
                                    );

                                if ($description === '') {

                                    echo 'Fresh grocery item available for delivery.';

                                } else {

                                    echo e(
                                        mb_strimwidth(
                                            $description,
                                            0,
                                            90,
                                            '...'
                                        )
                                    );
                                }

                                ?>

                            </p>


                            <div
                                class="d-flex
                                       justify-content-between
                                       align-items-end
                                       gap-2
                                       mt-3"
                            >

                                <div>

                                    <div class="product-price">

                                        <?= money(
                                            $product['price']
                                        ) ?>

                                    </div>

                                    <div class="product-unit">

                                        per
                                        <?= e(
                                            $product['unit']
                                            ?? 'piece'
                                        ) ?>

                                    </div>

                                </div>


                                <a
                                    href="product.php?id=<?= (int)$product['id'] ?>"
                                    class="btn btn-green"
                                >

                                    View

                                </a>

                            </div>

                        </div>

                    </article>

                </div>


            <?php endforeach; ?>


        <?php else: ?>


            <div class="col-12">

                <div class="empty-state">

                    <i class="bi bi-basket"></i>

                    <h4>
                        No products found
                    </h4>

                    <p>

                        Try another search or
                        product category.

                    </p>


                    <a
                        href="products.php"
                        class="btn btn-green"
                    >
                        View All Products
                    </a>

                </div>

            </div>


        <?php endif; ?>


    </div>

</main>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>