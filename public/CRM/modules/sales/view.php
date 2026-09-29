<?php
// ============================================================
// Sale Detail View
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
     JOIN locations l ON s.location_id=l.id
     LEFT JOIN users u ON s.created_by=u.id
     WHERE s.id=?",
    [$saleId]
);
if (!$sale) { header('Location: ' . APP_URL . '/modules/sales/'); exit; }

$items = DB::query('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id', [$saleId]);

$pageTitle = 'Sale ' . $sale['record_number'];
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($sale['record_number']) ?></h1>
        <p class="page-sub">
            <span class="badge badge-loc"><?= htmlspecialchars($sale['location_code']) ?></span>
            &nbsp;<?= date('M j, Y g:ia', strtotime($sale['created_at'])) ?>
            &nbsp;· <?= htmlspecialchars($sale['sold_by'] ?? '—') ?>
            &nbsp;· <?= ucfirst($sale['payment_method']) ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;">
        <a href="receipt.php?id=<?= $saleId ?>" target="_blank" class="btn btn-primary">🖨 Print Receipt</a>
        <a href="index.php" class="btn btn-secondary">← Sales</a>
    </div>
</div>

<div class="card" style="max-width:700px;">
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr><th>Item</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                <td class="text-muted small"><?= htmlspecialchars($item['item_sku'] ?? '—') ?></td>
                <td><?= $item['quantity'] ?></td>
                <td>$<?= number_format($item['unit_price'],2) ?></td>
                <td><strong>$<?= number_format($item['line_total'],2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding:1rem;text-align:right;border-top:1px solid var(--border);">
            <div style="display:flex;justify-content:flex-end;gap:2rem;margin-bottom:.3rem;color:var(--text-3);">
                <span>Subtotal</span><span>$<?= number_format($sale['subtotal'],2) ?></span>
            </div>
            <?php if ($sale['tax_amount'] > 0): ?>
            <div style="display:flex;justify-content:flex-end;gap:2rem;margin-bottom:.3rem;color:var(--text-3);">
                <span>HST</span><span>$<?= number_format($sale['tax_amount'],2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($sale['discount_amount'] > 0): ?>
            <div style="display:flex;justify-content:flex-end;gap:2rem;margin-bottom:.3rem;color:var(--red);">
                <span>Discount</span><span>−$<?= number_format($sale['discount_amount'],2) ?></span>
            </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:flex-end;gap:2rem;font-size:1.2rem;font-weight:700;padding-top:.5rem;border-top:2px solid var(--border);">
                <span>Total</span><span style="color:var(--blue);">$<?= number_format($sale['total_amount'],2) ?></span>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
