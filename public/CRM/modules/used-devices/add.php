<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ============================================================
// Used Devices — Add / Edit
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

$editId  = intval($_GET['id'] ?? 0);
$device  = $editId ? DB::queryOne("SELECT * FROM used_devices WHERE id=?", [$editId]) : null;
$isEdit  = $device !== null;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locId        = intval($_POST['location_id']    ?? $user['location_id']);
    $deviceType   = trim($_POST['device_type']      ?? '');
    $brand        = trim($_POST['device_brand']      ?? '');
    $model        = trim($_POST['device_model']      ?? '');
    $color        = trim($_POST['color']             ?? '');
    $storage      = trim($_POST['storage']           ?? '');
    $imei         = trim($_POST['imei']              ?? '');
    $sn           = trim($_POST['serial_number']     ?? '');
    $condition    = trim($_POST['condition_grade']   ?? 'Good');
    $source       = $_POST['source'] === 'walk_in' ? 'walk_in' : 'vendor';
    $vendorName   = trim($_POST['vendor_name']       ?? '');
    $buyPrice     = floatval($_POST['purchase_price'] ?? 0);
    $sellPrice    = floatval($_POST['selling_price']  ?? 0);
    $warranty     = trim($_POST['warranty']          ?? 'none');
    $warrantyDays = intval($_POST['warranty_days']   ?? 0);
    $status       = trim($_POST['status']            ?? 'in_stock');
    $notes        = trim($_POST['notes']             ?? '');

    if (!$deviceType) $errors[] = 'Device type is required.';
    if (!$brand)      $errors[] = 'Brand is required.';
    if (!$model)      $errors[] = 'Model is required.';
    if (!$locId)      $errors[] = 'Location is required.';

    if (empty($errors)) {
        $warrantyDaysSave = $warranty === 'custom' ? $warrantyDays : null;
        try {
        if ($isEdit) {
            DB::execute(
                "UPDATE used_devices SET location_id=?, device_type=?, device_brand=?, device_model=?,
                 color=?, storage=?, imei=?, serial_number=?, condition_grade=?, source=?, vendor_name=?,
                 purchase_price=?, selling_price=?, warranty=?, warranty_days=?, status=?, notes=?, updated_at=NOW()
                 WHERE id=?",
                [$locId, $deviceType, $brand, $model, $color ?: null, $storage ?: null,
                 $imei ?: null, $sn ?: null, $condition, $source, $source === 'vendor' ? ($vendorName ?: null) : null,
                 $buyPrice, $sellPrice, $warranty, $warrantyDaysSave, $status, $notes ?: null, $editId]
            );
            $newId = $editId;
        } else {
            $newId = DB::insert(
                "INSERT INTO used_devices (location_id, device_type, device_brand, device_model, color, storage,
                 imei, serial_number, condition_grade, source, vendor_name, purchase_price, selling_price,
                 warranty, warranty_days, status, notes, added_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                [$locId, $deviceType, $brand, $model, $color ?: null, $storage ?: null,
                 $imei ?: null, $sn ?: null, $condition, $source, $source === 'vendor' ? ($vendorName ?: null) : null,
                 $buyPrice, $sellPrice, $warranty, $warrantyDaysSave, $status, $notes ?: null, $user['id']]
            );
            // Auto-generate barcode
            $barcode = 'CTF' . str_pad($newId, 7, '0', STR_PAD_LEFT);
            DB::execute("UPDATE used_devices SET barcode=? WHERE id=?", [$barcode, $newId]);
        }

        $printLabel = !empty($_POST['print_label']);
        $redirect = APP_URL . '/modules/used-devices/index.php';
        if ($printLabel) {
            $redirect = APP_URL . '/modules/inventory/label.php?type=used&ids=' . $newId . '&autoprint=1';
        }
        header('Location: ' . $redirect); exit;
        } catch (\Throwable $e) {
            $errors[] = '⚠ Database error: ' . $e->getMessage();
        }
    }
}

$v = $device ?? [];
$pageTitle = $isEdit ? 'Edit Device' : 'Add Used Device';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? '✏️ Edit Device' : '➕ Add Used Device' ?></h1>
    </div>
    <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
<?php endif; ?>

<form method="POST" id="used-device-form">
<div class="form-grid">

    <!-- Device Info -->
    <div class="card" style="grid-column:1/-1;">
        <div class="card-header"><h2 class="card-title">Device Details</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Type <span class="required">*</span></label>
                    <select name="device_type" class="form-control" required>
                        <option value="">— Select —</option>
                        <?php foreach (['Phone','Tablet','Laptop','Computer','Gaming Console'] as $t): ?>
                        <option value="<?= $t ?>" <?= ($v['device_type'] ?? $_POST['device_type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Brand <span class="required">*</span></label>
                    <input type="text" name="device_brand" class="form-control" required
                           value="<?= htmlspecialchars($v['device_brand'] ?? $_POST['device_brand'] ?? '') ?>"
                           placeholder="e.g. Apple, Samsung">
                </div>
                <div class="form-group">
                    <label class="form-label">Model <span class="required">*</span></label>
                    <input type="text" name="device_model" class="form-control" required
                           value="<?= htmlspecialchars($v['device_model'] ?? $_POST['device_model'] ?? '') ?>"
                           placeholder="e.g. iPhone 13, Galaxy S22">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Color</label>
                    <input type="text" name="color" class="form-control"
                           value="<?= htmlspecialchars($v['color'] ?? $_POST['color'] ?? '') ?>"
                           placeholder="e.g. Midnight Black">
                </div>
                <div class="form-group">
                    <label class="form-label">Storage</label>
                    <input type="text" name="storage" class="form-control"
                           value="<?= htmlspecialchars($v['storage'] ?? $_POST['storage'] ?? '') ?>"
                           placeholder="e.g. 128GB">
                </div>
                <div class="form-group">
                    <label class="form-label">Condition</label>
                    <select name="condition_grade" class="form-control">
                        <?php foreach (['Excellent','Good','Fair'] as $c): ?>
                        <option value="<?= $c ?>" <?= ($v['condition_grade'] ?? $_POST['condition_grade'] ?? 'Good') === $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">IMEI</label>
                    <input type="text" name="imei" class="form-control"
                           value="<?= htmlspecialchars($v['imei'] ?? $_POST['imei'] ?? '') ?>"
                           placeholder="15-digit IMEI">
                </div>
                <div class="form-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control"
                           value="<?= htmlspecialchars($v['serial_number'] ?? $_POST['serial_number'] ?? '') ?>"
                           placeholder="Device SN">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes / Cosmetic Issues</label>
                <textarea name="notes" class="form-control" rows="2"
                          placeholder="e.g. Minor scratch on back, screen crack top-left..."><?= htmlspecialchars($v['notes'] ?? $_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Acquisition -->
    <div class="card">
        <div class="card-header"><h2 class="card-title">Source & Pricing</h2></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Source</label>
                <select name="source" class="form-control" onchange="toggleVendor(this.value)">
                    <option value="vendor"   <?= ($v['source'] ?? 'vendor') === 'vendor'   ? 'selected' : '' ?>>Vendor / Supplier</option>
                    <option value="walk_in"  <?= ($v['source'] ?? '') === 'walk_in'         ? 'selected' : '' ?>>Walk-in (Individual)</option>
                </select>
            </div>
            <div class="form-group" id="vendor-name-row" style="<?= ($v['source'] ?? 'vendor') === 'walk_in' ? 'display:none;' : '' ?>">
                <label class="form-label">Vendor Name</label>
                <input type="text" name="vendor_name" class="form-control"
                       value="<?= htmlspecialchars($v['vendor_name'] ?? $_POST['vendor_name'] ?? '') ?>"
                       placeholder="Supplier or company name">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Purchase Price ($)</label>
                    <input type="number" name="purchase_price" class="form-control" step="0.01" min="0"
                           value="<?= htmlspecialchars($v['purchase_price'] ?? $_POST['purchase_price'] ?? '0') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Selling Price ($)</label>
                    <input type="number" name="selling_price" class="form-control" step="0.01" min="0"
                           value="<?= htmlspecialchars($v['selling_price'] ?? $_POST['selling_price'] ?? '0') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Warranty & Status (Manager/Owner only for warranty) -->
    <div class="card">
        <div class="card-header"><h2 class="card-title">Warranty & Status</h2></div>
        <div class="card-body">
            <?php if ($isMgr): ?>
            <div class="form-group">
                <label class="form-label">Customer Warranty</label>
                <select name="warranty" class="form-control" onchange="toggleWarrantyDays(this.value)">
                    <option value="none"     <?= ($v['warranty'] ?? 'none') === 'none'     ? 'selected' : '' ?>>No Warranty</option>
                    <option value="30_days"  <?= ($v['warranty'] ?? '') === '30_days'      ? 'selected' : '' ?>>30 Days</option>
                    <option value="60_days"  <?= ($v['warranty'] ?? '') === '60_days'      ? 'selected' : '' ?>>60 Days</option>
                    <option value="90_days"  <?= ($v['warranty'] ?? '') === '90_days'      ? 'selected' : '' ?>>90 Days</option>
                    <option value="custom"   <?= ($v['warranty'] ?? '') === 'custom'       ? 'selected' : '' ?>>Custom</option>
                </select>
                <div class="form-hint">Warranty starts from date of sale to customer.</div>
            </div>
            <div class="form-group" id="warranty-days-row" style="<?= ($v['warranty'] ?? 'none') !== 'custom' ? 'display:none;' : '' ?>">
                <label class="form-label">Custom Warranty Days</label>
                <input type="number" name="warranty_days" class="form-control" min="1" max="365"
                       value="<?= intval($v['warranty_days'] ?? $_POST['warranty_days'] ?? 0) ?>">
            </div>
            <?php else: ?>
            <div class="alert" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;font-size:13px;color:var(--text-3);">
                🔒 Warranty can only be set by a Manager or Owner.
            </div>
            <input type="hidden" name="warranty" value="<?= htmlspecialchars($v['warranty'] ?? 'none') ?>">
            <?php endif; ?>
            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="in_stock"  <?= ($v['status'] ?? 'in_stock') === 'in_stock'  ? 'selected' : '' ?>>In Stock</option>
                    <option value="returned"  <?= ($v['status'] ?? '') === 'returned'           ? 'selected' : '' ?>>Returned</option>
                    <option value="scrapped"  <?= ($v['status'] ?? '') === 'scrapped'           ? 'selected' : '' ?>>Scrapped</option>
                </select>
            </div>
            <?php if ($isMgr): ?>
            <div class="form-group">
                <label class="form-label">Location</label>
                <select name="location_id" class="form-control">
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= ($v['location_id'] ?? $user['location_id']) == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="location_id" value="<?= $user['location_id'] ?>">
            <?php endif; ?>
        </div>
    </div>

</div>

<div class="form-actions" style="display:flex;gap:.5rem;align-items:center;">
    <a href="index.php" class="btn btn-secondary">Cancel</a>
    <button type="button" onclick="document.getElementById('print_label_input').value='1'; document.getElementById('used-device-form').submit();" class="btn btn-secondary">💾 Save & Print Label</button>
    <button type="button" onclick="document.getElementById('print_label_input').value=''; document.getElementById('used-device-form').submit();" class="btn btn-primary">💾 Save Device</button>
</div>
<input type="hidden" name="print_label" id="print_label_input" value="">
</form>

<script>
function toggleVendor(val) {
    document.getElementById('vendor-name-row').style.display = val === 'walk_in' ? 'none' : '';
}
function toggleWarrantyDays(val) {
    document.getElementById('warranty-days-row').style.display = val === 'custom' ? '' : 'none';
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
