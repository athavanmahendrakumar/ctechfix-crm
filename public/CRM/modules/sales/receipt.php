<?php
// ============================================================
// Sales Receipt — Print View (receipt-printer optimised)
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$saleId = intval($_GET['id'] ?? 0);
$sale   = DB::queryOne(
    "SELECT s.*, l.name AS location_name, l.code AS location_code, u.first_name AS sold_by
     FROM sales s
     JOIN locations l ON s.location_id = l.id
     LEFT JOIN users u ON s.created_by = u.id
     WHERE s.id = ?",
    [$saleId]
);
if (!$sale) { header('Location: ' . APP_URL . '/modules/sales/'); exit; }

$items = DB::query('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$saleId]);

// Load settings
$global  = [];
$rows = DB::query('SELECT setting_key, value FROM settings WHERE location_id IS NULL', []);
foreach ($rows as $r) $global[$r['setting_key']] = $r['value'];

$locSettings = [];
$lrows = DB::query('SELECT setting_key, value FROM settings WHERE location_id = ?', [$sale['location_id']]);
foreach ($lrows as $r) $locSettings[$r['setting_key']] = $r['value'];

$businessName = $global['business_name'] ?? 'C Tech Fix';
$hstNumber    = $global['hst_number']    ?? '';

// Per-location overrides global logo; fall back to global
$logoFile = $locSettings['logo_path'] ?? ($global['logo_path'] ?? '');
$logoUrl  = $logoFile ? APP_URL . '/uploads/logos/' . htmlspecialchars($logoFile) : '';
$address  = $locSettings['location_address'] ?? '';
$phone    = $locSettings['location_phone']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt <?= htmlspecialchars($sale['record_number']) ?></title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Courier New', Courier, monospace;
      font-size: 13px;
      background: #fff;
      color: #000;
      display: flex;
      justify-content: center;
      padding: 20px 0;
    }

    .receipt {
      width: 300px;
    }

    /* ── Header ── */
    .receipt-header {
      text-align: center;
      margin-bottom: 10px;
    }
    .receipt-logo {
      max-width: 140px;
      max-height: 70px;
      object-fit: contain;
      margin-bottom: 6px;
    }
    .receipt-biz-name {
      font-size: 16px;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 4px;
    }
    .receipt-loc {
      font-size: 11px;
      line-height: 1.5;
      color: #333;
    }

    /* ── Divider ── */
    .divider {
      border: none;
      border-top: 1px dashed #000;
      margin: 8px 0;
    }
    .divider-solid {
      border: none;
      border-top: 2px solid #000;
      margin: 8px 0;
    }

    /* ── Meta row ── */
    .meta-row {
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      margin-bottom: 3px;
    }
    .meta-label { color: #555; }

    /* ── Items ── */
    .items { width: 100%; margin: 6px 0; }
    .item-row { margin-bottom: 6px; }
    .item-name { font-weight: bold; font-size: 12px; }
    .item-detail {
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      color: #444;
    }

    /* ── Totals ── */
    .total-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 3px;
      font-size: 12px;
    }
    .total-row.discount { color: #c00; }
    .total-row.grand {
      font-size: 16px;
      font-weight: bold;
      margin-top: 5px;
      padding-top: 5px;
      border-top: 2px solid #000;
    }

    /* ── Footer ── */
    .receipt-footer {
      text-align: center;
      font-size: 11px;
      margin-top: 10px;
      color: #333;
      line-height: 1.6;
    }

    /* ── Print button (screen only) ── */
    .print-btn {
      display: block;
      width: 100%;
      margin-top: 16px;
      padding: 10px;
      background: #1d4ed8;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      font-family: sans-serif;
      cursor: pointer;
    }
    .no-print { }

    @media print {
      body { padding: 0; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>
<div class="receipt">

  <!-- Header -->
  <div class="receipt-header">
    <?php if ($logoUrl): ?>
    <img src="<?= $logoUrl ?>" alt="Logo" class="receipt-logo">
    <?php endif; ?>
    <div class="receipt-biz-name"><?= htmlspecialchars($businessName) ?></div>
    <?php if ($sale['location_name']): ?>
    <div class="receipt-loc" style="font-weight:bold;"><?= htmlspecialchars($sale['location_name']) ?></div>
    <?php endif; ?>
    <?php if ($address): ?>
    <div class="receipt-loc"><?= nl2br(htmlspecialchars($address)) ?></div>
    <?php endif; ?>
    <?php if ($phone): ?>
    <div class="receipt-loc"><?= htmlspecialchars(formatPhone($phone)) ?></div>
    <?php endif; ?>
  </div>

  <hr class="divider-solid">

  <!-- Sale Meta -->
  <div class="meta-row">
    <span class="meta-label">Receipt #</span>
    <span><?= htmlspecialchars($sale['record_number']) ?></span>
  </div>
  <div class="meta-row">
    <span class="meta-label">Date</span>
    <span><?= date('M j, Y', strtotime($sale['created_at'])) ?></span>
  </div>
  <div class="meta-row">
    <span class="meta-label">Time</span>
    <span><?= date('g:i A', strtotime($sale['created_at'])) ?></span>
  </div>
  <div class="meta-row">
    <span class="meta-label">Served by</span>
    <span><?= htmlspecialchars($sale['sold_by'] ?? '—') ?></span>
  </div>
  <div class="meta-row">
    <span class="meta-label">Payment</span>
    <span><?= ucfirst(htmlspecialchars($sale['payment_method'])) ?></span>
  </div>

  <hr class="divider">

  <!-- Items -->
  <div class="items">
    <?php foreach ($items as $item): ?>
    <div class="item-row">
      <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
      <div class="item-detail">
        <span><?= $item['quantity'] ?> × $<?= number_format($item['unit_price'], 2) ?></span>
        <span>$<?= number_format($item['line_total'], 2) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <hr class="divider">

  <!-- Totals -->
  <div class="total-row">
    <span>Subtotal</span>
    <span>$<?= number_format($sale['subtotal'], 2) ?></span>
  </div>

  <?php if ($sale['discount_amount'] > 0): ?>
  <div class="total-row discount">
    <span>Discount</span>
    <span>−$<?= number_format($sale['discount_amount'], 2) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($sale['tax_amount'] > 0): ?>
  <div class="total-row">
    <span>HST (13%)</span>
    <span>$<?= number_format($sale['tax_amount'], 2) ?></span>
  </div>
  <?php endif; ?>

  <div class="total-row grand">
    <span>TOTAL</span>
    <span>$<?= number_format($sale['total_amount'], 2) ?></span>
  </div>

  <!-- Footer -->
  <hr class="divider">
  <div class="receipt-footer">
    <?php if ($sale['tax_amount'] > 0 && $hstNumber): ?>
    <div>HST Reg # <?= htmlspecialchars($hstNumber) ?></div>
    <hr class="divider">
    <?php endif; ?>
    <?php if ($sale['notes']): ?>
    <div style="margin-bottom:6px;font-style:italic;"><?= htmlspecialchars($sale['notes']) ?></div>
    <hr class="divider">
    <?php endif; ?>
    <div style="font-size:14px;font-weight:bold;margin-bottom:4px;">Thank You!</div>
    <div>We appreciate your business.</div>
    <div>Questions? Contact us at</div>
    <?php if ($phone): ?>
    <div><?= htmlspecialchars(formatPhone($phone)) ?></div>
    <?php endif; ?>
  </div>

  <!-- Print button (hidden when printing) -->
  <div class="no-print">
    <button class="print-btn" onclick="window.print()">🖨 Print Receipt</button>
    <button class="print-btn" style="background:#6b7280;margin-top:8px;" onclick="window.close()">✕ Close</button>
  </div>

</div>

<script>
// Auto-print when opened in new tab (skip if ?noprint=1 for preview)
if (!new URLSearchParams(window.location.search).has('noprint')) {
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 500);
    });
}
</script>
</body>
</html>
