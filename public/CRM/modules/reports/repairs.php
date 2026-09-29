<?php
// ============================================================
// Repair Volume & Technician Performance Report
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

// Filters
$period     = $_GET['period']     ?? 'month';
$locationId = intval($_GET['location_id'] ?? 0);

// If manager, lock to their location
if (!$isOwner && $isManager) {
    $locationId = $user['location_id'];
}

[$dateFrom, $dateTo] = match($period) {
    'today'   => [date('Y-m-d'), date('Y-m-d')],
    'week'    => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'month'   => [date('Y-m-01'), date('Y-m-d')],
    'quarter' => [date('Y-m-d', strtotime('first day of -2 month')), date('Y-m-d')],
    default   => [date('Y-m-01'), date('Y-m-d')],
};

if (!empty($_GET['date_from'])) $dateFrom = $_GET['date_from'];
if (!empty($_GET['date_to']))   $dateTo   = $_GET['date_to'];

$locWhere  = $locationId ? 'AND r.location_id = ' . intval($locationId) : '';

// Overall volume stats
$volumeStats = DB::queryOne(
    "SELECT
        COUNT(*) AS total,
        SUM(status='completed')  AS completed,
        SUM(status NOT IN ('completed','cancelled','closed')) AS active,
        SUM(status='cancelled')  AS cancelled,
        ROUND(AVG(CASE WHEN status='completed' THEN TIMESTAMPDIFF(HOUR, created_at, updated_at) END), 1) AS avg_hours,
        COALESCE(SUM(CASE WHEN status='completed' THEN final_cost ELSE 0 END), 0) AS revenue
     FROM repairs r
     WHERE DATE(r.created_at) BETWEEN ? AND ? {$locWhere}",
    [$dateFrom, $dateTo]
);

// By status breakdown
$byStatus = DB::query(
    "SELECT status, COUNT(*) AS cnt,
            COALESCE(SUM(final_cost),0) AS revenue
     FROM repairs r
     WHERE DATE(r.created_at) BETWEEN ? AND ? {$locWhere}
     GROUP BY status ORDER BY cnt DESC",
    [$dateFrom, $dateTo]
);

// By device type
$byDevice = DB::query(
    "SELECT device_type, COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN status='completed' THEN final_cost ELSE 0 END),0) AS revenue
     FROM repairs r
     WHERE DATE(r.created_at) BETWEEN ? AND ? {$locWhere}
     GROUP BY device_type ORDER BY cnt DESC LIMIT 10",
    [$dateFrom, $dateTo]
);

// By location
$byLocation = DB::query(
    "SELECT l.name AS loc_name, l.code AS loc_code,
            COUNT(*) AS total,
            SUM(r.status='completed') AS completed,
            COALESCE(SUM(CASE WHEN r.status='completed' THEN r.final_cost ELSE 0 END),0) AS revenue
     FROM repairs r
     JOIN locations l ON l.id = r.location_id
     WHERE DATE(r.created_at) BETWEEN ? AND ? {$locWhere}
     GROUP BY r.location_id ORDER BY total DESC",
    [$dateFrom, $dateTo]
);

// Technician performance (assigned_to)
$byTech = DB::query(
    "SELECT
        COALESCE(u.first_name, 'Unassigned') AS tech_name,
        COUNT(*) AS total,
        SUM(r.status='completed') AS completed,
        ROUND(AVG(CASE WHEN r.status='completed' THEN TIMESTAMPDIFF(HOUR, r.created_at, r.updated_at) END),1) AS avg_hours,
        COALESCE(SUM(CASE WHEN r.status='completed' THEN r.final_cost ELSE 0 END),0) AS revenue
     FROM repairs r
     LEFT JOIN users u ON u.id = r.assigned_to
     WHERE DATE(r.created_at) BETWEEN ? AND ? {$locWhere}
     GROUP BY r.assigned_to
     ORDER BY completed DESC",
    [$dateFrom, $dateTo]
);

// Daily trend (last 30 days max)
$trend = DB::query(
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt,
            SUM(status='completed') AS done
     FROM repairs r
     WHERE DATE(r.created_at) BETWEEN ? AND ? {$locWhere}
     GROUP BY DATE(created_at) ORDER BY day ASC",
    [$dateFrom, $dateTo]
);

$pageTitle = 'Repair Report';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🔧 Repair Report</h1>
        <p class="page-sub"><?= date('M j, Y', strtotime($dateFrom)) ?> – <?= date('M j, Y', strtotime($dateTo)) ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary">← Reports</a>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:flex-end;">
    <div class="form-group" style="margin:0;">
        <label class="form-label">Period</label>
        <select name="period" class="form-control" onchange="this.form.submit()">
            <option value="today"   <?= $period==='today'  ?'selected':'' ?>>Today</option>
            <option value="week"    <?= $period==='week'   ?'selected':'' ?>>This Week</option>
            <option value="month"   <?= $period==='month'  ?'selected':'' ?>>This Month</option>
            <option value="quarter" <?= $period==='quarter'?'selected':'' ?>>This Quarter</option>
            <option value="custom"  <?= $period==='custom' ?'selected':'' ?>>Custom</option>
        </select>
    </div>
    <?php if ($isOwner): ?>
    <div class="form-group" style="margin:0;">
        <label class="form-label">Location</label>
        <select name="location_id" class="form-control" onchange="this.form.submit()">
            <option value="0">All Locations</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['id'] ?>" <?= $locationId==$loc['id']?'selected':'' ?>>
                <?= htmlspecialchars($loc['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if ($period === 'custom'): ?>
    <div class="form-group" style="margin:0;">
        <label class="form-label">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>">
    </div>
    <div class="form-group" style="margin:0;">
        <label class="form-label">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>">
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:auto;">Apply</button>
    <?php endif; ?>
</form>

<!-- Summary Metrics -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">
    <div class="metric-card accent-blue">
        <div class="metric-label">Total Repairs</div>
        <div class="metric-value"><?= intval($volumeStats['total'] ?? 0) ?></div>
        <div class="metric-sub">Created this period</div>
    </div>
    <div class="metric-card accent-green">
        <div class="metric-label">Completed</div>
        <div class="metric-value"><?= intval($volumeStats['completed'] ?? 0) ?></div>
        <div class="metric-sub">
            <?= $volumeStats['total'] > 0
                ? round(($volumeStats['completed'] / $volumeStats['total']) * 100) . '% completion rate'
                : '—' ?>
        </div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Active</div>
        <div class="metric-value"><?= intval($volumeStats['active'] ?? 0) ?></div>
        <div class="metric-sub">In progress</div>
    </div>
    <div class="metric-card accent-green">
        <div class="metric-label">Revenue</div>
        <div class="metric-value">$<?= number_format($volumeStats['revenue'] ?? 0, 0) ?></div>
        <div class="metric-sub">Completed repairs</div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Avg Turnaround</div>
        <div class="metric-value"><?= $volumeStats['avg_hours'] ? round($volumeStats['avg_hours']) . 'h' : '—' ?></div>
        <div class="metric-sub">Hours to completion</div>
    </div>
</div>

<div class="repair-grid">

    <!-- Left column -->
    <div class="repair-col-main">

        <!-- Technician Performance -->
        <?php if (!empty($byTech)): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">👤 Technician Performance</h2></div>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr><th>Technician</th><th>Assigned</th><th>Completed</th><th>Avg Hours</th><th>Revenue</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byTech as $t): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($t['tech_name']) ?></td>
                    <td><?= $t['total'] ?></td>
                    <td>
                        <span style="color:var(--green);font-weight:600;"><?= $t['completed'] ?></span>
                        <?php if ($t['total'] > 0): ?>
                        <span class="text-muted small"> (<?= round(($t['completed']/$t['total'])*100) ?>%)</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $t['avg_hours'] ? $t['avg_hours'] . 'h' : '—' ?></td>
                    <td style="font-weight:600;">$<?= number_format($t['revenue'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- By Device Type -->
        <?php if (!empty($byDevice)): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">📱 By Device Type</h2></div>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead><tr><th>Device</th><th>Count</th><th>Revenue</th><th>% of Total</th></tr></thead>
                <tbody>
                <?php $totalRepairs = intval($volumeStats['total'] ?? 1);
                foreach ($byDevice as $d): ?>
                <tr>
                    <td><?= htmlspecialchars(ucwords($d['device_type'] ?? 'Unknown')) ?></td>
                    <td><?= $d['cnt'] ?></td>
                    <td>$<?= number_format($d['revenue'], 2) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <div style="background:var(--surface-2);border-radius:4px;height:6px;width:80px;overflow:hidden;">
                                <div style="height:100%;width:<?= round(($d['cnt']/$totalRepairs)*100) ?>%;background:var(--blue);"></div>
                            </div>
                            <span class="text-muted small"><?= round(($d['cnt']/$totalRepairs)*100) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- By Location -->
        <?php if (!empty($byLocation) && $isOwner): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">📍 By Location</h2></div>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead><tr><th>Location</th><th>Total</th><th>Completed</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($byLocation as $l): ?>
                <tr>
                    <td><span class="badge badge-loc"><?= htmlspecialchars($l['loc_code']) ?></span> <?= htmlspecialchars($l['loc_name']) ?></td>
                    <td><?= $l['total'] ?></td>
                    <td style="color:var(--green);font-weight:600;"><?= $l['completed'] ?></td>
                    <td style="font-weight:600;">$<?= number_format($l['revenue'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Right column: Status breakdown + Trend -->
    <div class="repair-col-side">

        <!-- Status Breakdown -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h2 class="card-title">Status Breakdown</h2></div>
            <div class="card-body" style="padding:0;">
            <?php foreach ($byStatus as $s):
                $statusColors = [
                    'completed' => 'var(--green)',
                    'cancelled' => 'var(--text-3)',
                    'in_progress' => 'var(--blue)',
                    'pending' => 'var(--amber)',
                    'waiting_parts' => 'var(--amber)',
                    'ready' => 'var(--green)',
                ];
                $color = $statusColors[$s['status']] ?? 'var(--text-2)';
                $pct   = $volumeStats['total'] > 0 ? round(($s['cnt']/$volumeStats['total'])*100) : 0;
            ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem 1rem;border-bottom:1px solid var(--border);">
                <div style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>;flex-shrink:0;"></div>
                <div style="flex:1;font-size:14px;"><?= ucwords(str_replace('_',' ',$s['status'])) ?></div>
                <div style="font-weight:700;"><?= $s['cnt'] ?></div>
                <div class="text-muted small" style="width:32px;text-align:right;"><?= $pct ?>%</div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>

        <!-- Daily Trend -->
        <?php if (!empty($trend)): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">📈 Daily Trend</h2></div>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead><tr><th>Date</th><th>Created</th><th>Completed</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($trend, -14) as $row): ?>
                <tr>
                    <td class="text-muted small"><?= date('M j', strtotime($row['day'])) ?></td>
                    <td><?= $row['cnt'] ?></td>
                    <td style="color:var(--green);"><?= $row['done'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
