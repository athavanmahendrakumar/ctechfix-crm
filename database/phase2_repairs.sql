-- ============================================================
-- C Tech Fix CRM — Phase 2: Repair Operations
-- Import this file in phpMyAdmin after phase1_calls_sms.sql
-- ============================================================

-- ── Customers (extend from Phase 1 stub) ───────────────────
-- Add any missing columns if customers table already exists
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS email          VARCHAR(255)  NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS notes          TEXT          NULL AFTER email,
    ADD COLUMN IF NOT EXISTS is_training    TINYINT(1)    NOT NULL DEFAULT 0 AFTER notes;

-- ── Repairs ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS repairs (
    id                  INT             NOT NULL AUTO_INCREMENT,
    record_number       VARCHAR(30)     NOT NULL,
    location_id         INT             NOT NULL,
    customer_id         INT             NOT NULL,

    -- Device
    device_brand        VARCHAR(100)    NOT NULL,
    device_model        VARCHAR(150)    NOT NULL,
    device_color        VARCHAR(50)     NULL,
    device_serial       VARCHAR(100)    NULL,
    device_imei         VARCHAR(20)     NULL,
    device_passcode     VARCHAR(255)    NULL,   -- stored as-is; warn staff it's visible

    -- Accessories received with device
    accessories         VARCHAR(500)    NULL,   -- e.g. "Charger, Case, SIM tool"

    -- Issue & diagnosis
    issue_description   TEXT            NOT NULL,
    diagnosis_notes     TEXT            NULL,

    -- Pricing
    estimated_cost      DECIMAL(10,2)   NULL,
    final_cost          DECIMAL(10,2)   NULL,
    discount_amount     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,

    -- Status
    status              ENUM(
                            'received',
                            'diagnosed',
                            'in_repair',
                            'waiting_parts',
                            'ready_pickup',
                            'completed',
                            'cancelled'
                        ) NOT NULL DEFAULT 'received',

    -- Assignment
    assigned_to         INT             NULL,   -- user_id of technician

    -- Public status token (for customer-facing status page)
    status_token        VARCHAR(64)     NOT NULL,

    -- Payment
    payment_method      ENUM('cash','debit','credit','e_transfer','warranty','insurance','other') NULL,
    payment_notes       VARCHAR(255)    NULL,
    paid_at             DATETIME        NULL,

    -- Key timestamps
    received_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estimated_ready_at  DATETIME        NULL,
    completed_at        DATETIME        NULL,

    -- Flags
    is_training         TINYINT(1)      NOT NULL DEFAULT 0,
    sms_opt_out         TINYINT(1)      NOT NULL DEFAULT 0,  -- don't auto-SMS this customer

    -- Audit
    created_by          INT             NOT NULL,
    updated_by          INT             NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE  KEY uq_record_number (record_number),
    UNIQUE  KEY uq_status_token  (status_token),
    KEY     idx_location         (location_id),
    KEY     idx_customer         (customer_id),
    KEY     idx_status           (status),
    KEY     idx_assigned         (assigned_to),
    KEY     idx_received_at      (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Repair Status History ───────────────────────────────────
CREATE TABLE IF NOT EXISTS repair_status_history (
    id              INT             NOT NULL AUTO_INCREMENT,
    repair_id       INT             NOT NULL,
    old_status      VARCHAR(50)     NULL,
    new_status      VARCHAR(50)     NOT NULL,
    notes           TEXT            NULL,
    sms_sent        TINYINT(1)      NOT NULL DEFAULT 0,
    sms_message_id  INT             NULL,
    changed_by      INT             NOT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_rsh_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Repair Parts Used ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS repair_parts (
    id              INT             NOT NULL AUTO_INCREMENT,
    repair_id       INT             NOT NULL,
    part_name       VARCHAR(255)    NOT NULL,
    part_sku        VARCHAR(100)    NULL,
    quantity        INT             NOT NULL DEFAULT 1,
    cost_price      DECIMAL(10,2)   NULL,
    sell_price      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    added_by        INT             NOT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_rp_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Repair Notes ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS repair_notes (
    id              INT             NOT NULL AUTO_INCREMENT,
    repair_id       INT             NOT NULL,
    note            TEXT            NOT NULL,
    is_internal     TINYINT(1)      NOT NULL DEFAULT 1,
    created_by      INT             NOT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_rn_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Repair Photos ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS repair_photos (
    id              INT             NOT NULL AUTO_INCREMENT,
    repair_id       INT             NOT NULL,
    filename        VARCHAR(255)    NOT NULL,
    original_name   VARCHAR(255)    NULL,
    caption         VARCHAR(255)    NULL,
    uploaded_by     INT             NOT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_rph_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed: record sequence for repairs ──────────────────────
INSERT IGNORE INTO record_sequences (location_code, record_type, year, last_number)
VALUES
    ('OS', 'R', YEAR(NOW()), 0),
    ('PF', 'R', YEAR(NOW()), 0);
