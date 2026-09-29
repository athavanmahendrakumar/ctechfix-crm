<?php
// ============================================================
// Reports Hub
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/staff.php'); exit;
}

$pageTitle = 'Reports';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📈 Reports</h1>
        <p class="page-sub">Analytics, performance &amp; financial summaries</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">

    <!-- Sales Report -->
    <a href="sales.php" style="text-decoration:none;">
        <div class="card" style="cursor:pointer;transition:.15s;" onmouseover="this.style.borderColor='var(--blue)'" onmouseout="this.style.borderColor='var(--border)'">
            <div class="card-body" style="display:flex;align-items:flex-start;gap:1rem;">
                <div style="font-size:36px;">📊</div>
                <div>
                    <div style="font-weight:700;font-size:16px;margin-bottom:4px;">Sales Report</div>
                    <div class="text-muted small">Filter by period, location, stream, payment method and staff. Daily breakdown, top items and per-staff revenue.</div>
                </div>
            </div>
        </div>
    </a>

    <!-- P&L -->
    <a href="pl.php" style="text-decoration:none;">
        <div class="card" style="cursor:pointer;transition:.15s;" onmouseover="this.style.borderColor='var(--blue)'" onmouseout="this.style.borderColor='var(--border)'">
            <div class="card-body" style="display:flex;align-items:flex-start;gap:1rem;">
                <div style="font-size:36px;">💰</div>
                <div>
                    <div style="font-weight:700;font-size:16px;margin-bottom:4px;">Profit &amp; Loss</div>
                    <div class="text-muted small">Revenue, expenses and net profit by period and location. Compare months and download summaries.</div>
                </div>
            </div>
        </div>
    </a>

    <!-- Repairs -->
    <a href="repairs.php" style="text-decoration:none;">
        <div class="card" style="cursor:pointer;transition:.15s;" onmouseover="this.style.borderColor='var(--blue)'" onmouseout="this.style.borderColor='var(--border)'">
            <div class="card-body" style="display:flex;align-items:flex-start;gap:1rem;">
                <div style="font-size:36px;">🔧</div>
                <div>
                    <div style="font-weight:700;font-size:16px;margin-bottom:4px;">Repair Performance</div>
                    <div class="text-muted small">Volume by technician, device type and location. Completion rates, average turnaround and revenue.</div>
                </div>
            </div>
        </div>
    </a>

    <!-- Inventory -->
    <a href="inventory.php" style="text-decoration:none;">
        <div class="card" style="cursor:pointer;transition:.15s;" onmouseover="this.style.borderColor='var(--blue)'" onmouseout="this.style.borderColor='var(--border)'">
            <div class="card-body" style="display:flex;align-items:flex-start;gap:1rem;">
                <div style="font-size:36px;">📦</div>
                <div>
                    <div style="font-weight:700;font-size:16px;margin-bottom:4px;">Inventory Valuation</div>
                    <div class="text-muted small">Cost vs retail value, low stock alerts, out-of-stock items and category breakdown.</div>
                </div>
            </div>
        </div>
    </a>

    <!-- Sales Targets -->
    <a href="targets.php" style="text-decoration:none;">
        <div class="card" style="cursor:pointer;transition:.15s;" onmouseover="this.style.borderColor='var(--blue)'" onmouseout="this.style.borderColor='var(--border)'">
            <div class="card-body" style="display:flex;align-items:flex-start;gap:1rem;">
                <div style="font-size:36px;">🎯</div>
                <div>
                    <div style="font-weight:700;font-size:16px;margin-bottom:4px;">Sales Targets</div>
                    <div class="text-muted small">Set daily, weekly and monthly revenue &amp; volume targets per location. Track progress vs actuals.</div>
                </div>
            </div>
        </div>
    </a>

</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
