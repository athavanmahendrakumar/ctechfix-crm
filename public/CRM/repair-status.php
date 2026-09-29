<?php
// ============================================================
// Public Repair Status Page — no login required
// Accessed via: ctrepair.ca/CRM/repair-status.php?t=TOKEN
// ============================================================
$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/helpers.php';

$token = trim($_GET['t'] ?? '');

$repair = null;
if ($token) {
    $repair = DB::queryOne(
        "SELECT r.id, r.record_number, r.status, r.device_brand, r.device_model,
                r.issue_description, r.estimated_ready_at, r.completed_at,
                r.estimated_cost, r.final_cost, r.status_token,
                r.quote_approval, r.quote_approved_at, r.quote_decline_reason,
                c.first_name, l.name AS location_name, l.code AS location_code,
                l.did AS location_did, l.phone AS location_phone
         FROM repairs r
         JOIN customers c ON r.customer_id = c.id
         JOIN locations l ON r.location_id = l.id
         WHERE r.status_token = ? AND r.is_training = 0
         LIMIT 1",
        [$token]
    );
}

// ── Handle customer approval / decline ──────────────────────
$approvalMsg = null;
if ($repair && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'approve_quote' && $repair['quote_approval'] === 'pending') {
        DB::execute(
            "UPDATE repairs SET quote_approval='approved', quote_approved_at=NOW() WHERE id=?",
            [$repair['id']]
        );
        // Auto-advance to in_repair if still at diagnosed/received
        if (in_array($repair['status'], ['received','diagnosed'])) {
            DB::execute(
                "UPDATE repairs SET status='in_repair' WHERE id=?",
                [$repair['id']]
            );
            DB::execute(
                "INSERT INTO repair_status_history (repair_id, old_status, new_status, notes, changed_by)
                 VALUES (?, ?, 'in_repair', 'Customer approved quote via online status page.', NULL)",
                [$repair['id'], $repair['status']]
            );
        }
        $approvalMsg = 'approved';
        // Reload repair
        $repair = DB::queryOne(
            "SELECT r.id, r.record_number, r.status, r.device_brand, r.device_model,
                    r.issue_description, r.estimated_ready_at, r.completed_at,
                    r.estimated_cost, r.final_cost, r.status_token,
                    r.quote_approval, r.quote_approved_at, r.quote_decline_reason,
                    c.first_name, l.name AS location_name, l.code AS location_code,
                    l.did AS location_did, l.phone AS location_phone
             FROM repairs r JOIN customers c ON r.customer_id=c.id JOIN locations l ON r.location_id=l.id
             WHERE r.status_token=? AND r.is_training=0 LIMIT 1",
            [$token]
        );
    }

    if ($act === 'decline_quote' && $repair['quote_approval'] === 'pending') {
        $reason = trim($_POST['decline_reason'] ?? '');
        DB::execute(
            "UPDATE repairs SET quote_approval='declined', quote_decline_reason=? WHERE id=?",
            [$reason ?: null, $repair['id']]
        );
        $approvalMsg = 'declined';
        $repair = DB::queryOne(
            "SELECT r.id, r.record_number, r.status, r.device_brand, r.device_model,
                    r.issue_description, r.estimated_ready_at, r.completed_at,
                    r.estimated_cost, r.final_cost, r.status_token,
                    r.quote_approval, r.quote_approved_at, r.quote_decline_reason,
                    c.first_name, l.name AS location_name, l.code AS location_code,
                    l.did AS location_did, l.phone AS location_phone
             FROM repairs r JOIN customers c ON r.customer_id=c.id JOIN locations l ON r.location_id=l.id
             WHERE r.status_token=? AND r.is_training=0 LIMIT 1",
            [$token]
        );
    }
}

$statusLabels = [
    'received'      => 'Received',
    'diagnosed'     => 'Diagnosed',
    'in_repair'     => 'In Repair',
    'waiting_parts' => 'Waiting for Parts',
    'ready_pickup'  => '✅ Ready for Pickup!',
    'completed'     => '✅ Completed',
    'cancelled'     => 'Cancelled',
];

$statusSteps = ['received', 'diagnosed', 'in_repair', 'waiting_parts', 'ready_pickup', 'completed'];

// Public notes for this repair
$publicNotes = [];
$repairParts = [];
if ($repair) {
    $publicNotes = DB::query(
        'SELECT note, created_at FROM repair_notes WHERE repair_id = ? AND is_internal = 0 ORDER BY created_at DESC',
        [$repair['id']]
    );
    $repairParts = DB::query(
        'SELECT part_name, quantity, sell_price FROM repair_parts WHERE repair_id = ? ORDER BY id ASC',
        [$repair['id']]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Repair Status — C Tech Fix</title>
<style>
  :root {
    --bg: #0f1117;
    --card: #1a1d2e;
    --border: #2a2d3e;
    --text: #e2e8f0;
    --muted: #94a3b8;
    --accent: #6366f1;
    --success: #22c55e;
    --warning: #f59e0b;
    --danger: #ef4444;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; min-height: 100vh; padding: 2rem 1rem; }
  .wrap { max-width: 560px; margin: 0 auto; }
  .logo { text-align: center; margin-bottom: 2rem; }
  .logo h1 { font-size: 1.5rem; font-weight: 800; color: var(--accent); }
  .logo p  { color: var(--muted); font-size: .9rem; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; }
  .status-banner { text-align: center; padding: 1.5rem; border-radius: 12px; margin-bottom: 1rem; }
  .status-banner.ready    { background: rgba(34,197,94,.15);  border: 1px solid var(--success); }
  .status-banner.active   { background: rgba(99,102,241,.12); border: 1px solid var(--accent);  }
  .status-banner.waiting  { background: rgba(245,158,11,.12); border: 1px solid var(--warning); }
  .status-banner.done     { background: rgba(34,197,94,.08);  border: 1px solid var(--border);  }
  .status-label { font-size: 1.4rem; font-weight: 700; }
  .ticket { font-size: .85rem; color: var(--muted); margin-top: .25rem; }
  /* Progress bar */
  .progress-steps { display: flex; align-items: center; gap: 0; margin: 1.5rem 0; }
  .step { flex: 1; text-align: center; position: relative; }
  .step-dot { width: 20px; height: 20px; border-radius: 50%; background: var(--border); border: 2px solid var(--border); margin: 0 auto 6px; }
  .step.done .step-dot    { background: var(--success); border-color: var(--success); }
  .step.active .step-dot  { background: var(--accent);  border-color: var(--accent); box-shadow: 0 0 0 4px rgba(99,102,241,.2); }
  .step-line { position: absolute; top: 9px; left: 50%; width: 100%; height: 2px; background: var(--border); z-index: 0; }
  .step.done .step-line   { background: var(--success); }
  .step:last-child .step-line { display: none; }
  .step-label { font-size: .65rem; color: var(--muted); line-height: 1.2; }
  .step.active .step-label { color: var(--text); font-weight: 600; }
  /* Detail rows */
  .detail { display: flex; justify-content: space-between; padding: .5rem 0; border-bottom: 1px solid var(--border); }
  .detail:last-child { border-bottom: none; }
  .detail-label { color: var(--muted); font-size: .875rem; }
  .detail-value { font-weight: 500; font-size: .875rem; }
  .note { background: rgba(99,102,241,.08); border-left: 3px solid var(--accent); padding: .75rem; border-radius: 0 8px 8px 0; margin-bottom: .75rem; font-size: .875rem; }
  .note-date { color: var(--muted); font-size: .75rem; margin-top: .25rem; }
  /* Quote approval card */
  .quote-card { border-color: var(--warning) !important; }
  .quote-card h3 { font-size: 1.1rem; margin-bottom: .25rem; }
  .quote-amount { font-size: 2rem; font-weight: 800; color: var(--text); margin: 1rem 0 .5rem; text-align: center; }
  .quote-detail { display: flex; justify-content: space-between; padding: .35rem 0; border-bottom: 1px solid var(--border); font-size: .875rem; }
  .quote-detail:last-child { border-bottom: none; }
  .btn-approve { display: block; width: 100%; padding: .9rem; background: var(--success); color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; margin-top: 1rem; }
  .btn-approve:active { opacity: .85; }
  .btn-decline-toggle { background: none; border: none; color: var(--muted); font-size: .8rem; cursor: pointer; margin-top: .75rem; display: block; width: 100%; text-align: center; text-decoration: underline; }
  .decline-section { margin-top: 1rem; display: none; }
  .decline-section.open { display: block; }
  .decline-section textarea { width: 100%; background: #0f1117; border: 1px solid var(--border); border-radius: 8px; padding: .6rem; color: var(--text); font-size: .875rem; resize: vertical; margin-bottom: .5rem; }
  .btn-decline { display: block; width: 100%; padding: .75rem; background: var(--danger); color: #fff; border: none; border-radius: 10px; font-size: .95rem; font-weight: 700; cursor: pointer; }
  /* Approval result banners */
  .approval-banner { text-align: center; padding: 1.5rem; border-radius: 12px; margin-bottom: 1rem; }
  .approval-banner.approved { background: rgba(34,197,94,.15); border: 1px solid var(--success); }
  .approval-banner.declined { background: rgba(239,68,68,.12); border: 1px solid var(--danger); }
  .contact { text-align: center; margin-top: 2rem; color: var(--muted); font-size: .85rem; }
  .contact strong { color: var(--text); }
  .not-found { text-align: center; padding: 3rem 1rem; }
  .not-found h2 { color: var(--muted); margin-bottom: .5rem; }
  footer { text-align: center; margin-top: 2rem; color: var(--muted); font-size: .75rem; }
</style>
</head>
<body>
<div class="wrap">

    <div class="logo">
        <h1>C Tech Fix</h1>
        <p>Repair Status Tracker</p>
    </div>

    <?php if (!$repair): ?>
    <div class="not-found card">
        <h2>Ticket Not Found</h2>
        <p>This link may be invalid or expired. Please contact us directly.</p>
    </div>

    <?php else:
        $s = $repair['status'];
        $qa = $repair['quote_approval'] ?? 'none';
        $bannerClass = match($s) {
            'ready_pickup' => 'ready',
            'completed'    => 'done',
            'waiting_parts','cancelled' => 'waiting',
            default        => 'active',
        };
        $currentStep = array_search($s, $statusSteps);
    ?>

    <?php if ($approvalMsg === 'approved'): ?>
    <div class="approval-banner approved">
        <div style="font-size:2rem;">✅</div>
        <div style="font-size:1.2rem;font-weight:700;margin-top:.5rem;">Repair Approved!</div>
        <div style="color:#86efac;margin-top:.25rem;font-size:.9rem;">Our team has been notified and will begin work on your device.</div>
    </div>
    <?php elseif ($approvalMsg === 'declined'): ?>
    <div class="approval-banner declined">
        <div style="font-size:2rem;">❌</div>
        <div style="font-size:1.2rem;font-weight:700;margin-top:.5rem;">Repair Declined</div>
        <div style="color:#fca5a5;margin-top:.25rem;font-size:.9rem;">We'll be in touch shortly. Your device is safe with us.</div>
    </div>
    <?php endif; ?>

    <!-- Status Banner -->
    <div class="status-banner <?= $bannerClass ?>">
        <div class="status-label"><?= $statusLabels[$s] ?? ucwords(str_replace('_',' ',$s)) ?></div>
        <div class="ticket"><?= htmlspecialchars($repair['record_number']) ?> · <?= htmlspecialchars($repair['location_name']) ?></div>
    </div>

    <!-- Progress Steps -->
    <?php if ($s !== 'cancelled'): ?>
    <div class="progress-steps">
        <?php foreach ($statusSteps as $i => $step):
            $isDone   = $currentStep !== false && $i < $currentStep;
            $isActive = $currentStep !== false && $i === $currentStep;
            $shortLabels = ['Received','Diagnosed','In Repair','Parts','Ready','Done'];
        ?>
        <div class="step <?= $isDone ? 'done' : ($isActive ? 'active' : '') ?>">
            <div class="step-line"></div>
            <div class="step-dot"></div>
            <div class="step-label"><?= $shortLabels[$i] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── QUOTE APPROVAL SECTION ───────────────────────── -->
    <?php if ($qa === 'pending' && !$approvalMsg): ?>
    <div class="card quote-card">
        <div style="text-align:center;">
            <div style="font-size:1rem;font-weight:700;color:var(--warning);margin-bottom:.25rem;">⚠️ Repair Quote — Action Required</div>
            <div style="color:var(--muted);font-size:.875rem;">
                Hi <?= htmlspecialchars($repair['first_name']) ?>, your <?= htmlspecialchars($repair['device_brand'] . ' ' . $repair['device_model']) ?> has been diagnosed.
                Please review the quote below and approve or decline.
            </div>
        </div>

        <div class="quote-detail">
            <span style="color:var(--muted);">Device</span>
            <span><?= htmlspecialchars($repair['device_brand'] . ' ' . $repair['device_model']) ?></span>
        </div>
        <div class="quote-detail">
            <span style="color:var(--muted);">Issue</span>
            <span><?= htmlspecialchars(mb_strimwidth($repair['issue_description'], 0, 60, '...')) ?></span>
        </div>

        <?php if (!empty($repairParts)): ?>
        <!-- Parts line items -->
        <div style="margin-top:1rem;border-top:1px solid var(--border);padding-top:.75rem;">
            <div style="font-size:.75rem;color:var(--muted);font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.5rem;">Parts &amp; Labour</div>
            <?php
            $partsTotal = 0;
            foreach ($repairParts as $part):
                $lineTotal = $part['sell_price'] * $part['quantity'];
                $partsTotal += $lineTotal;
            ?>
            <div class="quote-detail">
                <span>
                    <?= htmlspecialchars($part['part_name']) ?>
                    <?php if ($part['quantity'] > 1): ?>
                    <span style="color:var(--muted);font-size:.8rem;">× <?= $part['quantity'] ?></span>
                    <?php endif; ?>
                </span>
                <span>$<?= number_format($lineTotal, 2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($repair['estimated_cost'] !== null): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;padding-top:.75rem;border-top:2px solid var(--border);">
            <span style="font-size:1rem;font-weight:700;">Total Estimate</span>
            <span style="font-size:1.5rem;font-weight:800;">$<?= number_format((float)$repair['estimated_cost'], 2) ?></span>
        </div>
        <div style="text-align:right;color:var(--muted);font-size:.75rem;margin-top:.2rem;">Subject to parts availability · Tax may apply</div>
        <?php endif; ?>

        <!-- Approve -->
        <form method="POST">
            <input type="hidden" name="action" value="approve_quote">
            <button type="submit" class="btn-approve">✅ Yes, Approve Repair</button>
        </form>

        <!-- Decline toggle -->
        <button class="btn-decline-toggle" onclick="document.getElementById('decline-section').classList.toggle('open')">
            ✕ No, I want to decline
        </button>
        <div id="decline-section" class="decline-section">
            <form method="POST">
                <input type="hidden" name="action" value="decline_quote">
                <textarea name="decline_reason" placeholder="Optional: let us know why you're declining…" rows="3"></textarea>
                <button type="submit" class="btn-decline">Decline Repair</button>
            </form>
        </div>
    </div>

    <?php elseif ($qa === 'approved'): ?>
    <div class="card" style="border-color:var(--success);text-align:center;padding:1rem;">
        <span style="color:var(--success);font-weight:700;">✓ You approved this repair</span>
        <?php if ($repair['quote_approved_at']): ?>
        <span style="color:var(--muted);font-size:.8rem;display:block;margin-top:.2rem;">on <?= date('M j, Y g:ia', strtotime($repair['quote_approved_at'])) ?></span>
        <?php endif; ?>
    </div>

    <?php elseif ($qa === 'declined'): ?>
    <div class="card" style="border-color:var(--danger);text-align:center;padding:1rem;">
        <span style="color:var(--danger);font-weight:700;">✗ You declined this repair</span>
        <?php if ($repair['quote_decline_reason']): ?>
        <span style="color:var(--muted);font-size:.8rem;display:block;margin-top:.25rem;">"<?= htmlspecialchars($repair['quote_decline_reason']) ?>"</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Device Info -->
    <div class="card">
        <div class="detail">
            <span class="detail-label">Hi</span>
            <span class="detail-value"><?= htmlspecialchars($repair['first_name']) ?></span>
        </div>
        <div class="detail">
            <span class="detail-label">Device</span>
            <span class="detail-value"><?= htmlspecialchars($repair['device_brand'] . ' ' . $repair['device_model']) ?></span>
        </div>
        <div class="detail">
            <span class="detail-label">Issue</span>
            <span class="detail-value"><?= htmlspecialchars(mb_strimwidth($repair['issue_description'], 0, 60, '...')) ?></span>
        </div>
        <?php if ($repair['estimated_ready_at'] && !in_array($s, ['completed','cancelled'])): ?>
        <div class="detail">
            <span class="detail-label">Est. Ready</span>
            <span class="detail-value"><?= date('M j, Y g:ia', strtotime($repair['estimated_ready_at'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($repair['final_cost'] !== null): ?>
        <div class="detail">
            <span class="detail-label">Amount</span>
            <span class="detail-value" style="color:var(--success);">$<?= number_format($repair['final_cost'], 2) ?></span>
        </div>
        <?php elseif ($repair['estimated_cost'] !== null && $qa !== 'pending'): ?>
        <div class="detail">
            <span class="detail-label">Est. Cost</span>
            <span class="detail-value">$<?= number_format($repair['estimated_cost'], 2) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Public Notes -->
    <?php if (!empty($publicNotes)): ?>
    <div class="card">
        <h3 style="margin-bottom:.75rem;font-size:.95rem;">Updates from our team</h3>
        <?php foreach ($publicNotes as $n): ?>
        <div class="note">
            <?= nl2br(htmlspecialchars($n['note'])) ?>
            <div class="note-date"><?= date('M j, g:ia', strtotime($n['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Contact -->
    <div class="contact">
        <p>Questions? Call or text us:</p>
        <?php
        $locPhone = $repair['location_phone'] ?? null;
        if (!$locPhone) {
            $locPhone = $repair['location_code'] === 'OS' ? '(905) 233-2596' : '(905) 752-0343';
        }
        ?>
        <p><strong><?= htmlspecialchars($locPhone) ?></strong> — <?= htmlspecialchars($repair['location_name']) ?></p>
    </div>

    <?php endif; ?>

    <footer>C Tech Fix &copy; <?= date('Y') ?></footer>
</div>
</body>
</html>
