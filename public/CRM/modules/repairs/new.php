<?php
// ============================================================
// New Repair Intake
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/Audit.php';
require_once $rootPath . '/core/RecordNumber.php';

Auth::boot();
Auth::require();

$user     = Auth::user();
$training = Auth::isTraining();
$errors   = [];
$success  = null;

// Load technicians
$techs = DB::query(
    'SELECT id, first_name FROM users WHERE is_active = 1 ORDER BY first_name',
    []
);

// Load locations
$locations = DB::query('SELECT * FROM locations WHERE is_active = 1 ORDER BY name', []);

// Default location for this user
$defaultLocationId = $user['location_id'];

// ── Pre-fill from maintenance ────────────────────────────────
$fromMaintenanceId = intval($_GET['from_maintenance'] ?? 0);
$fromMaint = null;
if ($fromMaintenanceId) {
    $fromMaint = DB::queryOne(
        "SELECT m.*, l.name AS loc_name, l.id AS loc_id
         FROM device_maintenance m
         LEFT JOIN locations l ON l.id = m.location_id
         WHERE m.id = ? LIMIT 1",
        [$fromMaintenanceId]
    );
    if ($fromMaint) {
        // Map maintenance device types to repair device types
        $maintDeviceMap = [
            'Gaming Console' => 'Gaming Console',
            'Computer'       => 'Desktop',
            'Phone'          => 'Cell Phone',
            'Tablet'         => 'Tablet',
            'Laptop'         => 'Laptop',
        ];
        $nameParts = explode(' ', trim($fromMaint['customer_name']), 2);
        // Try to find existing customer
        $maintPhone = preg_replace('/\D/', '', $fromMaint['customer_phone']);
        if (strlen($maintPhone) === 11 && $maintPhone[0] === '1') $maintPhone = substr($maintPhone, 1);
        $maintCust = $maintPhone ? DB::queryOne('SELECT * FROM customers WHERE phone_normalized=? LIMIT 1', [$maintPhone]) : null;
        $prefill = [
            'customer_id' => $maintCust['id'] ?? '',
            'first_name'  => $maintCust['first_name'] ?? ($nameParts[0] ?? ''),
            'last_name'   => $maintCust['last_name']  ?? ($nameParts[1] ?? ''),
            'phone'       => $fromMaint['customer_phone'],
            'email'       => $maintCust['email'] ?? '',
            'device_type' => $maintDeviceMap[$fromMaint['device_type']] ?? $fromMaint['device_type'],
            'device_brand'=> $fromMaint['device_brand'] ?? '',
            'device_model'=> $fromMaint['device_model'] ?? '',
            'issue'       => $fromMaint['maintenance_type'],  // pre-fill issue with maintenance type
            'location_id' => $fromMaint['loc_id'] ?? $defaultLocationId,
        ];
        if (!$defaultLocationId) $defaultLocationId = $fromMaint['loc_id'];
    }
}

// ── Pre-fill from booking ───────────────────────────────────
$fromBookingId = intval($_GET['from_booking'] ?? 0);
if (!$fromMaintenanceId) $prefill = [];
if ($fromBookingId) {
    $bk = DB::queryOne(
        "SELECT b.*, c.id AS cust_id, c.first_name AS cust_first, c.last_name AS cust_last, c.email AS cust_email
         FROM bookings b
         LEFT JOIN customers c ON c.id = b.customer_id
         WHERE b.id = ? LIMIT 1",
        [$fromBookingId]
    );
    if ($bk) {
        $nameParts = explode(' ', trim($bk['customer_name']), 2);
        // Map booking device types to repair device types
        $deviceTypeMap = [
            'Phone'          => 'Cell Phone',
            'Tablet'         => 'Tablet',
            'Laptop'         => 'Laptop',
            'Computer'       => 'Desktop',
            'Gaming Console' => 'Gaming Console',
            'Other'          => 'Other',
        ];
        $prefill = [
            'customer_id' => $bk['cust_id']    ?? '',
            'first_name'  => $bk['cust_first'] ?? ($nameParts[0] ?? ''),
            'last_name'   => $bk['cust_last']  ?? ($nameParts[1] ?? ''),
            'phone'       => $bk['customer_phone'] ?? '',
            'email'       => $bk['cust_email'] ?? ($bk['customer_email'] ?? ''),
            'device_type' => $deviceTypeMap[$bk['device_type']] ?? $bk['device_type'],
            'device_brand'=> $bk['device_brand'] ?? '',
            'device_model'=> $bk['device_model'] ?? '',
            'issue'       => $bk['issue_description'] ?? '',
            'location_id' => $bk['location_id'] ?? $defaultLocationId,
        ];
        if (!$defaultLocationId) $defaultLocationId = $bk['location_id'];
    }
}

// ── Pre-fill from walk-in inquiry ───────────────────────────
$fromInquiryId = intval($_GET['inquiry_id'] ?? 0);
if ($fromInquiryId && !$fromMaintenanceId && !$fromBookingId) {
    $inq = DB::queryOne("SELECT * FROM walk_in_inquiries WHERE id=? LIMIT 1", [$fromInquiryId]);
    if ($inq) {
        $inqPhone = preg_replace('/\D/', '', $inq['customer_phone']);
        if (strlen($inqPhone) === 11 && $inqPhone[0] === '1') $inqPhone = substr($inqPhone, 1);
        $inqCust  = $inqPhone ? DB::queryOne('SELECT * FROM customers WHERE phone_normalized=? LIMIT 1', [$inqPhone]) : null;
        $inqParts = explode(' ', trim($inq['customer_name']), 2);
        $prefill  = [
            'customer_id' => $inqCust['id']         ?? '',
            'first_name'  => $inqCust['first_name'] ?? ($inqParts[0] ?? ''),
            'last_name'   => $inqCust['last_name']  ?? ($inqParts[1] ?? ''),
            'phone'       => $inq['customer_phone'],
            'email'       => $inqCust['email']      ?? '',
            'device_type' => 'Cell Phone',
            'device_brand'=> $inq['device_brand']   ?? '',
            'device_model'=> $inq['device_model']   ?? '',
            'issue'       => $inq['description']    ?? '',
            'location_id' => $inq['location_id']    ?? $defaultLocationId,
        ];
        if (!$defaultLocationId) $defaultLocationId = $inq['location_id'];
    }
}

// ── Handle form submission ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {

        // --- Customer ---
        $customerId   = intval($_POST['customer_id'] ?? 0);
        $firstName    = trim($_POST['first_name']    ?? '');
        $lastName     = trim($_POST['last_name']     ?? '');
        $phone        = trim($_POST['phone']         ?? '');
        $email        = trim($_POST['email']         ?? '');

        // --- Repair ---
        $locationId   = intval($_POST['location_id']      ?? $defaultLocationId);
        $deviceType   = trim($_POST['device_type']        ?? '');
        $deviceBrand  = trim($_POST['device_brand']       ?? '');
        $deviceModel  = trim($_POST['device_model']       ?? '');
        $deviceColor  = trim($_POST['device_color']       ?? '');
        $deviceSerial = trim($_POST['device_serial']      ?? '');
        $deviceImei   = trim($_POST['device_imei']        ?? '');
        $passcode     = trim($_POST['device_passcode']    ?? '');
        $accessories  = trim($_POST['accessories']        ?? '');
        $issue        = trim($_POST['issue_description']  ?? '');
        $serviceType  = implode(', ', array_filter(array_map('trim', (array)($_POST['service_types'] ?? []))));
        $estCost       = trim($_POST['estimated_cost']     ?? '');
        $depositAmount = floatval($_POST['deposit_amount'] ?? 0);
        $depositMethod = trim($_POST['deposit_method']    ?? '');
        $warrantyDays  = intval($_POST['warranty_days']   ?? 0);
        $assignedTo    = intval($_POST['assigned_to']      ?? 0) ?: null;
        $estReady     = trim($_POST['estimated_ready_at'] ?? '');
        $smsOptOut    = isset($_POST['sms_opt_out']) ? 1 : 0;

        // Validate
        if (!$firstName) $errors[] = 'Customer first name is required.';
        if (!$phone)     $errors[] = 'Customer phone number is required.';
        if (!$deviceType)  $errors[] = 'Device type is required.';
        if (!$deviceBrand) $errors[] = 'Device brand is required.';
        if (!$deviceModel) $errors[] = 'Device model is required.';
        if (!$issue)       $errors[] = 'Issue description is required.';
        if (!$locationId)  $errors[] = 'Location is required.';

        $phoneNormalized = normalizePhone($phone);
        if (strlen($phoneNormalized) !== 10) $errors[] = 'Phone number must be 10 digits.';

        if (empty($errors)) {

            // Create or update customer
            if ($customerId) {
                // Existing customer — update if info changed
                DB::execute(
                    'UPDATE customers SET first_name=?, last_name=?, phone_primary=?, phone_normalized=?, email=?, updated_at=NOW()
                     WHERE id=?',
                    [$firstName, $lastName, formatPhone($phoneNormalized), $phoneNormalized, $email ?: null, $customerId]
                );
            } else {
                // Check if customer exists by phone
                $existing = DB::queryOne(
                    'SELECT id FROM customers WHERE phone_normalized = ? LIMIT 1',
                    [$phoneNormalized]
                );
                if ($existing) {
                    $customerId = $existing['id'];
                    DB::execute(
                        'UPDATE customers SET first_name=?, last_name=?, email=COALESCE(NULLIF(?,\'\'), email), updated_at=NOW()
                         WHERE id=?',
                        [$firstName, $lastName, $email, $customerId]
                    );
                } else {
                    $customerId = DB::insert(
                        'INSERT INTO customers (first_name, last_name, phone_primary, phone_normalized, email, location_id, is_training, created_at)
                         VALUES (?,?,?,?,?,?,?,NOW())',
                        [$firstName, $lastName, formatPhone($phoneNormalized), $phoneNormalized, $email ?: null, $locationId, $training ? 1 : 0]
                    );
                }
            }

            // Generate record number CTF-OS-R-2026-000001
            $locCode = DB::queryOne('SELECT code FROM locations WHERE id=? LIMIT 1', [$locationId])['code'] ?? 'XX';
            $recordNumber = RecordNumber::next('REPAIR', $locCode);

            // Generate unique public status token
            $statusToken = bin2hex(random_bytes(16));

            // Insert repair
            $fromBookingId     = intval($_POST['from_booking_id']     ?? 0);
            $fromMaintenanceId = intval($_POST['from_maintenance_id'] ?? 0);
            $fromInquiryId     = intval($_POST['from_inquiry_id']     ?? 0);
            $repairId = DB::insert(
                'INSERT INTO repairs
                 (record_number, location_id, customer_id, booking_id,
                  device_type, device_brand, device_model, device_color, device_serial, device_imei, device_passcode,
                  accessories, issue_description, service_type, estimated_cost, deposit_amount, deposit_method, warranty_days, assigned_to,
                  status_token, estimated_ready_at, sms_opt_out, is_training, created_by, received_at)
                 VALUES (?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?,NOW())',
                [
                    $recordNumber, $locationId, $customerId, $fromBookingId ?: null,
                    $deviceType, $deviceBrand, $deviceModel, $deviceColor ?: null, $deviceSerial ?: null, $deviceImei ?: null, $passcode ?: null,
                    $accessories ?: null, $issue, $serviceType ?: null, $estCost !== '' ? $estCost : null,
                    $depositAmount, $depositMethod ?: null, $warrantyDays, $assignedTo,
                    $statusToken, $estReady ?: null, $smsOptOut, $training ? 1 : 0, $user['id'],
                ]
            );

            // If deposit collected, log it as a sale record
            if ($depositAmount > 0 && !$training) {
                $saleRecordNum = RecordNumber::next('SALE', $locCode);
                $saleId = DB::insert(
                    'INSERT INTO sales (record_number, location_id, customer_id, repair_id, sale_type,
                     subtotal, tax_amount, discount_amount, total_amount, payment_method, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $saleRecordNum, $locationId, $customerId, $repairId, 'repair_deposit',
                        $depositAmount, 0, 0, $depositAmount,
                        $depositMethod ?: 'other',
                        "Deposit for repair $recordNumber",
                        $user['id'],
                    ]
                );
                DB::insert(
                    'INSERT INTO sale_items (sale_id, item_name, quantity, unit_price, line_total)
                     VALUES (?,?,?,?,?)',
                    [$saleId, 'Repair Deposit — ' . $deviceBrand . ' ' . $deviceModel, 1, $depositAmount, $depositAmount]
                );
                // Link deposit sale back to repair
                DB::execute(
                    'UPDATE repairs SET deposit_sale_id=? WHERE id=?',
                    [$saleId, $repairId]
                );
            }

            // If converted from a booking, mark it completed and link the repair
            if ($fromBookingId) {
                DB::execute(
                    "UPDATE bookings SET status='completed', repair_id=? WHERE id=?",
                    [$repairId, $fromBookingId]
                );
            }

            // If converted from a walk-in inquiry, mark it converted and link the repair
            if ($fromInquiryId) {
                DB::execute(
                    "UPDATE walk_in_inquiries SET status='converted', repair_id=? WHERE id=?",
                    [$repairId, $fromInquiryId]
                );
            }

            // If converted from a maintenance record, mark it completed and link the repair
            if ($fromMaintenanceId) {
                DB::execute(
                    "UPDATE device_maintenance SET outcome='completed', repair_id=?, outcome_at=NOW() WHERE id=?",
                    [$repairId, $fromMaintenanceId]
                );
            }

            // Log initial status in history
            DB::execute(
                'INSERT INTO repair_status_history (repair_id, old_status, new_status, notes, changed_by)
                 VALUES (?,?,?,?,?)',
                [$repairId, null, 'received', 'Repair ticket created.', $user['id']]
            );

            // Audit log
            Audit::log('create', 'repairs', $repairId, null, null,
                "Ticket $recordNumber created — $deviceBrand $deviceModel");

            // Redirect to the repair detail page
            header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&created=1');
            exit;
        }
    }
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Phone lookup (AJAX)
if (isset($_GET['lookup_phone'])) {
    header('Content-Type: application/json');
    $p = normalizePhone($_GET['lookup_phone']);
    $c = DB::queryOne(
        'SELECT id, first_name, last_name, phone_primary AS phone, email FROM customers WHERE phone_normalized = ? LIMIT 1',
        [$p]
    );
    echo json_encode($c ?: null);
    exit;
}

$pageTitle = 'New Repair';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">New Repair</h1>
        <p class="page-sub">Fill in the customer and device details to open a repair ticket.</p>
    </div>
    <a href="<?= APP_URL ?>/modules/repairs/" class="btn btn-secondary">← Back to Repairs</a>
</div>

<?php if ($fromMaintenanceId && $fromMaint): ?>
<div class="alert" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;">
    🔧 <strong>Converting Maintenance Record</strong>
    — <?= htmlspecialchars($fromMaint['customer_name']) ?>
    · <?= htmlspecialchars($fromMaint['maintenance_type']) ?>
    · <?= htmlspecialchars($fromMaint['device_brand'] . ' ' . $fromMaint['device_model']) ?>
    <a href="<?= APP_URL ?>/modules/maintenance/index.php?status=sent" style="margin-left:auto;font-size:13px;color:#166534;">← Back to Maintenance</a>
</div>
<?php endif; ?>

<?php if ($fromBookingId && $bk): ?>
<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;">
    📅 <strong>Converting Booking #<?= str_pad($fromBookingId, 4, '0', STR_PAD_LEFT) ?></strong>
    — <?= htmlspecialchars($bk['customer_name']) ?> · <?= date('M j Y', strtotime($bk['booking_date'])) ?>
    · <?= ucfirst($bk['time_preference']) ?>
    <a href="<?= APP_URL ?>/modules/bookings/index.php" style="margin-left:auto;font-size:13px;color:#1e40af;">← Back to Bookings</a>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?>
        <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($training): ?>
<div class="alert alert-warning">⚠ Training Mode — this repair will NOT appear in live reports or financials.</div>
<?php endif; ?>

<style>
.svc-chip {
    display: inline-block;
    padding: .3rem .7rem;
    border-radius: 20px;
    border: 1.5px solid var(--border);
    background: var(--bg-2);
    color: var(--text);
    font-size: 13px;
    transition: all .15s;
    user-select: none;
}
label:hover .svc-chip       { border-color: var(--primary); color: var(--primary); }
label.svc-active .svc-chip,
.svc-chip.svc-active        { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }
</style>

<form method="POST" id="repairForm" novalidate>
    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
    <input type="hidden" name="customer_id" id="customer_id" value="<?= htmlspecialchars($prefill['customer_id'] ?? '') ?>">
    <input type="hidden" name="from_booking_id"     value="<?= $fromBookingId ?>">
    <input type="hidden" name="from_maintenance_id" value="<?= $fromMaintenanceId ?>">
    <input type="hidden" name="from_inquiry_id"     value="<?= $fromInquiryId ?>">

    <div class="form-grid">

        <!-- ── CUSTOMER ──────────────────────────────────── -->
        <div class="card" style="grid-column: 1 / -1;">
            <div class="card-header">
                <h2 class="card-title">Customer</h2>
            </div>
            <div class="card-body">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number <span class="required">*</span></label>
                        <div style="display:flex;gap:.5rem;">
                            <input type="tel" name="phone" id="phone_input" class="form-control"
                                   placeholder="(905) 555-1234"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? $prefill['phone'] ?? '') ?>"
                                   required>
                            <button type="button" class="btn btn-secondary" onclick="lookupPhone()">Look Up</button>
                        </div>
                        <div id="customer_found" class="form-hint" style="display:none;color:var(--success);"></div>
                        <div id="customer_new"   class="form-hint" style="display:none;color:var(--warning);">New customer — info will be saved.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" id="first_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['first_name'] ?? $prefill['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" id="last_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['last_name'] ?? $prefill['last_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="email" class="form-control"
                               value="<?= htmlspecialchars($_POST['email'] ?? $prefill['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="sms_opt_out" value="1" <?= !empty($_POST['sms_opt_out']) ? 'checked' : '' ?>>
                        <span>Do NOT send automatic SMS updates to this customer</span>
                    </label>
                </div>

            </div>
        </div>

        <!-- ── DEVICE ─────────────────────────────────────── -->
        <div class="card" style="grid-column: 1 / -1;">
            <div class="card-header">
                <h2 class="card-title">Device</h2>
            </div>
            <div class="card-body">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Device Type <span class="required">*</span></label>
                        <select name="device_type" id="device_type" class="form-control" required onchange="updateBrandList()">
                            <option value="">— Select Type —</option>
                            <option value="Cell Phone"      <?= ($_POST['device_type']??'')==='Cell Phone'      ?'selected':'' ?>>📱 Cell Phone</option>
                            <option value="Tablet"          <?= ($_POST['device_type']??'')==='Tablet'          ?'selected':'' ?>>📟 Tablet</option>
                            <option value="Laptop"          <?= ($_POST['device_type']??'')==='Laptop'          ?'selected':'' ?>>💻 Laptop</option>
                            <option value="Desktop"         <?= ($_POST['device_type']??'')==='Desktop'         ?'selected':'' ?>>🖥 Desktop</option>
                            <option value="Gaming Console"  <?= ($_POST['device_type']??'')==='Gaming Console'  ?'selected':'' ?>>🎮 Gaming Console</option>
                            <option value="Smartwatch"      <?= ($_POST['device_type']??'')==='Smartwatch'      ?'selected':'' ?>>⌚ Smartwatch</option>
                            <option value="Other"           <?= ($_POST['device_type']??'')==='Other'           ?'selected':'' ?>>Other</option>
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
                               list="model_suggestions"
                               value="<?= htmlspecialchars($_POST['device_model'] ?? '') ?>"
                               placeholder="e.g. iPhone 14 Pro" required>
                        <datalist id="model_suggestions"></datalist>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Color</label>
                        <input type="text" name="device_color" class="form-control"
                               value="<?= htmlspecialchars($_POST['device_color'] ?? '') ?>"
                               placeholder="e.g. Midnight Black">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Serial Number</label>
                        <input type="text" name="device_serial" class="form-control"
                               value="<?= htmlspecialchars($_POST['device_serial'] ?? '') ?>"
                               placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label class="form-label">IMEI</label>
                        <input type="text" name="device_imei" class="form-control"
                               value="<?= htmlspecialchars($_POST['device_imei'] ?? '') ?>"
                               placeholder="Optional — dial *#06#">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Passcode / PIN</label>
                        <input type="text" name="device_passcode" class="form-control"
                               value="<?= htmlspecialchars($_POST['device_passcode'] ?? '') ?>"
                               placeholder="Leave blank if none">
                        <div class="form-hint">Stored in plain text — visible to staff with access.</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Accessories Received</label>
                    <input type="text" name="accessories" class="form-control"
                           value="<?= htmlspecialchars($_POST['accessories'] ?? '') ?>"
                           placeholder="e.g. Charger, Case, AirPods — leave blank if none">
                </div>

            </div>
        </div>

        <!-- ── REPAIR DETAILS ─────────────────────────────── -->
        <div class="card" style="grid-column: 1 / -1;">
            <div class="card-header">
                <h2 class="card-title">Repair Details</h2>
            </div>
            <div class="card-body">

                <div class="form-group">
                    <label class="form-label">Service Type <span style="font-weight:400;color:var(--text-3);">(select all that apply)</span></label>
                    <?php
                    $serviceTypes = [
                        'Screen Replacement',
                        'Battery Replacement',
                        'Charging Port Repair',
                        'Camera Repair',
                        'Speaker Repair',
                        'Housing / Back Glass',
                        'Water Damage',
                        'Motherboard Repair',
                        'HDMI Port Repair',
                        'Deep Cleaning',
                        'Thermal Paste / Liquid Metal',
                        'No Power Diagnosis',
                        'Software / OS Install',
                        'Data Recovery',
                        'Other',
                    ];
                    // On POST, selected comes from array; on prefill from maintenance, it's a string
                    $selSvcs = [];
                    if (!empty($_POST['service_types'])) {
                        $selSvcs = (array)$_POST['service_types'];
                    } elseif (!empty($prefill['issue'])) {
                        // maintenance prefill sets issue; leave service blank
                    }
                    ?>
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.3rem;">
                    <?php foreach ($serviceTypes as $st):
                        $checked = in_array($st, $selSvcs, true);
                    ?>
                        <label style="cursor:pointer;">
                            <input type="checkbox" name="service_types[]" value="<?= htmlspecialchars($st) ?>"
                                   <?= $checked ? 'checked' : '' ?>
                                   style="display:none;"
                                   onchange="this.closest('label').classList.toggle('svc-active', this.checked)">
                            <span class="svc-chip <?= $checked ? 'svc-active' : '' ?>"><?= htmlspecialchars($st) ?></span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                    <div class="form-hint">Tap to select. Multiple services can be chosen for the same repair.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Issue / What needs fixing <span class="required">*</span></label>
                    <textarea name="issue_description" class="form-control" rows="3" required
                              placeholder="Describe the problem the customer reported..."><?= htmlspecialchars($_POST['issue_description'] ?? $prefill['issue'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Estimated Cost ($)</label>
                        <input type="number" name="estimated_cost" id="est_cost" class="form-control" step="0.01" min="0"
                               value="<?= htmlspecialchars($_POST['estimated_cost'] ?? '') ?>"
                               placeholder="0.00" oninput="updateBalance()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deposit Collected ($)</label>
                        <input type="number" name="deposit_amount" id="deposit_amount" class="form-control" step="0.01" min="0"
                               value="<?= htmlspecialchars($_POST['deposit_amount'] ?? '0') ?>"
                               placeholder="0.00" oninput="updateBalance()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deposit Method</label>
                        <select name="deposit_method" id="deposit_method" class="form-control">
                            <option value="">— N/A —</option>
                            <option value="cash"       <?= ($_POST['deposit_method']??'')==='cash'       ?'selected':'' ?>>Cash</option>
                            <option value="debit"      <?= ($_POST['deposit_method']??'')==='debit'      ?'selected':'' ?>>Debit</option>
                            <option value="credit"     <?= ($_POST['deposit_method']??'')==='credit'     ?'selected':'' ?>>Credit Card</option>
                            <option value="e_transfer" <?= ($_POST['deposit_method']??'')==='e_transfer' ?'selected':'' ?>>E-Transfer</option>
                            <option value="other"      <?= ($_POST['deposit_method']??'')==='other'      ?'selected':'' ?>>Other</option>
                        </select>
                    </div>
                    <div id="balance_preview" style="display:none;padding:.6rem 1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:14px;color:#166534;margin-bottom:.75rem;">
                        💰 Deposit: <strong id="bal_dep">$0.00</strong> &nbsp;·&nbsp;
                        Balance owing at pickup: <strong id="bal_due">$0.00</strong>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Estimated Ready</label>
                        <input type="datetime-local" name="estimated_ready_at" class="form-control"
                               value="<?= htmlspecialchars($_POST['estimated_ready_at'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Assign Technician</label>
                        <select name="assigned_to" class="form-control">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($techs as $t): ?>
                            <option value="<?= $t['id'] ?>"
                                <?= (($_POST['assigned_to'] ?? '') == $t['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['first_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Warranty — own row so it's always visible -->
                <div class="form-group" style="max-width:280px;">
                    <label class="form-label">Warranty</label>
                    <select name="warranty_days" class="form-control">
                        <option value="0"   <?= intval($_POST['warranty_days']??0)===0   ?'selected':'' ?>>No Warranty</option>
                        <option value="30"  <?= intval($_POST['warranty_days']??0)===30  ?'selected':'' ?>>30 Days</option>
                        <option value="60"  <?= intval($_POST['warranty_days']??0)===60  ?'selected':'' ?>>60 Days</option>
                        <option value="90"  <?= intval($_POST['warranty_days']??0)===90  ?'selected':'' ?>>90 Days</option>
                        <option value="180" <?= intval($_POST['warranty_days']??0)===180 ?'selected':'' ?>>6 Months</option>
                        <option value="365" <?= intval($_POST['warranty_days']??0)===365 ?'selected':'' ?>>1 Year</option>
                    </select>
                    <div class="form-hint">Printed on receipt. Use "No Warranty" for liquid damage, data recovery, etc.</div>
                </div>

                <?php if (Auth::isOwner()): ?>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control">
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"
                            <?= (($_POST['location_id'] ?? $prefill['location_id'] ?? $defaultLocationId) == $loc['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="location_id" value="<?= $defaultLocationId ?>">
                <?php endif; ?>

            </div>
        </div>

    </div><!-- /.form-grid -->

    <div class="form-actions">
        <a href="<?= APP_URL ?>/modules/repairs/" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg">Open Repair Ticket →</button>
    </div>

</form>

<script>
// ── Deposit balance preview ─────────────────────────────────
function updateBalance() {
    const est = parseFloat(document.getElementById('est_cost').value) || 0;
    const dep = parseFloat(document.getElementById('deposit_amount').value) || 0;
    const preview = document.getElementById('balance_preview');
    if (dep > 0) {
        const balance = Math.max(0, est - dep);
        document.getElementById('bal_dep').textContent = '$' + dep.toFixed(2);
        document.getElementById('bal_due').textContent = '$' + balance.toFixed(2);
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

// ── Phone lookup ────────────────────────────────────────────
function lookupPhone() {
    const raw = document.getElementById('phone_input').value.trim();
    if (!raw) return;
    fetch('?lookup_phone=' + encodeURIComponent(raw))
        .then(r => r.json())
        .then(c => {
            const found = document.getElementById('customer_found');
            const newEl = document.getElementById('customer_new');
            if (c) {
                document.getElementById('customer_id').value  = c.id;
                document.getElementById('first_name').value   = c.first_name;
                document.getElementById('last_name').value    = c.last_name  || '';
                document.getElementById('email').value        = c.email      || '';
                found.textContent = '✓ Existing customer found: ' + c.first_name + ' ' + (c.last_name || '');
                found.style.display = 'block';
                newEl.style.display = 'none';
            } else {
                document.getElementById('customer_id').value = '';
                found.style.display = 'none';
                newEl.style.display = 'block';
            }
        });
}

// Trigger lookup on Enter in phone field
document.getElementById('phone_input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); lookupPhone(); }
});

// ── Brand lists per device type ─────────────────────────────
const brandsByType = {
    'Cell Phone':     ['Apple','Samsung','Google','LG','Motorola','OnePlus','Nokia','Other'],
    'Tablet':         ['Apple','Samsung','Microsoft','Lenovo','Amazon','Other'],
    'Laptop':         ['Apple','Dell','HP','Lenovo','Asus','Acer','MSI','Microsoft','Toshiba','Other'],
    'Desktop':        ['Dell','HP','Lenovo','Asus','Apple','Custom Build','Other'],
    'Gaming Console': ['Sony (PlayStation)','Microsoft (Xbox)','Nintendo','Valve (Steam Deck)','Other'],
    'Smartwatch':     ['Apple','Samsung','Garmin','Fitbit','Other'],
    'Other':          ['Other'],
};

// ── Model suggestions per brand ─────────────────────────────
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
    const type      = document.getElementById('device_type').value;
    const brandSel  = document.getElementById('device_brand');
    const brands    = brandsByType[type] || ['Other'];

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
    document.getElementById('device_model').value = '';
    document.getElementById('device_model').focus();
}

// Restore brand list on page reload or pre-fill from booking
(function() {
    const type  = '<?= htmlspecialchars($_POST['device_type']  ?? $prefill['device_type']  ?? '') ?>';
    const brand = '<?= htmlspecialchars($_POST['device_brand'] ?? $prefill['device_brand'] ?? '') ?>';
    const model = '<?= htmlspecialchars($_POST['device_model'] ?? $prefill['device_model'] ?? '') ?>';
    if (type) {
        document.getElementById('device_type').value = type;
        updateBrandList();
        if (brand) {
            document.getElementById('device_brand').value = brand;
            updateModelList();
        }
        if (model) document.getElementById('device_model').value = model;
    }
    // Show existing customer banner if pre-filled from booking/inquiry
    <?php if (!empty($prefill['customer_id'])): ?>
    const found = document.getElementById('customer_found');
    <?php if ($fromInquiryId): ?>
    found.textContent = '✓ Linked from walk-in inquiry: <?= htmlspecialchars(($prefill['first_name'] ?? '') . ' ' . ($prefill['last_name'] ?? '')) ?>';
    <?php else: ?>
    found.textContent = '✓ Linked from booking: <?= htmlspecialchars(($prefill['first_name'] ?? '') . ' ' . ($prefill['last_name'] ?? '')) ?>';
    <?php endif; ?>
    found.style.display = 'block';
    <?php endif; ?>
})();
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
