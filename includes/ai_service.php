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
        $context['preferences'] = $stmt->fetch() ?: null;

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
            . "Help with orders, payments, delivery, product discovery, and preference-based suggestions. "
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
        $role = ($message['role'] ?? '') === 'assistant'
            ? 'assistant'
            : 'user';

        $input[] = [
            'role' => $role,
            'content' => [
                [
                    'type' => $role === 'assistant'
                        ? 'output_text'
                        : 'input_text',
                    'text' => (string) ($message['content'] ?? ''),
                ],
            ],
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
