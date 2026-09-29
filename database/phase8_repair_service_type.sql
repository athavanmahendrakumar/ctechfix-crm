-- ============================================================
-- Phase 8b: Add service_type column to repairs table
-- Run once against your live database
-- ============================================================

ALTER TABLE repairs
    ADD COLUMN IF NOT EXISTS service_type VARCHAR(100) NULL
    AFTER issue_description;

-- Optional index for filtering/reporting
ALTER TABLE repairs
    ADD INDEX IF NOT EXISTS idx_service_type (service_type);
