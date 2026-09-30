<?php
// ============================================================
// Customers — List & Search
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$search         = trim($_GET['q']           ?? '');
$filterLocation = intval($_GET['location_id'] ?? 0);

$where  = ['c.is_training = 0'];
$params = [];

if ($search) {
    $s        = '%' . $search . '%';
    $where[]  = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone_primary LIKE ? OR c.email LIKE ?)';
    $params   = array_merge($params, [$s, $s, $s, $s]);
}
if ($filterLocation) {
    $where[]  = 'c.location_id = ?';
    $params[] = $filterLocation;
}
// Managers and staff see their location only
if (Auth::isManager()) {
    $where[]  = 'c.location_id = ?';
    $params[] = Auth::workingLocationId();
}
if (Auth::isStaff()) {
    $where[]  = 'c.location_id = ?';
    $params[] = $user['location_id'];
}

$customers = DB::query(
    "SELECT c.*,
            l.name AS location_name, l.code AS location_code,
            COUNT(DISTINCT r.id) AS repair_count,
            MAX(r.created_at)    AS last_repair
     FROM customers c
     LEFT JOIN locations l ON c.location_id = l.id
     LEFT JOIN repairs r   ON r.customer_id = c.id AND r.is_training = 0
     WHERE " . implode(' AND ', $where) . "
     GROUP BY c.id
     ORDER BY c.first_name, c.last_name
     LIMIT 200",
    $params
);

$pageTitle = 'Customers';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Customers</h1>
        <p class="page-sub"><?= count($customers) ?> customer<?= count($customers) !== 1 ? 's' : '' ?><?= $search ? ' matching "' . htmlspecialchars($search) . '"' : '' ?></p>
    </div>
</div>

<form method="GET" class="filter-bar">
    <input type="text" name="q" class="form-control filter-search"
           placeholder="Search name, phone, email..."
           value="<?= htmlspecialchars($search) ?>">

    <?php if (Auth::isOwner()): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLocation == $loc['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($loc['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <button type="submit" class="btn btn-secondary">Search</button>
    <a href="?" class="btn btn-ghost">Clear</a>
</form>

<?php if (empty($customers)): ?>
<div class="empty-state">
    <div class="empty-icon">👥</div>
    <p>No customers found<?= $search ? ' for that search' : '' ?>.</p>
</div>
<?php else: ?>
<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Location</th>
            <th>Repairs</th>
            <th>Last Visit</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
    <tr style="cursor:pointer;" onclick="window.location='view.php?id=<?= $c['id'] ?>'">
        <td>
            <strong><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></strong>
        </td>
        <td><?= htmlspecialchars($c['phone_primary'] ?? '—') ?></td>
        <td class="text-muted small"><?= htmlspecialchars($c['email'] ?? '—') ?></td>
        <td>
            <?php if ($c['location_code']): ?>
            <span class="badge badge-loc"><?= htmlspecialchars($c['location_code']) ?></span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td>
            <span class="badge badge-secondary"><?= $c['repair_count'] ?></span>
        </td>
        <td class="text-muted small">
            <?= $c['last_repair'] ? timeAgo($c['last_repair']) : 'Never' ?>
        </td>
        <td><a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
