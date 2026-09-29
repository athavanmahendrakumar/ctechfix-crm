<?php
// ============================================================
// Edit Repair Ticket
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

$repairId = intval($_GET['id'] ?? 0);
if (!$repairId) { header('Location: ' . APP_URL . '/modules/repairs/'); exit; }

$repair = DB::queryOne(
    "SELECT r.*, c.first_name, c.last_name, c.email, c.phone_primary
     FROM repairs r
     JOIN customers c ON r.customer_id = c.id
     WHERE r.id = ?",
    [$repairId]
);
if (!$repair) { header('Location: ' . APP_URL . '/modules/repairs/'); exit; }

// Staff can only edit repairs at their own location
if ($isStaff && $repair['location_id'] != $user['location_id']) {
    header('Location: ' . APP_URL . '/modules/repairs/'); exit;
}

// Locations — staff locked to their own
$locations = ($isOwner || $isManager)
    ? DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', [])
    : DB::query('SELECT * FROM locations WHERE is_active=1 AND id=? ORDER BY name', [$user['location_id']]);

// Techs
$techs = DB::query("SELECT u.id, u.first_name, u.last_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name IN ('owner','manager','staff') AND u.is_active=1 ORDER BY u.first_name", []);

$errors = [];

// ── POST: Save edits ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName       = trim($_POST['first_name']        ?? '');
    $lastName        = trim($_POST['last_name']         ?? '');
    $email           = trim($_POST['email']             ?? '');
    $phonePrimary    = trim($_POST['phone_primary']     ?? '');
    $smsOptOut       = isset($_POST['sms_opt_out']) ? 1 : 0;

    $deviceType      = trim($_POST['device_type']       ?? '');
    $deviceBrand     = trim($_POST['device_brand']      ?? '');
    $deviceModel     = trim($_POST['device_model']      ?? '');
    $deviceColor     = trim($_POST['device_color']      ?? '');
    $deviceSerial    = trim($_POST['device_serial']     ?? '');
    $deviceImei      = trim($_POST['device_imei']       ?? '');
    $devicePasscode  = trim($_POST['device_passcode']   ?? '');
    $accessories     = trim($_POST['accessories']       ?? '');

    $serviceType     = implode(', ', array_filter(array_map('trim', (array)($_POST['service_types'] ?? []))));
    $issueDesc       = trim($_POST['issue_description'] ?? '');
    $estimatedCost   = $_POST['estimated_cost'] !== '' ? floatval($_POST['estimated_cost']) : null;
    $estimatedReady  = trim($_POST['estimated_ready_at'] ?? '');
    $assignedTo      = intval($_POST['assigned_to']     ?? 0) ?: null;
    $warrantyDays    = intval($_POST['warranty_days']   ?? 0);
    $locationId      = ($isOwner || $isManager) ? intval($_POST['location_id'] ?? $repair['location_id']) : $repair['location_id'];

    if (!$firstName)   $errors[] = 'Customer first name is required.';
    if (!$phonePrimary) $errors[] = 'Phone number is required.';
    if (!$issueDesc)   $errors[] = 'Issue description is required.';
    if (!$deviceBrand) $errors[] = 'Device brand is required.';
    if (!$deviceModel) $errors[] = 'Device model is required.';

    if (empty($errors)) {
        // Normalize phone
        $phoneNorm = preg_replace('/\D/', '', $phonePrimary);
        if (strlen($phoneNorm) === 10) $phoneNorm = '1' . $phoneNorm;

        // Update customer
        DB::execute(
            'UPDATE customers SET first_name=?, last_name=?, email=?, phone_primary=?, phone_normalized=? WHERE id=?',
            [$firstName, $lastName ?: null, $email ?: null, $phonePrimary, $phoneNorm, $repair['customer_id']]
        );

        // Update repair
        DB::execute(
            'UPDATE repairs SET
                device_type=?, device_brand=?, device_model=?, device_color=?, device_serial=?,
                device_imei=?, device_passcode=?, accessories=?,
                service_type=?, issue_description=?, estimated_cost=?, estimated_ready_at=?,
                assigned_to=?, warranty_days=?, location_id=?, sms_opt_out=?, updated_by=?
             WHERE id=?',
            [
                $deviceType ?: null, $deviceBrand, $deviceModel, $deviceColor ?: null, $deviceSerial ?: null,
                $deviceImei ?: null, $devicePasscode ?: null, $accessories ?: null,
                $serviceType ?: null, $issueDesc, $estimatedCost, $estimatedReady ?: null,
                $assignedTo, $warrantyDays, $locationId, $smsOptOut, $user['id'],
                $repairId,
            ]
        );

        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=saved');
        exit;
    }
}

// Current service types as array for checkbox pre-check
$currentServices = $repair['service_type']
    ? array_map('trim', explode(',', $repair['service_type']))
    : [];

$serviceTypes = [
    'Screen Replacement', 'Battery Replacement', 'Charging Port Repair',
    'Camera Repair', 'Speaker Repair', 'Housing / Back Glass', 'Water Damage',
    'Motherboard Repair', 'HDMI Port Repair', 'Deep Cleaning',
    'Thermal Paste / Liquid Metal', 'No Power Diagnosis',
    'Software / OS Install', 'Data Recovery', 'Other',
];

// Pre-fill from POST on validation error, else from DB
function fv($post_key, $db_val) {
    return htmlspecialchars($_POST[$post_key] ?? $db_val ?? '');
}

$pageTitle = 'Edit Repair — ' . $repair['record_number'];
require_once APP_ROOT . '/modules/layout/header.php';
?>

<style>
.svc-chip {
    display:inline-block;padding:.3rem .75rem;border-radius:20px;
    border:1.5px solid var(--border);font-size:13px;cursor:pointer;
    transition:all .15s;user-select:none;
}
label:hover .svc-chip       { border-color: var(--primary); color: var(--primary); }
label.svc-active .svc-chip,
.svc-chip.svc-active        { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Repair</h1>
        <p class="page-sub"><?= htmlspecialchars($repair['record_number']) ?></p>
    </div>
    <a href="view.php?id=<?= $repairId ?>" class="btn btn-secondary">← Back to Repair</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">

    <!-- ── Customer ──────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-header"><h2 class="card-title">Customer Info</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" class="form-control" required
                           value="<?= fv('first_name', $repair['first_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control"
                           value="<?= fv('last_name', $repair['last_name']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Phone <span class="required">*</span></label>
                    <input type="text" name="phone_primary" class="form-control" required
                           value="<?= fv('phone_primary', $repair['phone_primary']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= fv('email', $repair['email']) ?>">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:.25rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:14px;">
                        <input type="checkbox" name="sms_opt_out" value="1"
                               <?= (($_POST['sms_opt_out'] ?? null) === '1' || (!isset($_POST['sms_opt_out']) && $repair['sms_opt_out'])) ? 'checked' : '' ?>>
                        SMS Opt-Out
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Device ─────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-header"><h2 class="card-title">Device</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Device Type</label>
                    <select name="device_type" id="device_type" class="form-control" onchange="updateBrandList()">
                        <option value="">— Select —</option>
                        <?php foreach (['Cell Phone','Tablet','Laptop','Desktop','Gaming Console','Smartwatch','Other'] as $dt): ?>
                        <option value="<?= $dt ?>" <?= fv('device_type', $repair['device_type']) === $dt ? 'selected' : '' ?>><?= $dt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Brand <span class="required">*</span></label>
                    <select name="device_brand" id="device_brand" class="form-control" required onchange="updateModelList()">
                        <option value="">— Select Brand —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Model <span class="required">*</span></label>
                    <input type="text" name="device_model" id="device_model" class="form-control"
                           list="model_suggestions" required
                           value="<?= fv('device_model', $repair['device_model']) ?>"
                           placeholder="Type or pick a model">
                    <datalist id="model_suggestions"></datalist>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Color</label>
                    <input type="text" name="device_color" class="form-control"
                           value="<?= fv('device_color', $repair['device_color']) ?>"
                           placeholder="e.g. Space Black">
                </div>
                <div class="form-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="device_serial" class="form-control"
                           value="<?= fv('device_serial', $repair['device_serial']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">IMEI</label>
                    <input type="text" name="device_imei" class="form-control"
                           value="<?= fv('device_imei', $repair['device_imei']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Passcode</label>
                    <input type="text" name="device_passcode" class="form-control"
                           value="<?= fv('device_passcode', $repair['device_passcode']) ?>"
                           placeholder="Device unlock code">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Accessories Included</label>
                    <input type="text" name="accessories" class="form-control"
                           value="<?= fv('accessories', $repair['accessories']) ?>"
                           placeholder="e.g. Case, charger">
                </div>
            </div>
        </div>
    </div>

    <!-- ── Repair Details ─────────────────────────────────────── -->
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-header"><h2 class="card-title">Repair Details</h2></div>
        <div class="card-body">

            <div class="form-group" style="margin-bottom:1rem;">
                <label class="form-label">Service Type</label>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.25rem;">
                <?php
                $selSvcs = isset($_POST['service_types'])
                    ? array_map('trim', (array)$_POST['service_types'])
                    : $currentServices;
                foreach ($serviceTypes as $st):
                    $checked = in_array($st, $selSvcs);
                ?>
                <label class="<?= $checked ? 'svc-active' : '' ?>" style="margin:0;">
                    <input type="checkbox" name="service_types[]" value="<?= htmlspecialchars($st) ?>"
                           style="display:none;"
                           <?= $checked ? 'checked' : '' ?>
                           onchange="this.closest('label').classList.toggle('svc-active', this.checked)">
                    <span class="svc-chip <?= $checked ? 'svc-active' : '' ?>"><?= htmlspecialchars($st) ?></span>
                </label>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Issue Description <span class="required">*</span></label>
                <textarea name="issue_description" class="form-control" rows="3" required
                          placeholder="Describe the problem in detail"><?= fv('issue_description', $repair['issue_description']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Estimated Cost ($)</label>
                    <input type="number" name="estimated_cost" class="form-control" step="0.01" min="0"
                           value="<?= fv('estimated_cost', $repair['estimated_cost']) ?>"
                           placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Estimated Ready</label>
                    <?php
                    $readyVal = '';
                    $rawReady = $_POST['estimated_ready_at'] ?? $repair['estimated_ready_at'] ?? '';
                    if ($rawReady) {
                        try { $readyVal = (new DateTime($rawReady))->format('Y-m-d\TH:i'); } catch (Exception $e) {}
                    }
                    ?>
                    <input type="datetime-local" name="estimated_ready_at" class="form-control"
                           value="<?= htmlspecialchars($readyVal) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Warranty</label>
                    <select name="warranty_days" class="form-control">
                        <?php foreach ([0=>'No Warranty',30=>'30 Days',60=>'60 Days',90=>'90 Days',180=>'6 Months',365=>'1 Year'] as $days=>$label): ?>
                        <option value="<?= $days ?>" <?= intval($_POST['warranty_days'] ?? $repair['warranty_days']) == $days ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Assigned Technician</label>
                    <select name="assigned_to" class="form-control">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($techs as $tech): ?>
                        <option value="<?= $tech['id'] ?>"
                            <?= intval($_POST['assigned_to'] ?? $repair['assigned_to']) == $tech['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tech['first_name'] . ' ' . $tech['last_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isOwner || $isManager): ?>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control">
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"
                            <?= intval($_POST['location_id'] ?? $repair['location_id']) == $loc['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <div class="form-actions">
        <a href="view.php?id=<?= $repairId ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg">Save Changes</button>
    </div>

</form>

<script>
const brandsByType = {
    'Cell Phone':     ['Apple','Samsung','Google','LG','Motorola','OnePlus','Nokia','Other'],
    'Tablet':         ['Apple','Samsung','Microsoft','Lenovo','Amazon','Other'],
    'Laptop':         ['Apple','Dell','HP','Lenovo','Asus','Acer','MSI','Microsoft','Toshiba','Other'],
    'Desktop':        ['Dell','HP','Lenovo','Asus','Apple','Custom Build','Other'],
    'Gaming Console': ['Sony (PlayStation)','Microsoft (Xbox)','Nintendo','Valve (Steam Deck)','Other'],
    'Smartwatch':     ['Apple','Samsung','Garmin','Fitbit','Other'],
    'Other':          ['Other'],
};

const modelsByBrand = {
    'Apple': {
        'Cell Phone': ['iPhone 16 Pro Max','iPhone 16 Pro','iPhone 16 Plus','iPhone 16','iPhone 15 Pro Max','iPhone 15 Pro','iPhone 15 Plus','iPhone 15','iPhone 14 Pro Max','iPhone 14 Pro','iPhone 14 Plus','iPhone 14','iPhone 13 Pro Max','iPhone 13 Pro','iPhone 13','iPhone 13 Mini','iPhone 12 Pro Max','iPhone 12 Pro','iPhone 12','iPhone 12 Mini','iPhone 11 Pro Max','iPhone 11 Pro','iPhone 11','iPhone XS Max','iPhone XS','iPhone XR','iPhone X','iPhone 8 Plus','iPhone 8','iPhone SE (3rd Gen)','iPhone SE (2nd Gen)'],
        'Tablet':     ['iPad Pro 13"','iPad Pro 11"','iPad Air (M2)','iPad Air (M1)','iPad Mini 7','iPad Mini 6','iPad (10th Gen)','iPad (9th Gen)'],
        'Laptop':     ['MacBook Pro 16"','MacBook Pro 14"','MacBook Air 15"','MacBook Air 13" (M3)','MacBook Air 13" (M2)','MacBook Air 13" (M1)'],
        'Smartwatch': ['Apple Watch Ultra 2','Apple Watch Series 10','Apple Watch Series 9','Apple Watch SE'],
    },
    'Samsung': {
        'Cell Phone': ['Galaxy S25 Ultra','Galaxy S25+','Galaxy S25','Galaxy S24 Ultra','Galaxy S24+','Galaxy S24','Galaxy S23 Ultra','Galaxy S23+','Galaxy S23','Galaxy S22 Ultra','Galaxy S22+','Galaxy S22','Galaxy A55','Galaxy A54','Galaxy A35','Galaxy A34','Galaxy A25','Galaxy A15','Galaxy Z Fold 6','Galaxy Z Fold 5','Galaxy Z Flip 6','Galaxy Z Flip 5'],
        'Tablet':     ['Galaxy Tab S10 Ultra','Galaxy Tab S10+','Galaxy Tab S10','Galaxy Tab S9 Ultra','Galaxy Tab S9+','Galaxy Tab S9','Galaxy Tab A9+','Galaxy Tab A9'],
    },
    'Google': {
        'Cell Phone': ['Pixel 9 Pro XL','Pixel 9 Pro Fold','Pixel 9 Pro','Pixel 9','Pixel 8 Pro','Pixel 8a','Pixel 8','Pixel 7 Pro','Pixel 7a','Pixel 7','Pixel 6 Pro','Pixel 6a','Pixel 6'],
    },
    'Sony (PlayStation)': {
        'Gaming Console': ['PlayStation 5 (Disc)','PlayStation 5 Digital','PlayStation 4 Pro','PlayStation 4 Slim','PlayStation 4','PlayStation 3'],
    },
    'Microsoft (Xbox)': {
        'Gaming Console': ['Xbox Series X','Xbox Series S','Xbox One X','Xbox One S','Xbox One'],
    },
    'Nintendo': {
        'Gaming Console': ['Nintendo Switch OLED','Nintendo Switch','Nintendo Switch Lite','Nintendo 3DS','Nintendo DS'],
    },
    'Dell': {
        'Laptop':  ['XPS 15','XPS 13','Inspiron 15','Inspiron 14','Latitude 14','Latitude 13','Precision 5570'],
        'Desktop': ['XPS Desktop','Inspiron Desktop','OptiPlex','Vostro Desktop'],
    },
    'HP': {
        'Laptop':  ['Spectre x360','Envy 15','Pavilion 15','EliteBook 840','ProBook 450'],
        'Desktop': ['Pavilion Desktop','Envy Desktop','EliteDesk','ProDesk'],
    },
    'Lenovo': {
        'Laptop':  ['ThinkPad X1 Carbon','ThinkPad T14','IdeaPad 5','Legion 5','Yoga 9i'],
        'Desktop': ['ThinkCentre M90','IdeaCentre 5','Legion Tower 5i'],
        'Tablet':  ['Tab P12 Pro','Tab P11 Pro','Tab M10'],
    },
};

function updateBrandList() {
    const type     = document.getElementById('device_type').value;
    const brandSel = document.getElementById('device_brand');
    const brands   = brandsByType[type] || ['Other'];
    brandSel.innerHTML = '<option value="">— Select Brand —</option>';
    brands.forEach(b => {
        const opt = document.createElement('option');
        opt.value = opt.textContent = b;
        brandSel.appendChild(opt);
    });
    document.getElementById('device_model').value = '';
    document.getElementById('model_suggestions').innerHTML = '';
}

function updateModelList() {
    const type     = document.getElementById('device_type').value;
    const brand    = document.getElementById('device_brand').value;
    const datalist = document.getElementById('model_suggestions');
    datalist.innerHTML = '';
    const suggestions = (modelsByBrand[brand] || {})[type] || [];
    suggestions.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m;
        datalist.appendChild(opt);
    });
}

// Pre-fill brand/model on load
(function() {
    const type  = <?= json_encode($_POST['device_type']  ?? $repair['device_type']  ?? '') ?>;
    const brand = <?= json_encode($_POST['device_brand'] ?? $repair['device_brand'] ?? '') ?>;
    const model = <?= json_encode($_POST['device_model'] ?? $repair['device_model'] ?? '') ?>;
    if (type) {
        document.getElementById('device_type').value = type;
        updateBrandList();
        if (brand) {
            document.getElementById('device_brand').value = brand;
            updateModelList();
        }
        if (model) document.getElementById('device_model').value = model;
    }
})();
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
