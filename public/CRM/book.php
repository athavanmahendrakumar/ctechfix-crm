<?php
// ============================================================
// Public Customer Booking Page
// No login required
// ============================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$rootPath = dirname(__DIR__);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/helpers.php';

// Load active locations with their hours
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// Load hours per location
$locHours = [];
foreach ($locations as $loc) {
    $lid = (int)$loc['id'];
    $rows = DB::query(
        "SELECT setting_key, value FROM settings WHERE location_id=? AND setting_key IN ('store_open_time','store_close_time','store_open_days','store_booking_buffer','location_address','location_phone')",
        [$lid]
    );
    $s = [];
    foreach ($rows as $r) $s[$r['setting_key']] = $r['value'];
    $locHours[$lid] = $s;
}

// Load global business name
$globalRows = DB::query("SELECT setting_key, value FROM settings WHERE location_id IS NULL", []);
$global = [];
foreach ($globalRows as $r) $global[$r['setting_key']] = $r['value'];
$businessName = $global['business_name'] ?? 'C Tech Fix';

// Slot definitions — Morning and Afternoon boundaries (24h)
// Morning:   booking_open (open+buffer) → 14:00
// Afternoon: 14:00 → booking_close (close-buffer)
// Both require current_time + 2hrs < slot_end for same-day availability

function getSlotBoundaries(array $hours): array {
    $buffer    = intval($hours['store_booking_buffer'] ?? 30); // minutes
    $openMins  = timeToMins($hours['store_open_time']  ?? '11:00') + $buffer;
    $closeMins = timeToMins($hours['store_close_time'] ?? '21:30') - $buffer;
    $midMins   = 14 * 60; // 2:00 PM divides morning / afternoon
    return [
        'booking_open'  => $openMins,   // e.g. 11:30 = 690
        'booking_close' => $closeMins,  // e.g. 21:00 = 1260
        'morning_end'   => $midMins,    // 840
        'afternoon_start' => $midMins,
    ];
}

function timeToMins(string $t): int {
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

function minsToTime(int $m): string {
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

// Returns: ['dates' => [...], 'unavailable_slots' => ['Y-m-d' => ['morning'|'afternoon']]]
function getAvailableDates(array $hours, int $numDays = 3): array {
    $openDays  = array_map('intval', explode(',', $hours['store_open_days'] ?? '1,2,3,4,5,6'));
    $bounds    = getSlotBoundaries($hours);
    $nowMins   = (int)date('G') * 60 + (int)date('i');
    $twoHrMins = $nowMins + 120; // now + 2 hours

    $dates           = [];
    $unavailableSlots = [];
    $check           = new DateTime('today');
    $limit           = 60;

    while (count($dates) < $numDays && $limit-- > 0) {
        $isToday = $check->format('Y-m-d') === date('Y-m-d');
        $dow     = (int)$check->format('N');

        if (in_array($dow, $openDays)) {
            $blocked = [];

            if ($isToday) {
                // Morning available if now+2hrs < morning_end AND booking_open < morning_end
                if ($twoHrMins >= $bounds['morning_end'] || $bounds['booking_open'] >= $bounds['morning_end']) {
                    $blocked[] = 'morning';
                }
                // Afternoon available if now+2hrs < booking_close AND afternoon_start < booking_close
                if ($twoHrMins >= $bounds['booking_close'] || $bounds['afternoon_start'] >= $bounds['booking_close']) {
                    $blocked[] = 'afternoon';
                }
                // Only add today if at least one slot is still open
                if (count($blocked) < 2) {
                    $dates[] = $check->format('Y-m-d');
                    if ($blocked) $unavailableSlots[$check->format('Y-m-d')] = $blocked;
                }
            } else {
                $dates[] = $check->format('Y-m-d');
                // Future dates: both slots available (store hours apply, but no time restriction)
            }
        }
        $check->modify('+1 day');
    }

    return ['dates' => $dates, 'unavailable_slots' => $unavailableSlots];
}

$submitted = false;
$errors    = [];
$booking   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locId       = intval($_POST['location_id'] ?? 0);
    $name        = trim($_POST['customer_name'] ?? '');
    $phone       = preg_replace('/\D/', '', trim($_POST['customer_phone'] ?? ''));
    $email       = trim($_POST['customer_email'] ?? '');
    $deviceType  = trim($_POST['device_type'] ?? '');
    $deviceBrand = trim($_POST['device_brand'] ?? '');
    $deviceModel = trim($_POST['device_model'] ?? '');
    $issue       = trim($_POST['issue_description'] ?? '');
    $bookDate    = trim($_POST['booking_date'] ?? '');
    $timePref    = in_array($_POST['time_preference'] ?? '', ['morning','afternoon']) ? $_POST['time_preference'] : 'morning';

    // Validate
    if (!$locId)         $errors[] = 'Please select a location.';
    if (!$name)          $errors[] = 'Please enter your name.';
    if (strlen($phone) < 10) $errors[] = 'Please enter a valid phone number.';
    if (!$deviceType)    $errors[] = 'Please select a device type.';
    if (!$issue)         $errors[] = 'Please describe what you need.';
    if (!$bookDate)      $errors[] = 'Please select a date.';

    // Validate date and time slot are actually available for that location
    if (!$errors && $locId && isset($locHours[$locId])) {
        $result    = getAvailableDates($locHours[$locId]);
        $available = $result['dates'];
        $blocked   = $result['unavailable_slots'];
        if (!in_array($bookDate, $available)) {
            $errors[] = 'Selected date is not available. Please choose from the available dates.';
        } elseif (isset($blocked[$bookDate]) && in_array($timePref, $blocked[$bookDate])) {
            $errors[] = 'That time slot is no longer available today. Please choose Afternoon or a future date.';
        }
    }
    // Server-side 2-hour check for same-day
    if (!$errors && $bookDate === date('Y-m-d') && $locId && isset($locHours[$locId])) {
        $bounds  = getSlotBoundaries($locHours[$locId]);
        $nowMins = (int)date('G') * 60 + (int)date('i');
        $slotEnd = $timePref === 'morning' ? $bounds['morning_end'] : $bounds['booking_close'];
        if ($nowMins + 120 >= $slotEnd) {
            $errors[] = 'This time slot requires at least 2 hours notice. Please choose a later slot or a future date.';
        }
    }

    if (!$errors) {
        try {
            // Normalize phone
            if (strlen($phone) === 11 && $phone[0] === '1') $phone = substr($phone, 1);

            // ── Auto-create or match customer ─────────────────────
            $phoneNorm  = $phone;
            $customerId = null;
            $existing   = DB::queryOne("SELECT id FROM customers WHERE phone_normalized=? LIMIT 1", [$phoneNorm]);
            if ($existing) {
                $customerId = $existing['id'];
                // Update email if we now have one and didn't before
                if ($email) DB::execute("UPDATE customers SET email=? WHERE id=? AND (email IS NULL OR email='')", [$email, $customerId]);
            } else {
                // Split name into first / last
                $nameParts = explode(' ', trim($name), 2);
                $firstName = $nameParts[0];
                $lastName  = $nameParts[1] ?? '';
                $customerId = DB::insert(
                    "INSERT INTO customers (first_name, last_name, phone_primary, phone_normalized, email, created_at)
                     VALUES (?,?,?,?,?,NOW())",
                    [$firstName, $lastName, $phone, $phoneNorm, $email ?: null]
                );
            }

            // ── Save booking ──────────────────────────────────────
            $bookingId = DB::insert(
                "INSERT INTO bookings (location_id, customer_id, customer_name, customer_phone, customer_email, device_type, device_brand, device_model, issue_description, booking_date, time_preference, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')",
                [$locId, $customerId, $name, $phone, $email ?: null, $deviceType, $deviceBrand ?: null, $deviceModel ?: null, $issue, $bookDate, $timePref]
            );

            // ── Auto-SMS to customer ──────────────────────────────
            $smsError = null;
            try {
                require_once dirname(__DIR__) . '/core/VoipMS.php';
                $loc = null;
                foreach ($locations as $l) { if ((int)$l['id'] === (int)$locId) { $loc = $l; break; } }
                if (!$loc)      throw new \Exception("Location ID {$locId} not found");
                if (!$bookingId) throw new \Exception("No booking ID");

                $did = preg_replace('/\D/', '', $loc['did'] ?? '');
                if (!$did) throw new \Exception("No DID set for location: " . $loc['name']);
                if (!$phone) throw new \Exception("No customer phone number");

                // Build slot label from stored hours (avoid strtotime on HH:MM alone)
                $buffer   = intval($locHours[$locId]['store_booking_buffer'] ?? 30);
                $openMins = timeToMins($locHours[$locId]['store_open_time']  ?? '11:00') + $buffer;
                $closeMins= timeToMins($locHours[$locId]['store_close_time'] ?? '21:30') - $buffer;
                $openBuf  = minsToTime($openMins);
                $closeBuf = minsToTime($closeMins);
                $slotLabels = [
                    'morning'   => $openBuf . ' - 2:00pm',
                    'afternoon' => '2:00pm - ' . $closeBuf,
                ];
                $tLabel    = ucfirst($timePref) . ' (' . ($slotLabels[$timePref] ?? '') . ')';
                $dDate     = date('D, M j Y', strtotime($bookDate));
                $ref       = str_pad($bookingId, 4, '0', STR_PAD_LEFT);
                $firstName = explode(' ', $name)[0];

                // Keep under 160 chars for single SMS
                $message = "Hi {$firstName}! C Tech Fix {$loc['name']} got your booking."
                         . " {$dDate}, {$tLabel}. Ref #{$ref}."
                         . " We'll contact you to confirm soon!";
                $message = mb_substr($message, 0, 159);

                $voip   = new VoipMS();
                $result = $voip->sendSMS($did, $phone, $message);
                if ($result === true) {
                    DB::execute("UPDATE bookings SET sms_sent=1 WHERE id=?", [$bookingId]);
                } else {
                    throw new \Exception("VoipMS error: " . $result);
                }
            } catch (\Throwable $e) {
                $smsError = $e->getMessage();
                // Log failure to bookings table so staff can see it
                if ($bookingId) DB::execute("UPDATE bookings SET staff_notes=CONCAT(IFNULL(staff_notes,''), ?) WHERE id=?",
                    ["\n[Auto-SMS failed: {$smsError}]", $bookingId]);
            }

            $submitted = true;
            $booking   = ['id' => $bookingId, 'name' => $name, 'date' => $bookDate, 'time_preference' => $timePref];

        } catch (\Throwable $e) {
            $errors[] = 'Something went wrong: ' . $e->getMessage();
        }
    }
}

// Pre-load dates + unavailable slots per location for JS
$availableByLoc    = [];
$unavailableByLoc  = [];
$slotLabelsByLoc   = [];
foreach ($locations as $loc) {
    $lid    = (int)$loc['id'];
    $result = getAvailableDates($locHours[$lid]);
    $bounds = getSlotBoundaries($locHours[$lid]);
    $availableByLoc[$lid]   = $result['dates'];
    $unavailableByLoc[$lid] = $result['unavailable_slots'];
    // Human-readable slot times for this location
    $openStr  = date('g:ia', mktime(0, $bounds['booking_open'],  0));
    $closeStr = date('g:ia', mktime(0, $bounds['booking_close'], 0));
    $slotLabelsByLoc[$lid] = [
        'morning'   => $openStr . ' – 2:00pm',
        'afternoon' => '2:00pm – ' . $closeStr,
    ];
}

$DEVICE_TYPES = ['Phone', 'Tablet', 'Laptop', 'Computer', 'Gaming Console', 'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book an Appointment — <?= htmlspecialchars($businessName) ?> | Device Service</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --blue:    #2563eb;
            --green:   #16a34a;
            --red:     #dc2626;
            --amber:   #d97706;
            --text:    #111827;
            --text-2:  #374151;
            --text-3:  #6b7280;
            --border:  #e5e7eb;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --radius:  12px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f3f4f6;
            color: var(--text);
            min-height: 100vh;
        }
        .header {
            background: var(--blue);
            color: #fff;
            padding: 1.25rem 1rem;
            text-align: center;
        }
        .header h1 { font-size: 1.4rem; font-weight: 700; }
        .header p  { font-size: .9rem; opacity: .85; margin-top: .25rem; }
        .container { max-width: 560px; margin: 2rem auto; padding: 0 1rem 3rem; }
        .card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .card-body { padding: 1.5rem; }
        .form-group { margin-bottom: 1.1rem; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-2); margin-bottom: .35rem; }
        .form-label span { color: var(--red); }
        .form-control {
            width: 100%; padding: .6rem .75rem;
            border: 1px solid var(--border); border-radius: 8px;
            font-size: 15px; color: var(--text);
            background: var(--surface);
            transition: border-color .15s;
        }
        .form-control:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        .form-hint { font-size: 12px; color: var(--text-3); margin-top: .3rem; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: .75rem 1.5rem; border-radius: 8px; font-size: 15px;
            font-weight: 600; border: none; cursor: pointer; text-decoration: none;
            transition: opacity .15s;
        }
        .btn:hover { opacity: .9; }
        .btn-primary { background: var(--blue); color: #fff; width: 100%; }
        .error-list {
            background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px;
            padding: 1rem; margin-bottom: 1.25rem;
        }
        .error-list p { color: var(--red); font-size: 13px; margin-bottom: .25rem; }
        .date-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-top: .35rem; }
        .date-btn {
            border: 2px solid var(--border); border-radius: 8px; padding: .6rem .3rem;
            text-align: center; cursor: pointer; background: var(--surface);
            transition: all .15s; font-size: 13px; font-weight: 600; color: var(--text-2);
        }
        .date-btn:hover  { border-color: var(--blue); color: var(--blue); }
        .date-btn.selected { border-color: var(--blue); background: var(--blue); color: #fff; }
        .date-btn .day  { font-size: 11px; font-weight: 400; opacity: .8; }
        .time-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-top: .35rem; }
        .time-btn {
            border: 2px solid var(--border); border-radius: 8px; padding: .75rem;
            text-align: center; cursor: pointer; background: var(--surface);
            transition: all .15s; font-weight: 600; font-size: 13px; color: var(--text-2);
        }
        .time-btn:hover { border-color: var(--blue); color: var(--blue); }
        .time-btn.selected { border-color: var(--blue); background: var(--blue); color: #fff; }
        .time-btn .sub { font-size: 11px; font-weight: 400; opacity: .8; margin-top: 2px; }
        .loc-card {
            border: 2px solid var(--border); border-radius: 10px; padding: .85rem 1rem;
            cursor: pointer; transition: all .15s; margin-bottom: .5rem;
        }
        .loc-card:hover { border-color: var(--blue); }
        .loc-card.selected { border-color: var(--blue); background: #eff6ff; }
        .loc-card h3 { font-size: 15px; font-weight: 700; }
        .loc-card p  { font-size: 12px; color: var(--text-3); margin-top: .2rem; }
        .success-box {
            text-align: center; padding: 2rem 1.5rem;
        }
        .success-icon { font-size: 3rem; margin-bottom: 1rem; }
        .success-box h2 { font-size: 1.3rem; font-weight: 700; color: var(--green); margin-bottom: .5rem; }
        .success-box p  { color: var(--text-2); font-size: 14px; line-height: 1.6; }
        .ref-box {
            background: var(--surface-2); border-radius: 8px; padding: .75rem 1rem;
            margin: 1rem 0; font-size: 13px; color: var(--text-2);
        }
        .ref-box strong { color: var(--text); }
        .section-title { font-size: 13px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .75rem; }
        .divider { border: none; border-top: 1px solid var(--border); margin: 1.25rem 0; }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .75rem center; padding-right: 2rem; }
    </style>
</head>
<body>

<div class="header">
    <h1>📅 Book an Appointment</h1>
    <p><?= htmlspecialchars($businessName) ?></p>
</div>

<div class="container">

<?php if ($submitted): ?>
<!-- ── SUCCESS ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-body success-box">
        <div class="success-icon">✅</div>
        <h2>Request Received!</h2>
        <p>Thanks <?= htmlspecialchars($booking['name']) ?>! We've received your appointment request and sent you a confirmation text.</p>
        <div class="ref-box">
            <strong>Ref #<?= str_pad($booking['id'], 4, '0', STR_PAD_LEFT) ?></strong> &nbsp;·&nbsp;
            <?= date('D, M j Y', strtotime($booking['date'])) ?> &nbsp;·&nbsp;
            <?= $booking['time_preference'] === 'morning' ? 'Morning (11am–2pm)' : 'Afternoon (2pm–9pm)' ?>
        </div>
        <p>A staff member will <strong>call or text you shortly</strong> to confirm your appointment.</p>
        <br>
        <a href="/book.php" class="btn btn-primary" style="max-width:260px;">Book Another Appointment</a>
    </div>
</div>

<?php else: ?>
<!-- ── FORM ──────────────────────────────────────────────── -->

<?php if ($errors): ?>
<div class="error-list">
    <?php foreach ($errors as $e): ?><p>⚠ <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" id="booking-form">

    <!-- Step 1: Location -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <div class="section-title">1 · Select Location</div>
            <?php foreach ($locations as $loc):
                $lid  = $loc['id'];
                $ls   = $locHours[$lid] ?? [];
                $days = $ls['store_open_days'] ?? '1,2,3,4,5,6';
                $open = $ls['store_open_time']  ?? '11:00';
                $close= $ls['store_close_time'] ?? '21:30';
                $addr = $ls['location_address'] ?? '';
                $dayNames = ['1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat','7'=>'Sun'];
                $dayLabels = implode('–', array_filter(array_map(fn($d) => $dayNames[trim($d)] ?? '', explode(',', $days))));
                $openFmt  = date('g:ia', strtotime($open));
                $closeFmt = date('g:ia', strtotime($close));
                $selected = (int)($_POST['location_id'] ?? 0) === (int)$lid;
            ?>
            <div class="loc-card <?= $selected ? 'selected' : '' ?>"
                 onclick="selectLocation(<?= $lid ?>)"
                 id="loc-card-<?= $lid ?>">
                <h3>📍 <?= htmlspecialchars($loc['name']) ?></h3>
                <p><?= htmlspecialchars($addr) ?></p>
                <p style="margin-top:.3rem;">🕐 <?= $dayLabels ?> · <?= $openFmt ?> – <?= $closeFmt ?></p>
            </div>
            <?php endforeach; ?>
            <input type="hidden" name="location_id" id="location_id" value="<?= intval($_POST['location_id'] ?? 0) ?>">
        </div>
    </div>

    <!-- Step 2: Date -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <div class="section-title">2 · Choose a Date</div>
            <div class="date-grid" id="date-grid">
                <div style="color:var(--text-3);font-size:13px;grid-column:1/-1;">Select a location first to see available dates.</div>
            </div>
            <input type="hidden" name="booking_date" id="booking_date" value="<?= htmlspecialchars($_POST['booking_date'] ?? '') ?>">

            <hr class="divider">

            <div class="section-title">Preferred Time</div>
            <div class="time-grid">
                <div class="time-btn <?= ($_POST['time_preference'] ?? 'morning')==='morning'?'selected':'' ?>"
                     onclick="selectTime('morning')" id="time-morning">
                    🌤 Morning
                    <div class="sub" id="morning-sub">11:30am – 2:00pm</div>
                </div>
                <div class="time-btn <?= ($_POST['time_preference'] ?? '')==='afternoon'?'selected':'' ?>"
                     onclick="selectTime('afternoon')" id="time-afternoon">
                    ☀️ Afternoon
                    <div class="sub" id="afternoon-sub">2:00pm – 9:00pm</div>
                </div>
            </div>
            <input type="hidden" name="time_preference" id="time_preference" value="<?= htmlspecialchars($_POST['time_preference'] ?? 'morning') ?>">
        </div>
    </div>

    <!-- Step 3: Device -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <div class="section-title">3 · Your Device</div>
            <div class="form-group">
                <label class="form-label">Device Type <span>*</span></label>
                <select name="device_type" class="form-control" required>
                    <option value="">— Select device —</option>
                    <?php foreach ($DEVICE_TYPES as $dt): ?>
                    <option value="<?= $dt ?>" <?= ($_POST['device_type'] ?? '')===$dt?'selected':'' ?>><?= $dt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div class="form-group">
                    <label class="form-label">Brand</label>
                    <input type="text" name="device_brand" class="form-control"
                           value="<?= htmlspecialchars($_POST['device_brand'] ?? '') ?>"
                           placeholder="e.g. Apple, Samsung">
                </div>
                <div class="form-group">
                    <label class="form-label">Model</label>
                    <input type="text" name="device_model" class="form-control"
                           value="<?= htmlspecialchars($_POST['device_model'] ?? '') ?>"
                           placeholder="e.g. iPhone 14 Pro">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">How can we help? <span>*</span></label>
                <textarea name="issue_description" class="form-control" rows="3" required
                    placeholder="e.g. Cracked screen, battery not holding charge, charging port issue, water damage..."><?= htmlspecialchars($_POST['issue_description'] ?? '') ?></textarea>
                <div class="form-hint">The more detail you provide, the better we can prepare for your visit.</div>
            </div>
        </div>
    </div>

    <!-- Step 4: Contact -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-body">
            <div class="section-title">4 · Your Contact Info</div>
            <div class="form-group">
                <label class="form-label">Full Name <span>*</span></label>
                <input type="text" name="customer_name" class="form-control" required
                       value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>"
                       placeholder="Your name">
            </div>
            <div class="form-group">
                <label class="form-label">Phone Number <span>*</span></label>
                <input type="tel" name="customer_phone" class="form-control" required
                       value="<?= htmlspecialchars($_POST['customer_phone'] ?? '') ?>"
                       placeholder="(905) 555-1234">
                <div class="form-hint">We'll call or text this number to confirm.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Email <span style="font-weight:400;color:var(--text-3);">(optional)</span></label>
                <input type="email" name="customer_email" class="form-control"
                       value="<?= htmlspecialchars($_POST['customer_email'] ?? '') ?>"
                       placeholder="you@email.com">
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">📅 Request Appointment →</button>
    <p style="text-align:center;font-size:12px;color:var(--text-3);margin-top:.75rem;">
        A staff member will contact you to confirm within business hours.
    </p>

</form>
<?php endif; ?>

</div>

<script>
const availableDates   = <?= json_encode($availableByLoc) ?>;
const unavailableSlots = <?= json_encode($unavailableByLoc) ?>;
const slotLabels       = <?= json_encode($slotLabelsByLoc) ?>;
const selectedDate     = <?= json_encode($_POST['booking_date'] ?? '') ?>;
const selectedLoc      = <?= json_encode(intval($_POST['location_id'] ?? 0)) ?>;

const DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const TODAY  = new Date().toISOString().split('T')[0];

let currentLocId = selectedLoc || 0;

function selectLocation(locId) {
    currentLocId = locId;
    document.getElementById('location_id').value = locId;
    document.querySelectorAll('.loc-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('loc-card-' + locId).classList.add('selected');
    // Update slot labels for this location
    updateSlotLabels(locId);
    renderDates(locId);
    // Reset date & time selection
    document.getElementById('booking_date').value = '';
    updateTimeSlots(locId, null);
}

function updateSlotLabels(locId) {
    const labels = slotLabels[locId] || { morning: '11:30am – 2:00pm', afternoon: '2:00pm – 9:00pm' };
    const mEl = document.getElementById('morning-sub');
    const aEl = document.getElementById('afternoon-sub');
    if (mEl) mEl.textContent = labels.morning;
    if (aEl) aEl.textContent = labels.afternoon;
}

function renderDates(locId) {
    const grid  = document.getElementById('date-grid');
    const dates = availableDates[locId] || [];
    if (!dates.length) {
        grid.innerHTML = '<div style="color:var(--text-3);font-size:13px;grid-column:1/-1;">No available dates today — please call us or check back later.</div>';
        return;
    }
    grid.innerHTML = dates.map(d => {
        const dt    = new Date(d + 'T12:00:00');
        const day   = d === TODAY ? 'Today' : DAYS[dt.getDay()];
        const mon   = MONTHS[dt.getMonth()];
        const date  = dt.getDate();
        const sel   = d === document.getElementById('booking_date').value ? 'selected' : '';
        return `<div class="date-btn ${sel}" onclick="selectDate('${d}', this)">
                    <div class="day">${day}</div>
                    ${mon} ${date}
                </div>`;
    }).join('');
}

function selectDate(val, el) {
    document.getElementById('booking_date').value = val;
    document.querySelectorAll('.date-btn').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');
    updateTimeSlots(currentLocId, val);
}

function updateTimeSlots(locId, date) {
    const blocked   = (unavailableSlots[locId] && date) ? (unavailableSlots[locId][date] || []) : [];
    const mBtn      = document.getElementById('time-morning');
    const aBtn      = document.getElementById('time-afternoon');
    const timePref  = document.getElementById('time_preference');

    function applyBlock(btn, slot) {
        if (blocked.includes(slot)) {
            btn.classList.remove('selected');
            btn.style.opacity     = '0.4';
            btn.style.cursor      = 'not-allowed';
            btn.style.borderColor = 'var(--border)';
            btn.onclick = () => alert('This slot is no longer available today. Please choose Afternoon or a future date.');
            // Deselect if currently selected
            if (timePref.value === slot) {
                timePref.value = '';
                btn.classList.remove('selected');
            }
        } else {
            btn.style.opacity     = '1';
            btn.style.cursor      = 'pointer';
            btn.onclick = () => selectTime(slot);
        }
    }

    applyBlock(mBtn, 'morning');
    applyBlock(aBtn, 'afternoon');

    // Auto-select the first available slot if nothing valid is selected
    if (!timePref.value || blocked.includes(timePref.value)) {
        if (!blocked.includes('morning'))   selectTime('morning');
        else if (!blocked.includes('afternoon')) selectTime('afternoon');
        else timePref.value = '';
    }
}

function selectTime(val) {
    document.getElementById('time_preference').value = val;
    document.getElementById('time-morning').classList.toggle('selected',   val === 'morning');
    document.getElementById('time-afternoon').classList.toggle('selected', val === 'afternoon');
}

// Init on load
if (selectedLoc) {
    updateSlotLabels(selectedLoc);
    renderDates(selectedLoc);
    if (selectedDate) updateTimeSlots(selectedLoc, selectedDate);
}

// Prevent double submit
document.getElementById('booking-form')?.addEventListener('submit', function(e) {
    if (!document.getElementById('location_id').value)  { e.preventDefault(); alert('Please select a location.'); return; }
    if (!document.getElementById('booking_date').value)  { e.preventDefault(); alert('Please select a date.'); return; }
    if (!document.getElementById('time_preference').value) { e.preventDefault(); alert('Please select a time preference.'); return; }
    const btn = this.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
});
</script>

</body>
</html>
