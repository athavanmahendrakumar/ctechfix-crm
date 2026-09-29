-- ============================================================
-- Phase 9: Booking SMS Confirmation Flow
-- Run once against your live database
-- ============================================================

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS appointment_time       TIME         NULL AFTER time_preference,
    ADD COLUMN IF NOT EXISTS confirmation_sms_sent  TINYINT(1)   NOT NULL DEFAULT 0 AFTER sms_sent,
    ADD COLUMN IF NOT EXISTS confirmation_sms_sent_at DATETIME   NULL AFTER confirmation_sms_sent,
    ADD COLUMN IF NOT EXISTS reminder_sms_sent      TINYINT(1)   NOT NULL DEFAULT 0 AFTER confirmation_sms_sent_at,
    ADD COLUMN IF NOT EXISTS reminder_sms_sent_at   DATETIME     NULL AFTER reminder_sms_sent,
    ADD COLUMN IF NOT EXISTS customer_reply         ENUM('yes','no') NULL AFTER reminder_sms_sent_at,
    ADD COLUMN IF NOT EXISTS customer_replied_at    DATETIME     NULL AFTER customer_reply;
