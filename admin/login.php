<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$error = '';


/*
|--------------------------------------------------------------------------
| IF ALREADY LOGGED IN AS COMPANY ADMIN
|--------------------------------------------------------------------------
|
| Only redirect if the CURRENT session is actually an admin session.
| A Super Admin session must NOT hijack this login page.
|--------------------------------------------------------------------------
*/

if (
    isset($_SESSION['user_id'])
    && ($_SESSION['role'] ?? '') === 'admin'
) {
    header(
        'Location: /somame_ent/admin/dashboard.php'
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email =
        strtolower(
            trim($_POST['email'] ?? '')
        );

    $password =
        $_POST['password'] ?? '';


    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $error =
            'Enter a valid email address.';
    } elseif ($password === '') {

        $error =
            'Enter your password.';
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | LOAD COMPANY ADMIN
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT

                    u.id,
                    u.company_id,
                    u.name,
                    u.email,
                    u.password,
                    u.role,
                    u.status,

                    c.company_name,
                    c.status AS company_status,

                    c.subscription_plan,
                    c.trial_ends_at,
                    c.subscription_ends_at

                FROM users u

                INNER JOIN companies c
                    ON c.id = u.company_id

                WHERE u.email = ?
                  AND u.role = 'admin'

                LIMIT 1
            ");

            $stmt->execute([
                $email
            ]);

            $user =
                $stmt->fetch();


            /*
            |--------------------------------------------------------------------------
            | VERIFY ACCOUNT
            |--------------------------------------------------------------------------
            */

            if (
                !$user
                || !password_verify(
                    $password,
                    $user['password']
                )
            ) {

                throw new RuntimeException(
                    'Invalid admin email or password.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | USER STATUS
            |--------------------------------------------------------------------------
            */

            if (
                $user['status']
                !== 'active'
            ) {

                throw new RuntimeException(
                    'This admin account is not active.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | COMPANY STATUS
            |--------------------------------------------------------------------------
            */

            if (
                $user['company_status']
                !== 'active'
            ) {

                throw new RuntimeException(
                    'This company account is currently unavailable.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SUBSCRIPTION
            |--------------------------------------------------------------------------
            */

            $subscriptionValid =
                false;


            if (
                $user['subscription_plan']
                === 'trial'
            ) {

                if (
                    !empty($user['trial_ends_at'])
                    && strtotime(
                        $user['trial_ends_at']
                    ) >= time()
                ) {

                    $subscriptionValid =
                        true;
                }
            } elseif (
                in_array(
                    $user['subscription_plan'],
                    [
                        'monthly',
                        'quarterly',
                        'yearly'
                    ],
                    true
                )
            ) {

                if (
                    !empty($user['subscription_ends_at'])
                    && strtotime(
                        $user['subscription_ends_at']
                    ) >= time()
                ) {

                    $subscriptionValid =
                        true;
                }
            }


            if (!$subscriptionValid) {

                throw new RuntimeException(
                    'Your company subscription has expired. Please contact the platform administrator.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | REPLACE ANY EXISTING SESSION
            |--------------------------------------------------------------------------
            |
            | This is what prevents Super Admin → Admin session mixing.
            |--------------------------------------------------------------------------
            */

            $_SESSION = [];


            /*
             * Remove old session cookie.
             */
            if (
                ini_get(
                    'session.use_cookies'
                )
            ) {

                $params =
                    session_get_cookie_params();


                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }


            /*
             * Destroy the existing session.
             */
            session_destroy();


            /*
             * Start completely fresh session.
             */
            session_start();

            session_regenerate_id(
                true
            );


            /*
            |--------------------------------------------------------------------------
            | ADMIN SESSION
            |--------------------------------------------------------------------------
            */

            $_SESSION['user_id'] =
                (int)$user['id'];

            $_SESSION['name'] =
                $user['name'];

            $_SESSION['email'] =
                $user['email'];

            $_SESSION['role'] =
                'admin';

            $_SESSION['company_id'] =
                (int)$user['company_id'];

            $_SESSION['company_name'] =
                $user['company_name'];


            /*
            |--------------------------------------------------------------------------
            | REDIRECT
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /somame_ent/admin/dashboard.php'
            );

            exit;
        } catch (Throwable $e) {

            $error =
                $e->getMessage();
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
        Company Admin Login | Grocery Delivery
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
            --navy: #101828;
        }


        * {
            box-sizing: border-box;
        }


        body {

            min-height: 100vh;

            margin: 0;

            background:
                #f4f6f8;

            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

        }


        .login-page {

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 25px;

        }


        .login-card {

            width: 100%;

            max-width: 1000px;

            background: #fff;

            border-radius: 28px;

            overflow: hidden;

            box-shadow:
                0 25px 70px rgba(16,
                    24,
                    40,
                    .15);

        }


        /*
        |--------------------------------------------------------------------------
        | BRAND PANEL
        |--------------------------------------------------------------------------
        */

        .brand-panel {

            min-height: 610px;

            padding: 50px;

            background:
                linear-gradient(145deg,
                    #0f5132,
                    #198754);

            color: #fff;

            display: flex;

            flex-direction: column;

            justify-content:
                space-between;

        }


        .brand-icon {

            width: 52px;

            height: 52px;

            border-radius: 16px;

            display: flex;

            align-items: center;

            justify-content: center;

            background:
                rgba(255,
                    255,
                    255,
                    .16);

            font-size:
                1.4rem;

        }


        .brand-title {

            font-size:
                clamp(2.2rem,
                    4vw,
                    3.4rem);

            font-weight: 800;

            line-height: 1.05;

        }


        .brand-text {

            color:
                rgba(255,
                    255,
                    255,
                    .73);

            max-width:
                430px;

        }


        /*
        |--------------------------------------------------------------------------
        | LOGIN
        |--------------------------------------------------------------------------
        */

        .login-area {

            min-height: 610px;

            padding: 60px;

            display: flex;

            flex-direction: column;

            justify-content: center;

        }


        .portal-badge {

            display:
                inline-flex;

            align-items:
                center;

            gap: 7px;

            width: fit-content;

            padding:
                7px 12px;

            border-radius:
                999px;

            color:
                var(--green-dark);

            background:
                #e9f7ef;

            font-size:
                .8rem;

            font-weight: 700;

        }


        .form-control {

            min-height:
                52px;

            border-radius:
                13px;

        }


        .form-control:focus {

            border-color:
                var(--green);

            box-shadow:
                0 0 0 .2rem rgba(25,
                    135,
                    84,
                    .11);

        }


        .password-wrap {

            position: relative;

        }


        .password-wrap input {

            padding-right:
                50px;

        }


        .password-toggle {

            position:
                absolute;

            right: 7px;

            bottom: 7px;

            width: 38px;

            height: 38px;

            border: 0;

            background:
                transparent;

            border-radius:
                10px;

            color:
                #667085;

        }


        .login-btn {

            min-height:
                52px;

            border-radius:
                13px;

            background:
                var(--green);

            border-color:
                var(--green);

            color:
                #fff;

            font-weight:
                600;

        }


        .login-btn:hover {

            background:
                var(--green-dark);

            border-color:
                var(--green-dark);

            color:
                #fff;

        }


        @media(max-width: 767px) {

            .login-page {

                padding: 0;

            }


            .login-card {

                min-height:
                    100vh;

                border-radius: 0;

            }


            .brand-panel {

                display: none;

            }


            .login-area {

                min-height:
                    100vh;

                padding:
                    30px 22px;

            }

        }
    </style>

</head>

<body>


    <div class="login-page">

        <div class="login-card">

            <div class="row g-0">


                <!-- =============================================
                 BRAND
            ============================================== -->

                <div class="col-lg-6">

                    <div class="brand-panel">


                        <div>

                            <div
                                class="d-flex align-items-center gap-3">

                                <div class="brand-icon">

                                    <i
                                        class="bi bi-shop"></i>

                                </div>


                                <div>

                                    <strong
                                        class="fs-5">
                                        Grocery Delivery
                                    </strong>

                                    <small
                                        class="d-block"
                                        style="
                                        color:
                                        rgba(
                                            255,
                                            255,
                                            255,
                                            .65
                                        );
                                    ">
                                        Vendor Portal
                                    </small>

                                </div>

                            </div>


                            <div class="mt-5">

                                <h1
                                    class="brand-title">
                                    Manage your
                                    storefront.
                                </h1>


                                <p
                                    class="brand-text mt-3">

                                    Manage products,
                                    orders, staff,
                                    deliveries and your
                                    company storefront
                                    from one place.

                                </p>

                            </div>

                        </div>


                        <div>

                            <i
                                class="bi bi-shield-check me-1"></i>

                            Secure Company Administration

                        </div>


                    </div>

                </div>


                <!-- =============================================
                 LOGIN
            ============================================== -->

                <div class="col-lg-6">

                    <div class="login-area">


                        <span
                            class="portal-badge mb-3">

                            <i
                                class="bi bi-building-lock"></i>

                            COMPANY ADMIN

                        </span>


                        <h2 class="fw-bold mb-1">

                            Welcome back

                        </h2>


                        <p
                            class="text-muted mb-4">

                            Sign in to your company's
                            administration portal.

                        </p>


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


                        <form method="POST">


                            <div class="mb-3">

                                <label
                                    class="form-label">
                                    Admin Email
                                </label>


                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $_POST['email']
                                                    ?? ''
                                            ) ?>"
                                    placeholder="admin@company.com"
                                    required
                                    autofocus
                                    autocomplete="email">

                            </div>


                            <div
                                class="mb-4 password-wrap">

                                <label
                                    class="form-label">
                                    Password
                                </label>


                                <input
                                    type="password"
                                    name="password"
                                    id="adminPassword"
                                    class="form-control"
                                    placeholder="Enter password"
                                    required
                                    autocomplete="current-password">


                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="togglePassword">

                                    <i
                                        class="bi bi-eye"></i>

                                </button>

                            </div>


                            <button
                                type="submit"
                                class="btn login-btn w-100">

                                <i
                                    class="bi bi-box-arrow-in-right me-2"></i>

                                Sign In to Vendor Portal

                            </button>

                        </form>


                        <div
                            class="text-center mt-4">

                            <a
                                href="/somame_ent/login.php?customer=1"
                                class="text-muted text-decoration-none small">

                                <i
                                    class="bi bi-person me-1"></i>

                                Customer Login

                            </a>


                            <span
                                class="text-muted mx-2">
                                •
                            </span>


                            <a
                                href="/somame_ent/super_admin/login.php"
                                class="text-muted text-decoration-none small">

                                <i
                                    class="bi bi-shield-lock me-1"></i>

                                Platform Admin

                            </a>

                        </div>


                    </div>

                </div>


            </div>

        </div>

    </div>


    <script>
        const password =
            document.getElementById(
                'adminPassword'
            );

        const toggle =
            document.getElementById(
                'togglePassword'
            );


        toggle.addEventListener(
            'click',
            function() {

                const icon =
                    toggle.querySelector('i');


                if (
                    password.type ===
                    'password'
                ) {

                    password.type =
                        'text';

                    icon.className =
                        'bi bi-eye-slash';

                } else {

                    password.type =
                        'password';

                    icon.className =
                        'bi bi-eye';

                }

            }
        );
    </script>


</body>

</html>