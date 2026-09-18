-- GroceryDelivery multi-company/SaaS migration
-- IMPORTANT: back up grocery_delivery before running this file.
USE grocery_delivery;

CREATE TABLE IF NOT EXISTS companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_code VARCHAR(30) NOT NULL UNIQUE,
    company_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    whatsapp VARCHAR(30) DEFAULT NULL,
    logo VARCHAR(255) DEFAULT NULL,
    status ENUM('active','suspended','blocked') NOT NULL DEFAULT 'active',
    subscription_plan ENUM('trial','monthly','quarterly','yearly') NOT NULL DEFAULT 'trial',
    trial_ends_at DATETIME DEFAULT NULL,
    subscription_starts_at DATETIME DEFAULT NULL,
    subscription_ends_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company_status (status),
    INDEX idx_company_subscription (subscription_plan, subscription_ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO companies
(company_code, company_name, email, status, subscription_plan, trial_ends_at)
SELECT 'DEFAULT001', 'Default Grocery Company', 'admin@local.test', 'active', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE NOT EXISTS (SELECT 1 FROM companies LIMIT 1);

ALTER TABLE users
    MODIFY role ENUM('super_admin','admin','staff','customer','delivery_partner') NOT NULL DEFAULT 'customer';

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED DEFAULT NULL AFTER id;

UPDATE users
SET company_id = (SELECT id FROM companies ORDER BY id ASC LIMIT 1)
WHERE company_id IS NULL AND role <> 'super_admin';

ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED DEFAULT NULL AFTER id;

UPDATE categories SET company_id = (SELECT id FROM companies ORDER BY id ASC LIMIT 1) WHERE company_id IS NULL;
UPDATE products SET company_id = (SELECT id FROM companies ORDER BY id ASC LIMIT 1) WHERE company_id IS NULL;
UPDATE orders SET company_id = (SELECT id FROM companies ORDER BY id ASC LIMIT 1) WHERE company_id IS NULL;

-- Category names only need to be unique inside a company.
ALTER TABLE categories DROP INDEX name;
ALTER TABLE categories ADD UNIQUE KEY uq_company_category_name (company_id, name);

ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_company (company_id);
ALTER TABLE categories ADD INDEX IF NOT EXISTS idx_categories_company (company_id);
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_company (company_id);
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_company (company_id);

-- MariaDB will reject duplicate FK names, so run these only once.
ALTER TABLE users ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT;
ALTER TABLE categories ADD CONSTRAINT fk_categories_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;
ALTER TABLE products ADD CONSTRAINT fk_products_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;
ALTER TABLE orders ADD CONSTRAINT fk_orders_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT;
