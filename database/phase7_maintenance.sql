-- ============================================================
-- Phase 7: Device Maintenance Module
-- ============================================================

CREATE TABLE IF NOT EXISTS device_maintenance (
    id              INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id     INT(10) UNSIGNED NOT NULL,
    customer_id     INT(10) UNSIGNED NULL,
    -- Customer info (stored directly so it persists if customer record changes)
    customer_name   VARCHAR(100) NOT NULL,
    customer_phone  VARCHAR(30)  NOT NULL,
    -- Device info
    device_type     ENUM('Gaming Console','Computer','Gaming Computer','Phone','Tablet','Laptop','Other') NOT NULL,
    device_brand    VARCHAR(60)  NULL,
    device_model    VARCHAR(100) NULL,
    -- Maintenance
    maintenance_type ENUM(
        'Gaming Console Deep Clean',
        'Computer Tune Up',
        'Gaming Computer Maintenance',
        'Phone Plan Renewal',
        'Battery Change Reminder',
        'Other'
    ) NOT NULL,
    maintenance_notes TEXT NULL,
    service_date    DATE NOT NULL,
    -- Follow-up
    follow_up_months TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '3, 6, or 12',
    follow_up_date   DATE NOT NULL,
    follow_up_sms_sent TINYINT(1) NOT NULL DEFAULT 0,
    follow_up_sms_sent_at DATETIME NULL,
    -- Meta
    created_by      INT(10) UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT NOW(),
    updated_at      DATETIME NULL ON UPDATE NOW(),
    INDEX idx_location   (location_id),
    INDEX idx_customer   (customer_id),
    INDEX idx_follow_up  (follow_up_date, follow_up_sms_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
