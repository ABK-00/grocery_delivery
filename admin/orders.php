<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole("admin");

$message = "";
$messageType = "success";

$allowedStatuses = [
    "pending",
    "confirmed",
    "preparing",
    "ready",
    "out_for_delivery",
    "delivered",
    "cancelled"
];

$statusLabels = [
    "pending"           => "Pending",
    "confirmed"         => "Confirmed",
    "preparing"         => "Preparing",
    "ready"             => "Ready",
    "out_for_delivery"  => "Out for Delivery",
    "delivered"         => "Delivered",
    "cancelled"         => "Cancelled"
];

$statusBadgeClass = [
    "pending"           => "badge-pending",
    "confirmed"         => "badge-confirmed",
    "preparing"         => "badge-preparing",
    "ready"             => "badge-ready",
    "out_for_delivery"  => "badge-out",
    "delivered"         => "badge-delivered",
    "cancelled"         => "badge-cancelled"
];

$paymentBadgeClass = [
    "pending"  => "badge-pending",
    "paid"     => "badge-delivered",
    "failed"   => "badge-cancelled",
    "refunded" => "badge-preparing"
];

/*
|--------------------------------------------------------------------------
| SUCCESS / ERROR MESSAGES (after redirect)
|--------------------------------------------------------------------------
*/
if (isset($_GET["success"]) && $_GET["success"] === "status_updated") {
    $message = "Order status updated successfully.";
}

if (isset($_GET["error"])) {
    $message = $_GET["error"];
    $messageType = "danger";
}

/*
|--------------------------------------------------------------------------
| QUICK STATUS UPDATE
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    if ($action === "update_status") {

        $orderId = (int) ($_POST["order_id"] ?? 0);
        $newStatus = $_POST["status"] ?? "";

        $qs = $_POST["qs"] ?? "";

        if ($orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {

            header("Location: orders.php?error=" . urlencode("Invalid order or status.") . ($qs ? "&{$qs}" : ""));
            exit;
        }

        try {

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT id
                FROM orders
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$orderId]);

            if (!$stmt->fetch()) {
                throw new Exception("Order not found.");
            }

            $stmt = $conn->prepare("
                UPDATE orders
                SET status = ?
                WHERE id = ?
            ");

            $stmt->execute([$newStatus, $orderId]);

            /*
            | Keep linked delivery record in sync
            | for the statuses that affect it.
            */
            if (in_array($newStatus, ["out_for_delivery", "delivered", "cancelled"], true)) {

                $deliveryStmt = $conn->prepare("
                    SELECT id, delivery_partner_id
                    FROM deliveries
                    WHERE order_id = ?
                    LIMIT 1
                ");

                $deliveryStmt->execute([$orderId]);
                $delivery = $deliveryStmt->fetch();

                if ($delivery) {

                    if ($newStatus === "out_for_delivery") {

                        $conn->prepare("
                            UPDATE deliveries
                            SET status = 'out_for_delivery'
                            WHERE id = ?
                        ")->execute([$delivery["id"]]);

                    } elseif ($newStatus === "delivered") {

                        $conn->prepare("
                            UPDATE deliveries
                            SET status = 'delivered', delivered_at = NOW()
                            WHERE id = ?
                        ")->execute([$delivery["id"]]);

                        if (!empty($delivery["delivery_partner_id"])) {

                            $conn->prepare("
                                UPDATE delivery_partners
                                SET status = 'available'
                                WHERE id = ?
                            ")->execute([$delivery["delivery_partner_id"]]);
                        }

                    } elseif ($newStatus === "cancelled") {

                        $conn->prepare("
                            UPDATE deliveries
                            SET status = 'cancelled'
                            WHERE id = ?
                        ")->execute([$delivery["id"]]);

                        if (!empty($delivery["delivery_partner_id"])) {

                            $conn->prepare("
                                UPDATE delivery_partners
                                SET status = 'available'
                                WHERE id = ?
                            ")->execute([$delivery["delivery_partner_id"]]);
                        }
                    }
                }
            }

            $conn->commit();

            header("Location: orders.php?success=status_updated" . ($qs ? "&{$qs}" : ""));
            exit;

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            header("Location: orders.php?error=" . urlencode($e->getMessage()) . ($qs ? "&{$qs}" : ""));
            exit;
        }
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "";
$paymentFilter = $_GET["payment"] ?? "";
$dateFrom = $_GET["date_from"] ?? "";
$dateTo = $_GET["date_to"] ?? "";

/*
| Build a query string to preserve filters
| across quick actions and pagination.
*/
$qsParts = [];

foreach (["search", "status", "payment", "date_from", "date_to"] as $key) {
    if (!empty($_GET[$key])) {
        $qsParts[] = $key . "=" . urlencode($_GET[$key]);
    }
}

$qs = implode("&", $qsParts);

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/
$perPage = 15;
$page = max(1, (int) ($_GET["page"] ?? 1));
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| BUILD WHERE CLAUSE
|--------------------------------------------------------------------------
*/
$where = " WHERE 1=1 ";
$params = [];

if ($search !== "") {

    $where .= "
        AND (
            o.order_number LIKE ?
            OR u.name LIKE ?
            OR u.email LIKE ?
            OR o.delivery_phone LIKE ?
        )
    ";

    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($statusFilter !== "" && in_array($statusFilter, $allowedStatuses, true)) {
    $where .= " AND o.status = ? ";
    $params[] = $statusFilter;
}

if ($paymentFilter !== "" && in_array($paymentFilter, ["pending", "paid", "failed", "refunded"], true)) {
    $where .= " AND o.payment_status = ? ";
    $params[] = $paymentFilter;
}

if ($dateFrom !== "") {
    $where .= " AND DATE(o.created_at) >= ? ";
    $params[] = $dateFrom;
}

if ($dateTo !== "") {
    $where .= " AND DATE(o.created_at) <= ? ";
    $params[] = $dateTo;
}

/*
|--------------------------------------------------------------------------
| COUNT (for pagination)
|--------------------------------------------------------------------------
*/
$countSql = "
    SELECT COUNT(*)
    FROM orders o
    INNER JOIN users u ON u.id = o.user_id
    {$where}
";

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);

$totalMatching = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalMatching / $perPage));

/*
|--------------------------------------------------------------------------
| ORDERS
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        o.id,
        o.order_number,
        o.subtotal,
        o.delivery_fee,
        o.total_amount,
        o.delivery_phone,
        o.status,
        o.payment_status,
        o.created_at,
        u.name AS customer_name,
        u.email AS customer_email,
        (
            SELECT COUNT(*)
            FROM order_items oi
            WHERE oi.order_id = o.id
        ) AS item_count,
        d.status AS delivery_status
    FROM orders o
    INNER JOIN users u
        ON u.id = o.user_id
    LEFT JOIN deliveries d
        ON d.order_id = o.id
    {$where}
    ORDER BY o.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$orders = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
$totalOrders = (int) $conn->query("
    SELECT COUNT(*) FROM orders
")->fetchColumn();

$pendingOrders = (int) $conn->query("
    SELECT COUNT(*) FROM orders WHERE status = 'pending'
")->fetchColumn();

$activeOrders = (int) $conn->query("
    SELECT COUNT(*) FROM orders
    WHERE status IN ('confirmed','preparing','ready','out_for_delivery')
")->fetchColumn();

$totalRevenue = (float) $conn->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE status = 'delivered'
")->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Orders | GroceryDelivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <style>

        :root {
            --navy: #07182d;
            --navy-light: #0d2747;
            --green: #20c997;
            --green-dark: #12a67e;
            --bg: #f5f7fb;
            --text: #172033;
            --muted: #718096;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, Arial, sans-serif;
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            margin: 0;
        }

        .page-title p {
            color: var(--muted);
            margin: 6px 0 0;
        }

        .stat-card {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 5px 20px rgba(0,0,0,.04);
            height: 100%;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e9faf5;
            color: var(--green-dark);
            font-size: 21px;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 800;
        }

        .stat-label {
            color: var(--muted);
            font-size: 14px;
        }

        .content-card {
            background: white;
            border-radius: 16px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,.04);
        }

        .filters {
            padding: 20px;
            border-bottom: 1px solid #edf0f5;
        }

        .form-control,
        .form-select {
            border-radius: 9px;
            padding: 10px 12px;
        }

        .btn-primary-custom {
            background: var(--green);
            border: none;
            color: #062018;
            font-weight: 700;
            padding: 11px 18px;
            border-radius: 10px;
        }

        .btn-primary-custom:hover {
            background: var(--green-dark);
            color: white;
        }

        .order-table {
            vertical-align: middle;
        }

        .order-number {
            font-weight: 700;
            color: var(--navy);
        }

        .customer-name {
            font-weight: 600;
        }

        .badge-pill {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge-pending      { background: #fff3cd; color: #997404; }
        .badge-confirmed    { background: #e0edff; color: #1d4fa3; }
        .badge-preparing    { background: #fde8cc; color: #b5590a; }
        .badge-ready        { background: #e2e3ff; color: #4338ca; }
        .badge-out          { background: #dcecff; color: #075985; }
        .badge-delivered    { background: #dff8ef; color: #087f5b; }
        .badge-cancelled    { background: #ffe3e3; color: #c92a2a; }

        .status-form select {
            font-size: 13px;
            padding: 6px 10px;
        }

        @media (max-width: 991px) {

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 576px) {

            .main-content {
                padding: 15px;
            }

            .table-responsive {
                font-size: 13px;
            }
        }

    </style>

</head>

<body>

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>


<main class="main-content">

    <!-- Header -->
    <div class="page-header">

        <div class="page-title">

            <h1>
                <i class="bi bi-cart-check-fill me-2"></i>
                Orders
            </h1>

            <p>
                Monitor, filter and manage every customer order.
            </p>

        </div>

    </div>


    <!-- Message -->
    <?php if ($message): ?>

        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show">

            <?= e($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <!-- Stats -->
    <div class="row g-3 mb-4">

        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-receipt"></i>
                </div>

                <div class="stat-number">
                    <?= number_format($totalOrders) ?>
                </div>

                <div class="stat-label">
                    Total Orders
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div class="stat-number">
                    <?= number_format($pendingOrders) ?>
                </div>

                <div class="stat-label">
                    Pending Orders
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-truck"></i>
                </div>

                <div class="stat-number">
                    <?= number_format($activeOrders) ?>
                </div>

                <div class="stat-label">
                    In Progress
                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-cash-coin"></i>
                </div>

                <div class="stat-number">
                    ₵<?= number_format($totalRevenue, 2) ?>
                </div>

                <div class="stat-label">
                    Delivered Revenue
                </div>

            </div>

        </div>

    </div>


    <!-- Orders -->
    <div class="content-card">

        <!-- Filters -->
        <div class="filters">

            <form method="GET">

                <div class="row g-2">

                    <div class="col-lg-4">

                        <div class="input-group">

                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Order #, customer name, email or phone..."
                                value="<?= e($search) ?>">

                        </div>

                    </div>


                    <div class="col-lg-2">

                        <select
                            name="status"
                            class="form-select">

                            <option value="">
                                All Status
                            </option>

                            <?php foreach ($statusLabels as $value => $label): ?>

                                <option
                                    value="<?= e($value) ?>"
                                    <?= $statusFilter === $value ? "selected" : "" ?>>

                                    <?= e($label) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-lg-2">

                        <select
                            name="payment"
                            class="form-select">

                            <option value="">
                                All Payments
                            </option>

                            <?php foreach (["pending" => "Payment Pending", "paid" => "Paid", "failed" => "Failed", "refunded" => "Refunded"] as $value => $label): ?>

                                <option
                                    value="<?= e($value) ?>"
                                    <?= $paymentFilter === $value ? "selected" : "" ?>>

                                    <?= e($label) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-lg-2">

                        <input
                            type="date"
                            name="date_from"
                            class="form-control"
                            title="From date"
                            value="<?= e($dateFrom) ?>">

                    </div>


                    <div class="col-lg-2">

                        <input
                            type="date"
                            name="date_to"
                            class="form-control"
                            title="To date"
                            value="<?= e($dateTo) ?>">

                    </div>


                    <div class="col-12 d-flex gap-2 mt-2">

                        <button
                            class="btn btn-dark">

                            <i class="bi bi-funnel me-1"></i>
                            Filter

                        </button>

                        <?php if ($search !== "" || $statusFilter !== "" || $paymentFilter !== "" || $dateFrom !== "" || $dateTo !== ""): ?>

                            <a
                                href="orders.php"
                                class="btn btn-outline-secondary">

                                Clear

                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </form>

        </div>


        <!-- Table -->
        <div class="table-responsive">

            <table class="table table-hover mb-0 order-table">

                <thead>

                    <tr>

                        <th class="px-4 py-3">
                            Order
                        </th>

                        <th>
                            Customer
                        </th>

                        <th>
                            Items
                        </th>

                        <th>
                            Total
                        </th>

                        <th>
                            Payment
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Date
                        </th>

                        <th class="text-end px-4">
                            Actions
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$orders): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="text-center py-5 text-muted">

                            <i
                                class="bi bi-cart-x fs-1 d-block mb-2">
                            </i>

                            No orders found.

                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach ($orders as $order): ?>

                    <tr>

                        <!-- Order -->
                        <td class="px-4">

                            <div class="order-number">
                                #<?= e($order["order_number"]) ?>
                            </div>

                            <?php if ($order["delivery_status"]): ?>

                                <small class="text-muted">
                                    Delivery:
                                    <?= e(ucwords(str_replace("_", " ", $order["delivery_status"]))) ?>
                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- Customer -->
                        <td>

                            <div class="customer-name">
                                <?= e($order["customer_name"]) ?>
                            </div>

                            <small class="text-muted">
                                <?= e($order["customer_email"]) ?>
                            </small>

                        </td>


                        <!-- Items -->
                        <td>
                            <?= (int) $order["item_count"] ?>
                            item<?= (int) $order["item_count"] === 1 ? "" : "s" ?>
                        </td>


                        <!-- Total -->
                        <td>

                            <strong>
                                GH₵ <?= number_format($order["total_amount"], 2) ?>
                            </strong>

                        </td>


                        <!-- Payment -->
                        <td>

                            <span class="badge-pill <?= $paymentBadgeClass[$order["payment_status"]] ?? "badge-pending" ?>">
                                <?= e(ucfirst($order["payment_status"])) ?>
                            </span>

                        </td>


                        <!-- Status -->
                        <td>

                            <span class="badge-pill <?= $statusBadgeClass[$order["status"]] ?? "badge-pending" ?>">
                                <?= e($statusLabels[$order["status"]] ?? ucfirst($order["status"])) ?>
                            </span>

                        </td>


                        <!-- Date -->
                        <td>

                            <?= date("d M Y", strtotime($order["created_at"])) ?>
                            <br>
                            <small class="text-muted">
                                <?= date("h:i A", strtotime($order["created_at"])) ?>
                            </small>

                        </td>


                        <!-- Actions -->
                        <td class="text-end px-4">

                            <div class="d-flex gap-2 justify-content-end align-items-center">

                                <form
                                    method="POST"
                                    class="status-form d-flex gap-1">

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update_status">

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= (int) $order["id"] ?>">

                                    <input
                                        type="hidden"
                                        name="qs"
                                        value="<?= e($qs . ($qs ? "&" : "") . "page={$page}") ?>">

                                    <select
                                        name="status"
                                        class="form-select form-select-sm"
                                        onchange="this.form.submit()">

                                        <?php foreach ($statusLabels as $value => $label): ?>

                                            <option
                                                value="<?= e($value) ?>"
                                                <?= $order["status"] === $value ? "selected" : "" ?>>

                                                <?= e($label) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </form>

                                <a
                                    href="order_details.php?id=<?= (int) $order["id"] ?>"
                                    class="btn btn-sm btn-outline-primary">

                                    <i class="bi bi-eye"></i>

                                </a>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>


        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>

            <div class="d-flex justify-content-between align-items-center p-3">

                <small class="text-muted">
                    Showing page <?= $page ?> of <?= $totalPages ?>
                    (<?= number_format($totalMatching) ?> orders)
                </small>

                <nav>

                    <ul class="pagination pagination-sm mb-0">

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>

                            <li class="page-item <?= $p === $page ? "active" : "" ?>">

                                <a
                                    class="page-link"
                                    href="?<?= e($qs . ($qs ? "&" : "") . "page={$p}") ?>">

                                    <?= $p ?>

                                </a>

                            </li>

                        <?php endfor; ?>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>

    </div>

</main>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

</body>
</html>