<?php

session_start();

require_once __DIR__ . '/config/db.php';

$error = '';


/*
|--------------------------------------------------------------------------
| LOGIN MODE
|--------------------------------------------------------------------------
|
| customer=1 means this page was opened from the marketplace/customer
| registration flow.
|--------------------------------------------------------------------------
 */

$customerMode = true;


/*
|--------------------------------------------------------------------------
| ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['user_id'])) {

    $role = $_SESSION['role'] ?? '';

    /*
     * Customer login mode:
     *
     * If an Admin/Staff/etc. is currently logged in,
     * DO NOT redirect them to their dashboard.
     *
     * Allow the customer login form to display.
     */
    if ($customerMode) {

        if ($role === 'customer') {

            header(
                'Location: /somame_ent/marketplace.php'
            );

            exit;
        }

    } else {

        /*
         * Normal system login behaviour.
         */
        switch ($role) {

            case 'super_admin':

                header(
                    'Location: /somame_ent/super_admin/dashboard.php'
                );

                exit;


            case 'admin':

                header(
                    'Location: /somame_ent/admin/dashboard.php'
                );

                exit;


            case 'staff':

                header(
                    'Location: /somame_ent/staff/dashboard.php'
                );

                exit;


            case 'delivery_partner':

                header(
                    'Location: /somame_ent/delivery/dashboard.php'
                );

                exit;


            case 'customer':

                header(
                    'Location: /somame_ent/marketplace.php'
                );

                exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(
        trim($_POST['email'] ?? '')
    );

    $password = $_POST['password'] ?? '';


    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $error = 'Please enter a valid email address.';
    } elseif ($password === '') {

        $error = 'Please enter your password.';
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | FIND USER
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    u.id,
                    u.company_id,
                    u.name,
                    u.email,
                    u.phone,
                    u.password,
                    u.role,
                    u.status,

                    c.company_name,
                    c.status AS company_status,
                    c.subscription_plan,
                    c.trial_ends_at,
                    c.subscription_starts_at,
                    c.subscription_ends_at

                FROM users u

                LEFT JOIN companies c
                    ON c.id = u.company_id

                WHERE u.email = ?

                LIMIT 1
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch();


            /*
            |--------------------------------------------------------------------------
            | VERIFY ACCOUNT
            |--------------------------------------------------------------------------
            */

            if (!$user) {

                throw new RuntimeException(
                    'Invalid email or password.'
                );
            }


            if (
                !password_verify(
                    $password,
                    $user['password']
                )
            ) {

                throw new RuntimeException(
                    'Invalid email or password.'
                );
            }

            /*
|--------------------------------------------------------------------------
| CUSTOMER LOGIN MODE CHECK
|--------------------------------------------------------------------------
*/

if ($user['role'] !== 'customer') {

    throw new RuntimeException(
        'This login is for customer accounts. Please use your role-specific portal.'
    );
}


            /*
            |--------------------------------------------------------------------------
            | USER STATUS
            |--------------------------------------------------------------------------
            */

            if ($user['status'] !== 'active') {

                throw new RuntimeException(
                    'Your account is currently inactive or suspended.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | COMPANY USER CHECK
            |--------------------------------------------------------------------------
            |
            | Customers and Super Admin do NOT need a company.
            |
            | Admin, Staff and Delivery Partner DO.
            |--------------------------------------------------------------------------
            */

            $companyRoles = [
                'admin',
                'staff',
                'delivery_partner'
            ];


            if (
                in_array(
                    $user['role'],
                    $companyRoles,
                    true
                )
            ) {

                if (empty($user['company_id'])) {

                    throw new RuntimeException(
                        'This account is not linked to a company.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | COMPANY STATUS
                |--------------------------------------------------------------------------
                */

                if ($user['company_status'] !== 'active') {

                    throw new RuntimeException(
                        'Your company account is currently unavailable.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | SUBSCRIPTION CHECK
                |--------------------------------------------------------------------------
                */

                $now = new DateTime();

                $subscriptionValid = false;


                /*
                 * Trial
                 */
                if (
                    $user['subscription_plan']
                    === 'trial'
                ) {

                    if (
                        !empty($user['trial_ends_at'])
                    ) {

                        $trialEnd =
                            new DateTime(
                                $user['trial_ends_at']
                            );

                        if ($trialEnd >= $now) {

                            $subscriptionValid = true;
                        }
                    }
                } else {

                    /*
                     * Monthly / Quarterly / Yearly
                     */
                    if (
                        in_array(
                            $user['subscription_plan'],
                            [
                                'monthly',
                                'quarterly',
                                'yearly'
                            ],
                            true
                        )
                        && !empty($user['subscription_ends_at'])
                    ) {

                        $subscriptionEnd =
                            new DateTime(
                                $user['subscription_ends_at']
                            );

                        if (
                            $subscriptionEnd
                            >= $now
                        ) {

                            $subscriptionValid =
                                true;
                        }
                    }
                }


                if (!$subscriptionValid) {

                    throw new RuntimeException(
                        'Your company subscription has expired. Please renew your subscription to continue.'
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | CUSTOMER RULE
            |--------------------------------------------------------------------------
            |
            | Marketplace customers should NOT belong
            | to any vendor/company.
            |--------------------------------------------------------------------------
            */

            if ($user['role'] === 'customer') {

                /*
                 * Clean up older customer accounts
                 * created under the previous structure.
                 */
                if ($user['company_id'] !== null) {

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET company_id = NULL
                        WHERE id = ?
                          AND role = 'customer'
                    ");

                    $stmt->execute([
                        $user['id']
                    ]);

                    $user['company_id'] = null;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | CREATE SESSION
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);

            $_SESSION['user_id'] =
                (int)$user['id'];

            $_SESSION['name'] =
                $user['name'];

            $_SESSION['email'] =
                $user['email'];

            $_SESSION['role'] =
                $user['role'];

            $_SESSION['company_id'] =
                $user['company_id'] !== null
                ? (int)$user['company_id']
                : null;


            /*
            |--------------------------------------------------------------------------
            | REDIRECT BY ROLE
            |--------------------------------------------------------------------------
            */

            switch ($user['role']) {

                case 'super_admin':

                    header(
                        'Location: super_admin/dashboard.php'
                    );

                    exit;


                case 'admin':

                    header(
                        'Location: admin/dashboard.php'
                    );

                    exit;


                case 'staff':

                    header(
                        'Location: staff/dashboard.php'
                    );

                    exit;


                case 'delivery_partner':

                    header(
                        'Location: delivery/dashboard.php'
                    );

                    exit;


                case 'customer':

                    header(
                        'Location: marketplace.php'
                    );

                    exit;


                default:

                    session_unset();
                    session_destroy();

                    throw new RuntimeException(
                        'Invalid account role.'
                    );
            }
        } catch (Throwable $e) {

            $error = $e->getMessage();
        }
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
        Login | Grocery Delivery
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
            --market-green-dark: #146c43;
            --market-light: #eef9f2;
        }

        body {

            min-height: 100vh;
            margin: 0;

            background:
                linear-gradient(135deg,
                    #f4fbf7,
                    #ffffff);

            color: #17202a;
        }


        .auth-page {

            min-height: 100vh;

            display: flex;
            align-items: center;

            padding: 30px 15px;

        }


        .auth-container {

            width: 100%;
            max-width: 1050px;

            margin: auto;

            background: #fff;

            border-radius: 28px;

            overflow: hidden;

            border: 1px solid #e9ecef;

            box-shadow:
                0 20px 60px rgba(0, 0, 0, .08);

        }


        /* =====================================================
           LEFT
        ====================================================== */

        .auth-visual {

            min-height: 600px;

            padding: 55px;

            background:
                linear-gradient(145deg,
                    #dff5e7,
                    #f7fcf9);

            display: flex;
            flex-direction: column;
            justify-content: space-between;

        }


        .platform-brand {

            display: flex;
            align-items: center;
            gap: 12px;

        }


        .brand-logo {

            width: 50px;
            height: 50px;

            border-radius: 15px;

            background:
                var(--market-green);

            color: #fff;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 1.4rem;

        }


        .auth-heading {

            font-weight: 800;

            font-size:
                clamp(2rem,
                    4vw,
                    3.3rem);

            line-height: 1.05;

        }


        .vendor-preview {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 12px;

        }


        .vendor-box {

            padding: 18px 12px;

            text-align: center;

            background:
                rgba(255, 255, 255, .8);

            border:
                1px solid rgba(255,
                    255,
                    255,
                    .9);

            border-radius: 17px;

        }


        .vendor-icon {

            width: 45px;
            height: 45px;

            margin:
                0 auto 8px;

            border-radius: 13px;

            display: flex;
            align-items: center;
            justify-content: center;

            color: #fff;

            background:
                var(--market-green);

        }


        /* =====================================================
           FORM
        ====================================================== */

        .auth-form {

            padding: 60px 55px;

        }


        .form-control {

            min-height: 50px;

            border-radius: 13px;

        }


        .form-control:focus {

            border-color:
                var(--market-green);

            box-shadow:
                0 0 0 .2rem rgba(25, 135, 84, .12);

        }


        .password-wrap {

            position: relative;

        }


        .password-wrap input {

            padding-right: 50px;

        }


        .password-toggle {

            position: absolute;

            right: 6px;

            bottom: 6px;

            width: 38px;
            height: 38px;

            border: 0;

            border-radius: 10px;

            background: transparent;

            color: #6c757d;

        }


        .btn-market {

            background:
                var(--market-green);

            border-color:
                var(--market-green);

            color: #fff;

            min-height: 50px;

            border-radius: 13px;

            font-weight: 600;

        }


        .btn-market:hover {

            background:
                var(--market-green-dark);

            border-color:
                var(--market-green-dark);

            color: #fff;

        }


        .signup-link {

            color:
                var(--market-green);

            font-weight: 600;

            text-decoration: none;

        }


        .signup-link:hover {

            color:
                var(--market-green-dark);

        }


        .account-note {

            padding: 15px;

            background: #f8f9fa;

            border-radius: 14px;

            font-size: .85rem;

        }


        @media(max-width: 991px) {

            .auth-visual {

                min-height: auto;

                padding: 35px;

            }

            .auth-form {

                padding: 40px 35px;

            }

        }


        @media(max-width: 575px) {

            .auth-page {

                padding: 0;

                align-items: stretch;

            }


            .auth-container {

                min-height: 100vh;

                border-radius: 0;

            }


            .auth-visual {

                display: none;

            }


            .auth-form {

                padding:
                    35px 20px;

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


    <div class="auth-page">

        <div class="auth-container">

            <div class="row g-0">


                <!-- =================================================
                 MARKETPLACE VISUAL
            ================================================== -->

                <div class="col-lg-6">

                    <div class="auth-visual">


                        <div>


                            <div
                                class="platform-brand">

                                <div class="brand-logo">

                                    <i
                                        class="bi bi-basket2-fill"></i>

                                </div>


                                <div>

                                    <strong
                                        class="fs-5">

                                        Grocery Delivery

                                    </strong>

                                    <small
                                        class="d-block text-muted">

                                        Marketplace

                                    </small>

                                </div>

                            </div>


                            <div class="mt-5">


                                <small
                                    class="text-success fw-bold text-uppercase">

                                    Welcome back

                                </small>


                                <h1
                                    class="auth-heading mt-2">

                                    One login.
                                    Every store.

                                </h1>


                                <p
                                    class="lead text-muted mt-3">

                                    Sign in to browse vendors,
                                    place orders and manage
                                    deliveries from one account.

                                </p>


                            </div>

                        </div>


                        <div>


                            <small
                                class="text-muted d-block mb-3">

                                Shop across multiple vendors

                            </small>


                            <div class="vendor-preview">


                                <div class="vendor-box">

                                    <div class="vendor-icon">

                                        <i
                                            class="bi bi-cup-hot"></i>

                                    </div>

                                    <strong
                                        class="small">
                                        Food
                                    </strong>

                                </div>


                                <div class="vendor-box">

                                    <div class="vendor-icon">

                                        <i
                                            class="bi bi-basket"></i>

                                    </div>

                                    <strong
                                        class="small">
                                        Groceries
                                    </strong>

                                </div>


                                <div class="vendor-box">

                                    <div class="vendor-icon">

                                        <i
                                            class="bi bi-shop"></i>

                                    </div>

                                    <strong
                                        class="small">
                                        Stores
                                    </strong>

                                </div>


                            </div>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                 LOGIN
            ================================================== -->

                <div class="col-lg-6">

                    <div class="auth-form">


                        <!-- MOBILE BRAND -->

                        <div
                            class="platform-brand d-lg-none mb-5">

                            <div class="brand-logo">

                                <i
                                    class="bi bi-basket2-fill"></i>

                            </div>


                            <div>

                                <strong>
                                    Grocery Delivery
                                </strong>

                                <small
                                    class="d-block text-muted">
                                    Marketplace
                                </small>

                            </div>

                        </div>


                        <h2 class="fw-bold mb-1">

                            Welcome back

                        </h2>


                        <p
                            class="text-muted mb-4">

                            Sign in to continue.

                        </p>


                        <!-- ERROR -->

                        <?php if ($error): ?>

                            <div
                                class="alert alert-danger">

                                <i
                                    class="bi bi-exclamation-circle me-1"></i>

                                <?= htmlspecialchars(
                                    $error
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <!-- LOGIN FORM -->

                        <?php if ($customerMode): ?>

                            <input
                                type="hidden"
                                name="customer_mode"
                                value="1">

                        <?php endif; ?>

                        <form method="POST">


                            <div class="mb-3">

                                <label
                                    class="form-label">

                                    Email Address

                                </label>


                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $_POST['email']
                                                    ?? ''
                                            ) ?>"
                                    autocomplete="email"
                                    placeholder="you@example.com"
                                    required
                                    autofocus>

                            </div>


                            <div
                                class="mb-3 password-wrap">

                                <label
                                    class="form-label">

                                    Password

                                </label>


                                <input
                                    type="password"
                                    name="password"
                                    id="loginPassword"
                                    class="form-control"
                                    autocomplete="current-password"
                                    placeholder="Your password"
                                    required>


                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="passwordToggle"
                                    aria-label="Show password">

                                    <i
                                        class="bi bi-eye"></i>

                                </button>

                            </div>


                            <div
                                class="d-flex justify-content-between align-items-center mb-4">

                                <div class="form-check">

                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        id="remember">

                                    <label
                                        class="form-check-label small"
                                        for="remember">
                                        Remember me
                                    </label>

                                </div>


                                <a
                                    href="#"
                                    class="small signup-link">
                                    Forgot password?
                                </a>

                            </div>


                            <button
                                type="submit"
                                class="btn btn-market w-100">

                                <i
                                    class="bi bi-box-arrow-in-right me-1"></i>

                                Sign In

                            </button>


                        </form>


                        <!-- CUSTOMER SIGN UP -->

                        <div
                            class="text-center mt-4">

                            <span class="text-muted">
                                New customer?
                            </span>


                            <a
                                href="register.php"
                                class="signup-link ms-1">
                                Create an account
                            </a>

                        </div>


                        <!-- ACCOUNT INFO -->

                        <div
                            class="account-note mt-4">

                            <div
                                class="d-flex gap-2">

                                <i
                                    class="bi bi-info-circle text-success"></i>

                                <div>

                                    <strong
                                        class="d-block">
                                        Business account?
                                    </strong>

                                    <span
                                        class="text-muted">

                                        Company administrators,
                                        staff and delivery partners
                                        also sign in from this page.

                                    </span>

                                </div>

                            </div>

                        </div>


                        <div
                            class="text-center mt-4">

                            <a
                                href="index.php"
                                class="text-muted text-decoration-none small">

                                <i
                                    class="bi bi-arrow-left me-1"></i>

                                Back to home

                            </a>

                        </div>


                    </div>

                </div>

            </div>

        </div>

    </div>


    <script>
        const passwordInput =
            document.getElementById(
                'loginPassword'
            );

        const passwordToggle =
            document.getElementById(
                'passwordToggle'
            );


        passwordToggle.addEventListener(
            'click',
            function() {

                const icon =
                    passwordToggle.querySelector(
                        'i'
                    );


                if (
                    passwordInput.type ===
                    'password'
                ) {

                    passwordInput.type =
                        'text';

                    icon.className =
                        'bi bi-eye-slash';

                    passwordToggle.setAttribute(
                        'aria-label',
                        'Hide password'
                    );

                } else {

                    passwordInput.type =
                        'password';

                    icon.className =
                        'bi bi-eye';

                    passwordToggle.setAttribute(
                        'aria-label',
                        'Show password'
                    );

                }

            }
        );
    </script>


</body>

</html>