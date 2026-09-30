<?php
// ============================================================
// Activation Commission Pipeline
// Manager/Owner: verify activations, mark payable, mark paid
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/Audit.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/activations/index.php'); exit;
}

$user    = Auth::user();
$isOwner = Auth::isOwner();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$msg = ''; $msgType = 'success';

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ids    = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));

    if ($action === 'verify' && !empty($ids)) {
        foreach ($ids as $id) {
            $act = DB::queryOne("SELECT * FROM activations WHERE id=? AND status='submitted'", [$id]);
            if (!$act) continue;
            // 45-day pending starts from activation_date
            $payableAt = date('Y-m-d H:i:s', strtotime($act['activation_date'] . ' +45 days'));
            DB::execute(
                "UPDATE activations SET status='pending_45day', verified_by=?, verified_at=NOW(), payable_at=?
                 WHERE id=? AND status='submitted'",
                [$user['id'], $payableAt, $id]
            );
            Audit::log('activation_verified', 'activations', $id, ['verified_by' => $user['id']]);
        }
        $msg = count($ids) . ' activation(s) verified — 45-day clock started.';

    } elseif ($action === 'mark_payable' && !empty($ids)) {
        foreach ($ids as $id) {
            DB::execute(
                "UPDATE activations SET status='payable', payable_at=NOW()
                 WHERE id=? AND status='pending_45day'",
                [$id]
            );
        }
        $msg = count($ids) . ' activation(s) marked payable.';

    } elseif ($action === 'mark_paid' && !empty($ids)) {
        foreach ($ids as $id) {
            DB::execute(
                "UPDATE activations SET status='paid', paid_by=?, paid_at=NOW()
                 WHERE id=? AND status='payable'",
                [$user['id'], $id]
            );
            Audit::log('activation_paid', 'activations', $id, ['paid_by' => $user['id']]);
        }
        $msg = count($ids) . ' commission(s) marked as paid.';

    } elseif ($action === 'cancel') {
        $id     = intval($_POST['ids'] ?? 0);
        $reason = trim($_POST['cancel_reason'] ?? '');
        if ($id && $reason) {
            DB::execute(
                "UPDATE activations SET status='cancelled', cancel_reason=? WHERE id=?",
                [$reason, $id]
            );
            Audit::log('activation_cancelled', 'activations', $id, ['reason' => $reason]);
            $msg = 'Activation cancelled.';
        } else {
            $msg = 'Cancellation reason is required.'; $msgType = 'warning';
        }

    } elseif ($action === 'delete_activation' && $isOwner) {
        $id = intval($_POST['ids'] ?? 0);
        if ($id) DB::execute('DELETE FROM activations WHERE id=?', [$id]);
        $msg = 'Activation deleted.';
    }
}

// ── Auto-promote pending_45day → payable (past 45 days) ─────
DB::execute(
    "UPDATE activations SET status='payable'
     WHERE status='pending_45day' AND payable_at IS NOT NULL AND payable_at <= NOW()"
);

// ── Filters ──────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$isManager    = Auth::isManager();
$filterLoc    = $isOwner ? intval($_GET['location_id'] ?? 0) : Auth::workingLocationId();
$filterFrom   = $_GET['from'] ?? date('Y-m-01');
$filterTo     = $_GET['to']   ?? date('Y-m-d');
$filterStaff  = intval($_GET['user_id'] ?? 0);

$where  = ['a.activation_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];

if ($filterLoc)                   { $where[] = 'a.location_id=?'; $params[] = $filterLoc; }
elseif (!$isOwner) { $where[] = 'a.location_id=?'; $params[] = $user['location_id']; }
if ($filterStatus !== 'all') { $where[] = 'a.status=?'; $params[] = $filterStatus; }
if ($filterStaff) { $where[] = 'a.user_id=?'; $params[] = $filterStaff; }

$whereSQL = implode(' AND ', $where);

$activations = DB::query(
    "SELECT a.*, u.first_name, u.username, l.code AS loc_code,
            vb.first_name AS verified_name,
            pb.first_name AS paid_name,
            DATEDIFF(a.payable_at, NOW()) AS days_until_payable
     FROM activations a
     JOIN users u ON u.id = a.user_id
     JOIN locations l ON l.id = a.location_id
     LEFT JOIN users vb ON vb.id = a.verified_by
     LEFT JOIN users pb ON pb.id = a.paid_by
     WHERE {$whereSQL}
     ORDER BY a.activation_date DESC, a.created_at DESC",
    $params
);

// Summary counts (no date filter for pipeline overview)
$pipelineCounts = DB::query(
    "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(commission),0) AS total_comm
     FROM activations
     WHERE 1=1" . (!$isOwner ? " AND location_id={$user['location_id']}" : "") . "
     GROUP BY status"
);
$statusCounts = [];
foreach ($pipelineCounts as $r) $statusCounts[$r['status']] = $r;

$allStaff = DB::query(
    "SELECT DISTINCT u.id, u.first_name, u.username
     FROM activations a JOIN users u ON u.id=a.user_id
     " . (!$isOwner ? "WHERE a.location_id={$user['location_id']}" : "") . "
     ORDER BY u.first_name"
);

$STATUS_LABELS = [
    'submitted'        => ['label'=>'Awaiting Verification', 'color'=>'var(--amber)',  'icon'=>'⏳'],
    'pending_45day'    => ['label'=>'45-Day Pending',        'color'=>'var(--blue)',   'icon'=>'🕐'],
    'payable'          => ['label'=>'Payable',               'color'=>'var(--green)',  'icon'=>'💰'],
    'paid'             => ['label'=>'Paid',                  'color'=>'var(--text-3)', 'icon'=>'✅'],
    'cancelled'        => ['label'=>'Cancelled',             'color'=>'var(--red)',    'icon'=>'❌'],
];

$pageTitle = 'Commission Pipeline';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">📡 Commission Pipeline</h1>
        <p class="page-sub">Chatr · Fizz · Freedom Prepaid · Koodo Prepaid</p>
    </div>
    <a href="index.php" class="btn btn-secondary">← Activations Log</a>
</div>

<!-- Pipeline status overview -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">
<?php foreach ($STATUS_LABELS as $s => $info):
    $cnt  = (int)($statusCounts[$s]['cnt']  ?? 0);
    $comm = (float)($statusCounts[$s]['total_comm'] ?? 0);
?>
<div class="metric-card" style="cursor:pointer;" onclick="window.location='?status=<?= $s ?>&from=<?= $filterFrom ?>&to=<?= $filterTo ?>'">
    <div class="metric-label"><?= $info['icon'] ?> <?= $info['label'] ?></div>
    <div class="metric-value" style="color:<?= $info['color'] ?>;"><?= $cnt ?></div>
    <?php if ($comm > 0): ?>
    <div class="metric-sub" style="color:var(--green);">$<?= number_format($comm,2) ?> commission</div>
    <?php else: ?>
    <div class="metric-sub">activations</div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
    <select name="status" class="form-control filter-select" onchange="this.form.submit()">
        <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All Statuses</option>
        <?php foreach ($STATUS_LABELS as $s => $info): ?>
        <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= $info['icon'] ?> <?= $info['label'] ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($isOwner): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLoc==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="user_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Staff</option>
        <?php foreach ($allStaff as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $filterStaff==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['first_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="from" class="form-control" value="<?= $filterFrom ?>" style="width:auto;">
    <span class="text-muted">to</span>
    <input type="date" name="to"   class="form-control" value="<?= $filterTo ?>"   style="width:auto;">
    <button type="submit" class="btn btn-secondary">Filter</button>
    <a href="?" class="btn btn-ghost">Reset</a>
</form>

<?php if (empty($activations)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state"><div class="empty-icon">📡</div><p>No activations found.</p></div>
</div></div>
<?php else: ?>

<!-- Bulk action form -->
<form method="POST" id="pipeline-form">
<div class="card">
    <div class="card-header" style="justify-content:space-between;">
        <h2 class="card-title">Activations (<?= count($activations) ?>)</h2>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button type="submit" name="action" value="verify"
                class="btn btn-sm btn-secondary"
                onclick="return confirm('Verify selected activations? This starts the 45-day clock.')">
                ✅ Verify Selected
            </button>
            <button type="submit" name="action" value="mark_payable"
                class="btn btn-sm btn-secondary"
                onclick="return confirm('Mark selected as payable?')">
                💰 Mark Payable
            </button>
            <button type="submit" name="action" value="mark_paid"
                class="btn btn-primary btn-sm"
                onclick="return confirm('Mark selected commissions as PAID?')">
                ✅ Mark Paid
            </button>
        </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="data-table" style="min-width:900px;">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="check-all" onchange="document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked)"></th>
                <th>Date</th>
                <th>Staff</th>
                <?php if ($isOwner): ?><th>Loc</th><?php endif; ?>
                <th>Carrier</th>
                <th>Plan</th>
                <th>Phone #</th>
                <th>Commission</th>
                <th>Status</th>
                <th>Timeline</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($activations as $a):
            $info = $STATUS_LABELS[$a['status']] ?? ['label'=>$a['status'],'color'=>'var(--text)','icon'=>''];
            $ptLabel = ['monthly'=>'Monthly','3-month'=>'3-Month','yearly'=>'Yearly'][$a['plan_type']] ?? $a['plan_type'];
        ?>
        <tr>
            <td><input type="checkbox" class="row-check" name="ids[]" value="<?= $a['id'] ?>"></td>
            <td class="small" style="white-space:nowrap;"><?= date('M j, Y', strtotime($a['activation_date'])) ?></td>
            <td>
                <span style="font-weight:600;"><?= htmlspecialchars($a['first_name']) ?></span>
                <span class="text-muted small"> <?= htmlspecialchars($a['username']) ?></span>
            </td>
            <?php if ($isOwner): ?>
            <td><span class="badge badge-loc"><?= htmlspecialchars($a['loc_code']) ?></span></td>
            <?php endif; ?>
            <td style="font-weight:600;"><?= htmlspecialchars($a['carrier']) ?></td>
            <td>
                <div style="font-size:12px;"><?= $ptLabel ?> · $<?= number_format($a['plan_amount'],2) ?></div>
            </td>
            <td style="font-family:monospace;font-size:12px;"><?= formatPhone($a['customer_phone']) ?></td>
            <td>
                <?php if ($a['commission'] > 0): ?>
                <span style="color:var(--green);font-weight:700;">$<?= number_format($a['commission'],2) ?></span>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td>
                <span style="color:<?= $info['color'] ?>;font-weight:600;font-size:12px;">
                    <?= $info['icon'] ?> <?= $info['label'] ?>
                </span>
            </td>
            <td style="font-size:11px;color:var(--text-3);">
                <?php if ($a['status'] === 'pending_45day' && $a['payable_at']): ?>
                    <?php $daysLeft = (int)$a['days_until_payable']; ?>
                    <?php if ($daysLeft > 0): ?>
                    <span style="color:var(--blue);"><?= $daysLeft ?> days left</span><br>
                    <?php else: ?>
                    <span style="color:var(--green);">Ready!</span><br>
                    <?php endif; ?>
                    Payable: <?= date('M j', strtotime($a['payable_at'])) ?>
                <?php elseif ($a['status'] === 'submitted'): ?>
                    Logged <?= date('M j', strtotime($a['created_at'])) ?>
                <?php elseif ($a['status'] === 'payable'): ?>
                    Since <?= date('M j', strtotime($a['payable_at'])) ?>
                <?php elseif ($a['status'] === 'paid' && $a['paid_at']): ?>
                    Paid <?= date('M j', strtotime($a['paid_at'])) ?><br>
                    by <?= htmlspecialchars($a['paid_name'] ?? '—') ?>
                <?php elseif ($a['status'] === 'cancelled'): ?>
                    <?= htmlspecialchars($a['cancel_reason'] ?? '') ?>
                <?php endif; ?>
            </td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap;">
                <?php if ($a['status'] !== 'paid' && $a['status'] !== 'cancelled'): ?>
                <button type="button" class="btn btn-sm btn-ghost"
                    onclick="showCancel(<?= $a['id'] ?>)"
                    style="color:var(--red);font-size:11px;">Cancel</button>
                <?php endif; ?>
                <?php if ($isOwner): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_activation">
                    <input type="hidden" name="ids"    value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('Permanently delete this activation?')"
                            style="padding:.2rem .55rem;font-size:11px;">🗑</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</form>

<!-- Cancel modal -->
<div id="cancel-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--surface);border-radius:12px;padding:1.5rem;max-width:400px;width:90%;">
    <h3 style="margin-bottom:1rem;">Cancel Activation</h3>
    <form method="POST">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="ids" id="cancel-id">
        <div class="form-group">
            <label class="form-label">Reason <span style="color:var(--red)">*</span></label>
            <select name="cancel_reason" class="form-control" required>
                <option value="">— Select reason —</option>
                <option value="Cancelled within 45 days">Cancelled within 45 days</option>
                <option value="Plan reversed by carrier">Plan reversed by carrier</option>
                <option value="Customer returned/rejected plan">Customer returned/rejected plan</option>
                <option value="Logged in error">Logged in error</option>
                <option value="Duplicate entry">Duplicate entry</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <div style="display:flex;gap:.5rem;margin-top:1rem;">
            <button type="submit" class="btn btn-danger">Confirm Cancel</button>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('cancel-modal').style.display='none'">Close</button>
        </div>
    </form>
</div>
</div>

<?php endif; ?>

<script>
function showCancel(id) {
    document.getElementById('cancel-id').value = id;
    document.getElementById('cancel-modal').style.display = 'flex';
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
