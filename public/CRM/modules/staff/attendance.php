<?php
// ============================================================
// Staff Attendance — Manager / Owner View
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/staff/clock.php'); exit;
}

$user     = Auth::user();
$isOwner  = Auth::isOwner();

// ── Handle POST: edit shift hours ─────────────────────────
$msg = ''; $msgType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_clockout') {
    $shiftId = intval($_POST['shift_id']);
    if ($shiftId) {
        try {
            $shift = DB::queryOne("SELECT id, clock_in FROM staff_attendance WHERE id=? LIMIT 1", [$shiftId]);
            if ($shift) {
                $clockIn  = strtotime($shift['clock_in']);
                $closeAt  = time();
                $hrs      = round(($closeAt - $clockIn) / 3600, 2);
                DB::execute(
                    "UPDATE staff_attendance SET clock_out=NOW(), hours_worked=?,
                     notes=CONCAT(COALESCE(notes,''), ' [FORCE CLOCKED OUT BY MANAGER]') WHERE id=?",
                    [$hrs, $shiftId]
                );
                $msg = 'Staff member has been clocked out. Hours logged: ' . number_format($hrs, 2) . 'h. Please review and correct if needed.';
            }
        } catch (\Throwable $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_shift') {
    $shiftId    = intval($_POST['shift_id']);
    $newClockIn = trim($_POST['clock_in']  ?? '');
    $newClockOut= trim($_POST['clock_out'] ?? '');
    $editNotes  = trim($_POST['notes']     ?? '');

    if ($shiftId && $newClockIn) {
        try {
            if ($newClockOut) {
                $inTs  = strtotime($newClockIn);
                $outTs = strtotime($newClockOut);
                $hrs   = $outTs > $inTs ? round(($outTs - $inTs) / 3600, 2) : 0;
                DB::execute(
                    "UPDATE staff_attendance
                     SET clock_in=?, clock_out=?, hours_worked=?, notes=COALESCE(NULLIF(?, ''), notes)
                     WHERE id=?",
                    [$newClockIn, $newClockOut, $hrs, $editNotes, $shiftId]
                );
                $msg = 'Shift updated. Hours recalculated: ' . number_format($hrs, 2) . 'h.';
            } else {
                DB::execute(
                    "UPDATE staff_attendance
                     SET clock_in=?, clock_out=NULL, hours_worked=NULL, notes=COALESCE(NULLIF(?, ''), notes)
                     WHERE id=?",
                    [$newClockIn, $editNotes, $shiftId]
                );
                $msg = 'Shift updated (still open — no clock-out set).';
            }
        } catch (\Throwable $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    } else {
        $msg = 'Invalid shift data.'; $msgType = 'warning';
    }
}

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// Filters
$locationId = intval($_GET['location_id'] ?? ($isOwner ? 0 : $user['location_id']));
if (!$isOwner) $locationId = (int)$user['location_id'];

$view      = $_GET['view'] ?? 'week';   // week | month | day
$dateParam = $_GET['date'] ?? date('Y-m-d');
$dateTs    = strtotime($dateParam);

if ($view === 'week') {
    // ISO week: Monday–Sunday
    $periodStart = date('Y-m-d', strtotime('monday this week', $dateTs));
    $periodEnd   = date('Y-m-d', strtotime('sunday this week', $dateTs));
    $periodLabel = date('M j', strtotime($periodStart)) . ' – ' . date('M j, Y', strtotime($periodEnd));
    $prevDate    = date('Y-m-d', strtotime($periodStart . ' -7 days'));
    $nextDate    = date('Y-m-d', strtotime($periodStart . ' +7 days'));
} elseif ($view === 'month') {
    $periodStart = date('Y-m-01', $dateTs);
    $periodEnd   = date('Y-m-t',  $dateTs);
    $periodLabel = date('F Y', $dateTs);
    $prevDate    = date('Y-m-d', strtotime($periodStart . ' -1 month'));
    $nextDate    = date('Y-m-d', strtotime($periodStart . ' +1 month'));
} else { // day
    $periodStart = date('Y-m-d', $dateTs);
    $periodEnd   = $periodStart;
    $periodLabel = date('l, M j Y', $dateTs);
    $prevDate    = date('Y-m-d', $dateTs - 86400);
    $nextDate    = date('Y-m-d', $dateTs + 86400);
}

$locWhere  = $locationId ? 'AND sa.location_id = ' . $locationId : '';
$params    = [$periodStart, $periodEnd];

// Currently clocked in (live)
$clockedInNow = DB::query(
    "SELECT sa.*, u.first_name, u.username, r.name AS role,
            l.code AS loc_code,
            TIMESTAMPDIFF(MINUTE, sa.clock_in, NOW()) AS mins_so_far,
            cd.opening_amount AS drawer_open, cd.actual_close AS drawer_close
     FROM staff_attendance sa
     JOIN users u ON u.id = sa.user_id
     JOIN roles r ON r.id = u.role_id
     JOIN locations l ON l.id = sa.location_id
     LEFT JOIN cash_drawers cd ON cd.location_id = sa.location_id
                               AND cd.drawer_date = DATE(sa.clock_in)
     WHERE sa.clock_out IS NULL
     " . ($locationId ? "AND sa.location_id={$locationId}" : '') . "
     ORDER BY sa.clock_in ASC",
    []
);

// Attendance records for the period
$records = DB::query(
    "SELECT sa.*,
            u.first_name, u.username, r.name AS role,
            l.code AS loc_code, l.name AS loc_name,
            DATE(sa.clock_in) AS work_date,
            cd.opening_amount AS drawer_open, cd.actual_close AS drawer_close, cd.status AS drawer_status
     FROM staff_attendance sa
     JOIN users u ON u.id = sa.user_id
     JOIN roles r ON r.id = u.role_id
     JOIN locations l ON l.id = sa.location_id
     LEFT JOIN cash_drawers cd ON cd.location_id = sa.location_id
                               AND cd.drawer_date = DATE(sa.clock_in)
     WHERE DATE(sa.clock_in) BETWEEN ? AND ?
       {$locWhere}
     ORDER BY sa.clock_in DESC",
    $params
);

// Summary per staff member
$byStaff = [];
foreach ($records as $r) {
    $uid = $r['user_id'];
    if (!isset($byStaff[$uid])) {
        $byStaff[$uid] = [
            'first_name' => $r['first_name'],
            'username'   => $r['username'],
            'role'       => $r['role'],
            'loc_code'   => $r['loc_code'],
            'shifts'     => 0,
            'hours'      => 0,
        ];
    }
    $byStaff[$uid]['shifts']++;
    $byStaff[$uid]['hours'] += (float)$r['hours_worked'];
}
usort($byStaff, fn($a,$b) => $b['hours'] <=> $a['hours']);

$totalHours  = array_sum(array_column($records, 'hours_worked'));
$totalShifts = count(array_filter($records, fn($r) => $r['clock_out'] !== null));

$pageTitle = 'Staff Attendance';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">📋 Staff Attendance</h1>
        <p class="page-sub"><?= htmlspecialchars($periodLabel) ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="clock.php" class="btn btn-ghost">🕐 My Clock</a>
        <?php if ($isOwner || Auth::isManager()): ?>
        <a href="?view=<?= $view ?>&date=<?= $prevDate ?>&location_id=<?= $locationId ?>" class="btn btn-ghost">← Prev</a>
        <a href="?view=<?= $view ?>&date=<?= $nextDate ?>&location_id=<?= $locationId ?>" class="btn btn-ghost">Next →</a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
    <?php if ($isOwner): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $locationId==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php else: ?>
    <input type="hidden" name="location_id" value="<?= $locationId ?>">
    <?php endif; ?>
    <div class="btn-group">
        <?php foreach (['day'=>'Day','week'=>'Week','month'=>'Month'] as $v=>$l): ?>
        <a href="?view=<?= $v ?>&date=<?= $dateParam ?>&location_id=<?= $locationId ?>"
           class="btn btn-sm <?= $view===$v?'btn-primary':'btn-secondary' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
    <input type="date" name="date" class="form-control" value="<?= $dateParam ?>" style="width:auto;" onchange="this.form.submit()">
    <input type="hidden" name="view" value="<?= $view ?>">
</form>

<!-- Currently Clocked In -->
<?php if (!empty($clockedInNow)): ?>
<div class="card" style="margin-bottom:1.5rem;border:2px solid var(--green);">
    <div class="card-header">
        <h2 class="card-title" style="color:var(--green);">
            🟢 Currently Clocked In (<?= count($clockedInNow) ?>)
        </h2>
    </div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead><tr><th>Staff</th><th>Location</th><th>Clocked In At</th><th>Duration</th><th>Opening Till</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($clockedInNow as $r):
            $isStale = (int)$r['mins_so_far'] > 720; // >12 hours
        ?>
        <tr style="<?= $isStale ? 'background:rgba(239,68,68,.06);' : '' ?>">
            <td>
                <div style="font-weight:600;"><?= htmlspecialchars($r['first_name']) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($r['username']) ?> · <?= ucfirst($r['role']) ?></div>
            </td>
            <td><span class="badge badge-loc"><?= htmlspecialchars($r['loc_code']) ?></span></td>
            <td>
                <?= date('D g:i A', strtotime($r['clock_in'])) ?>
                <?php if ($isStale): ?>
                <div style="font-size:11px;color:var(--red);font-weight:600;">⚠️ Forgot to clock out?</div>
                <?php endif; ?>
            </td>
            <td style="color:<?= $isStale ? 'var(--red)' : 'var(--green)' ?>;font-weight:600;">
                <?php $m = (int)$r['mins_so_far']; echo floor($m/60) . 'h ' . ($m%60) . 'm'; ?>
            </td>
            <td style="font-weight:600;">
                <?= $r['drawer_open'] !== null ? '$' . number_format($r['drawer_open'], 2) : '<span class="text-muted small">—</span>' ?>
            </td>
            <td>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Force clock out <?= htmlspecialchars($r['first_name']) ?>? You can edit the exact time after.')">
                    <input type="hidden" name="action"   value="force_clockout">
                    <input type="hidden" name="shift_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Force Out</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info" style="margin-bottom:1.5rem;">⚪ No staff currently clocked in.</div>
<?php endif; ?>

<!-- Period Summary -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">
    <div class="metric-card accent-blue">
        <div class="metric-label">Total Hours</div>
        <div class="metric-value"><?= number_format($totalHours, 1) ?>h</div>
        <div class="metric-sub">Paid time logged</div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Completed Shifts</div>
        <div class="metric-value"><?= $totalShifts ?></div>
        <div class="metric-sub">Clock-outs recorded</div>
    </div>
    <div class="metric-card accent-green">
        <div class="metric-label">Staff Working</div>
        <div class="metric-value"><?= count($byStaff) ?></div>
        <div class="metric-sub">Unique employees</div>
    </div>
    <?php if ($totalShifts > 0): ?>
    <div class="metric-card accent-blue">
        <div class="metric-label">Avg Shift Length</div>
        <div class="metric-value"><?= number_format($totalHours / $totalShifts, 1) ?>h</div>
        <div class="metric-sub">Per completed shift</div>
    </div>
    <?php endif; ?>
</div>

<div class="repair-grid">
<div class="repair-col-main">

    <!-- Detailed Records -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Shift Records</h2>
            <span class="text-muted small"><?= count($records) ?> records</span>
        </div>
        <?php if (empty($records)): ?>
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-icon">📅</div>
                <p>No attendance records found for this period.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr><th>Date</th><th>Staff</th><th>Loc</th><th>In</th><th>Out</th><th>Hours</th><th>Opening Till</th><th>Closing Till</th><th>Notes</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($records as $r): ?>
            <tr <?= $r['clock_out'] === null ? 'style="background:rgba(34,197,94,.05);"' : '' ?>>
                <td class="text-muted small" style="white-space:nowrap;">
                    <?= date('D M j', strtotime($r['work_date'])) ?>
                </td>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($r['first_name']) ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($r['username']) ?></div>
                </td>
                <td><span class="badge badge-loc"><?= htmlspecialchars($r['loc_code']) ?></span></td>
                <td class="small"><?= date('g:i A', strtotime($r['clock_in'])) ?></td>
                <td class="small">
                    <?php if ($r['clock_out']): ?>
                        <?= date('g:i A', strtotime($r['clock_out'])) ?>
                    <?php else: ?>
                        <span style="color:var(--green);font-weight:600;">● Active</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;">
                    <?php if ($r['clock_out']): ?>
                        <?= number_format((float)$r['hours_worked'], 2) ?>h
                    <?php else: ?>
                        <span class="text-muted small">In progress</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;color:var(--green);">
                    <?= $r['drawer_open'] !== null ? '$' . number_format($r['drawer_open'], 2) : '<span class="text-muted small">—</span>' ?>
                </td>
                <td style="font-weight:600;<?= $r['drawer_close'] !== null ? 'color:var(--blue);' : '' ?>">
                    <?php if ($r['drawer_close'] !== null): ?>
                        $<?= number_format($r['drawer_close'], 2) ?>
                    <?php elseif ($r['drawer_status'] === 'open'): ?>
                        <span class="text-muted small">Open</span>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small"><?= htmlspecialchars($r['notes'] ?? '') ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-ghost"
                        onclick="openEditShift(
                            <?= $r['id'] ?>,
                            '<?= addslashes(date('Y-m-d\TH:i', strtotime($r['clock_in']))) ?>',
                            '<?= $r['clock_out'] ? addslashes(date('Y-m-d\TH:i', strtotime($r['clock_out']))) : '' ?>',
                            '<?= addslashes(htmlspecialchars_decode($r['notes'] ?? '')) ?>'
                        )">✏️</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Right: Per-staff summary -->
<div class="repair-col-side">
    <div class="card">
        <div class="card-header"><h2 class="card-title">Hours by Staff</h2></div>
        <div class="card-body" style="padding:0;">
        <?php if (empty($byStaff)): ?>
        <div style="padding:1rem;" class="text-muted small">No data.</div>
        <?php else:
            $maxH = max(array_column($byStaff, 'hours')) ?: 1;
        foreach ($byStaff as $uid => $s): ?>
        <div style="padding:.7rem 1rem;border-bottom:1px solid var(--border);">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                <div>
                    <span style="font-weight:600;"><?= htmlspecialchars($s['first_name']) ?></span>
                    <span class="text-muted small" style="margin-left:4px;"><?= htmlspecialchars($s['username']) ?></span>
                </div>
                <span class="badge badge-loc"><?= htmlspecialchars($s['loc_code']) ?></span>
            </div>
            <div style="background:var(--surface-2);border-radius:4px;height:6px;overflow:hidden;margin-bottom:3px;">
                <div style="height:100%;width:<?= round(($s['hours']/$maxH)*100) ?>%;background:var(--blue);"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-3);">
                <span><?= number_format($s['hours'],2) ?>h logged</span>
                <span><?= $s['shifts'] ?> shift<?= $s['shifts']!=1?'s':'' ?></span>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>
</div>

<!-- Auto-refresh while someone is clocked in -->
<?php if (!empty($clockedInNow)): ?>
<script>setTimeout(() => location.reload(), 120000);</script>
<?php endif; ?>

<!-- Edit Shift Modal -->
<div id="editShiftModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:12px;padding:1.5rem;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 1rem;font-size:1.1rem;">✏️ Edit Shift</h3>
        <form method="POST" id="editShiftForm">
            <input type="hidden" name="action"   value="edit_shift">
            <input type="hidden" name="shift_id" id="edit_shift_id">
            <div class="form-group">
                <label class="form-label">Clock In <span style="color:var(--red)">*</span></label>
                <input type="datetime-local" name="clock_in" id="edit_clock_in" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Clock Out <span class="text-muted small">(leave blank if still open)</span></label>
                <input type="datetime-local" name="clock_out" id="edit_clock_out" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" id="edit_notes" class="form-control" placeholder="Optional">
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="closeEditShift()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditShift(id, clockIn, clockOut, notes) {
    document.getElementById('edit_shift_id').value  = id;
    document.getElementById('edit_clock_in').value  = clockIn;
    document.getElementById('edit_clock_out').value = clockOut;
    document.getElementById('edit_notes').value     = notes;
    var modal = document.getElementById('editShiftModal');
    modal.style.display = 'flex';
}
function closeEditShift() {
    document.getElementById('editShiftModal').style.display = 'none';
}
// Close on backdrop click
document.getElementById('editShiftModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditShift();
});
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
