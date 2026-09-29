<?php
// ============================================================
// Cash Drawer — Open, manage, close, manager review
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$isMgr     = $isOwner || $isManager;

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);
$msg = ''; $msgType = 'success';

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $drawerId = intval($_POST['drawer_id'] ?? 0);

    if ($action === 'open') {
        try {
        $locId   = intval($_POST['location_id'] ?? $user['location_id']);
        $opening = floatval($_POST['opening_amount'] ?? 0);
        $today   = date('Y-m-d');
        $exists  = DB::queryOne("SELECT id FROM cash_drawers WHERE location_id=? AND drawer_date=?", [$locId, $today]);
        if ($exists) {
            $msg = 'A drawer is already open for today at this location.'; $msgType = 'warning';
        } else {
            // Auto-pull today's cash sales from sales table
            $cashSales = DB::queryOne(
                "SELECT COALESCE(SUM(total_amount),0) AS total FROM sales
                 WHERE location_id=? AND payment_method='cash' AND DATE(created_at)=?",
                [$locId, $today]
            )['total'] ?? 0;
            $id = DB::insert(
                "INSERT INTO cash_drawers (location_id, drawer_date, opening_amount, opened_by, opened_at, cash_sales, status)
                 VALUES (?,?,?,?,NOW(),?,'open')",
                [$locId, $today, $opening, $user['id'], $cashSales]
            );
            $msg = 'Cash drawer opened.';
            header("Location: index.php?view=" . $id); exit;
        }
        } catch (\Throwable $ex) {
            $msg = 'Error opening drawer: ' . $ex->getMessage(); $msgType = 'danger';
        }

    } elseif ($action === 'add_expense' && $drawerId) {
        $drawer = DB::queryOne("SELECT * FROM cash_drawers WHERE id=? AND status='open'", [$drawerId]);
        if ($drawer) {
            $desc   = trim($_POST['description'] ?? '');
            $amount = floatval($_POST['amount'] ?? 0);
            $cat    = trim($_POST['category'] ?? 'General');
            if ($desc && $amount > 0) {
                DB::execute(
                    "INSERT INTO cash_drawer_expenses (drawer_id, description, amount, category, created_by) VALUES (?,?,?,?,?)",
                    [$drawerId, $desc, $amount, $cat, $user['id']]
                );
                DB::execute(
                    "UPDATE cash_drawers SET cash_payouts = cash_payouts + ? WHERE id=?",
                    [$amount, $drawerId]
                );
                $msg = 'Expense added.';
            }
        }
        header("Location: index.php?view=" . $drawerId); exit;

    } elseif ($action === 'close' && $drawerId) {
        $drawer = DB::queryOne("SELECT * FROM cash_drawers WHERE id=? AND status='open'", [$drawerId]);
        if ($drawer) {
            // Refresh cash sales (in case sales were added after opening)
            $cashSales = DB::queryOne(
                "SELECT COALESCE(SUM(total_amount),0) AS total FROM sales
                 WHERE location_id=? AND payment_method='cash' AND DATE(created_at)=?",
                [$drawer['location_id'], $drawer['drawer_date']]
            )['total'] ?? 0;
            $actual    = floatval($_POST['actual_close'] ?? 0);
            $expected  = floatval($drawer['opening_amount']) + floatval($cashSales) - floatval($drawer['cash_payouts']);
            $variance  = $actual - $expected;
            $expl      = trim($_POST['variance_explanation'] ?? '');
            DB::execute(
                "UPDATE cash_drawers SET
                    cash_sales=?, expected_close=?, actual_close=?, variance=?, variance_explanation=?,
                    status='submitted', closed_by=?, closed_at=NOW()
                 WHERE id=?",
                [$cashSales, $expected, $actual, $variance, $expl, $user['id'], $drawerId]
            );
            $msg = 'Drawer closed and submitted for manager review.';
            header("Location: index.php?view=" . $drawerId); exit;
        }

    } elseif ($action === 'approve' && $drawerId && $isMgr) {
        $mgrnotes = trim($_POST['manager_notes'] ?? '');
        DB::execute(
            "UPDATE cash_drawers SET status='manager_approved', manager_reviewed_by=?, manager_reviewed_at=NOW(), manager_notes=?
             WHERE id=? AND status='submitted'",
            [$user['id'], $mgrnotes, $drawerId]
        );
        // Optionally send SMS to owner if variance is significant
        $drawer = DB::queryOne("SELECT * FROM cash_drawers WHERE id=?", [$drawerId]);
        if ($drawer && abs($drawer['variance'] ?? 0) >= 5 && !$isOwner) {
            // Notify owner via SMS (if configured)
            try {
                require_once APP_ROOT . '/core/VoipMS.php';
                $ownerPhone = DB::queryOne("SELECT value FROM settings WHERE setting_key='owner_phone' LIMIT 1")['value'] ?? null;
                $locName    = DB::queryOne("SELECT name FROM locations WHERE id=?",[$drawer['location_id']])['name'] ?? '';
                if ($ownerPhone) {
                    $voip = new VoipMS();
                    $did  = preg_replace('/\D/', '', DB::queryOne("SELECT did FROM locations WHERE id=?",[$drawer['location_id']])['did'] ?? '');
                    $sign = $drawer['variance'] > 0 ? '+' : '';
                    $voip->sendSMS($did, preg_replace('/\D/','',$ownerPhone),
                        "Cash Drawer Alert — {$locName}: variance {$sign}" . number_format($drawer['variance'],2) . " on " . date('M j',$now=time()) . ". Approved by {$user['first_name']}.");
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        $msg = 'Drawer approved.';
        header("Location: index.php?view=" . $drawerId); exit;
    }
}

// ── View individual drawer ─────────────────────────────────
$viewId = intval($_GET['view'] ?? 0);
if ($viewId) {
    $drawer = DB::queryOne(
        "SELECT cd.*, l.name AS loc_name, l.code AS loc_code,
                ob.first_name AS opened_name, cb.first_name AS closed_name,
                mb.first_name AS mgr_name
         FROM cash_drawers cd
         JOIN locations l ON l.id=cd.location_id
         LEFT JOIN users ob ON ob.id=cd.opened_by
         LEFT JOIN users cb ON cb.id=cd.closed_by
         LEFT JOIN users mb ON mb.id=cd.manager_reviewed_by
         WHERE cd.id=?",
        [$viewId]
    );
    if (!$drawer) { header('Location: index.php'); exit; }
    if (!$isOwner && !$isManager && $drawer['location_id'] != $user['location_id']) {
        header('Location: index.php'); exit;
    }
    $expenses = DB::query(
        "SELECT cde.*, u.first_name FROM cash_drawer_expenses cde
         LEFT JOIN users u ON u.id=cde.created_by
         WHERE cde.drawer_id=? ORDER BY cde.created_at",
        [$viewId]
    );
    // Live breakdown: cash vs card, repairs vs walk-in sales
    $REPAIR_TYPES  = "sale_type IN ('repair_final','repair_deposit')";
    $WALKIN_TYPES  = "(sale_type IS NULL OR sale_type NOT IN ('repair_final','repair_deposit'))";
    $CARD_METHODS  = "payment_method IN ('card','debit','credit','square','visa','mastercard','amex','tap')";
    $baseWhere     = "location_id=? AND DATE(created_at)=?";
    $bp            = [$drawer['location_id'], $drawer['drawer_date']];

    $cashRepairs = (float)(DB::queryOne(
        "SELECT COALESCE(SUM(total_amount),0) AS t FROM sales
         WHERE $baseWhere AND payment_method='cash' AND $REPAIR_TYPES", $bp
    )['t'] ?? 0);

    $cashWalkin = (float)(DB::queryOne(
        "SELECT COALESCE(SUM(total_amount),0) AS t FROM sales
         WHERE $baseWhere AND payment_method='cash' AND $WALKIN_TYPES", $bp
    )['t'] ?? 0);

    $cardRepairs = (float)(DB::queryOne(
        "SELECT COALESCE(SUM(total_amount),0) AS t FROM sales
         WHERE $baseWhere AND $CARD_METHODS AND $REPAIR_TYPES", $bp
    )['t'] ?? 0);

    $cardWalkin = (float)(DB::queryOne(
        "SELECT COALESCE(SUM(total_amount),0) AS t FROM sales
         WHERE $baseWhere AND $CARD_METHODS AND $WALKIN_TYPES", $bp
    )['t'] ?? 0);

    $liveCashSales = $cashRepairs + $cashWalkin;
    $liveCashTotal = $liveCashSales; // for expected close calculation
}

// ── List view ─────────────────────────────────────────────
$filterLoc  = ($isOwner || $isManager) ? intval($_GET['location_id'] ?? 0) : (int)$user['location_id'];
$filterSt   = $_GET['status'] ?? 'active';

$where  = ['1=1'];
$params = [];
if ($filterLoc)    { $where[] = 'cd.location_id=?'; $params[] = $filterLoc; }
elseif (!$isOwner && !$isManager) { $where[] = 'cd.location_id=?'; $params[] = $user['location_id']; }
if ($filterSt === 'active')  $where[] = "cd.status IN ('open','submitted')";
elseif ($filterSt !== 'all') { $where[] = 'cd.status=?'; $params[] = $filterSt; }

$drawers = DB::query(
    "SELECT cd.*, l.code AS loc_code, l.name AS loc_name, ob.first_name AS opened_name
     FROM cash_drawers cd
     JOIN locations l ON l.id=cd.location_id
     LEFT JOIN users ob ON ob.id=cd.opened_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY cd.drawer_date DESC, cd.opened_at DESC
     LIMIT 60",
    $params
);

// Check if today's drawer exists for current user's location
$todayDrawer = DB::queryOne(
    "SELECT id, status FROM cash_drawers WHERE location_id=? AND drawer_date=?",
    [$user['location_id'], date('Y-m-d')]
);

$STATUS_COLORS = [
    'open'             => 'var(--green)',
    'submitted'        => 'var(--amber)',
    'manager_approved' => 'var(--text-3)',
];
$STATUS_ICONS = ['open'=>'🟢','submitted'=>'📋','manager_approved'=>'✅'];

$pageTitle = $viewId ? 'Cash Drawer' : 'Cash Drawers';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($viewId && isset($drawer)): ?>
<!-- ── DRAWER DETAIL VIEW ─────────────────────────────── -->
<div class="page-header">
    <div>
        <h1 class="page-title">💵 Cash Drawer — <?= date('M j, Y', strtotime($drawer['drawer_date'])) ?></h1>
        <p class="page-sub"><?= htmlspecialchars($drawer['loc_name']) ?></p>
    </div>
    <a href="index.php" class="btn btn-secondary">← All Drawers</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <!-- Summary card -->
    <div class="card">
        <div class="card-header"><h2 class="card-title">Summary</h2>
            <span style="color:<?= $STATUS_COLORS[$drawer['status']] ?? 'var(--text)' ?>;font-weight:700;">
                <?= $STATUS_ICONS[$drawer['status']] ?? '' ?> <?= ucfirst(str_replace('_',' ',$drawer['status'])) ?>
            </span>
        </div>
        <div class="card-body">
            <?php
            $expected = floatval($drawer['opening_amount']) + $liveCashTotal + floatval($drawer['cash_deposits']) - floatval($drawer['cash_payouts']);
            $variance = $drawer['actual_close'] !== null ? floatval($drawer['actual_close']) - $expected : null;
            $totalCard = $cardRepairs + $cardWalkin;
            ?>

            <!-- TILL (Cash) -->
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-3);margin-bottom:4px;">💵 Till (Cash)</div>
            <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:1rem;">
                <tr><td style="padding:5px 0;color:var(--text-3);">Opening Float</td><td style="text-align:right;font-weight:600;">$<?= number_format($drawer['opening_amount'],2) ?></td></tr>
                <tr><td style="padding:5px 0 5px 12px;color:var(--text-3);font-size:13px;">↳ Repairs (cash)</td><td style="text-align:right;font-weight:600;color:var(--green);">$<?= number_format($cashRepairs,2) ?></td></tr>
                <tr><td style="padding:5px 0 5px 12px;color:var(--text-3);font-size:13px;">↳ Walk-in Sales (cash)</td><td style="text-align:right;font-weight:600;color:var(--green);">$<?= number_format($cashWalkin,2) ?></td></tr>
                <?php if ($drawer['cash_deposits'] > 0): ?>
                <tr><td style="padding:5px 0;color:var(--text-3);">Cash Deposits</td><td style="text-align:right;font-weight:600;color:var(--green);">$<?= number_format($drawer['cash_deposits'],2) ?></td></tr>
                <?php endif; ?>
                <?php if ($drawer['cash_payouts'] > 0): ?>
                <tr><td style="padding:5px 0;color:var(--text-3);">Cash Payouts</td><td style="text-align:right;font-weight:600;color:var(--red);">-$<?= number_format($drawer['cash_payouts'],2) ?></td></tr>
                <?php endif; ?>
                <tr style="border-top:2px solid var(--border);">
                    <td style="padding:8px 0;font-weight:700;">Expected Close</td>
                    <td style="text-align:right;font-weight:700;font-size:16px;">$<?= number_format($expected,2) ?></td>
                </tr>
                <?php if ($drawer['actual_close'] !== null): ?>
                <tr><td style="padding:5px 0;color:var(--text-3);">Actual Count</td><td style="text-align:right;font-weight:600;">$<?= number_format($drawer['actual_close'],2) ?></td></tr>
                <tr>
                    <td style="padding:5px 0;font-weight:700;">Variance</td>
                    <td style="text-align:right;font-weight:700;color:<?= abs($variance??0)<0.01?'var(--green)':($variance>0?'var(--blue)':'var(--red)') ?>;">
                        <?= ($variance>=0?'+':'') . '$' . number_format(abs($variance??0),2) ?>
                    </td>
                </tr>
                <?php if ($drawer['variance_explanation']): ?>
                <tr><td colspan="2" style="font-size:12px;color:var(--text-3);padding-bottom:4px;"><?= htmlspecialchars($drawer['variance_explanation']) ?></td></tr>
                <?php endif; ?>
                <?php endif; ?>
            </table>

            <!-- SQUARE / CARD (reference only) -->
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-3);margin-bottom:4px;">💳 Square / Card (reference)</div>
            <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:1rem;">
                <tr><td style="padding:5px 0 5px 12px;color:var(--text-3);font-size:13px;">↳ Repairs (card)</td><td style="text-align:right;font-weight:600;color:var(--blue);">$<?= number_format($cardRepairs,2) ?></td></tr>
                <tr><td style="padding:5px 0 5px 12px;color:var(--text-3);font-size:13px;">↳ Walk-in Sales (card)</td><td style="text-align:right;font-weight:600;color:var(--blue);">$<?= number_format($cardWalkin,2) ?></td></tr>
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:6px 0;font-weight:700;">Total Square</td>
                    <td style="text-align:right;font-weight:700;color:var(--blue);">$<?= number_format($totalCard,2) ?></td>
                </tr>
            </table>

            <div style="font-size:12px;color:var(--text-3);">
                Opened by <?= htmlspecialchars($drawer['opened_name'] ?? '—') ?> at <?= date('g:i A', strtotime($drawer['opened_at'])) ?>
                <?php if ($drawer['closed_at']): ?> · Closed by <?= htmlspecialchars($drawer['closed_name'] ?? '—') ?> at <?= date('g:i A', strtotime($drawer['closed_at'])) ?><?php endif; ?>
                <?php if ($drawer['mgr_name']): ?> · Approved by <?= htmlspecialchars($drawer['mgr_name']) ?><?php endif; ?>
            </div>
            <?php if ($drawer['manager_notes']): ?>
            <div style="margin-top:.5rem;background:var(--surface-2);border-radius:6px;padding:.5rem;font-size:13px;">
                <strong>Manager note:</strong> <?= htmlspecialchars($drawer['manager_notes']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actions column -->
    <div style="display:flex;flex-direction:column;gap:1rem;">

        <?php if ($drawer['status'] === 'open'): ?>
        <!-- Close drawer -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">🔒 Close Drawer</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action"    value="close">
                    <input type="hidden" name="drawer_id" value="<?= $drawer['id'] ?>">
                    <div class="form-group">
                        <label class="form-label">Actual Cash Count <span style="color:var(--red)">*</span></label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                            <input type="number" name="actual_close" step="0.01" min="0" class="form-control" style="padding-left:24px;" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Variance Explanation</label>
                        <input type="text" name="variance_explanation" class="form-control" placeholder="Reason if variance expected">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;"
                        onclick="return confirm('Close this drawer and submit for manager review?')">
                        🔒 Close & Submit
                    </button>
                </form>
            </div>
        </div>

        <!-- Add expense/payout -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">➕ Add Cash Payout</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action"    value="add_expense">
                    <input type="hidden" name="drawer_id" value="<?= $drawer['id'] ?>">
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g. Lunch for staff" required>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" step="0.01" min="0.01" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-control">
                                <option>General</option>
                                <option>Supplies</option>
                                <option>Food</option>
                                <option>Parts</option>
                                <option>Petty Cash</option>
                                <option>Refund</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="width:100%;">Add Payout</button>
                </form>
            </div>
        </div>

        <?php elseif ($drawer['status'] === 'submitted' && $isMgr): ?>
        <!-- Manager review -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">✅ Manager Approval</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action"    value="approve">
                    <input type="hidden" name="drawer_id" value="<?= $drawer['id'] ?>">
                    <div class="form-group">
                        <label class="form-label">Manager Notes (optional)</label>
                        <textarea name="manager_notes" class="form-control" rows="3" placeholder="Any notes..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;"
                        onclick="return confirm('Approve this cash drawer?')">
                        ✅ Approve Drawer
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Expense / payouts log -->
<?php if (!empty($expenses)): ?>
<div class="card">
    <div class="card-header"><h3 class="card-title">💸 Cash Payouts</h3></div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead><tr><th>Time</th><th>Category</th><th>Description</th><th>By</th><th style="text-align:right;">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($expenses as $ex): ?>
        <tr>
            <td class="small text-muted"><?= date('g:i A', strtotime($ex['created_at'])) ?></td>
            <td><span class="badge badge-secondary"><?= htmlspecialchars($ex['category']) ?></span></td>
            <td><?= htmlspecialchars($ex['description']) ?></td>
            <td class="small"><?= htmlspecialchars($ex['first_name'] ?? '—') ?></td>
            <td style="text-align:right;color:var(--red);font-weight:700;">-$<?= number_format($ex['amount'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:2px solid var(--border);">
            <td colspan="4" style="font-weight:700;text-align:right;">Total Payouts:</td>
            <td style="text-align:right;color:var(--red);font-weight:700;">-$<?= number_format(array_sum(array_column($expenses,'amount')),2) ?></td>
        </tr>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ── DRAWER LIST VIEW ───────────────────────────────── -->
<div class="page-header">
    <div>
        <h1 class="page-title">💵 Cash Drawers</h1>
    </div>
    <?php if (!$todayDrawer): ?>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('open-modal').style.display='flex'">
        + Open Today's Drawer
    </button>
    <?php elseif ($todayDrawer['status'] === 'open'): ?>
    <a href="index.php?view=<?= $todayDrawer['id'] ?>" class="btn btn-primary">🟢 Today's Drawer (Open)</a>
    <?php elseif ($todayDrawer['status'] === 'submitted'): ?>
    <a href="index.php?view=<?= $todayDrawer['id'] ?>" class="btn btn-secondary">📋 Today's Drawer (Pending Review)</a>
    <?php else: ?>
    <a href="index.php?view=<?= $todayDrawer['id'] ?>" class="btn btn-ghost">✅ Today's Drawer (Approved)</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <?php if ($isOwner): ?>
    <select name="location_id" class="form-control filter-select" onchange="this.form.submit()">
        <option value="0">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $filterLoc==$loc['id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="status" class="form-control filter-select" onchange="this.form.submit()">
        <option value="active" <?= $filterSt==='active'?'selected':'' ?>>Active (Open + Pending)</option>
        <option value="all"    <?= $filterSt==='all'   ?'selected':'' ?>>All</option>
        <option value="open"             <?= $filterSt==='open'            ?'selected':'' ?>>Open</option>
        <option value="submitted"        <?= $filterSt==='submitted'       ?'selected':'' ?>>Pending Review</option>
        <option value="manager_approved" <?= $filterSt==='manager_approved'?'selected':'' ?>>Approved</option>
    </select>
    <a href="?" class="btn btn-ghost">Reset</a>
</form>

<?php if (empty($drawers)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state"><div class="empty-icon">💵</div>
        <p>No drawers found. <?php if (!$todayDrawer): ?><a href="#" onclick="document.getElementById('open-modal').style.display='flex'">Open today's drawer.</a><?php endif; ?></p>
    </div>
</div></div>
<?php else: ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Drawer History (<?= count($drawers) ?>)</h2></div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="data-table" style="min-width:700px;">
        <thead>
            <tr>
                <th>Date</th>
                <?php if ($isOwner): ?><th>Location</th><?php endif; ?>
                <th>Opened By</th>
                <th>Opening Float</th>
                <th>Cash Sales</th>
                <th>Payouts</th>
                <th>Variance</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($drawers as $d): ?>
        <tr>
            <td style="font-weight:600;white-space:nowrap;"><?= date('M j, Y', strtotime($d['drawer_date'])) ?></td>
            <?php if ($isOwner): ?>
            <td><span class="badge badge-loc"><?= htmlspecialchars($d['loc_code']) ?></span></td>
            <?php endif; ?>
            <td class="small"><?= htmlspecialchars($d['opened_name'] ?? '—') ?></td>
            <td>$<?= number_format($d['opening_amount'],2) ?></td>
            <td style="color:var(--green);">$<?= number_format($d['cash_sales'],2) ?></td>
            <td style="color:var(--red);">$<?= number_format($d['cash_payouts'],2) ?></td>
            <td>
                <?php if ($d['variance'] !== null): ?>
                <span style="font-weight:700;color:<?= abs($d['variance'])<0.01?'var(--green)':($d['variance']>0?'var(--blue)':'var(--red)') ?>;">
                    <?= ($d['variance']>=0?'+':'') . '$' . number_format(abs($d['variance']),2) ?>
                </span>
                <?php else: ?>
                <span class="text-muted small">—</span>
                <?php endif; ?>
            </td>
            <td>
                <span style="color:<?= $STATUS_COLORS[$d['status']] ?? 'var(--text)' ?>;font-weight:600;">
                    <?= $STATUS_ICONS[$d['status']] ?? '' ?> <?= ucfirst(str_replace('_',' ',$d['status'])) ?>
                </span>
            </td>
            <td><a href="index.php?view=<?= $d['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Open drawer modal -->
<div id="open-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--surface);border-radius:12px;padding:1.5rem;max-width:380px;width:90%;">
    <h3 style="margin-bottom:1rem;">💵 Open Today's Drawer</h3>
    <form method="POST">
        <input type="hidden" name="action" value="open">
        <?php if ($isOwner || $isManager): ?>
        <div class="form-group">
            <label class="form-label">Location</label>
            <select name="location_id" class="form-control">
                <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= $loc['id']==$user['location_id']?'selected':'' ?>><?= htmlspecialchars($loc['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="location_id" value="<?= $user['location_id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label class="form-label">Opening Float Amount <span style="color:var(--red)">*</span></label>
            <div style="position:relative;">
                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                <input type="number" name="opening_amount" step="0.01" min="0" class="form-control" style="padding-left:24px;" required placeholder="200.00">
            </div>
            <div class="form-hint">Count the cash in the drawer right now.</div>
        </div>
        <div style="display:flex;gap:.5rem;margin-top:1rem;">
            <button type="submit" class="btn btn-primary">Open Drawer</button>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('open-modal').style.display='none'">Cancel</button>
        </div>
    </form>
</div>
</div>

<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
