-- Subscription billing foundation for existing databases.
USE grocery_delivery;

CREATE TABLE IF NOT EXISTS subscription_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    plan ENUM('monthly','quarterly','yearly') NOT NULL,
    reference VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'GHS',
    status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    authorization_url TEXT DEFAULT NULL,
    gateway_response LONGTEXT DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subscription_payment_reference (reference),
    INDEX idx_subscription_payments_company (company_id, created_at),
    INDEX idx_subscription_payments_status (status),
    CONSTRAINT fk_subscription_payment_company
        FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_subscription_payment_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_settings (setting_key, setting_value)
VALUES
    ('subscription_monthly_price', NULL),
    ('subscription_quarterly_price', NULL),
    ('subscription_yearly_price', NULL)
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
