<?php
// ============================================================
// Customer Detail
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user       = Auth::user();
$customerId = intval($_GET['id'] ?? 0);

// ── POST: send SMS from customer view ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_sms') {
    require_once $rootPath . '/core/VoipMS.php';
    $locId   = intval($_POST['location_id'] ?? 0);
    $phone   = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($locId && $phone && $message && !Auth::isTraining()) {
        $loc    = DB::queryOne('SELECT * FROM locations WHERE id=?', [$locId]);
        $did    = VoipMS::didForLocation($loc['code']);
        $result = VoipMS::sendSMS($did, $phone, $message);

        if ($result === true) {
            DB::execute(
                'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_at)
                 VALUES (?,?,?,?,?,?,?,NOW())',
                [$locId, $customerId, 'outbound', $did, $phone, $message, 'sent']
            );
            // Mark any pending inbound from this number as replied
            DB::execute(
                'UPDATE sms_messages SET needs_reply=0 WHERE customer_id=? AND location_id=? AND direction=?',
                [$customerId, $locId, 'inbound']
            );
        }
    }
    header('Location: ' . APP_URL . '/modules/customers/view.php?id=' . $customerId . '&msg=sms_sent'); exit;
}

$customer = DB::queryOne(
    "SELECT c.*, l.name AS location_name, l.code AS location_code
     FROM customers c
     LEFT JOIN locations l ON c.location_id = l.id
     WHERE c.id = ? AND c.is_training = 0",
    [$customerId]
);
if (!$customer) { header('Location: ' . APP_URL . '/modules/customers/'); exit; }

// Staff: only their location
if (Auth::isStaff() && $customer['location_id'] != $user['location_id']) {
    header('Location: ' . APP_URL . '/modules/customers/'); exit;
}

// Repair history
$repairs = DB::query(
    "SELECT r.*, l.code AS location_code, u.first_name AS tech_name
     FROM repairs r
     JOIN locations l  ON r.location_id = l.id
     LEFT JOIN users u ON r.assigned_to = u.id
     WHERE r.customer_id = ? AND r.is_training = 0
     ORDER BY r.created_at DESC",
    [$customerId]
);

// SMS history
$smsHistory = DB::query(
    "SELECT * FROM sms_messages
     WHERE customer_id = ?
     ORDER BY sent_at DESC LIMIT 20",
    [$customerId]
);

$statusLabels = [
    'received'      => 'Received',
    'diagnosed'     => 'Diagnosed',
    'in_repair'     => 'In Repair',
    'waiting_parts' => 'Waiting Parts',
    'ready_pickup'  => 'Ready for Pickup',
    'completed'     => 'Completed',
    'cancelled'     => 'Cancelled',
];
$statusColors = [
    'received'      => 'badge-secondary',
    'diagnosed'     => 'badge-info',
    'in_repair'     => 'badge-warning',
    'waiting_parts' => 'badge-warning',
    'ready_pickup'  => 'badge-primary',
    'completed'     => 'badge-success',
    'cancelled'     => 'badge-danger',
];

$totalSpent = array_sum(array_column(
    array_filter($repairs, fn($r) => $r['status'] === 'completed'),
    'final_cost'
));

$pageTitle = $customer['first_name'] . ' ' . $customer['last_name'];
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if (($_GET['msg'] ?? '') === 'sms_sent'): ?>
<div class="alert alert-success">✓ Message sent.</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?></h1>
        <p class="page-sub">
            <?php if ($customer['location_code']): ?>
            <span class="badge badge-loc"><?= htmlspecialchars($customer['location_code']) ?></span>&nbsp;
            <?php endif; ?>
            Customer since <?= date('M Y', strtotime($customer['created_at'])) ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;">
        <a href="<?= APP_URL ?>/modules/repairs/new.php?phone=<?= urlencode($customer['phone_primary'] ?? '') ?>"
           class="btn btn-primary">+ New Repair</a>
        <a href="index.php" class="btn btn-secondary">← Customers</a>
    </div>
</div>

<div class="repair-grid">

    <!-- LEFT: Repair History -->
    <div class="repair-col-main">

        <div class="card">
            <div class="card-header"><h2 class="card-title">Repair History</h2></div>
            <?php if (empty($repairs)): ?>
            <div class="card-body"><p class="text-muted">No repairs yet.</p></div>
            <?php else: ?>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr><th>Ticket</th><th>Device</th><th>Status</th><th>Date</th><th>Total</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($repairs as $r): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($r['record_number']) ?></strong><br>
                        <span class="badge badge-loc"><?= htmlspecialchars($r['location_code']) ?></span>
                    </td>
                    <td>
                        <?= htmlspecialchars($r['device_brand'] . ' ' . $r['device_model']) ?><br>
                        <span class="text-muted small"><?= htmlspecialchars($r['repair_type'] ?? '') ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $statusColors[$r['status']] ?? 'badge-secondary' ?>">
                            <?= $statusLabels[$r['status']] ?? ucwords($r['status']) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= timeAgo($r['created_at']) ?></td>
                    <td>
                        <?php if ($r['final_cost']): ?>
                        <strong>$<?= number_format($r['final_cost'], 2) ?></strong>
                        <?php elseif ($r['estimated_cost']): ?>
                        <span class="text-muted">~$<?= number_format($r['estimated_cost'], 2) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?= APP_URL ?>/modules/repairs/view.php?id=<?= $r['id'] ?>"
                           class="btn btn-sm btn-secondary">View</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- SMS Conversation -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">💬 SMS Conversation</h2>
            </div>

            <!-- Thread -->
            <div id="sms-thread" style="padding:1rem;max-height:350px;overflow-y:auto;display:flex;flex-direction:column;gap:.5rem;background:var(--surface-2);">
                <?php if (empty($smsHistory)): ?>
                <p class="text-muted" style="text-align:center;padding:1rem;">No messages yet.</p>
                <?php else: ?>
                <?php foreach ($smsHistory as $sms): ?>
                <div style="display:flex;flex-direction:column;align-items:<?= $sms['direction']==='outbound' ? 'flex-end' : 'flex-start' ?>;">
                    <div style="max-width:78%;padding:.6rem .9rem;
                                border-radius:<?= $sms['direction']==='outbound' ? '16px 16px 4px 16px' : '16px 16px 16px 4px' ?>;
                                background:<?= $sms['direction']==='outbound' ? 'var(--blue)' : '#fff' ?>;
                                color:<?= $sms['direction']==='outbound' ? '#fff' : 'var(--text)' ?>;
                                font-size:14px;line-height:1.4;
                                box-shadow:0 1px 2px rgba(0,0,0,.08);">
                        <?= htmlspecialchars($sms['message']) ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:2px;padding:0 4px;">
                        <?= $sms['direction']==='outbound' ? 'You' : 'Customer' ?> · <?= timeAgo($sms['sent_at']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Reply box -->
            <?php if (!Auth::isTraining() && !$customer['sms_opt_out']): ?>
            <div style="padding:1rem;border-top:1px solid var(--border);">
                <form method="POST" style="display:flex;gap:.5rem;" data-once="true">
                    <input type="hidden" name="action"      value="send_sms">
                    <input type="hidden" name="phone"       value="<?= htmlspecialchars($customer['phone_primary'] ?? '') ?>">
                    <input type="hidden" name="location_id" value="<?= $customer['location_id'] ?>">
                    <input type="text" name="message" class="form-control"
                           placeholder="Type a message..." required style="flex:1;">
                    <button type="submit" class="btn btn-primary" data-sending="Sending…">Send →</button>
                </form>
            </div>
            <?php elseif ($customer['sms_opt_out']): ?>
            <div style="padding:.75rem 1rem;font-size:13px;color:var(--text-3);border-top:1px solid var(--border);">
                SMS disabled — customer has opted out.
            </div>
            <?php else: ?>
            <div style="padding:.75rem 1rem;font-size:13px;color:var(--text-3);border-top:1px solid var(--border);">
                SMS blocked in training mode.
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- RIGHT: Customer Info -->
    <div class="repair-col-side">

        <div class="card">
            <div class="card-header"><h2 class="card-title">Contact Info</h2></div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item" style="grid-column:1/-1;">
                        <span class="detail-label">Phone</span>
                        <span class="detail-value">
                            <a href="tel:<?= htmlspecialchars($customer['phone_primary'] ?? '') ?>">
                                <?= htmlspecialchars($customer['phone_primary'] ?? '—') ?>
                            </a>
                        </span>
                    </div>
                    <?php if ($customer['email']): ?>
                    <div class="detail-item" style="grid-column:1/-1;">
                        <span class="detail-label">Email</span>
                        <span class="detail-value">
                            <a href="mailto:<?= htmlspecialchars($customer['email']) ?>">
                                <?= htmlspecialchars($customer['email']) ?>
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <span class="detail-label">Location</span>
                        <span class="detail-value"><?= htmlspecialchars($customer['location_name'] ?? '—') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">SMS Opt-out</span>
                        <span class="detail-value"><?= $customer['sms_opt_out'] ? 'Yes' : 'No' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Summary</h2></div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Total Repairs</span>
                        <span class="detail-value" style="font-weight:700;font-size:1.3rem;">
                            <?= count($repairs) ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Total Spent</span>
                        <span class="detail-value" style="font-weight:700;font-size:1.3rem;color:var(--green);">
                            $<?= number_format($totalSpent, 2) ?>
                        </span>
                    </div>
                    <div class="detail-item" style="grid-column:1/-1;">
                        <span class="detail-label">Customer Since</span>
                        <span class="detail-value"><?= date('M j, Y', strtotime($customer['created_at'])) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
// Auto-scroll SMS thread to bottom
const thread = document.getElementById('sms-thread');
if (thread) thread.scrollTop = thread.scrollHeight;
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
