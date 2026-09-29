<?php
// ============================================================
// AI Access — Generate & manage the Claude API key
// Owner only
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner()) {
    header('Location: ' . APP_URL . '/modules/dashboard/'); exit;
}

$user = Auth::user();

// ── Generate or regenerate key ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_key') {
    $newKey = bin2hex(random_bytes(24)); // 48-char hex key
    DB::execute(
        "INSERT INTO settings (setting_key, location_id, value, updated_by)
         VALUES ('ai_api_key', NULL, ?, ?)
         ON DUPLICATE KEY UPDATE value=?, updated_by=?",
        [$newKey, $user['id'], $newKey, $user['id']]
    );
    header('Location: ' . APP_URL . '/modules/settings/ai_access.php?msg=generated'); exit;
}

// ── Load existing key ────────────────────────────────────────
$row    = DB::queryOne("SELECT value FROM settings WHERE setting_key='ai_api_key' AND location_id IS NULL", []);
$apiKey = $row['value'] ?? null;
$base   = APP_URL . '/api.php';

$pageTitle = 'Claude AI Access';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<style>
.url-box {
    font-family: monospace;
    font-size: 13px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .6rem .75rem;
    word-break: break-all;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: .5rem;
}
.url-box span { flex: 1; }
.copy-btn {
    flex-shrink: 0;
    padding: .25rem .6rem;
    font-size: 12px;
    cursor: pointer;
    border: 1px solid var(--border);
    border-radius: 5px;
    background: var(--card);
    color: var(--text);
}
.copy-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
.report-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px,1fr));
    gap: .75rem;
    margin-top: 1rem;
}
.report-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: .85rem 1rem;
}
.report-card h3 { font-size: 14px; font-weight: 700; margin-bottom: .25rem; }
.report-card p  { font-size: 12px; color: var(--text-3); margin-bottom: .5rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">🤖 Claude AI Access</h1>
        <p class="page-sub">Let Claude read your live CRM data to do analysis for you</p>
    </div>
    <a href="index.php" class="btn btn-secondary">← Settings</a>
</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success">✓ New API key generated. Copy your URLs below.</div>
<?php endif; ?>

<?php if (!$apiKey): ?>
<!-- No key yet -->
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;">
        <div style="font-size:48px;margin-bottom:1rem;">🔑</div>
        <h2 style="margin-bottom:.5rem;">No API key yet</h2>
        <p class="text-muted" style="margin-bottom:1.5rem;">Generate a key to give Claude access to your CRM data.</p>
        <form method="POST">
            <input type="hidden" name="action" value="generate_key">
            <button type="submit" class="btn btn-primary btn-lg">Generate My Key</button>
        </form>
    </div>
</div>

<?php else: ?>

<!-- Key exists — show everything -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">Your API Key</h2>
        <form method="POST" onsubmit="return confirm('This will break any existing URLs. Are you sure?')">
            <input type="hidden" name="action" value="generate_key">
            <button type="submit" class="btn btn-sm btn-ghost">🔄 Regenerate Key</button>
        </form>
    </div>
    <div class="card-body">
        <div class="form-hint" style="margin-bottom:.5rem;">Keep this private — it gives read access to your CRM data.</div>
        <div class="url-box">
            <span id="key-display"><?= htmlspecialchars($apiKey) ?></span>
            <button class="copy-btn" onclick="copyText('key-display', this)">Copy</button>
        </div>
    </div>
</div>

<!-- How to use -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2 class="card-title">📋 How to Use with Claude</h2></div>
    <div class="card-body">
        <ol style="padding-left:1.2rem;line-height:2;">
            <li>Pick a report URL below and click <strong>Copy</strong></li>
            <li>Go to Claude (this app) and type something like:<br>
                <code style="background:var(--surface-2);padding:.2rem .5rem;border-radius:4px;font-size:13px;">
                    Fetch this URL and analyse my business: [paste URL]
                </code>
            </li>
            <li>Claude will pull your live data and give you analysis, trends, and recommendations</li>
        </ol>

        <div style="background:var(--surface-2);border-radius:8px;padding:.75rem 1rem;margin-top:1rem;font-size:13px;">
            <strong>💡 Example prompts you can use:</strong>
            <ul style="margin-top:.5rem;padding-left:1.2rem;line-height:2;">
                <li>"Fetch [summary URL] and tell me how today is going"</li>
                <li>"Fetch [sales URL] and compare this week to what's typical"</li>
                <li>"Fetch [repairs URL] and tell me if any repairs need attention"</li>
                <li>"Fetch [inventory URL] and tell me what I need to order"</li>
                <li>"Fetch [staff URL] and show me who's working today"</li>
            </ul>
        </div>
    </div>
</div>

<!-- Report URLs -->
<div class="card">
    <div class="card-header"><h2 class="card-title">🔗 Your Report URLs</h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:1rem;">Copy any URL and paste it to Claude. Claude can fetch live data from these endpoints.</p>

        <div class="report-grid">

            <div class="report-card">
                <h3>📊 Business Summary</h3>
                <p>Today's revenue, active repairs, who's clocked in, cash drawers, low stock alert</p>
                <div class="url-box">
                    <span id="url-summary"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=summary</span>
                    <button class="copy-btn" onclick="copyText('url-summary',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>💰 Sales — This Month</h3>
                <p>Daily breakdown, top items, by staff, payment methods</p>
                <div class="url-box">
                    <span id="url-sales"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=sales&period=month</span>
                    <button class="copy-btn" onclick="copyText('url-sales',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>💰 Sales — This Week</h3>
                <p>Daily breakdown for the current week</p>
                <div class="url-box">
                    <span id="url-sales-week"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=sales&period=week</span>
                    <button class="copy-btn" onclick="copyText('url-sales-week',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>🔧 Repairs — This Month</h3>
                <p>Stats, by device type, by technician, all active tickets</p>
                <div class="url-box">
                    <span id="url-repairs"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=repairs&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?></span>
                    <button class="copy-btn" onclick="copyText('url-repairs',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>📦 Inventory</h3>
                <p>Total stock value, low stock items, breakdown by category</p>
                <div class="url-box">
                    <span id="url-inv"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=inventory</span>
                    <button class="copy-btn" onclick="copyText('url-inv',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>💵 Cash</h3>
                <p>Today's drawer status per location, this week cash vs card</p>
                <div class="url-box">
                    <span id="url-cash"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=cash</span>
                    <button class="copy-btn" onclick="copyText('url-cash',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>👤 Staff</h3>
                <p>Who's clocked in now, today's shifts, month hours summary</p>
                <div class="url-box">
                    <span id="url-staff"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=staff</span>
                    <button class="copy-btn" onclick="copyText('url-staff',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>🏪 Business Intelligence</h3>
                <p>Both locations vs monthly targets — revenue, repairs, calls, conversion, staff</p>
                <div class="url-box">
                    <span id="url-bi"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=bi&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?></span>
                    <button class="copy-btn" onclick="copyText('url-bi',this)">Copy</button>
                </div>
            </div>

            <div class="report-card">
                <h3>📞 Calls & Conversions</h3>
                <p>Inbound calls, missed calls, repair lead conversion rate, pending callbacks</p>
                <div class="url-box">
                    <span id="url-calls"><?= $base ?>?key=<?= htmlspecialchars($apiKey) ?>&report=calls&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?></span>
                    <button class="copy-btn" onclick="copyText('url-calls',this)">Copy</button>
                </div>
            </div>

        </div>

        <div style="margin-top:1.25rem;padding:.75rem 1rem;background:var(--surface-2);border-radius:8px;font-size:13px;color:var(--text-3);">
            <strong>Custom date range:</strong> add <code>&from=2026-08-01&to=2026-08-31</code> to any sales or repairs URL.
        </div>

    </div>
</div>

<?php endif; ?>

<script>
function copyText(elId, btn) {
    const text = document.getElementById(elId).textContent.trim();
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.background = 'var(--green)';
        btn.style.color = '#fff';
        setTimeout(() => { btn.textContent = orig; btn.style.background = ''; btn.style.color = ''; }, 2000);
    });
}
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
