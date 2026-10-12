<?php

require_once __DIR__ . '/config/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$role = $_SESSION['role'] ?? '';

$_SESSION = [];

if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

$target = match ($role) {
    'super_admin' =>
        BASE_URL . '/super_admin/login.php',

    'admin' =>
        BASE_URL . '/admin/login.php',

    'staff' =>
        BASE_URL . '/staff/login.php',

    'delivery_partner' =>
        BASE_URL . '/delivery/login.php',

    'customer' =>
        BASE_URL . '/login.php?customer=1',

    default =>
        BASE_URL . '/access.php',
};

header('Location: ' . $target);
exit;
