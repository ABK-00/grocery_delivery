<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['company_register_csrf'])
) {
    $_SESSION['company_register_csrf'] =
        bin2hex(
            random_bytes(32)
        );
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function makeStorefrontSlug(
    PDO $conn,
    string $companyName
): string {

    $slug = strtolower(
        trim($companyName)
    );

    $slug = preg_replace(
        '/[^a-z0-9]+/i',
        '-',
        $slug
    );

    $slug = trim(
        $slug,
        '-'
    );

    if ($slug === '') {
        $slug = 'store';
    }

    $baseSlug = $slug;
    $counter = 2;

    while (true) {

        $stmt = $conn->prepare("
            SELECT id
            FROM companies
            WHERE storefront_slug = ?
            LIMIT 1
        ");

        $stmt->execute([
            $slug
        ]);

        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug =
            $baseSlug
            . '-'
            . $counter;

        $counter++;
    }
}


function generateCompanyCode(
    PDO $conn
): string {

    do {

        $code =
            'GD-'
            . strtoupper(
                substr(
                    bin2hex(
                        random_bytes(5)
                    ),
                    0,
                    8
                )
            );

        $stmt = $conn->prepare("
            SELECT id
            FROM companies
            WHERE company_code = ?
            LIMIT 1
        ");

        $stmt->execute([
            $code
        ]);
    } while ($stmt->fetch());

    return $code;
}


/*
|--------------------------------------------------------------------------
| FORM STATE
|--------------------------------------------------------------------------
*/

$error = '';

$old = [
    'company_name' => '',
    'company_email' => '',
    'company_phone' => '',
    'admin_name' => '',
    'admin_email' => '',
    'admin_phone' => '',
    'admin_whatsapp' => ''
];


/*
|--------------------------------------------------------------------------
| REGISTER COMPANY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($old as $key => $value) {

        $old[$key] =
            trim(
                $_POST[$key]
                    ?? ''
            );
    }


    $password =
        $_POST['password']
        ?? '';

    $confirmPassword =
        $_POST['confirm_password']
        ?? '';

    $csrf =
        $_POST['csrf_token']
        ?? '';

    $termsAccepted =
        isset(
            $_POST['terms']
        );


    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        if (
            !$csrf
            || !hash_equals(
                $_SESSION['company_register_csrf'],
                $csrf
            )
        ) {

            throw new RuntimeException(
                'Your session expired. Please refresh the page and try again.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | REQUIRED FIELDS
        |--------------------------------------------------------------------------
        */

        if (
            $old['company_name'] === ''
            || $old['company_email'] === ''
            || $old['company_phone'] === ''
            || $old['admin_name'] === ''
            || $old['admin_email'] === ''
            || $old['admin_phone'] === ''
            || $password === ''
            || $confirmPassword === ''
        ) {

            throw new RuntimeException(
                'Please complete all required fields.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | EMAIL VALIDATION
        |--------------------------------------------------------------------------
        */

        if (
            !filter_var(
                $old['company_email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            throw new RuntimeException(
                'Enter a valid company email address.'
            );
        }


        if (
            !filter_var(
                $old['admin_email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            throw new RuntimeException(
                'Enter a valid administrator email address.'
            );
        }


        $companyEmail =
            strtolower(
                $old['company_email']
            );

        $adminEmail =
            strtolower(
                $old['admin_email']
            );


        /*
        |--------------------------------------------------------------------------
        | PASSWORD
        |--------------------------------------------------------------------------
        */

        if (
            strlen($password) < 8
        ) {

            throw new RuntimeException(
                'Password must be at least 8 characters.'
            );
        }


        if (
            $password !==
            $confirmPassword
        ) {

            throw new RuntimeException(
                'Passwords do not match.'
            );
        }


        if (!$termsAccepted) {

            throw new RuntimeException(
                'You must agree to the terms before registering.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK ADMIN EMAIL
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([
            $adminEmail
        ]);

        if ($stmt->fetch()) {

            throw new RuntimeException(
                'An account already exists with that administrator email.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK COMPANY EMAIL
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM companies
            WHERE LOWER(email) = ?
            LIMIT 1
        ");

        $stmt->execute([
            $companyEmail
        ]);

        if ($stmt->fetch()) {

            throw new RuntimeException(
                'A company is already registered with this email address.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | GENERATE IDENTIFIERS
        |--------------------------------------------------------------------------
        */

        $companyCode =
            generateCompanyCode(
                $conn
            );

        $storefrontSlug =
            makeStorefrontSlug(
                $conn,
                $old['company_name']
            );


        /*
        |--------------------------------------------------------------------------
        | CREATE EVERYTHING AT ONCE
        |--------------------------------------------------------------------------
        */

        $conn->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | COMPANY
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO companies
            (
                company_code,
                company_name,
                storefront_slug,
                email,
                phone,
                whatsapp,
                status,
                subscription_plan,
                trial_ends_at,
                subscription_starts_at,
                subscription_ends_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                'active',
                'trial',
                DATE_ADD(NOW(), INTERVAL 30 DAY),
                NULL,
                NULL
            )
        ");

        $stmt->execute([
            $companyCode,
            $old['company_name'],
            $storefrontSlug,
            $companyEmail,
            $old['company_phone'],
            $old['admin_whatsapp'] !== ''
                ? $old['admin_whatsapp']
                : null
        ]);

        $companyId =
            (int)$conn->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | DEFAULT STOREFRONT
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO company_storefronts
            (
                company_id,
                display_name,
                description,
                email,
                phone,
                whatsapp,
                primary_color,
                store_status
            )
            VALUES
            (
                ?,
                ?,
                NULL,
                ?,
                ?,
                ?,
                '#2563eb',
                'open'
            )
        ");

        $stmt->execute([
            $companyId,
            $old['company_name'],
            $companyEmail,
            $old['company_phone'],
            $old['admin_whatsapp'] !== ''
                ? $old['admin_whatsapp']
                : $old['company_phone']
        ]);


        /*
        |--------------------------------------------------------------------------
        | FIRST COMPANY ADMINISTRATOR
        |--------------------------------------------------------------------------
        */

        $hashedPassword =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $stmt = $conn->prepare("
            INSERT INTO users
            (
                company_id,
                name,
                email,
                phone,
                whatsapp,
                password,
                role,
                status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                'admin',
                'active'
            )
        ");

        $stmt->execute([
            $companyId,
            $old['admin_name'],
            $adminEmail,
            $old['admin_phone'],
            $old['admin_whatsapp'] !== ''
                ? $old['admin_whatsapp']
                : null,
            $hashedPassword
        ]);


        /*
        |--------------------------------------------------------------------------
        | COMPLETE
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        unset(
            $_SESSION['company_register_csrf']
        );

        $_SESSION['company_registration_success'] = [
            'company_name' =>
            $old['company_name'],

            'company_code' =>
            $companyCode,

            'admin_email' =>
            $adminEmail
        ];

        header(
            'Location: '
                . BASE_URL
                . '/admin/login.php?registered=1'
        );

        exit;
    } catch (Throwable $e) {

        if (
            $conn->inTransaction()
        ) {
            $conn->rollBack();
        }

        $error =
            $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        Register Company | GroceryDelivery
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">


    <style>
        :root {

            --primary:
                #2563eb;

            --primary-2:
                #0ea5e9;

            --primary-rgb:
                37, 99, 235;

            --text:
                #172033;

            --muted:
                #64748b;

            --border:
                rgba(148,
                    163,
                    184,
                    .18);
        }


        * {
            box-sizing:
                border-box;
        }


        body {

            margin: 0;

            min-height:
                100vh;

            color:
                var(--text);

            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            background:

                radial-gradient(circle at 10% 10%,
                    rgba(var(--primary-rgb),
                        .16),
                    transparent 30%),

                radial-gradient(circle at 92% 16%,
                    rgba(14,
                        165,
                        233,
                        .12),
                    transparent 28%),

                linear-gradient(135deg,
                    #f8fbff,
                    #edf4fb);

            background-attachment:
                fixed;
        }


        .register-page {

            min-height:
                100vh;

            display:
                flex;

            align-items:
                center;

            padding:
                35px 18px;
        }


        .register-wrapper {

            width:
                100%;

            max-width:
                1180px;

            margin:
                auto;
        }


        .register-card {

            overflow:
                hidden;

            border-radius:
                26px;

            border:
                1px solid rgba(255,
                    255,
                    255,
                    .62);

            background:
                rgba(255,
                    255,
                    255,
                    .58);

            box-shadow:
                0 28px 80px rgba(15,
                    23,
                    42,
                    .12);

            backdrop-filter:
                blur(24px) saturate(150%);

            -webkit-backdrop-filter:
                blur(24px) saturate(150%);
        }


        /*
        |--------------------------------------------------------------------------
        | LEFT PANEL
        |--------------------------------------------------------------------------
        */

        .info-panel {

            min-height:
                760px;

            padding:
                48px;

            color:
                white;

            display:
                flex;

            flex-direction:
                column;

            justify-content:
                space-between;

            background:

                linear-gradient(145deg,
                    rgba(30,
                        64,
                        175,
                        .96),
                    rgba(14,
                        165,
                        233,
                        .90));
        }


        .brand {

            display:
                flex;

            align-items:
                center;

            gap:
                12px;
        }


        .brand-icon {

            width:
                48px;

            height:
                48px;

            display:
                grid;

            place-items:
                center;

            border-radius:
                15px;

            background:
                rgba(255,
                    255,
                    255,
                    .14);

            border-top:
                2px solid rgba(255,
                    255,
                    255,
                    .65);

            font-size:
                1.25rem;
        }


        .info-title {

            margin-top:
                60px;

            max-width:
                470px;

            font-size:
                clamp(2.3rem,
                    5vw,
                    4rem);

            line-height:
                1.02;

            font-weight:
                800;

            letter-spacing:
                -.045em;
        }


        .info-copy {

            max-width:
                450px;

            margin-top:
                18px;

            color:
                rgba(255,
                    255,
                    255,
                    .72);
        }


        .benefit {

            padding:
                14px 15px;

            border-radius:
                15px;

            background:
                rgba(255,
                    255,
                    255,
                    .09);

            border-top:
                2px solid rgba(255,
                    255,
                    255,
                    .38);

            backdrop-filter:
                blur(10px);
        }


        .benefit i {

            width:
                32px;

            height:
                32px;

            display:
                grid;

            place-items:
                center;

            border-radius:
                10px;

            background:
                rgba(255,
                    255,
                    255,
                    .10);
        }


        /*
        |--------------------------------------------------------------------------
        | FORM PANEL
        |--------------------------------------------------------------------------
        */

        .form-panel {

            min-height:
                760px;

            padding:
                42px;

            background:
                rgba(255,
                    255,
                    255,
                    .35);
        }


        .eyebrow {

            display:
                inline-flex;

            align-items:
                center;

            gap:
                7px;

            width:
                fit-content;

            padding:
                6px 10px;

            border-radius:
                999px;

            color:
                var(--primary);

            background:
                rgba(var(--primary-rgb),
                    .08);

            border-top:
                2px solid rgba(var(--primary-rgb),
                    .72);

            font-size:
                .72rem;

            font-weight:
                700;

            text-transform:
                uppercase;

            letter-spacing:
                .07em;
        }


        .section-title {

            margin:
                30px 0 15px;

            padding-bottom:
                9px;

            color:
                var(--text);

            font-size:
                .78rem;

            font-weight:
                800;

            text-transform:
                uppercase;

            letter-spacing:
                .08em;

            border-bottom:
                1px solid rgba(148,
                    163,
                    184,
                    .18);
        }


        .form-label {

            margin-bottom:
                6px;

            font-size:
                .78rem;

            font-weight:
                700;
        }


        .form-control {

            min-height:
                46px;

            border-radius:
                12px;

            color:
                var(--text);

            background:
                rgba(255,
                    255,
                    255,
                    .56);

            border:
                1px solid rgba(148,
                    163,
                    184,
                    .20);

            border-top:
                2px solid rgba(var(--primary-rgb),
                    .32);

            backdrop-filter:
                blur(12px);
        }


        .form-control:focus {

            background:
                rgba(255,
                    255,
                    255,
                    .78);

            border-color:
                rgba(148,
                    163,
                    184,
                    .20);

            border-top-color:
                var(--primary);

            box-shadow:
                0 0 0 .2rem rgba(var(--primary-rgb),
                    .10);
        }


        .password-wrap {

            position:
                relative;
        }


        .password-wrap input {

            padding-right:
                48px;
        }


        .password-toggle {

            position:
                absolute;

            right:
                6px;

            bottom:
                5px;

            width:
                36px;

            height:
                36px;

            display:
                grid;

            place-items:
                center;

            border:
                0;

            background:
                transparent;

            color:
                var(--muted);

            border-radius:
                10px;
        }


        /*
        |--------------------------------------------------------------------------
        | BUTTON
        |--------------------------------------------------------------------------
        */

        .register-btn {

            min-height:
                48px;

            padding:
                10px 20px;

            border-radius:
                12px;

            border:
                1px solid rgba(var(--primary-rgb),
                    .94);

            color:
                #fff;

            font-weight:
                700;

            background:

                linear-gradient(135deg,
                    var(--primary),
                    var(--primary-2));

            box-shadow:
                0 12px 28px rgba(var(--primary-rgb),
                    .20);
        }


        .register-btn:hover {

            color:
                #fff;

            transform:
                translateY(-1px);
        }


        .back-link {

            color:
                var(--muted);

            font-size:
                .82rem;

            text-decoration:
                none;
        }


        .back-link:hover {

            color:
                var(--primary);
        }


        .trial-note {

            display:
                flex;

            gap:
                12px;

            padding:
                14px;

            margin-top:
                22px;

            border-radius:
                14px;

            background:
                rgba(var(--primary-rgb),
                    .06);

            border-top:
                2px solid rgba(var(--primary-rgb),
                    .50);

            color:
                var(--muted);

            font-size:
                .78rem;
        }


        @media(max-width: 991px) {

            .info-panel {

                min-height:
                    auto;

                padding:
                    35px;
            }


            .info-title {

                margin-top:
                    35px;
            }


            .info-panel .benefits {

                margin-top:
                    30px;
            }


            .form-panel {

                min-height:
                    auto;

                padding:
                    35px;
            }
        }


        @media(max-width: 575px) {

            .register-page {

                padding:
                    0;
            }


            .register-wrapper {

                max-width:
                    none;
            }


            .register-card {

                min-height:
                    100vh;

                border-radius:
                    0;

                border:
                    0;
            }


            .info-panel {

                display:
                    none;
            }


            .form-panel {

                min-height:
                    100vh;

                padding:
                    28px 18px;
            }

        }
    </style>

</head>


<body>


    <div class="register-page">

        <div class="register-wrapper">

            <div class="register-card">

                <div class="row g-0">


                    <!-- =================================================
                     INFORMATION
                ================================================== -->

                    <div class="col-lg-5">

                        <div class="info-panel">

                            <div>

                                <div class="brand">

                                    <div class="brand-icon">

                                        <i
                                            class="bi bi-shop-window"></i>

                                    </div>

                                    <div>

                                        <strong>
                                            GroceryDelivery
                                        </strong>

                                        <small
                                            class="d-block"
                                            style="
                                            color:
                                            rgba(
                                                255,
                                                255,
                                                255,
                                                .60
                                            );
                                        ">
                                            Vendor Platform
                                        </small>

                                    </div>

                                </div>


                                <h1 class="info-title">

                                    Take your store
                                    online.

                                </h1>


                                <p class="info-copy">

                                    Create your company account,
                                    manage your storefront,
                                    add your team and start
                                    serving customers through
                                    the marketplace.

                                </p>

                            </div>


                            <div class="benefits">

                                <div class="benefit mb-3">

                                    <div
                                        class="
                                        d-flex
                                        gap-3
                                        align-items-center
                                    ">

                                        <i
                                            class="
                                            bi
                                            bi-calendar-check
                                        "></i>

                                        <div>

                                            <strong
                                                class="d-block">
                                                30-day free trial
                                            </strong>

                                            <small
                                                style="
                                                color:
                                                rgba(
                                                    255,
                                                    255,
                                                    255,
                                                    .65
                                                );
                                            ">
                                                Explore the platform
                                                before choosing a plan.
                                            </small>

                                        </div>

                                    </div>

                                </div>


                                <div class="benefit mb-3">

                                    <div
                                        class="
                                        d-flex
                                        gap-3
                                        align-items-center
                                    ">

                                        <i
                                            class="
                                            bi
                                            bi-people
                                        "></i>

                                        <div>

                                            <strong
                                                class="d-block">
                                                Manage your team
                                            </strong>

                                            <small
                                                style="
                                                color:
                                                rgba(
                                                    255,
                                                    255,
                                                    255,
                                                    .65
                                                );
                                            ">
                                                Add staff and
                                                delivery partners
                                                after registration.
                                            </small>

                                        </div>

                                    </div>

                                </div>


                                <div class="benefit">

                                    <div
                                        class="
                                        d-flex
                                        gap-3
                                        align-items-center
                                    ">

                                        <i
                                            class="
                                            bi
                                            bi-bag-check
                                        "></i>

                                        <div>

                                            <strong
                                                class="d-block">
                                                Marketplace ready
                                            </strong>

                                            <small
                                                style="
                                                color:
                                                rgba(
                                                    255,
                                                    255,
                                                    255,
                                                    .65
                                                );
                                            ">
                                                Your storefront is
                                                created automatically.
                                            </small>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                     FORM
                ================================================== -->

                    <div class="col-lg-7">

                        <div class="form-panel">


                            <div
                                class="
                                d-flex
                                justify-content-between
                                align-items-center
                                gap-3
                                mb-3
                            ">

                                <span class="eyebrow">

                                    <i
                                        class="bi bi-building-add"></i>

                                    Company Registration

                                </span>


                                <a
                                    href="<?= BASE_URL ?>/access.php"
                                    class="back-link">

                                    <i
                                        class="
                                        bi
                                        bi-arrow-left
                                        me-1
                                    "></i>

                                    Back

                                </a>

                            </div>


                            <h2
                                class="
                                fw-bold
                                mb-1
                            ">
                                Create your company
                            </h2>


                            <p
                                class="
                                text-muted
                                mb-4
                            ">
                                Your company administrator
                                account will be created at
                                the same time.
                            </p>


                            <?php if ($error): ?>

                                <div
                                    class="
                                    alert
                                    alert-danger
                                ">

                                    <i
                                        class="
                                        bi
                                        bi-exclamation-circle
                                        me-1
                                    "></i>

                                    <?= htmlspecialchars(
                                        $error
                                    ) ?>

                                </div>

                            <?php endif; ?>


                            <form
                                method="POST"
                                autocomplete="off">


                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                                $_SESSION['company_register_csrf']
                                            ) ?>">


                                <!-- COMPANY -->

                                <div class="section-title">

                                    Company Information

                                </div>


                                <div class="row g-3">


                                    <div class="col-md-6">

                                        <label
                                            class="form-label">
                                            Company Name *
                                        </label>

                                        <input
                                            type="text"
                                            name="company_name"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                        $old['company_name']
                                                    ) ?>"
                                            placeholder="ABC Grocery Ltd"
                                            required>

                                    </div>


                                    <div class="col-md-6">

                                        <label
                                            class="form-label">
                                            Company Email *
                                        </label>

                                        <input
                                            type="email"
                                            name="company_email"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                        $old['company_email']
                                                    ) ?>"
                                            placeholder="info@company.com"
                                            required>

                                    </div>


                                    <div class="col-md-6">

                                        <label
                                            class="form-label">
                                            Company Phone *
                                        </label>

                                        <input
                                            type="text"
                                            name="company_phone"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                        $old['company_phone']
                                                    ) ?>"
                                            placeholder="024 000 0000"
                                            required>

                                    </div>

                                </div>


                                <!-- ADMIN -->

                                <div class="section-title">

                                    Administrator Account

                                </div>


                                <div class="row g-3">


                                    <div class="col-md-6">

                                        <label
                                            class="form-label">
                                            Administrator Name *
                                        </label>

                                        <input
                                            type="text"
                                            name="admin_name"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                        $old['admin_name']
                                                    ) ?>"
                                            placeholder="Full name"
                                            required>

                                    </div>


                                    <div class="col-md-6">

                                        <label
                                            class="form-label">
                                            Administrator Email *
                                        </label>

                                        <input
                                            type="email"
                                            name="admin_email"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                        $old['admin_email']
                                                    ) ?>"
                                            placeholder="admin@company.com"
                                            required>

                                    </div>


                                    <div class="col-md-6">

                                        <label
                                            class="form-label">
                                            Phone *
                                        </label>

                                        <input
                                            type="text"
                                            name="admin_phone"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                        $old['admin_phone']
                                                    ) ?>"
                                            placeholder="024 000 0000"
                                            required>

                                    </div>


                                    <div class="col-md-6">

                                        <label
                                            class="form-label">
                                            WhatsApp
                                        </label>

                                        <input
                                            type="text"
                                            name="admin_whatsapp"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                        $old['admin_whatsapp']
                                                    ) ?>"
                                            placeholder="Optional">

                                    </div>


                                    <div class="col-md-6">

                                        <div class="password-wrap">

                                            <label
                                                class="form-label">
                                                Password *
                                            </label>

                                            <input
                                                type="password"
                                                name="password"
                                                id="password"
                                                class="form-control"
                                                minlength="8"
                                                autocomplete="new-password"
                                                placeholder="Minimum 8 characters"
                                                required>

                                            <button
                                                type="button"
                                                class="password-toggle"
                                                data-target="password"
                                                aria-label="Show password">

                                                <i
                                                    class="bi bi-eye"></i>

                                            </button>

                                        </div>

                                    </div>


                                    <div class="col-md-6">

                                        <div class="password-wrap">

                                            <label
                                                class="form-label">
                                                Confirm Password *
                                            </label>

                                            <input
                                                type="password"
                                                name="confirm_password"
                                                id="confirmPassword"
                                                class="form-control"
                                                minlength="8"
                                                autocomplete="new-password"
                                                placeholder="Repeat password"
                                                required>

                                            <button
                                                type="button"
                                                class="password-toggle"
                                                data-target="confirmPassword"
                                                aria-label="Show password">

                                                <i
                                                    class="bi bi-eye"></i>

                                            </button>

                                        </div>

                                    </div>


                                </div>


                                <div class="trial-note">

                                    <i
                                        class="
                                        bi
                                        bi-info-circle
                                        fs-5
                                    "></i>

                                    <div>

                                        Your company starts with a
                                        <strong>
                                            30-day free trial
                                        </strong>.

                                        After signing in, you can
                                        configure your storefront,
                                        add products, staff and
                                        delivery partners.

                                    </div>

                                </div>


                                <div
                                    class="
                                    form-check
                                    mt-4
                                ">

                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="terms"
                                        value="1"
                                        id="terms"
                                        required>

                                    <label
                                        class="
                                        form-check-label
                                        small
                                        text-muted
                                    "
                                        for="terms">

                                        I confirm that I am
                                        authorized to create this
                                        company account and agree
                                        to the platform terms.

                                    </label>

                                </div>


                                <div
                                    class="
                                    d-flex
                                    flex-wrap
                                    align-items-center
                                    justify-content-between
                                    gap-3
                                    mt-4
                                ">

                                    <div>

                                        <span
                                            class="
                                            text-muted
                                            small
                                        ">
                                            Already registered?
                                        </span>

                                        <a
                                            href="<?= BASE_URL ?>/admin/login.php"
                                            class="
                                            text-decoration-none
                                            fw-semibold
                                            small
                                        ">
                                            Sign in
                                        </a>

                                    </div>


                                    <button
                                        type="submit"
                                        class="
                                        btn
                                        register-btn
                                    ">

                                        Create Company

                                        <i
                                            class="
                                            bi
                                            bi-arrow-right
                                            ms-1
                                        "></i>

                                    </button>

                                </div>


                            </form>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <script>
        document
            .querySelectorAll(
                '.password-toggle'
            )
            .forEach(
                function(button) {

                    button.addEventListener(
                        'click',
                        function() {

                            const target =
                                document.getElementById(
                                    button.dataset.target
                                );

                            if (!target) {
                                return;
                            }

                            const icon =
                                button.querySelector(
                                    'i'
                                );

                            const currentlyVisible =
                                target.type ===
                                'text';

                            target.type =
                                currentlyVisible ?
                                'password' :
                                'text';

                            if (icon) {

                                icon.className =
                                    currentlyVisible ?
                                    'bi bi-eye' :
                                    'bi bi-eye-slash';
                            }
                        }
                    );
                }
            );
    </script>


</body>

</html>