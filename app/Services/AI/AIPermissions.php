<?php

declare(strict_types=1);

class AIPermissions
{
    private const PERMISSIONS = [

        'customer' => [
            'search_products',
            'view_own_orders',
            'view_own_delivery',
            'view_own_refunds',
            'manage_own_preferences',
            'manage_own_addresses',
            'create_support_ticket',
            'view_own_support_tickets',
        ],

        'staff' => [
            'search_company_products',
            'view_company_orders',
            'view_company_deliveries',
            'view_company_customers',
            'view_company_support',
        ],

        'admin' => [
            'search_company_products',
            'view_company_orders',
            'view_company_deliveries',
            'view_company_customers',
            'view_company_support',
            'view_company_reports',
            'view_company_staff',
            'view_company_storefront',
            'view_company_subscription',
        ],

        'delivery_partner' => [
            'view_assigned_deliveries',
            'update_delivery_status',
            'view_delivery_destination',
        ],

        'super_admin' => [
            'view_platform_companies',
            'view_platform_orders',
            'view_platform_payments',
            'view_platform_subscriptions',
            'view_platform_support',
            'view_platform_reports',
            'view_platform_audit_logs',
        ],
    ];

    public static function can(string $role, string $permission): bool
    {
        $role = strtolower(trim($role));

        return in_array(
            $permission,
            self::PERMISSIONS[$role] ?? [],
            true
        );
    }

    public static function permissionsFor(string $role): array
    {
        $role = strtolower(trim($role));

        return self::PERMISSIONS[$role] ?? [];
    }
}
