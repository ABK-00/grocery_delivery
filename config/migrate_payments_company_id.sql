-- GroceryDelivery legacy payments table compatibility migration
-- Safe for existing local databases.
USE grocery_delivery;

-- The current application derives the vendor/company from orders.company_id.
-- Legacy databases may still have payments.company_id as NOT NULL, which
-- prevents checkout because current payment inserts intentionally omit it.
ALTER TABLE payments
    MODIFY company_id INT UNSIGNED NULL DEFAULT NULL;

-- Keep existing legacy values intact. New rows may leave company_id NULL.
