<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';


/*
|--------------------------------------------------------------------------
| AUTHORITATIVE SESSION SYNC
|--------------------------------------------------------------------------
| Protected pages must not trust stale role/company information from the
| session. When a database connection is available, refresh the authenticated
| identity from users/companies before applying role checks.
|--------------------------------------------------------------------------
*/

function clearAuthenticatedSession(): void
{
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

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}


function syncAuthenticatedSession(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    global $conn;

    if (
        !isset($conn)
        || !($conn instanceof PDO)
    ) {
        return isset($_SESSION['role']);
    }

    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.company_id,
            u.name,
            u.email,
            u.role,
            u.status,
            c.company_name,
            c.status AS company_status
        FROM users u
        LEFT JOIN companies c
            ON c.id = u.company_id
        WHERE u.id = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int) $_SESSION['user_id']
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$user
        || $user['status'] !== 'active'
    ) {
        clearAuthenticatedSession();
        return false;
    }

    $role = (string) $user['role'];

    $companyRoles = [
        'admin',
        'staff',
        'delivery_partner'
    ];

    if (
        in_array($role, $companyRoles, true)
        && (
            empty($user['company_id'])
            || $user['company_status'] !== 'active'
        )
    ) {
        clearAuthenticatedSession();
        return false;
    }

    $_SESSION['user_id'] =
        (int) $user['id'];

    $_SESSION['name'] =
        (string) $user['name'];

    $_SESSION['user_name'] =
        (string) $user['name'];

    $_SESSION['email'] =
        (string) $user['email'];

    $_SESSION['role'] =
        $role;

    $_SESSION['company_id'] =
        in_array($role, $companyRoles, true)
            ? (int) $user['company_id']
            : null;

    if (
        in_array($role, $companyRoles, true)
        && !empty($user['company_name'])
    ) {
        $_SESSION['company_name'] =
            (string) $user['company_name'];
    } else {
        unset($_SESSION['company_name']);
    }

    return true;
}


function roleDashboardUrl(string $role): string
{
    return match ($role) {
        'super_admin' =>
            BASE_URL . '/super_admin/dashboard.php',

        'admin' =>
            BASE_URL . '/admin/dashboard.php',

        'staff' =>
            BASE_URL . '/staff/dashboard.php',

        'delivery_partner' =>
            BASE_URL . '/delivery/dashboard.php',

        'customer' =>
            BASE_URL . '/customer/dashboard.php',

        default =>
            BASE_URL . '/access.php',
    };
}



function portalKeyForRole(string $role): string
{
    return match ($role) {
        'super_admin' =>
            'super_admin',

        'admin' =>
            'admin',

        'staff' =>
            'staff',

        'delivery_partner' =>
            'delivery',

        'customer' =>
            'customer',

        default =>
            '',
    };
}


function roleForPortalKey(string $portal): ?string
{
    $portal = strtolower(
        trim($portal)
    );

    return match ($portal) {
        'super_admin' =>
            'super_admin',

        'admin' =>
            'admin',

        'staff' =>
            'staff',

        'delivery' =>
            'delivery_partner',

        'customer' =>
            'customer',

        default =>
            null,
    };
}


function requestedPortalRole(): ?string
{
    $portal =
        (string) (
            $_GET['portal']
            ?? $_POST['portal']
            ?? ''
        );

    if ($portal === '') {
        return null;
    }

    return roleForPortalKey(
        $portal
    );
}


function requirePortalContext(
    ?string $fallbackRole = null
): string {
    $expectedRole =
        requestedPortalRole()
        ?? $fallbackRole;

    if ($expectedRole === null) {
        $expectedRole =
            (string) (
                $_SESSION['role']
                ?? ''
            );
    }

    if (
        $expectedRole === ''
        || roleForPortalKey(
            portalKeyForRole(
                $expectedRole
            )
        ) === null
    ) {
        header(
            'Location: '
            . BASE_URL
            . '/access.php'
        );
        exit;
    }

    if (
        !isLoggedIn()
        || !syncAuthenticatedSession()
    ) {
        header(
            'Location: '
            . roleLoginUrl(
                $expectedRole
            )
            . (
                str_contains(
                    roleLoginUrl($expectedRole),
                    '?'
                )
                    ? '&'
                    : '?'
            )
            . 'error=portal'
        );
        exit;
    }

    $actualRole =
        (string) (
            $_SESSION['role']
            ?? ''
        );

    if ($actualRole !== $expectedRole) {
        header(
            'Location: '
            . roleLoginUrl(
                $expectedRole
            )
            . (
                str_contains(
                    roleLoginUrl($expectedRole),
                    '?'
                )
                    ? '&'
                    : '?'
            )
            . 'error=role_mismatch'
        );
        exit;
    }

    return $expectedRole;
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
    if (
        !isLoggedIn()
        || !syncAuthenticatedSession()
    ) {
        header(
            'Location: '
                . BASE_URL
                . '/access.php'
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
}


function requireRole(string $role): void
{
    if (
        !isLoggedIn()
        || !syncAuthenticatedSession()
    ) {
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

function requireCompanyAccess(
    bool $allowExpiredAdmin = false
): void
{
    requireLogin();

    global $conn;

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


    $companyId = currentCompanyId();

    if (!$companyId) {

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

    if (
        isset($conn)
        && $conn instanceof PDO
    ) {
        $stmt = $conn->prepare("
            SELECT
                status,
                subscription_plan,
                trial_ends_at,
                subscription_ends_at
            FROM companies
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$companyId]);
        $company = $stmt->fetch();

        if (
            !$company
            || $company['status'] !== 'active'
        ) {
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

        $subscriptionValid = false;

        if ($company['subscription_plan'] === 'trial') {
            $subscriptionValid =
                !empty($company['trial_ends_at'])
                && strtotime(
                    (string) $company['trial_ends_at']
                ) >= time();
        } elseif (
            in_array(
                $company['subscription_plan'],
                ['monthly', 'quarterly', 'yearly'],
                true
            )
        ) {
            $subscriptionValid =
                !empty($company['subscription_ends_at'])
                && strtotime(
                    (string) $company['subscription_ends_at']
                ) >= time();
        }

        if (!$subscriptionValid) {
            if (
                $role === 'admin'
                && $allowExpiredAdmin
            ) {
                return;
            }

            if ($role === 'admin') {
                header(
                    'Location: '
                        . BASE_URL
                        . '/admin/subscription.php?expired=1'
                );
                exit;
            }

            session_unset();
            session_destroy();

            header(
                'Location: '
                    . roleLoginUrl($role)
                    . (str_contains(roleLoginUrl($role), '?') ? '&' : '?')
                    . 'error=subscription'
            );
            exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| REDIRECT BY ROLE
|--------------------------------------------------------------------------
*/

function redirectByRole(): void
{
    if (
        !isLoggedIn()
        || !syncAuthenticatedSession()
    ) {
        header(
            'Location: '
                . BASE_URL
                . '/access.php'
        );
        exit;
    }

    $role =
        (string) ($_SESSION['role'] ?? '');

    header(
        'Location: '
            . roleDashboardUrl($role)
    );
    exit;
}
