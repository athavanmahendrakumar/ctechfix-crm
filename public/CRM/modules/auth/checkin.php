<?php
// ============================================================
// Daily Location Check-In + Till Open
// Must complete before accessing any module
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require(); // must be logged in

$user      = Auth::user();
$today     = date('Y-m-d');

// Already checked in today? Go to dashboard
if (
    isset($_SESSION['checkin_date']) &&
    $_SESSION['checkin_date'] === $today &&
    !empty($_SESSION['working_location_id'])
) {
    redirect(APP_URL . '/modules/dashboard/' . $_SESSION['role'] . '.php');
}

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);
$msg = '';
$msgType = 'danger';

// ── POST: process check-in ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locId   = intval($_POST['location_id'] ?? 0);
    $opening = floatval($_POST['opening_amount'] ?? 0);

    if (!$locId) {
        $msg = 'Please select a location.';
    } elseif ($opening < 0) {
        $msg = 'Opening amount cannot be negative.';
    } else {
        // Validate location exists
        $loc = DB::queryOne('SELECT * FROM locations WHERE id=? AND is_active=1', [$locId]);
        if (!$loc) {
            $msg = 'Invalid location selected.';
        } else {
            // Set session working location
            $_SESSION['working_location_id'] = $locId;
            $_SESSION['checkin_date']        = $today;
            $_SESSION['working_location_name'] = $loc['name'];

            // Open cash drawer if not already open today
            $existing = DB::queryOne(
                "SELECT id FROM cash_drawers WHERE location_id=? AND drawer_date=?",
                [$locId, $today]
            );

            if (!$existing) {
                // Auto-pull any cash sales already made today (edge case)
                $cashSales = DB::queryOne(
                    "SELECT COALESCE(SUM(total_amount),0) AS total FROM sales
                     WHERE location_id=? AND payment_method='cash' AND DATE(created_at)=?",
                    [$locId, $today]
                )['total'] ?? 0;

                DB::insert(
                    "INSERT INTO cash_drawers (location_id, drawer_date, opening_amount, opened_by, opened_at, cash_sales, status)
                     VALUES (?,?,?,?,NOW(),?,'open')",
                    [$locId, $today, $opening, $user['id'], $cashSales]
                );
            }
            // If drawer already open, still complete check-in — don't block them

            redirect(APP_URL . '/modules/dashboard/' . $_SESSION['role'] . '.php');
        }
    }
}

// Pre-select if single-location staff
$primaryLocId = intval($user['location_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Check In — C Tech Fix CRM</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
  <style>
    .checkin-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg-secondary); }
    .checkin-card { background: var(--bg-primary); border-radius: 12px; padding: 2.5rem; width: 100%; max-width: 460px; box-shadow: 0 4px 24px rgba(0,0,0,0.12); }
    .checkin-header { text-align: center; margin-bottom: 2rem; }
    .checkin-header .icon { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; }
    .checkin-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem; }
    .checkin-header p { color: var(--text-muted); margin: 0; font-size: 0.9rem; }
    .checkin-staff { background: var(--bg-secondary); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; font-size: 0.9rem; color: var(--text-muted); }
    .checkin-staff strong { color: var(--text-primary); }
    .location-grid { display: grid; gap: 0.75rem; margin-bottom: 1.25rem; }
    .location-btn { border: 2px solid var(--border-color); border-radius: 8px; padding: 1rem; cursor: pointer; background: var(--bg-primary); text-align: left; transition: all 0.15s; }
    .location-btn:hover { border-color: var(--primary); background: var(--primary-light, rgba(37,99,235,0.06)); }
    .location-btn.selected { border-color: var(--primary); background: var(--primary-light, rgba(37,99,235,0.06)); }
    .location-btn input[type=radio] { display: none; }
    .location-btn .loc-name { font-weight: 600; font-size: 1rem; color: var(--text-primary); }
    .location-btn .loc-sub { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem; }
    .till-section { margin-bottom: 1.5rem; }
    .till-section label { display: block; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; font-size: 0.9rem; }
    .till-section .hint { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem; }
    .till-input-wrap { position: relative; }
    .till-input-wrap .dollar { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-weight: 600; }
    .till-input-wrap input { padding-left: 1.75rem; }
    .btn-checkin { width: 100%; padding: 0.875rem; font-size: 1rem; font-weight: 700; border-radius: 8px; border: none; background: var(--primary, #2563eb); color: #fff; cursor: pointer; transition: opacity 0.15s; }
    .btn-checkin:hover { opacity: 0.9; }
    .alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
    .alert-danger { background: rgba(220,38,38,0.1); color: #dc2626; border: 1px solid rgba(220,38,38,0.2); }
  </style>
</head>
<body class="checkin-page">
<div class="checkin-card">

  <div class="checkin-header">
    <span class="icon">📍</span>
    <h1>Where are you working today?</h1>
    <p>Select your location and open the till to get started.</p>
  </div>

  <div class="checkin-staff">
    Signed in as <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>
    &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-danger"><?= e($msg) ?></div>
  <?php endif; ?>

  <form method="POST" id="checkinForm">

    <div class="location-grid">
      <?php foreach ($locations as $loc): ?>
        <?php $isDefault = ($loc['id'] == $primaryLocId); ?>
        <label class="location-btn <?= $isDefault ? 'selected' : '' ?>" id="lbl-<?= $loc['id'] ?>">
          <input
            type="radio"
            name="location_id"
            value="<?= $loc['id'] ?>"
            <?= $isDefault ? 'checked' : '' ?>
            onchange="selectLoc(<?= $loc['id'] ?>)"
            required
          >
          <div class="loc-name">📍 <?= e($loc['name']) ?></div>
          <div class="loc-sub"><?= e($loc['address'] ?? 'C Tech Fix') ?></div>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="till-section">
      <label for="opening_amount">Opening Till Amount</label>
      <div class="hint">Count the cash in the drawer right now and enter it below.</div>
      <div class="till-input-wrap">
        <span class="dollar">$</span>
        <input
          type="number"
          id="opening_amount"
          name="opening_amount"
          class="form-control"
          step="0.01"
          min="0"
          placeholder="0.00"
          required
        >
      </div>
    </div>

    <button type="submit" class="btn-checkin">✅ Start My Day</button>
  </form>

</div>
<script>
function selectLoc(id) {
  document.querySelectorAll('.location-btn').forEach(el => el.classList.remove('selected'));
  const lbl = document.getElementById('lbl-' + id);
  if (lbl) lbl.classList.add('selected');
}
// Init on load
document.addEventListener('DOMContentLoaded', () => {
  const checked = document.querySelector('input[name=location_id]:checked');
  if (checked) selectLoc(checked.value);
});
</script>
</body>
</html>
