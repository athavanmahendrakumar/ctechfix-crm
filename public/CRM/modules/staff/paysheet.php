<?php
// ============================================================
// Staff Paysheet — 2-week pay period
// Hours + Sales + Quota + Activations per day per staff
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
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── Pay period (2-week blocks, Mon–Sun) ──────────────────────
// Default: current 2-week block starting from most recent Monday
$today     = new DateTime();
$dayOfWeek = (int)$today->format('N'); // 1=Mon, 7=Sun
$daysBack  = $dayOfWeek - 1; // days since last Monday
$thisMon   = (clone $today)->modify("-{$daysBack} days")->format('Y-m-d');
// 2-week block starts 2 weeks back if we're in the first week, otherwise current
$defaultFrom = date('Y-m-d', strtotime($thisMon));
$defaultTo   = date('Y-m-d', strtotime($thisMon . ' +13 days'));

$periodFrom = $_GET['from'] ?? $defaultFrom;
$periodTo   = $_GET['to']   ?? $defaultTo;
$locFilter  = $isOwner ? intval($_GET['location_id'] ?? 0) : (int)$user['location_id'];
$staffFilter = intval($_GET['user_id'] ?? 0);

// Navigation: prev/next 2-week blocks
$prevFrom = date('Y-m-d', strtotime($periodFrom . ' -14 days'));
$prevTo   = date('Y-m-d', strtotime($periodTo   . ' -14 days'));
$nextFrom = date('Y-m-d', strtotime($periodFrom . ' +14 days'));
$nextTo   = date('Y-m-d', strtotime($periodTo   . ' +14 days'));

// ── CSV Export ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="paysheet_' . $periodFrom . '_' . $periodTo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['C Tech Fix — Staff Paysheet']);
    fputcsv($out, ['Period: ' . date('M j', strtotime($periodFrom)) . ' – ' . date('M j, Y', strtotime($periodTo))]);
    fputcsv($out, []);
    fputcsv($out, ['Name', 'Date', 'Day', 'Hours Worked', 'Daily Sales $', 'Daily Quota $', 'Quota Met?', 'Activations', 'Act. Commission $']);
    // (data filled below after queries)
    // We'll fall through and reuse the same data arrays
    define('DOING_EXPORT', true);
}

// ── Staff list for this location/period ─────────────────────
$staffWhere  = $locFilter ? 'AND sa.location_id=' . $locFilter : '';
$staffParams = [$periodFrom, $periodTo];
$staffList   = DB::query(
    "SELECT DISTINCT u.id, u.first_name, u.username, sa.location_id, l.code AS loc_code
     FROM staff_attendance sa
     JOIN users u ON u.id = sa.user_id
     JOIN locations l ON l.id = sa.location_id
     WHERE DATE(sa.clock_in) BETWEEN ? AND ? {$staffWhere}
     ORDER BY u.first_name ASC",
    $staffParams
);

if ($staffFilter) {
    $staffList = array_filter($staffList, fn($s) => $s['id'] == $staffFilter);
}

// ── Daily quota from location monthly target ─────────────────
// Count Mon–Sat working days in the relevant month(s) covered by this period
function getWorkingDaysInMonth(string $yearMonth): int {
    $start = new DateTime($yearMonth . '-01');
    $end   = new DateTime($yearMonth . '-' . $start->format('t'));
    $days  = 0;
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $dow = (int)$d->format('N'); // 1=Mon … 7=Sun
        if ($dow <= 6) $days++; // Mon–Sat
    }
    return $days ?: 1;
}

$quotaCache = []; // keyed by location_id
foreach ($staffList as $s) {
    $lid = $s['location_id'];
    if (!isset($quotaCache[$lid])) {
        $monthKey = date('Y-m', strtotime($periodFrom));
        $target = DB::queryOne(
            "SELECT target_amount FROM sales_targets
             WHERE location_id=? AND period_type='monthly'
             ORDER BY effective_from DESC LIMIT 1",
            [$lid]
        );
        $monthly    = $target ? (float)$target['target_amount'] : 0;
        $workDays   = getWorkingDaysInMonth($monthKey);
        $quotaCache[$lid] = $monthly > 0 ? round($monthly / $workDays, 2) : 0;
    }
}

// ── Build day-by-day data per staff ──────────────────────────
$period = new DatePeriod(
    new DateTime($periodFrom),
    new DateInterval('P1D'),
    (new DateTime($periodTo))->modify('+1 day')
);
$dates = [];
foreach ($period as $d) { $dates[] = $d->format('Y-m-d'); }

$rows = []; // [user_id => [date => [hours, sales, activations, commission]]]

foreach ($staffList as $s) {
    $uid = $s['id'];
    $lid = $s['location_id'];
    $rows[$uid] = ['info' => $s, 'days' => []];
    foreach ($dates as $date) { $rows[$uid]['days'][$date] = ['hours'=>0,'sales'=>0,'acts'=>0,'act_sales'=>0,'commission'=>0]; }

    // Hours per day
    $shifts = DB::query(
        "SELECT DATE(clock_in) AS work_date, SUM(hours_worked) AS hrs
         FROM staff_attendance
         WHERE user_id=? AND DATE(clock_in) BETWEEN ? AND ? AND clock_out IS NOT NULL
         GROUP BY DATE(clock_in)",
        [$uid, $periodFrom, $periodTo]
    );
    foreach ($shifts as $sh) {
        if (isset($rows[$uid]['days'][$sh['work_date']])) {
            $rows[$uid]['days'][$sh['work_date']]['hours'] = (float)$sh['hrs'];
        }
    }

    // Sales per day (created_by = user_id in sales table)
    $sales = DB::query(
        "SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount),0) AS total
         FROM sales
         WHERE created_by=? AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY DATE(created_at)",
        [$uid, $periodFrom, $periodTo]
    );
    foreach ($sales as $sl) {
        if (isset($rows[$uid]['days'][$sl['sale_date']])) {
            $rows[$uid]['days'][$sl['sale_date']]['sales'] = (float)$sl['total'];
        }
    }

    // Activations per day — monthly equivalent sales value
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
         FROM activations
         WHERE user_id=? AND activation_date BETWEEN ? AND ?
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

// ── CSV output ───────────────────────────────────────────────
if (defined('DOING_EXPORT')) {
    foreach ($rows as $uid => $data) {
        $s     = $data['info'];
        $quota = $quotaCache[$s['location_id']] ?? 0;
        fputcsv($out, []);
        fputcsv($out, [strtoupper($s['first_name'] . ' (' . $s['username'] . ')'), 'Location: ' . $s['loc_code']]);
        $totHrs = $totSales = $totActs = $totComm = 0;
        $quotaMet = 0; $daysWorked = 0;
        foreach ($data['days'] as $date => $d) {
            if ($d['hours'] == 0 && $d['sales'] == 0 && $d['acts'] == 0) continue;
            $met = ($quota > 0 && $d['sales'] >= $quota) ? 'Yes' : ($quota > 0 ? 'No' : 'N/A');
            $dayName = date('l', strtotime($date));
            fputcsv($out, [
                $s['first_name'],
                date('M j, Y', strtotime($date)),
                $dayName,
                number_format($d['hours'], 2),
                number_format($d['sales'], 2),
                $quota > 0 ? number_format($quota, 2) : 'No target set',
                $met,
                $d['acts'],
                number_format($d['commission'], 2),
            ]);
            $totHrs   += $d['hours'];
            $totSales += $d['sales'];
            $totActs  += $d['acts'];
            $totComm  += $d['commission'];
            if ($d['hours'] > 0) $daysWorked++;
            if ($quota > 0 && $d['sales'] >= $quota) $quotaMet++;
        }
        fputcsv($out, [
            'TOTAL', '', '', number_format($totHrs,2),
            number_format($totSales,2), '',
            $quota > 0 ? "{$quotaMet}/{$daysWorked} days" : 'N/A',
            $totActs, number_format($totComm,2)
        ]);
    }
    fclose($out);
    exit;
}

// ── Screen view ───────────────────────────────────────────────
$pageTitle = 'Paysheet';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">💰 Staff Paysheet</h1>
        <p class="page-sub">
            <?= date('M j', strtotime($periodFrom)) ?> – <?= date('M j, Y', strtotime($periodTo)) ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="?from=<?= $prevFrom ?>&to=<?= $prevTo ?>&location_id=<?= $locFilter ?>&user_id=<?= $staffFilter ?>"
           class="btn btn-ghost">← Prev 2 Weeks</a>
        <a href="?from=<?= $nextFrom ?>&to=<?= $nextTo ?>&location_id=<?= $locFilter ?>&user_id=<?= $staffFilter ?>"
           class="btn btn-ghost">Next 2 Weeks →</a>
        <a href="paysheet_print.php?from=<?= $periodFrom ?>&to=<?= $periodTo ?>&location_id=<?= $locFilter ?>&user_id=<?= $staffFilter ?>"
           target="_blank" class="btn btn-secondary">🖨 Print</a>
        <a href="?from=<?= $periodFrom ?>&to=<?= $periodTo ?>&location_id=<?= $locFilter ?>&user_id=<?= $staffFilter ?>&export=csv"
           class="btn btn-primary">⬇ Export CSV</a>
    </div>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
    <?php if ($isOwner): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $locFilter==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php else: ?>
    <input type="hidden" name="location_id" value="<?= $locFilter ?>">
    <?php endif; ?>

    <select name="user_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Staff</option>
        <?php
        $allStaff = DB::query(
            "SELECT u.id, u.first_name, u.username FROM users u
             JOIN staff_attendance sa ON sa.user_id=u.id
             WHERE DATE(sa.clock_in) BETWEEN ? AND ?" . ($locFilter ? " AND sa.location_id={$locFilter}" : "") . "
             GROUP BY u.id ORDER BY u.first_name",
            [$periodFrom, $periodTo]
        );
        foreach ($allStaff as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $staffFilter==$s['id']?'selected':'' ?>>
            <?= htmlspecialchars($s['first_name']) ?> (<?= htmlspecialchars($s['username']) ?>)
        </option>
        <?php endforeach; ?>
    </select>

    <input type="date" name="from" class="form-control" value="<?= $periodFrom ?>" style="width:auto;">
    <span class="text-muted">to</span>
    <input type="date" name="to"   class="form-control" value="<?= $periodTo ?>"   style="width:auto;">
    <button type="submit" class="btn btn-secondary">Go</button>
    <input type="hidden" name="user_id" value="<?= $staffFilter ?>">
</form>

<?php if (empty($rows)): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon">📅</div>
            <p>No attendance records found for this period. Make sure staff have clocked in.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php foreach ($rows as $uid => $data):
    $s     = $data['info'];
    $totActSales = 0;
    $quota = $quotaCache[$s['location_id']] ?? 0;
    $totHrs = $totSales = $totActs = $totComm = 0;
    $quotaMet = 0; $daysWorked = 0;
    $hasData = false;
    foreach ($data['days'] as $d) {
        if ($d['hours'] > 0 || $d['sales'] > 0 || $d['acts'] > 0) { $hasData = true; break; }
    }
    if (!$hasData) continue;
?>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header" style="background:var(--surface-2);">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <div style="width:36px;height:36px;border-radius:50%;background:var(--blue);color:#fff;
                        display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;">
                <?= strtoupper(substr($s['first_name'],0,1)) ?>
            </div>
            <div>
                <div style="font-weight:700;font-size:1rem;"><?= htmlspecialchars($s['first_name']) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($s['username']) ?>
                    · <span class="badge badge-loc"><?= htmlspecialchars($s['loc_code']) ?></span>
                    <?php if ($quota > 0): ?>
                    · Daily quota: <strong>$<?= number_format($quota, 2) ?></strong>
                    <?php else: ?>
                    · <span style="color:var(--amber);">No sales target set</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:.4rem;">
            <a href="paysheet_print.php?from=<?= $periodFrom ?>&to=<?= $periodTo ?>&location_id=<?= $locFilter ?>&user_id=<?= $uid ?>"
               target="_blank" class="btn btn-sm btn-secondary">🖨 Print</a>
            <a href="?from=<?= $periodFrom ?>&to=<?= $periodTo ?>&location_id=<?= $locFilter ?>&user_id=<?= $uid ?>&export=csv"
               class="btn btn-sm btn-ghost">⬇ CSV</a>
        </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="data-table" style="min-width:680px;">
        <thead>
            <tr>
                <th>Date</th>
                <th>Day</th>
                <th>Hours</th>
                <th>Walk-in Sales</th>
                <th>Act. Sales Value</th>
                <th>Total Sales</th>
                <th>Quota $</th>
                <th>Quota Met?</th>
                <th>Activations</th>
                <th>Act. Commission</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($data['days'] as $date => $d):
            $dow = date('N', strtotime($date)); // 7=Sunday
            if ($dow == 7) continue; // skip Sundays
            $worked     = $d['hours'] > 0;
            $totalSales = $d['sales'] + $d['act_sales']; // walk-in + activation monthly equiv
            $quotaMet_day = $quota > 0 && $totalSales >= $quota;
            $totHrs   += $d['hours'];
            $totSales += $d['sales'];
            $totActs  += $d['acts'];
            $totComm  += $d['commission'];
            $totActSales += $d['act_sales'];
            if ($worked) $daysWorked++;
            if ($quotaMet_day) $quotaMet++;
        ?>
        <tr style="<?= !$worked ? 'color:var(--text-3);background:var(--surface-2);' : '' ?>">
            <td style="white-space:nowrap;<?= !$worked?'':'font-weight:600;' ?>">
                <?= date('M j', strtotime($date)) ?>
            </td>
            <td class="small"><?= date('D', strtotime($date)) ?></td>
            <td style="<?= $worked?'font-weight:600;':'color:var(--text-3);' ?>">
                <?= $d['hours'] > 0 ? number_format($d['hours'],2).'h' : '—' ?>
            </td>
            <td style="<?= $d['sales']>0?'font-weight:600;':'color:var(--text-3);' ?>">
                <?= $d['sales'] > 0 ? '$'.number_format($d['sales'],2) : '—' ?>
            </td>
            <td style="<?= $d['act_sales']>0?'color:var(--blue);font-weight:600;':'color:var(--text-3);' ?>">
                <?= $d['act_sales'] > 0 ? '$'.number_format($d['act_sales'],2) : '—' ?>
            </td>
            <td style="font-weight:700;">
                <?= $totalSales > 0 ? '$'.number_format($totalSales,2) : '—' ?>
            </td>
            <td class="text-muted small">
                <?= $quota > 0 ? '$'.number_format($quota,2) : '—' ?>
            </td>
            <td>
                <?php if (!$worked): ?>
                <span class="text-muted small">—</span>
                <?php elseif ($quota <= 0): ?>
                <span class="text-muted small">No target</span>
                <?php elseif ($quotaMet_day): ?>
                <span style="color:var(--green);font-weight:700;">✅ Yes</span>
                <?php else: ?>
                <span style="color:var(--red);">❌ No
                    <span class="small">(–$<?= number_format($quota - $totalSales,2) ?>)</span>
                </span>
                <?php endif; ?>
            </td>
            <td style="<?= $d['acts']>0?'font-weight:700;color:var(--blue);':'color:var(--text-3);' ?>">
                <?= $d['acts'] > 0 ? $d['acts'] : '—' ?>
            </td>
            <td>
                <?php if ($d['commission'] > 0): ?>
                <span class="badge badge-success">+$<?= number_format($d['commission'],2) ?></span>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr style="background:var(--surface-2);font-weight:700;border-top:2px solid var(--border);">
            <td colspan="2">TOTAL (<?= $daysWorked ?> days worked)</td>
            <td><?= number_format($totHrs,2) ?>h</td>
            <td>$<?= number_format($totSales,2) ?></td>
            <td style="color:var(--blue);">$<?= number_format($totActSales,2) ?></td>
            <td>$<?= number_format($totSales+$totActSales,2) ?></td>
            <td class="text-muted" style="font-weight:400;">
                <?= $quota>0 ? '$'.number_format($quota*$daysWorked,2).' target' : '' ?>
            </td>
            <td>
                <?php if ($quota > 0 && $daysWorked > 0): ?>
                <span style="color:<?= $quotaMet>=$daysWorked*0.8?'var(--green)':'var(--amber)' ?>;">
                    <?= $quotaMet ?>/<?= $daysWorked ?> days
                </span>
                <?php endif; ?>
            </td>
            <td style="color:var(--blue);"><?= $totActs > 0 ? $totActs : '—' ?></td>
            <td>
                <?php if ($totComm > 0): ?>
                <span style="color:var(--green);font-size:1.1rem;">+$<?= number_format($totComm,2) ?></span>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>

<?php endforeach; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
