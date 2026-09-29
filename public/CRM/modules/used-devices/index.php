<?php
// ============================================================
// Used Devices — List
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

// ── POST: delete used device (owner only) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_device' && $isOwner) {
    $delId = intval($_POST['device_id'] ?? 0);
    if ($delId) DB::execute('DELETE FROM used_devices WHERE id=?', [$delId]);
    header('Location: ' . APP_URL . '/modules/used-devices/?deleted=1'); exit;
}

// Filters
$filterStatus = $_GET['status']      ?? 'in_stock';
$filterType   = $_GET['device_type'] ?? '';
$filterLoc    = $isMgr ? intval($_GET['location_id'] ?? 0) : (int)$user['location_id'];

$where  = ['1=1'];
$params = [];
if ($filterStatus !== 'all') { $where[] = 'u.status=?';      $params[] = $filterStatus; }
if ($filterType)             { $where[] = 'u.device_type=?'; $params[] = $filterType; }
if ($filterLoc)              { $where[] = 'u.location_id=?'; $params[] = $filterLoc; }
elseif (!$isMgr)             { $where[] = 'u.location_id=?'; $params[] = $user['location_id']; }

$devices = DB::query(
    "SELECT u.*, l.code AS loc_code, l.name AS loc_name
     FROM used_devices u
     LEFT JOIN locations l ON l.id=u.location_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY u.created_at DESC",
    $params
);

$WARRANTY_LABELS = [
    'none'     => 'No Warranty',
    '30_days'  => '30 Days',
    '60_days'  => '60 Days',
    '90_days'  => '90 Days',
    'custom'   => 'Custom',
];

$CONDITION_COLORS = [
    'Excellent' => 'var(--green)',
    'Good'      => 'var(--blue)',
    'Fair'      => 'var(--amber)',
];

$pageTitle = 'Used Devices';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📱 Used Devices</h1>
        <p class="page-sub">Pre-owned inventory tracked by IMEI / Serial Number</p>
    </div>
    <div style="display:flex;gap:.5rem;">
        <?php if (count($devices) > 0): ?>
        <a href="<?= APP_URL ?>/modules/inventory/label.php?type=used&ids=<?= implode(',', array_column($devices, 'id')) ?>"
           target="_blank" class="btn btn-secondary">🏷 Print All Labels</a>
        <?php endif; ?>
        <a href="add.php" class="btn btn-primary">+ Add Device</a>
    </div>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
    <select name="status" class="form-control filter-select" onchange="this.form.submit()">
        <option value="in_stock"  <?= $filterStatus==='in_stock' ?'selected':'' ?>>In Stock</option>
        <option value="sold"      <?= $filterStatus==='sold'     ?'selected':'' ?>>Sold</option>
        <option value="returned"  <?= $filterStatus==='returned' ?'selected':'' ?>>Returned</option>
        <option value="scrapped"  <?= $filterStatus==='scrapped' ?'selected':'' ?>>Scrapped</option>
        <option value="all"       <?= $filterStatus==='all'      ?'selected':'' ?>>All</option>
    </select>
    <select name="device_type" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Types</option>
        <?php foreach (['Phone','Tablet','Laptop','Computer','Gaming Console'] as $t): ?>
        <option value="<?= $t ?>" <?= $filterType===$t?'selected':'' ?>><?= $t ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($isMgr): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLoc==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <a href="?" class="btn btn-ghost">Reset</a>
</form>

<?php if (empty($devices)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state">
        <div class="empty-icon">📱</div>
        <p>No used devices found.</p>
        <a href="add.php" class="btn btn-primary" style="margin-top:1rem;">+ Add First Device</a>
    </div>
</div></div>
<?php else: ?>
<div class="card">
<div class="card-body" style="padding:0;">
<table class="data-table">
    <thead>
        <tr>
            <th>Device</th>
            <th>IMEI / SN</th>
            <th>Condition</th>
            <th>Source</th>
            <th>Buy</th>
            <th>Sell</th>
            <th>Margin</th>
            <th>Warranty</th>
            <th>Status</th>
            <?php if ($isMgr): ?><th>Loc</th><?php endif; ?>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($devices as $d):
        $margin = $d['selling_price'] - $d['purchase_price'];
        $warrantyLabel = $d['warranty'] === 'custom'
            ? ($d['warranty_days'] . ' Days')
            : ($WARRANTY_LABELS[$d['warranty']] ?? 'No Warranty');
    ?>
    <tr>
        <td>
            <div style="font-weight:600;"><?= htmlspecialchars($d['device_brand'] . ' ' . $d['device_model']) ?></div>
            <div style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars($d['device_type']) ?><?= $d['storage'] ? ' · ' . htmlspecialchars($d['storage']) : '' ?><?= $d['color'] ? ' · ' . htmlspecialchars($d['color']) : '' ?></div>
            <?php if ($d['barcode']): ?>
            <div style="font-size:11px;color:var(--text-3);font-family:monospace;"><?= htmlspecialchars($d['barcode']) ?></div>
            <?php endif; ?>
        </td>
        <td style="font-family:monospace;font-size:12px;">
            <?php if ($d['imei']): ?><div>IMEI: <?= htmlspecialchars($d['imei']) ?></div><?php endif; ?>
            <?php if ($d['serial_number']): ?><div>SN: <?= htmlspecialchars($d['serial_number']) ?></div><?php endif; ?>
            <?php if (!$d['imei'] && !$d['serial_number']): ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td><span style="color:<?= $CONDITION_COLORS[$d['condition_grade']] ?? 'inherit' ?>;font-weight:600;"><?= htmlspecialchars($d['condition_grade']) ?></span></td>
        <td><?= $d['source'] === 'walk_in' ? 'Walk-in' : htmlspecialchars($d['vendor_name'] ?? 'Vendor') ?></td>
        <td>$<?= number_format($d['purchase_price'], 2) ?></td>
        <td style="font-weight:600;">$<?= number_format($d['selling_price'], 2) ?></td>
        <td style="color:<?= $margin >= 0 ? 'var(--green)' : 'var(--red)' ?>;font-weight:600;">
            <?= $margin >= 0 ? '+' : '' ?>$<?= number_format($margin, 2) ?>
        </td>
        <td>
            <?php if ($d['warranty'] === 'none'): ?>
            <span style="color:var(--text-3);font-size:12px;">None</span>
            <?php else: ?>
            <span style="color:var(--green);font-size:12px;">✓ <?= htmlspecialchars($warrantyLabel) ?></span>
            <?php endif; ?>
        </td>
        <td>
            <?php
            $statusColors = ['in_stock'=>'var(--green)','sold'=>'var(--text-3)','returned'=>'var(--amber)','scrapped'=>'var(--red)'];
            $statusLabels = ['in_stock'=>'In Stock','sold'=>'Sold','returned'=>'Returned','scrapped'=>'Scrapped'];
            ?>
            <span style="color:<?= $statusColors[$d['status']] ?? 'inherit' ?>;font-weight:600;font-size:13px;">
                <?= $statusLabels[$d['status']] ?? $d['status'] ?>
            </span>
        </td>
        <?php if ($isMgr): ?>
        <td><span class="badge badge-loc"><?= htmlspecialchars($d['loc_code'] ?? '—') ?></span></td>
        <?php endif; ?>
        <td style="display:flex;gap:.3rem;flex-wrap:wrap;">
            <a href="edit.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
            <a href="<?= APP_URL ?>/modules/inventory/label.php?type=used&ids=<?= $d['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" title="Print Label">🏷</a>
            <?php if ($isOwner): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action"    value="delete_device">
                <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Delete this device? This cannot be undone.')"
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

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
