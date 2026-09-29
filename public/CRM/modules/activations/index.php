<?php
// ============================================================
// Wireless Prepaid Activations — Log & View
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
$isOwner    = Auth::isOwner();
$isManager  = Auth::isManager();
$locationId = (int)$user['location_id'];

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$CARRIERS = ['Chatr', 'Fizz', 'Freedom Prepaid', 'Koodo Prepaid'];

// Monthly equivalent: what counts toward daily sales quota
function monthlyEquiv(string $planType, float $amount): float {
    if ($planType === '3-month') return round($amount / 3, 2);
    if ($planType === 'yearly')  return round($amount / 12, 2);
    return $amount; // monthly — full amount
}

// ── Handle POST ───────────────────────────────────────────────
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_activation' && Auth::isOwner()) {
    $delId = intval($_POST['activation_id'] ?? 0);
    if ($delId) DB::execute('DELETE FROM activations WHERE id=?', [$delId]);
    header('Location: ' . APP_URL . '/modules/activations/?deleted=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_activation'])) {
    $carrier    = trim($_POST['carrier'] ?? '');
    $custPhone  = preg_replace('/\D/', '', trim($_POST['customer_phone'] ?? ''));
    $planType   = $_POST['plan_type'] ?? 'monthly';
    $planAmount = (float)($_POST['plan_amount'] ?? 0);
    $commission = (float)($_POST['commission'] ?? 0);
    $actDate    = $_POST['activation_date'] ?? date('Y-m-d');
    $saleId     = intval($_POST['sale_id'] ?? 0) ?: null;
    $notes      = trim($_POST['notes'] ?? '');
    $actLocId   = intval($_POST['location_id'] ?? 0) ?: intval($locationId);

    $validPlanTypes = ['monthly', '3-month', 'yearly'];
    if (!$actLocId) {
        $msg = 'Please select a location.'; $msgType = 'danger';
    } elseif (!in_array($carrier, $CARRIERS)) {
        $msg = 'Please select a valid carrier.'; $msgType = 'danger';
    } elseif (!in_array($planType, $validPlanTypes)) {
        $msg = 'Please select a valid plan type.'; $msgType = 'danger';
    } elseif (strlen($custPhone) < 10) {
        $msg = 'Please enter a valid 10-digit phone number.'; $msgType = 'danger';
    } elseif ($planAmount <= 0) {
        $msg = 'Please enter the plan amount.'; $msgType = 'danger';
    } else {
        DB::execute(
            "INSERT INTO activations
             (location_id, user_id, sale_id, carrier, customer_phone, plan_type, plan_amount, commission, activation_date, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$actLocId, $user['id'], $saleId, $carrier, $custPhone, $planType, $planAmount, $commission, $actDate, $notes ?: null]
        );
        Audit::log('activation_logged', 'activations', null, [
            'carrier' => $carrier, 'plan_type' => $planType, 'plan_amount' => $planAmount, 'commission' => $commission
        ]);
        $planTypeLabel = ['monthly'=>'Monthly','3-month'=>'3-Month','yearly'=>'Yearly'][$planType];
        $msg = "✅ Activation logged! {$carrier} · {$planTypeLabel} · \${$planAmount}"
             . ($commission > 0 ? " · \${$commission} commission" : " · No commission")
             . " — 📌 If a physical SIM was used, don't forget to log the SIM card sale.";
    }
}

// ── Filters ───────────────────────────────────────────────────
$filterLoc     = intval($_GET['location_id'] ?? 0);
$filterCarrier = $_GET['carrier'] ?? 'all';
$filterFrom    = $_GET['from'] ?? date('Y-m-01');
$filterTo      = $_GET['to']   ?? date('Y-m-d');

$where  = ['a.activation_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterLoc) { $where[] = 'a.location_id = ?'; $params[] = $filterLoc; }
if ($filterCarrier !== 'all') { $where[] = 'a.carrier = ?'; $params[] = $filterCarrier; }
$whereSQL = implode(' AND ', $where);

$activations = DB::query(
    "SELECT a.*, u.first_name, u.username,
            COALESCE(l.code, '??') AS loc_code,
            COALESCE(a.status, 'submitted') AS status
     FROM activations a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN locations l ON l.id = a.location_id
     WHERE {$whereSQL}
     ORDER BY a.activation_date DESC, a.created_at DESC",
    $params
);

$summary = DB::queryOne(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(plan_amount),0) AS plan_revenue,
            COALESCE(SUM(
                CASE plan_type
                    WHEN '3-month' THEN plan_amount / 3
                    WHEN 'yearly'  THEN plan_amount / 12
                    ELSE plan_amount
                END
            ),0) AS sales_value_total,
            COALESCE(SUM(commission),0) AS total_commission
     FROM activations a WHERE {$whereSQL}",
    $params
);

$byCarrier = DB::query(
    "SELECT carrier, COUNT(*) AS cnt,
            COALESCE(SUM(plan_amount),0) AS revenue,
            COALESCE(SUM(commission),0)  AS commission
     FROM activations a WHERE {$whereSQL} GROUP BY carrier ORDER BY cnt DESC",
    $params
);

$recentSales = DB::query(
    "SELECT id, record_number, total_amount, DATE(created_at) AS sale_date
     FROM sales WHERE location_id=? AND DATE(created_at) >= ?
     ORDER BY created_at DESC LIMIT 30",
    [$locationId, date('Y-m-d', strtotime('-7 days'))]
);

$pageTitle = 'Activations';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">📱 Wireless Activations</h1>
        <p class="page-sub">Chatr · Fizz · Freedom Prepaid · Koodo Prepaid</p>
    </div>
    <?php if ($isOwner || $isManager): ?>
    <div style="display:flex;gap:.5rem;">
        <a href="pipeline.php" class="btn btn-primary">📡 Commission Pipeline</a>
        <a href="<?= APP_URL ?>/modules/staff/paysheet.php" class="btn btn-secondary">💰 Paysheet</a>
    </div>
    <?php endif; ?>
</div>

<div class="repair-grid">
<div class="repair-col-main">

    <!-- Log Form -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header"><h2 class="card-title">➕ Log New Activation</h2></div>
        <div class="card-body">
        <form method="POST">
            <input type="hidden" name="log_activation" value="1">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">

                <div>
                    <label class="form-label">Location <span style="color:var(--red)">*</span></label>
                    <select name="location_id" class="form-control" required>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>" <?= ($loc['id']==$locationId)?'selected':'' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Carrier <span style="color:var(--red)">*</span></label>
                    <select name="carrier" class="form-control" required>
                        <option value="">-- Select Carrier --</option>
                        <?php foreach ($CARRIERS as $c): ?>
                        <option value="<?= $c ?>"><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Plan Type <span style="color:var(--red)">*</span></label>
                    <select name="plan_type" id="planType" class="form-control" required onchange="updateHint()">
                        <option value="monthly">Monthly</option>
                        <option value="3-month">3-Month</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Customer Phone # <span style="color:var(--red)">*</span></label>
                    <input type="text" name="customer_phone" class="form-control"
                           placeholder="e.g. 9056781234" maxlength="15" required>
                </div>

                <div>
                    <label class="form-label">Plan Amount <span style="color:var(--red)">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                        <input type="number" name="plan_amount" id="planAmt" class="form-control"
                               step="0.01" min="0.01" placeholder="35.00" required
                               style="padding-left:22px;" oninput="updateEquiv()">
                    </div>
                    <div id="equiv-hint" style="margin-top:4px;font-size:12px;color:var(--text-3);"></div>
                </div>

                <div>
                    <label class="form-label">Commission Earned ($)</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                        <input type="number" name="commission" class="form-control"
                               step="0.01" min="0" placeholder="0.00"
                               style="padding-left:22px;" value="0">
                    </div>
                    <div class="form-hint">Enter the commission amount (varies by carrier/plan). Leave 0 if none.</div>
                </div>

                <div>
                    <label class="form-label">Activation Date</label>
                    <input type="date" name="activation_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>

                <div>
                    <label class="form-label">Link to Sale # (optional)</label>
                    <select name="sale_id" class="form-control">
                        <option value="">-- None --</option>
                        <?php foreach ($recentSales as $s): ?>
                        <option value="<?= $s['id'] ?>">
                            <?= htmlspecialchars($s['record_number']) ?> · $<?= number_format($s['total_amount'],2) ?> (<?= date('M j', strtotime($s['sale_date'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="grid-column:1/-1;">
                    <label class="form-label">Notes (optional)</label>
                    <input type="text" name="notes" class="form-control"
                           placeholder="e.g. New SIM, port-in from Telus, etc.">
                </div>
            </div>
            <div style="margin-top:1rem;">
                <button type="submit" class="btn btn-primary">📱 Log Activation</button>
            </div>
        </form>
        </div>
    </div>

    <!-- List -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Activation Log</h2>
            <span class="text-muted small"><?= count($activations) ?> records</span>
        </div>
        <div class="card-body" style="border-bottom:1px solid var(--border);padding:.75rem 1rem;">
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
                <option value="0">All Locations</option>
                <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= $filterLoc==$loc['id']?'selected':'' ?>>
                    <?= htmlspecialchars($loc['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="carrier" class="form-control filter-select" onchange="this.form.submit()">
                <option value="all">All Carriers</option>
                <?php foreach ($CARRIERS as $c): ?>
                <option value="<?= $c ?>" <?= $filterCarrier===$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" class="form-control" value="<?= $filterFrom ?>" style="width:auto;">
            <span class="text-muted">to</span>
            <input type="date" name="to"   class="form-control" value="<?= $filterTo ?>"   style="width:auto;">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a href="?" class="btn btn-ghost">Reset</a>
        </form>
        </div>

        <?php if (empty($activations)): ?>
        <div class="card-body">
            <div class="empty-state"><div class="empty-icon">📱</div><p>No activations found.</p></div>
        </div>
        <?php else: ?>
        <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th><th>Staff</th>
                    <?php if (!$filterLoc): ?><th>Loc</th><?php endif; ?>
                    <th>Carrier</th><th>Plan Type</th><th>Phone #</th><th>Plan $</th><th>Sales Value</th><th>Commission</th><th>Status</th><th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($activations as $a): ?>
            <tr>
                <td class="small" style="white-space:nowrap;"><?= date('D M j', strtotime($a['activation_date'])) ?></td>
                <td>
                    <span style="font-weight:600;"><?= htmlspecialchars($a['first_name']) ?></span>
                    <span class="text-muted small" style="margin-left:4px;"><?= htmlspecialchars($a['username']) ?></span>
                </td>
                <?php if (!$filterLoc): ?>
                <td><span class="badge badge-loc"><?= htmlspecialchars($a['loc_code']) ?></span></td>
                <?php endif; ?>
                <td style="font-weight:600;"><?= htmlspecialchars($a['carrier']) ?></td>
                <td>
                    <?php
                    $ptLabel = ['monthly'=>'Monthly','3-month'=>'3-Month','yearly'=>'Yearly'][$a['plan_type']] ?? $a['plan_type'];
                    $ptColor = $a['plan_type']==='monthly' ? 'var(--blue)' : 'var(--text-3)';
                    ?>
                    <span style="font-size:12px;color:<?= $ptColor ?>;font-weight:600;"><?= $ptLabel ?></span>
                </td>
                <td style="font-family:monospace;font-size:13px;"><?= formatPhone($a['customer_phone']) ?></td>
                <td class="text-muted small">$<?= number_format($a['plan_amount'], 2) ?></td>
                <td style="font-weight:700;">
                    <?php $equiv = monthlyEquiv($a['plan_type'], $a['plan_amount']); ?>
                    $<?= number_format($equiv, 2) ?>
                    <?php if ($a['plan_type'] !== 'monthly'): ?>
                    <div style="font-size:11px;color:var(--text-3);font-weight:400;">
                        ÷<?= $a['plan_type']==='yearly'?'12':'3' ?> months
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($a['commission'] > 0): ?>
                    <span class="badge badge-success">+$<?= number_format($a['commission'], 2) ?></span>
                    <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                </td>
                <td style="font-size:11px;">
                    <?php
                    $st = $a['status'] ?? 'submitted';
                    $stColors = ['submitted'=>'var(--text-3)','manager_verified'=>'var(--text-3)','pending_45day'=>'var(--blue)','payable'=>'var(--green)','paid'=>'var(--text-3)','cancelled'=>'var(--red)'];
                    $stLabels = ['submitted'=>'⏳ New','manager_verified'=>'✓ Verified','pending_45day'=>'🕐 45-Day','payable'=>'💰 Payable','paid'=>'✅ Paid','cancelled'=>'❌ Cancelled'];
                    ?>
                    <span style="color:<?= $stColors[$st] ?? 'var(--text-3)' ?>;font-weight:600;">
                        <?= $stLabels[$st] ?? $st ?>
                    </span>
                </td>
                <td class="text-muted small"><?= htmlspecialchars($a['notes'] ?? '') ?></td>
                <?php if (Auth::isOwner()): ?>
                <td onclick="event.stopPropagation()">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action"        value="delete_activation">
                        <input type="hidden" name="activation_id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Delete this activation? This cannot be undone.')"
                                style="padding:.2rem .55rem;font-size:12px;">🗑</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Right sidebar -->
<div class="repair-col-side">
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h2 class="card-title">📊 Period Summary</h2></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:.75rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span class="text-muted">Total Activations</span>
                <span style="font-weight:700;font-size:1.2rem;"><?= intval($summary['total'] ?? 0) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span class="text-muted">
                    Monthly Sales Value
                    <div style="font-size:11px;color:var(--text-3);">÷3 or ÷12 for multi-month plans</div>
                </span>
                <span style="font-weight:700;color:var(--blue);font-size:1.2rem;">$<?= number_format($summary['sales_value_total'] ?? 0, 2) ?></span>
            </div>
            <hr style="border:none;border-top:1px solid var(--border);margin:.25rem 0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span class="text-muted">Total Commissions</span>
                <span style="font-weight:700;font-size:1.3rem;color:var(--green);">
                    $<?= number_format($summary['total_commission'] ?? 0, 2) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">By Carrier</h2></div>
        <div class="card-body" style="padding:0;">
        <?php if (empty($byCarrier)): ?>
        <div style="padding:1rem;" class="text-muted small">No data.</div>
        <?php else:
            $maxCnt = max(array_column($byCarrier, 'cnt')) ?: 1;
            foreach ($byCarrier as $bc): ?>
        <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border);">
            <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                <span style="font-weight:600;"><?= htmlspecialchars($bc['carrier']) ?></span>
                <span class="text-muted small"><?= $bc['cnt'] ?> act.</span>
            </div>
            <div style="background:var(--surface-2);border-radius:4px;height:5px;overflow:hidden;margin-bottom:3px;">
                <div style="height:100%;width:<?= round(($bc['cnt']/$maxCnt)*100) ?>%;background:var(--blue);"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-3);">
                <span>$<?= number_format($bc['revenue'],2) ?> rev.</span>
                <span style="color:<?= $bc['commission']>0?'var(--green)':'inherit' ?>;">
                    $<?= number_format($bc['commission'],2) ?> comm.
                </span>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>
</div>

<script>
function updateEquiv() {
    var el   = document.getElementById('equiv-hint');
    var amt  = parseFloat(document.getElementById('planAmt').value);
    var type = document.getElementById('planType').value;
    if (!amt || isNaN(amt)) { el.textContent = ''; return; }
    if (type === '3-month') {
        el.textContent = 'Monthly sales value: $' + (amt / 3).toFixed(2) + ' (÷3 months)';
    } else if (type === 'yearly') {
        el.textContent = 'Monthly sales value: $' + (amt / 12).toFixed(2) + ' (÷12 months)';
    } else {
        el.textContent = '';
    }
}
document.getElementById('planType').addEventListener('change', updateEquiv);
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
