<?php
// ============================================================
// Manager Dashboard
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::requireRole('manager', 'owner');

$user       = Auth::user();
$isTraining = Auth::isTraining();

// Scope to this manager's location
$locationId = $user['location_id'];
$location   = $locationId
    ? DB::queryOne('SELECT * FROM locations WHERE id=? LIMIT 1', [$locationId])
    : null;

// Live counts — scoped to manager's location
$missedCalls = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM call_logs
     WHERE " . ($locationId ? "location_id=? AND " : "") . "status='missed'
       AND direction='inbound' AND needs_callback=1 AND callback_status='pending'",
    $locationId ? [$locationId] : []
)['c'] ?? 0);

$activeRepairs = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM repairs
     WHERE " . ($locationId ? "location_id=? AND " : "") . "status NOT IN ('completed','cancelled','closed')",
    $locationId ? [$locationId] : []
)['c'] ?? 0);

$overdueRepairs = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM repairs
     WHERE " . ($locationId ? "location_id=? AND " : "") . "status NOT IN ('completed','cancelled','closed')
       AND estimated_ready_at IS NOT NULL AND estimated_ready_at < NOW()",
    $locationId ? [$locationId] : []
)['c'] ?? 0);

$followUpsDue = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM follow_ups
     WHERE " . ($locationId ? "location_id=? AND " : "") . "status IN ('pending','in_progress') AND due_at <= NOW()",
    $locationId ? [$locationId] : []
)['c'] ?? 0);

// Today's calls
$todayCalls = DB::queryOne(
    "SELECT SUM(direction='inbound') AS total, SUM(status='missed') AS missed
     FROM call_logs
     WHERE DATE(call_at) = CURDATE()" . ($locationId ? " AND location_id=?" : ""),
    $locationId ? [$locationId] : []
);

// Today's repairs
$todayRepairsCreated   = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM repairs WHERE DATE(created_at)=CURDATE()"
    . ($locationId ? " AND location_id=?" : ""),
    $locationId ? [$locationId] : [])['c'] ?? 0);
$todayRepairsCompleted = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM repairs WHERE DATE(updated_at)=CURDATE() AND status='completed'"
    . ($locationId ? " AND location_id=?" : ""),
    $locationId ? [$locationId] : [])['c'] ?? 0);

// Missed calls list for quick view
$missedList = DB::query(
    "SELECT cl.*, l.code AS loc_code,
            COALESCE(c.first_name, c2.first_name) AS first_name,
            COALESCE(c.last_name,  c2.last_name)  AS last_name
     FROM call_logs cl
     LEFT JOIN locations l  ON l.id  = cl.location_id
     LEFT JOIN customers c  ON c.id  = cl.customer_id
     LEFT JOIN customers c2 ON c2.phone_normalized = cl.caller_number AND cl.customer_id IS NULL
     WHERE cl.status='missed' AND cl.direction='inbound'
       AND cl.needs_callback=1 AND cl.callback_status='pending'"
    . ($locationId ? " AND cl.location_id=?" : "") .
    " ORDER BY cl.call_at ASC LIMIT 5",
    $locationId ? [$locationId] : []
);

// Staff clocked in at this location
$staffClockedIn = (int)(DB::queryOne(
    "SELECT COUNT(*) AS c FROM staff_attendance
     WHERE clock_out IS NULL" . ($locationId ? " AND location_id=?" : ""),
    $locationId ? [$locationId] : []
)['c'] ?? 0);

$pageTitle = 'Dashboard';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📊 Manager Dashboard</h1>
        <p class="page-sub">
            <?= $location ? htmlspecialchars($location['name']) . ' · ' : 'All Locations · ' ?>
            <?= date('l, F j') ?>
        </p>
    </div>
</div>

<!-- Metrics row -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">
    <div class="metric-card <?= $missedCalls>0?'accent-red':'accent-green' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/calls/missed.php'" style="cursor:pointer;">
        <div class="metric-label">Missed Calls</div>
        <div class="metric-value"><?= $missedCalls ?></div>
        <div class="metric-sub">Need callback</div>
    </div>
    <div class="metric-card accent-blue"
         onclick="location.href='<?= APP_URL ?>/modules/repairs/'" style="cursor:pointer;">
        <div class="metric-label">Active Repairs</div>
        <div class="metric-value"><?= $activeRepairs ?></div>
        <div class="metric-sub">In progress</div>
    </div>
    <div class="metric-card <?= $overdueRepairs>0?'accent-red':'accent-green' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/repairs/'" style="cursor:pointer;">
        <div class="metric-label">Overdue Repairs</div>
        <div class="metric-value"><?= $overdueRepairs ?></div>
        <div class="metric-sub">Past ETA</div>
    </div>
    <div class="metric-card <?= $followUpsDue>0?'accent-yellow':'accent-green' ?>"
         onclick="location.href='<?= APP_URL ?>/modules/calls/followups.php'" style="cursor:pointer;">
        <div class="metric-label">Follow-Ups Due</div>
        <div class="metric-value"><?= $followUpsDue ?></div>
        <div class="metric-sub">Today or overdue</div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Today's Calls</div>
        <div class="metric-value"><?= intval($todayCalls['total'] ?? 0) ?></div>
        <div class="metric-sub"><?= intval($todayCalls['missed'] ?? 0) ?> missed</div>
    </div>
    <div class="metric-card accent-blue">
        <div class="metric-label">Repairs Today</div>
        <div class="metric-value"><?= $todayRepairsCreated ?></div>
        <div class="metric-sub"><?= $todayRepairsCompleted ?> completed</div>
    </div>
    <div class="metric-card accent-green" style="cursor:pointer;"
         onclick="location.href='<?= APP_URL ?>/modules/staff/attendance.php'">
        <div class="metric-label">Staff Clocked In</div>
        <div class="metric-value"><?= $staffClockedIn ?></div>
        <div class="metric-sub">Right now · <a href="<?= APP_URL ?>/modules/staff/attendance.php" style="color:inherit;">View →</a></div>
    </div>
</div>

<div class="repair-grid">
    <!-- Missed Calls Quick View -->
    <div class="repair-col-main">
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header">
                <h2 class="card-title">📞 Missed Calls — Callback Queue</h2>
                <a href="<?= APP_URL ?>/modules/calls/missed.php" class="btn btn-sm btn-secondary">View All</a>
            </div>
            <?php if (empty($missedList)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <p>No missed calls needing callback.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body" style="padding:0;">
            <?php foreach ($missedList as $call): ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.65rem 1rem;border-bottom:1px solid var(--border);">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;">
                        <?= htmlspecialchars(formatPhone($call['caller_number'])) ?>
                        <?php if ($call['first_name']): ?>
                        — <?= htmlspecialchars($call['first_name'] . ' ' . $call['last_name']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small"><?= timeAgo($call['call_at']) ?></div>
                </div>
                <span class="badge badge-loc"><?= htmlspecialchars($call['loc_code'] ?? '') ?></span>
                <a href="<?= APP_URL ?>/modules/calls/classify.php?id=<?= $call['id'] ?>"
                   class="btn btn-sm btn-secondary">Handle</a>
            </div>
            <?php endforeach; ?>
            <?php if ($missedCalls > 5): ?>
            <div style="padding:.65rem 1rem;text-align:center;">
                <a href="<?= APP_URL ?>/modules/calls/missed.php" class="btn btn-sm btn-ghost">
                    + <?= $missedCalls - 5 ?> more →
                </a>
            </div>
            <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Today's Snapshot -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📅 Today at a Glance</h2>
                <span class="text-muted small"><?= date('D, M j') ?></span>
            </div>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <tbody>
                    <tr>
                        <td>Calls Received</td>
                        <td style="text-align:right;font-weight:700;"><?= intval($todayCalls['total'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td>Calls Missed</td>
                        <td style="text-align:right;font-weight:700;color:<?= intval($todayCalls['missed']??0)>0?'var(--red)':'inherit' ?>;"><?= intval($todayCalls['missed'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td>Repairs Created</td>
                        <td style="text-align:right;font-weight:700;"><?= $todayRepairsCreated ?></td>
                    </tr>
                    <tr>
                        <td>Repairs Completed</td>
                        <td style="text-align:right;font-weight:700;color:var(--green);"><?= $todayRepairsCompleted ?></td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="repair-col-side">
        <div class="card">
            <div class="card-header"><h2 class="card-title">⚡ Quick Actions</h2></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem;">
                <a href="<?= APP_URL ?>/modules/calls/missed.php"    class="btn btn-ghost">📞 Callback Queue</a>
                <a href="<?= APP_URL ?>/modules/repairs/"             class="btn btn-ghost">🔧 View Repairs</a>
                <a href="<?= APP_URL ?>/modules/customers/create.php" class="btn btn-ghost">👥 New Customer</a>
                <a href="<?= APP_URL ?>/modules/staff/index.php"      class="btn btn-ghost">👤 Staff</a>
                <a href="<?= APP_URL ?>/modules/calls/sms.php"        class="btn btn-ghost">💬 Send SMS</a>
                <a href="<?= APP_URL ?>/modules/expenses/index.php"   class="btn btn-ghost">💰 Log Expense</a>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
