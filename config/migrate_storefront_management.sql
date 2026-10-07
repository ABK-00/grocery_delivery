CREATE TABLE IF NOT EXISTS company_storefronts (
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

    primary_color VARCHAR(20) NOT NULL DEFAULT '#198754',

    store_status ENUM('open', 'closed')
        NOT NULL DEFAULT 'open',

    delivery_information TEXT DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_company_storefront (company_id),

    CONSTRAINT fk_storefront_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);