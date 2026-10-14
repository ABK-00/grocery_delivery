<?php

declare(strict_types=1);

require_once __DIR__ . '/AIPermissions.php';

class AIToolExecutor
{
    private PDO $pdo;
    private int $userId;
    private string $role;
    private ?int $companyId;

    public function __construct(
        PDO $pdo,
        int $userId,
        string $role,
        ?int $companyId
    ) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->role = strtolower(trim($role));
        $this->companyId = $companyId;
    }

    public function execute(
        string $name,
        array $arguments
    ): array {
        return match ($name) {
            'search_products' =>
                $this->searchProducts($arguments),

            'get_order' =>
                $this->getOrder($arguments),

            'get_delivery_status' =>
                $this->getDeliveryStatus($arguments),

            'get_customer_preferences' =>
                $this->getCustomerPreferences(),

            'create_support_ticket' =>
                $this->createSupportTicket($arguments),

            'get_company_orders' =>
                $this->getCompanyOrders($arguments),

            'get_company_summary' =>
                $this->getCompanySummary(),

            'get_assigned_deliveries' =>
                $this->getAssignedDeliveries(),

            'get_platform_companies' =>
                $this->getPlatformCompanies($arguments),

            'get_platform_summary' =>
                $this->getPlatformSummary(),

            default => [
                'success' => false,
                'error' => 'Unknown AI tool.'
            ]
        };
    }

    private function requirePermission(
        string $permission
    ): void {
        if (!AIPermissions::can(
            $this->role,
            $permission
        )) {
            throw new RuntimeException(
                'You do not have permission to perform this action.'
            );
        }
    }

    private function requireCompany(): int
    {
        if (!$this->companyId) {
            throw new RuntimeException(
                'Company context is unavailable.'
            );
        }

        return $this->companyId;
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCT SEARCH
    |--------------------------------------------------------------------------
    */

    private function searchProducts(array $args): array
    {
        $customerAllowed =
            AIPermissions::can(
                $this->role,
                'search_products'
            );

        $companyAllowed =
            AIPermissions::can(
                $this->role,
                'search_company_products'
            );

        if (!$customerAllowed && !$companyAllowed) {
            throw new RuntimeException(
                'You do not have permission to search products.'
            );
        }

        $query = trim(
            (string)($args['query'] ?? '')
        );

        $limit = (int)($args['limit'] ?? 10);

        $limit = max(
            1,
            min($limit, 20)
        );

        if ($query === '') {
            throw new RuntimeException(
                'Product search query cannot be empty.'
            );
        }

        /*
         * Admin/staff searches remain within their company.
         * Customers search across the marketplace.
         */
        if (
            in_array(
                $this->role,
                ['admin', 'staff'],
                true
            )
        ) {
            $companyId = $this->requireCompany();

            $sql = "
                SELECT
                    p.id,
                    p.company_id,
                    p.category_id,
                    p.name,
                    p.description,
                    p.price,
                    p.stock,
                    p.unit,
                    p.image,
                    p.status,
                    cs.display_name AS store_name,
                    cs.store_status
                FROM products p
                LEFT JOIN company_storefronts cs
                    ON cs.company_id = p.company_id
                WHERE p.company_id = :company_id
                  AND p.status = 'active'
                  AND (
                        p.name LIKE :query
                        OR p.description LIKE :query
                  )
                ORDER BY
                    p.stock > 0 DESC,
                    p.name ASC
                LIMIT {$limit}
            ";

            $stmt = $this->pdo->prepare($sql);

            $stmt->execute([
                ':company_id' => $companyId,
                ':query' => '%' . $query . '%'
            ]);
        } else {
            $sql = "
                SELECT
                    p.id,
                    p.company_id,
                    p.category_id,
                    p.name,
                    p.description,
                    p.price,
                    p.stock,
                    p.unit,
                    p.image,
                    cs.display_name AS store_name,
                    cs.store_status,
                    c.company_name
                FROM products p
                INNER JOIN companies c
                    ON c.id = p.company_id
                LEFT JOIN company_storefronts cs
                    ON cs.company_id = p.company_id
                WHERE p.status = 'active'
                  AND c.status = 'active'
                  AND (
                        cs.store_status IS NULL
                        OR cs.store_status = 'open'
                  )
                  AND (
                        p.name LIKE :query
                        OR p.description LIKE :query
                  )
                ORDER BY
                    p.stock > 0 DESC,
                    p.name ASC
                LIMIT {$limit}
            ";

            $stmt = $this->pdo->prepare($sql);

            $stmt->execute([
                ':query' => '%' . $query . '%'
            ]);
        }

        return [
            'success' => true,
            'products' =>
                $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER ORDER
    |--------------------------------------------------------------------------
    */

    private function getOrder(array $args): array
    {
        $this->requirePermission(
            'view_own_orders'
        );

        $orderId =
            (int)($args['order_id'] ?? 0);

        if ($orderId <= 0) {
            throw new RuntimeException(
                'Invalid order ID.'
            );
        }

        $stmt = $this->pdo->prepare("
            SELECT
                o.id,
                o.company_id,
                o.user_id,
                o.order_number,
                o.subtotal,
                o.delivery_fee,
                o.total_amount,
                o.delivery_address,
                o.delivery_phone,
                o.notes,
                o.status,
                o.payment_status,
                o.payment_reference,
                o.created_at,
                o.updated_at,
                c.company_name,
                cs.display_name AS store_name
            FROM orders o
            INNER JOIN companies c
                ON c.id = o.company_id
            LEFT JOIN company_storefronts cs
                ON cs.company_id = o.company_id
            WHERE o.id = :order_id
              AND o.user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $this->userId
        ]);

        $order =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return [
                'success' => false,
                'error' =>
                    'Order not found or it does not belong to the current customer.'
            ];
        }

        $itemStmt = $this->pdo->prepare("
            SELECT
                id,
                product_id,
                product_name,
                unit,
                quantity,
                unit_price,
                subtotal
            FROM order_items
            WHERE order_id = :order_id
            ORDER BY id ASC
        ");

        $itemStmt->execute([
            ':order_id' => $orderId
        ]);

        $order['items'] =
            $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'order' => $order
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER DELIVERY
    |--------------------------------------------------------------------------
    */

    private function getDeliveryStatus(
        array $args
    ): array {
        $this->requirePermission(
            'view_own_delivery'
        );

        $orderId =
            (int)($args['order_id'] ?? 0);

        if ($orderId <= 0) {
            throw new RuntimeException(
                'Invalid order ID.'
            );
        }

        /*
         * o.user_id makes it impossible for one customer
         * to request another customer's delivery.
         */
        $stmt = $this->pdo->prepare("
            SELECT
                d.id AS delivery_id,
                d.order_id,
                d.status AS delivery_status,
                d.assigned_at,
                d.picked_up_at,
                d.out_for_delivery_at,
                d.delivered_at,

                o.order_number,
                o.status AS order_status,
                o.delivery_address,
                o.delivery_phone,

                dp.vehicle_type,
                dp.vehicle_registration,

                u.name AS delivery_partner_name,
                u.phone AS delivery_partner_phone

            FROM deliveries d

            INNER JOIN orders o
                ON o.id = d.order_id

            LEFT JOIN delivery_partners dp
                ON dp.id = d.delivery_partner_id

            LEFT JOIN users u
                ON u.id = dp.user_id

            WHERE d.order_id = :order_id
              AND o.user_id = :user_id

            LIMIT 1
        ");

        $stmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $this->userId
        ]);

        $delivery =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            return [
                'success' => false,
                'error' =>
                    'Delivery information was not found for this order.'
            ];
        }

        return [
            'success' => true,
            'delivery' => $delivery
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER PREFERENCES
    |--------------------------------------------------------------------------
    */

    private function getCustomerPreferences(): array
    {
        $this->requirePermission(
            'manage_own_preferences'
        );

        $stmt = $this->pdo->prepare("
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
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ':user_id' => $this->userId
        ]);

        $preferences =
            $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'preferences' =>
                $preferences ?: null
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUPPORT
    |--------------------------------------------------------------------------
    */

    private function createSupportTicket(
        array $args
    ): array {
        $this->requirePermission(
            'create_support_ticket'
        );

        $subject = trim(
            (string)($args['subject'] ?? '')
        );

        $description = trim(
            (string)($args['description'] ?? '')
        );

        $category =
            (string)($args['category'] ?? 'other');

        $orderId =
            isset($args['order_id'])
            && $args['order_id'] !== null
                ? (int)$args['order_id']
                : null;

        if (
            $subject === ''
            || $description === ''
        ) {
            throw new RuntimeException(
                'Support subject and description are required.'
            );
        }

        $allowedCategories = [
            'order',
            'payment',
            'refund',
            'delivery',
            'account',
            'product',
            'subscription',
            'technical',
            'other'
        ];

        if (
            !in_array(
                $category,
                $allowedCategories,
                true
            )
        ) {
            $category = 'other';
        }

        $companyId = null;

        if ($orderId) {
            /*
             * Validate ownership AND determine which
             * company should receive the ticket.
             */
            $stmt = $this->pdo->prepare("
                SELECT company_id
                FROM orders
                WHERE id = :order_id
                  AND user_id = :user_id
                LIMIT 1
            ");

            $stmt->execute([
                ':order_id' => $orderId,
                ':user_id' => $this->userId
            ]);

            $company = $stmt->fetchColumn();

            if ($company === false) {
                throw new RuntimeException(
                    'The selected order was not found.'
                );
            }

            $companyId = (int)$company;
        }

        $ticketNumber =
            'SUP-' .
            date('YmdHis') .
            '-' .
            strtoupper(
                bin2hex(random_bytes(3))
            );

        $stmt = $this->pdo->prepare("
            INSERT INTO support_tickets (
                ticket_number,
                company_id,
                user_id,
                order_id,
                subject,
                description,
                category,
                priority,
                status,
                created_by_type
            )
            VALUES (
                :ticket_number,
                :company_id,
                :user_id,
                :order_id,
                :subject,
                :description,
                :category,
                'normal',
                'open',
                'customer'
            )
        ");

        $stmt->execute([
            ':ticket_number' =>
                $ticketNumber,

            ':company_id' =>
                $companyId,

            ':user_id' =>
                $this->userId,

            ':order_id' =>
                $orderId,

            ':subject' =>
                $subject,

            ':description' =>
                $description,

            ':category' =>
                $category
        ]);

        return [
            'success' => true,
            'ticket_id' =>
                (int)$this->pdo->lastInsertId(),

            'ticket_number' =>
                $ticketNumber,

            'message' =>
                'Support ticket created successfully.'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | COMPANY ORDERS
    |--------------------------------------------------------------------------
    */

    private function getCompanyOrders(
        array $args
    ): array {
        $this->requirePermission(
            'view_company_orders'
        );

        $companyId =
            $this->requireCompany();

        $limit = max(
            1,
            min(
                (int)($args['limit'] ?? 20),
                50
            )
        );

        $status =
            trim(
                (string)($args['status'] ?? '')
            );

        $sql = "
            SELECT
                o.id,
                o.order_number,
                o.user_id,
                u.name AS customer_name,
                u.phone AS customer_phone,
                o.subtotal,
                o.delivery_fee,
                o.total_amount,
                o.status,
                o.payment_status,
                o.delivery_address,
                o.created_at
            FROM orders o

            INNER JOIN users u
                ON u.id = o.user_id

            WHERE o.company_id = :company_id
        ";

        $params = [
            ':company_id' => $companyId
        ];

        if ($status !== '') {
            $sql .= "
                AND o.status = :status
            ";

            $params[':status'] = $status;
        }

        $sql .= "
            ORDER BY o.id DESC
            LIMIT {$limit}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'success' => true,
            'orders' =>
                $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | COMPANY SUMMARY
    |--------------------------------------------------------------------------
    */

    private function getCompanySummary(): array
    {
        $this->requirePermission(
            'view_company_reports'
        );

        $companyId =
            $this->requireCompany();

        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total_orders,

                COALESCE(
                    SUM(
                        CASE
                            WHEN payment_status = 'paid'
                            THEN total_amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS paid_revenue,

                COALESCE(
                    SUM(
                        CASE
                            WHEN DATE(created_at) = CURDATE()
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS orders_today,

                COALESCE(
                    SUM(
                        CASE
                            WHEN payment_status = 'pending'
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS pending_payments

            FROM orders
            WHERE company_id = :company_id
        ");

        $stmt->execute([
            ':company_id' => $companyId
        ]);

        $orders =
            $stmt->fetch(PDO::FETCH_ASSOC);

        $productStmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total_products,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status = 'active'
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS active_products,

                COALESCE(
                    SUM(
                        CASE
                            WHEN stock <= 5
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS low_stock_products,

                COALESCE(
                    SUM(
                        CASE
                            WHEN stock <= 0
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS out_of_stock_products

            FROM products
            WHERE company_id = :company_id
        ");

        $productStmt->execute([
            ':company_id' => $companyId
        ]);

        $products =
            $productStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'company_id' => $companyId,
            'orders' => $orders,
            'products' => $products
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | DELIVERY PARTNER
    |--------------------------------------------------------------------------
    */

    private function getAssignedDeliveries(): array
    {
        $this->requirePermission(
            'view_assigned_deliveries'
        );

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM delivery_partners
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ':user_id' => $this->userId
        ]);

        $partnerId =
            $stmt->fetchColumn();

        if ($partnerId === false) {
            return [
                'success' => false,
                'error' =>
                    'Delivery partner profile was not found.'
            ];
        }

        $stmt = $this->pdo->prepare("
            SELECT
                d.id AS delivery_id,
                d.status AS delivery_status,
                d.assigned_at,
                d.picked_up_at,
                d.out_for_delivery_at,
                d.delivered_at,

                o.id AS order_id,
                o.order_number,
                o.delivery_address,
                o.delivery_phone,
                o.status AS order_status,
                o.payment_status,

                u.name AS customer_name

            FROM deliveries d

            INNER JOIN orders o
                ON o.id = d.order_id

            INNER JOIN users u
                ON u.id = o.user_id

            WHERE d.delivery_partner_id =
                :delivery_partner_id

            ORDER BY d.id DESC

            LIMIT 30
        ");

        $stmt->execute([
            ':delivery_partner_id' =>
                (int)$partnerId
        ]);

        return [
            'success' => true,
            'deliveries' =>
                $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUPER ADMIN - COMPANIES
    |--------------------------------------------------------------------------
    */

    private function getPlatformCompanies(
        array $args
    ): array {
        $this->requirePermission(
            'view_platform_companies'
        );

        $limit = max(
            1,
            min(
                (int)($args['limit'] ?? 20),
                50
            )
        );

        $status =
            trim(
                (string)($args['status'] ?? '')
            );

        $allowedStatuses = [
            'active',
            'suspended',
            'blocked'
        ];

        $sql = "
            SELECT
                c.id,
                c.company_code,
                c.company_name,
                c.storefront_slug,
                c.email,
                c.phone,
                c.status,
                c.subscription_plan,
                c.trial_ends_at,
                c.subscription_starts_at,
                c.subscription_ends_at,
                c.created_at,

                cs.display_name,
                cs.store_status

            FROM companies c

            LEFT JOIN company_storefronts cs
                ON cs.company_id = c.id

            WHERE 1 = 1
        ";

        $params = [];

        if (
            $status !== ''
            && in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {
            $sql .= "
                AND c.status = :status
            ";

            $params[':status'] = $status;
        }

        $sql .= "
            ORDER BY c.id DESC
            LIMIT {$limit}
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute($params);

        return [
            'success' => true,
            'companies' =>
                $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUPER ADMIN - PLATFORM SUMMARY
    |--------------------------------------------------------------------------
    */

    private function getPlatformSummary(): array
    {
        $this->requirePermission(
            'view_platform_reports'
        );

        $companyStats =
            $this->pdo->query("
                SELECT
                    COUNT(*) AS total_companies,

                    COALESCE(
                        SUM(status = 'active'),
                        0
                    ) AS active_companies,

                    COALESCE(
                        SUM(status = 'suspended'),
                        0
                    ) AS suspended_companies,

                    COALESCE(
                        SUM(status = 'blocked'),
                        0
                    ) AS blocked_companies

                FROM companies
            ")->fetch(PDO::FETCH_ASSOC);

        $orderStats =
            $this->pdo->query("
                SELECT
                    COUNT(*) AS total_orders,

                    COALESCE(
                        SUM(
                            CASE
                                WHEN payment_status = 'paid'
                                THEN total_amount
                                ELSE 0
                            END
                        ),
                        0
                    ) AS gross_paid_order_value,

                    COALESCE(
                        SUM(
                            DATE(created_at) = CURDATE()
                        ),
                        0
                    ) AS orders_today

                FROM orders
            ")->fetch(PDO::FETCH_ASSOC);

        $customerStmt =
            $this->pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE role = 'customer'
                  AND status = 'active'
            ");

        $customerStmt->execute();

        $activeCustomers =
            (int)$customerStmt->fetchColumn();

        $supportStmt =
            $this->pdo->query("
                SELECT COUNT(*)
                FROM support_tickets
                WHERE status NOT IN (
                    'resolved',
                    'closed'
                )
            ");

        $openSupportTickets =
            (int)$supportStmt->fetchColumn();

        return [
            'success' => true,

            'companies' =>
                $companyStats,

            'orders' =>
                $orderStats,

            'active_customers' =>
                $activeCustomers,

            'open_support_tickets' =>
                $openSupportTickets
        ];
    }
}