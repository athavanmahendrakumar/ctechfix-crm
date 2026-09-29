-- ============================================================
-- Cash Accounts & Movements — No FK constraints (shared hosting safe)
-- Run once via phpMyAdmin
-- ============================================================

CREATE TABLE IF NOT EXISTS cash_accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    type            ENUM('till','hub','card','bank','other') NOT NULL DEFAULT 'other',
    location_id     INT NULL,
    opening_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      INT NOT NULL DEFAULT 0,
    notes           TEXT NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_movements (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    from_account_id  INT NULL,
    to_account_id    INT NULL,
    amount           DECIMAL(10,2) NOT NULL,
    movement_type    ENUM('transfer','expense','deposit','adjustment','opening') NOT NULL,
    expense_id       INT NULL,
    reference        VARCHAR(255) NULL,
    notes            TEXT NULL,
    moved_at         DATETIME NOT NULL,
    flagged          TINYINT(1) NOT NULL DEFAULT 0,
    flag_reason      TEXT NULL,
    created_by       INT NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hub_counts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    account_id      INT NOT NULL,
    expected_amount DECIMAL(10,2) NOT NULL,
    actual_amount   DECIMAL(10,2) NOT NULL,
    variance        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    explanation     TEXT NULL,
    counted_by      INT NOT NULL,
    counted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    expense_id        INT NOT NULL,
    description       VARCHAR(255) NOT NULL,
    inventory_item_id INT NULL,
    unit_cost         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity          INT NOT NULL DEFAULT 1,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_item_allocations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    expense_item_id INT NOT NULL,
    location_id     INT NOT NULL,
    quantity        INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add new columns to expenses (safe — IF NOT EXISTS skipped on older MySQL, run each separately if needed)
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS vendor_name        VARCHAR(255) NULL;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS payment_account_id INT NULL;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS receipt_drive_id   VARCHAR(255) NULL;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS has_receipt        TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS payment_method_label VARCHAR(100) NULL;

-- Add repair_id to expense_items so a line item can link to a repair ticket
ALTER TABLE expense_items ADD COLUMN IF NOT EXISTS repair_id INT NULL;
