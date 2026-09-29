<?php
// ============================================================
// Bookings — View / Set Appointment Time / Send Confirmation SMS
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();

$bookingId = intval($_GET['id'] ?? 0);
if (!$bookingId) { header('Location: index.php'); exit; }

$booking = DB::queryOne(
    "SELECT b.*, l.code AS loc_code, l.name AS loc_name,
            cb.first_name AS confirmed_name,
            r.record_number AS repair_number,
            r.id AS repair_id_linked
     FROM bookings b
     JOIN locations l ON l.id = b.location_id
     LEFT JOIN users cb ON cb.id = b.confirmed_by
     LEFT JOIN repairs r ON r.id = b.repair_id
     WHERE b.id = ?",
    [$bookingId]
);
if (!$booking) { header('Location: index.php'); exit; }

// Load store hours from settings table (per location)
$locSettings = [];
$settingRows = DB::query(
    "SELECT setting_key, value FROM settings WHERE location_id = ?",
    [$booking['location_id']]
);
foreach ($settingRows as $row) {
    $locSettings[$row['setting_key']] = $row['value'];
}

$openTime  = $locSettings['store_open_time']  ?? '10:00';
$closeTime = $locSettings['store_close_time'] ?? '19:00';
$openDays  = $locSettings['store_open_days']  ?? '1,2,3,4,5,6'; // 1=Mon...6=Sat (date('N'))

// Check if location is open on the booking's day (date('N') = 1 Mon … 7 Sun)
$dayOfWeekN  = (int)date('N', strtotime($booking['booking_date']));
$openDaysArr = array_map('intval', explode(',', $openDays));
$isOpenDay   = in_array($dayOfWeekN, $openDaysArr, true);

// Build 30-minute time slots filtered by morning/afternoon preference
function buildTimeSlots(bool $isOpen, string $openTime, string $closeTime, string $preference): array {
    if (!$isOpen) return [];

    $open  = strtotime('today ' . $openTime);
    $close = strtotime('today ' . $closeTime);
    $noon  = strtotime('today 12:00');

    $slotStart = $preference === 'morning' ? $open  : max($open, $noon);
    $slotEnd   = $preference === 'morning' ? min($close, $noon) : $close;

    $slots = [];
    $cur = $slotStart;
    while ($cur < $slotEnd) {
        $slots[] = date('H:i:s', $cur);
        $cur += 1800; // 30 minutes
    }
    return $slots;
}

$timeSlots = buildTimeSlots($isOpenDay, $openTime, $closeTime, $booking['time_preference'] ?? 'morning');

$msg = ''; $msgType = 'success';

// ── POST: set appointment time ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_time') {
        $apptTime = $_POST['appointment_time'] ?? '';
        if ($apptTime) {
            DB::execute(
                "UPDATE bookings SET appointment_time=? WHERE id=?",
                [$apptTime, $bookingId]
            );
            $booking['appointment_time'] = $apptTime;
            $msg = 'Appointment time saved.';
        }

    } elseif ($action === 'send_confirmation') {
        if (empty($booking['appointment_time'])) {
            $msg = 'Please set an appointment time before sending the confirmation SMS.';
            $msgType = 'danger';
        } else {
            // Format date: "Thursday, July 10"
            $dateFormatted = date('l, F j', strtotime($booking['booking_date']));
            // Format time: "2:30 PM"
            $timeFormatted = date('g:i A', strtotime($booking['appointment_time']));
            $firstName     = explode(' ', trim($booking['customer_name']))[0];

            $message = "Hi {$firstName}, C Tech Fix {$booking['loc_name']}: {$dateFormatted} at {$timeFormatted}. Reply YES to confirm or NO to cancel.";

            $fromDid = VoipMS::didForLocation($booking['loc_code']);
            $result  = VoipMS::sendSMS($fromDid, $booking['customer_phone'], $message);

            if ($result === true) {
                DB::execute(
                    "UPDATE bookings SET confirmation_sms_sent=1, confirmation_sms_sent_at=NOW(), status='confirmed' WHERE id=?",
                    [$bookingId]
                );
                $booking['confirmation_sms_sent'] = 1;
                $booking['status'] = 'confirmed';
                $msg = "✅ Confirmation SMS sent to {$booking['customer_name']} for {$dateFormatted} at {$timeFormatted}.";
            } else {
                $msg = '❌ SMS failed to send. Check VoipMS settings. Error: ' . htmlspecialchars((string)$result);
                $msgType = 'danger';
            }
        }

    } elseif ($action === 'cancel') {
        $reason = trim($_POST['cancel_reason'] ?? 'Cancelled by staff');
        DB::execute(
            "UPDATE bookings SET status='cancelled', cancellation_reason=? WHERE id=?",
            [$reason, $bookingId]
        );
        $fromDid = VoipMS::didForLocation($booking['loc_code']);
        $firstName = explode(' ', trim($booking['customer_name']))[0];
        VoipMS::sendSMS($fromDid, $booking['customer_phone'],
            "Hi {$firstName}, your appointment at C Tech Fix {$booking['loc_name']} has been cancelled. Please call or text us to reschedule. Sorry for the inconvenience!"
        );
        $booking['status'] = 'cancelled';
        $msg = 'Booking cancelled and customer notified.';
        $msgType = 'warning';
    }
}

// Reload booking to get latest data
$booking = DB::queryOne(
    "SELECT b.*, l.code AS loc_code, l.name AS loc_name,
            cb.first_name AS confirmed_name,
            r.record_number AS repair_number,
            r.id AS repair_id_linked
     FROM bookings b
     JOIN locations l ON l.id = b.location_id
     LEFT JOIN users cb ON cb.id = b.confirmed_by
     LEFT JOIN repairs r ON r.id = b.repair_id
     WHERE b.id = ?",
    [$bookingId]
);

$pageTitle = 'Booking #' . str_pad($bookingId, 4, '0', STR_PAD_LEFT);
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📅 Booking #<?= str_pad($bookingId, 4, '0', STR_PAD_LEFT) ?></h1>
        <p class="page-sub"><?= htmlspecialchars($booking['customer_name']) ?> · <?= date('l, F j, Y', strtotime($booking['booking_date'])) ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary">← Back to Bookings</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<div class="repair-grid">
<div class="repair-col-main">

    <!-- Booking Details -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h2 class="card-title">Booking Details</h2></div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Customer</span>
                    <span class="detail-value"><?= htmlspecialchars($booking['customer_name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Phone</span>
                    <span class="detail-value" style="font-family:monospace;"><?= htmlspecialchars(formatPhone($booking['customer_phone'])) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Date</span>
                    <span class="detail-value"><?= date('l, F j, Y', strtotime($booking['booking_date'])) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Preference</span>
                    <span class="detail-value"><?= ucfirst($booking['time_preference']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Device</span>
                    <span class="detail-value"><?= htmlspecialchars(trim(($booking['device_brand'] ?? '') . ' ' . ($booking['device_model'] ?? ''))) ?: '—' ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Location</span>
                    <span class="detail-value"><?= htmlspecialchars($booking['loc_name']) ?></span>
                </div>
                <div class="detail-item" style="grid-column:1/-1;">
                    <span class="detail-label">Issue</span>
                    <span class="detail-value"><?= nl2br(htmlspecialchars($booking['issue_description'])) ?></span>
                </div>
                <?php if ($booking['staff_notes']): ?>
                <div class="detail-item" style="grid-column:1/-1;">
                    <span class="detail-label">Staff Notes</span>
                    <span class="detail-value"><?= nl2br(htmlspecialchars($booking['staff_notes'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($booking['repair_number']): ?>
                <div class="detail-item" style="grid-column:1/-1;">
                    <span class="detail-label">Repair Ticket</span>
                    <span class="detail-value">
                        <a href="<?= APP_URL ?>/modules/repairs/view.php?id=<?= $booking['repair_id_linked'] ?>">
                            <?= htmlspecialchars($booking['repair_number']) ?>
                        </a>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!in_array($booking['status'], ['cancelled','completed'])): ?>

    <!-- Set Appointment Time -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h2 class="card-title">⏰ Set Appointment Time</h2></div>
        <div class="card-body">
            <?php if (empty($timeSlots)): ?>
            <div class="alert alert-warning">No available time slots — the store may be closed on this day. Check store hours in Settings.</div>
            <?php else: ?>
            <form method="POST" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="action" value="set_time">
                <div class="form-group" style="margin:0;flex:1;min-width:200px;">
                    <label class="form-label">
                        Appointment Time
                        <span style="color:var(--text-3);font-size:12px;font-weight:400;">
                            (<?= ucfirst($booking['time_preference']) ?> slots for <?= htmlspecialchars($booking['loc_name']) ?>)
                        </span>
                    </label>
                    <select name="appointment_time" class="form-control" required>
                        <option value="">— Pick a time —</option>
                        <?php foreach ($timeSlots as $slot): ?>
                        <option value="<?= $slot ?>"
                            <?= ($booking['appointment_time'] ?? '') === $slot ? 'selected' : '' ?>>
                            <?= date('g:i A', strtotime($slot)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary" style="margin-bottom:0;">💾 Save Time</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Send Confirmation SMS -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h2 class="card-title">💬 Confirmation SMS</h2></div>
        <div class="card-body">
            <?php if (!empty($booking['appointment_time'])): ?>
            <?php
                $previewDate = date('l, F j', strtotime($booking['booking_date']));
                $previewTime = date('g:i A', strtotime($booking['appointment_time']));
                $previewName = explode(' ', trim($booking['customer_name']))[0];
            ?>
            <div style="background:var(--surface-2);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:13px;border-left:3px solid var(--blue);">
                <div style="font-size:11px;color:var(--text-3);margin-bottom:4px;">SMS PREVIEW</div>
                "Hi <?= htmlspecialchars($previewName) ?>, your appointment at C Tech Fix <?= htmlspecialchars($booking['loc_name']) ?> is set for <strong><?= $previewDate ?> at <?= $previewTime ?></strong>. Reply YES to confirm or NO to cancel. — C Tech Fix"
            </div>

            <?php if ($booking['confirmation_sms_sent']): ?>
            <div class="alert" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:8px;padding:.6rem 1rem;">
                ✅ Confirmation SMS sent on <?= date('M j \a\t g:i A', strtotime($booking['confirmation_sms_sent_at'])) ?>
            </div>
            <form method="POST" style="margin-top:.5rem;">
                <input type="hidden" name="action" value="send_confirmation">
                <button type="submit" class="btn btn-secondary btn-sm">🔄 Resend SMS</button>
            </form>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="send_confirmation">
                <button type="submit" class="btn btn-primary">📤 Send Confirmation SMS</button>
            </form>
            <?php endif; ?>

            <?php else: ?>
            <div class="alert" style="background:#fef9c3;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:.6rem 1rem;">
                ⏳ Set an appointment time above before sending the confirmation SMS.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cancel -->
    <?php if ($booking['status'] !== 'cancelled'): ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title" style="color:var(--red);">Cancel Booking</h2></div>
        <div class="card-body">
            <form method="POST" onsubmit="return confirm('Cancel this booking and notify the customer by SMS?');">
                <input type="hidden" name="action" value="cancel">
                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <input type="text" name="cancel_reason" class="form-control" placeholder="e.g. Customer requested cancellation">
                </div>
                <button type="submit" class="btn btn-danger">❌ Cancel Booking</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // not cancelled/completed ?>

</div>

<!-- Right sidebar -->
<div class="repair-col-side">

    <!-- Status -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h2 class="card-title">Status</h2></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:.75rem;">

            <?php
            $statusColors = [
                'pending'   => 'var(--amber)',
                'confirmed' => 'var(--blue)',
                'cancelled' => 'var(--red)',
                'completed' => 'var(--green)',
            ];
            $statusLabels = [
                'pending'   => '⏳ Pending',
                'confirmed' => '✅ Confirmed',
                'cancelled' => '❌ Cancelled',
                'completed' => '✔ Completed',
            ];
            $st = $booking['status'];
            ?>
            <div style="text-align:center;font-size:1.2rem;font-weight:700;color:<?= $statusColors[$st] ?? 'var(--text-1)' ?>;">
                <?= $statusLabels[$st] ?? ucfirst($st) ?>
            </div>

            <?php if (!empty($booking['appointment_time'])): ?>
            <div style="text-align:center;">
                <div style="font-size:11px;color:var(--text-3);margin-bottom:2px;">APPOINTMENT</div>
                <div style="font-weight:700;font-size:1.1rem;"><?= date('g:i A', strtotime($booking['appointment_time'])) ?></div>
                <div style="font-size:12px;color:var(--text-3);"><?= date('l, F j', strtotime($booking['booking_date'])) ?></div>
            </div>
            <?php endif; ?>

            <hr style="border:none;border-top:1px solid var(--border);margin:.25rem 0;">

            <!-- Customer Reply -->
            <div>
                <div style="font-size:11px;color:var(--text-3);margin-bottom:4px;">CUSTOMER REPLY</div>
                <?php if ($booking['customer_reply'] === 'yes'): ?>
                <div style="color:var(--green);font-weight:700;">✅ Confirmed YES</div>
                <div style="font-size:11px;color:var(--text-3);"><?= date('M j \a\t g:i A', strtotime($booking['customer_replied_at'])) ?></div>
                <?php elseif ($booking['customer_reply'] === 'no'): ?>
                <div style="color:var(--red);font-weight:700;">❌ Replied NO</div>
                <div style="font-size:11px;color:var(--text-3);"><?= date('M j \a\t g:i A', strtotime($booking['customer_replied_at'])) ?></div>
                <?php elseif ($booking['confirmation_sms_sent']): ?>
                <div style="color:var(--text-3);">⏳ Waiting for reply…</div>
                <?php else: ?>
                <div style="color:var(--text-3);">— SMS not sent yet</div>
                <?php endif; ?>
            </div>

            <?php if ($booking['reminder_sms_sent']): ?>
            <div style="font-size:12px;color:var(--text-3);">
                📩 Reminder sent <?= date('M j \a\t g:i A', strtotime($booking['reminder_sms_sent_at'])) ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Convert to Repair -->
    <?php if (!$booking['repair_number'] && $booking['status'] !== 'cancelled'): ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title">🔧 Convert to Repair</h2></div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--text-3);margin-bottom:.75rem;">Customer arrived? Open a repair ticket from this booking.</p>
            <a href="<?= APP_URL ?>/modules/repairs/new.php?from_booking=<?= $bookingId ?>" class="btn btn-primary" style="width:100%;text-align:center;">
                Open Repair Ticket →
            </a>
        </div>
    </div>
    <?php elseif ($booking['repair_number']): ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title">🔧 Repair Ticket</h2></div>
        <div class="card-body">
            <a href="<?= APP_URL ?>/modules/repairs/view.php?id=<?= $booking['repair_id_linked'] ?>" class="btn btn-secondary" style="width:100%;text-align:center;">
                View <?= htmlspecialchars($booking['repair_number']) ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
