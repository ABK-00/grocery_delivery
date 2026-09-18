<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole('staff');
requireCompanyAccess();
$companyId = currentCompanyId();

$success = $_SESSION['order_success'] ?? null;
$error   = $_SESSION['order_error'] ?? null;

unset($_SESSION['order_success'], $_SESSION['order_error']);

$stmt = $conn->prepare("
    SELECT
        o.id,
        o.order_number,
        o.total_amount,
        o.status,
        o.delivery_address,
        o.delivery_phone,
        o.created_at,
        u.name AS customer_name,
        u.email AS customer_email
    FROM orders o
    INNER JOIN users u ON u.id = o.user_id AND u.company_id = o.company_id
    WHERE o.company_id = ?
    ORDER BY o.created_at DESC
");

$stmt->execute([$companyId]);
$orders = $stmt->fetchAll();

function orderStatusBadge($status)
{
    $map = [
        'pending' => ['Pending', 'bg-warning text-dark'],
        'confirmed' => ['Confirmed', 'bg-primary'],
        'preparing' => ['Preparing', 'bg-info text-dark'],
        'ready' => ['Ready', 'bg-secondary'],
        'out_for_delivery' => ['Out for Delivery', 'bg-dark'],
        'delivered' => ['Delivered', 'bg-success'],
        'cancelled' => ['Cancelled', 'bg-danger']
    ];

    $data = $map[$status] ?? [ucfirst($status), 'bg-secondary'];

    return '<span class="badge ' . $data[1] . '">' . e($data[0]) . '</span>';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Manage Orders | GroceryDelivery</title>

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
    background: #f5f7fa;
}

.main {
    padding: 30px;
    margin-left: 250px;
}

.card-box {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    padding: 22px;
}

.table th {
    white-space: nowrap;
    font-size: 13px;
    color: #64748b;
}

.table td {
    vertical-align: middle;
}

.order-number {
    font-weight: 700;
    color: #0f172a;
}

.customer-name {
    font-weight: 600;
}

@media(max-width:768px) {

    .main {
        padding: 15px;
        margin-left: 0px;
    }

}

</style>

</head>

<body>

<?php require_once __DIR__ . "/../includes/staff_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>
<main class="main">

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h2 class="fw-bold mb-1">
            <i class="bi bi-cart-check"></i>
            Orders
        </h2>

        <p class="text-muted mb-0">
            Manage and process customer orders
        </p>

    </div>

</div>


<?php if ($success): ?>

<div class="alert alert-success alert-dismissible fade show">

    <i class="bi bi-check-circle"></i>

    <?= e($success) ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<?php if ($error): ?>

<div class="alert alert-danger alert-dismissible fade show">

    <i class="bi bi-exclamation-triangle"></i>

    <?= e($error) ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<div class="card-box">

<div class="table-responsive">

<table class="table table-hover align-middle">

<thead>

<tr>

<th>Order</th>
<th>Customer</th>
<th>Total</th>
<th>Status</th>
<th>Date</th>
<th class="text-end">Action</th>

</tr>

</thead>

<tbody>

<?php if (!$orders): ?>

<tr>

<td colspan="6" class="text-center py-5">

    <i class="bi bi-cart-x fs-1 text-muted"></i>

    <div class="mt-2 text-muted">
        No orders found.
    </div>

</td>

</tr>

<?php endif; ?>


<?php foreach ($orders as $order): ?>

<tr>

<td>

    <div class="order-number">
        #<?= e($order['order_number']) ?>
    </div>

</td>


<td>

    <div class="customer-name">
        <?= e($order['customer_name']) ?>
    </div>

    <small class="text-muted">
        <?= e($order['customer_email']) ?>
    </small>

</td>


<td class="fw-bold">

    GH₵<?= number_format(
        $order['total_amount'],
        2
    ) ?>

</td>


<td>

    <?= orderStatusBadge($order['status']) ?>

</td>


<td>

    <?= date(
        'd M Y, h:i A',
        strtotime($order['created_at'])
    ) ?>

</td>


<td class="text-end">

    <a
        href="order_details.php?id=<?= (int)$order['id'] ?>"
        class="btn btn-sm btn-outline-primary"
    >

        <i class="bi bi-eye"></i>

        Manage

    </a>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

</main>


<script
src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>