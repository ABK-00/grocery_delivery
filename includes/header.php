<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($page_title ?? "Grocery Delivery") ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/grocery_delivery/assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
        <div class="container"><a class="navbar-brand fw-bold" href="/grocery_delivery/">GroceryGo</a>
            <div class="ms-auto"><?php if (!empty($_SESSION["user_id"])): ?><span class="text-white me-3">Hi, <?= e($_SESSION["name"] ?? "User") ?></span><a class="btn btn-outline-light btn-sm" href="/grocery_delivery/logout.php">Logout</a><?php else: ?><a class="btn btn-light btn-sm" href="/grocery_delivery/login.php">Login</a><?php endif; ?></div>
        </div>
    </nav>
    <main class="container py-4">