<?php
// ============================================================
// Walk-in Sales — New Sale & History
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/RecordNumber.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// Load tax rate from settings
$taxSetting = DB::queryOne("SELECT value FROM settings WHERE setting_key='tax_rate'", []);
$taxRate    = floatval($taxSetting['value'] ?? 13);

// ── AJAX: product lookup ─────────────────────────────────────
if (isset($_GET['lookup_barcode'])) {
    header('Content-Type: application/json');
    $code  = trim($_GET['lookup_barcode']);
    $locId = intval($_GET['location_id'] ?? 0);
    // Check regular inventory first
    $item = DB::queryOne(
        "SELECT i.id, i.name, i.sku, i.cost_price, i.sell_price,
                COALESCE(s.quantity,0) AS stock
         FROM inventory_items i
         LEFT JOIN inventory_stock s ON s.item_id=i.id AND s.location_id=?
         WHERE i.barcode=? AND i.is_active=1 LIMIT 1",
        [$locId, $code]
    );
    // Check used devices
    if (!$item) {
        $ud = DB::queryOne(
            "SELECT id, CONCAT(device_brand,' ',device_model) AS name, barcode AS sku,
                    purchase_price AS cost_price, selling_price AS sell_price
             FROM used_devices WHERE barcode=? AND status='in_stock' LIMIT 1",
            [$code]
        );
        if ($ud) {
            $ud['used_device_id'] = $ud['id'];
            $ud['id'] = null; // not a regular inventory item
            $item = $ud;
        }
    }
    echo json_encode($item ?: null);
    exit;
}

if (isset($_GET['lookup_product'])) {
    header('Content-Type: application/json');
    $locId = intval($_GET['location_id'] ?? 0);
    $term  = '%' . trim($_GET['lookup_product']) . '%';

    // Regular inventory items
    $rows = DB::query(
        "SELECT i.id, i.name, i.sku, i.cost_price, i.sell_price,
                COALESCE(s.quantity,0) AS stock,
                NULL AS used_device_id, 'inventory' AS item_type
         FROM inventory_items i
         LEFT JOIN inventory_stock s ON s.item_id=i.id AND s.location_id=?
         WHERE i.is_active=1
           AND (i.name LIKE ? OR i.sku LIKE ?)
         ORDER BY i.name LIMIT 15",
        [$locId, $term, $term]
    );

    // Used devices in stock
    $locFilter = $locId ? "AND location_id=?" : "";
    $udParams  = $locId ? [$term, $term, $locId] : [$term, $term];
    $used = DB::query(
        "SELECT NULL AS id,
                CONCAT(device_brand,' ',device_model) AS name,
                barcode AS sku, purchase_price AS cost_price,
                selling_price AS sell_price, 1 AS stock,
                id AS used_device_id, 'used_device' AS item_type,
                storage, condition_grade
         FROM used_devices
         WHERE status='in_stock' $locFilter
           AND (CONCAT(device_brand,' ',device_model) LIKE ? OR barcode LIKE ?)
         ORDER BY created_at DESC LIMIT 10",
        array_merge($locId ? [$locId] : [], [$term, $term])
    );

    echo json_encode(array_merge($rows, $used));
    exit;
}

// ── POST: delete sale (owner only) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_sale') {
    if (Auth::isOwner()) {
        $delId = intval($_POST['sale_id'] ?? 0);
        if ($delId) {
            DB::execute('DELETE FROM sale_items WHERE sale_id=?', [$delId]);
            DB::execute('DELETE FROM sales WHERE id=?', [$delId]);
        }
    }
    header('Location: ' . APP_URL . '/modules/sales/?deleted=1'); exit;
}

// ── POST: create sale ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationId = intval($_POST['location_id']  ?? $user['location_id']);
    $payMethod  = $_POST['payment_method']       ?? 'cash';
    $discount   = floatval($_POST['discount']    ?? 0);
    $notes      = trim($_POST['notes']           ?? '');
    $applyTax   = isset($_POST['apply_tax']);

    $itemIds        = $_POST['item_id']             ?? [];
    $itemNames      = $_POST['item_name']           ?? [];
    $itemSkus       = $_POST['item_sku']            ?? [];
    $itemQtys       = $_POST['item_qty']            ?? [];
    $itemCosts      = $_POST['item_cost']           ?? [];
    $itemPrices     = $_POST['item_price']          ?? [];
    $itemUsedDevIds = $_POST['item_used_device_id'] ?? [];

    $lineItems = [];
    $subtotal  = 0;

    foreach ($itemNames as $i => $name) {
        $name = trim($name);
        if (!$name) continue;
        $qty   = max(1, intval($itemQtys[$i]        ?? 1));
        $price = floatval($itemPrices[$i]            ?? 0);
        $cost  = floatval($itemCosts[$i]             ?? 0);
        $invId = intval($itemIds[$i]                 ?? 0) ?: null;
        $udId  = intval($itemUsedDevIds[$i]          ?? 0) ?: null;
        $sku   = trim($itemSkus[$i]                  ?? '') ?: null;
        $line  = round($qty * $price, 2);
        $subtotal += $line;
        $lineItems[] = compact('invId','udId','name','sku','qty','cost','price','line');
    }

    if (!empty($lineItems)) {
        $taxAmount = $applyTax ? round($subtotal * $taxRate / 100, 2) : 0;
        $total     = round($subtotal + $taxAmount - $discount, 2);

        $loc       = DB::queryOne('SELECT code FROM locations WHERE id=?', [$locationId]);
        $locCode   = $loc['code'] ?? 'OS';
        $recordNum = RecordNumber::next('SALE', $locCode);

        $saleId = DB::insert(
            'INSERT INTO sales (record_number, location_id, subtotal, tax_amount, discount_amount, total_amount, payment_method, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$recordNum, $locationId, $subtotal, $taxAmount, $discount, $total, $payMethod, $notes ?: null, $user['id']]
        );

        foreach ($lineItems as $li) {
            DB::execute(
                'INSERT INTO sale_items (sale_id, inventory_item_id, item_name, item_sku, quantity, unit_cost, unit_price, line_total)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$saleId, $li['invId'], $li['name'], $li['sku'], $li['qty'],
                 $li['cost'] ?: null, $li['price'], $li['line']]
            );
            if ($li['invId']) {
                DB::execute(
                    'UPDATE inventory_stock SET quantity=GREATEST(0,quantity-?) WHERE item_id=? AND location_id=?',
                    [$li['qty'], $li['invId'], $locationId]
                );
                DB::execute(
                    'INSERT INTO inventory_movements (item_id,location_id,movement_type,quantity,reference_type,reference_id,notes,created_by)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$li['invId'], $locationId, 'sale', -$li['qty'], 'sale', $saleId,
                     'Walk-in sale ' . $recordNum, $user['id']]
                );
            }
            if ($li['udId']) {
                DB::execute(
                    "UPDATE used_devices SET status='sold', sale_id=?, sold_at=NOW() WHERE id=?",
                    [$saleId, $li['udId']]
                );
            }
        }

        header('Location: ' . APP_URL . '/modules/sales/?msg=sold&sale_id=' . $saleId); exit;
    }
}

// ── Sales history ────────────────────────────────────────────
$filterMonth    = $_GET['month']       ?? date('Y-m');
$filterLocation = intval($_GET['location_id'] ?? 0);
$dateFrom = $filterMonth . '-01';
$dateTo   = date('Y-m-t', strtotime($dateFrom));

$where  = ['s.created_at BETWEEN ? AND ?'];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if ($filterLocation) { $where[] = 's.location_id = ?'; $params[] = $filterLocation; }
if (Auth::isStaff())  { $where[] = 's.location_id = ?'; $params[] = $user['location_id']; }

$sales = DB::query(
    "SELECT s.*, l.name AS location_name, l.code AS location_code, u.first_name AS sold_by
     FROM sales s
     JOIN locations l ON s.location_id=l.id
     LEFT JOIN users u ON s.created_by=u.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY s.created_at DESC",
    $params
);

$totalRevenue = array_sum(array_column($sales, 'total_amount'));

$msgMap    = ['sold' => '✓ Sale recorded successfully.'];
$pageTitle = 'Walk-in Sales';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Walk-in Sales</h1>
        <p class="page-sub"><?= date('F Y', strtotime($dateFrom)) ?> · <?= count($sales) ?> sale<?= count($sales)!==1?'s':'' ?> · $<?= number_format($totalRevenue,2) ?> revenue</p>
    </div>
</div>

<?php if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): ?>
<div class="alert alert-success"><?= $msgMap[$_GET['msg']] ?></div>
<?php endif; ?>

<div class="repair-grid">

    <!-- LEFT: New Sale -->
    <div class="repair-col-main">
        <div class="card">
            <div class="card-header"><h2 class="card-title">New Sale</h2></div>
            <div class="card-body">
                <form method="POST" id="sale-form">

                    <div class="form-row">
                        <?php if (Auth::isOwner() || Auth::isManager()): ?>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <select name="location_id" id="sale_location" class="form-control">
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>" <?= $user['location_id']==$loc['id']?'selected':'' ?>>
                                    <?= htmlspecialchars($loc['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="location_id" id="sale_location" value="<?= $user['location_id'] ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-control">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="e-transfer">E-Transfer</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- Barcode scan -->
                    <div class="form-group" style="position:relative;">
                        <label class="form-label">🔍 Scan Barcode</label>
                        <input type="text" id="barcode_scan" class="form-control"
                               placeholder="Scan or type barcode — press Enter" autocomplete="off"
                               style="font-family:monospace;font-size:15px;border:2px solid var(--blue);">
                        <div id="scan_feedback" style="font-size:12px;margin-top:3px;min-height:16px;"></div>
                    </div>

                    <!-- Product search -->
                    <div class="form-group" style="position:relative;">
                        <label class="form-label">Search Inventory</label>
                        <input type="text" id="product_search" class="form-control"
                               placeholder="Type to search products/parts..." autocomplete="off">
                        <div id="product_results" style="display:none;position:absolute;z-index:200;background:#fff;
                             border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow);
                             left:0;right:0;max-height:220px;overflow-y:auto;"></div>
                    </div>

                    <!-- Items table -->
                    <table class="data-table" style="margin-bottom:1rem;">
                        <thead>
                            <tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Total</th><th></th></tr>
                        </thead>
                        <tbody id="items-body">
                            <tr id="no-items-row">
                                <td colspan="5" class="text-muted" style="text-align:center;padding:1rem;">
                                    Search above or add a custom item below
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Manual add -->
                    <div class="form-row" style="background:var(--surface-2);padding:.75rem;border-radius:8px;margin-bottom:1rem;align-items:flex-end;">
                        <div class="form-group" style="flex:2;margin-bottom:0;">
                            <label class="form-label" style="font-size:12px;">Custom Item Name</label>
                            <input type="text" id="manual_name" class="form-control" placeholder="e.g. Screen Protector">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" style="font-size:12px;">Price ($)</label>
                            <input type="number" id="manual_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group" style="flex:0 0 70px;margin-bottom:0;">
                            <label class="form-label" style="font-size:12px;">Qty</label>
                            <input type="number" id="manual_qty" class="form-control" value="1" min="1">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <button type="button" class="btn btn-secondary" onclick="addManualItem()">+ Add</button>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div style="background:var(--surface-2);border-radius:8px;padding:1rem;margin-bottom:1rem;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                            <span>Subtotal</span><strong id="disp-sub">$0.00</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                            <label style="display:flex;align-items:center;gap:.5rem;font-size:13px;cursor:pointer;margin:0;">
                                <input type="checkbox" name="apply_tax" id="apply_tax" value="1" checked onchange="recalc()">
                                HST (<?= $taxRate ?>%)
                            </label>
                            <strong id="disp-tax">$0.00</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                            <label style="font-size:13px;margin:0;">Discount ($)</label>
                            <input type="number" name="discount" id="disc" class="form-control"
                                   style="width:110px;text-align:right;" step="0.01" min="0" value="0" oninput="recalc()">
                        </div>
                        <div style="display:flex;justify-content:space-between;padding-top:.5rem;border-top:2px solid var(--border);font-size:1.1rem;">
                            <strong>Total</strong>
                            <strong id="disp-total" style="color:var(--blue);">$0.00</strong>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes (optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Customer name">
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg" style="width:100%;" id="submit-btn" disabled>
                        Complete Sale →
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT: History -->
    <div class="repair-col-side">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Sales History</h2></div>
            <div class="card-body" style="padding-bottom:.5rem;">
                <form method="GET" style="display:flex;gap:.5rem;">
                    <input type="month" name="month" class="form-control"
                           value="<?= $filterMonth ?>" onchange="this.form.submit()">
                    <?php if (Auth::isOwner() || Auth::isManager()): ?>
                    <select name="location_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>" <?= $filterLocation==$loc['id']?'selected':'' ?>>
                            <?= htmlspecialchars($loc['code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </form>
            </div>
            <?php if (empty($sales)): ?>
            <div class="card-body"><p class="text-muted">No sales this period.</p></div>
            <?php else: ?>
            <div style="max-height:500px;overflow-y:auto;">
            <table class="data-table">
                <thead><tr><th>Sale #</th><th>Loc</th><th>Total</th><th>By</th><?php if (Auth::isOwner()): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($sales as $sale): ?>
                <tr>
                    <td style="cursor:pointer;" onclick="window.location='view.php?id=<?= $sale['id'] ?>'">
                        <strong><?= htmlspecialchars($sale['record_number']) ?></strong><br>
                        <span class="text-muted small"><?= timeAgo($sale['created_at']) ?></span>
                    </td>
                    <td style="cursor:pointer;" onclick="window.location='view.php?id=<?= $sale['id'] ?>'"><span class="badge badge-loc"><?= htmlspecialchars($sale['location_code']) ?></span></td>
                    <td style="cursor:pointer;" onclick="window.location='view.php?id=<?= $sale['id'] ?>'" ><strong>$<?= number_format($sale['total_amount'],2) ?></strong></td>
                    <td style="cursor:pointer;" onclick="window.location='view.php?id=<?= $sale['id'] ?>'" class="text-muted small"><?= htmlspecialchars($sale['sold_by']??'—') ?></td>
                    <?php if (Auth::isOwner()): ?>
                    <td onclick="event.stopPropagation()">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action"  value="delete_sale">
                            <input type="hidden" name="sale_id" value="<?= $sale['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Delete sale <?= htmlspecialchars($sale['record_number']) ?>? This cannot be undone.')"
                                    style="padding:.2rem .55rem;font-size:12px;">🗑</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;background:var(--surface-2);">
                        <td colspan="2">Total</td>
                        <td>$<?= number_format($totalRevenue,2) ?></td>
                        <td><?php if (Auth::isOwner()): ?><?php endif; ?></td>
                        <?php if (Auth::isOwner()): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
const TAX_RATE = <?= $taxRate ?>;
let itemCount  = 0;

const searchEl  = document.getElementById('product_search');
const resultsEl = document.getElementById('product_results');
const locEl     = document.getElementById('sale_location');

// ── Barcode scan ─────────────────────────────────────────────
const scanEl    = document.getElementById('barcode_scan');
const scanFb    = document.getElementById('scan_feedback');
scanEl.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = this.value.trim();
    if (!code) return;
    const locId = locEl ? locEl.value : '<?= $user['location_id'] ?>';
    fetch('?lookup_barcode=' + encodeURIComponent(code) + '&location_id=' + locId)
        .then(r => r.json())
        .then(item => {
            if (item) {
                addRow(item.id, item.name, item.sku || '', item.cost_price, item.sell_price || item.selling_price, 1, item.used_device_id || '');
                scanFb.style.color = 'var(--green)';
                scanFb.textContent = '✓ Added: ' + item.name;
            } else {
                scanFb.style.color = 'var(--red)';
                scanFb.textContent = '✗ Barcode not found: ' + code;
            }
            this.value = '';
            this.focus();
            setTimeout(() => { scanFb.textContent = ''; }, 3000);
        });
});

let timer = null;
searchEl.addEventListener('input', function () {
    clearTimeout(timer);
    if (this.value.trim().length < 2) { resultsEl.style.display='none'; return; }
    timer = setTimeout(() => {
        const locId = locEl ? locEl.value : '<?= $user['location_id'] ?>';
        fetch('?lookup_product=' + encodeURIComponent(this.value.trim()) + '&location_id=' + locId)
            .then(r => r.json()).then(renderResults);
    }, 300);
});
document.addEventListener('click', e => {
    if (!searchEl.contains(e.target) && !resultsEl.contains(e.target))
        resultsEl.style.display = 'none';
});

function renderResults(items) {
    resultsEl.innerHTML = '';
    if (!items.length) {
        resultsEl.innerHTML = '<div style="padding:.75rem 1rem;color:var(--text-3);font-size:13px;">No items found.</div>';
    } else {
        items.forEach(item => {
            const isUsed = item.item_type === 'used_device';
            const d = document.createElement('div');
            d.style.cssText = 'padding:.6rem 1rem;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px;';
            const badge = isUsed
                ? '<span style="background:var(--blue);color:#fff;font-size:10px;padding:1px 5px;border-radius:4px;margin-left:5px;">Used</span>'
                : '';
            const sub = isUsed
                ? (item.storage ? item.storage + ' · ' : '') + (item.condition_grade || '')
                : '';
            d.innerHTML = '<strong>' + esc(item.name) + '</strong>' + badge +
                (item.sku ? ' <span style="color:var(--text-3);">· ' + esc(item.sku) + '</span>' : '') +
                (sub ? '<br><span style="color:var(--text-3);font-size:11px;">' + esc(sub) + '</span>' : '') +
                '<br><span style="color:var(--text-3);">$' + parseFloat(item.sell_price).toFixed(2) +
                (isUsed ? ' · <span style="color:var(--green);">In Stock</span>' :
                 ' · <span style="color:' + (item.stock>0?'var(--green)':'var(--red)') + ';">' + item.stock + ' in stock</span>') +
                '</span>';
            d.addEventListener('mouseenter', () => d.style.background = 'var(--surface-2)');
            d.addEventListener('mouseleave', () => d.style.background = '');
            d.addEventListener('click', () => {
                addRow(item.id, item.name, item.sku||'', item.cost_price, item.sell_price, 1, item.used_device_id||'');
                searchEl.value = ''; resultsEl.style.display = 'none';
            });
            resultsEl.appendChild(d);
        });
    }
    resultsEl.style.display = 'block';
}

function addRow(invId, name, sku, cost, price, qty, usedDeviceId) {
    const tbody = document.getElementById('items-body');
    const noRow = document.getElementById('no-items-row');
    if (noRow) noRow.remove();
    const idx = itemCount++;
    const tr  = document.createElement('tr');
    tr.id = 'row-' + idx;
    tr.innerHTML = `
        <input type="hidden" name="item_id[${idx}]"             value="${invId||''}">
        <input type="hidden" name="item_used_device_id[${idx}]" value="${usedDeviceId||''}">
        <input type="hidden" name="item_sku[${idx}]"            value="${esc(sku)}">
        <input type="hidden" name="item_cost[${idx}]"           value="${cost||0}">
        <td><input type="text"   name="item_name[${idx}]"  class="form-control form-control-sm" value="${esc(name)}" required></td>
        <td><input type="number" name="item_qty[${idx}]"   class="form-control form-control-sm item-qty"   value="${qty}"   min="1" style="width:60px;" oninput="recalc()"></td>
        <td><input type="number" name="item_price[${idx}]" class="form-control form-control-sm item-price" value="${parseFloat(price).toFixed(2)}" step="0.01" min="0" style="width:90px;" oninput="recalc()"></td>
        <td class="item-line" style="font-weight:600;">$${(qty*price).toFixed(2)}</td>
        <td><button type="button" class="btn btn-sm btn-ghost" style="color:var(--red);" onclick="removeRow(${idx})">✕</button></td>
    `;
    tbody.appendChild(tr);
    recalc();
}

function addManualItem() {
    const name  = document.getElementById('manual_name').value.trim();
    const price = parseFloat(document.getElementById('manual_price').value) || 0;
    const qty   = parseInt(document.getElementById('manual_qty').value) || 1;
    if (!name) { alert('Enter an item name.'); return; }
    addRow('', name, '', 0, price, qty);
    document.getElementById('manual_name').value  = '';
    document.getElementById('manual_price').value = '';
    document.getElementById('manual_qty').value   = '1';
}

function removeRow(idx) {
    const row = document.getElementById('row-' + idx);
    if (row) row.remove();
    if (!document.querySelectorAll('#items-body tr[id^="row-"]').length) {
        document.getElementById('items-body').innerHTML =
            '<tr id="no-items-row"><td colspan="5" class="text-muted" style="text-align:center;padding:1rem;">Search above or add a custom item below</td></tr>';
    }
    recalc();
}

function recalc() {
    let subtotal = 0;
    document.querySelectorAll('#items-body tr[id^="row-"]').forEach(row => {
        const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const line  = qty * price;
        row.querySelector('.item-line').textContent = '$' + line.toFixed(2);
        subtotal += line;
    });
    const tax      = document.getElementById('apply_tax').checked ? subtotal * TAX_RATE / 100 : 0;
    const discount = parseFloat(document.getElementById('disc').value) || 0;
    const total    = Math.max(0, subtotal + tax - discount);
    document.getElementById('disp-sub').textContent   = '$' + subtotal.toFixed(2);
    document.getElementById('disp-tax').textContent   = '$' + tax.toFixed(2);
    document.getElementById('disp-total').textContent = '$' + total.toFixed(2);
    document.getElementById('submit-btn').disabled =
        !document.querySelectorAll('#items-body tr[id^="row-"]').length;
}

function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
