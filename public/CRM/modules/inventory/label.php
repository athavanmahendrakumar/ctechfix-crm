<?php
// ============================================================
// Barcode Label Print — 57mm × 32mm
// ?type=inventory&ids=1,2,3   — regular inventory items
// ?type=used&ids=1,2,3        — used devices
// ?copies=N                   — print N copies of each label
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$type   = $_GET['type']   ?? 'inventory';
$copies = max(1, intval($_GET['copies'] ?? 1));
$ids    = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));

if (!$ids) { echo 'No items selected.'; exit; }

$items = [];

if ($type === 'inventory') {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::query(
        "SELECT * FROM inventory_items WHERE id IN ($placeholders) ORDER BY name",
        $ids
    );
    foreach ($rows as $r) {
        $items[] = [
            'barcode'  => $r['barcode'] ?? $r['sku'] ?? 'CTF' . str_pad($r['id'], 7, '0', STR_PAD_LEFT),
            'name'     => $r['name'],
            'price'    => '$' . number_format($r['sell_price'] ?? 0, 2),
            'loc'      => '',
            'sub'      => $r['sku'] ?? '',
        ];
    }
} elseif ($type === 'used') {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::query(
        "SELECT u.*, l.code AS loc_code
         FROM used_devices u
         LEFT JOIN locations l ON l.id = u.location_id
         WHERE u.id IN ($placeholders) ORDER BY u.device_brand, u.device_model",
        $ids
    );
    foreach ($rows as $r) {
        $items[] = [
            'barcode'  => $r['barcode'] ?? 'CTF' . str_pad($r['id'], 7, '0', STR_PAD_LEFT),
            'name'     => $r['device_brand'] . ' ' . $r['device_model'],
            'price'    => '$' . number_format($r['selling_price'], 2),
            'loc'      => $r['loc_code'] ?? '',
            'sub'      => ($r['storage'] ? $r['storage'] . ' · ' : '') . $r['condition_grade'],
        ];
    }
}

// Expand copies
$labels = [];
foreach ($items as $item) {
    for ($i = 0; $i < $copies; $i++) {
        $labels[] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Labels — C Tech Fix</title>
<!-- JsBarcode from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js"></script>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }

  body {
    background: #fff;
    font-family: Arial, sans-serif;
  }

  /* Screen: show grid of labels with print button */
  .controls {
    padding: 16px;
    background: #f3f4f6;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .controls button {
    padding: 8px 20px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
  }
  .controls span { font-size: 13px; color: #6b7280; }

  .labels-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 16px;
  }

  /* Each label: 57mm × 32mm */
  .label {
    width:  57mm;
    height: 32mm;
    border: 1px dashed #ccc;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1.5mm 2mm;
    overflow: hidden;
    position: relative;
  }

  .label-loc {
    position: absolute;
    top: 1.5mm;
    right: 2mm;
    font-size: 7pt;
    font-weight: 700;
    color: #374151;
    letter-spacing: .5px;
  }

  .label-name {
    font-size: 7.5pt;
    font-weight: 700;
    text-align: center;
    line-height: 1.2;
    max-width: 100%;
    word-break: break-word;
    margin-bottom: 1mm;
  }

  .label-sub {
    font-size: 6pt;
    color: #6b7280;
    margin-bottom: 1mm;
    text-align: center;
  }

  .label svg {
    max-width: 51mm;
    height: 11mm;
  }

  .label-price {
    font-size: 9pt;
    font-weight: 700;
    margin-top: 1mm;
    color: #111;
  }

  /* Print styles */
  @media print {
    .controls { display: none !important; }
    body { background: #fff; }
    .labels-grid {
      padding: 0;
      gap: 0;
    }
    .label {
      border: none;
      page-break-inside: avoid;
    }
  }
</style>
</head>
<body>

<div class="controls no-print">
  <button onclick="window.print()">🖨 Print Labels</button>
  <span><?= count($labels) ?> label<?= count($labels) !== 1 ? 's' : '' ?> ready</span>
  <a href="javascript:history.back()" style="font-size:13px;color:#6b7280;margin-left:auto;">← Back</a>
</div>

<div class="labels-grid">
<?php foreach ($labels as $i => $label): ?>
<div class="label">
  <?php if ($label['loc']): ?>
  <div class="label-loc"><?= htmlspecialchars($label['loc']) ?></div>
  <?php endif; ?>
  <div class="label-name"><?= htmlspecialchars(mb_strimwidth($label['name'], 0, 40, '…')) ?></div>
  <?php if ($label['sub']): ?>
  <div class="label-sub"><?= htmlspecialchars($label['sub']) ?></div>
  <?php endif; ?>
  <svg id="bc<?= $i ?>" class="barcode"></svg>
  <div class="label-price"><?= htmlspecialchars($label['price']) ?></div>
</div>
<?php endforeach; ?>
</div>

<script>
const labels = <?= json_encode(array_values(array_column($labels, 'barcode'))) ?>;
labels.forEach(function(code, i) {
    try {
        JsBarcode('#bc' + i, code, {
            format:      'CODE128',
            width:       1.4,
            height:      36,
            displayValue: true,
            fontSize:    8,
            margin:      2,
            textMargin:  1,
        });
    } catch(e) {
        document.getElementById('bc' + i).outerHTML =
            '<div style="font-size:7pt;color:red;">Invalid barcode</div>';
    }
});

// Auto-print if ?autoprint=1
if (new URLSearchParams(location.search).get('autoprint') === '1') {
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 600);
    });
}
</script>
</body>
</html>
