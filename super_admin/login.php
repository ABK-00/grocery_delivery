<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$error = '';


/*
|--------------------------------------------------------------------------
| ALREADY LOGGED IN AS SUPER ADMIN
|--------------------------------------------------------------------------
*/

if (
    isset($_SESSION['user_id'])
    && ($_SESSION['role'] ?? '') === 'super_admin'
) {

    header(
        'Location: /somame_ent/super_admin/dashboard.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| SUPER ADMIN LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(
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
            | FIND SUPER ADMIN ONLY
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    company_id,
                    name,
                    email,
                    phone,
                    password,
                    role,
                    status

                FROM users

                WHERE email = ?
                  AND role = 'super_admin'

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

            if (!$user) {

                throw new RuntimeException(
                    'Invalid Super Admin email or password.'
                );
            }


            if (
                !password_verify(
                    $password,
                    $user['password']
                )
            ) {

                throw new RuntimeException(
                    'Invalid Super Admin email or password.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | STATUS
            |--------------------------------------------------------------------------
            */

            if ($user['status'] !== 'active') {

                throw new RuntimeException(
                    'This Super Admin account is not active.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SUPER ADMIN MUST NOT BELONG TO A COMPANY
            |--------------------------------------------------------------------------
            */

            if ($user['company_id'] !== null) {

                $stmt = $conn->prepare("
                    UPDATE users

                    SET company_id = NULL

                    WHERE id = ?
                      AND role = 'super_admin'
                ");

                $stmt->execute([
                    $user['id']
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | CREATE SESSION
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);

            /*
             * Clear any previous role data.
             */
            $_SESSION = [];


            $_SESSION['user_id'] =
                (int)$user['id'];

            $_SESSION['name'] =
                $user['name'];

            $_SESSION['email'] =
                $user['email'];

            $_SESSION['role'] =
                'super_admin';

            $_SESSION['company_id'] =
                null;


            /*
            |--------------------------------------------------------------------------
            | REDIRECT
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /somame_ent/super_admin/dashboard.php'
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
        Super Admin Login | Grocery Delivery
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">


    <style>
        :root {
            --navy: #101828;
            --navy-light: #182230;
            --green: #198754;
            --green-dark: #146c43;
        }


        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            min-height: 100vh;

            background:
                linear-gradient(135deg,
                    #0f172a,
                    #172033);

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

            max-width: 1050px;

            background: #fff;

            border-radius: 28px;

            overflow: hidden;

            box-shadow:
                0 30px 80px rgba(0, 0, 0, .30);

        }


        /* =====================================================
           LEFT
        ====================================================== */

        .login-brand {

            min-height: 620px;

            padding: 55px;

            color: #fff;

            background:
                linear-gradient(150deg,
                    #101828,
                    #182230);

            display: flex;

            flex-direction: column;

            justify-content: space-between;

        }


        .brand-header {

            display: flex;

            align-items: center;

            gap: 12px;

        }


        .brand-icon {

            width: 52px;

            height: 52px;

            border-radius: 16px;

            background:
                var(--green);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 1.45rem;

        }


        .platform-label {

            color:
                rgba(255,
                    255,
                    255,
                    .6);

            font-size: .82rem;

        }


        .hero-title {

            font-size:
                clamp(2.3rem,
                    5vw,
                    3.7rem);

            font-weight: 800;

            line-height: 1.05;

        }


        .hero-text {

            max-width: 450px;

            color:
                rgba(255,
                    255,
                    255,
                    .68);

            font-size: 1.05rem;

        }


        .security-card {

            background:
                rgba(255,
                    255,
                    255,
                    .07);

            border:
                1px solid rgba(255,
                    255,
                    255,
                    .1);

            border-radius: 18px;

            padding: 18px;

        }


        .security-icon {

            width: 45px;

            height: 45px;

            border-radius: 13px;

            display: flex;

            justify-content: center;

            align-items: center;

            background:
                rgba(25,
                    135,
                    84,
                    .25);

            color: #75d6a3;

            flex-shrink: 0;

        }


        /* =====================================================
           RIGHT
        ====================================================== */

        .login-form-area {

            min-height: 620px;

            padding: 60px;

            display: flex;

            flex-direction: column;

            justify-content: center;

        }


        .admin-badge {

            display: inline-flex;

            align-items: center;

            width: fit-content;

            gap: 7px;

            border-radius: 999px;

            padding: 7px 12px;

            background: #eaf7ef;

            color:
                var(--green-dark);

            font-size: .8rem;

            font-weight: 700;

        }


        .form-control {

            min-height: 52px;

            border-radius: 14px;

        }


        .form-control:focus {

            border-color:
                var(--green);

            box-shadow:
                0 0 0 .2rem rgba(25,
                    135,
                    84,
                    .12);

        }


        .password-wrap {

            position: relative;

        }


        .password-wrap input {

            padding-right: 52px;

        }


        .password-toggle {

            position: absolute;

            right: 7px;

            bottom: 7px;

            width: 38px;

            height: 38px;

            border: 0;

            background: transparent;

            color: #667085;

            border-radius: 10px;

        }


        .login-btn {

            min-height: 52px;

            border-radius: 14px;

            background:
                var(--green);

            border-color:
                var(--green);

            color: #fff;

            font-weight: 600;

        }


        .login-btn:hover {

            background:
                var(--green-dark);

            border-color:
                var(--green-dark);

            color: #fff;

        }


        .system-login-link {

            color:
                var(--green);

            font-weight: 600;

            text-decoration: none;

        }


        .system-login-link:hover {

            color:
                var(--green-dark);

        }


        @media(max-width: 991px) {

            .login-brand {

                min-height: auto;

                padding: 35px;

            }


            .login-form-area {

                min-height: auto;

                padding: 45px 35px;

            }

        }


        @media(max-width: 767px) {

            .login-page {

                padding: 0;

            }


            .login-card {

                min-height: 100vh;

                border-radius: 0;

            }


            .login-brand {

                display: none;

            }


            .login-form-area {

                min-height: 100vh;

                padding: 30px 20px;

            }

        }
    </style>

</head>

<body>


    <div class="login-page">

        <div class="login-card">

            <div class="row g-0">


                <!-- =================================================
                 LEFT PANEL
            ================================================== -->

                <div class="col-lg-6">

                    <div class="login-brand">


                        <div>

                            <div
                                class="brand-header">

                                <div class="brand-icon">

                                    <i
                                        class="bi bi-shield-lock-fill"></i>

                                </div>


                                <div>

                                    <strong
                                        class="fs-5">
                                        Grocery Delivery
                                    </strong>

                                    <div
                                        class="platform-label">
                                        Platform Administration
                                    </div>

                                </div>

                            </div>


                            <div class="mt-5">

                                <div
                                    class="text-success fw-bold small text-uppercase">
                                    Platform Control
                                </div>


                                <h1
                                    class="hero-title mt-2">

                                    Super Admin
                                    Console

                                </h1>


                                <p
                                    class="hero-text mt-3">

                                    Manage vendors,
                                    subscriptions,
                                    platform payments,
                                    users and marketplace
                                    operations from one place.

                                </p>

                            </div>

                        </div>


                        <div class="security-card">

                            <div
                                class="d-flex gap-3">

                                <div
                                    class="security-icon">

                                    <i
                                        class="bi bi-shield-check"></i>

                                </div>


                                <div>

                                    <strong
                                        class="d-block">
                                        Restricted Access
                                    </strong>


                                    <small
                                        class="platform-label">

                                        This portal is reserved
                                        for authorized platform
                                        administrators.

                                    </small>

                                </div>

                            </div>

                        </div>


                    </div>

                </div>


                <!-- =================================================
                 LOGIN FORM
            ================================================== -->

                <div class="col-lg-6">

                    <div
                        class="login-form-area">


                        <!-- MOBILE BRAND -->

                        <div
                            class="d-lg-none brand-header mb-5 text-dark">

                            <div class="brand-icon">

                                <i
                                    class="bi bi-shield-lock-fill"></i>

                            </div>


                            <div>

                                <strong>
                                    Grocery Delivery
                                </strong>

                                <small
                                    class="d-block text-muted">
                                    Super Admin
                                </small>

                            </div>

                        </div>


                        <div class="admin-badge mb-3">

                            <i
                                class="bi bi-person-lock"></i>

                            SUPER ADMIN

                        </div>


                        <h2
                            class="fw-bold mb-1">
                            Welcome back
                        </h2>


                        <p
                            class="text-muted mb-4">

                            Sign in to manage the
                            Grocery Delivery platform.

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
                                    placeholder="admin@example.com"
                                    autocomplete="email"
                                    required
                                    autofocus>

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
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
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


                            <button
                                type="submit"
                                class="btn login-btn w-100">

                                <i
                                    class="bi bi-box-arrow-in-right me-2"></i>

                                Sign In to Console

                            </button>

                        </form>


                        <div
                            class="text-center mt-4">

                            <small
                                class="text-muted">

                                Customer or company user?

                            </small>

                            <a
                                href="/somame_ent/login.php"
                                class="system-login-link ms-1">
                                Main Login
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
                'adminPassword'
            );

        const toggle =
            document.getElementById(
                'passwordToggle'
            );


        toggle.addEventListener(
            'click',
            function() {

                const icon =
                    toggle.querySelector('i');


                if (
                    passwordInput.type ===
                    'password'
                ) {

                    passwordInput.type =
                        'text';

                    icon.className =
                        'bi bi-eye-slash';

                    toggle.setAttribute(
                        'aria-label',
                        'Hide password'
                    );

                } else {

                    passwordInput.type =
                        'password';

                    icon.className =
                        'bi bi-eye';

                    toggle.setAttribute(
                        'aria-label',
                        'Show password'
                    );

                }

            }
        );
    </script>


</body>

</html>