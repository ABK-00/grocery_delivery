<?php

require_once __DIR__ . '/../config/db.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $companyId = (int)($_POST['company_id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $whatsapp  = trim($_POST['whatsapp'] ?? '');
    $password  = $_POST['password'] ?? '';

    if (
        $companyId <= 0 ||
        $name === '' ||
        $email === '' ||
        $password === ''
    ) {

        $message = 'Please fill all required fields.';
        $messageType = 'danger';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
        $messageType = 'danger';

    } elseif (strlen($password) < 8) {

        $message = 'Password must be at least 8 characters.';
        $messageType = 'danger';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | CHECK COMPANY
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id, company_name, status
                FROM companies
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$companyId]);

            $company = $stmt->fetch();

            if (!$company) {

                throw new Exception(
                    'Selected company does not exist.'
                );
            }


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

                throw new Exception(
                    'A user with this email already exists.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | HASH PASSWORD
            |--------------------------------------------------------------------------
            */

            $hashedPassword =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            /*
            |--------------------------------------------------------------------------
            | CREATE COMPANY ADMIN
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    company_id,
                    name,
                    email,
                    phone,
                    whatsapp,
                    password,
                    role,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'admin',
                    'active'
                )
            ");

            $stmt->execute([
                $companyId,
                $name,
                $email,
                $phone !== '' ? $phone : null,
                $whatsapp !== '' ? $whatsapp : null,
                $hashedPassword
            ]);


            $message =
                'Admin created successfully for '
                . $company['company_name']
                . '.';

            $messageType = 'success';

        } catch (Throwable $e) {

            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}


/*
|--------------------------------------------------------------------------
| LOAD COMPANIES
|--------------------------------------------------------------------------
*/

$stmt = $conn->query("
    SELECT
        id,
        company_code,
        company_name,
        status
    FROM companies
    ORDER BY company_name ASC
");

$companies = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Create Company Admin | GroceryDelivery
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

        body {
            min-height: 100vh;
            background: #f4f7f9;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }

        .setup-card {
            width: 100%;
            max-width: 650px;

            background: #fff;

            border-radius: 20px;

            box-shadow:
                0 15px 50px rgba(0,0,0,.08);

            overflow: hidden;
        }

        .setup-header {
            background: #071827;
            color: #fff;
            padding: 30px;
        }

        .setup-header i {
            color: #20c997;
        }

        .setup-body {
            padding: 30px;
        }

        .form-control,
        .form-select {
            min-height: 46px;
            border-radius: 10px;
        }

        .btn-create {
            min-height: 48px;
            border-radius: 10px;

            background: #198754;
            color: #fff;

            font-weight: 600;
        }

        .btn-create:hover {
            background: #146c43;
            color: #fff;
        }

    </style>

</head>

<body>

<div class="setup-card">

    <div class="setup-header">

        <h3 class="mb-2">

            <i class="bi bi-person-plus-fill me-2"></i>

            Create Company Admin

        </h3>

        <p class="mb-0 opacity-75">

            Create an administrator for a
            GroceryDelivery company.

        </p>

    </div>


    <div class="setup-body">

        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?= htmlspecialchars($messageType) ?>"
            >

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <form method="POST">


            <!-- COMPANY -->

            <div class="mb-3">

                <label class="form-label">
                    Company *
                </label>

                <select
                    name="company_id"
                    class="form-select"
                    required
                >

                    <option value="">
                        Select company
                    </option>

                    <?php foreach ($companies as $company): ?>

                        <option
                            value="<?= (int)$company['id'] ?>"
                        >

                            <?= htmlspecialchars(
                                $company['company_name']
                            ) ?>

                            (<?= htmlspecialchars(
                                $company['company_code']
                            ) ?>)

                            - <?= ucfirst(
                                htmlspecialchars(
                                    $company['status']
                                )
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- NAME -->

            <div class="mb-3">

                <label class="form-label">
                    Admin Name *
                </label>

                <input
                    type="text"
                    name="name"
                    class="form-control"
                    placeholder="Full name"
                    required
                >

            </div>


            <!-- EMAIL -->

            <div class="mb-3">

                <label class="form-label">
                    Email *
                </label>

                <input
                    type="email"
                    name="email"
                    class="form-control"
                    placeholder="admin@company.com"
                    required
                >

            </div>


            <div class="row">


                <!-- PHONE -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        Phone
                    </label>

                    <input
                        type="text"
                        name="phone"
                        class="form-control"
                        placeholder="+233..."
                    >

                </div>


                <!-- WHATSAPP -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">
                        WhatsApp
                    </label>

                    <input
                        type="text"
                        name="whatsapp"
                        class="form-control"
                        placeholder="+233..."
                    >

                </div>

            </div>


            <!-- PASSWORD -->

            <div class="mb-4">

                <label class="form-label">
                    Password *
                </label>

                <input
                    type="password"
                    name="password"
                    class="form-control"
                    minlength="8"
                    required
                >

                <div class="form-text">
                    Minimum 8 characters.
                </div>

            </div>


            <button
                type="submit"
                class="btn btn-create w-100"
            >

                <i class="bi bi-person-check me-2"></i>

                Create Company Admin

            </button>

        </form>

    </div>

</div>

</body>
</html>