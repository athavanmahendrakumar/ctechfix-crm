<?php
// ============================================================
// C Tech Fix CRM -- Claude AI Data API
// URL: https://ctrepair.ca/CRM/api.php?key=YOUR_KEY&report=REPORT
// ============================================================
ob_start(); // capture any stray output so it never corrupts JSON
$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Validate API key ─────────────────────────────────────────
$key = trim($_GET['key'] ?? '');
if (!$key) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error'=>'Missing API key']);
    exit;
}

$stored = DB::queryOne("SELECT value FROM settings WHERE setting_key='ai_api_key' AND location_id IS NULL", []);
if (!$stored || !hash_equals($stored['value'], $key)) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error'=>'Invalid API key']);
    exit;
}

// ── Date helpers ─────────────────────────────────────────────
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$yearStart  = date('Y-01-01');
$yearEnd    = date('Y-12-31');
$weekStart  = date('Y-m-d', strtotime('monday this week'));

$report = $_GET['report'] ?? 'summary';
$locId  = intval($_GET['location_id'] ?? 0);
$locSql  = $locId ? "AND location_id=$locId"   : '';
$locSqlS = $locId ? "AND s.location_id=$locId" : '';
$locSqlR = $locId ? "AND r.location_id=$locId" : '';
$locSqlA = $locId ? "AND a.location_id=$locId" : '';

$out = ['report' => $report, 'generated_at' => date('Y-m-d H:i:s'), 'timezone' => 'America/Toronto'];

try {

// ── SUMMARY (default — everything at a glance) ───────────────
if ($report === 'summary') {

    // Today revenue
    $todaySales = DB::queryOne(
        "SELECT COALESCE(SUM(subtotal),0) AS rev, COUNT(*) AS cnt
         FROM sales WHERE DATE(created_at)=? AND sale_type='sale' $locSql", [$today]);
    $todayRepairs = DB::queryOne(
        "SELECT COALESCE(SUM(s.subtotal),0) AS rev
         FROM sales s JOIN repairs r ON r.id=s.repair_id
         WHERE DATE(s.created_at)=? AND s.sale_type IN ('repair_final','repair_deposit')
           AND r.is_training=0 $locSqlS", [$today]);
    $todayAct = DB::queryOne(
        "SELECT COALESCE(SUM(plan_amount),0) AS rev, COALESCE(SUM(commission),0) AS commission, COUNT(*) AS cnt
         FROM activations WHERE activation_date=? $locSql", [$today]);

    // Month revenue -- sales table has no alias here so use plain $locSql
    $monthSales = DB::queryOne(
        "SELECT COALESCE(SUM(subtotal),0) AS rev FROM sales
         WHERE sale_type='sale' AND DATE(created_at) BETWEEN ? AND ? $locSql",
        [$monthStart,$monthEnd]);
    $monthRepairs = DB::queryOne(
        "SELECT COALESCE(SUM(s.subtotal),0) AS rev
         FROM sales s JOIN repairs r ON r.id=s.repair_id
         WHERE s.sale_type IN ('repair_final','repair_deposit') AND r.is_training=0
           AND DATE(s.created_at) BETWEEN ? AND ? $locSqlS",
        [$monthStart,$monthEnd]);
    $monthAct = DB::queryOne(
        "SELECT COALESCE(SUM(plan_amount),0) AS rev, COALESCE(SUM(commission),0) AS commission, COUNT(*) AS cnt
         FROM activations WHERE activation_date BETWEEN ? AND ? $locSql", [$monthStart,$monthEnd]);

    // Active repairs
    $activeRepairs = DB::queryOne(
        "SELECT COUNT(*) AS cnt,
                SUM(status='received') AS received,
                SUM(status='in_repair') AS in_repair,
                SUM(status='waiting_parts') AS waiting_parts,
                SUM(status='ready_pickup') AS ready_pickup,
                SUM(status='diagnosed') AS diagnosed
         FROM repairs WHERE status NOT IN ('completed','cancelled','non_repairable') AND is_training=0 $locSql", []);

    // Inventory low stock
    $lowStock = DB::queryOne(
        "SELECT COUNT(DISTINCT i.id) AS items
         FROM inventory_items i JOIN inventory_stock s ON s.item_id=i.id
         WHERE i.is_active=1 AND s.quantity<=s.min_quantity AND i.buy_on_demand=0", []);

    // Staff clocked in
    $clockedIn = DB::query(
        "SELECT u.first_name, u.last_name, l.name AS location, sa.clock_in
         FROM staff_attendance sa
         JOIN users u ON u.id=sa.user_id
         JOIN locations l ON l.id=sa.location_id
         WHERE sa.clock_out IS NULL AND DATE(sa.clock_in)=?
         ORDER BY sa.clock_in", [$today]);

    // Cash drawers today
    $cashDrawers = DB::query(
        "SELECT l.name AS location, cd.opening_amount, cd.actual_close, cd.status
         FROM cash_drawers cd JOIN locations l ON l.id=cd.location_id
         WHERE cd.drawer_date=? ORDER BY l.name", [$today]);

    $todayTotal = (float)$todaySales['rev'] + (float)$todayRepairs['rev'] + (float)$todayAct['rev'];
    $monthTotal = (float)$monthSales['rev'] + (float)$monthRepairs['rev'] + (float)$monthAct['rev'];

    $out['today'] = [
        'total_revenue'   => round($todayTotal, 2),
        'walkin_sales'    => round((float)$todaySales['rev'], 2),
        'walkin_txns'     => (int)$todaySales['cnt'],
        'repair_payments' => round((float)$todayRepairs['rev'], 2),
        'activations'     => round((float)$todayAct['rev'], 2),
        'activation_cnt'  => (int)$todayAct['cnt'],
    ];
    $out['month_to_date'] = [
        'total_revenue'   => round($monthTotal, 2),
        'walkin_sales'    => round((float)$monthSales['rev'], 2),
        'repair_payments' => round((float)$monthRepairs['rev'], 2),
        'activations'     => round((float)$monthAct['rev'], 2),
        'month'           => date('F Y'),
    ];
    $out['active_repairs'] = [
        'total'         => (int)$activeRepairs['cnt'],
        'received'      => (int)$activeRepairs['received'],
        'diagnosed'     => (int)$activeRepairs['diagnosed'],
        'in_repair'     => (int)$activeRepairs['in_repair'],
        'waiting_parts' => (int)$activeRepairs['waiting_parts'],
        'ready_pickup'  => (int)$activeRepairs['ready_pickup'],
    ];
    $out['low_stock_items']   = (int)($lowStock['items'] ?? 0);
    $out['staff_clocked_in']  = $clockedIn;
    $out['cash_drawers_today'] = $cashDrawers;
}

// ── SALES ────────────────────────────────────────────────────
elseif ($report === 'sales') {

    $period = $_GET['period'] ?? 'month';
    switch ($period) {
        case 'today':   $from = $to = $today; break;
        case 'week':    $from = $weekStart; $to = $today; break;
        case 'year':    $from = $yearStart; $to = $yearEnd; break;
        case 'custom':  $from = $_GET['from'] ?? $monthStart; $to = $_GET['to'] ?? $monthEnd; break;
        default:        $from = $monthStart; $to = $monthEnd;
    }

    // Daily breakdown
    $daily = DB::query(
        "SELECT DATE(s.created_at) AS day,
                SUM(CASE WHEN s.sale_type='sale' THEN s.subtotal ELSE 0 END) AS walkin,
                SUM(CASE WHEN s.sale_type IN ('repair_final','repair_deposit') THEN s.subtotal ELSE 0 END) AS repairs,
                COUNT(DISTINCT CASE WHEN s.sale_type='sale' THEN s.id END) AS walkin_cnt,
                SUM(CASE WHEN s.payment_method='cash' THEN s.subtotal ELSE 0 END) AS cash,
                SUM(CASE WHEN s.payment_method='card' THEN s.subtotal ELSE 0 END) AS card,
                SUM(CASE WHEN s.payment_method='e-transfer' THEN s.subtotal ELSE 0 END) AS etransfer
         FROM sales s
         LEFT JOIN repairs r ON r.id=s.repair_id
         WHERE DATE(s.created_at) BETWEEN ? AND ?
           AND (r.id IS NULL OR r.is_training=0) $locSqlS
         GROUP BY DATE(s.created_at) ORDER BY day",
        [$from, $to]);

    $acts = DB::query(
        "SELECT activation_date AS day, SUM(plan_amount) AS rev, SUM(commission) AS commission, COUNT(*) AS cnt
         FROM activations WHERE activation_date BETWEEN ? AND ? $locSql
         GROUP BY activation_date ORDER BY day", [$from,$to]);
    $actByDay = [];
    foreach ($acts as $a) $actByDay[$a['day']] = $a;

    foreach ($daily as &$d) {
        $d['activations'] = (float)($actByDay[$d['day']]['rev'] ?? 0);
        $d['total'] = round((float)$d['walkin'] + (float)$d['repairs'] + $d['activations'], 2);
    }

    // Top items
    $topItems = DB::query(
        "SELECT si.item_name, SUM(si.quantity) AS units, SUM(si.line_total) AS revenue
         FROM sale_items si JOIN sales s ON s.id=si.sale_id
         WHERE s.sale_type='sale' AND DATE(s.created_at) BETWEEN ? AND ? $locSqlS
         GROUP BY si.item_name ORDER BY revenue DESC LIMIT 10", [$from,$to]);

    // By staff
    $byStaff = DB::query(
        "SELECT u.first_name, u.last_name,
                COALESCE(SUM(CASE WHEN s.sale_type='sale' THEN s.subtotal END),0) AS walkin,
                COALESCE(SUM(CASE WHEN s.sale_type IN ('repair_final','repair_deposit') THEN s.subtotal END),0) AS repairs
         FROM sales s JOIN users u ON u.id=s.created_by
         LEFT JOIN repairs r ON r.id=s.repair_id
         WHERE DATE(s.created_at) BETWEEN ? AND ?
           AND (r.id IS NULL OR r.is_training=0) $locSqlS
         GROUP BY s.created_by ORDER BY SUM(s.subtotal) DESC", [$from,$to]);

    $out['period'] = ['from'=>$from,'to'=>$to,'type'=>$period];
    $out['daily_breakdown'] = $daily;
    $out['top_items']       = $topItems;
    $out['by_staff']        = $byStaff;
}

// ── REPAIRS ──────────────────────────────────────────────────
elseif ($report === 'repairs') {

    $from = $_GET['from'] ?? $monthStart;
    $to   = $_GET['to']   ?? $monthEnd;

    $stats = DB::queryOne(
        "SELECT COUNT(*) AS total,
                SUM(status='completed') AS completed,
                SUM(status='cancelled') AS cancelled,
                SUM(status='non_repairable') AS non_repairable,
                SUM(status NOT IN ('completed','cancelled','non_repairable')) AS active,
                COALESCE(SUM(CASE WHEN status='completed' THEN final_cost END),0) AS revenue
         FROM repairs WHERE is_training=0 AND DATE(created_at) BETWEEN ? AND ? $locSql",
        [$from,$to]);

    $byDevice = DB::query(
        "SELECT device_type, COUNT(*) AS cnt, COALESCE(SUM(final_cost),0) AS rev
         FROM repairs WHERE is_training=0 AND DATE(created_at) BETWEEN ? AND ? $locSql
         GROUP BY device_type ORDER BY cnt DESC", [$from,$to]);

    $byStaff = DB::query(
        "SELECT u.first_name, u.last_name,
                COUNT(*) AS total, SUM(r.status='completed') AS completed,
                COALESCE(SUM(CASE WHEN r.status='completed' THEN r.final_cost END),0) AS revenue
         FROM repairs r JOIN users u ON u.id=r.assigned_to
         WHERE r.is_training=0 AND DATE(r.created_at) BETWEEN ? AND ? $locSqlR
         GROUP BY r.assigned_to ORDER BY SUM(r.final_cost) DESC", [$from,$to]);

    $active = DB::query(
        "SELECT r.id, r.device_type, r.device_model,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                r.status, r.created_at, l.name AS location
         FROM repairs r
         JOIN locations l ON l.id=r.location_id
         JOIN customers c ON c.id=r.customer_id
         WHERE r.is_training=0 AND r.status NOT IN ('completed','cancelled','non_repairable') $locSqlR
         ORDER BY r.created_at ASC LIMIT 20", []);

    $out['period']     = ['from'=>$from,'to'=>$to];
    $out['stats']      = $stats;
    $out['by_device']  = $byDevice;
    $out['by_staff']   = $byStaff;
    $out['active_now'] = $active;
}

// ── INVENTORY ────────────────────────────────────────────────
elseif ($report === 'inventory') {

    $totals = DB::queryOne(
        "SELECT COUNT(DISTINCT i.id) AS total_items,
                COALESCE(SUM(s.quantity),0) AS total_units,
                COALESCE(SUM(i.cost_price*s.quantity),0) AS cost_value,
                COALESCE(SUM(i.sell_price*s.quantity),0) AS sell_value,
                SUM(CASE WHEN (i.cost_price IS NULL OR i.cost_price=0) THEN 1 ELSE 0 END) AS zero_cost_items,
                SUM(CASE WHEN (i.cost_price IS NULL OR i.cost_price=0) AND COALESCE(s.quantity,0)>0 THEN 1 ELSE 0 END) AS zero_cost_in_stock
         FROM inventory_items i LEFT JOIN inventory_stock s ON s.item_id=i.id
         WHERE i.is_active=1", []);

    $zeroCostItems = DB::query(
        "SELECT i.name, i.category, i.sku, i.sell_price,
                COALESCE(SUM(s.quantity),0) AS total_qty
         FROM inventory_items i LEFT JOIN inventory_stock s ON s.item_id=i.id
         WHERE i.is_active=1 AND (i.cost_price IS NULL OR i.cost_price=0)
         GROUP BY i.id, i.name, i.category, i.sku, i.sell_price
         ORDER BY i.category, i.name", []);

    $lowStock = DB::query(
        "SELECT i.name, i.category, i.sku,
                s.quantity, s.min_quantity, l.name AS location,
                GREATEST(0, s.min_quantity-s.quantity+s.min_quantity) AS need_to_buy
         FROM inventory_items i
         JOIN inventory_stock s ON s.item_id=i.id
         JOIN locations l ON l.id=s.location_id
         WHERE i.is_active=1 AND s.quantity<=s.min_quantity AND i.buy_on_demand=0
         ORDER BY i.category, i.name", []);

    $byCategory = DB::query(
        "SELECT i.category,
                COUNT(DISTINCT i.id) AS items,
                COALESCE(SUM(s.quantity),0) AS units,
                COALESCE(SUM(i.cost_price*s.quantity),0) AS cost_value
         FROM inventory_items i LEFT JOIN inventory_stock s ON s.item_id=i.id
         WHERE i.is_active=1 GROUP BY i.category ORDER BY cost_value DESC", []);

    $out['totals']           = $totals;
    $out['low_stock']        = $lowStock;
    $out['by_category']      = $byCategory;
    $out['zero_cost_items']  = $zeroCostItems;
}

// ── CASH ─────────────────────────────────────────────────────
elseif ($report === 'cash') {

    $drawers = DB::query(
        "SELECT cd.*, l.name AS location
         FROM cash_drawers cd JOIN locations l ON l.id=cd.location_id
         WHERE cd.drawer_date=? ORDER BY l.name", [$today]);

    $weekCash = DB::query(
        "SELECT DATE(created_at) AS day,
                SUM(CASE WHEN payment_method='cash' THEN subtotal ELSE 0 END) AS cash,
                SUM(CASE WHEN payment_method='card' THEN subtotal ELSE 0 END) AS card,
                SUM(CASE WHEN payment_method='e-transfer' THEN subtotal ELSE 0 END) AS etransfer
         FROM sales WHERE DATE(created_at) BETWEEN ? AND ? $locSql
         GROUP BY DATE(created_at) ORDER BY day", [$weekStart,$today]);

    $out['today_drawers']   = $drawers;
    $out['week_by_method']  = $weekCash;
    $out['today']           = $today;
}

// ── STAFF ────────────────────────────────────────────────────
elseif ($report === 'staff') {

    $clocked = DB::query(
        "SELECT u.first_name, u.last_name, l.name AS location, sa.clock_in,
                ROUND(TIMESTAMPDIFF(MINUTE,sa.clock_in,NOW())/60,1) AS hours_so_far
         FROM staff_attendance sa
         JOIN users u ON u.id=sa.user_id JOIN locations l ON l.id=sa.location_id
         WHERE sa.clock_out IS NULL AND DATE(sa.clock_in)=?", [$today]);

    $todayShifts = DB::query(
        "SELECT u.first_name, u.last_name, l.name AS location,
                sa.clock_in, sa.clock_out, sa.hours_worked
         FROM staff_attendance sa
         JOIN users u ON u.id=sa.user_id JOIN locations l ON l.id=sa.location_id
         WHERE DATE(sa.clock_in)=? ORDER BY sa.clock_in", [$today]);

    $monthHours = DB::query(
        "SELECT u.first_name, u.last_name,
                ROUND(SUM(sa.hours_worked),1) AS total_hours,
                COUNT(*) AS shifts
         FROM staff_attendance sa JOIN users u ON u.id=sa.user_id
         WHERE sa.clock_out IS NOT NULL AND DATE(sa.clock_in) BETWEEN ? AND ?
         GROUP BY sa.user_id ORDER BY total_hours DESC",
        [$monthStart,$monthEnd]);

    $out['currently_clocked_in'] = $clocked;
    $out['todays_shifts']        = $todayShifts;
    $out['month_hours']          = $monthHours;
}

// ── CALLS ────────────────────────────────────────────────────
elseif ($report === 'calls') {

    $from = $_GET['from'] ?? $monthStart;
    $to   = $_GET['to']   ?? $monthEnd;

    // Overall call stats for the period
    $stats = DB::queryOne(
        "SELECT COUNT(*) AS total,
                SUM(direction='inbound')  AS inbound,
                SUM(direction='outbound') AS outbound,
                SUM(status='missed' AND direction='inbound')   AS missed_inbound,
                SUM(status='answered' AND direction='inbound') AS answered_inbound,
                SUM(classification='new_repair_lead')          AS repair_leads,
                SUM(classification='product_sales_inquiry')    AS sales_inquiries,
                SUM(classification='existing_repair_status')   AS status_calls,
                SUM(classification='wireless_activation_inquiry') AS activation_inquiries,
                SUM(classification='wrong_number_spam')        AS spam,
                SUM(classification='unclassified')             AS unclassified,
                SUM(needs_callback=1 AND (callback_status IS NULL OR callback_status='pending')) AS pending_callbacks,
                ROUND(AVG(CASE WHEN status='answered' THEN duration_seconds END)/60, 1) AS avg_call_min
         FROM call_logs
         WHERE DATE(call_at) BETWEEN ? AND ? AND is_training=0 $locSql",
        [$from, $to]);

    // Missed calls that were never called back
    $missedNoCallback = DB::query(
        "SELECT cl.caller_number, cl.caller_name, cl.call_at, l.name AS location
         FROM call_logs cl JOIN locations l ON l.id=cl.location_id
         WHERE cl.direction='inbound' AND cl.status='missed'
           AND cl.needs_callback=1
           AND (cl.callback_status IS NULL OR cl.callback_status='pending')
           AND cl.is_training=0 $locSqlS
         ORDER BY cl.call_at DESC LIMIT 20",
        []);

    // Call-to-repair conversion: inbound repair leads where customer later booked a repair
    $conversions = DB::queryOne(
        "SELECT
            COUNT(DISTINCT cl.id) AS repair_lead_calls,
            COUNT(DISTINCT r.id)  AS converted_to_repair
         FROM call_logs cl
         LEFT JOIN repairs r
           ON r.customer_id = cl.customer_id
           AND r.created_at >= cl.call_at
           AND DATE(r.created_at) <= DATE_ADD(DATE(cl.call_at), INTERVAL 7 DAY)
           AND r.is_training = 0
         WHERE cl.classification = 'new_repair_lead'
           AND cl.direction = 'inbound'
           AND DATE(cl.call_at) BETWEEN ? AND ?
           AND cl.is_training = 0 $locSql",
        [$from, $to]);

    // Daily call volume
    $daily = DB::query(
        "SELECT DATE(call_at) AS day,
                COUNT(*) AS total,
                SUM(direction='inbound')  AS inbound,
                SUM(status='missed' AND direction='inbound') AS missed
         FROM call_logs
         WHERE DATE(call_at) BETWEEN ? AND ? AND is_training=0 $locSql
         GROUP BY DATE(call_at) ORDER BY day",
        [$from, $to]);

    // By classification breakdown
    $byClass = DB::query(
        "SELECT classification,
                COUNT(*) AS cnt,
                SUM(status='answered') AS answered,
                SUM(status='missed')   AS missed
         FROM call_logs
         WHERE direction='inbound' AND DATE(call_at) BETWEEN ? AND ? AND is_training=0 $locSql
         GROUP BY classification ORDER BY cnt DESC",
        [$from, $to]);

    // Pending callbacks
    $callbacks = DB::query(
        "SELECT cl.caller_number, cl.caller_name, cl.call_at,
                cl.classification, cl.summary, l.name AS location
         FROM call_logs cl JOIN locations l ON l.id=cl.location_id
         WHERE cl.needs_callback=1
           AND (cl.callback_status IS NULL OR cl.callback_status='pending')
           AND cl.is_training=0 $locSqlS
         ORDER BY cl.call_at ASC LIMIT 10",
        []);

    // Conversion rate calculation
    $leadCalls  = (int)($conversions['repair_lead_calls'] ?? 0);
    $converted  = (int)($conversions['converted_to_repair'] ?? 0);
    $convRate   = $leadCalls > 0 ? round($converted / $leadCalls * 100, 1) : null;

    $out['period']              = ['from' => $from, 'to' => $to];
    $out['stats']               = $stats;
    $out['conversion'] = [
        'repair_lead_calls'    => $leadCalls,
        'converted_to_repair'  => $converted,
        'conversion_rate_pct'  => $convRate,
        'note'                 => 'Conversion = repair lead call followed by a repair ticket within 7 days',
    ];
    $out['daily_volume']        = $daily;
    $out['by_classification']   = $byClass;
    $out['pending_callbacks']   = $callbacks;
    $out['missed_no_callback']  = $missedNoCallback;
}

// ── CALL CONVERSION AUDIT ────────────────────────────────────
// Matches every inbound call to repairs via phone_normalized
elseif ($report === 'call_audit') {

    $from   = $_GET['from'] ?? $monthStart;
    $to     = $_GET['to']   ?? $monthEnd;
    $window = intval($_GET['window_days'] ?? 30); // days after call to look for repair

    // All inbound calls in period, matched to customer + any repair by phone number
    $rows = DB::query(
        "SELECT
            cl.id            AS call_id,
            cl.call_at,
            cl.status        AS call_status,
            cl.classification,
            cl.caller_number,
            cl.caller_name,
            cl.duration_seconds,
            cl.summary,
            l.name           AS location,
            -- customer match by phone
            c.id             AS customer_id,
            CONCAT(c.first_name,' ',c.last_name) AS customer_name,
            -- most recent repair by this customer after the call
            r.id             AS repair_id,
            r.device_type,
            r.device_model,
            r.status         AS repair_status,
            r.final_cost,
            r.created_at     AS repair_created_at,
            r.is_training
         FROM call_logs cl
         JOIN locations l ON l.id = cl.location_id
         LEFT JOIN customers c
            ON c.phone_normalized = cl.caller_number
         LEFT JOIN repairs r
            ON r.customer_id = c.id
            AND r.created_at >= cl.call_at
            AND r.created_at <= DATE_ADD(cl.call_at, INTERVAL ? DAY)
            AND r.is_training = 0
         WHERE cl.direction = 'inbound'
           AND DATE(cl.call_at) BETWEEN ? AND ?
           AND cl.is_training = 0
         ORDER BY cl.call_at DESC",
        [$window, $from, $to]);

    // Summarise
    $total          = 0;
    $converted      = 0;
    $repairLeads    = 0;
    $repairConverted= 0;
    $unmatched      = 0; // caller not in customer DB
    $summary        = [];

    foreach ($rows as $r) {
        $total++;
        $hasCustomer = !empty($r['customer_id']);
        $hasRepair   = !empty($r['repair_id']);
        if (!$hasCustomer) $unmatched++;
        if ($hasRepair)    $converted++;
        if ($r['classification'] === 'new_repair_lead') {
            $repairLeads++;
            if ($hasRepair) $repairConverted++;
        }
        $summary[] = [
            'call_id'         => $r['call_id'],
            'call_at'         => $r['call_at'],
            'caller_number'   => $r['caller_number'],
            'caller_name'     => $r['caller_name'],
            'call_status'     => $r['call_status'],
            'classification'  => $r['classification'],
            'duration_sec'    => $r['duration_seconds'],
            'location'        => $r['location'],
            'customer_found'  => $hasCustomer,
            'customer_name'   => $r['customer_name'],
            'converted_to_repair' => $hasRepair,
            'repair_id'       => $r['repair_id'],
            'repair_device'   => $hasRepair ? ($r['device_type'] . ' ' . $r['device_model']) : null,
            'repair_status'   => $r['repair_status'],
            'repair_value'    => $r['final_cost'],
            'repair_created'  => $r['repair_created_at'],
        ];
    }

    $convRate        = $total > 0         ? round($converted / $total * 100, 1)        : null;
    $leadConvRate    = $repairLeads > 0   ? round($repairConverted / $repairLeads * 100, 1) : null;

    $out['period']   = ['from' => $from, 'to' => $to, 'window_days' => $window];
    $out['summary_stats'] = [
        'total_inbound_calls'         => $total,
        'callers_matched_to_customer' => $total - $unmatched,
        'callers_not_in_system'       => $unmatched,
        'converted_to_repair'         => $converted,
        'overall_conversion_pct'      => $convRate,
        'repair_lead_calls'           => $repairLeads,
        'repair_leads_converted'      => $repairConverted,
        'repair_lead_conversion_pct'  => $leadConvRate,
        'note'                        => "Matched by phone_normalized = caller_number. Window = $window days after call.",
    ];
    $out['calls'] = $summary;
}

// ── BUSINESS INTELLIGENCE (per-location vs targets) ──────────
elseif ($report === 'bi') {

    $from = $_GET['from'] ?? $monthStart;
    $to   = $_GET['to']   ?? $monthEnd;

    // All active locations
    $locations = DB::query("SELECT id, name, code FROM locations WHERE is_active=1 ORDER BY name", []);

    // Targets (most recent monthly target per location)
    $targetRows = DB::query(
        "SELECT t.location_id, t.target_amount, t.target_repairs, t.target_sales, t.effective_from
         FROM sales_targets t
         INNER JOIN (
             SELECT location_id, MAX(effective_from) AS maxdate
             FROM sales_targets WHERE period_type='monthly'
             GROUP BY location_id
         ) latest ON latest.location_id=t.location_id AND latest.maxdate=t.effective_from
         WHERE t.period_type='monthly'", []);
    $targets = [];
    foreach ($targetRows as $t) $targets[$t['location_id']] = $t;

    $locData = [];

    foreach ($locations as $loc) {
        $lid = $loc['id'];
        $lSql  = "AND location_id=$lid";
        $lSqlS = "AND s.location_id=$lid";
        $lSqlR = "AND r.location_id=$lid";
        $lSqlRP = "AND location_id=$lid"; // repairs with no alias

        // Revenue
        $walkin = DB::queryOne(
            "SELECT COALESCE(SUM(subtotal),0) AS rev, COUNT(DISTINCT id) AS txns
             FROM sales WHERE sale_type='sale' AND DATE(created_at) BETWEEN ? AND ? $lSql",
            [$from,$to]);

        $activations = DB::queryOne(
            "SELECT COALESCE(SUM(plan_amount),0) AS rev, COALESCE(SUM(commission),0) AS commission, COUNT(*) AS cnt
             FROM activations WHERE activation_date BETWEEN ? AND ? $lSql",
            [$from,$to]);

        // Repair tickets — single source of truth for repair revenue (matches dashboard)
        $repairStats = DB::queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(status='completed') AS completed,
                    SUM(status='cancelled') AS cancelled,
                    SUM(status NOT IN ('completed','cancelled','non_repairable')) AS active,
                    COALESCE(SUM(CASE WHEN status='completed' THEN final_cost END),0) AS repair_revenue
             FROM repairs WHERE is_training=0 AND DATE(created_at) BETWEEN ? AND ? $lSqlRP",
            [$from,$to]);

        $totalRev = (float)$walkin['rev'] + (float)$repairStats['repair_revenue'] + (float)$activations['rev'];

        // Payment breakdown
        $payments = DB::query(
            "SELECT payment_method, COALESCE(SUM(subtotal),0) AS total
             FROM sales WHERE DATE(created_at) BETWEEN ? AND ? $lSql
             GROUP BY payment_method", [$from,$to]);
        $payMap = [];
        foreach ($payments as $p) $payMap[$p['payment_method']] = (float)$p['total'];

        // Staff revenue
        $staff = DB::query(
            "SELECT u.first_name, u.last_name,
                    COALESCE(SUM(CASE WHEN s.sale_type='sale' THEN s.subtotal END),0) AS walkin,
                    COALESCE(SUM(CASE WHEN s.sale_type IN ('repair_final','repair_deposit') THEN s.subtotal END),0) AS repairs
             FROM sales s JOIN users u ON u.id=s.created_by
             LEFT JOIN repairs r ON r.id=s.repair_id
             WHERE DATE(s.created_at) BETWEEN ? AND ?
               AND (r.id IS NULL OR r.is_training=0) $lSqlS
             GROUP BY s.created_by ORDER BY SUM(s.subtotal) DESC",
            [$from,$to]);

        // Calls + conversion
        $callStats = DB::queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(status='missed' AND direction='inbound') AS missed,
                    SUM(classification='new_repair_lead') AS repair_leads,
                    SUM(classification='unclassified') AS unclassified
             FROM call_logs cl WHERE cl.direction='inbound' AND DATE(cl.call_at) BETWEEN ? AND ?
               AND cl.is_training=0 AND cl.location_id=$lid",
            [$from,$to]);

        // Call-to-repair conversion by phone match
        $conv = DB::queryOne(
            "SELECT COUNT(DISTINCT cl.id) AS leads, COUNT(DISTINCT r.id) AS converted
             FROM call_logs cl
             LEFT JOIN customers c ON c.phone_normalized = cl.caller_number
             LEFT JOIN repairs r ON r.customer_id=c.id
               AND r.created_at >= cl.call_at
               AND r.created_at <= DATE_ADD(cl.call_at, INTERVAL 30 DAY)
               AND r.is_training=0
             WHERE cl.classification='new_repair_lead' AND cl.direction='inbound'
               AND DATE(cl.call_at) BETWEEN ? AND ? AND cl.is_training=0
               AND cl.location_id=$lid",
            [$from,$to]);

        // Active tickets right now
        $activeTickets = DB::query(
            "SELECT r.id, r.device_type, r.device_model, r.status, r.created_at,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name
             FROM repairs r JOIN customers c ON c.id=r.customer_id
             WHERE r.is_training=0
               AND r.status NOT IN ('completed','cancelled','non_repairable')
               AND r.location_id=?
             ORDER BY r.created_at ASC", [$lid]);

        // Target
        $target = $targets[$lid] ?? null;
        $targetAmt = $target ? (float)$target['target_amount'] : null;
        $pctOfTarget = ($targetAmt && $targetAmt > 0) ? round($totalRev / $targetAmt * 100, 1) : null;
        // Days in month projection
        $daysTotal   = (int)date('t', strtotime($from));
        $daysDone    = min((int)((strtotime($to) - strtotime($from)) / 86400) + 1, $daysTotal);
        $dailyAvg    = $daysDone > 0 ? $totalRev / $daysDone : 0;
        $projected   = round($dailyAvg * $daysTotal, 2);
        $gapToTarget = $targetAmt ? round($targetAmt - $totalRev, 2) : null;
        $daysLeft    = $daysTotal - $daysDone;

        $leads       = (int)($conv['leads'] ?? 0);
        $converted2  = (int)($conv['converted'] ?? 0);
        $convRate    = $leads > 0 ? round($converted2 / $leads * 100, 1) : null;

        $locData[] = [
            'location'       => $loc['name'],
            'location_id'    => $lid,
            'period'         => ['from'=>$from,'to'=>$to,'days_done'=>$daysDone,'days_left'=>$daysLeft],
            'revenue' => [
                'walkin'              => round((float)$walkin['rev'],2),
                'walkin_txns'         => (int)$walkin['txns'],
                'repairs'             => round((float)$repairStats['repair_revenue'],2),
                'activations_plans'   => round((float)$activations['rev'],2),
                'activations_commission' => round((float)$activations['commission'],2),
                'activation_cnt'      => (int)$activations['cnt'],
                'total'               => round($totalRev,2),
                'daily_avg'           => round($dailyAvg,2),
                'projected_month'     => $projected,
            ],
            'target' => [
                'monthly_target'   => $targetAmt,
                'pct_achieved'     => $pctOfTarget,
                'gap_remaining'    => $gapToTarget,
                'needed_per_day'   => $daysLeft > 0 && $gapToTarget > 0 ? round($gapToTarget/$daysLeft,2) : 0,
                'on_track'         => $pctOfTarget !== null ? ($projected >= $targetAmt) : null,
            ],
            'payments' => [
                'cash'      => $payMap['cash']       ?? 0,
                'card'      => $payMap['card']       ?? 0,
                'etransfer' => $payMap['e-transfer'] ?? 0,
            ],
            'repairs' => [
                'total'      => (int)$repairStats['total'],
                'completed'  => (int)$repairStats['completed'],
                'cancelled'  => (int)$repairStats['cancelled'],
                'active_now' => (int)$repairStats['active'],
                'revenue'    => round((float)$repairStats['repair_revenue'],2),
                'cancel_pct' => $repairStats['total'] > 0
                    ? round($repairStats['cancelled']/$repairStats['total']*100,1) : 0,
            ],
            'calls' => [
                'total_inbound'   => (int)$callStats['total'],
                'missed'          => (int)$callStats['missed'],
                'repair_leads'    => $leads,
                'leads_converted' => $converted2,
                'conversion_pct'  => $convRate,
                'unclassified'    => (int)$callStats['unclassified'],
            ],
            'staff'          => $staff,
            'active_tickets' => $activeTickets,
        ];
    }

    $out['period']    = ['from'=>$from,'to'=>$to];
    $out['locations'] = $locData;

    // Combined totals
    $combTotal = array_sum(array_column(array_column($locData,'revenue'),'total'));
    $combTarget= array_sum(array_filter(array_column(array_column($locData,'target'),'monthly_target')));
    $out['combined'] = [
        'total_revenue'  => round($combTotal,2),
        'total_target'   => $combTarget ?: null,
        'pct_of_target'  => $combTarget > 0 ? round($combTotal/$combTarget*100,1) : null,
    ];
}

elseif ($report === 'transactions') {
    // Individual sale transactions with line items, per location
    // Params: from, to (default today), location_id, type (walkin|repairs|all, default walkin)
    $txFrom = $_GET['from'] ?? $today;
    $txTo   = $_GET['to']   ?? $today;
    $txType = $_GET['type'] ?? 'walkin';

    if ($txType === 'walkin') {
        $typeSql = "AND s.sale_type = 'sale'";
    } elseif ($txType === 'repairs') {
        $typeSql = "AND s.sale_type IN ('repair_final','repair_deposit')";
    } else {
        $typeSql = "AND s.sale_type IN ('sale','repair_final','repair_deposit')";
    }

    // Fetch transactions
    $txRows = DB::query(
        "SELECT s.id, s.record_number, s.sale_type, s.subtotal, s.tax_amount,
                s.discount_amount, s.total_amount, s.payment_method, s.notes,
                s.created_at, l.name AS location,
                CONCAT(u.first_name,' ',u.last_name) AS staff
         FROM sales s
         JOIN locations l ON l.id = s.location_id
         JOIN users u ON u.id = s.created_by
         WHERE DATE(s.created_at) BETWEEN ? AND ?
           $typeSql $locSql
         ORDER BY s.created_at DESC",
        [$txFrom, $txTo]
    );

    // For each transaction, fetch line items
    $transactions = [];
    foreach ($txRows as $tx) {
        $items = DB::query(
            "SELECT item_name, item_sku, quantity, unit_price, line_total
             FROM sale_items WHERE sale_id = ? ORDER BY id",
            [$tx['id']]
        );
        $transactions[] = [
            'id'             => (int)$tx['id'],
            'record_number'  => $tx['record_number'],
            'sale_type'      => $tx['sale_type'],
            'location'       => $tx['location'],
            'staff'          => trim($tx['staff']),
            'payment_method' => $tx['payment_method'],
            'subtotal'       => (float)$tx['subtotal'],
            'tax'            => (float)$tx['tax_amount'],
            'discount'       => (float)$tx['discount_amount'],
            'total'          => (float)$tx['total_amount'],
            'notes'          => $tx['notes'],
            'time'           => $tx['created_at'],
            'items'          => array_map(fn($i) => [
                'name'        => $i['item_name'],
                'sku'         => $i['item_sku'],
                'qty'         => (int)$i['quantity'],
                'unit_price'  => (float)$i['unit_price'],
                'line_total'  => (float)$i['line_total'],
            ], $items),
        ];
    }

    $out['period']       = ['from' => $txFrom, 'to' => $txTo, 'type' => $txType];
    $out['location']     = $locId ? "Location $locId" : 'All locations';
    $out['count']        = count($transactions);
    $out['transactions'] = $transactions;
}

elseif ($report === 'expenses') {
    $expFrom = $_GET['from'] ?? date('Y-m-01');
    $expTo   = $_GET['to']   ?? date('Y-m-t');

    // Totals
    $expTotal = DB::queryOne(
        "SELECT COALESCE(SUM(e.amount),0) AS total, COUNT(*) AS cnt
         FROM expenses e
         WHERE e.expense_date BETWEEN ? AND ? AND e.deleted_at IS NULL $locSql",
        [$expFrom, $expTo]);

    // By category
    $expByCat = DB::query(
        "SELECT ec.name AS category, COUNT(*) AS cnt,
                COALESCE(SUM(e.amount),0) AS total
         FROM expenses e
         JOIN expense_categories ec ON ec.id = e.category_id
         WHERE e.expense_date BETWEEN ? AND ? AND e.deleted_at IS NULL $locSql
         GROUP BY ec.name ORDER BY total DESC",
        [$expFrom, $expTo]);

    // By location
    $expByLoc = DB::query(
        "SELECT l.name AS location, COUNT(*) AS cnt,
                COALESCE(SUM(e.amount),0) AS total
         FROM expenses e
         JOIN locations l ON l.id = e.location_id
         WHERE e.expense_date BETWEEN ? AND ? AND e.deleted_at IS NULL $locSql
         GROUP BY l.name ORDER BY total DESC",
        [$expFrom, $expTo]);

    // Individual expenses
    $expList = DB::query(
        "SELECT e.expense_date, e.amount, e.description, e.vendor_name,
                e.payment_method_label, e.has_receipt,
                ec.name AS category, l.name AS location,
                CONCAT(u.first_name,' ',u.last_name) AS logged_by
         FROM expenses e
         JOIN expense_categories ec ON ec.id = e.category_id
         JOIN locations l ON l.id = e.location_id
         JOIN users u ON u.id = e.created_by
         WHERE e.expense_date BETWEEN ? AND ? AND e.deleted_at IS NULL $locSql
         ORDER BY e.expense_date DESC, e.id DESC",
        [$expFrom, $expTo]);

    $out['period']      = ['from' => $expFrom, 'to' => $expTo];
    $out['totals']      = ['amount' => round((float)$expTotal['total'],2), 'count' => (int)$expTotal['cnt']];
    $out['by_category'] = $expByCat;
    $out['by_location'] = $expByLoc;
    $out['expenses']    = $expList;
}

else {
    http_response_code(400);
    $out['error'] = "Unknown report. Available: summary, sales, repairs, inventory, cash, staff, calls, call_audit, bi, transactions, expenses";
}

} catch (Throwable $e) {
    // Catch any DB or PHP error and return it as JSON instead of empty response
    $out['error']   = $e->getMessage();
    $out['errline'] = $e->getLine();
    $out['errtrace'] = substr($e->getTraceAsString(), 0, 500);
}

ob_end_clean();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
