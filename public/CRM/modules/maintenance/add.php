<?php
// ============================================================
// Device Maintenance — Add / Edit
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

$editId = intval($_GET['id'] ?? 0);
$record = $editId ? DB::queryOne("SELECT * FROM device_maintenance WHERE id=?", [$editId]) : null;
$isEdit = $record !== null;
$errors = [];

// Device type → default maintenance type suggestion map
$DEVICE_MAINT_SUGGEST = [
    'Gaming Console'   => 'Gaming Console Deep Clean',
    'Computer'         => 'Computer Tune Up',
    'Gaming Computer'  => 'Gaming Computer Maintenance',
    'Phone'            => 'Phone Plan Renewal',
    'Tablet'           => 'Battery Change Reminder',
    'Laptop'           => 'Computer Tune Up',
];

$MAINT_TYPES = [
    'Gaming Console Deep Clean',
    'Computer Tune Up',
    'Gaming Computer Maintenance',
    'Phone Plan Renewal',
    'Battery Change Reminder',
    'Other',
];

$DEVICE_TYPES = ['Gaming Console','Computer','Gaming Computer','Phone','Tablet','Laptop','Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locId          = intval($_POST['location_id']      ?? $user['location_id']);
    $custId         = intval($_POST['customer_id']      ?? 0) ?: null;
    $custName       = trim($_POST['customer_name']      ?? '');
    $custPhone      = trim($_POST['customer_phone']     ?? '');
    $deviceType     = trim($_POST['device_type']        ?? '');
    $deviceBrand    = trim($_POST['device_brand']       ?? '');
    $deviceModel    = trim($_POST['device_model']       ?? '');
    $maintType      = trim($_POST['maintenance_type']   ?? '');
    $maintNotes     = trim($_POST['maintenance_notes']  ?? '');
    $serviceDate    = trim($_POST['service_date']       ?? '');
    $followMonths   = intval($_POST['follow_up_months'] ?? 3);
    if (!in_array($followMonths, [3,6,12])) $followMonths = 3;

    // Auto-calculate follow-up date
    $followDate = '';
    if ($serviceDate) {
        try {
            $dt = new DateTime($serviceDate);
            $dt->modify("+{$followMonths} months");
            $followDate = $dt->format('Y-m-d');
        } catch (Exception $e) { $errors[] = 'Invalid service date.'; }
    }

    if (!$custName)    $errors[] = 'Customer name is required.';
    if (!$custPhone)   $errors[] = 'Customer phone is required.';
    if (!$deviceType)  $errors[] = 'Device type is required.';
    if (!$maintType)   $errors[] = 'Maintenance type is required.';
    if (!$serviceDate) $errors[] = 'Service date is required.';
    if (!$followDate && empty($errors)) $errors[] = 'Could not calculate follow-up date.';

    if (empty($errors)) {
        if ($isEdit) {
            DB::execute(
                "UPDATE device_maintenance SET location_id=?, customer_id=?, customer_name=?, customer_phone=?,
                 device_type=?, device_brand=?, device_model=?, maintenance_type=?, maintenance_notes=?,
                 service_date=?, follow_up_months=?, follow_up_date=?, updated_at=NOW()
                 WHERE id=?",
                [$locId, $custId, $custName, $custPhone, $deviceType, $deviceBrand ?: null, $deviceModel ?: null,
                 $maintType, $maintNotes ?: null, $serviceDate, $followMonths, $followDate, $editId]
            );
        } else {
            DB::insert(
                "INSERT INTO device_maintenance
                 (location_id, customer_id, customer_name, customer_phone, device_type, device_brand, device_model,
                  maintenance_type, maintenance_notes, service_date, follow_up_months, follow_up_date, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                [$locId, $custId, $custName, $custPhone, $deviceType, $deviceBrand ?: null, $deviceModel ?: null,
                 $maintType, $maintNotes ?: null, $serviceDate, $followMonths, $followDate, $user['id']]
            );
        }
        header('Location: ' . APP_URL . '/modules/maintenance/index.php?msg=saved'); exit;
    }
}

// Customer search pre-fill (from ?customer_id=)
$prefillCustomer = null;
$prefillCustId = intval($_GET['customer_id'] ?? 0);
if ($prefillCustId) {
    $prefillCustomer = DB::queryOne(
        "SELECT id, first_name, last_name, phone_primary FROM customers WHERE id=?",
        [$prefillCustId]
    );
}

$v = $record ?? [];
$pageTitle = $isEdit ? 'Edit Maintenance Record' : 'Add Maintenance Record';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? '✏️ Edit Record' : '🔧 Add Maintenance Record' ?></h1>
    </div>
    <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
<?php endif; ?>

<form method="POST">
<div class="form-grid">

    <!-- Customer -->
    <div class="card" style="grid-column:1/-1;">
        <div class="card-header"><h2 class="card-title">Customer</h2></div>
        <div class="card-body">
            <input type="hidden" name="customer_id" id="customer_id_hidden"
                   value="<?= intval($v['customer_id'] ?? $prefillCustId ?? 0) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Customer Name <span class="required">*</span></label>
                    <input type="text" id="customer_name_input" name="customer_name" class="form-control" required
                           autocomplete="off"
                           value="<?= htmlspecialchars($v['customer_name'] ?? $_POST['customer_name'] ?? ($prefillCustomer ? trim($prefillCustomer['first_name'].' '.$prefillCustomer['last_name']) : '')) ?>"
                           placeholder="Type name or search existing...">
                    <div id="customer-suggestions" style="display:none;position:absolute;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:200;min-width:300px;max-height:200px;overflow-y:auto;"></div>
                </div>
                <div class="form-group" style="position:relative;">
                    <label class="form-label">Phone <span class="required">*</span></label>
                    <div style="display:flex;gap:.5rem;">
                        <input type="text" name="customer_phone" class="form-control" required id="customer_phone_input"
                               value="<?= htmlspecialchars($v['customer_phone'] ?? $_POST['customer_phone'] ?? ($prefillCustomer['phone_primary'] ?? '')) ?>"
                               placeholder="e.g. 905-555-0123">
                        <button type="button" class="btn btn-secondary" onclick="lookupByPhone()">Look Up</button>
                    </div>
                    <div id="phone_lookup_result" class="form-hint" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Device -->
    <div class="card">
        <div class="card-header"><h2 class="card-title">Device Info</h2></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Device Type <span class="required">*</span></label>
                <select name="device_type" class="form-control" id="device_type_select" onchange="suggestMaintType(this.value)" required>
                    <option value="">— Select —</option>
                    <?php foreach ($DEVICE_TYPES as $dt): ?>
                    <option value="<?= $dt ?>" <?= ($v['device_type'] ?? $_POST['device_type'] ?? '') === $dt ? 'selected' : '' ?>><?= $dt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Brand</label>
                    <input type="text" name="device_brand" class="form-control"
                           value="<?= htmlspecialchars($v['device_brand'] ?? $_POST['device_brand'] ?? '') ?>"
                           placeholder="e.g. Sony, Dell, Apple">
                </div>
                <div class="form-group">
                    <label class="form-label">Model</label>
                    <input type="text" name="device_model" class="form-control"
                           value="<?= htmlspecialchars($v['device_model'] ?? $_POST['device_model'] ?? '') ?>"
                           placeholder="e.g. PS5, XPS 15">
                </div>
            </div>
        </div>
    </div>

    <!-- Maintenance -->
    <div class="card">
        <div class="card-header"><h2 class="card-title">Maintenance Details</h2></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Maintenance Type <span class="required">*</span></label>
                <select name="maintenance_type" class="form-control" id="maintenance_type_select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($MAINT_TYPES as $mt): ?>
                    <option value="<?= htmlspecialchars($mt) ?>" <?= ($v['maintenance_type'] ?? $_POST['maintenance_type'] ?? '') === $mt ? 'selected' : '' ?>>
                        <?= htmlspecialchars($mt) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="maintenance_notes" class="form-control" rows="2"
                          placeholder="Any notes about the service performed..."><?= htmlspecialchars($v['maintenance_notes'] ?? $_POST['maintenance_notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Scheduling -->
    <div class="card" style="grid-column:1/-1;">
        <div class="card-header"><h2 class="card-title">Scheduling</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Service Date <span class="required">*</span></label>
                    <input type="date" name="service_date" class="form-control" required
                           value="<?= htmlspecialchars($v['service_date'] ?? $_POST['service_date'] ?? date('Y-m-d')) ?>"
                           onchange="updateFollowUpPreview()">
                </div>
                <div class="form-group">
                    <label class="form-label">Follow-up Reminder</label>
                    <select name="follow_up_months" class="form-control" onchange="updateFollowUpPreview()">
                        <?php foreach ([3,6,12] as $m): ?>
                        <option value="<?= $m ?>" <?= ($v['follow_up_months'] ?? $_POST['follow_up_months'] ?? 3) == $m ? 'selected' : '' ?>>
                            <?= $m ?> months
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <div id="follow-up-preview" style="padding:.5rem 0;font-size:14px;color:var(--text-2);"></div>
                </div>
            </div>
            <div class="form-hint">An SMS reminder will be automatically sent to the customer on their follow-up date.</div>

            <?php if ($isMgr): ?>
            <div class="form-group" style="margin-top:1rem;">
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

<div class="form-actions" style="display:flex;gap:.5rem;">
    <a href="index.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">💾 <?= $isEdit ? 'Save Changes' : 'Add Record' ?></button>
</div>
</form>

<script>
// ── Phone lookup ─────────────────────────────────────────────
function lookupByPhone() {
    const phone = document.getElementById('customer_phone_input').value.trim();
    if (!phone) return;
    fetch('<?= APP_URL ?>/modules/customers/search.php?q=' + encodeURIComponent(phone))
        .then(r => r.json())
        .then(data => {
            const result = document.getElementById('phone_lookup_result');
            if (data && data.length > 0) {
                const c = data[0];
                document.getElementById('customer_name_input').value = c.full_name;
                document.getElementById('customer_id_hidden').value  = c.id || '';
                document.getElementById('customer_phone_input').value = c.phone || phone;
                result.textContent = '✓ Found: ' + c.full_name;
                result.style.color = 'var(--green)';
                result.style.display = 'block';
            } else {
                result.textContent = 'No existing customer found — new customer will be saved.';
                result.style.color = 'var(--text-3)';
                result.style.display = 'block';
            }
        });
}
document.getElementById('customer_phone_input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); lookupByPhone(); }
});

const deviceMaintSuggest = <?= json_encode($DEVICE_MAINT_SUGGEST) ?>;

function suggestMaintType(deviceType) {
    const sel = document.getElementById('maintenance_type_select');
    if (sel.value === '' && deviceMaintSuggest[deviceType]) {
        sel.value = deviceMaintSuggest[deviceType];
    }
}

function updateFollowUpPreview() {
    const dateVal   = document.querySelector('[name=service_date]').value;
    const months    = parseInt(document.querySelector('[name=follow_up_months]').value);
    const preview   = document.getElementById('follow-up-preview');
    if (!dateVal) { preview.innerHTML = ''; return; }
    const d = new Date(dateVal + 'T00:00:00');
    d.setMonth(d.getMonth() + months);
    const opts = { year:'numeric', month:'long', day:'numeric' };
    preview.innerHTML = '📅 Reminder on <strong>' + d.toLocaleDateString('en-CA', opts) + '</strong>';
}
updateFollowUpPreview();

// Customer name search (live lookup in existing customers)
const nameInput  = document.getElementById('customer_name_input');
const phoneInput = document.getElementById('customer_phone_input');
const suggestions = document.getElementById('customer-suggestions');
const custIdHidden = document.getElementById('customer_id_hidden');
let searchTimer;

nameInput.addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) { suggestions.style.display = 'none'; return; }
    searchTimer = setTimeout(() => {
        fetch('<?= APP_URL ?>/modules/customers/search.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) { suggestions.style.display = 'none'; return; }
                suggestions.innerHTML = data.map(c =>
                    `<div class="suggestion-item" style="padding:.5rem 1rem;cursor:pointer;border-bottom:1px solid var(--border);"
                          data-name="${c.first_name} ${c.last_name}" data-phone="${c.phone_primary || ''}" data-id="${c.id}">
                        <strong>${c.first_name} ${c.last_name}</strong>
                        <span style="color:var(--text-3);font-size:12px;margin-left:.5rem;">${c.phone_primary || ''}</span>
                    </div>`
                ).join('');
                suggestions.style.display = 'block';
                suggestions.querySelectorAll('.suggestion-item').forEach(el => {
                    el.addEventListener('mouseenter', () => el.style.background = 'var(--surface-2)');
                    el.addEventListener('mouseleave', () => el.style.background = '');
                    el.addEventListener('click', () => {
                        nameInput.value    = el.dataset.name;
                        phoneInput.value   = el.dataset.phone;
                        custIdHidden.value = el.dataset.id;
                        suggestions.style.display = 'none';
                    });
                });
            });
    }, 300);
});

document.addEventListener('click', e => {
    if (!e.target.closest('#customer-suggestions') && e.target !== nameInput) {
        suggestions.style.display = 'none';
    }
});
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
