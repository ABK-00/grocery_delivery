<?php

session_start();

require_once __DIR__ . '/config/db.php';

$error = '';


/*
|--------------------------------------------------------------------------
| ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['user_id'])) {

    $role = $_SESSION['role'] ?? '';

    if ($role === 'customer') {
        header('Location: /somame_ent/marketplace.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| CUSTOMER REGISTRATION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');

    $email = strtolower(
        trim($_POST['email'] ?? '')
    );

    $phone = trim($_POST['phone'] ?? '');

    $whatsapp = trim(
        $_POST['whatsapp'] ?? ''
    );

    $password =
        $_POST['password'] ?? '';

    $confirmPassword =
        $_POST['confirm_password'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($name === '') {

        $error = 'Please enter your full name.';
    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $error = 'Please enter a valid email address.';
    } elseif ($phone === '') {

        $error = 'Please enter your phone number.';
    } elseif (strlen($password) < 8) {

        $error =
            'Your password must contain at least 8 characters.';
    } elseif ($password !== $confirmPassword) {

        $error = 'The passwords do not match.';
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | CHECK EMAIL
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            if ($stmt->fetch()) {

                throw new RuntimeException(
                    'An account already exists with this email address.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CREATE MARKETPLACE CUSTOMER
            |--------------------------------------------------------------------------
            |
            | Customers do NOT belong to any vendor.
            |
            | company_id = NULL
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO users (
                    company_id,
                    name,
                    email,
                    phone,
                    whatsapp,
                    password,
                    role,
                    status
                )

                VALUES (
                    NULL,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'customer',
                    'active'
                )
            ");

            $stmt->execute([
                $name,
                $email,
                $phone,
                $whatsapp !== ''
                    ? $whatsapp
                    : $phone,
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                )
            ]);


            $userId =
                (int)$conn->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | LOG CUSTOMER IN
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);

            $_SESSION['user_id'] =
                $userId;

            $_SESSION['name'] =
                $name;

            $_SESSION['email'] =
                $email;

            $_SESSION['role'] =
                'customer';

            /*
             * Customer intentionally has no company.
             */
            $_SESSION['company_id'] =
                null;


            /*
            |--------------------------------------------------------------------------
            | REDIRECT TO MARKETPLACE
            |--------------------------------------------------------------------------
            */

            header('Location: /somame_ent/marketplace.php');
            exit;
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
        Create Account | Grocery Delivery
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
            --market-dark: #111827;
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
            max-width: 1080px;
            margin: auto;

            background: #fff;

            border-radius: 28px;

            overflow: hidden;

            box-shadow:
                0 20px 60px rgba(0, 0, 0, .08);

            border: 1px solid #e9ecef;
        }


        /*
        |--------------------------------------------------------------------------
        | LEFT PANEL
        |--------------------------------------------------------------------------
        */

        .auth-visual {

            min-height: 650px;

            background:
                linear-gradient(145deg,
                    #dff5e7,
                    #f5fcf7);

            padding: 55px;

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


        .auth-visual h1 {

            font-weight: 800;
            font-size: clamp(2rem,
                    4vw,
                    3.4rem);

            line-height: 1.05;

        }


        .market-feature {

            background:
                rgba(255, 255, 255, .75);

            border:
                1px solid rgba(255, 255, 255, .9);

            border-radius: 18px;

            padding: 16px;

            backdrop-filter:
                blur(8px);
        }


        .feature-icon {

            width: 42px;
            height: 42px;

            border-radius: 12px;

            background:
                var(--market-green);

            color: #fff;

            display: flex;
            align-items: center;
            justify-content: center;

            flex-shrink: 0;

        }


        /*
        |--------------------------------------------------------------------------
        | FORM PANEL
        |--------------------------------------------------------------------------
        */

        .auth-form {

            padding: 50px;

        }


        .form-control {

            min-height: 49px;

            border-radius: 13px;

        }


        .form-control:focus {

            border-color:
                var(--market-green);

            box-shadow:
                0 0 0 .2rem rgba(25, 135, 84, .12);

        }


        .password-group {

            position: relative;

        }


        .password-group .form-control {

            padding-right: 48px;

        }


        .password-toggle {

            position: absolute;

            right: 7px;
            bottom: 5px;

            width: 38px;
            height: 38px;

            border: 0;

            background: transparent;

            color: #6c757d;

            border-radius: 10px;

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


        .login-link {

            color:
                var(--market-green);

            font-weight: 600;

            text-decoration: none;

        }


        .login-link:hover {

            color:
                var(--market-green-dark);

        }


        @media(max-width: 991px) {

            .auth-visual {
                min-height: auto;
                padding: 35px;
            }

            .auth-form {
                padding: 35px;
            }

        }


        @media(max-width: 575px) {

            .auth-page {
                padding: 0;
                align-items: stretch;
            }

            .auth-container {
                border-radius: 0;
                min-height: 100vh;
            }

            .auth-visual {
                display: none;
            }

            .auth-form {
                padding: 30px 20px;
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
                 LEFT SIDE
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
                                    One marketplace
                                </small>


                                <h1 class="mt-2">

                                    One account.
                                    Every store.

                                </h1>


                                <p
                                    class="lead text-muted mt-3">

                                    Discover businesses,
                                    shop from different vendors
                                    and manage all your orders
                                    from one account.

                                </p>

                            </div>

                        </div>


                        <div class="row g-3">


                            <div class="col-12">

                                <div class="market-feature">

                                    <div
                                        class="d-flex align-items-center gap-3">

                                        <div class="feature-icon">

                                            <i
                                                class="bi bi-shop"></i>

                                        </div>

                                        <div>

                                            <strong>
                                                Multiple Stores
                                            </strong>

                                            <small
                                                class="d-block text-muted">
                                                Browse all active vendors
                                                in one marketplace.
                                            </small>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <div class="col-md-6">

                                <div class="market-feature h-100">

                                    <div
                                        class="d-flex align-items-center gap-3">

                                        <div class="feature-icon">

                                            <i
                                                class="bi bi-bag-check"></i>

                                        </div>

                                        <div>

                                            <strong>
                                                Easy Orders
                                            </strong>

                                            <small
                                                class="d-block text-muted">
                                                Shop and checkout easily.
                                            </small>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <div class="col-md-6">

                                <div class="market-feature h-100">

                                    <div
                                        class="d-flex align-items-center gap-3">

                                        <div class="feature-icon">

                                            <i
                                                class="bi bi-geo-alt"></i>

                                        </div>

                                        <div>

                                            <strong>
                                                Track Delivery
                                            </strong>

                                            <small
                                                class="d-block text-muted">
                                                Follow active deliveries.
                                            </small>

                                        </div>

                                    </div>

                                </div>

                            </div>


                        </div>

                    </div>

                </div>


                <!-- =================================================
                 RIGHT SIDE
            ================================================== -->

                <div class="col-lg-6">

                    <div class="auth-form">


                        <!-- MOBILE BRAND -->

                        <div
                            class="d-lg-none platform-brand mb-4">

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

                            Create your account

                        </h2>


                        <p class="text-muted mb-4">

                            Sign up once and shop from
                            any available store.

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


                        <!-- FORM -->

                        <form method="POST">


                            <div class="mb-3">

                                <label
                                    class="form-label">

                                    Full Name *

                                </label>


                                <input
                                    type="text"
                                    name="name"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $_POST['name']
                                                    ?? ''
                                            ) ?>"
                                    required
                                    autocomplete="name"
                                    placeholder="Your full name">

                            </div>


                            <div class="mb-3">

                                <label
                                    class="form-label">

                                    Email Address *

                                </label>


                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $_POST['email']
                                                    ?? ''
                                            ) ?>"
                                    required
                                    autocomplete="email"
                                    placeholder="you@example.com">

                            </div>


                            <div class="row g-3">


                                <div class="col-md-6">

                                    <label
                                        class="form-label">

                                        Phone *

                                    </label>


                                    <input
                                        type="text"
                                        name="phone"
                                        class="form-control"
                                        value="<?= htmlspecialchars(
                                                    $_POST['phone']
                                                        ?? ''
                                                ) ?>"
                                        required
                                        autocomplete="tel"
                                        placeholder="024 000 0000">

                                </div>


                                <div class="col-md-6">

                                    <label
                                        class="form-label">

                                        WhatsApp

                                    </label>


                                    <input
                                        type="text"
                                        name="whatsapp"
                                        class="form-control"
                                        value="<?= htmlspecialchars(
                                                    $_POST['whatsapp']
                                                        ?? ''
                                                ) ?>"
                                        autocomplete="tel"
                                        placeholder="Optional">

                                </div>


                            </div>


                            <div class="row g-3 mt-0">


                                <div class="col-md-6">

                                    <div
                                        class="password-group">

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
                                            required
                                            autocomplete="new-password"
                                            placeholder="Minimum 8 characters">


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

                                    <div
                                        class="password-group">

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
                                            required
                                            autocomplete="new-password"
                                            placeholder="Repeat password">


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


                            <div
                                class="form-check mt-4">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="terms"
                                    required>

                                <label
                                    class="form-check-label small text-muted"
                                    for="terms">

                                    I agree to the platform's
                                    terms and conditions.

                                </label>

                            </div>


                            <button
                                type="submit"
                                class="btn btn-market w-100 mt-4">

                                <i
                                    class="bi bi-person-plus me-1"></i>

                                Create Account

                            </button>


                        </form>


                        <div
                            class="text-center mt-4">

                            <span class="text-muted">
                                Already have an account?
                            </span>

                            <a
                                href="/somame_ent/login.php?customer=1"
                                class="login-link ms-1">
                                Sign in
                            </a>

                        </div>


                        <div
                            class="text-center mt-4">

                            <a
                                href="index.php"
                                class="text-decoration-none text-muted small">

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
        document.querySelectorAll(
            '.password-toggle'
        ).forEach(function(button) {

            button.addEventListener(
                'click',
                function() {

                    const targetId =
                        button.getAttribute(
                            'data-target'
                        );

                    const input =
                        document.getElementById(
                            targetId
                        );

                    const icon =
                        button.querySelector('i');


                    if (
                        input.type ===
                        'password'
                    ) {

                        input.type = 'text';

                        icon.className =
                            'bi bi-eye-slash';

                        button.setAttribute(
                            'aria-label',
                            'Hide password'
                        );

                    } else {

                        input.type =
                            'password';

                        icon.className =
                            'bi bi-eye';

                        button.setAttribute(
                            'aria-label',
                            'Show password'
                        );

                    }

                }
            );

        });
    </script>


</body>

</html>