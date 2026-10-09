<?php

require_once '../config/db.php';
require_once '../includes/auth.php';

requireRole('admin');
requireCompanyAccess();

$companyId = currentCompanyId();

$q = trim($_GET['q'] ?? '');


/*
|--------------------------------------------------------------------------
| COMPANY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        company_name
    FROM companies
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$companyId]);

$company = $stmt->fetch();

if (!$company) {
    die('Company not found.');
}


/*
|--------------------------------------------------------------------------
| CUSTOMER STATS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT o.user_id) AS total_customers,

        COUNT(DISTINCT CASE
            WHEN DATE(o.created_at) = CURDATE()
            THEN o.user_id
        END) AS customers_today,

        COUNT(DISTINCT CASE
            WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            THEN o.user_id
        END) AS customers_30_days,

        COALESCE(
            SUM(
                CASE
                    WHEN o.status != 'cancelled'
                    THEN o.total_amount
                    ELSE 0
                END
            ),
            0
        ) AS total_customer_revenue

    FROM orders o

    INNER JOIN users u
        ON u.id = o.user_id
        AND u.role = 'customer'

    WHERE o.company_id = ?
");

$stmt->execute([$companyId]);

$stats = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| CUSTOMERS WHO ORDERED FROM THIS COMPANY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.whatsapp,
        u.status,
        u.created_at,

        COUNT(o.id) AS order_count,

        COALESCE(
            SUM(
                CASE
                    WHEN o.status != 'cancelled'
                    THEN o.total_amount
                    ELSE 0
                END
            ),
            0
        ) AS total_spent,

        MAX(o.created_at) AS last_order_at,

        MAX(
            CASE
                WHEN o.status != 'cancelled'
                THEN o.total_amount
                ELSE NULL
            END
        ) AS largest_order

    FROM users u

    INNER JOIN orders o
        ON o.user_id = u.id
        AND o.company_id = ?

    WHERE u.role = 'customer'
";

$params = [$companyId];


if ($q !== '') {

    $sql .= "
        AND (
            u.name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR u.whatsapp LIKE ?
        )
    ";

    $like = '%' . $q . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}


$sql .= "
    GROUP BY
        u.id,
        u.name,
        u.email,
        u.phone,
        u.whatsapp,
        u.status,
        u.created_at

    ORDER BY last_order_at DESC
";


$stmt = $conn->prepare($sql);
$stmt->execute($params);

$customers = $stmt->fetchAll();

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        Customers | Admin
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <style>
        body {
            background: #f5f7fa;
        }

        .main-content {
            padding: 30px;
        }

        .page-card {
            border: 0;
            border-radius: 18px;
            box-shadow:
                0 8px 25px rgba(0, 0, 0, .05);
        }

        .stat-card {
            border: 0;
            border-radius: 18px;
            height: 100%;
            box-shadow:
                0 8px 25px rgba(0, 0, 0, .05);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.25rem;
        }

        .customer-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e9f7ef;
            color: #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            flex-shrink: 0;
        }

        @media(max-width: 900px) {

            .main-content {
                padding: 20px 15px;
            }

        }
    </style>

    <link href="../assets/css/admin-farvist.css" rel="stylesheet">
</head>

<body class="admin-farvist">

    <?php

    include '../includes/loader.php';

    if (file_exists('../includes/admin_sidebar.php')) {
        include '../includes/admin_sidebar.php';
    }

    ?>


    <main class="main-content">

        <!-- HEADER -->

        <div
            class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

            <div>

                <h2 class="fw-bold mb-1">
                    Customers
                </h2>

                <p class="text-muted mb-0">

                    Customers who have ordered from

                    <strong>
                        <?= htmlspecialchars(
                            $company['company_name']
                        ) ?>
                    </strong>.

                </p>

            </div>


            <div class="badge text-bg-light fs-6 p-2">

                <i class="bi bi-shield-check me-1"></i>

                Marketplace Customers

            </div>

        </div>


        <!-- STATS -->

        <div class="row g-3 mb-4">

            <div class="col-sm-6 col-xl-3">

                <div class="card stat-card">

                    <div class="card-body">

                        <div
                            class="d-flex justify-content-between align-items-start">

                            <div>

                                <small class="text-muted">
                                    Total Customers
                                </small>

                                <h3 class="fw-bold mt-2 mb-0">

                                    <?= (int)(
                                        $stats['total_customers']
                                        ?? 0
                                    ) ?>

                                </h3>

                            </div>


                            <div
                                class="stat-icon bg-success-subtle text-success">

                                <i class="bi bi-people"></i>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-sm-6 col-xl-3">

                <div class="card stat-card">

                    <div class="card-body">

                        <div
                            class="d-flex justify-content-between align-items-start">

                            <div>

                                <small class="text-muted">
                                    Ordered Today
                                </small>

                                <h3 class="fw-bold mt-2 mb-0">

                                    <?= (int)(
                                        $stats['customers_today']
                                        ?? 0
                                    ) ?>

                                </h3>

                            </div>


                            <div
                                class="stat-icon bg-primary-subtle text-primary">

                                <i class="bi bi-calendar-check"></i>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-sm-6 col-xl-3">

                <div class="card stat-card">

                    <div class="card-body">

                        <div
                            class="d-flex justify-content-between align-items-start">

                            <div>

                                <small class="text-muted">
                                    Last 30 Days
                                </small>

                                <h3 class="fw-bold mt-2 mb-0">

                                    <?= (int)(
                                        $stats['customers_30_days']
                                        ?? 0
                                    ) ?>

                                </h3>

                            </div>


                            <div
                                class="stat-icon bg-warning-subtle text-warning">

                                <i class="bi bi-graph-up"></i>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-sm-6 col-xl-3">

                <div class="card stat-card">

                    <div class="card-body">

                        <div
                            class="d-flex justify-content-between align-items-start">

                            <div>

                                <small class="text-muted">
                                    Customer Revenue
                                </small>

                                <h3 class="fw-bold mt-2 mb-0">

                                    GH₵
                                    <?= number_format(
                                        (float)(
                                            $stats['total_customer_revenue']
                                            ?? 0
                                        ),
                                        2
                                    ) ?>

                                </h3>

                            </div>


                            <div
                                class="stat-icon bg-info-subtle text-info">

                                <i class="bi bi-cash-stack"></i>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- SEARCH -->

        <div class="card page-card mb-4">

            <div class="card-body">

                <form
                    method="GET"
                    class="row g-2">

                    <div class="col-md-10">

                        <input
                            type="search"
                            name="q"
                            class="form-control"
                            value="<?= htmlspecialchars($q) ?>"
                            placeholder="Search customer name, email or phone...">

                    </div>


                    <div class="col-md-2">

                        <button
                            type="submit"
                            class="btn btn-dark w-100">

                            <i class="bi bi-search me-1"></i>

                            Search

                        </button>

                    </div>

                </form>

            </div>

        </div>


        <!-- CUSTOMER TABLE -->

        <div class="card page-card">

            <div class="card-body">

                <div
                    class="d-flex justify-content-between align-items-center mb-3">

                    <div>

                        <h5 class="fw-bold mb-1">
                            Customer History
                        </h5>

                        <small class="text-muted">

                            Only customers who have ordered
                            from your store are visible here.

                        </small>

                    </div>

                </div>


                <div class="table-responsive">

                    <table
                        class="table align-middle">

                        <thead>

                            <tr>

                                <th>
                                    Customer
                                </th>

                                <th>
                                    Phone
                                </th>

                                <th>
                                    Orders
                                </th>

                                <th>
                                    Total Spent
                                </th>

                                <th>
                                    Largest Order
                                </th>

                                <th>
                                    Last Order
                                </th>

                                <th>
                                    Account
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $customers as $customer
                            ): ?>

                                <?php

                                $initials = '';

                                $parts = preg_split(
                                    '/\s+/',
                                    trim(
                                        $customer['name']
                                    )
                                );

                                foreach (
                                    array_slice(
                                        $parts,
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


                                $statusClass = match ($customer['status']) {

                                    'active' =>
                                    'success',

                                    'suspended' =>
                                    'danger',

                                    default =>
                                    'secondary'
                                };

                                ?>

                                <tr>

                                    <td>

                                        <div
                                            class="d-flex align-items-center gap-3">

                                            <div
                                                class="customer-avatar">
                                                <?= htmlspecialchars(
                                                    $initials
                                                        ?: 'C'
                                                ) ?>
                                            </div>


                                            <div>

                                                <strong
                                                    class="d-block">

                                                    <?= htmlspecialchars(
                                                        $customer['name']
                                                    ) ?>

                                                </strong>


                                                <small
                                                    class="text-muted">

                                                    <?= htmlspecialchars(
                                                        $customer['email']
                                                    ) ?>

                                                </small>

                                            </div>

                                        </div>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $customer['phone']
                                                ?: '—'
                                        ) ?>

                                    </td>


                                    <td>

                                        <span
                                            class="badge text-bg-light">

                                            <?= (int)
                                            $customer['order_count'] ?>

                                        </span>

                                    </td>


                                    <td>

                                        <strong>

                                            GH₵
                                            <?= number_format(
                                                (float)
                                                $customer['total_spent'],
                                                2
                                            ) ?>

                                        </strong>

                                    </td>


                                    <td>

                                        GH₵
                                        <?= number_format(
                                            (float)(
                                                $customer['largest_order']
                                                ?? 0
                                            ),
                                            2
                                        ) ?>

                                    </td>


                                    <td>

                                        <?php if (
                                            $customer['last_order_at']
                                        ): ?>

                                            <?= date(
                                                'M j, Y',
                                                strtotime(
                                                    $customer['last_order_at']
                                                )
                                            ) ?>

                                            <small
                                                class="d-block text-muted">

                                                <?= date(
                                                    'g:i A',
                                                    strtotime(
                                                        $customer['last_order_at']
                                                    )
                                                ) ?>

                                            </small>

                                        <?php else: ?>

                                            —

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <span
                                            class="badge text-bg-<?= $statusClass ?>">

                                            <?= ucfirst(
                                                $customer['status']
                                            ) ?>

                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>


                            <?php if (!$customers): ?>

                                <tr>

                                    <td
                                        colspan="7"
                                        class="text-center py-5">

                                        <i
                                            class="bi bi-people display-4 text-muted"></i>

                                        <h5 class="mt-3">

                                            No customers yet

                                        </h5>

                                        <p
                                            class="text-muted mb-0">

                                            Customers will appear here
                                            after they place an order
                                            from your storefront.

                                        </p>

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </main>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>