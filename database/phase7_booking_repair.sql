-- ============================================================
-- Phase 7: Booking → Repair conversion link
-- Run in phpMyAdmin on ctrecfkn_ctechfix_crm
-- ============================================================

-- Link repairs back to the booking that created them
ALTER TABLE repairs ADD COLUMN booking_id INT(10) UNSIGNED NULL AFTER customer_id;
ALTER TABLE repairs ADD INDEX idx_booking_id (booking_id);

-- Link bookings forward to the repair ticket
ALTER TABLE bookings ADD COLUMN repair_id INT(10) UNSIGNED NULL;
ALTER TABLE bookings ADD INDEX idx_repair_id (repair_id);
