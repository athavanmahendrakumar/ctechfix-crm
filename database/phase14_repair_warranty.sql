-- ============================================================
-- Phase 14: Per-repair warranty period
-- Run once via phpMyAdmin → Import
-- ============================================================

ALTER TABLE repairs
    ADD COLUMN IF NOT EXISTS warranty_days INT NOT NULL DEFAULT 0
        COMMENT '0 = no warranty; otherwise days of coverage on parts & labour'
        AFTER deposit_method;

-- Existing repairs get 0 (no warranty) — staff can update individually if needed
-- New repairs default to No Warranty; staff selects a period at intake if applicable
