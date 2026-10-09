# GroceryDelivery database setup

The canonical fresh-install database schema is:

`config/grocery_delivery.sql`

This file now represents the database structure expected by the current multi-company GroceryDelivery application.

## New computer / fresh XAMPP setup

1. Clone or pull the project.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin.
4. Import `config/grocery_delivery.sql`.
5. Check `config/db.php` and make sure the local MySQL host, database name, username and password are correct.
6. Create the first platform super administrator using `super_admin/create_super_admin.php`.
7. Create vendors from the Super Admin portal. Vendor storefronts and first vendor-admin accounts are created by the application.

## Important architecture rules

- `super_admin` users have `company_id = NULL`.
- Platform `customer` users have `company_id = NULL`.
- Vendor `admin`, `staff` and `delivery_partner` users belong to a company.
- Categories, products and orders are company-scoped.
- Delivery partners are also directly company-scoped.
- An order belongs to one vendor and one platform customer.
- A customer cart is limited by application logic to products from one vendor at a time.

## Existing database

Do **not** import `grocery_delivery.sql` over an existing database that contains data unless you intentionally want a clean reset. The canonical schema drops the application tables before recreating them.

The older `migrate_*.sql` files remain in the repository for historical/incremental upgrade work. For a completely new installation, use only `grocery_delivery.sql`.

## What Git does and does not sync

Git syncs the SQL schema files and application code. It does not synchronize live MariaDB/MySQL rows between computers.

For development data that must move between machines, export a separate database dump from phpMyAdmin or `mysqldump`. Do not commit production/customer data or secrets to Git.
