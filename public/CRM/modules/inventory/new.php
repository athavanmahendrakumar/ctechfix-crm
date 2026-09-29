<?php
// ============================================================
// Add / Edit Inventory Item
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$isStaff   = Auth::isStaff();

// Staff only see their own location's stock fields
$locations = ($isOwner || $isManager)
    ? DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', [])
    : DB::query('SELECT * FROM locations WHERE is_active=1 AND id=? ORDER BY name', [$user['location_id']]);
$errors    = [];

// Editing existing?
$editId = intval($_GET['id'] ?? 0);
$item   = $editId ? DB::queryOne('SELECT * FROM inventory_items WHERE id=?', [$editId]) : null;
if ($editId && !$item) { header('Location: ' . APP_URL . '/modules/inventory/'); exit; }

// Load current stock for edit
$currentStock = [];
if ($item) {
    $rows = DB::query('SELECT * FROM inventory_stock WHERE item_id=?', [$item['id']]);
    foreach ($rows as $r) $currentStock[$r['location_id']] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name']          ?? '');
    $category     = trim($_POST['category']      ?? '');
    $customCat    = trim($_POST['category_custom'] ?? '');
    $itemType     = $_POST['item_type']           ?? 'part';
    $sku          = trim($_POST['sku']            ?? '');
    $description  = trim($_POST['description']   ?? '');
    $costPrice    = trim($_POST['cost_price']     ?? '0');
    $sellPrice    = trim($_POST['sell_price']     ?? '0');
    $compatWith   = trim($_POST['compatible_with'] ?? '');
    $buyOnDemand  = isset($_POST['buy_on_demand']) ? 1 : 0;

    if ($category === '__custom__') $category = $customCat;

    if (!$name)     $errors[] = 'Item name is required.';
    if (!$category) $errors[] = 'Category is required.';

    if (empty($errors)) {
        if ($item) {
            DB::execute(
                'UPDATE inventory_items SET name=?, category=?, item_type=?, sku=?, description=?,
                 cost_price=?, sell_price=?, compatible_with=?, buy_on_demand=? WHERE id=?',
                [$name, $category, $itemType, $sku ?: null, $description ?: null,
                 $costPrice, $sellPrice, $compatWith ?: null, $buyOnDemand, $item['id']]
            );
            $itemId = $item['id'];
        } else {
            $itemId = DB::insert(
                'INSERT INTO inventory_items (name, category, item_type, sku, description, cost_price, sell_price, compatible_with, buy_on_demand, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$name, $category, $itemType, $sku ?: null, $description ?: null,
                 $costPrice, $sellPrice, $compatWith ?: null, $buyOnDemand, $user['id']]
            );
            // Auto-generate barcode
            $barcode = 'CTF' . str_pad($itemId, 7, '0', STR_PAD_LEFT);
            DB::execute("UPDATE inventory_items SET barcode=? WHERE id=?", [$barcode, $itemId]);
        }

        // Save stock levels per location
        foreach ($locations as $loc) {
            $qty    = intval($_POST['stock_qty_'    . $loc['id']] ?? 0);
            $minQty = intval($_POST['stock_min_'    . $loc['id']] ?? 1);

            $existing = DB::queryOne(
                'SELECT id FROM inventory_stock WHERE item_id=? AND location_id=?',
                [$itemId, $loc['id']]
            );
            if ($existing) {
                DB::execute(
                    'UPDATE inventory_stock SET quantity=?, min_quantity=? WHERE item_id=? AND location_id=?',
                    [$qty, $minQty, $itemId, $loc['id']]
                );
            } else {
                DB::execute(
                    'INSERT INTO inventory_stock (item_id, location_id, quantity, min_quantity) VALUES (?,?,?,?)',
                    [$itemId, $loc['id'], $qty, $minQty]
                );
            }

            // Log movement if adding stock
            if (!$item && $qty > 0) {
                DB::execute(
                    'INSERT INTO inventory_movements (item_id, location_id, movement_type, quantity, reference_type, notes, created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$itemId, $loc['id'], 'in', $qty, 'manual', 'Initial stock entry', $user['id']]
                );
            }
        }

        header('Location: ' . APP_URL . '/modules/inventory/view.php?id=' . $itemId . '&msg=saved');
        exit;
    }
}

// Categories list — fixed authoritative list (do not merge from DB to avoid showing deprecated categories)
$catList = [];

$pageTitle = $item ? 'Edit: ' . $item['name'] : 'Add Inventory Item';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $item ? 'Edit Item' : 'Add Inventory Item' ?></h1>
    </div>
    <a href="<?= $item ? 'view.php?id='.$item['id'] : 'index.php' ?>" class="btn btn-secondary">← Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
    <div class="form-grid">

        <!-- Item Details -->
        <div class="card" style="grid-column:1/-1;">
            <div class="card-header"><h2 class="card-title">Item Details</h2></div>
            <div class="card-body">

                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label class="form-label">Item Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= htmlspecialchars($_POST['name'] ?? $item['name'] ?? '') ?>"
                               placeholder="e.g. iPhone 14 Pro Screen Assembly">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type <span class="required">*</span></label>
                        <select name="item_type" class="form-control">
                            <option value="part"    <?= ($_POST['item_type'] ?? $item['item_type'] ?? 'part') === 'part'    ? 'selected' : '' ?>>Part (used in repairs)</option>
                            <option value="product" <?= ($_POST['item_type'] ?? $item['item_type'] ?? '') === 'product' ? 'selected' : '' ?>>Product (sold as-is)</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Category <span class="required">*</span></label>
                        <select name="category" class="form-control" id="category_select" onchange="toggleCustomCat()">
                            <option value="">— Select —</option>
                            <?php
                            $defaultCats = [
                                // Parts
                                'Screen / Display','Battery','Charging Port','Camera',
                                'Speaker','Back Glass / Housing','Other Parts',
                                // Accessories
                                'Screen Protector','Case','Cable','Charging Block',
                                'Power Bank','Stand','Headphone','Headset','Bluetooth','Storage Device',
                                // SIM Cards
                                'SIM Cards',
                            ];
                            $allCats = array_unique(array_merge($defaultCats, $catList));
                            sort($allCats);
                            $selCat = $_POST['category'] ?? $item['category'] ?? '';
                            foreach ($allCats as $cat):
                            ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $selCat===$cat?'selected':'' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="__custom__" <?= $selCat==='__custom__'?'selected':'' ?>>+ Add new category</option>
                        </select>
                    </div>
                    <div class="form-group" id="custom_cat_group" style="display:none;">
                        <label class="form-label">New Category Name</label>
                        <input type="text" name="category_custom" class="form-control"
                               value="<?= htmlspecialchars($_POST['category_custom'] ?? '') ?>"
                               placeholder="Enter category name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SKU / Part Number</label>
                        <input type="text" name="sku" class="form-control"
                               value="<?= htmlspecialchars($_POST['sku'] ?? $item['sku'] ?? '') ?>"
                               placeholder="Optional">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Compatible With</label>
                    <input type="text" name="compatible_with" class="form-control"
                           value="<?= htmlspecialchars($_POST['compatible_with'] ?? $item['compatible_with'] ?? '') ?>"
                           placeholder="e.g. iPhone 14, iPhone 14 Pro — leave blank if universal">
                </div>

                <div class="form-group">
                    <label class="form-label">Description / Notes</label>
                    <textarea name="description" class="form-control" rows="2"
                              placeholder="Optional internal notes"><?= htmlspecialchars($_POST['description'] ?? $item['description'] ?? '') ?></textarea>
                </div>

            </div>
        </div>

        <!-- Pricing -->
        <div class="card" style="grid-column:1/-1;">
            <div class="card-header"><h2 class="card-title">Pricing</h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Cost Price ($)</label>
                        <input type="number" name="cost_price" class="form-control" step="0.01" min="0"
                               value="<?= htmlspecialchars($_POST['cost_price'] ?? $item['cost_price'] ?? '0') ?>"
                               placeholder="What you pay">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sell Price ($)</label>
                        <input type="number" name="sell_price" class="form-control" step="0.01" min="0"
                               value="<?= htmlspecialchars($_POST['sell_price'] ?? $item['sell_price'] ?? '0') ?>"
                               placeholder="What you charge">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;">
                        <div style="padding:10px 0;color:var(--text-2);font-size:14px;" id="margin_display"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Buy on Demand flag -->
        <div class="card" style="grid-column:1/-1;">
            <div class="card-body">
                <label style="display:flex;align-items:flex-start;gap:.75rem;cursor:pointer;">
                    <input type="checkbox" name="buy_on_demand" value="1"
                           <?= ($_POST['buy_on_demand'] ?? $item['buy_on_demand'] ?? 0) ? 'checked' : '' ?>
                           style="margin-top:3px;width:18px;height:18px;flex-shrink:0;">
                    <div>
                        <strong>Buy on Demand</strong>
                        <div class="form-hint" style="margin-top:2px;">
                            Check this if you only stock 1 unit and reorder when it sells out.
                            These items are <strong>excluded from the purchase pick list</strong> automatically.
                        </div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Stock per Location -->
        <div class="card" style="grid-column:1/-1;">
            <div class="card-header"><h2 class="card-title">Stock Levels</h2></div>
            <div class="card-body">
                <div class="form-row">
                <?php foreach ($locations as $loc): ?>
                <?php $s = $currentStock[$loc['id']] ?? null; ?>
                <div class="form-group" style="min-width:200px;">
                    <label class="form-label"><?= htmlspecialchars($loc['name']) ?></label>
                    <div style="display:flex;gap:.5rem;align-items:center;">
                        <div>
                            <div class="form-hint" style="margin-bottom:4px;">Qty in stock</div>
                            <input type="number" name="stock_qty_<?= $loc['id'] ?>" class="form-control"
                                   style="width:90px;" min="0"
                                   value="<?= $_POST['stock_qty_'.$loc['id']] ?? $s['quantity'] ?? 0 ?>">
                        </div>
                        <div>
                            <div class="form-hint" style="margin-bottom:4px;">Min (alert)</div>
                            <input type="number" name="stock_min_<?= $loc['id'] ?>" class="form-control"
                                   style="width:90px;" min="0"
                                   value="<?= $_POST['stock_min_'.$loc['id']] ?? $s['min_quantity'] ?? 1 ?>">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <div class="form-hint">Set Min to the quantity at which you want a low stock alert.</div>
            </div>
        </div>

    </div>

    <div class="form-actions">
        <a href="<?= $item ? 'view.php?id='.$item['id'] : 'index.php' ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg"><?= $item ? 'Save Changes' : 'Add Item →' ?></button>
    </div>
</form>

<script>
function toggleCustomCat() {
    const sel = document.getElementById('category_select');
    document.getElementById('custom_cat_group').style.display =
        sel.value === '__custom__' ? 'block' : 'none';
}

// Margin calculator
function updateMargin() {
    const cost = parseFloat(document.querySelector('[name=cost_price]').value) || 0;
    const sell = parseFloat(document.querySelector('[name=sell_price]').value) || 0;
    const el   = document.getElementById('margin_display');
    if (sell > 0) {
        const margin = ((sell - cost) / sell * 100).toFixed(1);
        const profit = (sell - cost).toFixed(2);
        el.innerHTML = `<strong>$${profit}</strong> profit<br><span class="text-muted">${margin}% margin</span>`;
    } else {
        el.innerHTML = '';
    }
}
document.querySelector('[name=cost_price]').addEventListener('input', updateMargin);
document.querySelector('[name=sell_price]').addEventListener('input', updateMargin);
updateMargin();

// Show custom cat if needed on reload
toggleCustomCat();
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
