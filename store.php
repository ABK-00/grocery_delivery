<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();


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


/*
|--------------------------------------------------------------------------
| RESOLVE STORED FILE
|--------------------------------------------------------------------------
|
| Supports:
|
| uploads/products/image.jpg
| assets/uploads/products/image.jpg
| products/image.jpg
| image.jpg
| absolute HTTP URLs
|
|--------------------------------------------------------------------------
*/

function resolveStoredImage(
    ?string $stored,
    string $type = 'product'
): string {

    $placeholder =
        '/somame_ent/assets/images/product-placeholder.svg';

    $stored = trim((string)$stored);

    if ($stored === '') {
        return $placeholder;
    }


    /*
    |--------------------------------------------------------------------------
    | REMOTE URL
    |--------------------------------------------------------------------------
    */

    if (
        str_starts_with($stored, 'http://')
        || str_starts_with($stored, 'https://')
    ) {
        return $stored;
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE
    |--------------------------------------------------------------------------
    */

    $stored = str_replace(
        '\\',
        '/',
        $stored
    );


    /*
     * Remove localhost/project prefix if it somehow
     * ended up being stored in the database.
     */
    $stored = preg_replace(
        '#^https?://[^/]+/somame_ent/#i',
        '',
        $stored
    );


    /*
     * Remove Windows project filesystem prefix.
     */
    $projectMarker = '/somame_ent/';

    $position = stripos(
        $stored,
        $projectMarker
    );

    if ($position !== false) {

        $stored = substr(
            $stored,
            $position
                + strlen($projectMarker)
        );
    }


    $stored = ltrim(
        $stored,
        '/'
    );


    /*
    |--------------------------------------------------------------------------
    | BUILD CANDIDATES
    |--------------------------------------------------------------------------
    */

    $candidates = [];


    /*
     * Already stored as a relative project path.
     */
    $candidates[] = $stored;


    if ($type === 'product') {

        if (
            str_starts_with(
                $stored,
                'products/'
            )
        ) {

            $candidates[] =
                'uploads/' . $stored;

            $candidates[] =
                'assets/uploads/' . $stored;
        }


        $filename =
            basename($stored);


        $candidates[] =
            'uploads/products/' . $filename;

        $candidates[] =
            'assets/uploads/products/' . $filename;

        $candidates[] =
            'uploads/' . $filename;

        $candidates[] =
            'assets/uploads/' . $filename;
    }


    if ($type === 'storefront') {

        $filename =
            basename($stored);


        $candidates[] =
            'assets/uploads/storefronts/' . $filename;

        $candidates[] =
            'uploads/storefronts/' . $filename;

        $candidates[] =
            'assets/uploads/' . $filename;

        $candidates[] =
            'uploads/' . $filename;
    }


    /*
    |--------------------------------------------------------------------------
    | RETURN FIRST FILE THAT ACTUALLY EXISTS
    |--------------------------------------------------------------------------
    */

    foreach (
        array_unique($candidates)
        as $candidate
    ) {

        $candidate =
            ltrim(
                str_replace(
                    '\\',
                    '/',
                    $candidate
                ),
                '/'
            );


        $physicalPath =
            __DIR__
            . '/'
            . $candidate;


        if (is_file($physicalPath)) {

            return
                '/somame_ent/'
                . $candidate;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | FALLBACK
    |--------------------------------------------------------------------------
    */

    if (
        str_starts_with(
            $stored,
            'assets/'
        )
        || str_starts_with(
            $stored,
            'uploads/'
        )
    ) {

        return
            '/somame_ent/'
            . $stored;
    }


    return $placeholder;
}


/*
|--------------------------------------------------------------------------
| PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

function storefrontProductImage(array $product): string
{
    $placeholder =
        '/somame_ent/assets/images/product-placeholder.svg';

    /*
    |--------------------------------------------------------------------------
    | PRIMARY IMAGE FIRST
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | EXTERNAL IMAGE
    |--------------------------------------------------------------------------
    */

    if (
        str_starts_with($image, 'http://')
        || str_starts_with($image, 'https://')
    ) {
        return $image;
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALISE
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | ALREADY STORED AS FULL PROJECT RELATIVE PATH
    |--------------------------------------------------------------------------
    */

    if (
        str_starts_with(
            $image,
            'somame_ent/'
        )
    ) {

        return '/' . $image;
    }


    /*
    |--------------------------------------------------------------------------
    | ALREADY STORED AS ASSETS PATH
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | ADMIN PRODUCT UPLOADER STORES ONLY THE FILENAME
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | FALLBACK
    |--------------------------------------------------------------------------
    */

    return $placeholder;
}


/*
|--------------------------------------------------------------------------
| REQUEST
|--------------------------------------------------------------------------
*/

$storeSlug =
    trim(
        $_GET['store'] ?? ''
    );

if ($storeSlug === '') {

    http_response_code(404);

    die('Store not found.');
}


$role =
    $_SESSION['role'] ?? '';

$userId =
    (int)(
        $_SESSION['user_id']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| LOAD STORE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT

        c.id AS company_id,
        c.company_code,
        c.company_name,
        c.storefront_slug,
        c.email AS company_email,
        c.phone AS company_phone,
        c.logo AS company_logo,

        c.status AS company_status,

        c.subscription_plan,
        c.trial_ends_at,
        c.subscription_starts_at,
        c.subscription_ends_at,

        s.id AS storefront_id,
        s.display_name,
        s.description,
        s.logo AS storefront_logo,
        s.banner_image,
        s.email AS storefront_email,
        s.phone AS storefront_phone,
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

$stmt->execute([
    $storeSlug
]);

$store =
    $stmt->fetch();


if (!$store) {

    http_response_code(404);

    die('Store not found.');
}


$companyId =
    (int)$store['company_id'];


/*
|--------------------------------------------------------------------------
| ACCESS MODE
|--------------------------------------------------------------------------
*/

$previewMode = false;
$previewType = null;


/*
|--------------------------------------------------------------------------
| CUSTOMER
|--------------------------------------------------------------------------
*/

if ($role === 'customer') {

    if (
        $store['company_status']
        !== 'active'
    ) {

        http_response_code(403);

        die('This store is currently unavailable.');
    }
}


/*
|--------------------------------------------------------------------------
| SUPER ADMIN
|--------------------------------------------------------------------------
|
| Super Admin may preview any storefront regardless of:
|
| - company status
| - subscription status
| - store open/closed
|
|--------------------------------------------------------------------------
*/ elseif ($role === 'super_admin') {

    $previewMode = true;

    $previewType =
        'super_admin';
}


/*
|--------------------------------------------------------------------------
| COMPANY ADMIN / STAFF
|--------------------------------------------------------------------------
*/ elseif (
    in_array(
        $role,
        [
            'admin',
            'staff'
        ],
        true
    )
) {

    $loggedCompanyId =
        (int)(
            $_SESSION['company_id']
            ?? 0
        );


    if (
        $loggedCompanyId
        !== $companyId
    ) {

        redirectByRole();
    }


    $previewMode = true;

    $previewType =
        'company';
}


/*
|--------------------------------------------------------------------------
| OTHER ROLES
|--------------------------------------------------------------------------
*/ else {

    redirectByRole();
}


/*
|--------------------------------------------------------------------------
| SUBSCRIPTION CHECK
|--------------------------------------------------------------------------
*/

$subscriptionValid = false;

$now =
    new DateTime();


if (
    $store['subscription_plan']
    === 'trial'
) {

    if (
        !empty($store['trial_ends_at'])
    ) {

        try {

            $trialEnd =
                new DateTime(
                    $store['trial_ends_at']
                );


            if (
                $trialEnd >= $now
            ) {

                $subscriptionValid =
                    true;
            }
        } catch (Throwable $e) {

            $subscriptionValid =
                false;
        }
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
    ) {

        try {

            $subscriptionEnd =
                new DateTime(
                    $store['subscription_ends_at']
                );


            if (
                $subscriptionEnd
                >= $now
            ) {

                $subscriptionValid =
                    true;
            }
        } catch (Throwable $e) {

            $subscriptionValid =
                false;
        }
    }
}


/*
 * Only customers are blocked by subscription.
 *
 * Admin and Super Admin previews remain available.
 */
if (
    !$previewMode
    && !$subscriptionValid
) {

    http_response_code(403);

    die('This store is currently unavailable.');
}


/*
|--------------------------------------------------------------------------
| STORE DETAILS
|--------------------------------------------------------------------------
*/

$storeName =
    trim(
        (string)(
            $store['display_name']
            ?? ''
        )
    );

if ($storeName === '') {

    $storeName =
        $store['company_name'];
}


$description =
    trim(
        (string)(
            $store['description']
            ?? ''
        )
    );


$address =
    trim(
        (string)(
            $store['address']
            ?? ''
        )
    );


$phone =
    trim(
        (string)(
            $store['storefront_phone']
            ?? ''
        )
    );

if ($phone === '') {

    $phone =
        trim(
            (string)(
                $store['company_phone']
                ?? ''
            )
        );
}


$email =
    trim(
        (string)(
            $store['storefront_email']
            ?? ''
        )
    );

if ($email === '') {

    $email =
        trim(
            (string)(
                $store['company_email']
                ?? ''
            )
        );
}


$whatsapp =
    trim(
        (string)(
            $store['whatsapp']
            ?? ''
        )
    );


$deliveryInformation =
    trim(
        (string)(
            $store['delivery_information']
            ?? ''
        )
    );


$storeOpen =
    ($store['store_status']
        ?? 'open')
    === 'open';


$primaryColor =
    trim(
        (string)(
            $store['primary_color']
            ?? ''
        )
    );


if (
    !preg_match(
        '/^#[A-Fa-f0-9]{6}$/',
        $primaryColor
    )
) {

    $primaryColor =
        '#198754';
}


/*
|--------------------------------------------------------------------------
| STORE LOGO
|--------------------------------------------------------------------------
*/

$logoStored =
    !empty($store['storefront_logo'])
    ? $store['storefront_logo']
    : (
        $store['company_logo']
        ?? ''
    );


$storeLogo =
    resolveStoredImage(
        $logoStored,
        'storefront'
    );


/*
|--------------------------------------------------------------------------
| BANNER
|--------------------------------------------------------------------------
*/

$bannerImage = '';

if (
    !empty($store['banner_image'])
) {

    $bannerImage =
        resolveStoredImage(
            $store['banner_image'],
            'storefront'
        );


    /*
     * Do not use generic product placeholder
     * as a storefront banner.
     */
    if (
        str_contains(
            $bannerImage,
            'product-placeholder.svg'
        )
    ) {

        $bannerImage = '';
    }
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


$categoryId =
    isset($_GET['category'])
    ? (int)$_GET['category']
    : 0;


/*
|--------------------------------------------------------------------------
| LOAD CATEGORIES
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

$stmt->execute([
    $companyId
]);

$categories =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| LOAD PRODUCTS
|--------------------------------------------------------------------------
|
| Important:
| We read BOTH products.image and product_images.image.
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

        cat.name AS category_name,

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

    LEFT JOIN categories cat
        ON cat.id = p.category_id
        AND cat.company_id = p.company_id

    WHERE p.company_id = ?
      AND p.status = 'active'
";


$params = [
    $companyId
];


if ($search !== '') {

    $sql .= "
        AND (
            p.name LIKE ?
            OR p.description LIKE ?
            OR cat.name LIKE ?
        )
    ";

    $like =
        '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}


if ($categoryId > 0) {

    $sql .= "
        AND p.category_id = ?
    ";

    $params[] =
        $categoryId;
}


$sql .= "
    ORDER BY
        p.id DESC
";


$stmt =
    $conn->prepare($sql);

$stmt->execute($params);

$products =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| PRODUCT COUNT
|--------------------------------------------------------------------------
*/

$totalProducts =
    count($products);


/*
|--------------------------------------------------------------------------
| CART COUNT
|--------------------------------------------------------------------------
*/

$cartCount = 0;


if (
    $role === 'customer'
    && $userId > 0
) {

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
}


/*
|--------------------------------------------------------------------------
| WHATSAPP URL
|--------------------------------------------------------------------------
*/

$whatsappUrl = '';

if ($whatsapp !== '') {

    $whatsappNumber =
        preg_replace(
            '/[^0-9]/',
            '',
            $whatsapp
        );


    if ($whatsappNumber !== '') {

        $whatsappUrl =
            'https://wa.me/'
            . $whatsappNumber;
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
        <?= h($storeName) ?>
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
                <?= h($primaryColor) ?>;

            --page-bg:
                #f7f8fa;

            --border:
                #e9ecef;

            --text:
                #182230;

            --muted:
                #667085;

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
        | PREVIEW BAR
        |--------------------------------------------------------------------------
        */

        .preview-bar {

            background:
                #101828;

            color:
                #fff;

            padding:
                10px 15px;

            text-align: center;

            font-size:
                .88rem;

            position:
                relative;

            z-index:
                1100;

        }


        .preview-bar strong {

            color:
                #7be2a8;

        }


        /*
        |--------------------------------------------------------------------------
        | NAVIGATION
        |--------------------------------------------------------------------------
        */

        .store-nav {

            position:
                sticky;

            top:
                0;

            z-index:
                1000;

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


        .store-brand {

            display:
                flex;

            align-items:
                center;

            gap:
                10px;

            min-width:
                0;

        }


        .nav-logo {

            width:
                42px;

            height:
                42px;

            object-fit:
                cover;

            border-radius:
                12px;

            border:
                1px solid var(--border);

            background:
                #f2f4f7;

        }


        .store-brand-name {

            font-weight:
                750;

            color:
                var(--text);

            white-space:
                nowrap;

            overflow:
                hidden;

            text-overflow:
                ellipsis;

            max-width:
                260px;

        }


        /*
        |--------------------------------------------------------------------------
        | HERO
        |--------------------------------------------------------------------------
        */

        .store-hero {

            position:
                relative;

            overflow:
                hidden;

            border-radius:
                26px;

            min-height:
                330px;

            background:
                linear-gradient(135deg,
                    #172033,
                    #101828);

            color:
                #fff;

        }


        .hero-banner {

            position:
                absolute;

            inset:
                0;

            width:
                100%;

            height:
                100%;

            object-fit:
                cover;

        }


        .hero-overlay {

            position:
                absolute;

            inset:
                0;

            background:
                linear-gradient(90deg,
                    rgba(16,
                        24,
                        40,
                        .94),
                    rgba(16,
                        24,
                        40,
                        .65),
                    rgba(16,
                        24,
                        40,
                        .30));

        }


        .hero-content {

            position:
                relative;

            z-index:
                2;

            padding:
                45px;

            min-height:
                330px;

            display:
                flex;

            align-items:
                center;

        }


        .hero-logo {

            width:
                88px;

            height:
                88px;

            border-radius:
                22px;

            object-fit:
                cover;

            border:
                4px solid rgba(255,
                    255,
                    255,
                    .2);

            background:
                #fff;

        }


        .store-status {

            display:
                inline-flex;

            align-items:
                center;

            gap:
                6px;

            border-radius:
                999px;

            padding:
                7px 12px;

            font-size:
                .8rem;

            font-weight:
                700;

        }


        .store-open {

            background:
                rgba(25,
                    135,
                    84,
                    .22);

            color:
                #a6efc1;

        }


        .store-closed {

            background:
                rgba(220,
                    53,
                    69,
                    .23);

            color:
                #ffafb7;

        }


        /*
        |--------------------------------------------------------------------------
        | INFORMATION CARDS
        |--------------------------------------------------------------------------
        */

        .info-card {

            background:
                #fff;

            border:
                1px solid var(--border);

            border-radius:
                18px;

            padding:
                20px;

            height:
                100%;

        }


        .info-icon {

            width:
                42px;

            height:
                42px;

            border-radius:
                12px;

            display:
                flex;

            justify-content:
                center;

            align-items:
                center;

            background:
                color-mix(in srgb,
                    var(--store-primary) 12%,
                    white);

            color:
                var(--store-primary);

            flex-shrink:
                0;

        }


        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        .filter-panel {

            background:
                #fff;

            border:
                1px solid var(--border);

            border-radius:
                20px;

            padding:
                18px;

        }


        .search-control {

            min-height:
                48px;

            border-radius:
                13px;

        }


        .category-scroll {

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

            display:
                inline-flex;

            white-space:
                nowrap;

            border:
                1px solid var(--border);

            background:
                #fff;

            color:
                #475467;

            padding:
                9px 14px;

            border-radius:
                999px;

            font-size:
                .88rem;

            transition:
                .2s;

        }


        .category-pill:hover,
        .category-pill.active {

            color:
                #fff;

            background:
                var(--store-primary);

            border-color:
                var(--store-primary);

        }


        /*
        |--------------------------------------------------------------------------
        | PRODUCT CARD
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
                transform .2s ease,
                box-shadow .2s ease;

        }


        .product-card:hover {

            transform:
                translateY(-4px);

            box-shadow:
                0 16px 35px rgba(16,
                    24,
                    40,
                    .09);

        }


        .product-image-wrap {

            position:
                relative;

            background:
                #f2f4f7;

            overflow:
                hidden;

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
                transform .35s ease;

        }


        .product-card:hover .product-image {

            transform:
                scale(1.035);

        }


        .stock-badge {

            position:
                absolute;

            top:
                13px;

            right:
                13px;

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
                0 3px 12px rgba(0,
                    0,
                    0,
                    .08);

        }


        .product-body {

            padding:
                18px;

        }


        .product-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);

            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;

            min-height: 48px;
        }


        .product-description {
            color: var(--muted);
            font-size: .86rem;

            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;

            min-height: 41px;
        }


        .product-price {

            font-weight:
                800;

            font-size:
                1.14rem;

            color:
                var(--store-primary);

        }


        .product-unit {

            color:
                var(--muted);

            font-size:
                .8rem;

        }


        .btn-store {

            background:
                var(--store-primary);

            border-color:
                var(--store-primary);

            color:
                #fff;

            border-radius:
                11px;

        }


        .btn-store:hover {

            background:
                var(--store-primary);

            border-color:
                var(--store-primary);

            color:
                #fff;

            filter:
                brightness(.92);

        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY STATE
        |--------------------------------------------------------------------------
        */

        .empty-state {

            background:
                #fff;

            border:
                1px solid var(--border);

            border-radius:
                22px;

            padding:
                65px 25px;

            text-align:
                center;

        }


        .empty-icon {

            width:
                70px;

            height:
                70px;

            margin:
                0 auto 18px;

            border-radius:
                20px;

            display:
                flex;

            justify-content:
                center;

            align-items:
                center;

            background:
                #f2f4f7;

            color:
                #667085;

            font-size:
                1.7rem;

        }


        /*
        |--------------------------------------------------------------------------
        | CLOSED STORE
        |--------------------------------------------------------------------------
        */

        .closed-message {

            border:
                0;

            border-radius:
                18px;

            background:
                #fff3cd;

            color:
                #664d03;

        }


        /*
        |--------------------------------------------------------------------------
        | MOBILE
        |--------------------------------------------------------------------------
        */

        @media(max-width: 767px) {

            .store-brand-name {

                max-width:
                    150px;

            }


            .hero-content {

                padding:
                    28px 22px;

                min-height:
                    290px;

            }


            .store-hero {

                min-height:
                    290px;

                border-radius:
                    20px;

            }


            .hero-logo {

                width:
                    70px;

                height:
                    70px;

            }


            .product-image {

                height:
                    180px;

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
     PREVIEW MODE
========================================================= -->

    <?php if ($previewMode): ?>

        <div class="preview-bar">

            <i
                class="bi bi-eye me-1"></i>


            <?php if (
                $previewType
                === 'super_admin'
            ): ?>

                <strong>
                    SUPER ADMIN PREVIEW
                </strong>

                — You are viewing this storefront
                as a platform administrator.

            <?php else: ?>

                <strong>
                    STOREFRONT PREVIEW
                </strong>

                — Customers see this storefront
                in the marketplace.

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
     NAV
========================================================= -->

    <nav class="store-nav">

        <div class="container">

            <div
                class="d-flex align-items-center justify-content-between py-3 gap-3">


                <div
                    class="d-flex align-items-center gap-3">


                    <!-- BACK -->

                    <?php if (
                        $role === 'customer'
                    ): ?>

                        <a
                            href="/somame_ent/marketplace.php"
                            class="btn btn-light border"
                            title="Back to Marketplace">

                            <i
                                class="bi bi-arrow-left"></i>

                        </a>


                    <?php elseif (
                        $role
                        === 'super_admin'
                    ): ?>

                        <a
                            href="/somame_ent/super_admin/companies.php"
                            class="btn btn-light border"
                            title="Back to Companies">

                            <i
                                class="bi bi-arrow-left"></i>

                        </a>


                    <?php else: ?>

                        <a
                            href="/somame_ent/admin/storefront.php"
                            class="btn btn-light border"
                            title="Back to Storefront Management">

                            <i
                                class="bi bi-arrow-left"></i>

                        </a>

                    <?php endif; ?>


                    <div class="store-brand">

                        <img
                            src="<?= h(
                                        $storeLogo
                                    ) ?>"
                            class="nav-logo"
                            alt="<?= h(
                                        $storeName
                                    ) ?>"
                            onerror="
                            this.onerror=null;
                            this.src='/somame_ent/assets/images/product-placeholder.svg';
                        ">


                        <div
                            class="store-brand-name">

                            <?= h(
                                $storeName
                            ) ?>

                        </div>

                    </div>

                </div>


                <!-- CUSTOMER ACTIONS -->

                <?php if (
                    $role === 'customer'
                ): ?>

                    <div
                        class="d-flex align-items-center gap-2">

                        <a
                            href="/somame_ent/orders.php"
                            class="btn btn-light border d-none d-sm-inline-flex">

                            <i
                                class="bi bi-receipt me-1"></i>

                            Orders

                        </a>


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

                    </div>

                <?php endif; ?>


            </div>

        </div>

    </nav>


    <main class="container py-4">


        <!-- =====================================================
         HERO
    ====================================================== -->

        <section
            class="store-hero mb-4">


            <?php if (
                $bannerImage !== ''
            ): ?>

                <img
                    src="<?= h(
                                $bannerImage
                            ) ?>"
                    class="hero-banner"
                    alt="">

            <?php endif; ?>


            <div class="hero-overlay"></div>


            <div class="hero-content">

                <div
                    class="row w-100 align-items-center">

                    <div
                        class="col-lg-8">

                        <div
                            class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-3 mb-3">

                            <img
                                src="<?= h(
                                            $storeLogo
                                        ) ?>"
                                alt="<?= h(
                                            $storeName
                                        ) ?>"
                                class="hero-logo"
                                onerror="
                                this.onerror=null;
                                this.src='/somame_ent/assets/images/product-placeholder.svg';
                            ">


                            <div>

                                <div
                                    class="mb-2">

                                    <span
                                        class="store-status <?= $storeOpen
                                                                ? 'store-open'
                                                                : 'store-closed' ?>">

                                        <i
                                            class="bi <?= $storeOpen
                                                            ? 'bi-check-circle-fill'
                                                            : 'bi-clock-fill' ?>"></i>

                                        <?= $storeOpen
                                            ? 'Open'
                                            : 'Closed' ?>

                                    </span>

                                </div>


                                <h1
                                    class="fw-bold mb-0">

                                    <?= h(
                                        $storeName
                                    ) ?>

                                </h1>

                            </div>

                        </div>


                        <?php if (
                            $description !== ''
                        ): ?>

                            <p
                                class="mb-3"
                                style="
                                max-width:650px;
                                color:
                                rgba(
                                    255,
                                    255,
                                    255,
                                    .74
                                );
                            ">

                                <?= nl2br(
                                    h(
                                        $description
                                    )
                                ) ?>

                            </p>

                        <?php endif; ?>


                        <div
                            class="d-flex flex-wrap gap-3 small"
                            style="
                            color:
                            rgba(
                                255,
                                255,
                                255,
                                .72
                            );
                        ">


                            <?php if (
                                $address !== ''
                            ): ?>

                                <span>

                                    <i
                                        class="bi bi-geo-alt me-1"></i>

                                    <?= h(
                                        $address
                                    ) ?>

                                </span>

                            <?php endif; ?>


                            <span>

                                <i
                                    class="bi bi-bag me-1"></i>

                                <?= $totalProducts ?>

                                product<?= $totalProducts === 1
                                            ? ''
                                            : 's' ?>

                            </span>


                        </div>

                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
         CLOSED MESSAGE
    ====================================================== -->

        <?php if (
            !$storeOpen
        ): ?>

            <div
                class="alert closed-message mb-4">

                <div
                    class="d-flex gap-3">

                    <i
                        class="bi bi-clock-history fs-4"></i>


                    <div>

                        <strong
                            class="d-block">
                            Store currently closed
                        </strong>

                        <small>
                            You can browse the products,
                            but ordering is temporarily
                            unavailable.
                        </small>

                    </div>

                </div>

            </div>

        <?php endif; ?>


        <!-- =====================================================
         STORE INFORMATION
    ====================================================== -->

        <?php if (
            $address !== ''
            || $phone !== ''
            || $email !== ''
            || $whatsappUrl !== ''
            || $deliveryInformation !== ''
        ): ?>

            <div
                class="row g-3 mb-4">


                <?php if (
                    $address !== ''
                ): ?>

                    <div
                        class="col-md-6 col-xl-3">

                        <div
                            class="info-card">

                            <div
                                class="d-flex gap-3">

                                <div
                                    class="info-icon">

                                    <i
                                        class="bi bi-geo-alt"></i>

                                </div>


                                <div>

                                    <small
                                        class="text-muted d-block">
                                        Location
                                    </small>

                                    <strong>
                                        <?= h(
                                            $address
                                        ) ?>
                                    </strong>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>


                <?php if (
                    $phone !== ''
                ): ?>

                    <div
                        class="col-md-6 col-xl-3">

                        <div
                            class="info-card">

                            <div
                                class="d-flex gap-3">

                                <div
                                    class="info-icon">

                                    <i
                                        class="bi bi-telephone"></i>

                                </div>


                                <div>

                                    <small
                                        class="text-muted d-block">
                                        Contact
                                    </small>

                                    <a
                                        href="tel:<?= h(
                                                        $phone
                                                    ) ?>"
                                        class="fw-semibold text-dark">

                                        <?= h(
                                            $phone
                                        ) ?>

                                    </a>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>


                <?php if (
                    $whatsappUrl !== ''
                ): ?>

                    <div
                        class="col-md-6 col-xl-3">

                        <div
                            class="info-card">

                            <div
                                class="d-flex gap-3">

                                <div
                                    class="info-icon">

                                    <i
                                        class="bi bi-whatsapp"></i>

                                </div>


                                <div>

                                    <small
                                        class="text-muted d-block">
                                        WhatsApp
                                    </small>

                                    <a
                                        href="<?= h(
                                                    $whatsappUrl
                                                ) ?>"
                                        target="_blank"
                                        rel="noopener"
                                        class="fw-semibold"
                                        style="
                                        color:
                                        var(
                                            --store-primary
                                        );
                                    ">

                                        Chat with store

                                    </a>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>


                <?php if (
                    $deliveryInformation !== ''
                ): ?>

                    <div
                        class="col-md-6 col-xl-3">

                        <div
                            class="info-card">

                            <div
                                class="d-flex gap-3">

                                <div
                                    class="info-icon">

                                    <i
                                        class="bi bi-truck"></i>

                                </div>


                                <div>

                                    <small
                                        class="text-muted d-block">
                                        Delivery
                                    </small>

                                    <span
                                        class="fw-semibold">

                                        <?= h(
                                            $deliveryInformation
                                        ) ?>

                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>


            </div>

        <?php endif; ?>


        <!-- =====================================================
         FILTERS
    ====================================================== -->

        <section
            class="filter-panel mb-4">

            <form
                method="GET"
                action="/somame_ent/store.php">

                <input
                    type="hidden"
                    name="store"
                    value="<?= h(
                                $storeSlug
                            ) ?>">


                <?php if (
                    $categoryId > 0
                ): ?>

                    <input
                        type="hidden"
                        name="category"
                        value="<?= $categoryId ?>">

                <?php endif; ?>


                <div
                    class="row g-2 align-items-center mb-3">

                    <div
                        class="col-md">

                        <div
                            class="input-group">

                            <span
                                class="input-group-text bg-white border-end-0 search-control">

                                <i
                                    class="bi bi-search text-muted"></i>

                            </span>


                            <input
                                type="search"
                                name="q"
                                value="<?= h(
                                            $search
                                        ) ?>"
                                class="form-control border-start-0 search-control"
                                placeholder="Search products...">

                        </div>

                    </div>


                    <div
                        class="col-md-auto">

                        <button
                            type="submit"
                            class="btn btn-store px-4 search-control">

                            Search

                        </button>

                    </div>


                    <?php if (
                        $search !== ''
                        || $categoryId > 0
                    ): ?>

                        <div
                            class="col-md-auto">

                            <a
                                href="/somame_ent/store.php?store=<?= urlencode(
                                                                        $storeSlug
                                                                    ) ?>"
                                class="btn btn-light border search-control d-flex align-items-center">

                                Clear

                            </a>

                        </div>

                    <?php endif; ?>

                </div>

            </form>


            <div
                class="category-scroll">

                <a
                    href="/somame_ent/store.php?store=<?= urlencode(
                                                            $storeSlug
                                                        ) ?><?= $search !== ''
                                                                ? '&q='
                                                                . urlencode(
                                                                    $search
                                                                )
                                                                : '' ?>"
                    class="category-pill <?= $categoryId === 0
                                                ? 'active'
                                                : '' ?>">

                    All Products

                </a>


                <?php foreach (
                    $categories
                    as $category
                ): ?>

                    <a
                        href="/somame_ent/store.php?store=<?= urlencode(
                                                                $storeSlug
                                                            ) ?>&category=<?= (int)$category['id'] ?><?= $search !== ''
                                                                                                            ? '&q='
                                                                                                            . urlencode(
                                                                                                                $search
                                                                                                            )
                                                                                                            : '' ?>"
                        class="category-pill <?= $categoryId === (int)$category['id']
                                                    ? 'active'
                                                    : '' ?>">

                        <?= h(
                            $category['name']
                        ) ?>

                    </a>

                <?php endforeach; ?>

            </div>

        </section>


        <!-- =====================================================
         PRODUCTS HEADER
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

                    <?= $totalProducts ?>

                    item<?= $totalProducts === 1
                            ? ''
                            : 's' ?>

                    found

                </small>

            </div>

        </div>


        <!-- =====================================================
         PRODUCTS
    ====================================================== -->

        <?php if (
            !empty($products)
        ): ?>

            <div
                class="row g-3 g-lg-4">


                <?php foreach (
                    $products
                    as $product
                ): ?>


                    <?php

                    $productImage =
                        storefrontProductImage(
                            $product
                        );

                    $inStock =
                        (float)$product['stock']
                        > 0;

                    ?>


                    <div
                        class="col-6 col-md-4 col-xl-3">

                        <div
                            class="product-card">


                            <div
                                class="product-image-wrap">

                                <?php if (
                                    $role === 'customer'
                                ): ?>

                                    <a
                                        href="/somame_ent/product.php?id=<?= (int)$product['id'] ?>&store=<?= urlencode(
                                                                                                                $storeSlug
                                                                                                            ) ?>">

                                        <img
                                            src="<?= h(
                                                        $productImage
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


                                <?php else: ?>

                                    <img
                                        src="<?= h(
                                                    $productImage
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

                                <?php endif; ?>


                                <span
                                    class="stock-badge <?= $inStock
                                                            ? 'text-success'
                                                            : 'text-danger' ?>">

                                    <i
                                        class="bi <?= $inStock
                                                        ? 'bi-check-circle'
                                                        : 'bi-x-circle' ?> me-1"></i>

                                    <?= $inStock
                                        ? 'In Stock'
                                        : 'Out of Stock' ?>

                                </span>

                            </div>


                            <div
                                class="product-body">


                                <?php if (
                                    !empty($product['category_name'])
                                ): ?>

                                    <small
                                        class="text-muted d-block mb-1">

                                        <?= h(
                                            $product['category_name']
                                        ) ?>

                                    </small>

                                <?php endif; ?>


                                <div
                                    class="product-name mb-2">

                                    <?= h(
                                        $product['name']
                                    ) ?>

                                </div>


                                <?php if (
                                    !empty($product['description'])
                                ): ?>

                                    <div
                                        class="product-description mb-3">

                                        <?= h(
                                            $product['description']
                                        ) ?>

                                    </div>

                                <?php else: ?>

                                    <div
                                        class="product-description mb-3">
                                        Available from
                                        <?= h(
                                            $storeName
                                        ) ?>.
                                    </div>

                                <?php endif; ?>


                                <div
                                    class="d-flex justify-content-between align-items-end gap-2">

                                    <div>

                                        <div
                                            class="product-price">

                                            <?= money(
                                                $product['price']
                                            ) ?>

                                        </div>


                                        <?php if (
                                            !empty($product['unit'])
                                        ): ?>

                                            <div
                                                class="product-unit">

                                                per
                                                <?= h(
                                                    $product['unit']
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>


                                    <?php if (
                                        $role
                                        === 'customer'
                                    ): ?>

                                        <a
                                            href="/somame_ent/product.php?id=<?= (int)$product['id'] ?>&store=<?= urlencode(
                                                                                                                    $storeSlug
                                                                                                                ) ?>"
                                            class="btn btn-store btn-sm">

                                            <i
                                                class="bi bi-arrow-right"></i>

                                        </a>


                                    <?php else: ?>

                                        <span
                                            class="badge text-bg-light border">

                                            Preview

                                        </span>

                                    <?php endif; ?>


                                </div>


                            </div>

                        </div>

                    </div>


                <?php endforeach; ?>


            </div>


        <?php else: ?>


            <div
                class="empty-state">

                <div
                    class="empty-icon">

                    <i
                        class="bi bi-bag"></i>

                </div>


                <h5
                    class="fw-bold">

                    No products found

                </h5>


                <p
                    class="text-muted mb-3">

                    <?php if (
                        $search !== ''
                        || $categoryId > 0
                    ): ?>

                        No products match your
                        current filters.

                    <?php else: ?>

                        This store has not added
                        any products yet.

                    <?php endif; ?>

                </p>


                <?php if (
                    $search !== ''
                    || $categoryId > 0
                ): ?>

                    <a
                        href="/somame_ent/store.php?store=<?= urlencode(
                                                                $storeSlug
                                                            ) ?>"
                        class="btn btn-store">

                        View All Products

                    </a>

                <?php endif; ?>


            </div>


        <?php endif; ?>


        <!-- =====================================================
         FOOTER
    ====================================================== -->

        <footer
            class="py-5 text-center text-muted small">

            <div
                class="mb-1 fw-semibold text-dark">

                <?= h(
                    $storeName
                ) ?>

            </div>

            Powered by Grocery Delivery

        </footer>


    </main>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


</body>

</html>