<?php

/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| APPLICATION BASE URL
|--------------------------------------------------------------------------
|
| Your project is:
| C:\xampppp\htdocs\somame_ent
|
| Browser:
| http://localhost/somame_ent/
|--------------------------------------------------------------------------
*/

if (!defined('BASE_URL')) {
    define('BASE_URL', '/somame_ent');
}


/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/

function isLoggedIn(): bool
{
    return isset(
        $_SESSION['user_id'],
        $_SESSION['role']
    );
}


/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

function requireLogin(): void
{
    if (!isLoggedIn()) {

        header(
            'Location: '
                . BASE_URL
                . '/login.php'
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| REQUIRE ROLE
|--------------------------------------------------------------------------
*/

function roleLoginUrl(string $role): string
{
    return match ($role) {
        'super_admin' => BASE_URL . '/super_admin/login.php',
        'admin' => BASE_URL . '/admin/login.php',
        'staff' => BASE_URL . '/staff/login.php',
        'delivery_partner' => BASE_URL . '/delivery/login.php',
        'customer' => BASE_URL . '/login.php?customer=1',
        default => BASE_URL . '/login.php?customer=1',
    };
}


function requireRole(string $role): void
{
    if (!isLoggedIn()) {
        header(
            'Location: '
                . roleLoginUrl($role)
        );
        exit;
    }

    if (
        !isset($_SESSION['role'])
        || $_SESSION['role'] !== $role
    ) {
        redirectByRole();
    }
}


/*
|--------------------------------------------------------------------------
| CURRENT USER ID
|--------------------------------------------------------------------------
*/

function currentUserId(): ?int
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    return (int)$_SESSION['user_id'];
}


/*
|--------------------------------------------------------------------------
| CURRENT COMPANY ID
|--------------------------------------------------------------------------
|
| Returns NULL for:
|
| - super_admin
| - customer
|
| Returns company ID for:
|
| - admin
| - staff
| - delivery_partner
|--------------------------------------------------------------------------
*/

function currentCompanyId(): ?int
{
    if (
        !isset($_SESSION['company_id'])
        || $_SESSION['company_id'] === null
        || $_SESSION['company_id'] === ''
    ) {
        return null;
    }

    return (int)$_SESSION['company_id'];
}


/*
|--------------------------------------------------------------------------
| REQUIRE COMPANY ACCESS
|--------------------------------------------------------------------------
|
| Only company-side users should call this.
|--------------------------------------------------------------------------
*/

function requireCompanyAccess(): void
{
    requireLogin();

    $companyRoles = [
        'admin',
        'staff',
        'delivery_partner'
    ];

    $role =
        $_SESSION['role']
        ?? '';

    if (
        !in_array(
            $role,
            $companyRoles,
            true
        )
    ) {

        redirectByRole();
    }


    if (!currentCompanyId()) {

        session_unset();
        session_destroy();

        header(
            'Location: '
                . roleLoginUrl($role)
                . (str_contains(roleLoginUrl($role), '?') ? '&' : '?')
                . 'error=company'
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| REDIRECT BY ROLE
|--------------------------------------------------------------------------
*/

function redirectByRole(): void
{
    if (!isLoggedIn()) {

        header(
            'Location: '
                . BASE_URL
                . '/login.php'
        );

        exit;
    }


    $role =
        $_SESSION['role']
        ?? '';


    switch ($role) {

        case 'super_admin':

            header(
                'Location: '
                    . BASE_URL
                    . '/super_admin/dashboard.php'
            );

            exit;


        case 'admin':

            header(
                'Location: '
                    . BASE_URL
                    . '/admin/dashboard.php'
            );

            exit;


        case 'staff':

            header(
                'Location: '
                    . BASE_URL
                    . '/staff/dashboard.php'
            );

            exit;


        case 'delivery_partner':

            header(
                'Location: '
                    . BASE_URL
                    . '/delivery/dashboard.php'
            );

            exit;


        case 'customer':

            /*
             * IMPORTANT:
             *
             * Customers are marketplace users.
             * They DO NOT need company_id.
             */
            header(
                'Location: '
                    . BASE_URL
                    . '/marketplace.php'
            );

            exit;


        default:

            session_unset();
            session_destroy();

            header(
                'Location: '
                    . BASE_URL
                    . '/login.php'
            );

            exit;
    }
}
