-- ============================================================
-- Phase 7: Barcodes, Used Devices, Device Maintenance
-- Run in phpMyAdmin on ctrecfkn_ctechfix_crm
-- ============================================================

-- ── 1. Add barcode to existing inventory items ───────────────
ALTER TABLE inventory_items ADD COLUMN barcode VARCHAR(20) NULL AFTER sku;
ALTER TABLE inventory_items ADD UNIQUE INDEX idx_barcode (barcode);

-- ── 2. Used Devices (IMEI/SN tracked, one row per unit) ──────
CREATE TABLE IF NOT EXISTS used_devices (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id     INT(10) UNSIGNED NOT NULL,
    barcode         VARCHAR(20)      NULL,
    device_type     ENUM('Phone','Tablet','Laptop','Computer','Gaming Console') NOT NULL,
    device_brand    VARCHAR(50)      NOT NULL,
    device_model    VARCHAR(100)     NOT NULL,
    color           VARCHAR(30)      NULL,
    storage         VARCHAR(30)      NULL,
    imei            VARCHAR(20)      NULL,
    serial_number   VARCHAR(50)      NULL,
    condition_grade ENUM('Excellent','Good','Fair') NOT NULL DEFAULT 'Good',
    source          ENUM('vendor','walk_in') NOT NULL DEFAULT 'vendor',
    vendor_name     VARCHAR(100)     NULL,
    purchase_price  DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    selling_price   DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    warranty        ENUM('none','30_days','60_days','90_days','custom') NOT NULL DEFAULT 'none',
    warranty_days   INT(4)           NULL,
    status          ENUM('in_stock','sold','returned','scrapped') NOT NULL DEFAULT 'in_stock',
    sale_id         INT(10) UNSIGNED NULL,
    sold_at         DATETIME         NULL,
    notes           TEXT             NULL,
    added_by        INT(10) UNSIGNED NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_location  (location_id),
    INDEX idx_status    (status),
    INDEX idx_imei      (imei),
    UNIQUE INDEX idx_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. Device Maintenance ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS device_maintenance (
    id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_number       VARCHAR(30)      NOT NULL,
    location_id         INT(10) UNSIGNED NOT NULL,
    customer_id         INT(10) UNSIGNED NOT NULL,
    device_type         VARCHAR(50)      NOT NULL,
    device_brand        VARCHAR(50)      NOT NULL,
    device_model        VARCHAR(100)     NOT NULL,
    maintenance_type    VARCHAR(80)      NOT NULL,
    notes               TEXT             NULL,
    cost                DECIMAL(10,2)    NULL,
    performed_by        INT(10) UNSIGNED NULL,
    performed_at        DATE             NOT NULL,
    follow_up_months    TINYINT(3)       NOT NULL DEFAULT 6,
    follow_up_date      DATE             NOT NULL,
    follow_up_sms_sent  TINYINT(1)       NOT NULL DEFAULT 0,
    follow_up_sent_at   DATETIME         NULL,
    status              ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
    created_by          INT(10) UNSIGNED NOT NULL,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_location    (location_id),
    INDEX idx_customer    (customer_id),
    INDEX idx_follow_up   (follow_up_date, follow_up_sms_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
