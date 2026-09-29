<?php
// ============================================================
// Staff Clock In / Clock Out — with mandatory till count
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/Audit.php';

Auth::boot();
Auth::require();

$user       = Auth::user();
$userId     = (int)$user['id'];
$locationId = (int)$user['location_id'];
$today      = date('Y-m-d');

// ── Handle POST ──────────────────────────────────────────────
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']        ?? '';
    $notes       = trim($_POST['notes']    ?? '');
    $tillAmount  = $_POST['till_amount']   ?? '';

    // -- CLOCK IN --
    if ($action === 'clock_in') {
        // Validate till count
        if ($tillAmount === '' || !is_numeric($tillAmount)) {
            $msg     = 'Please enter your opening till count before clocking in.';
            $msgType = 'warning';
        } else {
            $openAmount = round((float)$tillAmount, 2);

            // Check for any open shift
            $open = DB::queryOne(
                "SELECT id, clock_in FROM staff_attendance WHERE user_id=? AND clock_out IS NULL LIMIT 1",
                [$userId]
            );

            // If open shift is from a PREVIOUS day, auto-close it at 11:59 PM that day
            if ($open && date('Y-m-d', strtotime($open['clock_in'])) < $today) {
                $autoCloseTime = date('Y-m-d', strtotime($open['clock_in'])) . ' 23:59:00';
                $autoHours     = round((strtotime($autoCloseTime) - strtotime($open['clock_in'])) / 3600, 2);
                DB::execute(
                    "UPDATE staff_attendance
                     SET clock_out=?, hours_worked=?, notes=CONCAT(COALESCE(notes,''), ' [AUTO-CLOSED: forgot to clock out]')
                     WHERE id=?",
                    [$autoCloseTime, $autoHours, $open['id']]
                );
                $open = null; // cleared, allow clock-in below
                $msg  = '⚠️ Your previous shift was not clocked out — it has been auto-closed at 11:59 PM. Please inform your manager. ';
                $msgType = 'warning';
            }

            if ($open) {
                $msg     = 'You are already clocked in today. Please clock out first.';
                $msgType = 'warning';
            } else {
                // Auto-close any OTHER staff at this location still open from a previous day
                $staleOthers = DB::query(
                    "SELECT id, user_id, clock_in FROM staff_attendance
                     WHERE location_id=? AND clock_out IS NULL AND user_id != ?
                       AND DATE(clock_in) < ?",
                    [$locationId, $userId, $today]
                );
                foreach ($staleOthers as $stale) {
                    $autoClose = date('Y-m-d', strtotime($stale['clock_in'])) . ' 23:59:00';
                    $autoHours = round((strtotime($autoClose) - strtotime($stale['clock_in'])) / 3600, 2);
                    DB::execute(
                        "UPDATE staff_attendance
                         SET clock_out=?, hours_worked=?,
                             notes=CONCAT(COALESCE(notes,''), ' [AUTO-CLOSED at shift handoff]')
                         WHERE id=?",
                        [$autoClose, $autoHours, $stale['id']]
                    );
                }
                if (!empty($staleOthers)) {
                    $msg .= count($staleOthers) . ' previous shift(s) from other staff were auto-closed. Managers should review. ';
                    $msgType = 'warning';
                }

                // Insert attendance record
                DB::execute(
                    "INSERT INTO staff_attendance (user_id, location_id, clock_in, notes)
                     VALUES (?, ?, NOW(), ?)",
                    [$userId, $locationId, $notes ?: null]
                );
                Audit::log('attendance_clock_in', 'staff_attendance', null, [
                    'user_id' => $userId, 'location_id' => $locationId
                ]);

                // Open cash drawer if none exists for today at this location
                $existingDrawer = DB::queryOne(
                    "SELECT id, status FROM cash_drawers WHERE location_id=? AND drawer_date=? LIMIT 1",
                    [$locationId, $today]
                );
                if (!$existingDrawer) {
                    DB::execute(
                        "INSERT INTO cash_drawers
                             (location_id, drawer_date, opening_amount, status, opened_by, opened_at)
                         VALUES (?, ?, ?, 'open', ?, NOW())",
                        [$locationId, $today, $openAmount, $userId]
                    );
                    Audit::log('cash_drawer_opened', 'cash_drawers', null, [
                        'user_id' => $userId, 'location_id' => $locationId, 'opening_amount' => $openAmount
                    ]);
                    $msg .= 'Clocked in at ' . date('g:i A') . '. Cash drawer opened with $' . number_format($openAmount, 2) . '.';
                } else {
                    // Drawer already open (earlier shift) -- just record the count in notes
                    $msg .= 'Clocked in at ' . date('g:i A') . '. Till count noted: $' . number_format($openAmount, 2) . ' (drawer already open for today).';
                }
            }
        }

    // -- CLOCK OUT --
    } elseif ($action === 'clock_out') {
        // Validate till count
        if ($tillAmount === '' || !is_numeric($tillAmount)) {
            $msg     = 'Please enter your closing till count before clocking out.';
            $msgType = 'warning';
        } else {
            $actualClose = round((float)$tillAmount, 2);

            $open = DB::queryOne(
                "SELECT id, clock_in FROM staff_attendance WHERE user_id=? AND clock_out IS NULL LIMIT 1",
                [$userId]
            );
            if (!$open) {
                $msg     = 'You are not currently clocked in.';
                $msgType = 'warning';
            } else {
                $clockIn     = strtotime($open['clock_in']);
                $hoursWorked = round((time() - $clockIn) / 3600, 2);

                // Update attendance
                DB::execute(
                    "UPDATE staff_attendance
                     SET clock_out=NOW(), hours_worked=?, notes=COALESCE(NULLIF(?, ''), notes)
                     WHERE id=?",
                    [$hoursWorked, $notes, $open['id']]
                );
                Audit::log('attendance_clock_out', 'staff_attendance', $open['id'], [
                    'user_id' => $userId, 'hours_worked' => $hoursWorked
                ]);

                // Close cash drawer if still open for today
                $openDrawer = DB::queryOne(
                    "SELECT id FROM cash_drawers
                     WHERE location_id=? AND drawer_date=? AND status='open' LIMIT 1",
                    [$locationId, $today]
                );
                if ($openDrawer) {
                    DB::execute(
                        "UPDATE cash_drawers
                         SET actual_close=?, status='submitted', closed_by=?, closed_at=NOW()
                         WHERE id=?",
                        [$actualClose, $userId, $openDrawer['id']]
                    );
                    Audit::log('cash_drawer_closed', 'cash_drawers', $openDrawer['id'], [
                        'user_id' => $userId, 'actual_close' => $actualClose
                    ]);
                    $msg = 'Clocked out. You worked ' . number_format($hoursWorked, 2) . ' hours. Cash drawer submitted with $' . number_format($actualClose, 2) . '.';
                } else {
                    $msg = 'Clocked out. You worked ' . number_format($hoursWorked, 2) . ' hours.';
                }
            }
        }
    }
}

// ── State ──────────────────────────────────────────────────
$openShift = DB::queryOne(
    "SELECT *, TIMESTAMPDIFF(MINUTE, clock_in, NOW()) AS mins_so_far
     FROM staff_attendance WHERE user_id=? AND clock_out IS NULL LIMIT 1",
    [$userId]
);

// Today's drawer state
$todayDrawer = DB::queryOne(
    "SELECT id, status, opening_amount, actual_close FROM cash_drawers
     WHERE location_id=? AND drawer_date=? LIMIT 1",
    [$locationId, $today]
);

// This week's records
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$weekRows  = DB::query(
    "SELECT *, DATE(clock_in) AS work_date
     FROM staff_attendance
     WHERE user_id=? AND DATE(clock_in) BETWEEN ? AND ?
     ORDER BY clock_in DESC",
    [$userId, $weekStart, $weekEnd]
);

$weekHours = array_sum(array_column($weekRows, 'hours_worked'));

// This month
$monthHours = (float)(DB::queryOne(
    "SELECT COALESCE(SUM(hours_worked), 0) AS h
     FROM staff_attendance
     WHERE user_id=? AND MONTH(clock_in)=MONTH(NOW()) AND YEAR(clock_in)=YEAR(NOW())
       AND clock_out IS NOT NULL",
    [$userId]
)['h'] ?? 0);

$pageTitle = 'My Attendance';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">🕐 My Attendance</h1>
        <p class="page-sub">Track your working hours</p>
    </div>
    <?php if (Auth::isOwner() || Auth::isManager()): ?>
    <a href="attendance.php" class="btn btn-secondary">📋 View All Staff</a>
    <?php endif; ?>
</div>

<!-- Status Card -->
<div class="card" style="margin-bottom:1.5rem;<?= $openShift ? 'border:2px solid var(--green);' : '' ?>">
    <div class="card-body" style="text-align:center;padding:2rem;">
        <?php if ($openShift): ?>
            <div style="font-size:3rem;margin-bottom:.5rem;">🟢</div>
            <div style="font-size:1.4rem;font-weight:700;color:var(--green);margin-bottom:.25rem;">Currently Clocked In</div>
            <div class="text-muted" style="font-size:15px;margin-bottom:.25rem;">
                Since <?= date('g:i A', strtotime($openShift['clock_in'])) ?>
            </div>
            <div style="font-size:2rem;font-weight:700;margin:.75rem 0;" id="live-timer">
                <?php
                $mins = (int)$openShift['mins_so_far'];
                echo floor($mins/60) . 'h ' . ($mins % 60) . 'm';
                ?>
            </div>

            <!-- Till status banner -->
            <?php if ($todayDrawer && $todayDrawer['status'] === 'open'): ?>
            <div style="display:inline-block;background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:8px;padding:6px 16px;font-size:13px;font-weight:600;margin-bottom:1rem;">
                💵 Drawer open · Float: $<?= number_format($todayDrawer['opening_amount'],2) ?>
            </div>
            <?php elseif ($todayDrawer && $todayDrawer['status'] !== 'open'): ?>
            <div style="display:inline-block;background:#f3f4f6;color:var(--text-3);border:1px solid var(--border);border-radius:8px;padding:6px 16px;font-size:13px;font-weight:600;margin-bottom:1rem;">
                ✅ Drawer already closed for today
            </div>
            <?php endif; ?>

            <form method="POST" style="max-width:360px;margin:0 auto;" onsubmit="return validateClockOut(this)">
                <input type="hidden" name="action" value="clock_out">

                <!-- Mandatory till count -->
                <div style="background:#fff8e1;border:2px solid #f59e0b;border-radius:10px;padding:1rem;margin-bottom:.75rem;text-align:left;">
                    <label style="display:block;font-weight:700;font-size:14px;margin-bottom:.4rem;">
                        💵 Count the till — enter total cash <span style="color:var(--red)">*</span>
                    </label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-weight:700;color:var(--text-2);">$</span>
                        <input type="number" name="till_amount" id="till_amount_out" min="0" step="0.01"
                               class="form-control" placeholder="0.00"
                               style="padding-left:24px;font-size:1.1rem;font-weight:600;"
                               required>
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:4px;">Count all bills and coins in the till before clocking out.</div>
                </div>

                <textarea name="notes" class="form-control" placeholder="End-of-shift notes (optional)" rows="2" style="margin-bottom:.75rem;"></textarea>
                <button type="submit" class="btn btn-danger" style="width:100%;font-size:1.1rem;padding:.75rem;">
                    ⏹ Clock Out &amp; Submit Till
                </button>
            </form>

        <?php else: ?>
            <div style="font-size:3rem;margin-bottom:.5rem;">⚪</div>
            <div style="font-size:1.4rem;font-weight:700;color:var(--text-2);margin-bottom:1rem;">Not Clocked In</div>

            <!-- Drawer status if already opened by another staff -->
            <?php if ($todayDrawer && $todayDrawer['status'] === 'open'): ?>
            <div style="display:inline-block;background:#fef9c3;color:#92400e;border:1px solid #fde68a;border-radius:8px;padding:6px 16px;font-size:13px;font-weight:600;margin-bottom:1rem;">
                💵 Drawer already open for today · Float: $<?= number_format($todayDrawer['opening_amount'],2) ?>
            </div>
            <?php endif; ?>

            <form method="POST" style="max-width:360px;margin:0 auto;" onsubmit="return validateClockIn(this)">
                <input type="hidden" name="action" value="clock_in">

                <!-- Mandatory till count -->
                <div style="background:#f0fdf4;border:2px solid #22c55e;border-radius:10px;padding:1rem;margin-bottom:.75rem;text-align:left;">
                    <label style="display:block;font-weight:700;font-size:14px;margin-bottom:.4rem;">
                        💵 Count the till — enter opening cash <span style="color:var(--red)">*</span>
                    </label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-weight:700;color:var(--text-2);">$</span>
                        <input type="number" name="till_amount" id="till_amount_in" min="0" step="0.01"
                               class="form-control" placeholder="0.00"
                               style="padding-left:24px;font-size:1.1rem;font-weight:600;"
                               required>
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:4px;">Count all bills and coins in the till before starting your shift.</div>
                </div>

                <textarea name="notes" class="form-control" placeholder="Start-of-shift notes (optional)" rows="2" style="margin-bottom:.75rem;"></textarea>
                <button type="submit" class="btn btn-success" style="width:100%;font-size:1.1rem;padding:.75rem;">
                    ▶ Clock In &amp; Open Till
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Stats -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">
    <div class="metric-card accent-blue">
        <div class="metric-label">This Week</div>
        <div class="metric-value"><?= number_format($weekHours + ($openShift ? ($openShift['mins_so_far']/60) : 0), 1) ?>h</div>
        <div class="metric-sub"><?= date('M j', strtotime($weekStart)) ?> – <?= date('M j', strtotime($weekEnd)) ?></div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">This Month</div>
        <div class="metric-value"><?= number_format($monthHours, 1) ?>h</div>
        <div class="metric-sub"><?= date('F Y') ?></div>
    </div>
    <div class="metric-card accent-green">
        <div class="metric-label">Shifts This Week</div>
        <div class="metric-value"><?= count(array_filter($weekRows, fn($r) => $r['clock_out'] !== null)) ?></div>
        <div class="metric-sub">Completed</div>
    </div>
</div>

<!-- This Week's Shifts -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">This Week's Shifts</h2>
        <span class="text-muted small"><?= date('M j', strtotime($weekStart)) ?> – <?= date('M j', strtotime($weekEnd)) ?></span>
    </div>
    <?php if (empty($weekRows) && !$openShift): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon">📅</div>
            <p>No shifts recorded this week.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Clock In</th><th>Clock Out</th><th>Hours</th><th>Notes</th></tr>
        </thead>
        <tbody>
        <?php if ($openShift && date('Y-m-d', strtotime($openShift['clock_in'])) >= $weekStart): ?>
        <tr style="background:rgba(34,197,94,.06);">
            <td style="font-weight:600;"><?= date('D M j', strtotime($openShift['clock_in'])) ?></td>
            <td><?= date('g:i A', strtotime($openShift['clock_in'])) ?></td>
            <td><span style="color:var(--green);font-weight:600;">● Active</span></td>
            <td id="live-hours" style="color:var(--green);">
                <?= number_format($openShift['mins_so_far']/60, 2) ?>h
            </td>
            <td class="text-muted small"><?= htmlspecialchars($openShift['notes'] ?? '') ?></td>
        </tr>
        <?php endif; ?>
        <?php foreach ($weekRows as $row): ?>
        <?php if ($row['clock_out'] === null) continue; ?>
        <tr>
            <td style="font-weight:600;"><?= date('D M j', strtotime($row['clock_in'])) ?></td>
            <td><?= date('g:i A', strtotime($row['clock_in'])) ?></td>
            <td><?= date('g:i A', strtotime($row['clock_out'])) ?></td>
            <td><?= number_format((float)$row['hours_worked'], 2) ?>h</td>
            <td class="text-muted small"><?= htmlspecialchars($row['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
function validateClockIn(form) {
    var amt = parseFloat(form.till_amount.value);
    if (isNaN(amt) || amt < 0) {
        alert('Please enter a valid till count (e.g. 100.00) before clocking in.');
        form.till_amount.focus();
        return false;
    }
    return true;
}
function validateClockOut(form) {
    var amt = parseFloat(form.till_amount.value);
    if (isNaN(amt) || amt < 0) {
        alert('Please count the till and enter the total before clocking out.');
        form.till_amount.focus();
        return false;
    }
    return confirm('Clock out with till count of $' + amt.toFixed(2) + '?');
}

<?php if ($openShift): ?>
(function() {
    var startMs = <?= strtotime($openShift['clock_in']) * 1000 ?>;
    function update() {
        var elapsed = Math.floor((Date.now() - startMs) / 60000);
        var h = Math.floor(elapsed / 60);
        var m = elapsed % 60;
        var timerEl = document.getElementById('live-timer');
        var hoursEl = document.getElementById('live-hours');
        if (timerEl) timerEl.textContent = h + 'h ' + m + 'm';
        if (hoursEl) hoursEl.textContent = (elapsed / 60).toFixed(2) + 'h';
    }
    update();
    setInterval(update, 60000);
})();
<?php endif; ?>
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
