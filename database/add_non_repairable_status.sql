-- ============================================================
-- Add non_repairable to repairs status ENUM
-- Run this in phpMyAdmin before uploading the updated view.php
-- ============================================================

ALTER TABLE repairs
MODIFY COLUMN status ENUM(
    'received',
    'diagnosed',
    'in_repair',
    'waiting_parts',
    'ready_pickup',
    'completed',
    'cancelled',
    'non_repairable'
) NOT NULL DEFAULT 'received';
