-- ============================================================
-- Phase 3: Inventory & Purchasing
-- ============================================================

-- Main inventory catalog
CREATE TABLE IF NOT EXISTS inventory_items (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(200)     NOT NULL,
    category        VARCHAR(100)     NOT NULL,
    item_type       ENUM('part','product') NOT NULL DEFAULT 'part',
    sku             VARCHAR(100)     NULL,
    description     TEXT             NULL,
    cost_price      DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    sell_price      DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    compatible_with VARCHAR(200)     NULL,   -- e.g. "iPhone 14, iPhone 14 Pro"
    image_url       VARCHAR(500)     NULL,
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_by      INT(10) UNSIGNED NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_type     (item_type),
    KEY idx_category (category),
    KEY idx_sku      (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock levels per location
CREATE TABLE IF NOT EXISTS inventory_stock (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id         INT(10) UNSIGNED NOT NULL,
    location_id     INT(10) UNSIGNED NOT NULL,
    quantity        INT(11)          NOT NULL DEFAULT 0,
    min_quantity    INT(11)          NOT NULL DEFAULT 1,   -- alert threshold
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_location (item_id, location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock movement log (audit trail)
CREATE TABLE IF NOT EXISTS inventory_movements (
    id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id         INT(10) UNSIGNED NOT NULL,
    location_id     INT(10) UNSIGNED NOT NULL,
    movement_type   ENUM('in','out','adjustment','repair_use','sale') NOT NULL,
    quantity        INT(11)          NOT NULL,   -- positive = in, negative = out
    reference_type  VARCHAR(50)      NULL,       -- 'repair', 'sale', 'manual'
    reference_id    INT(10) UNSIGNED NULL,       -- repair_id or sale_id
    notes           TEXT             NULL,
    created_by      INT(10) UNSIGNED NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_item     (item_id),
    KEY idx_location (location_id),
    KEY idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed categories
INSERT IGNORE INTO inventory_items (id) VALUES (0);  -- just to test table exists

-- Remove that test row
DELETE FROM inventory_items WHERE name = '';
