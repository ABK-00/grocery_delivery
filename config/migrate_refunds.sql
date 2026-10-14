-- Refund workflow + webhook support
USE grocery_delivery;

CREATE TABLE IF NOT EXISTS refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED DEFAULT NULL,
    gateway_refund_id VARCHAR(100) DEFAULT NULL,
    refund_reference VARCHAR(120) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'GHS',
    status ENUM(
        'pending',
        'processing',
        'needs_attention',
        'processed',
        'failed'
    ) NOT NULL DEFAULT 'pending',
    reason VARCHAR(255) DEFAULT NULL,
    gateway_response LONGTEXT DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_refunds_payment (payment_id),
    INDEX idx_refunds_order (order_id),
    INDEX idx_refunds_status (status),

    CONSTRAINT fk_refunds_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_refunds_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_refunds_requested_by
        FOREIGN KEY (requested_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
