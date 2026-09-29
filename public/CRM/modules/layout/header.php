<?php
// ============================================================
// Shared Layout Header
// Requires: $pageTitle (string), Auth::boot() already called
// ============================================================
$_layoutUser     = Auth::user();
$_layoutTraining = Auth::isTraining();
$_layoutLocation = null;
if ($_layoutUser['location_id']) {
    $_layoutLocation = DB::queryOne('SELECT * FROM locations WHERE id = ? LIMIT 1', [$_layoutUser['location_id']]);
}
$_layoutRole = strtolower($_layoutUser['role'] ?? 'staff');

// Unread SMS count for alert badge
$_smsAlertWhere  = ['m.needs_reply = 1', 'm.direction = ?'];
$_smsAlertParams = ['inbound'];
if (!Auth::isOwner() && !Auth::isManager()) {
    $_smsAlertWhere[]  = 'm.location_id = ?';
    $_smsAlertParams[] = $_layoutUser['location_id'];
}
$_unreadSms = DB::queryOne(
    'SELECT COUNT(*) AS cnt FROM sms_messages m WHERE ' . implode(' AND ', $_smsAlertWhere),
    $_smsAlertParams
);
$_unreadSmsCount = intval($_unreadSms['cnt'] ?? 0);

// Overdue follow-ups (due_at has passed, still pending/in_progress)
$_overdueFollowups = 0;
try {
    $_fuWhere  = ["f.status IN ('pending','in_progress')", "f.due_at <= NOW()"];
    if (!Auth::isOwner() && !Auth::isManager()) {
        $_fuWhere[] = "f.location_id = " . intval($_layoutUser['location_id']);
    }
    $_overdueFollowups = intval(DB::queryOne(
        "SELECT COUNT(*) AS cnt FROM follow_ups f WHERE " . implode(' AND ', $_fuWhere)
    )['cnt'] ?? 0);
} catch (\Throwable $_e) {}

// Overdue inquiries (pending > 1 hour)
$_overdueInquiries = 0;
try {
    $_inqWhere  = ["status='pending'", "created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)"];
    if (!Auth::isOwner() && !Auth::isManager()) {
        $_inqWhere[] = "location_id = " . intval($_layoutUser['location_id']);
    }
    $_overdueInquiries = intval(DB::queryOne(
        "SELECT COUNT(*) AS cnt FROM walk_in_inquiries WHERE " . implode(' AND ', $_inqWhere)
    )['cnt'] ?? 0);
} catch (\Throwable $_e) {}

$_totalUrgent = $_overdueFollowups + $_overdueInquiries;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="color-scheme" content="light">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'C Tech Fix CRM') ?> — C Tech Fix</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body class="<?= $_layoutTraining ? 'training-mode' : '' ?>">

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<aside class="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-brand">C <span>Tech</span> Fix</div>
    <div class="sidebar-role">
      <?= $_layoutLocation ? htmlspecialchars($_layoutLocation['name']) : ucfirst($_layoutRole) ?>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Operations</div>
    <a href="<?= APP_URL ?>/modules/dashboard/<?= $_layoutRole ?>.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/dashboard/') ? 'active' : '' ?>">
        <span class="nav-icon">📊</span> Dashboard
    </a>
    <a href="<?= APP_URL ?>/modules/repairs/" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/repairs') ? 'active' : '' ?>">
        <span class="nav-icon">🔧</span> Repairs
    </a>
    <a href="<?= APP_URL ?>/modules/calls/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/calls') && !str_contains($_SERVER['REQUEST_URI'], 'inbox') && !str_contains($_SERVER['REQUEST_URI'], 'followups') ? 'active' : '' ?>">
        <span class="nav-icon">📞</span> Calls & SMS
    </a>
    <a href="<?= APP_URL ?>/modules/calls/followups.php?filter=open" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], 'followups') ? 'active' : '' ?>"
       style="<?= $_overdueFollowups > 0 ? 'color:var(--red);font-weight:600;' : '' ?>">
        <span class="nav-icon">📋</span> Follow-ups
        <?php if ($_overdueFollowups > 0): ?>
        <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:auto;"><?= $_overdueFollowups ?></span>
        <?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/modules/calls/inbox.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], 'inbox') ? 'active' : '' ?>" style="<?= $_unreadSmsCount > 0 ? 'color:var(--red);font-weight:600;' : '' ?>">
        <span class="nav-icon">📱</span> SMS Inbox
        <?php if ($_unreadSmsCount > 0): ?>
        <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:auto;"><?= $_unreadSmsCount ?></span>
        <?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/modules/customers/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/customers') ? 'active' : '' ?>">
        <span class="nav-icon">👥</span> Customers
    </a>
    <?php
    $_bookingPending = DB::queryOne(
        "SELECT COUNT(*) AS cnt FROM bookings WHERE status='pending'" .
        (!Auth::isOwner() && !Auth::isManager() ? " AND location_id={$_layoutUser['location_id']}" : '')
    )['cnt'] ?? 0;
    ?>
    <a href="<?= APP_URL ?>/modules/bookings/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/bookings') ? 'active' : '' ?>" style="<?= $_bookingPending > 0 ? 'color:var(--amber);font-weight:600;' : '' ?>">
        <span class="nav-icon">📅</span> Bookings
        <?php if ($_bookingPending > 0): ?>
        <span style="background:var(--amber);color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:auto;"><?= $_bookingPending ?></span>
        <?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/modules/sales/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/sales') ? 'active' : '' ?>">
        <span class="nav-icon">🛒</span> Sales
    </a>
    <a href="<?= APP_URL ?>/modules/activations/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/activations') ? 'active' : '' ?>">
        <span class="nav-icon">📡</span> Activations
    </a>
    <?php
    $_pendingInquiries = 0;
    try {
        $_pendingInquiries = intval(DB::queryOne(
            "SELECT COUNT(*) AS cnt FROM walk_in_inquiries WHERE status='pending'" .
            (!Auth::isOwner() && !Auth::isManager() ? " AND location_id={$_layoutUser['location_id']}" : '')
        )['cnt'] ?? 0);
    } catch (\Throwable $_e) {}
    ?>
    <a href="<?= APP_URL ?>/modules/inquiries/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/inquiries') ? 'active' : '' ?>"
       style="<?= $_pendingInquiries > 0 ? 'color:var(--amber);font-weight:600;' : '' ?>">
        <span class="nav-icon">🚶</span> Inquiries
        <?php if ($_pendingInquiries > 0): ?>
        <span style="background:var(--amber);color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:auto;"><?= $_pendingInquiries ?></span>
        <?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/modules/inventory/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/inventory') && !str_contains($_SERVER['REQUEST_URI'], 'count') ? 'active' : '' ?>">
        <span class="nav-icon">📦</span> Inventory
    </a>
    <a href="<?= APP_URL ?>/modules/inventory/count.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/inventory/count') ? 'active' : '' ?>">
        <span class="nav-icon">📋</span> Count Schedule
    </a>
    <a href="<?= APP_URL ?>/modules/used-devices/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/used-devices') ? 'active' : '' ?>">
        <span class="nav-icon">📱</span> Used Devices
    </a>
    <a href="<?= APP_URL ?>/modules/maintenance/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/maintenance') ? 'active' : '' ?>">
        <span class="nav-icon">🔧</span> Maintenance
    </a>
    <?php if (Auth::isOwner() || Auth::isManager()): ?>
    <a href="<?= APP_URL ?>/modules/cash/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/modules/cash/') ? 'active' : '' ?>">
        <span class="nav-icon">💵</span> Cash Drawer
    </a>
    <?php endif; ?>
    <?php if (Auth::isOwner() || Auth::isManager()): ?>
    <a href="<?= APP_URL ?>/modules/cash_accounts/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/cash_accounts') ? 'active' : '' ?>">
        <span class="nav-icon">💰</span> Cash Tracker
    </a>
    <a href="<?= APP_URL ?>/modules/expenses/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/expenses') ? 'active' : '' ?>">
        <span class="nav-icon">💸</span> Expenses
    </a>
    <a href="<?= APP_URL ?>/modules/activations/pipeline.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/activations/pipeline') ? 'active' : '' ?>">
        <span class="nav-icon">💰</span> Commission Pipeline
    </a>
    <div class="nav-section-label">Management</div>
    <a href="<?= APP_URL ?>/modules/reports/" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/reports') ? 'active' : '' ?>">
        <span class="nav-icon">📈</span> Reports
    </a>
    <a href="<?= APP_URL ?>/modules/staff/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/staff/index') ? 'active' : '' ?>">
        <span class="nav-icon">👤</span> Staff
    </a>
    <a href="<?= APP_URL ?>/modules/staff/attendance.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/staff/attendance') ? 'active' : '' ?>">
        <span class="nav-icon">🕐</span> Attendance
    </a>
    <a href="<?= APP_URL ?>/modules/staff/paysheet.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/staff/paysheet') ? 'active' : '' ?>">
        <span class="nav-icon">💰</span> Paysheet
    </a>
    <?php endif; ?>
    <?php if (Auth::isStaff() || Auth::isTraining()): ?>
    <a href="<?= APP_URL ?>/modules/staff/clock.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/staff/clock') ? 'active' : '' ?>">
        <span class="nav-icon">🕐</span> My Clock
    </a>
    <?php endif; ?>
    <?php if (Auth::isOwner()): ?>
    <a href="<?= APP_URL ?>/modules/settings/index.php" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/settings') ? 'active' : '' ?>">
        <span class="nav-icon">⚙️</span> Settings
    </a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <a href="<?= APP_URL ?>/modules/auth/checkout.php" class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($_layoutUser['first_name'], 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($_layoutUser['first_name']) ?></div>
        <div class="user-role"><?= ucfirst($_layoutRole) ?> · <?= htmlspecialchars($_SESSION['working_location_name'] ?? 'Not checked in') ?> · Clock Out</div>
      </div>
    </a>
  </div>
</aside>

<div class="main-content">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
      <span class="topbar-title"><?= htmlspecialchars($pageTitle ?? '') ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if ($_totalUrgent > 0):
          // Build label
          $urgentParts = [];
          if ($_overdueFollowups > 0) $urgentParts[] = $_overdueFollowups . ' follow-up' . ($_overdueFollowups !== 1 ? 's' : '') . ' overdue';
          if ($_overdueInquiries > 0) $urgentParts[] = $_overdueInquiries . ' inquiry' . ($_overdueInquiries !== 1 ? ' inquiries' : '') . ' waiting &gt;1hr';
          // Link: prefer follow-ups if overdue, else inquiries
          $urgentLink = $_overdueFollowups > 0
              ? APP_URL . '/modules/calls/followups.php?filter=open'
              : APP_URL . '/modules/inquiries/index.php?status=pending';
      ?>
      <a href="<?= $urgentLink ?>"
         class="urgent-flash-badge"
         style="display:inline-flex;align-items:center;gap:6px;background:#f59e0b;color:#fff;border:2px solid #d97706;border-radius:20px;padding:4px 14px;font-size:13px;font-weight:700;text-decoration:none;">
          🚨 <?= implode(' · ', $urgentParts) ?>
      </a>
      <style>
      @keyframes urgentPulse {
          0%,100% { opacity:1; transform:scale(1); }
          50%      { opacity:.7; transform:scale(1.05); }
      }
      .urgent-flash-badge { animation: urgentPulse 1.4s ease-in-out infinite; }
      </style>
      <?php endif; ?>
      <?php if ($_unreadSmsCount > 0): ?>
      <a href="<?= APP_URL ?>/modules/calls/inbox.php" style="position:relative;display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:20px;padding:4px 12px;font-size:13px;font-weight:600;text-decoration:none;">
          📱 <?= $_unreadSmsCount ?> new message<?= $_unreadSmsCount !== 1 ? 's' : '' ?>
      </a>
      <?php endif; ?>
      <?php if ($_layoutTraining): ?>
      <span class="badge badge-warning" style="font-size:.75rem;">⚠ Training Mode</span>
      <?php endif; ?>
    </div>
  </header>
  <div class="page-content">
