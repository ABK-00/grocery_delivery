<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function routeResult(
    bool $ok,
    string $label,
    string $detail = ''
): void {
    global $pass, $fail;

    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }

    echo str_pad(
        $ok ? '[PASS]' : '[FAIL]',
        7
    )
    . ' '
    . $label
    . ($detail !== '' ? ' - ' . $detail : '')
    . PHP_EOL;
}

function sourceText(
    string $root,
    string $path
): string {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $path
        );

    return is_file($file)
        ? (string) file_get_contents($file)
        : '';
}

echo "Somame route isolation audit\n";
echo str_repeat('=', 38) . "\n";

$roleDirs = [
    'admin' => 'admin',
    'staff' => 'staff',
    'delivery' => 'delivery_partner',
    'customer' => 'customer',
    'super_admin' => 'super_admin'
];

$roleExceptions = [
    'admin/login.php',
    'staff/login.php',
    'delivery/login.php',
    'super_admin/login.php',
    'super_admin/create_super_admin.php'
];

foreach ($roleDirs as $dir => $role) {
    $folder =
        $root
        . DIRECTORY_SEPARATOR
        . $dir;

    foreach (
        glob(
            $folder
            . DIRECTORY_SEPARATOR
            . '*.php'
        ) ?: []
        as $file
    ) {
        $relative =
            $dir . '/' . basename($file);

        if (
            in_array(
                $relative,
                $roleExceptions,
                true
            )
        ) {
            continue;
        }

        $content =
            (string) file_get_contents($file);

        $guardSingle =
            "requireRole('" . $role . "')";

        $guardDouble =
            'requireRole("' . $role . '")';

        routeResult(
            str_contains(
                $content,
                $guardSingle
            )
            || str_contains(
                $content,
                $guardDouble
            ),
            $relative . ' role guard'
        );

        if (
            in_array(
                $role,
                [
                    'admin',
                    'staff',
                    'delivery_partner'
                ],
                true
            )
        ) {
            routeResult(
                str_contains(
                    $content,
                    'requireCompanyAccess('
                )
                || str_contains(
                    $content,
                    'requireCompanyAccess();'
                ),
                $relative
                    . ' company boundary'
            );
        }

        $foreignDirs = array_keys(
            array_filter(
                $roleDirs,
                fn(
                    string $otherRole,
                    string $otherDir
                ): bool =>
                    $otherDir !== $dir,
                ARRAY_FILTER_USE_BOTH
            )
        );

        foreach ($foreignDirs as $foreignDir) {
            $lines = preg_split(
                '/\R/',
                $content
            ) ?: [];

            foreach ($lines as $number => $line) {
                if (
                    !preg_match(
                        '/href\s*=|Location\s*:|header\s*\(/i',
                        $line
                    )
                ) {
                    continue;
                }

                if (
                    str_contains(
                        $line,
                        '/' . $foreignDir . '/'
                    )
                    || str_contains(
                        $line,
                        '../' . $foreignDir . '/'
                    )
                ) {
                    routeResult(
                        false,
                        $relative
                            . ' cross-role route',
                        'line '
                            . ($number + 1)
                            . ': '
                            . trim($line)
                    );
                }
            }
        }
    }
}

$portalSidebars = [
    'includes/admin_sidebar.php' =>
        'admin',
    'includes/staff_sidebar.php' =>
        'staff',
    'includes/delivery_sidebar.php' =>
        'delivery',
    'includes/customer_sidebar.php' =>
        'customer',
    'includes/super_admin_sidebar.php' =>
        'super_admin'
];

foreach ($portalSidebars as $path => $portal) {
    $content =
        sourceText(
            $root,
            $path
        );

    foreach ([
        'assistant.php',
        'notifications.php',
        'support.php',
        'logout.php'
    ] as $shared) {
        routeResult(
            str_contains(
                $content,
                '/' . $shared
                . '?portal='
                . $portal
            ),
            $path
                . ' '
                . $shared
                . ' portal binding'
        );
    }
}

foreach ($portalSidebars as $path => $portal) {
    $content =
        sourceText(
            $root,
            $path
        );

    routeResult(
        !str_contains(
            $content,
            '/communications.php?portal='
        ),
        $path
            . ' communications hidden from sidebar'
    );
}

foreach ([
    'assistant.php',
    'communications.php',
    'notifications.php',
    'support.php',
    'support_ticket.php'
] as $path) {
    $content =
        sourceText(
            $root,
            $path
        );

    routeResult(
        str_contains(
            $content,
            'requirePortalContext()'
        ),
        $path
            . ' portal guard'
    );
}

foreach ([
    'api/ai/chat.php',
    'api/ai_chat.php',
    'api/communications.php'
] as $path) {
    $content =
        sourceText(
            $root,
            $path
        );

    routeResult(
        str_contains(
            $content,
            'requirePortalContext()'
        ),
        $path
            . ' AI portal guard'
    );
}

$auth =
    sourceText(
        $root,
        'includes/auth.php'
    );

foreach ([
    'portalKeyForRole',
    'roleForPortalKey',
    'requestedPortalRole',
    'requirePortalContext',
    'portalizeSharedUrl'
] as $helper) {
    routeResult(
        str_contains(
            $auth,
            'function ' . $helper
        ),
        'auth helper ' . $helper
    );
}

$logout =
    sourceText(
        $root,
        'logout.php'
    );

routeResult(
    str_contains(
        $logout,
        'requestedPortalRole()'
    )
    && str_contains(
        $logout,
        '$activeRole !== $expectedRole'
    ),
    'logout stale-tab protection'
);

$contextualPages = [
    'admin/order_details.php',
    'staff/order_details.php',
    'delivery/order_details.php',
    'customer/track_order.php'
];

foreach ($contextualPages as $path) {
    $content =
        sourceText(
            $root,
            $path
        );

    routeResult(
        str_contains(
            $content,
            '/communications.php?portal='
        ),
        $path
            . ' contextual delivery communications links'
    );
}

$adminOrder =
    sourceText(
        $root,
        'admin/order_details.php'
    );

routeResult(
    !str_contains(
        $adminOrder,
        '../delivery/track_delivery.php'
    ),
    'admin tracking stays in admin portal'
);

echo "\n";
echo "Pass={$pass}, Fail={$fail}\n";

exit($fail > 0 ? 1 : 0);
