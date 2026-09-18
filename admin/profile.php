<?php

require_once "../config/db.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireRole('admin');
requireCompanyAccess();
$companyId = currentCompanyId();

$adminId = (int) $_SESSION['user_id'];

$success = '';
$error   = '';

/*
|--------------------------------------------------------------------------
| LOAD ADMIN
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT id, name, email, phone, whatsapp, role, status, created_at
    FROM users
    WHERE id = ?
      AND role = 'admin'
    LIMIT 1
");

$stmt->execute([$adminId]);
$admin = $stmt->fetch();

if (!$admin) {
    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'update_profile'
) {

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');

    if ($name === '') {

        $error = "Your name is required.";

    } elseif ($email === '') {

        $error = "Your email address is required.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE EMAIL
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                  AND id != ?
                LIMIT 1
            ");

            $stmt->execute([$email, $adminId]);

            if ($stmt->fetch()) {

                $error = "That email address is already being used by another account.";

            } else {

                /*
                |--------------------------------------------------------------------------
                | UPDATE ADMIN PROFILE
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        email = ?,
                        phone = ?,
                        whatsapp = ?
                    WHERE id = ?
                      AND role = 'admin'
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone !== '' ? $phone : null,
                    $whatsapp !== '' ? $whatsapp : null,
                    $adminId
                ]);

                /*
                |--------------------------------------------------------------------------
                | UPDATE SESSION
                |--------------------------------------------------------------------------
                */

                $_SESSION['user_name'] = $name;

                /*
                |--------------------------------------------------------------------------
                | RELOAD ADMIN
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT
                        id,
                        name,
                        email,
                        phone,
                        whatsapp,
                        role,
                        status,
                        created_at
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$adminId]);

                $admin = $stmt->fetch();

                $success = "Profile updated successfully.";
            }

        } catch (PDOException $e) {

            $error = "Unable to update your profile. Please try again.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| CHANGE PASSWORD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'change_password'
) {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        $currentPassword === ''
        || $newPassword === ''
        || $confirmPassword === ''
    ) {

        $error = "Please complete all password fields.";

    } elseif (strlen($newPassword) < 8) {

        $error = "Your new password must contain at least 8 characters.";

    } elseif ($newPassword !== $confirmPassword) {

        $error = "The new passwords do not match.";

    } elseif ($currentPassword === $newPassword) {

        $error = "Your new password must be different from your current password.";

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | GET CURRENT PASSWORD HASH
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT password
                FROM users
                WHERE id = ?
                  AND role = 'admin'
                LIMIT 1
            ");

            $stmt->execute([$adminId]);

            $account = $stmt->fetch();

            if (!$account) {

                $error = "Administrator account could not be found.";

            } elseif (!password_verify(
                $currentPassword,
                $account['password']
            )) {

                $error = "Your current password is incorrect.";

            } else {

                /*
                |--------------------------------------------------------------------------
                | HASH NEW PASSWORD
                |--------------------------------------------------------------------------
                */

                $newHash = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                /*
                |--------------------------------------------------------------------------
                | UPDATE PASSWORD
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                      AND role = 'admin'
                ");

                $stmt->execute([
                    $newHash,
                    $adminId
                ]);

                $success = "Password changed successfully.";
            }

        } catch (PDOException $e) {

            $error = "Unable to change your password. Please try again.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| PROFILE INITIALS
|--------------------------------------------------------------------------
*/

$nameParts = preg_split('/\s+/', trim($admin['name']));

$initials = '';

foreach ($nameParts as $part) {

    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    if (strlen($initials) >= 2) {
        break;
    }
}

if ($initials === '') {
    $initials = 'A';
}


/*
|--------------------------------------------------------------------------
| ACCOUNT AGE
|--------------------------------------------------------------------------
*/

$joinedDate = !empty($admin['created_at'])
    ? date('F j, Y', strtotime($admin['created_at']))
    : 'Unknown';

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>My Profile | GroceryDelivery Admin</title>


    <!-- BOOTSTRAP -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- BOOTSTRAP ICONS -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- SHARED ADMIN UI -->
    <link href="../assets/css/admin.css" rel="stylesheet">


    <style>

        :root {
            --navy: #071827;
            --navy-light: #0d2438;
            --green: #198754;
            --green-dark: #146c43;
            --green-soft: #eaf7f0;
            --page-bg: #f4f7f9;
            --text: #17212b;
            --muted: #6c757d;
            --border: #e8ecef;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page-bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }


        /*
        |--------------------------------------------------------------------------
        | MAIN CONTENT
        |--------------------------------------------------------------------------
        */

        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            padding: 30px;
        }


        /*
        |--------------------------------------------------------------------------
        | PAGE HEADER
        |--------------------------------------------------------------------------
        */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-header h2 {
            font-weight: 700;
            margin: 0;
        }

        .page-header p {
            margin: 6px 0 0;
            color: var(--muted);
        }


        /*
        |--------------------------------------------------------------------------
        | PROFILE HEADER
        |--------------------------------------------------------------------------
        */

        .profile-header {
            position: relative;
            overflow: hidden;
            background: linear-gradient(
                135deg,
                var(--navy),
                var(--navy-light)
            );
            border-radius: 20px;
            padding: 32px;
            color: #fff;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(7, 24, 39, .15);
        }

        .profile-header::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            right: -70px;
            top: -90px;
            background: rgba(25, 135, 84, .22);
        }

        .profile-header::before {
            content: "";
            position: absolute;
            width: 150px;
            height: 150px;
            border-radius: 50%;
            right: 100px;
            bottom: -110px;
            background: rgba(255, 255, 255, .05);
        }

        .profile-header-content {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .profile-avatar {
            width: 88px;
            height: 88px;
            min-width: 88px;
            border-radius: 50%;
            background: var(--green);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: 700;
            border: 4px solid rgba(255, 255, 255, .2);
            box-shadow: 0 8px 22px rgba(0, 0, 0, .2);
        }

        .profile-heading h3 {
            margin: 0 0 5px;
            font-weight: 700;
        }

        .profile-heading p {
            color: rgba(255,255,255,.72);
            margin: 0 0 10px;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 11px;
            background: rgba(25, 135, 84, .22);
            border: 1px solid rgba(32, 201, 151, .4);
            color: #7ce7b2;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
        }


        /*
        |--------------------------------------------------------------------------
        | CARDS
        |--------------------------------------------------------------------------
        */

        .profile-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 5px 22px rgba(0, 0, 0, .045);
            height: 100%;
        }

        .profile-card-header {
            padding: 22px 24px 17px;
            border-bottom: 1px solid var(--border);
        }

        .profile-card-header h5 {
            font-weight: 700;
            margin: 0 0 4px;
        }

        .profile-card-header p {
            color: var(--muted);
            margin: 0;
            font-size: 14px;
        }

        .profile-card-body {
            padding: 24px;
        }


        /*
        |--------------------------------------------------------------------------
        | FORMS
        |--------------------------------------------------------------------------
        */

        .form-label {
            font-weight: 600;
            font-size: 14px;
            color: #34404b;
        }

        .form-control {
            min-height: 47px;
            border-radius: 10px;
            border-color: #dde3e7;
        }

        .form-control:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 .2rem rgba(25,135,84,.12);
        }

        .input-group .form-control {
            border-radius: 10px 0 0 10px;
        }

        .input-group .password-toggle {
            border-radius: 0 10px 10px 0;
        }

        .btn-success {
            background: var(--green);
            border-color: var(--green);
        }

        .btn-success:hover {
            background: var(--green-dark);
            border-color: var(--green-dark);
        }


        /*
        |--------------------------------------------------------------------------
        | ACCOUNT INFO
        |--------------------------------------------------------------------------
        */

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 15px 0;
            border-bottom: 1px solid var(--border);
        }

        .info-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .info-row:first-child {
            padding-top: 0;
        }

        .info-label {
            color: var(--muted);
            font-size: 14px;
        }

        .info-value {
            text-align: right;
            font-weight: 600;
            word-break: break-word;
        }

        .account-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--green);
            background: var(--green-soft);
            border-radius: 50px;
            padding: 5px 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .security-note {
            background: #f7f9fa;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 15px;
            color: #59636c;
            font-size: 14px;
        }

        .security-note i {
            color: var(--green);
        }


        /*
        |--------------------------------------------------------------------------
        | ALERTS
        |--------------------------------------------------------------------------
        */

        .alert {
            border: 0;
            border-radius: 12px;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        @media (max-width: 575.98px) {

            .main-content {
                padding: 15px;
            }

            .profile-header {
                padding: 25px 20px;
            }

            .profile-header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .profile-avatar {
                width: 75px;
                height: 75px;
                min-width: 75px;
                font-size: 25px;
            }

            .profile-card-header,
            .profile-card-body {
                padding-left: 18px;
                padding-right: 18px;
            }

            .info-row {
                flex-direction: column;
                gap: 4px;
            }

            .info-value {
                text-align: left;
            }
        }

    </style>

</head>

<body>


<?php include "../includes/admin_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>


<main class="main-content">


    <!-- PAGE HEADER -->

    <div class="page-header">

        <div>

            <h2>
                <i class="bi bi-person-circle me-2"></i>
                My Profile
            </h2>

            <p>
                Manage your administrator account and security settings.
            </p>

        </div>

    </div>


    <!-- SUCCESS MESSAGE -->

    <?php if ($success !== ''): ?>

        <div
            class="alert alert-success alert-dismissible fade show"
            role="alert"
        >

            <i class="bi bi-check-circle-fill me-2"></i>

            <?= htmlspecialchars($success) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- ERROR MESSAGE -->

    <?php if ($error !== ''): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
            role="alert"
        >

            <i class="bi bi-exclamation-circle-fill me-2"></i>

            <?= htmlspecialchars($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- PROFILE BANNER -->

    <section class="profile-header">

        <div class="profile-header-content">

            <div class="profile-avatar">
                <?= htmlspecialchars($initials) ?>
            </div>

            <div class="profile-heading">

                <h3>
                    <?= htmlspecialchars($admin['name']) ?>
                </h3>

                <p>
                    <?= htmlspecialchars($admin['email']) ?>
                </p>

                <span class="admin-badge">

                    <i class="bi bi-shield-check"></i>

                    Administrator

                </span>

            </div>

        </div>

    </section>


    <div class="row g-4">


        <!-- PROFILE INFORMATION -->

        <div class="col-xl-8">

            <section class="profile-card">

                <div class="profile-card-header">

                    <h5>
                        <i class="bi bi-person me-2 text-success"></i>
                        Personal Information
                    </h5>

                    <p>
                        Update your administrator profile information.
                    </p>

                </div>


                <div class="profile-card-body">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="update_profile"
                        >


                        <div class="row g-3">


                            <!-- NAME -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    Full Name
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    class="form-control"
                                    value="<?= htmlspecialchars($admin['name']) ?>"
                                    maxlength="100"
                                    required
                                >

                            </div>


                            <!-- EMAIL -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    Email Address
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars($admin['email']) ?>"
                                    maxlength="150"
                                    required
                                >

                            </div>


                            <!-- PHONE -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    Phone Number
                                </label>

                                <input
                                    type="tel"
                                    name="phone"
                                    class="form-control"
                                    value="<?= htmlspecialchars($admin['phone'] ?? '') ?>"
                                    placeholder="e.g. 0241234567"
                                    maxlength="30"
                                >

                            </div>


                            <!-- WHATSAPP -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    WhatsApp Number
                                </label>

                                <input
                                    type="tel"
                                    name="whatsapp"
                                    class="form-control"
                                    value="<?= htmlspecialchars($admin['whatsapp'] ?? '') ?>"
                                    placeholder="e.g. 0241234567"
                                    maxlength="30"
                                >

                            </div>


                            <div class="col-12 mt-4">

                                <button
                                    type="submit"
                                    class="btn btn-success px-4"
                                >

                                    <i class="bi bi-check-lg me-1"></i>

                                    Save Changes

                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </section>

        </div>


        <!-- ACCOUNT DETAILS -->

        <div class="col-xl-4">

            <section class="profile-card">

                <div class="profile-card-header">

                    <h5>
                        <i class="bi bi-shield-check me-2 text-success"></i>
                        Account Details
                    </h5>

                    <p>
                        Administrator account information.
                    </p>

                </div>


                <div class="profile-card-body">

                    <div class="info-row">

                        <div class="info-label">
                            Account ID
                        </div>

                        <div class="info-value">
                            #<?= (int)$admin['id'] ?>
                        </div>

                    </div>


                    <div class="info-row">

                        <div class="info-label">
                            Role
                        </div>

                        <div class="info-value">
                            Administrator
                        </div>

                    </div>


                    <div class="info-row">

                        <div class="info-label">
                            Account Status
                        </div>

                        <div class="info-value">

                            <?php if ($admin['status'] === 'active'): ?>

                                <span class="account-status">

                                    <i class="bi bi-check-circle-fill"></i>

                                    Active

                                </span>

                            <?php else: ?>

                                <span class="badge bg-danger">

                                    <?= htmlspecialchars(
                                        ucfirst($admin['status'])
                                    ) ?>

                                </span>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="info-row">

                        <div class="info-label">
                            Member Since
                        </div>

                        <div class="info-value">
                            <?= htmlspecialchars($joinedDate) ?>
                        </div>

                    </div>

                </div>

            </section>

        </div>


        <!-- CHANGE PASSWORD -->

        <div class="col-xl-8">

            <section class="profile-card">

                <div class="profile-card-header">

                    <h5>
                        <i class="bi bi-lock me-2 text-success"></i>
                        Change Password
                    </h5>

                    <p>
                        Update the password used to access your administrator account.
                    </p>

                </div>


                <div class="profile-card-body">

                    <form
                        method="POST"
                        id="passwordForm"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="change_password"
                        >


                        <!-- CURRENT PASSWORD -->

                        <div class="mb-3">

                            <label class="form-label">
                                Current Password
                            </label>

                            <div class="input-group">

                                <input
                                    type="password"
                                    name="current_password"
                                    id="currentPassword"
                                    class="form-control"
                                    autocomplete="current-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="btn btn-outline-secondary password-toggle"
                                    onclick="togglePassword(
                                        'currentPassword',
                                        this
                                    )"
                                >

                                    <i class="bi bi-eye"></i>

                                </button>

                            </div>

                        </div>


                        <div class="row g-3">


                            <!-- NEW PASSWORD -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    New Password
                                </label>

                                <div class="input-group">

                                    <input
                                        type="password"
                                        name="new_password"
                                        id="newPassword"
                                        class="form-control"
                                        minlength="8"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary password-toggle"
                                        onclick="togglePassword(
                                            'newPassword',
                                            this
                                        )"
                                    >

                                        <i class="bi bi-eye"></i>

                                    </button>

                                </div>

                                <small class="text-muted">
                                    Minimum 8 characters.
                                </small>

                            </div>


                            <!-- CONFIRM PASSWORD -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    Confirm New Password
                                </label>

                                <div class="input-group">

                                    <input
                                        type="password"
                                        name="confirm_password"
                                        id="confirmPassword"
                                        class="form-control"
                                        minlength="8"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary password-toggle"
                                        onclick="togglePassword(
                                            'confirmPassword',
                                            this
                                        )"
                                    >

                                        <i class="bi bi-eye"></i>

                                    </button>

                                </div>

                            </div>

                        </div>


                        <div class="security-note mt-4">

                            <i class="bi bi-info-circle-fill me-1"></i>

                            Use a strong password that you do not use
                            for another account.

                        </div>


                        <div class="mt-4">

                            <button
                                type="submit"
                                class="btn btn-success px-4"
                            >

                                <i class="bi bi-key me-1"></i>

                                Change Password

                            </button>

                        </div>

                    </form>

                </div>

            </section>

        </div>


        <!-- SECURITY -->

        <div class="col-xl-4">

            <section class="profile-card">

                <div class="profile-card-header">

                    <h5>
                        <i class="bi bi-shield-lock me-2 text-success"></i>
                        Security
                    </h5>

                    <p>
                        Basic security information.
                    </p>

                </div>


                <div class="profile-card-body">

                    <div class="d-flex align-items-start gap-3 mb-4">

                        <div class="stat-icon">

                            <i class="bi bi-lock-fill"></i>

                        </div>

                        <div>

                            <div class="fw-semibold">
                                Password Protected
                            </div>

                            <small class="text-muted">
                                Your password is stored securely using
                                PHP password hashing.
                            </small>

                        </div>

                    </div>


                    <div class="d-flex align-items-start gap-3 mb-4">

                        <div class="stat-icon">

                            <i class="bi bi-person-check-fill"></i>

                        </div>

                        <div>

                            <div class="fw-semibold">
                                Role Protected
                            </div>

                            <small class="text-muted">
                                This page can only be accessed by an
                                authenticated administrator.
                            </small>

                        </div>

                    </div>


                    <hr>


                    <a
                        href="../logout.php"
                        class="btn btn-outline-danger w-100"
                    >

                        <i class="bi bi-box-arrow-right me-1"></i>

                        Sign Out

                    </a>

                </div>

            </section>

        </div>


    </div>

</main>


<script>

/*
|--------------------------------------------------------------------------
| PASSWORD VISIBILITY
|--------------------------------------------------------------------------
*/

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);
    const icon = button.querySelector('i');

    if (input.type === 'password') {

        input.type = 'text';

        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');

    } else {

        input.type = 'password';

        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}


/*
|--------------------------------------------------------------------------
| PASSWORD MATCH CHECK
|--------------------------------------------------------------------------
*/

document
    .getElementById('passwordForm')
    .addEventListener('submit', function(event) {

        const newPassword =
            document.getElementById('newPassword').value;

        const confirmPassword =
            document.getElementById('confirmPassword').value;

        if (newPassword !== confirmPassword) {

            event.preventDefault();

            alert('The new passwords do not match.');

            document
                .getElementById('confirmPassword')
                .focus();
        }

    });

</script>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

</body>

</html>