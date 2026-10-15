<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function resultLine(
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

function fileText(
    string $root,
    string $path
): string {
    $full = $root . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $path);

    if (!is_file($full)) {
        return '';
    }

    return (string) file_get_contents($full);
}

function expectContains(
    string $root,
    string $path,
    array $needles,
    string $label
): void {
    $content = fileText($root, $path);

    if ($content === '') {
        resultLine(
            false,
            $label,
            'file missing or empty: ' . $path
        );
        return;
    }

    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            resultLine(
                false,
                $label,
                'missing ' . $needle
            );
            return;
        }
    }

    resultLine(true, $label);
}

echo "Somame role/access matrix audit\n";
echo str_repeat('=', 40) . "\n";

$roleDirectories = [
    'admin' => 'admin',
    'staff' => 'staff',
    'delivery' => 'delivery_partner',
    'customer' => 'customer',
    'super_admin' => 'super_admin',
];

$roleExceptions = [
    'admin/login.php',
    'staff/login.php',
    'delivery/login.php',
    'super_admin/login.php',
    'super_admin/create_super_admin.php',
];

foreach ($roleDirectories as $directory => $role) {
    $dir = $root . DIRECTORY_SEPARATOR . $directory;

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
        $relative =
            $directory . '/' . basename($file);

        if (in_array($relative, $roleExceptions, true)) {
            continue;
        }

        $content =
            (string) file_get_contents($file);

        $expected =
            "requireRole('" . $role . "')";

        $expectedDouble =
            'requireRole("' . $role . '")';

        resultLine(
            str_contains($content, $expected)
                || str_contains($content, $expectedDouble),
            $relative . ' role guard',
            'expected role=' . $role
        );

        if (
            in_array(
                $role,
                ['admin', 'staff', 'delivery_partner'],
                true
            )
        ) {
            resultLine(
                str_contains(
                    $content,
                    'requireCompanyAccess('
                )
                || str_contains(
                    $content,
                    'requireCompanyAccess();'
                ),
                $relative . ' company boundary'
            );
        }
    }
}

$customerRootPages = [
    'cart.php',
    'checkout.php',
    'marketplace.php',
    'order_details.php',
    'order_success.php',
    'orders.php',
    'payment_callback.php',
    'product.php',
    'products.php',
    'store.php',
];

foreach ($customerRootPages as $path) {
    expectContains(
        $root,
        $path,
        ["requireRole('customer')"],
        $path . ' customer-only access'
    );
}

foreach ([
    'assistant.php',
    'notifications.php',
    'support.php',
    'support_ticket.php'
] as $path) {
    expectContains(
        $root,
        $path,
        ['requireLogin();'],
        $path . ' authenticated access'
    );
}

$apiRules = [
    'api/add_to_cart.php' =>
        "requireRole('customer')",
    'api/get_delivery_location.php' =>
        "requireRole('customer')",
    'api/get_admin_delivery_location.php' =>
        "requireRole('admin')",
    'api/place_order.php' =>
        "requireRole('customer')",
    'api/remove_from_cart.php' =>
        "requireRole('customer')",
    'api/reorder.php' =>
        "requireRole('customer')",
    'api/start_payment.php' =>
        "requireRole('customer')",
    'api/start_subscription_payment.php' =>
        "requireRole('admin')",
    'api/update_cart.php' =>
        "requireRole('customer')",
    'api/update_delivery_location.php' =>
        "requireRole('delivery_partner')",
];

foreach ($apiRules as $path => $guard) {
    expectContains(
        $root,
        $path,
        [$guard],
        $path . ' endpoint guard'
    );
}

$allPhp = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS
    )
);

foreach ($iterator as $file) {
    if (
        $file->isFile()
        && strtolower($file->getExtension()) === 'php'
    ) {
        $allPhp[] = $file->getPathname();
    }
}

$legacyRequireRole = [];
$wrongTrackingLinks = [];

foreach ($allPhp as $file) {
    $content =
        (string) file_get_contents($file);

    $relative = str_replace(
        '\\',
        '/',
        substr($file, strlen($root) + 1)
    );

    if (str_contains($content, 'require_role(')) {
        $legacyRequireRole[] = $relative;
    }

    if (
        str_contains(
            $content,
            'track_order.php?order_id='
        )
    ) {
        $wrongTrackingLinks[] = $relative;
    }
}

resultLine(
    $legacyRequireRole === [],
    'no legacy require_role() calls',
    $legacyRequireRole
        ? implode(', ', $legacyRequireRole)
        : ''
);

resultLine(
    $wrongTrackingLinks === [],
    'no stale customer tracking parameter',
    $wrongTrackingLinks
        ? implode(', ', $wrongTrackingLinks)
        : ''
);

$auth = fileText(
    $root,
    'includes/auth.php'
);

resultLine(
    str_contains(
        $auth,
        'function syncAuthenticatedSession()'
    )
    && str_contains(
        $auth,
        'function roleDashboardUrl'
    ),
    'central authoritative auth routing'
);

$staffOrder = fileText(
    $root,
    'staff/order_details.php'
);

resultLine(
    str_contains(
        $staffOrder,
        "'Delivery assignment confirmed'"
    )
    && str_contains(
        $staffOrder,
        '/customer/track_order.php?order='
    ),
    'delivery assignment notification contract'
);

echo "\n";
echo "Pass={$pass}, Fail={$fail}\n";

exit($fail > 0 ? 1 : 0);
