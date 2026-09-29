-- ============================================================
-- Bookings — Customer-facing parts/repair appointment requests
-- Run in phpMyAdmin on ctrecfkn_ctechfix_crm
-- ============================================================

CREATE TABLE IF NOT EXISTS bookings (
    id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id         INT(10) UNSIGNED NOT NULL,
    customer_id         INT(10) UNSIGNED NULL,
    customer_name       VARCHAR(100)     NOT NULL,
    customer_phone      VARCHAR(20)      NOT NULL,
    customer_email      VARCHAR(150)     NULL,
    device_type         VARCHAR(50)      NOT NULL,
    device_brand        VARCHAR(50)      NULL,
    device_model        VARCHAR(100)     NULL,
    issue_description   TEXT             NOT NULL,
    booking_date        DATE             NOT NULL,
    time_preference     ENUM('morning','afternoon') NOT NULL DEFAULT 'morning',
    status              ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
    staff_notes         TEXT             NULL,
    confirmed_by        INT(10) UNSIGNED NULL,
    confirmed_at        DATETIME         NULL,
    cancellation_reason VARCHAR(255)     NULL,
    sms_sent            TINYINT(1)       NOT NULL DEFAULT 0,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_location (location_id),
    INDEX idx_date (booking_date),
    INDEX idx_status (status),
    INDEX idx_phone (customer_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default store hours for each location (update location IDs if needed)
-- These get inserted into settings table — run after locations exist
INSERT INTO settings (setting_key, location_id, value)
SELECT 'store_open_time',  id, '11:00' FROM locations WHERE is_active=1
ON DUPLICATE KEY UPDATE value=value;

INSERT INTO settings (setting_key, location_id, value)
SELECT 'store_close_time', id, '21:30' FROM locations WHERE is_active=1
ON DUPLICATE KEY UPDATE value=value;

INSERT INTO settings (setting_key, location_id, value)
SELECT 'store_booking_buffer', id, '30' FROM locations WHERE is_active=1
ON DUPLICATE KEY UPDATE value=value;

-- Days open: 1=Mon,2=Tue,3=Wed,4=Thu,5=Fri,6=Sat (PHP date('N') format, 7=Sun)
INSERT INTO settings (setting_key, location_id, value)
SELECT 'store_open_days', id, '1,2,3,4,5,6' FROM locations WHERE is_active=1
ON DUPLICATE KEY UPDATE value=value;
