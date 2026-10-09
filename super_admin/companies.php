<?php

require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/storefront.php';

requireRole('super_admin');

$msg = '';
$err = '';


/*
|--------------------------------------------------------------------------
| DELETE COMPANY
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete'
) {

    $companyId = (int)($_POST['company_id'] ?? 0);

    if ($companyId <= 0) {

        $err = 'Invalid company selected.';
    } else {

        try {

            $conn->beginTransaction();

            /*
             * Confirm that the company exists before deleting it.
             */
            $stmt = $conn->prepare("
                SELECT id, company_name
                FROM companies
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$companyId]);

            $company = $stmt->fetch();

            if (!$company) {
                throw new Exception(
                    'The selected company no longer exists.'
                );
            }


            /*
             * Delete company.
             *
             * Related storefront/users/products/orders/etc.
             * are removed through ON DELETE CASCADE.
             */
            $stmt = $conn->prepare("
                DELETE FROM companies
                WHERE id = ?
            ");

            $stmt->execute([$companyId]);

            if ($stmt->rowCount() !== 1) {
                throw new Exception(
                    'The company could not be deleted.'
                );
            }

            $conn->commit();

            /*
             * Redirect after successful POST.
             * Prevents accidental re-submission when refreshing.
             */
            header(
                'Location: companies.php?deleted=1&name='
                    . urlencode($company['company_name'])
            );

            exit;
        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $err = 'Could not delete company: ' . $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| DELETE SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {

    $deletedName = trim($_GET['name'] ?? '');

    if ($deletedName !== '') {
        $msg = $deletedName . ' was deleted successfully.';
    } else {
        $msg = 'Company deleted successfully.';
    }
}


/*
|--------------------------------------------------------------------------
| CREATE COMPANY
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'create'
) {

    $name = trim($_POST['company_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $password = $_POST['password'] ?? '';


    /*
     * Validation
     */
    if (
        $name === ''
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $adminName === ''
        || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
        || strlen($password) < 8
    ) {

        $err =
            'Complete all required fields correctly. '
            . 'Password must be at least 8 characters.';
    } else {

        try {

            $conn->beginTransaction();


            /*
             * Check whether admin email already exists.
             */
            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$adminEmail]);

            if ($stmt->fetch()) {
                throw new Exception(
                    'The administrator email is already registered.'
                );
            }


            /*
             * Generate unique company code.
             */
            do {

                $code =
                    'GD-'
                    . strtoupper(
                        substr(
                            bin2hex(random_bytes(4)),
                            0,
                            8
                        )
                    );

                $stmt = $conn->prepare("
                    SELECT id
                    FROM companies
                    WHERE company_code = ?
                    LIMIT 1
                ");

                $stmt->execute([$code]);
            } while ($stmt->fetch());


            /*
             * Generate unique storefront slug.
             */
            $slug = uniqueStorefrontSlug(
                $conn,
                $name
            );


            /*
             * Create company.
             */
            $stmt = $conn->prepare("
                INSERT INTO companies (
                    company_code,
                    company_name,
                    storefront_slug,
                    email,
                    phone,
                    status,
                    subscription_plan,
                    trial_ends_at
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'active',
                    'trial',
                    DATE_ADD(NOW(), INTERVAL 30 DAY)
                )
            ");

            $stmt->execute([
                $code,
                $name,
                $slug,
                $email,
                $phone !== '' ? $phone : null
            ]);

            $companyId = (int)$conn->lastInsertId();


            /*
             * Create default storefront.
             */
            $stmt = $conn->prepare("
                INSERT INTO company_storefronts (
                    company_id,
                    display_name,
                    email,
                    phone,
                    whatsapp,
                    primary_color,
                    store_status
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    '#198754',
                    'open'
                )
            ");

            $stmt->execute([
                $companyId,
                $name,
                $email,
                $phone !== '' ? $phone : null,
                $phone !== '' ? $phone : null
            ]);


            /*
             * Create first company administrator.
             */
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

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
                $adminName,
                $adminEmail,
                $phone !== '' ? $phone : null,
                $phone !== '' ? $phone : null,
                $hashedPassword
            ]);


            $conn->commit();


            /*
             * Redirect after successful creation.
             */
            header(
                'Location: companies.php?created=1&store='
                    . urlencode($slug)
            );

            exit;
        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            /*
             * Detailed error while developing.
             * Replace with a generic message before production.
             */
            $err =
                'Could not create company: '
                . $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| CREATE SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

if (isset($_GET['created']) && $_GET['created'] === '1') {

    $createdSlug = trim($_GET['store'] ?? '');

    $msg = 'Company created successfully.';

    if ($createdSlug !== '') {

        $msg .=
            ' Storefront: '
            . '../store.php?store='
            . $createdSlug;
    }
}


/*
|--------------------------------------------------------------------------
| SEARCH / FILTER
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');

$sql = "
    SELECT
        c.*,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.company_id = c.id
        ) AS user_count

    FROM companies c

    WHERE 1 = 1
";

$params = [];


/*
 * Search
 */
if ($q !== '') {

    $sql .= "
        AND (
            c.company_name LIKE ?
            OR c.company_code LIKE ?
            OR c.email LIKE ?
        )
    ";

    $like = '%' . $q . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}


/*
 * Status filter
 */
$allowedStatuses = [
    'active',
    'suspended',
    'blocked'
];

if (in_array($status, $allowedStatuses, true)) {

    $sql .= "
        AND c.status = ?
    ";

    $params[] = $status;
}


$sql .= "
    ORDER BY c.created_at DESC
";


$stmt = $conn->prepare($sql);
$stmt->execute($params);

$companies = $stmt->fetchAll();

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        Companies | Super Admin
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

</head>

<body>

    <?php

    include '../includes/loader.php';
    include '../includes/super_admin_sidebar.php';

    ?>


    <main class="main-content">

        <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

        <div
            class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

            <div>

                <h2 class="fw-bold mb-1">
                    Companies
                </h2>

                <p class="text-muted mb-0">
                    Create and control businesses using the platform.
                </p>

            </div>


            <button
                type="button"
                class="btn btn-success"
                data-bs-toggle="modal"
                data-bs-target="#addCompany">

                <i class="bi bi-plus-lg me-1"></i>

                Add Company

            </button>

        </div>


        <!-- =====================================================
         MESSAGES
    ====================================================== -->

        <?php if ($msg): ?>

            <div
                class="alert alert-success alert-dismissible fade show"
                role="alert">

                <i class="bi bi-check-circle me-2"></i>

                <?= htmlspecialchars($msg) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>

            </div>

        <?php endif; ?>


        <?php if ($err): ?>

            <div
                class="alert alert-danger alert-dismissible fade show"
                role="alert">

                <i class="bi bi-exclamation-circle me-2"></i>

                <?= htmlspecialchars($err) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>

            </div>

        <?php endif; ?>


        <!-- =====================================================
         SEARCH / FILTER
    ====================================================== -->

        <div class="panel p-3 mb-4">

            <form
                method="GET"
                class="row g-2">

                <div class="col-md-7">

                    <input
                        type="text"
                        class="form-control"
                        name="q"
                        value="<?= htmlspecialchars($q) ?>"
                        placeholder="Search company, code or email">

                </div>


                <div class="col-md-3">

                    <select
                        class="form-select"
                        name="status">

                        <option value="">
                            All statuses
                        </option>

                        <?php foreach ($allowedStatuses as $value): ?>

                            <option
                                value="<?= $value ?>"
                                <?= $status === $value ? 'selected' : '' ?>>
                                <?= ucfirst($value) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-2">

                    <button
                        type="submit"
                        class="btn btn-dark w-100">

                        <i class="bi bi-search me-1"></i>

                        Filter

                    </button>

                </div>

            </form>

        </div>


        <!-- =====================================================
         COMPANIES TABLE
    ====================================================== -->

        <div class="panel p-3">

            <div class="table-responsive">

                <table class="table align-middle">

                    <thead>

                        <tr>

                            <th>Company</th>

                            <th>Code</th>

                            <th>Plan</th>

                            <th>Users</th>

                            <th>Access Until</th>

                            <th>Status</th>

                            <th class="text-end">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($companies as $company): ?>

                            <?php

                            $accessUntil =
                                $company['subscription_plan'] === 'trial'
                                ? $company['trial_ends_at']
                                : $company['subscription_ends_at'];

                            $badgeClass = match ($company['status']) {

                                'active' => 'success',

                                'suspended' => 'warning',

                                default => 'danger'
                            };

                            ?>

                            <tr>

                                <!-- Company -->

                                <td>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $company['company_name']
                                        ) ?>
                                    </strong>

                                    <small
                                        class="d-block text-muted">
                                        <?= htmlspecialchars(
                                            $company['email']
                                        ) ?>
                                    </small>

                                </td>


                                <!-- Code -->

                                <td>

                                    <span class="fw-semibold">

                                        <?= htmlspecialchars(
                                            $company['company_code']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Plan -->

                                <td>

                                    <?= ucfirst(
                                        $company['subscription_plan']
                                    ) ?>

                                </td>


                                <!-- Users -->

                                <td>

                                    <?= (int)$company['user_count'] ?>

                                </td>


                                <!-- Access -->

                                <td>

                                    <?php if ($accessUntil): ?>

                                        <?= date(
                                            'M j, Y',
                                            strtotime($accessUntil)
                                        ) ?>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </td>


                                <!-- Status -->

                                <td>

                                    <span
                                        class="badge text-bg-<?= $badgeClass ?>">

                                        <?= ucfirst(
                                            $company['status']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Actions -->

                                <td class="text-end">

                                    <div
                                        class="d-flex justify-content-end gap-2">

                                        <a
                                            href="../store.php?store=<?= urlencode(
                                                                            $company['storefront_slug']
                                                                        ) ?>"
                                            target="_blank"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Preview Storefront">

                                            <i class="bi bi-shop-window"></i>

                                        </a>


                                        <a
                                            href="company_details.php?id=<?= (int)$company['id'] ?>"
                                            class="btn btn-sm btn-outline-success">

                                            <i class="bi bi-gear me-1"></i>

                                            Manage

                                        </a>


                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger delete-company-btn"

                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteCompanyModal"

                                            data-company-id="<?= (int)$company['id'] ?>"

                                            data-company-name="<?= htmlspecialchars(
                                                                    $company['company_name'],
                                                                    ENT_QUOTES,
                                                                    'UTF-8'
                                                                ) ?>">

                                            <i class="bi bi-trash me-1"></i>

                                            Delete

                                        </button>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                        <?php if (!$companies): ?>

                            <tr>

                                <td
                                    colspan="7"
                                    class="text-center py-5">

                                    <div class="text-muted">

                                        <i
                                            class="bi bi-building fs-1 d-block mb-2"></i>

                                        No companies found.

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </main>


    <!-- =========================================================
     ADD COMPANY MODAL
========================================================= -->

    <div
        class="modal fade"
        id="addCompany"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-lg modal-dialog-centered">

            <form
                method="POST"
                class="modal-content">

                <input
                    type="hidden"
                    name="action"
                    value="create">


                <div class="modal-header">

                    <h5 class="modal-title">

                        <i class="bi bi-building-add me-2"></i>

                        Register Company

                    </h5>


                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"></button>

                </div>


                <div class="modal-body">

                    <div class="row g-3">


                        <!-- COMPANY DETAILS -->

                        <div class="col-12">

                            <h6 class="fw-bold">
                                Company Information
                            </h6>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Company Name *
                            </label>

                            <input
                                type="text"
                                name="company_name"
                                class="form-control"
                                required>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Company Email *
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                required>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Phone
                            </label>

                            <input
                                type="text"
                                name="phone"
                                class="form-control">

                        </div>


                        <!-- ADMIN DETAILS -->

                        <div class="col-12">

                            <hr>

                            <h6 class="fw-bold">
                                First Company Administrator
                            </h6>

                            <small class="text-muted">
                                This user will manage the company.
                            </small>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Admin Name *
                            </label>

                            <input
                                type="text"
                                name="admin_name"
                                class="form-control"
                                required>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Admin Email *
                            </label>

                            <input
                                type="email"
                                name="admin_email"
                                class="form-control"
                                required>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Temporary Password *
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                minlength="8"
                                required>

                            <div class="form-text">
                                Minimum 8 characters.
                            </div>

                        </div>

                    </div>


                    <div class="alert alert-info mt-4 mb-0">

                        <i class="bi bi-info-circle me-1"></i>

                        New companies automatically receive a
                        <strong>30-day free trial</strong> and a storefront.

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn btn-success">

                        <i class="bi bi-plus-circle me-1"></i>

                        Create Company

                    </button>

                </div>

            </form>

        </div>

    </div>


    <!-- =========================================================
     DELETE COMPANY MODAL
========================================================= -->

    <div
        class="modal fade"
        id="deleteCompanyModal"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered">

            <form
                method="POST"
                action="companies.php"
                class="modal-content">

                <input
                    type="hidden"
                    name="action"
                    value="delete">

                <input
                    type="hidden"
                    name="company_id"
                    id="deleteCompanyId"
                    value="">


                <div class="modal-header">

                    <h5 class="modal-title text-danger">

                        <i
                            class="bi bi-exclamation-triangle-fill me-2"></i>

                        Delete Company

                    </h5>


                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"></button>

                </div>


                <div class="modal-body">

                    <p>
                        You are about to permanently delete:
                    </p>


                    <h5
                        class="fw-bold"
                        id="deleteCompanyName">
                        Company
                    </h5>


                    <div class="alert alert-danger mt-3 mb-0">

                        <strong>
                            This action cannot be undone.
                        </strong>

                        <br><br>

                        The company's associated storefront,
                        users, products, orders, payments and
                        delivery information will also be removed.

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn btn-danger">

                        <i class="bi bi-trash me-1"></i>

                        Yes, Delete Company

                    </button>

                </div>

            </form>

        </div>

    </div>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


    <script>
        /*
|--------------------------------------------------------------------------
| DELETE COMPANY MODAL
|--------------------------------------------------------------------------
*/

        document.addEventListener('DOMContentLoaded', function() {

            const deleteModal =
                document.getElementById('deleteCompanyModal');

            if (!deleteModal) {
                return;
            }


            deleteModal.addEventListener(
                'show.bs.modal',
                function(event) {

                    const button = event.relatedTarget;

                    if (!button) {
                        return;
                    }


                    const companyId =
                        button.getAttribute('data-company-id');

                    const companyName =
                        button.getAttribute('data-company-name');


                    document.getElementById(
                        'deleteCompanyId'
                    ).value = companyId;


                    document.getElementById(
                        'deleteCompanyName'
                    ).textContent = companyName;

                }
            );

        });
    </script>


</body>

</html>