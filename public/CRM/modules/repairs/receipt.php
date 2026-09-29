<?php
// ============================================================
// Repair Intake Receipt — 80mm Thermal Printer
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$repairId = intval($_GET['id'] ?? 0);
if (!$repairId) { header('Location: ' . APP_URL . '/modules/repairs/'); exit; }

$repair = DB::queryOne(
    "SELECT r.*,
            c.first_name, c.last_name, c.phone_primary AS phone, c.email,
            l.name AS location_name, l.code AS location_code, l.address AS location_address,
            l.phone AS location_phone,
            u.first_name AS tech_first, u.last_name AS tech_last
     FROM repairs r
     JOIN customers c  ON r.customer_id = c.id
     JOIN locations l  ON r.location_id = l.id
     LEFT JOIN users u ON r.assigned_to = u.id
     WHERE r.id = ? LIMIT 1",
    [$repairId]
);

if (!$repair) { header('Location: ' . APP_URL . '/modules/repairs/'); exit; }

// Tax rate from settings (stored as percent e.g. "13"), fallback to 13%
$taxSetting  = DB::queryOne("SELECT value FROM settings WHERE setting_key='tax_rate' AND location_id IS NULL LIMIT 1", []);
$TAX_RATE    = $taxSetting ? floatval($taxSetting['value']) / 100 : 0.13;

$depositAmt   = (float)($repair['deposit_amount'] ?? 0);
$depositMethod = $repair['deposit_method'] ?? '';
$estCost      = $repair['estimated_cost'] !== null ? (float)$repair['estimated_cost'] : null;

// Tax & totals
$taxAmt      = $estCost !== null ? round($estCost * $TAX_RATE, 2) : null;
$totalAmt    = $estCost !== null ? $estCost + $taxAmt : null;
$balanceOwing = $totalAmt  !== null ? max(0, $totalAmt - $depositAmt) : null;

$statusUrl = APP_URL . '/repair-status.php?t=' . $repair['status_token'];
$receivedAt = date('M j, Y  g:i A', strtotime($repair['received_at']));
$estReady   = $repair['estimated_ready_at'] ? date('M j, Y  g:i A', strtotime($repair['estimated_ready_at'])) : null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt — <?= htmlspecialchars($repair['record_number']) ?></title>
<style>
    /* ── Reset ─────────────────────────────── */
    * { margin:0; padding:0; box-sizing:border-box; }

    body {
        font-family: 'Courier New', Courier, monospace;
        font-size: 12px;
        font-weight: bold;
        line-height: 1.5;
        color: #000;
        background: #fff;
        width: 302px;         /* 80mm at 96dpi */
        margin: 0 auto;
        padding: 4px 6px 24px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    @media print {
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            width: 72mm;
            padding: 2mm 3mm 10mm;
        }
        .no-print { display: none !important; }
    }

    /* ── Layout helpers ────────────────────── */
    .center  { text-align: center; }
    .right   { text-align: right; }
    .bold    { font-weight: bold; }
    .divider { border: none; border-top: 1px dashed #000; margin: 5px 0; }
    .divider-solid { border: none; border-top: 1px solid #000; margin: 5px 0; }

    /* ── Header ────────────────────────────── */
    .receipt-header { text-align: center; margin-bottom: 4px; }
    .receipt-header .brand { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
    .receipt-header .tagline { font-size: 9px; color: #000; margin-top: 1px; }
    .receipt-header .loc-info { font-size: 10px; margin-top: 3px; }

    /* ── Section label ─────────────────────── */
    .section-label {
        font-size: 10px;
        font-weight: bold;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: #000;
        margin: 5px 0 2px;
    }

    /* ── Row ───────────────────────────────── */
    .row {
        display: flex;
        justify-content: space-between;
        gap: 4px;
        margin-bottom: 2px;
    }
    .row .label { color: #000; flex-shrink: 0; }
    .row .val   { text-align: right; word-break: break-word; max-width: 62%; }

    /* ── Full-width text ───────────────────── */
    .full-val {
        margin-bottom: 3px;
        word-break: break-word;
        white-space: pre-wrap;
    }

    /* ── Ticket # ──────────────────────────── */
    .ticket-number {
        font-size: 14px;
        font-weight: bold;
        text-align: center;
        margin: 6px 0 2px;
        letter-spacing: .5px;
    }

    /* ── Money rows ────────────────────────── */
    .money-row {
        display: flex;
        justify-content: space-between;
        padding: 2px 0;
        font-size: 11px;
    }
    .money-row.total {
        font-size: 13px;
        font-weight: bold;
        border-top: 1px solid #000;
        margin-top: 3px;
        padding-top: 3px;
    }
    .money-row.deposit { color: #008000; }
    .money-row.balance { }

    /* ── Status URL ────────────────────────── */
    #qrcode img, #qrcode canvas { display: block; margin: 0 auto; }


    /* ── Terms ─────────────────────────────── */
    .terms {
        font-size: 10px;
        color: #000;
        text-align: center;
        margin-top: 6px;
        line-height: 1.3;
    }

    /* ── Print button (screen only) ────────── */
    .print-bar {
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #1e293b;
        color: #fff;
        padding: 8px 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        font-family: sans-serif;
        font-size: 13px;
        z-index: 9999;
    }
    .print-bar button {
        background: #3b82f6;
        color: #fff;
        border: none;
        border-radius: 5px;
        padding: 5px 16px;
        cursor: pointer;
        font-size: 13px;
    }
    @media print { .print-bar { display: none; } }
    body { padding-top: 44px; }
    @media print { body { padding-top: 0; } }
</style>
</head>
<body>

<!-- Print bar (screen only) -->
<div class="print-bar no-print">
    <span>🧾 Intake Receipt — <?= htmlspecialchars($repair['record_number']) ?></span>
    <div>
        <button onclick="window.print()">🖨 Print</button>
        &nbsp;
        <a href="view.php?id=<?= $repairId ?>" style="color:#94a3b8;font-size:12px;text-decoration:none;">← Back to Ticket</a>
    </div>
</div>

<!-- ── RECEIPT BODY ─────────────────────────── -->

<!-- Header -->
<div class="receipt-header">
    <div class="brand">C TECH FIX</div>
    <div class="tagline">Phone &amp; Device Repair Specialists</div>
    <?php if ($repair['location_name']): ?>
    <div class="loc-info"><?= htmlspecialchars($repair['location_name']) ?></div>
    <?php endif; ?>
    <?php if ($repair['location_address']): ?>
    <div class="loc-info"><?= htmlspecialchars($repair['location_address']) ?></div>
    <?php endif; ?>
    <?php if ($repair['location_phone']): ?>
    <div class="loc-info"><?= htmlspecialchars($repair['location_phone']) ?></div>
    <?php endif; ?>
</div>

<hr class="divider-solid">

<!-- Ticket # -->
<div class="ticket-number"><?= htmlspecialchars($repair['record_number']) ?></div>
<div style="text-align:center;font-size:10px;font-weight:bold;color:#000;">INTAKE RECEIPT</div>
<div style="text-align:center;font-size:10px;color:#000;margin-bottom:3px;"><?= $receivedAt ?></div>

<hr class="divider">

<!-- Customer -->
<div class="section-label">CUSTOMER</div>
<div class="row">
    <span class="label">Name:</span>
    <span class="val bold"><?= htmlspecialchars($repair['first_name'] . ' ' . $repair['last_name']) ?></span>
</div>
<div class="row">
    <span class="label">Phone:</span>
    <span class="val"><?= htmlspecialchars($repair['phone']) ?></span>
</div>
<?php if ($repair['email']): ?>
<div class="row">
    <span class="label">Email:</span>
    <span class="val"><?= htmlspecialchars($repair['email']) ?></span>
</div>
<?php endif; ?>

<hr class="divider">

<!-- Device -->
<div class="section-label">DEVICE</div>
<div class="row">
    <span class="label">Type:</span>
    <span class="val"><?= htmlspecialchars($repair['device_type']) ?></span>
</div>
<div class="row">
    <span class="label">Brand:</span>
    <span class="val"><?= htmlspecialchars($repair['device_brand']) ?></span>
</div>
<div class="row">
    <span class="label">Model:</span>
    <span class="val"><?= htmlspecialchars($repair['device_model']) ?></span>
</div>
<?php if ($repair['device_color']): ?>
<div class="row">
    <span class="label">Color:</span>
    <span class="val"><?= htmlspecialchars($repair['device_color']) ?></span>
</div>
<?php endif; ?>
<?php if ($repair['device_imei']): ?>
<div class="row">
    <span class="label">IMEI:</span>
    <span class="val"><?= htmlspecialchars($repair['device_imei']) ?></span>
</div>
<?php endif; ?>
<?php if ($repair['device_serial']): ?>
<div class="row">
    <span class="label">Serial:</span>
    <span class="val"><?= htmlspecialchars($repair['device_serial']) ?></span>
</div>
<?php endif; ?>
<?php if ($repair['accessories']): ?>
<div class="row">
    <span class="label">Accessories:</span>
    <span class="val"><?= htmlspecialchars($repair['accessories']) ?></span>
</div>
<?php endif; ?>

<hr class="divider">

<!-- Issue -->
<div class="section-label">ISSUE REPORTED</div>
<?php if ($repair['service_type']): ?>
<?php foreach (array_map('trim', explode(',', $repair['service_type'])) as $svc): ?>
<div class="row">
    <span class="label">Service:</span>
    <span class="val"><?= htmlspecialchars($svc) ?></span>
</div>
<?php endforeach; ?>
<?php endif; ?>
<div class="full-val"><?= htmlspecialchars($repair['issue_description']) ?></div>

<hr class="divider">

<!-- Financials -->
<div class="section-label">ESTIMATE</div>
<?php if ($estCost !== null): ?>
<div class="money-row">
    <span>Subtotal</span>
    <span>$<?= number_format($estCost, 2) ?></span>
</div>
<div class="money-row">
    <span>HST (<?= number_format($TAX_RATE * 100, 0) ?>%)</span>
    <span>$<?= number_format($taxAmt, 2) ?></span>
</div>
<div class="money-row total">
    <span>TOTAL</span>
    <span>$<?= number_format($totalAmt, 2) ?></span>
</div>
<?php if ($depositAmt > 0): ?>
<div class="money-row" style="margin-top:4px;">
    <span>Deposit Paid
        <?php if ($depositMethod): ?>
        (<?= ucwords(str_replace('_',' ', $depositMethod)) ?>)
        <?php endif; ?>
    </span>
    <span>-$<?= number_format($depositAmt, 2) ?></span>
</div>
<div class="money-row total">
    <span>BALANCE AT PICKUP</span>
    <span>$<?= number_format($balanceOwing, 2) ?></span>
</div>
<?php endif; ?>
<?php else: ?>
<div style="text-align:center;font-size:10px;font-weight:bold;">Quote pending diagnosis</div>
<?php endif; ?>


<hr class="divider">

<!-- Track status -->
<div class="section-label center">TRACK YOUR REPAIR</div>
<div style="text-align:center;margin:6px 0;">
    <div id="qrcode" style="display:inline-block;"></div>
</div>
<div style="text-align:center;font-size:10px;">Scan to check your repair status</div>
<div style="text-align:center;font-size:10px;">or call us — we'll update you by SMS</div>

<hr class="divider">

<hr class="divider">

<!-- Terms -->
<div class="terms">
    C Tech Fix is not responsible for data loss. Back up your device before service.
    Devices not collected within 15 days of first contact may be recycled.
    <?php
    $warrantyDays = intval($repair['warranty_days'] ?? 90);
    if ($warrantyDays > 0):
        if ($warrantyDays === 365)      $warrantyLabel = '1 Year';
        elseif ($warrantyDays === 180)  $warrantyLabel = '6 Months';
        else                            $warrantyLabel = $warrantyDays . ' Days';
    ?>
    Warranty: <?= $warrantyLabel ?> on parts &amp; labour.
    <?php endif; ?>
    All prices exclude tax.
</div>

<div style="text-align:center;font-size:13px;font-weight:bold;color:#000;margin-top:8px;letter-spacing:1px;border:2px solid #000;padding:4px 0;">
    ★ CASH — NO TAX ★
</div>

<div style="text-align:center;font-size:10px;font-weight:bold;color:#000;margin-top:6px;">
    Thank you for choosing C Tech Fix!
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Generate QR code
new QRCode(document.getElementById('qrcode'), {
    text:   '<?= addslashes($statusUrl) ?>',
    width:  160,
    height: 160,
    colorDark:  '#000000',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M,
});

// Auto-print after QR renders
if (!window.location.search.includes('noprint')) {
    window.addEventListener('load', () => setTimeout(() => window.print(), 800));
}
</script>
</body>
</html>
