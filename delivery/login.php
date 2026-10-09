<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$error = '';

if (
    isset($_SESSION['user_id'])
    && ($_SESSION['role'] ?? '') === 'delivery_partner'
) {
    header('Location: /somame_ent/delivery/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Enter a valid email address.';

    } elseif ($password === '') {

        $error = 'Enter your password.';

    } else {

        try {

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
                  AND u.role = 'delivery_partner'
                LIMIT 1
            ");

            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (
                !$user
                || !password_verify(
                    $password,
                    $user['password']
                )
            ) {
                throw new RuntimeException(
                    'Invalid delivery partner email or password.'
                );
            }

            if ($user['status'] !== 'active') {
                throw new RuntimeException(
                    'Your delivery partner account is not active.'
                );
            }

            if ($user['company_status'] !== 'active') {
                throw new RuntimeException(
                    'Your company account is currently unavailable.'
                );
            }

            $subscriptionValid = false;

            if ($user['subscription_plan'] === 'trial') {

                $subscriptionValid =
                    !empty($user['trial_ends_at'])
                    && strtotime($user['trial_ends_at']) >= time();

            } elseif (
                in_array(
                    $user['subscription_plan'],
                    ['monthly', 'quarterly', 'yearly'],
                    true
                )
            ) {

                $subscriptionValid =
                    !empty($user['subscription_ends_at'])
                    && strtotime($user['subscription_ends_at']) >= time();
            }

            if (!$subscriptionValid) {
                throw new RuntimeException(
                    'Your company subscription has expired. Please contact your company administrator.'
                );
            }

            $_SESSION = [];
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = 'delivery_partner';
            $_SESSION['company_id'] = (int)$user['company_id'];
            $_SESSION['company_name'] = $user['company_name'];

            header(
                'Location: /somame_ent/delivery/dashboard.php'
            );

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
        content="width=device-width, initial-scale=1"
    >

    <title>
        Delivery Partner Login | Grocery Delivery
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
            --green: #198754;
            --green-dark: #146c43;
            --navy: #0f172a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(25,135,84,.18), transparent 32%),
                #f4f7f5;
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
            padding: 24px;
        }

        .login-card {
            width: 100%;
            max-width: 980px;
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(255,255,255,.7);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(15,23,42,.16);
            backdrop-filter: blur(16px);
        }

        .brand-panel {
            min-height: 610px;
            padding: 48px;
            color: #fff;
            background:
                linear-gradient(145deg, #0f172a, #14532d);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .brand-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,.14);
            font-size: 1.45rem;
        }

        .brand-title {
            font-size: clamp(2.2rem, 4vw, 3.5rem);
            font-weight: 800;
            line-height: 1.05;
        }

        .brand-copy {
            color: rgba(255,255,255,.72);
            max-width: 420px;
        }

        .login-area {
            min-height: 610px;
            padding: 58px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .portal-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            width: fit-content;
            padding: 7px 12px;
            border-radius: 999px;
            background: #e9f7ef;
            color: var(--green-dark);
            font-size: .8rem;
            font-weight: 700;
        }

        .form-control {
            min-height: 52px;
            border-radius: 13px;
        }

        .form-control:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 .2rem rgba(25,135,84,.11);
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
            border-radius: 10px;
            background: transparent;
            color: #667085;
        }

        .login-btn {
            min-height: 52px;
            border-radius: 13px;
            background: var(--green);
            border-color: var(--green);
            color: #fff;
            font-weight: 600;
        }

        .login-btn:hover {
            background: var(--green-dark);
            border-color: var(--green-dark);
            color: #fff;
        }

        .portal-link {
            color: #667085;
            text-decoration: none;
            font-size: .83rem;
        }

        .portal-link:hover {
            color: var(--green);
        }

        @media(max-width: 767px) {

            .login-page {
                padding: 0;
            }

            .login-card {
                min-height: 100vh;
                border-radius: 0;
            }

            .brand-panel {
                display: none;
            }

            .login-area {
                min-height: 100vh;
                padding: 30px 22px;
            }
        }

    </style>

</head>

<body>

<div class="login-page">

    <div class="login-card">

        <div class="row g-0">

            <div class="col-lg-6">

                <div class="brand-panel">

                    <div>

                        <div class="d-flex align-items-center gap-3">

                            <div class="brand-icon">
                                <i class="bi bi-truck"></i>
                            </div>

                            <div>
                                <strong class="fs-5">
                                    Grocery Delivery
                                </strong>

                                <small
                                    class="d-block"
                                    style="color:rgba(255,255,255,.65)"
                                >
                                    Delivery Partner Portal
                                </small>
                            </div>

                        </div>

                        <div class="mt-5">

                            <h1 class="brand-title">
                                Deliver with confidence.
                            </h1>

                            <p class="brand-copy mt-3">
                                View assigned deliveries, order details
                                and delivery tracking tools from one secure portal.
                            </p>

                        </div>

                    </div>

                    <div>
                        <i class="bi bi-shield-check me-1"></i>
                        Secure Delivery Access
                    </div>

                </div>

            </div>

            <div class="col-lg-6">

                <div class="login-area">

                    <span class="portal-badge mb-3">
                        <i class="bi bi-bicycle"></i>
                        DELIVERY PARTNER
                    </span>

                    <h2 class="fw-bold mb-1">
                        Delivery sign in
                    </h2>

                    <p class="text-muted mb-4">
                        Use the delivery account created by your company.
                    </p>

                    <?php if ($error): ?>

                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>

                    <?php endif; ?>

                    <form method="POST">

                        <div class="mb-3">

                            <label class="form-label">
                                Delivery Partner Email
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                placeholder="rider@company.com"
                                required
                                autofocus
                                autocomplete="email"
                            >

                        </div>

                        <div class="mb-4 password-wrap">

                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                id="deliveryPassword"
                                class="form-control"
                                placeholder="Enter password"
                                required
                                autocomplete="current-password"
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                id="togglePassword"
                                aria-label="Show password"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                        </div>

                        <button
                            type="submit"
                            class="btn login-btn w-100"
                        >
                            <i class="bi bi-box-arrow-in-right me-2"></i>
                            Sign In to Delivery Portal
                        </button>

                    </form>

                    <div class="text-center mt-4">

                        <a
                            href="/somame_ent/admin/login.php"
                            class="portal-link"
                        >
                            Company Admin
                        </a>

                        <span class="text-muted mx-2">•</span>

                        <a
                            href="/somame_ent/staff/login.php"
                            class="portal-link"
                        >
                            Staff
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script>

const password =
    document.getElementById('deliveryPassword');

const toggle =
    document.getElementById('togglePassword');

toggle.addEventListener(
    'click',
    function () {

        const icon =
            toggle.querySelector('i');

        const visible =
            password.type === 'text';

        password.type =
            visible
            ? 'password'
            : 'text';

        icon.className =
            visible
            ? 'bi bi-eye'
            : 'bi bi-eye-slash';

        toggle.setAttribute(
            'aria-label',
            visible
            ? 'Show password'
            : 'Hide password'
        );
    }
);

</script>

</body>
</html>
