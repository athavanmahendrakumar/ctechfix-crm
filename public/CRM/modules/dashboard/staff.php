<?php
// ============================================================
// Staff Dashboard
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user       = Auth::user();
$isTraining = Auth::isTraining();
$locationId = $user['location_id'];

$location = $locationId
    ? DB::queryOne('SELECT * FROM locations WHERE id=? LIMIT 1', [$locationId])
    : null;

// My open follow-ups
$myFollowups = DB::query(
    "SELECT f.*, c.first_name, c.last_name
     FROM follow_ups f
     LEFT JOIN customers c ON c.id = f.customer_id
     WHERE f.status IN ('pending','in_progress')
       AND (f.assigned_to = ? OR f.assigned_to IS NULL)"
    . ($locationId ? " AND f.location_id=?" : "") .
    " ORDER BY f.due_at ASC LIMIT 10",
    $locationId ? [$user['id'], $locationId] : [$user['id']]
);

// Missed calls in my location needing callback
$missedCalls = DB::query(
    "SELECT cl.*,
            COALESCE(c.first_name, c2.first_name) AS first_name,
            COALESCE(c.last_name,  c2.last_name)  AS last_name
     FROM call_logs cl
     LEFT JOIN customers c  ON c.id  = cl.customer_id
     LEFT JOIN customers c2 ON c2.phone_normalized = cl.caller_number AND cl.customer_id IS NULL
     WHERE cl.status='missed' AND cl.direction='inbound'
       AND cl.needs_callback=1 AND cl.callback_status='pending'"
    . ($locationId ? " AND cl.location_id=?" : "") .
    " ORDER BY cl.call_at ASC LIMIT 5",
    $locationId ? [$locationId] : []
);

$totalMissed = $locationId
    ? (int)(DB::queryOne(
        "SELECT COUNT(*) AS c FROM call_logs
         WHERE status='missed' AND direction='inbound' AND needs_callback=1 AND callback_status='pending' AND location_id=?",
        [$locationId])['c'] ?? 0)
    : 0;

$hour = (int)date('H');
$greeting = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');

// My current attendance shift
$myOpenShift = DB::queryOne(
    "SELECT *, TIMESTAMPDIFF(MINUTE, clock_in, NOW()) AS mins_so_far
     FROM staff_attendance WHERE user_id=? AND clock_out IS NULL LIMIT 1",
    [$user['id']]
);

$pageTitle = 'Dashboard';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            Good <?= $greeting ?>, <?= htmlspecialchars($user['first_name']) ?>! 👋
        </h1>
        <p class="page-sub">
            <?= date('l, F j, Y') ?>
            <?= $location ? ' · ' . htmlspecialchars($location['name']) : '' ?>
            <?php if ($isTraining): ?>
            · <span style="color:var(--warning);">Training Mode</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="repair-grid">

    <!-- Left: Action Items -->
    <div class="repair-col-main">

        <!-- Missed Calls -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header">
                <h2 class="card-title">📞 Missed Calls — Need Callback</h2>
                <?php if ($totalMissed > 0): ?>
                <a href="<?= APP_URL ?>/modules/calls/missed.php" class="btn btn-sm btn-danger">
                    <?= $totalMissed ?> waiting
                </a>
                <?php endif; ?>
            </div>
            <?php if (empty($missedCalls)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <p>No missed calls — you're all caught up.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body" style="padding:0;">
            <?php foreach ($missedCalls as $call): ?>
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
                <a href="<?= APP_URL ?>/modules/calls/classify.php?id=<?= $call['id'] ?>"
                   class="btn btn-sm btn-secondary">Handle</a>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- My Follow-Ups -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📋 My Follow-Ups</h2>
                <a href="<?= APP_URL ?>/modules/calls/followups.php" class="btn btn-sm btn-ghost">View All</a>
            </div>
            <?php if (empty($myFollowups)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <p>No open follow-ups assigned to you.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body" style="padding:0;">
            <?php
            $now = time();
            foreach ($myFollowups as $f):
                $isOverdue = !empty($f['due_at']) && strtotime($f['due_at']) < $now;
            ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.65rem 1rem;border-bottom:1px solid var(--border);background:<?= $isOverdue?'rgba(var(--red-rgb),0.05)':'transparent' ?>;">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;">
                        <?= htmlspecialchars($f['title']) ?>
                        <?php if ($isOverdue): ?>
                        <span style="color:var(--red);font-size:11px;margin-left:6px;">OVERDUE</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small">
                        Due <?= date('M j, g:i A', strtotime($f['due_at'])) ?>
                        <?php if ($f['first_name']): ?>
                        · <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?= APP_URL ?>/modules/calls/followups.php"
                   class="btn btn-sm btn-<?= $isOverdue?'danger':'secondary' ?>">View</a>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Right: Clock In/Out + Quick Start -->
    <div class="repair-col-side">

        <!-- Attendance Card -->
        <div class="card" style="margin-bottom:1rem;<?= $myOpenShift ? 'border:2px solid var(--green);' : '' ?>">
            <div class="card-header">
                <h2 class="card-title">🕐 Attendance</h2>
                <a href="<?= APP_URL ?>/modules/staff/clock.php" class="btn btn-sm btn-ghost">History</a>
            </div>
            <div class="card-body" style="text-align:center;padding:1.25rem;">
                <?php if ($myOpenShift): ?>
                    <div style="font-size:2rem;">🟢</div>
                    <div style="font-weight:700;color:var(--green);margin:.25rem 0;">Clocked In</div>
                    <div class="text-muted small" style="margin-bottom:.5rem;">
                        Since <?= date('g:i A', strtotime($myOpenShift['clock_in'])) ?> ·
                        <?php $m = (int)$myOpenShift['mins_so_far']; echo floor($m/60).'h '.($m%60).'m'; ?>
                    </div>
                    <a href="<?= APP_URL ?>/modules/staff/clock.php" class="btn btn-danger" style="width:100%;">
                        ⏹ Clock Out
                    </a>
                <?php else: ?>
                    <div style="font-size:2rem;">⚪</div>
                    <div style="font-weight:700;color:var(--text-2);margin:.25rem 0;">Not Clocked In</div>
                    <form method="POST" action="<?= APP_URL ?>/modules/staff/clock.php" style="margin-top:.5rem;">
                        <input type="hidden" name="action" value="clock_in">
                        <button type="submit" class="btn btn-success" style="width:100%;">▶ Clock In</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">⚡ Quick Start</h2></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem;">
                <a href="<?= APP_URL ?>/modules/calls/index.php"      class="btn btn-primary">📞 Calls & SMS</a>
                <a href="<?= APP_URL ?>/modules/repairs/"              class="btn btn-ghost">🔧 Repairs</a>
                <a href="<?= APP_URL ?>/modules/customers/create.php"  class="btn btn-ghost">👥 New Customer</a>
                <a href="<?= APP_URL ?>/modules/sales/index.php"       class="btn btn-ghost">🛒 Walk-in Sale</a>
                <a href="<?= APP_URL ?>/modules/calls/sms.php"         class="btn btn-ghost">💬 Send SMS</a>
            </div>
        </div>
    </div>

</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
