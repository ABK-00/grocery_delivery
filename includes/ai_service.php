<?php

require_once __DIR__ . '/../config/ai.php';

function aiScopeForRole(
    string $role,
    ?string $requestedScope = null
): string {
    if ($role === 'customer') {
        return $requestedScope === 'customer_personalization'
            ? 'customer_personalization'
            : 'customer_support';
    }

    return match ($role) {
        'super_admin' => 'super_admin_assistant',
        'admin' => 'admin_assistant',
        'staff' => 'staff_assistant',
        'delivery_partner' => 'delivery_assistant',
        default => 'customer_support',
    };
}

function buildAiBusinessContext(
    PDO $conn,
    int $userId,
    string $role,
    ?int $companyId
): array {
    $context = [
        'role' => $role,
        'user_id' => $userId,
        'company_id' => $companyId,
    ];

    if ($role === 'customer') {
        $stmt = $conn->prepare("
            SELECT
                o.order_number,
                o.status,
                o.payment_status,
                o.total_amount,
                o.created_at,
                COALESCE(cs.display_name, c.company_name) AS store_name
            FROM orders o
            INNER JOIN companies c ON c.id = o.company_id
            LEFT JOIN company_storefronts cs ON cs.company_id = c.id
            WHERE o.user_id = ?
            ORDER BY o.created_at DESC
            LIMIT 8
        ");
        $stmt->execute([$userId]);
        $context['recent_orders'] = $stmt->fetchAll();

        $stmt = $conn->prepare("
            SELECT
                p.name,
                ca.quantity,
                p.price,
                COALESCE(cs.display_name, c.company_name) AS store_name
            FROM cart ca
            INNER JOIN products p ON p.id = ca.product_id
            INNER JOIN companies c ON c.id = p.company_id
            LEFT JOIN company_storefronts cs ON cs.company_id = c.id
            WHERE ca.user_id = ?
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $context['cart'] = $stmt->fetchAll();

        $stmt = $conn->prepare("
            SELECT
                preferred_categories,
                preferred_stores,
                dietary_preferences,
                disliked_products,
                preferred_payment_method,
                allow_personalization,
                allow_marketing,
                allow_location_personalization,
                preferred_language,
                ai_profile_summary
            FROM customer_preferences
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $preferences = $stmt->fetch() ?: null;

        $personalizationEnabled =
            $preferences === null
            || !empty($preferences['allow_personalization']);

        $context['personalization_enabled'] =
            $personalizationEnabled;

        if ($personalizationEnabled) {
            $context['preferences'] = $preferences;

            $stmt = $conn->prepare("
                SELECT
                    oi.product_name,
                    SUM(oi.quantity) AS quantity_bought,
                    MAX(o.created_at) AS last_ordered_at
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE o.user_id = ?
                  AND o.payment_status = 'paid'
                GROUP BY oi.product_id, oi.product_name
                ORDER BY quantity_bought DESC, last_ordered_at DESC
                LIMIT 12
            ");
            $stmt->execute([$userId]);
            $context['purchase_patterns'] = $stmt->fetchAll();
        } else {
            $context['preferences'] = null;
            $context['purchase_patterns'] = [];
        }

        $context['product_candidates'] = $conn->query("
            SELECT
                p.id,
                p.name,
                p.price,
                p.stock,
                p.unit,
                cat.name AS category,
                COALESCE(cs.display_name, c.company_name) AS store_name
            FROM products p
            INNER JOIN companies c ON c.id = p.company_id
            LEFT JOIN company_storefronts cs ON cs.company_id = c.id
            LEFT JOIN categories cat ON cat.id = p.category_id
            WHERE p.status = 'active'
              AND p.stock > 0
              AND c.status = 'active'
              AND COALESCE(cs.store_status, 'open') = 'open'
              AND (
                    (
                        c.subscription_plan = 'trial'
                        AND c.trial_ends_at IS NOT NULL
                        AND c.trial_ends_at >= NOW()
                    )
                    OR
                    (
                        c.subscription_plan IN ('monthly','quarterly','yearly')
                        AND c.subscription_ends_at IS NOT NULL
                        AND c.subscription_ends_at >= NOW()
                    )
              )
            ORDER BY p.updated_at DESC
            LIMIT 30
        ")->fetchAll();

        return $context;
    }

    if ($role === 'super_admin') {
        $context['platform'] = [
            'companies' => (int) $conn->query("SELECT COUNT(*) FROM companies")->fetchColumn(),
            'active_companies' => (int) $conn->query("SELECT COUNT(*) FROM companies WHERE status='active'")->fetchColumn(),
            'customers' => (int) $conn->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
            'orders' => (int) $conn->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'paid_volume' => (float) $conn->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status='paid'")->fetchColumn(),
        ];

        $context['subscriptions'] = $conn->query("
            SELECT
                company_name,
                subscription_plan,
                trial_ends_at,
                subscription_ends_at,
                status
            FROM companies
            ORDER BY updated_at DESC
            LIMIT 12
        ")->fetchAll();

        $context['support_load'] = [
            'open' => (int) $conn->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn(),
            'in_progress' => (int) $conn->query("SELECT COUNT(*) FROM support_tickets WHERE status='in_progress'")->fetchColumn(),
            'urgent' => (int) $conn->query("SELECT COUNT(*) FROM support_tickets WHERE priority='urgent' AND status IN ('open','in_progress')")->fetchColumn(),
        ];

        $context['payment_health'] = [
            'paid_order_payments' => (int) $conn->query("SELECT COUNT(*) FROM payments WHERE status='paid'")->fetchColumn(),
            'failed_order_payments' => (int) $conn->query("SELECT COUNT(*) FROM payments WHERE status='failed'")->fetchColumn(),
            'paid_subscriptions' => (int) $conn->query("SELECT COUNT(*) FROM subscription_payments WHERE status='paid'")->fetchColumn(),
            'failed_subscriptions' => (int) $conn->query("SELECT COUNT(*) FROM subscription_payments WHERE status='failed'")->fetchColumn(),
        ];

        return $context;
    }

    if ($role === 'delivery_partner') {
        /*
         * Delivery partners intentionally receive only their assigned-delivery
         * context. They must not receive company-wide sales, inventory,
         * customer, staff, or support information.
         */
        if ($companyId) {
            $stmt = $conn->prepare("
                SELECT
                    company_name
                FROM companies
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$companyId]);
            $context['company'] = $stmt->fetch();
        }

        $stmt = $conn->prepare("
            SELECT
                o.order_number,
                o.delivery_address,
                o.delivery_phone,
                d.status,
                d.assigned_at,
                d.picked_up_at,
                d.delivered_at
            FROM delivery_partners dp
            INNER JOIN deliveries d
                ON d.delivery_partner_id = dp.id
            INNER JOIN orders o
                ON o.id = d.order_id
            WHERE dp.user_id = ?
              AND d.status IN (
                    'assigned',
                    'picked_up',
                    'out_for_delivery'
              )
            ORDER BY d.assigned_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $context['active_deliveries'] = $stmt->fetchAll();

        return $context;
    }

    if ($companyId) {
        $stmt = $conn->prepare("
            SELECT
                company_name,
                status,
                subscription_plan,
                trial_ends_at,
                subscription_ends_at
            FROM companies
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$companyId]);
        $context['company'] = $stmt->fetch();

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total_orders,
                SUM(payment_status='paid') AS paid_orders,
                SUM(status='pending') AS pending_orders,
                SUM(status='ready') AS ready_orders,
                COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END),0) AS paid_volume
            FROM orders
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $context['order_summary'] = $stmt->fetch();

        $stmt = $conn->prepare("
            SELECT
                name,
                stock,
                unit,
                status
            FROM products
            WHERE company_id = ?
            ORDER BY stock ASC, name ASC
            LIMIT 12
        ");
        $stmt->execute([$companyId]);
        $context['inventory_watch'] = $stmt->fetchAll();

        $stmt = $conn->prepare("
            SELECT
                o.order_number,
                o.status,
                o.payment_status,
                o.total_amount,
                o.created_at,
                u.name AS customer_name
            FROM orders o
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.company_id = ?
            ORDER BY o.created_at DESC
            LIMIT 12
        ");
        $stmt->execute([$companyId]);
        $context['recent_company_orders'] = $stmt->fetchAll();

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS active_staff,
                SUM(role='delivery_partner' AND status='active') AS active_delivery_accounts
            FROM users
            WHERE company_id = ?
              AND status = 'active'
        ");
        $stmt->execute([$companyId]);
        $context['team_summary'] = $stmt->fetch();

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS open_tickets,
                SUM(priority='urgent') AS urgent_tickets
            FROM support_tickets
            WHERE company_id = ?
              AND status IN ('open','in_progress')
        ");
        $stmt->execute([$companyId]);
        $context['support_summary'] = $stmt->fetch();
    }

    return $context;
}

function aiInstructionsForRole(
    string $role,
    ?string $scope = null
): string
{
    $base = "You are the built-in AI for a multi-vendor commerce and delivery platform. "
        . "Use only the supplied application context for account-specific facts. "
        . "Never invent order statuses, payment results, stock levels, customer identities, or company metrics. "
        . "Never claim you changed application data. You are advisory only unless the application explicitly gives you a tool. "
        . "Keep answers concise, useful, and operational.";

    return match ($role) {
        'customer' => $base
            . (
                $scope === 'customer_personalization'
                    ? " You are the customer's personal shopping assistant. "
                        . "Help discover products, compare available choices, plan baskets, stay within the customer's stated budget preference, and explain why recommendations fit. "
                    : " You are a customer-service assistant. "
                        . "Help with orders, payments, delivery, account guidance, returns/support routing, and product discovery. "
            )
            . "Only personalize recommendations when allow_personalization is true. "
            . "When personalization is disabled, do not infer tastes from purchase history or stored preferences. "
            . "Treat dietary preferences and disliked products as important constraints, but never present them as medical advice. "
            . "Do not recommend unavailable products and do not expose vendor-internal information.",
        'admin' => $base
            . " You are a company administrator's operations assistant. "
            . "Help analyze orders, inventory, customers, staff, delivery operations, storefront activity, and priorities. "
            . "Only discuss this administrator's company.",
        'staff' => $base
            . " You are a staff operations assistant. Focus on order processing and day-to-day company operations. "
            . "Do not reveal platform-wide or other-company information.",
        'delivery_partner' => $base
            . " You are a delivery assistant. Focus on assigned deliveries, safe workflow, customer contact details already supplied, and delivery status guidance.",
        'super_admin' => $base
            . " You are the platform super administrator's executive assistant. "
            . "Help analyze companies, subscriptions, users, payments, platform health, support load, risks, and priorities.",
        default => $base,
    };
}

function callOpenAiResponses(
    array $messages,
    string $instructions
): array {
    if (!OPENAI_AI_ENABLED) {
        throw new RuntimeException(
            'AI is not configured. Add OPENAI_API_KEY or config/ai.local.php.'
        );
    }

    $input = [];

    foreach ($messages as $message) {
        $input[] = [
            'role' => ($message['role'] ?? '') === 'assistant'
                ? 'assistant'
                : 'user',
            'content' => (string) ($message['content'] ?? ''),
        ];
    }

    $payload = [
        'model' => OPENAI_MODEL,
        'instructions' => $instructions,
        'input' => $input,
        'max_output_tokens' => 900,
    ];

    $ch = curl_init(OPENAI_RESPONSES_URL);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('AI connection failed: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300 || !is_array($result)) {
        throw new RuntimeException(
            is_array($result)
                ? ($result['error']['message'] ?? 'AI request failed.')
                : 'AI request failed.'
        );
    }

    $text = '';

    foreach (($result['output'] ?? []) as $output) {
        if (($output['type'] ?? '') !== 'message') {
            continue;
        }

        foreach (($output['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'output_text') {
                $text .= (string) ($part['text'] ?? '');
            }
        }
    }

    $text = trim($text);

    if ($text === '') {
        throw new RuntimeException('The AI returned an empty response.');
    }

    return [
        'text' => $text,
        'response_id' => $result['id'] ?? null,
        'model' => $result['model'] ?? OPENAI_MODEL,
        'usage' => $result['usage'] ?? null,
    ];
}


/**
 * Read-only application tools available to the role-scoped AI.
 * These tools never mutate application data.
 */
function aiReadToolsForRole(string $role): array
{
    $orderTool = [
        'type' => 'function',
        'name' => 'lookup_order',
        'description' =>
            'Look up an order by its order number using the current user authorization scope.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Order number shown to the user.'
                ]
            ],
            'required' => ['order_number'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    $productTool = [
        'type' => 'function',
        'name' => 'search_products',
        'description' =>
            'Search currently available marketplace products by a short product or category phrase.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Product or category search phrase.'
                ]
            ],
            'required' => ['query'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    $inventoryTool = [
        'type' => 'function',
        'name' => 'search_inventory',
        'description' =>
            'Search products and stock in the current vendor company.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Product name search phrase.'
                ]
            ],
            'required' => ['query'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    $companyTool = [
        'type' => 'function',
        'name' => 'lookup_company',
        'description' =>
            'Look up a vendor company by name or company code for platform administration.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Company name or company code.'
                ]
            ],
            'required' => ['query'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];


    $businessMetricsTool = [
        'type' => 'function',
        'name' => 'get_business_metrics',
        'description' =>
            'Get current company operational metrics, revenue, order pipeline, and low-stock counts for the current vendor.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 90,
                    'description' => 'Number of recent days to summarize.'
                ]
            ],
            'required' => ['days'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    $supportQueueTool = [
        'type' => 'function',
        'name' => 'get_support_queue',
        'description' =>
            'Summarize open support tickets visible to the current role. Vendor administrators see only tickets they personally opened; platform super administrators see the platform queue.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20
                ]
            ],
            'required' => ['limit'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    $subscriptionRiskTool = [
        'type' => 'function',
        'name' => 'get_subscription_risks',
        'description' =>
            'Find vendor trials or subscriptions that are expired or approaching expiry for platform administration.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 60
                ]
            ],
            'required' => ['days'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    $recommendationTool = [
        'type' => 'function',
        'name' => 'get_personalized_recommendations',
        'description' =>
            'Return currently available products ranked using the customer preference profile and paid purchase history when personalization is enabled.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional product, category, meal, or shopping need.'
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 12
                ]
            ],
            'required' => ['query', 'limit'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];


    $supportTicketTool = [
        'type' => 'function',
        'name' => 'lookup_support_ticket',
        'description' =>
            'Look up a support ticket by ticket number within the current authorization scope.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'ticket_number' => [
                    'type' => 'string',
                    'description' => 'Support ticket number.'
                ]
            ],
            'required' => ['ticket_number'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    $deliveryStatusTool = [
        'type' => 'function',
        'name' => 'lookup_delivery_status',
        'description' =>
            'Look up delivery status and the latest recorded location metadata for an authorized order.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Order number.'
                ]
            ],
            'required' => ['order_number'],
            'additionalProperties' => false
        ],
        'strict' => true
    ];

    return match ($role) {
        'customer' => [
            $orderTool,
            $productTool,
            $recommendationTool,
            $supportTicketTool,
            $deliveryStatusTool
        ],
        'admin' => [
            $orderTool,
            $inventoryTool,
            $businessMetricsTool,
            $supportQueueTool,
            $supportTicketTool,
            $deliveryStatusTool
        ],
        'staff' => [
            $orderTool,
            $inventoryTool,
            $supportTicketTool,
            $deliveryStatusTool
        ],
        'delivery_partner' => [
            $orderTool,
            $supportTicketTool,
            $deliveryStatusTool
        ],
        'super_admin' => [
            $orderTool,
            $companyTool,
            $supportQueueTool,
            $subscriptionRiskTool,
            $supportTicketTool,
            $deliveryStatusTool
        ],
        default => []
    };
}


function executeAiReadTool(
    PDO $conn,
    string $name,
    array $arguments,
    int $userId,
    string $role,
    ?int $companyId
): array {
    if ($name === 'lookup_order') {
        $orderNumber = trim(
            (string) ($arguments['order_number'] ?? '')
        );

        if ($orderNumber === '') {
            return ['error' => 'Order number is required.'];
        }

        $sql = "
            SELECT
                o.id,
                o.order_number,
                o.status,
                o.payment_status,
                o.total_amount,
                o.delivery_address,
                o.created_at,
                COALESCE(cs.display_name, c.company_name)
                    AS store_name
            FROM orders o
            INNER JOIN companies c
                ON c.id = o.company_id
            LEFT JOIN company_storefronts cs
                ON cs.company_id = c.id
            WHERE o.order_number = ?
        ";

        $params = [$orderNumber];

        if ($role === 'customer') {
            $sql .= " AND o.user_id = ? ";
            $params[] = $userId;
        } elseif (
            in_array($role, ['admin', 'staff'], true)
        ) {
            $sql .= " AND o.company_id = ? ";
            $params[] = $companyId;
        } elseif ($role === 'delivery_partner') {
            $sql .= "
                AND EXISTS (
                    SELECT 1
                    FROM deliveries d
                    INNER JOIN delivery_partners dp
                        ON dp.id = d.delivery_partner_id
                    WHERE d.order_id = o.id
                      AND dp.user_id = ?
                )
            ";
            $params[] = $userId;
        } elseif ($role !== 'super_admin') {
            return ['error' => 'Order lookup is unavailable.'];
        }

        $sql .= " LIMIT 1 ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $order = $stmt->fetch();

        if (!$order) {
            return ['found' => false];
        }

        $itemStmt = $conn->prepare("
            SELECT
                product_name,
                quantity,
                unit_price,
                subtotal
            FROM order_items
            WHERE order_id = ?
            ORDER BY id ASC
            LIMIT 30
        ");
        $itemStmt->execute([
            $order['id']
        ]);

        $order['items'] =
            $itemStmt->fetchAll();

        unset($order['id']);

        return [
            'found' => true,
            'order' => $order
        ];
    }

    if ($name === 'search_products') {
        if ($role !== 'customer') {
            return ['error' => 'Marketplace search is unavailable.'];
        }

        $query = trim(
            (string) ($arguments['query'] ?? '')
        );

        if ($query === '') {
            return ['products' => []];
        }

        $like = '%' . $query . '%';

        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.name,
                p.price,
                p.stock,
                p.unit,
                cat.name AS category,
                c.storefront_slug,
                COALESCE(cs.display_name, c.company_name)
                    AS store_name
            FROM products p
            INNER JOIN companies c
                ON c.id = p.company_id
            LEFT JOIN company_storefronts cs
                ON cs.company_id = c.id
            LEFT JOIN categories cat
                ON cat.id = p.category_id
            WHERE p.status = 'active'
              AND p.stock > 0
              AND c.status = 'active'
              AND COALESCE(cs.store_status, 'open') = 'open'
              AND (
                    p.name LIKE ?
                    OR p.description LIKE ?
                    OR cat.name LIKE ?
              )
              AND (
                    (
                        c.subscription_plan = 'trial'
                        AND c.trial_ends_at >= NOW()
                    )
                    OR
                    (
                        c.subscription_plan IN (
                            'monthly',
                            'quarterly',
                            'yearly'
                        )
                        AND c.subscription_ends_at >= NOW()
                    )
              )
            ORDER BY p.name ASC
            LIMIT 15
        ");

        $stmt->execute([
            $like,
            $like,
            $like
        ]);

        return [
            'products' => $stmt->fetchAll()
        ];
    }

    if ($name === 'search_inventory') {
        if (
            !in_array($role, ['admin', 'staff'], true)
            || !$companyId
        ) {
            return ['error' => 'Inventory search is unavailable.'];
        }

        $query = trim(
            (string) ($arguments['query'] ?? '')
        );

        $like = '%' . $query . '%';

        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.name,
                p.price,
                p.stock,
                p.unit,
                p.status,
                cat.name AS category
            FROM products p
            LEFT JOIN categories cat
                ON cat.id = p.category_id
            WHERE p.company_id = ?
              AND p.name LIKE ?
            ORDER BY p.stock ASC, p.name ASC
            LIMIT 20
        ");

        $stmt->execute([
            $companyId,
            $like
        ]);

        return [
            'products' => $stmt->fetchAll()
        ];
    }

    if ($name === 'lookup_company') {
        if ($role !== 'super_admin') {
            return ['error' => 'Company lookup is unavailable.'];
        }

        $query = trim(
            (string) ($arguments['query'] ?? '')
        );

        $like = '%' . $query . '%';

        $stmt = $conn->prepare("
            SELECT
                id,
                company_code,
                company_name,
                status,
                subscription_plan,
                trial_ends_at,
                subscription_ends_at,
                created_at
            FROM companies
            WHERE company_name LIKE ?
               OR company_code LIKE ?
            ORDER BY company_name ASC
            LIMIT 10
        ");

        $stmt->execute([
            $like,
            $like
        ]);

        $companies = [];

        foreach ($stmt->fetchAll() as $company) {
            $metrics = $conn->prepare("
                SELECT
                    COUNT(*) AS total_orders,
                    SUM(payment_status = 'paid')
                        AS paid_orders,
                    COALESCE(
                        SUM(
                            CASE
                                WHEN payment_status = 'paid'
                                THEN total_amount
                                ELSE 0
                            END
                        ),
                        0
                    ) AS paid_volume
                FROM orders
                WHERE company_id = ?
            ");

            $metrics->execute([
                $company['id']
            ]);

            $company['metrics'] =
                $metrics->fetch();

            unset($company['id']);

            $companies[] = $company;
        }

        return [
            'companies' => $companies
        ];
    }


    if ($name === 'get_business_metrics') {
        if ($role !== 'admin' || !$companyId) {
            return ['error' => 'Business metrics are unavailable.'];
        }

        $days = max(
            1,
            min(90, (int) ($arguments['days'] ?? 30))
        );

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS orders,
                SUM(payment_status='paid') AS paid_orders,
                SUM(status='pending') AS pending_orders,
                SUM(status='preparing') AS preparing_orders,
                SUM(status='ready') AS ready_orders,
                SUM(status='out_for_delivery') AS out_for_delivery,
                COALESCE(
                    SUM(
                        CASE
                            WHEN payment_status='paid'
                            THEN total_amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS paid_revenue
            FROM orders
            WHERE company_id = ?
              AND created_at >= DATE_SUB(
                    NOW(),
                    INTERVAL ? DAY
              )
        ");
        $stmt->execute([
            $companyId,
            $days
        ]);

        $lowStockStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM products
            WHERE company_id = ?
              AND status = 'active'
              AND stock <= 5
        ");
        $lowStockStmt->execute([$companyId]);

        $metrics = $stmt->fetch();
        $metrics['low_stock_products'] =
            (int) $lowStockStmt->fetchColumn();
        $metrics['period_days'] = $days;

        return ['metrics' => $metrics];
    }

    if ($name === 'get_support_queue') {
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            return ['error' => 'Support queue is unavailable.'];
        }

        $limit = max(
            1,
            min(20, (int) ($arguments['limit'] ?? 10))
        );

        if ($role === 'super_admin') {
            $stmt = $conn->prepare("
                SELECT
                    st.ticket_number,
                    st.category,
                    st.subject,
                    st.status,
                    st.priority,
                    st.updated_at,
                    u.name AS requester_name,
                    c.company_name
                FROM support_tickets st
                INNER JOIN users u ON u.id = st.user_id
                LEFT JOIN companies c ON c.id = st.company_id
                WHERE st.status IN ('open','in_progress')
                ORDER BY
                    FIELD(st.priority,'urgent','high','normal','low'),
                    st.updated_at ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("
                SELECT
                    st.ticket_number,
                    st.category,
                    st.subject,
                    st.status,
                    st.priority,
                    st.updated_at,
                    u.name AS requester_name
                FROM support_tickets st
                INNER JOIN users u ON u.id = st.user_id
                WHERE st.company_id = ?
                  AND st.status IN ('open','in_progress')
                ORDER BY
                    FIELD(st.priority,'urgent','high','normal','low'),
                    st.updated_at ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, (int) $companyId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        return ['tickets' => $stmt->fetchAll()];
    }

    if ($name === 'get_subscription_risks') {
        if ($role !== 'super_admin') {
            return ['error' => 'Subscription risk data is unavailable.'];
        }

        $days = max(
            1,
            min(60, (int) ($arguments['days'] ?? 14))
        );

        $stmt = $conn->prepare("
            SELECT
                company_code,
                company_name,
                status,
                subscription_plan,
                trial_ends_at,
                subscription_ends_at,
                CASE
                    WHEN subscription_plan='trial'
                    THEN trial_ends_at
                    ELSE subscription_ends_at
                END AS access_ends_at
            FROM companies
            WHERE
                (
                    subscription_plan='trial'
                    AND trial_ends_at IS NOT NULL
                    AND trial_ends_at <= DATE_ADD(
                        NOW(),
                        INTERVAL ? DAY
                    )
                )
                OR
                (
                    subscription_plan IN (
                        'monthly',
                        'quarterly',
                        'yearly'
                    )
                    AND subscription_ends_at IS NOT NULL
                    AND subscription_ends_at <= DATE_ADD(
                        NOW(),
                        INTERVAL ? DAY
                    )
                )
            ORDER BY access_ends_at ASC
            LIMIT 30
        ");
        $stmt->execute([
            $days,
            $days
        ]);

        return [
            'window_days' => $days,
            'companies' => $stmt->fetchAll()
        ];
    }

    if ($name === 'get_personalized_recommendations') {
        if ($role !== 'customer') {
            return ['error' => 'Recommendations are unavailable.'];
        }

        $limit = max(
            1,
            min(12, (int) ($arguments['limit'] ?? 8))
        );
        $query = trim(
            (string) ($arguments['query'] ?? '')
        );

        $prefStmt = $conn->prepare("
            SELECT
                preferred_categories,
                preferred_stores,
                dietary_preferences,
                disliked_products,
                preferred_payment_method,
                allow_personalization,
                allow_marketing,
                allow_location_personalization,
                preferred_language,
                ai_profile_summary
            FROM customer_preferences
            WHERE user_id = ?
            LIMIT 1
        ");
        $prefStmt->execute([$userId]);
        $prefs = $prefStmt->fetch();

        if (
            $prefs
            && empty($prefs['allow_personalization'])
        ) {
            return [
                'personalization_enabled' => false,
                'message' =>
                    'The customer has disabled personalized recommendations.'
            ];
        }

        $like = '%' . $query . '%';

        $sql = "
            SELECT
                p.id,
                p.name,
                p.price,
                p.stock,
                p.unit,
                p.description,
                cat.name AS category,
                c.storefront_slug,
                COALESCE(cs.display_name, c.company_name)
                    AS store_name,
                COALESCE(
                    (
                        SELECT SUM(oi.quantity)
                        FROM order_items oi
                        INNER JOIN orders o
                            ON o.id = oi.order_id
                        WHERE oi.product_id = p.id
                          AND o.user_id = ?
                          AND o.payment_status = 'paid'
                    ),
                    0
                ) AS previous_quantity
            FROM products p
            INNER JOIN companies c
                ON c.id = p.company_id
            LEFT JOIN company_storefronts cs
                ON cs.company_id = c.id
            LEFT JOIN categories cat
                ON cat.id = p.category_id
            WHERE p.status='active'
              AND p.stock > 0
              AND c.status='active'
              AND COALESCE(cs.store_status,'open')='open'
              AND (
                    (
                        c.subscription_plan='trial'
                        AND c.trial_ends_at >= NOW()
                    )
                    OR
                    (
                        c.subscription_plan IN (
                            'monthly','quarterly','yearly'
                        )
                        AND c.subscription_ends_at >= NOW()
                    )
              )
        ";

        $params = [$userId];

        if ($query !== '') {
            $sql .= "
                AND (
                    p.name LIKE ?
                    OR p.description LIKE ?
                    OR cat.name LIKE ?
                )
            ";
            array_push(
                $params,
                $like,
                $like,
                $like
            );
        }

        $sql .= "
            ORDER BY
                previous_quantity DESC,
                p.updated_at DESC
            LIMIT " . (int) $limit;

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        return [
            'personalization_enabled' => true,
            'preferences' => $prefs ?: [
                'allow_personalization' => 1
            ],
            'products' => $stmt->fetchAll()
        ];
    }


    if ($name === 'lookup_support_ticket') {
        $ticketNumber = trim(
            (string) ($arguments['ticket_number'] ?? '')
        );

        if ($ticketNumber === '') {
            return ['error' => 'Ticket number is required.'];
        }

        $sql = "
            SELECT
                st.id,
                st.ticket_number,
                st.category,
                st.subject,
                st.status,
                st.priority,
                st.created_at,
                st.updated_at,
                u.name AS requester_name,
                c.company_name
            FROM support_tickets st
            INNER JOIN users u ON u.id = st.user_id
            LEFT JOIN companies c ON c.id = st.company_id
            WHERE st.ticket_number = ?
        ";
        $params = [$ticketNumber];

        if (
            in_array($role, ['admin', 'staff'], true)
            && $companyId
        ) {
            $sql .= " AND st.company_id = ? ";
            $params[] = $companyId;
        } elseif ($role !== 'super_admin') {
            $sql .= " AND st.user_id = ? ";
            $params[] = $userId;
        }

        $sql .= " LIMIT 1 ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            return ['found' => false];
        }

        $messageStmt = $conn->prepare("
            SELECT
                sm.message,
                sm.created_at,
                u.name,
                u.role
            FROM support_messages sm
            INNER JOIN users u ON u.id = sm.user_id
            WHERE sm.ticket_id = ?
            ORDER BY sm.id DESC
            LIMIT 5
        ");
        $messageStmt->execute([
            $ticket['id']
        ]);

        $ticket['recent_messages'] = array_reverse(
            $messageStmt->fetchAll()
        );
        unset($ticket['id']);

        return [
            'found' => true,
            'ticket' => $ticket
        ];
    }

    if ($name === 'lookup_delivery_status') {
        $orderNumber = trim(
            (string) ($arguments['order_number'] ?? '')
        );

        if ($orderNumber === '') {
            return ['error' => 'Order number is required.'];
        }

        $sql = "
            SELECT
                o.id,
                o.order_number,
                o.status AS order_status,
                o.payment_status,
                o.delivery_address,
                d.id AS delivery_id,
                d.status AS delivery_status,
                d.assigned_at,
                d.picked_up_at,
                d.delivered_at,
                du.name AS delivery_partner_name,
                du.phone AS delivery_partner_phone
            FROM orders o
            LEFT JOIN deliveries d
                ON d.order_id = o.id
            LEFT JOIN delivery_partners dp
                ON dp.id = d.delivery_partner_id
            LEFT JOIN users du
                ON du.id = dp.user_id
            WHERE o.order_number = ?
        ";
        $params = [$orderNumber];

        if ($role === 'customer') {
            $sql .= " AND o.user_id = ? ";
            $params[] = $userId;
        } elseif (
            in_array($role, ['admin','staff'], true)
        ) {
            $sql .= " AND o.company_id = ? ";
            $params[] = $companyId;
        } elseif ($role === 'delivery_partner') {
            $sql .= "
                AND dp.user_id = ?
            ";
            $params[] = $userId;
        } elseif ($role !== 'super_admin') {
            return ['error' => 'Delivery lookup is unavailable.'];
        }

        $sql .= " LIMIT 1 ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $delivery = $stmt->fetch();

        if (!$delivery) {
            return ['found' => false];
        }

        if (!empty($delivery['delivery_id'])) {
            $locationStmt = $conn->prepare("
                SELECT
                    latitude,
                    longitude,
                    accuracy,
                    speed,
                    heading,
                    recorded_at
                FROM delivery_locations
                WHERE delivery_id = ?
                ORDER BY recorded_at DESC, id DESC
                LIMIT 1
            ");
            $locationStmt->execute([
                $delivery['delivery_id']
            ]);
            $delivery['latest_location'] =
                $locationStmt->fetch() ?: null;
        } else {
            $delivery['latest_location'] = null;
        }

        unset(
            $delivery['id'],
            $delivery['delivery_id']
        );

        return [
            'found' => true,
            'delivery' => $delivery
        ];
    }

    return ['error' => 'Unknown tool.'];
}


function extractAiResponseText(array $result): string
{
    $text = '';

    foreach (($result['output'] ?? []) as $output) {
        if (($output['type'] ?? '') !== 'message') {
            continue;
        }

        foreach (($output['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'output_text') {
                $text .= (string) ($part['text'] ?? '');
            }
        }
    }

    return trim($text);
}


function sendOpenAiResponsePayload(array $payload): array
{
    $ch = curl_init(OPENAI_RESPONSES_URL);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException(
            'AI connection failed: ' . $error
        );
    }

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    $result = json_decode(
        $response,
        true
    );

    if (
        $httpCode < 200
        || $httpCode >= 300
        || !is_array($result)
    ) {
        throw new RuntimeException(
            is_array($result)
                ? ($result['error']['message']
                    ?? 'AI request failed.')
                : 'AI request failed.'
        );
    }

    return $result;
}


/**
 * Responses API orchestration with role-scoped read-only tools.
 */
function callOpenAiResponsesWithTools(
    PDO $conn,
    array $messages,
    string $instructions,
    int $userId,
    string $role,
    ?int $companyId
): array {
    if (!OPENAI_AI_ENABLED) {
        throw new RuntimeException(
            'AI is not configured. Add OPENAI_API_KEY or config/ai.local.php.'
        );
    }

    $input = [];

    foreach ($messages as $message) {
        $input[] = [
            'role' => ($message['role'] ?? '') === 'assistant'
                ? 'assistant'
                : 'user',
            'content' =>
                (string) ($message['content'] ?? ''),
        ];
    }

    $tools = aiReadToolsForRole($role);

    $payload = [
        'model' => OPENAI_MODEL,
        'instructions' => $instructions,
        'input' => $input,
        'max_output_tokens' => 900,
        'tools' => $tools,
        'parallel_tool_calls' => false,
    ];

    $result =
        sendOpenAiResponsePayload($payload);

    for ($round = 0; $round < 3; $round++) {
        $calls = [];

        foreach (($result['output'] ?? []) as $output) {
            if (
                ($output['type'] ?? '')
                === 'function_call'
            ) {
                $calls[] = $output;
            }
        }

        if (!$calls) {
            break;
        }

        $toolOutputs = [];

        foreach ($calls as $call) {
            $arguments = json_decode(
                (string) ($call['arguments'] ?? '{}'),
                true
            );

            if (!is_array($arguments)) {
                $arguments = [];
            }

            $toolResult = executeAiReadTool(
                $conn,
                (string) ($call['name'] ?? ''),
                $arguments,
                $userId,
                $role,
                $companyId
            );

            $toolOutputs[] = [
                'type' => 'function_call_output',
                'call_id' =>
                    (string) ($call['call_id'] ?? ''),
                'output' => json_encode(
                    $toolResult,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                )
            ];
        }

        $result = sendOpenAiResponsePayload([
            'model' => OPENAI_MODEL,
            'instructions' => $instructions,
            'previous_response_id' =>
                $result['id'] ?? null,
            'input' => $toolOutputs,
            'max_output_tokens' => 900,
            'tools' => $tools,
            'parallel_tool_calls' => false,
        ]);
    }

    $text = extractAiResponseText($result);

    if ($text === '') {
        throw new RuntimeException(
            'The AI returned an empty response.'
        );
    }

    return [
        'text' => $text,
        'response_id' =>
            $result['id'] ?? null,
        'model' =>
            $result['model'] ?? OPENAI_MODEL,
        'usage' =>
            $result['usage'] ?? null,
    ];
}
