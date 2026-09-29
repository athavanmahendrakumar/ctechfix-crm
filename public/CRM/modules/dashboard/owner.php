<?php
// ============================================================
// Owner Dashboard
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::requireRole('owner');

$user       = Auth::user();
$isTraining = Auth::isTraining();

// Live counts — keyed by location code
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);
$locMap    = [];
foreach ($locations as $l) {
    $locMap[$l['code']] = $l['id'];
}

$osId = $locMap['OS'] ?? 0;
$pfId = $locMap['PF'] ?? 0;

$data = [
    'missed_calls_os'   => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM call_logs
         WHERE location_id=? AND status='missed' AND direction='inbound'
           AND needs_callback=1 AND callback_status='pending'",
        [$osId])['c'] ?? 0),
    'missed_calls_pf'   => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM call_logs
         WHERE location_id=? AND status='missed' AND direction='inbound'
           AND needs_callback=1 AND callback_status='pending'",
        [$pfId])['c'] ?? 0),
    'repairs_active_os' => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM repairs
         WHERE location_id=? AND status NOT IN ('completed','cancelled','closed')",
        [$osId])['c'] ?? 0),
    'repairs_active_pf' => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM repairs
         WHERE location_id=? AND status NOT IN ('completed','cancelled','closed')",
        [$pfId])['c'] ?? 0),
    'repairs_overdue'   => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM repairs
         WHERE status NOT IN ('completed','cancelled','closed')
           AND estimated_ready_at IS NOT NULL AND estimated_ready_at < NOW()")['c'] ?? 0),
    'follow_ups_due'    => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM follow_ups
         WHERE status IN ('pending','in_progress') AND due_at <= NOW()")['c'] ?? 0),
    'failed_sms'        => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM sms_messages
         WHERE status='failed' AND (sent_manually IS NULL OR sent_manually=0)")['c'] ?? 0),
    'inventory_alerts'  => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM inventory_stock
         WHERE quantity <= min_quantity AND min_quantity > 0")['c'] ?? 0),
    'staff_clocked_in'  => (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM staff_attendance WHERE clock_out IS NULL")['c'] ?? 0),
];

// Monthly targets vs actuals (for each location)
$monthTargets = [];
foreach ($locations as $loc) {
    $target = DB::queryOne(
        "SELECT * FROM sales_targets
         WHERE location_id=? AND period_type='monthly'
         ORDER BY effective_from DESC LIMIT 1",
        [$loc['id']]
    );
    if ($target) {
        $revenue = (float)(DB::queryOne(
            "SELECT COALESCE(SUM(total_amount),0) AS t FROM sales
             WHERE location_id=? AND DATE(created_at) BETWEEN ? AND ?
               AND (sale_type IS NULL OR sale_type NOT IN ('repair_final','repair_deposit'))",
            [$loc['id'], date('Y-m-01'), date('Y-m-t')])['t'] ?? 0);
        $repairRev = (float)(DB::queryOne(
            "SELECT COALESCE(SUM(final_cost),0) AS t FROM repairs
             WHERE location_id=? AND status='completed' AND DATE(updated_at) BETWEEN ? AND ?",
            [$loc['id'], date('Y-m-01'), date('Y-m-t')])['t'] ?? 0);
        $monthTargets[$loc['id']] = [
            'target'   => $target,
            'actual'   => $revenue + $repairRev,
            'loc_code' => $loc['code'],
            'loc_name' => $loc['name'],
        ];
    }
}

$data['cash_variances']       = 0; // Phase 5 — cash reconciliation
$data['complaints_open']      = 0; // Phase 5 — complaints module
$data['activations_pipeline'] = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM activations
     WHERE activation_date BETWEEN ? AND ? AND status='submitted'",
    [date('Y-m-01'), date('Y-m-t')])['c'] ?? 0);
$data['activations_month_cnt'] = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM activations
     WHERE activation_date BETWEEN ? AND ?",
    [date('Y-m-01'), date('Y-m-t')])['c'] ?? 0);

// Today's snapshot
$todayStats = DB::queryOne(
    "SELECT
        SUM(direction='inbound') AS calls_in,
        SUM(status='missed')     AS calls_missed
     FROM call_logs WHERE DATE(call_at) = CURDATE()"
);
$todaySales = DB::queryOne(
    "SELECT COUNT(*) AS cnt, SUM(total_amount) AS total FROM sales
     WHERE DATE(created_at) = CURDATE()
       AND (sale_type IS NULL OR sale_type NOT IN ('repair_final','repair_deposit'))"
);
$todayRepairsCreated   = DB::queryOne(
    "SELECT COUNT(*) AS c FROM repairs WHERE DATE(created_at) = CURDATE()")['c'] ?? 0;
$todayRepairsCompleted = DB::queryOne(
    "SELECT COUNT(*) AS c FROM repairs WHERE DATE(updated_at) = CURDATE() AND status='completed'")['c'] ?? 0;

$totalAlerts = $data['missed_calls_os'] + $data['missed_calls_pf']
             + $data['repairs_overdue'] + $data['follow_ups_due']
             + $data['failed_sms'] + $data['complaints_open'];

$pageTitle = ($totalAlerts > 0 ? "({$totalAlerts}) " : '') . 'Dashboard';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<!-- Auto-refresh via JS (60s) — avoids needing to modify layout header -->
<script>setTimeout(()=>location.reload(),60000);</script>

<div class="page-header">
    <div>
        <h1 class="page-title">📊 Owner Dashboard</h1>
        <p class="page-sub"><?= date('l, F j, Y') ?> · Auto-refreshes every 60s</p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <span style="font-size:13px;color:var(--text-muted);">
            <span style="color:var(--green);">●</span> Stores active
        </span>
        <span id="live-clock" style="font-family:monospace;font-size:14px;"></span>
    </div>
</div>

<!-- Location Status -->
<div style="display:flex;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
    <?php foreach ($locations as $loc): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px 14px;display:flex;align-items:center;gap:10px;">
        <span style="color:var(--green);font-size:10px;">●</span>
        <span class="badge badge-loc"><?= htmlspecialchars($loc['code']) ?></span>
        <span class="text-muted small"><?= htmlspecialchars($loc['name']) ?> · <?= htmlspecialchars(formatPhone($loc['did'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<!-- Key Metrics -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">

    <div class="metric-card <?= ($data['missed_calls_os']+$data['missed_calls_pf'])>0?'accent-red':'accent-blue' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/calls/missed.php'" style="cursor:pointer;">
        <div class="metric-label">Missed Calls</div>
        <div class="metric-value"><?= $data['missed_calls_os'] + $data['missed_calls_pf'] ?></div>
        <div class="metric-sub">
            <span class="badge badge-loc" style="font-size:10px;">OS</span> <?= $data['missed_calls_os'] ?> &nbsp;
            <span class="badge badge-loc" style="font-size:10px;">PF</span> <?= $data['missed_calls_pf'] ?>
        </div>
    </div>

    <div class="metric-card accent-blue"
         onclick="location.href='<?= APP_URL ?>/modules/repairs/'" style="cursor:pointer;">
        <div class="metric-label">Active Repairs</div>
        <div class="metric-value"><?= $data['repairs_active_os'] + $data['repairs_active_pf'] ?></div>
        <div class="metric-sub">
            <span class="badge badge-loc" style="font-size:10px;">OS</span> <?= $data['repairs_active_os'] ?> &nbsp;
            <span class="badge badge-loc" style="font-size:10px;">PF</span> <?= $data['repairs_active_pf'] ?>
        </div>
    </div>

    <div class="metric-card <?= $data['repairs_overdue']>0?'accent-red':'accent-green' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/repairs/'" style="cursor:pointer;">
        <div class="metric-label">Overdue Repairs</div>
        <div class="metric-value"><?= $data['repairs_overdue'] ?></div>
        <div class="metric-sub">Past estimated completion</div>
    </div>

    <div class="metric-card <?= $data['follow_ups_due']>0?'accent-yellow':'accent-green' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/calls/followups.php'" style="cursor:pointer;">
        <div class="metric-label">Follow-Ups Due</div>
        <div class="metric-value"><?= $data['follow_ups_due'] ?></div>
        <div class="metric-sub">Due today or overdue</div>
    </div>

    <div class="metric-card <?= $data['failed_sms']>0?'accent-yellow':'accent-green' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/calls/sms-failed.php'" style="cursor:pointer;">
        <div class="metric-label">Failed SMS</div>
        <div class="metric-value"><?= $data['failed_sms'] ?></div>
        <div class="metric-sub">Needs retry</div>
    </div>

    <div class="metric-card <?= $data['inventory_alerts']>0?'accent-yellow':'accent-green' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/inventory/'" style="cursor:pointer;">
        <div class="metric-label">Inventory Alerts</div>
        <div class="metric-value"><?= $data['inventory_alerts'] ?></div>
        <div class="metric-sub">Low stock</div>
    </div>

    <div class="metric-card accent-green" style="cursor:pointer;" onclick="location.href='<?= APP_URL ?>/modules/staff/attendance.php'">
        <div class="metric-label">Staff Clocked In</div>
        <div class="metric-value"><?= $data['staff_clocked_in'] ?></div>
        <div class="metric-sub">Right now · <a href="<?= APP_URL ?>/modules/staff/attendance.php" style="color:inherit;">View →</a></div>
    </div>

    <div class="metric-card accent-green">
        <div class="metric-label">Cash Variances</div>
        <div class="metric-value"><?= $data['cash_variances'] ?></div>
        <div class="metric-sub">Phase 5</div>
    </div>

    <div class="metric-card <?= $data['activations_pipeline']>0?'accent-amber':'accent-blue' ?>">
        <div class="metric-label">Activations — This Month</div>
        <div class="metric-value"><?= $data['activations_month_cnt'] ?></div>
        <div class="metric-sub"><?= $data['activations_pipeline'] ?> awaiting verification · <a href="<?= APP_URL ?>/modules/activations/pipeline.php" style="color:inherit;">Pipeline →</a></div>
    </div>

    <div class="metric-card <?= $data['complaints_open']>0?'accent-red':'accent-green' ?>">
        <div class="metric-label">Open Complaints</div>
        <div class="metric-value"><?= $data['complaints_open'] ?></div>
        <div class="metric-sub">Phase 5</div>
    </div>

</div>

<!-- Bottom Grid -->
<div class="repair-grid">

    <!-- Active Alerts -->
    <div class="repair-col-main">
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header">
                <h2 class="card-title">🔔 Active Alerts</h2>
            </div>
            <div class="card-body" style="<?= $totalAlerts>0 ? 'padding:0;' : '' ?>">
            <?php if ($totalAlerts === 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <p>All clear — no alerts right now.</p>
                </div>
            <?php else: ?>
                <?php if ($data['missed_calls_os']+$data['missed_calls_pf']>0): ?>
                <div style="display:flex;align-items:center;gap:1rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);">
                    <span style="font-size:20px;">📞</span>
                    <div style="flex:1;">
                        <div style="font-weight:600;">
                            <?= $data['missed_calls_os']+$data['missed_calls_pf'] ?> missed call<?= ($data['missed_calls_os']+$data['missed_calls_pf'])>1?'s':'' ?> need callback
                        </div>
                        <div class="text-muted small">OS: <?= $data['missed_calls_os'] ?> &nbsp; PF: <?= $data['missed_calls_pf'] ?></div>
                    </div>
                    <a href="<?= APP_URL ?>/modules/calls/missed.php" class="btn btn-sm btn-danger">View →</a>
                </div>
                <?php endif; ?>
                <?php if ($data['repairs_overdue']>0): ?>
                <div style="display:flex;align-items:center;gap:1rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);">
                    <span style="font-size:20px;">⚠️</span>
                    <div style="flex:1;">
                        <div style="font-weight:600;"><?= $data['repairs_overdue'] ?> overdue repair<?= $data['repairs_overdue']>1?'s':'' ?></div>
                        <div class="text-muted small">Past estimated completion date</div>
                    </div>
                    <a href="<?= APP_URL ?>/modules/repairs/" class="btn btn-sm btn-danger">View →</a>
                </div>
                <?php endif; ?>
                <?php if ($data['follow_ups_due']>0): ?>
                <div style="display:flex;align-items:center;gap:1rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);">
                    <span style="font-size:20px;">📋</span>
                    <div style="flex:1;">
                        <div style="font-weight:600;"><?= $data['follow_ups_due'] ?> follow-up<?= $data['follow_ups_due']>1?'s':'' ?> due</div>
                        <div class="text-muted small">Due today or overdue</div>
                    </div>
                    <a href="<?= APP_URL ?>/modules/calls/followups.php" class="btn btn-sm btn-secondary">View →</a>
                </div>
                <?php endif; ?>
                <?php if ($data['failed_sms']>0): ?>
                <div style="display:flex;align-items:center;gap:1rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);">
                    <span style="font-size:20px;">💬</span>
                    <div style="flex:1;">
                        <div style="font-weight:600;"><?= $data['failed_sms'] ?> SMS failed to send</div>
                        <div class="text-muted small">Retry or mark manual</div>
                    </div>
                    <a href="<?= APP_URL ?>/modules/calls/sms-failed.php" class="btn btn-sm btn-secondary">View →</a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- Today's Snapshot -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📅 Today's Snapshot</h2>
                <span class="text-muted small"><?= date('D, M j') ?></span>
            </div>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <tbody>
                    <tr>
                        <td>Calls Received</td>
                        <td style="text-align:right;font-weight:700;"><?= intval($todayStats['calls_in'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td>Calls Missed</td>
                        <td style="text-align:right;font-weight:700;color:<?= intval($todayStats['calls_missed']??0)>0?'var(--red)':'inherit' ?>;"><?= intval($todayStats['calls_missed'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td>Repairs Created</td>
                        <td style="text-align:right;font-weight:700;"><?= intval($todayRepairsCreated) ?></td>
                    </tr>
                    <tr>
                        <td>Repairs Completed</td>
                        <td style="text-align:right;font-weight:700;color:var(--green);"><?= intval($todayRepairsCompleted) ?></td>
                    </tr>
                    <tr>
                        <td>Walk-in Sales</td>
                        <td style="text-align:right;font-weight:700;"><?= intval($todaySales['cnt'] ?? 0) ?>
                            <?php if (($todaySales['total'] ?? 0) > 0): ?>
                            <span class="text-muted" style="font-size:12px;font-weight:400;"> · $<?= number_format($todaySales['total'], 2) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Right side: Targets + Quick Actions -->
    <div class="repair-col-side">

        <!-- Monthly Targets Widget -->
        <?php if (!empty($monthTargets)): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header">
                <h2 class="card-title">🎯 Monthly Targets</h2>
                <a href="<?= APP_URL ?>/modules/reports/targets.php" class="btn btn-sm btn-ghost">Edit</a>
            </div>
            <div class="card-body">
            <?php foreach ($monthTargets as $mt):
                $pct   = $mt['target']['target_amount'] > 0
                         ? min(100, round(($mt['actual'] / $mt['target']['target_amount']) * 100))
                         : 0;
                $color = $pct >= 100 ? 'var(--green)' : ($pct >= 70 ? 'var(--amber)' : 'var(--red)');
            ?>
            <div style="margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px;">
                    <span style="font-weight:600;">
                        <span class="badge badge-loc" style="font-size:10px;"><?= htmlspecialchars($mt['loc_code']) ?></span>
                        <?= htmlspecialchars($mt['loc_name']) ?>
                    </span>
                    <span style="color:<?= $color ?>;font-weight:700;"><?= $pct ?>%</span>
                </div>
                <div style="background:var(--surface-2);border-radius:4px;height:8px;overflow:hidden;margin-bottom:3px;">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:4px;transition:.3s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-3);">
                    <span>$<?= number_format($mt['actual'], 0) ?> earned</span>
                    <span>$<?= number_format($mt['target']['target_amount'], 0) ?> goal</span>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2 class="card-title">⚡ Quick Actions</h2></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem;">
                <a href="<?= APP_URL ?>/modules/staff/index.php"    class="btn btn-ghost">👤 Manage Staff</a>
                <a href="<?= APP_URL ?>/modules/expenses/index.php"  class="btn btn-ghost">💰 Log Expense</a>
                <a href="<?= APP_URL ?>/modules/reports/index.php"   class="btn btn-ghost">📈 Reports</a>
                <a href="<?= APP_URL ?>/modules/settings/index.php"  class="btn btn-ghost">⚙️ Settings</a>
                <a href="<?= APP_URL ?>/modules/calls/sms.php"       class="btn btn-ghost">💬 Send SMS</a>
            </div>
        </div>
    </div>

</div>

<script>
// Live clock
(function tick(){
    const el=document.getElementById('live-clock');
    if(el){el.textContent=new Date().toLocaleTimeString('en-CA',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
    setTimeout(tick,1000);
})();
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
