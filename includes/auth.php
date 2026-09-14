<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header("Location: ../login.php");
        exit;
    }
}

function requireRole($role)
{
    requireLogin();

    if ($_SESSION['user_role'] !== $role) {
        header("Location: ../index.php");
        exit;
    }
}

function redirectByRole()
{
    switch ($_SESSION['user_role']) {

        case 'admin':
            header("Location: admin/dashboard.php");
            break;

        case 'staff':
            header("Location: staff/dashboard.php");
            break;

        case 'customer':
            header("Location: customer/dashboard.php");
            break;

        case 'delivery_partner':
            header("Location: delivery/dashboard.php");
            break;

        default:
            header("Location: index.php");
    }

    exit;
}