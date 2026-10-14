<?php

declare(strict_types=1);

require_once __DIR__ . '/AIPermissions.php';

class AITools
{
    public static function definitions(string $role): array
    {
        $tools = [];

        if (
            AIPermissions::can($role, 'search_products')
                ||
                AIPermissions::can($role, 'search_company_products')
        ) {
            $tools[] = self::searchProducts();
        }

        if (AIPermissions::can($role, 'view_own_orders')) {
            $tools[] = self::getOwnOrder();
        }

        if (AIPermissions::can($role, 'view_own_delivery')) {
            $tools[] = self::getOwnDelivery();
        }

        if (AIPermissions::can($role, 'manage_own_preferences')) {
            $tools[] = self::getCustomerPreferences();
        }

        if (AIPermissions::can($role, 'create_support_ticket')) {
            $tools[] = self::createSupportTicket();
        }

        if (AIPermissions::can($role, 'view_company_orders')) {
            $tools[] = self::getCompanyOrders();
        }

        if (AIPermissions::can($role, 'view_company_reports')) {
            $tools[] = self::getCompanySummary();
        }

        if (AIPermissions::can($role, 'view_assigned_deliveries')) {
            $tools[] = self::getAssignedDeliveries();
        }

        if (AIPermissions::can($role, 'view_platform_companies')) {
            $tools[] = self::getPlatformCompanies();
        }

        if (AIPermissions::can($role, 'view_platform_reports')) {
            $tools[] = self::getPlatformSummary();
        }

        return $tools;
    }

    private static function searchProducts(): array
    {
        return [
            'type' => 'function',
            'name' => 'search_products',
            'description' =>
                'Search products available on the Somame marketplace.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',

                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' =>
                            'Product name or search phrase.'
                    ],

                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 20
                    ]
                ],

                'required' => [
                    'query',
                    'limit'
                ],

                'additionalProperties' => false
            ]
        ];
    }

    private static function getOwnOrder(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_order',
            'description' =>
                'Retrieve an order belonging to the current customer.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',

                'properties' => [
                    'order_id' => [
                        'type' => 'integer'
                    ]
                ],

                'required' => [
                    'order_id'
                ],

                'additionalProperties' => false
            ]
        ];
    }

    private static function getOwnDelivery(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_delivery_status',
            'description' =>
                'Retrieve delivery information for one of the current customer orders.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',

                'properties' => [
                    'order_id' => [
                        'type' => 'integer'
                    ]
                ],

                'required' => [
                    'order_id'
                ],

                'additionalProperties' => false
            ]
        ];
    }

    private static function getCustomerPreferences(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_customer_preferences',
            'description' =>
                'Get shopping and personalization preferences for the current customer.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
                'additionalProperties' => false
            ]
        ];
    }

    private static function createSupportTicket(): array
    {
        return [
            'type' => 'function',
            'name' => 'create_support_ticket',
            'description' =>
                'Create a customer support ticket when assistance from a human may be required.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',

                'properties' => [
                    'subject' => [
                        'type' => 'string'
                    ],

                    'description' => [
                        'type' => 'string'
                    ],

                    'category' => [
                        'type' => 'string',

                        'enum' => [
                            'order',
                            'payment',
                            'refund',
                            'delivery',
                            'account',
                            'product',
                            'subscription',
                            'technical',
                            'other'
                        ]
                    ],

                    'order_id' => [
                        'type' => [
                            'integer',
                            'null'
                        ]
                    ]
                ],

                'required' => [
                    'subject',
                    'description',
                    'category',
                    'order_id'
                ],

                'additionalProperties' => false
            ]
        ];
    }

    private static function getCompanyOrders(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_company_orders',
            'description' =>
                'Retrieve recent orders belonging only to the current company.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',

                'properties' => [
                    'status' => [
                        'type' => [
                            'string',
                            'null'
                        ]
                    ],

                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50
                    ]
                ],

                'required' => [
                    'status',
                    'limit'
                ],

                'additionalProperties' => false
            ]
        ];
    }

    private static function getCompanySummary(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_company_summary',
            'description' =>
                'Get operational summary information for the current company.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
                'additionalProperties' => false
            ]
        ];
    }

    private static function getAssignedDeliveries(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_assigned_deliveries',
            'description' =>
                'Get deliveries assigned to the currently logged-in delivery partner.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
                'additionalProperties' => false
            ]
        ];
    }

    private static function getPlatformCompanies(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_platform_companies',
            'description' =>
                'Retrieve companies on the Somame platform for the super administrator.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',

                'properties' => [
                    'status' => [
                        'type' => [
                            'string',
                            'null'
                        ]
                    ],

                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50
                    ]
                ],

                'required' => [
                    'status',
                    'limit'
                ],

                'additionalProperties' => false
            ]
        ];
    }

    private static function getPlatformSummary(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_platform_summary',
            'description' =>
                'Retrieve high-level Somame platform statistics for the super administrator.',
            'strict' => true,

            'parameters' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
                'additionalProperties' => false
            ]
        ];
    }
}