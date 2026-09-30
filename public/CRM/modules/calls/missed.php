<?php
// ============================================================
// Missed Calls — Callback Queue
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user    = Auth::user();
$isOwner = Auth::isOwner();

// ── Move to Follow-Up ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'to_followup') {
    $callId = intval($_POST['call_id'] ?? 0);
    $dueAt  = trim($_POST['due_at'] ?? '');
    $title  = trim($_POST['title']  ?? '');
    if ($callId && $dueAt && $title) {
        $call = DB::queryOne(
            "SELECT cl.*, COALESCE(c.id, c2.id) AS cust_id
             FROM call_logs cl
             LEFT JOIN customers c  ON c.id  = cl.customer_id
             LEFT JOIN customers c2 ON c2.phone_normalized = cl.caller_number AND cl.customer_id IS NULL
             WHERE cl.id = ?", [$callId]
        );
        if ($call) {
            DB::execute(
                'INSERT INTO follow_ups (location_id, customer_id, type, title, notes, due_at, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$call['location_id'], $call['cust_id'] ?: null, 'callback', $title,
                 'From missed call: ' . formatPhone($call['caller_number']), $dueAt, 'pending', $user['id']]
            );
            // Clear from callback queue
            DB::execute('UPDATE call_logs SET needs_callback=0, callback_status=? WHERE id=?',
                ['completed', $callId]);
        }
    }
    header('Location: ' . APP_URL . '/modules/calls/missed.php?moved=1'); exit;
}

// ── Hard delete call log (owner only) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_call' && $isOwner) {
    $callId = intval($_POST['call_id'] ?? 0);
    if ($callId) {
        DB::execute('DELETE FROM callback_attempts WHERE call_log_id=?', [$callId]);
        DB::execute('DELETE FROM call_logs WHERE id=?', [$callId]);
    }
    header('Location: ' . APP_URL . '/modules/calls/missed.php?deleted=1'); exit;
}

$where  = ["cl.status = 'missed'", "cl.direction = 'inbound'", "cl.needs_callback = 1"];
$params = [];

if (!Auth::isOwner() && $user['location_id']) {
    $where[]  = 'cl.location_id = ?';
    $params[] = $user['location_id'];
}

$missed = DB::query(
    "SELECT cl.*, l.code AS loc_code, l.name AS loc_name,
            COALESCE(c.first_name, c2.first_name) AS first_name,
            COALESCE(c.last_name,  c2.last_name)  AS last_name
     FROM call_logs cl
     LEFT JOIN locations l  ON l.id  = cl.location_id
     LEFT JOIN customers c  ON c.id  = cl.customer_id
     LEFT JOIN customers c2 ON c2.phone_normalized = cl.caller_number AND cl.customer_id IS NULL
     WHERE " . implode(' AND ', $where) . "
     ORDER BY
       CASE cl.callback_status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,
       cl.call_at ASC",
    $params
);

$now     = time();
$overdue = array_filter($missed, fn($c) => !empty($c['callback_due_at']) && strtotime($c['callback_due_at']) < $now && ($c['callback_status'] ?? 'pending') === 'pending');
$pending = array_filter($missed, fn($c) =>  empty($c['callback_due_at']) || strtotime($c['callback_due_at']) >= $now || ($c['callback_status'] ?? 'pending') !== 'pending');

function callRow(array $call, bool $isOwner): string {
    $isOverdue = !empty($call['callback_due_at']) && strtotime($call['callback_due_at']) < time()
                 && ($call['callback_status'] ?? 'pending') === 'pending';
    $btnClass  = $isOverdue ? 'btn-danger' : 'btn-secondary';
    $defaultTitle = 'Call back ' . formatPhone($call['caller_number'])
        . ($call['first_name'] ? ' — ' . $call['first_name'] . ' ' . $call['last_name'] : '');
    $cid = $call['id'];

    ob_start(); ?>
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border);">
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:180px;">
                <div style="font-weight:600;">
                    <?= htmlspecialchars(formatPhone($call['caller_number'])) ?>
                    <?php if ($call['first_name']): ?>
                     — <?= htmlspecialchars($call['first_name'] . ' ' . $call['last_name']) ?>
                    <?php endif; ?>
                </div>
                <div class="text-muted small">
                    Called <?= timeAgo($call['call_at']) ?>
                    · <?= intval($call['callback_attempts'] ?? 0) ?>/3 attempts
                    · <?= ucfirst(str_replace('_', ' ', $call['callback_status'] ?? 'pending')) ?>
                    <?php if (!empty($call['callback_due_at'])): ?>
                    · Due <?= date('g:i A', strtotime($call['callback_due_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="badge badge-loc"><?= htmlspecialchars($call['loc_code']) ?></span>
            <div style="display:flex;gap:.4rem;align-items:center;flex-shrink:0;">
                <a href="classify.php?id=<?= $cid ?>" class="btn btn-sm <?= $btnClass ?>">
                    <?= $isOverdue ? '🚨 Call Back' : 'Handle' ?>
                </a>
                <button class="btn btn-sm btn-secondary"
                        onclick="document.getElementById('fu-<?= $cid ?>').style.display='block';this.style.display='none'">
                    📋 → Follow-Up
                </button>
                <?php if ($isOwner): ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Hard delete this call log? Cannot be undone.')">
                    <input type="hidden" name="action"  value="delete_call">
                    <input type="hidden" name="call_id" value="<?= $cid ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            style="padding:.2rem .5rem;font-size:12px;">🗑</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Move to Follow-Up inline form -->
        <div id="fu-<?= $cid ?>" style="display:none;margin-top:.65rem;padding:.75rem;
                                         background:var(--surface-2);border-radius:8px;">
            <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="action"  value="to_followup">
                <input type="hidden" name="call_id" value="<?= $cid ?>">
                <div class="form-group" style="flex:2;min-width:180px;margin:0;">
                    <label class="form-label" style="font-size:12px;">Task Description</label>
                    <input type="text" name="title" class="form-control"
                           value="<?= htmlspecialchars($defaultTitle) ?>" required>
                </div>
                <div class="form-group" style="flex:1;min-width:160px;margin:0;">
                    <label class="form-label" style="font-size:12px;">Due Date & Time</label>
                    <input type="datetime-local" name="due_at" class="form-control" required
                           value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Move to Follow-Ups</button>
                <button type="button" class="btn btn-sm btn-ghost"
                        onclick="document.getElementById('fu-<?= $cid ?>').style.display='none'">Cancel</button>
            </form>
        </div>
    </div>
    <?php return ob_get_clean();
}

$pageTitle = 'Missed Calls';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📵 Missed Calls</h1>
        <p class="page-sub"><?= count($missed) ?> needing callback</p>
    </div>
    <a href="index.php" class="btn btn-secondary">← All Calls</a>
</div>

<?php if (isset($_GET['moved'])): ?>
<div class="alert alert-success">✓ Moved to Follow-Ups.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success">✓ Call deleted.</div>
<?php endif; ?>

<?php if (count($overdue) > 0): ?>
<div class="card" style="margin-bottom:1.5rem;border:2px solid var(--red);">
    <div class="card-header">
        <h2 class="card-title" style="color:var(--red);">🚨 Overdue Callbacks (<?= count($overdue) ?>)</h2>
        <span class="text-muted small">Past 30-minute SLO</span>
    </div>
    <div class="card-body" style="padding:0;">
    <?php foreach ($overdue as $call): ?>
    <?= callRow($call, $isOwner) ?>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2 class="card-title">Callback Queue (<?= count($pending) ?>)</h2></div>
    <?php if (empty($missed)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon">✅</div>
            <p>No missed calls needing callback.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
    <?php foreach ($pending as $call): ?>
    <?= callRow($call, $isOwner) ?>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
