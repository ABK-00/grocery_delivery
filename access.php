<?php

require_once __DIR__ . '/config/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
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
        Choose Access | GroceryDelivery
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --primary-rgb: 37, 99, 235;

            --text: #172033;
            --muted: #64748b;

            --glass:
                rgba(255, 255, 255, .58);

            --border:
                rgba(148, 163, 184, .18);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;

            min-height: 100vh;

            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            color: var(--text);

            background:

                radial-gradient(circle at 10% 15%,
                    rgba(37, 99, 235, .15),
                    transparent 32%),

                radial-gradient(circle at 90% 20%,
                    rgba(14, 165, 233, .12),
                    transparent 30%),

                linear-gradient(135deg,
                    #f8fbff,
                    #eef4fb);
        }


        .access-page {
            min-height: 100vh;

            display: flex;
            align-items: center;

            padding:
                40px 20px;
        }


        .access-container {
            width: 100%;
            max-width: 1120px;

            margin: auto;
        }


        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;

            margin-bottom: 38px;
        }


        .brand {
            display: flex;
            align-items: center;
            gap: 12px;

            text-decoration: none;
            color: var(--text);
        }


        .brand-icon {
            width: 44px;
            height: 44px;

            display: grid;
            place-items: center;

            border-radius: 14px;

            color: #fff;

            background:
                linear-gradient(135deg,
                    #2563eb,
                    #0ea5e9);

            box-shadow:
                0 10px 28px rgba(37, 99, 235, .22);
        }


        .brand strong {
            display: block;
            font-size: 1rem;
        }


        .brand small {
            color: var(--muted);
        }


        .back-link {
            text-decoration: none;
            color: var(--muted);

            font-size: .88rem;
        }


        .heading {
            max-width: 680px;

            margin-bottom: 30px;
        }


        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding:
                6px 10px;

            margin-bottom: 12px;

            border-radius: 999px;

            color: var(--primary);

            background:
                rgba(var(--primary-rgb),
                    .08);

            border:
                1px solid rgba(var(--primary-rgb),
                    .16);

            font-size: .72rem;
            font-weight: 700;

            text-transform: uppercase;
            letter-spacing: .07em;
        }


        h1 {
            margin: 0;

            font-size:
                clamp(2rem,
                    5vw,
                    3.3rem);

            font-weight: 800;
            letter-spacing: -.04em;
        }


        .heading p {
            margin-top: 12px;

            color: var(--muted);

            font-size: 1rem;
        }


        .role-card {
            height: 100%;

            padding: 23px;

            border-radius: 19px;

            background:
                linear-gradient(135deg,
                    rgba(255, 255, 255, .72),
                    rgba(255, 255, 255, .42));

            border:
                1px solid var(--border);

            box-shadow:
                0 18px 45px rgba(15, 23, 42, .08);

            backdrop-filter:
                blur(20px) saturate(145%);

            transition:
                transform .2s ease,
                box-shadow .2s ease,
                border-color .2s ease;
        }


        .role-card:hover {
            transform:
                translateY(-4px);

            border-color:
                rgba(var(--primary-rgb),
                    .24);

            box-shadow:
                0 24px 55px rgba(15, 23, 42, .12);
        }


        .role-icon {
            width: 47px;
            height: 47px;

            display: grid;
            place-items: center;

            border-radius: 14px;

            color: var(--primary);

            background:
                rgba(var(--primary-rgb),
                    .09);

            border-top:
                2px solid rgba(var(--primary-rgb),
                    .92);

            font-size: 1.18rem;

            margin-bottom: 20px;
        }


        .role-card h3 {
            font-size: 1rem;
            font-weight: 800;

            margin-bottom: 7px;
        }


        .role-card p {
            color: var(--muted);

            font-size: .83rem;

            min-height: 42px;

            margin-bottom: 20px;
        }


        .role-actions {
            display: grid;
            gap: 8px;
        }


        .role-btn {
            min-height: 40px;

            display: flex;
            align-items: center;
            justify-content: center;

            gap: 7px;

            padding:
                8px 12px;

            border-radius: 11px;

            text-decoration: none;

            font-size: .8rem;
            font-weight: 700;

            color: var(--text);

            background:
                rgba(255,
                    255,
                    255,
                    .32);

            border-left:
                1px solid rgba(148,
                    163,
                    184,
                    .16);

            border-right:
                1px solid rgba(148,
                    163,
                    184,
                    .16);

            border-bottom:
                1px solid rgba(148,
                    163,
                    184,
                    .16);

            border-top:
                2px solid rgba(var(--primary-rgb),
                    .92);

            transition:
                .18s ease;
        }


        .role-btn:hover {
            color: var(--text);

            background:
                rgba(var(--primary-rgb),
                    .08);

            border-top-color:
                rgb(var(--primary-rgb));
        }


        .role-btn.primary {
            color: #fff;

            background:
                linear-gradient(135deg,
                    #2563eb,
                    #0ea5e9);

            border-color:
                rgba(var(--primary-rgb),
                    .9);
        }


        .role-note {
            margin-top: 12px;

            color: var(--muted);

            font-size: .72rem;

            line-height: 1.45;
        }


        @media(max-width: 576px) {

            .access-page {
                padding:
                    25px 14px;
            }

            .brand-row {
                margin-bottom:
                    28px;
            }

            .brand small {
                display: none;
            }

            .heading {
                margin-bottom:
                    22px;
            }

            .role-card {
                padding:
                    18px;
            }

            .role-card p {
                min-height: auto;
            }

        }
    </style>

</head>

<body>

    <div class="access-page">

        <div class="access-container">

            <div class="brand-row">

                <a
                    href="<?= BASE_URL ?>/index.php"
                    class="brand">

                    <div class="brand-icon">

                        <i
                            class="bi bi-basket2-fill"></i>

                    </div>

                    <div>

                        <strong>
                            GroceryDelivery
                        </strong>

                        <small>
                            Marketplace Platform
                        </small>

                    </div>

                </a>


                <a
                    href="<?= BASE_URL ?>/index.php"
                    class="back-link">

                    <i
                        class="bi bi-arrow-left me-1"></i>

                    Back home

                </a>

            </div>


            <div class="heading">

                <div class="eyebrow">

                    <i class="bi bi-shield-check"></i>

                    Secure Access

                </div>

                <h1>
                    How are you signing in?
                </h1>

                <p>
                    Choose your account type and we'll take you
                    to the correct portal.
                </p>

            </div>


            <div class="row g-3">


                <!-- CUSTOMER -->

                <div class="col-md-6 col-xl-3">

                    <div class="role-card">

                        <div class="role-icon">

                            <i
                                class="bi bi-bag-heart"></i>

                        </div>

                        <h3>
                            Customer
                        </h3>

                        <p>
                            Shop from available stores,
                            manage orders and track deliveries.
                        </p>

                        <div class="role-actions">

                            <a
                                href="<?= BASE_URL ?>/login.php?customer=1"
                                class="role-btn primary">

                                <i
                                    class="bi bi-box-arrow-in-right"></i>

                                Sign In

                            </a>

                            <a
                                href="<?= BASE_URL ?>/register.php"
                                class="role-btn">

                                <i
                                    class="bi bi-person-plus"></i>

                                Create Account

                            </a>

                        </div>

                    </div>

                </div>


                <!-- COMPANY ADMIN -->

                <div class="col-md-6 col-xl-3">

                    <div class="role-card">

                        <div class="role-icon">

                            <i
                                class="bi bi-building-gear"></i>

                        </div>

                        <h3>
                            Company Administrator
                        </h3>

                        <p>
                            Manage your storefront,
                            staff, products, orders and deliveries.
                        </p>

                        <div class="role-actions">

                            <a
                                href="<?= BASE_URL ?>/admin/login.php"
                                class="role-btn primary">

                                <i
                                    class="bi bi-box-arrow-in-right"></i>

                                Sign In

                            </a>

                            <a
                                href="<?= BASE_URL ?>/company/register.php"
                                class="role-btn">
                                <i class="bi bi-building-add"></i>
                                Register Company
                            </a>

                        </div>

                    </div>

                </div>


                <!-- STAFF -->

                <div class="col-md-6 col-xl-3">

                    <div class="role-card">

                        <div class="role-icon">

                            <i
                                class="bi bi-person-badge"></i>

                        </div>

                        <h3>
                            Staff
                        </h3>

                        <p>
                            Access company orders and
                            operational tools assigned to you.
                        </p>

                        <div class="role-actions">

                            <a
                                href="<?= BASE_URL ?>/staff/login.php"
                                class="role-btn primary">

                                <i
                                    class="bi bi-box-arrow-in-right"></i>

                                Staff Sign In

                            </a>

                        </div>

                        <div class="role-note">

                            Staff accounts are created by
                            the company administrator.

                        </div>

                    </div>

                </div>


                <!-- DELIVERY -->

                <div class="col-md-6 col-xl-3">

                    <div class="role-card">

                        <div class="role-icon">

                            <i
                                class="bi bi-bicycle"></i>

                        </div>

                        <h3>
                            Delivery Partner
                        </h3>

                        <p>
                            View assigned deliveries,
                            update status and share live location.
                        </p>

                        <div class="role-actions">

                            <a
                                href="<?= BASE_URL ?>/delivery/login.php"
                                class="role-btn primary">

                                <i
                                    class="bi bi-box-arrow-in-right"></i>

                                Delivery Sign In

                            </a>

                        </div>

                        <div class="role-note">

                            Delivery accounts are created
                            by the company administrator.

                        </div>

                    </div>

                </div>


            </div>

        </div>

    </div>

</body>

</html>