# GroceryDelivery database setup

The canonical fresh-install schema is:

`config/grocery_delivery.sql`

It represents the database structure expected by the current multi-vendor application.

## Fresh Laragon setup

1. Put the project at `C:\laragon\www\somame_ent`.
2. Start Apache and MySQL from Laragon.
3. Open phpMyAdmin.
4. Import `config/grocery_delivery.sql`.
5. Confirm `config/db.php` points to the `grocery_delivery` database.
6. Create the first platform super administrator with `super_admin/create_super_admin.php`.
7. Configure local Paystack and AI secrets using the ignored `*.local.php` files.

## Existing database

Do **not** import `grocery_delivery.sql` over an existing database containing useful data. It drops application tables before recreating them.

Apply incremental migrations instead. Current migrations include:

- `config/migrate_multicompany.sql`
- `config/migrate_storefront_management.sql`
- `config/migrate_storefronts.sql`
- `config/migrate_payments_company_id.sql`
- `config/migrate_subscription_billing.sql`
- `config/migrate_ai_support.sql`
- `config/migrate_refunds.sql`

Only run migrations that have not already been applied to that database.

## Architecture rules

- `super_admin.company_id = NULL`
- `customer.company_id = NULL`
- vendor `admin`, `staff`, and `delivery_partner` users require a company
- categories/products/orders are company-scoped
- a customer cart contains one vendor at a time
- an order belongs to one vendor and one platform customer
- payment and refund records are linked to orders
- customer AI personalization can be disabled

## Git vs database data

Git synchronizes source code and schema/migration files. It does not synchronize live MySQL rows.

Use a database export when development data must be moved between machines. Never commit production/customer data or secret keys.
