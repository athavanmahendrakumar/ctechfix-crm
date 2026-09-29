<?php
// ============================================================
// Expenses - List, Add (with line items + inventory + cash accounts)
// Manager & Owner only
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/'); exit;
}

$user       = Auth::user();
$isOwner    = Auth::isOwner();
$locations  = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);
$categories = DB::query('SELECT * FROM expense_categories WHERE is_active=1 ORDER BY name', []);
$cashAccounts = DB::query("SELECT * FROM cash_accounts WHERE is_active=1 ORDER BY sort_order, name", []);

// Open repairs for autocomplete
$openRepairs = DB::query(
    "SELECT r.id, r.record_number, c.first_name, c.last_name, r.device_brand, r.device_model, r.issue_description
     FROM repairs r
     JOIN customers c ON c.id = r.customer_id
     WHERE r.status NOT IN ('completed','cancelled','picked_up') AND r.is_training=0
     ORDER BY r.created_at DESC LIMIT 200", []
);
$repairsJson = json_encode(array_map(fn($r) => [
    'id'    => $r['id'],
    'label' => '#' . $r['record_number'] . ' - ' . $r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['device_brand'] . ' ' . $r['device_model'] . ')',
], $openRepairs));

// Inventory items for autocomplete
$invItems = DB::query(
    "SELECT i.id, i.name, i.cost_price, i.sell_price,
            COALESCE(SUM(s.quantity),0) AS stock
     FROM inventory_items i
     LEFT JOIN inventory_stock s ON s.item_id = i.id
     WHERE i.is_active=1
     GROUP BY i.id
     ORDER BY i.name", []
);
$invJson = json_encode(array_map(fn($i) => [
    'id'    => $i['id'],
    'label' => $i['name'],
    'cost'  => $i['cost_price'],
    'stock' => $i['stock'],
], $invItems));

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add expense with line items ───────────────────────────
    if ($action === 'add_expense') {
        $locationId   = intval($_POST['location_id']    ?? $user['location_id']);
        $categoryId   = intval($_POST['category_id']    ?? 0);
        $vendorName   = trim($_POST['vendor_name']      ?? '');
        $date         = trim($_POST['expense_date']     ?? date('Y-m-d'));
        $notes        = trim($_POST['notes']            ?? '');
        $acctId       = intval($_POST['payment_account_id'] ?? 0) ?: null;
        $payLabel     = trim($_POST['payment_method_label'] ?? '');

        // Line items from POST
        $itemDescs      = $_POST['item_desc']      ?? [];
        $itemInvIds     = $_POST['item_inv_id']    ?? [];
        $itemRepairIds  = $_POST['item_repair_id'] ?? [];
        $itemQtys       = $_POST['item_qty']       ?? [];
        $itemCosts      = $_POST['item_cost']      ?? [];
        $itemSells      = $_POST['item_sell']      ?? [];

        // Calculate total from line items
        $total = 0;
        $lineItems = [];
        foreach ($itemDescs as $idx => $desc) {
            $desc     = trim($desc);
            $invId    = intval($itemInvIds[$idx]    ?? 0) ?: null;
            $repairId = intval($itemRepairIds[$idx] ?? 0) ?: null;
            $qty      = max(1, intval($itemQtys[$idx] ?? 1));
            $cost     = floatval($itemCosts[$idx] ?? 0);
            $sell     = floatval($itemSells[$idx]  ?? 0);
            if (!$desc || $cost <= 0) continue;
            $total += $cost * $qty;
            $lineItems[] = compact('desc','invId','repairId','qty','cost','sell');
        }

        if ($categoryId && $total > 0) {
          try {
            // Insert main expense row
            $expId = DB::insert(
                'INSERT INTO expenses (location_id, category_id, amount, description, vendor_name,
                  payment_account_id, payment_method_label, expense_date, notes, has_receipt, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?)',
                [$locationId, $categoryId, $total,
                 $vendorName ?: null, $vendorName ?: null,
                 $acctId, $payLabel ?: null,
                 $date, $notes ?: null, $user['id']]
            );

            // Insert line items & handle inventory + repairs
            foreach ($lineItems as $idx => $item) {
                $itemId = DB::insert(
                    'INSERT INTO expense_items (expense_id, description, inventory_item_id, repair_id, unit_cost, quantity)
                     VALUES (?,?,?,?,?,?)',
                    [$expId, $item['desc'], $item['invId'], $item['repairId'], $item['cost'], $item['qty']]
                );

                // If linked to a repair, add to repair_parts
                if ($item['repairId']) {
                    DB::execute(
                        'INSERT INTO repair_parts (repair_id, part_name, quantity, cost_price, sell_price, added_by)
                         VALUES (?,?,?,?,?,?)',
                        [$item['repairId'], $item['desc'], $item['qty'], $item['cost'], $item['sell'], $user['id']]
                    );
                    // Recalculate repair estimated_cost from all parts sell prices
                    DB::execute(
                        'UPDATE repairs SET estimated_cost = (
                            SELECT COALESCE(SUM(sell_price * quantity), 0)
                            FROM repair_parts WHERE repair_id = ?
                         ) WHERE id = ?',
                        [$item['repairId'], $item['repairId']]
                    );
                }

                // Location allocations
                $allocLocs = $_POST['alloc_loc_' . $idx] ?? [];
                $allocQtys = $_POST['alloc_qty_' . $idx] ?? [];
                $totalAlloc = 0;
                foreach ($allocLocs as $aIdx => $aLocId) {
                    $aLocId = intval($aLocId);
                    $aQty   = max(0, intval($allocQtys[$aIdx] ?? 0));
                    if ($aLocId && $aQty > 0) {
                        DB::execute(
                            'INSERT INTO expense_item_allocations (expense_item_id, location_id, quantity)
                             VALUES (?,?,?)',
                            [$itemId, $aLocId, $aQty]
                        );
                        $totalAlloc += $aQty;

                        // Update inventory stock
                        if ($item['invId']) {
                            // Update cost price
                            DB::execute(
                                'UPDATE inventory_items SET cost_price=? WHERE id=?',
                                [$item['cost'], $item['invId']]
                            );
                            // Upsert stock
                            DB::execute(
                                'INSERT INTO inventory_stock (item_id, location_id, quantity)
                                 VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE quantity = quantity + ?',
                                [$item['invId'], $aLocId, $aQty, $aQty]
                            );
                            // Log movement
                            DB::execute(
                                'INSERT INTO inventory_movements
                                 (item_id, location_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
                                 VALUES (?,?,?,?,?,?,?,?)',
                                [$item['invId'], $aLocId, 'in', $aQty, 'expense', $expId,
                                 'Purchase from ' . ($vendorName ?: 'supplier'), $user['id']]
                            );
                        }
                    }
                }

                // If no allocations entered but inventory linked, default to expense location
                if ($totalAlloc === 0 && $item['invId'] && $item['qty'] > 0) {
                    DB::execute(
                        'INSERT INTO expense_item_allocations (expense_item_id, location_id, quantity)
                         VALUES (?,?,?)',
                        [$itemId, $locationId, $item['qty']]
                    );
                    DB::execute(
                        'UPDATE inventory_items SET cost_price=? WHERE id=?',
                        [$item['cost'], $item['invId']]
                    );
                    DB::execute(
                        'INSERT INTO inventory_stock (item_id, location_id, quantity)
                         VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE quantity = quantity + ?',
                        [$item['invId'], $locationId, $item['qty'], $item['qty']]
                    );
                    DB::execute(
                        'INSERT INTO inventory_movements
                         (item_id, location_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [$item['invId'], $locationId, 'in', $item['qty'], 'expense', $expId,
                         'Purchase from ' . ($vendorName ?: 'supplier'), $user['id']]
                    );
                }
            }

            // Cash movement - deduct from payment account if it's a cash account
            if ($acctId && $total > 0) {
                DB::execute(
                    'INSERT INTO cash_movements
                     (from_account_id, amount, movement_type, expense_id, notes, moved_at, created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$acctId, $total, 'expense', $expId,
                     'Expense: ' . ($vendorName ?: 'Purchase'), $date . ' 12:00:00', $user['id']]
                );
            }
          } catch (\Exception $e) {
              header('Location: ' . APP_URL . '/modules/expenses/?err=' . urlencode($e->getMessage())); exit;
          }
        }
        header('Location: ' . APP_URL . '/modules/expenses/?msg=added'); exit;
    }

    // ── Delete expense (owner only) ───────────────────────────
    if ($action === 'delete_expense' && $isOwner) {
        $expId = intval($_POST['expense_id'] ?? 0);
        if ($expId) {
            DB::execute('UPDATE expenses SET deleted_at=NOW(), deleted_by=? WHERE id=?', [$user['id'], $expId]);
        }
        header('Location: ' . APP_URL . '/modules/expenses/?msg=deleted'); exit;
    }

    // ── Add category (owner only) ─────────────────────────────
    if ($action === 'add_category' && $isOwner) {
        $name = trim($_POST['cat_name'] ?? '');
        if ($name) DB::execute('INSERT IGNORE INTO expense_categories (name, created_by) VALUES (?,?)', [$name, $user['id']]);
        header('Location: ' . APP_URL . '/modules/expenses/?msg=cat_added&tab=categories'); exit;
    }

    // ── Delete category (owner only) ──────────────────────────
    if ($action === 'delete_category' && $isOwner) {
        $catId = intval($_POST['cat_id'] ?? 0);
        if ($catId) DB::execute('UPDATE expense_categories SET is_active=0 WHERE id=?', [$catId]);
        header('Location: ' . APP_URL . '/modules/expenses/?msg=cat_deleted&tab=categories'); exit;
    }
}

// ── Filters ──────────────────────────────────────────────────
$filterLocation = intval($_GET['location_id'] ?? 0);
$filterCategory = intval($_GET['category_id'] ?? 0);
$filterMonth    = $_GET['month'] ?? date('Y-m');
$activeTab      = $_GET['tab']   ?? 'expenses';

$dateFrom = $filterMonth . '-01';
$dateTo   = date('Y-m-t', strtotime($dateFrom));

$where  = ['e.deleted_at IS NULL'];
$params = [];
if ($filterLocation) { $where[] = 'e.location_id=?'; $params[] = $filterLocation; }
if ($filterCategory) { $where[] = 'e.category_id=?'; $params[] = $filterCategory; }
$where[]  = 'e.expense_date BETWEEN ? AND ?';
$params[] = $dateFrom;
$params[] = $dateTo;

$expenses = DB::query(
    "SELECT e.*, ec.name AS category_name, l.name AS location_name, l.code AS location_code,
            u.first_name AS added_by_name,
            ca.name AS account_name
     FROM expenses e
     JOIN expense_categories ec ON e.category_id = ec.id
     JOIN locations l           ON e.location_id  = l.id
     LEFT JOIN users u          ON e.added_by      = u.id
     LEFT JOIN cash_accounts ca ON ca.id = e.payment_account_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY e.expense_date DESC, e.created_at DESC",
    $params
);

$totalAmount = array_sum(array_column($expenses, 'amount'));

// Load line items for each expense
$expenseIds = array_column($expenses, 'id');
$lineItemMap = [];
if (!empty($expenseIds)) {
    $placeholders = implode(',', array_fill(0, count($expenseIds), '?'));
    $items = DB::query(
        "SELECT ei.*, ii.name AS inv_name
         FROM expense_items ei
         LEFT JOIN inventory_items ii ON ii.id = ei.inventory_item_id
         WHERE ei.expense_id IN ($placeholders)
         ORDER BY ei.id",
        $expenseIds
    );
    foreach ($items as $item) {
        $lineItemMap[$item['expense_id']][] = $item;
    }
}

$catTotals = [];
foreach ($expenses as $e) {
    $catTotals[$e['category_name']] = ($catTotals[$e['category_name']] ?? 0) + $e['amount'];
}
arsort($catTotals);

$msgMap = [
    'added'       => '✓ Expense added and inventory updated.',
    'deleted'     => '✓ Expense deleted.',
    'cat_added'   => '✓ Category added.',
    'cat_deleted' => '✓ Category removed.',
];

// Build payment options for dropdown
$payOptions = [];
foreach ($cashAccounts as $ca) {
    $payOptions[] = ['id' => 'acct_' . $ca['id'], 'label' => $ca['name'], 'acct_id' => $ca['id']];
}
$payOptions[] = ['id' => 'other_etransfer', 'label' => 'E-Transfer',    'acct_id' => null];
$payOptions[] = ['id' => 'other_cheque',    'label' => 'Cheque',         'acct_id' => null];

$pageTitle = 'Expenses';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">💸 Expenses</h1>
        <p class="page-sub"><?= date('F Y', strtotime($dateFrom)) ?> · $<?= number_format($totalAmount, 2) ?> total</p>
    </div>
</div>

<?php if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): ?>
<div class="alert alert-success"><?= $msgMap[$_GET['msg']] ?></div>
<?php endif; ?>
<?php if (isset($_GET['err'])): ?>
<div class="alert alert-danger"><strong>DB Error:</strong> <?= htmlspecialchars($_GET['err']) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-bar" style="margin-bottom:1.5rem;">
    <a href="?tab=expenses&month=<?= $filterMonth ?>" class="tab-link <?= $activeTab==='expenses'?'active':'' ?>">Expenses</a>
    <?php if ($isOwner): ?>
    <a href="?tab=categories" class="tab-link <?= $activeTab==='categories'?'active':'' ?>">Categories</a>
    <?php endif; ?>
</div>

<?php if ($activeTab === 'categories' && $isOwner): ?>
<!-- ── Categories Tab ─────────────────────────────────── -->
<div class="repair-grid">
    <div class="repair-col-main">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Expense Categories</h2></div>
            <div class="card-body" style="padding:0;">
                <table class="data-table">
                    <thead><tr><th>Category</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><?= htmlspecialchars($cat['name']) ?></td>
                        <td style="text-align:right;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action"  value="delete_category">
                                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('Remove this category?')">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="repair-col-side">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Add Category</h2></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_category">
                    <div class="form-group">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="cat_name" class="form-control" required
                               placeholder="e.g. Vehicle Expenses">
                    </div>
                    <button type="submit" class="btn btn-primary">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── Expenses Tab ───────────────────────────────────── -->

<!-- Filters -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="expenses">
    <input type="month" name="month" class="form-control filter-select"
           value="<?= $filterMonth ?>" onchange="this.form.submit()">
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLocation==$loc['id']?'selected':'' ?>>
            <?= htmlspecialchars($loc['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select name="category_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= $filterCategory==$cat['id']?'selected':'' ?>>
            <?= htmlspecialchars($cat['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <a href="?" class="btn btn-ghost">Clear</a>
</form>

<div class="repair-grid">
<div class="repair-col-main">

<!-- ── Add Expense Form ──────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title">+ Add Expense</h2>
        <span class="text-muted small">Receipt can be attached later</span>
    </div>
    <div class="card-body">
    <form method="POST" id="expense-form">
        <input type="hidden" name="action" value="add_expense">

        <!-- Row 1: Vendor, Category, Date, Location -->
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label class="form-label">Vendor / Store Name <span class="required">*</span></label>
                <input type="text" name="vendor_name" class="form-control" required
                       placeholder="e.g. Cell Solutions, Amazon, Canadian Tire">
            </div>
            <div class="form-group">
                <label class="form-label">Category <span class="required">*</span></label>
                <select name="category_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:0 0 160px;">
                <label class="form-label">Date <span class="required">*</span></label>
                <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                <div class="form-hint">Backdate if needed</div>
            </div>
        </div>

        <!-- Row 2: Location, Payment -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Location <span class="required">*</span></label>
                <select name="location_id" class="form-control" required>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>"
                        <?= $user['location_id']==$loc['id']?'selected':'' ?>>
                        <?= htmlspecialchars($loc['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:2;">
                <label class="form-label">Paid With <span class="required">*</span></label>
                <select name="payment_account_id" id="payment_account_id" class="form-control" required
                        onchange="updatePayLabel(this)">
                    <option value="">— Select —</option>
                    <?php foreach ($cashAccounts as $ca): ?>
                    <option value="<?= $ca['id'] ?>" data-label="<?= htmlspecialchars($ca['name']) ?>">
                        <?= htmlspecialchars($ca['name']) ?>
                    </option>
                    <?php endforeach; ?>
                    <option value="etransfer" data-label="E-Transfer">E-Transfer</option>
                    <option value="cheque"    data-label="Cheque">Cheque</option>
                    <option value="other"     data-label="Other">Other</option>
                </select>
                <input type="hidden" name="payment_method_label" id="payment_method_label">
            </div>
            <div class="form-group" style="flex:2;">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional">
            </div>
        </div>

        <!-- Line Items -->
        <div style="margin-top:.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                <label class="form-label" style="margin:0;font-weight:700;">
                    Items Purchased <span class="required">*</span>
                </label>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addLine()">+ Add Item</button>
            </div>

            <!-- Column headers -->
            <div style="display:grid;grid-template-columns:2fr 90px 100px 100px 24px;gap:.4rem;
                        font-size:11px;color:var(--text-3);font-weight:700;text-transform:uppercase;
                        letter-spacing:.5px;padding:0 .25rem;margin-bottom:.25rem;">
                <span>Description / Product</span>
                <span>Qty</span>
                <span>Cost Price</span>
                <span style="color:var(--green);">Sell Price</span>
                <span></span>
            </div>

            <div id="line-items"></div>

            <!-- Total display -->
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:1rem;
                        margin-top:.75rem;padding:.75rem 1rem;background:var(--surface-2);border-radius:8px;">
                <span style="font-size:13px;color:var(--text-2);">Total:</span>
                <span id="total-display" style="font-size:20px;font-weight:800;color:var(--green);">$0.00</span>
            </div>
        </div>

        <div style="margin-top:1rem;display:flex;gap:.75rem;align-items:center;">
            <button type="submit" class="btn btn-primary" onclick="return validateForm()">Save Expense</button>
            <span style="font-size:12px;color:var(--text-3);">
                📎 No receipt? That's OK — you can attach one later. It will be flagged as missing.
            </span>
        </div>
    </form>
    </div>
</div>

<!-- ── Expense List ──────────────────────────────────── -->
<?php if (empty($expenses)): ?>
<div class="empty-state">
    <div class="empty-icon">💸</div>
    <p>No expenses for <?= date('F Y', strtotime($dateFrom)) ?>.</p>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Expense History (<?= count($expenses) ?>)</h2>
    </div>
    <div class="card-body" style="padding:0;">
    <?php foreach ($expenses as $e):
        $items = $lineItemMap[$e['id']] ?? [];
        $hasReceipt = (bool)($e['has_receipt'] ?? false);
    ?>
    <div style="padding:.85rem 1rem;border-bottom:1px solid var(--border);">
        <div style="display:flex;align-items:flex-start;gap:.75rem;flex-wrap:wrap;">

            <!-- Receipt indicator -->
            <div style="flex-shrink:0;padding-top:2px;" title="<?= $hasReceipt?'Receipt attached':'No receipt' ?>">
                <?php if ($hasReceipt): ?>
                <span style="color:var(--green);font-size:16px;">📎</span>
                <?php else: ?>
                <span style="color:#f59e0b;font-size:16px;" title="No receipt attached">⚠️</span>
                <?php endif; ?>
            </div>

            <!-- Main info -->
            <div style="flex:1;min-width:200px;">
                <div style="font-weight:700;font-size:15px;">
                    <?= htmlspecialchars($e['vendor_name'] ?? $e['description'] ?? '—') ?>
                    <span class="badge badge-secondary" style="font-size:11px;margin-left:.4rem;">
                        <?= htmlspecialchars($e['category_name']) ?>
                    </span>
                    <?php if (!$hasReceipt): ?>
                    <span style="font-size:11px;color:#f59e0b;margin-left:.4rem;font-weight:600;">No Receipt</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small" style="margin-top:3px;display:flex;flex-wrap:wrap;gap:.6rem;">
                    <span>📅 <?= date('M j, Y', strtotime($e['expense_date'])) ?></span>
                    <span class="badge badge-loc"><?= htmlspecialchars($e['location_code']) ?></span>
                    <?php if ($e['account_name'] ?? ($e['payment_method_label'] ?? '')): ?>
                    <span>💳 <?= htmlspecialchars($e['account_name'] ?? $e['payment_method_label'] ?? '') ?></span>
                    <?php endif; ?>
                    <?php if ($e['added_by_name']): ?>
                    <span>👤 <?= htmlspecialchars($e['added_by_name']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Line items -->
                <?php if (!empty($items)): ?>
                <div style="margin-top:.5rem;display:flex;flex-direction:column;gap:.2rem;">
                <?php foreach ($items as $li): ?>
                <div style="font-size:12px;color:var(--text-2);display:flex;gap:.5rem;align-items:center;">
                    <span>·</span>
                    <span><?= htmlspecialchars($li['description']) ?></span>
                    <?php if ($li['inv_name']): ?>
                    <span style="color:var(--blue);font-size:11px;">
                        [<?= htmlspecialchars($li['inv_name']) ?>]
                    </span>
                    <?php endif; ?>
                    <span style="color:var(--text-3);">
                        <?= $li['quantity'] ?>× @ $<?= number_format($li['unit_cost'],2) ?>
                        = $<?= number_format($li['unit_cost']*$li['quantity'],2) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                </div>
                <?php elseif ($e['description']): ?>
                <div style="font-size:12px;color:var(--text-2);margin-top:.25rem;">
                    <?= htmlspecialchars($e['description']) ?>
                </div>
                <?php endif; ?>

                <?php if ($e['notes']): ?>
                <div style="font-size:12px;color:var(--text-3);margin-top:.25rem;"><?= htmlspecialchars($e['notes']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Amount + delete -->
            <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:18px;font-weight:800;color:var(--red);">
                    $<?= number_format($e['amount'],2) ?>
                </div>
                <?php if ($isOwner): ?>
                <form method="POST" style="margin-top:.4rem;">
                    <input type="hidden" name="action"     value="delete_expense">
                    <input type="hidden" name="expense_id" value="<?= $e['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            style="padding:.15rem .4rem;font-size:11px;"
                            onclick="return confirm('Delete this expense?')">🗑</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Footer total -->
    <div style="padding:.75rem 1rem;display:flex;justify-content:flex-end;align-items:center;gap:1rem;
                background:var(--surface-2);font-weight:700;">
        <span>Total <?= date('F Y', strtotime($dateFrom)) ?></span>
        <span style="font-size:18px;color:var(--red);">$<?= number_format($totalAmount,2) ?></span>
    </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /repair-col-main -->

<!-- Side: Category Breakdown -->
<div class="repair-col-side">
    <div class="card">
        <div class="card-header"><h2 class="card-title">By Category</h2></div>
        <div class="card-body">
            <?php if (empty($catTotals)): ?>
            <p class="text-muted">No data yet.</p>
            <?php else: ?>
            <?php foreach ($catTotals as $catName => $catTotal): ?>
            <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--border);font-size:13px;">
                <span><?= htmlspecialchars($catName) ?></span>
                <strong>$<?= number_format($catTotal,2) ?></strong>
            </div>
            <?php endforeach; ?>
            <div style="display:flex;justify-content:space-between;padding:.6rem 0;font-weight:700;">
                <span>Total</span>
                <span>$<?= number_format($totalAmount,2) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cash account balances hint -->
    <?php if (!empty($cashAccounts)): ?>
    <div class="card" style="margin-top:1rem;">
        <div class="card-header"><h2 class="card-title">💵 Payment Accounts</h2></div>
        <div class="card-body" style="padding:0;">
        <table class="data-table" style="font-size:13px;">
            <thead><tr><th>Account</th><th>Type</th></tr></thead>
            <tbody>
            <?php foreach ($cashAccounts as $ca): ?>
            <tr>
                <td><?= htmlspecialchars($ca['name']) ?></td>
                <td class="text-muted small"><?= ucfirst($ca['type']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

</div><!-- /repair-grid -->
<?php endif; ?>

<!-- ── JS: Dynamic line items ─────────────────────────────── -->
<script>
const INV_ITEMS  = <?= $invJson ?>;
const REPAIRS    = <?= $repairsJson ?>;
const LOCATIONS  = <?= json_encode(array_map(fn($l)=>['id'=>$l['id'],'name'=>$l['name']], $locations)) ?>;
let lineCount = 0;

function addLine(desc='', invId=0, repairId=0, qty=1, cost='', sell='') {
    const idx = lineCount++;
    const inv = document.getElementById('line-items');
    const div = document.createElement('div');
    div.id = 'line-' + idx;
    div.style.cssText = 'margin-bottom:.75rem;background:var(--surface-2);border-radius:8px;padding:.5rem;';

    let invOpts = '<option value="">- No inventory link -</option>';
    INV_ITEMS.forEach(i => {
        const sel = i.id == invId ? 'selected' : '';
        invOpts += `<option value="${i.id}" data-cost="${i.cost}" ${sel}>${i.label}</option>`;
    });

    let repOpts = '<option value="">- No repair link -</option>';
    REPAIRS.forEach(r => {
        const sel = r.id == repairId ? 'selected' : '';
        repOpts += `<option value="${r.id}" ${sel}>${r.label}</option>`;
    });

    div.innerHTML = `
    <div style="display:grid;grid-template-columns:2fr 90px 100px 100px 24px;gap:.4rem;align-items:start;margin-bottom:.35rem;">
        <input type="text" name="item_desc[]" class="form-control" placeholder="What was bought?"
               value="${desc}" required style="font-size:13px;">
        <input type="number" name="item_qty[]" class="form-control qty-${idx}"
               value="${qty}" min="1" style="font-size:13px;" oninput="calcTotal();buildAlloc(${idx})">
        <div style="position:relative;">
            <span style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:13px;">$</span>
            <input type="number" name="item_cost[]" class="form-control cost-${idx}"
                   value="${cost}" step="0.01" min="0.01" placeholder="Cost"
                   style="padding-left:20px;font-size:13px;" oninput="calcTotal()">
        </div>
        <div style="position:relative;">
            <span style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:13px;">$</span>
            <input type="number" name="item_sell[]" class="form-control sell-${idx}"
                   value="${sell}" step="0.01" min="0" placeholder="Sell"
                   style="padding-left:20px;font-size:13px;background:rgba(34,197,94,.07);"
                   title="Sell price charged to customer (only used if linked to a repair)">
        </div>
        <button type="button" onclick="removeLine(${idx})"
                style="background:none;border:none;color:var(--red);font-size:18px;cursor:pointer;padding:0;line-height:1;">x</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;">
        <div>
            <div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;margin-bottom:2px;">Inventory Link</div>
            <select name="item_inv_id[]" class="form-control inv-select-${idx}" style="font-size:12px;" onchange="onInvChange(${idx})">
                ${invOpts}
            </select>
        </div>
        <div>
            <div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;margin-bottom:2px;">Repair Ticket Link</div>
            <select name="item_repair_id[]" class="form-control" style="font-size:12px;">
                ${repOpts}
            </select>
        </div>
    </div>
    <div id="alloc-${idx}" style="margin-top:.35rem;display:none;"></div>`;

    inv.appendChild(div);
    if (invId) buildAlloc(idx);
    calcTotal();
}

function removeLine(idx) {
    const el = document.getElementById('line-' + idx);
    if (el) { el.remove(); calcTotal(); }
}

function onInvChange(idx) {
    const sel = document.querySelector('.inv-select-' + idx);
    const opt = sel.options[sel.selectedIndex];
    const cost = opt.dataset.cost || '';
    if (cost) {
        const costEl = document.querySelector('.cost-' + idx);
        if (costEl && !costEl.value) costEl.value = parseFloat(cost).toFixed(2);
    }
    buildAlloc(idx);
    calcTotal();
}

function buildAlloc(idx) {
    const sel = document.querySelector('.inv-select-' + idx);
    const hasInv = sel && sel.value;
    const allocDiv = document.getElementById('alloc-' + idx);
    if (!hasInv || LOCATIONS.length <= 1) {
        allocDiv.style.display = 'none';
        allocDiv.innerHTML = '';
        return;
    }
    const qty = parseInt(document.querySelector('.qty-' + idx)?.value || 1);
    let html = `<div style="font-size:11px;color:var(--text-3);margin-bottom:.3rem;font-weight:700;">
                  📦 Split stock between locations (total: ${qty})</div>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap;">`;
    LOCATIONS.forEach((loc, i) => {
        const defaultQty = i === 0 ? qty : 0;
        html += `<label style="font-size:12px;display:flex;align-items:center;gap:.3rem;">
            <input type="hidden" name="alloc_loc_${idx}[]" value="${loc.id}">
            ${loc.name}:
            <input type="number" name="alloc_qty_${idx}[]" min="0" value="${defaultQty}"
                   style="width:55px;" class="form-control" oninput="checkAllocTotal(${idx},${qty})">
        </label>`;
    });
    html += '</div><div id="alloc-warn-' + idx + '" style="font-size:11px;color:#ef4444;margin-top:.2rem;"></div>';
    allocDiv.innerHTML = html;
    allocDiv.style.display = 'block';
}

function checkAllocTotal(idx, totalQty) {
    const inputs = document.querySelectorAll(`input[name="alloc_qty_${idx}[]"]`);
    let sum = 0;
    inputs.forEach(i => sum += parseInt(i.value||0));
    const warn = document.getElementById('alloc-warn-' + idx);
    if (warn) warn.textContent = sum !== totalQty ? `⚠ Allocated ${sum} of ${totalQty}` : '';
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('[name="item_qty[]"]').forEach((qEl, i) => {
        const cEl = document.querySelectorAll('[name="item_cost[]"]')[i];
        if (qEl && cEl) total += (parseInt(qEl.value)||0) * (parseFloat(cEl.value)||0);
    });
    document.getElementById('total-display').textContent = '$' + total.toFixed(2);
}

function updatePayLabel(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('payment_method_label').value = opt.dataset.label || opt.text || '';
    // Only pass account ID if it's a real cash account (numeric)
    if (isNaN(parseInt(sel.value))) sel.name = '_payment_account_id_skip';
    else sel.name = 'payment_account_id';
}

function validateForm() {
    const lines = document.querySelectorAll('[name="item_desc[]"]');
    if (lines.length === 0) {
        alert('Please add at least one item.');
        return false;
    }
    return true;
}

// Start with one line
addLine();
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
