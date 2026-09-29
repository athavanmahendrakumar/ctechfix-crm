-- ============================================================
-- Phase 16: Staff-created bookings
-- Adds created_by so staff bookings are tracked
-- Run once via phpMyAdmin → Import
-- ============================================================

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS created_by INT(10) UNSIGNED NULL
        COMMENT 'NULL = customer self-booked; user ID = staff created'
        AFTER staff_notes;
