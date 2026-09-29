-- ============================================================
-- Phase 6: Activation Commission Pipeline + Cash Drawer + Inventory Counts
-- Run in phpMyAdmin on ctrecfkn_ctechfix_crm
-- ============================================================

-- ── 1. Activation commission pipeline columns ────────────────
ALTER TABLE activations
    ADD COLUMN IF NOT EXISTS status ENUM('submitted','manager_verified','pending_45day','payable','paid','cancelled')
        NOT NULL DEFAULT 'submitted' AFTER commission,
    ADD COLUMN IF NOT EXISTS verified_by   INT(10) UNSIGNED NULL AFTER status,
    ADD COLUMN IF NOT EXISTS verified_at   DATETIME         NULL AFTER verified_by,
    ADD COLUMN IF NOT EXISTS payable_at    DATETIME         NULL AFTER verified_at,
    ADD COLUMN IF NOT EXISTS paid_by       INT(10) UNSIGNED NULL AFTER payable_at,
    ADD COLUMN IF NOT EXISTS paid_at       DATETIME         NULL AFTER paid_by,
    ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255)     NULL AFTER paid_at;

-- Mark all existing paid-commission activations as payable (they were already approved implicitly)
UPDATE activations SET status = 'payable' WHERE commission > 0 AND status = 'submitted';
UPDATE activations SET status = 'submitted' WHERE commission = 0 AND status = 'submitted';

-- ── 2. Cash drawers ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cash_drawers (
    id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id         INT(10) UNSIGNED NOT NULL,
    drawer_date         DATE             NOT NULL,
    opening_amount      DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    opened_by           INT(10) UNSIGNED NOT NULL,
    opened_at           DATETIME         NOT NULL,
    cash_sales          DECIMAL(10,2)    NOT NULL DEFAULT 0.00,  -- auto-pulled from sales
    cash_deposits       DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    cash_payouts        DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    expected_close      DECIMAL(10,2)    NULL,
    actual_close        DECIMAL(10,2)    NULL,
    variance            DECIMAL(10,2)    NULL,
    variance_explanation TEXT            NULL,
    status              ENUM('open','submitted','manager_approved') NOT NULL DEFAULT 'open',
    closed_by           INT(10) UNSIGNED NULL,
    closed_at           DATETIME         NULL,
    manager_reviewed_by INT(10) UNSIGNED NULL,
    manager_reviewed_at DATETIME         NULL,
    manager_notes       VARCHAR(500)     NULL,
    sms_sent            TINYINT(1)       NOT NULL DEFAULT 0,
    notes               TEXT             NULL,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loc_date (location_id, drawer_date),
    INDEX idx_location (location_id),
    INDEX idx_date (drawer_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cash drawer line expenses (separate from main expenses table)
CREATE TABLE IF NOT EXISTS cash_drawer_expenses (
    id          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    drawer_id   INT(10) UNSIGNED NOT NULL,
    description VARCHAR(255)     NOT NULL,
    amount      DECIMAL(10,2)    NOT NULL,
    category    VARCHAR(100)     NOT NULL DEFAULT 'General',
    created_by  INT(10) UNSIGNED NOT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_drawer (drawer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. Inventory counts ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS inventory_counts (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id     INT(10) UNSIGNED NOT NULL,
    count_type      ENUM('full','parts_accessories','high_value') NOT NULL,
    due_date        DATE             NOT NULL,
    status          ENUM('pending','in_progress','submitted','approved','overdue') NOT NULL DEFAULT 'pending',
    started_by      INT(10) UNSIGNED NULL,
    started_at      DATETIME         NULL,
    submitted_by    INT(10) UNSIGNED NULL,
    submitted_at    DATETIME         NULL,
    approved_by     INT(10) UNSIGNED NULL,
    approved_at     DATETIME         NULL,
    notes           TEXT             NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_location (location_id),
    INDEX idx_due (due_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_count_items (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    count_id        INT(10) UNSIGNED NOT NULL,
    item_id         INT(10) UNSIGNED NOT NULL,
    item_name       VARCHAR(200)     NOT NULL,
    category        VARCHAR(100)     NOT NULL,
    system_qty      INT              NOT NULL DEFAULT 0,
    counted_qty     INT              NULL,
    variance        INT              NULL,
    variance_reason ENUM('sold_not_logged','damaged','stolen','transfer_error','found','supplier_error','other') NULL,
    variance_notes  VARCHAR(255)     NULL,
    counted_by      INT(10) UNSIGNED NULL,
    counted_at      DATETIME         NULL,
    INDEX idx_count (count_id),
    INDEX idx_item  (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
