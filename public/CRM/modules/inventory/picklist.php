<?php
// ============================================================
// Purchase / Stock Pick List
// No filters = low/out of stock items only
// With filters = all matching items (printable stock sheet)
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── Filters (passed from index.php) ─────────────────────────
$filterType     = $_GET['type']     ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterSearch   = trim($_GET['q']   ?? '');
$filterStock    = $_GET['stock']    ?? '';
$filterUsage    = $_GET['usage']    ?? '';

$hasFilters = $filterType || $filterCategory || $filterSearch || $filterStock || $filterUsage;

// ── Build query ──────────────────────────────────────────────
$where  = ['i.is_active = 1'];
$params = [];

if ($filterType) {
    $where[]  = 'i.item_type = ?';
    $params[] = $filterType;
}
if ($filterCategory) {
    $where[]  = 'i.category = ?';
    $params[] = $filterCategory;
}
if ($filterSearch) {
    $where[]  = '(i.name LIKE ? OR i.sku LIKE ? OR i.compatible_with LIKE ?)';
    $s = '%' . $filterSearch . '%';
    $params = array_merge($params, [$s, $s, $s]);
}

// Never show buy-on-demand items in pick list (they're ordered as needed)
$where[] = 'i.buy_on_demand = 0';

// When no filters: only show low/out of stock (purchase mode)
// When filters active: show all matching items (stock sheet mode)
if (!$hasFilters) {
    $where[] = 's.quantity <= s.min_quantity';
}

if ($filterStock === 'low') {
    $where[] = 's.quantity <= s.min_quantity AND s.quantity > 0';
} elseif ($filterStock === 'out') {
    $where[] = 's.quantity = 0';
}

$whereStr = implode(' AND ', $where);

$rows = DB::query(
    "SELECT i.id, i.name, i.category, i.item_type, i.sku,
            i.cost_price, i.sell_price, i.compatible_with,
            s.location_id, s.quantity, s.min_quantity,
            l.name AS location_name, l.code AS location_code,
            GREATEST(0, s.min_quantity - s.quantity + s.min_quantity) AS suggested_qty,
            COUNT(DISTINCT rp.repair_id) AS repair_usage_count,
            COUNT(DISTINCT si2.sale_id)  AS sale_usage_count
     FROM inventory_items i
     LEFT JOIN inventory_stock  s   ON s.item_id            = i.id
     LEFT JOIN locations        l   ON l.id                 = s.location_id
     LEFT JOIN repair_parts     rp  ON rp.inventory_item_id = i.id
     LEFT JOIN sale_items       si2 ON si2.inventory_item_id = i.id
     WHERE {$whereStr}
     GROUP BY i.id, s.location_id
     ORDER BY i.category, i.name, l.name",
    $params
);

// Apply usage filter in PHP (same as index)
if ($filterUsage) {
    // First collect usage counts per item
    $usageByItem = [];
    foreach ($rows as $r) {
        if (!isset($usageByItem[$r['id']])) {
            $usageByItem[$r['id']] = ['repair' => $r['repair_usage_count'], 'sale' => $r['sale_usage_count']];
        }
    }
    $rows = array_values(array_filter($rows, function($r) use ($filterUsage, $usageByItem) {
        $u = $usageByItem[$r['id']] ?? ['repair'=>0,'sale'=>0];
        if ($filterUsage === 'repair') return $u['repair'] > 0;
        if ($filterUsage === 'sale')   return $u['sale']   > 0;
        if ($filterUsage === 'both')   return $u['repair'] > 0 && $u['sale'] > 0;
        if ($filterUsage === 'unused') return $u['repair'] == 0 && $u['sale'] == 0;
        return true;
    }));
}

// Group by item
$grouped = [];
foreach ($rows as $row) {
    if (!isset($grouped[$row['id']])) {
        $grouped[$row['id']]['item'] = $row;
        $grouped[$row['id']]['locs'] = [];
    }
    if ($row['location_id']) {
        $grouped[$row['id']]['locs'][] = $row;
    }
}

// Build filter label for the header
$filterLabels = [];
if ($filterType)     $filterLabels[] = ucfirst($filterType) . 's';
if ($filterCategory) $filterLabels[] = $filterCategory;
if ($filterSearch)   $filterLabels[] = '"' . $filterSearch . '"';
if ($filterStock === 'low') $filterLabels[] = 'Low Stock';
if ($filterStock === 'out') $filterLabels[] = 'Out of Stock';
if ($filterUsage)    $filterLabels[] = match($filterUsage) {
    'repair' => 'Used in Repairs', 'sale' => 'Used in Sales',
    'both' => 'Used in Both', 'unused' => 'Never Used', default => ''
};
$filterLabel = implode(' · ', array_filter($filterLabels));

$mode     = $hasFilters ? 'stock_sheet' : 'purchase';
$pageTitle = $hasFilters ? 'Stock Sheet' : 'Purchase Pick List';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<style>
@media print {
    .sidebar, nav, .topbar, .page-header .btn,
    .sidebar-overlay, .no-print { display: none !important; }
    .main-content { margin-left: 0 !important; }
    .page-content  { padding: 0 !important; }
    .pick-check, #checkAll { display: none; }
    tr  { page-break-inside: avoid; }
    h2  { page-break-after: avoid; }
    .category-block { page-break-inside: avoid; }
    body { font-size: 12px; }
    .print-header { display: block !important; }
}
.print-header {
    display: none;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: .5rem;
}
.category-label {
    background: var(--surface-2);
    padding: .35rem .75rem;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: var(--muted);
    border-left: 3px solid var(--primary);
    margin: .75rem 0 .25rem;
}
</style>

<!-- Print-only header -->
<div class="print-header">
    C Tech Fix — <?= $hasFilters ? 'Stock Sheet' : 'Purchase Pick List' ?> &nbsp;|&nbsp;
    <?= date('F j, Y') ?>
    <?php if ($filterLabel): ?>&nbsp;|&nbsp; Filter: <?= htmlspecialchars($filterLabel) ?><?php endif; ?>
</div>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <?= $hasFilters ? '📋 Stock Sheet' : '🛒 Purchase Pick List' ?>
        </h1>
        <p class="page-sub">
            <?php if ($filterLabel): ?>
                Filtered: <strong><?= htmlspecialchars($filterLabel) ?></strong> &nbsp;·&nbsp;
            <?php else: ?>
                Items at or below minimum stock level &nbsp;·&nbsp;
            <?php endif; ?>
            <span class="text-muted" style="font-size:12px;">Buy-on-demand items excluded</span> &nbsp;·&nbsp;
            <?= count($grouped) ?> item<?= count($grouped) !== 1 ? 's' : '' ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;" class="no-print">
        <button onclick="window.print()" class="btn btn-primary">🖨 Print Full Page</button>
        <a href="receipt_print.php?<?= http_build_query(array_filter(['type'=>$filterType,'category'=>$filterCategory,'stock'=>$filterStock,'usage'=>$filterUsage,'q'=>$filterSearch])) ?>"
           target="_blank" class="btn btn-secondary">🧾 Receipt Printer</a>
        <?php
        $backParams = array_filter([
            'type' => $filterType, 'category' => $filterCategory,
            'stock' => $filterStock, 'usage' => $filterUsage, 'q' => $filterSearch,
        ]);
        ?>
        <a href="index.php<?= $backParams ? '?' . http_build_query($backParams) : '' ?>"
           class="btn btn-secondary">← Back to Inventory</a>
    </div>
</div>

<?php if (empty($grouped)): ?>
<div class="empty-state">
    <div class="empty-icon"><?= $hasFilters ? '📦' : '✅' ?></div>
    <p><?= $hasFilters ? 'No items match the selected filters.' : 'All stock levels are good — nothing to buy right now!' ?></p>
    <a href="index.php" class="btn btn-secondary no-print">← Inventory</a>
</div>
<?php else: ?>

<!-- Summary by location -->
<div class="metrics-grid" style="margin-bottom:1.5rem;" class="no-print">
<?php foreach ($locations as $loc): ?>
<?php
$locItems = array_filter($rows, fn($r) => $r['location_id'] == $loc['id']);
$locCount = count(array_unique(array_column(iterator_to_array((function() use ($locItems) { yield from $locItems; })()), 'id')));
// Simpler: count distinct item ids for this location
$locItemIds = [];
foreach ($rows as $r) { if ($r['location_id'] == $loc['id']) $locItemIds[$r['id']] = 1; }
$locCount = count($locItemIds);
$locCost  = 0;
foreach ($rows as $r) {
    if ($r['location_id'] == $loc['id']) {
        $locCost += ($mode === 'purchase' ? $r['suggested_qty'] : $r['quantity']) * $r['cost_price'];
    }
}
?>
<div class="metric-card">
    <div class="metric-label"><?= htmlspecialchars($loc['name']) ?></div>
    <div class="metric-value"><?= $locCount ?></div>
    <div class="metric-sub">
        <?= $mode === 'purchase' ? 'items to restock' : 'items' ?>
        &nbsp;·&nbsp; ~$<?= number_format($locCost, 2) ?> <?= $mode === 'purchase' ? 'est. cost' : 'cost value' ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Grouped by category -->
<?php
$byCategory = [];
foreach ($grouped as $itemId => $data) {
    $cat = $data['item']['category'] ?: 'Uncategorized';
    $byCategory[$cat][$itemId] = $data;
}
ksort($byCategory);
?>

<?php foreach ($byCategory as $cat => $catItems): ?>
<div class="category-block">
    <div class="category-label">📁 <?= htmlspecialchars($cat) ?> (<?= count($catItems) ?> item<?= count($catItems)!=1?'s':'' ?>)</div>
    <div class="table-wrap" style="margin-bottom:0;">
    <table class="data-table" style="margin-bottom:.25rem;">
        <thead>
            <tr>
                <th class="no-print"><input type="checkbox" onchange="toggleCat(this)"></th>
                <th>Item</th>
                <th>SKU</th>
                <th>Type</th>
                <?php foreach ($locations as $loc): ?>
                <th>
                    <?= htmlspecialchars($loc['code']) ?>
                    <?= $mode === 'purchase' ? 'Have / Min / Buy' : 'Stock / Min' ?>
                </th>
                <?php endforeach; ?>
                <?php if ($mode === 'purchase'): ?>
                <th>Est. Cost</th>
                <?php else: ?>
                <th>Sell Price</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($catItems as $itemId => $data): ?>
        <?php
        $item    = $data['item'];
        $locData = [];
        foreach ($data['locs'] as $l) $locData[$l['location_id']] = $l;
        $totalCost = 0;
        foreach ($data['locs'] as $l) {
            $totalCost += ($mode === 'purchase' ? $l['suggested_qty'] : $l['quantity']) * $item['cost_price'];
        }
        ?>
        <tr>
            <td class="no-print"><input type="checkbox" class="pick-check" value="<?= $itemId ?>" checked></td>
            <td>
                <strong><?= htmlspecialchars($item['name']) ?></strong>
                <?php if ($item['compatible_with']): ?>
                <br><span class="text-muted small"><?= htmlspecialchars($item['compatible_with']) ?></span>
                <?php endif; ?>
            </td>
            <td class="text-muted small"><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
            <td>
                <span class="badge <?= $item['item_type']==='part' ? 'badge-info' : 'badge-primary' ?>">
                    <?= $item['item_type']==='part' ? '🔧' : '🛒' ?>
                </span>
            </td>
            <?php foreach ($locations as $loc): ?>
            <?php $l = $locData[$loc['id']] ?? null; ?>
            <td>
                <?php if ($l): ?>
                    <?php if ($mode === 'purchase'): ?>
                        <span style="color:var(--red);font-weight:600;"><?= $l['quantity'] ?></span>
                        / <?= $l['min_quantity'] ?>
                        / <strong style="color:var(--blue);"><?= $l['suggested_qty'] ?></strong>
                    <?php else: ?>
                        <strong><?= $l['quantity'] ?></strong>
                        <span class="text-muted small">/ <?= $l['min_quantity'] ?> min</span>
                        <?php if ($l['quantity'] <= $l['min_quantity']): ?>
                        <span style="color:var(--red);">⚠</span>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td>$<?= number_format($mode === 'purchase' ? $totalCost : $item['sell_price'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<!-- Grand total -->
<?php if ($mode === 'purchase'): ?>
<?php
$grandTotal = 0;
foreach ($grouped as $data) {
    foreach ($data['locs'] as $l) {
        $grandTotal += $l['suggested_qty'] * $data['item']['cost_price'];
    }
}
?>
<div style="text-align:right;font-weight:700;font-size:1.05rem;margin-top:.75rem;padding:.5rem;">
    Total Estimated Purchase Cost: $<?= number_format($grandTotal, 2) ?>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
function toggleCat(cb) {
    const row = cb.closest('table');
    row.querySelectorAll('.pick-check').forEach(c => c.checked = cb.checked);
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
