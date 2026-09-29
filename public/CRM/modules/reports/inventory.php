<?php
// ============================================================
// Inventory Valuation & Low Stock Report
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/staff.php'); exit;
}

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$locationId = intval($_GET['location_id'] ?? 0);
if (!$isOwner && !$isManager) $locationId = $user['location_id'];

$locWhere = $locationId ? 'AND s.location_id = ' . intval($locationId) : '';

// Total valuation (cost)
$valuation = DB::queryOne(
    "SELECT
        COUNT(DISTINCT i.id) AS item_count,
        COALESCE(SUM(s.quantity * i.cost_price), 0) AS cost_value,
        COALESCE(SUM(s.quantity * i.sell_price), 0) AS retail_value,
        COALESCE(SUM(s.quantity), 0) AS total_units
     FROM inventory_items i
     JOIN inventory_stock s ON s.item_id = i.id
     WHERE i.is_active = 1 {$locWhere}",
    []
);

// Low stock items
$lowStock = DB::query(
    "SELECT i.*, s.quantity, s.min_quantity, s.location_id,
            l.code AS loc_code, l.name AS loc_name,
            (s.quantity * i.cost_price) AS stock_value
     FROM inventory_items i
     JOIN inventory_stock s ON s.item_id = i.id
     JOIN locations l ON l.id = s.location_id
     WHERE i.is_active = 1
       AND s.quantity <= s.min_quantity
       AND s.min_quantity > 0
       {$locWhere}
     ORDER BY (s.quantity / GREATEST(s.min_quantity,1)) ASC, i.name ASC",
    []
);

// Out of stock
$outOfStock = DB::query(
    "SELECT i.*, s.quantity, s.min_quantity, s.location_id,
            l.code AS loc_code
     FROM inventory_items i
     JOIN inventory_stock s ON s.item_id = i.id
     JOIN locations l ON l.id = s.location_id
     WHERE i.is_active = 1 AND s.quantity = 0
     {$locWhere}
     ORDER BY i.name ASC",
    []
);

// Full stock list by value
$allStock = DB::query(
    "SELECT i.*, s.quantity, s.min_quantity, s.location_id,
            l.code AS loc_code,
            (s.quantity * i.cost_price)  AS cost_value,
            (s.quantity * i.sell_price)  AS retail_value
     FROM inventory_items i
     JOIN inventory_stock s ON s.item_id = i.id
     JOIN locations l ON l.id = s.location_id
     WHERE i.is_active = 1 {$locWhere}
     ORDER BY cost_value DESC",
    []
);

// Category breakdown
$byCategory = DB::query(
    "SELECT COALESCE(i.category, 'Uncategorized') AS cat_name,
            COUNT(DISTINCT i.id) AS item_count,
            COALESCE(SUM(s.quantity),0) AS total_units,
            COALESCE(SUM(s.quantity * i.cost_price),0) AS cost_value,
            COALESCE(SUM(s.quantity * i.sell_price),0) AS retail_value
     FROM inventory_items i
     JOIN inventory_stock s ON s.item_id = i.id
     WHERE i.is_active = 1 {$locWhere}
     GROUP BY i.category ORDER BY cost_value DESC",
    []
);

$pageTitle = 'Inventory Report';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📦 Inventory Report</h1>
        <p class="page-sub">Valuation &amp; stock levels as of <?= date('M j, Y') ?></p>
    </div>
    <div style="display:flex;gap:.5rem;">
        <a href="<?= APP_URL ?>/modules/inventory/" class="btn btn-ghost">→ Inventory</a>
        <a href="index.php" class="btn btn-secondary">← Reports</a>
    </div>
</div>

<!-- Location filter -->
<?php if ($isOwner || $isManager): ?>
<form method="GET" style="margin-bottom:1.5rem;">
    <select name="location_id" class="form-control" style="width:auto;display:inline-block;" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $locationId==$loc['id']?'selected':'' ?>>
            <?= htmlspecialchars($loc['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<!-- Valuation Summary -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">
    <div class="metric-card accent-blue">
        <div class="metric-label">Total SKUs</div>
        <div class="metric-value"><?= intval($valuation['item_count'] ?? 0) ?></div>
        <div class="metric-sub">Active items</div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Total Units</div>
        <div class="metric-value"><?= number_format($valuation['total_units'] ?? 0) ?></div>
        <div class="metric-sub">In stock</div>
    </div>
    <div class="metric-card accent-green">
        <div class="metric-label">Cost Value</div>
        <div class="metric-value">$<?= number_format($valuation['cost_value'] ?? 0, 0) ?></div>
        <div class="metric-sub">At cost price</div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Retail Value</div>
        <div class="metric-value">$<?= number_format($valuation['retail_value'] ?? 0, 0) ?></div>
        <div class="metric-sub">At sell price</div>
    </div>
    <div class="metric-card <?= count($lowStock)>0?'accent-red':'accent-green' ?>">
        <div class="metric-label">Low Stock</div>
        <div class="metric-value"><?= count($lowStock) ?></div>
        <div class="metric-sub">Need reorder</div>
    </div>
    <div class="metric-card <?= count($outOfStock)>0?'accent-red':'accent-green' ?>">
        <div class="metric-label">Out of Stock</div>
        <div class="metric-value"><?= count($outOfStock) ?></div>
        <div class="metric-sub">Zero units</div>
    </div>
</div>

<div class="repair-grid">
<div class="repair-col-main">

    <!-- Low Stock Alert -->
    <?php if (!empty($lowStock)): ?>
    <div class="card" style="margin-bottom:1rem;border:2px solid var(--red);">
        <div class="card-header">
            <h2 class="card-title" style="color:var(--red);">🚨 Low Stock (<?= count($lowStock) ?>)</h2>
        </div>
        <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>Item</th><th>Loc</th><th>In Stock</th><th>Min</th><th>Need to Order</th></tr></thead>
            <tbody>
            <?php foreach ($lowStock as $item): ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($item['name']) ?></div>
                    <?php if ($item['sku']): ?><div class="text-muted small"><?= htmlspecialchars($item['sku']) ?></div><?php endif; ?>
                </td>
                <td><span class="badge badge-loc"><?= htmlspecialchars($item['loc_code']) ?></span></td>
                <td style="color:<?= $item['quantity']==0?'var(--red)':'var(--amber)' ?>;font-weight:700;">
                    <?= $item['quantity'] ?>
                </td>
                <td class="text-muted"><?= $item['min_quantity'] ?></td>
                <td style="font-weight:600;"><?= max(0, $item['min_quantity'] - $item['quantity']) ?> units</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Full Stock List -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">All Stock by Value</h2>
            <span class="text-muted small"><?= count($allStock) ?> items</span>
        </div>
        <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>Item</th><?php if ($isOwner && !$locationId): ?><th>Loc</th><?php endif; ?><th>Qty</th><th>Cost Each</th><th>Cost Value</th><th>Retail Value</th></tr></thead>
            <tbody>
            <?php foreach ($allStock as $item): ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($item['name']) ?></div>
                    <?php if ($item['sku']): ?><div class="text-muted small"><?= htmlspecialchars($item['sku']) ?></div><?php endif; ?>
                </td>
                <?php if ($isOwner && !$locationId): ?>
                <td><span class="badge badge-loc"><?= htmlspecialchars($item['loc_code']) ?></span></td>
                <?php endif; ?>
                <td style="<?= $item['quantity'] <= $item['min_quantity'] && $item['min_quantity']>0 ? 'color:var(--red);font-weight:700;' : '' ?>">
                    <?= $item['quantity'] ?>
                </td>
                <td class="text-muted small">$<?= number_format($item['cost_price'] ?? 0, 2) ?></td>
                <td style="font-weight:600;">$<?= number_format($item['cost_value'] ?? 0, 2) ?></td>
                <td class="text-muted">$<?= number_format($item['retail_value'] ?? 0, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>

<!-- Right: Category Breakdown -->
<div class="repair-col-side">
    <div class="card">
        <div class="card-header"><h2 class="card-title">By Category</h2></div>
        <div class="card-body" style="padding:0;">
        <?php if (empty($byCategory)): ?>
        <div style="padding:1rem;" class="text-muted small">No data.</div>
        <?php else:
            $maxVal = max(array_column($byCategory, 'cost_value')) ?: 1;
        foreach ($byCategory as $cat): ?>
        <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border);">
            <div style="display:flex;justify-content:space-between;margin-bottom:3px;font-size:14px;">
                <span style="font-weight:600;"><?= htmlspecialchars($cat['cat_name'] ?? 'Uncategorized') ?></span>
                <span class="text-muted small"><?= $cat['item_count'] ?> items</span>
            </div>
            <div style="background:var(--surface-2);border-radius:4px;height:6px;overflow:hidden;margin-bottom:3px;">
                <div style="height:100%;width:<?= round(($cat['cost_value']/$maxVal)*100) ?>%;background:var(--blue);"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-3);">
                <span>Cost: $<?= number_format($cat['cost_value'],2) ?></span>
                <span>Retail: $<?= number_format($cat['retail_value'],2) ?></span>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
