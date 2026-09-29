<?php
// ============================================================
// Walk-in Inquiries — log customers needing callback / quote
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user       = Auth::user();
$isOwner    = Auth::isOwner();
$isManager  = Auth::isManager();
$locationId = (int)$user['location_id'];
$locations  = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

$msg = ''; $msgType = 'success';

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Log new inquiry
    if ($action === 'log') {
        try {
            $name    = trim($_POST['customer_name'] ?? '');
            $phone   = preg_replace('/\D/', '', trim($_POST['customer_phone'] ?? ''));
            $brand   = trim($_POST['device_brand'] ?? '');
            $model   = trim($_POST['device_model'] ?? '');
            $desc    = trim($_POST['description'] ?? '');
            $estPrice = $_POST['estimated_price'] !== '' ? floatval($_POST['estimated_price']) : null;
            $locId   = ($isOwner || $isManager) ? intval($_POST['location_id'] ?? $locationId) : $locationId;

            if (!$name)  { $msg = 'Customer name is required.'; $msgType = 'danger'; }
            elseif (strlen($phone) < 10) { $msg = 'Enter a valid 10-digit phone number.'; $msgType = 'danger'; }
            else {
                DB::execute(
                    "INSERT INTO walk_in_inquiries
                     (location_id, logged_by, customer_name, customer_phone, device_brand, device_model, description, estimated_price)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$locId, $user['id'], $name, $phone, $brand ?: null, $model ?: null, $desc ?: null, $estPrice]
                );
                $msg = "Inquiry logged for {$name} — remember to call back!";
            }
        } catch (Exception $ex) {
            $msg = 'Error: ' . $ex->getMessage(); $msgType = 'danger';
        }
    }

    // Update status (quoted / lost)
    if ($action === 'update_status') {
        try {
            $id        = intval($_POST['inquiry_id']);
            $newStatus = $_POST['status'] ?? '';
            $notes     = trim($_POST['notes'] ?? '');
            $allowed   = ['quoted','lost','pending'];
            if ($id && in_array($newStatus, $allowed)) {
                DB::execute(
                    "UPDATE walk_in_inquiries SET status=?, notes=IF(?='', notes, ?) WHERE id=?",
                    [$newStatus, $notes, $notes, $id]
                );
                $msg = 'Inquiry updated.';
            }
        } catch (Exception $ex) {
            $msg = 'Error: ' . $ex->getMessage(); $msgType = 'danger';
        }
    }

    // Convert to repair ticket
    if ($action === 'convert') {
        try {
            $id = intval($_POST['inquiry_id']);
            $inq = DB::queryOne("SELECT * FROM walk_in_inquiries WHERE id=?", [$id]);
            if ($inq) {
                DB::execute("UPDATE walk_in_inquiries SET status='converted' WHERE id=?", [$id]);
                // Redirect to new repair with pre-filled params
                $params = http_build_query([
                    'prefill_name'   => $inq['customer_name'],
                    'prefill_phone'  => $inq['customer_phone'],
                    'prefill_brand'  => $inq['device_brand'] ?? '',
                    'prefill_model'  => $inq['device_model'] ?? '',
                    'prefill_issue'  => $inq['description'] ?? '',
                    'inquiry_id'     => $id,
                ]);
                header('Location: ' . APP_URL . '/modules/repairs/new.php?' . $params); exit;
            }
        } catch (Exception $ex) {
            $msg = 'Error: ' . $ex->getMessage(); $msgType = 'danger';
        }
    }
}

// ── Filters ───────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'pending';
$filterLoc    = ($isOwner || $isManager) ? intval($_GET['location_id'] ?? 0) : $locationId;

$where  = ['1=1'];
$params = [];

if ($filterStatus !== 'all') {
    $where[]  = 'i.status = ?';
    $params[] = $filterStatus;
}
if ($filterLoc) {
    $where[]  = 'i.location_id = ?';
    $params[] = $filterLoc;
}

$inquiries = DB::query(
    "SELECT i.*, l.name AS loc_name, u.first_name, u.last_name
     FROM walk_in_inquiries i
     JOIN locations l ON l.id = i.location_id
     JOIN users u ON u.id = i.logged_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(i.status,'pending','quoted','converted','lost'), i.created_at DESC",
    $params
);

// Counts for tabs
$counts = DB::query(
    "SELECT status, COUNT(*) AS cnt FROM walk_in_inquiries
     WHERE " . ($filterLoc ? "location_id={$filterLoc}" : "1=1") . "
     GROUP BY status",
    []
);
$cnt = ['pending'=>0,'quoted'=>0,'converted'=>0,'lost'=>0,'all'=>0];
foreach ($counts as $c) {
    $cnt[$c['status']] = (int)$c['cnt'];
    $cnt['all'] += (int)$c['cnt'];
}

$pageTitle = 'Walk-in Inquiries';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🚶 Walk-in Inquiries</h1>
        <p class="page-sub">Log customers who need a callback or quote</p>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Log new inquiry -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2 class="card-title">➕ Log New Inquiry</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="log">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Customer Name <span style="color:var(--red)">*</span></label>
                    <input type="text" name="customer_name" class="form-control" placeholder="Jane Smith" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone Number <span style="color:var(--red)">*</span></label>
                    <input type="tel" name="customer_phone" class="form-control" placeholder="6471234567" required>
                </div>
                <?php if ($isOwner || $isManager): ?>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control">
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>" <?= $loc['id'] == $locationId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Device Brand</label>
                    <input type="text" name="device_brand" class="form-control" placeholder="e.g. Asus">
                </div>
                <div class="form-group">
                    <label class="form-label">Device Model</label>
                    <input type="text" name="device_model" class="form-control" placeholder="e.g. Zenfone 4">
                </div>
                <div class="form-group">
                    <label class="form-label">Estimated Price <span class="text-muted">(optional)</span></label>
                    <input type="number" name="estimated_price" class="form-control" step="0.01" min="0" placeholder="0.00" value="">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Inquiry / Issue Description</label>
                <textarea name="description" class="form-control" rows="2"
                    placeholder="e.g. Asking price for screen replacement — need to source part first"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Log Inquiry</button>
        </form>
    </div>
</div>

<!-- Filter bar -->
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
    <?php
    $statuses = ['pending'=>'Pending','quoted'=>'Quoted','converted'=>'Converted','lost'=>'Lost','all'=>'All'];
    $colors   = ['pending'=>'var(--amber)','quoted'=>'var(--blue)','converted'=>'var(--green)','lost'=>'var(--red)','all'=>'var(--text-muted)'];
    foreach ($statuses as $s => $label):
        $active = $filterStatus === $s;
        $locParam = $filterLoc ? "&location_id={$filterLoc}" : '';
    ?>
    <a href="?status=<?= $s ?><?= $locParam ?>"
       style="padding:5px 14px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;
              background:<?= $active ? $colors[$s] : 'var(--surface-2)' ?>;
              color:<?= $active ? '#fff' : 'var(--text-muted)' ?>;border:2px solid <?= $colors[$s] ?>;">
        <?= $label ?> <?php if ($cnt[$s] > 0): ?><span style="opacity:.85;">(<?= $cnt[$s] ?>)</span><?php endif; ?>
    </a>
    <?php endforeach; ?>

    <?php if ($isOwner || $isManager): ?>
    <select onchange="location='?status=<?= $filterStatus ?>&location_id='+this.value" class="form-control" style="width:auto;padding:4px 10px;font-size:13px;">
        <option value="0" <?= !$filterLoc ? 'selected' : '' ?>>All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= $loc['id'] == $filterLoc ? 'selected' : '' ?>>
            <?= htmlspecialchars($loc['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<!-- Inquiry list -->
<?php if (empty($inquiries)): ?>
<div class="card"><div class="card-body text-muted" style="text-align:center;padding:2rem;">
    No inquiries found for this filter.
</div></div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:.75rem;">
<?php foreach ($inquiries as $inq):
    $statusColors = ['pending'=>'var(--amber)','quoted'=>'var(--blue)','converted'=>'var(--green)','lost'=>'var(--red)'];
    $sc = $statusColors[$inq['status']] ?? 'var(--text-muted)';

    // Age calculation
    $ageSeconds = time() - strtotime($inq['created_at']);
    $ageHours   = floor($ageSeconds / 3600);
    $ageMins    = floor(($ageSeconds % 3600) / 60);
    if ($ageHours >= 1) {
        $ageLabel = $ageHours . 'h ' . $ageMins . 'm ago';
    } else {
        $ageLabel = $ageMins . 'm ago';
    }
    $isOverdue = $inq['status'] === 'pending' && $ageSeconds >= 3600;
    $cardBg    = $isOverdue ? 'background:#fffbeb;' : '';
?>
<div class="card <?= $isOverdue ? 'overdue-inquiry' : '' ?>" style="border-left:4px solid <?= $sc ?>;<?= $cardBg ?>">
    <div class="card-body" style="padding:1rem 1.25rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
            <div>
                <div style="font-weight:700;font-size:16px;">
                    <?= htmlspecialchars($inq['customer_name']) ?>
                    <a href="tel:<?= htmlspecialchars($inq['customer_phone']) ?>"
                       style="font-size:13px;font-weight:600;color:var(--blue);margin-left:8px;text-decoration:none;">
                        📞 <?= htmlspecialchars($inq['customer_phone']) ?>
                    </a>
                </div>
                <?php if ($inq['device_brand'] || $inq['device_model']): ?>
                <div style="font-size:13px;color:var(--text-muted);margin-top:2px;">
                    📱 <?= htmlspecialchars(trim(($inq['device_brand'] ?? '') . ' ' . ($inq['device_model'] ?? ''))) ?>
                </div>
                <?php endif; ?>
                <?php if ($inq['description']): ?>
                <div style="font-size:13px;margin-top:4px;"><?= htmlspecialchars($inq['description']) ?></div>
                <?php endif; ?>
                <?php if ($inq['notes']): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px;font-style:italic;">
                    Note: <?= htmlspecialchars($inq['notes']) ?>
                </div>
                <?php endif; ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                    <?= htmlspecialchars($inq['loc_name']) ?>
                    · Logged by <?= htmlspecialchars($inq['first_name']) ?>
                    · <?= date('M j, g:ia', strtotime($inq['created_at'])) ?>
                    <?php if ($inq['estimated_price'] !== null): ?>
                    · Est. <strong>$<?= number_format($inq['estimated_price'], 2) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.35rem;flex-shrink:0;">
                <span style="background:<?= $sc ?>;color:#fff;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;">
                    <?= $inq['status'] ?>
                </span>
                <span style="font-size:11px;font-weight:700;color:<?= $isOverdue ? '#d97706' : 'var(--text-muted)' ?>;"
                      <?= $isOverdue ? 'class="age-flash"' : '' ?>>
                    ⏱ <?= $ageLabel ?>
                </span>
            </div>
        </div>

        <?php if ($inq['status'] !== 'converted' && $inq['status'] !== 'lost'): ?>
        <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap;align-items:center;">

            <!-- Convert to repair -->
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="convert">
                <input type="hidden" name="inquiry_id" value="<?= $inq['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm"
                    onclick="return confirm('Convert this inquiry to a repair ticket?')">
                    🔧 Convert to Repair
                </button>
            </form>

            <!-- Mark Quoted -->
            <?php if ($inq['status'] !== 'quoted'): ?>
            <button class="btn btn-secondary btn-sm" onclick="toggleForm('quoted-<?= $inq['id'] ?>')">
                ✅ Mark Quoted
            </button>
            <?php endif; ?>

            <!-- Mark Lost -->
            <button class="btn btn-secondary btn-sm" style="color:var(--red);" onclick="toggleForm('lost-<?= $inq['id'] ?>')">
                ✗ Mark Lost
            </button>

            <!-- Re-open to pending if quoted -->
            <?php if ($inq['status'] === 'quoted'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="inquiry_id" value="<?= $inq['id'] ?>">
                <input type="hidden" name="status" value="pending">
                <input type="hidden" name="notes" value="">
                <button type="submit" class="btn btn-secondary btn-sm">↩ Re-open</button>
            </form>
            <?php endif; ?>

            <!-- Quoted form -->
            <div id="quoted-<?= $inq['id'] ?>" style="display:none;width:100%;margin-top:.5rem;">
                <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="inquiry_id" value="<?= $inq['id'] ?>">
                    <input type="hidden" name="status" value="quoted">
                    <input type="text" name="notes" class="form-control" style="max-width:320px;padding:4px 8px;font-size:13px;"
                        placeholder="Price quoted (e.g. told them $120 for screen)">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleForm('quoted-<?= $inq['id'] ?>')">Cancel</button>
                </form>
            </div>

            <!-- Lost form -->
            <div id="lost-<?= $inq['id'] ?>" style="display:none;width:100%;margin-top:.5rem;">
                <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="inquiry_id" value="<?= $inq['id'] ?>">
                    <input type="hidden" name="status" value="lost">
                    <input type="text" name="notes" class="form-control" style="max-width:320px;padding:4px 8px;font-size:13px;"
                        placeholder="Reason (optional — e.g. went elsewhere)">
                    <button type="submit" class="btn btn-danger btn-sm">Confirm Lost</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleForm('lost-<?= $inq['id'] ?>')">Cancel</button>
                </form>
            </div>

        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<style>
@keyframes agePulse {
    0%,100% { opacity:1; }
    50%      { opacity:.4; }
}
.age-flash { animation: agePulse 1.2s ease-in-out infinite; }
.overdue-inquiry { box-shadow: 0 0 0 2px #f59e0b44; }
</style>

<script>
function toggleForm(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// Live age timers — re-calculates every minute
const timestamps = <?= json_encode(array_column($inquiries, 'created_at', 'id')) ?>;
function updateAges() {
    const now = Date.now();
    document.querySelectorAll('[data-inquiry-id]').forEach(el => {
        const id = el.dataset.inquiryId;
        if (!timestamps[id]) return;
        const created = new Date(timestamps[id].replace(' ', 'T')).getTime();
        const diffSec = Math.floor((now - created) / 1000);
        const h = Math.floor(diffSec / 3600);
        const m = Math.floor((diffSec % 3600) / 60);
        el.textContent = '⏱ ' + (h > 0 ? h + 'h ' + m + 'm ago' : m + 'm ago');
        if (diffSec >= 3600) {
            el.style.color = '#d97706';
            el.classList.add('age-flash');
        }
    });
}
// Tag all age spans with data attribute
document.querySelectorAll('.age-flash, [class=""]').forEach(el => {
    if (el.textContent.includes('⏱')) {
        // Already rendered server-side; just refresh periodically
    }
});
setInterval(updateAges, 60000);
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
