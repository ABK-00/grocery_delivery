<?php

require_once __DIR__ . "/../includes/auth.php";

requireRole("customer");

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Customer Dashboard</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

</head>

<body>

<nav class="navbar navbar-dark bg-success">

    <div class="container">

        <span class="navbar-brand">
            Grocery Delivery
        </span>

        <div class="text-white">

            <?php echo htmlspecialchars($_SESSION["user_name"]); ?>

            <a
                href="../logout.php"
                class="btn btn-light btn-sm ms-3">

                Logout

            </a>

        </div>

    </div>

</nav>

<div class="container py-5">

    <h1>
        Welcome,
        <?php echo htmlspecialchars($_SESSION["user_name"]); ?> 👋
    </h1>

    <p class="text-muted">
        Your customer dashboard is ready.
    </p>

    <div class="row g-4 mt-3">

        <div class="col-md-4">

            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <h5>🛒 Browse Groceries</h5>

                    <p>
                        Browse available products and add them to your cart.
                    </p>

                </div>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <h5>📦 My Orders</h5>

                    <p>
                        View and track your grocery orders.
                    </p>

                </div>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <h5>📍 Live Tracking</h5>

                    <p>
                        Track your delivery partner in real time.
                    </p>

                </div>

            </div>

        </div>

    </div>

</div>

</body>
</html>