-- ============================================================
-- Phase 10: Maintenance Outcome Tracking
-- ============================================================

ALTER TABLE device_maintenance
    ADD COLUMN IF NOT EXISTS outcome ENUM('pending','completed','missed','canceled') NOT NULL DEFAULT 'pending' AFTER follow_up_sms_sent_at,
    ADD COLUMN IF NOT EXISTS repair_id INT NULL AFTER outcome,
    ADD COLUMN IF NOT EXISTS outcome_notes VARCHAR(255) NULL AFTER repair_id,
    ADD COLUMN IF NOT EXISTS outcome_at DATETIME NULL AFTER outcome_notes;

-- Index for repair link
ALTER TABLE device_maintenance
    ADD INDEX IF NOT EXISTS idx_maintenance_repair (repair_id),
    ADD INDEX IF NOT EXISTS idx_maintenance_outcome (outcome);
