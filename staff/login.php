<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$error = '';


/*
|--------------------------------------------------------------------------
| ALREADY LOGGED IN AS STAFF
|--------------------------------------------------------------------------
|
| Only an existing STAFF session should automatically enter
| the staff dashboard.
|--------------------------------------------------------------------------
*/

if (
    isset($_SESSION['user_id'])
    && ($_SESSION['role'] ?? '') === 'staff'
) {
    header(
        'Location: /somame_ent/staff/dashboard.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| STAFF LOGIN
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
            | FIND STAFF MEMBER
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT

                    u.id,
                    u.company_id,
                    u.name,
                    u.email,
                    u.phone,
                    u.whatsapp,
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
                  AND u.role = 'staff'

                LIMIT 1
            ");

            $stmt->execute([
                $email
            ]);

            $user =
                $stmt->fetch();


            /*
            |--------------------------------------------------------------------------
            | VERIFY LOGIN
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
                    'Invalid staff email or password.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | STAFF ACCOUNT STATUS
            |--------------------------------------------------------------------------
            */

            if (
                $user['status']
                !== 'active'
            ) {

                throw new RuntimeException(
                    'Your staff account is currently inactive.'
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
                    'Your company account is currently unavailable.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | COMPANY SUBSCRIPTION
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
                    'Your company subscription has expired. Please contact your company administrator.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | REPLACE CURRENT SESSION
            |--------------------------------------------------------------------------
            |
            | This prevents Admin / Super Admin / Customer sessions
            | from mixing with the Staff session.
            |--------------------------------------------------------------------------
            */

            $_SESSION = [];

            session_regenerate_id(
                true
            );


            /*
            |--------------------------------------------------------------------------
            | STAFF SESSION
            |--------------------------------------------------------------------------
            */

            $_SESSION['user_id'] =
                (int)$user['id'];

            $_SESSION['name'] =
                $user['name'];

            $_SESSION['email'] =
                $user['email'];

            $_SESSION['role'] =
                'staff';

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
                'Location: /somame_ent/staff/dashboard.php'
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
        Staff Login | Grocery Delivery
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">


    <style>
        :root {

            --green:
                #198754;

            --green-dark:
                #146c43;

            --navy:
                #101828;

            --muted:
                #667085;

        }


        * {
            box-sizing:
                border-box;
        }


        body {

            margin:
                0;

            min-height:
                100vh;

            background:
                #f4f6f8;

            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

        }


        /*
        |--------------------------------------------------------------------------
        | PAGE
        |--------------------------------------------------------------------------
        */

        .login-page {

            min-height:
                100vh;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            padding:
                25px;

        }


        .login-card {

            width:
                100%;

            max-width:
                1000px;

            overflow:
                hidden;

            border-radius:
                28px;

            background:
                #fff;

            box-shadow:
                0 25px 70px rgba(16,
                    24,
                    40,
                    .15);

        }


        /*
        |--------------------------------------------------------------------------
        | LEFT PANEL
        |--------------------------------------------------------------------------
        */

        .brand-panel {

            min-height:
                610px;

            padding:
                50px;

            display:
                flex;

            flex-direction:
                column;

            justify-content:
                space-between;

            color:
                #fff;

            background:
                linear-gradient(145deg,
                    #173e2b,
                    #198754);

        }


        .brand-row {

            display:
                flex;

            align-items:
                center;

            gap:
                13px;

        }


        .brand-icon {

            width:
                52px;

            height:
                52px;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            border-radius:
                16px;

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

            font-weight:
                800;

            line-height:
                1.05;

        }


        .brand-description {

            max-width:
                430px;

            color:
                rgba(255,
                    255,
                    255,
                    .72);

            font-size:
                1rem;

        }


        .staff-info {

            padding:
                17px;

            border:
                1px solid rgba(255,
                    255,
                    255,
                    .13);

            border-radius:
                17px;

            background:
                rgba(255,
                    255,
                    255,
                    .08);

        }


        /*
        |--------------------------------------------------------------------------
        | LOGIN AREA
        |--------------------------------------------------------------------------
        */

        .login-area {

            min-height:
                610px;

            padding:
                60px;

            display:
                flex;

            flex-direction:
                column;

            justify-content:
                center;

        }


        .portal-badge {

            display:
                inline-flex;

            align-items:
                center;

            gap:
                7px;

            width:
                fit-content;

            padding:
                7px 12px;

            border-radius:
                999px;

            background:
                #eaf7ef;

            color:
                var(--green-dark);

            font-size:
                .8rem;

            font-weight:
                700;

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


        /*
        |--------------------------------------------------------------------------
        | PASSWORD
        |--------------------------------------------------------------------------
        */

        .password-wrap {

            position:
                relative;

        }


        .password-wrap input {

            padding-right:
                52px;

        }


        .password-toggle {

            position:
                absolute;

            right:
                7px;

            bottom:
                7px;

            width:
                38px;

            height:
                38px;

            border:
                0;

            border-radius:
                10px;

            background:
                transparent;

            color:
                var(--muted);

        }


        /*
        |--------------------------------------------------------------------------
        | BUTTON
        |--------------------------------------------------------------------------
        */

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

            color:
                #fff;

            background:
                var(--green-dark);

            border-color:
                var(--green-dark);

        }


        /*
        |--------------------------------------------------------------------------
        | OTHER PORTALS
        |--------------------------------------------------------------------------
        */

        .portal-link {

            color:
                var(--muted);

            font-size:
                .82rem;

            text-decoration:
                none;

        }


        .portal-link:hover {

            color:
                var(--green);

        }


        /*
        |--------------------------------------------------------------------------
        | MOBILE
        |--------------------------------------------------------------------------
        */

        @media(max-width: 767px) {

            .login-page {

                padding:
                    0;

            }


            .login-card {

                min-height:
                    100vh;

                border-radius:
                    0;

            }


            .brand-panel {

                display:
                    none;

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


                <!-- =================================================
                 LEFT
            ================================================== -->

                <div class="col-lg-6">

                    <div class="brand-panel">


                        <div>

                            <div class="brand-row">

                                <div class="brand-icon">

                                    <i
                                        class="bi bi-people-fill"></i>

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
                                            .63
                                        );
                                    ">
                                        Staff Portal
                                    </small>

                                </div>

                            </div>


                            <div class="mt-5">

                                <h1
                                    class="brand-title">

                                    Keep orders
                                    moving.

                                </h1>


                                <p
                                    class="brand-description mt-3">

                                    Access your company's
                                    orders and operational
                                    tools securely through
                                    the staff portal.

                                </p>

                            </div>

                        </div>


                        <div class="staff-info">

                            <div
                                class="d-flex gap-3 align-items-center">

                                <i
                                    class="bi bi-shield-check fs-4"></i>


                                <div>

                                    <strong
                                        class="d-block">
                                        Staff Access
                                    </strong>

                                    <small
                                        style="
                                        color:
                                        rgba(
                                            255,
                                            255,
                                            255,
                                            .68
                                        );
                                    ">
                                        Your access is managed
                                        by your company administrator.
                                    </small>

                                </div>

                            </div>

                        </div>


                    </div>

                </div>


                <!-- =================================================
                 RIGHT
            ================================================== -->

                <div class="col-lg-6">

                    <div class="login-area">


                        <span
                            class="portal-badge mb-3">

                            <i
                                class="bi bi-person-badge"></i>

                            STAFF PORTAL

                        </span>


                        <h2
                            class="fw-bold mb-1">

                            Staff sign in

                        </h2>


                        <p
                            class="text-muted mb-4">

                            Use the staff account provided
                            by your company administrator.

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

                        <form method="POST">


                            <div class="mb-3">

                                <label
                                    class="form-label">

                                    Staff Email

                                </label>


                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $_POST['email']
                                                    ?? ''
                                            ) ?>"
                                    placeholder="staff@company.com"
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
                                    id="staffPassword"
                                    class="form-control"
                                    placeholder="Enter your password"
                                    required
                                    autocomplete="current-password">


                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="togglePassword"
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

                                Sign In to Staff Portal

                            </button>


                        </form>


                        <!-- OTHER PORTALS -->

                        <div
                            class="text-center mt-4">

                            <a
                                href="/somame_ent/admin/login.php"
                                class="portal-link">

                                <i
                                    class="bi bi-building me-1"></i>

                                Company Admin

                            </a>


                            <span
                                class="text-muted mx-2">
                                •
                            </span>


                            <a
                                href="/somame_ent/login.php?customer=1"
                                class="portal-link">

                                <i
                                    class="bi bi-person me-1"></i>

                                Customer

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
                'staffPassword'
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

                    toggle.setAttribute(
                        'aria-label',
                        'Hide password'
                    );

                } else {

                    password.type =
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