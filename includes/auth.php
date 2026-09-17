<?php

/*
|--------------------------------------------------------------------------
| Start Session
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| Require Login
|--------------------------------------------------------------------------
|
| Makes sure the user is logged in before accessing protected pages.
|
*/

function require_login()
{
    if (empty($_SESSION["user_id"])) {

        header("Location: /grocery_delivery/login.php");

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Require Role
|--------------------------------------------------------------------------
|
| Allows access only to users with the specified role(s).
|
| Example:
|
| require_role("admin");
|
| or:
|
| require_role(["admin", "staff"]);
|
*/

function require_role($roles)
{
    require_login();

    $roles = (array) $roles;

    $current_role = $_SESSION["role"] ?? "";

    if (!in_array($current_role, $roles, true)) {

        http_response_code(403);

        exit("Access denied.");
    }
}


/*
|--------------------------------------------------------------------------
| Optional Helper: Check Role
|--------------------------------------------------------------------------
*/

function has_role($role)
{
    if (empty($_SESSION["role"])) {
        return false;
    }

    return $_SESSION["role"] === $role;
}


/*
|--------------------------------------------------------------------------
| Optional Helper: Check Login
|--------------------------------------------------------------------------
*/

function is_logged_in()
{
    return !empty($_SESSION["user_id"]);
}