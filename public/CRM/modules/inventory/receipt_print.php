<?php
// ============================================================
// Receipt Printer Pick List
// Narrow format for 58mm/80mm thermal receipt printers
// Print via browser: File > Print > set paper to 80mm roll
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';

Auth::boot();
Auth::require();

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// Filters (same as picklist.php)
$filterType     = $_GET['type']     ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterSearch   = trim($_GET['q']   ?? '');
$filterStock    = $_GET['stock']    ?? '';
$filterUsage    = $_GET['usage']    ?? '';
$hasFilters     = $filterType || $filterCategory || $filterSearch || $filterStock || $filterUsage;

$where  = ['i.is_active = 1', 'i.buy_on_demand = 0'];
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
    "SELECT i.id, i.name, i.category, i.item_type, i.sku, i.cost_price,
            i.compatible_with,
            s.location_id, s.quantity, s.min_quantity,
            l.name AS location_name, l.id AS loc_id,
            GREATEST(0, s.min_quantity - s.quantity + s.min_quantity) AS suggested_qty
     FROM inventory_items i
     LEFT JOIN inventory_stock s ON s.item_id = i.id
     LEFT JOIN locations       l ON l.id = s.location_id
     WHERE {$whereStr}
     ORDER BY l.name, i.category, i.name",
    $params
);

// Group by location then category
$byLoc = [];
foreach ($rows as $row) {
    if (!$row['location_id']) continue;
    $locName = $row['location_name'];
    $cat     = $row['category'] ?: 'Other';
    $byLoc[$locName][$cat][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pick List — Receipt</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    color: #000;
    background: #fff;
    width: 76mm;   /* fits 80mm roll with margins */
    margin: 0 auto;
    padding: 4mm 2mm;
}

.screen-only {
    display: block;
    margin-bottom: 12px;
}
@media print {
    .screen-only { display: none; }
    body { width: 100%; padding: 0; }
    .loc-block { page-break-after: always; }
    .loc-block:last-child { page-break-after: avoid; }
}

/* ── Screen print button bar ── */
.btn-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 2px dashed #ccc;
}
.btn-bar button, .btn-bar a {
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 700;
    border: 2px solid #000;
    background: #000;
    color: #fff;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
.btn-bar a { background: #fff; color: #000; }

/* ── Receipt layout ── */
.loc-block {
    margin-bottom: 8mm;
}

.receipt-header {
    text-align: center;
    border-top: 3px solid #000;
    border-bottom: 3px solid #000;
    padding: 2mm 0;
    margin-bottom: 3mm;
}
.receipt-header .shop  { font-size: 15px; font-weight: 900; letter-spacing: 1px; }
.receipt-header .title { font-size: 12px; font-weight: 700; margin-top: 1mm; }
.receipt-header .loc   { font-size: 14px; font-weight: 900; margin-top: 1mm; }
.receipt-header .date  { font-size: 11px; color: #333; margin-top: 1mm; }

.cat-header {
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .5px;
    border-bottom: 1px solid #000;
    padding: 1mm 0;
    margin: 2mm 0 1mm;
}

.item-row {
    display: flex;
    align-items: flex-start;
    gap: 3px;
    padding: 1.5mm 0;
    border-bottom: 1px dotted #555;
}
.item-row:last-child { border-bottom: none; }

.item-check {
    width: 14px;
    height: 14px;
    border: 2px solid #000;
    flex-shrink: 0;
    margin-top: 1px;
}

.item-info { flex: 1; min-width: 0; }
.item-name {
    font-size: 12px;
    font-weight: 900;
    line-height: 1.2;
    color: #000;
    word-break: break-word;
}
.item-compat {
    font-size: 10px;
    color: #222;
    font-weight: 700;
    margin-top: 1px;
}
.item-sku {
    font-size: 10px;
    color: #444;
    margin-top: 1px;
}

.item-qty {
    text-align: right;
    flex-shrink: 0;
    min-width: 32px;
}
.qty-need {
    font-size: 14px;
    font-weight: 900;
    color: #000;
    line-height: 1;
}
.qty-have {
    font-size: 10px;
    color: #444;
    font-weight: 700;
}

.receipt-footer {
    border-top: 2px solid #000;
    margin-top: 3mm;
    padding-top: 2mm;
    font-size: 11px;
    font-weight: 700;
    text-align: center;
}

.empty-msg {
    text-align: center;
    font-size: 13px;
    font-weight: 700;
    padding: 6mm 0;
    border: 2px solid #000;
    border-radius: 4px;
}
</style>
</head>
<body>

<!-- Screen-only controls -->
<div class="screen-only btn-bar">
    <button onclick="window.print()">🖨 Print</button>
    <a href="picklist.php<?= $_SERVER['QUERY_STRING'] ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>">← Full Page View</a>
    <a href="index.php">← Inventory</a>
</div>

<?php if (empty($byLoc)): ?>
<div class="empty-msg">✅ Nothing to restock!</div>
<?php else: ?>

<?php foreach ($byLoc as $locName => $catGroups): ?>
<?php
// Count totals for this location
$locItemCount  = array_sum(array_map('count', $catGroups));
$locTotalBuy   = 0;
foreach ($catGroups as $cat => $items) {
    foreach ($items as $item) {
        $locTotalBuy += $item['suggested_qty'];
    }
}
?>
<div class="loc-block">

    <div class="receipt-header">
        <div class="shop">C TECH FIX</div>
        <div class="title"><?= $hasFilters ? 'STOCK SHEET' : 'BUY LIST' ?></div>
        <div class="loc">📍 <?= strtoupper(htmlspecialchars($locName)) ?></div>
        <div class="date"><?= date('D M j, Y  g:i A') ?></div>
    </div>

    <?php foreach ($catGroups as $cat => $items): ?>
    <div class="cat-header">▶ <?= htmlspecialchars($cat) ?></div>

    <?php foreach ($items as $item): ?>
    <div class="item-row">
        <div class="item-check"></div>
        <div class="item-info">
            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
            <?php if ($item['compatible_with']): ?>
            <div class="item-compat"><?= htmlspecialchars($item['compatible_with']) ?></div>
            <?php endif; ?>
            <?php if ($item['sku']): ?>
            <div class="item-sku">SKU: <?= htmlspecialchars($item['sku']) ?></div>
            <?php endif; ?>
        </div>
        <div class="item-qty">
            <?php if ($hasFilters): ?>
                <div class="qty-need"><?= $item['quantity'] ?></div>
                <div class="qty-have">/ <?= $item['min_quantity'] ?> min</div>
            <?php else: ?>
                <div class="qty-need">+<?= $item['suggested_qty'] ?></div>
                <div class="qty-have">have <?= $item['quantity'] ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="receipt-footer">
        <?= $locItemCount ?> item<?= $locItemCount != 1 ? 's' : '' ?>
        <?php if (!$hasFilters): ?> · <?= $locTotalBuy ?> units to buy<?php endif; ?>
    </div>

</div>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
