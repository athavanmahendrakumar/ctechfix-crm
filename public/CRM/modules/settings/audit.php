<?php
require_once dirname(__DIR__, 4) . '/core/Auth.php';
Auth::boot();
Auth::requireRole('owner', 'manager');
$user = Auth::user();
$isOwner = Auth::isOwner();

// Managers see operational audit, Owner sees all
$whereClause = $isOwner ? '' : "WHERE a.module NOT IN ('auth','users','settings','permissions')";

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$logs = DB::query(
    "SELECT a.*, u.username, u.first_name, u.last_name, l.code AS loc_code
       FROM audit_log a
       LEFT JOIN users u ON u.id = a.user_id
       LEFT JOIN locations l ON l.id = a.location_id
       {$whereClause}
       ORDER BY a.created_at DESC
       LIMIT {$perPage} OFFSET {$offset}"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Audit Log — C Tech Fix</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>
<div class="sidebar-overlay"></div>
<div class="main-content" style="margin-left:0;">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <span class="topbar-title">🔍 Audit Log</span>
    </div>
    <div class="topbar-actions">
      <a href="<?= APP_URL ?>/modules/dashboard/<?= $user['role'] ?>.php" class="btn btn-ghost btn-sm">← Dashboard</a>
    </div>
  </header>
  <main class="page-content">
    <div class="card">
      <?php if (empty($logs)): ?>
        <p style="text-align:center;padding:40px;color:var(--text-muted);">No audit records yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>User</th>
                <th>Location</th>
                <th>Module</th>
                <th>Action</th>
                <th>Record ID</th>
                <th>Training</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log): ?>
              <tr>
                <td style="white-space:nowrap;font-size:12px;color:var(--text-muted);">
                  <?= e($log['created_at']) ?>
                </td>
                <td><?= $log['username'] ? e($log['first_name'] . ' ' . $log['last_name']) : '<em>System</em>' ?></td>
                <td>
                  <?php if ($log['loc_code']): ?>
                    <span class="loc-<?= strtolower(e($log['loc_code'])) ?>"><?= e($log['loc_code']) ?></span>
                  <?php else: ?>
                    <span style="color:var(--text-muted);">—</span>
                  <?php endif; ?>
                </td>
                <td><span style="color:var(--text-muted);font-size:12px;"><?= e($log['module']) ?></span></td>
                <td><strong><?= e($log['action']) ?></strong></td>
                <td style="color:var(--text-muted);"><?= $log['record_id'] ?? '—' ?></td>
                <td><?= $log['is_training'] ? '<span class="training-badge">T</span>' : '' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:13px;color:var(--text-muted);">Showing page <?= $page ?> · <?= count($logs) ?> entries</span>
          <div style="display:flex;gap:8px;">
            <?php if ($page > 1): ?>
              <a href="?page=<?= $page - 1 ?>" class="btn btn-ghost btn-sm">← Prev</a>
            <?php endif; ?>
            <?php if (count($logs) === $perPage): ?>
              <a href="?page=<?= $page + 1 ?>" class="btn btn-ghost btn-sm">Next →</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
