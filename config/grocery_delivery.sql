-- ============================================================
-- GroceryDelivery - Canonical Fresh-Install Database Schema
-- Database: grocery_delivery
-- Updated: 2026-10-09
--
-- PURPOSE
--   This is the single fresh-install schema for the current app.
--   Clone/pull the repository on a new machine, import this file,
--   configure config/db.php, and the application has the database
--   structure expected by the current codebase.
--
-- WARNING
--   This file is DESTRUCTIVE when imported into an existing
--   grocery_delivery database because it drops the app tables.
--   Back up production/important data first.
-- ============================================================

CREATE DATABASE IF NOT EXISTS grocery_delivery
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE grocery_delivery;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS delivery_locations;
DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS cart;
DROP TABLE IF EXISTS product_images;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS delivery_partners;
DROP TABLE IF EXISTS company_storefronts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS companies;

SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================
-- COMPANIES / VENDORS
-- ============================================================

CREATE TABLE companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    company_code VARCHAR(30) NOT NULL,
    company_name VARCHAR(150) NOT NULL,
    storefront_slug VARCHAR(180) NOT NULL,

    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    whatsapp VARCHAR(30) DEFAULT NULL,
    logo VARCHAR(255) DEFAULT NULL,

    status ENUM(
        'active',
        'suspended',
        'blocked'
    ) NOT NULL DEFAULT 'active',

    subscription_plan ENUM(
        'trial',
        'monthly',
        'quarterly',
        'yearly'
    ) NOT NULL DEFAULT 'trial',

    trial_ends_at DATETIME DEFAULT NULL,
    subscription_starts_at DATETIME DEFAULT NULL,
    subscription_ends_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_companies_code (company_code),
    UNIQUE KEY uq_companies_storefront_slug (storefront_slug),

    INDEX idx_company_status (status),
    INDEX idx_company_subscription (
        subscription_plan,
        subscription_ends_at
    ),
    INDEX idx_company_name (company_name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- USERS
--
-- company_id:
--   NULL for super_admin and platform customers.
--   Vendor company id for admin/staff/delivery_partner.
-- ============================================================

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    company_id INT UNSIGNED DEFAULT NULL,

    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    whatsapp VARCHAR(30) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,

    role ENUM(
        'super_admin',
        'admin',
        'staff',
        'customer',
        'delivery_partner'
    ) NOT NULL DEFAULT 'customer',

    status ENUM(
        'active',
        'inactive',
        'suspended'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_email (email),

    INDEX idx_users_company (company_id),
    INDEX idx_users_role (role),
    INDEX idx_users_status (status),
    INDEX idx_users_company_role (company_id, role),

    CONSTRAINT fk_users_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- COMPANY STOREFRONTS
-- ============================================================

CREATE TABLE company_storefronts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    company_id INT UNSIGNED NOT NULL,

    display_name VARCHAR(150) DEFAULT NULL,
    description TEXT DEFAULT NULL,

    logo VARCHAR(255) DEFAULT NULL,
    banner_image VARCHAR(255) DEFAULT NULL,

    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    whatsapp VARCHAR(30) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,

    primary_color VARCHAR(20)
        NOT NULL DEFAULT '#198754',

    store_status ENUM(
        'open',
        'closed'
    ) NOT NULL DEFAULT 'open',

    delivery_information TEXT DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_company_storefront (company_id),

    CONSTRAINT fk_storefront_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- DELIVERY PARTNERS
-- ============================================================

CREATE TABLE delivery_partners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    company_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    vehicle_type VARCHAR(50) NOT NULL,
    vehicle_registration VARCHAR(50) NOT NULL,

    status ENUM(
        'available',
        'busy',
        'offline'
    ) NOT NULL DEFAULT 'offline',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_delivery_partner_user (user_id),
    UNIQUE KEY uq_company_vehicle_registration (
        company_id,
        vehicle_registration
    ),

    INDEX idx_delivery_partners_company (company_id),
    INDEX idx_delivery_partners_status (company_id, status),

    CONSTRAINT fk_delivery_partner_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_delivery_partner_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- CATEGORIES
-- ============================================================

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    company_id INT UNSIGNED NOT NULL,

    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,

    status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_company_category_name (
        company_id,
        name
    ),

    INDEX idx_categories_company (company_id),
    INDEX idx_categories_status (company_id, status),

    CONSTRAINT fk_categories_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- PRODUCTS
-- ============================================================

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    company_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,

    name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,

    price DECIMAL(10,2)
        NOT NULL DEFAULT 0.00,

    stock DECIMAL(10,2)
        NOT NULL DEFAULT 0.00,

    unit VARCHAR(30)
        NOT NULL DEFAULT 'piece',

    -- Legacy/primary image fallback.
    image VARCHAR(255) DEFAULT NULL,

    status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_company_product_name (
        company_id,
        name
    ),

    INDEX idx_products_company (company_id),
    INDEX idx_products_category (category_id),
    INDEX idx_products_status (company_id, status),
    INDEX idx_products_name (name),

    CONSTRAINT fk_products_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- PRODUCT IMAGE GALLERY
-- ============================================================

CREATE TABLE product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    product_id INT UNSIGNED NOT NULL,

    image VARCHAR(255) NOT NULL,
    is_primary TINYINT(1)
        NOT NULL DEFAULT 0,

    sort_order INT UNSIGNED
        NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_product_images_product (product_id),
    INDEX idx_product_images_primary (
        product_id,
        is_primary
    ),

    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- CUSTOMER CART
--
-- The application enforces one vendor/company per cart.
-- ============================================================

CREATE TABLE cart (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,

    quantity DECIMAL(10,2)
        NOT NULL DEFAULT 1.00,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_cart_user_product (
        user_id,
        product_id
    ),

    INDEX idx_cart_user (user_id),
    INDEX idx_cart_product (product_id),

    CONSTRAINT fk_cart_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_cart_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- ORDERS
-- ============================================================

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    company_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    order_number VARCHAR(40) NOT NULL,

    subtotal DECIMAL(10,2)
        NOT NULL DEFAULT 0.00,

    delivery_fee DECIMAL(10,2)
        NOT NULL DEFAULT 0.00,

    total_amount DECIMAL(10,2)
        NOT NULL DEFAULT 0.00,

    delivery_address TEXT NOT NULL,
    delivery_phone VARCHAR(30) NOT NULL,
    notes TEXT DEFAULT NULL,

    status ENUM(
        'pending',
        'confirmed',
        'preparing',
        'ready',
        'out_for_delivery',
        'delivered',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    payment_status ENUM(
        'pending',
        'paid',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending',

    payment_reference VARCHAR(100) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_orders_order_number (order_number),

    INDEX idx_orders_company (company_id),
    INDEX idx_orders_user (user_id),
    INDEX idx_orders_status (company_id, status),
    INDEX idx_orders_payment_status (
        company_id,
        payment_status
    ),
    INDEX idx_orders_created_at (
        company_id,
        created_at
    ),
    INDEX idx_orders_payment_reference (
        payment_reference
    ),

    CONSTRAINT fk_orders_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- ORDER ITEMS
-- ============================================================

CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,

    product_name VARCHAR(150) NOT NULL,

    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_product (product_id),

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- PAYMENTS
-- ============================================================

CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    reference VARCHAR(100) NOT NULL,

    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10)
        NOT NULL DEFAULT 'GHS',

    payment_method VARCHAR(50) DEFAULT NULL,

    status ENUM(
        'pending',
        'paid',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending',

    gateway VARCHAR(50)
        NOT NULL DEFAULT 'paystack',

    gateway_response LONGTEXT DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_payments_reference (reference),

    INDEX idx_payments_order (order_id),
    INDEX idx_payments_user (user_id),
    INDEX idx_payments_status (status),

    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_payments_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- DELIVERIES
-- ============================================================

CREATE TABLE deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL,
    delivery_partner_id INT UNSIGNED DEFAULT NULL,

    status ENUM(
        'pending',
        'assigned',
        'picked_up',
        'out_for_delivery',
        'delivered',
        'cancelled'
    ) NOT NULL DEFAULT 'assigned',

    assigned_at DATETIME DEFAULT NULL,
    picked_up_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_deliveries_order (order_id),

    INDEX idx_deliveries_partner (
        delivery_partner_id
    ),
    INDEX idx_deliveries_status (status),

    CONSTRAINT fk_deliveries_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_deliveries_partner
        FOREIGN KEY (delivery_partner_id)
        REFERENCES delivery_partners(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- LIVE DELIVERY LOCATIONS
-- ============================================================

CREATE TABLE delivery_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    delivery_id INT UNSIGNED NOT NULL,

    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,

    accuracy DECIMAL(10,2) DEFAULT NULL,
    speed DECIMAL(10,2) DEFAULT NULL,
    heading DECIMAL(10,2) DEFAULT NULL,

    recorded_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_delivery_location_time (
        delivery_id,
        recorded_at
    ),

    CONSTRAINT fk_delivery_locations_delivery
        FOREIGN KEY (delivery_id)
        REFERENCES deliveries(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- FRESH-INSTALL NOTES
--
-- 1. No vendor-specific categories are seeded here because
--    categories now belong to individual companies.
-- 2. Customers must keep company_id = NULL.
-- 3. super_admin accounts must keep company_id = NULL.
-- 4. Vendor admin/staff/delivery_partner accounts require the
--    appropriate company_id.
-- 5. Create the first super admin using the application's
--    super_admin/create_super_admin.php helper.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF CANONICAL SCHEMA
-- ============================================================
