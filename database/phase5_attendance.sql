-- ============================================================
-- Phase 5: Staff Attendance (Clock In / Clock Out)
-- Run this in phpMyAdmin on ctrecfkn_ctechfix_crm database
-- ============================================================

CREATE TABLE IF NOT EXISTS staff_attendance (
    id           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT(10) UNSIGNED NOT NULL,
    location_id  INT(10) UNSIGNED NOT NULL,
    clock_in     DATETIME         NOT NULL,
    clock_out    DATETIME         NULL,
    hours_worked DECIMAL(5,2)     NULL,      -- calculated on clock out
    notes        VARCHAR(255)     NULL,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user     (user_id),
    INDEX idx_location (location_id),
    INDEX idx_clock_in (clock_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
