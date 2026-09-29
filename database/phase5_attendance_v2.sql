-- ============================================================
-- Phase 5: Add activations_count to staff_attendance
-- Run in phpMyAdmin after phase5_attendance.sql
-- ============================================================

ALTER TABLE staff_attendance
    ADD COLUMN activations_count TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Phone plan activations completed this shift'
        AFTER hours_worked;
