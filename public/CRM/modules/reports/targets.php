<?php
// ============================================================
// Sales Targets — Set & View per Location
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/staff.php'); exit;
}

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$success = '';
$error   = '';

// POST — save a target
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationId  = intval($_POST['location_id']);
    $periodType  = $_POST['period_type'] ?? 'monthly';
    $amount      = floatval($_POST['target_amount'] ?? 0);
    $repairs     = intval($_POST['target_repairs'] ?? 0);
    $sales       = intval($_POST['target_sales'] ?? 0);
    $effectiveFrom = $_POST['effective_from'] ?? date('Y-m-01');
    $notes       = trim($_POST['notes'] ?? '');

    // Managers can only set targets for their own location
    if (!$isOwner && $locationId !== $user['location_id']) {
        $error = 'You can only set targets for your own location.';
    } else {
        DB::execute(
            'INSERT INTO sales_targets
             (location_id, period_type, target_amount, target_repairs, target_sales, effective_from, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                target_amount=VALUES(target_amount),
                target_repairs=VALUES(target_repairs),
                target_sales=VALUES(target_sales),
                notes=VALUES(notes),
                created_by=VALUES(created_by)',
            [$locationId, $periodType, $amount, $repairs, $sales, $effectiveFrom, $notes ?: null, $user['id']]
        );
        $success = 'Target saved.';
    }
}

// Load current targets (most recent per location + period)
$targets = DB::query(
    "SELECT t.*, l.name AS loc_name, l.code AS loc_code
     FROM sales_targets t
     JOIN locations l ON l.id = t.location_id
     ORDER BY t.location_id, t.period_type, t.effective_from DESC",
    []
);

// Group by location + period for display
$grouped = [];
foreach ($targets as $t) {
    $key = $t['location_id'] . '_' . $t['period_type'];
    if (!isset($grouped[$key])) $grouped[$key] = $t; // first = most recent
}

// Current period actuals
function getPeriodActuals(int $locationId, string $period): array {
    [$dateFrom, $dateTo] = match($period) {
        'daily'   => [date('Y-m-d'), date('Y-m-d')],
        'weekly'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
        'monthly' => [date('Y-m-01'), date('Y-m-t')],
        default   => [date('Y-m-01'), date('Y-m-t')],
    };

    $revenue = DB::queryOne(
        "SELECT COALESCE(SUM(total_amount),0) AS total FROM sales
         WHERE location_id=? AND DATE(created_at) BETWEEN ? AND ?
           AND (sale_type IS NULL OR sale_type NOT IN ('repair_final','repair_deposit'))",
        [$locationId, $dateFrom, $dateTo]
    )['total'] ?? 0;

    // Also count repair revenue (final_cost where completed)
    $repairRevenue = DB::queryOne(
        "SELECT COALESCE(SUM(final_cost),0) AS total FROM repairs
         WHERE location_id=? AND status='completed' AND DATE(updated_at) BETWEEN ? AND ?",
        [$locationId, $dateFrom, $dateTo]
    )['total'] ?? 0;

    $repairCount = DB::queryOne(
        "SELECT COUNT(*) AS c FROM repairs
         WHERE location_id=? AND status='completed' AND DATE(updated_at) BETWEEN ? AND ?",
        [$locationId, $dateFrom, $dateTo]
    )['c'] ?? 0;

    $saleCount = DB::queryOne(
        "SELECT COUNT(*) AS c FROM sales
         WHERE location_id=? AND DATE(created_at) BETWEEN ? AND ?
           AND (sale_type IS NULL OR sale_type NOT IN ('repair_final','repair_deposit'))",
        [$locationId, $dateFrom, $dateTo]
    )['c'] ?? 0;

    return [
        'revenue'      => floatval($revenue) + floatval($repairRevenue),
        'repair_count' => intval($repairCount),
        'sale_count'   => intval($saleCount),
        'date_from'    => $dateFrom,
        'date_to'      => $dateTo,
    ];
}

$pageTitle = 'Sales Targets';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🎯 Sales Targets</h1>
        <p class="page-sub">Set daily, weekly, and monthly goals per location</p>
    </div>
    <a href="index.php" class="btn btn-secondary">← Reports</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Current Performance vs Targets -->
<?php foreach ($locations as $loc):
    if (!$isOwner && $loc['id'] !== $user['location_id']) continue;
?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title">
            <span class="badge badge-loc"><?= htmlspecialchars($loc['code']) ?></span>
            <?= htmlspecialchars($loc['name']) ?> — Current Performance
        </h2>
    </div>
    <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
    <?php foreach (['daily','weekly','monthly'] as $period):
        $key     = $loc['id'] . '_' . $period;
        $target  = $grouped[$key] ?? null;
        $actuals = getPeriodActuals($loc['id'], $period);
        $pct     = ($target && $target['target_amount'] > 0)
                   ? min(100, round(($actuals['revenue'] / $target['target_amount']) * 100))
                   : null;
        $color   = $pct === null ? 'var(--border)' : ($pct >= 100 ? 'var(--green)' : ($pct >= 70 ? 'var(--amber)' : 'var(--red)'));
    ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:1rem;">
        <div style="font-weight:700;text-transform:capitalize;margin-bottom:.5rem;font-size:15px;">
            <?= $period ?>
            <span class="text-muted small" style="font-weight:400;"> · <?= date('M j', strtotime($actuals['date_from'])) ?>–<?= date('M j', strtotime($actuals['date_to'])) ?></span>
        </div>

        <?php if ($target): ?>
        <!-- Progress bar -->
        <div style="background:var(--surface-2);border-radius:4px;height:10px;margin-bottom:.5rem;overflow:hidden;">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:4px;transition:.3s;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:.5rem;">
            <span style="font-weight:700;color:<?= $color ?>;">$<?= number_format($actuals['revenue'], 2) ?></span>
            <span class="text-muted">of $<?= number_format($target['target_amount'], 2) ?> (<?= $pct ?>%)</span>
        </div>
        <?php if ($target['target_repairs'] > 0): ?>
        <div class="small text-muted">Repairs: <?= $actuals['repair_count'] ?> / <?= $target['target_repairs'] ?></div>
        <?php endif; ?>
        <?php if ($target['target_sales'] > 0): ?>
        <div class="small text-muted">Sales: <?= $actuals['sale_count'] ?> / <?= $target['target_sales'] ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div class="text-muted small" style="margin-bottom:.5rem;">No target set</div>
        <div style="font-size:20px;font-weight:700;">$<?= number_format($actuals['revenue'], 2) ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Set / Update Targets -->
<div class="card">
    <div class="card-header"><h2 class="card-title">✏️ Set a Target</h2></div>
    <div class="card-body" style="max-width:560px;">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control" required>
                        <?php foreach ($locations as $loc):
                            if (!$isOwner && $loc['id'] !== $user['location_id']) continue;
                        ?>
                        <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Period</label>
                    <select name="period_type" class="form-control" required>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly" selected>Monthly</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Revenue Target ($)</label>
                    <input type="number" name="target_amount" class="form-control"
                           step="0.01" min="0" placeholder="5000.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Effective From</label>
                    <input type="date" name="effective_from" class="form-control"
                           value="<?= date('Y-m-01') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Repair Count Target <span class="text-muted">(optional)</span></label>
                    <input type="number" name="target_repairs" class="form-control" min="0" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Sale Count Target <span class="text-muted">(optional)</span></label>
                    <input type="number" name="target_sales" class="form-control" min="0" value="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                <input type="text" name="notes" class="form-control" placeholder="e.g. Q3 push, summer target">
            </div>
            <button type="submit" class="btn btn-primary">Save Target</button>
        </form>
    </div>
</div>

<!-- Target History -->
<?php if (!empty($targets)): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header"><h2 class="card-title">📋 Target History</h2></div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr><th>Location</th><th>Period</th><th>Revenue Target</th><th>Repairs</th><th>Sales</th><th>Effective From</th><th>Notes</th></tr>
        </thead>
        <tbody>
        <?php foreach ($targets as $t): ?>
        <tr>
            <td><span class="badge badge-loc"><?= htmlspecialchars($t['loc_code']) ?></span></td>
            <td style="text-transform:capitalize;"><?= htmlspecialchars($t['period_type']) ?></td>
            <td style="font-weight:600;">$<?= number_format($t['target_amount'], 2) ?></td>
            <td><?= $t['target_repairs'] ?: '—' ?></td>
            <td><?= $t['target_sales'] ?: '—' ?></td>
            <td class="text-muted small"><?= date('M j, Y', strtotime($t['effective_from'])) ?></td>
            <td class="text-muted small"><?= htmlspecialchars($t['notes'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
