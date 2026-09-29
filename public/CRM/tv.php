<?php
// ============================================================
// TV Dashboard — Public display URL, no login required
// Usage: /CRM/tv.php
// ============================================================
$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/helpers.php';

// ── Date ranges ──────────────────────────────────────────────
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$yearStart  = date('Y-01-01');
$yearEnd    = date('Y-12-31');
$thisYear   = date('Y');

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);
$locCount  = count($locations);

// ── Data queries ─────────────────────────────────────────────

$salesData = DB::query(
    "SELECT location_id,
            SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? THEN subtotal ELSE 0 END) AS month_sales,
            SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? THEN subtotal ELSE 0 END) AS year_sales
     FROM sales
     WHERE DATE(created_at) BETWEEN ? AND ?
       AND (sale_type IS NULL OR sale_type NOT IN ('repair_final','repair_deposit'))
     GROUP BY location_id",
    [$monthStart,$monthEnd, $yearStart,$yearEnd, $yearStart,$yearEnd]
);
$sales = []; foreach ($salesData as $r) $sales[$r['location_id']] = $r;

$repairData = DB::query(
    "SELECT location_id,
            COUNT(CASE WHEN DATE(completed_at) BETWEEN ? AND ? AND status='completed' THEN 1 END) AS month_count,
            COUNT(CASE WHEN DATE(completed_at) BETWEEN ? AND ? AND status='completed' THEN 1 END) AS year_count,
            SUM(CASE WHEN DATE(completed_at) BETWEEN ? AND ? AND status='completed' THEN COALESCE(final_cost,0) ELSE 0 END) AS month_rev,
            SUM(CASE WHEN DATE(completed_at) BETWEEN ? AND ? AND status='completed' THEN COALESCE(final_cost,0) ELSE 0 END) AS year_rev,
            COUNT(CASE WHEN status='cancelled' AND DATE(updated_at) BETWEEN ? AND ? THEN 1 END) AS month_cancelled,
            COUNT(CASE WHEN status NOT IN ('completed','cancelled') THEN 1 END) AS in_progress
     FROM repairs WHERE is_training=0 GROUP BY location_id",
    [$monthStart,$monthEnd, $yearStart,$yearEnd,
     $monthStart,$monthEnd, $yearStart,$yearEnd,
     $monthStart,$monthEnd]
);
$repairs = []; foreach ($repairData as $r) $repairs[$r['location_id']] = $r;

$actData = DB::query(
    "SELECT location_id,
            COUNT(CASE WHEN activation_date BETWEEN ? AND ? THEN 1 END) AS month_count,
            COUNT(CASE WHEN activation_date BETWEEN ? AND ? THEN 1 END) AS year_count,
            SUM(CASE WHEN activation_date BETWEEN ? AND ? THEN COALESCE(plan_amount,0) ELSE 0 END) AS month_plan,
            SUM(CASE WHEN activation_date BETWEEN ? AND ? THEN COALESCE(plan_amount,0) ELSE 0 END) AS year_plan,
            SUM(CASE WHEN activation_date BETWEEN ? AND ? THEN COALESCE(commission,0) ELSE 0 END) AS month_commission,
            SUM(CASE WHEN activation_date BETWEEN ? AND ? THEN COALESCE(commission,0) ELSE 0 END) AS year_commission
     FROM activations WHERE activation_date BETWEEN ? AND ? GROUP BY location_id",
    [$monthStart,$monthEnd, $yearStart,$yearEnd,
     $monthStart,$monthEnd, $yearStart,$yearEnd,
     $monthStart,$monthEnd, $yearStart,$yearEnd,
     $yearStart,$yearEnd]
);
$acts = []; foreach ($actData as $r) $acts[$r['location_id']] = $r;

$expData = DB::query(
    "SELECT location_id,
            SUM(CASE WHEN expense_date BETWEEN ? AND ? THEN amount ELSE 0 END) AS month_exp,
            SUM(CASE WHEN expense_date BETWEEN ? AND ? THEN amount ELSE 0 END) AS year_exp
     FROM expenses WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?
     GROUP BY location_id",
    [$monthStart,$monthEnd, $yearStart,$yearEnd, $yearStart,$yearEnd]
);
$exps = []; foreach ($expData as $r) $exps[$r['location_id']] = $r;

$callData = DB::query(
    "SELECT location_id,
            COUNT(CASE WHEN DATE(call_at) BETWEEN ? AND ? THEN 1 END) AS month_calls,
            SUM(CASE WHEN DATE(call_at) BETWEEN ? AND ? AND status='missed' THEN 1 ELSE 0 END) AS month_missed
     FROM call_logs WHERE DATE(call_at) BETWEEN ? AND ? GROUP BY location_id",
    [$monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd]
);
$calls = []; foreach ($callData as $r) $calls[$r['location_id']] = $r;

$inquiryData = [];
try {
    $inquiryData = DB::query(
        "SELECT location_id,
                SUM(status='pending') AS pending,
                SUM(status='quoted')  AS quoted,
                SUM(DATE(created_at) BETWEEN ? AND ?) AS month_total
         FROM walk_in_inquiries GROUP BY location_id",
        [$monthStart, $monthEnd]
    );
} catch (\Throwable $e) {}
$inquiries = []; foreach ($inquiryData as $r) $inquiries[$r['location_id']] = $r;

$invTotals = DB::queryOne(
    "SELECT SUM(i.cost_price * s.quantity) AS cost, SUM(i.sell_price * s.quantity) AS sell
     FROM inventory_items i JOIN inventory_stock s ON s.item_id=i.id WHERE i.is_active=1"
);

$invLocData = DB::query(
    "SELECT s.location_id,
            SUM(s.quantity)                AS units,
            SUM(i.cost_price * s.quantity) AS cost_value,
            SUM(i.sell_price * s.quantity) AS sell_value
     FROM inventory_stock s
     JOIN inventory_items i ON i.id = s.item_id
     WHERE i.is_active = 1
     GROUP BY s.location_id",
    []
);
$invLoc = [];
foreach ($invLocData as $r) $invLoc[$r['location_id']] = $r;

$targetData = DB::query(
    "SELECT t.* FROM sales_targets t
     INNER JOIN (
         SELECT location_id, MAX(effective_from) AS max_date
         FROM sales_targets WHERE period_type='monthly' AND effective_from <= CURDATE()
         GROUP BY location_id
     ) latest ON t.location_id=latest.location_id AND t.effective_from=latest.max_date", []
);
$targets = []; foreach ($targetData as $t) $targets[$t['location_id']] = $t;

function fmt($n): string      { return '$'.number_format((float)$n, 0); }
function fmtN($n): string     { return number_format((int)$n); }
function barColor(int $pct): string {
    return $pct >= 100 ? '#4ade80' : ($pct >= 70 ? '#fbbf24' : '#f87171');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="60">
<title>C Tech Fix — Live Dashboard</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    width: 100%; height: 100%;
    overflow: hidden;
    background: #0f172a;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    color: #e2e8f0;
    display: flex;
    flex-direction: column;
    height: 100dvh;
    padding: clamp(4px, .5vw, 10px);
    gap: clamp(4px, .4vw, 8px);
}

/* ── Topbar ── */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #1e293b;
    border-radius: clamp(6px, .5vw, 12px);
    padding: clamp(4px, .4vh, 10px) clamp(8px, 1vw, 20px);
    flex-shrink: 0;
    border: 1px solid #334155;
}
.topbar-brand {
    font-size: clamp(13px, 1.1vw, 22px);
    font-weight: 900;
    color: #f8fafc;
    letter-spacing: -.3px;
}
.topbar-brand span { color: #3b82f6; }
.topbar-date {
    font-size: clamp(10px, .7vw, 14px);
    color: #94a3b8;
    margin-top: 2px;
}
.topbar-period {
    font-size: clamp(11px, .8vw, 16px);
    color: #94a3b8;
    text-align: center;
    letter-spacing: .5px;
}
.topbar-clock {
    font-size: clamp(16px, 1.6vw, 32px);
    font-weight: 800;
    color: #f8fafc;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.topbar-refresh {
    font-size: clamp(9px, .6vw, 12px);
    color: #475569;
}

/* ── Inventory strip ── */
.inv-strip {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: clamp(6px, .5vw, 12px);
    padding: clamp(3px, .35vh, 8px) clamp(8px, 1vw, 20px);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: clamp(12px, 2vw, 40px);
}
.inv-label {
    font-size: clamp(8px, .6vw, 12px);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: #475569;
    white-space: nowrap;
}
.inv-items { display: flex; gap: clamp(16px, 2.5vw, 48px); flex: 1; }
.inv-item  { display: flex; align-items: baseline; gap: .35em; }
.inv-item .v { font-size: clamp(13px, 1.1vw, 22px); font-weight: 800; color: #f1f5f9; }
.inv-item .l { font-size: clamp(9px, .65vw, 13px); color: #64748b; }
.inv-item .v.profit { color: #4ade80; }

/* ── Location grid ── */
.loc-grid {
    display: grid;
    grid-template-columns: repeat(<?= $locCount ?>, 1fr);
    gap: clamp(4px, .5vw, 10px);
    flex: 1;
    min-height: 0;
}

.loc-col {
    display: flex;
    flex-direction: column;
    gap: clamp(3px, .35vw, 7px);
    min-height: 0;
}

.loc-header {
    background: #3b82f6;
    border-radius: clamp(5px, .4vw, 10px);
    padding: clamp(3px, .4vh, 8px) clamp(8px, .8vw, 16px);
    font-size: clamp(12px, 1vw, 20px);
    font-weight: 800;
    text-align: center;
    letter-spacing: .5px;
    color: #fff;
    flex-shrink: 0;
}

/* ── Cards ── */
.card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: clamp(5px, .4vw, 10px);
    padding: clamp(4px, .4vh, 10px) clamp(6px, .6vw, 14px);
    flex: 1;
    min-height: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: clamp(2px, .25vh, 5px);
}
.card.card-revenue  { border-left: 3px solid #3b82f6; }
.card.card-net-pos  { background: #052e16; border: 1px solid #166534; }
.card.card-net-neg  { background: #450a0a; border: 1px solid #991b1b; }
.card.card-alert    { border: 1px solid #d97706; }

.ctitle {
    font-size: clamp(7px, .55vw, 11px);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .9px;
    color: #475569;
    margin-bottom: clamp(2px, .2vh, 5px);
    flex-shrink: 0;
}

/* Big number */
.big-val {
    font-size: clamp(18px, 2vw, 40px);
    font-weight: 900;
    line-height: 1;
    color: #f8fafc;
}
.big-val.green  { color: #4ade80; }
.big-val.blue   { color: #60a5fa; }
.big-val.red    { color: #f87171; }
.big-val.amber  { color: #fbbf24; }

/* Sub-rows */
.sub-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    font-size: clamp(9px, .7vw, 14px);
}
.sub-label { color: #64748b; }
.sub-val   { font-weight: 700; color: #94a3b8; }

.breakdown {
    font-size: clamp(8px, .6vw, 12px);
    color: #64748b;
    margin-top: clamp(1px, .15vh, 4px);
}
.breakdown span { color: #94a3b8; font-weight: 600; }

.divider {
    height: 1px;
    background: #263348;
    margin: 0;
    flex-shrink: 0;
}

/* Progress bar */
.prog-wrap {
    height: clamp(4px, .4vh, 7px);
    background: #263348;
    border-radius: 4px;
    overflow: hidden;
    margin-top: clamp(2px, .2vh, 4px);
}
.prog-fill { height: 100%; border-radius: 4px; transition: width .5s; }
.prog-label {
    font-size: clamp(8px, .55vw, 11px);
    color: #475569;
    margin-top: 2px;
}

/* Pill tags */
.pills { display: flex; gap: .5em; flex-wrap: wrap; margin-top: clamp(2px, .2vh, 4px); }
.pill {
    font-size: clamp(8px, .6vw, 12px);
    font-weight: 700;
    padding: 1px clamp(4px, .4vw, 8px);
    border-radius: 99px;
}
.pill-amber  { background:#451a03; color:#fbbf24; }
.pill-red    { background:#450a0a; color:#f87171; }
.pill-green  { background:#052e16; color:#4ade80; }
.pill-blue   { background:#172554; color:#60a5fa; }
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <div>
        <div class="topbar-brand">🔧 C <span>Tech</span> Fix</div>
        <div class="topbar-date"><?= date('l, F j, Y') ?></div>
    </div>
    <div class="topbar-period"><?= date('F Y') ?> &nbsp;·&nbsp; <?= $thisYear ?></div>
    <div>
        <div class="topbar-clock" id="clock"><?= date('g:i:s A') ?></div>
        <div class="topbar-refresh">Auto-refreshes every 60s</div>
    </div>
</div>

<!-- Inventory strip -->
<div class="inv-strip">
    <div class="inv-label">📦 Inventory</div>
    <div class="inv-items">
        <div class="inv-item">
            <div class="v"><?= fmt($invTotals['cost'] ?? 0) ?></div>
            <div class="l">Cost</div>
        </div>
        <div class="inv-item">
            <div class="v"><?= fmt($invTotals['sell'] ?? 0) ?></div>
            <div class="l">Sell Value</div>
        </div>
        <div class="inv-item">
            <div class="v profit"><?= fmt(($invTotals['sell'] ?? 0) - ($invTotals['cost'] ?? 0)) ?></div>
            <div class="l">Potential Profit</div>
        </div>
    </div>
</div>

<!-- Per-location grid -->
<div class="loc-grid">
<?php foreach ($locations as $loc):
    $lid = $loc['id'];
    $s   = $sales[$lid]     ?? [];
    $r   = $repairs[$lid]   ?? [];
    $a   = $acts[$lid]      ?? [];
    $e   = $exps[$lid]      ?? [];
    $c   = $calls[$lid]     ?? [];
    $t   = $targets[$lid]   ?? [];
    $iq  = $inquiries[$lid] ?? [];

    $monthSales   = (float)($s['month_sales']      ?? 0);
    $yearSales    = (float)($s['year_sales']        ?? 0);
    $monthRepRev  = (float)($r['month_rev']         ?? 0);
    $yearRepRev   = (float)($r['year_rev']          ?? 0);
    $monthActComm = (float)($a['month_commission']  ?? 0);
    $yearActComm  = (float)($a['year_commission']   ?? 0);
    $monthActPlan = (float)($a['month_plan']        ?? 0);
    $monthActCnt  = (int)($a['month_count']         ?? 0);
    $yearActCnt   = (int)($a['year_count']          ?? 0);
    $monthRev     = $monthSales + $monthRepRev + $monthActComm;
    $yearRev      = $yearSales  + $yearRepRev  + $yearActComm;
    $monthExp     = (float)($e['month_exp']         ?? 0);
    $yearExp      = (float)($e['year_exp']          ?? 0);
    $monthNet     = $monthRev - $monthExp;
    $yearNet      = $yearRev  - $yearExp;
    $iqPending    = (int)($iq['pending']            ?? 0);
    $iqQuoted     = (int)($iq['quoted']             ?? 0);

    $goalAmount  = (float)($t['target_amount']  ?? 0);
    $goalRepairs = (int)($t['target_repairs']   ?? 0);
    $revPct      = $goalAmount  > 0 ? min(100, round($monthRev / $goalAmount * 100))             : 0;
    $repPct      = $goalRepairs > 0 ? min(100, round(($r['month_count'] ?? 0) / $goalRepairs * 100)) : 0;


?>
<div class="loc-col">

    <div class="loc-header"><?= htmlspecialchars($loc['name']) ?></div>

    <!-- Revenue -->
    <div class="card card-revenue">
        <div class="ctitle">💰 Revenue &nbsp;<span style="font-weight:400;color:#475569;"><?= $thisYear ?> total: <span style="color:#60a5fa;"><?= fmt($yearRev) ?></span></span></div>
        <div class="big-val blue"><?= fmt($monthRev) ?></div>
        <div class="breakdown">
            Walk-in <span><?= fmt($monthSales) ?></span>
            &nbsp;·&nbsp; Repairs <span><?= fmt($monthRepRev) ?></span>
            &nbsp;·&nbsp; Activations <span><?= fmt($monthActComm) ?></span>
        </div>
        <?php if ($goalAmount > 0): ?>
        <div class="prog-wrap"><div class="prog-fill" style="width:<?= $revPct ?>%;background:<?= barColor($revPct) ?>;"></div></div>
        <div class="prog-label"><?= $revPct ?>% of <?= fmt($goalAmount) ?> goal</div>
        <?php endif; ?>
    </div>

    <!-- Net Revenue -->
    <div class="card <?= $monthNet >= 0 ? 'card-net-pos' : 'card-net-neg' ?>">
        <div class="ctitle" style="color:<?= $monthNet >= 0 ? '#16a34a' : '#dc2626' ?>;">📈 Net Revenue &nbsp;<span style="font-weight:400;color:#475569;"><?= $thisYear ?>: <span style="color:<?= $yearNet >= 0 ? '#4ade80' : '#f87171' ?>;"><?= fmt($yearNet) ?></span></span></div>
        <div class="big-val <?= $monthNet >= 0 ? 'green' : 'red' ?>"><?= fmt($monthNet) ?></div>
        <div class="breakdown"><?= fmt($monthRev) ?> rev &minus; <?= fmt($monthExp) ?> exp</div>
    </div>

    <!-- Repairs -->
    <div class="card">
        <div class="ctitle">🔧 Repairs &nbsp;<span style="font-weight:400;color:#475569;"><?= $thisYear ?>: <span style="color:#94a3b8;"><?= fmtN($r['year_count'] ?? 0) ?> · <?= fmt($yearRepRev) ?></span></span></div>
        <div class="big-val green"><?= fmtN($r['month_count'] ?? 0) ?> <span style="font-size:.5em;color:#4ade80;">done</span></div>
        <?php if ($goalRepairs > 0): ?>
        <div class="prog-wrap"><div class="prog-fill" style="width:<?= $repPct ?>%;background:<?= barColor($repPct) ?>;"></div></div>
        <div class="prog-label"><?= $repPct ?>% of <?= $goalRepairs ?> goal</div>
        <?php endif; ?>
        <div class="pills">
            <span class="pill pill-amber">⏳ <?= fmtN($r['in_progress'] ?? 0) ?> active</span>
            <span class="pill pill-red">✗ <?= fmtN($r['month_cancelled'] ?? 0) ?> cancelled</span>
        </div>
    </div>

    <!-- Activations -->
    <div class="card">
        <div class="ctitle">📡 Activations &nbsp;<span style="font-weight:400;color:#475569;"><?= $thisYear ?>: <span style="color:#94a3b8;"><?= fmtN($yearActCnt) ?></span></span></div>
        <div class="big-val blue"><?= fmtN($monthActCnt) ?> <span style="font-size:.5em;color:#60a5fa;">this month</span></div>
        <div class="breakdown">
            Plan value <span><?= fmt($monthActPlan) ?></span>
            &nbsp;·&nbsp; Commission <span><?= fmt($monthActComm) ?></span>
        </div>
    </div>

    <!-- Calls + Inquiries (combined row) -->
    <div class="card <?= $iqPending > 0 ? 'card-alert' : '' ?>">
        <div style="display:flex;gap:clamp(6px,.6vw,14px);height:100%;min-height:0;">
            <!-- Calls -->
            <div style="flex:1;min-width:0;">
                <div class="ctitle">📞 Calls</div>
                <div class="big-val"><?= fmtN($c['month_calls'] ?? 0) ?></div>
                <div class="breakdown"><span style="color:#f87171;"><?= fmtN($c['month_missed'] ?? 0) ?> missed</span></div>
            </div>
            <div style="width:1px;background:#263348;flex-shrink:0;"></div>
            <!-- Inquiries -->
            <div style="flex:1;min-width:0;">
                <div class="ctitle" style="<?= $iqPending > 0 ? 'color:#d97706;' : '' ?>">🚶 Inquiries</div>
                <div class="pills" style="margin-top:2px;">
                    <span class="pill pill-amber">⏳ <?= $iqPending ?> pending</span>
                    <span class="pill pill-blue">✅ <?= $iqQuoted ?> quoted</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Expenses -->
    <div class="card">
        <div class="ctitle">📋 Expenses &nbsp;<span style="font-weight:400;color:#475569;"><?= $thisYear ?>: <span style="color:#f87171;"><?= fmt($yearExp) ?></span></span></div>
        <div class="big-val red"><?= fmt($monthExp) ?></div>
    </div>

    <!-- Inventory (this location) -->
    <?php
    $inv = $invLoc[$lid] ?? null;
    $invUnits = (int)($inv['units']      ?? 0);
    $invCost  = (float)($inv['cost_value'] ?? 0);
    $invSell  = (float)($inv['sell_value'] ?? 0);
    $invProfit = $invSell - $invCost;
    ?>
    <div class="card">
        <div class="ctitle">📦 Inventory</div>
        <div class="big-val"><?= fmtN($invUnits) ?> <span style="font-size:.45em;color:#94a3b8;">units</span></div>
        <div class="breakdown">
            Cost <span><?= fmt($invCost) ?></span>
            &nbsp;·&nbsp; Sell <span><?= fmt($invSell) ?></span>
            &nbsp;·&nbsp; Margin <span style="color:#4ade80;"><?= fmt($invProfit) ?></span>
        </div>
    </div>

</div>
<?php endforeach; ?>
</div>

<script>
(function() {
    function tick() {
        const now = new Date();
        let h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
        const ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        document.getElementById('clock').textContent =
            h + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0') + ' ' + ap;
    }
    setInterval(tick, 1000); tick();
})();
</script>
</body>
</html>
