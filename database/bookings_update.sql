-- Add customer_id link to bookings table
-- Run in phpMyAdmin — skip if column already exists
ALTER TABLE bookings ADD COLUMN customer_id INT(10) UNSIGNED NULL AFTER location_id;
