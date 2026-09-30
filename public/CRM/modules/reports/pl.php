<?php
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log = dirname(__DIR__, 4) . '/debug_report_error.txt';
        file_put_contents($log, date('Y-m-d H:i:s') . "\nFILE: " . $e['file'] . "\nLINE: " . $e['line'] . "\nMSG: " . $e['message'] . "\n\n", FILE_APPEND);
        echo '<pre style="background:#fee;padding:20px;margin:20px;border:2px solid red;font-size:14px;"><strong>PHP Fatal Error</strong><br>File: ' . $e['file'] . '<br>Line: ' . $e['line'] . '<br>Error: ' . htmlspecialchars($e['message']) . '</pre>';
    }
});
// ============================================================
// Profit & Loss Report
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
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// ── Void a repair (owner only) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_repair']) && $isOwner) {
    $repairId = intval($_POST['repair_id'] ?? 0);
    if ($repairId) {
        try {
            DB::execute(
                "UPDATE repairs SET status='cancelled', final_cost=NULL, completed_at=NULL WHERE id=?",
                [$repairId]
            );
        } catch (\Exception $e) { /* silently fail */ }
    }
    // Redirect back preserving filters
    $qs = http_build_query(array_filter([
        'period'      => $_POST['period']      ?? 'month',
        'month'       => $_POST['month']       ?? null,
        'location_id' => $_POST['location_id'] ?? null,
    ]));
    header('Location: ' . APP_URL . '/modules/reports/pl.php?' . $qs . '#daily'); exit;
}

// ── Date range ───────────────────────────────────────────────
$period   = $_GET['period']   ?? 'month';
$dateFrom = $_GET['from']     ?? date('Y-m-01');
$dateTo   = $_GET['to']       ?? date('Y-m-t');

if ($period === 'month') {
    $month    = $_GET['month'] ?? date('Y-m');
    $dateFrom = $month . '-01';
    $dateTo   = date('Y-m-t', strtotime($dateFrom));
} elseif ($period === 'quarter') {
    $qtr      = intval($_GET['quarter'] ?? ceil(date('n') / 3));
    $year     = intval($_GET['year']    ?? date('Y'));
    $qFrom    = [1=>'01',2=>'04',3=>'07',4=>'10'][$qtr];
    $qTo      = [1=>'03',2=>'06',3=>'09',4=>'12'][$qtr];
    $dateFrom = "$year-$qFrom-01";
    $dateTo   = date('Y-m-t', strtotime("$year-$qTo-01"));
} elseif ($period === 'year') {
    $year     = intval($_GET['year'] ?? date('Y'));
    $dateFrom = "$year-01-01";
    $dateTo   = "$year-12-31";
} elseif ($period === 'custom') {
    $dateFrom = $_GET['from'] ?? date('Y-m-01');
    $dateTo   = $_GET['to']   ?? date('Y-m-t');
}

$filterLocId = intval($_GET['location_id'] ?? 0);

// ── Helper: build location filter SQL ───────────────────────
function locFilter(string $col, int $locId): array {
    if ($locId) return ["$col = ?", [$locId]];
    return ['1=1', []];
}

// ── 1. Repair Revenue (final_cost where completed in period) ─
function getRepairRevenue(string $from, string $to, int $locId): array {
    [$locSql, $locP] = locFilter('r.location_id', $locId);
    $rows = DB::query(
        "SELECT r.location_id, l.name AS loc_name, l.code AS loc_code,
                COALESCE(SUM(r.final_cost),0) AS revenue
         FROM repairs r
         JOIN locations l ON r.location_id=l.id
         WHERE r.status='completed'
           AND r.is_training=0
           AND r.completed_at BETWEEN ? AND ?
           AND $locSql
         GROUP BY r.location_id",
        array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $locP)
    );
    return $rows;
}

// ── 2. Walk-in Sales Revenue (subtotal only, excludes tax & repair payments) ──
function getSalesRevenue(string $from, string $to, int $locId): array {
    [$locSql, $locP] = locFilter('s.location_id', $locId);
    $rows = DB::query(
        "SELECT s.location_id, l.name AS loc_name, l.code AS loc_code,
                COALESCE(SUM(s.subtotal),0)   AS revenue,
                COALESCE(SUM(s.tax_amount),0) AS tax_collected
         FROM sales s
         JOIN locations l ON s.location_id=l.id
         WHERE s.created_at BETWEEN ? AND ?
           AND (s.sale_type IS NULL OR s.sale_type NOT IN ('repair_final','repair_deposit'))
           AND $locSql
         GROUP BY s.location_id",
        array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $locP)
    );
    return $rows;
}

// ── 3. Cost of Goods (parts cost from completed repairs) ─────
function getCOGS(string $from, string $to, int $locId): array {
    [$locSql, $locP] = locFilter('r.location_id', $locId);
    $rows = DB::query(
        "SELECT r.location_id, l.name AS loc_name, l.code AS loc_code,
                COALESCE(SUM(rp.cost_price * rp.quantity),0) AS cogs
         FROM repair_parts rp
         JOIN repairs r   ON rp.repair_id=r.id
         JOIN locations l ON r.location_id=l.id
         WHERE r.status='completed'
           AND r.is_training=0
           AND r.completed_at BETWEEN ? AND ?
           AND $locSql
         GROUP BY r.location_id",
        array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $locP)
    );
    return $rows;
}

// Walk-in sales COGS (exclude repair payments)
function getSalesCOGS(string $from, string $to, int $locId): array {
    [$locSql, $locP] = locFilter('s.location_id', $locId);
    $rows = DB::query(
        "SELECT s.location_id,
                COALESCE(SUM(si.unit_cost * si.quantity),0) AS cogs
         FROM sale_items si
         JOIN sales s ON si.sale_id=s.id
         WHERE s.created_at BETWEEN ? AND ?
           AND (s.sale_type IS NULL OR s.sale_type NOT IN ('repair_final','repair_deposit'))
           AND $locSql
         GROUP BY s.location_id",
        array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $locP)
    );
    return $rows;
}

// ── 4. Expenses ──────────────────────────────────────────────
function getExpenses(string $from, string $to, int $locId): array {
    [$locSql, $locP] = locFilter('e.location_id', $locId);
    $rows = DB::query(
        "SELECT e.location_id, l.name AS loc_name, l.code AS loc_code,
                ec.name AS category,
                COALESCE(SUM(e.amount),0) AS amount
         FROM expenses e
         JOIN expense_categories ec ON e.category_id=ec.id
         JOIN locations l           ON e.location_id=l.id
         WHERE e.deleted_at IS NULL
           AND e.expense_date BETWEEN ? AND ?
           AND $locSql
         GROUP BY e.location_id, ec.id
         ORDER BY amount DESC",
        array_merge([$from, $to], $locP)
    );
    return $rows;
}

// ── Build data ───────────────────────────────────────────────
$repairRev   = getRepairRevenue($dateFrom, $dateTo, $filterLocId);
$salesRev    = getSalesRevenue($dateFrom, $dateTo, $filterLocId);
$repairCOGS  = getCOGS($dateFrom, $dateTo, $filterLocId);
$salesCOGS   = getSalesCOGS($dateFrom, $dateTo, $filterLocId);
$expenses    = getExpenses($dateFrom, $dateTo, $filterLocId);

// ── Daily breakdown with detail ──────────────────────────────
[$locSqlD, $locPD] = $filterLocId ? ["AND r.location_id=?", [$filterLocId]] : ['', []];

// Individual completed repairs
$dailyRepairDetail = DB::query(
    "SELECT DATE(r.completed_at) AS day, r.location_id, l.name AS loc_name,
            r.id, r.record_number, r.device_brand, r.device_model,
            r.issue_description, r.final_cost,
            CONCAT(c.first_name,' ',c.last_name) AS customer_name
     FROM repairs r
     JOIN locations l ON l.id=r.location_id
     JOIN customers c ON c.id=r.customer_id
     WHERE r.status='completed' AND r.is_training=0
       AND r.completed_at BETWEEN ? AND ?
       $locSqlD
     ORDER BY r.completed_at",
    array_merge([$dateFrom.' 00:00:00', $dateTo.' 23:59:59'], $locPD)
);

[$locSqlS, $locPS] = $filterLocId ? ["AND s.location_id=?", [$filterLocId]] : ['', []];

// Individual sales with their line items
$dailySaleDetail = DB::query(
    "SELECT DATE(s.created_at) AS day, s.location_id, l.name AS loc_name,
            s.id, s.record_number, s.subtotal,
            GROUP_CONCAT(si.item_name ORDER BY si.id SEPARATOR ', ') AS items
     FROM sales s
     JOIN locations l ON l.id=s.location_id
     LEFT JOIN sale_items si ON si.sale_id=s.id
     WHERE s.created_at BETWEEN ? AND ?
       AND (s.sale_type IS NULL OR s.sale_type NOT IN ('repair_final','repair_deposit'))
       $locSqlS
     GROUP BY s.id
     ORDER BY s.created_at",
    array_merge([$dateFrom.' 00:00:00', $dateTo.' 23:59:59'], $locPS)
);

// Build detail map: day => location_id => {repairs:[], sales:[]}
$detailMap = [];
foreach ($dailyRepairDetail as $r) {
    $detailMap[$r['day']][$r['location_id']]['loc_name']  = $r['loc_name'];
    $detailMap[$r['day']][$r['location_id']]['repairs'][] = $r;
    $detailMap[$r['day']][$r['location_id']]['sales']     = $detailMap[$r['day']][$r['location_id']]['sales'] ?? [];
}
foreach ($dailySaleDetail as $s) {
    $detailMap[$s['day']][$s['location_id']]['loc_name'] = $s['loc_name'];
    $detailMap[$s['day']][$s['location_id']]['sales'][]  = $s;
    $detailMap[$s['day']][$s['location_id']]['repairs']  = $detailMap[$s['day']][$s['location_id']]['repairs'] ?? [];
}
ksort($detailMap);

// Summary totals per day/location for header row
$dailyMap = [];
foreach ($detailMap as $day => $locs) {
    foreach ($locs as $locId => $data) {
        $dailyMap[$day][$locId]['loc_name'] = $data['loc_name'];
        $dailyMap[$day][$locId]['repairs']  = array_sum(array_column($data['repairs'], 'final_cost'));
        $dailyMap[$day][$locId]['sales']    = array_sum(array_column($data['sales'],   'subtotal'));
    }
}

// ── Aggregate by location ────────────────────────────────────
$locData = [];
foreach ($locations as $loc) {
    if ($filterLocId && $loc['id'] != $filterLocId) continue;
    $locData[$loc['id']] = [
        'name'         => $loc['name'],
        'code'         => $loc['code'],
        'repair_rev'   => 0,
        'sales_rev'    => 0,
        'repair_cogs'  => 0,
        'sales_cogs'   => 0,
        'expenses'     => 0,
        'exp_by_cat'   => [],
    ];
}

foreach ($repairRev  as $r) { if (isset($locData[$r['location_id']])) $locData[$r['location_id']]['repair_rev']  += $r['revenue']; }
foreach ($salesRev   as $r) { if (isset($locData[$r['location_id']])) $locData[$r['location_id']]['sales_rev']   += $r['revenue']; }
foreach ($repairCOGS as $r) { if (isset($locData[$r['location_id']])) $locData[$r['location_id']]['repair_cogs'] += $r['cogs']; }
foreach ($salesCOGS  as $r) { if (isset($locData[$r['location_id']])) $locData[$r['location_id']]['sales_cogs']  += $r['cogs']; }
foreach ($expenses   as $e) {
    if (isset($locData[$e['location_id']])) {
        $locData[$e['location_id']]['expenses'] += $e['amount'];
        $locData[$e['location_id']]['exp_by_cat'][$e['category']] =
            ($locData[$e['location_id']]['exp_by_cat'][$e['category']] ?? 0) + $e['amount'];
    }
}

// Combined totals
$combined = ['repair_rev'=>0,'sales_rev'=>0,'repair_cogs'=>0,'sales_cogs'=>0,'expenses'=>0,'exp_by_cat'=>[]];
foreach ($locData as $ld) {
    $combined['repair_rev']  += $ld['repair_rev'];
    $combined['sales_rev']   += $ld['sales_rev'];
    $combined['repair_cogs'] += $ld['repair_cogs'];
    $combined['sales_cogs']  += $ld['sales_cogs'];
    $combined['expenses']    += $ld['expenses'];
    foreach ($ld['exp_by_cat'] as $cat => $amt) {
        $combined['exp_by_cat'][$cat] = ($combined['exp_by_cat'][$cat] ?? 0) + $amt;
    }
}

function calcPL(array $d): array {
    $revenue      = $d['repair_rev'] + $d['sales_rev'];
    $cogs         = $d['repair_cogs'] + $d['sales_cogs'];
    $grossProfit  = $revenue - $cogs;
    $grossMargin  = $revenue > 0 ? ($grossProfit / $revenue * 100) : 0;
    $netProfit    = $grossProfit - $d['expenses'];
    $netMargin    = $revenue > 0 ? ($netProfit / $revenue * 100) : 0;
    return compact('revenue','cogs','grossProfit','grossMargin','netProfit','netMargin');
}

$pageTitle = 'Profit & Loss';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Profit & Loss</h1>
        <p class="page-sub"><?= date('M j, Y', strtotime($dateFrom)) ?> — <?= date('M j, Y', strtotime($dateTo)) ?></p>
    </div>
    <button onclick="window.print()" class="btn btn-secondary">🖨 Print</button>
</div>

<!-- Filters -->
<form method="GET" id="pl-filter">
<div class="card" style="margin-bottom:1.5rem;padding:1rem 1.25rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">

        <!-- Period buttons -->
        <div style="display:flex;gap:.25rem;background:var(--surface-2);border-radius:8px;padding:3px;">
            <?php foreach(['month'=>'Monthly','quarter'=>'Quarterly','year'=>'Yearly','custom'=>'Custom'] as $p=>$label): ?>
            <button type="button" onclick="setPeriod('<?= $p ?>')"
                    style="padding:.35rem .9rem;font-size:13px;font-weight:600;border:none;border-radius:6px;cursor:pointer;
                           background:<?= $period===$p ? 'var(--surface)' : 'transparent' ?>;
                           color:<?= $period===$p ? 'var(--blue)' : 'var(--text-3)' ?>;
                           box-shadow:<?= $period===$p ? 'var(--shadow-sm)' : 'none' ?>;">
                <?= $label ?>
            </button>
            <?php endforeach; ?>
        </div>

        <input type="hidden" name="period" id="period-input" value="<?= $period ?>">

        <!-- Month picker -->
        <div id="f-month" <?= $period!=='month'?'style="display:none;"':'' ?>>
            <input type="month" name="month" class="form-control" style="font-size:14px;"
                   value="<?= $_GET['month'] ?? date('Y-m') ?>" onchange="this.form.submit()">
        </div>

        <!-- Quarter picker -->
        <div id="f-quarter" style="display:flex;gap:.5rem;<?= $period!=='quarter'?'display:none!important;':'' ?>">
            <select name="quarter" class="form-control" style="font-size:14px;" onchange="this.form.submit()">
                <?php for($q=1;$q<=4;$q++): ?>
                <option value="<?=$q?>" <?= ($_GET['quarter']??ceil(date('n')/3))==$q?'selected':'' ?>>Q<?=$q?></option>
                <?php endfor; ?>
            </select>
            <input type="number" name="year" class="form-control" style="width:85px;font-size:14px;"
                   value="<?= $_GET['year'] ?? date('Y') ?>" min="2020" max="2099" onchange="this.form.submit()">
        </div>

        <!-- Year picker -->
        <div id="f-year" <?= $period!=='year'?'style="display:none;"':'' ?>>
            <input type="number" name="year" class="form-control" style="width:85px;font-size:14px;"
                   value="<?= $_GET['year'] ?? date('Y') ?>" min="2020" max="2099" onchange="this.form.submit()">
        </div>

        <!-- Custom range -->
        <div id="f-custom" style="display:flex;gap:.5rem;align-items:center;<?= $period!=='custom'?'display:none!important;':'' ?>">
            <input type="date" name="from" class="form-control" style="font-size:14px;" value="<?= $dateFrom ?>">
            <span style="color:var(--text-3);font-size:13px;">to</span>
            <input type="date" name="to"   class="form-control" style="font-size:14px;" value="<?= $dateTo ?>">
            <button type="submit" class="btn btn-primary" style="font-size:13px;padding:.4rem .9rem;">Apply</button>
        </div>

        <!-- Location -->
        <div style="margin-left:auto;">
            <select name="location_id" class="form-control" style="font-size:14px;" onchange="this.form.submit()">
                <option value="">All Locations</option>
                <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= $filterLocId==$loc['id']?'selected':'' ?>>
                    📍 <?= htmlspecialchars($loc['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

    </div>
</div>
</form>
<script>
function setPeriod(p) {
    document.getElementById('period-input').value = p;
    ['month','quarter','year','custom'].forEach(function(id) {
        var el = document.getElementById('f-' + id);
        if (el) el.style.display = (id === p) ? '' : 'none';
    });
    if (p !== 'custom') document.getElementById('pl-filter').submit();
}
</script>

<!-- Combined Summary Cards -->
<?php $c = calcPL($combined); ?>
<div class="metrics-grid" style="margin-bottom:2rem;">
    <div class="metric-card accent-blue">
        <div class="metric-label">Total Revenue</div>
        <div class="metric-value">$<?= number_format($c['revenue'],2) ?></div>
        <div class="metric-sub">Repairs $<?= number_format($combined['repair_rev'],2) ?> · Sales $<?= number_format($combined['sales_rev'],2) ?></div>
    </div>
    <div class="metric-card accent-orange">
        <div class="metric-label">Cost of Goods</div>
        <div class="metric-value">$<?= number_format($c['cogs'],2) ?></div>
        <div class="metric-sub">Parts & product costs</div>
    </div>
    <div class="metric-card accent-green">
        <div class="metric-label">Gross Profit</div>
        <div class="metric-value">$<?= number_format($c['grossProfit'],2) ?></div>
        <div class="metric-sub"><?= number_format($c['grossMargin'],1) ?>% margin</div>
    </div>
    <div class="metric-card <?= $c['netProfit'] >= 0 ? 'accent-green' : 'accent-red' ?>">
        <div class="metric-label">Net Profit</div>
        <div class="metric-value">$<?= number_format($c['netProfit'],2) ?></div>
        <div class="metric-sub"><?= number_format($c['netMargin'],1) ?>% margin · after $<?= number_format($combined['expenses'],2) ?> expenses</div>
    </div>
</div>

<!-- Per-Location Breakdown -->
<?php if (!$filterLocId && count($locData) > 1): ?>
<div class="repair-grid" style="margin-bottom:2rem;">
<?php foreach ($locData as $ld):
    $pl = calcPL($ld);
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= htmlspecialchars($ld['name']) ?></h2>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Repair Revenue</span>
                <span class="detail-value">$<?= number_format($ld['repair_rev'],2) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Sales Revenue</span>
                <span class="detail-value">$<?= number_format($ld['sales_rev'],2) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Total Revenue</span>
                <span class="detail-value" style="font-weight:700;">$<?= number_format($pl['revenue'],2) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">COGS</span>
                <span class="detail-value" style="color:var(--red);">−$<?= number_format($pl['cogs'],2) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Gross Profit</span>
                <span class="detail-value" style="color:var(--green);">$<?= number_format($pl['grossProfit'],2) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Expenses</span>
                <span class="detail-value" style="color:var(--red);">−$<?= number_format($ld['expenses'],2) ?></span>
            </div>
            <div class="detail-item" style="grid-column:1/-1;padding-top:.5rem;border-top:2px solid var(--border);">
                <span class="detail-label" style="font-weight:700;">Net Profit</span>
                <span class="detail-value" style="font-size:1.1rem;font-weight:800;color:<?= $pl['netProfit']>=0?'var(--green)':'var(--red)' ?>;">
                    $<?= number_format($pl['netProfit'],2) ?>
                    <span style="font-size:12px;font-weight:400;color:var(--text-3);">(<?= number_format($pl['netMargin'],1) ?>%)</span>
                </span>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Full P&L Statement -->
<div class="card" style="max-width:750px;">
    <div class="card-header">
        <h2 class="card-title">P&L Statement<?= $filterLocId ? ' — ' . htmlspecialchars($locData[$filterLocId]['name'] ?? '') : ' — Combined' ?></h2>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <tbody>
                <tr style="background:var(--surface-2);">
                    <td colspan="2" style="font-weight:700;padding:.75rem 1rem;">REVENUE</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Repair Revenue</td>
                    <td style="text-align:right;">$<?= number_format($combined['repair_rev'],2) ?></td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Walk-in Sales Revenue</td>
                    <td style="text-align:right;">$<?= number_format($combined['sales_rev'],2) ?></td>
                </tr>
                <tr style="font-weight:700;border-top:1px solid var(--border);">
                    <td>Total Revenue</td>
                    <td style="text-align:right;">$<?= number_format($c['revenue'],2) ?></td>
                </tr>

                <tr style="background:var(--surface-2);">
                    <td colspan="2" style="font-weight:700;padding:.75rem 1rem;">COST OF GOODS SOLD</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Parts Used in Repairs</td>
                    <td style="text-align:right;">$<?= number_format($combined['repair_cogs'],2) ?></td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Product Cost (Walk-in Sales)</td>
                    <td style="text-align:right;">$<?= number_format($combined['sales_cogs'],2) ?></td>
                </tr>
                <tr style="font-weight:700;border-top:1px solid var(--border);">
                    <td>Total COGS</td>
                    <td style="text-align:right;color:var(--red);">$<?= number_format($c['cogs'],2) ?></td>
                </tr>

                <tr style="font-weight:700;background:rgba(34,197,94,.08);border-top:2px solid var(--border);">
                    <td>GROSS PROFIT</td>
                    <td style="text-align:right;color:var(--green);">$<?= number_format($c['grossProfit'],2) ?>
                        <span style="font-size:12px;color:var(--text-3);"> (<?= number_format($c['grossMargin'],1) ?>%)</span>
                    </td>
                </tr>

                <tr style="background:var(--surface-2);">
                    <td colspan="2" style="font-weight:700;padding:.75rem 1rem;">OPERATING EXPENSES</td>
                </tr>
                <?php
                arsort($combined['exp_by_cat']);
                foreach ($combined['exp_by_cat'] as $cat => $amt): ?>
                <tr>
                    <td style="padding-left:2rem;"><?= htmlspecialchars($cat) ?></td>
                    <td style="text-align:right;">$<?= number_format($amt,2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight:700;border-top:1px solid var(--border);">
                    <td>Total Expenses</td>
                    <td style="text-align:right;color:var(--red);">$<?= number_format($combined['expenses'],2) ?></td>
                </tr>

                <tr style="font-weight:800;font-size:1.05rem;background:<?= $c['netProfit']>=0?'rgba(34,197,94,.08)':'rgba(239,68,68,.08)' ?>;border-top:2px solid var(--border);">
                    <td>NET PROFIT</td>
                    <td style="text-align:right;color:<?= $c['netProfit']>=0?'var(--green)':'var(--red)' ?>;">
                        $<?= number_format($c['netProfit'],2) ?>
                        <span style="font-size:12px;font-weight:400;color:var(--text-3);"> (<?= number_format($c['netMargin'],1) ?>%)</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<style>
@media print {
    .sidebar,.topbar,.filter-bar,.page-header .btn { display:none!important; }
    .main-content { margin-left:0!important; }
}
</style>

<script>
function togglePeriodFields(val) {
    ['month','quarter','year','custom'].forEach(p => {
        const el = document.getElementById('f-' + p);
        if (el) el.style.display = p === val ? '' : 'none';
    });
}
</script>

<!-- Daily Breakdown with Detail -->
<a name="daily"></a>
<?php if (!empty($detailMap)): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header">
        <h2 class="card-title">📅 Daily Breakdown</h2>
        <span class="text-muted small">Click a day to see detail</span>
    </div>
    <div class="card-body" style="padding:0;">
    <?php
    $grandTotal = 0;
    foreach ($detailMap as $day => $locs):
        $dayTotal = 0;
        foreach ($locs as $loc) {
            $dayTotal += array_sum(array_column($loc['repairs'],'final_cost'))
                       + array_sum(array_column($loc['sales'],'subtotal'));
        }
        $grandTotal += $dayTotal;
        $dayId = 'day-' . str_replace('-','',$day);
    ?>
    <!-- Day header row -->
    <div onclick="toggleDay('<?= $dayId ?>')"
         style="display:grid;grid-template-columns:140px 1fr auto;align-items:center;
                padding:.75rem 1.25rem;border-bottom:1px solid var(--border);
                cursor:pointer;background:var(--surface-2);">
        <div style="font-weight:700;font-size:14px;"><?= date('D, M j', strtotime($day)) ?></div>
        <div style="font-size:12px;color:var(--text-3);">
            <?php foreach ($locs as $locId => $loc): ?>
            <span style="margin-right:1rem;">
                📍<?= htmlspecialchars($loc['loc_name']) ?>
                <?php if (!empty($loc['repairs'])): ?>
                <span style="color:var(--blue);">🔧 $<?= number_format(array_sum(array_column($loc['repairs'],'final_cost')),2) ?></span>
                <?php endif; ?>
                <?php if (!empty($loc['sales'])): ?>
                <span style="color:var(--green);">🛒 $<?= number_format(array_sum(array_column($loc['sales'],'subtotal')),2) ?></span>
                <?php endif; ?>
            </span>
            <?php endforeach; ?>
        </div>
        <div style="font-weight:800;font-size:15px;">$<?= number_format($dayTotal,2) ?> <span style="font-size:12px;color:var(--text-3);">▼</span></div>
    </div>

    <!-- Day detail (hidden by default) -->
    <div id="<?= $dayId ?>" style="display:none;border-bottom:2px solid var(--border);">
    <?php foreach ($locs as $locId => $loc): ?>
        <div style="padding:.5rem 1.25rem .25rem;background:var(--surface);border-bottom:1px solid var(--border);">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-3);letter-spacing:.5px;margin-bottom:.4rem;">
                📍 <?= htmlspecialchars($loc['loc_name']) ?>
            </div>

            <?php if (!empty($loc['repairs'])): ?>
            <div style="margin-bottom:.5rem;">
                <div style="font-size:11px;font-weight:700;color:var(--blue);text-transform:uppercase;margin-bottom:.3rem;">🔧 Repairs</div>
                <?php foreach ($loc['repairs'] as $r): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;
                            padding:.3rem .5rem;border-radius:6px;margin-bottom:2px;background:rgba(59,130,246,.05);">
                    <div style="flex:1;">
                        <span style="font-size:12px;font-weight:600;"><?= htmlspecialchars($r['device_brand'].' '.$r['device_model']) ?></span>
                        <span style="font-size:11px;color:var(--text-3);margin-left:.4rem;"><?= htmlspecialchars($r['customer_name']) ?></span>
                        <a href="<?= APP_URL ?>/modules/repairs/view.php?id=<?= $r['id'] ?>"
                           style="font-size:10px;color:var(--blue);margin-left:.4rem;" target="_blank">#<?= $r['record_number'] ?></a><br>
                        <span style="font-size:11px;color:var(--text-3);"><?= htmlspecialchars(mb_strimwidth($r['issue_description'],0,60,'…')) ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;margin-left:1rem;white-space:nowrap;">
                        <span style="font-weight:700;font-size:13px;color:var(--blue);">$<?= number_format($r['final_cost'],2) ?></span>
                        <?php if ($isOwner): ?>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('Void this repair from P&L? It will be marked cancelled.')">
                            <input type="hidden" name="void_repair"   value="1">
                            <input type="hidden" name="repair_id"     value="<?= $r['id'] ?>">
                            <input type="hidden" name="period"        value="<?= htmlspecialchars($period) ?>">
                            <input type="hidden" name="month"         value="<?= htmlspecialchars($_GET['month'] ?? date('Y-m')) ?>">
                            <input type="hidden" name="location_id"   value="<?= $filterLocId ?>">
                            <button type="submit"
                                    style="background:none;border:1px solid var(--red);color:var(--red);
                                           font-size:10px;padding:2px 6px;border-radius:4px;cursor:pointer;
                                           font-weight:700;">
                                Void
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($loc['sales'])): ?>
            <div style="margin-bottom:.5rem;">
                <div style="font-size:11px;font-weight:700;color:var(--green);text-transform:uppercase;margin-bottom:.3rem;">🛒 Sales</div>
                <?php foreach ($loc['sales'] as $s): ?>
                <div style="display:flex;justify-content:space-between;align-items:start;
                            padding:.3rem .5rem;border-radius:6px;margin-bottom:2px;background:rgba(34,197,94,.05);">
                    <div>
                        <span style="font-size:11px;color:var(--text-3);"><?= htmlspecialchars($s['items'] ?? 'Walk-in sale') ?></span>
                    </div>
                    <div style="font-weight:700;font-size:13px;color:var(--green);white-space:nowrap;margin-left:1rem;">
                        $<?= number_format($s['subtotal'],2) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <?php endforeach; ?>

    <!-- Grand total -->
    <div style="display:flex;justify-content:space-between;padding:.85rem 1.25rem;font-weight:800;font-size:1rem;">
        <span>Period Total</span>
        <span style="color:var(--green);">$<?= number_format($grandTotal,2) ?></span>
    </div>
    </div>
</div>

<script>
function toggleDay(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
