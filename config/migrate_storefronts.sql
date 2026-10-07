-- GroceryDelivery company storefront migration
-- Run once after migrate_multicompany.sql.
USE grocery_delivery;

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS storefront_slug VARCHAR(180) DEFAULT NULL AFTER company_name;

-- Backfill stable slugs from company code for existing companies.
UPDATE companies
SET storefront_slug = LOWER(REPLACE(REPLACE(company_code, ' ', '-'), '_', '-'))
WHERE storefront_slug IS NULL OR storefront_slug = '';

ALTER TABLE companies
    ADD UNIQUE KEY uq_companies_storefront_slug (storefront_slug);
