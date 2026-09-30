<?php
// ============================================================
// Bookings — Staff view, confirm, cancel, complete
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
$isMgr     = $isOwner || $isManager;
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$msg = ''; $msgType = 'success';

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']    ?? '';
    $bookingId = intval($_POST['booking_id'] ?? 0);

    if ($action === 'confirm' && $bookingId) {
        $notes = trim($_POST['staff_notes'] ?? '');
        DB::execute(
            "UPDATE bookings SET status='confirmed', confirmed_by=?, confirmed_at=NOW(), staff_notes=? WHERE id=? AND status='pending'",
            [$user['id'], $notes ?: null, $bookingId]
        );
        // SMS the customer
        $b = DB::queryOne(
            "SELECT b.*, l.code AS loc_code, l.name AS loc_name FROM bookings b JOIN locations l ON l.id=b.location_id WHERE b.id=?",
            [$bookingId]
        );
        if ($b) {
            try {
                $firstName = explode(' ', trim($b['customer_name']))[0];
                $dStr = date('l, F j', strtotime($b['booking_date']));
                $tStr = $b['time_preference'] === 'morning' ? 'morning' : 'afternoon';
                $fromDid = VoipMS::didForLocation($b['loc_code']);
                VoipMS::sendSMS($fromDid, $b['customer_phone'],
                    "Hi {$firstName}! We received your repair request at C Tech Fix {$b['loc_name']} for {$dStr} ({$tStr}). We'll call you to confirm your exact time. — C Tech Fix"
                );
            } catch (\Throwable $e) {}
        }
        $msg = 'Booking confirmed and customer notified by SMS.';

    } elseif ($action === 'cancel' && $bookingId) {
        $reason = trim($_POST['cancel_reason'] ?? '');
        DB::execute(
            "UPDATE bookings SET status='cancelled', cancellation_reason=? WHERE id=?",
            [$reason ?: 'Cancelled by staff', $bookingId]
        );
        // Notify customer
        $b = DB::queryOne("SELECT b.*, l.code AS loc_code, l.name AS loc_name FROM bookings b JOIN locations l ON l.id=b.location_id WHERE b.id=?", [$bookingId]);
        if ($b) {
            try {
                $firstName = explode(' ', trim($b['customer_name']))[0];
                $fromDid = VoipMS::didForLocation($b['loc_code']);
                VoipMS::sendSMS($fromDid, $b['customer_phone'],
                    "Hi {$firstName}, your appointment at C Tech Fix {$b['loc_name']} has been cancelled. Please call or text us to reschedule. Sorry for the inconvenience!"
                );
            } catch (\Throwable $e) {}
        }
        $msg = 'Booking cancelled and customer notified.';

    } elseif ($action === 'complete' && $bookingId) {
        DB::execute("UPDATE bookings SET status='completed' WHERE id=?", [$bookingId]);
        $msg = 'Booking marked as completed.';

    } elseif ($action === 'add_note' && $bookingId) {
        $notes = trim($_POST['staff_notes'] ?? '');
        DB::execute("UPDATE bookings SET staff_notes=? WHERE id=?", [$notes, $bookingId]);
        $msg = 'Note saved.';

    } elseif ($action === 'delete_booking' && $bookingId && $isOwner) {
        DB::execute('DELETE FROM bookings WHERE id=?', [$bookingId]);
        $msg = 'Booking deleted.';
    }

    header('Location: index.php?msg=' . urlencode($msg)); exit;
}

if (isset($_GET['msg'])) { $msg = $_GET['msg']; }

// ── Filters ──────────────────────────────────────────────────
$filterStatus = $_GET['status']      ?? 'active';
$filterLoc    = $isOwner ? intval($_GET['location_id'] ?? 0) : (int)$user['location_id'];
$filterFrom   = $_GET['from'] ?? date('Y-m-d');
$filterTo     = $_GET['to']   ?? date('Y-m-d', strtotime('+14 days'));

$where  = ['b.booking_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterLoc)                      { $where[] = 'b.location_id=?'; $params[] = $filterLoc; }
elseif (!$isOwner)    { $where[] = 'b.location_id=?'; $params[] = $user['location_id']; }
if ($filterStatus === 'active')  $where[] = "b.status IN ('pending','confirmed')";
elseif ($filterStatus !== 'all') { $where[] = 'b.status=?'; $params[] = $filterStatus; }

$bookings = DB::query(
    "SELECT b.*, l.code AS loc_code, l.name AS loc_name, l.did,
            cb.first_name AS confirmed_name,
            c.id AS cust_id,
            r.record_number AS repair_number
     FROM bookings b
     JOIN locations l ON l.id=b.location_id
     LEFT JOIN users cb ON cb.id=b.confirmed_by
     LEFT JOIN customers c ON c.id=b.customer_id
     LEFT JOIN repairs r ON r.id=b.repair_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY b.booking_date ASC, b.time_preference ASC, b.created_at ASC",
    $params
);

// Pending count for badge
$pendingCount = DB::queryOne(
    "SELECT COUNT(*) AS cnt FROM bookings WHERE status='pending'"
    . (!$isOwner ? " AND location_id={$user['location_id']}" : '')
)['cnt'] ?? 0;

$STATUS_COLORS = ['pending'=>'var(--amber)','confirmed'=>'var(--green)','cancelled'=>'var(--red)','completed'=>'var(--text-3)'];
$STATUS_ICONS  = ['pending'=>'⏳','confirmed'=>'✅','cancelled'=>'❌','completed'=>'☑️'];

$pageTitle = 'Bookings';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">📅 Bookings</h1>
        <p class="page-sub">Customer appointment requests</p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <?php if ($pendingCount > 0): ?>
        <span style="background:var(--amber);color:#fff;padding:.3rem .75rem;border-radius:20px;font-size:13px;font-weight:700;">
            ⏳ <?= $pendingCount ?> pending
        </span>
        <?php endif; ?>
        <a href="new.php" class="btn btn-primary">➕ New Booking</a>
        <a href="/book.php" target="_blank" class="btn btn-secondary">🔗 Booking Page</a>
    </div>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
    <select name="status" class="form-control filter-select" onchange="this.form.submit()">
        <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Pending + Confirmed</option>
        <option value="all"    <?= $filterStatus==='all'   ?'selected':'' ?>>All</option>
        <option value="pending"   <?= $filterStatus==='pending'   ?'selected':'' ?>>Pending</option>
        <option value="confirmed" <?= $filterStatus==='confirmed' ?'selected':'' ?>>Confirmed</option>
        <option value="completed" <?= $filterStatus==='completed' ?'selected':'' ?>>Completed</option>
        <option value="cancelled" <?= $filterStatus==='cancelled' ?'selected':'' ?>>Cancelled</option>
    </select>
    <?php if ($isOwner || $isManager): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLoc==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <input type="date" name="from" class="form-control" value="<?= $filterFrom ?>" style="width:auto;">
    <span class="text-muted">to</span>
    <input type="date" name="to"   class="form-control" value="<?= $filterTo ?>"   style="width:auto;">
    <button type="submit" class="btn btn-secondary">Filter</button>
    <a href="?" class="btn btn-ghost">Reset</a>
</form>

<?php if (empty($bookings)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state">
        <div class="empty-icon">📅</div>
        <p>No bookings found for this period.</p>
        <p style="margin-top:.5rem;"><a href="/book.php" target="_blank" class="btn btn-secondary" style="margin-top:.5rem;">🔗 View Booking Page</a></p>
    </div>
</div></div>
<?php else: ?>

<?php
// Group by date
$byDate = [];
foreach ($bookings as $b) $byDate[$b['booking_date']][] = $b;
?>

<?php foreach ($byDate as $date => $dayBookings): ?>
<div style="margin-bottom:1.5rem;">
    <div style="font-weight:700;font-size:15px;color:var(--text-2);margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem;">
        📆 <?= date('l, M j Y', strtotime($date)) ?>
        <?php if ($date === date('Y-m-d')): ?>
        <span style="background:var(--blue);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;">TODAY</span>
        <?php elseif ($date === date('Y-m-d', strtotime('tomorrow'))): ?>
        <span style="background:var(--amber);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;">TOMORROW</span>
        <?php endif; ?>
        <span class="text-muted small">(<?= count($dayBookings) ?> booking<?= count($dayBookings)!==1?'s':'' ?>)</span>
    </div>

    <?php foreach ($dayBookings as $b):
        $timeLbl = $b['time_preference'] === 'morning' ? '🌤 Morning (11am–2pm)' : '☀️ Afternoon (2pm–9pm)';
    ?>
    <div class="card" style="margin-bottom:.75rem;">
        <div class="card-body" style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:start;">
            <div>
                <!-- Header row -->
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
                    <?php if ($b['cust_id']): ?>
                    <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= $b['cust_id'] ?>" style="font-size:16px;font-weight:700;color:var(--blue);text-decoration:none;">
                        <?= htmlspecialchars($b['customer_name']) ?> ↗
                    </a>
                    <?php else: ?>
                    <span style="font-size:16px;font-weight:700;"><?= htmlspecialchars($b['customer_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($isOwner): ?>
                    <span class="badge badge-loc"><?= htmlspecialchars($b['loc_code']) ?></span>
                    <?php endif; ?>
                    <span style="color:<?= $STATUS_COLORS[$b['status']] ?>;font-weight:600;font-size:13px;">
                        <?= $STATUS_ICONS[$b['status']] ?> <?= ucfirst($b['status']) ?>
                    </span>
                    <?php if (!empty($b['appointment_time'])): ?>
                    <span style="font-size:13px;font-weight:700;color:var(--blue);">
                        🕐 <?= date('g:i A', strtotime($b['appointment_time'])) ?>
                    </span>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--text-3);"><?= $timeLbl ?></span>
                    <?php endif; ?>
                    <?php if ($b['customer_reply'] === 'yes'): ?>
                    <span style="font-size:12px;font-weight:600;color:var(--green);">✅ Customer confirmed</span>
                    <?php elseif ($b['customer_reply'] === 'no'): ?>
                    <span style="font-size:12px;font-weight:600;color:var(--red);">❌ Customer said NO</span>
                    <?php elseif (!empty($b['confirmation_sms_sent'])): ?>
                    <span style="font-size:12px;color:var(--text-3);">⏳ Awaiting reply</span>
                    <?php endif; ?>
                </div>
                <!-- Device -->
                <div style="font-size:13px;color:var(--text-2);margin-bottom:.3rem;">
                    📱 <strong><?= htmlspecialchars($b['device_type']) ?></strong>
                    <?php if ($b['device_brand'] || $b['device_model']): ?>
                    — <?= htmlspecialchars(trim(($b['device_brand'] ?? '') . ' ' . ($b['device_model'] ?? ''))) ?>
                    <?php endif; ?>
                </div>
                <!-- Issue -->
                <div style="font-size:13px;background:var(--surface-2);border-radius:6px;padding:.5rem .75rem;margin-bottom:.5rem;">
                    <?= htmlspecialchars($b['issue_description']) ?>
                </div>
                <!-- Contact -->
                <div style="font-size:12px;color:var(--text-3);display:flex;gap:1rem;flex-wrap:wrap;">
                    <span>📞 <a href="tel:<?= $b['customer_phone'] ?>" style="color:var(--blue);"><?= formatPhone($b['customer_phone']) ?></a></span>
                    <?php if ($b['customer_email']): ?>
                    <span>✉ <?= htmlspecialchars($b['customer_email']) ?></span>
                    <?php endif; ?>
                    <span>Ref #<?= str_pad($b['id'], 4, '0', STR_PAD_LEFT) ?></span>
                    <span>Booked <?= timeAgo($b['created_at']) ?></span>
                </div>
                <?php if ($b['staff_notes']): ?>
                <div style="margin-top:.5rem;font-size:12px;color:var(--text-2);background:var(--surface-2);border-left:3px solid var(--blue);padding:.4rem .6rem;border-radius:0 4px 4px 0;">
                    📝 <?= htmlspecialchars($b['staff_notes']) ?>
                </div>
                <?php endif; ?>
                <?php if ($b['confirmed_name']): ?>
                <div style="margin-top:.3rem;font-size:11px;color:var(--text-3);">Confirmed by <?= htmlspecialchars($b['confirmed_name']) ?> at <?= date('g:i A', strtotime($b['confirmed_at'])) ?></div>
                <?php endif; ?>
                <?php if ($b['cancellation_reason']): ?>
                <div style="margin-top:.3rem;font-size:11px;color:var(--red);">Reason: <?= htmlspecialchars($b['cancellation_reason']) ?></div>
                <?php endif; ?>
                <?php if ($b['repair_id'] && $b['repair_number']): ?>
                <div style="margin-top:.4rem;">
                    <a href="<?= APP_URL ?>/modules/repairs/view.php?id=<?= $b['repair_id'] ?>"
                       style="display:inline-flex;align-items:center;gap:.3rem;font-size:12px;font-weight:600;color:var(--green);text-decoration:none;">
                        🔧 Repair <?= htmlspecialchars($b['repair_number']) ?> ↗
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div style="display:flex;flex-direction:column;gap:.4rem;min-width:110px;">
                <a href="<?= APP_URL ?>/modules/bookings/view.php?id=<?= $b['id'] ?>" class="btn btn-primary btn-sm">👁 View</a>
                <?php if ($b['status'] === 'pending'): ?>
                <button type="button" class="btn btn-primary btn-sm"
                    onclick="showConfirm(<?= $b['id'] ?>)">✅ Confirm</button>
                <?php if (!$b['repair_id']): ?>
                <a href="<?= APP_URL ?>/modules/repairs/new.php?from_booking=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">🔧 Convert</a>
                <?php endif; ?>
                <a href="tel:<?= $b['customer_phone'] ?>" class="btn btn-secondary btn-sm">📞 Call</a>
                <?php
                $firstName = explode(' ', $b['customer_name'])[0];
                $dStr      = date('D M j', strtotime($b['booking_date']));
                $tStr      = $b['time_preference'] === 'morning' ? 'morning' : 'afternoon';
                $smsTemplate = urlencode("Hi {$firstName}! Just following up on your appointment request for {$dStr} ({$tStr}). ");
                ?>
                <a href="<?= APP_URL ?>/modules/calls/sms.php?to=<?= $b['customer_phone'] ?>&msg=<?= $smsTemplate ?>&location_id=<?= $b['location_id'] ?>" class="btn btn-secondary btn-sm">💬 SMS</a>
                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--red);"
                    onclick="showCancel(<?= $b['id'] ?>)">❌ Cancel</button>

                <?php elseif ($b['status'] === 'confirmed'): ?>
                <?php if (!$b['repair_id']): ?>
                <a href="<?= APP_URL ?>/modules/repairs/new.php?from_booking=<?= $b['id'] ?>" class="btn btn-primary btn-sm">🔧 Convert</a>
                <?php else: ?>
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="action"     value="complete">
                    <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">☑️ Complete</button>
                </form>
                <?php endif; ?>
                <a href="tel:<?= $b['customer_phone'] ?>" class="btn btn-secondary btn-sm">📞 Call</a>
                <?php
                $smsTemplateConfirmed = urlencode("Hi {$firstName}! This is C Tech Fix confirming your appointment on {$dStr} ({$tStr}). See you then! ");
                ?>
                <a href="<?= APP_URL ?>/modules/calls/sms.php?to=<?= $b['customer_phone'] ?>&msg=<?= $smsTemplateConfirmed ?>&location_id=<?= $b['location_id'] ?>" class="btn btn-secondary btn-sm">💬 SMS</a>
                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--red);"
                    onclick="showCancel(<?= $b['id'] ?>)">❌ Cancel</button>
                <?php endif; ?>

                <?php if (in_array($b['status'], ['pending','confirmed','completed'])): ?>
                <button type="button" class="btn btn-ghost btn-sm"
                    onclick="showNote(<?= $b['id'] ?>, <?= htmlspecialchars(json_encode($b['staff_notes'] ?? '')) ?>)">📝 Note</button>
                <?php endif; ?>
                <?php if ($isOwner): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action"     value="delete_booking">
                    <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);"
                            onclick="return confirm('Permanently delete this booking? This cannot be undone.')">🗑 Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Confirm modal -->
<div id="confirm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--surface);border-radius:12px;padding:1.5rem;max-width:400px;width:90%;">
    <h3 style="margin-bottom:1rem;">✅ Confirm Booking</h3>
    <form method="POST">
        <input type="hidden" name="action"     value="confirm">
        <input type="hidden" name="booking_id" id="confirm-id">
        <div class="form-group">
            <label class="form-label">Staff Note (optional)</label>
            <textarea name="staff_notes" class="form-control" rows="2" placeholder="e.g. Parts on order, will be ready in 2 days"></textarea>
        </div>
        <p class="form-hint" style="margin-bottom:1rem;">Customer will receive a confirmation SMS automatically.</p>
        <div style="display:flex;gap:.5rem;">
            <button type="submit" class="btn btn-primary">Confirm & Notify</button>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('confirm-modal').style.display='none'">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Cancel modal -->
<div id="cancel-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--surface);border-radius:12px;padding:1.5rem;max-width:400px;width:90%;">
    <h3 style="margin-bottom:1rem;">❌ Cancel Booking</h3>
    <form method="POST">
        <input type="hidden" name="action"     value="cancel">
        <input type="hidden" name="booking_id" id="cancel-id">
        <div class="form-group">
            <label class="form-label">Reason</label>
            <select name="cancel_reason" class="form-control">
                <option value="">— Select reason —</option>
                <option value="Parts not available">Parts not available</option>
                <option value="Customer rescheduled">Customer rescheduled</option>
                <option value="Customer no-show">Customer no-show</option>
                <option value="Duplicate booking">Duplicate booking</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <p class="form-hint" style="margin-bottom:1rem;">Customer will receive a cancellation SMS.</p>
        <div style="display:flex;gap:.5rem;">
            <button type="submit" class="btn btn-danger">Cancel Booking</button>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('cancel-modal').style.display='none'">Close</button>
        </div>
    </form>
</div>
</div>

<!-- Note modal -->
<div id="note-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--surface);border-radius:12px;padding:1.5rem;max-width:400px;width:90%;">
    <h3 style="margin-bottom:1rem;">📝 Staff Note</h3>
    <form method="POST">
        <input type="hidden" name="action"     value="add_note">
        <input type="hidden" name="booking_id" id="note-id">
        <div class="form-group">
            <textarea name="staff_notes" id="note-text" class="form-control" rows="3" placeholder="Internal note about this booking..."></textarea>
        </div>
        <div style="display:flex;gap:.5rem;">
            <button type="submit" class="btn btn-primary">Save Note</button>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('note-modal').style.display='none'">Cancel</button>
        </div>
    </form>
</div>
</div>

<script>
function showConfirm(id) { document.getElementById('confirm-id').value=id; document.getElementById('confirm-modal').style.display='flex'; }
function showCancel(id)  { document.getElementById('cancel-id').value=id;  document.getElementById('cancel-modal').style.display='flex'; }
function showNote(id, text) {
    document.getElementById('note-id').value=id;
    document.getElementById('note-text').value=text||'';
    document.getElementById('note-modal').style.display='flex';
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
