<?php

require_once __DIR__ . '/includes/auth.php';

$expectedRole =
    requestedPortalRole();

$activeRole =
    (string) ($_SESSION['role'] ?? '');

if (
    $expectedRole !== null
    && $activeRole !== ''
    && $activeRole !== $expectedRole
) {
    header(
        'Location: '
        . roleLoginUrl($expectedRole)
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

$role =
    $expectedRole
    ?? $activeRole;

clearAuthenticatedSession();

header(
    'Location: '
    . (
        $role !== ''
            ? roleLoginUrl($role)
            : BASE_URL . '/access.php'
    )
);
exit;
