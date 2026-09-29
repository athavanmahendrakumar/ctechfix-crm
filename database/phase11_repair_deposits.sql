-- ============================================================
-- Phase 11: Repair Deposits & Receipt Tracking
-- ============================================================

-- Add deposit tracking to repairs
ALTER TABLE repairs
    ADD COLUMN IF NOT EXISTS deposit_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER estimated_cost,
    ADD COLUMN IF NOT EXISTS deposit_sale_id  INT NULL AFTER deposit_amount,
    ADD COLUMN IF NOT EXISTS deposit_method   ENUM('cash','debit','credit','e_transfer','other') NULL AFTER deposit_sale_id;

-- Link sales back to a repair (for deposit + final payment records)
ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS repair_id  INT NULL AFTER customer_id,
    ADD COLUMN IF NOT EXISTS sale_type  ENUM('sale','repair_deposit','repair_final') NOT NULL DEFAULT 'sale' AFTER repair_id;

ALTER TABLE sales
    ADD INDEX IF NOT EXISTS idx_sales_repair (repair_id);

