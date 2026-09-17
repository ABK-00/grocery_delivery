<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

// Confirm authentication function exists
if (!function_exists("requireRole")) {
    die("Authentication error: requireRole() was not loaded. Please check includes/auth.php.");
}

requireRole("admin");

$page_title = "Payments";

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function column_exists($conn, $table, $column)
{
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

function get_table_columns($conn, $table)
{
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table`");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

function find_column($columns, $possible_names)
{
    foreach ($possible_names as $name) {
        if (in_array($name, $columns, true)) {
            return $name;
        }
    }

    return null;
}

function format_money($amount)
{
    return "GH₵ " . number_format((float) $amount, 2);
}

function status_badge($status)
{
    $status = strtolower(trim((string) $status));

    $class = "badge-pending";

    if (in_array($status, ["paid", "completed", "successful", "success"], true)) {
        $class = "badge-delivered";
    } elseif (in_array($status, ["pending", "processing", "awaiting"], true)) {
        $class = "badge-pending";
    } elseif (in_array($status, ["failed", "cancelled", "canceled", "rejected"], true)) {
        $class = "badge-cancelled";
    } elseif (in_array($status, ["refunded", "refund"], true)) {
        $class = "badge-preparing";
    }

    return '<span class="badge ' . $class . '">' . e(ucfirst($status ?: "Unknown")) . '</span>';
}

/*
|--------------------------------------------------------------------------
| Check orders table
|--------------------------------------------------------------------------
*/

$orders_columns = get_table_columns($conn, "orders");

if (empty($orders_columns)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payments | Grocery Delivery</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="../assets/css/admin.css" rel="stylesheet">
    </head>
    <body>
        <?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>
        <main class="main-content">
            <div class="alert alert-danger">
                <h5 class="alert-heading">
                    <i class="bi bi-exclamation-triangle"></i>
                    Orders table not found
                </h5>
                <p class="mb-0">
                    The <strong>orders</strong> table could not be found in the <strong>grocery_delivery</strong> database.
                </p>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| Detect columns
|--------------------------------------------------------------------------
*/

$id_column = find_column($orders_columns, [
    "id",
    "order_id"
]);

$user_column = find_column($orders_columns, [
    "user_id",
    "customer_id",
    "client_id"
]);


$customer_name_column = null;

try {
    $users_columns = get_table_columns($conn, "users");

    $user_name_column = find_column($users_columns, [
        "name",
        "full_name",
        "username"
    ]);

    $user_email_column = find_column($users_columns, [
        "email"
    ]);
} catch (Exception $e) {
    $users_columns = [];
    $user_name_column = null;
    $user_email_column = null;
}

$amount_column = find_column($orders_columns, [
    "total_amount",
    "amount",
    "grand_total",
    "total",
    "order_total",
    "price"
]);

$payment_status_column = find_column($orders_columns, [
    "payment_status",
    "payment_state",
    "transaction_status"
]);

$payment_method_column = find_column($orders_columns, [
    "payment_method",
    "method",
    "payment_type"
]);

$transaction_column = find_column($orders_columns, [
    "transaction_id",
    "transaction_reference",
    "reference",
    "payment_reference",
    "tx_ref"
]);

$date_column = find_column($orders_columns, [
    "created_at",
    "order_date",
    "created_on",
    "date_created",
    "payment_date"
]);

$status_column = find_column($orders_columns, [
    "status",
    "order_status"
]);

/*
|--------------------------------------------------------------------------
| Handle payment status update
|--------------------------------------------------------------------------
*/

$success_message = "";
$error_message = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["update_payment_status"])
) {
    if (!$payment_status_column) {
        $error_message = "A payment status column does not exist in the orders table.";
    } else {
        $order_id = trim($_POST["order_id"] ?? "");
        $new_status = trim($_POST["payment_status"] ?? "");

        $allowed_statuses = [
            "pending",
            "paid",
            "successful",
            "failed",
            "refunded",
            "cancelled"
        ];

        if ($order_id === "" || !in_array(strtolower($new_status), $allowed_statuses, true)) {
            $error_message = "Invalid payment status update.";
        } else {
            try {
                $sql = "UPDATE orders SET `$payment_status_column` = ? WHERE `$id_column` = ?";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $new_status,
                    $order_id
                ]);

                $success_message = "Payment status updated successfully.";
            } catch (PDOException $e) {
                $error_message = "Unable to update payment status: " . $e->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Search and filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");
$payment_filter = trim($_GET["payment_status"] ?? "");
$method_filter = trim($_GET["payment_method"] ?? "");

$where = [];
$params = [];

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $search_conditions = [];

    if ($id_column) {
        $search_conditions[] = "CAST(o.`$id_column` AS CHAR) LIKE ?";
        $params[] = "%" . $search . "%";
    }

    if ($transaction_column) {
        $search_conditions[] = "o.`$transaction_column` LIKE ?";
        $params[] = "%" . $search . "%";
    }

    if ($user_column) {
        $search_conditions[] = "CAST(o.`$user_column` AS CHAR) LIKE ?";
        $params[] = "%" . $search . "%";
    }

    if ($search_conditions) {
        $where[] = "(" . implode(" OR ", $search_conditions) . ")";
    }
}

/*
|--------------------------------------------------------------------------
| Payment status filter
|--------------------------------------------------------------------------
*/

if ($payment_filter !== "" && $payment_status_column) {
    $where[] = "LOWER(o.`$payment_status_column`) = LOWER(?)";
    $params[] = $payment_filter;
}

/*
|--------------------------------------------------------------------------
| Payment method filter
|--------------------------------------------------------------------------
*/

if ($method_filter !== "" && $payment_method_column) {
    $where[] = "LOWER(o.`$payment_method_column`) = LOWER(?)";
    $params[] = $method_filter;
}

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/

$select_fields = [];

$select_fields[] = "o.`$id_column` AS order_id";

if ($amount_column) {
    $select_fields[] = "o.`$amount_column` AS payment_amount";
} else {
    $select_fields[] = "0 AS payment_amount";
}

if ($payment_status_column) {
    $select_fields[] = "o.`$payment_status_column` AS payment_status";
} else {
    $select_fields[] = "'unknown' AS payment_status";
}

if ($payment_method_column) {
    $select_fields[] = "o.`$payment_method_column` AS payment_method";
} else {
    $select_fields[] = "'Not specified' AS payment_method";
}

if ($transaction_column) {
    $select_fields[] = "o.`$transaction_column` AS transaction_reference";
} else {
    $select_fields[] = "NULL AS transaction_reference";
}

if ($date_column) {
    $select_fields[] = "o.`$date_column` AS payment_date";
} else {
    $select_fields[] = "NULL AS payment_date";
}

if ($status_column) {
    $select_fields[] = "o.`$status_column` AS order_status";
} else {
    $select_fields[] = "'Unknown' AS order_status";
}

if ($user_column) {
    $select_fields[] = "o.`$user_column` AS customer_id";
} else {
    $select_fields[] = "NULL AS customer_id";
}

/*
|--------------------------------------------------------------------------
| Customer information
|--------------------------------------------------------------------------
*/

$join_users = false;

if (
    $user_column &&
    !empty($users_columns) &&
    in_array("id", $users_columns, true) &&
    $user_name_column
) {
    $join_users = true;

    $select_fields[] = "u.`$user_name_column` AS customer_name";

    if ($user_email_column) {
        $select_fields[] = "u.`$user_email_column` AS customer_email";
    } else {
        $select_fields[] = "NULL AS customer_email";
    }
} else {
    $select_fields[] = "NULL AS customer_name";
    $select_fields[] = "NULL AS customer_email";
}

$sql = "SELECT " . implode(", ", $select_fields) . " FROM orders o";

if ($join_users) {
    $sql .= " LEFT JOIN users u ON u.id = o.`$user_column`";
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

if ($date_column) {
    $sql .= " ORDER BY o.`$date_column` DESC";
} else {
    $sql .= " ORDER BY o.`$id_column` DESC";
}

$sql .= " LIMIT 200";

/*
|--------------------------------------------------------------------------
| Fetch payments
|--------------------------------------------------------------------------
*/

$payments = [];

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
} catch (PDOException $e) {
    $error_message = "Unable to load payments: " . $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_payments = 0;
$successful_payments = 0;
$pending_payments = 0;
$failed_payments = 0;
$total_revenue = 0;

foreach ($payments as $payment) {

    $total_payments++;

    $amount = (float) ($payment["payment_amount"] ?? 0);
    $total_revenue += $amount;

    $status = strtolower(trim((string) ($payment["payment_status"] ?? "")));

    if (in_array($status, ["paid", "successful", "success", "completed"], true)) {
        $successful_payments++;
    } elseif (in_array($status, ["pending", "processing", "awaiting"], true)) {
        $pending_payments++;
    } elseif (in_array($status, ["failed", "cancelled", "canceled", "rejected"], true)) {
        $failed_payments++;
    }
}

/*
|--------------------------------------------------------------------------
| Payment methods
|--------------------------------------------------------------------------
*/

$payment_methods = [];

if ($payment_method_column) {
    try {
        $method_sql = "
            SELECT DISTINCT `$payment_method_column`
            FROM orders
            WHERE `$payment_method_column` IS NOT NULL
              AND `$payment_method_column` <> ''
            ORDER BY `$payment_method_column` ASC
        ";

        $method_stmt = $conn->query($method_sql);
        $payment_methods = $method_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $payment_methods = [];
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Payments | Grocery Delivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">

    <link
        href="../assets/css/admin.css"
        rel="stylesheet">

    <style>
        .payment-ref {
            font-family: monospace;
            font-size: .85rem;
        }

        .customer-name {
            font-weight: 600;
        }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            display: block;
            margin-bottom: 15px;
        }
    </style>

</head>

<body>

<!-- SIDEBAR -->

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>


<!-- MAIN CONTENT -->

<main class="main-content">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="topbar-left">

            <button
                class="sidebar-toggle"
                id="sidebarToggle"
                type="button"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="topbar-title">
                    Payments Management
                </h1>

                <p class="topbar-subtitle">
                    Monitor customer payments, transaction references, and payment status.
                </p>
            </div>

        </div>

        <div>
            <a href="orders.php" class="btn btn-success">
                <i class="bi bi-cart-check me-1"></i>
                View Orders
            </a>
        </div>

    </div>

    <!-- Alerts -->

    <?php if ($success_message): ?>

        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?= e($success_message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>
        </div>

    <?php endif; ?>

    <?php if ($error_message): ?>

        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= e($error_message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>
        </div>

    <?php endif; ?>

    <!-- Statistics -->

    <div class="row g-4 mb-4">

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="bi bi-wallet2"></i>
                    </div>

                    <div>
                        <div class="stat-label">Total Payments</div>
                        <p class="stat-value">
                            <?= number_format($total_payments) ?>
                        </p>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>

                    <div>
                        <div class="stat-label">Successful</div>
                        <p class="stat-value">
                            <?= number_format($successful_payments) ?>
                        </p>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="bi bi-hourglass-split"></i>
                    </div>

                    <div>
                        <div class="stat-label">Pending</div>
                        <p class="stat-value">
                            <?= number_format($pending_payments) ?>
                        </p>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="bi bi-cash-stack"></i>
                    </div>

                    <div>
                        <div class="stat-label">Payment Value</div>
                        <p class="stat-value">
                            <?= format_money($total_revenue) ?>
                        </p>
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- Filters -->

    <div class="card filter-card mb-4">

        <div class="card-body">

            <form method="get">

                <div class="row g-3 align-items-end">

                    <div class="col-lg-5">

                        <label class="form-label fw-semibold">
                            Search
                        </label>

                        <div class="input-group">

                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Order ID, customer ID or transaction reference"
                                value="<?= e($search) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-lg-3">

                        <label class="form-label fw-semibold">
                            Payment Status
                        </label>

                        <select name="payment_status" class="form-select">

                            <option value="">All statuses</option>

                            <option value="pending"
                                <?= $payment_filter === "pending" ? "selected" : "" ?>>
                                Pending
                            </option>

                            <option value="paid"
                                <?= $payment_filter === "paid" ? "selected" : "" ?>>
                                Paid
                            </option>

                            <option value="successful"
                                <?= $payment_filter === "successful" ? "selected" : "" ?>>
                                Successful
                            </option>

                            <option value="failed"
                                <?= $payment_filter === "failed" ? "selected" : "" ?>>
                                Failed
                            </option>

                            <option value="refunded"
                                <?= $payment_filter === "refunded" ? "selected" : "" ?>>
                                Refunded
                            </option>

                            <option value="cancelled"
                                <?= $payment_filter === "cancelled" ? "selected" : "" ?>>
                                Cancelled
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-2">

                        <label class="form-label fw-semibold">
                            Method
                        </label>

                        <select name="payment_method" class="form-select">

                            <option value="">All methods</option>

                            <?php foreach ($payment_methods as $method): ?>

                                <option
                                    value="<?= e($method) ?>"
                                    <?= $method_filter === $method ? "selected" : "" ?>
                                >
                                    <?= e(ucwords(str_replace("_", " ", $method))) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-2 d-flex gap-2">

                        <button class="btn btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>
                            Filter
                        </button>

                        <a href="payments.php"
                           class="btn btn-outline-secondary"
                           title="Clear filters">

                            <i class="bi bi-arrow-clockwise"></i>

                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- Payments Table -->

    <div class="card content-card">

        <div class="card-header bg-white border-0 pt-4 px-4">

            <div class="d-flex justify-content-between align-items-center">

                <div>

                    <h5 class="mb-1 fw-bold">
                        Payment Transactions
                    </h5>

                    <small class="text-muted">
                        Showing up to 200 payment records
                    </small>

                </div>

                <span class="badge bg-light text-dark">
                    <?= number_format(count($payments)) ?> records
                </span>

            </div>

        </div>

        <div class="card-body px-0">

            <?php if (empty($payments)): ?>

                <div class="empty-state">

                    <i class="bi bi-credit-card text-muted"></i>

                    <h5>No payment records found</h5>

                    <p class="mb-0">
                        There are currently no payments matching your search or filters.
                    </p>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover mb-0">

                        <thead class="table-light">

                            <tr>

                                <th class="ps-4">
                                    Order
                                </th>

                                <th>
                                    Customer
                                </th>

                                <th>
                                    Amount
                                </th>

                                <th>
                                    Method
                                </th>

                                <th>
                                    Transaction
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Date
                                </th>

                                <th class="text-end pe-4">
                                    Action
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($payments as $payment): ?>

                                <tr>

                                    <!-- Order -->

                                    <td class="ps-4">

                                        <strong>
                                            #<?= e($payment["order_id"]) ?>
                                        </strong>

                                        <br>

                                        <small class="text-muted">
                                            <?= e(ucfirst($payment["order_status"] ?? "Unknown")) ?>
                                        </small>

                                    </td>

                                    <!-- Customer -->

                                    <td>

                                        <?php if (!empty($payment["customer_name"])): ?>

                                            <div class="customer-name">
                                                <?= e($payment["customer_name"]) ?>
                                            </div>

                                            <?php if (!empty($payment["customer_email"])): ?>

                                                <small class="text-muted">
                                                    <?= e($payment["customer_email"]) ?>
                                                </small>

                                            <?php endif; ?>

                                        <?php elseif (!empty($payment["customer_id"])): ?>

                                            <div class="customer-name">
                                                Customer #<?= e($payment["customer_id"]) ?>
                                            </div>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                Unknown customer
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Amount -->

                                    <td>

                                        <strong>
                                            <?= format_money($payment["payment_amount"] ?? 0) ?>
                                        </strong>

                                    </td>

                                    <!-- Method -->

                                    <td>

                                        <?php
                                        $method = trim((string) ($payment["payment_method"] ?? ""));
                                        ?>

                                        <?php if ($method): ?>

                                            <span class="text-capitalize">
                                                <?= e(str_replace("_", " ", $method)) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                Not specified
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Transaction -->

                                    <td>

                                        <?php if (!empty($payment["transaction_reference"])): ?>

                                            <span class="payment-ref">
                                                <?= e($payment["transaction_reference"]) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Status -->

                                    <td>

                                        <?= status_badge($payment["payment_status"] ?? "Unknown") ?>

                                    </td>

                                    <!-- Date -->

                                    <td>

                                        <?php if (!empty($payment["payment_date"])): ?>

                                            <div>
                                                <?= e(date("d M Y", strtotime($payment["payment_date"]))) ?>
                                            </div>

                                            <small class="text-muted">
                                                <?= e(date("h:i A", strtotime($payment["payment_date"]))) ?>
                                            </small>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Action -->

                                    <td class="text-end pe-4">

                                        <?php if ($payment_status_column): ?>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#paymentModal<?= e($payment["order_id"]) ?>"
                                            >
                                                <i class="bi bi-pencil-square"></i>
                                            </button>

                                        <?php else: ?>

                                            <span class="text-muted small">
                                                No status field
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- Payment Status Modals -->

<?php foreach ($payments as $payment): ?>

    <?php if ($payment_status_column): ?>

        <div
            class="modal fade"
            id="paymentModal<?= e($payment["order_id"]) ?>"
            tabindex="-1"
            aria-hidden="true"
        >

            <div class="modal-dialog modal-dialog-centered">

                <div class="modal-content">

                    <div class="modal-header">

                        <h5 class="modal-title">
                            <i class="bi bi-credit-card me-2"></i>
                            Update Payment
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                        ></button>

                    </div>

                    <form method="post">

                        <div class="modal-body">

                            <input
                                type="hidden"
                                name="update_payment_status"
                                value="1"
                            >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= e($payment["order_id"]) ?>"
                            >

                            <div class="mb-3">

                                <label class="form-label fw-semibold">
                                    Order
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    value="#<?= e($payment["order_id"]) ?>"
                                    readonly
                                >

                            </div>

                            <div class="mb-3">

                                <label class="form-label fw-semibold">
                                    Amount
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?= e(format_money($payment["payment_amount"] ?? 0)) ?>"
                                    readonly
                                >

                            </div>

                            <div class="mb-3">

                                <label class="form-label fw-semibold">
                                    Current Status
                                </label>

                                <div>
                                    <?= status_badge($payment["payment_status"] ?? "Unknown") ?>
                                </div>

                            </div>

                            <div class="mb-3">

                                <label class="form-label fw-semibold">
                                    New Payment Status
                                </label>

                                <select
                                    name="payment_status"
                                    class="form-select"
                                    required
                                >

                                    <option value="pending"
                                        <?= strtolower($payment["payment_status"] ?? "") === "pending" ? "selected" : "" ?>>
                                        Pending
                                    </option>

                                    <option value="paid"
                                        <?= strtolower($payment["payment_status"] ?? "") === "paid" ? "selected" : "" ?>>
                                        Paid
                                    </option>

                                    <option value="successful"
                                        <?= strtolower($payment["payment_status"] ?? "") === "successful" ? "selected" : "" ?>>
                                        Successful
                                    </option>

                                    <option value="failed"
                                        <?= strtolower($payment["payment_status"] ?? "") === "failed" ? "selected" : "" ?>>
                                        Failed
                                    </option>

                                    <option value="refunded"
                                        <?= strtolower($payment["payment_status"] ?? "") === "refunded" ? "selected" : "" ?>>
                                        Refunded
                                    </option>

                                    <option value="cancelled"
                                        <?= strtolower($payment["payment_status"] ?? "") === "cancelled" ? "selected" : "" ?>>
                                        Cancelled
                                    </option>

                                </select>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button
                                type="button"
                                class="btn btn-light"
                                data-bs-dismiss="modal"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-check-lg me-1"></i>
                                Save Status
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    <?php endif; ?>

<?php endforeach; ?>


</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>