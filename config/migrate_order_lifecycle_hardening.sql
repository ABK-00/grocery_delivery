-- Somame order lifecycle hardening
-- 1) Lock vendor cancellation/refund before READY
-- 2) Add vendor pending-SLA escalation outbox
-- 3) Add failed-delivery return-to-vendor workflow

ALTER TABLE orders
    MODIFY status ENUM(
        'pending',
        'confirmed',
        'preparing',
        'ready',
        'out_for_delivery',
        'delivery_failed',
        'returning_to_vendor',
        'return_pending_confirmation',
        'returned',
        'delivered',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN vendor_sla_expires_at DATETIME NULL AFTER stock_released_at,
    ADD COLUMN vendor_escalated_at DATETIME NULL AFTER vendor_sla_expires_at,
    ADD INDEX idx_orders_vendor_sla (
        status,
        payment_status,
        vendor_escalated_at,
        vendor_sla_expires_at
    );

ALTER TABLE deliveries
    MODIFY status ENUM(
        'pending',
        'assigned',
        'picked_up',
        'out_for_delivery',
        'delivery_failed',
        'returning_to_vendor',
        'return_pending_confirmation',
        'returned',
        'delivered',
        'cancelled'
    ) NOT NULL DEFAULT 'assigned',
    ADD COLUMN failure_reason TEXT NULL AFTER delivered_at,
    ADD COLUMN failed_at DATETIME NULL AFTER failure_reason,
    ADD COLUMN return_started_at DATETIME NULL AFTER failed_at,
    ADD COLUMN return_pending_at DATETIME NULL AFTER return_started_at,
    ADD COLUMN returned_at DATETIME NULL AFTER return_pending_at,
    ADD COLUMN return_confirmed_by INT UNSIGNED NULL AFTER returned_at,
    ADD COLUMN return_notes TEXT NULL AFTER return_confirmed_by,
    ADD CONSTRAINT fk_deliveries_return_confirmed_by
        FOREIGN KEY (return_confirmed_by)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS delivery_return_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    quantity_expected DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity_received DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity_restocked DECIMAL(10,2) NOT NULL DEFAULT 0,
    item_condition ENUM(
        'sellable',
        'damaged',
        'opened',
        'perishable',
        'missing'
    ) NOT NULL DEFAULT 'sellable',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_return_item (delivery_id, order_item_id),
    KEY idx_return_items_delivery (delivery_id),
    CONSTRAINT fk_return_items_delivery
        FOREIGN KEY (delivery_id)
        REFERENCES deliveries(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_return_items_order_item
        FOREIGN KEY (order_item_id)
        REFERENCES order_items(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(120) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    company_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    dedupe_key VARCHAR(190) NULL,
    payload JSON NOT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_realtime_events_dedupe (dedupe_key),
    KEY idx_realtime_events_channel (channel, processed_at, id),
    KEY idx_realtime_events_company (company_id, processed_at, id),
    CONSTRAINT fk_realtime_events_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_realtime_events_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
