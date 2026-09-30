<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ============================================================
// Sales Report — comprehensive filtering for owner / manager
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/'); exit;
}

$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$user      = Auth::user();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── Filters ──────────────────────────────────────────────────
$period    = $_GET['period']     ?? 'month';
$stream    = $_GET['stream']     ?? 'all';   // all | walkin | repairs | activations
$payMethod = $_GET['pay']        ?? 'all';   // all | cash | card | e-transfer | other
$staffId   = intval($_GET['staff_id']   ?? 0);
$locId     = intval($_GET['location_id'] ?? 0);

// Managers can only see their location
if (!$isOwner) $locId = (int)$user['location_id'];

// Date range
$year = intval($_GET['year'] ?? date('Y'));
switch ($period) {
    case 'today':
        $dateFrom = $dateTo = date('Y-m-d'); break;
    case 'yesterday':
        $dateFrom = $dateTo = date('Y-m-d', strtotime('-1 day')); break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo   = date('Y-m-d'); break;
    case 'last_week':
        $dateFrom = date('Y-m-d', strtotime('monday last week'));
        $dateTo   = date('Y-m-d', strtotime('sunday last week')); break;
    case 'month':
        $month    = $_GET['month'] ?? date('Y-m');
        $dateFrom = $month . '-01';
        $dateTo   = date('Y-m-t', strtotime($dateFrom)); break;
    case 'quarter':
        $qtr      = intval($_GET['quarter'] ?? ceil(date('n') / 3));
        $qFrom    = [1=>'01',2=>'04',3=>'07',4=>'10'][$qtr];
        $qTo      = [1=>'03',2=>'06',3=>'09',4=>'12'][$qtr];
        $dateFrom = "$year-$qFrom-01";
        $dateTo   = date('Y-m-t', strtotime("$year-$qTo-01")); break;
    case 'year':
        $dateFrom = "$year-01-01";
        $dateTo   = "$year-12-31"; break;
    case 'custom':
        $dateFrom = $_GET['from'] ?? date('Y-m-01');
        $dateTo   = $_GET['to']   ?? date('Y-m-t'); break;
    default:
        $month    = date('Y-m');
        $dateFrom = $month . '-01';
        $dateTo   = date('Y-m-t');
}

// ── Location WHERE helpers ────────────────────────────────────
$locSalesSql = $locId ? "AND s.location_id=$locId" : '';
$locRepSql   = $locId ? "AND r.location_id=$locId" : '';
$locActSql   = $locId ? "AND a.location_id=$locId" : '';

// ── Payment method filter (sales table) ──────────────────────
$payWhere = '';
if ($payMethod !== 'all') {
    $payWhere = "AND s.payment_method=" . DB::quote($payMethod);
}

// ── Staff filter ─────────────────────────────────────────────
$staffWhereSales = $staffId ? "AND s.created_by=$staffId" : '';
$staffWhereRep   = $staffId ? "AND r.assigned_to=$staffId" : '';
$staffWhereAct   = $staffId ? "AND a.user_id=$staffId"     : '';

// ── 1. Walk-in revenue ───────────────────────────────────────
$walkinRev = 0; $walkinCount = 0;
if ($stream === 'all' || $stream === 'walkin') {
    $w = DB::queryOne(
        "SELECT COALESCE(SUM(s.subtotal),0) AS rev, COUNT(*) AS cnt
         FROM sales s
         WHERE DATE(s.created_at) BETWEEN ? AND ?
           AND s.sale_type = 'sale'
           $locSalesSql $payWhere $staffWhereSales",
        [$dateFrom, $dateTo]
    );
    $walkinRev   = (float)($w['rev'] ?? 0);
    $walkinCount = (int)($w['cnt'] ?? 0);
}

// ── 2. Repair revenue (final payments in period) ─────────────
$repairRev = 0; $repairCount = 0;
if ($stream === 'all' || $stream === 'repairs') {
    $r = DB::queryOne(
        "SELECT COALESCE(SUM(s.subtotal),0) AS rev, COUNT(DISTINCT r.id) AS cnt
         FROM sales s
         JOIN repairs r ON r.id = s.repair_id
         WHERE DATE(s.created_at) BETWEEN ? AND ?
           AND s.sale_type IN ('repair_final','repair_deposit')
           AND r.is_training = 0
           $locSalesSql $payWhere $staffWhereSales",
        [$dateFrom, $dateTo]
    );
    $repairRev   = (float)($r['rev'] ?? 0);
    $repairCount = (int)($r['cnt'] ?? 0);
}

// ── 3. Activation commissions ────────────────────────────────
$actRev = 0; $actCount = 0;
if (($stream === 'all' || $stream === 'activations') && $payMethod === 'all') {
    // activations don't have payment_method
    $a = DB::queryOne(
        "SELECT COALESCE(SUM(a.commission),0) AS rev, COUNT(*) AS cnt
         FROM activations a
         WHERE a.activation_date BETWEEN ? AND ?
           $locActSql $staffWhereAct",
        [$dateFrom, $dateTo]
    );
    $actRev   = (float)($a['rev'] ?? 0);
    $actCount = (int)($a['cnt'] ?? 0);
}

$totalRev   = $walkinRev + $repairRev + $actRev;
$totalTxns  = $walkinCount + $repairCount + $actCount;
$avgSale    = $totalTxns > 0 ? $totalRev / $totalTxns : 0;

// ── 4. Payment method breakdown (walk-in + repairs) ──────────
$payBreakdown = [];
if ($stream !== 'activations') {
    $payRows = DB::query(
        "SELECT s.payment_method, COALESCE(SUM(s.subtotal),0) AS rev, COUNT(*) AS cnt
         FROM sales s
         LEFT JOIN repairs r ON r.id=s.repair_id AND s.sale_type IN ('repair_final','repair_deposit')
         WHERE DATE(s.created_at) BETWEEN ? AND ?
           AND (r.id IS NULL OR r.is_training=0)
           $locSalesSql $staffWhereSales
         GROUP BY s.payment_method
         ORDER BY rev DESC",
        [$dateFrom, $dateTo]
    );
    foreach ($payRows as $pr) $payBreakdown[$pr['payment_method']] = $pr;
}

// ── 5. Daily breakdown ───────────────────────────────────────
$dailyRows = DB::query(
    "SELECT
        d.day,
        COALESCE(wi.rev,0)  AS walkin_rev,
        COALESCE(wi.cnt,0)  AS walkin_cnt,
        COALESCE(rp.rev,0)  AS repair_rev,
        COALESCE(rp.cnt,0)  AS repair_cnt,
        COALESCE(ac.rev,0)  AS act_rev,
        COALESCE(ac.cnt,0)  AS act_cnt
     FROM (
         SELECT DATE(created_at) AS day FROM sales
         WHERE DATE(created_at) BETWEEN ? AND ? $locSalesSql
         UNION
         SELECT activation_date AS day FROM activations
         WHERE activation_date BETWEEN ? AND ? $locActSql
     ) d
     LEFT JOIN (
         SELECT DATE(created_at) AS day, SUM(subtotal) AS rev, COUNT(*) AS cnt
         FROM sales WHERE sale_type='sale' AND DATE(created_at) BETWEEN ? AND ? $locSalesSql $payWhere $staffWhereSales
         GROUP BY DATE(created_at)
     ) wi ON wi.day = d.day
     LEFT JOIN (
         SELECT DATE(s.created_at) AS day, SUM(s.subtotal) AS rev, COUNT(DISTINCT r.id) AS cnt
         FROM sales s JOIN repairs r ON r.id=s.repair_id
         WHERE s.sale_type IN ('repair_final','repair_deposit')
           AND r.is_training=0 AND DATE(s.created_at) BETWEEN ? AND ? $locSalesSql $payWhere $staffWhereSales
         GROUP BY DATE(s.created_at)
     ) rp ON rp.day = d.day
     LEFT JOIN (
         SELECT activation_date AS day, SUM(commission) AS rev, COUNT(*) AS cnt
         FROM activations WHERE activation_date BETWEEN ? AND ? $locActSql $staffWhereAct
         GROUP BY activation_date
     ) ac ON ac.day = d.day
     GROUP BY d.day ORDER BY d.day ASC",
    [$dateFrom,$dateTo, $dateFrom,$dateTo,
     $dateFrom,$dateTo, $dateFrom,$dateTo,
     $dateFrom,$dateTo]
);

// Filter daily by stream
$daily = [];
foreach ($dailyRows as $dr) {
    $total = 0;
    if ($stream === 'all' || $stream === 'walkin')      $total += $dr['walkin_rev'];
    if ($stream === 'all' || $stream === 'repairs')     $total += $dr['repair_rev'];
    if ($stream === 'all' || $stream === 'activations') $total += $dr['act_rev'];
    $dr['total'] = $total;
    $daily[] = $dr;
}

// ── 6. Top selling items ─────────────────────────────────────
$topItems = [];
if ($stream === 'all' || $stream === 'walkin') {
    $topItems = DB::query(
        "SELECT si.item_name, si.item_sku,
                SUM(si.quantity) AS units_sold,
                SUM(si.line_total) AS revenue,
                SUM((si.unit_price - COALESCE(si.unit_cost,0)) * si.quantity) AS profit
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE DATE(s.created_at) BETWEEN ? AND ?
           AND s.sale_type = 'sale'
           $locSalesSql $payWhere $staffWhereSales
         GROUP BY si.item_name, si.item_sku
         ORDER BY revenue DESC
         LIMIT 15",
        [$dateFrom, $dateTo]
    );
}

// ── 7. By staff member ───────────────────────────────────────
$staffRows = DB::query(
    "SELECT u.first_name, u.last_name, u.id AS uid,
            COALESCE(wi.rev,0) AS walkin_rev, COALESCE(wi.cnt,0) AS walkin_cnt,
            COALESCE(rp.rev,0) AS repair_rev, COALESCE(rp.cnt,0) AS repair_cnt,
            COALESCE(ac.rev,0) AS act_rev,    COALESCE(ac.cnt,0) AS act_cnt
     FROM users u
     LEFT JOIN (
         SELECT created_by, SUM(subtotal) AS rev, COUNT(*) AS cnt
         FROM sales WHERE sale_type='sale' AND DATE(created_at) BETWEEN ? AND ? $locSalesSql
         GROUP BY created_by
     ) wi ON wi.created_by = u.id
     LEFT JOIN (
         SELECT s.created_by, SUM(s.subtotal) AS rev, COUNT(DISTINCT r.id) AS cnt
         FROM sales s JOIN repairs r ON r.id=s.repair_id
         WHERE s.sale_type IN ('repair_final','repair_deposit') AND r.is_training=0
           AND DATE(s.created_at) BETWEEN ? AND ? $locSalesSql
         GROUP BY s.created_by
     ) rp ON rp.created_by = u.id
     LEFT JOIN (
         SELECT user_id, SUM(commission) AS rev, COUNT(*) AS cnt
         FROM activations WHERE activation_date BETWEEN ? AND ? $locActSql
         GROUP BY user_id
     ) ac ON ac.user_id = u.id
     WHERE u.is_active=1
       AND (wi.rev IS NOT NULL OR rp.rev IS NOT NULL OR ac.rev IS NOT NULL)
     ORDER BY (COALESCE(wi.rev,0)+COALESCE(rp.rev,0)+COALESCE(ac.rev,0)) DESC",
    [$dateFrom,$dateTo, $dateFrom,$dateTo, $dateFrom,$dateTo]
);

// ── 8. All staff for filter dropdown ─────────────────────────
$allStaff = DB::query(
    "SELECT id, first_name, last_name FROM users WHERE is_active=1 ORDER BY first_name", []
);

// ── Previous period for comparison ───────────────────────────
$days      = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1);
$prevFrom  = date('Y-m-d', strtotime($dateFrom) - ($days * 86400));
$prevTo    = date('Y-m-d', strtotime($dateFrom) - 86400);
$prevW = DB::queryOne("SELECT COALESCE(SUM(subtotal),0) AS rev FROM sales WHERE sale_type='sale' AND DATE(created_at) BETWEEN ? AND ? $locSalesSql", [$prevFrom,$prevTo]);
$prevR = DB::queryOne("SELECT COALESCE(SUM(s.subtotal),0) AS rev FROM sales s JOIN repairs r ON r.id=s.repair_id WHERE s.sale_type IN ('repair_final','repair_deposit') AND r.is_training=0 AND DATE(s.created_at) BETWEEN ? AND ? $locSalesSql", [$prevFrom,$prevTo]);
$prevA = DB::queryOne("SELECT COALESCE(SUM(commission),0) AS rev FROM activations WHERE activation_date BETWEEN ? AND ? $locActSql", [$prevFrom,$prevTo]);
$prevTotal = (float)($prevW['rev']??0) + (float)($prevR['rev']??0) + (float)($prevA['rev']??0);
$vsChange  = $prevTotal > 0 ? round(($totalRev - $prevTotal) / $prevTotal * 100, 1) : null;

function fmt2($n) { return '$' . number_format((float)$n, 2); }
function fmtK($n) { return '$' . number_format((float)$n, 0); }
function pct($part, $total) { return $total > 0 ? round($part/$total*100,1) : 0; }

$pageTitle = 'Sales Report';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📊 Sales Report</h1>
        <p class="page-sub">
            <?= date('M j, Y', strtotime($dateFrom)) ?>
            <?= $dateFrom !== $dateTo ? ' — ' . date('M j, Y', strtotime($dateTo)) : '' ?>
            <?php if ($locId): ?>
            &nbsp;·&nbsp; <?= htmlspecialchars(array_column($locations,'name','id')[$locId] ?? '') ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="index.php" class="btn btn-secondary">← Reports</a>
</div>

<!-- ── Filter Bar ── -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:flex-end;">

    <!-- Period -->
    <div>
        <div class="form-hint" style="margin-bottom:3px;">Period</div>
        <select name="period" class="form-control filter-select" onchange="togglePeriodInputs(this.value)">
            <option value="today"     <?= $period==='today'     ?'selected':'' ?>>Today</option>
            <option value="yesterday" <?= $period==='yesterday' ?'selected':'' ?>>Yesterday</option>
            <option value="week"      <?= $period==='week'      ?'selected':'' ?>>This Week</option>
            <option value="last_week" <?= $period==='last_week' ?'selected':'' ?>>Last Week</option>
            <option value="month"     <?= $period==='month'     ?'selected':'' ?>>Month</option>
            <option value="quarter"   <?= $period==='quarter'   ?'selected':'' ?>>Quarter</option>
            <option value="year"      <?= $period==='year'      ?'selected':'' ?>>Year</option>
            <option value="custom"    <?= $period==='custom'    ?'selected':'' ?>>Custom</option>
        </select>
    </div>

    <!-- Month picker -->
    <div id="inp-month" style="<?= $period==='month'?'':'display:none;' ?>">
        <div class="form-hint" style="margin-bottom:3px;">Month</div>
        <input type="month" name="month" class="form-control"
               value="<?= htmlspecialchars($_GET['month'] ?? date('Y-m')) ?>">
    </div>

    <!-- Quarter picker -->
    <div id="inp-quarter" style="<?= $period==='quarter'?'':'display:none;' ?> display:flex;gap:.3rem;">
        <div>
            <div class="form-hint" style="margin-bottom:3px;">Quarter</div>
            <select name="quarter" class="form-control">
                <?php for($q=1;$q<=4;$q++): ?>
                <option value="<?= $q ?>" <?= (intval($_GET['quarter']??ceil(date('n')/3)))==$q?'selected':'' ?>>Q<?= $q ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <div class="form-hint" style="margin-bottom:3px;">Year</div>
            <select name="year" class="form-control">
                <?php for($y=date('Y');$y>=date('Y')-3;$y--): ?>
                <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </div>

    <!-- Year picker -->
    <div id="inp-year" style="<?= $period==='year'?'':'display:none;' ?>">
        <div class="form-hint" style="margin-bottom:3px;">Year</div>
        <select name="year" class="form-control">
            <?php for($y=date('Y');$y>=date('Y')-3;$y--): ?>
            <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>

    <!-- Custom range -->
    <div id="inp-custom" style="<?= $period==='custom'?'':'display:none;' ?> display:flex;gap:.3rem;">
        <div>
            <div class="form-hint" style="margin-bottom:3px;">From</div>
            <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($_GET['from'] ?? date('Y-m-01')) ?>">
        </div>
        <div>
            <div class="form-hint" style="margin-bottom:3px;">To</div>
            <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($_GET['to'] ?? date('Y-m-t')) ?>">
        </div>
    </div>

    <!-- Stream -->
    <div>
        <div class="form-hint" style="margin-bottom:3px;">Revenue Stream</div>
        <select name="stream" class="form-control filter-select">
            <option value="all"         <?= $stream==='all'         ?'selected':'' ?>>All Streams</option>
            <option value="walkin"      <?= $stream==='walkin'      ?'selected':'' ?>>Walk-in Sales</option>
            <option value="repairs"     <?= $stream==='repairs'     ?'selected':'' ?>>Repair Payments</option>
            <option value="activations" <?= $stream==='activations' ?'selected':'' ?>>Activations</option>
        </select>
    </div>

    <!-- Payment Method -->
    <div>
        <div class="form-hint" style="margin-bottom:3px;">Payment</div>
        <select name="pay" class="form-control filter-select">
            <option value="all"        <?= $payMethod==='all'        ?'selected':'' ?>>All Methods</option>
            <option value="cash"       <?= $payMethod==='cash'       ?'selected':'' ?>>Cash</option>
            <option value="card"       <?= $payMethod==='card'       ?'selected':'' ?>>Card</option>
            <option value="e-transfer" <?= $payMethod==='e-transfer' ?'selected':'' ?>>E-Transfer</option>
            <option value="other"      <?= $payMethod==='other'      ?'selected':'' ?>>Other</option>
        </select>
    </div>

    <!-- Location (owner only) -->
    <?php if ($isOwner): ?>
    <div>
        <div class="form-hint" style="margin-bottom:3px;">Location</div>
        <select name="location_id" class="form-control filter-select">
            <option value="0">All Locations</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['id'] ?>" <?= $locId==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <!-- Staff -->
    <div>
        <div class="form-hint" style="margin-bottom:3px;">Staff</div>
        <select name="staff_id" class="form-control filter-select">
            <option value="0">All Staff</option>
            <?php foreach ($allStaff as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $staffId==$s['id']?'selected':'' ?>>
                <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="display:flex;gap:.3rem;align-items:flex-end;">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="sales.php" class="btn btn-ghost">Reset</a>
    </div>
</form>

<!-- ── Summary Cards ── -->
<div class="metrics-grid" style="margin-bottom:1.5rem;">

    <div class="metric-card accent-blue">
        <div class="metric-label">Total Revenue</div>
        <div class="metric-value"><?= fmtK($totalRev) ?></div>
        <div class="metric-sub">
            <?= $totalTxns ?> transaction<?= $totalTxns!=1?'s':'' ?>
            <?php if ($vsChange !== null): ?>
            &nbsp;·&nbsp;
            <span style="color:<?= $vsChange>=0?'var(--green)':'var(--red)' ?>;">
                <?= $vsChange>=0?'▲':'▼' ?> <?= abs($vsChange) ?>% vs prev period
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($stream==='all' || $stream==='walkin'): ?>
    <div class="metric-card">
        <div class="metric-label">🛒 Walk-in Sales</div>
        <div class="metric-value"><?= fmtK($walkinRev) ?></div>
        <div class="metric-sub"><?= $walkinCount ?> sale<?= $walkinCount!=1?'s':'' ?> · <?= pct($walkinRev,$totalRev) ?>% of total</div>
    </div>
    <?php endif; ?>

    <?php if ($stream==='all' || $stream==='repairs'): ?>
    <div class="metric-card">
        <div class="metric-label">🔧 Repair Payments</div>
        <div class="metric-value"><?= fmtK($repairRev) ?></div>
        <div class="metric-sub"><?= $repairCount ?> repair<?= $repairCount!=1?'s':'' ?> · <?= pct($repairRev,$totalRev) ?>% of total</div>
    </div>
    <?php endif; ?>

    <?php if (($stream==='all' || $stream==='activations') && $payMethod==='all'): ?>
    <div class="metric-card">
        <div class="metric-label">📡 Activation Commissions</div>
        <div class="metric-value"><?= fmtK($actRev) ?></div>
        <div class="metric-sub"><?= $actCount ?> activation<?= $actCount!=1?'s':'' ?> · <?= pct($actRev,$totalRev) ?>% of total</div>
    </div>
    <?php endif; ?>

    <div class="metric-card">
        <div class="metric-label">Avg. Transaction</div>
        <div class="metric-value"><?= fmtK($avgSale) ?></div>
        <div class="metric-sub">per transaction</div>
    </div>

</div>

<!-- ── Payment Method Breakdown ── -->
<?php if (!empty($payBreakdown) && $stream !== 'activations'): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2 class="card-title">💳 By Payment Method</h2></div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead><tr><th>Method</th><th>Transactions</th><th>Revenue</th><th>% of Total</th><th>Bar</th></tr></thead>
        <tbody>
        <?php
        $pmTotal = array_sum(array_column($payBreakdown,'rev'));
        $pmColors = ['cash'=>'var(--green)','card'=>'var(--blue)','e-transfer'=>'var(--amber)','other'=>'var(--text-3)'];
        foreach ($payBreakdown as $pm => $pd):
            $pmPct = $pmTotal > 0 ? round($pd['rev']/$pmTotal*100,1) : 0;
        ?>
        <tr>
            <td style="font-weight:600;text-transform:capitalize;"><?= htmlspecialchars($pm) ?></td>
            <td><?= $pd['cnt'] ?></td>
            <td><?= fmt2($pd['rev']) ?></td>
            <td><?= $pmPct ?>%</td>
            <td style="min-width:120px;">
                <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
                    <div style="height:100%;width:<?= $pmPct ?>%;background:<?= $pmColors[$pm] ?? 'var(--primary)' ?>;border-radius:4px;"></div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Daily Breakdown ── -->
<?php if (!empty($daily)): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2 class="card-title">📅 Daily Breakdown</h2></div>
    <div class="card-body" style="padding:0;">
    <?php $maxDay = max(array_column($daily,'total')) ?: 1; ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <?php if ($stream==='all'||$stream==='walkin'):    ?><th>Walk-in</th><?php endif; ?>
                <?php if ($stream==='all'||$stream==='repairs'):   ?><th>Repairs</th><?php endif; ?>
                <?php if ($stream==='all'||$stream==='activations'&&$payMethod==='all'): ?><th>Activations</th><?php endif; ?>
                <th>Total</th>
                <th style="width:180px;">Bar</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($daily as $d):
            $isWeekend = in_array(date('N', strtotime($d['day'])), [6,7]);
        ?>
        <tr style="<?= $isWeekend ? 'background:var(--surface-2);' : '' ?>">
            <td style="font-weight:<?= $isWeekend?'600':'400' ?>;">
                <?= date('D M j', strtotime($d['day'])) ?>
                <?php if ($isWeekend): ?><span class="text-muted small"> wknd</span><?php endif; ?>
            </td>
            <?php if ($stream==='all'||$stream==='walkin'): ?>
            <td><?= $d['walkin_rev']>0 ? fmt2($d['walkin_rev']) : '<span class="text-muted">—</span>' ?></td>
            <?php endif; ?>
            <?php if ($stream==='all'||$stream==='repairs'): ?>
            <td><?= $d['repair_rev']>0 ? fmt2($d['repair_rev']) : '<span class="text-muted">—</span>' ?></td>
            <?php endif; ?>
            <?php if ($stream==='all'||$stream==='activations'&&$payMethod==='all'): ?>
            <td><?= $d['act_rev']>0 ? fmt2($d['act_rev']) : '<span class="text-muted">—</span>' ?></td>
            <?php endif; ?>
            <td style="font-weight:700;"><?= fmt2($d['total']) ?></td>
            <td>
                <div style="height:10px;background:var(--border);border-radius:4px;overflow:hidden;">
                    <div style="height:100%;width:<?= min(100,round($d['total']/$maxDay*100)) ?>%;background:var(--primary);border-radius:4px;"></div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:700;background:var(--surface-2);">
                <td>TOTAL</td>
                <?php if ($stream==='all'||$stream==='walkin'): ?>
                <td><?= fmt2(array_sum(array_column($daily,'walkin_rev'))) ?></td>
                <?php endif; ?>
                <?php if ($stream==='all'||$stream==='repairs'): ?>
                <td><?= fmt2(array_sum(array_column($daily,'repair_rev'))) ?></td>
                <?php endif; ?>
                <?php if ($stream==='all'||$stream==='activations'&&$payMethod==='all'): ?>
                <td><?= fmt2(array_sum(array_column($daily,'act_rev'))) ?></td>
                <?php endif; ?>
                <td><?= fmt2(array_sum(array_column($daily,'total'))) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Top Selling Items ── -->
<?php if (!empty($topItems)): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2 class="card-title">🏆 Top Selling Items</h2></div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead><tr><th>#</th><th>Item</th><th>SKU</th><th>Units Sold</th><th>Revenue</th><th>Profit</th><th>Margin %</th></tr></thead>
        <tbody>
        <?php foreach ($topItems as $i => $ti):
            $margin = $ti['revenue'] > 0 ? round($ti['profit']/$ti['revenue']*100,1) : 0;
        ?>
        <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td><strong><?= htmlspecialchars($ti['item_name']) ?></strong></td>
            <td class="text-muted small"><?= htmlspecialchars($ti['item_sku'] ?? '—') ?></td>
            <td><?= $ti['units_sold'] ?></td>
            <td><?= fmt2($ti['revenue']) ?></td>
            <td style="color:<?= $ti['profit']>=0?'var(--green)':'var(--red)' ?>;"><?= fmt2($ti['profit']) ?></td>
            <td><?= $margin ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ── By Staff Member ── -->
<?php if (!empty($staffRows)): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2 class="card-title">👤 Revenue by Staff</h2></div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Staff</th>
                <?php if ($stream==='all'||$stream==='walkin'):    ?><th>Walk-in Rev</th><?php endif; ?>
                <?php if ($stream==='all'||$stream==='repairs'):   ?><th>Repair Rev</th><?php endif; ?>
                <?php if ($stream==='all'||$stream==='activations'&&$payMethod==='all'): ?><th>Act. Commission</th><?php endif; ?>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($staffRows as $sr):
            $stTotal = 0;
            if ($stream==='all'||$stream==='walkin')      $stTotal += $sr['walkin_rev'];
            if ($stream==='all'||$stream==='repairs')     $stTotal += $sr['repair_rev'];
            if (($stream==='all'||$stream==='activations')&&$payMethod==='all') $stTotal += $sr['act_rev'];
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($sr['first_name'] . ' ' . $sr['last_name']) ?></strong></td>
            <?php if ($stream==='all'||$stream==='walkin'): ?>
            <td><?= $sr['walkin_rev']>0 ? fmt2($sr['walkin_rev']).'<span class="text-muted small"> ('.$sr['walkin_cnt'].')</span>' : '<span class="text-muted">—</span>' ?></td>
            <?php endif; ?>
            <?php if ($stream==='all'||$stream==='repairs'): ?>
            <td><?= $sr['repair_rev']>0 ? fmt2($sr['repair_rev']).'<span class="text-muted small"> ('.$sr['repair_cnt'].')</span>' : '<span class="text-muted">—</span>' ?></td>
            <?php endif; ?>
            <?php if (($stream==='all'||$stream==='activations')&&$payMethod==='all'): ?>
            <td><?= $sr['act_rev']>0 ? fmt2($sr['act_rev']).'<span class="text-muted small"> ('.$sr['act_cnt'].')</span>' : '<span class="text-muted">—</span>' ?></td>
            <?php endif; ?>
            <td style="font-weight:700;"><?= fmt2($stTotal) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<script>
function togglePeriodInputs(val) {
    ['month','quarter','year','custom'].forEach(function(k) {
        const el = document.getElementById('inp-'+k);
        if (el) el.style.display = (val===k) ? (k==='quarter'?'flex':'') : 'none';
    });
}
// Run on load to restore state
togglePeriodInputs('<?= $period ?>');
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
