<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/AI/AIToolExecutor.php';
require_once __DIR__ . '/../app/Services/AI/AIPermissions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$pass = 0;
$fail = 0;
$skip = 0;

function lineResult(
    string $status,
    string $label,
    string $detail = ''
): void {
    $tag = match ($status) {
        'pass' => '[PASS]',
        'fail' => '[FAIL]',
        default => '[SKIP]'
    };

    echo str_pad($tag, 7)
        . ' '
        . $label
        . ($detail !== '' ? ' - ' . $detail : '')
        . PHP_EOL;
}

function passTest(
    string $label,
    string $detail = ''
): void {
    global $pass;
    $pass++;
    lineResult('pass', $label, $detail);
}

function failTest(
    string $label,
    string $detail = ''
): void {
    global $fail;
    $fail++;
    lineResult('fail', $label, $detail);
}

function skipTest(
    string $label,
    string $detail = ''
): void {
    global $skip;
    $skip++;
    lineResult('skip', $label, $detail);
}

function runTool(
    AIToolExecutor $executor,
    string $tool,
    array $args = []
): array {
    try {
        $result = $executor->execute(
            $tool,
            $args
        );

        return [
            'threw' => false,
            'result' => $result,
            'error' => null
        ];
    } catch (Throwable $e) {
        return [
            'threw' => true,
            'result' => null,
            'error' => $e->getMessage()
        ];
    }
}

function expectAllowed(
    string $label,
    AIToolExecutor $executor,
    string $tool,
    array $args = []
): ?array {
    $run = runTool(
        $executor,
        $tool,
        $args
    );

    if ($run['threw']) {
        failTest(
            $label,
            (string) $run['error']
        );
        return null;
    }

    $result = $run['result'];

    if (
        !is_array($result)
        || !array_key_exists('success', $result)
    ) {
        failTest(
            $label,
            'Tool returned an invalid result.'
        );
        return null;
    }

    passTest(
        $label,
        'tool executed'
    );

    return $result;
}

function expectDenied(
    string $label,
    AIToolExecutor $executor,
    string $tool,
    array $args = []
): void {
    $run = runTool(
        $executor,
        $tool,
        $args
    );

    if ($run['threw']) {
        passTest(
            $label,
            'permission denied'
        );
        return;
    }

    failTest(
        $label,
        'tool unexpectedly executed'
    );
}

function firstRow(
    PDO $conn,
    string $sql,
    array $params = []
): ?array {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    return $row ?: null;
}

function findSearchTerm(
    PDO $conn
): string {
    $row = firstRow(
        $conn,
        "
            SELECT name
            FROM products
            WHERE status = 'active'
            ORDER BY id ASC
            LIMIT 1
        "
    );

    if (!$row) {
        return 'a';
    }

    $name = trim(
        (string) $row['name']
    );

    if ($name === '') {
        return 'a';
    }

    return mb_substr(
        $name,
        0,
        min(4, mb_strlen($name))
    );
}

echo "Somame AI local tool test harness\n";
echo str_repeat('=', 40) . "\n";
echo "OpenAI API calls: DISABLED\n";
echo "Database writes: DISABLED by default\n\n";

$customer = firstRow(
    $conn,
    "
        SELECT
            u.id,
            u.company_id,
            u.role
        FROM users u
        WHERE u.role = 'customer'
          AND u.status = 'active'
        ORDER BY EXISTS(
            SELECT 1
            FROM orders o
            WHERE o.user_id = u.id
        ) DESC,
        u.id ASC
        LIMIT 1
    "
);

$admin = firstRow(
    $conn,
    "
        SELECT
            id,
            company_id,
            role
        FROM users
        WHERE role = 'admin'
          AND status = 'active'
          AND company_id IS NOT NULL
        ORDER BY id ASC
        LIMIT 1
    "
);

$staff = firstRow(
    $conn,
    "
        SELECT
            id,
            company_id,
            role
        FROM users
        WHERE role = 'staff'
          AND status = 'active'
          AND company_id IS NOT NULL
        ORDER BY id ASC
        LIMIT 1
    "
);

$deliveryUser = firstRow(
    $conn,
    "
        SELECT
            u.id,
            u.company_id,
            u.role
        FROM users u
        INNER JOIN delivery_partners dp
            ON dp.user_id = u.id
        WHERE u.role = 'delivery_partner'
          AND u.status = 'active'
        ORDER BY EXISTS(
            SELECT 1
            FROM deliveries d
            WHERE d.delivery_partner_id = dp.id
        ) DESC,
        u.id ASC
        LIMIT 1
    "
);

$superAdmin = firstRow(
    $conn,
    "
        SELECT
            id,
            company_id,
            role
        FROM users
        WHERE role = 'super_admin'
          AND status = 'active'
        ORDER BY id ASC
        LIMIT 1
    "
);

$searchTerm = findSearchTerm($conn);

/*
|--------------------------------------------------------------------------
| CUSTOMER
|--------------------------------------------------------------------------
*/

echo "Customer tests\n";
echo str_repeat('-', 40) . "\n";

if (!$customer) {
    skipTest(
        'Customer suite',
        'No active customer account found.'
    );
} else {
    $customerExecutor = new AIToolExecutor(
        $conn,
        (int) $customer['id'],
        'customer',
        null
    );

    expectAllowed(
        'customer can search marketplace products',
        $customerExecutor,
        'search_products',
        [
            'query' => $searchTerm,
            'limit' => 5
        ]
    );

    expectAllowed(
        'customer can read own preferences',
        $customerExecutor,
        'get_customer_preferences'
    );

    $ownOrder = firstRow(
        $conn,
        "
            SELECT id
            FROM orders
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT 1
        ",
        [(int) $customer['id']]
    );

    if ($ownOrder) {
        $result = expectAllowed(
            'customer can query own order',
            $customerExecutor,
            'get_order',
            [
                'order_id' =>
                    (int) $ownOrder['id']
            ]
        );

        if (
            $result
            && ($result['success'] ?? false) !== true
        ) {
            failTest(
                'customer own-order ownership check',
                (string) (
                    $result['error']
                    ?? 'own order not returned'
                )
            );
        } else {
            passTest(
                'customer own-order ownership check'
            );
        }

        $hasDelivery = firstRow(
            $conn,
            "
                SELECT id
                FROM deliveries
                WHERE order_id = ?
                LIMIT 1
            ",
            [(int) $ownOrder['id']]
        );

        if ($hasDelivery) {
            expectAllowed(
                'customer can query own delivery',
                $customerExecutor,
                'get_delivery_status',
                [
                    'order_id' =>
                        (int) $ownOrder['id']
                ]
            );
        } else {
            skipTest(
                'customer can query own delivery',
                'Selected customer order has no delivery record.'
            );
        }
    } else {
        skipTest(
            'customer own order tools',
            'Selected customer has no orders.'
        );
    }

    $foreignOrder = firstRow(
        $conn,
        "
            SELECT id
            FROM orders
            WHERE user_id <> ?
            ORDER BY id DESC
            LIMIT 1
        ",
        [(int) $customer['id']]
    );

    if ($foreignOrder) {
        $run = runTool(
            $customerExecutor,
            'get_order',
            [
                'order_id' =>
                    (int) $foreignOrder['id']
            ]
        );

        if (
            !$run['threw']
            && is_array($run['result'])
            && ($run['result']['success'] ?? true) === false
        ) {
            passTest(
                'customer cannot read another customer order'
            );
        } else {
            failTest(
                'customer cannot read another customer order',
                $run['threw']
                    ? (string) $run['error']
                    : 'foreign order was exposed'
            );
        }
    } else {
        skipTest(
            'customer cross-account isolation',
            'No second customer order exists.'
        );
    }

    expectDenied(
        'customer cannot access company summary',
        $customerExecutor,
        'get_company_summary'
    );

    expectDenied(
        'customer cannot access platform summary',
        $customerExecutor,
        'get_platform_summary'
    );

    skipTest(
        'customer create_support_ticket',
        'Read-only harness intentionally avoids database writes.'
    );
}

echo "\nAdmin tests\n";
echo str_repeat('-', 40) . "\n";

if (!$admin) {
    skipTest(
        'Admin suite',
        'No active admin account found.'
    );
} else {
    $adminExecutor = new AIToolExecutor(
        $conn,
        (int) $admin['id'],
        'admin',
        (int) $admin['company_id']
    );

    expectAllowed(
        'admin can search own company products',
        $adminExecutor,
        'search_products',
        [
            'query' => $searchTerm,
            'limit' => 5
        ]
    );

    $ordersResult = expectAllowed(
        'admin can read own company orders',
        $adminExecutor,
        'get_company_orders',
        ['limit' => 10]
    );

    if ($ordersResult) {
        $wrongCompany = array_filter(
            $ordersResult['orders'] ?? [],
            static function (array $order) use ($admin): bool {
                return isset($order['company_id'])
                    && (int) $order['company_id']
                        !== (int) $admin['company_id'];
            }
        );

        if (!$wrongCompany) {
            passTest(
                'admin order query remains company scoped'
            );
        } else {
            failTest(
                'admin order query remains company scoped',
                'Cross-company order returned.'
            );
        }
    }

    expectAllowed(
        'admin can read own company summary',
        $adminExecutor,
        'get_company_summary'
    );

    expectDenied(
        'admin cannot access platform summary',
        $adminExecutor,
        'get_platform_summary'
    );
}

echo "\nStaff tests\n";
echo str_repeat('-', 40) . "\n";

if (!$staff) {
    skipTest(
        'Staff suite',
        'No active staff account found.'
    );
} else {
    $staffExecutor = new AIToolExecutor(
        $conn,
        (int) $staff['id'],
        'staff',
        (int) $staff['company_id']
    );

    expectAllowed(
        'staff can search own company products',
        $staffExecutor,
        'search_products',
        [
            'query' => $searchTerm,
            'limit' => 5
        ]
    );

    expectAllowed(
        'staff can read company orders',
        $staffExecutor,
        'get_company_orders',
        ['limit' => 10]
    );

    expectDenied(
        'staff cannot access company reports',
        $staffExecutor,
        'get_company_summary'
    );

    expectDenied(
        'staff cannot access platform summary',
        $staffExecutor,
        'get_platform_summary'
    );
}

echo "\nDelivery partner tests\n";
echo str_repeat('-', 40) . "\n";

if (!$deliveryUser) {
    skipTest(
        'Delivery suite',
        'No active delivery partner found.'
    );
} else {
    $deliveryExecutor = new AIToolExecutor(
        $conn,
        (int) $deliveryUser['id'],
        'delivery_partner',
        (int) $deliveryUser['company_id']
    );

    expectAllowed(
        'delivery partner can read assigned deliveries',
        $deliveryExecutor,
        'get_assigned_deliveries'
    );

    expectDenied(
        'delivery partner cannot access company orders',
        $deliveryExecutor,
        'get_company_orders',
        ['limit' => 10]
    );

    expectDenied(
        'delivery partner cannot access platform summary',
        $deliveryExecutor,
        'get_platform_summary'
    );
}

echo "\nSuper admin tests\n";
echo str_repeat('-', 40) . "\n";

if (!$superAdmin) {
    skipTest(
        'Super admin suite',
        'No active super admin account found.'
    );
} else {
    $superExecutor = new AIToolExecutor(
        $conn,
        (int) $superAdmin['id'],
        'super_admin',
        null
    );

    expectAllowed(
        'super admin can list platform companies',
        $superExecutor,
        'get_platform_companies',
        ['limit' => 10]
    );

    expectAllowed(
        'super admin can read platform summary',
        $superExecutor,
        'get_platform_summary'
    );

    expectDenied(
        'super admin cannot use customer own-order tool',
        $superExecutor,
        'get_order',
        ['order_id' => 1]
    );
}

echo "\n";
echo "Pass={$pass}, Fail={$fail}, Skip={$skip}\n";

exit($fail > 0 ? 1 : 0);
