<?php
// ============================================================
// Failed SMS — Retry Queue
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';
require_once $rootPath . '/core/Audit.php';

Auth::boot();
Auth::require();

$user    = Auth::user();
$success = '';
$error   = '';

// Retry a failed SMS
if (isset($_GET['retry'])) {
    $smsId = intval($_GET['retry']);
    $sms   = DB::queryOne(
        'SELECT s.*, l.did AS loc_did FROM sms_messages s JOIN locations l ON l.id=s.location_id WHERE s.id=? LIMIT 1',
        [$smsId]
    );
    if ($sms) {
        $voip   = new VoipMS();
        $did    = preg_replace('/\D/', '', $sms['loc_did']);
        $result = $voip->sendSMS($did, $sms['contact_number'], $sms['message']);
        if ($result === true) {
            DB::execute(
                'UPDATE sms_messages SET status=?, retry_count=COALESCE(retry_count,0)+1 WHERE id=?',
                ['sent', $smsId]
            );
            $success = 'SMS retried successfully.';
        } else {
            DB::execute(
                'UPDATE sms_messages SET retry_count=COALESCE(retry_count,0)+1, error_message=? WHERE id=?',
                [$result, $smsId]
            );
            $error = 'Retry failed: ' . $result;
        }
    }
}

// Mark as sent manually
if (isset($_GET['manual'])) {
    $smsId = intval($_GET['manual']);
    DB::execute(
        "UPDATE sms_messages SET status='sent', sent_manually=1 WHERE id=?",
        [$smsId]
    );
    Audit::log('sms.marked_manual', 'sms_messages', $smsId, null, null, null);
    $success = 'Marked as sent manually.';
}

$failed = DB::query(
    "SELECT s.*, l.code AS loc_code,
            COALESCE(c.first_name, c2.first_name) AS first_name,
            COALESCE(c.last_name,  c2.last_name)  AS last_name
     FROM sms_messages s
     LEFT JOIN locations l  ON l.id  = s.location_id
     LEFT JOIN customers c  ON c.id  = s.customer_id
     LEFT JOIN customers c2 ON c2.phone_normalized = s.contact_number AND s.customer_id IS NULL
     WHERE s.status = 'failed' AND (s.sent_manually IS NULL OR s.sent_manually = 0)
     ORDER BY s.sent_at DESC",
    []
);

$pageTitle = 'Failed SMS';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">⚠️ Failed SMS</h1>
        <p class="page-sub"><?= count($failed) ?> message<?= count($failed) !== 1 ? 's' : '' ?> need attention</p>
    </div>
    <a href="sms.php" class="btn btn-secondary">← SMS</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <?php if (empty($failed)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon">✅</div>
            <p>No failed SMS messages.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
    <?php foreach ($failed as $sms): ?>
    <div style="display:flex;align-items:flex-start;gap:1rem;padding:.85rem 1rem;border-bottom:1px solid var(--border);">
        <div style="flex:1;">
            <div style="font-weight:600;margin-bottom:2px;">
                To: <?= htmlspecialchars(formatPhone($sms['contact_number'])) ?>
                <?php if ($sms['first_name']): ?>
                — <?= htmlspecialchars($sms['first_name'] . ' ' . $sms['last_name']) ?>
                <?php endif; ?>
            </div>
            <div class="small" style="margin-bottom:3px;">
                <?= htmlspecialchars(mb_strimwidth($sms['message'], 0, 100, '...')) ?>
            </div>
            <div class="text-muted small">
                Sent <?= timeAgo($sms['sent_at']) ?> ·
                <?= intval($sms['retry_count'] ?? 0) ?> retries
                <?php if (!empty($sms['error_message'])): ?>
                · <span style="color:var(--red);">Error: <?= htmlspecialchars(mb_strimwidth($sms['error_message'], 0, 80, '...')) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <span class="badge badge-loc"><?= htmlspecialchars($sms['loc_code'] ?? '—') ?></span>
        <div style="display:flex;gap:.4rem;flex-shrink:0;">
            <a href="?retry=<?= $sms['id'] ?>" class="btn btn-sm btn-primary">↻ Retry</a>
            <a href="?manual=<?= $sms['id'] ?>"
               class="btn btn-sm btn-ghost"
               onclick="return confirm('Mark as manually sent? Only use this if you already sent the message via another method.')">
                ✓ Manual
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
