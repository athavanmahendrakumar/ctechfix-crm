<?php
// ============================================================
// Inventory Item Detail
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$itemId    = intval($_GET['id'] ?? 0);
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$locations = ($isOwner || $isManager)
    ? DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', [])
    : DB::query('SELECT * FROM locations WHERE is_active=1 AND id=? ORDER BY name', [$user['location_id']]);

$item = DB::queryOne('SELECT * FROM inventory_items WHERE id=? AND is_active=1', [$itemId]);
if (!$item) { header('Location: ' . APP_URL . '/modules/inventory/'); exit; }

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_stock') {
    $locationId = intval($_POST['location_id'] ?? 0);
    $adjType    = $_POST['adj_type'] ?? 'in';
    $qty        = intval($_POST['quantity'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    if ($qty > 0 && $locationId) {
        $change = $adjType === 'out' ? -$qty : $qty;

        $existing = DB::queryOne(
            'SELECT * FROM inventory_stock WHERE item_id=? AND location_id=?',
            [$itemId, $locationId]
        );
        if ($existing) {
            DB::execute(
                'UPDATE inventory_stock SET quantity = GREATEST(0, quantity + ?) WHERE item_id=? AND location_id=?',
                [$change, $itemId, $locationId]
            );
        } else {
            DB::execute(
                'INSERT INTO inventory_stock (item_id, location_id, quantity, min_quantity) VALUES (?,?,?,1)',
                [$itemId, $locationId, max(0, $change)]
            );
        }

        DB::execute(
            'INSERT INTO inventory_movements (item_id, location_id, movement_type, quantity, reference_type, notes, created_by)
             VALUES (?,?,?,?,?,?,?)',
            [$itemId, $locationId, 'adjustment', $change, 'manual', $notes ?: null, $user['id']]
        );
    }

    header('Location: ' . APP_URL . '/modules/inventory/view.php?id=' . $itemId . '&msg=stock_updated');
    exit;
}

// Load stock
$stockRows = DB::query(
    'SELECT s.*, l.name AS location_name, l.code AS location_code
     FROM inventory_stock s
     JOIN locations l ON s.location_id = l.id
     WHERE s.item_id = ?',
    [$itemId]
);
$stockByLoc = [];
foreach ($stockRows as $r) $stockByLoc[$r['location_id']] = $r;

// Load movement history
$movements = DB::query(
    "SELECT m.*, l.name AS location_name, l.code AS location_code, u.first_name AS done_by
     FROM inventory_movements m
     LEFT JOIN locations l ON m.location_id = l.id
     LEFT JOIN users u ON m.created_by = u.id
     WHERE m.item_id = ?
     ORDER BY m.created_at DESC
     LIMIT 50",
    [$itemId]
);

$msgMap = [
    'saved'         => '✓ Item saved.',
    'stock_updated' => '✓ Stock updated.',
];

$pageTitle = $item['name'];
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($item['name']) ?></h1>
        <p class="page-sub">
            <span class="badge <?= $item['item_type']==='part' ? 'badge-info' : 'badge-primary' ?>">
                <?= ucfirst($item['item_type']) ?>
            </span>
            &nbsp;<?= htmlspecialchars($item['category']) ?>
            <?php if ($item['sku']): ?>&nbsp;· SKU: <?= htmlspecialchars($item['sku']) ?><?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="label.php?type=inventory&ids=<?= $itemId ?>&autoprint=1" target="_blank" class="btn btn-secondary">🏷 Print Label</a>
        <a href="new.php?id=<?= $itemId ?>" class="btn btn-secondary">✏ Edit</a>
        <a href="index.php" class="btn btn-secondary">← Inventory</a>
    </div>
</div>

<?php if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): ?>
<div class="alert alert-success"><?= $msgMap[$_GET['msg']] ?></div>
<?php endif; ?>

<div class="repair-grid">

    <!-- LEFT -->
    <div class="repair-col-main">

        <!-- Stock per Location -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Stock Levels</h2></div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;">
                <?php foreach ($locations as $loc): ?>
                <?php $s = $stockByLoc[$loc['id']] ?? null; ?>
                <div style="background:var(--surface-2);border-radius:8px;padding:1rem;border:1px solid var(--border);">
                    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.5rem;">
                        <?= htmlspecialchars($loc['name']) ?>
                    </div>
                    <?php if ($s): ?>
                        <?php $low = $s['quantity'] <= $s['min_quantity']; ?>
                        <div style="font-size:32px;font-weight:800;color:<?= $low ? 'var(--red)' : 'var(--text)' ?>;">
                            <?= $s['quantity'] ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-3);">
                            Min: <?= $s['min_quantity'] ?>
                            <?php if ($low): ?><span style="color:var(--red);"> ⚠ Low</span><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:32px;font-weight:800;color:var(--text-3);">—</div>
                        <div style="font-size:12px;color:var(--text-3);">Not tracked</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Adjust Stock -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Adjust Stock</h2></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="adjust_stock">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <select name="location_id" class="form-control" required>
                                <option value="">— Select —</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Adjustment</label>
                            <select name="adj_type" class="form-control">
                                <option value="in">➕ Add Stock</option>
                                <?php if ($isOwner || $isManager): ?>
                                <option value="out">➖ Remove Stock</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 100px;">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="form-group" style="flex:2;">
                            <label class="form-label">Reason</label>
                            <input type="text" name="notes" class="form-control" placeholder="e.g. Received from supplier">
                        </div>
                        <div class="form-group" style="display:flex;align-items:flex-end;">
                            <button type="submit" class="btn btn-primary">Update Stock</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Movement History -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Stock History</h2></div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($movements)): ?>
                <p class="text-muted" style="padding:1rem;">No movements yet.</p>
                <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Date</th><th>Location</th><th>Type</th><th>Qty</th><th>Notes</th><th>By</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($movements as $m): ?>
                    <tr>
                        <td class="text-muted small"><?= timeAgo($m['created_at']) ?></td>
                        <td><span class="badge badge-loc"><?= htmlspecialchars($m['location_code']) ?></span></td>
                        <td>
                            <span class="badge <?= $m['quantity'] > 0 ? 'badge-success' : 'badge-danger' ?>">
                                <?= $m['quantity'] > 0 ? '+ ' . $m['quantity'] : $m['quantity'] ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= ucwords(str_replace('_',' ',$m['movement_type'])) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($m['notes'] ?? '—') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($m['done_by'] ?? 'System') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- RIGHT -->
    <div class="repair-col-side">

        <!-- Pricing -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Pricing</h2></div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Cost Price</span>
                        <span class="detail-value">$<?= number_format($item['cost_price'], 2) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Sell Price</span>
                        <span class="detail-value" style="font-weight:600;">$<?= number_format($item['sell_price'], 2) ?></span>
                    </div>
                    <?php
                    $profit = $item['sell_price'] - $item['cost_price'];
                    $margin = $item['sell_price'] > 0 ? ($profit / $item['sell_price'] * 100) : 0;
                    ?>
                    <div class="detail-item">
                        <span class="detail-label">Profit</span>
                        <span class="detail-value" style="color:var(--green);">$<?= number_format($profit, 2) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Margin</span>
                        <span class="detail-value" style="color:var(--green);"><?= number_format($margin, 1) ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Details</h2></div>
            <div class="card-body">
                <div class="detail-grid">
                    <?php if ($item['compatible_with']): ?>
                    <div class="detail-item" style="grid-column:1/-1;">
                        <span class="detail-label">Compatible With</span>
                        <span class="detail-value"><?= htmlspecialchars($item['compatible_with']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($item['description']): ?>
                    <div class="detail-item" style="grid-column:1/-1;">
                        <span class="detail-label">Notes</span>
                        <span class="detail-value"><?= nl2br(htmlspecialchars($item['description'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <span class="detail-label">Added</span>
                        <span class="detail-value small"><?= timeAgo($item['created_at']) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
