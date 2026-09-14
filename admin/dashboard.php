<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

// Statistics
$totalUsers = $conn->query("
    SELECT COUNT(*) FROM users
")->fetchColumn();

$totalCustomers = $conn->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'customer'
")->fetchColumn();

$totalStaff = $conn->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'staff'
")->fetchColumn();

$totalDeliveryPartners = $conn->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'delivery_partner'
")->fetchColumn();

$totalOrders = 0;
$totalProducts = 0;
$totalRevenue = 0;

try {
    $totalOrders = $conn->query("
        SELECT COUNT(*) FROM orders
    ")->fetchColumn();
} catch (PDOException $e) {
    $totalOrders = 0;
}

try {
    $totalProducts = $conn->query("
        SELECT COUNT(*) FROM products
    ")->fetchColumn();
} catch (PDOException $e) {
    $totalProducts = 0;
}

try {
    $totalRevenue = $conn->query("
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE status = 'delivered'
    ")->fetchColumn();
} catch (PDOException $e) {
    $totalRevenue = 0;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | Grocery Delivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">    <link
        href="../assets/css/admin.css"
        rel="stylesheet">

    <style>
        .stat-card {
            border: none;
            border-radius: 16px;
            background: white;
            padding: 22px;
            height: 100%;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            background: #dcfce7;
            color: #16a34a;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
        }

        .quick-card {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 25px;
            height: 100%;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
            transition: .2s;
        }

        .quick-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,.08);
        }

        .quick-icon {
            font-size: 30px;
            color: #16a34a;
            margin-bottom: 15px;
        }
    </style>

</head>

<body>

<!-- SIDEBAR -->

<?php require_once __DIR__ . "/../includes/admin_sidebar.php"; ?>


<!-- MAIN CONTENT -->

<main class="main-content">

    <!-- TOP BAR -->

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
                    Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?> 👋
                </h1>

                <p class="topbar-subtitle">
                    Here's what's happening with your grocery delivery system.
                </p>
            </div>

        </div>

        <div>
            <span class="badge bg-success px-3 py-2">
                Super Admin
            </span>
        </div>

    </div>


    <!-- STATISTICS -->

    <div class="row g-4 mb-4">

        <div class="col-lg-3 col-md-6">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>
                        <small class="text-muted">
                            Total Users
                        </small>

                        <div class="stat-number">
                            <?= $totalUsers ?>
                        </div>
                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-3 col-md-6">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>
                        <small class="text-muted">
                            Customers
                        </small>

                        <div class="stat-number">
                            <?= $totalCustomers ?>
                        </div>
                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-person"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-3 col-md-6">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>
                        <small class="text-muted">
                            Staff
                        </small>

                        <div class="stat-number">
                            <?= $totalStaff ?>
                        </div>
                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-3 col-md-6">

            <div class="stat-card">

                <div class="d-flex justify-content-between">

                    <div>
                        <small class="text-muted">
                            Delivery Partners
                        </small>

                        <div class="stat-number">
                            <?= $totalDeliveryPartners ?>
                        </div>
                    </div>

                    <div class="stat-icon">
                        <i class="bi bi-bicycle"></i>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- BUSINESS STATISTICS -->

    <div class="row g-4 mb-4">

        <div class="col-lg-4">

            <div class="stat-card">

                <small class="text-muted">
                    Total Orders
                </small>

                <div class="stat-number mt-2">
                    <?= $totalOrders ?>
                </div>

                <small class="text-success">
                    <i class="bi bi-arrow-up"></i>
                    Orders processed
                </small>

            </div>

        </div>


        <div class="col-lg-4">

            <div class="stat-card">

                <small class="text-muted">
                    Products
                </small>

                <div class="stat-number mt-2">
                    <?= $totalProducts ?>
                </div>

                <small class="text-success">
                    Products in inventory
                </small>

            </div>

        </div>


        <div class="col-lg-4">

            <div class="stat-card">

                <small class="text-muted">
                    Delivered Revenue
                </small>

                <div class="stat-number mt-2">
                    ₵<?= number_format((float)$totalRevenue, 2) ?>
                </div>

                <small class="text-success">
                    Completed orders
                </small>

            </div>

        </div>

    </div>


    <!-- QUICK ACTIONS -->

    <h5 class="mb-3">
        Quick Actions
    </h5>

    <div class="row g-4">

        <div class="col-lg-3 col-md-6">

            <a href="staff.php"
               class="text-decoration-none text-dark">

                <div class="quick-card">

                    <div class="quick-icon">
                        <i class="bi bi-person-plus"></i>
                    </div>

                    <h5>
                        Add Staff
                    </h5>

                    <p class="text-muted mb-0">
                        Create and manage shop staff accounts.
                    </p>

                </div>

            </a>

        </div>


        <div class="col-lg-3 col-md-6">

            <a href="delivery_partners.php"
               class="text-decoration-none text-dark">

                <div class="quick-card">

                    <div class="quick-icon">
                        <i class="bi bi-bicycle"></i>
                    </div>

                    <h5>
                        Add Delivery Partner
                    </h5>

                    <p class="text-muted mb-0">
                        Register drivers and delivery partners.
                    </p>

                </div>

            </a>

        </div>


        <div class="col-lg-3 col-md-6">

            <a href="products.php"
               class="text-decoration-none text-dark">

                <div class="quick-card">

                    <div class="quick-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <h5>
                        Manage Products
                    </h5>

                    <p class="text-muted mb-0">
                        Add, edit and manage grocery products.
                    </p>

                </div>

            </a>

        </div>


        <div class="col-lg-3 col-md-6">

            <a href="orders.php"
               class="text-decoration-none text-dark">

                <div class="quick-card">

                    <div class="quick-icon">
                        <i class="bi bi-receipt"></i>
                    </div>

                    <h5>
                        View Orders
                    </h5>

                    <p class="text-muted mb-0">
                        Monitor and manage customer orders.
                    </p>

                </div>

            </a>

        </div>

    </div>

</main>

</body>
</html>