GroceryDelivery - Multi-Vendor Grocery Delivery SaaS
===================================================

Stack
-----
PHP 8.3+, MySQL 8+, Bootstrap 5, JavaScript, Tailwind CSS 4, TypeScript.

Local development
-----------------
Project root:
C:\laragon\www\somame_ent

Browser:
http://localhost/somame_ent/

Database:
grocery_delivery

Fresh installation
------------------
1. Start Apache and MySQL from Laragon.
2. Import config/grocery_delivery.sql in phpMyAdmin.
3. Check config/db.php for your local MySQL credentials.
4. Create the first Super Admin with super_admin/create_super_admin.php.
5. Configure Paystack locally:
   - Copy config/paystack.local.example.php to config/paystack.local.php
   - Add your own test secret key.
   - Never commit paystack.local.php.
6. Configure AI locally:
   - Copy config/ai.local.example.php to config/ai.local.php
   - Add your OpenAI API key.
   - Never commit ai.local.php.
7. Install frontend dependencies:
   npm install
8. Build frontend assets:
   npm run tw:build
   npm run ts:build

Existing database upgrades
--------------------------
Do NOT import the canonical schema over a database containing data.

Run the relevant incremental migration files from config/ instead.
The current application includes migrations for:
- multi-company architecture
- storefront management
- payment compatibility
- subscription billing
- AI/support/customer preferences
- refunds

Payment expiry
--------------
Pending unpaid orders expire after 15 minutes.

CLI cleanup job:
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe scripts\expire_pending_orders.php

For local development, schedule that command with Windows Task Scheduler.

Production Paystack webhook
---------------------------
Configure Paystack to send webhooks to the public HTTPS URL:

https://YOUR-DOMAIN/YOUR-BASE/api/paystack_webhook.php

The webhook endpoint verifies Paystack's signature before processing events.

Core platform rules
-------------------
- super_admin: platform-level account; company_id is NULL.
- customer: platform-level shopper; company_id is NULL.
- admin/staff/delivery_partner: belong to one vendor company.
- Customers sign in once and can shop from any eligible vendor.
- A cart may contain products from only one vendor at a time.
- Every order belongs to one vendor and one customer.
- Vendor data access is scoped by company_id.
- Stock is reserved when an order is placed and restored idempotently when an unpaid order expires/fails.
- Paid orders move through the fulfillment workflow in sequence.
- Delivery assignment cancellation returns a paid order to Ready; it does not cancel the customer's paid order.
- Paid-order cancellation uses the refund workflow.

AI assistant
------------
The application has role-scoped AI:
- Customers: customer service, order/payment/delivery help, product discovery and optional personalization.
- Admins: company operations assistant.
- Staff: day-to-day order operations assistant.
- Delivery partners: assigned-delivery assistant.
- Super Admins: platform operations/executive assistant.

Customer personalization is opt-in/out from:
customer/preferences.php

When personalization is disabled, saved preferences and purchase patterns are not supplied to the AI recommendation context.

Security notes
--------------
- Secrets are local/environment configuration only.
- Paystack callbacks/webhooks verify transaction information.
- Mutating forms use CSRF protection.
- SQL uses prepared statements for user-controlled values.
- Role/company ownership checks are required for private resources.
- Payment/order creation is idempotent.
- Refund processing is tracked and webhook-driven.
- Never store raw card numbers or CVV values.

Git
---
Repository:
https://github.com/ABK-00/grocery_delivery

Active development branch:
feature/storefront-management
