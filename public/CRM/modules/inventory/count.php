<?php
// ============================================================
// Inventory Count Schedule — Scan-to-Count
// Weekly (Parts & Accessories · Thu) · Monthly (Full · last day)
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
$isMgr     = $isOwner || $isManager;

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── AJAX: barcode scan — save per-scan instantly ──────────────
if (isset($_POST['ajax_scan'])) {
    header('Content-Type: application/json');
    $countId = intval($_POST['count_id'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');

    if (!$countId || !$barcode) {
        echo json_encode(['ok'=>false,'msg'=>'Missing data']); exit;
    }

    $count = DB::queryOne("SELECT * FROM inventory_counts WHERE id=?", [$countId]);
    if (!$count || $count['status'] !== 'in_progress') {
        echo json_encode(['ok'=>false,'msg'=>'Count is not active']); exit;
    }
    if (!$isOwner && $count['location_id'] != $user['location_id']) {
        echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit;
    }

    // Find item by barcode OR sku
    $item = DB::queryOne(
        "SELECT i.*, COALESCE(s.quantity,0) AS sys_qty
         FROM inventory_items i
         LEFT JOIN inventory_stock s ON s.item_id=i.id AND s.location_id=?
         WHERE (i.barcode=? OR i.sku=?) AND i.is_active=1 LIMIT 1",
        [$count['location_id'], $barcode, $barcode]
    );

    if (!$item) {
        echo json_encode(['ok'=>false,'msg'=>'Item not found for: ' . htmlspecialchars($barcode)]); exit;
    }

    // Find or create the count_item row
    $ci = DB::queryOne(
        "SELECT * FROM inventory_count_items WHERE count_id=? AND item_id=?",
        [$countId, $item['id']]
    );

    if ($ci) {
        $newQty  = (int)($ci['counted_qty'] ?? 0) + 1;
        $sysQty  = (int)$ci['system_qty'];
        $variance = $newQty - $sysQty;
        DB::execute(
            "UPDATE inventory_count_items SET counted_qty=?, variance=?, counted_by=?, counted_at=NOW() WHERE id=?",
            [$newQty, $variance, $user['id'], $ci['id']]
        );
        $ciId = (int)$ci['id'];
    } else {
        $sysQty   = (int)$item['sys_qty'];
        $newQty   = 1;
        $variance = 1 - $sysQty;
        $ciId = (int)DB::insert(
            "INSERT INTO inventory_count_items (count_id, item_id, item_name, category, system_qty, counted_qty, variance, counted_by, counted_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())",
            [$countId, $item['id'], $item['name'], $item['category'], $sysQty, $newQty, $variance, $user['id']]
        );
    }

    echo json_encode([
        'ok'        => true,
        'ci_id'     => $ciId,
        'item_name' => $item['name'],
        'category'  => $item['category'],
        'new_qty'   => $newQty,
        'sys_qty'   => (int)$item['sys_qty'],
        'variance'  => $variance,
    ]);
    exit;
}

$msg = ''; $msgType = 'success';

// ── Auto-generate scheduled counts if missing ─────────────────
function scheduleCountsForLocation(int $locId): void {
    $today = new DateTime();

    // Weekly — every Thursday
    $thursday = clone $today;
    $dow = (int)$thursday->format('N');
    if ($dow <= 4) $thursday->modify('+' . (4 - $dow) . ' days');
    else           $thursday->modify('+' . (11 - $dow) . ' days');
    $thuDate = $thursday->format('Y-m-d');

    $exists = DB::queryOne(
        "SELECT id FROM inventory_counts WHERE location_id=? AND count_type='weekly' AND due_date=?",
        [$locId, $thuDate]
    );
    if (!$exists) {
        DB::execute(
            "INSERT INTO inventory_counts (location_id, count_type, due_date, status) VALUES (?, 'weekly', ?, 'pending')",
            [$locId, $thuDate]
        );
    }

    // Monthly — last day of current month
    $lastDay = (new DateTime('last day of this month'))->format('Y-m-d');
    $exists2 = DB::queryOne(
        "SELECT id FROM inventory_counts WHERE location_id=? AND count_type='monthly' AND due_date=?",
        [$locId, $lastDay]
    );
    if (!$exists2) {
        DB::execute(
            "INSERT INTO inventory_counts (location_id, count_type, due_date, status) VALUES (?, 'monthly', ?, 'pending')",
            [$locId, $lastDay]
        );
    }
}

foreach ($locations as $loc) {
    scheduleCountsForLocation((int)$loc['id']);
}

// Auto-mark overdue
DB::execute(
    "UPDATE inventory_counts SET status='overdue' WHERE status='pending' AND due_date < CURDATE()"
);

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $countId = intval($_POST['count_id'] ?? 0);

    // Delete (owner only)
    if ($action === 'delete_count' && $countId && $isOwner) {
        DB::execute('DELETE FROM inventory_count_items WHERE count_id=?', [$countId]);
        DB::execute('DELETE FROM inventory_counts      WHERE id=?',       [$countId]);
        header('Location: ' . APP_URL . '/modules/inventory/count.php?deleted=1'); exit;
    }

    // Start — snapshot inventory_stock into count_items
    if ($action === 'start' && $countId) {
        DB::execute(
            "UPDATE inventory_counts SET status='in_progress', started_by=?, started_at=NOW()
             WHERE id=? AND status IN ('pending','overdue')",
            [$user['id'], $countId]
        );
        $count = DB::queryOne("SELECT * FROM inventory_counts WHERE id=?", [$countId]);
        if ($count) {
            // For weekly: parts & accessories only. For monthly: everything.
            $typeFilter = '';
            if ($count['count_type'] === 'weekly') {
                $typeFilter = "AND i.category IN (
                    'Screen Protector','Case','Cable','Charging Block','Power Bank',
                    'Stand','Headphone','Headset','Bluetooth','Storage Device','SIM Cards',
                    'Screen / Display','Battery','Charging Port','Camera','Speaker',
                    'Back Glass / Housing','Other Parts'
                )";
            }
            $items = DB::query(
                "SELECT i.id, i.name, i.category, COALESCE(s.quantity,0) AS sys_qty
                 FROM inventory_items i
                 LEFT JOIN inventory_stock s ON s.item_id=i.id AND s.location_id=?
                 WHERE i.is_active=1 {$typeFilter}
                 ORDER BY i.category, i.name",
                [$count['location_id']]
            );
            foreach ($items as $item) {
                $already = DB::queryOne(
                    "SELECT id FROM inventory_count_items WHERE count_id=? AND item_id=?",
                    [$countId, $item['id']]
                );
                if (!$already) {
                    DB::execute(
                        "INSERT INTO inventory_count_items (count_id, item_id, item_name, category, system_qty) VALUES (?,?,?,?,?)",
                        [$countId, $item['id'], $item['name'], $item['category'], (int)$item['sys_qty']]
                    );
                }
            }
        }
        header("Location: count.php?view=" . $countId); exit;
    }

    // Save manual qty adjustments
    elseif ($action === 'save_counts' && $countId) {
        $counts  = $_POST['counted_qty']     ?? [];
        $reasons = $_POST['variance_reason'] ?? [];
        $notes   = $_POST['variance_notes']  ?? [];
        foreach ($counts as $ciId => $qty) {
            if ($qty === '') continue;
            $sysQty   = (int)(DB::queryOne("SELECT system_qty FROM inventory_count_items WHERE id=?", [(int)$ciId])['system_qty'] ?? 0);
            $variance = (int)$qty - $sysQty;
            DB::execute(
                "UPDATE inventory_count_items SET counted_qty=?, variance=?, variance_reason=?, variance_notes=?, counted_by=?, counted_at=NOW()
                 WHERE count_id=? AND id=?",
                [(int)$qty, $variance, $reasons[$ciId] ?? null, $notes[$ciId] ?? null, $user['id'], $countId, (int)$ciId]
            );
        }
        header("Location: count.php?view=" . $countId); exit;
    }

    // Submit for approval
    elseif ($action === 'submit' && $countId) {
        DB::execute(
            "UPDATE inventory_counts SET status='submitted', submitted_by=?, submitted_at=NOW()
             WHERE id=? AND status='in_progress'",
            [$user['id'], $countId]
        );
        header("Location: count.php"); exit;
    }

    // Approve & apply variances (manager/owner only)
    elseif ($action === 'approve' && $countId && $isMgr) {
        DB::execute(
            "UPDATE inventory_counts SET status='approved', approved_by=?, approved_at=NOW()
             WHERE id=? AND status='submitted'",
            [$user['id'], $countId]
        );
        $items = DB::query(
            "SELECT ici.*, ic.location_id FROM inventory_count_items ici
             JOIN inventory_counts ic ON ic.id=ici.count_id
             WHERE ici.count_id=? AND ici.counted_qty IS NOT NULL",
            [$countId]
        );
        foreach ($items as $item) {
            $diff = (int)$item['counted_qty'] - (int)$item['system_qty'];
            if ($diff === 0) continue;
            // Update stock to match count
            DB::execute(
                "UPDATE inventory_stock SET quantity=GREATEST(0, quantity+?)
                 WHERE item_id=? AND location_id=?",
                [$diff, $item['item_id'], $item['location_id']]
            );
            // Log movement
            DB::execute(
                "INSERT INTO inventory_movements (item_id, location_id, movement_type, quantity, reference_type, notes, created_by)
                 VALUES (?,?,?,?,?,?,?)",
                [$item['item_id'], $item['location_id'], 'adjustment', abs($diff),
                 'count', 'Inventory count adjustment (' . ($diff > 0 ? '+' : '') . $diff . ')', $user['id']]
            );
        }
        header("Location: count.php"); exit;
    }
}

// ── View individual count ─────────────────────────────────────
$viewId = intval($_GET['view'] ?? 0);
if ($viewId) {
    $count = DB::queryOne(
        "SELECT ic.*, l.name AS loc_name, l.code AS loc_code,
                sb.first_name AS started_name, sub.first_name AS submitted_name, ab.first_name AS approved_name
         FROM inventory_counts ic
         JOIN locations l ON l.id=ic.location_id
         LEFT JOIN users sb  ON sb.id=ic.started_by
         LEFT JOIN users sub ON sub.id=ic.submitted_by
         LEFT JOIN users ab  ON ab.id=ic.approved_by
         WHERE ic.id=?",
        [$viewId]
    );
    if (!$count) { header('Location: count.php'); exit; }
    if (!$isOwner && $count['location_id'] != $user['location_id']) {
        header('Location: count.php'); exit;
    }
    $countItems = DB::query(
        "SELECT ici.*, ii.barcode
         FROM inventory_count_items ici
         LEFT JOIN inventory_items ii ON ii.id=ici.item_id
         WHERE ici.count_id=? ORDER BY ici.counted_at DESC, ici.category, ici.item_name",
        [$viewId]
    );
}

// ── Schedule list ─────────────────────────────────────────────
$filterLoc    = $isOwner ? intval($_GET['location_id'] ?? 0) : (int)$user['location_id'];
$filterStatus = $_GET['status'] ?? 'active';
$filterType   = $_GET['type']   ?? '';

$where  = ['1=1'];
$params = [];
if ($filterLoc) { $where[] = 'ic.location_id=?'; $params[] = $filterLoc; }
elseif (!$isOwner) { $where[] = 'ic.location_id=?'; $params[] = $user['location_id']; }
if ($filterStatus === 'active')  $where[] = "ic.status NOT IN ('approved')";
elseif ($filterStatus !== 'all') { $where[] = 'ic.status=?'; $params[] = $filterStatus; }
if ($filterType) { $where[] = 'ic.count_type=?'; $params[] = $filterType; }

$counts = DB::query(
    "SELECT ic.*, l.name AS loc_name, l.code AS loc_code, u.first_name AS started_name
     FROM inventory_counts ic
     JOIN locations l ON l.id=ic.location_id
     LEFT JOIN users u ON u.id=ic.started_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(ic.status,'overdue','pending','in_progress','submitted','approved'), ic.due_date ASC",
    $params
);

$TYPE_LABELS = [
    'weekly'            => ['label'=>'Weekly Count',   'icon'=>'🔧', 'freq'=>'Every Thursday'],
    'monthly'           => ['label'=>'Monthly Count',  'icon'=>'📦', 'freq'=>'Last day of month'],
    // Legacy types (keep labels so old records still display)
    'full'              => ['label'=>'Full Count',           'icon'=>'📦', 'freq'=>'Monthly'],
    'parts_accessories' => ['label'=>'Parts & Accessories',  'icon'=>'🔧', 'freq'=>'Weekly (Thu)'],
    'high_value'        => ['label'=>'High-Value Items',     'icon'=>'💎', 'freq'=>'Weekly (Thu)'],
];
$STATUS_COLORS = [
    'pending'     => 'var(--text-3)',
    'overdue'     => 'var(--red)',
    'in_progress' => 'var(--amber)',
    'submitted'   => 'var(--blue)',
    'approved'    => 'var(--green)',
];
$STATUS_ICONS = [
    'pending'=>'⏳','overdue'=>'🚨','in_progress'=>'🔄','submitted'=>'📋','approved'=>'✅'
];

$pageTitle = $viewId ? 'Inventory Count' : 'Count Schedule';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success">✓ Count deleted.</div>
<?php endif; ?>

<?php if ($viewId && isset($count)): ?>
<!-- ══ COUNT DETAIL VIEW ════════════════════════════════════ -->
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $TYPE_LABELS[$count['count_type']]['icon'] ?? '📦' ?> <?= $TYPE_LABELS[$count['count_type']]['label'] ?? $count['count_type'] ?></h1>
        <p class="page-sub"><?= htmlspecialchars($count['loc_name']) ?> · Due <?= date('M j, Y', strtotime($count['due_date'])) ?></p>
    </div>
    <a href="count.php" class="btn btn-secondary">← Schedule</a>
</div>

<!-- Status bar -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body" style="display:flex;gap:2rem;flex-wrap:wrap;align-items:center;">
        <div>
            <div class="metric-label">Status</div>
            <div style="color:<?= $STATUS_COLORS[$count['status']] ?>;font-weight:700;font-size:16px;">
                <?= $STATUS_ICONS[$count['status']] ?> <?= ucfirst(str_replace('_',' ',$count['status'])) ?>
            </div>
        </div>
        <?php if ($count['started_at']): ?>
        <div><div class="metric-label">Started</div>
            <div class="small"><?= htmlspecialchars($count['started_name'] ?? '—') ?><br><?= date('M j g:i A', strtotime($count['started_at'])) ?></div>
        </div><?php endif; ?>
        <?php if ($count['submitted_at']): ?>
        <div><div class="metric-label">Submitted</div>
            <div class="small"><?= htmlspecialchars($count['submitted_name'] ?? '—') ?><br><?= date('M j g:i A', strtotime($count['submitted_at'])) ?></div>
        </div><?php endif; ?>
        <?php if ($count['approved_at']): ?>
        <div><div class="metric-label">Approved</div>
            <div class="small"><?= htmlspecialchars($count['approved_name'] ?? '—') ?><br><?= date('M j g:i A', strtotime($count['approved_at'])) ?></div>
        </div><?php endif; ?>

        <div style="margin-left:auto;display:flex;gap:.5rem;flex-wrap:wrap;">
            <?php if (in_array($count['status'], ['pending','overdue'])): ?>
            <form method="POST">
                <input type="hidden" name="count_id" value="<?= $count['id'] ?>">
                <button type="submit" name="action" value="start" class="btn btn-primary">▶ Start Count</button>
            </form>
            <?php elseif ($count['status'] === 'in_progress'): ?>
            <button type="button" class="btn btn-primary" onclick="submitCount()">📋 Submit for Approval</button>
            <?php elseif ($count['status'] === 'submitted' && $isMgr): ?>
            <form method="POST">
                <input type="hidden" name="count_id" value="<?= $count['id'] ?>">
                <button type="submit" name="action" value="approve" class="btn btn-primary"
                    onclick="return confirm('Approve? Variances will be applied to inventory stock levels.')">
                    ✅ Approve & Adjust Inventory
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$totalItems   = count($countItems);
$scanned      = array_filter($countItems, fn($i) => $i['counted_qty'] !== null);
$notScanned   = array_filter($countItems, fn($i) => $i['counted_qty'] === null);
$variances    = array_filter($scanned, fn($i) => (int)$i['counted_qty'] !== (int)$i['system_qty']);
$scannedCount = count($scanned);
?>

<!-- Progress -->
<div class="card" style="margin-bottom:1rem;">
    <div class="card-body" style="display:flex;gap:2rem;align-items:center;flex-wrap:wrap;">
        <div style="flex:1;min-width:180px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:.3rem;">
                <span class="small">Progress</span>
                <span class="small text-muted"><?= $scannedCount ?> / <?= $totalItems ?> items scanned</span>
            </div>
            <div style="height:8px;background:var(--border);border-radius:4px;">
                <div style="height:100%;background:var(--green);border-radius:4px;
                            width:<?= $totalItems ? round($scannedCount/$totalItems*100) : 0 ?>%;transition:width .3s;"></div>
            </div>
        </div>
        <div style="text-align:center;">
            <div class="metric-label">Variances</div>
            <div style="font-size:20px;font-weight:700;color:<?= count($variances)>0?'var(--red)':'var(--green)' ?>;">
                <?= count($variances) ?>
            </div>
        </div>
        <div style="text-align:center;">
            <div class="metric-label">Not Scanned</div>
            <div style="font-size:20px;font-weight:700;color:<?= count($notScanned)>0?'var(--amber)':'var(--green)' ?>;">
                <?= count($notScanned) ?>
            </div>
        </div>
    </div>
</div>

<?php if ($count['status'] === 'in_progress'): ?>
<!-- ── SCAN BAR (AJAX) ────────────────────────────────────── -->
<div id="scan-bar" style="position:sticky;top:0;z-index:200;background:var(--surface);
     border:2px solid var(--blue);border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1rem;
     display:flex;gap:.75rem;align-items:center;box-shadow:0 3px 12px rgba(0,0,0,.15);">
    <span style="font-size:22px;">📷</span>
    <div style="flex:1;">
        <label style="font-size:11px;font-weight:700;color:var(--blue);letter-spacing:.05em;display:block;margin-bottom:2px;">SCAN BARCODE</label>
        <input type="text" id="barcode-input" autocomplete="off" autocorrect="off" spellcheck="false"
               placeholder="Scan or type barcode / SKU then Enter…"
               style="width:100%;border:none;background:transparent;font-size:16px;font-family:monospace;outline:none;color:var(--text);">
    </div>
    <div id="scan-feedback" style="font-size:13px;font-weight:600;min-width:180px;text-align:right;transition:color .2s;"></div>
</div>

<script>
(function(){
    const countId  = <?= (int)$count['id'] ?>;
    const ajaxUrl  = '<?= APP_URL ?>/modules/inventory/count.php';
    const input    = document.getElementById('barcode-input');
    const feedback = document.getElementById('scan-feedback');
    let feedTimer;

    function showFeedback(msg, color) {
        feedback.textContent = msg;
        feedback.style.color = color;
        clearTimeout(feedTimer);
        feedTimer = setTimeout(() => feedback.textContent = '', 2500);
    }

    function flash(row, color) {
        row.style.transition = 'background .1s';
        row.style.background = color;
        setTimeout(() => { row.style.background = ''; row.style.transition = ''; }, 800);
    }

    input.addEventListener('keydown', async function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const code = input.value.trim();
        input.value = '';
        if (!code) return;

        // Optimistic UI — disable input briefly
        input.disabled = true;

        try {
            const fd = new FormData();
            fd.append('ajax_scan', '1');
            fd.append('count_id', countId);
            fd.append('barcode', code);
            const res  = await fetch(ajaxUrl, { method:'POST', body:fd });
            const data = await res.json();

            if (!data.ok) {
                showFeedback(data.msg || '❌ Not found', 'var(--red)');
            } else {
                const varTxt = data.variance === 0 ? '✓' : (data.variance > 0 ? '+'+data.variance : data.variance);
                const varCol = data.variance === 0 ? 'var(--green)' : 'var(--red)';
                showFeedback('✓ ' + data.item_name.substring(0,28) + ' → ' + data.new_qty, 'var(--green)');

                // Update existing row in scanned table, or prepend a new one
                let row = document.getElementById('ci-row-' + data.ci_id);
                if (row) {
                    row.querySelector('.scanned-qty').textContent  = data.new_qty;
                    row.querySelector('.variance-cell').textContent = varTxt;
                    row.querySelector('.variance-cell').style.color = varCol;
                    row.querySelector('.variance-cell').style.fontWeight = data.variance !== 0 ? '700' : '400';
                    flash(row, 'rgba(59,130,246,.12)');
                } else {
                    // Prepend new row to scanned table
                    const tbody = document.getElementById('scanned-tbody');
                    if (tbody) {
                        const tr = document.createElement('tr');
                        tr.id = 'ci-row-' + data.ci_id;
                        tr.innerHTML = `
                            <td style="font-weight:500;">${escHtml(data.item_name)}</td>
                            <td class="small text-muted">${escHtml(data.category)}</td>
                            <td style="text-align:center;" class="text-muted">${data.sys_qty}</td>
                            <td style="text-align:center;font-weight:700;" class="scanned-qty">${data.new_qty}</td>
                            <td style="text-align:center;color:${varCol};font-weight:${data.variance!==0?'700':'400'};" class="variance-cell">${varTxt}</td>
                        `;
                        tbody.prepend(tr);
                        flash(tr, 'rgba(59,130,246,.12)');

                        // Remove from not-scanned list if present
                        const nsRow = document.getElementById('ns-item-' + data.ci_id);
                        if (nsRow) nsRow.remove();

                        // Update counters
                        updateCounters();
                    }
                }
            }
        } catch(err) {
            showFeedback('⚠ Network error', 'var(--red)');
        }

        input.disabled = false;
        input.focus();
    });

    function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function updateCounters() {
        const scannedCount = document.querySelectorAll('#scanned-tbody tr').length;
        const nsCount      = document.querySelectorAll('#ns-tbody tr').length;
        const total        = scannedCount + nsCount;
        const varCount     = document.querySelectorAll('#scanned-tbody .variance-cell[style*="red"]').length;

        const el = document.getElementById('progress-text');
        if (el) el.textContent = scannedCount + ' / ' + total + ' items scanned';
        const bar = document.getElementById('progress-bar');
        if (bar) bar.style.width = (total ? Math.round(scannedCount/total*100) : 0) + '%';
        const nsEl = document.getElementById('not-scanned-count');
        if (nsEl) nsEl.textContent = nsCount;
    }

    // Auto-focus + keep focus
    input.focus();
    document.addEventListener('click', function(e) {
        if (!e.target.closest('input:not(#barcode-input), select, textarea, button, a')) {
            input.focus();
        }
    });
})();

function submitCount() {
    if (!confirm('Submit this count for manager approval?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input name="action" value="submit"><input name="count_id" value="<?= (int)$count['id'] ?>">';
    document.body.appendChild(form);
    form.submit();
}
</script>
<?php endif; ?>

<!-- ── SCANNED ITEMS TABLE ───────────────────────────────── -->
<?php if (!empty($scanned)): ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header">
        <h3 class="card-title" style="color:var(--green);">✅ Scanned Items (<?= count($scanned) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
    <form method="POST" id="count-form">
        <input type="hidden" name="action"   value="save_counts">
        <input type="hidden" name="count_id" value="<?= $count['id'] ?>">
    <table class="data-table" style="min-width:600px;">
        <thead>
            <tr>
                <th>Item</th>
                <th>Category</th>
                <th style="text-align:center;">System Qty</th>
                <th style="text-align:center;">Scanned Qty</th>
                <th style="text-align:center;">Variance</th>
                <?php if ($count['status'] !== 'pending'): ?>
                <th>Reason</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody id="scanned-tbody">
        <?php foreach ($scanned as $ci):
            $variance = (int)$ci['counted_qty'] - (int)$ci['system_qty'];
            $varColor = $variance === 0 ? 'var(--green)' : 'var(--red)';
            $rowBg    = $variance !== 0 ? 'background:rgba(239,68,68,.05);' : '';
        ?>
        <tr id="ci-row-<?= $ci['id'] ?>" style="<?= $rowBg ?>">
            <td style="font-weight:500;"><?= htmlspecialchars($ci['item_name']) ?></td>
            <td class="small text-muted"><?= htmlspecialchars($ci['category']) ?></td>
            <td style="text-align:center;color:var(--text-3);"><?= $ci['system_qty'] ?></td>
            <td style="text-align:center;font-weight:700;" class="scanned-qty">
                <?php if ($count['status'] === 'in_progress'): ?>
                <input type="number" name="counted_qty[<?= $ci['id'] ?>]"
                       value="<?= (int)$ci['counted_qty'] ?>"
                       min="0" class="form-control" style="width:70px;text-align:center;margin:0 auto;">
                <?php else: ?>
                <?= (int)$ci['counted_qty'] ?>
                <?php endif; ?>
            </td>
            <td style="text-align:center;color:<?= $varColor ?>;font-weight:<?= $variance!==0?'700':'400' ?>;" class="variance-cell">
                <?= $variance === 0 ? '✓ 0' : ($variance > 0 ? '+' : '') . $variance ?>
            </td>
            <?php if ($count['status'] !== 'pending'): ?>
            <td>
                <?php if ($count['status'] === 'in_progress' && $variance !== 0): ?>
                <select name="variance_reason[<?= $ci['id'] ?>]" class="form-control" style="font-size:12px;">
                    <option value="">—</option>
                    <option value="sold_not_logged"  <?= ($ci['variance_reason']==='sold_not_logged') ?'selected':'' ?>>Sold / not logged</option>
                    <option value="damaged"          <?= ($ci['variance_reason']==='damaged')         ?'selected':'' ?>>Damaged</option>
                    <option value="stolen"           <?= ($ci['variance_reason']==='stolen')          ?'selected':'' ?>>Stolen</option>
                    <option value="transfer_error"   <?= ($ci['variance_reason']==='transfer_error')  ?'selected':'' ?>>Transfer error</option>
                    <option value="found"            <?= ($ci['variance_reason']==='found')           ?'selected':'' ?>>Found</option>
                    <option value="supplier_error"   <?= ($ci['variance_reason']==='supplier_error')  ?'selected':'' ?>>Supplier error</option>
                    <option value="other"            <?= ($ci['variance_reason']==='other')           ?'selected':'' ?>>Other</option>
                </select>
                <?php else: ?>
                <span class="small text-muted"><?= htmlspecialchars(str_replace('_',' ',ucwords($ci['variance_reason']??'','_'))) ?></span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($count['status'] === 'in_progress'): ?>
    <div style="padding:.75rem 1rem;">
        <button type="submit" class="btn btn-secondary">💾 Save Manual Adjustments</button>
    </div>
    <?php endif; ?>
    </form>
    </div>
</div>
<?php endif; ?>

<!-- ── NOT YET SCANNED ───────────────────────────────────── -->
<?php if (!empty($notScanned)): ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header">
        <h3 class="card-title" style="color:var(--amber);">
            ⏳ Not Yet Scanned (<span id="not-scanned-count"><?= count($notScanned) ?></span>)
        </h3>
        <?php if ($count['status'] === 'in_progress'): ?>
        <span class="text-muted small">Scan their barcode to mark them counted</span>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="data-table" style="min-width:400px;">
        <thead><tr><th>Item</th><th>Category</th><th style="text-align:center;">System Qty</th></tr></thead>
        <tbody id="ns-tbody">
        <?php foreach ($notScanned as $ci): ?>
        <tr id="ns-item-<?= $ci['id'] ?>">
            <td style="font-weight:500;"><?= htmlspecialchars($ci['item_name']) ?></td>
            <td class="small text-muted"><?= htmlspecialchars($ci['category']) ?></td>
            <td style="text-align:center;color:var(--text-3);"><?= $ci['system_qty'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($countItems)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state">
        <div class="empty-icon">📦</div>
        <p>Click "Start Count" to load items and begin scanning.</p>
    </div>
</div></div>
<?php endif; ?>

<?php else: ?>
<!-- ══ SCHEDULE LIST ════════════════════════════════════════ -->
<div class="page-header">
    <div>
        <h1 class="page-title">📋 Count Schedule</h1>
        <p class="page-sub">Weekly (Thu) · Monthly (last day)</p>
    </div>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <?php if ($isMgr): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLoc==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="status" class="form-control filter-select" onchange="this.form.submit()">
        <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
        <option value="all"    <?= $filterStatus==='all'   ?'selected':'' ?>>All</option>
        <?php foreach (['overdue','pending','in_progress','submitted','approved'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="type" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="weekly"  <?= $filterType==='weekly' ?'selected':'' ?>>🔧 Weekly</option>
        <option value="monthly" <?= $filterType==='monthly'?'selected':'' ?>>📦 Monthly</option>
    </select>
    <a href="?" class="btn btn-ghost">Reset</a>
</form>

<!-- Legend -->
<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;font-size:13px;">
    <?php foreach (['overdue'=>'🚨 Overdue','pending'=>'⏳ Pending','in_progress'=>'🔄 In Progress','submitted'=>'📋 Awaiting Approval','approved'=>'✅ Approved'] as $s=>$label): ?>
    <span style="color:<?= $STATUS_COLORS[$s] ?>;font-weight:600;"><?= $label ?></span>
    <?php endforeach; ?>
</div>

<?php if (empty($counts)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state"><div class="empty-icon">📋</div><p>No counts scheduled.</p></div>
</div></div>
<?php else: ?>
<div class="card">
    <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="data-table" style="min-width:600px;">
        <thead>
            <tr>
                <th>Type</th>
                <?php if ($isMgr): ?><th>Location</th><?php endif; ?>
                <th>Due Date</th>
                <th>Status</th>
                <th>Started By</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($counts as $c):
            $typeInfo = $TYPE_LABELS[$c['count_type']] ?? ['label'=>$c['count_type'],'icon'=>'📦','freq'=>''];
            $dueTs    = strtotime($c['due_date']);
            $overdue  = $c['status'] === 'overdue';
            $daysLeft = (int)ceil(($dueTs - time()) / 86400);
        ?>
        <tr style="<?= $overdue ? 'background:rgba(239,68,68,.04);' : '' ?>">
            <td>
                <span style="font-size:16px;"><?= $typeInfo['icon'] ?></span>
                <strong style="margin-left:.3rem;"><?= $typeInfo['label'] ?></strong>
                <div class="small text-muted"><?= $typeInfo['freq'] ?></div>
            </td>
            <?php if ($isMgr): ?>
            <td><span class="badge badge-loc"><?= htmlspecialchars($c['loc_code']) ?></span></td>
            <?php endif; ?>
            <td>
                <span style="font-weight:600;<?= $overdue?'color:var(--red);':'' ?>"><?= date('M j, Y', $dueTs) ?></span>
                <?php if ($overdue): ?>
                <div class="small" style="color:var(--red);">🚨 Overdue</div>
                <?php elseif ($daysLeft <= 3 && $daysLeft >= 0): ?>
                <div class="small" style="color:var(--amber);">Due in <?= $daysLeft ?> day<?= $daysLeft!==1?'s':'' ?></div>
                <?php endif; ?>
            </td>
            <td>
                <span style="color:<?= $STATUS_COLORS[$c['status']] ?>;font-weight:600;">
                    <?= $STATUS_ICONS[$c['status']] ?> <?= ucfirst(str_replace('_',' ',$c['status'])) ?>
                </span>
            </td>
            <td class="small text-muted"><?= htmlspecialchars($c['started_name'] ?? '—') ?></td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap;">
                <a href="count.php?view=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">
                    <?php if (in_array($c['status'],['pending','overdue'])): ?>▶ Start
                    <?php elseif ($c['status']==='in_progress'):           ?>🔄 Continue
                    <?php elseif ($c['status']==='submitted'):             ?>📋 Review
                    <?php else:                                            ?>👁 View
                    <?php endif; ?>
                </a>
                <?php if ($isOwner): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action"   value="delete_count">
                    <input type="hidden" name="count_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('Delete this count? Cannot be undone.')"
                            style="padding:.2rem .55rem;font-size:12px;">🗑</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
