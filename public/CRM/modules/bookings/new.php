<?php
// ============================================================
// Bookings — Staff creates a booking on behalf of a customer
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';

Auth::boot();
Auth::require();

// ── AJAX day view — must be checked BEFORE any HTML output ───
if (isset($_GET['ajax_day'])) {
    $ajaxDate  = $_GET['date']        ?? date('Y-m-d');
    $ajaxLocId = intval($_GET['location_id'] ?? 0);
    $ajaxRows  = DB::query(
        "SELECT b.appointment_time, b.customer_name, b.device_type, b.status
         FROM bookings b
         WHERE b.location_id=? AND b.booking_date=? AND b.status NOT IN ('cancelled')
         ORDER BY b.appointment_time ASC",
        [$ajaxLocId, $ajaxDate]
    );
    $html = '';
    if (empty($ajaxRows)) {
        $html = '<div class="empty-state" style="padding:1rem;"><div class="empty-icon" style="font-size:24px;">📭</div><p style="font-size:13px;">No bookings this day</p></div>';
    } else {
        foreach ($ajaxRows as $db) {
            $sc = ['confirmed'=>'var(--green)','pending'=>'var(--amber)','completed'=>'var(--text-3)'][$db['status']] ?? 'var(--text-2)';
            $t  = $db['appointment_time'] ? date('g:i A', strtotime($db['appointment_time'])) : '—';
            $html .= '<div style="display:flex;gap:.6rem;align-items:flex-start;padding:.5rem .4rem;border-bottom:1px solid var(--border);">';
            $html .= '<div style="font-weight:700;font-size:13px;color:var(--blue);min-width:58px;white-space:nowrap;">' . $t . '</div>';
            $html .= '<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . htmlspecialchars($db['customer_name']) . '</div>';
            $html .= '<div style="font-size:11px;color:var(--text-3);">' . htmlspecialchars($db['device_type']) . '</div></div>';
            $html .= '<span style="font-size:11px;color:' . $sc . ';font-weight:600;white-space:nowrap;">' . ucfirst($db['status']) . '</span></div>';
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['dateLabel' => date('D, M j', strtotime($ajaxDate)), 'count' => count($ajaxRows), 'html' => $html]);
    exit;
}

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── Helpers ─────────────────────────────────────────────────
function timeToMins(string $t): int {
    [$h, $m] = explode(':', $t . ':00');
    return (int)$h * 60 + (int)$m;
}
function minsToTime(int $m): string {
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

// Load store hours for a location
function getStoreHours(int $locId): array {
    $rows = DB::query(
        "SELECT setting_key, value FROM settings
         WHERE location_id=? AND setting_key IN ('store_open_time','store_close_time','store_open_days')",
        [$locId]
    );
    $h = [];
    foreach ($rows as $r) $h[$r['setting_key']] = $r['value'];
    return $h;
}

// Generate 30-min time slots between open and close
function getTimeSlots(array $hours): array {
    $open  = timeToMins($hours['store_open_time']  ?? '11:00');
    $close = timeToMins($hours['store_close_time'] ?? '21:00');
    $slots = [];
    for ($m = $open; $m < $close; $m += 30) {
        $slots[] = minsToTime($m);
    }
    return $slots;
}

// ── Customer lookup ──────────────────────────────────────────
$lookupPhone    = '';
$foundCustomer  = null;
$lookupAttempted = false;

if (isset($_GET['lookup_phone'])) {
    $lookupPhone     = preg_replace('/\D/', '', $_GET['lookup_phone']);
    if (strlen($lookupPhone) === 11 && $lookupPhone[0] === '1') $lookupPhone = substr($lookupPhone, 1);
    $lookupAttempted = true;
    if ($lookupPhone) {
        $foundCustomer = DB::queryOne(
            "SELECT * FROM customers WHERE phone_normalized=? LIMIT 1",
            [$lookupPhone]
        );
    }
}

// ── Defaults ─────────────────────────────────────────────────
$defaultLocId = $isOwner || $isManager
    ? intval($_GET['location_id'] ?? $user['location_id'])
    : (int)$user['location_id'];

$selectedDate = $_GET['booking_date'] ?? date('Y-m-d');

// ── Load day view for selected location + date ───────────────
$dayBookings = DB::query(
    "SELECT b.appointment_time, b.customer_name, b.device_type, b.status
     FROM bookings b
     WHERE b.location_id=? AND b.booking_date=? AND b.status NOT IN ('cancelled')
     ORDER BY b.appointment_time ASC",
    [$defaultLocId, $selectedDate]
);

// ── Store hours for selected location ────────────────────────
$storeHours = getStoreHours($defaultLocId);
$timeSlots  = getTimeSlots($storeHours);

// ── POST: create booking ─────────────────────────────────────
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking'])) {
    $locationId = intval($_POST['location_id'] ?? $defaultLocId);
    $custPhone  = preg_replace('/\D/', '', $_POST['customer_phone'] ?? '');
    if (strlen($custPhone) === 11 && $custPhone[0] === '1') $custPhone = substr($custPhone, 1);
    $custName   = trim($_POST['customer_name']  ?? '');
    $custEmail  = trim($_POST['customer_email'] ?? '');
    $deviceType = trim($_POST['device_type']    ?? '');
    $deviceBrand= trim($_POST['device_brand']   ?? '');
    $deviceModel= trim($_POST['device_model']   ?? '');
    $issueDesc  = trim($_POST['issue_description'] ?? '');
    $bookDate   = $_POST['booking_date']   ?? '';
    $bookTime   = $_POST['appointment_time'] ?? '';
    $notes      = trim($_POST['staff_notes'] ?? '');

    // Validate
    if (!$custPhone)  $errors[] = 'Customer phone is required.';
    if (!$custName)   $errors[] = 'Customer name is required.';
    if (!$deviceType) $errors[] = 'Device type is required.';
    if (!$issueDesc)  $errors[] = 'Issue description is required.';
    if (!$bookDate)   $errors[] = 'Booking date is required.';
    if (!$bookTime)   $errors[] = 'Appointment time is required.';

    if (empty($errors)) {
        // Find or note existing customer
        $customer = DB::queryOne("SELECT id FROM customers WHERE phone_normalized=? LIMIT 1", [$custPhone]);
        $customerId = $customer['id'] ?? null;

        // Insert booking
        $bookingId = DB::insert(
            "INSERT INTO bookings (location_id, customer_id, customer_name, customer_phone, customer_email,
                device_type, device_brand, device_model, issue_description,
                booking_date, time_preference, appointment_time,
                status, staff_notes, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                $locationId, $customerId, $custName, $custPhone, $custEmail ?: null,
                $deviceType, $deviceBrand ?: null, $deviceModel ?: null, $issueDesc,
                $bookDate,
                (date('H', strtotime($bookTime)) < 14) ? 'morning' : 'afternoon',
                $bookTime,
                'confirmed',   // Staff-created bookings are immediately confirmed
                $notes ?: null,
                $user['id'],
            ]
        );

        // Send SMS confirmation
        $loc = DB::queryOne("SELECT * FROM locations WHERE id=? LIMIT 1", [$locationId]);
        if ($loc && $custPhone && !Auth::isTraining()) {
            try {
                $firstName = explode(' ', trim($custName))[0];
                $dateStr   = date('l, M j', strtotime($bookDate));
                $timeStr   = date('g:i A', strtotime($bookTime));
                $locPhone  = !empty($loc['phone']) ? $loc['phone'] : formatPhone($loc['did'] ?? '');
                $did       = VoipMS::didForLocation($loc['code']);
                $smsText   = "Hi {$firstName}! Your repair appointment at C Tech Fix {$loc['name']} is confirmed for {$dateStr} at {$timeStr}. Questions? Call/text us at {$locPhone}.";
                VoipMS::sendSMS($did, $custPhone, $smsText);
                // Log SMS
                DB::execute(
                    "INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_at)
                     VALUES (?,?,?,?,?,?,?,NOW())",
                    [$locationId, $customerId, 'outbound', $did, $custPhone, $smsText, 'sent']
                );
            } catch (\Throwable $e) {
                // SMS failure is non-fatal
            }
        }

        header('Location: index.php?msg=' . urlencode('Booking created and customer notified by SMS.')); exit;
    }

    // Re-populate on error
    $defaultLocId = intval($_POST['location_id'] ?? $defaultLocId);
    $selectedDate = $_POST['booking_date'] ?? $selectedDate;
    $storeHours   = getStoreHours($defaultLocId);
    $timeSlots    = getTimeSlots($storeHours);
    $dayBookings  = DB::query(
        "SELECT b.appointment_time, b.customer_name, b.device_type, b.status
         FROM bookings b WHERE b.location_id=? AND b.booking_date=? AND b.status NOT IN ('cancelled')
         ORDER BY b.appointment_time ASC",
        [$defaultLocId, $selectedDate]
    );
}

// Prefill values (from POST, lookup, or GET)
$pre = [
    'location_id'       => $defaultLocId,
    'customer_phone'    => $_POST['customer_phone']    ?? ($foundCustomer ? formatPhone($lookupPhone) : ($lookupAttempted ? formatPhone($lookupPhone) : '')),
    'customer_name'     => $_POST['customer_name']     ?? ($foundCustomer ? trim(($foundCustomer['first_name'] ?? '') . ' ' . ($foundCustomer['last_name'] ?? '')) : ''),
    'customer_email'    => $_POST['customer_email']    ?? ($foundCustomer['email'] ?? ''),
    'device_type'       => $_POST['device_type']       ?? '',
    'device_brand'      => $_POST['device_brand']      ?? '',
    'device_model'      => $_POST['device_model']      ?? '',
    'issue_description' => $_POST['issue_description'] ?? '',
    'booking_date'      => $_POST['booking_date']      ?? $selectedDate,
    'appointment_time'  => $_POST['appointment_time']  ?? '',
    'staff_notes'       => $_POST['staff_notes']       ?? '',
];

$pageTitle = 'New Booking';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📅 New Booking</h1>
        <p class="page-sub">Book a repair appointment on behalf of a customer</p>
    </div>
    <a href="index.php" class="btn btn-secondary">← Back to Bookings</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div>⚠ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="repair-grid">

<!-- ── LEFT: Booking Form ──────────────────────────────────── -->
<div class="repair-col-main">

    <!-- Customer Lookup -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h2 class="card-title">🔍 Customer Lookup</h2></div>
        <div class="card-body">
            <form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="booking_date"  value="<?= htmlspecialchars($pre['booking_date']) ?>">
                <input type="hidden" name="location_id"   value="<?= $defaultLocId ?>">
                <div class="form-group" style="margin:0;flex:1;min-width:200px;">
                    <label class="form-label">Search by Phone Number</label>
                    <input type="tel" name="lookup_phone" class="form-control"
                           value="<?= htmlspecialchars($_GET['lookup_phone'] ?? '') ?>"
                           placeholder="(905) 555-1234">
                </div>
                <button type="submit" class="btn btn-secondary">Look Up</button>
            </form>
            <?php if ($lookupAttempted): ?>
                <?php if ($foundCustomer): ?>
                <div style="margin-top:.75rem;padding:.6rem .9rem;background:rgba(34,197,94,.08);border:1px solid var(--green);border-radius:8px;font-size:13px;">
                    ✅ <strong><?= htmlspecialchars($foundCustomer['first_name'] . ' ' . $foundCustomer['last_name']) ?></strong>
                    — <?= htmlspecialchars(formatPhone($foundCustomer['phone_normalized'])) ?>
                    <?php if ($foundCustomer['email']): ?> · <?= htmlspecialchars($foundCustomer['email']) ?><?php endif; ?>
                    <span style="color:var(--green);margin-left:.5rem;">Existing customer — info pre-filled below ↓</span>
                </div>
                <?php else: ?>
                <div style="margin-top:.75rem;padding:.5rem .9rem;background:rgba(245,158,11,.08);border:1px solid var(--amber);border-radius:8px;font-size:13px;">
                    ⚠ No existing customer found for that number — fill in details manually below.
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Booking Form -->
    <form method="POST">
        <input type="hidden" name="create_booking" value="1">

        <!-- Location (owners/managers can choose) -->
        <?php if ($isOwner || $isManager): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">📍 Location</h2></div>
            <div class="card-body">
                <select name="location_id" class="form-control"
                        onchange="window.location='new.php?booking_date=<?= $pre['booking_date'] ?>&location_id='+this.value">
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= $pre['location_id']==$loc['id']?'selected':'' ?>>
                        <?= htmlspecialchars($loc['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="location_id" value="<?= $defaultLocId ?>">
        <?php endif; ?>

        <!-- Customer Info -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">👤 Customer Info</h2></div>
            <div class="card-body">
                <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Full Name <span style="color:var(--red);">*</span></label>
                        <input type="text" name="customer_name" class="form-control"
                               value="<?= htmlspecialchars($pre['customer_name']) ?>"
                               placeholder="Jane Smith" required>
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Phone <span style="color:var(--red);">*</span></label>
                        <input type="tel" name="customer_phone" class="form-control"
                               value="<?= htmlspecialchars($pre['customer_phone']) ?>"
                               placeholder="(905) 555-1234" required>
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Email</label>
                        <input type="email" name="customer_email" class="form-control"
                               value="<?= htmlspecialchars($pre['customer_email']) ?>"
                               placeholder="jane@email.com">
                    </div>
                </div>
            </div>
        </div>

        <!-- Device & Issue -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">📱 Device & Issue</h2></div>
            <div class="card-body">
                <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:160px;">
                        <label class="form-label">Device Type <span style="color:var(--red);">*</span></label>
                        <select name="device_type" class="form-control" required>
                            <option value="">— Select —</option>
                            <?php foreach (['Phone','Tablet','Laptop','Desktop','Gaming Console','Smart Watch','Other'] as $dt): ?>
                            <option value="<?= $dt ?>" <?= $pre['device_type']===$dt?'selected':'' ?>><?= $dt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px;">
                        <label class="form-label">Brand</label>
                        <input type="text" name="device_brand" class="form-control"
                               value="<?= htmlspecialchars($pre['device_brand']) ?>"
                               placeholder="Apple, Samsung…">
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px;">
                        <label class="form-label">Model</label>
                        <input type="text" name="device_model" class="form-control"
                               value="<?= htmlspecialchars($pre['device_model']) ?>"
                               placeholder="iPhone 14, Galaxy S23…">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Issue Description <span style="color:var(--red);">*</span></label>
                    <textarea name="issue_description" class="form-control" rows="3"
                              placeholder="Describe the problem the customer is experiencing…"
                              required><?= htmlspecialchars($pre['issue_description']) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Date & Time -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">🕐 Date & Time</h2></div>
            <div class="card-body">
                <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Date <span style="color:var(--red);">*</span></label>
                        <input type="date" name="booking_date" id="bookingDate" class="form-control"
                               value="<?= htmlspecialchars($pre['booking_date']) ?>"
                               min="<?= date('Y-m-d') ?>" required
                               onchange="refreshDayView(this.value)">
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Time <span style="color:var(--red);">*</span></label>
                        <select name="appointment_time" class="form-control" required>
                            <option value="">— Select time —</option>
                            <?php foreach ($timeSlots as $slot): ?>
                            <?php $label = date('g:i A', strtotime($slot)); ?>
                            <option value="<?= $slot ?>" <?= $pre['appointment_time']===$slot?'selected':'' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">
                            Store hours: <?= date('g:i A', strtotime($storeHours['store_open_time'] ?? '11:00')) ?>
                            – <?= date('g:i A', strtotime($storeHours['store_close_time'] ?? '21:00')) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Staff Notes -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">📝 Staff Notes</h2></div>
            <div class="card-body">
                <textarea name="staff_notes" class="form-control" rows="2"
                          placeholder="Internal notes (not sent to customer)…"><?= htmlspecialchars($pre['staff_notes']) ?></textarea>
            </div>
        </div>

        <div style="display:flex;gap:.5rem;align-items:center;">
            <button type="submit" class="btn btn-primary" style="padding:.6rem 2rem;">
                📅 Create Booking & Notify Customer
            </button>
            <a href="index.php" class="btn btn-ghost">Cancel</a>
            <span class="text-muted small" style="margin-left:.5rem;">
                💬 A confirmation SMS will be sent automatically
            </span>
        </div>
    </form>
</div>

<!-- ── RIGHT: Day View ─────────────────────────────────────── -->
<div class="repair-col-side">
    <div class="card" style="position:sticky;top:1rem;">
        <div class="card-header">
            <h2 class="card-title">📆 <span id="dayViewTitle"><?= date('D, M j', strtotime($selectedDate)) ?></span></h2>
            <span class="text-muted small" id="dayViewCount"><?= count($dayBookings) ?> booking<?= count($dayBookings)!==1?'s':'' ?></span>
        </div>
        <div id="dayViewBody" class="card-body" style="padding:.5rem;">
            <?php if (empty($dayBookings)): ?>
            <div class="empty-state" style="padding:1rem;">
                <div class="empty-icon" style="font-size:24px;">📭</div>
                <p style="font-size:13px;">No bookings this day</p>
            </div>
            <?php else: ?>
            <?php foreach ($dayBookings as $db):
                $statusColor = ['confirmed'=>'var(--green)','pending'=>'var(--amber)','completed'=>'var(--text-3)'][$db['status']] ?? 'var(--text-2)';
            ?>
            <div style="display:flex;gap:.6rem;align-items:flex-start;padding:.5rem .4rem;border-bottom:1px solid var(--border);">
                <div style="font-weight:700;font-size:13px;color:var(--blue);min-width:58px;white-space:nowrap;">
                    <?= $db['appointment_time'] ? date('g:i A', strtotime($db['appointment_time'])) : '—' ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($db['customer_name']) ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-3);"><?= htmlspecialchars($db['device_type']) ?></div>
                </div>
                <span style="font-size:11px;color:<?= $statusColor ?>;font-weight:600;white-space:nowrap;">
                    <?= ucfirst($db['status']) ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- end repair-grid -->

<script>
// When staff changes the date, reload the day view via AJAX
function refreshDayView(date) {
    const locId = <?= $defaultLocId ?>;
    fetch('new.php?ajax_day=1&date=' + date + '&location_id=' + locId)
        .then(r => r.json())
        .then(data => {
            document.getElementById('dayViewTitle').textContent = data.dateLabel;
            document.getElementById('dayViewCount').textContent = data.count + (data.count === 1 ? ' booking' : ' bookings');
            document.getElementById('dayViewBody').innerHTML = data.html;
        })
        .catch(() => {});
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
