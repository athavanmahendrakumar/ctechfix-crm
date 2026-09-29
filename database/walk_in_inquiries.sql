-- ============================================================
-- Walk-in Inquiries — customers who need a callback / quote
-- Run in phpMyAdmin on ctrecfkn_ctechfix_crm
-- ============================================================

CREATE TABLE IF NOT EXISTS walk_in_inquiries (
    id               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id      INT(10) UNSIGNED NOT NULL,
    logged_by        INT(10) UNSIGNED NOT NULL,
    customer_name    VARCHAR(100)     NOT NULL,
    customer_phone   VARCHAR(20)      NOT NULL,
    device_brand     VARCHAR(50)      NULL,
    device_model     VARCHAR(100)     NULL,
    description      TEXT             NULL,
    estimated_price  DECIMAL(8,2)     NULL,
    status           ENUM('pending','quoted','converted','lost') NOT NULL DEFAULT 'pending',
    repair_id        INT(10) UNSIGNED NULL,
    notes            TEXT             NULL,
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_location (location_id),
    INDEX idx_status   (status),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
