<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('customer');

$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Customer';

/*
|--------------------------------------------------------------------------
| Customer statistics
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM orders
    WHERE user_id = ?
");

$stmt->execute([$userId]);

$totalOrders = (int)$stmt->fetchColumn();


$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM orders
    WHERE user_id = ?
    AND status NOT IN ('delivered', 'cancelled')
");

$stmt->execute([$userId]);

$activeOrders = (int)$stmt->fetchColumn();


$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM orders
    WHERE user_id = ?
    AND status = 'delivered'
");

$stmt->execute([$userId]);

$completedOrders = (int)$stmt->fetchColumn();


$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE user_id = ?
    AND payment_status = 'paid'
");

$stmt->execute([$userId]);

$totalSpent = (float)$stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| Cart count
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(quantity), 0)
    FROM cart
    WHERE user_id = ?
");

$stmt->execute([$userId]);

$cartItems = (float)$stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| Recent orders
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        order_number,
        total_amount,
        status,
        payment_status,
        created_at
    FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");

$stmt->execute([$userId]);

$recentOrders = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Active delivery
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        order_number,
        status,
        total_amount
    FROM orders
    WHERE user_id = ?
    AND status IN (
        'confirmed',
        'preparing',
        'ready',
        'out_for_delivery'
    )
    ORDER BY created_at DESC
    LIMIT 1
");

$stmt->execute([$userId]);

$activeDelivery = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| Recommended / latest products
|--------------------------------------------------------------------------
*/

$stmt = $conn->query("
    SELECT
        p.id,
        p.name,
        p.price,
        p.stock,
        p.unit,
        p.image,
        c.name AS category_name,

        (
            SELECT pi.image
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
            LIMIT 1
        ) AS gallery_image

    FROM products p

    LEFT JOIN categories c
        ON c.id = p.category_id

    WHERE p.status = 'active'

    ORDER BY p.created_at DESC

    LIMIT 4
");

$products = $stmt->fetchAll();


function customerProductImage($product)
{
    if (!empty($product['gallery_image'])) {
        return '../assets/images/products/' .
            $product['gallery_image'];
    }

    if (!empty($product['image'])) {
        return '../assets/images/products/' .
            $product['image'];
    }

    return '../assets/images/product-placeholder.jpg';
}


function orderStatusClass($status)
{
    switch ($status) {

        case 'pending':
            return 'warning';

        case 'confirmed':
            return 'primary';

        case 'preparing':
            return 'info';

        case 'ready':
            return 'secondary';

        case 'out_for_delivery':
            return 'success';

        case 'delivered':
            return 'success';

        case 'cancelled':
            return 'danger';

        default:
            return 'secondary';
    }
}


function orderStatusLabel($status)
{
    return ucwords(
        str_replace('_', ' ', $status)
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Customer Dashboard | GroceryDelivery</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="../assets/css/admin.css"
        rel="stylesheet"
    >

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            background: #f4f7f6;
        }

        .notification-btn {
            width: 42px;
            height: 42px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            color: #475569;
            cursor: pointer;
        }

        .welcome-card {
            background:
                linear-gradient(
                    135deg,
                    #0f172a,
                    #163c2c
                );
            color: white;
            border-radius: 18px;
            padding: 30px;
            position: relative;
            overflow: hidden;
            margin-bottom: 25px;
        }

        .welcome-card::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            right: -80px;
            top: -100px;
            border-radius: 50%;
            background: rgba(25, 135, 84, .25);
        }

        .welcome-card h2 {
            font-weight: 750;
            margin-bottom: 8px;
        }

        .welcome-card p {
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        .shop-btn {
            background: #198754;
            border: none;
            border-radius: 9px;
            padding: 11px 18px;
            font-weight: 600;
            color: white;
        }

        .shop-btn:hover {
            background: #157347;
            color: white;
        }

        .stat-card {
            background: white;
            border: 1px solid #e8edf3;
            border-radius: 15px;
            padding: 20px;
            height: 100%;
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #eaf7f0;
            color: #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 14px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 750;
            color: #0f172a;
            margin-top: 2px;
        }

        .section-card {
            background: white;
            border: 1px solid #e8edf3;
            border-radius: 15px;
            padding: 22px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .section-header h5 {
            margin: 0;
            font-weight: 700;
            color: #0f172a;
        }

        .section-header a {
            color: #198754;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .order-row {
            padding: 14px 0;
            border-bottom: 1px solid #edf1f5;
        }

        .order-row:last-child {
            border-bottom: none;
        }

        .order-number {
            font-weight: 650;
            color: #0f172a;
        }

        .order-date {
            color: #94a3b8;
            font-size: 12px;
        }

        .order-amount {
            font-weight: 700;
            color: #0f172a;
        }

        .delivery-card {
            background:
                linear-gradient(
                    135deg,
                    #eaf7f0,
                    #f7fbf9
                );
            border: 1px solid #ccebd9;
            border-radius: 15px;
            padding: 22px;
        }

        .delivery-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: #198754;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
        }

        .product-card {
            border: 1px solid #e8edf3;
            border-radius: 14px;
            overflow: hidden;
            background: white;
            height: 100%;
            transition: .2s;
        }

        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(15,23,42,.08);
        }

        .product-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
            background: #f1f5f9;
        }

        .product-body {
            padding: 15px;
        }

        .product-category {
            font-size: 11px;
            color: #198754;
            font-weight: 650;
        }

        .product-name {
            margin-top: 5px;
            font-weight: 650;
            color: #0f172a;
        }

        .product-price {
            font-size: 17px;
            font-weight: 750;
            color: #198754;
        }

        .cart-widget {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: #fff;
            border: 1px solid #e8edf3;
            border-radius: 14px;
            margin-top: 20px;
        }

        .cart-widget-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: #fff4dc;
            color: #d99000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @media(max-width: 768px) {
            .welcome-card { padding: 24px; }
        }

    </style>

    <link href="../assets/css/customer.css" rel="stylesheet">
</head>

<body>

<?php include __DIR__ . '/../includes/loader.php'; ?>

<?php include __DIR__ . '/../includes/customer_sidebar.php'; ?>

<main class="main">

    <!-- =====================================================
         TOPBAR
         ===================================================== -->

    <div class="topbar">

        <div class="topbar-left">

            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="topbar-title">Customer Dashboard</h1>
                <p class="topbar-subtitle">Manage your groceries, orders and deliveries.</p>
            </div>

        </div>

        <button class="notification-btn" title="Notifications">
            <i class="bi bi-bell"></i>
        </button>

    </div>


    <!-- Welcome -->

    <div class="welcome-card">

        <h2>
            Welcome back, <?= htmlspecialchars($userName) ?> 👋
        </h2>

        <p>
            Ready to get your groceries delivered?
            Browse our products and place your next order.
        </p>

        <a
            href="../products.php"
            class="btn shop-btn"
        >
            <i class="bi bi-basket2 me-2"></i>
            Start Shopping
        </a>

    </div>


    <!-- Statistics -->

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-bag-check"></i>
                </div>

                <div class="stat-label">
                    Total Orders
                </div>

                <div class="stat-value">
                    <?= number_format($totalOrders) ?>
                </div>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-truck"></i>
                </div>

                <div class="stat-label">
                    Active Orders
                </div>

                <div class="stat-value">
                    <?= number_format($activeOrders) ?>
                </div>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-check2-circle"></i>
                </div>

                <div class="stat-label">
                    Completed Orders
                </div>

                <div class="stat-value">
                    <?= number_format($completedOrders) ?>
                </div>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-wallet2"></i>
                </div>

                <div class="stat-label">
                    Total Spent
                </div>

                <div class="stat-value">
                    GHS <?= number_format($totalSpent, 2) ?>
                </div>

            </div>

        </div>

    </div>


    <div class="row g-4">

        <!-- Left -->

        <div class="col-lg-8">


            <!-- Active delivery -->

            <?php if ($activeDelivery): ?>

                <div class="delivery-card mb-4">

                    <div class="d-flex align-items-center">

                        <div class="delivery-icon">
                            <i class="bi bi-truck"></i>
                        </div>

                        <div class="ms-3">

                            <div class="small text-muted">
                                Active Order
                            </div>

                            <h5 class="mb-1 fw-bold">
                                <?= htmlspecialchars(
                                    $activeDelivery['order_number']
                                ) ?>
                            </h5>

                            <span class="badge text-bg-success">
                                <?= htmlspecialchars(
                                    orderStatusLabel(
                                        $activeDelivery['status']
                                    )
                                ) ?>
                            </span>

                        </div>

                    </div>

                    <div class="mt-3">

                        <a
                            href="track_order.php?order=<?= $activeDelivery['id'] ?>"
                            class="btn btn-success"
                        >
                            <i class="bi bi-geo-alt me-1"></i>
                            Track My Order
                        </a>

                        <a
                            href="../order_details.php?id=<?= $activeDelivery['id'] ?>"
                            class="btn btn-outline-secondary ms-2"
                        >
                            View Details
                        </a>

                    </div>

                </div>

            <?php endif; ?>


            <!-- Recent orders -->

            <div class="section-card mb-4">

                <div class="section-header">

                    <h5>
                        Recent Orders
                    </h5>

                    <a href="../orders.php">
                        View All
                    </a>

                </div>


                <?php if (!$recentOrders): ?>

                    <div class="text-center py-5">

                        <i
                            class="bi bi-bag-x"
                            style="font-size:40px;color:#94a3b8;"
                        ></i>

                        <p class="text-muted mt-3 mb-3">
                            You haven't placed any orders yet.
                        </p>

                        <a
                            href="../products.php"
                            class="btn btn-success"
                        >
                            Start Shopping
                        </a>

                    </div>

                <?php else: ?>

                    <?php foreach ($recentOrders as $order): ?>

                        <div class="order-row">

                            <div class="row align-items-center">

                                <div class="col-md-4">

                                    <div class="order-number">
                                        <?= htmlspecialchars(
                                            $order['order_number']
                                        ) ?>
                                    </div>

                                    <div class="order-date">
                                        <?= date(
                                            'M d, Y • h:i A',
                                            strtotime(
                                                $order['created_at']
                                            )
                                        ) ?>
                                    </div>

                                </div>


                                <div class="col-md-3 mt-2 mt-md-0">

                                    <span class="badge text-bg-<?=
                                        orderStatusClass(
                                            $order['status']
                                        )
                                    ?>">

                                        <?= htmlspecialchars(
                                            orderStatusLabel(
                                                $order['status']
                                            )
                                        ) ?>

                                    </span>

                                </div>


                                <div class="col-md-2 mt-2 mt-md-0">

                                    <?php if (
                                        $order['payment_status'] === 'paid'
                                    ): ?>

                                        <span class="text-success small">
                                            <i class="bi bi-check-circle"></i>
                                            Paid
                                        </span>

                                    <?php else: ?>

                                        <span class="text-warning small">
                                            <i class="bi bi-clock"></i>
                                            Payment Pending
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <div class="col-md-2 text-md-end mt-2 mt-md-0">

                                    <div class="order-amount">
                                        GHS
                                        <?= number_format(
                                            $order['total_amount'],
                                            2
                                        ) ?>
                                    </div>

                                </div>


                                <div class="col-md-1 text-md-end mt-2 mt-md-0">

                                    <a
                                        href="../order_details.php?id=<?= $order['id'] ?>"
                                        class="btn btn-sm btn-light"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>


            <!-- Latest products -->

            <div class="section-card">

                <div class="section-header">

                    <h5>
                        Shop Our Latest Products
                    </h5>

                    <a href="../products.php">
                        Browse All
                    </a>

                </div>


                <div class="row g-3">

                    <?php foreach ($products as $product): ?>

                        <div class="col-sm-6 col-xl-3">

                            <div class="product-card">

                                <a
                                    href="../product.php?id=<?= $product['id'] ?>"
                                    class="text-decoration-none"
                                >

                                    <img
                                        src="<?= htmlspecialchars(
                                            customerProductImage(
                                                $product
                                            )
                                        ) ?>"
                                        class="product-image"
                                        alt="<?= htmlspecialchars(
                                            $product['name']
                                        ) ?>"
                                    >

                                </a>

                                <div class="product-body">

                                    <div class="product-category">
                                        <?= htmlspecialchars(
                                            $product['category_name']
                                            ?? 'Grocery'
                                        ) ?>
                                    </div>

                                    <div class="product-name">
                                        <?= htmlspecialchars(
                                            $product['name']
                                        ) ?>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-3">

                                        <div class="product-price">

                                            GHS
                                            <?= number_format(
                                                $product['price'],
                                                2
                                            ) ?>

                                        </div>

                                        <a
                                            href="../product.php?id=<?= $product['id'] ?>"
                                            class="btn btn-sm btn-outline-success"
                                        >
                                            <i class="bi bi-cart-plus"></i>
                                        </a>

                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>


        <!-- Right -->

        <div class="col-lg-4">


            <!-- Cart -->

            <div class="section-card">

                <div class="section-header">

                    <h5>
                        My Cart
                    </h5>

                    <i class="bi bi-cart3 text-success"></i>

                </div>


                <div class="cart-widget">

                    <div class="d-flex align-items-center">

                        <div class="cart-widget-icon">

                            <i class="bi bi-cart3"></i>

                        </div>

                        <div class="ms-3">

                            <div class="fw-bold">
                                <?= number_format($cartItems, 0) ?>
                                item<?= $cartItems == 1 ? '' : 's' ?>
                            </div>

                            <div class="small text-muted">
                                Ready for checkout
                            </div>

                        </div>

                    </div>

                    <a
                        href="../cart.php"
                        class="btn btn-sm btn-success"
                    >
                        View
                    </a>

                </div>

            </div>


            <!-- Quick actions -->

            <div class="section-card mt-4">

                <div class="section-header">

                    <h5>
                        Quick Actions
                    </h5>

                </div>


                <div class="d-grid gap-2">

                    <a
                        href="../products.php"
                        class="btn btn-light text-start"
                    >
                        <i class="bi bi-shop text-success me-2"></i>
                        Browse Products
                    </a>

                    <a
                        href="../cart.php"
                        class="btn btn-light text-start"
                    >
                        <i class="bi bi-cart3 text-success me-2"></i>
                        View Cart
                    </a>

                    <a
                        href="../orders.php"
                        class="btn btn-light text-start"
                    >
                        <i class="bi bi-bag-check text-success me-2"></i>
                        My Orders
                    </a>

                    <a
                        href="profile.php"
                        class="btn btn-light text-start"
                    >
                        <i class="bi bi-person text-success me-2"></i>
                        My Profile
                    </a>

                </div>

            </div>


            <!-- Shopping message -->

            <div class="section-card mt-4">

                <div class="text-center py-2">

                    <div
                        class="stat-icon mx-auto mb-3"
                        style="width:55px;height:55px;"
                    >
                        <i class="bi bi-basket2"></i>
                    </div>

                    <h6 class="fw-bold">
                        Fresh groceries, delivered.
                    </h6>

                    <p class="small text-muted mb-3">
                        Shop your everyday essentials
                        and have them delivered to your door.
                    </p>

                    <a
                        href="../products.php"
                        class="btn btn-success w-100"
                    >
                        Shop Now
                    </a>

                </div>

            </div>

        </div>

    </div>

</main>

</body>
</html>