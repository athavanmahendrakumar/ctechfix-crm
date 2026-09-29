-- ============================================================
-- Phase 4: Sales, Finance & P&L
-- Run this in phpMyAdmin on ctrecfkn_crm database
-- ============================================================

-- ------------------------------------------------------------
-- 1. Settings (key/value store)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100)     NOT NULL UNIQUE,
    value       TEXT             NULL,
    updated_by  INT(10) UNSIGNED NULL,
    updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default settings
INSERT INTO settings (setting_key, value) VALUES
    ('business_name',    'C Tech Fix'),
    ('business_phone',   ''),
    ('business_email',   ''),
    ('business_address', ''),
    ('logo_path',        ''),
    ('tax_rate',         '13')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ------------------------------------------------------------
-- 2. Expense Categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expense_categories (
    id         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100)     NOT NULL UNIQUE,
    is_active  TINYINT(1)       NOT NULL DEFAULT 1,
    created_by INT(10) UNSIGNED NULL,
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preset categories
INSERT INTO expense_categories (name) VALUES
    ('Rent'),
    ('Utilities'),
    ('Salaries & Wages'),
    ('Supplies & Parts'),
    ('Marketing & Advertising'),
    ('Equipment'),
    ('Insurance'),
    ('Phone & Internet'),
    ('Software & Subscriptions'),
    ('Professional Services'),
    ('Shipping & Courier'),
    ('Miscellaneous')
ON DUPLICATE KEY UPDATE name = name;

-- ------------------------------------------------------------
-- 3. Expenses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id     INT(10) UNSIGNED NOT NULL,
    category_id     INT(10) UNSIGNED NOT NULL,
    amount          DECIMAL(10,2)    NOT NULL,
    description     VARCHAR(255)     NULL,
    expense_date    DATE             NOT NULL,
    receipt_path    VARCHAR(255)     NULL,
    notes           TEXT             NULL,
    created_by      INT(10) UNSIGNED NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      DATETIME         NULL,
    deleted_by      INT(10) UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. Sales (walk-in / point of sale)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_number   VARCHAR(30)      NOT NULL UNIQUE,
    location_id     INT(10) UNSIGNED NOT NULL,
    customer_id     INT(10) UNSIGNED NULL,
    subtotal        DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    total_amount    DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    payment_method  ENUM('cash','card','e-transfer','other') NOT NULL DEFAULT 'cash',
    notes           TEXT             NULL,
    created_by      INT(10) UNSIGNED NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. Sale Items (line items per sale)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_items (
    id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id             INT(10) UNSIGNED NOT NULL,
    inventory_item_id   INT(10) UNSIGNED NULL,
    item_name           VARCHAR(255)     NOT NULL,
    item_sku            VARCHAR(100)     NULL,
    quantity            INT(10)          NOT NULL DEFAULT 1,
    unit_cost           DECIMAL(10,2)    NULL,
    unit_price          DECIMAL(10,2)    NOT NULL,
    line_total          DECIMAL(10,2)    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. Record number sequence (reuse existing if present)
-- ------------------------------------------------------------
INSERT IGNORE INTO record_sequences (prefix, last_number, year) VALUES ('SALE', 0, YEAR(NOW()));
