<?php
// ============================================================
// Daily Clock-Out — Mandatory Till Close Before Logout
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user    = Auth::user();
$today   = date('Y-m-d');
$locId   = intval($_SESSION['working_location_id'] ?? $user['location_id'] ?? 0);
$locName = $_SESSION['working_location_name'] ?? 'your location';

// Pull today's open drawer
$drawer = $locId ? DB::queryOne(
    "SELECT * FROM cash_drawers WHERE location_id=? AND drawer_date=? AND status='open'",
    [$locId, $today]
) : null;

// Pull today's cash sales for summary
$cashSales = $locId ? (float)(DB::queryOne(
    "SELECT COALESCE(SUM(total_amount),0) AS total FROM sales
     WHERE location_id=? AND payment_method='cash' AND DATE(created_at)=?",
    [$locId, $today]
)['total'] ?? 0) : 0;

// Pull drawer expenses (payouts)
$payouts = $drawer ? (float)($drawer['cash_payouts'] ?? 0) : 0;
$opening = $drawer ? (float)($drawer['opening_amount'] ?? 0) : 0;
$expected = round($opening + $cashSales - $payouts, 2);

$msg = '';
$msgType = 'danger';

// ── POST: close till and clock out ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual  = floatval($_POST['actual_amount'] ?? -1);
    $notes   = trim($_POST['notes'] ?? '');

    if ($actual < 0) {
        $msg = 'Please enter the actual cash count.';
    } else {
        if ($drawer) {
            // Refresh cash_sales one more time before closing
            DB::execute(
                "UPDATE cash_drawers SET
                    cash_sales    = ?,
                    actual_close  = ?,
                    variance      = ?,
                    notes         = ?,
                    closed_by     = ?,
                    closed_at     = NOW(),
                    status        = 'submitted'
                 WHERE id = ?",
                [$cashSales, $actual, round($actual - $expected, 2), $notes, $user['id'], $drawer['id']]
            );
        }

        // Clear working location session — keep login session
        unset($_SESSION['working_location_id']);
        unset($_SESSION['checkin_date']);
        unset($_SESSION['working_location_name']);

        // Full logout
        Auth::logout();
        redirect(APP_URL . '/modules/auth/login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clock Out — C Tech Fix CRM</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
  <style>
    .checkin-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg-secondary); }
    .checkin-card { background: var(--bg-primary); border-radius: 12px; padding: 2.5rem; width: 100%; max-width: 480px; box-shadow: 0 4px 24px rgba(0,0,0,0.12); }
    .checkin-header { text-align: center; margin-bottom: 1.5rem; }
    .checkin-header .icon { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; }
    .checkin-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem; }
    .checkin-header p { color: var(--text-muted); margin: 0; font-size: 0.9rem; }
    .checkin-staff { background: var(--bg-secondary); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.9rem; color: var(--text-muted); }
    .checkin-staff strong { color: var(--text-primary); }
    .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.5rem; }
    .summary-box { background: var(--bg-secondary); border-radius: 8px; padding: 0.875rem 1rem; }
    .summary-box .label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
    .summary-box .value { font-size: 1.2rem; font-weight: 700; color: var(--text-primary); }
    .summary-box.expected .value { color: var(--primary, #2563eb); }
    .till-section { margin-bottom: 1.25rem; }
    .till-section label { display: block; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; font-size: 0.9rem; }
    .till-section .hint { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem; }
    .till-input-wrap { position: relative; }
    .till-input-wrap .dollar { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-weight: 600; }
    .till-input-wrap input { padding-left: 1.75rem; }
    .variance-preview { display: none; padding: 0.6rem 1rem; border-radius: 8px; font-size: 0.9rem; font-weight: 600; margin-top: 0.5rem; }
    .variance-ok   { background: rgba(22,163,74,0.1); color: #16a34a; }
    .variance-over { background: rgba(37,99,235,0.1); color: #2563eb; }
    .variance-short { background: rgba(220,38,38,0.1); color: #dc2626; }
    .btn-checkout { width: 100%; padding: 0.875rem; font-size: 1rem; font-weight: 700; border-radius: 8px; border: none; background: #dc2626; color: #fff; cursor: pointer; transition: opacity 0.15s; margin-top: 0.5rem; }
    .btn-checkout:hover { opacity: 0.9; }
    .alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
    .alert-danger { background: rgba(220,38,38,0.1); color: #dc2626; border: 1px solid rgba(220,38,38,0.2); }
    .no-drawer-note { background: rgba(217,119,6,0.1); border: 1px solid rgba(217,119,6,0.25); border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.875rem; color: #92400e; margin-bottom: 1.25rem; }
  </style>
</head>
<body class="checkin-page">
<div class="checkin-card">

  <div class="checkin-header">
    <span class="icon">🔒</span>
    <h1>End of Day — Clock Out</h1>
    <p>Count the till before you leave.</p>
  </div>

  <div class="checkin-staff">
    <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>
    &nbsp;·&nbsp; <?= e($locName) ?>
    &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-danger"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php if (!$drawer): ?>
    <div class="no-drawer-note">⚠️ No open till found for today at <?= e($locName) ?>. You can still clock out — no till to close.</div>
  <?php else: ?>
    <div class="summary-grid">
      <div class="summary-box">
        <div class="label">Opening Amount</div>
        <div class="value">$<?= number_format($opening, 2) ?></div>
      </div>
      <div class="summary-box">
        <div class="label">Cash Sales Today</div>
        <div class="value">$<?= number_format($cashSales, 2) ?></div>
      </div>
      <div class="summary-box">
        <div class="label">Cash Payouts</div>
        <div class="value">$<?= number_format($payouts, 2) ?></div>
      </div>
      <div class="summary-box expected">
        <div class="label">Expected in Till</div>
        <div class="value">$<?= number_format($expected, 2) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <form method="POST">

    <?php if ($drawer): ?>
    <div class="till-section">
      <label for="actual_amount">Actual Cash Count</label>
      <div class="hint">Count every bill and coin in the drawer and enter the total.</div>
      <div class="till-input-wrap">
        <span class="dollar">$</span>
        <input
          type="number"
          id="actual_amount"
          name="actual_amount"
          class="form-control"
          step="0.01"
          min="0"
          placeholder="0.00"
          oninput="showVariance(this.value)"
          required
        >
      </div>
      <div class="variance-preview" id="varianceBox"></div>
    </div>
    <?php else: ?>
      <input type="hidden" name="actual_amount" value="0">
    <?php endif; ?>

    <div class="till-section">
      <label for="notes">Notes <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
      <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Any discrepancies or notes for the manager..."></textarea>
    </div>

    <button type="submit" class="btn-checkout">🔒 Clock Out &amp; Close Till</button>
  </form>

</div>
<script>
const expected = <?= json_encode($expected) ?>;
function showVariance(val) {
  const box = document.getElementById('varianceBox');
  if (!box) return;
  if (val === '' || val === null) { box.style.display='none'; return; }
  const actual = parseFloat(val);
  const diff = Math.round((actual - expected) * 100) / 100;
  box.style.display = 'block';
  box.className = 'variance-preview';
  if (Math.abs(diff) < 0.01) {
    box.classList.add('variance-ok');
    box.textContent = '✅ Till balances perfectly.';
  } else if (diff > 0) {
    box.classList.add('variance-over');
    box.textContent = '📈 Over by $' + diff.toFixed(2);
  } else {
    box.classList.add('variance-short');
    box.textContent = '⚠️ Short by $' + Math.abs(diff).toFixed(2);
  }
}
</script>
</body>
</html>
