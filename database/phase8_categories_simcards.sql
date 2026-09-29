-- ============================================================
-- Phase 8: Inventory Category Cleanup + SIM Card Items
-- Run once against your live database
-- ============================================================

-- ── Rename old categories to new structured names ──────────

-- Parts
UPDATE inventory_items SET category = 'Speaker'            WHERE category IN ('Speaker / Microphone', 'Speaker/Microphone');
UPDATE inventory_items SET category = 'Back Glass / Housing' WHERE category IN ('Back Glass', 'Frame / Housing', 'Housing');
UPDATE inventory_items SET category = 'Other Parts'        WHERE category IN ('Button / Switch', 'Other', 'Misc', 'Miscellaneous', 'Motherboard');
UPDATE inventory_items SET category = 'SIM Cards'          WHERE category IN ('SIM Card', 'Sim Card', 'SIM');

-- Accessories
UPDATE inventory_items SET category = 'Screen Protector'   WHERE category IN ('Screen Protectors', 'Screen Protecter');
UPDATE inventory_items SET category = 'Case'               WHERE category IN ('Case / Cover', 'Cases', 'Cover');
UPDATE inventory_items SET category = 'Charging Block'     WHERE category IN ('Charger', 'Chargers', 'Charging Blocks', 'Charging Block');
UPDATE inventory_items SET category = 'Storage Device'     WHERE category IN ('Storage', 'Storage Devices');

-- ── Insert SIM card inventory items ────────────────────────
-- selling prices: $10 (with plan) and $20 (standalone) — notes in description
-- owner/manager controls stock manually

INSERT INTO inventory_items
    (name, category, item_type, sku, description, cost_price, sell_price, created_by)
VALUES
    ('Chatr SIM Card',         'SIM Cards', 'product', 'SIM-CHATR',    'Physical SIM. $10 with plan activation, $20 standalone. Deduct stock manually when sold.',          0.00, 10.00, 1),
    ('Fizz SIM Card',          'SIM Cards', 'product', 'SIM-FIZZ',     'Physical SIM. $10 with plan activation, $20 standalone. Deduct stock manually when sold.',          0.00, 10.00, 1),
    ('Freedom Prepaid SIM Card','SIM Cards', 'product', 'SIM-FREEDOM',  'Physical SIM. $10 with plan activation, $20 standalone. Deduct stock manually when sold.',          0.00, 10.00, 1),
    ('Koodo Prepaid SIM Card', 'SIM Cards', 'product', 'SIM-KOODO',    'Physical SIM. $10 with plan activation, $20 standalone. Deduct stock manually when sold.',          0.00, 10.00, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name); -- safe to re-run; skips if SKU already exists

-- ── Add barcode column if missing (safe) ───────────────────
ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS barcode VARCHAR(30) NULL UNIQUE AFTER sku;

-- Auto-generate barcodes for any SIM items just inserted that don't have one
UPDATE inventory_items
SET barcode = CONCAT('CTF', LPAD(id, 7, '0'))
WHERE barcode IS NULL
  AND category = 'SIM Cards'
  AND sku IN ('SIM-CHATR','SIM-FIZZ','SIM-FREEDOM','SIM-KOODO');
