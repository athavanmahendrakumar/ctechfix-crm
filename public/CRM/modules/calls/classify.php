<?php
require_once dirname(__DIR__, 4) . '/core/Auth.php';
Auth::boot();
Auth::require();
$user = Auth::user();

require_once CORE_PATH . '/VoipMS.php';

$id   = (int)($_GET['id'] ?? 0);
$call = DB::queryOne(
    "SELECT cl.*, l.code AS loc_code, l.name AS loc_name, l.did AS loc_did,
            c.first_name, c.last_name, c.phone_primary, c.id AS cust_id
       FROM call_logs cl
       LEFT JOIN locations l ON l.id = cl.location_id
       LEFT JOIN customers c ON c.id = cl.customer_id
      WHERE cl.id = ? LIMIT 1",
    [$id]
);

if (!$call) { redirect(APP_URL . '/modules/calls/index.php'); }

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Classify / update call ---
    if ($action === 'classify') {
        DB::execute(
            'UPDATE call_logs SET classification=?, reason=?, summary=?, quoted_amount=?,
             outcome=?, next_action=?, classified_by=?, classified_at=NOW() WHERE id=?',
            [
                $_POST['classification'] ?? 'unclassified',
                $_POST['reason']         ?? null,
                $_POST['summary']        ?? null,
                $_POST['quoted_amount']  ? (float)$_POST['quoted_amount'] : null,
                $_POST['outcome']        ?? null,
                $_POST['next_action']    ?? null,
                $user['id'],
                $id,
            ]
        );
        Audit::log('call.classified', 'calls', $id, null, ['classification' => $_POST['classification']]);
        $success = 'Call classified successfully.';
        $call = DB::queryOne("SELECT cl.*, l.code AS loc_code, l.name AS loc_name, l.did AS loc_did, c.first_name, c.last_name, c.phone_primary, c.id AS cust_id FROM call_logs cl LEFT JOIN locations l ON l.id = cl.location_id LEFT JOIN customers c ON c.id = cl.customer_id WHERE cl.id = ? LIMIT 1", [$id]);
    }

    // --- Log a callback attempt ---
    if ($action === 'log_attempt') {
        $outcome = $_POST['attempt_outcome'] ?? 'no_answer';
        $notes   = $_POST['attempt_notes']   ?? '';
        $attempts = (int)$call['callback_attempts'] + 1;

        DB::insert(
            'INSERT INTO callback_attempts (call_log_id, attempted_by, outcome, notes) VALUES (?,?,?,?)',
            [$id, $user['id'], $outcome, $notes]
        );

        $newStatus = 'in_progress';
        if ($outcome === 'reached') {
            $newStatus = 'completed';
        } elseif ($attempts >= 3) {
            $newStatus = 'no_response';
        }

        DB::execute(
            'UPDATE call_logs SET callback_attempts=?, callback_status=?,
             callback_completed_at = IF(?,NOW(),NULL),
             needs_callback = IF(? IN ("completed","no_response"),0,1)
             WHERE id=?',
            [$attempts, $newStatus, $newStatus==='completed'||$newStatus==='no_response', $newStatus, $id]
        );

        Audit::log('call.callback_attempt', 'calls', $id, null, ['outcome' => $outcome, 'attempt' => $attempts]);
        $success = "Callback attempt #{$attempts} logged.";
        $call = DB::queryOne("SELECT cl.*, l.code AS loc_code, l.name AS loc_name, l.did AS loc_did, c.first_name, c.last_name, c.phone_primary, c.id AS cust_id FROM call_logs cl LEFT JOIN locations l ON l.id = cl.location_id LEFT JOIN customers c ON c.id = cl.customer_id WHERE cl.id = ? LIMIT 1", [$id]);
    }

    // --- Create Follow-Up ---
    if ($action === 'create_followup') {
        $fuType  = in_array($_POST['fu_type'] ?? '', ['callback','appointment','general']) ? $_POST['fu_type'] : 'callback';
        $fuTitle = trim($_POST['fu_title'] ?? '');
        $fuNotes = trim($_POST['fu_notes'] ?? '');
        $fuDue   = trim($_POST['fu_due_at'] ?? '');
        if ($fuTitle && $fuDue) {
            DB::execute(
                'INSERT INTO follow_ups (location_id, customer_id, type, title, notes, due_at, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$call['location_id'], $call['customer_id'] ?: null, $fuType, $fuTitle, $fuNotes ?: null, $fuDue, 'pending', $user['id']]
            );
            $success = 'Follow-up created.';
        }
    }

    // --- Send SMS ---
    if ($action === 'send_sms' && !Auth::isTraining()) {
        $smsTo  = normalizePhone($_POST['sms_to'] ?? $call['caller_number']);
        $smsTxt = trim($_POST['sms_message'] ?? '');
        $did    = $call['loc_did'];

        if ($smsTxt) {
            $result = VoipMS::sendSMS($did, $smsTo, $smsTxt);
            if ($result === true) {
                DB::insert(
                    'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_by)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$call['location_id'], $call['customer_id'], 'outbound', $call['loc_did'], $smsTo, $smsTxt, 'sent', $user['id']]
                );
                $success = 'SMS sent successfully.';
            } else {
                $error = 'SMS failed: ' . $result;
                DB::insert(
                    'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, error_message, sent_by)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$call['location_id'], $call['customer_id'], 'outbound', $call['loc_did'], $smsTo, $smsTxt, 'failed', $result, $user['id']]
                );
            }
        }
    }
}

// Get callback attempts history
$attempts = DB::query(
    'SELECT ca.*, u.first_name, u.last_name FROM callback_attempts ca JOIN users u ON u.id = ca.attempted_by WHERE ca.call_log_id = ? ORDER BY ca.attempted_at ASC',
    [$id]
);

$classifications = [
    'new_repair_lead'              => 'New Repair Lead',
    'existing_repair_status'       => 'Existing Repair Status',
    'product_sales_inquiry'        => 'Product Sales Inquiry',
    'wireless_activation_inquiry'  => 'Wireless Activation Inquiry',
    'warranty_complaint'           => 'Warranty / Complaint',
    'supplier_general'             => 'Supplier / General',
    'wrong_number_spam'            => 'Wrong Number / Spam',
    'unclassified'                 => 'Unclassified',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Call #<?= $id ?> — C Tech Fix</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>
<div class="main-content" style="margin-left:0;">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <span class="topbar-title">📞 Call Detail</span>
    </div>
    <div class="topbar-actions">
      <a href="index.php" class="btn btn-ghost btn-sm">← Back</a>
    </div>
  </header>

  <main class="page-content">

    <?php if ($success): ?><div class="alert alert-success auto-dismiss"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="dashboard-grid">

      <!-- Call Info -->
      <div class="card">
        <div class="card-header"><span class="card-title">📞 Call Info</span></div>
        <table class="data-table">
          <tr><td style="color:var(--text-muted);">Number</td><td><strong><?= e(formatPhone($call['caller_number'])) ?></strong></td></tr>
          <tr><td style="color:var(--text-muted);">Customer</td>
            <td>
              <?php if ($call['cust_id']): ?>
                <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= $call['cust_id'] ?>"><?= e($call['first_name'].' '.$call['last_name']) ?></a>
              <?php else: ?>
                <span style="color:var(--text-muted);">Unknown — </span>
                <a href="<?= APP_URL ?>/modules/customers/create.php?phone=<?= urlencode($call['caller_number']) ?>&return=<?= urlencode(APP_URL . '/modules/calls/classify.php?id=' . $id) ?>">Create Customer</a>
              <?php endif; ?>
            </td>
          </tr>
          <tr><td style="color:var(--text-muted);">Location</td><td><span class="loc-<?= strtolower(e($call['loc_code'])) ?>"><?= e($call['loc_name']) ?></span></td></tr>
          <tr><td style="color:var(--text-muted);">Direction</td><td><?= ucfirst(e($call['direction'])) ?></td></tr>
          <tr><td style="color:var(--text-muted);">Status</td><td><?= ucfirst(e($call['status'])) ?></td></tr>
          <tr><td style="color:var(--text-muted);">Time</td><td><?= date('M j, Y g:i A', strtotime($call['call_at'])) ?></td></tr>
          <tr><td style="color:var(--text-muted);">Duration</td>
            <td><?php $d=(int)$call['duration_seconds']; echo $d>0?floor($d/60).'m '.($d%60).'s':'—'; ?></td>
          </tr>
        </table>

        <!-- Callback status -->
        <?php if ($call['needs_callback'] || $call['callback_status']): ?>
        <div style="margin-top:16px;padding:12px;background:var(--bg-card-hover);border-radius:8px;">
          <div style="font-size:13px;font-weight:600;margin-bottom:6px;">Callback Status</div>
          <span style="color:var(--accent-yellow);"><?= ucfirst(str_replace('_',' ',$call['callback_status']??'pending')) ?></span>
          · <?= (int)$call['callback_attempts'] ?>/3 attempts
          <?php if ($call['callback_due_at']): ?>
            · Due <?= date('g:i A', strtotime($call['callback_due_at'])) ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Classify -->
      <div class="card">
        <div class="card-header"><span class="card-title">🏷 Classify Call</span></div>
        <form method="POST">
          <input type="hidden" name="action" value="classify">
          <div class="form-group">
            <label>Classification</label>
            <select name="classification" class="form-input">
              <?php foreach ($classifications as $val => $label): ?>
                <option value="<?= $val ?>" <?= $call['classification']===$val?'selected':'' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Reason / Device / Item</label>
            <input type="text" name="reason" class="form-input" value="<?= e($call['reason']??'') ?>" placeholder="e.g. iPhone 14 screen repair">
          </div>
          <div class="form-group">
            <label>Summary</label>
            <textarea name="summary" class="form-input" rows="3" placeholder="Brief summary of the call..."><?= e($call['summary']??'') ?></textarea>
          </div>
          <div class="form-group">
            <label>Quoted Amount ($)</label>
            <input type="number" name="quoted_amount" class="form-input" step="0.01" value="<?= e($call['quoted_amount']??'') ?>" placeholder="0.00">
          </div>
          <div class="form-group">
            <label>Outcome</label>
            <input type="text" name="outcome" class="form-input" value="<?= e($call['outcome']??'') ?>" placeholder="e.g. Customer will drop off tomorrow">
          </div>
          <div class="form-group">
            <label>Next Action</label>
            <input type="text" name="next_action" class="form-input" value="<?= e($call['next_action']??'') ?>" placeholder="e.g. Follow up if no show by 3pm">
          </div>
          <button type="submit" class="btn btn-primary btn-full">Save Classification</button>
        </form>
      </div>

      <!-- Log Callback Attempt -->
      <?php if ($call['needs_callback'] && $call['callback_status'] !== 'completed'): ?>
      <div class="card">
        <div class="card-header"><span class="card-title">📲 Log Callback Attempt</span></div>
        <form method="POST">
          <input type="hidden" name="action" value="log_attempt">
          <div class="form-group">
            <label>Outcome</label>
            <select name="attempt_outcome" class="form-input">
              <option value="no_answer">No Answer</option>
              <option value="reached">Reached Customer ✅</option>
              <option value="voicemail">Left Voicemail</option>
              <option value="busy">Busy</option>
              <option value="wrong_number">Wrong Number</option>
            </select>
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea name="attempt_notes" class="form-input" rows="2" placeholder="Optional notes..."></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-full">Log Attempt (<?= (int)$call['callback_attempts'] ?>/3)</button>
        </form>

        <?php if (!empty($attempts)): ?>
          <div style="margin-top:16px;">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">Attempt History</div>
            <?php foreach ($attempts as $att): ?>
              <div style="padding:8px 0;border-bottom:1px solid var(--border-light);font-size:13px;">
                <strong><?= e($att['first_name']) ?></strong> —
                <?= ucfirst(str_replace('_',' ',$att['outcome'])) ?> ·
                <span style="color:var(--text-muted);"><?= timeAgo($att['attempted_at']) ?></span>
                <?php if ($att['notes']): ?><br><span style="color:var(--text-muted);font-size:12px;"><?= e($att['notes']) ?></span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Create Follow-Up -->
      <div class="card">
        <div class="card-header"><span class="card-title">📋 Create Follow-Up</span></div>
        <form method="POST">
          <input type="hidden" name="action" value="create_followup">
          <div class="form-group">
            <label>Type</label>
            <select name="fu_type" class="form-input">
              <option value="callback">📞 Call Back</option>
              <option value="appointment">🏪 Customer Visit</option>
              <option value="general">📋 General Task</option>
            </select>
          </div>
          <div class="form-group">
            <label>Task Description *</label>
            <input type="text" name="fu_title" class="form-input" required
                   placeholder="e.g. Call back re: iPhone 14 screen quote"
                   value="<?= $call['first_name'] ? 'Call back ' . htmlspecialchars($call['first_name'] . ' ' . $call['last_name']) : '' ?>">
          </div>
          <div class="form-group">
            <label>Due Date & Time *</label>
            <input type="datetime-local" name="fu_due_at" class="form-input" required
                   value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea name="fu_notes" class="form-input" rows="2"
                      placeholder="Any context for whoever picks this up..."><?= htmlspecialchars($call['summary'] ?? '') ?></textarea>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
            Will be assigned to: <strong><?= htmlspecialchars($call['loc_name']) ?></strong> location
            <?php if ($call['first_name']): ?>
            · Linked to <?= htmlspecialchars($call['first_name'] . ' ' . $call['last_name']) ?>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary btn-full">📋 Create Follow-Up</button>
        </form>
      </div>

      <!-- Send SMS -->
      <div class="card">
        <div class="card-header"><span class="card-title">💬 Send SMS</span></div>
        <?php if (Auth::isTraining()): ?>
          <div class="alert alert-warning">Training mode — SMS will not actually be sent.</div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="send_sms">
          <div class="form-group">
            <label>To Number</label>
            <input type="text" name="sms_to" class="form-input" value="<?= e($call['caller_number']) ?>">
          </div>
          <div class="form-group">
            <label>Message</label>
            <textarea name="sms_message" class="form-input" rows="4" placeholder="Type your message..." maxlength="160"></textarea>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Max 160 characters</div>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
            Sending from: <strong><?= e(formatPhone($call['loc_did'])) ?></strong> (<?= e($call['loc_code']) ?>)
          </div>
          <button type="submit" class="btn btn-primary btn-full">Send SMS</button>
        </form>
      </div>

    </div>
  </main>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
