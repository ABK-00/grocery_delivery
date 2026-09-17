<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('customer');

$userId = $_SESSION['user_id'];

$success = "";
$error = "";

/*
|--------------------------------------------------------------------------
| Update Profile
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');

    if ($name === "" || $email === "") {
        $error = "Name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$email, $userId]);

        if ($stmt->fetch()) {

            $error = "That email address is already being used.";

        } else {

            $stmt = $conn->prepare("
                UPDATE users
                SET name = ?,
                    email = ?,
                    phone = ?,
                    whatsapp = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $name,
                $email,
                $phone !== "" ? $phone : null,
                $whatsapp !== "" ? $whatsapp : null,
                $userId
            ]);

            $_SESSION['user_name'] = $name;

            $success = "Your profile has been updated successfully.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Change Password
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {

        $error = "Please complete all password fields.";

    } elseif (strlen($newPassword) < 8) {

        $error = "Your new password must contain at least 8 characters.";

    } elseif ($newPassword !== $confirmPassword) {

        $error = "The new passwords do not match.";

    } else {

        $stmt = $conn->prepare("
            SELECT password
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password'])) {

            $error = "Your current password is incorrect.";

        } else {

            $hashedPassword = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $hashedPassword,
                $userId
            ]);

            $success = "Your password has been changed successfully.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Get Current User
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT id, name, email, phone, whatsapp, created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>My Profile | GroceryDelivery</title>

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
            --navy: #071a2b;
            --navy-light: #0d2940;
            --green: #19b56b;
            --green-dark: #119456;
            --light: #f4f7f9;
            --muted: #718096;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--light);
            font-family: Arial, sans-serif;
            color: #1f2937;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: var(--navy);
            color: white;
            z-index: 1000;
            padding: 22px 15px;
        }

        .brand {
            font-size: 22px;
            font-weight: 700;
            padding: 0 12px 25px;
        }

        .brand span {
            color: var(--green);
        }

        .user-box {
            background: rgba(255,255,255,.06);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .nav-link {
            color: #cbd5e1;
            padding: 12px 14px;
            border-radius: 9px;
            margin-bottom: 5px;
            transition: .2s;
        }

        .nav-link:hover,
        .nav-link.active {
            background: var(--green);
            color: white;
        }

        .nav-link i {
            width: 24px;
        }

        .main {
            margin-left: 250px;
            min-height: 100vh;
        }

        .topbar {
            background: white;
            padding: 18px 30px;
            border-bottom: 1px solid #e5e7eb;
        }

        .content {
            padding: 30px;
        }

        .page-title {
            font-weight: 700;
            color: var(--navy);
        }

        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(7,26,43,.06);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #edf0f2;
            padding: 20px 22px;
            border-radius: 16px 16px 0 0 !important;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #d9e0e6;
        }

        .form-control:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 .2rem rgba(25,181,107,.12);
        }

        .btn-green {
            background: var(--green);
            border-color: var(--green);
            color: white;
            padding: 11px 20px;
            border-radius: 9px;
            font-weight: 600;
        }

        .btn-green:hover {
            background: var(--green-dark);
            border-color: var(--green-dark);
            color: white;
        }

        .info-box {
            background: #f8fafc;
            border-radius: 12px;
            padding: 18px;
        }

        .mobile-toggle {
            display: none;
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                transition: .3s;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .mobile-toggle {
                display: inline-block;
            }

            .content {
                padding: 20px;
            }
        }

    </style>

</head>

<body>

<?php include "../includes/customer_sidebar.php"; ?>

<div class="main">

    <div class="topbar d-flex align-items-center justify-content-between">

        <div>
            <button
                class="btn btn-outline-dark mobile-toggle"
                onclick="toggleSidebar()"
            >
                <i class="bi bi-list"></i>
            </button>

            <span class="ms-2 fw-semibold">
                My Profile
            </span>
        </div>

        <div class="text-muted small">
            <i class="bi bi-person-circle me-1"></i>
            <?= e($user['name']) ?>
        </div>

    </div>

    <div class="content">

        <div class="mb-4">

            <h2 class="page-title mb-1">
                My Profile
            </h2>

            <p class="text-muted mb-0">
                Manage your account information and security.
            </p>

        </div>

        <?php if ($success): ?>

            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?= e($success) ?>
            </div>

        <?php endif; ?>

        <?php if ($error): ?>

            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?= e($error) ?>
            </div>

        <?php endif; ?>


        <div class="row g-4">

            <!-- Profile -->
            <div class="col-lg-7">

                <div class="card">

                    <div class="card-header">

                        <h5 class="mb-1">
                            <i class="bi bi-person me-2 text-success"></i>
                            Personal Information
                        </h5>

                        <small class="text-muted">
                            Keep your contact information up to date.
                        </small>

                    </div>

                    <div class="card-body p-4">

                        <form method="POST">

                            <div class="row g-3">

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Full Name
                                    </label>

                                    <input
                                        type="text"
                                        name="name"
                                        class="form-control"
                                        value="<?= e($user['name']) ?>"
                                        required
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Email Address
                                    </label>

                                    <input
                                        type="email"
                                        name="email"
                                        class="form-control"
                                        value="<?= e($user['email']) ?>"
                                        required
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Phone Number
                                    </label>

                                    <input
                                        type="text"
                                        name="phone"
                                        class="form-control"
                                        value="<?= e($user['phone'] ?? '') ?>"
                                        placeholder="e.g. 024 XXX XXXX"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label">
                                        WhatsApp Number
                                    </label>

                                    <input
                                        type="text"
                                        name="whatsapp"
                                        class="form-control"
                                        value="<?= e($user['whatsapp'] ?? '') ?>"
                                        placeholder="Optional"
                                    >

                                </div>

                                <div class="col-12">

                                    <div class="info-box">

                                        <div class="small text-muted">
                                            Account created
                                        </div>

                                        <strong>
                                            <?= date(
                                                'd M Y',
                                                strtotime($user['created_at'])
                                            ) ?>
                                        </strong>

                                    </div>

                                </div>

                            </div>

                            <button
                                type="submit"
                                name="update_profile"
                                class="btn btn-green mt-4"
                            >
                                <i class="bi bi-check2-circle me-2"></i>
                                Save Changes
                            </button>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Password -->
            <div class="col-lg-5">

                <div class="card">

                    <div class="card-header">

                        <h5 class="mb-1">
                            <i class="bi bi-shield-lock me-2 text-success"></i>
                            Account Security
                        </h5>

                        <small class="text-muted">
                            Change your account password.
                        </small>

                    </div>

                    <div class="card-body p-4">

                        <form method="POST">

                            <div class="mb-3">

                                <label class="form-label">
                                    Current Password
                                </label>

                                <input
                                    type="password"
                                    name="current_password"
                                    class="form-control"
                                    required
                                >

                            </div>

                            <div class="mb-3">

                                <label class="form-label">
                                    New Password
                                </label>

                                <input
                                    type="password"
                                    name="new_password"
                                    class="form-control"
                                    minlength="8"
                                    required
                                >

                                <small class="text-muted">
                                    Minimum 8 characters.
                                </small>

                            </div>

                            <div class="mb-3">

                                <label class="form-label">
                                    Confirm New Password
                                </label>

                                <input
                                    type="password"
                                    name="confirm_password"
                                    class="form-control"
                                    minlength="8"
                                    required
                                >

                            </div>

                            <button
                                type="submit"
                                name="change_password"
                                class="btn btn-green w-100 mt-2"
                            >
                                <i class="bi bi-key me-2"></i>
                                Change Password
                            </button>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');

    if (sidebar) {
        sidebar.classList.toggle('show');
    }
}

</script>

</body>
</html>