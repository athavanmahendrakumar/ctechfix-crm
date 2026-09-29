-- ============================================================
-- Phase 5: Sales Targets
-- Run this in phpMyAdmin on ctrecfkn_ctechfix_crm database
-- ============================================================

CREATE TABLE IF NOT EXISTS sales_targets (
    id            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id   INT(10) UNSIGNED NOT NULL,
    period_type   ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'monthly',
    target_amount DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    target_repairs INT(10) UNSIGNED NOT NULL DEFAULT 0,
    target_sales   INT(10) UNSIGNED NOT NULL DEFAULT 0,
    effective_from DATE             NOT NULL,
    notes         VARCHAR(255)     NULL,
    created_by    INT(10) UNSIGNED NOT NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_target (location_id, period_type, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
