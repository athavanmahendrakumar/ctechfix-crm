<?php
// ============================================================
// Follow-Ups
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/Audit.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$filter    = $_GET['filter'] ?? 'open';

// ── POST: Create follow-up ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $locationId = ($isOwner || $isManager)
        ? intval($_POST['location_id'] ?? $user['location_id'])
        : intval($user['location_id']);
    $type  = in_array($_POST['type'] ?? '', ['callback','appointment','general']) ? $_POST['type'] : 'general';
    $title = trim($_POST['title'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $dueAt = trim($_POST['due_at'] ?? '');
    $phone = trim($_POST['customer_phone'] ?? '');

    $customerId = null;
    if ($phone) {
        $norm = preg_replace('/\D/', '', $phone);
        if (strlen($norm) === 10) $norm = '1' . $norm;
        $cust = DB::queryOne('SELECT id FROM customers WHERE phone_normalized=? LIMIT 1', [$norm]);
        $customerId = $cust['id'] ?? null;
    }

    if ($title && $dueAt && $locationId) {
        DB::execute(
            'INSERT INTO follow_ups (location_id, customer_id, type, title, notes, due_at, status, created_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [$locationId, $customerId, $type, $title, $notes ?: null, $dueAt, 'pending', $user['id']]
        );
    }
    header('Location: ' . APP_URL . '/modules/calls/followups.php?filter=open&created=1'); exit;
}

// ── POST: Complete follow-up ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    $fid = intval($_POST['followup_id']);
    DB::execute(
        'UPDATE follow_ups SET status=?, completed_at=NOW(), completed_by=?, resolution=? WHERE id=?',
        ['completed', $user['id'], trim($_POST['resolution'] ?? ''), $fid]
    );
    Audit::log('followup.completed', 'calls', $fid, null, null, null);
    header('Location: ' . APP_URL . '/modules/calls/followups.php?filter=' . $filter . '&done=' . $fid); exit;
}

// ── POST: Delete (owner/manager only) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && ($isOwner || $isManager)) {
    DB::execute('DELETE FROM follow_ups WHERE id=?', [intval($_POST['followup_id'])]);
    header('Location: ' . APP_URL . '/modules/calls/followups.php?filter=' . $filter); exit;
}

// ── Build list query ─────────────────────────────────────────
$where  = ['1=1'];
$params = [];

// Staff see all follow-ups at their location (not just assigned to them)
if (!$isOwner && !$isManager && $user['location_id']) {
    $where[]  = 'f.location_id = ?';
    $params[] = $user['location_id'];
}

if ($filter === 'open')     { $where[] = "f.status IN ('pending','in_progress')"; }
elseif ($filter === 'done') { $where[] = "f.status = 'completed'"; }

$followups = DB::query(
    "SELECT f.*, l.code AS loc_code,
            c.first_name, c.last_name, c.phone_primary AS customer_phone,
            u.first_name AS assigned_first,
            cb.first_name AS completed_by_name
     FROM follow_ups f
     LEFT JOIN locations l  ON l.id  = f.location_id
     LEFT JOIN customers c  ON c.id  = f.customer_id
     LEFT JOIN users u      ON u.id  = f.assigned_to
     LEFT JOIN users cb     ON cb.id = f.completed_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY f.due_at ASC LIMIT 200",
    $params
);

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);
$now       = new DateTime();

$typeLabels = [
    'callback'    => ['label' => '📞 Call Back',      'color' => 'badge-info'],
    'appointment' => ['label' => '🏪 Customer Visit',  'color' => 'badge-primary'],
    'general'     => ['label' => '📋 General Task',    'color' => 'badge-secondary'],
    'sms_reply'   => ['label' => '💬 SMS Reply',       'color' => 'badge-warning'],
    'estimate'    => ['label' => '💰 Estimate',         'color' => 'badge-success'],
];

$pageTitle = 'Follow-Ups';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📋 Follow-Ups</h1>
        <p class="page-sub"><?= count($followups) ?> <?= $filter === 'open' ? 'open' : 'completed' ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="?filter=open" class="btn <?= $filter==='open' ? 'btn-primary' : 'btn-secondary' ?>">Open</a>
        <a href="?filter=done" class="btn <?= $filter==='done' ? 'btn-primary' : 'btn-secondary' ?>">Completed</a>
        <button class="btn btn-secondary" onclick="togglePanel('new-followup-panel')">+ New Follow-Up</button>
        <a href="index.php" class="btn btn-secondary">← Calls</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success">✓ Follow-up created.</div>
<?php endif; ?>

<!-- ── Create Panel ───────────────────────────────────────── -->
<div id="new-followup-panel" style="display:none;margin-bottom:1.25rem;">
<div class="card">
    <div class="card-header"><h2 class="card-title">New Follow-Up</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Task Description <span class="required">*</span></label>
                    <input type="text" name="title" class="form-control" required
                           placeholder="e.g. Call John back about iPhone 14 screen quote">
                </div>
                <div class="form-group">
                    <label class="form-label">Type <span class="required">*</span></label>
                    <select name="type" class="form-control">
                        <option value="callback">📞 Call Back</option>
                        <option value="appointment">🏪 Customer Visit</option>
                        <option value="general">📋 General Task</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Due Date & Time <span class="required">*</span></label>
                    <input type="datetime-local" name="due_at" class="form-control" required
                           value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
                </div>
                <?php if ($isOwner || $isManager): ?>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control">
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"
                            <?= $loc['id'] == $user['location_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Customer Phone <span class="text-muted small">(optional)</span></label>
                    <input type="text" name="customer_phone" class="form-control"
                           placeholder="Auto-links to customer record">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"
                          placeholder="Any context or details for whoever picks this up..."></textarea>
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-primary">Create Follow-Up</button>
                <button type="button" class="btn btn-secondary"
                        onclick="togglePanel('new-followup-panel')">Cancel</button>
            </div>
        </form>
    </div>
</div>
</div>

<!-- ── Follow-Up List ─────────────────────────────────────── -->
<div class="card">
    <?php if (empty($followups)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon">✅</div>
            <p>No <?= $filter === 'open' ? 'open' : 'completed' ?> follow-ups.</p>
            <button class="btn btn-primary" onclick="togglePanel('new-followup-panel')">+ Create One</button>
        </div>
    </div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
    <?php foreach ($followups as $f):
        $due       = new DateTime($f['due_at']);
        $isOverdue = $f['status'] !== 'completed' && $due < $now;
        $typeInfo  = $typeLabels[$f['type']] ?? $typeLabels['general'];
    ?>
    <div style="padding:.85rem 1rem;border-bottom:1px solid var(--border);
                background:<?= $isOverdue ? 'rgba(220,38,38,.04)' : 'transparent' ?>;">

        <div style="display:flex;align-items:flex-start;gap:.75rem;flex-wrap:wrap;">

            <!-- Type badge -->
            <div style="padding-top:2px;flex-shrink:0;">
                <span class="badge <?= $typeInfo['color'] ?>" style="font-size:12px;">
                    <?= $typeInfo['label'] ?>
                </span>
            </div>

            <!-- Content -->
            <div style="flex:1;min-width:180px;">
                <div style="font-weight:600;font-size:15px;">
                    <?= htmlspecialchars($f['title']) ?>
                    <?php if ($isOverdue): ?>
                    <span style="color:var(--red);font-size:12px;font-weight:700;margin-left:6px;">OVERDUE</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small" style="margin-top:3px;display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;">
                    <span>📅 <?= date('M j, Y g:i A', strtotime($f['due_at'])) ?></span>
                    <?php if ($f['loc_code']): ?>
                    <span class="badge badge-loc" style="font-size:11px;"><?= htmlspecialchars($f['loc_code']) ?></span>
                    <?php endif; ?>
                    <?php if ($f['first_name']): ?>
                    <span>👤 <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>
                        <?php if ($f['customer_phone']): ?>
                        · <a href="tel:<?= htmlspecialchars($f['customer_phone']) ?>"
                             style="color:var(--primary);"><?= htmlspecialchars($f['customer_phone']) ?></a>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($f['notes']): ?>
                <div style="font-size:13px;color:var(--text-2);margin-top:4px;">
                    <?= htmlspecialchars($f['notes']) ?>
                </div>
                <?php endif; ?>
                <?php if ($f['status'] === 'completed'): ?>
                <div style="font-size:12px;color:var(--green);margin-top:5px;">
                    ✓ Completed <?= timeAgo($f['completed_at']) ?>
                    <?php if ($f['completed_by_name']): ?> by <?= htmlspecialchars($f['completed_by_name']) ?><?php endif; ?>
                    <?php if ($f['resolution']): ?> — "<?= htmlspecialchars($f['resolution']) ?>"<?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Action buttons -->
            <div style="display:flex;gap:.4rem;align-items:center;flex-shrink:0;" id="actions-<?= $f['id'] ?>">
                <?php if ($f['status'] !== 'completed'): ?>
                <button class="btn btn-sm btn-success"
                        onclick="showDone(<?= $f['id'] ?>)">✓ Done</button>
                <?php else: ?>
                <span style="color:var(--green);font-size:13px;font-weight:600;">✓ Done</span>
                <?php endif; ?>
                <?php if ($isOwner || $isManager): ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Delete this follow-up?')">
                    <input type="hidden" name="action"      value="delete">
                    <input type="hidden" name="followup_id" value="<?= $f['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            style="padding:.2rem .5rem;font-size:12px;">🗑</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Complete form -->
        <?php if ($f['status'] !== 'completed'): ?>
        <div id="done-<?= $f['id'] ?>" style="display:none;margin-top:.75rem;padding:.75rem;
                                               background:var(--surface-2);border-radius:8px;">
            <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action"      value="complete">
                <input type="hidden" name="followup_id" value="<?= $f['id'] ?>">
                <input type="text" name="resolution" class="form-control"
                       placeholder="What happened? e.g. Left voicemail, will call back Thursday"
                       style="flex:1;min-width:200px;" autofocus>
                <button type="submit" class="btn btn-sm btn-success">Mark Complete</button>
                <button type="button" class="btn btn-sm btn-ghost"
                        onclick="hideDone(<?= $f['id'] ?>)">Cancel</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function togglePanel(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        el.querySelector('input[name="title"]')?.focus();
        el.scrollIntoView({behavior:'smooth', block:'start'});
    }
}
function showDone(id) {
    document.getElementById('done-' + id).style.display = 'block';
    document.getElementById('actions-' + id).style.display = 'none';
    document.getElementById('done-' + id).querySelector('input[name="resolution"]')?.focus();
}
function hideDone(id) {
    document.getElementById('done-' + id).style.display = 'none';
    document.getElementById('actions-' + id).style.display = 'flex';
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
