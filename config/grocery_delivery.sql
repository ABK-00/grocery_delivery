-- ============================================================
-- Grocery Delivery System - Complete Database Schema
-- Database: grocery_delivery
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
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) DEFAULT NULL,
    whatsapp VARCHAR(30) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff','customer','delivery_partner') NOT NULL DEFAULT 'customer',
    status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_partners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    vehicle_type VARCHAR(50) NOT NULL,
    vehicle_registration VARCHAR(50) NOT NULL,
    status ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_delivery_partner_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(30) NOT NULL DEFAULT 'piece',
    image VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_category (category_id),
    INDEX idx_products_status (status),
    INDEX idx_products_name (name),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    image VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_images_product (product_id),
    INDEX idx_product_images_primary (product_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_product (user_id, product_id),
    CONSTRAINT fk_cart_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    order_number VARCHAR(30) NOT NULL UNIQUE,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_address TEXT NOT NULL,
    delivery_phone VARCHAR(30) NOT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM(
        'pending','confirmed','preparing','ready',
        'out_for_delivery','delivered','cancelled'
    ) NOT NULL DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed','refunded')
        NOT NULL DEFAULT 'pending',
    payment_reference VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_user (user_id),
    INDEX idx_orders_status (status),
    INDEX idx_orders_order_number (order_number),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reference VARCHAR(100) NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'GHS',
    payment_method VARCHAR(50) DEFAULT NULL,
    status ENUM('pending','paid','failed','refunded')
        NOT NULL DEFAULT 'pending',
    gateway VARCHAR(50) NOT NULL DEFAULT 'paystack',
    gateway_response TEXT DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_user (user_id),
    INDEX idx_payments_status (status),
    INDEX idx_payments_reference (reference),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL UNIQUE,
    delivery_partner_id INT UNSIGNED DEFAULT NULL,
    status ENUM(
        'assigned','picked_up','out_for_delivery','delivered','cancelled'
    ) NOT NULL DEFAULT 'assigned',
    assigned_at DATETIME DEFAULT NULL,
    picked_up_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_deliveries_partner FOREIGN KEY (delivery_partner_id)
        REFERENCES delivery_partners(id) ON DELETE SET NULL,
    INDEX idx_deliveries_partner (delivery_partner_id),
    INDEX idx_deliveries_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    CONSTRAINT fk_delivery_locations_delivery FOREIGN KEY (delivery_id)
        REFERENCES deliveries(id) ON DELETE CASCADE,
    INDEX idx_delivery_location_time (delivery_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories (name, description) VALUES
('Fruits & Vegetables', 'Fresh fruits, vegetables and farm produce.'),
('Meat & Poultry', 'Fresh meat, chicken and poultry products.'),
('Fish & Seafood', 'Fresh and frozen fish and seafood.'),
('Dairy & Eggs', 'Milk, cheese, yoghurt, butter and eggs.'),
('Bakery', 'Bread, pastries, cakes and other baked products.'),
('Beverages', 'Water, juices, soft drinks, tea and coffee.'),
('Snacks', 'Biscuits, chips, chocolates and other snacks.'),
('Rice & Grains', 'Rice, cereals, grains and related products.'),
('Canned & Packaged Foods', 'Canned, packaged and ready-to-use foods.'),
('Household Essentials', 'Cleaning products and other household essentials.');

-- ============================================================
-- END
-- ============================================================
