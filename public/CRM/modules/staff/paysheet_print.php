<?php
// ============================================================
// Paysheet — Print View
// Standalone page: no sidebar. Opens in new tab, auto-prints.
// Params: from, to, location_id, user_id (all optional)
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/staff.php'); exit;
}

$user     = Auth::user();
$isOwner  = Auth::isOwner();

// ── Global settings ──────────────────────────────────────────
$gRows = DB::query('SELECT setting_key, value FROM settings WHERE location_id IS NULL', []);
$global = [];
foreach ($gRows as $r) $global[$r['setting_key']] = $r['value'];
$businessName = $global['business_name'] ?? 'C Tech Fix';

// ── Pay period ───────────────────────────────────────────────
$today     = new DateTime();
$dayOfWeek = (int)$today->format('N');
$daysBack  = $dayOfWeek - 1;
$thisMon   = (clone $today)->modify("-{$daysBack} days")->format('Y-m-d');
$defaultFrom = $thisMon;
$defaultTo   = date('Y-m-d', strtotime($thisMon . ' +13 days'));

$periodFrom  = $_GET['from']        ?? $defaultFrom;
$periodTo    = $_GET['to']          ?? $defaultTo;
$locFilter   = $isOwner ? intval($_GET['location_id'] ?? 0) : (int)$user['location_id'];
$staffFilter = intval($_GET['user_id'] ?? 0);

// ── Location label ───────────────────────────────────────────
$locationLabel = '';
if ($locFilter) {
    $loc = DB::queryOne('SELECT name FROM locations WHERE id=?', [$locFilter]);
    $locationLabel = $loc['name'] ?? '';
} elseif (!$isOwner) {
    $loc = DB::queryOne('SELECT name FROM locations WHERE id=?', [$user['location_id']]);
    $locationLabel = $loc['name'] ?? '';
}

// ── Staff list ───────────────────────────────────────────────
$staffWhere  = $locFilter ? 'AND sa.location_id=' . $locFilter : '';
$staffList   = DB::query(
    "SELECT DISTINCT u.id, u.first_name, u.username, sa.location_id, l.name AS loc_name, l.code AS loc_code
     FROM staff_attendance sa
     JOIN users u ON u.id = sa.user_id
     JOIN locations l ON l.id = sa.location_id
     WHERE DATE(sa.clock_in) BETWEEN ? AND ? {$staffWhere}
     ORDER BY u.first_name ASC",
    [$periodFrom, $periodTo]
);
if ($staffFilter) {
    $staffList = array_filter($staffList, fn($s) => $s['id'] == $staffFilter);
}

// ── Daily quota ───────────────────────────────────────────────
function getWorkingDaysInMonth(string $yearMonth): int {
    $start = new DateTime($yearMonth . '-01');
    $end   = new DateTime($yearMonth . '-' . $start->format('t'));
    $days  = 0;
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        if ((int)$d->format('N') <= 6) $days++;
    }
    return $days ?: 1;
}

$quotaCache = [];
foreach ($staffList as $s) {
    $lid = $s['location_id'];
    if (!isset($quotaCache[$lid])) {
        $monthKey = date('Y-m', strtotime($periodFrom));
        $target   = DB::queryOne(
            "SELECT target_amount FROM sales_targets
             WHERE location_id=? AND period_type='monthly'
             ORDER BY effective_from DESC LIMIT 1",
            [$lid]
        );
        $monthly          = $target ? (float)$target['target_amount'] : 0;
        $workDays         = getWorkingDaysInMonth($monthKey);
        $quotaCache[$lid] = $monthly > 0 ? round($monthly / $workDays, 2) : 0;
    }
}

// ── Build day-by-day data ────────────────────────────────────
$period = new DatePeriod(
    new DateTime($periodFrom),
    new DateInterval('P1D'),
    (new DateTime($periodTo))->modify('+1 day')
);
$dates = [];
foreach ($period as $d) { $dates[] = $d->format('Y-m-d'); }

$rows = [];
foreach ($staffList as $s) {
    $uid = $s['id'];
    $rows[$uid] = ['info' => $s, 'days' => []];
    foreach ($dates as $date) {
        $rows[$uid]['days'][$date] = ['hours'=>0,'sales'=>0,'acts'=>0,'act_sales'=>0,'commission'=>0];
    }

    $shifts = DB::query(
        "SELECT DATE(clock_in) AS work_date, SUM(hours_worked) AS hrs
         FROM staff_attendance
         WHERE user_id=? AND DATE(clock_in) BETWEEN ? AND ? AND clock_out IS NOT NULL
         GROUP BY DATE(clock_in)",
        [$uid, $periodFrom, $periodTo]
    );
    foreach ($shifts as $sh) {
        if (isset($rows[$uid]['days'][$sh['work_date']]))
            $rows[$uid]['days'][$sh['work_date']]['hours'] = (float)$sh['hrs'];
    }

    $sales = DB::query(
        "SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount),0) AS total
         FROM sales WHERE created_by=? AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY DATE(created_at)",
        [$uid, $periodFrom, $periodTo]
    );
    foreach ($sales as $sl) {
        if (isset($rows[$uid]['days'][$sl['sale_date']]))
            $rows[$uid]['days'][$sl['sale_date']]['sales'] = (float)$sl['total'];
    }

    $acts = DB::query(
        "SELECT activation_date, COUNT(*) AS cnt,
                COALESCE(SUM(commission),0) AS comm,
                COALESCE(SUM(
                    CASE plan_type
                        WHEN '3-month' THEN plan_amount / 3
                        WHEN 'yearly'  THEN plan_amount / 12
                        ELSE plan_amount
                    END
                ),0) AS act_sales_equiv
         FROM activations WHERE user_id=? AND activation_date BETWEEN ? AND ?
         GROUP BY activation_date",
        [$uid, $periodFrom, $periodTo]
    );
    foreach ($acts as $act) {
        if (isset($rows[$uid]['days'][$act['activation_date']])) {
            $rows[$uid]['days'][$act['activation_date']]['acts']       = (int)$act['cnt'];
            $rows[$uid]['days'][$act['activation_date']]['commission'] = (float)$act['comm'];
            $rows[$uid]['days'][$act['activation_date']]['act_sales']  = (float)$act['act_sales_equiv'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Paysheet — <?= date('M j', strtotime($periodFrom)) ?> – <?= date('M j, Y', strtotime($periodTo)) ?></title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
      background: #fff;
      color: #111;
      padding: 20px;
    }

    /* ── Page header ── */
    .doc-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 6px;
      padding-bottom: 8px;
      border-bottom: 2px solid #111;
    }
    .doc-title { font-size: 20px; font-weight: bold; letter-spacing: .5px; }
    .doc-meta  { font-size: 11px; color: #555; margin-top: 3px; }
    .doc-right { text-align: right; font-size: 11px; color: #444; }
    .doc-period { font-size: 14px; font-weight: bold; color: #1d4ed8; }

    /* ── Staff section ── */
    .staff-section {
      margin-bottom: 28px;
      page-break-inside: avoid;
    }
    .staff-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #1d4ed8;
      color: #fff;
      padding: 8px 12px;
      border-radius: 4px 4px 0 0;
    }
    .staff-name  { font-size: 14px; font-weight: bold; }
    .staff-meta  { font-size: 11px; opacity: .85; margin-top: 2px; }
    .staff-quota { font-size: 13px; font-weight: bold; text-align: right; }
    .staff-quota-label { font-size: 10px; opacity: .8; }

    /* ── Table ── */
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11px;
    }
    th {
      background: #f3f4f6;
      border: 1px solid #d1d5db;
      padding: 5px 7px;
      text-align: left;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .4px;
      color: #374151;
    }
    td {
      border: 1px solid #e5e7eb;
      padding: 5px 7px;
      vertical-align: middle;
    }
    tr.off-day td { color: #9ca3af; background: #fafafa; }
    tr.worked td  { background: #fff; }

    /* ── Totals row ── */
    tfoot tr td {
      background: #f0fdf4;
      font-weight: bold;
      border-top: 2px solid #16a34a;
      font-size: 11px;
    }

    /* ── Quota badges ── */
    .met    { color: #15803d; font-weight: bold; }
    .missed { color: #dc2626; }
    .acts   { color: #1d4ed8; font-weight: bold; }
    .comm   { color: #15803d; font-weight: bold; }

    /* ── Summary box (end of all staff) ── */
    .summary-box {
      margin-top: 24px;
      padding: 12px 16px;
      border: 2px solid #111;
      border-radius: 4px;
      page-break-inside: avoid;
    }
    .summary-box h3 { font-size: 13px; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
    .summary-grid   { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
    .summary-item   { text-align: center; }
    .summary-val    { font-size: 18px; font-weight: bold; color: #1d4ed8; }
    .summary-lbl    { font-size: 10px; color: #555; margin-top: 2px; }

    /* ── Print controls (screen only) ── */
    .print-controls {
      position: fixed;
      top: 16px;
      right: 16px;
      display: flex;
      gap: 8px;
      z-index: 999;
    }
    .btn-print {
      padding: 8px 16px;
      background: #1d4ed8;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 13px;
      cursor: pointer;
      font-family: Arial, sans-serif;
    }
    .btn-close {
      padding: 8px 16px;
      background: #6b7280;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 13px;
      cursor: pointer;
      font-family: Arial, sans-serif;
    }

    @media print {
      .print-controls { display: none !important; }
      body { padding: 0; }
      .staff-section { page-break-after: always; }
      .staff-section:last-of-type { page-break-after: avoid; }
    }
  </style>
</head>
<body>

<!-- Print controls (screen only) -->
<div class="print-controls no-print">
  <button class="btn-print" onclick="window.print()">🖨 Print</button>
  <button class="btn-close" onclick="window.close()">✕ Close</button>
</div>

<!-- Document header -->
<div class="doc-header">
  <div>
    <div class="doc-title"><?= htmlspecialchars($businessName) ?> — Staff Paysheet</div>
    <div class="doc-meta">
      <?php if ($locationLabel): ?>Location: <strong><?= htmlspecialchars($locationLabel) ?></strong> &nbsp;·&nbsp; <?php endif; ?>
      Generated: <?= date('M j, Y g:i A') ?>
    </div>
  </div>
  <div class="doc-right">
    <div class="doc-period"><?= date('M j', strtotime($periodFrom)) ?> – <?= date('M j, Y', strtotime($periodTo)) ?></div>
    <div style="margin-top:3px;">Pay Period (14 days)</div>
    <?php if ($staffFilter): ?>
    <div style="margin-top:3px;color:#1d4ed8;">Individual Paysheet</div>
    <?php endif; ?>
  </div>
</div>

<?php
$grandHrs = $grandSales = $grandActSales = $grandComm = $grandActs = 0;

foreach ($rows as $uid => $data):
    $s      = $data['info'];
    $quota  = $quotaCache[$s['location_id']] ?? 0;
    $totHrs = $totSales = $totActSales = $totActs = $totComm = 0;
    $quotaMet = 0; $daysWorked = 0;

    // Check has any data
    $hasData = false;
    foreach ($data['days'] as $d) {
        if ($d['hours'] > 0 || $d['sales'] > 0 || $d['acts'] > 0) { $hasData = true; break; }
    }
    if (!$hasData) continue;
?>
<div class="staff-section">
  <div class="staff-header">
    <div>
      <div class="staff-name"><?= htmlspecialchars($s['first_name']) ?> <span style="font-weight:400;font-size:12px;">(<?= htmlspecialchars($s['username']) ?>)</span></div>
      <div class="staff-meta"><?= htmlspecialchars($s['loc_name']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($s['loc_code']) ?></div>
    </div>
    <div class="staff-quota">
      <?php if ($quota > 0): ?>
      <div>$<?= number_format($quota, 2) ?>/day</div>
      <div class="staff-quota-label">Daily Sales Quota</div>
      <?php else: ?>
      <div style="font-size:11px;opacity:.7;">No quota set</div>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Day</th>
        <th>Hours</th>
        <th>Walk-in Sales</th>
        <th>Act. Sales Value</th>
        <th>Total Sales</th>
        <th>Daily Quota</th>
        <th>Quota Met?</th>
        <th>Activations</th>
        <th>Commission</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($data['days'] as $date => $d):
        $dow  = date('N', strtotime($date));
        if ($dow == 7) continue; // skip Sundays
        $worked     = $d['hours'] > 0 || $d['sales'] > 0 || $d['acts'] > 0;
        $totalSales = $d['sales'] + $d['act_sales'];
        $metDay     = $quota > 0 && $totalSales >= $quota;
        $totHrs    += $d['hours'];
        $totSales  += $d['sales'];
        $totActSales += $d['act_sales'];
        $totActs   += $d['acts'];
        $totComm   += $d['commission'];
        if ($d['hours'] > 0) $daysWorked++;
        if ($metDay) $quotaMet++;
    ?>
    <tr class="<?= $worked ? 'worked' : 'off-day' ?>">
      <td style="white-space:nowrap;<?= $worked?'font-weight:600;':'' ?>"><?= date('M j', strtotime($date)) ?></td>
      <td><?= date('D', strtotime($date)) ?></td>
      <td><?= $d['hours'] > 0 ? number_format($d['hours'],2).'h' : '—' ?></td>
      <td><?= $d['sales'] > 0 ? '$'.number_format($d['sales'],2) : '—' ?></td>
      <td class="<?= $d['act_sales']>0?'acts':'' ?>"><?= $d['act_sales']>0 ? '$'.number_format($d['act_sales'],2) : '—' ?></td>
      <td style="font-weight:700;"><?= $totalSales>0 ? '$'.number_format($totalSales,2) : '—' ?></td>
      <td><?= $quota>0 ? '$'.number_format($quota,2) : '—' ?></td>
      <td>
        <?php if (!$worked): ?>—
        <?php elseif ($quota<=0): ?>N/A
        <?php elseif ($metDay): ?><span class="met">✓ Yes</span>
        <?php else: ?><span class="missed">✗ No (–$<?= number_format($quota-$totalSales,2) ?>)</span>
        <?php endif; ?>
      </td>
      <td class="<?= $d['acts']>0?'acts':'' ?>"><?= $d['acts']>0 ? $d['acts'] : '—' ?></td>
      <td class="<?= $d['commission']>0?'comm':'' ?>"><?= $d['commission']>0 ? '+$'.number_format($d['commission'],2) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <tr>
      <td colspan="2">TOTALS — <?= $daysWorked ?> days worked</td>
      <td><?= number_format($totHrs,2) ?>h</td>
      <td>$<?= number_format($totSales,2) ?></td>
      <td class="acts">$<?= number_format($totActSales,2) ?></td>
      <td>$<?= number_format($totSales+$totActSales,2) ?></td>
      <td><?= $quota>0 ? '$'.number_format($quota*$daysWorked,2) : '—' ?></td>
      <td>
        <?php if ($quota>0 && $daysWorked>0): ?>
        <span class="<?= $quotaMet>=$daysWorked*0.8?'met':'missed' ?>"><?= $quotaMet ?>/<?= $daysWorked ?> days</span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="acts"><?= $totActs>0 ? $totActs : '—' ?></td>
      <td class="comm"><?= $totComm>0 ? '+$'.number_format($totComm,2) : '—' ?></td>
    </tr>
    </tfoot>
  </table>
</div>

<?php
    $grandHrs     += $totHrs;
    $grandSales   += $totSales + $totActSales;
    $grandActSales += $totActSales;
    $grandComm    += $totComm;
    $grandActs    += $totActs;
endforeach;
?>

<?php if (count($rows) > 1): ?>
<!-- Grand summary across all staff -->
<div class="summary-box">
  <h3>Period Summary — All Staff</h3>
  <div class="summary-grid">
    <div class="summary-item">
      <div class="summary-val"><?= count(array_filter($rows, function($r){ foreach($r['days'] as $d){ if($d['hours']>0||$d['sales']>0||$d['acts']>0) return true; } return false; })) ?></div>
      <div class="summary-lbl">Staff Members</div>
    </div>
    <div class="summary-item">
      <div class="summary-val"><?= number_format($grandHrs,1) ?>h</div>
      <div class="summary-lbl">Total Hours</div>
    </div>
    <div class="summary-item">
      <div class="summary-val">$<?= number_format($grandSales,2) ?></div>
      <div class="summary-lbl">Total Sales</div>
    </div>
    <div class="summary-item">
      <div class="summary-val"><?= $grandActs ?></div>
      <div class="summary-lbl">Total Activations</div>
    </div>
    <div class="summary-item">
      <div class="summary-val" style="color:#15803d;">$<?= number_format($grandComm,2) ?></div>
      <div class="summary-lbl">Total Commission</div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
if (!new URLSearchParams(window.location.search).has('noprint')) {
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 600);
    });
}
</script>
</body>
</html>
