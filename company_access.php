<?php
session_start();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Company Access | GroceryDelivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <main class="container py-5">
        <div class="card border-0 shadow-sm mx-auto" style="max-width:620px">
            <div class="card-body p-5 text-center">
                <h2 class="fw-bold">Company access unavailable</h2>
                <p class="text-muted">This company is suspended, blocked, or its subscription is no longer active. Please contact your company administrator or GroceryDelivery platform administrator.</p><a href="logout.php" class="btn btn-success">Return to login</a>
            </div>
        </div>
    </main>
</body>

</html>