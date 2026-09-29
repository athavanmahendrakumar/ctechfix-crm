<?php
// ============================================================
// Cash Accounts - Movements, Transfers, Hub Count
// Manager & Owner only
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/'); exit;
}

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$msg = ''; $msgType = 'success';

// ============================================================
// POST handlers
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Log a transfer between accounts ──────────────────────
    if ($action === 'add_transfer') {
        try {
            $fromId  = intval($_POST['from_account_id'] ?? 0) ?: null;
            $toId    = intval($_POST['to_account_id']   ?? 0) ?: null;
            $amount  = floatval($_POST['amount'] ?? 0);
            $notes   = trim($_POST['notes'] ?? '');
            $movedAt = trim($_POST['moved_at'] ?? date('Y-m-d')) . ' ' . trim($_POST['moved_time'] ?? date('H:i:s'));

            if ($amount > 0 && ($fromId || $toId)) {
                DB::execute(
                    'INSERT INTO cash_movements
                     (from_account_id, to_account_id, amount, movement_type, notes, moved_at, created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$fromId, $toId, $amount, 'transfer', $notes ?: null, $movedAt, $user['id']]
                );
                header('Location: ' . APP_URL . '/modules/cash_accounts/?msg=transfer_saved'); exit;
            } else {
                $msg = 'Please enter an amount and select at least one account.';
                $msgType = 'warning';
            }
        } catch (\Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }

    // ── Log a deposit (cash coming in from outside) ──────────
    elseif ($action === 'add_deposit') {
        try {
            $toId    = intval($_POST['to_account_id'] ?? 0) ?: null;
            $amount  = floatval($_POST['amount'] ?? 0);
            $ref     = trim($_POST['reference'] ?? '');
            $notes   = trim($_POST['notes'] ?? '');
            $movedAt = trim($_POST['moved_at'] ?? date('Y-m-d')) . ' ' . trim($_POST['moved_time'] ?? date('H:i:s'));

            if ($amount > 0 && $toId) {
                DB::execute(
                    'INSERT INTO cash_movements
                     (to_account_id, amount, movement_type, reference, notes, moved_at, created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$toId, $amount, 'deposit', $ref ?: null, $notes ?: null, $movedAt, $user['id']]
                );
                header('Location: ' . APP_URL . '/modules/cash_accounts/?msg=deposit_saved'); exit;
            } else {
                $msg = 'Please enter an amount and select an account.';
                $msgType = 'warning';
            }
        } catch (\Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }

    // ── Log an adjustment (correction / manual fix) ──────────
    elseif ($action === 'add_adjustment') {
        try {
            $acctId  = intval($_POST['account_id'] ?? 0);
            $amount  = floatval($_POST['amount'] ?? 0);
            $dir     = $_POST['direction'] ?? 'in'; // in = add, out = remove
            $notes   = trim($_POST['notes'] ?? '');
            $movedAt = trim($_POST['moved_at'] ?? date('Y-m-d')) . ' 12:00:00';

            if ($amount > 0 && $acctId) {
                $fromId = $dir === 'out' ? $acctId : null;
                $toId   = $dir === 'in'  ? $acctId : null;
                DB::execute(
                    'INSERT INTO cash_movements
                     (from_account_id, to_account_id, amount, movement_type, notes, moved_at, created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$fromId, $toId, $amount, 'adjustment', $notes ?: null, $movedAt, $user['id']]
                );
                header('Location: ' . APP_URL . '/modules/cash_accounts/?msg=adj_saved'); exit;
            } else {
                $msg = 'Please enter an amount and select an account.';
                $msgType = 'warning';
            }
        } catch (\Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }

    // ── Hub count ─────────────────────────────────────────────
    elseif ($action === 'hub_count' && $isOwner) {
        try {
            $acctId      = intval($_POST['account_id'] ?? 0);
            $actual      = floatval($_POST['actual_amount'] ?? 0);
            $expected    = floatval($_POST['expected_amount'] ?? 0);
            $explanation = trim($_POST['explanation'] ?? '');
            $variance    = $actual - $expected;

            if ($acctId) {
                DB::execute(
                    'INSERT INTO hub_counts
                     (account_id, expected_amount, actual_amount, variance, explanation, counted_by, counted_at)
                     VALUES (?,?,?,?,?,?,NOW())',
                    [$acctId, $expected, $actual, $variance, $explanation ?: null, $user['id']]
                );
                // Log as adjustment if variance exists
                if (abs($variance) > 0.00) {
                    $fromId = $variance < 0 ? $acctId : null;
                    $toId   = $variance > 0 ? $acctId : null;
                    DB::execute(
                        'INSERT INTO cash_movements
                         (from_account_id, to_account_id, amount, movement_type, notes, moved_at, flagged, flag_reason, created_by)
                         VALUES (?,?,?,?,?,NOW(),?,?,?)',
                        [$fromId, $toId, abs($variance), 'adjustment',
                         'Hub count variance', 1,
                         'Variance of $' . number_format(abs($variance), 2) . '. ' . $explanation,
                         $user['id']]
                    );
                }
                header('Location: ' . APP_URL . '/modules/cash_accounts/?msg=count_saved&tab=hub'); exit;
            }
        } catch (\Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }
}

// ============================================================
// Data
// ============================================================
$accounts = DB::query(
    "SELECT * FROM cash_accounts WHERE is_active=1 ORDER BY sort_order, name", []
);

// Calculate running balance for each account
// Balance = opening_balance + SUM(in movements) - SUM(out movements)
$accountBalances = [];
foreach ($accounts as $acct) {
    $inSum = (float)(DB::queryOne(
        "SELECT COALESCE(SUM(amount),0) AS s FROM cash_movements WHERE to_account_id=?",
        [$acct['id']]
    )['s'] ?? 0);
    $outSum = (float)(DB::queryOne(
        "SELECT COALESCE(SUM(amount),0) AS s FROM cash_movements WHERE from_account_id=?",
        [$acct['id']]
    )['s'] ?? 0);
    $accountBalances[$acct['id']] = (float)$acct['opening_balance'] + $inSum - $outSum;
}

// Recent movements (last 100)
$movements = DB::query(
    "SELECT m.*,
            fa.name AS from_name,
            ta.name AS to_name,
            CONCAT(u.first_name, ' ', u.last_name) AS by_name
     FROM cash_movements m
     LEFT JOIN cash_accounts fa ON fa.id = m.from_account_id
     LEFT JOIN cash_accounts ta ON ta.id = m.to_account_id
     LEFT JOIN users u ON u.id = m.created_by
     ORDER BY m.moved_at DESC LIMIT 100", []
);

// Hub accounts (for count form)
$hubAccounts = array_filter($accounts, fn($a) => $a['type'] === 'hub');

// Recent hub counts
$hubCounts = DB::query(
    "SELECT hc.*, ca.name AS account_name,
            CONCAT(u.first_name, ' ', u.last_name) AS counted_by_name
     FROM hub_counts hc
     LEFT JOIN cash_accounts ca ON ca.id = hc.account_id
     LEFT JOIN users u ON u.id = hc.counted_by
     ORDER BY hc.counted_at DESC LIMIT 20", []
);

// Flagged movements
$flagged = DB::query(
    "SELECT m.*,
            fa.name AS from_name,
            ta.name AS to_name,
            CONCAT(u.first_name, ' ', u.last_name) AS by_name
     FROM cash_movements m
     LEFT JOIN cash_accounts fa ON fa.id = m.from_account_id
     LEFT JOIN cash_accounts ta ON ta.id = m.to_account_id
     LEFT JOIN users u ON u.id = m.created_by
     WHERE m.flagged = 1
     ORDER BY m.moved_at DESC LIMIT 50", []
);

$activeTab = $_GET['tab'] ?? 'overview';

$msgMap = [
    'transfer_saved' => 'Transfer logged.',
    'deposit_saved'  => 'Deposit logged.',
    'adj_saved'      => 'Adjustment saved.',
    'count_saved'    => 'Hub count recorded.',
];

$pageTitle = 'Cash Tracker';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): ?>
<div class="alert alert-success"><?= $msgMap[$_GET['msg']] ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">💰 Cash Tracker</h1>
        <p class="page-sub">Track cash across all accounts</p>
    </div>
</div>

<!-- Account Balance Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
<?php foreach ($accounts as $acct):
    $bal = $accountBalances[$acct['id']];
    $color = $bal >= 0 ? 'var(--green)' : 'var(--red)';
    $icons = ['hub'=>'🏠','card'=>'💳','bank'=>'🏦','till'=>'🗄️','other'=>'💰'];
    $icon  = $icons[$acct['type']] ?? '💰';
?>
<div class="card" style="padding:1rem;">
    <div style="font-size:1.4rem;margin-bottom:.25rem;"><?= $icon ?></div>
    <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
        <?= htmlspecialchars($acct['name']) ?>
    </div>
    <div style="font-size:1.5rem;font-weight:700;color:<?= $color ?>;margin:.25rem 0;">
        $<?= number_format($bal, 2) ?>
    </div>
    <div style="font-size:11px;color:var(--text-3);">
        Opening: $<?= number_format($acct['opening_balance'], 2) ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Tabs -->
<?php
$tabs = [
    'overview' => '📋 Overview',
    'transfer' => '↔️ Transfer',
    'deposit'  => '⬇️ Deposit',
    'adjust'   => '🔧 Adjustment',
];
if ($isOwner) $tabs['hub'] = '🏠 Hub Count';
if (count($flagged) > 0) $tabs['flagged'] = '⚠️ Flagged (' . count($flagged) . ')';
?>
<div style="display:flex;gap:.25rem;border-bottom:2px solid var(--border);margin-bottom:1.5rem;flex-wrap:wrap;">
<?php foreach ($tabs as $key => $label): ?>
    <a href="?tab=<?= $key ?>"
       style="padding:.5rem 1.25rem;font-size:14px;font-weight:600;border-radius:6px 6px 0 0;text-decoration:none;
              border:1px solid var(--border);border-bottom:none;
              background:<?= $activeTab===$key ? 'var(--surface)' : 'var(--surface-2)' ?>;
              color:<?= $activeTab===$key ? 'var(--blue)' : ($key==='flagged' ? 'var(--red)' : 'var(--text-2)') ?>;">
        <?= $label ?>
    </a>
<?php endforeach; ?>
</div>

<!-- ── OVERVIEW TAB ─────────────────────────────────────────── -->
<?php if ($activeTab === 'overview'): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Recent Movements</h2>
        <span class="text-muted small">Last 100 entries</span>
    </div>
    <?php if (empty($movements)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon">💸</div>
            <p>No movements recorded yet.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>Type</th>
                <th>From</th>
                <th>To</th>
                <th>Amount</th>
                <th>Notes</th>
                <th>By</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($movements as $m):
            $typeLabels = [
                'transfer'   => ['label'=>'Transfer',   'color'=>'var(--blue)'],
                'expense'    => ['label'=>'Expense',    'color'=>'var(--red)'],
                'deposit'    => ['label'=>'Deposit',    'color'=>'var(--green)'],
                'adjustment' => ['label'=>'Adjustment', 'color'=>'var(--amber)'],
                'opening'    => ['label'=>'Opening',    'color'=>'var(--text-3)'],
            ];
            $tl = $typeLabels[$m['movement_type']] ?? ['label'=>$m['movement_type'],'color'=>'var(--text-2)'];
        ?>
        <tr <?= $m['flagged'] ? 'style="background:rgba(239,68,68,.05);"' : '' ?>>
            <td style="white-space:nowrap;font-size:13px;">
                <?= date('M j, Y', strtotime($m['moved_at'])) ?><br>
                <span class="text-muted" style="font-size:11px;"><?= date('g:i A', strtotime($m['moved_at'])) ?></span>
            </td>
            <td>
                <span style="font-size:12px;font-weight:700;color:<?= $tl['color'] ?>;">
                    <?= $tl['label'] ?>
                </span>
                <?php if ($m['flagged']): ?>
                <span title="<?= htmlspecialchars($m['flag_reason'] ?? '') ?>">⚠️</span>
                <?php endif; ?>
            </td>
            <td style="font-size:13px;"><?= $m['from_name'] ? htmlspecialchars($m['from_name']) : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:13px;"><?= $m['to_name']   ? htmlspecialchars($m['to_name'])   : '<span class="text-muted">—</span>' ?></td>
            <td style="font-weight:700;">$<?= number_format($m['amount'], 2) ?></td>
            <td style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars($m['notes'] ?? $m['reference'] ?? '') ?></td>
            <td style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars($m['by_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── TRANSFER TAB ─────────────────────────────────────────── -->
<?php elseif ($activeTab === 'transfer'): ?>
<div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">
<div class="card">
    <div class="card-header"><h2 class="card-title">Log a Transfer</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_transfer">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label">From Account</label>
                    <select name="from_account_id" class="form-control">
                        <option value="">— None (external in) —</option>
                        <?php foreach ($accounts as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> ($<?= number_format($accountBalances[$a['id']],2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">To Account</label>
                    <select name="to_account_id" class="form-control">
                        <option value="">— None (external out) —</option>
                        <?php foreach ($accounts as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> ($<?= number_format($accountBalances[$a['id']],2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label">Amount *</label>
                <div style="position:relative;">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                           placeholder="0.00" style="padding-left:24px;" required>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label">Date</label>
                    <input type="date" name="moved_at" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="form-label">Time</label>
                    <input type="time" name="moved_time" class="form-control" value="<?= date('H:i') ?>">
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"
                          placeholder="e.g. Took cash from Pickering till to Hub after close"></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Log Transfer</button>
        </form>
    </div>
</div>
<div class="card" style="background:var(--surface-2);border:none;">
    <div class="card-body">
        <div style="font-weight:700;margin-bottom:.75rem;">Common transfers</div>
        <div style="font-size:13px;color:var(--text-2);line-height:1.8;">
            <b>Till → Hub:</b> Cash collected at location, brought home at end of day.<br>
            <b>Hub → BMO:</b> Owner deposits cash from Hub into bank.<br>
            <b>Hub → Till:</b> Owner sends float back to a location.<br>
            <b>Square Card → Hub:</b> Transferring Square card funds home.
        </div>
        <hr style="margin:1rem 0;border-color:var(--border);">
        <div style="font-size:12px;color:var(--text-3);">
            You can backdate a transfer by changing the date and time. The record will be logged with the date you enter.
        </div>
    </div>
</div>
</div>

<!-- ── DEPOSIT TAB ──────────────────────────────────────────── -->
<?php elseif ($activeTab === 'deposit'): ?>
<div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">
<div class="card">
    <div class="card-header"><h2 class="card-title">Log a Deposit</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_deposit">

            <div style="margin-bottom:1rem;">
                <label class="form-label">Into Account *</label>
                <select name="to_account_id" class="form-control" required>
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> ($<?= number_format($accountBalances[$a['id']],2) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label">Amount *</label>
                <div style="position:relative;">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                           placeholder="0.00" style="padding-left:24px;" required>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label">Date</label>
                    <input type="date" name="moved_at" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="form-label">Time</label>
                    <input type="time" name="moved_time" class="form-control" value="<?= date('H:i') ?>">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control"
                       placeholder="e.g. Customer payment, insurance payout">
            </div>

            <div style="margin-bottom:1.5rem;">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional details"></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Log Deposit</button>
        </form>
    </div>
</div>
<div class="card" style="background:var(--surface-2);border:none;">
    <div class="card-body" style="font-size:13px;color:var(--text-2);line-height:1.8;">
        <div style="font-weight:700;margin-bottom:.5rem;">Use this for:</div>
        Cash coming into an account from outside the system — a customer paying cash directly, a refund received, or any other income not tracked elsewhere.
    </div>
</div>
</div>

<!-- ── ADJUSTMENT TAB ──────────────────────────────────────── -->
<?php elseif ($activeTab === 'adjust'): ?>
<div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">
<div class="card">
    <div class="card-header"><h2 class="card-title">Log an Adjustment</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_adjustment">

            <div style="margin-bottom:1rem;">
                <label class="form-label">Account *</label>
                <select name="account_id" class="form-control" required>
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> ($<?= number_format($accountBalances[$a['id']],2) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label">Amount *</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                               placeholder="0.00" style="padding-left:24px;" required>
                    </div>
                </div>
                <div>
                    <label class="form-label">Direction *</label>
                    <select name="direction" class="form-control" required>
                        <option value="in">+ Add to balance</option>
                        <option value="out">- Remove from balance</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label">Date</label>
                <input type="date" name="moved_at" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>

            <div style="margin-bottom:1.5rem;">
                <label class="form-label">Reason *</label>
                <textarea name="notes" class="form-control" rows="2"
                          placeholder="Why is this adjustment needed?" required></textarea>
            </div>

            <button type="submit" class="btn btn-warning" style="width:100%;">Save Adjustment</button>
        </form>
    </div>
</div>
<div class="card" style="background:var(--surface-2);border:none;">
    <div class="card-body" style="font-size:13px;color:var(--text-2);line-height:1.8;">
        <div style="font-weight:700;margin-bottom:.5rem;">Use for corrections only.</div>
        An adjustment corrects the balance when a mistake was made — e.g. you forgot to log a transfer, or a count was wrong. Always add a reason so there is an audit trail.
    </div>
</div>
</div>

<!-- ── HUB COUNT TAB ────────────────────────────────────────── -->
<?php elseif ($activeTab === 'hub' && $isOwner): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

<div class="card">
    <div class="card-header"><h2 class="card-title">Record Hub Count</h2></div>
    <div class="card-body">
        <?php if (empty($hubAccounts)): ?>
        <div class="alert alert-warning">No Hub accounts found. Add a Hub account in Settings → Cash Accounts first.</div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="hub_count">

            <div style="margin-bottom:1rem;">
                <label class="form-label">Hub Account *</label>
                <select name="account_id" class="form-control" required onchange="updateExpected(this)">
                    <option value="">— Select —</option>
                    <?php foreach ($hubAccounts as $a): ?>
                    <option value="<?= $a['id'] ?>" data-balance="<?= $accountBalances[$a['id']] ?>">
                        <?= htmlspecialchars($a['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label">Expected (System Balance)</label>
                <div style="position:relative;">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                    <input type="number" name="expected_amount" id="expected_amount" class="form-control"
                           step="0.01" readonly style="padding-left:24px;background:var(--surface-2);" value="0.00">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label">Actual Cash Counted *</label>
                <div style="position:relative;">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                    <input type="number" name="actual_amount" class="form-control" step="0.01" min="0"
                           placeholder="0.00" style="padding-left:24px;" required oninput="calcVariance()">
                </div>
            </div>

            <div style="margin-bottom:1rem;padding:.75rem;background:var(--surface-2);border-radius:8px;">
                <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Variance</div>
                <div id="variance-display" style="font-size:1.4rem;font-weight:700;color:var(--text-2);">$0.00</div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label class="form-label">Explanation <span style="color:var(--text-3);font-size:12px;">(required if variance)</span></label>
                <textarea name="explanation" id="explanation" class="form-control" rows="2"
                          placeholder="e.g. Spent $45 on gas, forgot to log it"></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Record Count</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">Count History</h2></div>
    <?php if (empty($hubCounts)): ?>
    <div class="card-body"><p class="text-muted">No counts recorded yet.</p></div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Account</th><th>Expected</th><th>Actual</th><th>Variance</th><th>By</th></tr>
        </thead>
        <tbody>
        <?php foreach ($hubCounts as $hc):
            $var = (float)$hc['variance'];
            $varColor = $var == 0 ? 'var(--green)' : ($var < 0 ? 'var(--red)' : 'var(--amber)');
        ?>
        <tr>
            <td style="font-size:13px;"><?= date('M j, Y g:i A', strtotime($hc['counted_at'])) ?></td>
            <td><?= htmlspecialchars($hc['account_name'] ?? '') ?></td>
            <td>$<?= number_format($hc['expected_amount'], 2) ?></td>
            <td>$<?= number_format($hc['actual_amount'], 2) ?></td>
            <td style="font-weight:700;color:<?= $varColor ?>;">
                <?= $var >= 0 ? '+' : '' ?>$<?= number_format($var, 2) ?>
            </td>
            <td style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars($hc['counted_by_name'] ?? '') ?></td>
        </tr>
        <?php if ($hc['explanation']): ?>
        <tr style="background:var(--surface-2);">
            <td colspan="6" style="font-size:12px;color:var(--text-3);padding:.35rem 1rem;">
                Note: <?= htmlspecialchars($hc['explanation']) ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div>

<script>
function updateExpected(sel) {
    const bal = sel.options[sel.selectedIndex]?.dataset.balance ?? '0';
    document.getElementById('expected_amount').value = parseFloat(bal).toFixed(2);
    calcVariance();
}
function calcVariance() {
    const expected = parseFloat(document.getElementById('expected_amount').value || 0);
    const actual   = parseFloat(document.querySelector('[name="actual_amount"]')?.value || 0);
    const variance = actual - expected;
    const el = document.getElementById('variance-display');
    el.textContent = (variance >= 0 ? '+' : '') + '$' + Math.abs(variance).toFixed(2);
    el.style.color = variance === 0 ? 'var(--green)' : variance < 0 ? 'var(--red)' : 'var(--amber)';
}
</script>

<!-- ── FLAGGED TAB ───────────────────────────────────────────── -->
<?php elseif ($activeTab === 'flagged'): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">⚠️ Flagged Movements</h2>
        <span class="text-muted small"><?= count($flagged) ?> items need review</span>
    </div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Type</th><th>From</th><th>To</th><th>Amount</th><th>Reason</th><th>By</th></tr>
        </thead>
        <tbody>
        <?php foreach ($flagged as $m): ?>
        <tr style="background:rgba(239,68,68,.05);">
            <td style="font-size:13px;white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($m['moved_at'])) ?></td>
            <td style="font-size:12px;font-weight:700;color:var(--amber);"><?= ucfirst($m['movement_type']) ?></td>
            <td><?= $m['from_name'] ? htmlspecialchars($m['from_name']) : '—' ?></td>
            <td><?= $m['to_name']   ? htmlspecialchars($m['to_name'])   : '—' ?></td>
            <td style="font-weight:700;">$<?= number_format($m['amount'],2) ?></td>
            <td style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars($m['flag_reason'] ?? '') ?></td>
            <td style="font-size:12px;color:var(--text-3);"><?= htmlspecialchars($m['by_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
