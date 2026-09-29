<?php
// ============================================================
// Printable Repair Label (opens in new tab, auto-prints)
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$repairId = intval($_GET['id'] ?? 0);
$repair = DB::queryOne(
    "SELECT r.*, c.first_name, c.last_name, c.phone_primary AS phone, l.name AS location_name, l.code AS location_code,
            CONCAT(u.first_name,' ',u.last_name) AS tech_name
     FROM repairs r
     JOIN customers c ON r.customer_id = c.id
     JOIN locations l ON r.location_id = l.id
     LEFT JOIN users u ON r.assigned_to = u.id
     WHERE r.id = ? LIMIT 1",
    [$repairId]
);

if (!$repair) { echo 'Repair not found.'; exit; }

$statusUrl = APP_URL . '/repair-status.php?t=' . $repair['status_token'];
$qrUrl     = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($statusUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Label — <?= htmlspecialchars($repair['record_number']) ?></title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, sans-serif; background: #fff; color: #000; }

  .label {
    width: 4in;
    min-height: 2.5in;
    padding: .25in;
    border: 2px solid #000;
    display: flex;
    gap: .25in;
    page-break-after: always;
  }

  .label-qr img { width: 1.1in; height: 1.1in; }
  .label-qr .scan-hint { font-size: 7pt; text-align: center; margin-top: 4px; color: #555; }

  .label-info { flex: 1; }
  .label-shop { font-size: 9pt; font-weight: bold; color: #333; margin-bottom: 4px; }
  .label-ticket { font-size: 15pt; font-weight: bold; letter-spacing: .5px; }
  .label-device { font-size: 10pt; font-weight: bold; margin: 4px 0 2px; }
  .label-customer { font-size: 9pt; color: #333; }
  .label-issue { font-size: 8pt; color: #555; margin-top: 6px; border-top: 1px dashed #ccc; padding-top: 4px; }
  .label-meta { font-size: 7.5pt; color: #555; margin-top: 6px; }
  .label-date { font-size: 8pt; margin-top: 4px; }

  @media print {
    body { -webkit-print-color-adjust: exact; }
  }
</style>
</head>
<body onload="window.print()">

<div class="label">
    <div class="label-qr">
        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR">
        <div class="scan-hint">Scan for status</div>
    </div>
    <div class="label-info">
        <div class="label-shop">C Tech Fix — <?= htmlspecialchars($repair['location_name']) ?></div>
        <div class="label-ticket"><?= htmlspecialchars($repair['record_number']) ?></div>
        <div class="label-device"><?= htmlspecialchars($repair['device_brand'] . ' ' . $repair['device_model']) ?>
            <?= $repair['device_color'] ? '(' . htmlspecialchars($repair['device_color']) . ')' : '' ?>
        </div>
        <div class="label-customer">
            <?= htmlspecialchars($repair['first_name'] . ' ' . $repair['last_name']) ?>
            · <?= htmlspecialchars($repair['phone']) ?>
        </div>
        <?php if ($repair['issue_description']): ?>
        <div class="label-issue"><?= htmlspecialchars(mb_strimwidth($repair['issue_description'], 0, 100, '...')) ?></div>
        <?php endif; ?>
        <div class="label-meta">
            <?php if ($repair['tech_name']): ?>Tech: <?= htmlspecialchars($repair['tech_name']) ?> · <?php endif; ?>
            <?php if ($repair['estimated_cost']): ?>Est: $<?= number_format($repair['estimated_cost'], 2) ?> · <?php endif; ?>
            <?php if ($repair['accessories']): ?>With: <?= htmlspecialchars($repair['accessories']) ?><?php endif; ?>
        </div>
        <div class="label-date">Received: <?= date('M j, Y g:ia', strtotime($repair['received_at'])) ?></div>
    </div>
</div>

</body>
</html>
