GroceryGo - Grocery Delivery App
Stack: PHP, MySQL, HTML, CSS, Bootstrap 5, JavaScript.

INSTALL:
1. Copy this folder to C:\xampp\htdocs\grocery_delivery
2. Create the database using the SQL schema from the project setup.
3. Check config/db.php (default XAMPP root password is blank).
4. Start Apache and MySQL.
5. Open http://localhost/grocery_delivery/test_db.php
6. Register a customer at register.php.

Roles: admin, staff, customer, delivery_partner.
Admin/staff/delivery accounts should be created by an administrator in the completed system.

PHASE 6 - CUSTOMER / STAFF / DELIVERY TENANT ISOLATION
-----------------------------------------------------
- Customer product browsing and product details are company-scoped.
- Cart add/update/remove validates product ownership against the customer's company.
- Checkout and order placement only use products belonging to the customer's company.
- New orders now store company_id.
- Customer order list/details/tracking and GPS retrieval validate company ownership.
- Customer self-registration now requires a valid active company code and stores company_id.
- Staff order listing/details are company-scoped; order locks and driver assignment validate tenant ownership.
- Delivery partner lists/details/tracking are scoped to the driver's company.
- GPS update API validates delivery -> order -> company ownership.
- Payment initialization validates customer + order + company ownership.
- PHP syntax validation was run across the project.

IMPORTANT: Run config/migrate_multicompany.sql on an existing pre-SaaS database before using this phase.
