<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/functions.php";

requireRole("admin");
requireCompanyAccess();
$companyId = currentCompanyId();

/* =========================================================
   COMPANY-SCOPED REPORTS
   ========================================================= */

$totalRevenue = 0;
$totalOrders = 0;
$deliveredOrders = 0;
$avgOrderValue = 0;

try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE company_id = ? AND status = 'delivered'");
    $stmt->execute([$companyId]);
    $totalRevenue = (float)$stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $totalOrders = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE company_id = ? AND status = 'delivered'");
    $stmt->execute([$companyId]);
    $deliveredOrders = (int)$stmt->fetchColumn();

    if ($deliveredOrders > 0) $avgOrderValue = $totalRevenue / $deliveredOrders;
} catch (PDOException $e) {}

$topProducts = [];
try {
    $stmt = $conn->prepare("
        SELECT p.name, c.name AS category_name, COALESCE(SUM(oi.quantity),0) AS total_sold, COALESCE(SUM(oi.subtotal),0) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id AND p.company_id = o.company_id
        LEFT JOIN categories c ON c.id = p.category_id AND c.company_id = o.company_id
        WHERE o.company_id = ?
        GROUP BY p.id, p.name, c.name
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $topProducts = $stmt->fetchAll();
} catch (PDOException $e) { $topProducts = []; }

$statusBreakdown = [];
try {
    $stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM orders WHERE company_id = ? GROUP BY status");
    $stmt->execute([$companyId]);
    $statusBreakdown = $stmt->fetchAll();
} catch (PDOException $e) { $statusBreakdown = []; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics | Grocery Delivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>
<?php include "../includes/loader.php"; ?>

<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1 class="topbar-title">Reports & Analytics</h1>
                <p class="topbar-subtitle">Overview of sales performance, store revenue, and top-performing products.</p>
            </div>
        </div>
    </div>

    <!-- STATS -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Delivered Revenue</div>
                        <div class="stat-number">₵<?= number_format($totalRevenue, 2) ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Orders</div>
                        <div class="stat-number"><?= number_format($totalOrders) ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-cart-check"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Delivered Orders</div>
                        <div class="stat-number"><?= number_format($deliveredOrders) ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-bag-check"></i></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Avg Order Value</div>
                        <div class="stat-number">₵<?= number_format($avgOrderValue, 2) ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- TOP SELLING PRODUCTS -->
        <div class="col-lg-8">
            <div class="content-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0 fw-bold"><i class="bi bi-trophy text-success me-2"></i> Top Selling Products</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Units Sold</th>
                                    <th>Total Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topProducts)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted">No sales data available yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topProducts as $tp): ?>
                                        <tr>
                                            <td class="fw-bold"><?= e($tp["name"]) ?></td>
                                            <td><?= e($tp["category_name"] ?? "General") ?></td>
                                            <td><span class="badge badge-confirmed"><?= number_format($tp["total_sold"]) ?> units</span></td>
                                            <td class="fw-bold text-success">₵<?= number_format((float)$tp["revenue"], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ORDER STATUS BREAKDOWN -->
        <div class="col-lg-4">
            <div class="content-card h-100">
                <div class="card-header">
                    <h5 class="m-0 fw-bold"><i class="bi bi-pie-chart text-success me-2"></i> Order Status Distribution</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($statusBreakdown)): ?>
                        <div class="text-center py-4 text-muted">No order metrics recorded yet.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($statusBreakdown as $sb): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-3">
                                    <span class="fw-semibold"><?= ucfirst(str_replace('_', ' ', $sb["status"])) ?></span>
                                    <span class="badge bg-success rounded-pill px-3 py-2"><?= $sb["count"] ?> orders</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
