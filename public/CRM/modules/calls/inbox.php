<?php
// ============================================================
// SMS Inbox — Inbound messages needing a reply
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
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── POST: send reply ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $locationId = intval($_POST['location_id'] ?? 0);
    $phone      = trim($_POST['phone'] ?? '');
    $message    = trim($_POST['message'] ?? '');
    $customerId = intval($_POST['customer_id'] ?? 0) ?: null;

    if ($action === 'send_reply' && $locationId && $phone && $message && !Auth::isTraining()) {
        $loc = DB::queryOne('SELECT * FROM locations WHERE id=?', [$locationId]);
        if ($loc) {
            $voip   = new VoipMS();
            $did    = VoipMS::didForLocation($loc['code']);
            $result = $voip->sendSMS($did, $phone, $message);

            if ($result === true) {
                DB::execute(
                    'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_at)
                     VALUES (?,?,?,?,?,?,?,NOW())',
                    [$locationId, $customerId, 'outbound', $loc['did'], $phone, $message, 'sent']
                );
                // Mark all inbound from this number as replied
                DB::execute(
                    'UPDATE sms_messages SET needs_reply=0 WHERE contact_number=? AND location_id=? AND direction=?',
                    [$phone, $locationId, 'inbound']
                );
            }
        }
        header('Location: ' . APP_URL . '/modules/calls/inbox.php?msg=sent'); exit;
    }

    if ($action === 'mark_read') {
        $phone      = trim($_POST['phone'] ?? '');
        $locationId = intval($_POST['location_id'] ?? 0);
        DB::execute(
            'UPDATE sms_messages SET needs_reply=0 WHERE contact_number=? AND location_id=? AND direction=?',
            [$phone, $locationId, 'inbound']
        );
        header('Location: ' . APP_URL . '/modules/calls/inbox.php?msg=marked'); exit;
    }
}

// ── Load inbox threads ────────────────────────────────────────
$where  = ['m.needs_reply = 1', 'm.direction = "inbound"'];
$params = [];

if (!Auth::isOwner()) {
    $where[]  = 'm.location_id = ?';
    $params[] = $user['location_id'];
}

// Get one row per contact (latest message)
$threads = DB::query(
    "SELECT m.contact_number, m.location_id, m.customer_id,
            l.name AS location_name, l.code AS location_code, l.did,
            c.first_name, c.last_name,
            MAX(m.sent_at) AS last_message_at,
            COUNT(*) AS unread_count,
            (SELECT message FROM sms_messages
             WHERE contact_number=m.contact_number AND location_id=m.location_id
             ORDER BY sent_at DESC LIMIT 1) AS last_message
     FROM sms_messages m
     JOIN locations l ON m.location_id=l.id
     LEFT JOIN customers c ON m.customer_id=c.id
     WHERE " . implode(' AND ', $where) . "
     GROUP BY m.contact_number, m.location_id
     ORDER BY last_message_at DESC",
    $params
);

$msgMap = [
    'sent'   => '✓ Reply sent.',
    'marked' => '✓ Marked as read.',
];

$pageTitle = 'SMS Inbox';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📱 SMS Inbox</h1>
        <p class="page-sub"><?= count($threads) ?> conversation<?= count($threads) !== 1 ? 's' : '' ?> needing a reply</p>
    </div>
</div>

<?php if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): ?>
<div class="alert alert-success"><?= $msgMap[$_GET['msg']] ?></div>
<?php endif; ?>

<?php if (empty($threads)): ?>
<div class="empty-state">
    <div class="empty-icon">✅</div>
    <p>All caught up — no messages need a reply.</p>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:1rem;max-width:800px;">
<?php foreach ($threads as $thread):
    $phone      = $thread['contact_number'];
    $locId      = $thread['location_id'];
    $customerId = $thread['customer_id'];
    $name       = $thread['first_name']
                ? htmlspecialchars($thread['first_name'] . ' ' . $thread['last_name'])
                : formatPhone($phone);

    // Load full thread
    $msgs = DB::query(
        "SELECT * FROM sms_messages
         WHERE contact_number=? AND location_id=?
         ORDER BY sent_at ASC LIMIT 30",
        [$phone, $locId]
    );
?>
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="card-title" style="margin:0;">
                <?= $name ?>
                <?php if ($customerId): ?>
                <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= $customerId ?>"
                   style="font-size:12px;font-weight:400;color:var(--blue);margin-left:.5rem;">View Customer →</a>
                <?php endif; ?>
            </h2>
            <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
                <?= htmlspecialchars(formatPhone($phone)) ?>
                &nbsp;·&nbsp;<span class="badge badge-loc"><?= htmlspecialchars($thread['location_code']) ?></span>
                &nbsp;·&nbsp;<?= $thread['unread_count'] ?> unread
            </div>
        </div>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action"      value="mark_read">
            <input type="hidden" name="phone"       value="<?= htmlspecialchars($phone) ?>">
            <input type="hidden" name="location_id" value="<?= $locId ?>">
            <button type="submit" class="btn btn-sm btn-ghost">Mark Read</button>
        </form>
    </div>

    <!-- Thread -->
    <div class="card-body" style="padding:1rem;max-height:280px;overflow-y:auto;display:flex;flex-direction:column;gap:.5rem;" id="thread-<?= $locId ?>-<?= md5($phone) ?>">
        <?php foreach ($msgs as $msg): ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $msg['direction']==='outbound' ? 'flex-end' : 'flex-start' ?>;">
            <div style="max-width:75%;padding:.6rem .9rem;border-radius:<?= $msg['direction']==='outbound' ? '16px 16px 4px 16px' : '16px 16px 16px 4px' ?>;
                        background:<?= $msg['direction']==='outbound' ? 'var(--blue)' : 'var(--surface-2)' ?>;
                        color:<?= $msg['direction']==='outbound' ? '#fff' : 'var(--text)' ?>;
                        font-size:14px;line-height:1.4;">
                <?= htmlspecialchars($msg['message']) ?>
            </div>
            <div style="font-size:11px;color:var(--text-3);margin-top:2px;padding:0 4px;">
                <?= timeAgo($msg['sent_at']) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Reply form -->
    <?php if (!Auth::isTraining()): ?>
    <div style="padding:1rem;border-top:1px solid var(--border);">
        <form method="POST" style="display:flex;gap:.5rem;" data-once="true">
            <input type="hidden" name="action"      value="send_reply">
            <input type="hidden" name="phone"       value="<?= htmlspecialchars($phone) ?>">
            <input type="hidden" name="location_id" value="<?= $locId ?>">
            <input type="hidden" name="customer_id" value="<?= $customerId ?>">
            <input type="text" name="message" class="form-control"
                   placeholder="Type a reply..." required style="flex:1;">
            <button type="submit" class="btn btn-primary" data-sending="Sending…">Send →</button>
        </form>
    </div>
    <?php else: ?>
    <div style="padding:.75rem 1rem;font-size:13px;color:var(--text-3);border-top:1px solid var(--border);">
        SMS replies blocked in training mode.
    </div>
    <?php endif; ?>
</div>

<script>
// Scroll thread to bottom
(function() {
    const el = document.getElementById('thread-<?= $locId ?>-<?= md5($phone) ?>');
    if (el) el.scrollTop = el.scrollHeight;
})();
</script>

<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
