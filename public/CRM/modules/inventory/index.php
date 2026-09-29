<?php
// ============================================================
// Inventory List
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── POST: inline quick stock adjust ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_adjust') {
    $itemId     = intval($_POST['item_id']     ?? 0);
    $locationId = intval($_POST['location_id'] ?? 0);
    $adjType    = $_POST['adj_type'] ?? 'in';
    $qty        = intval($_POST['quantity']    ?? 0);
    $notes      = trim($_POST['notes']         ?? '');

    if ($itemId && $locationId && $qty > 0) {
        $change = $adjType === 'out' ? -$qty : $qty;
        $existing = DB::queryOne(
            'SELECT id FROM inventory_stock WHERE item_id=? AND location_id=?',
            [$itemId, $locationId]
        );
        if ($existing) {
            DB::execute(
                'UPDATE inventory_stock SET quantity=GREATEST(0,quantity+?) WHERE item_id=? AND location_id=?',
                [$change, $itemId, $locationId]
            );
        } else {
            DB::execute(
                'INSERT INTO inventory_stock (item_id,location_id,quantity,min_quantity) VALUES (?,?,?,1)',
                [$itemId, $locationId, max(0, $change)]
            );
        }
        DB::execute(
            'INSERT INTO inventory_movements (item_id,location_id,movement_type,quantity,reference_type,notes,created_by)
             VALUES (?,?,?,?,?,?,?)',
            [$itemId, $locationId, 'adjustment', $change, 'manual',
             $notes ?: 'Quick adjust from list', $user['id']]
        );
    }
    // Rebuild redirect preserving filters
    $qs = http_build_query(array_merge(
        array_filter($_POST, fn($k) => in_array($k, ['type','usage','category','stock','q']), ARRAY_FILTER_USE_KEY),
        ['msg' => 'stock_updated']
    ));
    header('Location: ' . APP_URL . '/modules/inventory/?' . $qs);
    exit;
}

// ── POST: delete inventory item (owner only) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item' && Auth::isOwner()) {
    $delId = intval($_POST['item_id'] ?? 0);
    if ($delId) {
        DB::execute('UPDATE repair_parts SET inventory_item_id=NULL WHERE inventory_item_id=?', [$delId]);
        DB::execute('DELETE FROM inventory_stock       WHERE item_id=?', [$delId]);
        DB::execute('DELETE FROM inventory_movements   WHERE item_id=?', [$delId]);
        DB::execute('DELETE FROM inventory_count_items WHERE item_id=?', [$delId]);
        DB::execute('DELETE FROM inventory_items       WHERE id=?',      [$delId]);
    }
    header('Location: ' . APP_URL . '/modules/inventory/?msg=deleted'); exit;
}

// ── Filters ──────────────────────────────────────────────────
$filterType     = $_GET['type']     ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterSearch   = trim($_GET['q']   ?? '');
$filterStock    = $_GET['stock']    ?? '';
$filterUsage    = $_GET['usage']    ?? ''; // 'repair' | 'sale' | 'both' | 'unused'
$filterNoCost   = isset($_GET['nocost']) && (Auth::isOwner() || Auth::isManager());

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

// No-cost filter (owner/manager only)
if ($filterNoCost) {
    $where[]  = '(i.cost_price IS NULL OR i.cost_price = 0)';
}

$whereStr = implode(' AND ', $where);

$items = DB::query(
    "SELECT i.*,
            COALESCE(SUM(s.quantity), 0)         AS total_stock,
            MAX(CASE WHEN s.quantity <= s.min_quantity THEN 1 ELSE 0 END) AS has_low_stock,
            COUNT(DISTINCT rp.repair_id)         AS repair_usage_count,
            COUNT(DISTINCT si.sale_id)           AS sale_usage_count
     FROM inventory_items i
     LEFT JOIN inventory_stock  s  ON s.item_id            = i.id
     LEFT JOIN repair_parts     rp ON rp.inventory_item_id = i.id
     LEFT JOIN sale_items       si ON si.inventory_item_id = i.id
     WHERE {$whereStr}
     GROUP BY i.id
     ORDER BY i.item_type, i.category, i.name",
    $params
);

// Filter by stock level
if ($filterStock === 'low') {
    $items = array_values(array_filter($items, fn($i) => $i['has_low_stock'] && $i['total_stock'] > 0));
} elseif ($filterStock === 'out') {
    $items = array_values(array_filter($items, fn($i) => $i['total_stock'] == 0));
}

// Filter by usage
if ($filterUsage === 'repair') {
    $items = array_values(array_filter($items, fn($i) => $i['repair_usage_count'] > 0));
} elseif ($filterUsage === 'sale') {
    $items = array_values(array_filter($items, fn($i) => $i['sale_usage_count'] > 0));
} elseif ($filterUsage === 'both') {
    $items = array_values(array_filter($items, fn($i) => $i['repair_usage_count'] > 0 && $i['sale_usage_count'] > 0));
} elseif ($filterUsage === 'unused') {
    $items = array_values(array_filter($items, fn($i) => $i['repair_usage_count'] == 0 && $i['sale_usage_count'] == 0));
}

// Stock per location for each item
$stockByItem = [];
if (!empty($items)) {
    $itemIds      = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stockRows    = DB::query(
        "SELECT s.*, l.name AS location_name, l.code AS location_code
         FROM inventory_stock s
         JOIN locations l ON s.location_id = l.id
         WHERE s.item_id IN ($placeholders)",
        $itemIds
    );
    foreach ($stockRows as $row) {
        $stockByItem[$row['item_id']][$row['location_id']] = $row;
    }
}

// Categories
$categories = DB::query(
    'SELECT DISTINCT category FROM inventory_items WHERE is_active=1 ORDER BY category', []
);

$pageTitle = 'Inventory';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<style>
/* ── Inline stock adjust popover ── */
.stock-cell { position: relative; }
.stock-display {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: .3rem;
}
.stock-display:hover .stock-edit-hint { opacity: 1; }
.stock-edit-hint {
    opacity: 0;
    font-size: 10px;
    color: var(--primary);
    transition: opacity .15s;
}
.stock-popover {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    z-index: 200;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .65rem;
    box-shadow: 0 6px 20px rgba(0,0,0,.22);
    min-width: 210px;
}
.stock-popover.open { display: block; }
.pop-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: var(--muted);
    margin-bottom: .4rem;
    border-bottom: 1px solid var(--border);
    padding-bottom: .3rem;
}
.pop-row { display: flex; gap: .3rem; align-items: center; margin-bottom: .3rem; }
.pop-row input[type=number] {
    width: 62px; padding: .28rem .4rem; font-size: 13px;
    border: 1px solid var(--border); border-radius: 5px;
    background: var(--bg); color: var(--text);
}
.pop-row select, .pop-row input[type=text] {
    padding: .28rem .4rem; font-size: 12px;
    border: 1px solid var(--border); border-radius: 5px;
    background: var(--bg); color: var(--text);
}
.pop-row input[type=text] { flex: 1; }
.pop-btns { display: flex; gap: .3rem; margin-top: .45rem; }

/* ── Usage badges ── */
.usage-badges { display: flex; gap: .25rem; flex-wrap: wrap; margin-top: 3px; }
.usage-tag {
    font-size: 10px; font-weight: 700;
    padding: 1px 6px; border-radius: 99px;
}
.usage-tag.repair  { background: #172554; color: #60a5fa; }
.usage-tag.sale    { background: #052e16; color: #4ade80; }
.usage-tag.demand  { background: #27272a; color: #a1a1aa; }
.usage-tag.nocost  { background: #450a0a; color: #f87171; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Inventory</h1>
        <p class="page-sub"><?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <?php
        // Build pick list URL preserving active filters
        $plParams = array_filter([
            'type'     => $filterType,
            'category' => $filterCategory,
            'stock'    => $filterStock,
            'usage'    => $filterUsage,
            'q'        => $filterSearch,
        ]);
        $plUrl = 'picklist.php' . ($plParams ? '?' . http_build_query($plParams) : '');
        ?>
        <a href="<?= $plUrl ?>" class="btn btn-secondary" target="_blank">🖨 Print Pick List<?= $plParams ? ' (filtered)' : '' ?></a>
        <a href="new.php" class="btn btn-primary">+ Add Item</a>
    </div>
</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success">
    <?= ['saved' => '✓ Item saved.', 'deleted' => '✓ Item deleted.', 'stock_updated' => '✓ Stock updated.'][$_GET['msg']] ?? '' ?>
</div>
<?php endif; ?>

<form method="GET" class="filter-bar">
    <input type="text" name="q" class="form-control filter-search"
           placeholder="Search name, SKU, compatible with..."
           value="<?= htmlspecialchars($filterSearch) ?>">

    <select name="type" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="part"    <?= $filterType==='part'    ?'selected':'' ?>>🔧 Repair Parts</option>
        <option value="product" <?= $filterType==='product' ?'selected':'' ?>>🛒 Sale Products</option>
    </select>

    <select name="usage" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Usage</option>
        <option value="repair" <?= $filterUsage==='repair' ?'selected':'' ?>>Used in Repairs</option>
        <option value="sale"   <?= $filterUsage==='sale'   ?'selected':'' ?>>Used in Sales</option>
        <option value="both"   <?= $filterUsage==='both'   ?'selected':'' ?>>Used in Both</option>
        <option value="unused" <?= $filterUsage==='unused' ?'selected':'' ?>>Never Used</option>
    </select>

    <select name="category" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat['category']) ?>"
            <?= $filterCategory === $cat['category'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cat['category']) ?>
        </option>
        <?php endforeach; ?>
    </select>

    <select name="stock" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Stock</option>
        <option value="low" <?= $filterStock==='low' ?'selected':'' ?>>Low Stock</option>
        <option value="out" <?= $filterStock==='out' ?'selected':'' ?>>Out of Stock</option>
    </select>

    <button type="submit" class="btn btn-secondary">Search</button>
    <a href="?" class="btn btn-ghost">Clear</a>
    <?php if (Auth::isOwner() || Auth::isManager()): ?>
    <a href="?nocost=1" class="btn btn-ghost" style="<?= $filterNoCost ? 'color:var(--red);font-weight:700;border-color:var(--red);' : '' ?>"
       title="Show items missing a cost price">⚠ No Cost Price</a>
    <?php endif; ?>
</form>

<?php if (empty($items)): ?>
<div class="empty-state">
    <div class="empty-icon">📦</div>
    <p>No inventory items found.</p>
    <?php if (Auth::isOwner() || Auth::isManager()): ?>
    <a href="new.php" class="btn btn-primary">Add First Item</a>
    <?php endif; ?>
</div>
<?php else: ?>

<p class="text-muted small" style="margin-bottom:.5rem;">
    💡 <strong>Click any stock number</strong> to quickly add or remove units for that location.
</p>

<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr>
            <th>Item</th>
            <th>Type</th>
            <th>Category</th>
            <th>SKU</th>
            <th>Cost</th>
            <th>Sell</th>
            <?php foreach ($locations as $loc): ?>
            <th><?= htmlspecialchars($loc['code']) ?> Stock</th>
            <?php endforeach; ?>
            <th>Total</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
    <?php
        $hasRepairUsage = $item['repair_usage_count'] > 0;
        $hasSaleUsage   = $item['sale_usage_count']   > 0;
    ?>
    <tr>
        <td>
            <strong><?= htmlspecialchars($item['name']) ?></strong>
            <?php if ($item['compatible_with']): ?>
            <br><span class="text-muted small"><?= htmlspecialchars($item['compatible_with']) ?></span>
            <?php endif; ?>
            <div class="usage-badges">
                <?php if ($hasRepairUsage): ?>
                <span class="usage-tag repair">🔧 <?= $item['repair_usage_count'] ?> repair<?= $item['repair_usage_count'] != 1 ? 's' : '' ?></span>
                <?php endif; ?>
                <?php if ($hasSaleUsage): ?>
                <span class="usage-tag sale">🛒 <?= $item['sale_usage_count'] ?> sale<?= $item['sale_usage_count'] != 1 ? 's' : '' ?></span>
                <?php endif; ?>
                <?php if (!empty($item['buy_on_demand'])): ?>
                <span class="usage-tag demand">📦 Buy on demand</span>
                <?php endif; ?>
                <?php if ((Auth::isOwner() || Auth::isManager()) && ($item['cost_price'] == 0 || $item['cost_price'] === null)): ?>
                <span class="usage-tag nocost">⚠ No cost price</span>
                <?php endif; ?>
            </div>
        </td>
        <td>
            <span class="badge <?= $item['item_type']==='part' ? 'badge-info' : 'badge-primary' ?>">
                <?= $item['item_type']==='part' ? '🔧 Part' : '🛒 Product' ?>
            </span>
        </td>
        <td><?= htmlspecialchars($item['category']) ?></td>
        <td class="text-muted small"><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
        <td>$<?= number_format($item['cost_price'], 2) ?></td>
        <td>$<?= number_format($item['sell_price'], 2) ?></td>

        <?php foreach ($locations as $loc): ?>
        <?php $s = $stockByItem[$item['id']][$loc['id']] ?? null; ?>
        <td class="stock-cell">
            <!-- Clickable stock display -->
            <div class="stock-display"
                 onclick="togglePop('pop-<?= $item['id'] ?>-<?= $loc['id'] ?>')"
                 title="Click to adjust stock">
                <?php if ($s !== null): ?>
                    <?php $low = $s['quantity'] <= $s['min_quantity']; ?>
                    <span style="color:<?= $low ? 'var(--red)' : 'var(--text)' ?>;font-weight:<?= $low ? '700' : '400' ?>;">
                        <?= $s['quantity'] ?>
                    </span>
                    <span class="text-muted small">/ <?= $s['min_quantity'] ?> min</span>
                    <?php if ($low): ?><span style="color:var(--red);">⚠</span><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">0</span>
                <?php endif; ?>
                <span class="stock-edit-hint">✏️</span>
            </div>

            <!-- Quick adjust popover -->
            <div class="stock-popover" id="pop-<?= $item['id'] ?>-<?= $loc['id'] ?>">
                <div class="pop-title"><?= htmlspecialchars($loc['name']) ?></div>
                <form method="POST">
                    <input type="hidden" name="action"      value="quick_adjust">
                    <input type="hidden" name="item_id"     value="<?= $item['id'] ?>">
                    <input type="hidden" name="location_id" value="<?= $loc['id'] ?>">
                    <!-- Preserve active filters so we return to same filtered view -->
                    <?php foreach (['type','usage','category','stock','q'] as $fk): ?>
                    <?php if (!empty($_GET[$fk])): ?>
                    <input type="hidden" name="<?= $fk ?>" value="<?= htmlspecialchars($_GET[$fk]) ?>">
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <div class="pop-row">
                        <select name="adj_type">
                            <option value="in">+ Add</option>
                            <option value="out">- Remove</option>
                        </select>
                        <input type="number" name="quantity" min="1" value="1" required>
                        <span class="text-muted small">units</span>
                    </div>
                    <div class="pop-row">
                        <input type="text" name="notes" placeholder="Reason (optional)">
                    </div>
                    <div class="pop-btns">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        <button type="button" class="btn btn-sm btn-ghost"
                                onclick="closePop('pop-<?= $item['id'] ?>-<?= $loc['id'] ?>')">Cancel</button>
                    </div>
                </form>
            </div>
        </td>
        <?php endforeach; ?>

        <td><strong><?= $item['total_stock'] ?></strong></td>
        <td style="display:flex;gap:.3rem;flex-wrap:wrap;">
            <a href="view.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-secondary">View</a>
            <a href="label.php?type=inventory&ids=<?= $item['id'] ?>" target="_blank"
               class="btn btn-sm btn-ghost" title="Print Label">🏷</a>
            <?php if (Auth::isOwner()): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action"  value="delete_item">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Delete <?= htmlspecialchars(addslashes($item['name'])) ?>? This cannot be undone.')"
                        style="padding:.2rem .55rem;font-size:12px;">🗑</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<script>
function togglePop(id) {
    const el = document.getElementById(id);
    const isOpen = el.classList.contains('open');
    // Close all open popovers first
    document.querySelectorAll('.stock-popover.open').forEach(p => p.classList.remove('open'));
    if (!isOpen) {
        el.classList.add('open');
        // Focus the quantity field
        const inp = el.querySelector('input[type=number]');
        if (inp) { inp.select(); }
    }
}
function closePop(id) {
    document.getElementById(id).classList.remove('open');
}
// Close on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.stock-cell')) {
        document.querySelectorAll('.stock-popover.open').forEach(p => p.classList.remove('open'));
    }
});
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
