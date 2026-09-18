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

function currentCompanyId()
{
    return isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : null;
}

function isSuperAdmin()
{
    return ($_SESSION['user_role'] ?? null) === 'super_admin';
}

function requireCompanyAccess()
{
    if (isSuperAdmin()) {
        return;
    }

    if (empty($_SESSION['company_id'])) {
        session_unset();
        session_destroy();
        header("Location: ../login.php?access=company");
        exit;
    }

    if (isset($_SESSION['company_access']) && $_SESSION['company_access'] !== 'active') {
        header("Location: ../company_access.php");
        exit;
    }
}

function redirectByRole()
{
    switch ($_SESSION['user_role']) {

        case 'super_admin':
            header("Location: super_admin/dashboard.php");
            break;

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