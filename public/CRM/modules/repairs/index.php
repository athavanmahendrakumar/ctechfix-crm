<?php
// ============================================================
// Repair Queue
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user     = Auth::user();
$training = Auth::isTraining();

// ── Filters ─────────────────────────────────────────────────
$filterStatus   = $_GET['status']   ?? '';
$filterLocation = $_GET['location'] ?? ($user['location_id'] ?? '');
$filterSearch   = trim($_GET['q']   ?? '');
$filterDate     = $_GET['date']     ?? '';

// Build query
$where  = ['1=1'];
$params = [];

if (!Auth::isOwner() && !Auth::isManager()) {
    $where[]  = 'r.location_id = ?';
    $params[] = $user['location_id'];
} elseif ($filterLocation) {
    $where[]  = 'r.location_id = ?';
    $params[] = $filterLocation;
}

if ($filterStatus) {
    $where[]  = 'r.status = ?';
    $params[] = $filterStatus;
} else {
    $where[] = "r.status NOT IN ('completed','cancelled')";
}

if ($filterSearch) {
    $where[]  = '(r.record_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone_primary LIKE ? OR r.device_model LIKE ?)';
    $s = '%' . $filterSearch . '%';
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}

if ($filterDate === 'today') {
    $where[] = 'DATE(r.received_at) = CURDATE()';
} elseif ($filterDate === 'week') {
    $where[] = 'r.received_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
}

if (!$training) {
    $where[] = 'r.is_training = 0';
}

$whereStr = implode(' AND ', $where);

$repairs = DB::query(
    "SELECT r.*,
            c.first_name, c.last_name, c.phone_primary AS phone,
            l.name AS location_name, l.code AS location_code,
            u.first_name AS tech_name
     FROM repairs r
     JOIN customers c  ON r.customer_id  = c.id
     JOIN locations l  ON r.location_id  = l.id
     LEFT JOIN users u ON r.assigned_to  = u.id
     WHERE {$whereStr}
     ORDER BY
        CASE r.status
            WHEN 'ready_pickup'   THEN 1
            WHEN 'waiting_parts'  THEN 2
            WHEN 'in_repair'      THEN 3
            WHEN 'diagnosed'      THEN 4
            WHEN 'received'       THEN 5
            ELSE 6
        END,
        r.received_at DESC
     LIMIT 200",
    $params
);

$counts = DB::query(
    "SELECT status, COUNT(*) AS cnt FROM repairs
     WHERE 1=1
     " . (!$training ? "AND is_training=0" : "") . "
     AND status NOT IN ('completed','cancelled')
     GROUP BY status",
    []
);
$countMap = [];
foreach ($counts as $row) $countMap[$row['status']] = $row['cnt'];

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$statusLabels = [
    'received'      => 'Received',
    'diagnosed'     => 'Diagnosed',
    'in_repair'     => 'In Repair',
    'waiting_parts' => 'Waiting Parts',
    'ready_pickup'  => 'Ready for Pickup',
    'completed'     => 'Completed',
    'cancelled'     => 'Cancelled',
];
$statusColors = [
    'received'      => 'badge-secondary',
    'diagnosed'     => 'badge-info',
    'in_repair'     => 'badge-primary',
    'waiting_parts' => 'badge-warning',
    'ready_pickup'  => 'badge-success',
    'completed'     => 'badge-muted',
    'cancelled'     => 'badge-danger',
];

$pageTitle = 'Repairs';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Repair Queue</h1>
        <p class="page-sub"><?= count($repairs) ?> ticket<?= count($repairs) !== 1 ? 's' : '' ?> shown</p>
    </div>
    <a href="<?= APP_URL ?>/modules/repairs/new.php" class="btn btn-primary">+ New Repair</a>
</div>

<form method="GET" class="filter-bar">
    <input type="text" name="q" class="form-control filter-search"
           placeholder="Search name, phone, ticket #, device..."
           value="<?= htmlspecialchars($filterSearch) ?>">

    <select name="status" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Active</option>
        <?php foreach ($statusLabels as $val => $label): ?>
        <option value="<?= $val ?>" <?= $filterStatus === $val ? 'selected' : '' ?>>
            <?= $label ?> <?= isset($countMap[$val]) ? '(' . $countMap[$val] . ')' : '' ?>
        </option>
        <?php endforeach; ?>
    </select>

    <?php if (Auth::isOwner() || Auth::isManager()): ?>
    <select name="location" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLocation == $loc['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($loc['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="date" class="form-control filter-select" onchange="this.form.submit()">
        <option value=""      <?= $filterDate===''      ?'selected':'' ?>>All Time</option>
        <option value="today" <?= $filterDate==='today' ?'selected':'' ?>>Today</option>
        <option value="week"  <?= $filterDate==='week'  ?'selected':'' ?>>Last 7 Days</option>
    </select>

    <button type="submit" class="btn btn-secondary">Search</button>
    <a href="?" class="btn btn-ghost">Clear</a>
</form>

<div class="status-strip">
    <?php foreach ($countMap as $st => $cnt): ?>
    <a href="?status=<?= $st ?>" class="status-chip <?= $filterStatus === $st ? 'active' : '' ?>">
        <span class="badge <?= $statusColors[$st] ?? 'badge-secondary' ?>"><?= $cnt ?></span>
        <?= $statusLabels[$st] ?? $st ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($repairs)): ?>
<div class="empty-state">
    <div class="empty-icon">🔧</div>
    <p>No repairs found<?= $filterSearch ? ' matching "' . htmlspecialchars($filterSearch) . '"' : '' ?>.</p>
    <a href="new.php" class="btn btn-primary">Open First Repair Ticket</a>
</div>
<?php else: ?>
<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr>
            <th>Ticket #</th>
            <th>Customer</th>
            <th>Device</th>
            <th>Issue</th>
            <th>Status</th>
            <th>Tech</th>
            <th>Loc</th>
            <th>Received</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($repairs as $r): ?>
    <tr class="<?= $r['status'] === 'ready_pickup' ? 'row-highlight' : '' ?>">
        <td>
            <a href="view.php?id=<?= $r['id'] ?>" class="record-number">
                <?= htmlspecialchars($r['record_number']) ?>
            </a>
        </td>
        <td>
            <strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></strong><br>
            <span class="text-muted small"><?= htmlspecialchars($r['phone']) ?></span>
        </td>
        <td>
            <?= htmlspecialchars($r['device_brand'] . ' ' . $r['device_model']) ?>
            <?php if (!empty($r['service_type'])): ?>
            <div style="font-size:11px;color:var(--text-3);margin-top:2px;"><?= htmlspecialchars($r['service_type']) ?></div>
            <?php endif; ?>
        </td>
        <td class="truncate" style="max-width:200px;" title="<?= htmlspecialchars($r['issue_description']) ?>">
            <?= htmlspecialchars($r['issue_description']) ?>
        </td>
        <td>
            <span class="badge <?= $statusColors[$r['status']] ?? 'badge-secondary' ?>">
                <?= $statusLabels[$r['status']] ?? $r['status'] ?>
            </span>
        </td>
        <td><?= $r['tech_name'] ? htmlspecialchars($r['tech_name']) : '<span class="text-muted">—</span>' ?></td>
        <td><span class="badge badge-loc"><?= htmlspecialchars($r['location_code']) ?></span></td>
        <td class="text-muted small"><?= timeAgo($r['received_at']) ?></td>
        <td><a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
