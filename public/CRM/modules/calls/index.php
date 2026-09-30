<?php
// ============================================================
// Calls & SMS — Call Log
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';
require_once $rootPath . '/core/CallImporter.php';
require_once $rootPath . '/core/SmsImporter.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();

// ── Sync handlers ───────────────────────────────────────────
$importMsg = '';
function saveSyncTime(string $key, string $now): void {
    $existing = DB::queryOne("SELECT id FROM settings WHERE setting_key=? AND location_id IS NULL", [$key]);
    if ($existing) {
        DB::execute("UPDATE settings SET value=? WHERE setting_key=? AND location_id IS NULL", [$now, $key]);
    } else {
        DB::execute("INSERT INTO settings (setting_key, location_id, value) VALUES (?, NULL, ?)", [$key, $now]);
    }
}
if (isset($_GET['import'])) {
    $result    = CallImporter::importRecent(7);
    $importMsg = "Calls: imported {$result['imported']}, skipped {$result['skipped']}.";
    saveSyncTime('last_call_sync', date('Y-m-d H:i:s'));
}
if (isset($_GET['import_sms'])) {
    $result    = SmsImporter::importRecent(2);
    $importMsg = "SMS: imported {$result['imported']}, skipped {$result['skipped']}.";
    saveSyncTime('last_sms_sync', date('Y-m-d H:i:s'));
}

// ── Date range helper ───────────────────────────────────────
function resolveDateRange(string $period, string $customFrom, string $customTo): array {
    $today = date('Y-m-d');
    switch ($period) {
        case 'today':
            return [$today, $today];
        case 'yesterday':
            $y = date('Y-m-d', strtotime('-1 day'));
            return [$y, $y];
        case 'this_week':
            // Monday → Sunday of the current ISO week
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))];
        case 'this_month':
            return [date('Y-m-01'), date('Y-m-t')];
        case 'this_year':
            return [date('Y-01-01'), date('Y-12-31')];
        case 'last_year':
            $y = (int)date('Y') - 1;
            return ["{$y}-01-01", "{$y}-12-31"];
        case 'custom':
            return [$customFrom ?: $today, $customTo ?: $today];
        default:
            return ['', ''];   // 'all' — no date restriction
    }
}

// ── Filters ─────────────────────────────────────────────────
$locationFilter = $_GET['location']    ?? 'all';
$statusFilter   = $_GET['status']      ?? 'all';
$period         = $_GET['period']      ?? 'today';
$customFrom     = $_GET['date_from']   ?? '';
$customTo       = $_GET['date_to']     ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;
$offset         = ($page - 1) * $perPage;

[$dateFrom, $dateTo] = resolveDateRange($period, $customFrom, $customTo);

$where  = ['1=1'];
$params = [];

// Location restriction
if (!$isOwner) {
    $where[]  = 'cl.location_id = ?';
    $params[] = $user['location_id'];
} elseif ($locationFilter !== 'all') {
    $where[]  = 'l.code = ?';
    $params[] = $locationFilter;
}

// Status filter
if ($statusFilter !== 'all') {
    $where[]  = 'cl.status = ?';
    $params[] = $statusFilter;
}

// Date filter
if ($dateFrom && $dateTo) {
    $where[]  = 'DATE(cl.call_at) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;
}

$whereSQL = implode(' AND ', $where);

$calls = DB::query(
    "SELECT cl.*,
            COALESCE(c.first_name, c2.first_name) AS first_name,
            COALESCE(c.last_name,  c2.last_name)  AS last_name,
            COALESCE(c.id,         c2.id)          AS customer_id,
            l.code AS loc_code, l.name AS loc_name
     FROM call_logs cl
     LEFT JOIN customers c   ON cl.customer_id = c.id
     LEFT JOIN customers c2  ON c2.phone_normalized = cl.caller_number AND cl.customer_id IS NULL
     LEFT JOIN locations l   ON cl.location_id = l.id
     WHERE {$whereSQL}
     ORDER BY cl.call_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Stats — respect same filters
$statsParams = $params; // same where/params, no LIMIT needed
$stats = DB::queryOne(
    "SELECT COUNT(*) AS total,
            SUM(cl.status='missed')     AS missed,
            SUM(cl.status='answered')   AS answered,
            SUM(cl.direction='inbound') AS inbound,
            SUM(cl.direction='outbound') AS outbound
     FROM call_logs cl
     LEFT JOIN locations l ON cl.location_id = l.id
     WHERE {$whereSQL}",
    $statsParams
);

$missedCount = DB::queryOne(
    "SELECT COUNT(*) AS cnt FROM call_logs cl
     WHERE cl.status='missed' AND cl.direction='inbound'
       AND cl.needs_callback=1 AND cl.callback_status='pending'"
)['cnt'] ?? 0;

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// Sync status
$lastCallSync = DB::queryOne("SELECT value, updated_at FROM settings WHERE setting_key='last_call_sync' LIMIT 1");
$lastSmsSync  = DB::queryOne("SELECT value, updated_at FROM settings WHERE setting_key='last_sms_sync'  LIMIT 1");

// Build query-string helper for pagination (preserves all current filters)
function filterQS(array $overrides = []): string {
    global $locationFilter, $statusFilter, $period, $customFrom, $customTo;
    $base = [
        'location'  => $locationFilter,
        'status'    => $statusFilter,
        'period'    => $period,
        'date_from' => $customFrom,
        'date_to'   => $customTo,
    ];
    return http_build_query(array_merge($base, $overrides));
}

$pageTitle = 'Calls & SMS';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($importMsg): ?>
<div class="alert alert-success"><?= htmlspecialchars($importMsg) ?></div>
<?php endif; ?>

<!-- Sync Status Bar -->
<div style="display:flex;gap:1.5rem;flex-wrap:wrap;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:.6rem 1rem;margin-bottom:1rem;font-size:13px;align-items:center;">
    <?php
    $syncInterval = 5 * 60;
    function syncStatus(string $label, string $icon, ?array $syncRow, int $interval): string {
        if (!$syncRow || empty($syncRow['value'])) {
            return "<span style='color:var(--text-3);'>{$icon} <strong>{$label}:</strong> Never synced</span>";
        }
        $lastTs   = strtotime($syncRow['value']);
        $nextTs   = $lastTs + $interval;
        $secsAgo  = time() - $lastTs;
        $secsLeft = $nextTs - time();
        $lastStr  = $secsAgo < 60 ? 'just now' : ($secsAgo < 3600 ? floor($secsAgo/60).'m ago' : date('g:i A', $lastTs));
        $nextStr  = $secsLeft <= 0 ? 'any moment' : ($secsLeft < 60 ? $secsLeft.'s' : ceil($secsLeft/60).'m');
        $color    = $secsAgo > ($interval*3) ? 'var(--red)' : ($secsAgo > $interval ? 'var(--amber)' : 'var(--green)');
        return "<span><span style='color:{$color};'>●</span> {$icon} <strong>{$label}:</strong> Last <strong>{$lastStr}</strong> · Next in <strong>{$nextStr}</strong></span>";
    }
    ?>
    <?= syncStatus('Calls', '📞', $lastCallSync ?? null, $syncInterval) ?>
    <a href="?import=1" class="btn btn-sm btn-ghost" style="padding:2px 8px;font-size:11px;"
       onclick="return confirm('Pull latest calls from VoIP.ms now?')">⬇ Sync</a>
    <span style="color:var(--border);">|</span>
    <?= syncStatus('SMS', '💬', $lastSmsSync ?? null, $syncInterval) ?>
    <a href="?import_sms=1" class="btn btn-sm btn-ghost" style="padding:2px 8px;font-size:11px;"
       onclick="return confirm('Pull latest SMS from VoIP.ms now?')">⬇ Sync</a>
</div>

<div class="page-header">
    <div>
        <h1 class="page-title">Calls & SMS</h1>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/modules/calls/sms.php"       class="btn btn-secondary">💬 SMS</a>
        <a href="<?= APP_URL ?>/modules/calls/missed.php"    class="btn btn-secondary">
            📵 Missed <?php if ($missedCount > 0): ?><span class="badge badge-danger" style="margin-left:4px;"><?= $missedCount ?></span><?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/modules/calls/followups.php" class="btn btn-secondary">📋 Follow-Ups</a>
    </div>
</div>

<!-- Stats — reflect current filter period -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">
    <div class="metric-card accent-blue">
        <div class="metric-label"><?= $period === 'all' ? 'All Calls' : 'Calls' ?></div>
        <div class="metric-value"><?= $stats['total'] ?? 0 ?></div>
    </div>
    <div class="metric-card accent-red">
        <div class="metric-label">Missed</div>
        <div class="metric-value"><?= $stats['missed'] ?? 0 ?></div>
    </div>
    <div class="metric-card accent-green">
        <div class="metric-label">Answered</div>
        <div class="metric-value"><?= $stats['answered'] ?? 0 ?></div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Inbound</div>
        <div class="metric-value"><?= $stats['inbound'] ?? 0 ?></div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Outbound</div>
        <div class="metric-value"><?= $stats['outbound'] ?? 0 ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar" style="margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;" id="callFilterForm">
    <?php if ($isOwner): ?>
    <select name="location" class="form-control filter-select" onchange="this.form.submit()">
        <option value="all">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= htmlspecialchars($loc['code']) ?>" <?= $locationFilter===$loc['code']?'selected':'' ?>>
            <?= htmlspecialchars($loc['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="status" class="form-control filter-select" onchange="this.form.submit()">
        <option value="all">All Statuses</option>
        <option value="missed"   <?= $statusFilter==='missed'  ?'selected':'' ?>>Missed</option>
        <option value="answered" <?= $statusFilter==='answered'?'selected':'' ?>>Answered</option>
        <option value="busy"     <?= $statusFilter==='busy'    ?'selected':'' ?>>Busy</option>
    </select>

    <select name="period" class="form-control filter-select" id="periodSelect" onchange="toggleCustomDates(this.value);this.form.submit()">
        <option value="all"        <?= $period==='all'        ?'selected':'' ?>>All Time</option>
        <option value="today"      <?= $period==='today'      ?'selected':'' ?>>Today</option>
        <option value="yesterday"  <?= $period==='yesterday'  ?'selected':'' ?>>Yesterday</option>
        <option value="this_week"  <?= $period==='this_week'  ?'selected':'' ?>>This Week</option>
        <option value="this_month" <?= $period==='this_month' ?'selected':'' ?>>This Month</option>
        <option value="this_year"  <?= $period==='this_year'  ?'selected':'' ?>>This Year</option>
        <option value="last_year"  <?= $period==='last_year'  ?'selected':'' ?>>Last Year</option>
        <option value="custom"     <?= $period==='custom'     ?'selected':'' ?>>Custom Range</option>
    </select>

    <span id="customDateWrap" style="display:<?= $period==='custom'?'flex':'none' ?>;gap:.4rem;align-items:center;">
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($customFrom) ?>"
               style="width:145px;" onchange="this.form.submit()">
        <span style="color:var(--text-2);font-size:13px;">→</span>
        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($customTo) ?>"
               style="width:145px;" onchange="this.form.submit()">
    </span>

    <a href="?" class="btn btn-ghost">Reset</a>

    <?php if ($dateFrom && $dateTo): ?>
    <span class="text-muted small" style="align-self:center;">
        <?= $dateFrom === $dateTo ? date('M j, Y', strtotime($dateFrom)) : date('M j', strtotime($dateFrom)).' – '.date('M j, Y', strtotime($dateTo)) ?>
    </span>
    <?php endif; ?>
</form>

<script>
function toggleCustomDates(val) {
    document.getElementById('customDateWrap').style.display = val === 'custom' ? 'flex' : 'none';
}
</script>

<!-- Call Log -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Call Log</h2>
        <span class="text-muted small"><?= count($calls) === $perPage ? $perPage.'+' : count($calls) ?> records</span>
    </div>
    <?php if (empty($calls)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon">📞</div>
            <p>No calls found for this filter. <?php if ($isOwner): ?>Use "Sync" to pull from VoIP.ms.<?php endif; ?></p>
        </div>
    </div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Time</th><th>Location</th><th>Number</th><th>Customer</th>
                <th>Direction</th><th>Status</th><th>Duration</th><th>Classification</th><th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($calls as $call): ?>
        <tr>
            <td class="text-muted small" style="white-space:nowrap;">
                <?= date('M j, g:i A', strtotime($call['call_at'])) ?>
            </td>
            <td><span class="badge badge-loc"><?= htmlspecialchars($call['loc_code']) ?></span></td>
            <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars(formatPhone($call['caller_number'])) ?></td>
            <td>
                <?php if ($call['first_name']): ?>
                <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= $call['customer_id'] ?>">
                    <?= htmlspecialchars($call['first_name'] . ' ' . $call['last_name']) ?>
                </a>
                <?php else: ?>
                <span class="text-muted small">Unknown</span>
                <?php endif; ?>
            </td>
            <td>
                <?= $call['direction']==='inbound'
                    ? '<span style="color:var(--green);">✓ In</span>'
                    : '<span style="color:var(--blue);">↗ Out</span>' ?>
            </td>
            <td>
                <?php
                $sc = ['answered'=>'badge-success','missed'=>'badge-danger','busy'=>'badge-warning','failed'=>'badge-danger'][$call['status']] ?? 'badge-secondary';
                ?>
                <span class="badge <?= $sc ?>"><?= ucfirst(htmlspecialchars($call['status'])) ?></span>
                <?php if (!empty($call['needs_callback']) && ($call['callback_status'] ?? 'pending')==='pending'): ?>
                <span class="badge badge-warning" style="margin-left:2px;">CB</span>
                <?php endif; ?>
            </td>
            <td class="text-muted small">
                <?php $d = (int)$call['duration_seconds']; echo $d > 0 ? floor($d/60).'m '.($d%60).'s' : '—'; ?>
            </td>
            <td class="small">
                <?php $cls = $call['classification'] ?? 'unclassified'; ?>
                <?php if ($cls && $cls !== 'unclassified'): ?>
                <span style="color:var(--blue);"><?= htmlspecialchars(str_replace('_',' ',ucwords($cls,'_'))) ?></span>
                <?php else: ?>
                <span style="color:var(--warning);">Unclassified</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="<?= APP_URL ?>/modules/calls/classify.php?id=<?= $call['id'] ?>" class="btn btn-sm btn-secondary">
                    <?= $call['classification']==='unclassified' ? 'Classify' : 'View' ?>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($page > 1 || count($calls) === $perPage): ?>
    <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);">
        <span class="text-muted small">Page <?= $page ?></span>
        <div style="display:flex;gap:.5rem;">
            <?php if ($page > 1): ?>
            <a href="?<?= filterQS(['page' => $page-1]) ?>" class="btn btn-sm btn-secondary">← Prev</a>
            <?php endif; ?>
            <?php if (count($calls) === $perPage): ?>
            <a href="?<?= filterQS(['page' => $page+1]) ?>" class="btn btn-sm btn-secondary">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
