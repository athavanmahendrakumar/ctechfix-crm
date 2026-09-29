<?php
// ============================================================
// SMS — Send & Log
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';

Auth::boot();
Auth::require();

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$isTraining = Auth::isTraining();
$error   = '';
$success = '';

// ── Handle outbound SMS send ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isTraining) {
    $locationId = intval($_POST['location_id'] ?? $user['location_id']);
    $loc        = DB::queryOne('SELECT * FROM locations WHERE id=? LIMIT 1', [$locationId]);
    $toNumber   = preg_replace('/\D/', '', $_POST['to_number'] ?? '');
    if (strlen($toNumber) === 11 && $toNumber[0] === '1') $toNumber = substr($toNumber, 1);
    $message = trim($_POST['message'] ?? '');

    if ($loc && $toNumber && $message) {
        $did      = preg_replace('/\D/', '', $loc['did'] ?? '');
        $result   = VoipMS::sendSMS($did, $toNumber, $message);   // static — never new VoipMS()
        $customer = DB::queryOne('SELECT id FROM customers WHERE phone_normalized=? LIMIT 1', [$toNumber]);

        if ($result === true) {
            DB::execute(
                'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_at)
                 VALUES (?,?,?,?,?,?,?,NOW())',
                [$locationId, $customer['id'] ?? null, 'outbound', $loc['did'], $toNumber, $message, 'sent']
            );
            $success = 'SMS sent to ' . formatPhone($toNumber);
        } else {
            $error = 'SMS failed: ' . (is_string($result) ? $result : 'unknown error');
        }
    }
}

// ── Date range helper ───────────────────────────────────────
function resolveDateRange(string $period, string $customFrom, string $customTo): array {
    $today = date('Y-m-d');
    switch ($period) {
        case 'today':     return [$today, $today];
        case 'yesterday': $y = date('Y-m-d', strtotime('-1 day')); return [$y, $y];
        case 'this_week': return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))];
        case 'this_month': return [date('Y-m-01'), date('Y-m-t')];
        case 'this_year':  return [date('Y-01-01'), date('Y-12-31')];
        case 'last_year':  $y = (int)date('Y') - 1; return ["{$y}-01-01", "{$y}-12-31"];
        case 'custom':     return [$customFrom ?: $today, $customTo ?: $today];
        default:           return ['', ''];
    }
}

// ── Filters ─────────────────────────────────────────────────
$locationFilter = $_GET['location']  ?? 'all';
$period         = $_GET['period']    ?? 'today';
$customFrom     = $_GET['date_from'] ?? '';
$customTo       = $_GET['date_to']   ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;
$offset         = ($page - 1) * $perPage;

[$dateFrom, $dateTo] = resolveDateRange($period, $customFrom, $customTo);

$smsWhere  = ['1=1'];
$smsParams = [];

// Location restriction
if (!$isOwner && !$isManager) {
    $smsWhere[]  = 's.location_id = ?';
    $smsParams[] = $user['location_id'];
} elseif ($locationFilter !== 'all') {
    $smsWhere[]  = 'l.code = ?';
    $smsParams[] = $locationFilter;
}

// Date filter
if ($dateFrom && $dateTo) {
    $smsWhere[]  = 'DATE(s.sent_at) BETWEEN ? AND ?';
    $smsParams[] = $dateFrom;
    $smsParams[] = $dateTo;
}

$whereSQL = implode(' AND ', $smsWhere);

$recentSMS = DB::query(
    "SELECT s.*, l.code AS loc_code,
            COALESCE(c.first_name, c2.first_name) AS first_name,
            COALESCE(c.last_name,  c2.last_name)  AS last_name,
            COALESCE(c.id,         c2.id)          AS customer_id
     FROM sms_messages s
     LEFT JOIN locations l  ON l.id  = s.location_id
     LEFT JOIN customers c  ON c.id  = s.customer_id
     LEFT JOIN customers c2 ON c2.phone_normalized = s.contact_number AND s.customer_id IS NULL
     WHERE {$whereSQL}
     ORDER BY s.sent_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $smsParams
);

$failedCount = DB::queryOne("SELECT COUNT(*) AS cnt FROM sms_messages WHERE status='failed'")['cnt'] ?? 0;
$locations   = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// Helper: preserve filters in pagination links
function smsFilterQS(array $overrides = []): string {
    global $locationFilter, $period, $customFrom, $customTo;
    $base = ['location' => $locationFilter, 'period' => $period, 'date_from' => $customFrom, 'date_to' => $customTo];
    return http_build_query(array_merge($base, $overrides));
}

$pageTitle = 'SMS';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">💬 SMS</h1>
    </div>
    <div style="display:flex;gap:.5rem;">
        <?php if ($failedCount > 0): ?>
        <a href="sms-failed.php" class="btn btn-danger">⚠ <?= $failedCount ?> Failed</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary">← Calls</a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($isTraining): ?><div class="alert alert-warning">⚠ Training mode — SMS will NOT be sent.</div><?php endif; ?>

<div class="repair-grid">

    <!-- Send SMS -->
    <div class="repair-col-side">
        <div class="card">
            <div class="card-header"><h2 class="card-title">📤 Send SMS</h2></div>
            <div class="card-body">
                <form method="POST" data-once="true">
                    <div class="form-group">
                        <label class="form-label">From Location</label>
                        <select name="location_id" class="form-control">
                            <?php foreach ($locations as $loc): ?>
                            <?php $defaultLoc = intval($_GET['location_id'] ?? $user['location_id']); ?>
                            <option value="<?= $loc['id'] ?>" <?= $defaultLoc==$loc['id']?'selected':'' ?>>
                                <?= htmlspecialchars($loc['name']) ?> — <?= htmlspecialchars(formatPhone($loc['did'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">To Phone Number</label>
                        <input type="tel" name="to_number" class="form-control"
                               value="<?= htmlspecialchars($_GET['to'] ?? '') ?>"
                               placeholder="(905) 555-1234" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control" rows="5" maxlength="160"
                                  placeholder="Type message..." required
                                  oninput="document.getElementById('charCount').textContent=this.value.length"><?= htmlspecialchars($_GET['msg'] ?? '') ?></textarea>
                        <div class="form-hint" style="text-align:right;">
                            <span id="charCount"><?= strlen($_GET['msg'] ?? '') ?></span>/160 characters
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;" data-sending="Sending…">Send SMS →</button>
                </form>
            </div>
        </div>

        <!-- Follow-Up -->
        <div class="card" style="margin-top:1rem;">
            <div class="card-header"><h2 class="card-title">📋 Create Follow-Up</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= APP_URL ?>/modules/calls/followups.php">
                    <input type="hidden" name="action" value="create">
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-control">
                            <option value="callback">📞 Call Back</option>
                            <option value="appointment">🏪 Customer Visit</option>
                            <option value="general">📋 General Task</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Task Description *</label>
                        <input type="text" name="title" class="form-control" required
                               placeholder="e.g. Call back re: iPhone 14 repair">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Customer Phone <span class="text-muted small">(optional)</span></label>
                        <input type="text" name="customer_phone" class="form-control"
                               placeholder="Auto-links to customer">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date & Time *</label>
                        <input type="datetime-local" name="due_at" class="form-control" required
                               value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Context for whoever picks this up..."></textarea>
                    </div>
                    <?php if ($isOwner || $isManager): ?>
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <select name="location_id" class="form-control">
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['id'] ?>"
                                <?= $loc['id'] == $user['location_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-secondary" style="width:100%;">📋 Create Follow-Up</button>
                </form>
            </div>
        </div>
    </div>

    <!-- SMS Log -->
    <div class="repair-col-main">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📨 SMS Log</h2>
                <span class="text-muted small"><?= count($recentSMS) === $perPage ? $perPage.'+' : count($recentSMS) ?> messages</span>
            </div>

            <!-- Filters -->
            <div class="card-body" style="padding:.75rem 1rem;border-bottom:1px solid var(--border);">
                <form method="GET" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;" id="smsFilterForm">
                    <?php if ($isOwner || $isManager): ?>
                    <select name="location" class="form-control filter-select" style="width:auto;" onchange="this.form.submit()">
                        <option value="all">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= htmlspecialchars($loc['code']) ?>" <?= $locationFilter===$loc['code']?'selected':'' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <select name="period" class="form-control filter-select" style="width:auto;" id="smsPeriodSelect"
                            onchange="toggleSmsCustom(this.value);this.form.submit()">
                        <option value="all"        <?= $period==='all'        ?'selected':'' ?>>All Time</option>
                        <option value="today"      <?= $period==='today'      ?'selected':'' ?>>Today</option>
                        <option value="yesterday"  <?= $period==='yesterday'  ?'selected':'' ?>>Yesterday</option>
                        <option value="this_week"  <?= $period==='this_week'  ?'selected':'' ?>>This Week</option>
                        <option value="this_month" <?= $period==='this_month' ?'selected':'' ?>>This Month</option>
                        <option value="this_year"  <?= $period==='this_year'  ?'selected':'' ?>>This Year</option>
                        <option value="last_year"  <?= $period==='last_year'  ?'selected':'' ?>>Last Year</option>
                        <option value="custom"     <?= $period==='custom'     ?'selected':'' ?>>Custom Range</option>
                    </select>

                    <span id="smsCustomWrap" style="display:<?= $period==='custom'?'flex':'none' ?>;gap:.4rem;align-items:center;">
                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($customFrom) ?>"
                               style="width:145px;" onchange="this.form.submit()">
                        <span style="color:var(--text-2);font-size:13px;">→</span>
                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($customTo) ?>"
                               style="width:145px;" onchange="this.form.submit()">
                    </span>

                    <a href="sms.php" class="btn btn-ghost btn-sm">Reset</a>

                    <?php if ($dateFrom && $dateTo): ?>
                    <span class="text-muted small" style="align-self:center;">
                        <?= $dateFrom === $dateTo ? date('M j, Y', strtotime($dateFrom)) : date('M j', strtotime($dateFrom)).' – '.date('M j, Y', strtotime($dateTo)) ?>
                    </span>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($recentSMS)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon">💬</div>
                    <p>No SMS messages found for this filter.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr><th>Time</th><th>Loc</th><th>Dir</th><th>Number</th><th>Customer</th><th>Message</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentSMS as $sms): ?>
                <tr>
                    <td class="text-muted small" style="white-space:nowrap;"><?= timeAgo($sms['sent_at']) ?></td>
                    <td><span class="badge badge-loc"><?= htmlspecialchars($sms['loc_code'] ?? '—') ?></span></td>
                    <td>
                        <?= $sms['direction']==='inbound'
                            ? '<span style="color:var(--green);">↙ In</span>'
                            : '<span style="color:var(--blue);">↗ Out</span>' ?>
                    </td>
                    <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars(formatPhone($sms['contact_number'])) ?></td>
                    <td class="small">
                        <?php if ($sms['first_name']): ?>
                        <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= $sms['customer_id'] ?>">
                            <?= htmlspecialchars($sms['first_name'] . ' ' . $sms['last_name']) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-muted">Unknown</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:260px;font-size:13px;">
                        <?= htmlspecialchars(mb_strimwidth($sms['message'], 0, 80, '…')) ?>
                    </td>
                    <td>
                        <?php if ($sms['status']==='failed'): ?>
                        <span style="color:var(--red);">Failed</span>
                        <a href="sms-failed.php?retry=<?= $sms['id'] ?>" class="btn btn-sm btn-danger" style="margin-left:4px;">Retry</a>
                        <?php else: ?>
                        <span style="color:var(--green);"><?= ucfirst(htmlspecialchars($sms['status'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Pagination -->
            <?php if ($page > 1 || count($recentSMS) === $perPage): ?>
            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);">
                <span class="text-muted small">Page <?= $page ?></span>
                <div style="display:flex;gap:.5rem;">
                    <?php if ($page > 1): ?>
                    <a href="?<?= smsFilterQS(['page' => $page-1]) ?>" class="btn btn-sm btn-secondary">← Prev</a>
                    <?php endif; ?>
                    <?php if (count($recentSMS) === $perPage): ?>
                    <a href="?<?= smsFilterQS(['page' => $page+1]) ?>" class="btn btn-sm btn-secondary">Next →</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
function toggleSmsCustom(val) {
    document.getElementById('smsCustomWrap').style.display = val === 'custom' ? 'flex' : 'none';
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
