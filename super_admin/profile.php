<?php

require_once '../config/db.php';
require_once '../includes/auth.php';

requireRole('super_admin');

$userId = currentUserId();

$msg = '';
$err = '';


/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_profile'
) {

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');


    if ($name === '') {

        $err = 'Name is required.';
    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $err = 'Enter a valid email address.';
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
                  AND id != ?
                LIMIT 1
            ");

            $stmt->execute([
                $email,
                $userId
            ]);

            if ($stmt->fetch()) {

                throw new RuntimeException(
                    'That email address is already being used.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE
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
                  AND role = 'super_admin'
            ");

            $stmt->execute([
                $name,
                $email,
                $phone !== '' ? $phone : null,
                $whatsapp !== '' ? $whatsapp : null,
                $userId
            ]);


            /*
             * Update session values.
             */
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;


            header(
                'Location: profile.php?updated=1'
            );

            exit;
        } catch (Throwable $e) {

            $err =
                'Could not update profile: '
                . $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| CHANGE PASSWORD
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'change_password'
) {

    $currentPassword =
        $_POST['current_password'] ?? '';

    $newPassword =
        $_POST['new_password'] ?? '';

    $confirmPassword =
        $_POST['confirm_password'] ?? '';


    if (
        $currentPassword === ''
        || $newPassword === ''
        || $confirmPassword === ''
    ) {

        $err = 'Complete all password fields.';
    } elseif (
        strlen($newPassword) < 8
    ) {

        $err =
            'New password must contain at least 8 characters.';
    } elseif (
        $newPassword !== $confirmPassword
    ) {

        $err =
            'New passwords do not match.';
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | CURRENT PASSWORD
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT password
                FROM users
                WHERE id = ?
                  AND role = 'super_admin'
                LIMIT 1
            ");

            $stmt->execute([
                $userId
            ]);

            $account =
                $stmt->fetch();


            if (!$account) {

                throw new RuntimeException(
                    'Super Admin account not found.'
                );
            }


            if (
                !password_verify(
                    $currentPassword,
                    $account['password']
                )
            ) {

                throw new RuntimeException(
                    'Current password is incorrect.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | DON'T REUSE CURRENT PASSWORD
            |--------------------------------------------------------------------------
            */

            if (
                password_verify(
                    $newPassword,
                    $account['password']
                )
            ) {

                throw new RuntimeException(
                    'New password must be different from your current password.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE PASSWORD
            |--------------------------------------------------------------------------
            */

            $hashedPassword =
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );


            $stmt = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
                  AND role = 'super_admin'
            ");

            $stmt->execute([
                $hashedPassword,
                $userId
            ]);


            /*
             * Refresh session ID after security change.
             */
            session_regenerate_id(true);


            header(
                'Location: profile.php?password_changed=1'
            );

            exit;
        } catch (Throwable $e) {

            $err =
                'Could not change password: '
                . $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| MESSAGES
|--------------------------------------------------------------------------
*/

if (
    ($_GET['updated'] ?? '') === '1'
) {

    $msg =
        'Profile updated successfully.';
}


if (
    ($_GET['password_changed'] ?? '') === '1'
) {

    $msg =
        'Password changed successfully.';
}


/*
|--------------------------------------------------------------------------
| LOAD PROFILE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        company_id,
        name,
        email,
        phone,
        whatsapp,
        role,
        status,
        created_at,
        updated_at

    FROM users

    WHERE id = ?
      AND role = 'super_admin'

    LIMIT 1
");

$stmt->execute([
    $userId
]);

$profile =
    $stmt->fetch();


if (!$profile) {

    session_unset();
    session_destroy();

    header(
        'Location: /somame_ent/super_admin/login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| INITIALS
|--------------------------------------------------------------------------
*/

$initials = '';

$nameParts =
    preg_split(
        '/\s+/',
        trim($profile['name'])
    );


foreach (
    array_slice(
        $nameParts,
        0,
        2
    )
    as $part
) {

    if ($part !== '') {

        $initials .=
            strtoupper(
                mb_substr(
                    $part,
                    0,
                    1
                )
            );
    }
}


if ($initials === '') {
    $initials = 'SA';
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
        My Profile | Super Admin
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">


    <link
        href="../assets/css/super_admin.css"
        rel="stylesheet">


    <style>
        .profile-header {

            background:
                linear-gradient(135deg,
                    #101828,
                    #182230);

            border-radius: 22px;

            color: #fff;

            padding: 30px;

            position: relative;

            overflow: hidden;

        }


        .profile-header::after {

            content: "";

            position: absolute;

            width: 220px;
            height: 220px;

            border-radius: 50%;

            background:
                rgba(25,
                    135,
                    84,
                    .14);

            right: -70px;
            top: -90px;

        }


        .profile-avatar {

            width: 88px;
            height: 88px;

            border-radius: 24px;

            display: flex;
            justify-content: center;
            align-items: center;

            background: #198754;

            color: #fff;

            font-size: 1.8rem;

            font-weight: 800;

            border:
                4px solid rgba(255,
                    255,
                    255,
                    .15);

            position: relative;

            z-index: 2;

        }


        .profile-header-content {

            position: relative;

            z-index: 2;

        }


        .profile-role {

            display: inline-flex;

            align-items: center;

            gap: 6px;

            padding:
                6px 10px;

            border-radius: 999px;

            background:
                rgba(25,
                    135,
                    84,
                    .25);

            color: #9ee7bb;

            font-size: .78rem;

            font-weight: 700;

        }


        .profile-card {

            background:
                var(--bs-body-bg,
                    #fff);

            border:
                1px solid rgba(0,
                    0,
                    0,
                    .07);

            border-radius: 20px;

            padding: 25px;

            height: 100%;

            box-shadow:
                0 8px 25px rgba(0,
                    0,
                    0,
                    .04);

        }


        .section-icon {

            width: 44px;
            height: 44px;

            border-radius: 13px;

            display: flex;
            justify-content: center;
            align-items: center;

            background:
                #eaf7ef;

            color: #198754;

            font-size: 1.15rem;

            flex-shrink: 0;

        }


        .detail-row {

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            gap: 15px;

            padding:
                13px 0;

            border-bottom:
                1px solid rgba(0,
                    0,
                    0,
                    .06);

        }


        .detail-row:last-child {

            border-bottom: 0;

        }


        .detail-label {

            color: #667085;

            font-size: .9rem;

        }


        .status-dot {

            width: 8px;
            height: 8px;

            border-radius: 50%;

            background: #198754;

            display: inline-block;

            margin-right: 5px;

        }


        .form-control {

            min-height: 47px;

            border-radius: 12px;

        }


        .form-control:focus {

            border-color: #198754;

            box-shadow:
                0 0 0 .2rem rgba(25,
                    135,
                    84,
                    .11);

        }


        .password-field {

            position: relative;

        }


        .password-field input {

            padding-right: 48px;

        }


        .password-toggle {

            position: absolute;

            right: 5px;
            bottom: 5px;

            width: 37px;
            height: 37px;

            border: 0;

            border-radius: 9px;

            background: transparent;

            color: #667085;

        }


        @media(max-width: 767px) {

            .profile-header {

                padding: 22px;

            }


            .profile-card {

                padding: 20px;

            }

        }
    </style>

</head>

<body>


    <?php

    if (
        file_exists(
            '../includes/loader.php'
        )
    ) {

        include
            '../includes/loader.php';
    }


    if (
        file_exists(
            '../includes/super_admin_sidebar.php'
        )
    ) {

        include
            '../includes/super_admin_sidebar.php';
    }

    ?>


    <main class="main-content">


        <!-- =====================================================
         PAGE TITLE
    ====================================================== -->

        <div class="mb-4">

            <h2 class="fw-bold mb-1">

                My Profile

            </h2>


            <p class="text-muted mb-0">

                Manage your platform administrator
                account.

            </p>

        </div>


        <!-- =====================================================
         MESSAGES
    ====================================================== -->

        <?php if ($msg): ?>

            <div
                class="alert alert-success alert-dismissible fade show">

                <i
                    class="bi bi-check-circle me-2"></i>

                <?= htmlspecialchars($msg) ?>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>

            </div>

        <?php endif; ?>


        <?php if ($err): ?>

            <div
                class="alert alert-danger alert-dismissible fade show">

                <i
                    class="bi bi-exclamation-circle me-2"></i>

                <?= htmlspecialchars($err) ?>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>

            </div>

        <?php endif; ?>


        <!-- =====================================================
         PROFILE HEADER
    ====================================================== -->

        <section class="profile-header mb-4">

            <div
                class="profile-header-content d-flex flex-column flex-md-row align-items-md-center gap-4">


                <div class="profile-avatar">

                    <?= htmlspecialchars(
                        $initials
                    ) ?>

                </div>


                <div class="flex-grow-1">


                    <div class="profile-role mb-2">

                        <i
                            class="bi bi-shield-lock-fill"></i>

                        PLATFORM SUPER ADMIN

                    </div>


                    <h2 class="fw-bold mb-1">

                        <?= htmlspecialchars(
                            $profile['name']
                        ) ?>

                    </h2>


                    <p
                        class="mb-0"
                        style="
                        color:
                        rgba(
                            255,
                            255,
                            255,
                            .65
                        );
                    ">

                        <?= htmlspecialchars(
                            $profile['email']
                        ) ?>

                    </p>

                </div>


                <div>

                    <span
                        class="badge rounded-pill text-bg-success px-3 py-2">

                        <span
                            class="status-dot"
                            style="
                            background:
                            white;
                        "></span>

                        Active

                    </span>

                </div>


            </div>

        </section>


        <div class="row g-4">


            <!-- =================================================
             PROFILE FORM
        ================================================== -->

            <div class="col-xl-7">


                <div class="profile-card">


                    <div
                        class="d-flex align-items-center gap-3 mb-4">

                        <div class="section-icon">

                            <i
                                class="bi bi-person"></i>

                        </div>


                        <div>

                            <h5 class="fw-bold mb-1">

                                Personal Information

                            </h5>

                            <small
                                class="text-muted">

                                Update your Super Admin details.

                            </small>

                        </div>

                    </div>


                    <form method="POST">


                        <input
                            type="hidden"
                            name="action"
                            value="update_profile">


                        <div class="row g-3">


                            <div class="col-md-6">

                                <label class="form-label">

                                    Full Name *

                                </label>


                                <input
                                    type="text"
                                    name="name"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $profile['name']
                                            ) ?>"
                                    required>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Email Address *

                                </label>


                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $profile['email']
                                            ) ?>"
                                    required>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Phone

                                </label>


                                <input
                                    type="text"
                                    name="phone"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $profile['phone']
                                                    ?? ''
                                            ) ?>"
                                    placeholder="Phone number">

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    WhatsApp

                                </label>


                                <input
                                    type="text"
                                    name="whatsapp"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                                $profile['whatsapp']
                                                    ?? ''
                                            ) ?>"
                                    placeholder="WhatsApp number">

                            </div>


                            <div class="col-12">

                                <button
                                    type="submit"
                                    class="btn btn-success px-4">

                                    <i
                                        class="bi bi-floppy me-1"></i>

                                    Save Changes

                                </button>

                            </div>


                        </div>

                    </form>

                </div>

            </div>


            <!-- =================================================
             ACCOUNT SUMMARY
        ================================================== -->

            <div class="col-xl-5">


                <div class="profile-card">


                    <div
                        class="d-flex align-items-center gap-3 mb-3">

                        <div class="section-icon">

                            <i
                                class="bi bi-shield-check"></i>

                        </div>


                        <div>

                            <h5 class="fw-bold mb-0">

                                Account

                            </h5>

                        </div>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Role
                        </span>

                        <strong>
                            Super Admin
                        </strong>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Company
                        </span>

                        <strong>
                            Platform
                        </strong>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Status
                        </span>

                        <span
                            class="badge text-bg-success">
                            Active
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Account Created
                        </span>

                        <strong>

                            <?= date(
                                'M j, Y',
                                strtotime(
                                    $profile['created_at']
                                )
                            ) ?>

                        </strong>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Account ID
                        </span>

                        <strong>

                            #<?= (int)$profile['id'] ?>

                        </strong>

                    </div>


                </div>

            </div>


            <!-- =================================================
             PASSWORD
        ================================================== -->

            <div class="col-xl-7">


                <div class="profile-card">


                    <div
                        class="d-flex align-items-center gap-3 mb-4">

                        <div class="section-icon">

                            <i
                                class="bi bi-key"></i>

                        </div>


                        <div>

                            <h5 class="fw-bold mb-1">

                                Change Password

                            </h5>

                            <small class="text-muted">

                                Use a strong password for
                                your platform account.

                            </small>

                        </div>

                    </div>


                    <form method="POST">


                        <input
                            type="hidden"
                            name="action"
                            value="change_password">


                        <!-- CURRENT -->

                        <div
                            class="mb-3 password-field">

                            <label class="form-label">

                                Current Password

                            </label>


                            <input
                                type="password"
                                name="current_password"
                                id="currentPassword"
                                class="form-control"
                                required
                                autocomplete="current-password">


                            <button
                                type="button"
                                class="password-toggle"
                                data-password-target="currentPassword">

                                <i
                                    class="bi bi-eye"></i>

                            </button>

                        </div>


                        <div class="row g-3">


                            <!-- NEW -->

                            <div class="col-md-6">

                                <div class="password-field">

                                    <label class="form-label">

                                        New Password

                                    </label>


                                    <input
                                        type="password"
                                        name="new_password"
                                        id="newPassword"
                                        class="form-control"
                                        minlength="8"
                                        required
                                        autocomplete="new-password">


                                    <button
                                        type="button"
                                        class="password-toggle"
                                        data-password-target="newPassword">

                                        <i
                                            class="bi bi-eye"></i>

                                    </button>

                                </div>

                            </div>


                            <!-- CONFIRM -->

                            <div class="col-md-6">

                                <div class="password-field">

                                    <label class="form-label">

                                        Confirm Password

                                    </label>


                                    <input
                                        type="password"
                                        name="confirm_password"
                                        id="confirmPassword"
                                        class="form-control"
                                        minlength="8"
                                        required
                                        autocomplete="new-password">


                                    <button
                                        type="button"
                                        class="password-toggle"
                                        data-password-target="confirmPassword">

                                        <i
                                            class="bi bi-eye"></i>

                                    </button>

                                </div>

                            </div>


                        </div>


                        <button
                            type="submit"
                            class="btn btn-dark mt-4 px-4">

                            <i
                                class="bi bi-shield-lock me-1"></i>

                            Update Password

                        </button>


                    </form>


                </div>

            </div>


            <!-- =================================================
             SECURITY
        ================================================== -->

            <div class="col-xl-5">


                <div class="profile-card">


                    <div
                        class="d-flex align-items-center gap-3 mb-4">

                        <div class="section-icon">

                            <i
                                class="bi bi-lock"></i>

                        </div>


                        <div>

                            <h5 class="fw-bold mb-1">

                                Security

                            </h5>

                        </div>

                    </div>


                    <div
                        class="alert alert-success">

                        <i
                            class="bi bi-shield-check me-2"></i>

                        Your account has platform-level
                        administrator access.

                    </div>


                    <p
                        class="small text-muted mb-0">

                        Super Admin accounts have access
                        to companies, subscriptions,
                        payments and platform-wide
                        management features.

                    </p>


                </div>

            </div>


        </div>

    </main>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


    <script>
        document.querySelectorAll(
            '[data-password-target]'
        ).forEach(function(button) {

            button.addEventListener(
                'click',
                function() {

                    const target =
                        document.getElementById(
                            button.getAttribute(
                                'data-password-target'
                            )
                        );


                    if (!target) {
                        return;
                    }


                    const icon =
                        button.querySelector('i');


                    if (
                        target.type ===
                        'password'
                    ) {

                        target.type =
                            'text';

                        icon.className =
                            'bi bi-eye-slash';

                    } else {

                        target.type =
                            'password';

                        icon.className =
                            'bi bi-eye';

                    }

                }
            );

        });
    </script>


</body>

</html>