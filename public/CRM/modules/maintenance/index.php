<?php
// ============================================================
// Device Maintenance — List
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$isMgr     = $isOwner || $isManager;
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$msg = ''; $msgType = 'success';

// ── Send SMS directly ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_maintenance' && Auth::isOwner()) {
    $delId = intval($_POST['maintenance_id'] ?? 0);
    if ($delId) DB::execute('DELETE FROM device_maintenance WHERE id=?', [$delId]);
    header('Location: ' . APP_URL . '/modules/maintenance/?deleted=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    $mId = intval($_POST['maintenance_id'] ?? 0);
    $m   = DB::queryOne(
        "SELECT m.*, l.code AS loc_code, l.name AS loc_name
         FROM device_maintenance m
         JOIN locations l ON l.id = m.location_id
         WHERE m.id = ?",
        [$mId]
    );
    if ($m) {
        $firstName  = explode(' ', $m['customer_name'])[0];
        $type       = $m['maintenance_type'];
        $device     = trim(($m['device_brand'] ?? '') . ' ' . ($m['device_model'] ?? ''));
        $devicePart = $device ? " for your {$device}" : '';
        $message    = "Hi {$firstName}, C Tech Fix {$m['loc_name']}: Your {$type}{$devicePart} is due. Call or text us to book!";
        $message    = mb_substr($message, 0, 159);

        $fromDid = VoipMS::didForLocation($m['loc_code']);
        $result  = VoipMS::sendSMS($fromDid, $m['customer_phone'], $message);

        if ($result === true) {
            DB::execute(
                "UPDATE device_maintenance SET follow_up_sms_sent=1, follow_up_sms_sent_at=NOW() WHERE id=?",
                [$mId]
            );
            $msg = "✅ SMS sent to {$m['customer_name']}.";
        } else {
            $msg     = '❌ SMS failed: ' . htmlspecialchars((string)$result);
            $msgType = 'danger';
        }
    }
    header('Location: index.php?msg=' . urlencode($msg) . '&mtype=' . urlencode($msgType) . '&status=' . ($_GET['status'] ?? 'upcoming')); exit;
}

// ── Set outcome ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_outcome'])) {
    $mId     = intval($_POST['maintenance_id'] ?? 0);
    $outcome = $_POST['outcome'] ?? '';
    if ($mId && in_array($outcome, ['completed','missed','canceled'], true)) {
        DB::execute(
            "UPDATE device_maintenance SET outcome=?, outcome_at=NOW() WHERE id=?",
            [$outcome, $mId]
        );
        $msg = match($outcome) {
            'completed' => '✅ Marked as completed.',
            'missed'    => '❌ Marked as missed.',
            'canceled'  => '🚫 Marked as canceled.',
        };
    }
    header('Location: index.php?msg=' . urlencode($msg) . '&mtype=' . urlencode($msgType) . '&status=' . ($_POST['back_status'] ?? 'sent')); exit;
}

if (isset($_GET['msg']))   { $msg     = $_GET['msg']; }
if (isset($_GET['mtype'])) { $msgType = $_GET['mtype']; }

// Filters
$filterStatus = $_GET['status']      ?? 'upcoming';  // upcoming | overdue | sent | completed | missed | canceled | all
$filterType   = $_GET['maint_type']  ?? '';
$filterLoc    = $isMgr ? intval($_GET['location_id'] ?? 0) : (int)$user['location_id'];

$where  = ['1=1'];
$params = [];
if ($filterLoc) { $where[] = 'm.location_id=?'; $params[] = $filterLoc; }
elseif (!$isMgr){ $where[] = 'm.location_id=?'; $params[] = $user['location_id']; }
if ($filterType){ $where[] = 'm.maintenance_type=?'; $params[] = $filterType; }

if ($filterStatus === 'upcoming') {
    $where[] = 'm.follow_up_date >= CURDATE()';
    $where[] = 'm.follow_up_sms_sent = 0';
    $where[] = "m.outcome = 'pending'";
} elseif ($filterStatus === 'overdue') {
    $where[] = 'm.follow_up_date < CURDATE()';
    $where[] = 'm.follow_up_sms_sent = 0';
    $where[] = "m.outcome = 'pending'";
} elseif ($filterStatus === 'sent') {
    $where[] = 'm.follow_up_sms_sent = 1';
    $where[] = "m.outcome = 'pending'";
} elseif ($filterStatus === 'completed') {
    $where[] = "m.outcome = 'completed'";
} elseif ($filterStatus === 'missed') {
    $where[] = "m.outcome = 'missed'";
} elseif ($filterStatus === 'canceled') {
    $where[] = "m.outcome = 'canceled'";
}

$records = DB::query(
    "SELECT m.*, l.code AS loc_code, l.name AS loc_name,
            r.record_number AS linked_repair
     FROM device_maintenance m
     LEFT JOIN locations l ON l.id=m.location_id
     LEFT JOIN repairs r ON r.id=m.repair_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY m.follow_up_date ASC",
    $params
);

// Tab counts
$counts = [];
$countRows = DB::query(
    "SELECT
        SUM(follow_up_sms_sent=0 AND follow_up_date >= CURDATE() AND outcome='pending') AS upcoming,
        SUM(follow_up_sms_sent=0 AND follow_up_date < CURDATE()  AND outcome='pending') AS overdue,
        SUM(follow_up_sms_sent=1 AND outcome='pending')                                  AS sent,
        SUM(outcome='completed')                                                          AS completed,
        SUM(outcome='missed')                                                             AS missed,
        SUM(outcome='canceled')                                                           AS canceled
     FROM device_maintenance m
     " . ($filterLoc ? "WHERE m.location_id={$filterLoc}" : (!$isMgr ? "WHERE m.location_id={$user['location_id']}" : '')),
    []
);
if ($countRows) {
    $counts = $countRows[0];
}

$today = date('Y-m-d');

$MAINT_LABELS = [
    'Gaming Console Deep Clean'    => '🎮 Gaming Console Deep Clean',
    'Computer Tune Up'             => '💻 Computer Tune Up',
    'Gaming Computer Maintenance'  => '🖥️ Gaming Computer Maintenance',
    'Phone Plan Renewal'           => '📱 Phone Plan Renewal',
    'Battery Change Reminder'      => '🔋 Battery Change Reminder',
    'Other'                        => '🔧 Other',
];

$pageTitle = 'Device Maintenance';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= htmlspecialchars($msgType) ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">🔧 Device Maintenance</h1>
        <p class="page-sub">Follow-up reminders and outcome tracking</p>
    </div>
    <a href="add.php" class="btn btn-primary">+ Add Record</a>
</div>

<!-- Tab Nav -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
    <?php
    $tabs = [
        'upcoming'  => ['label' => 'Upcoming',  'color' => 'var(--blue)'],
        'overdue'   => ['label' => 'Overdue',   'color' => 'var(--red)'],
        'sent'      => ['label' => 'SMS Sent',  'color' => 'var(--amber)'],
        'completed' => ['label' => '✅ Completed','color' => 'var(--green)'],
        'missed'    => ['label' => '❌ Missed',  'color' => 'var(--red)'],
        'canceled'  => ['label' => '🚫 Canceled','color' => 'var(--text-3)'],
    ];
    foreach ($tabs as $key => $tab):
        $active = $filterStatus === $key;
        $count  = (int)($counts[$key] ?? 0);
    ?>
    <a href="?status=<?= $key ?><?= $filterType ? '&maint_type='.urlencode($filterType) : '' ?><?= $filterLoc ? '&location_id='.$filterLoc : '' ?>"
       style="padding:.4rem .85rem;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;
              background:<?= $active ? $tab['color'] : 'var(--bg-2)' ?>;
              color:<?= $active ? '#fff' : 'var(--text)' ?>;
              border:1px solid <?= $active ? $tab['color'] : 'var(--border)' ?>;">
        <?= $tab['label'] ?><?= $count ? " ({$count})" : '' ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Secondary filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    <select name="maint_type" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Types</option>
        <?php foreach (array_keys($MAINT_LABELS) as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>" <?= $filterType===$t?'selected':'' ?>><?= htmlspecialchars($t) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($isMgr): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLoc==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <a href="?status=<?= htmlspecialchars($filterStatus) ?>" class="btn btn-ghost">Reset</a>
</form>

<?php if (empty($records)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state">
        <div class="empty-icon">🔧</div>
        <p>No maintenance records found.</p>
        <a href="add.php" class="btn btn-primary" style="margin-top:1rem;">+ Add First Record</a>
    </div>
</div></div>
<?php else: ?>
<div class="card">
<div class="card-body" style="padding:0;">
<table class="data-table">
    <thead>
        <tr>
            <th>Customer</th>
            <th>Device</th>
            <th>Maintenance</th>
            <th>Service Date</th>
            <th>Follow-up</th>
            <th>SMS Status</th>
            <th>Outcome</th>
            <?php if ($isMgr): ?><th>Loc</th><?php endif; ?>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($records as $m):
        $followUp  = $m['follow_up_date'];
        $daysUntil = (int)round((strtotime($followUp) - strtotime($today)) / 86400);
        $isOverdue = !$m['follow_up_sms_sent'] && $followUp < $today;
        $isDueSoon = !$m['follow_up_sms_sent'] && $daysUntil >= 0 && $daysUntil <= 7;
        $outcome   = $m['outcome'] ?? 'pending';
    ?>
    <tr>
        <td>
            <div style="font-weight:600;"><?= htmlspecialchars($m['customer_name']) ?></div>
            <div style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars($m['customer_phone']) ?></div>
        </td>
        <td>
            <div><?= htmlspecialchars($m['device_type']) ?></div>
            <?php if ($m['device_brand'] || $m['device_model']): ?>
            <div style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars(trim($m['device_brand'] . ' ' . $m['device_model'])) ?></div>
            <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($MAINT_LABELS[$m['maintenance_type']] ?? $m['maintenance_type']) ?></td>
        <td><?= date('M j, Y', strtotime($m['service_date'])) ?></td>
        <td>
            <?php
            $fuLabel = date('M j, Y', strtotime($followUp));
            $fuColor = $isOverdue ? 'var(--red)' : ($isDueSoon ? 'var(--amber)' : 'var(--text)');
            $fuNote  = $isOverdue ? ' (overdue)' : ($isDueSoon ? ' (' . $daysUntil . 'd)' : '');
            ?>
            <span style="color:<?= $fuColor ?>;font-weight:<?= ($isOverdue||$isDueSoon)?'700':'400' ?>;">
                <?= $fuLabel . $fuNote ?>
            </span>
            <div style="font-size:11px;color:var(--text-3);"><?= $m['follow_up_months'] ?> month reminder</div>
        </td>
        <td>
            <?php if ($m['follow_up_sms_sent']): ?>
            <span style="color:var(--green);font-weight:600;">✓ Sent</span>
            <div style="font-size:11px;color:var(--text-3);"><?= $m['follow_up_sms_sent_at'] ? date('M j', strtotime($m['follow_up_sms_sent_at'])) : '' ?></div>
            <?php elseif ($isOverdue): ?>
            <span style="color:var(--red);font-weight:600;">⚠ Overdue</span>
            <?php elseif ($isDueSoon): ?>
            <span style="color:var(--amber);font-weight:600;">⏰ Due soon</span>
            <?php else: ?>
            <span style="color:var(--text-3);font-size:13px;">Pending</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($outcome === 'completed'): ?>
                <span style="color:var(--green);font-weight:600;">✅ Completed</span>
                <?php if ($m['linked_repair']): ?>
                <div style="font-size:11px;"><a href="<?= APP_URL ?>/modules/repairs/view.php?id=<?= $m['repair_id'] ?>">#<?= htmlspecialchars($m['linked_repair']) ?></a></div>
                <?php endif; ?>
            <?php elseif ($outcome === 'missed'): ?>
                <span style="color:var(--red);font-weight:600;">❌ Missed</span>
            <?php elseif ($outcome === 'canceled'): ?>
                <span style="color:var(--text-3);font-weight:600;">🚫 Canceled</span>
            <?php else: ?>
                <span style="color:var(--text-3);font-size:13px;">—</span>
            <?php endif; ?>
        </td>
        <?php if ($isMgr): ?>
        <td><span class="badge badge-loc"><?= htmlspecialchars($m['loc_code'] ?? '—') ?></span></td>
        <?php endif; ?>
        <td style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end;">
            <a href="add.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
            <?php if (Auth::isOwner()): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action"         value="delete_maintenance">
                <input type="hidden" name="maintenance_id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Delete this maintenance record? This cannot be undone.')"
                        style="padding:.2rem .55rem;font-size:12px;">🗑</button>
            </form>
            <?php endif; ?>

            <?php if ($outcome === 'pending'): ?>

                <?php if (!$m['follow_up_sms_sent']): ?>
                <!-- Send SMS -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Send follow-up SMS to <?= htmlspecialchars(addslashes($m['customer_name'])) ?>?');">
                    <input type="hidden" name="send_sms" value="1">
                    <input type="hidden" name="maintenance_id" value="<?= $m['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary">💬 SMS</button>
                </form>
                <?php endif; ?>

                <!-- Convert to Repair -->
                <a href="<?= APP_URL ?>/modules/repairs/new.php?from_maintenance=<?= $m['id'] ?>"
                   class="btn btn-sm btn-success" title="Create repair ticket from this maintenance record">
                    🔧 → Repair
                </a>

                <!-- Outcome buttons (only after SMS sent) -->
                <?php if ($m['follow_up_sms_sent']): ?>
                <div style="display:flex;gap:.25rem;">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as Completed?');">
                        <input type="hidden" name="set_outcome" value="1">
                        <input type="hidden" name="maintenance_id" value="<?= $m['id'] ?>">
                        <input type="hidden" name="outcome" value="completed">
                        <input type="hidden" name="back_status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <button type="submit" class="btn btn-sm btn-success">✅</button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as Missed?');">
                        <input type="hidden" name="set_outcome" value="1">
                        <input type="hidden" name="maintenance_id" value="<?= $m['id'] ?>">
                        <input type="hidden" name="outcome" value="missed">
                        <input type="hidden" name="back_status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <button type="submit" class="btn btn-sm btn-danger" style="padding:.2rem .5rem;">❌</button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as Canceled?');">
                        <input type="hidden" name="set_outcome" value="1">
                        <input type="hidden" name="maintenance_id" value="<?= $m['id'] ?>">
                        <input type="hidden" name="outcome" value="canceled">
                        <input type="hidden" name="back_status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <button type="submit" class="btn btn-sm btn-ghost" style="padding:.2rem .5rem;">🚫</button>
                    </form>
                </div>
                <?php endif; ?>

            <?php endif; /* outcome === pending */ ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
