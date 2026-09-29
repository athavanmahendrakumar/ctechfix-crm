-- ============================================================
-- Add buy_on_demand flag to inventory_items
-- Run in phpMyAdmin before uploading updated PHP files
-- ============================================================

ALTER TABLE inventory_items
ADD COLUMN IF NOT EXISTS buy_on_demand TINYINT(1) NOT NULL DEFAULT 0
AFTER sell_price;
