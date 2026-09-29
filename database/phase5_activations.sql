-- ============================================================
-- Phase 5: Wireless Prepaid Activations
-- Run in phpMyAdmin on ctrecfkn_ctechfix_crm
-- NOTE: Do NOT run phase5_attendance_v2.sql — that is superseded by this.
-- ============================================================

CREATE TABLE IF NOT EXISTS activations (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id     INT(10) UNSIGNED NOT NULL,
    user_id         INT(10) UNSIGNED NOT NULL,   -- staff who did the activation
    sale_id         INT(10) UNSIGNED NULL,        -- optional link to a sale record
    carrier         VARCHAR(50)      NOT NULL,    -- Chatr | Fizz | Freedom Prepaid | Koodo Prepaid
    customer_phone  VARCHAR(20)      NOT NULL,    -- phone number being activated
    plan_type       ENUM('monthly','3-month','yearly') NOT NULL DEFAULT 'monthly',
    plan_amount     DECIMAL(8,2)     NOT NULL,    -- plan cost e.g. 35.00
    commission      DECIMAL(8,2)     NOT NULL DEFAULT 0.00,
    -- Commission rule: monthly plan >= $30 = $5, all other plan types = $0
    activation_date DATE             NOT NULL,
    notes           VARCHAR(255)     NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user      (user_id),
    INDEX idx_location  (location_id),
    INDEX idx_date      (activation_date),
    INDEX idx_sale      (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If you already ran the original SQL, run this ALTER instead of the CREATE above:
-- ALTER TABLE activations
--     ADD COLUMN plan_type ENUM('monthly','3-month','yearly') NOT NULL DEFAULT 'monthly'
--         AFTER plan_amount;
-- UPDATE activations SET plan_type='monthly'; -- backfill existing rows
