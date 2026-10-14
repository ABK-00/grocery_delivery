<?php

require_once __DIR__ . '/../config/ai.php';

function aiScopeForRole(string $role): string
{
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
                disliked_items,
                budget_preference,
                dietary_notes,
                personalization_enabled
            FROM customer_preferences
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $preferences = $stmt->fetch() ?: null;

        $personalizationEnabled =
            $preferences === null
            || !empty($preferences['personalization_enabled']);

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

    if ($role === 'delivery_partner') {
        $stmt = $conn->prepare("
            SELECT
                o.order_number,
                o.delivery_address,
                o.delivery_phone,
                d.status,
                d.assigned_at
            FROM delivery_partners dp
            INNER JOIN deliveries d ON d.delivery_partner_id = dp.id
            INNER JOIN orders o ON o.id = d.order_id
            WHERE dp.user_id = ?
              AND d.status IN ('assigned','picked_up','out_for_delivery')
            ORDER BY d.assigned_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $context['active_deliveries'] = $stmt->fetchAll();
    }

    return $context;
}

function aiInstructionsForRole(string $role): string
{
    $base = "You are the built-in AI for a multi-vendor commerce and delivery platform. "
        . "Use only the supplied application context for account-specific facts. "
        . "Never invent order statuses, payment results, stock levels, customer identities, or company metrics. "
        . "Never claim you changed application data. You are advisory only unless the application explicitly gives you a tool. "
        . "Keep answers concise, useful, and operational.";

    return match ($role) {
        'customer' => $base
            . " You are a customer-service assistant and shopping helper. "
            . "Help with orders, payments, delivery, product discovery, and shopping recommendations. "
            . "Only personalize recommendations when personalization_enabled is true. "
            . "When personalization is disabled, do not infer tastes from purchase history or stored preferences. "
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
