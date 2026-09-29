-- ============================================================
-- Phase 15: Customer quote approval via public status page
-- Run once via phpMyAdmin → Import
-- ============================================================

ALTER TABLE repairs
    ADD COLUMN IF NOT EXISTS quote_approval ENUM('none','pending','approved','declined')
        NOT NULL DEFAULT 'none'
        COMMENT 'none=not requested, pending=awaiting customer, approved/declined=customer responded'
        AFTER warranty_days,
    ADD COLUMN IF NOT EXISTS quote_approved_at  DATETIME     NULL AFTER quote_approval,
    ADD COLUMN IF NOT EXISTS quote_decline_reason VARCHAR(255) NULL AFTER quote_approved_at;

ALTER TABLE repairs
    ADD INDEX IF NOT EXISTS idx_repairs_quote_approval (quote_approval);
